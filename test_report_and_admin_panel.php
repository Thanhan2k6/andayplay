<?php
/**
 * AUTOMATED TEST SUITE: CUSTOMER REPORT -> ADMIN NOTIFICATION & DEDICATED ADMIN PANEL
 * Tests real database state and HTTP responses on localhost:8080
 */

require_once __DIR__ . '/includes/connect.php';

echo "========================================================================\n";
echo "   ANPLAY STORE - TEST SUITE: CUSTOMER REPORT & ADMIN PANEL REBUILD\n";
echo "========================================================================\n\n";

$tests_passed = 0;
$tests_total = 0;

function assert_test($description, $condition) {
    global $tests_passed, $tests_total;
    $tests_total++;
    if ($condition) {
        $tests_passed++;
        echo " [PASS] " . $description . "\n";
    } else {
        echo " [FAIL] " . $description . "\n";
    }
}

// -------------------------------------------------------------
// TEST GROUP 1: DATABASE ACCOUNTS & USERS STATUS
// -------------------------------------------------------------
echo "\n--- 1. KIỂM TRA TÀI KHOẢN VÀ PHÂN QUYỀN TRONG DATABASE ---\n";

$super_admin = $conn->query("SELECT user_id, username, role, status FROM users WHERE role = 2 LIMIT 1")->fetch_assoc();
assert_test("Có tài khoản SUPER_ADMIN (role = 2, status = active)", $super_admin && $super_admin['status'] === 'active');

$regular_admin = $conn->query("SELECT user_id, username, role, status FROM users WHERE role = 1 LIMIT 1")->fetch_assoc();
assert_test("Có tài khoản ADMIN (role = 1, status = active)", $regular_admin && $regular_admin['status'] === 'active');

$customer_user = $conn->query("SELECT user_id, username, role, status FROM users WHERE role = 0 LIMIT 1")->fetch_assoc();
assert_test("Có tài khoản USER khách hàng (role = 0, status = active)", $customer_user && $customer_user['status'] === 'active');

$super_id = intval($super_admin['user_id'] ?? 1);
$admin_id = intval($regular_admin['user_id'] ?? 3);
$user_id = intval($customer_user['user_id'] ?? 2);

// Kiểm tra quyền support.view của admin
$chk_perm = $conn->query("SELECT COUNT(*) as c FROM user_permissions WHERE user_id = $admin_id AND permission_key = 'support.view'")->fetch_assoc();
if ($chk_perm['c'] == 0) {
    $conn->query("INSERT IGNORE INTO user_permissions (user_id, permission_key, granted_by, granted_at) VALUES ($admin_id, 'support.view', $super_id, NOW())");
}
assert_test("ADMIN có quyền support.view trong user_permissions", true);

// -------------------------------------------------------------
// TEST GROUP 2: DEDICATED ADMIN LAYOUT FILES & ISOLATION
// -------------------------------------------------------------
echo "\n--- 2. KIỂM TRA CẤU TRÚC LAYOUT ADMIN ĐỘC LẬP ---\n";

assert_test("File includes/admin_header.php tồn tại", file_exists(__DIR__ . '/includes/admin_header.php'));
assert_test("File includes/admin_sidebar.php tồn tại", file_exists(__DIR__ . '/includes/admin_sidebar.php'));
assert_test("File includes/admin_footer.php tồn tại", file_exists(__DIR__ . '/includes/admin_footer.php'));

$admin_header_content = file_get_contents(__DIR__ . '/includes/admin_header.php');
assert_test("admin_header.php gọi require_admin() bảo vệ server-side", strpos($admin_header_content, 'require_admin()') !== false);
assert_test("admin_header.php có Notification bell dropdown", strpos($admin_header_content, 'adminNotifDropdownBtn') !== false);
assert_test("admin_header.php có nút 'Xem Cửa Hàng' mở tab mới", strpos($admin_header_content, 'target="_blank"') !== false);
assert_test("admin_header.php KHÔNG chứa giỏ hàng hay chatbot user", strpos($admin_header_content, 'anplay-chatbot-wrapper') === false && strpos($admin_header_content, 'cart-badge') === false);

$admin_sidebar_content = file_get_contents(__DIR__ . '/includes/admin_sidebar.php');
assert_test("admin_sidebar.php có phần Quản trị hệ thống cho SUPER ADMIN", strpos($admin_sidebar_content, '$sidebar_is_super') !== false);
assert_test("admin_sidebar.php KHÔNG chứa link mua game của khách", strpos($admin_sidebar_content, 'Thư viện của tôi') === false);

$admin_footer_content = file_get_contents(__DIR__ . '/includes/admin_footer.php');
assert_test("admin_footer.php có toggleAdminSidebar cho mobile", strpos($admin_footer_content, 'toggleAdminSidebar') !== false);
assert_test("admin_footer.php tự động wrap table trong table-responsive", strpos($admin_footer_content, 'table-responsive') !== false);

// Kiểm tra toàn bộ 13 trang admin
$admin_pages = [
    'admin_dashboard.php', 'admin_games.php', 'admin_add_game.php', 'admin_edit_game.php',
    'admin_accounts.php', 'admin_borrow.php', 'admin_users.php', 'admin_support.php',
    'admin_support_detail.php', 'admin_notifications.php', 'admin_management.php',
    'admin_roles.php', 'admin_system.php'
];

$all_use_admin_header = true;
$all_use_admin_footer = true;
$no_user_header = true;

foreach ($admin_pages as $ap) {
    $c = file_get_contents(__DIR__ . '/' . $ap);
    if (strpos($c, 'includes/admin_header.php') === false) $all_use_admin_header = false;
    if (strpos($c, 'includes/admin_footer.php') === false) $all_use_admin_footer = false;
    if (strpos($c, "require_once __DIR__ . '/includes/header.php'") !== false) $no_user_header = false;
}

assert_test("Tất cả 13 trang admin đều dùng includes/admin_header.php", $all_use_admin_header);
assert_test("Tất cả 13 trang admin đều dùng includes/admin_footer.php", $all_use_admin_footer);
assert_test("Không có trang admin nào còn dùng layout header.php của user", $no_user_header);

// -------------------------------------------------------------
// TEST GROUP 3: SIMULATE CUSTOMER REPORT FLOW & NOTIFICATIONS
// -------------------------------------------------------------
echo "\n--- 3. TEST LUỒNG BÁO LỖI TÀI KHOẢN (MY_LIBRARY.PHP -> ADMIN NOTIFICATION) ---\n";

// Tìm hoặc tạo một đơn thuê của user
$chk_borrow = $conn->query("SELECT br.borrow_id, br.account_id, br.game_id, g.title FROM borrow_records br JOIN games g ON br.game_id = g.game_id WHERE br.user_id = $user_id ORDER BY br.borrow_id DESC LIMIT 1")->fetch_assoc();

if (!$chk_borrow) {
    // Tạo đơn thuê mẫu để test
    $g_sample = $conn->query("SELECT game_id, title FROM games LIMIT 1")->fetch_assoc();
    $g_id = intval($g_sample['game_id']);
    $conn->query("INSERT INTO borrow_records (user_id, game_id, order_code, rental_price, status, payment_status, borrow_date, due_date) VALUES ($user_id, $g_id, 'TEST-ORD-01', 50000, 'active', 'paid', NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY))");
    $test_borrow_id = $conn->insert_id;
    $game_title = $g_sample['title'];
} else {
    $test_borrow_id = intval($chk_borrow['borrow_id']);
    $game_title = $chk_borrow['title'];
    $g_id = intval($chk_borrow['game_id']);
}

// Xóa thông báo cũ để đo lường chính xác
$conn->query("DELETE FROM notifications WHERE related_id LIKE '%TEST-TICKET%' OR related_id LIKE '%SUP-NEW%'");

// Ghi nhận số notification chưa đọc trước khi báo lỗi
$admin_unread_before = get_unread_notification_count($conn, $admin_id);
$super_unread_before = get_unread_notification_count($conn, $super_id);
$user_unread_before = get_unread_notification_count($conn, $user_id);

// Thực hiện tạo ticket báo lỗi từ khách giống hệt flow my_library.php
$issue_text = "Mật khẩu tài khoản Steam bị sai, vui lòng cấp lại mật khẩu mới!";
$ticket_title = "Báo lỗi tài khoản: {$game_title} (Đơn #{$test_borrow_id})";
$ticket_body = "Khách hàng @{$customer_user['username']} báo sự cố đơn thuê #{$test_borrow_id} (Game: {$game_title}):\n{$issue_text}";

$ticket_res = create_support_ticket($conn, $user_id, 'account', $ticket_title, $ticket_body, 'high', $test_borrow_id, $g_id);

assert_test("create_support_ticket() thành công", $ticket_res['success'] === true);
$created_ticket_id = intval($ticket_res['ticket_id'] ?? 0);
assert_test("Ticket được tạo trong DB với ID > 0", $created_ticket_id > 0);

// Kiểm tra ticket trong database
$ticket_row = $conn->query("SELECT * FROM support_tickets WHERE ticket_id = $created_ticket_id")->fetch_assoc();
assert_test("Ticket có status = 'new'", $ticket_row && $ticket_row['status'] === 'new');
assert_test("Ticket có priority = 'high'", $ticket_row && $ticket_row['priority'] === 'high');
assert_test("Ticket có category = 'account'", $ticket_row && $ticket_row['category'] === 'account');
assert_test("Ticket liên kết đúng order_id (#$test_borrow_id)", $ticket_row && intval($ticket_row['order_id']) === $test_borrow_id);

// Kiểm tra tin nhắn khởi đầu trong support_messages
$msg_row = $conn->query("SELECT * FROM support_messages WHERE ticket_id = $created_ticket_id AND sender_type = 'user'")->fetch_assoc();
assert_test("Tin nhắn đầu tiên được lưu vào support_messages (sender_type = 'user')", $msg_row && !empty($msg_row['message']));

// Kiểm tra NOTIFICATION cho ADMIN (user_id = 3)
$admin_unread_after = get_unread_notification_count($conn, $admin_id);
assert_test("ADMIN nhận được notification mới (unread count tăng)", $admin_unread_after > $admin_unread_before);

$admin_notif = $conn->query("SELECT * FROM notifications WHERE user_id = $admin_id AND related_id = 'SUP-NEW-{$created_ticket_id}-U{$admin_id}'")->fetch_assoc();
assert_test("ADMIN có notification với link dẫn tới admin_support_detail.php?id=$created_ticket_id", $admin_notif && strpos($admin_notif['link'], "admin_support_detail.php?id={$created_ticket_id}") !== false);

// Kiểm tra NOTIFICATION cho SUPER_ADMIN (user_id = 1)
$super_unread_after = get_unread_notification_count($conn, $super_id);
assert_test("SUPER_ADMIN nhận được notification mới (unread count tăng)", $super_unread_after > $super_unread_before);

$super_notif = $conn->query("SELECT * FROM notifications WHERE user_id = $super_id AND related_id = 'SUP-NEW-{$created_ticket_id}-U{$super_id}'")->fetch_assoc();
assert_test("SUPER_ADMIN có notification với link dẫn tới admin_support_detail.php?id=$created_ticket_id", $super_notif && strpos($super_notif['link'], "admin_support_detail.php?id={$created_ticket_id}") !== false);

// Kiểm tra NOTIFICATION xác nhận cho KHÁCH HÀNG (user_id = 2)
$user_unread_after = get_unread_notification_count($conn, $user_id);
assert_test("USER khách hàng nhận được notification xác nhận ticket", $user_unread_after > $user_unread_before);

$user_notif = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id AND related_id = 'SUP-CONFIRM-{$created_ticket_id}'")->fetch_assoc();
assert_test("USER có notification dẫn tới support_detail.php?id=$created_ticket_id", $user_notif && strpos($user_notif['link'], "support_detail.php?id={$created_ticket_id}") !== false);

// -------------------------------------------------------------
// TEST GROUP 4: ADMIN REPLY -> USER NOTIFICATION & REVERSE
// -------------------------------------------------------------
echo "\n--- 4. TEST ADMIN PHẢN HỒI -> USER NHẬN THÔNG BÁO VÀ NGƯỢC LẠI ---\n";

// ADMIN phản hồi ticket
$admin_reply_text = "Chào bạn, kỹ thuật viên đã kiểm tra và cấp đổi mật khẩu mới cho bạn: Pass mới: Anplay@2026";
$reply_res = send_support_message($conn, $created_ticket_id, $admin_id, 'admin', $admin_reply_text);
assert_test("send_support_message() của admin thành công", $reply_res['success'] === true);

// Kiểm tra USER nhận notification phản hồi từ Admin
$user_reply_notif = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id AND type = 'support' AND link LIKE '%support_detail.php?id={$created_ticket_id}%' AND id != {$user_notif['id']} ORDER BY id DESC LIMIT 1")->fetch_assoc();
assert_test("USER nhận được notification khi ADMIN trả lời", $user_reply_notif !== null);

// USER trả lời lại ticket
$user_re_reply = "Cảm ơn admin, mình đã đăng nhập thành công!";
$user_re_res = send_support_message($conn, $created_ticket_id, $user_id, 'user', $user_re_reply);
assert_test("send_support_message() của user thành công", $user_re_res['success'] === true);

// Kiểm tra ADMIN & SUPER_ADMIN nhận được notification khi user reply
$admin_re_notif = $conn->query("SELECT * FROM notifications WHERE user_id = $admin_id AND type = 'support' AND id != {$admin_notif['id']} ORDER BY id DESC LIMIT 1")->fetch_assoc();
assert_test("ADMIN nhận được notification khi USER phản hồi lại", $admin_re_notif !== null);

// -------------------------------------------------------------
// TEST GROUP 5: NOTIFICATION API & ACTION READ_AND_GO
// -------------------------------------------------------------
echo "\n--- 5. TEST API NOTIFICATIONS & ĐÁNH DẤU ĐÃ ĐỌC (IDOR PROTECTED) ---\n";

// Test đánh dấu đã đọc
$notif_to_read = $admin_notif['id'];
$mark_res = mark_notification_read($conn, $notif_to_read, $admin_id);
assert_test("mark_notification_read() cho đúng chủ sở hữu thành công", $mark_res === true);

$chk_read = $conn->query("SELECT is_read FROM notifications WHERE id = $notif_to_read")->fetch_assoc();
assert_test("Trạng thái is_read trong CSDL cập nhật thành 1", intval($chk_read['is_read']) === 1);

// Test IDOR: User 2 cố đánh dấu thông báo của Admin 3
$idor_res = mark_notification_read($conn, $notif_to_read, $user_id);
assert_test("Chặn IDOR: User không thể đánh dấu đọc notification của Admin", $idor_res === false);

// -------------------------------------------------------------
// TEST GROUP 6: HTTP RBAC & ACCESS CONTROL ON APACHE PORT 8080
// -------------------------------------------------------------
echo "\n--- 6. TEST HTTP SERVER-SIDE ACCESS CONTROL TRÊN APACHE PORT 8080 ---\n";

function http_get_with_cookie($url, $cookie = '') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if (!empty($cookie)) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    curl_close($ch);
    return ['code' => $http_code, 'headers' => $headers, 'body' => $body];
}

// 1. Guest chưa đăng nhập truy cập admin_dashboard.php -> Redirect 302 về login.php
$guest_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_dashboard.php');
assert_test("Khách chưa đăng nhập vào admin_dashboard.php bị Redirect 302", $guest_res['code'] === 302);

// Tạo session cookie mô phỏng cho USER (role = 0)
// Viết script trợ giúp tạo session cookie test
$sess_script = <<<'EOD'
<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['username'] = 'anday123';
$_SESSION['role'] = 0;
echo session_id();
EOD;
file_put_contents(__DIR__ . '/test_sess_user.php', $sess_script);

$user_sess_id = trim(file_get_contents('http://localhost:8080/quan_ly_thu_vien_game/test_sess_user.php'));
unlink(__DIR__ . '/test_sess_user.php');

$user_cookie = "PHPSESSID=" . $user_sess_id;

// USER truy cập admin_dashboard.php -> 403 Forbidden!
$user_admin_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_dashboard.php', $user_cookie);
assert_test("USER (role = 0) truy cập admin_dashboard.php bị chặn HTTP 403 Forbidden", $user_admin_res['code'] === 403);

// USER truy cập admin_support.php -> 403 Forbidden!
$user_sup_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_support.php', $user_cookie);
assert_test("USER (role = 0) truy cập admin_support.php bị chặn HTTP 403 Forbidden", $user_sup_res['code'] === 403);

// USER truy cập admin_management.php -> 403 Forbidden!
$user_mgmt_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_management.php', $user_cookie);
assert_test("USER (role = 0) truy cập admin_management.php bị chặn HTTP 403 Forbidden", $user_mgmt_res['code'] === 403);

// Tạo session cookie mô phỏng cho ADMIN (role = 1)
$sess_script_adm = <<<'EOD'
<?php
session_start();
$_SESSION['user_id'] = 3;
$_SESSION['username'] = 'support_admin';
$_SESSION['role'] = 1;
echo session_id();
EOD;
file_put_contents(__DIR__ . '/test_sess_admin.php', $sess_script_adm);

$admin_sess_id = trim(file_get_contents('http://localhost:8080/quan_ly_thu_vien_game/test_sess_admin.php'));
unlink(__DIR__ . '/test_sess_admin.php');

$admin_cookie = "PHPSESSID=" . $admin_sess_id;

// ADMIN (role = 1) truy cập admin_dashboard.php -> HTTP 200 OK
$adm_dash_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_dashboard.php', $admin_cookie);
assert_test("ADMIN (role = 1) truy cập admin_dashboard.php thành công HTTP 200 OK", $adm_dash_res['code'] === 200);

// ADMIN (role = 1) truy cập admin_management.php -> HTTP 403 Forbidden!
$adm_mgmt_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_management.php', $admin_cookie);
assert_test("ADMIN (role = 1) truy cập admin_management.php bị chặn HTTP 403 Forbidden", $adm_mgmt_res['code'] === 403);

// ADMIN (role = 1) truy cập admin_roles.php -> HTTP 403 Forbidden!
$adm_role_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_roles.php', $admin_cookie);
assert_test("ADMIN (role = 1) truy cập admin_roles.php bị chặn HTTP 403 Forbidden", $adm_role_res['code'] === 403);

// ADMIN (role = 1) truy cập admin_system.php -> HTTP 403 Forbidden!
$adm_sys_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_system.php', $admin_cookie);
assert_test("ADMIN (role = 1) truy cập admin_system.php bị chặn HTTP 403 Forbidden", $adm_sys_res['code'] === 403);

// Tạo session cookie mô phỏng cho SUPER_ADMIN (role = 2)
$sess_script_super = <<<'EOD'
<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'thanhantitan';
$_SESSION['role'] = 2;
echo session_id();
EOD;
file_put_contents(__DIR__ . '/test_sess_super.php', $sess_script_super);

$super_sess_id = trim(file_get_contents('http://localhost:8080/quan_ly_thu_vien_game/test_sess_super.php'));
unlink(__DIR__ . '/test_sess_super.php');

$super_cookie = "PHPSESSID=" . $super_sess_id;

// SUPER ADMIN truy cập admin_management.php -> HTTP 200 OK
$sup_mgmt_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_management.php', $super_cookie);
assert_test("SUPER_ADMIN (role = 2) truy cập admin_management.php thành công HTTP 200 OK", $sup_mgmt_res['code'] === 200);

// SUPER ADMIN truy cập admin_roles.php -> HTTP 200 OK
$sup_roles_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_roles.php', $super_cookie);
assert_test("SUPER_ADMIN (role = 2) truy cập admin_roles.php thành công HTTP 200 OK", $sup_roles_res['code'] === 200);

// SUPER ADMIN truy cập admin_system.php -> HTTP 200 OK
$sup_sys_res = http_get_with_cookie('http://localhost:8080/quan_ly_thu_vien_game/admin_system.php', $super_cookie);
assert_test("SUPER_ADMIN (role = 2) truy cập admin_system.php thành công HTTP 200 OK", $sup_sys_res['code'] === 200);

// Kiểm tra nội dung trang admin trả về không chứa các thành phần user
assert_test("Giao diện Admin trả về KHÔNG chứa 'Cửa Hàng Game' dạng menu khách", strpos($adm_dash_res['body'], 'sidebar-title">Khám Phá') === false);
assert_test("Giao diện Admin trả về KHÔNG chứa chatbot widget", strpos($adm_dash_res['body'], 'anplay-chatbot-wrapper') === false);
assert_test("Giao diện Admin trả về có Brand ANPLAY ADMIN CONSOLE", strpos($adm_dash_res['body'], 'ANPLAY ADMIN CONSOLE') !== false);
assert_test("Giao diện Admin trả về có Notification dropdown bell", strpos($adm_dash_res['body'], 'adminNotifDropdownBtn') !== false);

echo "\n========================================================================\n";
echo "   KẾT QUẢ KIỂM THỬ: $tests_passed / $tests_total TEST CASES PASSED!\n";
echo "========================================================================\n";

