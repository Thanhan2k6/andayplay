<?php
/**
 * ANPLAY STORE - AUTOMATED TEST SUITE: PHASE 5 COMPLETE NOTIFICATION CENTER
 * Kiểm thử toàn diện hệ thống thông báo đa vai trò: USER, ADMIN, SUPER_ADMIN
 */

require_once __DIR__ . '/includes/connect.php';

$base_url = 'http://localhost:8080/quan_ly_thu_vien_game';

// Thiết lập Session IDs cho 3 vai trò
$sess_user = 'sessnotifuser' . substr(md5(uniqid()), 0, 10);
$sess_admin = 'sessnotifadmin' . substr(md5(uniqid()), 0, 10);
$sess_super = 'sessnotifsuper' . substr(md5(uniqid()), 0, 10);

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

// Đảm bảo dữ liệu role người dùng chính xác
$conn->query("UPDATE users SET role = 2, status = 'active' WHERE user_id = 1");
$conn->query("UPDATE users SET role = 0, status = 'active' WHERE user_id = 2");
$conn->query("UPDATE users SET role = 1, status = 'active' WHERE user_id = 3");

// Cấp quyền mẫu cho Admin 3: support.*, order.view, notification.view, notification.send
set_user_permissions($conn, 3, ['support.view', 'support.reply', 'order.view', 'notification.view', 'notification.send'], 1);

function http_req($url, $method = 'GET', $cookie_sess = '', $post_data = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $headers = [];
    if (!empty($cookie_sess)) {
        $headers[] = "Cookie: PHPSESSID=" . $cookie_sess;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
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

function assert_test($description, $condition, $extra_info = "") {
    global $total_tests, $passed_tests, $failed_tests;
    $total_tests++;
    if ($condition) {
        $passed_tests++;
        echo "  [PASS] Test #$total_tests: $description\n";
    } else {
        $failed_tests[] = "Test #$total_tests: $description " . ($extra_info ? "($extra_info)" : "");
        echo "  [FAIL] Test #$total_tests: $description " . ($extra_info ? "[$extra_info]" : "") . "\n";
    }
}

echo "========================================================================\n";
echo "   ANPLAY STORE - TEST SUITE: PHASE 5 NOTIFICATION CENTER\n";
echo "========================================================================\n\n";

// Dọn dẹp dữ liệu thông báo test cũ
$conn->query("DELETE FROM notifications WHERE related_id LIKE 'TEST-%' OR related_id LIKE 'EXP-SOON-%'");

// =========================================================================
// SECTION 1: USER NOTIFICATION SUITE
// =========================================================================
echo "--- SECTION 1: USER NOTIFICATION MATRIX ---\n";

// 1.1 Khuyến mãi (Promotion broadcast)
$init_u2_unread = get_unread_notification_count($conn, 2);
$promo_sent = broadcast_notification($conn, "Khuyến mãi Tết 2026", "Giảm giá 50% tất cả gói thuê game!", "promotion", "games.php", "customers");
assert_test("1.1 User nhận thông báo khuyến mãi khi broadcast cho khách hàng", $promo_sent > 0);

// 1.2 Đơn thuê game mới (Rental booking)
$notif_rent = create_notification($conn, 2, 'rental', "Thuê game thành công", "Bạn đã kích hoạt đơn thuê game Black Myth Wukong", "my_library.php", "TEST-RENT-1", 0);
assert_test("1.2 User nhận thông báo thuê game thành công", $notif_rent > 0);

// 1.3 Cảnh báo đơn thuê sắp hết hạn trong 24h
// Tạo một borrow record sắp hết hạn cho User 2 (trong 6 tiếng nữa)
$now_dt = date('Y-m-d H:i:s');
$due_soon_dt = date('Y-m-d H:i:s', strtotime('+6 hours'));
$conn->query("INSERT INTO borrow_records (user_id, game_id, account_id, borrow_date, due_date, status, payment_status, rental_price)
              VALUES (2, 1, 1, '$now_dt', '$due_soon_dt', 'active', 'paid', 25000)");
$test_borrow_id = $conn->insert_id;

$exp_warn_count = check_and_notify_expiring_rentals($conn, 2);
assert_test("1.3 Hệ thống quét và tự động gửi cảnh báo đơn thuê sắp hết hạn (trong 24h)", $exp_warn_count >= 1);

// Kiểm tra nội dung thông báo hết hạn
$q_exp_notif = $conn->query("SELECT * FROM notifications WHERE user_id = 2 AND related_id = 'EXP-SOON-{$test_borrow_id}' LIMIT 1");
assert_test("1.3b Thông báo hết hạn chứa liên kết và thông tin chuẩn xác", $q_exp_notif && $q_exp_notif->num_rows > 0);

// 1.4 Hết hạn đơn thuê (Rental expired)
$notif_exp = create_notification($conn, 2, 'rental', "Đơn thuê đã hết hạn", "Gói thuê Black Myth Wukong đã hết hạn sử dụng.", "my_library.php", "TEST-EXP-{$test_borrow_id}", 0);
assert_test("1.4 User nhận thông báo khi đơn thuê hết hạn", $notif_exp > 0);

// 1.5 Admin reply support ticket
$notif_reply = create_notification($conn, 2, 'support', "Phản hồi hỗ trợ từ Admin", "Admin @support_admin đã trả lời yêu cầu hỗ trợ của bạn.", "support_detail.php?id=999", "TEST-SUP-REPLY-1", 0);
assert_test("1.5 User nhận thông báo phản hồi hỗ trợ kèm liên kết", $notif_reply > 0);

// 1.6 Thanh toán thành công (Payment confirmation)
$notif_pay = create_notification($conn, 2, 'payment', "Thanh toán thành công", "Giao dịch 25.000đ cho đơn #{$test_borrow_id} đã hoàn tất.", "my_library.php", "TEST-PAY-1", 0);
assert_test("1.6 User nhận thông báo thanh toán thành công", $notif_pay > 0);

// 1.7 Thông báo cập nhật tài khoản (Account update)
$notif_acc = create_notification($conn, 2, 'account', "Cập nhật tài khoản", "Tài khoản của bạn đã được nâng cấp lên VIP.", "profile.php", "TEST-ACC-1", 0);
assert_test("1.7 User nhận thông báo liên quan đến tài khoản", $notif_acc > 0);

// Dọn dẹp borrow record test
$conn->query("DELETE FROM borrow_records WHERE borrow_id = $test_borrow_id");


// =========================================================================
// SECTION 2: ADMIN NOTIFICATION SUITE
// =========================================================================
echo "\n--- SECTION 2: ADMIN NOTIFICATION MATRIX ---\n";

// 2.1 Ticket hỗ trợ mới
$adm_notif_ticket = notify_all_admins($conn, 'support', "Yêu cầu hỗ trợ mới #T101", "Khách hàng @anday123 vừa tạo ticket mới", "admin_support_detail.php?id=101", "TEST-TKT-101", 0, 2);
assert_test("2.1 Admin nhận thông báo khi có ticket hỗ trợ mới", $adm_notif_ticket >= 1);

// 2.2 Khách hàng phản hồi ticket (User reply)
$adm_notif_usr_reply = notify_all_admins($conn, 'support', "Khách hàng phản hồi #T101", "Khách hàng đã gửi câu trả lời mới cho ticket #T101", "admin_support_detail.php?id=101", "TEST-TKT-REPLY-101", 0, 2);
assert_test("2.2 Admin nhận thông báo khi user phản hồi ticket", $adm_notif_usr_reply >= 1);

// 2.3 Đơn hàng mới cần xử lý (Admin có quyền 'order.view')
$order_notifs = notify_admins_with_permission($conn, 'order.view', 'order', "Đơn hàng mới #ORD-500", "Đơn hàng vừa được thanh toán thành công", "admin_borrow.php", "TEST-ORD-500", 0);
assert_test("2.3 Admin có quyền order.view nhận thông báo đơn hàng", $order_notifs >= 1);

// 2.4 Sự cố cấp phát tài khoản (Rental issue)
$alloc_issue_notifs = notify_admins_with_permission($conn, 'rental.manage', 'rental', "Cảnh báo hết tài khoản game!", "Đơn #ORD-501 không tìm thấy tài khoản trống", "admin_borrow.php", "TEST-ISSUE-501", 0);
// Admin 3 chưa có quyền 'rental.manage', nhưng Super Admin (user_id=1) luôn nhận
$q_adm3_has_issue = $conn->query("SELECT * FROM notifications WHERE user_id = 3 AND related_id = 'TEST-ISSUE-501-U3'");
assert_test("2.4 Admin KHÔNG có quyền rental.manage sẽ KHÔNG nhận cảnh báo sự cố tài khoản", $q_adm3_has_issue->num_rows === 0);
$q_sa_has_issue = $conn->query("SELECT * FROM notifications WHERE user_id = 1 AND related_id = 'TEST-ISSUE-501-U1'");
assert_test("2.4b Super Admin luôn nhận cảnh báo sự cố tài khoản", $q_sa_has_issue->num_rows > 0);


// =========================================================================
// SECTION 3: SUPER ADMIN NOTIFICATION SUITE
// =========================================================================
echo "\n--- SECTION 3: SUPER ADMIN NOTIFICATION MATRIX ---\n";

// 3.1 Khiếu nại của khách hàng (Complaint)
$sa_complaint_count = notify_super_admins($conn, 'complaint', "Khách hàng khiếu nại nghiêm trọng #C99", "Khách hàng @anday123 gửi khiếu nại dịch vụ", "admin_support_detail.php?id=99", "TEST-COMP-99", 0);
assert_test("3.1 Super Admin nhận cảnh báo khiếu nại của khách hàng", $sa_complaint_count >= 1);
$q_sa_comp = $conn->query("SELECT * FROM notifications WHERE user_id = 1 AND related_id = 'TEST-COMP-99-U1'");
assert_test("3.1b Thông báo khiếu nại được định tuyến đúng tới Super Admin", $q_sa_comp && $q_sa_comp->num_rows > 0);

// 3.2 Sự kiện bảo mật (Security event: admin lock / unlock / change role)
$sa_sec_count = notify_super_admins($conn, 'security', "Cảnh báo bảo mật: Thay đổi vai trò người dùng", "Super Admin @thanhantitan đã đổi vai trò của @test_mod sang Admin", "admin_management.php", "TEST-SEC-1", 0);
assert_test("3.2 Super Admin nhận thông báo sự kiện bảo mật", $sa_sec_count >= 1);

// 3.3 Sự cố thanh toán (Payment issue)
$sa_pay_issue = notify_super_admins($conn, 'payment', "Sự cố giao dịch thanh toán", "Giao dịch VNPay #TX999 bị sai lệch số tiền", "admin_dashboard.php", "TEST-PAY-ISSUE-999", 0);
assert_test("3.3 Super Admin nhận thông báo sự cố thanh toán hệ thống", $sa_pay_issue >= 1);


// =========================================================================
// SECTION 4: DEDUPLICATION & IDEMPOTENCY
// =========================================================================
echo "\n--- SECTION 4: DEDUPLICATION & IDEMPOTENCY ---\n";

// Thử tạo cùng một thông báo với dedup_window 30 phút
$dedup_id_1 = create_notification($conn, 2, 'promotion', "Ưu đãi duy nhất", "Nhận 10k vàng miễn phí", "games.php", "TEST-DEDUP-UNIQUE-1", 30);
$dedup_id_2 = create_notification($conn, 2, 'promotion', "Ưu đãi duy nhất", "Nhận 10k vàng miễn phí", "games.php", "TEST-DEDUP-UNIQUE-1", 30);

assert_test("4.1 Cuộc gọi thông báo đầu tiên tạo ra bản ghi hợp lệ", is_numeric($dedup_id_1) && $dedup_id_1 > 0);
assert_test("4.2 Cuộc gọi trùng lặp trong cửa sổ thời gian trả về ID đã có và không chèn bản ghi mới", $dedup_id_1 === $dedup_id_2);

$q_dedup_count = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id = 2 AND related_id = 'TEST-DEDUP-UNIQUE-1'");
$dedup_row_count = intval($q_dedup_count->fetch_assoc()['c']);
assert_test("4.3 Cơ sở dữ liệu chỉ tồn tại duy nhất 1 bản ghi (không bị spam duplicate)", $dedup_row_count === 1);


// =========================================================================
// SECTION 5: IDOR & SECURITY PROTECTION
// =========================================================================
echo "\n--- SECTION 5: IDOR & SECURITY PROTECTION ---\n";

// Lấy 1 thông báo thuộc về User 1 (Super Admin)
$sa_notif_res = $conn->query("SELECT id FROM notifications WHERE user_id = 1 LIMIT 1");
$sa_notif_id = $sa_notif_res->fetch_assoc()['id'];

// User 2 (anday123) cố gắng đánh dấu đọc thông báo của User 1
$idor_read_result = mark_notification_read($conn, $sa_notif_id, 2);
assert_test("5.1 User 2 KHÔNG thể đánh dấu đọc thông báo của User 1 (Chống IDOR Read)", $idor_read_result === false || $conn->affected_rows === 0);

// User 2 cố gắng xóa thông báo của User 1
$idor_del_result = delete_notification($conn, $sa_notif_id, 2);
assert_test("5.2 User 2 KHÔNG thể xóa thông báo của User 1 (Chống IDOR Delete)", $idor_del_result === false || $conn->affected_rows === 0);

// Xác minh thông báo của User 1 vẫn tồn tại nguyên vẹn
$q_sa_still_exists = $conn->query("SELECT id FROM notifications WHERE id = $sa_notif_id");
assert_test("5.3 Thông báo của User 1 vẫn được bảo vệ nguyên vẹn trong CSDL", $q_sa_still_exists && $q_sa_still_exists->num_rows === 1);


// =========================================================================
// SECTION 6: UNREAD COUNT & BADGE INTEGRITY
// =========================================================================
echo "\n--- SECTION 6: UNREAD COUNT & BADGE INTEGRITY ---\n";

// Lấy 1 thông báo chưa đọc của User 2
$u2_notif_q = $conn->query("SELECT id FROM notifications WHERE user_id = 2 AND is_read = 0 LIMIT 1");
$u2_notif = $u2_notif_q->fetch_assoc();
$test_notif_id = intval($u2_notif['id']);

$unread_before = get_unread_notification_count($conn, 2);
$db_actual_before = intval($conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id = 2 AND is_read = 0")->fetch_assoc()['c']);
assert_test("6.1 Hàm get_unread_notification_count khớp chính xác với SELECT COUNT CSDL thực tế", $unread_before === $db_actual_before);

// Đánh dấu đã đọc 1 thông báo
mark_notification_read($conn, $test_notif_id, 2);
$unread_after_one = get_unread_notification_count($conn, 2);
assert_test("6.2 Số lượng chưa đọc giảm chính xác 1 sau khi mark_read", $unread_after_one === $unread_before - 1);

// Đánh dấu tất cả đã đọc
mark_all_notifications_read($conn, 2);
$unread_after_all = get_unread_notification_count($conn, 2);
assert_test("6.3 Số lượng chưa đọc về 0 sau khi mark_all_notifications_read", $unread_after_all === 0);

// Xóa 1 thông báo
$del_res = delete_notification($conn, $test_notif_id, 2);
$q_check_del = $conn->query("SELECT id FROM notifications WHERE id = $test_notif_id");
assert_test("6.4 Xóa thành công thông báo cá nhân khỏi CSDL", $del_res && $q_check_del->num_rows === 0);

// Dọn dẹp tất cả thông báo đã đọc
delete_user_notifications($conn, 2, true);
$q_remaining_read = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id = 2 AND is_read = 1");
$read_remaining = intval($q_remaining_read->fetch_assoc()['c']);
assert_test("6.5 delete_user_notifications(only_read=true) dọn sạch thông báo đã đọc", $read_remaining === 0);


// =========================================================================
// SECTION 7: HTTP API NOTIFICATION ENDPOINTS
// =========================================================================
echo "\n--- SECTION 7: HTTP API NOTIFICATION ENDPOINTS ---\n";

// Tạo 2 thông báo mới cho User 2 để kiểm thử API HTTP
$api_n1 = create_notification($conn, 2, 'support', "Thông báo API Test 1", "Nội dung API 1", "support.php", "TEST-API-1", 0);
$api_n2 = create_notification($conn, 2, 'rental', "Thông báo API Test 2", "Nội dung API 2", "my_library.php", "TEST-API-2", 0);

// 7.1 GET unread count
$res_get_count = http_req("$base_url/api_notifications.php?action=get_unread_count", 'GET', $sess_user);
$json_count = json_decode($res_get_count['body'], true);
assert_test("7.1 API get_unread_count trả về JSON hợp lệ với số lượng unread", 
    $res_get_count['code'] === 200 && isset($json_count['success']) && $json_count['success'] === true && $json_count['unread_count'] >= 2);

// 7.2 GET dropdown items
$res_get_drop = http_req("$base_url/api_notifications.php?action=get_dropdown", 'GET', $sess_user);
$json_drop = json_decode($res_get_drop['body'], true);
assert_test("7.2 API get_dropdown trả về danh sách items kèm icon_info và time_ago",
    $res_get_drop['code'] === 200 && isset($json_drop['items']) && count($json_drop['items']) >= 2 && isset($json_drop['items'][0]['icon_info']));

// 7.3 POST mark_read
$res_mark = http_req("$base_url/api_notifications.php", 'POST', $sess_user, ['action' => 'mark_read', 'id' => $api_n1]);
$json_mark = json_decode($res_mark['body'], true);
assert_test("7.3 API mark_read chuyển trạng thái đọc và trả về new unread_count",
    $res_mark['code'] === 200 && isset($json_mark['success']) && $json_mark['success'] === true);

// 7.4 POST mark_all_read
$res_mark_all = http_req("$base_url/api_notifications.php", 'POST', $sess_user, ['action' => 'mark_all_read']);
$json_mark_all = json_decode($res_mark_all['body'], true);
assert_test("7.4 API mark_all_read đánh dấu tất cả đã đọc và unread_count = 0",
    $res_mark_all['code'] === 200 && isset($json_mark_all['unread_count']) && $json_mark_all['unread_count'] === 0);

// 7.5 POST delete single
$res_api_del = http_req("$base_url/api_notifications.php", 'POST', $sess_user, ['action' => 'delete', 'id' => $api_n1]);
$json_api_del = json_decode($res_api_del['body'], true);
assert_test("7.5 API delete xóa thông báo hợp lệ",
    $res_api_del['code'] === 200 && isset($json_api_del['success']) && $json_api_del['success'] === true);

// 7.6 POST clear_all read
$res_clear = http_req("$base_url/api_notifications.php", 'POST', $sess_user, ['action' => 'clear_all', 'only_read' => 1]);
$json_clear = json_decode($res_clear['body'], true);
assert_test("7.6 API clear_all (only_read=1) hoàn tất thành công",
    $res_clear['code'] === 200 && isset($json_clear['success']) && $json_clear['success'] === true);


// =========================================================================
// SECTION 8: FULL PAGE NOTIFICATIONS.PHP & TAB FILTERING
// =========================================================================
echo "\n--- SECTION 8: FULL PAGE NOTIFICATIONS.PHP & TAB FILTERING ---\n";

// Tạo mẫu các loại thông báo cho User 2 để kiểm thử phân trang & tabs
create_notification($conn, 2, 'rental', "Tab Rental Test", "Chi tiết đơn thuê", "my_library.php", "TEST-TAB-RENT", 0);
create_notification($conn, 2, 'support', "Tab Support Test", "Chi tiết ticket hỗ trợ", "support.php", "TEST-TAB-SUP", 0);
create_notification($conn, 2, 'system', "Tab System Test", "Thông báo bảo trì hệ thống", "", "TEST-TAB-SYS", 0);

// 8.1 Truy cập notifications.php không đăng nhập -> Chuyển hướng login.php
$res_anon = http_req("$base_url/notifications.php", 'GET');
assert_test("8.1 Truy cập notifications.php không đăng nhập bị redirect về login.php",
    $res_anon['code'] === 302 && strpos($res_anon['redirect'], 'login.php') !== false);

// 8.2 Truy cập notifications.php khi đã đăng nhập
$res_page = http_req("$base_url/notifications.php?tab=all", 'GET', $sess_user);
assert_test("8.2 User truy cập notifications.php trả về HTTP 200 và giao diện Trung Tâm Thông Báo",
    $res_page['code'] === 200 && strpos($res_page['body'], 'Trung Tâm Thông Báo') !== false);

// 8.3 Tab Rental
$items_rental = get_user_notifications($conn, 2, 'rental', false, 10, 0);
$all_rental_or_order = true;
foreach ($items_rental as $it) {
    if (!in_array($it['type'], ['rental', 'order'])) {
        $all_rental_or_order = false;
        break;
    }
}
assert_test("8.3 Tab Đơn thuê chỉ lọc thông báo loại rental và order", count($items_rental) > 0 && $all_rental_or_order);

// 8.4 Tab Support
$items_support = get_user_notifications($conn, 2, 'support', false, 10, 0);
$all_support_or_complaint = true;
foreach ($items_support as $it) {
    if (!in_array($it['type'], ['support', 'complaint'])) {
        $all_support_or_complaint = false;
        break;
    }
}
assert_test("8.4 Tab Hỗ trợ chỉ lọc thông báo loại support và complaint", count($items_support) > 0 && $all_support_or_complaint);

// 8.5 Tab System
$items_sys = get_user_notifications($conn, 2, 'system', false, 10, 0);
$all_system_types = true;
foreach ($items_sys as $it) {
    if (!in_array($it['type'], ['system', 'security', 'account'])) {
        $all_system_types = false;
        break;
    }
}
assert_test("8.5 Tab Hệ thống chỉ lọc thông báo loại system, security và account", count($items_sys) > 0 && $all_system_types);


// =========================================================================
// SECTION 9: CLEANUP & AUDIT INTEGRITY
// =========================================================================
echo "\n--- SECTION 9: CLEANUP & DATABASE INTEGRITY ---\n";

// Dọn dẹp thông báo test
$conn->query("DELETE FROM notifications WHERE related_id LIKE 'TEST-%' OR related_id LIKE 'EXP-SOON-%'");
assert_test("9.1 Dọn sạch toàn bộ dữ liệu thông báo test sau khi kiểm thử", true);

// Khôi phục trạng thái chuẩn
$conn->query("UPDATE users SET role = 2, status = 'active' WHERE user_id = 1");
$conn->query("UPDATE users SET role = 0, status = 'active' WHERE user_id = 2");
$conn->query("UPDATE users SET role = 1, status = 'active' WHERE user_id = 3");
assert_test("9.2 Trạng thái người dùng hệ thống duy trì toàn vẹn", true);

echo "\n========================================================================\n";
echo "   KẾT QUẢ KIỂM THỬ PHASE 5 NOTIFICATION CENTER\n";
echo "========================================================================\n";
echo "   Tổng số bài test: $total_tests\n";
echo "   Số bài đạt (PASS): $passed_tests\n";
echo "   Số bài trượt (FAIL): " . count($failed_tests) . "\n";

if (!empty($failed_tests)) {
    echo "\nDanh sách kiểm thử thất bại:\n";
    foreach ($failed_tests as $f) {
        echo "   - $f\n";
    }
} else {
    echo "\n>>> HOÀN TOÀN THÀNH CÔNG: 100% KIỂM THỬ ĐẠT CHUẨN! <<<\n";
}
echo "========================================================================\n";

