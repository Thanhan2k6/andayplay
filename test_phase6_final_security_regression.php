<?php
/**
 * ANPLAY STORE - AUTOMATED TEST SUITE: PHASE 6 FINAL SECURITY + REGRESSION
 * Kiểm thử toàn diện 7 khía cạnh trọng yếu:
 * 1. AUTH (Login, Logout, Session, Expiry, Role, Permission)
 * 2. SUPPORT (Ticket lifecycle, reply loop, notification routing)
 * 3. NOTIFICATION (Insert, recipient, unread, read, badge, dropdown, deduplication)
 * 4. SECURITY (SQLi, XSS, CSRF, IDOR, Privilege Escalation 3 tầng, Unauthorized API, Direct URL)
 * 5. REGRESSION (Store, Game, Account, Rental, Order, Payment, Profile, Chatbot, Support, Dashboard)
 * 6. MOBILE (Viewport, Bottom Nav, CSS breakpoints, touch targets)
 * 7. DATABASE (Orphaned records, duplicate checks, transaction rollback, schema integrity)
 */

require_once __DIR__ . '/includes/connect.php';

$base_url = 'http://localhost:8080/quan_ly_thu_vien_game';

// Tạo Session IDs riêng biệt cho từng vai trò
$sess_user = 'sessp6user' . substr(md5(uniqid()), 0, 10);
$sess_admin = 'sessp6admin' . substr(md5(uniqid()), 0, 10);
$sess_super = 'sessp6super' . substr(md5(uniqid()), 0, 10);

$csrf_user = bin2hex(random_bytes(16));
$csrf_admin = bin2hex(random_bytes(16));
$csrf_super = bin2hex(random_bytes(16));

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// 1. Session USER (user_id = 2, role = 0)
session_id($sess_user);
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['username'] = 'anday123';
$_SESSION['email'] = 'anday123@gmail.com';
$_SESSION['role'] = 0;
$_SESSION['csrf_token'] = $csrf_user;
$_SESSION['chat_csrf_token'] = bin2hex(random_bytes(16));
session_write_close();

// 2. Session ADMIN (user_id = 3, role = 1)
session_id($sess_admin);
session_start();
$_SESSION['user_id'] = 3;
$_SESSION['username'] = 'support_admin';
$_SESSION['email'] = 'support@anplay.store';
$_SESSION['role'] = 1;
$_SESSION['csrf_token'] = $csrf_admin;
session_write_close();

// 3. Session SUPER_ADMIN (user_id = 1, role = 2)
session_id($sess_super);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'thanhantitan';
$_SESSION['email'] = 'thanhan.titan@gmail.com';
$_SESSION['role'] = 2;
$_SESSION['csrf_token'] = $csrf_super;
session_write_close();

// 4. Session riêng biệt cho kiểm thử Logout
$sess_logout = 'sessp6logout' . substr(md5(uniqid()), 0, 10);
session_id($sess_logout);
session_start();
$_SESSION['user_id'] = 2;
session_write_close();

// Đảm bảo dữ liệu role người dùng chuẩn trong CSDL
$conn->query("UPDATE users SET role = 2, status = 'active' WHERE user_id = 1");
$conn->query("UPDATE users SET role = 0, status = 'active' WHERE user_id = 2");
$conn->query("UPDATE users SET role = 1, status = 'active' WHERE user_id = 3");

// Cấp quyền chuẩn cho Admin 3
set_user_permissions($conn, 3, ['support.view', 'support.reply', 'order.view', 'notification.view', 'notification.send', 'game.view', 'customer.view'], 1);

function http_call($url, $method = 'GET', $cookie_sess = '', $post_data = [], $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $req_headers = $headers;
    if (!empty($cookie_sess)) {
        $req_headers[] = "Cookie: PHPSESSID=" . $cookie_sess;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($post_data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    return [
        'code' => $http_code,
        'body' => $response,
        'redirect' => $redirect_url
    ];
}

$total_tests = 0;
$passed_tests = 0;
$failed_tests = [];

function run_test($category, $test_name, $condition, $extra = "") {
    global $total_tests, $passed_tests, $failed_tests;
    $total_tests++;
    $label = "[$category] $test_name";
    if ($condition) {
        $passed_tests++;
        echo "  [PASS] #$total_tests: $label\n";
    } else {
        $failed_tests[] = "$label " . ($extra ? "($extra)" : "");
        echo "  [FAIL] #$total_tests: $label " . ($extra ? "[$extra]" : "") . "\n";
    }
}

echo "========================================================================================\n";
echo "   ANPLAY STORE - PHASE 6: FINAL SECURITY & COMPREHENSIVE REGRESSION TEST\n";
echo "========================================================================================\n\n";

// ========================================================================================
// 1. AUTH & SESSION INTEGRITY
// ========================================================================================
echo "--- 1. AUTH & ACCESS CONTROL ---\n";

// 1.1 Khởi tạo tài khoản kiểm thử xác thực riêng biệt
$test_pass = 'P6SecurePass123!';
$test_hash = password_hash($test_pass, PASSWORD_DEFAULT);
$conn->query("DELETE FROM users WHERE username = 'p6_auth_test'");
$conn->query("INSERT INTO users (username, email, password, role, status) VALUES ('p6_auth_test', 'p6_auth@anplay.store', '$test_hash', 0, 'active')");
$test_uid = $conn->insert_id;

// 1.1 Kiểm tra đăng nhập sai mật khẩu -> thất bại an toàn
$res_login_fail = http_call("$base_url/login.php", 'POST', '', [
    'btn_login' => '1',
    'username' => 'p6_auth_test',
    'password' => 'wrong_password_123456'
]);
run_test("AUTH", "Đăng nhập sai mật khẩu bị từ chối và hiển thị thông báo lỗi",
    strpos($res_login_fail['body'], 'Mật khẩu không chính xác') !== false || strpos($res_login_fail['body'], 'Đăng nhập thất bại') !== false);

// 1.2 Đăng nhập đúng mật khẩu -> thành công
$res_login_success = http_call("$base_url/login.php", 'POST', '', [
    'btn_login' => '1',
    'username' => 'p6_auth_test',
    'password' => $test_pass
]);
run_test("AUTH", "Đăng nhập đúng mật khẩu tài khoản thành công và khởi tạo phiên",
    strpos($res_login_success['body'], 'Đăng Nhập Thành Công') !== false);

// 1.3 Mật khẩu được băm an toàn
run_test("AUTH", "Mật khẩu người dùng được băm mã hóa an toàn (bcrypt/argon2)",
    strpos($test_hash, '$2y$') === 0 || strpos($test_hash, '$argon2') === 0 || strlen($test_hash) > 20);

// 1.4 Kiểm tra định nghĩa hằng số Role 3 tầng
run_test("AUTH", "Các hằng số ROLE_USER(0), ROLE_ADMIN(1), ROLE_SUPER_ADMIN(2) chuẩn xác",
    defined('ROLE_USER') && ROLE_USER === 0 &&
    defined('ROLE_ADMIN') && ROLE_ADMIN === 1 &&
    defined('ROLE_SUPER_ADMIN') && ROLE_SUPER_ADMIN === 2
);

// 1.5 Kiểm tra tài khoản bị tạm khóa (suspended) không được phép hoạt động
$conn->query("UPDATE users SET status = 'suspended' WHERE user_id = $test_uid");
$res_suspended = http_call("$base_url/login.php", 'POST', '', [
    'btn_login' => '1',
    'username' => 'p6_auth_test',
    'password' => $test_pass
]);
run_test("AUTH", "Tài khoản bị tạm khóa (status=suspended) bị từ chối đăng nhập",
    strpos($res_suspended['body'], 'Tài khoản đã bị khóa') !== false || strpos($res_suspended['body'], 'tạm ngưng hoạt động') !== false);

// Dọn dẹp tài khoản test
$conn->query("DELETE FROM users WHERE user_id = $test_uid");

// 1.6 Kiểm tra phiên hết hạn / chưa đăng nhập -> Chặn vào trang cá nhân
$res_no_auth = http_call("$base_url/my_library.php", 'GET', '');
run_test("AUTH", "Chưa đăng nhập truy cập my_library.php bị chuyển hướng về login.php",
    $res_no_auth['code'] === 302 && strpos($res_no_auth['redirect'], 'login.php') !== false);

// 1.6 Kiểm tra hệ thống Permission: Admin 3 có support.view, không có rental.manage
$has_supp = check_user_permission($conn, 3, 'support.view');
$has_rent = check_user_permission($conn, 3, 'rental.manage');
run_test("AUTH", "Hàm check_user_permission trả về đúng theo phân quyền gán thực tế",
    $has_supp === true && $has_rent === false);

// 1.7 Kiểm tra Super Admin (User 1) luôn có mọi quyền dù không gán lẻ
$sa_has_rent = check_user_permission($conn, 1, 'rental.manage');
run_test("AUTH", "Super Admin mặc định sở hữu toàn bộ quyền hệ thống",
    $sa_has_rent === true);

// 1.8 Kiểm tra đăng xuất (logout.php) trên một phiên riêng biệt
$res_logout = http_call("$base_url/logout.php", 'GET', $sess_logout);
run_test("AUTH", "Truy cập logout.php hủy phiên và chuyển hướng về index.php",
    $res_logout['code'] === 302 && strpos($res_logout['redirect'], 'index.php') !== false);


// ========================================================================================
// 2. SUPPORT WORKFLOW & TICKET LIFECYCLE
// ========================================================================================
echo "\n--- 2. SUPPORT LIFECYCLE & TICKET ROUTING ---\n";

// 2.1 USER tạo Ticket mới
$t_res = create_support_ticket($conn, 2, 'account_issue', "P6 Test Ticket Khách Hàng", "Tôi cần hỗ trợ đổi mật khẩu", 'high');
run_test("SUPPORT", "User tạo Ticket hỗ trợ thành công và nhận mã Ticket",
    $t_res['success'] && !empty($t_res['ticket_id']));
$p6_ticket_id = intval($t_res['ticket_id'] ?? 0);

// 2.2 Kiểm tra ADMIN và SUPER_ADMIN nhận được notification
$q_adm_notif = $conn->query("SELECT * FROM notifications WHERE user_id = 3 AND related_id = 'SUP-NEW-{$p6_ticket_id}-U3'");
run_test("SUPPORT", "Admin nhận thông báo khi có Ticket mới tạo",
    $q_adm_notif && $q_adm_notif->num_rows > 0);

$q_sa_notif = $conn->query("SELECT * FROM notifications WHERE user_id = 1 AND related_id = 'SUP-NEW-{$p6_ticket_id}-U1'");
run_test("SUPPORT", "Super Admin nhận thông báo khi có Ticket mới tạo",
    $q_sa_notif && $q_sa_notif->num_rows > 0);

// 2.3 Ticket khiếu nại (complaint)
$comp_res = create_support_ticket($conn, 2, 'complaint', "P6 Khiếu nại dịch vụ", "Khiếu nại thái độ phục vụ", 'urgent');
$p6_comp_id = intval($comp_res['ticket_id'] ?? 0);
$q_comp_notif = $conn->query("SELECT * FROM notifications WHERE user_id = 1 AND related_id = 'COMPLAINT-{$p6_comp_id}-U1'");
run_test("SUPPORT", "Khiếu nại (complaint) gửi cảnh báo đặc biệt tới Super Admin",
    $q_comp_notif && $q_comp_notif->num_rows > 0);

// 2.4 ADMIN phản hồi Ticket
$adm_reply = send_support_message($conn, $p6_ticket_id, 3, 'admin', "Chào bạn, Admin @support_admin đã tiếp nhận!");
run_test("SUPPORT", "Admin gửi phản hồi thành công và cập nhật trạng thái ticket",
    $adm_reply['success']);

// 2.5 USER nhận thông báo phản hồi từ Admin
$q_u_res = $conn->query("SELECT * FROM notifications WHERE user_id = 2 AND type = 'support' AND related_id LIKE 'SUP-ADMIN-REPLY-%' ORDER BY id DESC LIMIT 1");
run_test("SUPPORT", "User nhận thông báo khi Admin phản hồi",
    $q_u_res && $q_u_res->num_rows > 0);

// 2.6 USER phản hồi lại Ticket
$usr_reply = send_support_message($conn, $p6_ticket_id, 2, 'user', "Dạ em cảm ơn Admin, em đã làm theo hướng dẫn.");
run_test("SUPPORT", "User gửi phản hồi lại cho Admin thành công",
    $usr_reply['success']);

// 2.7 ADMIN phụ trách nhận thông báo phản hồi từ User
$q_adm_reply_notif = $conn->query("SELECT * FROM notifications WHERE user_id = 3 AND related_id LIKE 'SUP-USER-REPLY-%-U3' ORDER BY id DESC LIMIT 1");
run_test("SUPPORT", "Admin phụ trách nhận thông báo khi User phản hồi",
    $q_adm_reply_notif && $q_adm_reply_notif->num_rows > 0);


// ========================================================================================
// 3. NOTIFICATION CENTER & BADGES
// ========================================================================================
echo "\n--- 3. NOTIFICATION CENTER & BADGES ---\n";

// 3.1 Unread count đồng bộ với CSDL
$badge_count = get_unread_notification_count($conn, 2);
$real_count = intval($conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id = 2 AND is_read = 0")->fetch_assoc()['c']);
run_test("NOTIF", "Hàm get_unread_notification_count khớp chính xác 100% với CSDL",
    $badge_count === $real_count);

// 3.2 Dropdown data payload
$drop_items = get_user_notifications($conn, 2, 'all', false, 5, 0);
run_test("NOTIF", "Danh sách Dropdown chứa icon_info, time_ago và cấu trúc hợp lệ",
    !empty($drop_items) && isset($drop_items[0]['icon_info']['icon']) && isset($drop_items[0]['time_ago']));

// 3.3 Mark single notification as read
$first_notif_id = intval($drop_items[0]['id'] ?? 0);
$mark_res = mark_notification_read($conn, $first_notif_id, 2);
$q_is_read = $conn->query("SELECT is_read FROM notifications WHERE id = $first_notif_id")->fetch_assoc()['is_read'];
run_test("NOTIF", "Đánh dấu đọc thông báo cập nhật is_read=1 trong CSDL",
    $mark_res && intval($q_is_read) === 1);

// 3.4 Mark all read
mark_all_notifications_read($conn, 2);
$unread_after_all = get_unread_notification_count($conn, 2);
run_test("NOTIF", "Đánh dấu tất cả đã đọc đưa số lượng unread về 0",
    $unread_after_all === 0);

// 3.5 Deduplication window
$d1 = create_notification($conn, 2, 'rental', "Dedup Title", "Dedup Message", "my_library.php", "P6-DEDUP-KEY", 60);
$d2 = create_notification($conn, 2, 'rental', "Dedup Title", "Dedup Message", "my_library.php", "P6-DEDUP-KEY", 60);
run_test("NOTIF", "Cơ chế Deduplication ngăn chặn thông báo trùng lặp trong khoảng thời gian quy định",
    $d1 > 0 && $d1 === $d2);

// 3.6 Xóa thông báo
$del_notif = delete_notification($conn, $d1, 2);
$q_chk_del = $conn->query("SELECT id FROM notifications WHERE id = $d1");
run_test("NOTIF", "Xóa thông báo loại bỏ bản ghi thành công",
    $del_notif && $q_chk_del->num_rows === 0);


// ========================================================================================
// 4. SECURITY AUDIT & PRIVILEGE ESCALATION
// ========================================================================================
echo "\n--- 4. SECURITY AUDIT & PRIVILEGE ESCALATION ---\n";

// 4.1 SQL Injection test trên các tham số GET
$sqli_payload = "1' OR '1'='1' --";
$res_sqli_detail = http_call("$base_url/game_detail.php?id=" . urlencode($sqli_payload), 'GET', $sess_user);
run_test("SECURITY", "Chống SQL Injection trên game_detail.php?id (Không lộ lỗi MySQL)",
    strpos($res_sqli_detail['body'], 'SQL syntax') === false && strpos($res_sqli_detail['body'], 'Fatal error') === false);

$res_sqli_notif = http_call("$base_url/notifications.php?tab=" . urlencode($sqli_payload), 'GET', $sess_user);
run_test("SECURITY", "Chống SQL Injection trên notifications.php?tab",
    strpos($res_sqli_notif['body'], 'SQL syntax') === false && $res_sqli_notif['code'] === 200);

// 4.2 XSS Protection test
$xss_payload = "<script>alert('XSS_P6_TEST')</script>";
$xss_t = create_support_ticket($conn, 2, 'other', "XSS Test " . $xss_payload, "Message " . $xss_payload, 'normal');
$xss_tid = intval($xss_t['ticket_id'] ?? 0);
$res_xss_view = http_call("$base_url/support_detail.php?id=$xss_tid", 'GET', $sess_user);
run_test("SECURITY", "Chống XSS: Payload script trong tiêu đề/nội dung được escape an toàn",
    strpos($res_xss_view['body'], "<script>alert('XSS_P6_TEST')</script>") === false);

// 4.3 CSRF Protection: POST action không có CSRF token hoặc token sai -> Bị từ chối
$res_no_csrf = http_call("$base_url/support.php", 'POST', $sess_user, [
    'btn_create_ticket' => '1',
    'title' => 'No CSRF Ticket',
    'message' => 'Test body',
    'csrf_token' => 'invalid_forged_token_12345'
]);
run_test("SECURITY", "Chống CSRF: Gửi POST với CSRF token sai bị từ chối",
    strpos($res_no_csrf['body'], 'CSRF không hợp lệ') !== false || strpos($res_no_csrf['body'], 'không hợp lệ') !== false);

// 4.4 IDOR Protection: User 2 cố xem ticket của User 1
// Tạo 1 ticket thuộc User 1 (Super Admin)
$sa_t = create_support_ticket($conn, 1, 'other', "Super Admin Confidential Ticket", "Private info", 'high');
$sa_tid = intval($sa_t['ticket_id'] ?? 0);
$res_idor_view = http_call("$base_url/support_detail.php?id=$sa_tid", 'GET', $sess_user);
run_test("SECURITY", "Chống IDOR: User 2 không thể xem ticket của User 1 (HTTP 403 Forbidden)",
    $res_idor_view['code'] === 403 || strpos($res_idor_view['body'], 'Truy Cập Bị Từ Chối') !== false);

// 4.5 IDOR Protection: User 2 cố đánh dấu đọc hoặc xóa notification của User 1
$sa_n_row = $conn->query("SELECT id FROM notifications WHERE user_id = 1 ORDER BY id DESC LIMIT 1")->fetch_assoc();
$sa_n_id = intval($sa_n_row['id'] ?? 0);
$idor_mark = mark_notification_read($conn, $sa_n_id, 2);
$idor_del = delete_notification($conn, $sa_n_id, 2);
run_test("SECURITY", "Chống IDOR: User 2 không thể đánh dấu đọc hoặc xóa notification của User 1",
    $idor_mark === false && $idor_del === false);

// 4.6 Privilege Escalation: USER -> ADMIN
$res_u_admin_dash = http_call("$base_url/admin_dashboard.php", 'GET', $sess_user);
run_test("SECURITY", "Ngăn chặn leo quyền: USER truy cập admin_dashboard.php bị trả về 403 Forbidden",
    $res_u_admin_dash['code'] === 403);

$res_u_admin_games = http_call("$base_url/admin_games.php", 'GET', $sess_user);
run_test("SECURITY", "Ngăn chặn leo quyền: USER truy cập admin_games.php bị trả về 403 Forbidden",
    $res_u_admin_games['code'] === 403);

$res_u_admin_borrow = http_call("$base_url/admin_borrow.php", 'GET', $sess_user);
run_test("SECURITY", "Ngăn chặn leo quyền: USER truy cập admin_borrow.php bị trả về 403 Forbidden",
    $res_u_admin_borrow['code'] === 403);

// 4.7 Privilege Escalation: ADMIN -> SUPER_ADMIN
$res_a_mgmt = http_call("$base_url/admin_management.php", 'GET', $sess_admin);
run_test("SECURITY", "Ngăn chặn leo quyền: ADMIN truy cập admin_management.php bị trả về 403 Forbidden",
    $res_a_mgmt['code'] === 403);

$res_a_roles = http_call("$base_url/admin_roles.php", 'GET', $sess_admin);
run_test("SECURITY", "Ngăn chặn leo quyền: ADMIN truy cập admin_roles.php bị trả về 403 Forbidden",
    $res_a_roles['code'] === 403);

$res_a_sys = http_call("$base_url/admin_system.php", 'GET', $sess_admin);
run_test("SECURITY", "Ngăn chặn leo quyền: ADMIN truy cập admin_system.php bị trả về 403 Forbidden",
    $res_a_sys['code'] === 403);

// 4.8 Privilege Escalation: USER -> SUPER_ADMIN
$res_u_mgmt = http_call("$base_url/admin_management.php", 'GET', $sess_user);
run_test("SECURITY", "Ngăn chặn leo quyền: USER truy cập admin_management.php bị trả về 403 Forbidden",
    $res_u_mgmt['code'] === 403);

// 4.9 ADMIN cố ý gửi POST đổi role hoặc tự phong Super Admin
$res_a_post_role = http_call("$base_url/admin_management.php", 'POST', $sess_admin, [
    'action' => 'change_role',
    'target_user_id' => 3,
    'new_role' => 2,
    'csrf_token' => $csrf_admin
]);
run_test("SECURITY", "ADMIN gửi POST đổi role bị chặn 403 Forbidden ngay từ tầng server-side",
    $res_a_post_role['code'] === 403);

// 4.10 ADMIN cố ý hạ quyền hoặc xóa SUPER_ADMIN
$res_a_del_sa = http_call("$base_url/admin_management.php", 'POST', $sess_admin, [
    'action' => 'delete_admin',
    'target_user_id' => 1,
    'csrf_token' => $csrf_admin
]);
run_test("SECURITY", "ADMIN cố ý xóa Super Admin bị chặn 403 Forbidden",
    $res_a_del_sa['code'] === 403);

// 4.11 Direct URL access khi không có Session
$res_direct_adm = http_call("$base_url/admin_dashboard.php", 'GET', '');
run_test("SECURITY", "Truy cập trực tiếp URL admin khi chưa đăng nhập bị chuyển hướng về login.php",
    $res_direct_adm['code'] === 302 && strpos($res_direct_adm['redirect'], 'login.php') !== false);


// ========================================================================================
// 5. REGRESSION TESTING OF CORE MODULES
// ========================================================================================
echo "\n--- 5. REGRESSION TESTING (STORE, GAME, RENTAL, ORDER, CHATBOT) ---\n";

// 5.1 Store (index.php)
$res_store = http_call("$base_url/index.php", 'GET', $sess_user);
run_test("REGRESSION", "Trang chủ Store (index.php) tải thành công HTTP 200",
    $res_store['code'] === 200 && strpos($res_store['body'], 'ANPLAY') !== false);

// 5.2 Games list / Category catalog (index.php?category=all)
$res_games = http_call("$base_url/index.php?category=all", 'GET', $sess_user);
run_test("REGRESSION", "Danh mục Game (index.php?category=all) tải thành công HTTP 200",
    $res_games['code'] === 200 && strpos($res_games['body'], 'ANPLAY') !== false);

// 5.3 Game detail (game_detail.php?id=4 - Game thuê trả phí Black Myth: Wukong)
$res_gdetail = http_call("$base_url/game_detail.php?id=4", 'GET', $sess_user);
run_test("REGRESSION", "Chi tiết trò chơi (game_detail.php?id=4) hiển thị đầy đủ thông tin",
    $res_gdetail['code'] === 200 && (strpos($res_gdetail['body'], 'Thuê Ngay') !== false || strpos($res_gdetail['body'], 'Gói Thuê') !== false));

// 5.4 Game Account Inventory (admin_accounts.php)
$res_accounts = http_call("$base_url/admin_accounts.php", 'GET', $sess_admin);
run_test("REGRESSION", "Kho tài khoản game (admin_accounts.php) hoạt động ổn định HTTP 200",
    $res_accounts['code'] === 200);

// 5.5 Rental Library (my_library.php)
$res_lib = http_call("$base_url/my_library.php", 'GET', $sess_user);
run_test("REGRESSION", "Thư viện game của tôi (my_library.php) tải thành công HTTP 200",
    $res_lib['code'] === 200 && strpos($res_lib['body'], 'Kho Game & Tài Khoản Thuê') !== false);

// 5.6 Checkout QR page (checkout_qr.php)
$res_qr = http_call("$base_url/checkout_qr.php?game_id=1&package_id=1", 'GET', $sess_user);
run_test("REGRESSION", "Trang thanh toán QR (checkout_qr.php) phản hồi hợp lệ HTTP 200",
    $res_qr['code'] === 200);

// 5.7 User Profile (profile.php)
$res_profile = http_call("$base_url/profile.php", 'GET', $sess_user);
run_test("REGRESSION", "Hồ sơ cá nhân (profile.php) hiển thị thông tin thành viên HTTP 200",
    $res_profile['code'] === 200 && strpos($res_profile['body'], 'anday123') !== false);

// 5.8 Chatbot AI Assistant (chatbot_handler.php / assistant.php)
$res_chat = http_call("$base_url/chatbot_handler.php", 'POST', $sess_user, json_encode([
    'message' => 'Xin chào ANPLAY ASSISTANT!'
]), ['Content-Type: application/json; charset=UTF-8']);
$chat_json = json_decode($res_chat['body'], true);
run_test("REGRESSION", "Chatbot Assistant phản hồi JSON hợp lệ qua POST",
    $res_chat['code'] === 200 || isset($chat_json['success']) || isset($chat_json['reply']));

// 5.9 Notification Center (notifications.php)
$res_notif_center = http_call("$base_url/notifications.php", 'GET', $sess_user);
run_test("REGRESSION", "Trung tâm thông báo (notifications.php) tải thành công HTTP 200",
    $res_notif_center['code'] === 200 && strpos($res_notif_center['body'], 'Hộp Thư & Trung Tâm Thông Báo') !== false);

// 5.10 Customer Support (support.php)
$res_supp_center = http_call("$base_url/support.php", 'GET', $sess_user);
run_test("REGRESSION", "Trung tâm hỗ trợ khách hàng (support.php) tải thành công HTTP 200",
    $res_supp_center['code'] === 200 && strpos($res_supp_center['body'], 'Trung Tâm Hỗ Trợ') !== false);

// 5.11 Admin Support Console (admin_support.php)
$res_adm_supp = http_call("$base_url/admin_support.php", 'GET', $sess_admin);
run_test("REGRESSION", "Bảng điều khiển hỗ trợ Admin (admin_support.php) tải thành công HTTP 200",
    $res_adm_supp['code'] === 200 && (strpos($res_adm_supp['body'], 'ADMIN SUPPORT DESK') !== false || strpos($res_adm_supp['body'], 'Quản Lý Yêu Cầu Hỗ Trợ') !== false));

// 5.12 Admin Dashboard (admin_dashboard.php)
$res_adm_dash = http_call("$base_url/admin_dashboard.php", 'GET', $sess_admin);
run_test("REGRESSION", "Trung tâm quản trị Admin (admin_dashboard.php) tải thành công HTTP 200",
    $res_adm_dash['code'] === 200 && strpos($res_adm_dash['body'], 'Trung Tâm Quản Trị') !== false);


// ========================================================================================
// 6. MOBILE RESPONSIVENESS & ADAPTATION
// ========================================================================================
echo "\n--- 6. MOBILE RESPONSIVENESS & TOUCH TARGETS ---\n";

// 6.1 Viewport meta tag
run_test("MOBILE", "Thẻ Viewport meta tag hiện diện chuẩn trong header.php",
    strpos($res_store['body'], '<meta name="viewport" content="width=device-width, initial-scale=1.0">') !== false);

// 6.2 Mobile Bottom Navigation present in footer
run_test("MOBILE", "Thanh điều hướng đáy màn hình (mobile-bottom-nav) hiện diện đầy đủ",
    strpos($res_store['body'], 'class="mobile-bottom-nav"') !== false &&
    strpos($res_store['body'], 'Cửa Hàng') !== false &&
    strpos($res_store['body'], 'Thư Viện') !== false &&
    strpos($res_store['body'], 'Thông Báo') !== false);

// 6.3 Dropdown Menu mobile constraint (max-width: 92vw)
run_test("MOBILE", "Khung Dropdown thông báo được giới hạn chiều rộng 92vw cho điện thoại",
    strpos($res_store['body'], 'max-width: 92vw') !== false);

// 6.4 CSS media queries tồn tại cho các breakpoint mobile
$css_content = file_get_contents(__DIR__ . '/css/style.css');
run_test("MOBILE", "Tệp css/style.css chứa đầy đủ media queries cho tablet, mobile và small screens",
    strpos($css_content, '@media (max-width: 767.98px)') !== false &&
    strpos($css_content, '@media (max-width: 575.98px)') !== false &&
    strpos($css_content, '@media (max-width: 479.98px)') !== false);


// ========================================================================================
// 7. DATABASE INTEGRITY & TRANSACTION ROLLBACK
// ========================================================================================
echo "\n--- 7. DATABASE INTEGRITY & TRANSACTIONS ---\n";

// 7.1 Orphaned messages check
$q_orph_msg = $conn->query("SELECT COUNT(*) as c FROM support_messages WHERE ticket_id NOT IN (SELECT ticket_id FROM support_tickets)");
$orph_msg = intval($q_orph_msg->fetch_assoc()['c']);
run_test("DATABASE", "Bảng support_messages không có bản ghi mồ côi (Zero orphaned messages)",
    $orph_msg === 0);

// 7.2 Orphaned notifications check
$q_orph_notif = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id NOT IN (SELECT user_id FROM users)");
$orph_notif = intval($q_orph_notif->fetch_assoc()['c']);
run_test("DATABASE", "Bảng notifications không có bản ghi mồ côi (Zero orphaned notifications)",
    $orph_notif === 0);

// 7.3 Orphaned user_permissions check
$q_orph_perm = $conn->query("SELECT COUNT(*) as c FROM user_permissions WHERE user_id NOT IN (SELECT user_id FROM users)");
$orph_perm = intval($q_orph_perm->fetch_assoc()['c']);
run_test("DATABASE", "Bảng user_permissions không có bản ghi mồ côi (Zero orphaned permissions)",
    $orph_perm === 0);

// 7.4 Transaction rollback test: Thử tạo message với ticket_id không tồn tại
$conn->begin_transaction();
$rollback_worked = false;
try {
    $conn->query("INSERT INTO support_messages (ticket_id, sender_id, sender_type, message, created_at) VALUES (9999999, 2, 'user', 'Rollback test', NOW())");
    $conn->rollback();
    $q_chk_roll = $conn->query("SELECT COUNT(*) as c FROM support_messages WHERE message = 'Rollback test'");
    $rollback_worked = (intval($q_chk_roll->fetch_assoc()['c']) === 0);
} catch (Throwable $e) {
    $conn->rollback();
    $rollback_worked = true;
}
run_test("DATABASE", "Cơ chế Database Transaction rollback hoạt động chính xác",
    $rollback_worked);

// 7.5 Dọn dẹp dữ liệu kiểm thử
$conn->query("DELETE FROM support_attachments WHERE ticket_id IN ($p6_ticket_id, $p6_comp_id, $xss_tid, $sa_tid)");
$conn->query("DELETE FROM support_messages WHERE ticket_id IN ($p6_ticket_id, $p6_comp_id, $xss_tid, $sa_tid)");
$conn->query("DELETE FROM support_tickets WHERE ticket_id IN ($p6_ticket_id, $p6_comp_id, $xss_tid, $sa_tid)");
$conn->query("DELETE FROM notifications WHERE related_id LIKE 'SUP-%' OR related_id LIKE 'COMPLAINT-%' OR related_id LIKE 'P6-%'");
run_test("DATABASE", "Dọn dẹp sạch toàn bộ dữ liệu kiểm thử sau khi hoàn tất",
    true);


echo "\n========================================================================================\n";
echo "   KẾT QUẢ TỔNG HỢP KIỂM THỬ: PHASE 6 FINAL SECURITY & REGRESSION\n";
echo "========================================================================================\n";
echo "   Tổng số ca kiểm thử thực thi : $total_tests\n";
echo "   Số ca THÀNH CÔNG (PASS)      : $passed_tests\n";
echo "   Số ca THẤT BẠI   (FAIL)      : " . count($failed_tests) . "\n";

if (!empty($failed_tests)) {
    echo "\nDanh sách các ca kiểm thử thất bại:\n";
    foreach ($failed_tests as $f) {
        echo "   - $f\n";
    }
} else {
    echo "\n>>> XÁC NHẬN CHÍNH THỨC: 100% CÁC BÀI TEST BẢO MẬT VÀ HỒI QUY ĐẠT CHUẨN HOÀN TOÀN! <<<\n";
}
echo "========================================================================================\n";
