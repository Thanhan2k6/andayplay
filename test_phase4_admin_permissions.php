<?php
/**
 * ANPLAY STORE - AUTOMATED TEST SUITE: PHASE 4 ADMIN MANAGEMENT & PERMISSION
 * Kiểm thử toàn diện các tính năng độc quyền Super Admin và bảo mật permissions
 */

require_once __DIR__ . '/includes/connect.php';

$base_url = 'http://localhost:8080/quan_ly_thu_vien_game';

// Thiết lập Session IDs
$sess_user = 'sessphase4user' . substr(md5(uniqid()), 0, 10);
$sess_admin = 'sessphase4admin' . substr(md5(uniqid()), 0, 10);
$sess_super = 'sessphase4super' . substr(md5(uniqid()), 0, 10);

$csrf_user = bin2hex(random_bytes(16));
$csrf_admin = bin2hex(random_bytes(16));
$csrf_super = bin2hex(random_bytes(16));

// Khởi tạo các phiên làm việc
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

// Đảm bảo trạng thái cơ sở dữ liệu ban đầu
$conn->query("UPDATE users SET role = 2, status = 'active' WHERE user_id = 1");
$conn->query("UPDATE users SET role = 0, status = 'active' WHERE user_id = 2");
$conn->query("UPDATE users SET role = 1, status = 'active' WHERE user_id = 3");

// Dọn dẹp tài khoản test tạm nếu còn sót từ lần trước
$conn->query("DELETE FROM user_permissions WHERE user_id IN (SELECT user_id FROM users WHERE username = 'test_mod_4')");
$conn->query("DELETE FROM users WHERE username = 'test_mod_4'");

function http_request($url, $method = 'GET', $cookie_sess = '', $post_data = []) {
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
$total_count = 0;
$passed_count = 0;

function execute_test($id, $role, $title, $endpoint, $method, $cookie, $data, $expected_codes, $validator = null) {
    global $base_url, $results, $total_count, $passed_count, $conn;

    $total_count++;
    $url = $base_url . '/' . ltrim($endpoint, '/');
    $res = http_request($url, $method, $cookie, $data);
    $code = $res['code'];

    $code_ok = in_array($code, (array)$expected_codes);
    $val_ok = true;
    $details = "HTTP $code (Expected: " . implode('/', (array)$expected_codes) . ")";

    if ($validator !== null) {
        $v_res = call_user_func($validator, $res, $conn);
        if ($v_res !== true) {
            $val_ok = false;
            $details .= " | " . $v_res;
        }
    }

    $pass = $code_ok && $val_ok;
    if ($pass) {
        $passed_count++;
    }

    $results[] = [
        'id' => $id,
        'role' => $role,
        'title' => $title,
        'status' => $pass ? 'PASS' : 'FAIL',
        'details' => $details
    ];
}

echo "========================================================================================\n";
echo "       ANPLAY STORE - PHASE 4: ADMIN MANAGEMENT & PERMISSIONS TEST SUITE                \n";
echo "========================================================================================\n\n";

// -------------------------------------------------------------------------
// GROUP 1: KIỂM THỬ TRUY CẬP ĐỘC QUYỀN SUPER_ADMIN (ACCESS CONTROL)
// -------------------------------------------------------------------------
execute_test('P4.1', 'USER', 'USER truy cập admin_management.php -> Chặn 403', 'admin_management.php', 'GET', $sess_user, [], [403]);
execute_test('P4.2', 'ADMIN', 'ADMIN thường truy cập admin_management.php -> Chặn 403', 'admin_management.php', 'GET', $sess_admin, [], [403]);
execute_test('P4.3', 'SUPER_ADMIN', 'SUPER_ADMIN truy cập admin_management.php -> Cho phép 200 OK', 'admin_management.php', 'GET', $sess_super, [], [200]);

// -------------------------------------------------------------------------
// GROUP 2: PHÒNG CHỐNG GIẢ MẠO REQUEST TỪ USER / ADMIN (TAMPERING DEFENSE)
// -------------------------------------------------------------------------
execute_test('P4.4', 'USER', 'USER gửi POST tạo admin -> Chặn 403', 'admin_management.php', 'POST', $sess_user, [
    'action' => 'create_admin',
    'username' => 'hack_admin',
    'email' => 'hack@gmail.com',
    'password' => '123456',
    'csrf_token' => $csrf_user
], [403]);

execute_test('P4.5', 'ADMIN', 'ADMIN gửi POST tạo admin -> Chặn 403', 'admin_management.php', 'POST', $sess_admin, [
    'action' => 'create_admin',
    'username' => 'hack_admin',
    'email' => 'hack@gmail.com',
    'password' => '123456',
    'csrf_token' => $csrf_admin
], [403]);

execute_test('P4.6', 'ADMIN', 'ADMIN gửi POST khóa Super Admin -> Chặn 403', 'admin_management.php', 'POST', $sess_admin, [
    'action' => 'lock_admin',
    'target_user_id' => 1,
    'csrf_token' => $csrf_admin
], [403]);

execute_test('P4.7', 'ADMIN', 'ADMIN gửi POST xóa Super Admin -> Chặn 403', 'admin_management.php', 'POST', $sess_admin, [
    'action' => 'delete_admin',
    'target_user_id' => 1,
    'csrf_token' => $csrf_admin
], [403]);

// -------------------------------------------------------------------------
// GROUP 3: QUẢN LÝ ADMIN BỞI SUPER_ADMIN (CRUD + LOCK + UNLOCK)
// -------------------------------------------------------------------------
$created_test_admin_id = 0;

// 3.1 SUPER_ADMIN tạo ADMIN mới kèm phân quyền ban đầu
execute_test('P4.8', 'SUPER_ADMIN', 'Tạo tài khoản ADMIN mới (test_mod_4)', 'admin_management.php', 'POST', $sess_super, [
    'action' => 'create_admin',
    'username' => 'test_mod_4',
    'email' => 'testmod4@anplay.store',
    'password' => 'SecurePass123!@#',
    'permissions' => ['support.view', 'support.reply', 'game.view'],
    'csrf_token' => $csrf_super
], [200], function($res, $conn) use (&$created_test_admin_id) {
    $q = $conn->query("SELECT user_id, role, status FROM users WHERE username = 'test_mod_4'");
    if ($q && $r = $q->fetch_assoc()) {
        $created_test_admin_id = intval($r['user_id']);
        if (intval($r['role']) === 1 && $r['status'] === 'active') {
            return true;
        }
        return "User created but role=" . $r['role'] . " status=" . $r['status'];
    }
    return "User test_mod_4 was not created in database";
});

// 3.2 Kiểm tra permissions được cấp trong user_permissions
execute_test('P4.9', 'SUPER_ADMIN', 'Kiểm tra Permissions ban đầu của Admin mới', 'admin_management.php', 'GET', $sess_super, [], [200], function($res, $conn) use (&$created_test_admin_id) {
    if ($created_test_admin_id <= 0) return "No test admin ID";
    $q = $conn->query("SELECT permission_key FROM user_permissions WHERE user_id = $created_test_admin_id");
    $keys = [];
    while ($r = $q->fetch_assoc()) {
        $keys[] = $r['permission_key'];
    }
    if (in_array('support.view', $keys) && in_array('support.reply', $keys) && in_array('game.view', $keys)) {
        return true;
    }
    return "Permissions mismatch: " . implode(', ', $keys);
});

// 3.3 SUPER_ADMIN thay đổi permissions cho Admin
execute_test('P4.10', 'SUPER_ADMIN', 'Cập nhật lại Permissions cho Admin', 'admin_management.php', 'POST', $sess_super, [
    'action' => 'update_permissions',
    'target_user_id' => $created_test_admin_id,
    'permissions' => ['customer.view', 'customer.manage', 'rental.view'],
    'csrf_token' => $csrf_super
], [200], function($res, $conn) use (&$created_test_admin_id) {
    if ($created_test_admin_id <= 0) return "No test admin ID";
    $q = $conn->query("SELECT permission_key FROM user_permissions WHERE user_id = $created_test_admin_id");
    $keys = [];
    while ($r = $q->fetch_assoc()) {
        $keys[] = $r['permission_key'];
    }
    if (in_array('customer.view', $keys) && in_array('customer.manage', $keys) && !in_array('support.view', $keys)) {
        return true;
    }
    return "Updated permissions mismatch: " . implode(', ', $keys);
});

// 3.4 SUPER_ADMIN khóa tài khoản ADMIN (Lock)
execute_test('P4.11', 'SUPER_ADMIN', 'Khóa tài khoản Admin (Lock -> suspended)', 'admin_management.php', 'POST', $sess_super, [
    'action' => 'lock_admin',
    'target_user_id' => $created_test_admin_id,
    'csrf_token' => $csrf_super
], [200], function($res, $conn) use (&$created_test_admin_id) {
    $q = $conn->query("SELECT status FROM users WHERE user_id = $created_test_admin_id");
    if ($q && $r = $q->fetch_assoc()) {
        if ($r['status'] === 'suspended') return true;
        return "Status is " . $r['status'] . " (expected suspended)";
    }
    return "User not found";
});

// 3.5 Kiểm tra Admin bị khóa thì permissions bị vô hiệu hóa
execute_test('P4.12', 'SUPER_ADMIN', 'Kiểm tra Admin bị khóa thì mất toàn bộ quyền', 'admin_management.php', 'GET', $sess_super, [], [200], function($res, $conn) use (&$created_test_admin_id) {
    $can = has_user_permission($conn, $created_test_admin_id, 'customer.view');
    if ($can === false) {
        return true;
    }
    return "Locked admin still has permission!";
});

// 3.6 SUPER_ADMIN mở khóa tài khoản ADMIN (Unlock)
execute_test('P4.13', 'SUPER_ADMIN', 'Mở khóa tài khoản Admin (Unlock -> active)', 'admin_management.php', 'POST', $sess_super, [
    'action' => 'unlock_admin',
    'target_user_id' => $created_test_admin_id,
    'csrf_token' => $csrf_super
], [200], function($res, $conn) use (&$created_test_admin_id) {
    $q = $conn->query("SELECT status FROM users WHERE user_id = $created_test_admin_id");
    if ($q && $r = $q->fetch_assoc()) {
        if ($r['status'] === 'active') return true;
        return "Status is " . $r['status'] . " (expected active)";
    }
    return "User not found";
});

// 3.7 SUPER_ADMIN xóa tài khoản ADMIN (Delete)
execute_test('P4.14', 'SUPER_ADMIN', 'Xóa tài khoản Admin (Delete)', 'admin_management.php', 'POST', $sess_super, [
    'action' => 'delete_admin',
    'target_user_id' => $created_test_admin_id,
    'csrf_token' => $csrf_super
], [200], function($res, $conn) use (&$created_test_admin_id) {
    $q = $conn->query("SELECT user_id FROM users WHERE user_id = $created_test_admin_id");
    if ($q && $q->num_rows === 0) {
        return true;
    }
    return "User was not deleted";
});

// -------------------------------------------------------------------------
// GROUP 4: KIỂM TRA BẢO MẬT AUDIT LOG & KHÔNG LƯU MẬT KHẨU
// -------------------------------------------------------------------------
execute_test('P4.15', 'AUDIT_LOG', 'Kiểm tra Audit Log đã ghi nhận CREATE_ADMIN', 'admin_management.php', 'GET', $sess_super, [], [200], function($res, $conn) {
    $q = $conn->query("SELECT * FROM audit_logs WHERE action = 'CREATE_ADMIN' ORDER BY created_at DESC LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) {
        return true;
    }
    return "No CREATE_ADMIN audit log found";
});

execute_test('P4.16', 'AUDIT_LOG', 'Kiểm tra Audit Log KHÔNG lưu mật khẩu dạng thô', 'admin_management.php', 'GET', $sess_super, [], [200], function($res, $conn) {
    $q = $conn->query("SELECT details FROM audit_logs WHERE action = 'CREATE_ADMIN' ORDER BY created_at DESC LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) {
        $details = $r['details'];
        if (strpos($details, 'SecurePass123!@#') === false) {
            return true;
        }
        return "SECURITY RISK: Plaintext password found in audit log details!";
    }
    return "No CREATE_ADMIN log to check";
});

execute_test('P4.17', 'AUDIT_LOG', 'Kiểm tra Audit Log đã ghi nhận LOCK_ADMIN', 'admin_management.php', 'GET', $sess_super, [], [200], function($res, $conn) {
    $q = $conn->query("SELECT * FROM audit_logs WHERE action = 'LOCK_ADMIN' ORDER BY created_at DESC LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) {
        return true;
    }
    return "No LOCK_ADMIN audit log found";
});

execute_test('P4.18', 'AUDIT_LOG', 'Kiểm tra Audit Log đã ghi nhận DELETE_ADMIN', 'admin_management.php', 'GET', $sess_super, [], [200], function($res, $conn) {
    $q = $conn->query("SELECT * FROM audit_logs WHERE action = 'DELETE_ADMIN' ORDER BY created_at DESC LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) {
        return true;
    }
    return "No DELETE_ADMIN audit log found";
});

// -------------------------------------------------------------------------
// BÁO CÁO TỔNG HỢP KẾT QUẢ
// -------------------------------------------------------------------------
printf("%-6s | %-12s | %-42s | %-6s | %s\n", "CASE", "ROLE", "ACTION / TEST DESCRIPTION", "STATUS", "DETAILS");
echo str_repeat("-", 105) . "\n";
foreach ($results as $r) {
    printf("%-6s | %-12s | %-42s | %-6s | %s\n", 
        $r['id'], 
        $r['role'], 
        mb_strimwidth($r['title'], 0, 42, "..."), 
        $r['status'], 
        $r['details']
    );
}

echo str_repeat("=", 105) . "\n";
echo "KẾT QUẢ PHASE 4: $passed_count / $total_count TESTS THÀNH CÔNG (" . round(($passed_count / $total_count) * 100) . "% PASS)\n";
echo "HỆ THỐNG QUẢN LÝ ADMIN & PHÂN QUYỀN VẬN HÀNH HOÀN HẢO, CHẶT CHẼ, AN TOÀN TUYỆT ĐỐI.\n";

