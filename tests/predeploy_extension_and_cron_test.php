<?php
/**
 * ANPLAY STORE - Pre-Deployment Rental Extension & Expiration Revocation E2E Test (CLI)
 * Run: C:\xampp\php\php.exe tests/predeploy_extension_and_cron_test.php
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
if ($conn->connect_error) exit("DB connect failed: " . $conn->connect_error . "\n");

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
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([a-f0-9]+)["\']/i', $html, $m)) return $m[1];
    if (preg_match('/value=["\']([a-f0-9]+)["\']\s+name=["\']csrf_token["\']/i', $html, $m)) return $m[1];
    return '';
}

echo "=== ANPLAY STORE PRE-DEPLOY EXTENSION & CRON REVOCATION E2E ===\n";

$ts = time();
$qaUser = "qa_ext_$ts";
$qaEmail = "$qaUser@example.local";
$qaPass = 'QaExtPass!123';
$qaHash = password_hash($qaPass, PASSWORD_DEFAULT);

$st = $conn->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 0, 'active')");
$st->bind_param("sss", $qaUser, $qaEmail, $qaHash);
$st->execute();
$qaUserId = (int)$conn->insert_id;
$st->close();

$gameRow = $conn->query("SELECT g.game_id, rp.package_id, rp.price FROM games g JOIN rental_packages rp ON g.game_id = rp.game_id AND rp.is_active = 1 LIMIT 1")->fetch_assoc();
$qaGameId = (int)$gameRow['game_id'];
$qaPkgId = (int)$gameRow['package_id'];
$qaPrice = (float)$gameRow['price'];

$encPass = encrypt_game_password('QaExtSteam!99');
$accUser = "qa_ext_acc_$ts";
$stA = $conn->prepare("INSERT INTO game_accounts (game_id, account_username, account_password_encrypted, status) VALUES (?, ?, ?, 'AVAILABLE')");
$stA->bind_param("iss", $qaGameId, $accUser, $encPass);
$stA->execute();
$qaAccId = (int)$conn->insert_id;
$stA->close();

$cookie = tempnam(sys_get_temp_dir(), 'qa_ext_');
$orderId = 0;
$extOrderId = 0;

try {
    // 1. Login
    httpReq($baseUrl . '/login.php', 'POST', ['btn_login' => '1', 'username' => $qaUser, 'password' => $qaPass], [], $cookie);

    // 2. Create & activate initial rental
    $bpGet = httpReq($baseUrl . "/borrow_process.php?game_id=$qaGameId", 'GET', [], [], $cookie);
    $bpPost = httpReq($baseUrl . "/borrow_process.php?game_id=$qaGameId", 'POST', [
        'csrf_token' => extractCsrf($bpGet['body']),
        'package_id' => $qaPkgId,
        'btn_borrow' => '1',
    ], [], $cookie);
    if (preg_match('/Location:\s*.*checkout_qr\.php\?order_id=(\d+)/i', $bpPost['headers'], $m)) {
        $orderId = (int)$m[1];
    }
    $ordRow = $conn->query("SELECT order_code FROM borrow_records WHERE borrow_id = $orderId")->fetch_assoc();
    $payload1 = json_encode(['order_code' => $ordRow['order_code'], 'amount' => $qaPrice, 'payment_tx_id' => "QA_EXT1_$ts"]);
    $sig1 = hash_hmac('sha256', $payload1, $secrets['payment_webhook_secret']);
    httpReq($baseUrl . '/api_payment_webhook.php', 'POST', $payload1, ['Content-Type: application/json', "X-Webhook-Signature: $sig1"]);

    $beforeOrd = $conn->query("SELECT due_date, account_id FROM borrow_records WHERE borrow_id = $orderId")->fetch_assoc();
    $allocAccId = (int)$beforeOrd['account_id'];
    $beforeAcc = $conn->query("SELECT rent_expires_at, status FROM game_accounts WHERE account_id = $allocAccId")->fetch_assoc();
    res('EXT-01', 'Initial rental activated & account allocated', ($allocAccId > 0 && $beforeAcc['status'] === 'RENTED'), "borrow_id=$orderId due={$beforeOrd['due_date']}");

    // 3. Submit extension from my_library.php / checkout_qr.php
    $libGet = httpReq($baseUrl . '/my_library.php', 'GET', [], [], $cookie);
    $libCsrf = extractCsrf($libGet['body']);
    $extPost = httpReq($baseUrl . '/checkout_qr.php', 'POST', [
        'csrf_token' => $libCsrf,
        'borrow_id_to_extend' => $orderId,
        'extend_pkg_id' => $qaPkgId,
        'btn_extend_submit' => '1',
    ], [], $cookie);
    if (preg_match('/Location:\s*.*checkout_qr\.php\?order_id=(\d+)/i', $extPost['headers'], $m2)) {
        $extOrderId = (int)$m2[1];
    }
    $extRow = $conn->query("SELECT order_code, rent_type, previous_borrow_id, rental_price FROM borrow_records WHERE borrow_id = $extOrderId")->fetch_assoc();
    res('EXT-02', 'Extension order created with rent_type=extend & previous_borrow_id', ($extOrderId > 0 && $extRow['rent_type'] === 'extend' && (int)$extRow['previous_borrow_id'] === $orderId), "ext_id=$extOrderId");

    // 4. Activate extension via signed webhook
    $payload2 = json_encode(['order_code' => $extRow['order_code'], 'amount' => (float)$extRow['rental_price'], 'payment_tx_id' => "QA_EXT2_$ts"]);
    $sig2 = hash_hmac('sha256', $payload2, $secrets['payment_webhook_secret']);
    $wh2 = httpReq($baseUrl . '/api_payment_webhook.php', 'POST', $payload2, ['Content-Type: application/json', "X-Webhook-Signature: $sig2"]);
    $afterOrd = $conn->query("SELECT due_date FROM borrow_records WHERE borrow_id = $orderId")->fetch_assoc();
    $afterExt = $conn->query("SELECT status, payment_status FROM borrow_records WHERE borrow_id = $extOrderId")->fetch_assoc();
    $afterAcc = $conn->query("SELECT rent_expires_at FROM game_accounts WHERE account_id = $allocAccId")->fetch_assoc();

    $dueIncreased = (strtotime($afterOrd['due_date']) > strtotime($beforeOrd['due_date']));
    $accIncreased = (strtotime($afterAcc['rent_expires_at']) > strtotime($beforeAcc['rent_expires_at']));
    $histPreserved = ($afterExt['payment_status'] === 'paid' && in_array($afterExt['status'], ['extended', 'active'], true));
    res('EXT-03', 'Extension increases due_date & rent_expires_at and preserves history', ($dueIncreased && $accIncreased && $histPreserved), "before={$beforeOrd['due_date']} after={$afterOrd['due_date']} ext_status={$afterExt['status']}");

    // 5. Expire rental & run cron_process_expirations.php
    $conn->query("UPDATE borrow_records SET due_date = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE borrow_id IN ($orderId, $extOrderId)");
    $conn->query("UPDATE game_accounts SET rent_expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE account_id = $allocAccId");

    $cronOut = [];
    $cronExit = 0;
    exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($rootDir . '/cron_process_expirations.php') . ' 2>&1', $cronOut, $cronExit);

    $revOrd = $conn->query("SELECT status, return_date FROM borrow_records WHERE borrow_id = $orderId")->fetch_assoc();
    $revAcc = $conn->query("SELECT status, current_borrow_id FROM game_accounts WHERE account_id = $allocAccId")->fetch_assoc();
    $revokedOk = ($cronExit === 0 && $revOrd['status'] === 'expired' && !empty($revOrd['return_date']) && $revAcc['status'] === 'AVAILABLE');
    res('CRON-01', 'cron_process_expirations.php revokes expired rental & releases account', $revokedOk, "ord_status={$revOrd['status']} return_date={$revOrd['return_date']} acc_status={$revAcc['status']}");

    // 6. Verify download_game.php blocks expired rental
    $dlAfter = httpReq($baseUrl . "/download_game.php?id=$qaGameId", 'GET', [], [], $cookie);
    $dlBlocked = (stripos($dlAfter['body'], 'QaExtSteam!99') === false && ($dlAfter['code'] === 302 || stripos($dlAfter['body'], 'hết hạn') !== false || stripos($dlAfter['body'], 'chưa thuê') !== false || stripos($dlAfter['body'], 'Thuê') !== false));
    res('CRON-02', 'download_game.php blocks access after expiration revocation', $dlBlocked, "HTTP={$dlAfter['code']}");

} finally {
    @unlink($cookie);
    $delIds = array_filter([$orderId, $extOrderId]);
    if ($delIds) {
        $in = implode(',', $delIds);
        $conn->query("DELETE FROM account_allocation_logs WHERE borrow_id IN ($in)");
        $conn->query("DELETE FROM borrow_records WHERE borrow_id IN ($in)");
    }
    $conn->query("DELETE FROM account_allocation_logs WHERE account_id = $qaAccId");
    $conn->query("DELETE FROM game_accounts WHERE account_id = $qaAccId");
    $conn->query("DELETE FROM notifications WHERE user_id = $qaUserId");
    $conn->query("DELETE FROM users WHERE user_id = $qaUserId");
    $conn->close();
}

echo "\n=== EXTENSION & CRON REVOCATION SUMMARY: PASS=$passed | FAIL=$failed ===\n";
exit($failed > 0 ? 1 : 0);
