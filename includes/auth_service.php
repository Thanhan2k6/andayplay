<?php
/**
 * ANPLAY STORE - HỆ THỐNG PHÂN QUYỀN TOÀN DIỆN (RBAC & ACCESS CONTROL)
 * Phân cấp 3 tầng quyền chuẩn hóa:
 * 1. ROLE_USER (0): Game thủ / Khách hàng
 * 2. ROLE_ADMIN (1): Quản trị viên cửa hàng & hỗ trợ
 * 3. ROLE_SUPER_ADMIN (2): Quản trị viên cấp cao nhất toàn sàn
 */

if (!defined('ROLE_USER')) {
    define('ROLE_USER', 0);
}
if (!defined('ROLE_ADMIN')) {
    define('ROLE_ADMIN', 1);
}
if (!defined('ROLE_SUPER_ADMIN')) {
    define('ROLE_SUPER_ADMIN', 2);
}

/**
 * Tự động tạo bảng audit_logs và system_settings nếu chưa có
 */
function ensure_auth_and_system_schema($conn) {
    static $checked = false;
    if ($checked || !$conn) return;
    $checked = true;

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS `audit_logs` (
          `log_id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `action` VARCHAR(100) NOT NULL,
          `target_type` VARCHAR(50) DEFAULT NULL,
          `target_id` INT DEFAULT NULL,
          `details` TEXT DEFAULT NULL,
          `ip_address` VARCHAR(45) DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_audit_user` (`user_id`),
          INDEX `idx_audit_action` (`action`),
          INDEX `idx_audit_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $conn->query("CREATE TABLE IF NOT EXISTS `system_settings` (
          `setting_key` VARCHAR(100) PRIMARY KEY,
          `setting_value` TEXT DEFAULT NULL,
          `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
          `description` VARCHAR(255) DEFAULT NULL,
          `updated_by` INT DEFAULT NULL,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // Bảng danh mục Quyền Hạn (Permissions)
        $conn->query("CREATE TABLE IF NOT EXISTS `permissions` (
          `permission_key` VARCHAR(50) PRIMARY KEY,
          `permission_name` VARCHAR(100) NOT NULL,
          `module` VARCHAR(50) NOT NULL,
          `description` VARCHAR(255) DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // Bảng gán quyền cho tài khoản (User Permissions)
        $conn->query("CREATE TABLE IF NOT EXISTS `user_permissions` (
          `user_id` INT NOT NULL,
          `permission_key` VARCHAR(50) NOT NULL,
          `granted_by` INT DEFAULT NULL,
          `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`user_id`, `permission_key`),
          INDEX `idx_perm_user` (`user_id`),
          INDEX `idx_perm_key` (`permission_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // Khởi tạo 16 quyền chuẩn nếu bảng permissions rỗng
        $chk_perm = $conn->query("SELECT COUNT(*) as c FROM permissions");
        if ($chk_perm && ($rp = $chk_perm->fetch_assoc()) && intval($rp['c']) === 0) {
            $conn->query("INSERT IGNORE INTO `permissions` (`permission_key`, `permission_name`, `module`, `description`) VALUES
                ('support.view', 'Xem danh sách yêu cầu hỗ trợ', 'support', 'Xem danh sách và chi tiết các yêu cầu hỗ trợ của khách hàng'),
                ('support.reply', 'Phản hồi yêu cầu hỗ trợ', 'support', 'Trả lời tin nhắn và cập nhật trạng thái xử lý ticket'),
                ('notification.view', 'Xem lịch sử thông báo', 'notification', 'Xem các thông báo đã gửi toàn sàn'),
                ('notification.send', 'Gửi thông báo hệ thống', 'notification', 'Phát tán thông báo tới toàn sàn hoặc cá nhân khách hàng'),
                ('customer.view', 'Xem danh sách khách hàng', 'customer', 'Xem danh sách thành viên và thông tin cá nhân'),
                ('customer.manage', 'Quản lý khách hàng', 'customer', 'Khóa, mở khóa và can thiệp tài khoản khách hàng'),
                ('game.view', 'Xem danh sách trò chơi', 'game', 'Xem kho game và chi tiết sản phẩm trong admin'),
                ('game.manage', 'Quản lý kho trò chơi', 'game', 'Thêm mới, chỉnh sửa thông tin và xóa game'),
                ('rental.view', 'Xem kho tài khoản game', 'rental', 'Xem danh sách tài khoản Steam/EA/Epic sẵn có'),
                ('rental.manage', 'Quản trị kho tài khoản', 'rental', 'Thêm mới tài khoản game, đổi mật khẩu và bảo trì'),
                ('order.view', 'Xem danh sách đơn thuê', 'order', 'Xem lịch sử các giao dịch thuê tài khoản game'),
                ('order.manage', 'Quản trị đơn thuê', 'order', 'Xác nhận gia hạn, hoàn tất hoặc thu hồi đơn thuê'),
                ('admin.manage', 'Quản trị ban quản trị', 'admin', 'Tạo tài khoản Admin, khóa, mở khóa và xóa Admin (Độc quyền Super Admin)'),
                ('role.manage', 'Quản lý phân quyền', 'role', 'Cấp quyền và điều chỉnh vai trò thành viên (Độc quyền Super Admin)'),
                ('audit.view', 'Xem nhật ký kiểm toán', 'audit', 'Xem lịch sử hành động bảo mật của hệ thống (Độc quyền Super Admin)'),
                ('system.manage', 'Cấu hình hệ thống toàn sàn', 'system', 'Điều chỉnh thông tin sàn và các cài đặt bảo mật (Độc quyền Super Admin)');");
        }
    } catch (Throwable $e) {}
}

if (isset($conn)) {
    ensure_auth_and_system_schema($conn);
}

/**
 * Lấy mã số quyền hiện tại từ Session (hoặc -1 nếu chưa đăng nhập)
 */
function get_current_role() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['role']) ? intval($_SESSION['role']) : -1;
}

/**
 * Kiểm tra xem người dùng đã đăng nhập chưa
 */
function is_logged_in() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0;
}

/**
 * Kiểm tra xem người dùng có phải là Khách Hàng (User) không
 */
function is_user() {
    return is_logged_in() && get_current_role() === ROLE_USER;
}

/**
 * Kiểm tra xem người dùng có quyền Quản Trị Viên (Admin hoặc Super Admin) không
 */
function is_admin() {
    return is_logged_in() && in_array(get_current_role(), [ROLE_ADMIN, ROLE_SUPER_ADMIN]);
}

/**
 * Kiểm tra xem người dùng có quyền Quản Trị Cấp Cao (Super Admin) không
 */
function is_super_admin() {
    return is_logged_in() && get_current_role() === ROLE_SUPER_ADMIN;
}

/**
 * Trả về thông tin hiển thị của Role
 */
function get_role_info($role) {
    $role = intval($role);
    switch ($role) {
        case ROLE_SUPER_ADMIN:
            return [
                'name' => 'SUPER_ADMIN',
                'label' => 'Quản Trị Cấp Cao (Super Admin)',
                'badge' => 'badge-danger',
                'color' => '#f43f5e',
                'bg' => 'rgba(244,63,94,0.2)'
            ];
        case ROLE_ADMIN:
            return [
                'name' => 'ADMIN',
                'label' => 'Quản Trị Viên (Admin)',
                'badge' => 'badge-primary',
                'color' => '#6366f1',
                'bg' => 'rgba(99,102,241,0.2)'
            ];
        case ROLE_USER:
        default:
            return [
                'name' => 'USER',
                'label' => 'Khách Hàng (Customer)',
                'badge' => 'badge-secondary',
                'color' => '#06b6d4',
                'bg' => 'rgba(6,182,212,0.15)'
            ];
    }
}

/**
 * Bắt buộc đăng nhập
 */
function require_login($redirect_to = 'login.php') {
    if (!is_logged_in()) {
        header("Location: " . $redirect_to);
        exit();
    }
}

/**
 * Render trang 403 Forbidden chuẩn UX gaming và thiết lập HTTP response code 403
 */
function render_403($message = "Bạn không có quyền truy cập vào khu vực này.") {
    http_response_code(403);
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    
    // Nếu là AJAX request
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => '403 Forbidden: ' . $message, 'code' => 403]);
        exit();
    }
    
    // Trang HTML 403 chuẩn
    echo "<!DOCTYPE html>
<html lang='vi'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>403 Forbidden — ANPLAY STORE</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css'>
    <link href='https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap' rel='stylesheet'>
    <style>
        body { background-color: #0b0d14; color: #f8fafc; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .error-card { background: #121622; border: 1px solid rgba(244,63,94,0.3); border-radius: 24px; padding: 48px; max-width: 520px; width: 100%; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.8); }
        .icon-box { width: 80px; height: 80px; border-radius: 50%; background: rgba(244,63,94,0.15); color: #f43f5e; display: inline-flex; align-items: center; justify-content: center; font-size: 36px; margin-bottom: 24px; border: 1px solid rgba(244,63,94,0.3); }
        .btn-gaming { background: #6366f1; color: #fff; border-radius: 12px; padding: 12px 28px; font-weight: 700; text-decoration: none; display: inline-block; transition: all 0.2s; border: none; }
        .btn-gaming:hover { background: #4f46e5; color: #fff; transform: translateY(-2px); }
    </style>
</head>
<body>
    <div class='error-card'>
        <div class='icon-box'><i class='bi bi-shield-x'></i></div>
        <h1 class='fw-bold mb-2' style='color: #f43f5e;'>403 FORBIDDEN</h1>
        <h5 class='fw-semibold text-white mb-3'>Truy Cập Bị Từ Chối</h5>
        <p class='text-secondary small mb-4'>" . htmlspecialchars($message) . "</p>
        <div class='d-flex gap-2 justify-content-center'>
            <a href='index.php' class='btn-gaming'><i class='bi bi-house-door me-2'></i>Về Trang Chủ</a>
        </div>
    </div>
</body>
</html>";
    exit();
}

/**
 * Bắt buộc quyền Quản Trị Viên (Admin hoặc Super Admin).
 * Nếu là USER cố truy cập -> trả về 403 Forbidden!
 */
function require_admin() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit();
    }
    if (!is_admin()) {
        render_403("Tài khoản của bạn không có quyền Quản trị viên (Admin). Yêu cầu truy cập bị từ chối.");
    }
}

/**
 * Bắt buộc quyền Quản Trị Cấp Cao (SUPER_ADMIN).
 * Nếu là ADMIN hoặc USER cố truy cập -> trả về 403 Forbidden!
 */
function require_super_admin() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit();
    }
    if (!is_super_admin()) {
        render_403("Chức năng này được bảo vệ nghiêm ngặt và chỉ dành riêng cho Quản Trị Cấp Cao (Super Admin).");
    }
}

/**
 * Ghi lại nhật ký kiểm toán hệ thống (Audit Log)
 * Tuyệt đối không lưu mật khẩu, token, API keys vào nhật ký.
 */
function write_audit_log($conn, $user_id, $action, $target_type = null, $target_id = null, $details = null) {
    if (!$conn) return false;
    $user_id = intval($user_id);
    $action = trim($action);
    $target_type = $target_type ? trim($target_type) : null;
    $target_id = ($target_id !== null && $target_id !== '') ? intval($target_id) : null;
    
    // Khử trùng an toàn: Không bao giờ lưu password/token/key/secret vào audit logs
    if (is_array($details)) {
        $sensitive_keys = ['password', 'pwd', 'pass', 'token', 'csrf_token', 'api_key', 'secret', 'auth_token'];
        array_walk_recursive($details, function(&$val, $key) use ($sensitive_keys) {
            if (in_array(strtolower($key), $sensitive_keys)) {
                $val = '[REDACTED]';
            }
        });
        $details = json_encode($details, JSON_UNESCAPED_UNICODE);
    } elseif ($details) {
        $details = trim($details);
    } else {
        $details = null;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    try {
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, target_type, target_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("ississ", $user_id, $action, $target_type, $target_id, $details, $ip);
            $res = $stmt->execute();
            $stmt->close();
            return $res;
        }
    } catch (Throwable $e) {}
    return false;
}

/**
 * Lấy tất cả quyền hạn có trong hệ thống kèm thông tin module
 */
function get_system_permissions($conn) {
    $perms = [];
    try {
        $res = $conn->query("SELECT * FROM permissions ORDER BY module ASC, permission_key ASC");
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $perms[] = $row;
            }
        }
    } catch (Throwable $e) {}
    return $perms;
}

/**
 * Lấy danh sách các quyền được cấp cho một người dùng cụ thể
 */
function get_user_permissions($conn, $user_id) {
    $user_id = intval($user_id);
    if ($user_id <= 0 || !$conn) return [];
    
    $role = -1;
    $status = 'active';
    try {
        $stmt = $conn->prepare("SELECT role, status FROM users WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $role = intval($row['role']);
                $status = $row['status'];
            }
            $stmt->close();
        }
    } catch (Throwable $e) {}

    // Banned hoặc suspended -> 0 quyền
    if (in_array($status, ['banned', 'suspended'])) {
        return [];
    }

    // SUPER_ADMIN có tất cả permissions
    if ($role === ROLE_SUPER_ADMIN) {
        $all = [];
        try {
            $q = $conn->query("SELECT permission_key FROM permissions");
            if ($q) {
                while ($r = $q->fetch_assoc()) {
                    $all[] = $r['permission_key'];
                }
            }
        } catch (Throwable $e) {}
        return $all;
    }

    // USER (0) không có quyền quản trị
    if ($role === ROLE_USER) {
        return [];
    }

    // ADMIN (1):
    $assigned = [];
    try {
        $stmt = $conn->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $assigned[] = $r['permission_key'];
            }
            $stmt->close();
        }
    } catch (Throwable $e) {}

    // Nếu chưa từng được cấu hình quyền cụ thể trong user_permissions, trả về quyền mặc định
    if (empty($assigned)) {
        return [
            'support.view', 'support.reply',
            'notification.view', 'notification.send',
            'customer.view', 'customer.manage',
            'game.view', 'game.manage',
            'rental.view', 'rental.manage',
            'order.view', 'order.manage'
        ];
    }

    return $assigned;
}

/**
 * Kiểm tra xem người dùng cụ thể có quyền $permission_key không
 */
function has_user_permission($conn, $user_id, $permission_key) {
    $user_id = intval($user_id);
    if ($user_id <= 0 || !$conn) return false;
    $perms = get_user_permissions($conn, $user_id);
    return in_array($permission_key, $perms);
}

/**
 * Bí danh (Alias) cho has_user_permission
 */
function check_user_permission($conn, $user_id, $permission_key) {
    return has_user_permission($conn, $user_id, $permission_key);
}

/**
 * Kiểm tra xem người dùng đang đăng nhập trong Session hiện tại có quyền $permission_key không
 */
function has_permission($permission_key) {
    global $conn;
    if (!is_logged_in() || !isset($conn)) return false;
    return has_user_permission($conn, intval($_SESSION['user_id']), $permission_key);
}

/**
 * Bắt buộc quyền thao tác cụ thể (Ví dụ: game.manage, support.reply, ...)
 * Nếu không có quyền -> Chặn 403 Forbidden!
 */
function require_permission($permission_key) {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit();
    }
    if (!has_permission($permission_key)) {
        render_403("Bạn không có quyền thực hiện thao tác này. (Yêu cầu quyền: {$permission_key})");
    }
}

/**
 * Cập nhật danh sách quyền hạn cho một Admin
 */
function set_user_permissions($conn, $user_id, array $permission_keys, $granted_by = null) {
    $user_id = intval($user_id);
    if ($user_id <= 0 || !$conn) return false;
    
    // Lấy role của user
    $q = $conn->query("SELECT role FROM users WHERE user_id = $user_id");
    if (!$q || $q->num_rows === 0) return false;
    $u = $q->fetch_assoc();
    $role = intval($u['role']);

    // Không gán permission thủ công cho Super Admin hoặc User thông thường
    if ($role === ROLE_SUPER_ADMIN || $role === ROLE_USER) {
        return false;
    }

    // Lọc danh sách permission hợp lệ
    $valid_perms = [];
    $q_all = $conn->query("SELECT permission_key FROM permissions");
    if ($q_all) {
        while ($r = $q_all->fetch_assoc()) {
            $valid_perms[] = $r['permission_key'];
        }
    }

    // Các quyền Super Admin độc quyền không được gán cho Admin thường
    $super_exclusive = ['admin.manage', 'role.manage', 'audit.view', 'system.manage'];

    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM user_permissions WHERE user_id = $user_id");
        $stmt = $conn->prepare("INSERT INTO user_permissions (user_id, permission_key, granted_by, granted_at) VALUES (?, ?, ?, NOW())");
        if ($stmt) {
            foreach ($permission_keys as $pk) {
                $pk = trim($pk);
                if (in_array($pk, $valid_perms) && !in_array($pk, $super_exclusive)) {
                    $stmt->bind_param("isi", $user_id, $pk, $granted_by);
                    $stmt->execute();
                }
            }
            $stmt->close();
        }
        $conn->commit();

        write_audit_log($conn, $granted_by ?? $user_id, 'UPDATE_ADMIN_PERMISSIONS', 'user', $user_id, [
            'updated_permissions' => $permission_keys
        ]);
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}

