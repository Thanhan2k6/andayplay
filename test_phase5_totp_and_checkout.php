<?php
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/totp_service.php';
require_once __DIR__ . '/includes/account_service.php';

echo "=== TEST PHASE 4 & 5: 2FA TOTP & CHECKOUT QR CREDENTIALS ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($title, $condition) {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] $title\n";
        $passCount++;
    } else {
        echo "[FAIL] $title\n";
        $failCount++;
    }
}

// 1. TEST TOTP GENERATION & VERIFICATION
$secret = TotpService::generateSecret(16);
assertTest("TOTP Secret length is 16 chars Base32", strlen($secret) === 16 && preg_match('/^[A-Z2-7]{16}$/', $secret));

$currentCode = TotpService::getCode($secret);
assertTest("Current TOTP code is 6 digits", strlen($currentCode) === 6 && ctype_digit($currentCode));

$verifySuccess = TotpService::verifyCode($secret, $currentCode);
assertTest("Verify current TOTP code returns true", $verifySuccess === true);

$verifyFail = TotpService::verifyCode($secret, "999999" === $currentCode ? "111111" : "999999");
assertTest("Verify invalid TOTP code returns false", $verifyFail === false);

// 2. TEST BACKUP CODES
$backupCodes = TotpService::generateBackupCodes(8);
assertTest("Generate 8 backup codes", count($backupCodes) === 8);
assertTest("Backup code format XXXX-XXXX", preg_match('/^[A-F0-9]{4}-[A-F0-9]{4}$/', $backupCodes[0]));

$hashedJson = TotpService::hashBackupCodes($backupCodes);
$hashedArr = json_decode($hashedJson, true);
assertTest("Hashed backup codes is valid JSON array of 8 hashes", is_array($hashedArr) && count($hashedArr) === 8);
assertTest("Backup codes are SHA-256 hashes (64 hex chars)", strlen($hashedArr[0]) === 64);

// 3. TEST DATABASE 2FA WORKFLOW FOR A TEST USER
// Find or create a test user
$testUserRes = $conn->query("SELECT user_id, username, password FROM users WHERE role = 'user' LIMIT 1");
if ($testUserRes && $testUser = $testUserRes->fetch_assoc()) {
    $tUid = intval($testUser['user_id']);
    $tUser = $testUser['username'];
    echo "\nTesting with user #$tUid (@$tUser)...\n";

    // Enable 2FA
    $upd = $conn->prepare("UPDATE users SET two_factor_enabled = 1, two_factor_secret = ?, two_factor_backup_codes = ? WHERE user_id = ?");
    $upd->bind_param("ssi", $secret, $hashedJson, $tUid);
    $upd->execute();
    $upd->close();

    $chk = $conn->query("SELECT two_factor_enabled, two_factor_secret, two_factor_backup_codes FROM users WHERE user_id = $tUid")->fetch_assoc();
    assertTest("User 2FA is successfully enabled in DB", intval($chk['two_factor_enabled']) === 1 && $chk['two_factor_secret'] === $secret);

    // Test consuming first backup code
    $firstCode = $backupCodes[0];
    $consumed1 = TotpService::verifyAndConsumeBackupCode($conn, $tUid, $firstCode);
    assertTest("Backup code 1 verified & consumed successfully", $consumed1 === true);

    // Verify code 1 cannot be reused (single-use)
    $consumedAgain = TotpService::verifyAndConsumeBackupCode($conn, $tUid, $firstCode);
    assertTest("Consumed backup code cannot be reused (Single-use enforced)", $consumedAgain === false);

    // Verify remaining codes in DB is 7
    $chk2 = $conn->query("SELECT two_factor_backup_codes FROM users WHERE user_id = $tUid")->fetch_assoc();
    $rem = json_decode($chk2['two_factor_backup_codes'], true);
    assertTest("Remaining backup codes count in DB is 7", count($rem) === 7);

    // Disable 2FA
    $conn->query("UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL, two_factor_backup_codes = NULL WHERE user_id = $tUid");
    $chk3 = $conn->query("SELECT two_factor_enabled, two_factor_secret, two_factor_backup_codes FROM users WHERE user_id = $tUid")->fetch_assoc();
    assertTest("User 2FA successfully disabled and secret cleared", intval($chk3['two_factor_enabled']) === 0 && is_null($chk3['two_factor_secret']));
} else {
    echo "[WARN] No standard user found to test DB 2FA workflow.\n";
}

// 4. TEST CHECKOUT QR & GAME PASSWORD ENCRYPTION / DECRYPTION
echo "\nTesting Account Service AES-256 Encryption...\n";
$plainPass = "MySuperSecretSteamPass!2026";
$encrypted = encrypt_game_password($plainPass);
assertTest("Encrypt game password produces non-empty string different from plaintext", !empty($encrypted) && $encrypted !== $plainPass);

$decrypted = decrypt_game_password($encrypted);
assertTest("Decrypt game password matches original plaintext", $decrypted === $plainPass);

// Check if game_accounts in DB have encrypted passwords
$accRes = $conn->query("SELECT account_id, account_username, account_password_encrypted FROM game_accounts LIMIT 1");
if ($accRes && $acc = $accRes->fetch_assoc()) {
    $decryptedDbPass = decrypt_game_password($acc['account_password_encrypted']);
    assertTest("Decrypt existing DB game account #{$acc['account_id']} produces valid non-empty password", !empty($decryptedDbPass));
}

echo "\n=========================================\n";
echo "SUMMARY: PASS: $passCount | FAIL: $failCount\n";
echo "=========================================\n";

