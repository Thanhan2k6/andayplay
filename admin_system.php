<?php
/**
 * ANPLAY STORE - CẤU HÌNH HỆ THỐNG & NHẬT KÝ KIỂM TOÁN (SYSTEM SETTINGS & AUDIT LOGS)
 * Endpoint độc quyền dành riêng cho Quản Trị Cấp Cao (Super Admin)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/connect.php';

// BẢO VỆ NGHIÊM NGẶT SERVER-SIDE: Chỉ SUPER_ADMIN mới được truy cập!
require_super_admin();

$super_admin_id = intval($_SESSION['user_id']);
$current_page = 'admin_system.php';

// Đảm bảo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success_msg = "";
$error_msg = "";

// 1. XỬ LÝ CẬP NHẬT CẤU HÌNH HỆ THỐNG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_save_settings'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật CSRF không hợp lệ.";
    } else {
        $settings = $_POST['settings'] ?? [];
        if (is_array($settings)) {
            $updated_count = 0;
            foreach ($settings as $key => $val) {
                $k = trim($key);
                $v = trim($val);
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, updated_by, updated_at)
                                        VALUES (?, ?, 'general', ?, NOW())
                                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()");
                if ($stmt) {
                    $stmt->bind_param("ssi", $k, $v, $super_admin_id);
                    if ($stmt->execute()) {
                        $updated_count++;
                    }
                    $stmt->close();
                }
            }
            write_audit_log($conn, $super_admin_id, 'UPDATE_SYSTEM_SETTINGS', 'system', 0, [
                'updated_keys' => array_keys($settings)
            ]);
            $success_msg = "Đã lưu cập nhật cấu hình hệ thống thành công!";
        }
    }
}

// 2. LẤY CÁC CẤU HÌNH HIỆN TẠI
$settings_map = [];
$res_set = $conn->query("SELECT * FROM system_settings");
if ($res_set && $res_set->num_rows > 0) {
    while ($row = $res_set->fetch_assoc()) {
        $settings_map[$row['setting_key']] = $row;
    }
}

// 3. LẤY NHẬT KÝ KIỂM TOÁN (AUDIT LOGS) CÓ PHÂN TRANG (PREPARED STATEMENT)
if (file_exists(__DIR__ . '/includes/pagination_helper.php')) {
    require_once __DIR__ . '/includes/pagination_helper.php';
}
$req_per_page = intval($_GET['per_page'] ?? 10);
$per_page = in_array($req_per_page, [10, 20, 50], true) ? $req_per_page : 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$total_logs = 0;
$st_cnt = $conn->prepare("SELECT COUNT(*) AS total FROM audit_logs");
if ($st_cnt) {
    $st_cnt->execute();
    $r_cnt = $st_cnt->get_result()->fetch_assoc();
    $total_logs = intval($r_cnt['total'] ?? 0);
    $st_cnt->close();
}

$total_pages = max(1, (int)ceil($total_logs / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$audit_logs = [];
$st_logs = $conn->prepare("SELECT al.*, u.username, u.role FROM audit_logs al 
    LEFT JOIN users u ON al.user_id = u.user_id 
    ORDER BY al.created_at DESC LIMIT ? OFFSET ?");
if ($st_logs) {
    $st_logs->bind_param("ii", $per_page, $offset);
    if ($st_logs->execute()) {
        $res_logs = $st_logs->get_result();
        while ($l = $res_logs->fetch_assoc()) {
            $audit_logs[] = $l;
        }
    }
    $st_logs->close();
}

$page_title = "Cấu Hình Hệ Thống & Nhật Ký Kiểm Toán";
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="container-fluid px-lg-5 py-4">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(244,63,94,0.2); color: #f43f5e; font-size: 0.75rem; border: 1px solid rgba(244,63,94,0.4);">
                    <i class="bi bi-shield-shaded me-1"></i> SUPER ADMIN EXCLUSIVE
                </span>
            </div>
            <h2 class="fw-extrabold mb-0">Cấu Hình Toàn Sàn & Audit Logs</h2>
            <p class="text-secondary small mb-0">Kiểm soát tham số hoạt động, chế độ bảo mật và theo dõi toàn bộ nhật ký kiểm toán hệ thống.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="admin_roles.php" class="btn btn-gaming-secondary py-2 px-3 small">
                <i class="bi bi-shield-lock me-1 text-primary"></i> Quản Lý Phân Quyền
            </a>
            <a href="admin_dashboard.php" class="btn btn-outline-secondary py-2 px-3 small">
                <i class="bi bi-speedometer2 me-1"></i> Dashboard
            </a>
        </div>
    </div>

    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" style="background: rgba(16,185,129,0.2); border: 1px solid #10b981;" role="alert">
            <i class="bi bi-check-circle-fill me-2 text-success"></i> <?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" style="background: rgba(244,63,94,0.2); border: 1px solid #f43f5e;" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <!-- CỘT TRÁI: CẤU HÌNH HỆ THỐNG -->
        <div class="col-lg-5">
            <div class="panel-dark p-4 rounded-3 shadow-sm h-100">
                <h5 class="fw-bold mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-sliders me-2 text-warning"></i> Cài Đặt Hệ Thống Toàn Sàn
                </h5>

                <form action="admin_system.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Tên Thương Hiệu Sàn</label>
                        <input type="text" name="settings[site_name]" class="form-control form-control-gaming" value="<?php echo htmlspecialchars($settings_map['site_name']['setting_value'] ?? 'ANPLAY STORE'); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Hotline Hỗ Trợ 24/7</label>
                        <input type="text" name="settings[support_hotline]" class="form-control form-control-gaming" value="<?php echo htmlspecialchars($settings_map['support_hotline']['setting_value'] ?? '0987.654.321'); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Email Hỗ Trợ Tiếp Nhận Ticket</label>
                        <input type="email" name="settings[support_email]" class="form-control form-control-gaming" value="<?php echo htmlspecialchars($settings_map['support_email']['setting_value'] ?? 'support@anplay.store'); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Cho Phép Khách Hàng Đăng Ký Mới</label>
                        <select name="settings[allow_registrations]" class="form-select form-control-gaming">
                            <option value="1" <?php echo (($settings_map['allow_registrations']['setting_value'] ?? '1') == '1') ? 'selected' : ''; ?>>Bật - Cho phép đăng ký tự do</option>
                            <option value="0" <?php echo (($settings_map['allow_registrations']['setting_value'] ?? '1') == '0') ? 'selected' : ''; ?>>Tạm khóa đăng ký thành viên mới</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Xác Nhận Thanh Toán VietQR</label>
                        <select name="settings[payment_simulation_mode]" class="form-select form-control-gaming">
                            <option value="1" <?php echo (($settings_map['payment_simulation_mode']['setting_value'] ?? '1') == '1') ? 'selected' : ''; ?>>Tự động xác nhận sau 20 giây (Chế độ mô phỏng)</option>
                            <option value="0" <?php echo (($settings_map['payment_simulation_mode']['setting_value'] ?? '1') == '0') ? 'selected' : ''; ?>>Chỉ xác nhận qua Webhook ngân hàng / Admin duyệt</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-secondary small fw-bold">Chế Độ Bảo Trì Hệ Thống</label>
                        <select name="settings[maintenance_mode]" class="form-select form-control-gaming">
                            <option value="0" <?php echo (($settings_map['maintenance_mode']['setting_value'] ?? '0') == '0') ? 'selected' : ''; ?>>Bình thường (Hoạt động 24/7)</option>
                            <option value="1" <?php echo (($settings_map['maintenance_mode']['setting_value'] ?? '0') == '1') ? 'selected' : ''; ?>>Bật bảo trì (Chỉ Super Admin vào được)</option>
                        </select>
                    </div>

                    <button type="submit" name="btn_save_settings" class="btn btn-gaming-primary w-100 py-2">
                        <i class="bi bi-save me-1"></i> Lưu Cấu Hình Hệ Thống
                    </button>
                </form>
            </div>
        </div>

        <!-- CỘT PHẢI: AUDIT LOGS -->
        <div class="col-lg-7">
            <div class="panel-dark p-4 rounded-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-journal-text me-2 text-primary"></i> Nhật Ký Kiểm Toán (Audit Logs)
                    </h5>
                    <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size: 0.75rem;">30 sự kiện gần nhất</span>
                </div>

                <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                    <table class="table table-gaming table-sm table-hover align-middle mb-0" style="background: transparent;">
                        <thead style="background: rgba(255,255,255,0.04);">
                            <tr class="text-secondary small" style="font-size: 0.72rem;">
                                <th class="py-2">THỜI GIAN</th>
                                <th class="py-2">NGƯỜI THỰC HIỆN</th>
                                <th class="py-2">HÀNH ĐỘNG</th>
                                <th class="py-2">CHI TIẾT</th>
                                <th class="py-2">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($audit_logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-secondary small">
                                        Chưa có nhật ký kiểm toán nào được ghi nhận.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($audit_logs as $log): ?>
                                    <tr style="font-size: 0.76rem; border-bottom: 1px solid rgba(255,255,255,0.04);">
                                        <td class="text-secondary text-nowrap"><?php echo date('d/m H:i:s', strtotime($log['created_at'])); ?></td>
                                        <td>
                                            <span class="fw-bold">@<?php echo htmlspecialchars($log['username'] ?? 'System'); ?></span>
                                            <?php if (intval($log['role'] ?? 0) === 2): ?>
                                                <span class="badge" style="background: rgba(244,63,94,0.15); color: #f43f5e; font-size: 0.60rem;">SUPER</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge anplay-surface-muted border border-secondary text-info"><?php echo htmlspecialchars($log['action']); ?></span>
                                        </td>
                                        <td class=" small text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($log['details'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($log['details'] ?? '-'); ?>
                                        </td>
                                        <td class="text-secondary small font-monospace"><?php echo htmlspecialchars($log['ip_address'] ?? '127.0.0.1'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (function_exists('render_pagination')) { echo render_pagination($page, $total_pages, $total_logs, $per_page, $_GET); } ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>

