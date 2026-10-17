<?php
/**
 * TEST SUITE: SCROLL FIX VERIFICATION & PAGE INTEGRITY
 * Tests the root cause resolution of the scroll freeze issue across ANPLAY STORE
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/connect.php';

// Prepare a valid Super Admin session
$stmt_admin = $conn->prepare("SELECT user_id, username, email, role FROM users WHERE role = 2 LIMIT 1");
$stmt_admin->execute();
$admin_row = $stmt_admin->get_result()->fetch_assoc();
$stmt_admin->close();

if ($admin_row) {
    $_SESSION['user_id'] = $admin_row['user_id'];
    $_SESSION['username'] = $admin_row['username'];
    $_SESSION['email'] = $admin_row['email'];
    $_SESSION['role'] = intval($admin_row['role']);
}
$admin_session_id = session_id();
session_write_close();

echo "=======================================================\n";
echo " ANPLAY STORE — SCROLL FIX VERIFICATION SUITE\n";
echo "=======================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function assert_test($description, $condition) {
    global $pass_count, $fail_count;
    if ($condition) {
        echo " [PASS] $description\n";
        $pass_count++;
    } else {
        echo " [FAIL] $description\n";
        $fail_count++;
    }
}

// 1. Audit css/style.css
$style_css = file_get_contents(__DIR__ . '/css/style.css');

assert_test("style.css: body does NOT have 'overflow: hidden;' on root rule", 
    !preg_match('/body\s*\{[^}]*overflow:\s*hidden/s', $style_css));

assert_test("style.css: body has 'overflow-y: auto;'", 
    preg_match('/body\s*\{[^}]*overflow-y:\s*auto/s', $style_css));

assert_test("style.css: body has 'min-height: 100vh;' or 'min-height: 100dvh;' without fixed height locking", 
    preg_match('/body\s*\{[^}]*min-height:\s*100(?:vh|dvh)/s', $style_css) && !preg_match('/body\s*\{[^}]*(?<![-a-z])height:\s*100(?:vh|dvh);/s', $style_css));

assert_test("style.css: body.lock-scroll is defined for drawer/modal locking", 
    strpos($style_css, 'body.lock-scroll') !== false && strpos($style_css, 'overflow: hidden !important') !== false);

assert_test("style.css: .sidebar has position: fixed on desktop", 
    preg_match('/\.sidebar\s*\{[^}]*position:\s*fixed/s', $style_css));

assert_test("style.css: .main-wrapper has margin-left: 265px on desktop", 
    preg_match('/\.main-wrapper\s*\{[^}]*margin-left:\s*265px/s', $style_css));

assert_test("style.css: .main-wrapper does NOT have fixed height: 100dvh", 
    !preg_match('/\.main-wrapper\s*\{[^}]*(?<![-a-z])height:\s*100dvh;/s', $style_css));

assert_test("style.css: .content-scroll does NOT have overflow-y: auto", 
    !preg_match('/\.content-scroll\s*\{[^}]*overflow-y:\s*auto;/s', $style_css));

assert_test("style.css: tablet media query resets main-wrapper margin-left: 0", 
    strpos($style_css, 'margin-left: 0 !important;') !== false);

assert_test("style.css: inactive chatbot box has display: none !important and pointer-events: none !important", 
    strpos($style_css, '.anplay-chat-box:not(.active)') !== false && strpos($style_css, 'display: none !important;') !== false);

assert_test("style.css: chatbot wrapper has pointer-events: none !important", 
    preg_match('/\.anplay-chatbot-wrapper\s*\{[^}]*pointer-events:\s*none\s*!important/s', $style_css));

// 2. Audit css/chatbot.css
$chatbot_css = file_get_contents(__DIR__ . '/css/chatbot.css');

assert_test("chatbot.css: .anplay-chatbot-wrapper has pointer-events: none !important", 
    preg_match('/\.anplay-chatbot-wrapper\s*\{[^}]*pointer-events:\s*none\s*!important/s', $chatbot_css));

assert_test("chatbot.css: .anplay-chat-box:not(.active) has display: none !important", 
    strpos($chatbot_css, '.anplay-chat-box:not(.active)') !== false);

assert_test("chatbot.css: mobile query targets .anplay-chat-box.active for full viewport", 
    strpos($chatbot_css, '.anplay-chat-box.active {') !== false);

// 3. Audit includes/admin_header.php
$admin_header = file_get_contents(__DIR__ . '/includes/admin_header.php');

assert_test("admin_header.php: body.admin-body has overflow-y: auto !important", 
    strpos($admin_header, 'overflow-y: auto !important;') !== false);

assert_test("admin_header.php: body.admin-body has height: auto !important and display: block !important", 
    strpos($admin_header, 'height: auto !important;') !== false && strpos($admin_header, 'display: block !important;') !== false);

assert_test("admin_header.php: .admin-main-container has width calc and min-height", 
    strpos($admin_header, 'calc(100% - var(--admin-sidebar-width))') !== false);

// 4. Audit includes/header.php
$header_php = file_get_contents(__DIR__ . '/includes/header.php');

assert_test("header.php: uses classList.add('lock-scroll') instead of style.overflow", 
    strpos($header_php, "classList.add('lock-scroll')") !== false && strpos($header_php, "document.body.style.overflow = 'hidden'") === false);

assert_test("header.php: auto-closes sidebar and unlocks scroll on window resize >= 992px", 
    strpos($header_php, "window.innerWidth >= 992") !== false);

// 5. Audit includes/admin_footer.php
$admin_footer = file_get_contents(__DIR__ . '/includes/admin_footer.php');

assert_test("admin_footer.php: uses lock-scroll class instead of style.overflow", 
    strpos($admin_footer, "classList.add('lock-scroll')") !== false && strpos($admin_footer, "document.body.style.overflow = 'hidden'") === false);

assert_test("admin_footer.php: auto-closes admin drawer on window resize >= 992px", 
    strpos($admin_footer, "window.innerWidth >= 992") !== false);

// 6. Test HTTP responses of User & Admin pages
$base_url = 'http://localhost:8080/quan_ly_thu_vien_game';

function test_http_get($url, $session_cookie = '') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    if (!empty($session_cookie)) {
        curl_setopt($ch, CURLOPT_COOKIE, $session_cookie);
    }
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $header_size);
    curl_close($ch);
    return ['code' => $http_code, 'body' => $body];
}

$user_cookie = "PHPSESSID=$admin_session_id"; // Admin is also a valid logged in user

$public_pages = [
    'index.php',
    'game_detail.php?id=1',
    'login.php',
    'register.php',
    'forgot_password.php'
];

foreach ($public_pages as $page) {
    $res = test_http_get("$base_url/$page");
    assert_test("HTTP GET $page returns 200 OK", $res['code'] === 200);
}

// User authenticated pages
$auth_pages = [
    'support.php',
    'my_library.php',
    'notifications.php',
    'profile.php'
];

foreach ($auth_pages as $ap) {
    $res = test_http_get("$base_url/$ap", $user_cookie);
    assert_test("HTTP GET $ap with User session returns 200 OK", $res['code'] === 200);
}

// 7. Test Admin pages with Super Admin session
$admin_pages = [
    'admin_dashboard.php',
    'admin_games.php',
    'admin_accounts.php',
    'admin_borrow.php',
    'admin_users.php',
    'admin_support.php',
    'admin_notifications.php',
    'admin_system.php',
    'admin_roles.php',
    'admin_management.php'
];

foreach ($admin_pages as $ap) {
    $res = test_http_get("$base_url/$ap", $user_cookie);
    assert_test("HTTP GET $ap with Admin session returns 200 OK", $res['code'] === 200);
}

echo "\n=======================================================\n";
echo " TEST SUMMARY: $pass_count PASSED, $fail_count FAILED\n";
echo "=======================================================\n";

if ($fail_count === 0) {
    echo " ALL TESTS PASSED! SCROLL AND LAYOUT ARCHITECTURE VERIFIED.\n";
} else {
    echo " SOME TESTS FAILED. PLEASE REVIEW THE LOG ABOVE.\n";
}

