<?php
/**
 * ANPLAY STORE - QUẢN LÝ ADMIN & PHÂN QUYỀN HỆ THỐNG (PHASE 4: SUPER ADMIN EXCLUSIVE)
 * Trung tâm điều hành cấp cao: Quản lý Quản trị viên, Phân quyền chi tiết, Ma trận quyền, Audit Logs
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/connect.php';

// BẢO VỆ NGHIÊM NGẶT SERVER-SIDE: Chỉ SUPER_ADMIN mới được truy cập!
require_super_admin();

$super_admin_id = intval($_SESSION['user_id']);
$current_page = 'admin_management.php';

// Đảm bảo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success_msg = "";
$error_msg = "";

// -------------------------------------------------------------------------
// 1. XỬ LÝ CÁC THAO TÁC QUẢN TRỊ VIÊN (POST / GET VỚI CSRF)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $post_csrf = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($post_csrf)) {
        $error_msg = "Mã xác thực bảo mật CSRF không hợp lệ. Vui lòng thử lại!";
    } else {

        // A. TẠO TÀI KHOẢN ADMIN MỚI
        if ($action === 'create_admin') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $selected_perms = $_POST['permissions'] ?? [];

            if (empty($username) || empty($email) || empty($password)) {
                $error_msg = "Vui lòng điền đầy đủ Tên đăng nhập, Email và Mật khẩu!";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_msg = "Định dạng Email không hợp lệ!";
            } elseif (strlen($password) < 6) {
                $error_msg = "Mật khẩu tối thiểu phải từ 6 ký tự trở lên!";
            } else {
                // Kiểm tra trùng username hoặc email
                $stmt_chk = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
                $stmt_chk->bind_param("ss", $username, $email);
                $stmt_chk->execute();
                $res_chk = $stmt_chk->get_result();

                if ($res_chk && $res_chk->num_rows > 0) {
                    $error_msg = "Tên đăng nhập hoặc Email này đã tồn tại trên hệ thống!";
                } else {
                    $hashed_pwd = password_hash($password, PASSWORD_DEFAULT);
                    $stmt_ins = $conn->prepare("INSERT INTO users (username, email, password, role, status, created_at) VALUES (?, ?, ?, 1, 'active', NOW())");
                    $stmt_ins->bind_param("sss", $username, $email, $hashed_pwd);

                    if ($stmt_ins->execute()) {
                        $new_admin_id = $stmt_ins->insert_id;
                        $stmt_ins->close();

                        // Gán các permissions đã chọn
                        if (!empty($selected_perms) && is_array($selected_perms)) {
                            set_user_permissions($conn, $new_admin_id, $selected_perms, $super_admin_id);
                        }

                        // Ghi Audit Log (Tuyệt đối không ghi mật khẩu!)
                        write_audit_log($conn, $super_admin_id, 'CREATE_ADMIN', 'user', $new_admin_id, [
                            'username' => $username,
                            'email' => $email,
                            'initial_permissions' => $selected_perms
                        ]);

                        // Thông báo tài khoản mới và cảnh báo bảo mật cho Super Admin
                        create_notification($conn, $new_admin_id, 'account', "Chào mừng bạn gia nhập Ban Quản Trị!", "Tài khoản của bạn đã được Super Admin phân quyền Quản Trị Viên.", "admin_dashboard.php", "ADM-NEW-{$new_admin_id}", 0);
                        notify_super_admins($conn, 'security', "Khởi tạo Quản Trị Viên mới", "Super Admin @{$_SESSION['username']} đã khởi tạo tài khoản Quản Trị Viên @{$username}.", "admin_management.php", "SEC-CREATE-ADM-{$new_admin_id}", 0, $super_admin_id);

                        $success_msg = "Đã khởi tạo tài khoản Quản Trị Viên @{$username} thành công!";
                    } else {
                        $error_msg = "Lỗi hệ thống khi tạo tài khoản: " . $conn->error;
                    }
                }
                $stmt_chk->close();
            }
        }

        // B. CẬP NHẬT PERMISSIONS CHO ADMIN
        elseif ($action === 'update_permissions') {
            $target_id = intval($_POST['target_user_id'] ?? 0);
            $selected_perms = $_POST['permissions'] ?? [];

            if ($target_id <= 0) {
                $error_msg = "Tài khoản không hợp lệ!";
            } else {
                $q_u = $conn->query("SELECT user_id, username, role FROM users WHERE user_id = $target_id");
                if (!$q_u || $q_u->num_rows === 0) {
                    $error_msg = "Không tìm thấy tài khoản quản trị viên!";
                } else {
                    $u = $q_u->fetch_assoc();
                    if (intval($u['role']) === ROLE_SUPER_ADMIN) {
                        $error_msg = "Super Admin mặc định sở hữu toàn quyền hệ thống, không cần gán quyền lẻ!";
                    } else {
                        if (set_user_permissions($conn, $target_id, (array)$selected_perms, $super_admin_id)) {
                            create_notification($conn, $target_id, 'account', "Cập nhật quyền hạn quản trị", "Danh sách quyền hạn tài khoản quản trị của bạn vừa được cập nhật bởi Super Admin.", "admin_dashboard.php", "PERM-UPD-{$target_id}-" . time(), 0);
                            notify_super_admins($conn, 'security', "Cập nhật quyền Quản Trị Viên", "Super Admin @{$_SESSION['username']} đã cập nhật quyền hạn cho Quản Trị Viên @{$u['username']}.", "admin_management.php", "SEC-PERM-{$target_id}-" . time(), 0, $super_admin_id);
                            $success_msg = "Đã cập nhật danh sách quyền cho Quản Trị Viên @{$u['username']}!";
                        } else {
                            $error_msg = "Cập nhật quyền thất bại!";
                        }
                    }
                }
            }
        }

        // C. KHÓA ADMIN (LOCK / SUSPEND)
        elseif ($action === 'lock_admin') {
            $target_id = intval($_POST['target_user_id'] ?? 0);

            if ($target_id === $super_admin_id) {
                $error_msg = "Bạn không thể tự khóa tài khoản của chính mình!";
            } else {
                $q_u = $conn->query("SELECT user_id, username, role FROM users WHERE user_id = $target_id");
                if ($q_u && $u = $q_u->fetch_assoc()) {
                    if (intval($u['role']) === ROLE_SUPER_ADMIN) {
                        $error_msg = "Không thể khóa tài khoản Quản Trị Cấp Cao (Super Admin)!";
                    } else {
                        $conn->query("UPDATE users SET status = 'suspended' WHERE user_id = $target_id");
                        write_audit_log($conn, $super_admin_id, 'LOCK_ADMIN', 'user', $target_id, [
                            'username' => $u['username'],
                            'new_status' => 'suspended'
                        ]);
                        create_notification($conn, $target_id, 'account', "Tài khoản bị tạm khóa", "Tài khoản của bạn đã bị tạm khóa bởi Super Admin.", "support.php", "ACC-LOCK-{$target_id}-" . time(), 0);
                        notify_super_admins($conn, 'security', "Tạm khóa tài khoản Quản Trị Viên", "Super Admin @{$_SESSION['username']} đã tạm khóa tài khoản @{$u['username']}.", "admin_management.php", "SEC-LOCK-{$target_id}-" . time(), 0, $super_admin_id);
                        $success_msg = "Đã tạm khóa tài khoản Quản Trị Viên @{$u['username']}!";
                    }
                } else {
                    $error_msg = "Không tìm thấy người dùng!";
                }
            }
        }

        // D. MỞ KHÓA ADMIN (UNLOCK)
        elseif ($action === 'unlock_admin') {
            $target_id = intval($_POST['target_user_id'] ?? 0);

            $q_u = $conn->query("SELECT user_id, username, role FROM users WHERE user_id = $target_id");
            if ($q_u && $u = $q_u->fetch_assoc()) {
                $conn->query("UPDATE users SET status = 'active' WHERE user_id = $target_id");
                write_audit_log($conn, $super_admin_id, 'UNLOCK_ADMIN', 'user', $target_id, [
                    'username' => $u['username'],
                    'new_status' => 'active'
                ]);
                create_notification($conn, $target_id, 'account', "Tài khoản đã được mở khóa", "Tài khoản của bạn đã được khôi phục trạng thái hoạt động.", "admin_dashboard.php", "ACC-UNLOCK-{$target_id}-" . time(), 0);
                notify_super_admins($conn, 'security', "Mở khóa tài khoản Quản Trị Viên", "Super Admin @{$_SESSION['username']} đã mở khóa tài khoản @{$u['username']}.", "admin_management.php", "SEC-UNLOCK-{$target_id}-" . time(), 0, $super_admin_id);
                $success_msg = "Đã mở khóa hoạt động cho Quản Trị Viên @{$u['username']}!";
            } else {
                $error_msg = "Không tìm thấy người dùng!";
            }
        }

        // E. XÓA ADMIN (DELETE)
        elseif ($action === 'delete_admin') {
            $target_id = intval($_POST['target_user_id'] ?? 0);

            if ($target_id === $super_admin_id) {
                $error_msg = "Bạn không thể tự xóa tài khoản của chính mình!";
            } else {
                $q_u = $conn->query("SELECT user_id, username, role FROM users WHERE user_id = $target_id");
                if ($q_u && $u = $q_u->fetch_assoc()) {
                    if (intval($u['role']) === ROLE_SUPER_ADMIN) {
                        $error_msg = "Không thể xóa tài khoản Super Admin!";
                    } else {
                        $conn->query("DELETE FROM user_permissions WHERE user_id = $target_id");
                        $conn->query("DELETE FROM notifications WHERE user_id = $target_id");
                        $conn->query("DELETE FROM users WHERE user_id = $target_id");
                        write_audit_log($conn, $super_admin_id, 'DELETE_ADMIN', 'user', $target_id, [
                            'username' => $u['username'],
                            'role' => $u['role']
                        ]);
                        $success_msg = "Đã xóa vĩnh viễn tài khoản @{$u['username']} khỏi hệ thống!";
                    }
                } else {
                    $error_msg = "Không tìm thấy tài khoản để xóa!";
                }
            }
        }

        // F. ĐỔI VAI TRÒ (CHANGE ROLE: USER <-> ADMIN <-> SUPER_ADMIN)
        elseif ($action === 'change_role') {
            $target_id = intval($_POST['target_user_id'] ?? 0);
            $new_role = intval($_POST['new_role'] ?? 0);

            if ($target_id === $super_admin_id && $new_role !== ROLE_SUPER_ADMIN) {
                $error_msg = "Bạn không thể tự hạ quyền Super Admin của chính mình!";
            } else {
                $q_u = $conn->query("SELECT user_id, username, role FROM users WHERE user_id = $target_id");
                if ($q_u && $u = $q_u->fetch_assoc()) {
                    $old_role = intval($u['role']);

                    // Nếu hạ quyền Super Admin, đảm bảo còn ít nhất 1 Super Admin khác
                    if ($old_role === ROLE_SUPER_ADMIN && $new_role !== ROLE_SUPER_ADMIN) {
                        $q_cnt = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 2");
                        $cnt = ($q_cnt && $r = $q_cnt->fetch_assoc()) ? intval($r['c']) : 0;
                        if ($cnt <= 1) {
                            $error_msg = "Không thể hạ quyền Super Admin này vì hệ thống bắt buộc phải có ít nhất 1 Super Admin hoạt động!";
                        }
                    }

                    if (empty($error_msg)) {
                        $conn->query("UPDATE users SET role = $new_role WHERE user_id = $target_id");
                        
                        // Nếu chuyển về User thông thường, dọn sạch user_permissions
                        if ($new_role === ROLE_USER) {
                            $conn->query("DELETE FROM user_permissions WHERE user_id = $target_id");
                        }

                        write_audit_log($conn, $super_admin_id, 'CHANGE_ROLE', 'user', $target_id, [
                            'username' => $u['username'],
                            'old_role' => $old_role,
                            'new_role' => $new_role
                        ]);

                        $role_label = ($new_role === ROLE_SUPER_ADMIN ? 'Super Admin (Quản Trị Cấp Cao)' : ($new_role === ROLE_ADMIN ? 'Admin (Quản Trị Viên)' : 'User (Thành Viên)'));
                        create_notification($conn, $target_id, 'account', "Thay đổi vai trò tài khoản", "Vai trò tài khoản của bạn đã được chuyển thành: {$role_label}.", ($new_role > 0 ? "admin_dashboard.php" : "profile.php"), "ROLE-CHG-{$target_id}-" . time(), 0);
                        notify_super_admins($conn, 'security', "Cảnh báo thay đổi vai trò tài khoản", "Super Admin @{$_SESSION['username']} đã chuyển vai trò của @{$u['username']} sang {$role_label}.", "admin_management.php", "SEC-ROLE-{$target_id}-" . time(), 0, $super_admin_id);

                        $success_msg = "Đã thay đổi vai trò của @{$u['username']} thành công!";
                    }
                } else {
                    $error_msg = "Không tìm thấy người dùng!";
                }
            }
        }
    }
}

// -------------------------------------------------------------------------
// 2. LẤY DỮ LIỆU HIỂN THỊ
// -------------------------------------------------------------------------
$all_system_perms = get_system_permissions($conn);

// Gom nhóm permissions theo module
$grouped_perms = [];
foreach ($all_system_perms as $p) {
    $grouped_perms[$p['module']][] = $p;
}

// Lấy danh sách tất cả Admins (Role 1 và 2)
$admins = [];
$res_admins = $conn->query("SELECT user_id, username, email, role, status, created_at FROM users WHERE role IN (1, 2) ORDER BY role DESC, user_id ASC");
if ($res_admins) {
    while ($row = $res_admins->fetch_assoc()) {
        $row['permissions'] = get_user_permissions($conn, $row['user_id']);
        $admins[] = $row;
    }
}

// Thống kê nhanh
$total_admins = count($admins);
$count_super = 0;
$count_active = 0;
$count_locked = 0;
foreach ($admins as $a) {
    if (intval($a['role']) === ROLE_SUPER_ADMIN) $count_super++;
    if ($a['status'] === 'active') $count_active++;
    if (in_array($a['status'], ['banned', 'suspended'])) $count_locked++;
}

// Lấy 40 Audit Logs gần nhất
$filter_admin_id = isset($_GET['filter_admin']) ? intval($_GET['filter_admin']) : 0;
$sql_audit = "SELECT al.*, u.username, u.role FROM audit_logs al 
              LEFT JOIN users u ON al.user_id = u.user_id ";
if ($filter_admin_id > 0) {
    $sql_audit .= " WHERE al.user_id = $filter_admin_id ";
}
$sql_audit .= " ORDER BY al.created_at DESC LIMIT 50";
$recent_audits = [];
$res_audit = $conn->query($sql_audit);
if ($res_audit) {
    while ($ra = $res_audit->fetch_assoc()) {
        $recent_audits[] = $ra;
    }
}

$page_title = "Quản Trị Admin & Phân Quyền";
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="container-fluid py-4 px-lg-4">
    <!-- PAGE TITLE BAR -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(192, 132, 252, 0.2); color: #c084fc; border: 1px solid rgba(192, 132, 252, 0.4); font-size: 0.78rem;">
                    <i class="bi bi-shield-shaded me-1"></i> SUPER ADMIN ONLY
                </span>
                <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-25" style="font-size: 0.75rem;">
                    <i class="bi bi-lock-fill me-1"></i> Kiểm soát Phân Quyền & Bảo Mật
                </span>
            </div>
            <h2 class="fw-bold text-white mb-0" style="letter-spacing: -0.5px;">
                <i class="bi bi-person-gear me-2" style="color: #c084fc;"></i>Quản Trị Ban Quản Trị & Phân Quyền
            </h2>
            <p class="text-secondary small mb-0 mt-1">
                Khởi tạo quản trị viên, ủy quyền chi tiết 16 permissions, khóa/mở khóa tài khoản và theo dõi Audit Logs.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-primary fw-bold px-3 shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#createAdminModal" style="background: linear-gradient(135deg, #a855f7, #6366f1); border: none;">
                <i class="bi bi-person-plus-fill fs-5"></i>
                <span>Tạo Admin Mới</span>
            </button>
            <a href="admin_system.php" class="btn btn-outline-secondary px-3">
                <i class="bi bi-sliders me-1"></i> Cấu Hình Sàn
            </a>
        </div>
    </div>

    <!-- THÔNG BÁO FLASH MESSAGE -->
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert" style="background: rgba(16, 185, 129, 0.15); border-left: 4px solid #10b981 !important; color: #34d399;">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill fs-5"></i>
                <div><?php echo htmlspecialchars($success_msg); ?></div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert" style="background: rgba(239, 68, 68, 0.15); border-left: 4px solid #ef4444 !important; color: #f87171;">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div><?php echo htmlspecialchars($error_msg); ?></div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- STATS CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card h-100 p-3 rounded-4 border border-secondary border-opacity-10" style="background: #121622;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem;">Tổng Quản Trị</div>
                        <div class="fs-3 fw-bold text-white mt-1"><?php echo $total_admins; ?></div>
                    </div>
                    <div class="rounded-3 p-3" style="background: rgba(99, 102, 241, 0.15); color: #818cf8;">
                        <i class="bi bi-people-fill fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 p-3 rounded-4 border border-secondary border-opacity-10" style="background: #121622;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem;">Super Admin</div>
                        <div class="fs-3 fw-bold mt-1" style="color: #c084fc;"><?php echo $count_super; ?></div>
                    </div>
                    <div class="rounded-3 p-3" style="background: rgba(192, 132, 252, 0.15); color: #c084fc;">
                        <i class="bi bi-shield-shaded fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 p-3 rounded-4 border border-secondary border-opacity-10" style="background: #121622;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem;">Đang Hoạt Động</div>
                        <div class="fs-3 fw-bold text-success mt-1"><?php echo $count_active; ?></div>
                    </div>
                    <div class="rounded-3 p-3" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">
                        <i class="bi bi-check-circle-fill fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 p-3 rounded-4 border border-secondary border-opacity-10" style="background: #121622;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem;">Đang Bị Khóa</div>
                        <div class="fs-3 fw-bold text-danger mt-1"><?php echo $count_locked; ?></div>
                    </div>
                    <div class="rounded-3 p-3" style="background: rgba(239, 68, 68, 0.15); color: #f87171;">
                        <i class="bi bi-slash-circle-fill fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- NAVIGATION TABS -->
    <ul class="nav nav-pills mb-4 gap-2 border-bottom border-secondary border-opacity-25 pb-3" id="adminMgmtTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active rounded-pill px-4 fw-bold" id="tab-admins-btn" data-bs-toggle="pill" data-bs-target="#tab-admins" type="button" role="tab">
                <i class="bi bi-person-lines-fill me-2"></i> Danh Sách Quản Trị Viên (<?php echo $total_admins; ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-bold text-secondary" id="tab-matrix-btn" data-bs-toggle="pill" data-bs-target="#tab-matrix" type="button" role="tab">
                <i class="bi bi-grid-3x3-gap-fill me-2"></i> Ma Trận Phân Quyền (16 Quyền)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-bold text-secondary" id="tab-audit-btn" data-bs-toggle="pill" data-bs-target="#tab-audit" type="button" role="tab">
                <i class="bi bi-journal-text me-2"></i> Nhật Ký Kiểm Toán (Audit Logs)
            </button>
        </li>
    </ul>

    <!-- TAB CONTENTS -->
    <div class="tab-content" id="adminMgmtTabContent">

        <!-- =================================================================== -->
        <!-- TAB 1: DANH SÁCH VÀ THAO TÁC QUẢN TRỊ VIÊN -->
        <!-- =================================================================== -->
        <div class="tab-pane fade show active" id="tab-admins" role="tabpanel">
            <div class="card border border-secondary border-opacity-10 rounded-4 shadow-sm" style="background: #121622;">
                <div class="card-header bg-transparent border-bottom border-secondary border-opacity-10 p-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-white mb-0">Danh Sách Thành Viên Ban Quản Trị</h5>
                    <span class="text-secondary small">Cập nhật quyền hạn và trạng thái theo thời gian thực</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-secondary small text-uppercase" style="border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 0.74rem;">
                                <th class="ps-3 py-3">ID</th>
                                <th>Tài Khoản</th>
                                <th>Vai Trò (Role)</th>
                                <th>Trạng Thái</th>
                                <th>Quyền Hạn Đang Cấp</th>
                                <th>Ngày Tham Gia</th>
                                <th class="text-end pe-3">Thao Tác Quản Lý</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $adm): 
                                $is_target_super = (intval($adm['role']) === ROLE_SUPER_ADMIN);
                                $is_self = ($adm['user_id'] == $super_admin_id);
                                $status_badge = ($adm['status'] === 'active') ? '<span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25 px-2 py-1"><i class="bi bi-check-circle me-1"></i>Hoạt động</span>' : '<span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-25 px-2 py-1"><i class="bi bi-slash-circle me-1"></i>Bị khóa</span>';
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <td class="ps-3 text-secondary small fw-bold">#<?php echo $adm['user_id']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($adm['username']); ?>&background=<?php echo $is_target_super ? 'c084fc' : '6366f1'; ?>&color=fff&bold=true&rounded=true" width="38" height="38" class="rounded-circle shadow-sm" alt="Avatar">
                                        <div>
                                            <div class="fw-bold text-white">
                                                @<?php echo htmlspecialchars($adm['username']); ?>
                                                <?php if ($is_self): ?>
                                                    <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 ms-1" style="font-size: 0.65rem;">Chính bạn</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-secondary small" style="font-size: 0.78rem;"><?php echo htmlspecialchars($adm['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($is_target_super): ?>
                                        <span class="badge px-2 py-1" style="background: rgba(192, 132, 252, 0.2); color: #c084fc; border: 1px solid rgba(192, 132, 252, 0.4);">
                                            <i class="bi bi-shield-shaded me-1"></i> Super Admin
                                        </span>
                                    <?php else: ?>
                                        <span class="badge px-2 py-1" style="background: rgba(99, 102, 241, 0.2); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.4);">
                                            <i class="bi bi-shield-lock-fill me-1"></i> Admin Thường
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $status_badge; ?></td>
                                <td style="max-width: 280px;">
                                    <?php if ($is_target_super): ?>
                                        <span class="badge bg-purple bg-opacity-25 text-purple border border-purple border-opacity-25" style="color: #c084fc; font-size: 0.72rem;">
                                            <i class="bi bi-infinity me-1"></i> Toàn quyền (16/16)
                                        </span>
                                    <?php else: 
                                        $p_count = count($adm['permissions']);
                                    ?>
                                        <div class="d-flex align-items-center gap-1 flex-wrap">
                                            <span class="badge bg-info bg-opacity-15 text-info border border-info border-opacity-25" style="font-size: 0.72rem;">
                                                <i class="bi bi-key-fill me-1"></i> <?php echo $p_count; ?> quyền
                                            </span>
                                            <?php 
                                            $show_limit = 2;
                                            $shown = 0;
                                            foreach ($adm['permissions'] as $pk) {
                                                if ($shown >= $show_limit) break;
                                                echo '<span class="badge bg-secondary bg-opacity-25 text-light" style="font-size: 0.65rem;">' . htmlspecialchars($pk) . '</span>';
                                                $shown++;
                                            }
                                            if ($p_count > $show_limit) {
                                                echo '<span class="text-secondary small" style="font-size: 0.70rem;">+' . ($p_count - $show_limit) . ' quyền khác</span>';
                                            }
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-secondary small">
                                    <?php echo date('d/m/Y H:i', strtotime($adm['created_at'])); ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <!-- Xem lịch sử hoạt động -->
                                        <a href="admin_management.php?filter_admin=<?php echo $adm['user_id']; ?>#tab-audit" class="btn btn-sm btn-dark border border-secondary border-opacity-25 text-secondary hover-white" title="Xem lịch sử hoạt động" onclick="switchToAuditTab(<?php echo $adm['user_id']; ?>); return false;">
                                            <i class="bi bi-clock-history"></i>
                                        </a>

                                        <?php if (!$is_target_super): ?>
                                            <!-- Chỉnh sửa Permissions -->
                                            <button type="button" class="btn btn-sm btn-outline-info" title="Chỉnh sửa quyền hạn" onclick='openPermModal(<?php echo json_encode($adm); ?>)'>
                                                <i class="bi bi-sliders"></i>
                                            </button>

                                            <!-- Khóa / Mở khóa -->
                                            <?php if ($adm['status'] === 'active'): ?>
                                                <form method="POST" action="admin_management.php" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn KHÓA tài khoản Quản Trị Viên @<?php echo htmlspecialchars($adm['username']); ?>?');">
                                                    <input type="hidden" name="action" value="lock_admin">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $adm['user_id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Khóa tài khoản">
                                                        <i class="bi bi-lock-fill"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="admin_management.php" class="d-inline">
                                                    <input type="hidden" name="action" value="unlock_admin">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $adm['user_id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Mở khóa tài khoản">
                                                        <i class="bi bi-unlock-fill"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Thăng cấp / Hạ quyền -->
                                            <div class="dropdown d-inline">
                                                <button class="btn btn-sm btn-dark border border-secondary border-opacity-25 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="bi bi-arrow-down-up"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow">
                                                    <li>
                                                        <form method="POST" action="admin_management.php" onsubmit="return confirm('Bạn có chắc muốn thăng cấp @<?php echo htmlspecialchars($adm['username']); ?> thành SUPER ADMIN?');">
                                                            <input type="hidden" name="action" value="change_role">
                                                            <input type="hidden" name="target_user_id" value="<?php echo $adm['user_id']; ?>">
                                                            <input type="hidden" name="new_role" value="2">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                            <button type="submit" class="dropdown-item text-purple" style="color: #c084fc;">
                                                                <i class="bi bi-shield-shaded me-2"></i> Thăng Super Admin
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" action="admin_management.php" onsubmit="return confirm('Hạ quyền @<?php echo htmlspecialchars($adm['username']); ?> về User thông thường?');">
                                                            <input type="hidden" name="action" value="change_role">
                                                            <input type="hidden" name="target_user_id" value="<?php echo $adm['user_id']; ?>">
                                                            <input type="hidden" name="new_role" value="0">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                            <button type="submit" class="dropdown-item text-warning">
                                                                <i class="bi bi-person me-2"></i> Giáng về Khách Hàng (User)
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>

                                            <!-- Xóa Admin -->
                                            <form method="POST" action="admin_management.php" class="d-inline" onsubmit="return confirm('HÀNH ĐỘNG NGUY HIỂM: Bạn có chắc chắn muốn XÓA VĨNH VIỄN Admin @<?php echo htmlspecialchars($adm['username']); ?> khỏi hệ thống?');">
                                                <input type="hidden" name="action" value="delete_admin">
                                                <input type="hidden" name="target_user_id" value="<?php echo $adm['user_id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Xóa tài khoản">
                                                    <i class="bi bi-trash3-fill"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-25 text-secondary px-2 py-1" style="font-size: 0.70rem;">
                                                <i class="bi bi-shield-check me-1"></i> Bất khả xâm phạm
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- TAB 2: MA TRẬN PHÂN QUYỀN HỆ THỐNG (PERMISSION MATRIX) -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="tab-matrix" role="tabpanel">
            <div class="card border border-secondary border-opacity-10 rounded-4 shadow-sm" style="background: #121622;">
                <div class="card-header bg-transparent border-bottom border-secondary border-opacity-10 p-3">
                    <h5 class="fw-bold text-white mb-0">Ma Trận 16 Quyền Hạn Hệ Thống (Permission Matrix)</h5>
                    <p class="text-secondary small mb-0 mt-1">SUPER_ADMIN mặc định sở hữu toàn quyền. ADMIN được phân bổ linh hoạt. USER không có quyền quản trị.</p>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-secondary small text-uppercase" style="border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 0.74rem;">
                                <th class="ps-3 py-3">Mã Quyền (Key)</th>
                                <th>Tên Quyền & Mô Tả</th>
                                <th>Phân Nhóm Module</th>
                                <th class="text-center">USER (Khách)</th>
                                <th class="text-center">ADMIN (Quản trị viên)</th>
                                <th class="text-center pe-3">SUPER_ADMIN (Toàn quyền)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $exclusive_keys = ['admin.manage', 'role.manage', 'audit.view', 'system.manage'];
                            foreach ($all_system_perms as $perm): 
                                $is_exclusive = in_array($perm['permission_key'], $exclusive_keys);
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <td class="ps-3">
                                    <code class="text-info fw-bold" style="background: rgba(6,182,212,0.1); padding: 2px 6px; border-radius: 4px;">
                                        <?php echo htmlspecialchars($perm['permission_key']); ?>
                                    </code>
                                </td>
                                <td>
                                    <div class="fw-bold text-white"><?php echo htmlspecialchars($perm['permission_name']); ?></div>
                                    <div class="text-secondary small" style="font-size: 0.76rem;"><?php echo htmlspecialchars($perm['description']); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary bg-opacity-25 text-light text-uppercase" style="font-size: 0.68rem;">
                                        <?php echo htmlspecialchars($perm['module']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <i class="bi bi-x-circle text-danger fs-5" title="Bị chặn"></i>
                                </td>
                                <td class="text-center">
                                    <?php if ($is_exclusive): ?>
                                        <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-25" style="font-size: 0.70rem;">
                                            <i class="bi bi-slash-circle me-1"></i> Bị chặn (Độc quyền Super)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25" style="font-size: 0.70rem;">
                                            <i class="bi bi-check2-circle me-1"></i> Được ủy quyền
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center pe-3">
                                    <i class="bi bi-check-circle-fill text-success fs-5" title="Toàn quyền sở hữu"></i>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- TAB 3: NHẬT KÝ KIỂM TOÁN BAN QUẢN TRỊ (AUDIT LOGS) -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="tab-audit" role="tabpanel">
            <div class="card border border-secondary border-opacity-10 rounded-4 shadow-sm" style="background: #121622;">
                <div class="card-header bg-transparent border-bottom border-secondary border-opacity-10 p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold text-white mb-0">Lịch Sử Kiểm Toán An Ninh & Hoạt Động</h5>
                        <small class="text-secondary">Theo dõi minh bạch mọi thay đổi cấu hình, tạo mới, nâng cấp, khóa hoặc xóa tài khoản</small>
                    </div>
                    <!-- Bộ lọc theo Admin -->
                    <div class="d-flex align-items-center gap-2">
                        <label class="text-secondary small">Lọc theo Admin:</label>
                        <select class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" style="width: auto;" onchange="location.href='admin_management.php?filter_admin=' + this.value + '#tab-audit'">
                            <option value="0">-- Tất cả Quản Trị Viên --</option>
                            <?php foreach ($admins as $adm): ?>
                                <option value="<?php echo $adm['user_id']; ?>" <?php echo ($filter_admin_id === intval($adm['user_id'])) ? 'selected' : ''; ?>>
                                    @<?php echo htmlspecialchars($adm['username']); ?> (ID: <?php echo $adm['user_id']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($filter_admin_id > 0): ?>
                            <a href="admin_management.php#tab-audit" class="btn btn-sm btn-outline-secondary">Xóa lọc</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-secondary small text-uppercase" style="border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 0.74rem;">
                                <th class="ps-3 py-3">Log ID</th>
                                <th>Thời Gian</th>
                                <th>Người Thực Hiện (Actor)</th>
                                <th>Hành Động (Action)</th>
                                <th>Đối Tượng (Target)</th>
                                <th>Địa Chỉ IP</th>
                                <th class="pe-3">Chi Tiết Hành Động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_audits)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-secondary">Chưa có nhật ký kiểm toán nào được ghi nhận.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_audits as $log): 
                                    $action_badge = 'bg-secondary';
                                    if (strpos($log['action'], 'CREATE') !== false) $action_badge = 'bg-success';
                                    elseif (strpos($log['action'], 'DELETE') !== false || strpos($log['action'], 'LOCK') !== false) $action_badge = 'bg-danger';
                                    elseif (strpos($log['action'], 'UPDATE') !== false || strpos($log['action'], 'PROMOTE') !== false || strpos($log['action'], 'ROLE') !== false) $action_badge = 'bg-primary';
                                ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                    <td class="ps-3 text-secondary small fw-bold">#<?php echo $log['log_id']; ?></td>
                                    <td class="text-secondary small">
                                        <?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-white">@<?php echo htmlspecialchars($log['username'] ?? 'User #' . $log['user_id']); ?></span>
                                        <?php if (intval($log['role'] ?? 0) === ROLE_SUPER_ADMIN): ?>
                                            <span class="badge" style="background: rgba(192, 132, 252, 0.2); color: #c084fc; font-size: 0.65rem;">Super</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $action_badge; ?> bg-opacity-25 text-white border border-secondary border-opacity-25" style="font-size: 0.72rem;">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-light small">
                                            <?php echo htmlspecialchars($log['target_type'] ?? '-'); ?>
                                            <?php if ($log['target_id']): ?>
                                                <span class="text-secondary">#<?php echo $log['target_id']; ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="text-secondary small font-monospace">
                                        <?php echo htmlspecialchars($log['ip_address'] ?? '127.0.0.1'); ?>
                                    </td>
                                    <td class="pe-3" style="max-width: 350px;">
                                        <code class="small text-secondary" style="font-size: 0.75rem; word-break: break-all;">
                                            <?php echo htmlspecialchars($log['details'] ?? '-'); ?>
                                        </code>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL 1: TẠO QUẢN TRỊ VIÊN MỚI -->
<!-- ======================================================================= -->
<div class="modal fade" id="createAdminModal" tabindex="-1" aria-labelledby="createAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-secondary border-opacity-25 rounded-4" style="background: #121622; color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background: rgba(99,102,241,0.2); color: #818cf8;">
                        <i class="bi bi-person-plus-fill fs-5"></i>
                    </div>
                    <h5 class="modal-title fw-bold" id="createAdminModalLabel">Khởi Tạo Quản Trị Viên (Admin) Mới</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="admin_management.php">
                <input type="hidden" name="action" value="create_admin">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="modal-body p-4">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Tên Đăng Nhập (Username) *</label>
                            <input type="text" name="username" class="form-control bg-dark border-secondary border-opacity-25 text-white" placeholder="vd: admin_support_2" required autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Địa Chỉ Email *</label>
                            <input type="email" name="email" class="form-control bg-dark border-secondary border-opacity-25 text-white" placeholder="vd: support2@anplay.store" required autocomplete="off">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Mật Khẩu Khởi Tạo (Tối thiểu 6 ký tự) *</label>
                            <div class="input-group">
                                <input type="password" name="password" id="createPwdInput" class="form-control bg-dark border-secondary border-opacity-25 text-white" placeholder="Nhập mật khẩu an toàn..." required minlength="6">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('createPwdInput')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <small class="text-secondary" style="font-size: 0.75rem;">Mật khẩu sẽ được mã hóa an toàn bằng thuật toán Bcrypt trước khi lưu vào Database.</small>
                        </div>
                    </div>

                    <!-- PHÂN QUYỀN BAN ĐẦU -->
                    <div class="border-top border-secondary border-opacity-25 pt-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <label class="form-label text-secondary small fw-bold text-uppercase mb-0">
                                <i class="bi bi-key-fill text-warning me-1"></i> Ủy Quyền Ban Đầu (Permissions)
                            </label>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-link btn-sm text-decoration-none text-info p-0" onclick="toggleAllCreatePerms(true)">Chọn tất cả chuẩn</button>
                                <span class="text-secondary">|</span>
                                <button type="button" class="btn btn-link btn-sm text-decoration-none text-secondary p-0" onclick="toggleAllCreatePerms(false)">Bỏ chọn</button>
                            </div>
                        </div>

                        <div class="row g-3">
                            <?php 
                            $module_labels = [
                                'support' => 'Hỗ Trợ Khách Hàng',
                                'notification' => 'Thông Báo Toàn Sàn',
                                'customer' => 'Quản Lý Khách Hàng',
                                'game' => 'Quản Trị Kho Trò Chơi',
                                'rental' => 'Kho Tài Khoản Thuê',
                                'order' => 'Quản Lý Đơn Thuê & Doanh Thu'
                            ];
                            foreach ($grouped_perms as $mod => $p_list): 
                                if (in_array($mod, ['admin', 'role', 'audit', 'system'])) continue; // Độc quyền Super Admin
                            ?>
                                <div class="col-md-6">
                                    <div class="p-3 rounded-3 border border-secondary border-opacity-10" style="background: rgba(255,255,255,0.02);">
                                        <div class="fw-bold text-white small mb-2 text-uppercase" style="color: #818cf8 !important; font-size: 0.75rem;">
                                            <i class="bi bi-folder-fill me-1"></i> <?php echo $module_labels[$mod] ?? $mod; ?>
                                        </div>
                                        <?php foreach ($p_list as $p_item): ?>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input create-perm-checkbox" type="checkbox" name="permissions[]" value="<?php echo $p_item['permission_key']; ?>" id="create_perm_<?php echo str_replace('.', '_', $p_item['permission_key']); ?>" checked>
                                                <label class="form-check-label small text-light" for="create_perm_<?php echo str_replace('.', '_', $p_item['permission_key']); ?>">
                                                    <span class="fw-bold"><?php echo htmlspecialchars($p_item['permission_name']); ?></span>
                                                    <code class="d-block text-secondary" style="font-size: 0.70rem;"><?php echo htmlspecialchars($p_item['permission_key']); ?></code>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy bỏ</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4" style="background: linear-gradient(135deg, #a855f7, #6366f1); border: none;">
                        <i class="bi bi-check2-circle me-1"></i> Xác Nhận Tạo Admin
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL 2: CHỈNH SỬA PERMISSIONS CHO ADMIN -->
<!-- ======================================================================= -->
<div class="modal fade" id="editPermModal" tabindex="-1" aria-labelledby="editPermModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-secondary border-opacity-25 rounded-4" style="background: #121622; color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background: rgba(6,182,212,0.2); color: #06b6d4;">
                        <i class="bi bi-sliders fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold" id="editPermModalLabel">Phân Quyền Chi Tiết Cho Admin</h5>
                        <div class="text-secondary small" id="editPermModalSubtitle">Đang thiết lập...</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="admin_management.php">
                <input type="hidden" name="action" value="update_permissions">
                <input type="hidden" name="target_user_id" id="editPermTargetUserId" value="0">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="modal-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-secondary small fw-bold text-uppercase">Danh sách 12 quyền vận hành có thể ủy quyền</span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-link btn-sm text-decoration-none text-info p-0" onclick="toggleAllEditPerms(true)">Chọn tất cả</button>
                            <span class="text-secondary">|</span>
                            <button type="button" class="btn btn-link btn-sm text-decoration-none text-secondary p-0" onclick="toggleAllEditPerms(false)">Bỏ chọn tất cả</button>
                        </div>
                    </div>

                    <div class="row g-3">
                        <?php 
                        foreach ($grouped_perms as $mod => $p_list): 
                            if (in_array($mod, ['admin', 'role', 'audit', 'system'])) continue; // Độc quyền Super Admin
                        ?>
                            <div class="col-md-6">
                                <div class="p-3 rounded-3 border border-secondary border-opacity-10" style="background: rgba(255,255,255,0.02);">
                                    <div class="fw-bold text-white small mb-2 text-uppercase" style="color: #06b6d4 !important; font-size: 0.75rem;">
                                        <i class="bi bi-folder-fill me-1"></i> <?php echo $module_labels[$mod] ?? $mod; ?>
                                    </div>
                                    <?php foreach ($p_list as $p_item): ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input edit-perm-checkbox" type="checkbox" name="permissions[]" value="<?php echo $p_item['permission_key']; ?>" id="edit_perm_<?php echo str_replace('.', '_', $p_item['permission_key']); ?>">
                                            <label class="form-check-label small text-light" for="edit_perm_<?php echo str_replace('.', '_', $p_item['permission_key']); ?>">
                                                <span class="fw-bold"><?php echo htmlspecialchars($p_item['permission_name']); ?></span>
                                                <code class="d-block text-secondary" style="font-size: 0.70rem;"><?php echo htmlspecialchars($p_item['permission_key']); ?></code>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy bỏ</button>
                    <button type="submit" class="btn btn-info fw-bold px-4 text-white" style="background: linear-gradient(135deg, #06b6d4, #3b82f6); border: none;">
                        <i class="bi bi-check2-circle me-1"></i> Lưu Cập Nhật Quyền
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    if (input) {
        input.type = (input.type === 'password') ? 'text' : 'password';
    }
}

function toggleAllCreatePerms(checked) {
    document.querySelectorAll('.create-perm-checkbox').forEach(cb => cb.checked = checked);
}

function toggleAllEditPerms(checked) {
    document.querySelectorAll('.edit-perm-checkbox').forEach(cb => cb.checked = checked);
}

function openPermModal(adminData) {
    document.getElementById('editPermTargetUserId').value = adminData.user_id;
    document.getElementById('editPermModalSubtitle').innerText = 'Quản Trị Viên: @' + adminData.username + ' (ID: #' + adminData.user_id + ')';
    
    // Đánh dấu các quyền đang sở hữu
    const userPerms = adminData.permissions || [];
    document.querySelectorAll('.edit-perm-checkbox').forEach(cb => {
        cb.checked = userPerms.includes(cb.value);
    });

    const modal = new bootstrap.Modal(document.getElementById('editPermModal'));
    modal.show();
}

function switchToAuditTab(adminId) {
    location.href = 'admin_management.php?filter_admin=' + adminId + '#tab-audit';
}

document.addEventListener('DOMContentLoaded', function() {
    // Kích hoạt tab nếu URL có hash #tab-audit hoặc #tab-matrix
    if (window.location.hash === '#tab-audit') {
        const trigger = document.getElementById('tab-audit-btn');
        if (trigger) {
            bootstrap.Tab.getOrCreateInstance(trigger).show();
        }
    } else if (window.location.hash === '#tab-matrix') {
        const trigger = document.getElementById('tab-matrix-btn');
        if (trigger) {
            bootstrap.Tab.getOrCreateInstance(trigger).show();
        }
    }
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>

