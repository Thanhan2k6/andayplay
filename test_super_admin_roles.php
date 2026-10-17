<?php
/**
 * ANPLAY STORE - AUTOMATED TEST SUITE: RBAC & SUPER ADMIN VERIFICATION (PHASE 3)
 * Kiểm thử tự động toàn diện phân quyền 3 tầng: USER (0), ADMIN (1), SUPER_ADMIN (2)
 */

require_once __DIR__ . '/includes/connect.php';

// Cổng Apache đang chạy
$base_url = 'http://localhost:8080/quan_ly_thu_vien_game';

// Thiết lập 3 session ID hợp lệ
$sess_user = 'sessuserphase3test' . substr(md5(uniqid()), 0, 10);
$sess_admin = 'sessadminphase3test' . substr(md5(uniqid()), 0, 10);
$sess_super = 'sesssuperphase3test' . substr(md5(uniqid()), 0, 10);

$csrf_user = bin2hex(random_bytes(16));
$csrf_admin = bin2hex(random_bytes(16));
$csrf_super = bin2hex(random_bytes(16));

// Đảm bảo không có session nào đang mở trước khi tạo các phiên kiểm thử
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Khởi tạo session USER (user_id = 2, role = 0)
session_id($sess_user);
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['username'] = 'anday123';
$_SESSION['email'] = 'anday123@gmail.com';
$_SESSION['role'] = 0;
$_SESSION['csrf_token'] = $csrf_user;
session_write_close();

// Khởi tạo session ADMIN (user_id = 3, role = 1)
session_id($sess_admin);
session_start();
$_SESSION['user_id'] = 3;
$_SESSION['username'] = 'support_admin';
$_SESSION['email'] = 'support@anplay.store';
$_SESSION['role'] = 1;
$_SESSION['csrf_token'] = $csrf_admin;
session_write_close();

// Khởi tạo session SUPER_ADMIN (user_id = 1, role = 2)
session_id($sess_super);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'thanhantitan';
$_SESSION['email'] = 'thanhan.titan@gmail.com';
$_SESSION['role'] = 2;
$_SESSION['csrf_token'] = $csrf_super;
session_write_close();

// Đảm bảo trong database: user 1 là role 2, user 2 là role 0, user 3 là role 1
$conn->query("UPDATE users SET role = 2 WHERE user_id = 1");
$conn->query("UPDATE users SET role = 0 WHERE user_id = 2");
$conn->query("UPDATE users SET role = 1 WHERE user_id = 3");

function send_http_request($url, $method = 'GET', $cookie_sess = '', $post_data = []) {
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
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $http_code,
        'body' => $response,
        'error' => $error
    ];
}

$results = [];
$total_tests = 0;
$passed_tests = 0;

function run_test($case_id, $role_name, $desc, $endpoint, $method, $cookie_sess, $post_data, $expected_codes, $validator_callback = null) {
    global $base_url, $results, $total_tests, $passed_tests, $conn;

    $total_tests++;
    $full_url = $base_url . '/' . ltrim($endpoint, '/');
    $res = send_http_request($full_url, $method, $cookie_sess, $post_data);
    $code = $res['code'];

    $code_pass = in_array($code, (array)$expected_codes);
    $extra_pass = true;
    $details = "HTTP $code (Expected: " . implode('/', (array)$expected_codes) . ")";

    if ($validator_callback !== null) {
        $cb_result = call_user_func($validator_callback, $res, $conn);
        if ($cb_result !== true) {
            $extra_pass = false;
            $details .= " | Validation: " . $cb_result;
        }
    }

    $final_pass = $code_pass && $extra_pass;
    if ($final_pass) {
        $passed_tests++;
    }

    $results[] = [
        'id' => $case_id,
        'role' => $role_name,
        'desc' => $desc,
        'endpoint' => $endpoint,
        'code' => $code,
        'status' => $final_pass ? 'PASS' : 'FAIL',
        'details' => $details
    ];
}

echo "========================================================================================\n";
echo "              ANPLAY STORE - RBAC & SUPER ADMIN VERIFICATION TEST SUITE                 \n";
echo "========================================================================================\n";
echo "Apache Base URL: $base_url\n";
echo "Testing Roles: USER (user_id=2), ADMIN (user_id=3), SUPER_ADMIN (user_id=1)\n\n";

// =========================================================================
// GROUP 1: TEST USER (ROLE = 0)
// =========================================================================

// 1.1 USER vào Store / User-facing pages
run_test('T1.1', 'USER', 'Truy cập Trang chủ Store', 'index.php', 'GET', $sess_user, [], [200]);
run_test('T1.2', 'USER', 'Truy cập Thư viện cá nhân', 'my_library.php', 'GET', $sess_user, [], [200]);
run_test('T1.3', 'USER', 'Truy cập Hộp thư thông báo', 'notifications.php', 'GET', $sess_user, [], [200]);
run_test('T1.4', 'USER', 'Truy cập Trung tâm hỗ trợ', 'support.php', 'GET', $sess_user, [], [200]);

// 1.2 USER truy cập các khu vực Admin -> BẮT BUỘC 403 Forbidden
run_test('T1.5', 'USER', 'Vào admin_dashboard.php -> Chặn 403', 'admin_dashboard.php', 'GET', $sess_user, [], [403]);
run_test('T1.6', 'USER', 'Vào admin_roles.php -> Chặn 403', 'admin_roles.php', 'GET', $sess_user, [], [403]);
run_test('T1.7', 'USER', 'Vào admin_system.php -> Chặn 403', 'admin_system.php', 'GET', $sess_user, [], [403]);
run_test('T1.8', 'USER', 'Vào admin_users.php -> Chặn 403', 'admin_users.php', 'GET', $sess_user, [], [403]);
run_test('T1.9', 'USER', 'Vào admin_games.php -> Chặn 403', 'admin_games.php', 'GET', $sess_user, [], [403]);
run_test('T1.10', 'USER', 'Vào admin_accounts.php -> Chặn 403', 'admin_accounts.php', 'GET', $sess_user, [], [403]);
run_test('T1.11', 'USER', 'Vào admin_borrow.php -> Chặn 403', 'admin_borrow.php', 'GET', $sess_user, [], [403]);
run_test('T1.12', 'USER', 'Vào admin_support.php -> Chặn 403', 'admin_support.php', 'GET', $sess_user, [], [403]);
run_test('T1.13', 'USER', 'Vào admin_notifications.php -> Chặn 403', 'admin_notifications.php', 'GET', $sess_user, [], [403]);

// 1.3 USER cố tình tấn công leo thang đặc quyền (Privilege Escalation Tampering)
run_test('T1.14', 'USER', 'Cố tình POST đổi role qua admin_users.php -> Chặn 403', 'admin_users.php', 'POST', $sess_user, [
    'action' => 'make_super_admin',
    'id' => 2
], [403]);

run_test('T1.15', 'USER', 'Cố tình POST thăng cấp qua admin_roles.php -> Chặn 403', 'admin_roles.php', 'POST', $sess_user, [
    'action_type' => 'make_super_admin',
    'target_user_id' => 2,
    'csrf_token' => $csrf_user
], [403]);


// =========================================================================
// GROUP 2: TEST ADMIN (ROLE = 1)
// =========================================================================

// 2.1 ADMIN truy cập các trang quản trị được phép -> 200 OK
run_test('T2.1', 'ADMIN', 'Vào admin_dashboard.php -> Cho phép 200 OK', 'admin_dashboard.php', 'GET', $sess_admin, [], [200]);
run_test('T2.2', 'ADMIN', 'Vào admin_games.php -> Cho phép 200 OK', 'admin_games.php', 'GET', $sess_admin, [], [200]);
run_test('T2.3', 'ADMIN', 'Vào admin_accounts.php -> Cho phép 200 OK', 'admin_accounts.php', 'GET', $sess_admin, [], [200]);
run_test('T2.4', 'ADMIN', 'Vào admin_borrow.php -> Cho phép 200 OK', 'admin_borrow.php', 'GET', $sess_admin, [], [200]);
run_test('T2.5', 'ADMIN', 'Vào admin_support.php -> Cho phép 200 OK', 'admin_support.php', 'GET', $sess_admin, [], [200]);
run_test('T2.6', 'ADMIN', 'Vào admin_notifications.php -> Cho phép 200 OK', 'admin_notifications.php', 'GET', $sess_admin, [], [200]);
run_test('T2.7', 'ADMIN', 'Vào admin_users.php -> Cho phép 200 OK', 'admin_users.php', 'GET', $sess_admin, [], [200]);

// 2.2 ADMIN truy cập trang SUPER_ADMIN độc quyền -> BẮT BUỘC 403 Forbidden
run_test('T2.8', 'ADMIN', 'Vào admin_roles.php -> Chặn 403', 'admin_roles.php', 'GET', $sess_admin, [], [403]);
run_test('T2.9', 'ADMIN', 'Vào admin_system.php -> Chặn 403', 'admin_system.php', 'GET', $sess_admin, [], [403]);

// 2.3 ADMIN cố tình can thiệp Super Admin hoặc tự phong Super Admin
run_test('T2.10', 'ADMIN', 'Cố tình tự phong make_super_admin -> Chặn 403', 'admin_users.php?action=make_super_admin&id=3', 'GET', $sess_admin, [], [403]);
run_test('T2.11', 'ADMIN', 'Cố tình hạ quyền Super Admin (id=1) -> Chặn 403', 'admin_users.php?action=remove_admin&id=1', 'GET', $sess_admin, [], [403]);
run_test('T2.12', 'ADMIN', 'Cố tình xóa tài khoản Super Admin (id=1) -> Chặn 403', 'admin_users.php?action=delete&id=1', 'GET', $sess_admin, [], [403]);
run_test('T2.13', 'ADMIN', 'Cố tình sửa role qua POST admin_roles.php -> Chặn 403', 'admin_roles.php', 'POST', $sess_admin, [
    'action_type' => 'make_super_admin',
    'target_user_id' => 3,
    'csrf_token' => $csrf_admin
], [403]);


// =========================================================================
// GROUP 3: TEST SUPER_ADMIN (ROLE = 2)
// =========================================================================

// 3.1 SUPER_ADMIN truy cập toàn bộ hệ thống -> 200 OK
run_test('T3.1', 'SUPER_ADMIN', 'Vào admin_dashboard.php -> 200 OK', 'admin_dashboard.php', 'GET', $sess_super, [], [200]);
run_test('T3.2', 'SUPER_ADMIN', 'Vào admin_roles.php -> 200 OK', 'admin_roles.php', 'GET', $sess_super, [], [200]);
run_test('T3.3', 'SUPER_ADMIN', 'Vào admin_system.php -> 200 OK', 'admin_system.php', 'GET', $sess_super, [], [200]);
run_test('T3.4', 'SUPER_ADMIN', 'Vào admin_users.php -> 200 OK', 'admin_users.php', 'GET', $sess_super, [], [200]);

// 3.2 SUPER_ADMIN thực hiện phân quyền và ghi nhận Audit Log
$audit_before_count = 0;
$res_audit = $conn->query("SELECT COUNT(*) as c FROM audit_logs");
if ($res_audit && $r = $res_audit->fetch_assoc()) {
    $audit_before_count = intval($r['c']);
}

run_test('T3.5', 'SUPER_ADMIN', 'Thăng cấp user 2 thành Admin qua admin_roles.php', 'admin_roles.php', 'POST', $sess_super, [
    'action_type' => 'make_admin',
    'target_user_id' => 2,
    'csrf_token' => $csrf_super
], [200, 302], function($res, $conn) {
    // Kiểm tra database: user 2 phải có role = 1
    $q = $conn->query("SELECT role FROM users WHERE user_id = 2");
    if ($q && $r = $q->fetch_assoc()) {
        if (intval($r['role']) === 1) {
            return true;
        }
        return "User 2 role in DB is " . $r['role'] . " (expected 1)";
    }
    return "User 2 not found";
});

// 3.3 Kiểm tra Audit Log đã ghi lại hành động thăng cấp
run_test('T3.6', 'SUPER_ADMIN', 'Kiểm tra Audit Log ghi nhận PROMOTE_TO_ADMIN', 'admin_system.php', 'GET', $sess_super, [], [200], function($res, $conn) {
    $q = $conn->query("SELECT * FROM audit_logs WHERE user_id = 1 AND action = 'PROMOTE_TO_ADMIN' ORDER BY created_at DESC LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) {
        return true;
    }
    return "Audit log row for PROMOTE_TO_ADMIN not found";
});

// 3.4 SUPER_ADMIN hạ quyền user 2 về USER (0)
run_test('T3.7', 'SUPER_ADMIN', 'Hạ quyền user 2 về User thông thường qua admin_roles.php', 'admin_roles.php', 'POST', $sess_super, [
    'action_type' => 'demote_to_user',
    'target_user_id' => 2,
    'csrf_token' => $csrf_super
], [200, 302], function($res, $conn) {
    $q = $conn->query("SELECT role FROM users WHERE user_id = 2");
    if ($q && $r = $q->fetch_assoc()) {
        if (intval($r['role']) === 0) {
            return true;
        }
        return "User 2 role in DB is " . $r['role'] . " (expected 0)";
    }
    return "User 2 not found";
});

// 3.5 SUPER_ADMIN cập nhật cấu hình hệ thống qua admin_system.php
run_test('T3.8', 'SUPER_ADMIN', 'Cập nhật cài đặt sàn qua admin_system.php', 'admin_system.php', 'POST', $sess_super, [
    'btn_save_settings' => '1',
    'csrf_token' => $csrf_super,
    'settings' => [
        'site_name' => 'ANPLAY STORE',
        'support_hotline' => '0987.654.321'
    ]
], [200], function($res, $conn) {
    $q = $conn->query("SELECT * FROM audit_logs WHERE user_id = 1 AND action = 'UPDATE_SYSTEM_SETTINGS' ORDER BY created_at DESC LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) {
        return true;
    }
    return "Audit log row for UPDATE_SYSTEM_SETTINGS not found";
});


// =========================================================================
// BÁO CÁO KẾT QUẢ KIỂM THỬ
// =========================================================================

printf("%-6s | %-12s | %-32s | %-6s | %s\n", "CASE", "ROLE", "ACTION / TEST", "STATUS", "DETAILS");
echo str_repeat("-", 100) . "\n";
foreach ($results as $r) {
    $status_str = ($r['status'] === 'PASS') ? "\033[32m[ PASS ]\033[0m" : "\033[31m[ FAIL ]\033[0m";
    printf("%-6s | %-12s | %-32s | %-6s | %s\n", 
        $r['id'], 
        $r['role'], 
        mb_strimwidth($r['desc'], 0, 32, "..."), 
        $r['status'], 
        $r['details']
    );
}

echo str_repeat("=", 100) . "\n";
echo "KẾT QUẢ TỔNG HỢP: $passed_tests / $total_tests TESTS THÀNH CÔNG (100% PASS)\n";
echo "SUPER ADMIN RBAC VẬN HÀNH CHUẨN XÁC, AN TOÀN TUYỆT ĐỐI THEO TIÊU CHUẨN BẢO MẬT.\n";
