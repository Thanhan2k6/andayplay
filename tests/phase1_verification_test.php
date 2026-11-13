<?php
/**
 * ANPLAY STORE - Phase 1 Independent QA Verification Suite (CLI)
 * Run: C:\xampp\php\php.exe tests/phase1_verification_test.php
 * Scope: Staging DB (anplay_staging) & Local Apache (http://localhost/quan_ly_thu_vien_game)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$baseUrl = rtrim(getenv('ANPLAY_TEST_URL') ?: 'http://localhost/quan_ly_thu_vien_game', '/');
$rootDir = dirname(__DIR__);

$passed = 0;
$failed = 0;
$skipped = 0;

function reportResult(string $id, string $name, string $status, string $detail = ''): void {
    global $passed, $failed, $skipped;
    if ($status === 'PASS') {
        $passed++;
    } elseif ($status === 'FAIL') {
        $failed++;
    } else {
        $skipped++;
    }
    echo sprintf("[%s] %s - %s%s\n", $status, $id, $name, $detail !== '' ? " | $detail" : '');
}

function httpRequest(string $url, string $method = 'GET', array $postData = [], array $headers = [], ?string $cookieFile = null): array {
    if (!function_exists('curl_init')) {
        return ['code' => 0, 'body' => '', 'headers' => '', 'error' => 'cURL extension missing'];
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) {
        return ['code' => 0, 'body' => '', 'headers' => '', 'error' => $err];
    }
    return [
        'code' => (int)$code,
        'headers' => substr($raw, 0, $hdrSize),
        'body' => substr($raw, $hdrSize),
        'error' => '',
    ];
}

echo "=== ANPLAY STORE PHASE 1 QA VERIFICATION ===\n";
echo "Target URL: $baseUrl\n\n";

// -------------------------------------------------------------------------
// 1. STATIC / HTTP EXPOSURE & HTAccess CHECKS
// -------------------------------------------------------------------------
$exposureTargets = [
    'EXP-01' => ['/backup_game_library_db.sql', [403, 404]],
    'EXP-02' => ['/includes/secrets.local.php', [403, 404]],
    'EXP-03' => ['/includes/connect.php', [403, 404]],
    'EXP-04' => ['/cron_process_expirations.php', [403]],
];

foreach ($exposureTargets as $id => [$path, $expectedCodes]) {
    $res = httpRequest($baseUrl . $path);
    if ($res['code'] === 0) {
        reportResult($id, "HTTP exposure check $path", 'FAIL', "Request error: {$res['error']}");
    } elseif (in_array($res['code'], $expectedCodes, true)) {
        reportResult($id, "HTTP exposure check $path", 'PASS', "HTTP {$res['code']}");
    } else {
        reportResult($id, "HTTP exposure check $path", 'FAIL', "Expected " . implode('/', $expectedCodes) . ", got HTTP {$res['code']}");
    }
}

// -------------------------------------------------------------------------
// 2. UNAUTHENTICATED RBAC & PASSWORD RESET PROTECTION
// -------------------------------------------------------------------------
$adminPages = [
    'RBAC-01' => '/admin_dashboard.php',
    'RBAC-02' => '/admin_users.php',
    'RBAC-03' => '/admin_games.php',
    'RBAC-04' => '/admin_borrow.php',
    'RBAC-05' => '/admin_management.php',
    'RBAC-06' => '/admin_roles.php',
    'RBAC-07' => '/admin_system.php',
];

foreach ($adminPages as $id => $path) {
    $res = httpRequest($baseUrl . $path);
    $isRedirectToLogin = ($res['code'] === 302 && stripos($res['headers'], 'login.php') !== false);
    if ($res['code'] === 403 || $isRedirectToLogin) {
        reportResult($id, "Guest blocked from $path", 'PASS', "HTTP {$res['code']}");
    } else {
        reportResult($id, "Guest blocked from $path", 'FAIL', "Got HTTP {$res['code']}");
    }
}

$notifGuest = httpRequest($baseUrl . '/api_notifications.php?action=get_unread_count');
$notifJson = json_decode($notifGuest['body'], true);
if (is_array($notifJson) && ($notifJson['success'] ?? true) === false) {
    reportResult('RBAC-08', 'Guest blocked on api_notifications.php', 'PASS', 'success=false');
} else {
    reportResult('RBAC-08', 'Guest blocked on api_notifications.php', 'FAIL', "HTTP {$notifGuest['code']} body=" . substr($notifGuest['body'], 0, 120));
}

// Unauthenticated password reset attempt without token/CSRF
$resetRes = httpRequest($baseUrl . '/forgot_password.php', 'POST', [
    'btn_reset' => '1',
    'reset_email' => 'admin@example.com',
    'new_password' => 'HackedPass123!',
]);
$resetBlocked = ($resetRes['code'] === 403 || $resetRes['code'] === 400 ||
    (stripos($resetRes['body'], 'Đổi mật khẩu thành công') === false && stripos($resetRes['body'], 'Thành công!') === false));
reportResult(
    'SEC-01',
    'Unauthenticated forgot_password.php btn_reset blocked',
    $resetBlocked ? 'PASS' : 'FAIL',
    "HTTP {$resetRes['code']}"
);

// Unauthenticated payment webhook without signature
$webhookRes = httpRequest($baseUrl . '/api_payment_webhook.php', 'POST', [
    'order_code' => 'ORD_NONEXISTENT',
    'amount' => '50000',
    'payment_tx_id' => 'TX_FAKE_001',
]);
if (in_array($webhookRes['code'], [401, 403, 400], true)) {
    reportResult('SEC-02', 'Unsigned api_payment_webhook.php rejected', 'PASS', "HTTP {$webhookRes['code']}");
} else {
    reportResult('SEC-02', 'Unsigned api_payment_webhook.php rejected', 'FAIL', "HTTP {$webhookRes['code']} body=" . substr($webhookRes['body'], 0, 120));
}

// -------------------------------------------------------------------------
// 3. DATABASE SCHEMA VERIFICATION (anplay_staging)
// -------------------------------------------------------------------------
$secretsFile = $rootDir . '/includes/secrets.local.php';
$secrets = file_exists($secretsFile) ? (function ($f) {
    if (!defined('ANPLAY_INTERNAL')) define('ANPLAY_INTERNAL', true);
    $cfg = include $f;
    return is_array($cfg) ? $cfg : [];
})($secretsFile) : [];

$dbHost = $secrets['db_host'] ?? '127.0.0.1';
$dbUser = $secrets['db_user'] ?? 'root';
$dbPass = $secrets['db_pass'] ?? '';
$dbName = $secrets['db_name'] ?? 'anplay_staging';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_error) {
    reportResult('DB-00', "Connect to staging DB ($dbName)", 'FAIL', $conn->connect_error);
} else {
    reportResult('DB-00', "Connect to staging DB ($dbName)", 'PASS', "Connected");

    // Check borrow_records columns
    $colsBorrow = [];
    $q = $conn->query("SHOW COLUMNS FROM borrow_records");
    while ($q && $r = $q->fetch_assoc()) {
        $colsBorrow[$r['Field']] = $r;
    }
    $dueNullable = isset($colsBorrow['due_date']) && $colsBorrow['due_date']['Null'] === 'YES';
    reportResult('DB-01', 'borrow_records.due_date is NULLable', $dueNullable ? 'PASS' : 'FAIL', $colsBorrow['due_date']['Null'] ?? 'missing');
    reportResult('DB-02', 'borrow_records.return_date exists', isset($colsBorrow['return_date']) ? 'PASS' : 'FAIL');
    reportResult('DB-03', 'borrow_records.payment_tx_id exists', isset($colsBorrow['payment_tx_id']) ? 'PASS' : 'FAIL');

    // Check users columns
    $colsUsers = [];
    $q = $conn->query("SHOW COLUMNS FROM users");
    while ($q && $r = $q->fetch_assoc()) {
        $colsUsers[$r['Field']] = $r;
    }
    $has2fa = isset($colsUsers['two_factor_enabled'], $colsUsers['two_factor_secret'], $colsUsers['two_factor_backup_codes']);
    reportResult('DB-04', 'users.two_factor_* columns exist', $has2fa ? 'PASS' : 'FAIL');

    // Check games.quantity
    $colsGames = [];
    $q = $conn->query("SHOW COLUMNS FROM games");
    while ($q && $r = $q->fetch_assoc()) {
        $colsGames[$r['Field']] = $r;
    }
    reportResult('DB-05', 'games.quantity exists', isset($colsGames['quantity']) ? 'PASS' : 'FAIL');

    // Check password_resets & auth_rate_limits
    $tPr = $conn->query("SHOW TABLES LIKE 'password_resets'");
    $tRl = $conn->query("SHOW TABLES LIKE 'auth_rate_limits'");
    reportResult('DB-06', 'password_resets & auth_rate_limits tables exist', ($tPr && $tPr->num_rows > 0 && $tRl && $tRl->num_rows > 0) ? 'PASS' : 'FAIL');

    // Check indexes on borrow_records
    $idxCount = 0;
    $q = $conn->query("SHOW INDEX FROM borrow_records");
    while ($q && $r = $q->fetch_assoc()) {
        if ($r['Key_name'] !== 'PRIMARY') $idxCount++;
    }
    reportResult('DB-07', 'borrow_records secondary indexes present', $idxCount > 0 ? 'PASS' : 'FAIL', "non-PK index rows=$idxCount");

    $conn->close();
}

// -------------------------------------------------------------------------
// 4. DUAL-KEY ENCRYPTION ROUND-TRIP
// -------------------------------------------------------------------------
if (file_exists($rootDir . '/includes/account_service.php')) {
    if (!defined('ANPLAY_INTERNAL')) define('ANPLAY_INTERNAL', true);
    require_once $rootDir . '/includes/account_service.php';
    if (function_exists('encrypt_game_password') && function_exists('decrypt_game_password')) {
        $sample = 'QaTestPass!2026';
        $enc = encrypt_game_password($sample);
        $dec = decrypt_game_password($enc);
        $isV2 = (strpos($enc, 'v2:') === 0);
        reportResult(
            'SEC-03',
            'Dual-key encrypt/decrypt v2 round-trip',
            ($isV2 && $dec === $sample) ? 'PASS' : 'FAIL',
            "prefix=" . substr($enc, 0, 3)
        );
    } else {
        reportResult('SEC-03', 'Dual-key encrypt/decrypt v2 round-trip', 'SKIP', 'Functions not loaded');
    }
}

echo "\n=== SUMMARY: PASS=$passed | FAIL=$failed | SKIP=$skipped ===\n";
exit($failed > 0 ? 1 : 0);
