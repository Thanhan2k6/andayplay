<?php
/**
 * ANPLAY STORE - QUẢN LÝ PHÂN QUYỀN & QUẢN TRỊ VIÊN (SUPER ADMIN ROLE MANAGEMENT)
 * Endpoint độc quyền dành riêng cho Quản Trị Cấp Cao (Super Admin)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/connect.php';

// BẢO VỆ NGHIÊM NGẶT SERVER-SIDE: Chỉ SUPER_ADMIN mới được truy cập!
require_super_admin();

$super_admin_id = intval($_SESSION['user_id']);
$current_page = 'admin_roles.php';

// Đảm bảo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success_msg = "";
$error_msg = "";

// 1. XỬ LÝ THAY ĐỔI QUYỀN (POST METHOD VỚI CSRF BẢO MẬT)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật CSRF không hợp lệ.";
    } else {
        $target_id = intval($_POST['target_user_id'] ?? 0);
        $action = trim($_POST['action_type']);

        // Lấy thông tin user mục tiêu
        $q_target = $conn->query("SELECT user_id, username, email, role FROM users WHERE user_id = $target_id");
        if (!$q_target || $q_target->num_rows === 0) {
            $error_msg = "Không tìm thấy người dùng được chọn.";
        } else {
            $target_user = $q_target->fetch_assoc();
            $target_username = $target_user['username'];
            $old_role = intval($target_user['role']);

            if ($target_id === $super_admin_id && in_array($action, ['demote', 'delete', 'remove_super'])) {
                $error_msg = "Bạn không thể tự hạ quyền hoặc xóa chính tài khoản Super Admin của mình!";
            } else {
                if ($action === 'make_admin') {
                    // Thăng cấp thành Admin (role = 1)
                    $conn->query("UPDATE users SET role = 1 WHERE user_id = $target_id");
                    write_audit_log($conn, $super_admin_id, 'PROMOTE_TO_ADMIN', 'user', $target_id, [
                        'username' => $target_username,
                        'old_role' => $old_role,
                        'new_role' => 1
                    ]);
                    $success_msg = "Đã cấp quyền Quản Trị Viên (Admin) cho @{$target_username}!";
                } elseif ($action === 'make_super_admin') {
                    // Thăng cấp thành Super Admin (role = 2)
                    $conn->query("UPDATE users SET role = 2 WHERE user_id = $target_id");
                    write_audit_log($conn, $super_admin_id, 'PROMOTE_TO_SUPER_ADMIN', 'user', $target_id, [
                        'username' => $target_username,
                        'old_role' => $old_role,
                        'new_role' => 2
                    ]);
                    $success_msg = "Đã cấp quyền Quản Trị Cấp Cao (Super Admin) cho @{$target_username}!";
                } elseif ($action === 'demote_to_user') {
                    // Đếm số lượng Super Admin còn lại nếu đang hạ quyền Super Admin
                    if ($old_role === ROLE_SUPER_ADMIN) {
                        $q_count_super = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 2");
                        $count_super = ($q_count_super && $r = $q_count_super->fetch_assoc()) ? intval($r['c']) : 0;
                        if ($count_super <= 1) {
                            $error_msg = "Không thể hạ quyền Super Admin này vì hệ thống bắt buộc phải có ít nhất 1 Super Admin!";
                        }
                    }
                    if (empty($error_msg)) {
                        $conn->query("UPDATE users SET role = 0 WHERE user_id = $target_id");
                        write_audit_log($conn, $super_admin_id, 'DEMOTE_TO_USER', 'user', $target_id, [
                            'username' => $target_username,
                            'old_role' => $old_role,
                            'new_role' => 0
                        ]);
                        $success_msg = "Đã chuyển tài khoản @{$target_username} về Khách Hàng (User)!";
                    }
                } elseif ($action === 'delete_user') {
                    if ($old_role === ROLE_SUPER_ADMIN) {
                        $error_msg = "Không thể xóa tài khoản Super Admin trực tiếp!";
                    } else {
                        $conn->query("DELETE FROM user_permissions WHERE user_id = $target_id");
                        $conn->query("DELETE FROM notifications WHERE user_id = $target_id");
                        $conn->query("DELETE FROM users WHERE user_id = $target_id");
                        write_audit_log($conn, $super_admin_id, 'DELETE_USER', 'user', $target_id, [
                            'username' => $target_username,
                            'role' => $old_role
                        ]);
                        $success_msg = "Đã xóa tài khoản @{$target_username} khỏi hệ thống!";
                    }
                }
            }
        }
    }
}

// 2. THỐNG KÊ QUYỀN
$count_super = 0;
$count_admin = 0;
$count_user = 0;
$q_rstats = $conn->query("SELECT 
    SUM(CASE WHEN role = 2 THEN 1 ELSE 0 END) as super_c,
    SUM(CASE WHEN role = 1 THEN 1 ELSE 0 END) as adm_c,
    SUM(CASE WHEN role = 0 THEN 1 ELSE 0 END) as usr_c
    FROM users");
if ($q_rstats && $r_st = $q_rstats->fetch_assoc()) {
    $count_super = intval($r_st['super_c'] ?? 0);
    $count_admin = intval($r_st['adm_c'] ?? 0);
    $count_user = intval($r_st['usr_c'] ?? 0);
}

// 3. TÌM KIẾM VÀ LẤY DANH SÁCH USER
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_role = isset($_GET['role']) ? trim($_GET['role']) : 'all';

$where_clauses = ["1=1"];
if (!empty($search)) {
    $esc = $conn->real_escape_string($search);
    $where_clauses[] = "(username LIKE '%$esc%' OR email LIKE '%$esc%')";
}
if ($filter_role === 'super_admin') {
    $where_clauses[] = "role = 2";
} elseif ($filter_role === 'admin') {
    $where_clauses[] = "role = 1";
} elseif ($filter_role === 'user') {
    $where_clauses[] = "role = 0";
}
$where_sql = implode(" AND ", $where_clauses);

$users_list = [];
$res_users = $conn->query("SELECT * FROM users WHERE $where_sql ORDER BY role DESC, created_at DESC");
if ($res_users && $res_users->num_rows > 0) {
    while ($u = $res_users->fetch_assoc()) {
        $users_list[] = $u;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 py-4 text-white">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(244,63,94,0.2); color: #f43f5e; font-size: 0.75rem; border: 1px solid rgba(244,63,94,0.4);">
                    <i class="bi bi-shield-shaded me-1"></i> SUPER ADMIN EXCLUSIVE
                </span>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Quản Lý Phân Quyền & Ban Quản Trị</h2>
            <p class="text-secondary small mb-0">Trung tâm phân cấp quyền cao nhất toàn sàn: Phân quyền Super Admin, Admin và kiểm soát bảo mật hệ thống.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="admin_management.php" class="btn btn-primary py-2 px-3 small fw-bold" style="background: linear-gradient(135deg, #a855f7, #6366f1); border: none;">
                <i class="bi bi-person-gear me-1"></i> Quản Trị Admin & Permissions
            </a>
            <a href="admin_system.php" class="btn btn-gaming-secondary py-2 px-3 small">
                <i class="bi bi-sliders me-1 text-warning"></i> Cấu Hình Hệ Thống & Audit Log
            </a>
            <a href="admin_dashboard.php" class="btn btn-outline-secondary py-2 px-3 small">
                <i class="bi bi-speedometer2 me-1"></i> Dashboard
            </a>
        </div>
    </div>

    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4 text-white" style="background: rgba(16,185,129,0.2); border: 1px solid #10b981;" role="alert">
            <i class="bi bi-check-circle-fill me-2 text-success"></i> <?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4 text-white" style="background: rgba(244,63,94,0.2); border: 1px solid #f43f5e;" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- THỐNG KÊ NHANH CÁC TẦNG QUYỀN -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="panel-dark p-3 rounded-4 border border-danger border-opacity-25 d-flex align-items-center gap-3">
                <div class="p-3 rounded-3" style="background: rgba(244,63,94,0.15); color: #f43f5e; font-size: 1.8rem;">
                    <i class="bi bi-shield-shaded"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold text-uppercase">Super Admin (Tối cao)</div>
                    <div class="fs-3 fw-bold text-white"><?php echo $count_super; ?> <span class="fs-6 text-secondary fw-normal">tài khoản</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel-dark p-3 rounded-4 border border-primary border-opacity-25 d-flex align-items-center gap-3">
                <div class="p-3 rounded-3" style="background: rgba(99,102,241,0.15); color: #6366f1; font-size: 1.8rem;">
                    <i class="bi bi-shield-check"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold text-uppercase">Admin Quản Trị</div>
                    <div class="fs-3 fw-bold text-white"><?php echo $count_admin; ?> <span class="fs-6 text-secondary fw-normal">tài khoản</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel-dark p-3 rounded-4 border border-secondary border-opacity-25 d-flex align-items-center gap-3">
                <div class="p-3 rounded-3" style="background: rgba(6,182,212,0.15); color: #06b6d4; font-size: 1.8rem;">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold text-uppercase">Khách Hàng (User)</div>
                    <div class="fs-3 fw-bold text-white"><?php echo $count_user; ?> <span class="fs-6 text-secondary fw-normal">tài khoản</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- BỘ LỌC TÌM KIẾM -->
    <div class="panel-dark p-3 rounded-4 mb-4">
        <form action="admin_roles.php" method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control form-control-gaming" placeholder="Tìm theo username hoặc email..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="role" class="form-select form-control-gaming">
                    <option value="all" <?php echo ($filter_role === 'all') ? 'selected' : ''; ?>>-- Tất cả các tầng quyền --</option>
                    <option value="super_admin" <?php echo ($filter_role === 'super_admin') ? 'selected' : ''; ?>>Super Admin (Role 2)</option>
                    <option value="admin" <?php echo ($filter_role === 'admin') ? 'selected' : ''; ?>>Admin (Role 1)</option>
                    <option value="user" <?php echo ($filter_role === 'user') ? 'selected' : ''; ?>>Khách hàng (Role 0)</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-gaming-primary w-100 py-2">
                    <i class="bi bi-funnel me-1"></i> Lọc Danh Sách
                </button>
                <a href="admin_roles.php" class="btn btn-outline-secondary py-2 px-3" title="Đặt lại bộ lọc"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    </div>

    <!-- BẢNG DANH SÁCH & ĐIỀU HÀNH PHÂN QUYỀN -->
    <div class="panel-dark p-0 rounded-4 overflow-hidden shadow-sm">
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                <thead style="background: rgba(255,255,255,0.04); border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <tr class="text-secondary small">
                        <th class="ps-4 py-3">ID & THÀNH VIÊN</th>
                        <th class="py-3">EMAIL</th>
                        <th class="py-3 text-center">TẦNG QUYỀN HIỆN TẠI</th>
                        <th class="py-3 text-center">TRẠNG THÁI</th>
                        <th class="py-3">NGÀY TẠO</th>
                        <th class="pe-4 py-3 text-end">QUẢN LÝ PHÂN QUYỀN (SUPER ADMIN)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users_list)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-secondary">
                                <i class="bi bi-person-x fs-2 d-block mb-2 text-secondary opacity-50"></i>
                                Không tìm thấy tài khoản nào phù hợp với bộ lọc tìm kiếm.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users_list as $u): 
                            $r_info = get_role_info($u['role']);
                            $is_current_super = ($u['user_id'] == $super_admin_id);
                        ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <td class="ps-4 py-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($u['username']); ?>&background=6366f1&color=fff&bold=true&rounded=true" width="32" height="32" class="rounded-circle shadow-sm" alt="Avatar">
                                        <div>
                                            <div class="fw-bold text-white small">
                                                @<?php echo htmlspecialchars($u['username']); ?>
                                                <?php if ($is_current_super): ?>
                                                    <span class="badge ms-1" style="background: rgba(16,185,129,0.2); color: #10b981; font-size: 0.65rem;">BẠN</span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="text-secondary" style="font-size: 0.70rem;">User ID: #<?php echo $u['user_id']; ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-light small"><?php echo htmlspecialchars($u['email']); ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge" style="background: <?php echo $r_info['bg']; ?>; color: <?php echo $r_info['color']; ?>; border: 1px solid <?php echo $r_info['color']; ?>; padding: 6px 12px; font-size: 0.75rem;">
                                        <?php if ($u['role'] == 2): ?><i class="bi bi-shield-shaded me-1"></i><?php elseif ($u['role'] == 1): ?><i class="bi bi-shield-check me-1"></i><?php else: ?><i class="bi bi-person me-1"></i><?php endif; ?>
                                        <?php echo $r_info['name']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success bg-opacity-25 text-success" style="font-size: 0.72rem;">Active</span>
                                </td>
                                <td>
                                    <span class="text-secondary small"><?php echo date('d/m/Y H:i', strtotime($u['created_at'] ?? 'now')); ?></span>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if ($is_current_super): ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-secondary small py-2 px-3">Tài khoản hiện tại</span>
                                    <?php else: ?>
                                        <div class="d-inline-flex gap-1">
                                            <!-- FORM THĂNG / HẠ QUYỀN AN TOÀN BẰNG POST -->
                                            <?php if ($u['role'] == 0): ?>
                                                <form action="admin_roles.php" method="POST" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn thăng cấp người này làm Quản Trị Viên (Admin)?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                                                    <input type="hidden" name="action_type" value="make_admin">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary py-1 px-2" title="Cấp quyền Admin">
                                                        <i class="bi bi-shield-plus me-1"></i> Làm Admin
                                                    </button>
                                                </form>

                                                <form action="admin_roles.php" method="POST" class="d-inline" onsubmit="return confirm('CẢNH BÁO: Bạn có chắc muốn nâng tài khoản này thành SUPER ADMIN với toàn quyền hệ thống?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                                                    <input type="hidden" name="action_type" value="make_super_admin">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Cấp quyền Super Admin">
                                                        <i class="bi bi-shield-shaded me-1"></i> Super Admin
                                                    </button>
                                                </form>
                                            <?php elseif ($u['role'] == 1): ?>
                                                <form action="admin_roles.php" method="POST" class="d-inline" onsubmit="return confirm('CẢNH BÁO: Bạn có chắc muốn thăng cấp Admin này lên SUPER ADMIN?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                                                    <input type="hidden" name="action_type" value="make_super_admin">
                                                    <button type="submit" class="btn btn-sm btn-danger py-1 px-2" title="Thăng lên Super Admin">
                                                        <i class="bi bi-arrow-up-circle me-1"></i> Lên Super Admin
                                                    </button>
                                                </form>

                                                <form action="admin_roles.php" method="POST" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn hạ quyền người này về Khách Hàng thường?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                                                    <input type="hidden" name="action_type" value="demote_to_user">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning py-1 px-2" title="Gỡ quyền Admin">
                                                        <i class="bi bi-arrow-down-circle me-1"></i> Hạ về User
                                                    </button>
                                                </form>
                                            <?php elseif ($u['role'] == 2): ?>
                                                <form action="admin_roles.php" method="POST" class="d-inline" onsubmit="return confirm('CẢNH BÁO QUAN TRỌNG: Bạn có chắc muốn hạ quyền tài khoản SUPER ADMIN này?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                                                    <input type="hidden" name="action_type" value="demote_to_user">
                                                    <button type="submit" class="btn btn-sm btn-warning py-1 px-2" title="Hạ quyền Super Admin">
                                                        <i class="bi bi-shield-slash me-1"></i> Hạ quyền
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Nút xóa user -->
                                            <?php if ($u['role'] != 2): ?>
                                                <form action="admin_roles.php" method="POST" class="d-inline" onsubmit="return confirm('Bạn có chắc chắn muốn xóa vĩnh viễn tài khoản này?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                                                    <input type="hidden" name="action_type" value="delete_user">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary py-1 px-2 text-danger hover-white" title="Xóa tài khoản">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

