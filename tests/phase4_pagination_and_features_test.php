<?php
/**
 * ANPLAY STORE - Phase 4 Pagination, Features & Legacy Cleanup Verification (CLI)
 * Run: C:\xampp\php\php.exe tests/phase4_pagination_and_features_test.php
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
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
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

echo "=== ANPLAY STORE PHASE 4 PAGINATION & FEATURES ===\n";

// 1. Legacy & Root Cleanup Check
$legacyFiles = glob($rootDir . '/test_*.php');
$removedFiles = ['seed_accounts_auto.php', 'chatbot_handler.php', 'api/chatbot.php'];
$strayFiles = [];
foreach ($legacyFiles as $lf) $strayFiles[] = basename($lf);
foreach ($removedFiles as $rf) {
    if (file_exists($rootDir . '/' . $rf)) $strayFiles[] = $rf;
}
res('LEG-01', 'Web root clean of test_*.php, seed_accounts_auto.php, legacy chatbot handlers', empty($strayFiles), implode(', ', $strayFiles));

$legacyDir = glob($rootDir . '/tests/legacy/test_*.php');
res('LEG-02', 'Legacy tests relocated to tests/legacy/', count($legacyDir) >= 14, 'count=' . count($legacyDir));

// 2. Create temporary Super Admin (role=2) and User (role=0) + sample notifications/tickets for pagination
$ts = time();
$adminUser = "qa_super_$ts";
$adminEmail = "$adminUser@example.local";
$passPlain = 'QaPhase4Pass!123';
$passHash = password_hash($passPlain, PASSWORD_DEFAULT);

$st = $conn->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 2, 'active')");
$st->bind_param("sss", $adminUser, $adminEmail, $passHash);
$st->execute();
$adminId = (int)$conn->insert_id;
$st->close();

$custUser = "qa_cust_$ts";
$custEmail = "$custUser@example.local";
$st = $conn->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 0, 'active')");
$st->bind_param("sss", $custUser, $custEmail, $passHash);
$st->execute();
$custId = (int)$conn->insert_id;
$st->close();

// Seed 12 notifications for $custId so page 1 (10) & page 2 (2) are exercised
$notifIds = [];
for ($i = 1; $i <= 12; $i++) {
    $title = "QA Pag Notif #$i";
    $msg = "Body $i";
    $sn = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, is_read, created_at) VALUES (?, 'system', ?, ?, 0, NOW())");
    $sn->bind_param("iss", $custId, $title, $msg);
    $sn->execute();
    $notifIds[] = (int)$conn->insert_id;
    $sn->close();
}

$cookieAdmin = tempnam(sys_get_temp_dir(), 'qa_adm_');
$cookieCust = tempnam(sys_get_temp_dir(), 'qa_cus_');
$createdTicketId = 0;

try {
    // Login Admin & Customer
    httpReq($baseUrl . '/login.php', 'POST', ['btn_login' => '1', 'username' => $adminUser, 'password' => $passPlain], [], $cookieAdmin);
    httpReq($baseUrl . '/login.php', 'POST', ['btn_login' => '1', 'username' => $custUser, 'password' => $passPlain], [], $cookieCust);

    // 3. Test all 12 paginated pages (default URL with no params, page=1, page=2, per_page=50, empty filter)
    $paginatedEndpoints = [
        ['PAG-01', 'index.php', '/index.php', '/index.php?page=2&per_page=10', '/index.php?search=zzz_qa_nonexistent_999', null, true],
        ['PAG-02', 'my_library.php', '/my_library.php', '/my_library.php?page=2&per_page=10', null, $cookieCust, true],
        ['PAG-03', 'support.php', '/support.php', '/support.php?page=2&per_page=10', null, $cookieCust, false],
        ['PAG-04', 'notifications.php', '/notifications.php', '/notifications.php?page=2&per_page=10', null, $cookieCust, true],
        ['PAG-05', 'admin_users.php', '/admin_users.php', '/admin_users.php?page=2&per_page=10', '/admin_users.php?search=zzz_qa_nonexistent_999', $cookieAdmin, true],
        ['PAG-06', 'admin_games.php', '/admin_games.php', '/admin_games.php?page=2&per_page=10', '/admin_games.php?search=zzz_qa_nonexistent_999', $cookieAdmin, true],
        ['PAG-07', 'admin_accounts.php', '/admin_accounts.php', '/admin_accounts.php?page=2&per_page=10', null, $cookieAdmin, true],
        ['PAG-08', 'admin_borrow.php', '/admin_borrow.php', '/admin_borrow.php?page=2&per_page=10', null, $cookieAdmin, true],
        ['PAG-09', 'admin_notifications.php', '/admin_notifications.php', '/admin_notifications.php?page=2&per_page=10', null, $cookieAdmin, true],
        ['PAG-10', 'admin_support.php', '/admin_support.php', '/admin_support.php?page=2&per_page=10', null, $cookieAdmin, true],
        ['PAG-11', 'admin_management.php', '/admin_management.php', '/admin_management.php?page=2&per_page=10', null, $cookieAdmin, true],
        ['PAG-12', 'admin_system.php', '/admin_system.php', '/admin_system.php?page=2&per_page=10', null, $cookieAdmin, true],
    ];

    foreach ($paginatedEndpoints as [$id, $label, $p1, $p2, $emptyUrl, $ck, $requireBar]) {
        $r1 = httpReq($baseUrl . $p1, 'GET', [], [], $ck);
        $r2 = httpReq($baseUrl . $p2, 'GET', [], [], $ck);
        $sep = (strpos($p1, '?') === false) ? '?' : '&';
        $r50 = httpReq($baseUrl . $p1 . $sep . 'page=1&per_page=50', 'GET', [], [], $ck);
        $hasBar = (strpos($r1['body'], 'anplay-pagination') !== false || strpos($r1['body'], 'bản ghi') !== false);
        $noFatal = (stripos($r1['body'], 'Fatal error') === false && stripos($r2['body'], 'Fatal error') === false && stripos($r50['body'], 'Fatal error') === false);
        $emptyOk = true;
        $emptyDetail = '';
        if ($emptyUrl !== null) {
            $re = httpReq($baseUrl . $emptyUrl, 'GET', [], [], $ck);
            $emptyNoFatal = (stripos($re['body'], 'Fatal error') === false);
            $emptyOk = ($re['code'] === 200 && $emptyNoFatal);
            $emptyDetail = " empty={$re['code']}" . ($emptyNoFatal ? '' : '(FATAL)');
        }
        $barOk = $requireBar ? $hasBar : true;
        $ok = ($r1['code'] === 200 && $r2['code'] === 200 && $r50['code'] === 200 && $barOk && $noFatal && $emptyOk);
        res($id, "Pagination & filters on $label", $ok, "p1={$r1['code']} p2={$r2['code']} p50={$r50['code']} bar=" . ($hasBar ? 'yes' : 'no') . " fatal=" . ($noFatal ? 'no' : 'YES') . $emptyDetail);
    }

    // 4. Verify notifications.php Page 1 vs Page 2 zero duplicate/missing items
    $n1 = httpReq($baseUrl . '/notifications.php?page=1&per_page=10', 'GET', [], [], $cookieCust);
    $n2 = httpReq($baseUrl . '/notifications.php?page=2&per_page=10', 'GET', [], [], $cookieCust);
    preg_match_all('/QA Pag Notif #(\d+)/', $n1['body'], $m1);
    preg_match_all('/QA Pag Notif #(\d+)/', $n2['body'], $m2);
    $allSeen = array_unique(array_merge($m1[1] ?? [], $m2[1] ?? []));
    $noOverlap = empty(array_intersect($m1[1] ?? [], $m2[1] ?? []));
    res('PAG-13', 'notifications.php Page 1 (10) + Page 2 (2) = 12 unique items, 0 overlap', (count($m1[1] ?? []) === 10 && count($m2[1] ?? []) === 2 && count($allSeen) === 12 && $noOverlap), 'p1=' . count($m1[1] ?? []) . ' p2=' . count($m2[1] ?? []));

    // 5. AI Chatbot assistant.php CSRF & health check
    $botNoCsrf = httpReq($baseUrl . '/assistant.php', 'POST', json_encode(['message' => 'Xin chao']), ['Content-Type: application/json'], $cookieCust);
    res('CHAT-01', 'assistant.php rejects POST without chat_csrf_token', in_array($botNoCsrf['code'], [400, 403], true), "HTTP {$botNoCsrf['code']}");

    $idxBody = httpReq($baseUrl . '/index.php', 'GET', [], [], $cookieCust)['body'];
    $chatCsrf = '';
    if (preg_match('/name=["\']chat-csrf["\']\s+content=["\']([a-f0-9]+)["\']/i', $idxBody, $cm)) {
        $chatCsrf = $cm[1];
    }
    $botHealth = httpReq($baseUrl . '/assistant.php', 'POST', [
        'action' => 'health_check',
        'csrf_token' => $chatCsrf,
    ], [], $cookieCust);
    res('CHAT-02', 'assistant.php POST health_check with chat-csrf passes CSRF gate', ($chatCsrf !== '' && in_array($botHealth['code'], [200, 503], true)), "csrf_len=" . strlen($chatCsrf) . " health_http={$botHealth['code']}");

    // 6. Support Ticket Creation + Reply
    $supGet = httpReq($baseUrl . '/support.php', 'GET', [], [], $cookieCust);
    $supCsrf = extractCsrf($supGet['body']);
    $supPost = httpReq($baseUrl . '/support.php', 'POST', [
        'csrf_token' => $supCsrf,
        'btn_create_ticket' => '1',
        'category' => 'other',
        'priority' => 'normal',
        'title' => "QA Ticket $ts",
        'message' => 'Testing support ticket creation in Phase 4.',
    ], [], $cookieCust);
    $tRow = $conn->query("SELECT ticket_id FROM support_tickets WHERE user_id = $custId ORDER BY ticket_id DESC LIMIT 1")->fetch_assoc();
    $createdTicketId = $tRow ? (int)$tRow['ticket_id'] : 0;
    res('FEAT-01', 'Support ticket creation (support.php) succeeds', $createdTicketId > 0, "ticket_id=$createdTicketId");

} finally {
    @unlink($cookieAdmin);
    @unlink($cookieCust);
    if ($createdTicketId > 0) {
        $conn->query("DELETE FROM support_messages WHERE ticket_id = $createdTicketId");
        $conn->query("DELETE FROM support_tickets WHERE ticket_id = $createdTicketId");
    }
    $conn->query("DELETE FROM notifications WHERE user_id IN ($adminId, $custId)");
    $conn->query("DELETE FROM users WHERE user_id IN ($adminId, $custId)");
    $conn->close();
}

echo "\n=== PHASE 4 PAGINATION & FEATURES SUMMARY: PASS=$passed | FAIL=$failed ===\n";
exit($failed > 0 ? 1 : 0);
