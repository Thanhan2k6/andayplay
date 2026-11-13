<?php
/**
 * ANPLAY STORE — ZERO-DOWNTIME APP_SECURITY_KEY ROTATION SCRIPT (CLI-ONLY)
 * Re-encrypts legacy game_accounts.account_password_encrypted rows to v2: (AES-256-CBC + HMAC-SHA256)
 * using APP_SECURITY_KEY_OLD -> APP_SECURITY_KEY_CURRENT with pre-migration backup & in-memory verification.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

define('ANPLAY_INTERNAL', true);
require_once dirname(__DIR__) . '/includes/connect.php';
require_once dirname(__DIR__) . '/includes/account_service.php';

if (!isset($conn) || !$conn || $conn->connect_error) {
    fwrite(STDERR, "[ERROR] Database connection failed.\n");
    exit(1);
}

if (empty(APP_SECURITY_KEY_CURRENT)) {
    fwrite(STDERR, "[ERROR] APP_SECURITY_KEY_CURRENT is empty. Configure includes/secrets.local.php first.\n");
    exit(1);
}

// 1. Pre-migration backup of game_accounts to scratch/backups
$backupDir = 'C:\\Users\\Admin\\.gemini\\antigravity\\brain\\928b00f1-3303-4835-a6b5-5a0192190554\\scratch\\backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}
$backupFile = $backupDir . DIRECTORY_SEPARATOR . 'game_accounts_pre_rotation_' . date('Ymd_His') . '.json';

$rows = [];
$res = $conn->query("SELECT account_id, account_username, account_password_encrypted FROM game_accounts ORDER BY account_id ASC");
if (!$res) {
    fwrite(STDERR, "[ERROR] Failed to query game_accounts: " . $conn->error . "\n");
    exit(1);
}
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
if (file_put_contents($backupFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    fwrite(STDERR, "[ERROR] Failed to write backup file: $backupFile\n");
    exit(1);
}
echo "[BACKUP] Saved " . count($rows) . " game_accounts rows to $backupFile\n";

// 2. Batch re-encrypt legacy rows to v2:
$total = count($rows);
$alreadyV2 = 0;
$migrated = 0;
$failedIds = [];

$upd = $conn->prepare("UPDATE game_accounts SET account_password_encrypted = ?, updated_at = NOW() WHERE account_id = ?");
if (!$upd) {
    fwrite(STDERR, "[ERROR] Failed to prepare UPDATE statement.\n");
    exit(1);
}

foreach ($rows as $row) {
    $accId = intval($row['account_id']);
    $cipher = $row['account_password_encrypted'];

    if (empty($cipher)) {
        continue;
    }
    if (strpos($cipher, 'v2:') === 0) {
        $alreadyV2++;
        continue;
    }

    $plaintext = decrypt_game_password($cipher);
    if ($plaintext === '') {
        $failedIds[] = $accId;
        continue;
    }

    try {
        $newCipher = encrypt_game_password($plaintext);
        $roundTrip = decrypt_game_password($newCipher);
        if ($roundTrip !== $plaintext) {
            $failedIds[] = $accId;
            continue;
        }
        $upd->bind_param("si", $newCipher, $accId);
        if ($upd->execute()) {
            $migrated++;
        } else {
            $failedIds[] = $accId;
        }
    } catch (Throwable $e) {
        $failedIds[] = $accId;
    }
}
$upd->close();

// 3. Post-migration verification
$chk = $conn->query("SELECT COUNT(*) AS remaining_legacy FROM game_accounts WHERE account_password_encrypted != '' AND account_password_encrypted NOT LIKE 'v2:%'");
$remaining = ($chk && ($cr = $chk->fetch_assoc())) ? intval($cr['remaining_legacy']) : -1;

echo "[SUMMARY] total=$total already_v2=$alreadyV2 migrated=$migrated failed=" . count($failedIds) . " remaining_legacy=$remaining\n";
if (!empty($failedIds)) {
    echo "[FAILED_IDS] " . implode(',', $failedIds) . "\n";
    exit(2);
}
exit(0);
