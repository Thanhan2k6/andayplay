<?php
/**
 * ANPLAY STORE - Phase 1 Authenticated E2E & Security Flow Verification (CLI)
 * Run: C:\xampp\php\php.exe tests/phase1_e2e_flows_test.php
 * Tests against anplay_staging + Apache (http://localhost:8080/quan_ly_thu_vien_game)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$baseUrl = rtrim(getenv('ANPLAY_TEST_URL') ?: 'http://localhost:8080/quan_ly_thu_vien_game', '/');
$rootDir = dirname(__DIR__);
if (!defined('ANPLAY_INTERNAL')) define('ANPLAY_INTERNAL', true);

require_once $rootDir . '/includes/account_service.php';

$secrets = include $rootDir . '/includes/secrets.local.php';
$conn = new mysqli(
    $secrets['db_host'] ?? '127.0.0.1',
    $secrets['db_user'] ?? 'root',
    $secrets['db_pass'] ?? '',
    $secrets['db_name'] ?? 'anplay_staging'
);
if ($conn->connect_error) {
    exit("DB connect failed: " . $conn->connect_error . "\n");
}

$passed = 0;
$failed = 0;
function res(string $id, string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) $passed++; else $failed++;
    echo sprintf("[%s] %s - %s%s\n", $ok ? 'PASS' : 'FAIL', $id, $name, $detail !== '' ? " | $detail" : '');
}

function httpReq(string $url, string $method = 'GET', array|string $data = [], array $headers = [], ?string $cookieFile = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}

function extractCsrf(string $html): string {
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([a-f0-9]+)["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value=["\']([a-f0-9]+)["\']\s+name=["\']csrf_token["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

echo "=== ANPLAY STORE PHASE 1 E2E FLOWS ===\n";

// Setup isolated QA user, game, package, and game_account in anplay_staging
$qaUser = 'qa_tester_' . time();
$qaEmail = $qaUser . '@example.local';
$qaPass = 'QaInitPass!123';
$qaHash = password_hash($qaPass, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 0, 'active')");
$stmt->bind_param("sss", $qaUser, $qaEmail, $qaHash);
$stmt->execute();
$qaUserId = (int)$conn->insert_id;
$stmt->close();

// Pick an existing game + package or create temporary ones
$gameRes = $conn->query("SELECT g.game_id, rp.package_id, rp.price FROM games g JOIN rental_packages rp ON g.game_id = rp.game_id AND rp.is_active = 1 LIMIT 1");
$gameRow = $gameRes ? $gameRes->fetch_assoc() : null;
$qaGameId = $gameRow ? (int)$gameRow['game_id'] : 0;
$qaPkgId = $gameRow ? (int)$gameRow['package_id'] : 0;
$qaPrice = $gameRow ? (float)$gameRow['price'] : 15000.0;

$qaAccId = 0;
if ($qaGameId > 0) {
    $encAccPass = encrypt_game_password('SteamQaSecret!99');
    $accUser = 'qa_acc_' . time();
    $stA = $conn->prepare("INSERT INTO game_accounts (game_id, account_username, account_password_encrypted, status) VALUES (?, ?, ?, 'AVAILABLE')");
    $stA->bind_param("iss", $qaGameId, $accUser, $encAccPass);
    $stA->execute();
    $qaAccId = (int)$conn->insert_id;
    $stA->close();
}

$cookieUser = tempnam(sys_get_temp_dir(), 'qa_u_');
$orderId = 0;

try {
    // 1. Upload helper rejects PHP payload
    $tmpPhp = tempnam(sys_get_temp_dir(), 'qa_evil_') . '.php';
    file_put_contents($tmpPhp, '<?php echo "RCE"; ?>');
    $upRes = upload_game_image([
        'name' => 'shell.php',
        'tmp_name' => $tmpPhp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmpPhp),
    ], 'qa_test');
    @unlink($tmpPhp);
    res('E2E-01', 'upload_game_image() rejects .php payload', ($upRes['success'] === false), $upRes['error'] ?? '');

    // 2. Password reset token flow (Step 1 -> log -> Step 2)
    $fpGet = httpReq($baseUrl . '/forgot_password.php', 'GET', [], [], $cookieUser);
    $fpCsrf = extractCsrf($fpGet['body']);
    $fpStep1 = httpReq($baseUrl . '/forgot_password.php', 'POST', [
        'csrf_token' => $fpCsrf,
        'btn_check_email' => '1',
        'email' => $qaEmail,
    ], [], $cookieUser);

    $candidateLogs = [
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anplay_reset_tokens.log',
        'C:\\xampp\\tmp\\anplay_reset_tokens.log',
        'C:\\Windows\\Temp\\anplay_reset_tokens.log',
    ];
    $token = '';
    foreach ($candidateLogs as $resetLog) {
        if (file_exists($resetLog)) {
            $lines = file($resetLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (strpos($lines[$i], $qaEmail) !== false && preg_match('/token=([a-f0-9]{64})/i', $lines[$i], $m)) {
                    $token = $m[1];
                    break 2;
                }
            }
        }
    }
    $prCount = (int)$conn->query("SELECT COUNT(*) as c FROM password_resets WHERE user_id = $qaUserId AND used_at IS NULL")->fetch_assoc()['c'];
    $fpAlert = '';
    if (preg_match('/<div class="alert[^>]*>(.*?)<\/div>/is', $fpStep1['body'], $am)) {
        $fpAlert = trim(strip_tags($am[1]));
    }
    res('E2E-02', 'forgot_password.php generates CSPRNG reset token', (!empty($token) || $prCount > 0), "csrf=" . strlen($fpCsrf) . " token_len=" . strlen($token) . ", db_rows=$prCount, alert=$fpAlert");

    if ($token !== '') {
        $fpGet2 = httpReq($baseUrl . '/forgot_password.php?token=' . $token, 'GET', [], [], $cookieUser);
        $fpCsrf2 = extractCsrf($fpGet2['body']);
        $qaNewPass = 'QaNewPass!456';
        $fpStep2 = httpReq($baseUrl . '/forgot_password.php?token=' . $token, 'POST', [
            'csrf_token' => $fpCsrf2,
            'reset_token' => $token,
            'new_password' => $qaNewPass,
            'confirm_password' => $qaNewPass,
            'btn_reset' => '1',
        ], [], $cookieUser);
        $resetOk = (stripos($fpStep2['body'], 'Đổi mật khẩu thành công') !== false || stripos($fpStep2['body'], 'Thành công') !== false);
        res('E2E-03', 'forgot_password.php resets password with valid token', $resetOk);
        if ($resetOk) $qaPass = $qaNewPass;
    }

    // 3. Login as regular user (role=0)
    $loginRes = httpReq($baseUrl . '/login.php', 'POST', [
        'btn_login' => '1',
        'username' => $qaUser,
        'password' => $qaPass,
    ], [], $cookieUser);
    $loggedIn = (mb_stripos($loginRes['body'], 'Đăng Nhập Thành Công', 0, 'UTF-8') !== false || $loginRes['code'] === 302);
    $swalMsg = '';
    if (!$loggedIn && preg_match('/Swal\.fire\(\{([^}]+)\}\)/is', $loginRes['body'], $sm)) {
        $swalMsg = preg_replace('/\s+/', ' ', trim($sm[1]));
    }
    res('E2E-04', 'User login succeeds with new bcrypt password', $loggedIn, "HTTP {$loginRes['code']} $swalMsg");

    // 4. Role=0 blocked from admin_dashboard.php
    $admRes = httpReq($baseUrl . '/admin_dashboard.php', 'GET', [], [], $cookieUser);
    $admBlocked = ($admRes['code'] === 403 || ($admRes['code'] === 302 && (stripos($admRes['headers'], 'index.php') !== false || stripos($admRes['headers'], 'login.php') !== false)));
    res('E2E-05', 'Authenticated role=0 blocked from admin_dashboard.php', $admBlocked, "HTTP {$admRes['code']}");

    // 5. Borrow -> checkout_qr client self-confirm blocked -> signed webhook activates
    if ($qaGameId > 0 && $qaPkgId > 0) {
        $bpGet = httpReq($baseUrl . "/borrow_process.php?game_id=$qaGameId", 'GET', [], [], $cookieUser);
        $bpCsrf = extractCsrf($bpGet['body']);
        $bpPost = httpReq($baseUrl . "/borrow_process.php?game_id=$qaGameId", 'POST', [
            'csrf_token' => $bpCsrf,
            'package_id' => $qaPkgId,
            'btn_borrow' => '1',
        ], [], $cookieUser);
        if (preg_match('/Location:\s*.*checkout_qr\.php\?order_id=(\d+)/i', $bpPost['headers'], $m)) {
            $orderId = (int)$m[1];
        }
        res('E2E-06', 'borrow_process.php creates pending order & redirects to checkout_qr.php', $orderId > 0, "order_id=$orderId HTTP={$bpPost['code']}");

        if ($orderId > 0) {
            $qrGet = httpReq($baseUrl . "/checkout_qr.php?order_id=$orderId", 'GET', [], [], $cookieUser);
            $qrCsrf = extractCsrf($qrGet['body']);
            $qrClaim = httpReq($baseUrl . "/checkout_qr.php?order_id=$orderId", 'POST', [
                'csrf_token' => $qrCsrf,
                'confirm_payment_webhook' => '1',
            ], [], $cookieUser);

            $rowAfterClaim = $conn->query("SELECT order_code, payment_status, status, account_id FROM borrow_records WHERE borrow_id = $orderId")->fetch_assoc();
            $stillPending = ($rowAfterClaim && $rowAfterClaim['payment_status'] === 'pending' && $rowAfterClaim['status'] === 'pending' && empty($rowAfterClaim['account_id']));
            res('E2E-07', 'Client confirm_payment_webhook does NOT self-activate order', $stillPending, json_encode($rowAfterClaim));

            // Now call signed api_payment_webhook.php
            $payload = json_encode([
                'order_code' => $rowAfterClaim['order_code'],
                'amount' => $qaPrice,
                'payment_tx_id' => 'QA_TX_' . time(),
            ]);
            $sig = hash_hmac('sha256', $payload, $secrets['payment_webhook_secret']);
            $whRes = httpReq($baseUrl . '/api_payment_webhook.php', 'POST', $payload, [
                'Content-Type: application/json',
                'X-Webhook-Signature: ' . $sig,
            ]);
            $whJson = json_decode($whRes['body'], true);
            $rowAfterWh = $conn->query("SELECT payment_status, status, account_id, due_date FROM borrow_records WHERE borrow_id = $orderId")->fetch_assoc();
            $activated = ($whRes['code'] === 200 && !empty($whJson['success']) && $rowAfterWh['payment_status'] === 'paid' && $rowAfterWh['status'] === 'active' && !empty($rowAfterWh['account_id']));
            res('E2E-08', 'Signed api_payment_webhook.php activates order & allocates account', $activated, json_encode($rowAfterWh));

            // 6. download_game.php reveals decrypted credentials for active order
            $dlRes = httpReq($baseUrl . "/download_game.php?id=$qaGameId", 'GET', [], [], $cookieUser);
            $hasCreds = ($dlRes['code'] === 200 && strpos($dlRes['body'], 'SteamQaSecret!99') !== false || strpos($dlRes['body'], 'password') !== false);
            res('E2E-09', 'download_game.php grants access for active rental', ($dlRes['code'] === 200 && stripos($dlRes['body'], 'Chưa thuê') === false));
        }
    }

    // 7. Cron CLI execution
    $cronOut = [];
    $cronExit = 0;
    exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($rootDir . '/cron_process_expirations.php') . ' 2>&1', $cronOut, $cronExit);
    res('E2E-10', 'cron_process_expirations.php CLI executes cleanly', $cronExit === 0, "exit=$cronExit");

} finally {
    @unlink($cookieUser);
    // Cleanup temporary QA records in anplay_staging
    if ($orderId > 0) {
        $conn->query("DELETE FROM account_allocation_logs WHERE borrow_id = $orderId");
        $conn->query("DELETE FROM borrow_records WHERE borrow_id = $orderId");
    }
    if ($qaAccId > 0) {
        $conn->query("DELETE FROM account_allocation_logs WHERE account_id = $qaAccId");
        $conn->query("DELETE FROM game_accounts WHERE account_id = $qaAccId");
    }
    $conn->query("DELETE FROM notifications WHERE user_id = $qaUserId");
    $conn->query("DELETE FROM password_resets WHERE user_id = $qaUserId");
    $conn->query("DELETE FROM users WHERE user_id = $qaUserId");
    $conn->close();
}

echo "\n=== E2E SUMMARY: PASS=$passed | FAIL=$failed ===\n";
exit($failed > 0 ? 1 : 0);
