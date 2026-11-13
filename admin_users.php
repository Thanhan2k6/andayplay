<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/connect.php';

// BẢO VỆ NGHIÊM NGẶT SERVER-SIDE: Tối thiểu phải là ADMIN và có quyền customer.view
require_admin();
require_permission('customer.view');

$current_user_id = intval($_SESSION['user_id']);
$actor_is_super = is_super_admin();
$csrf_token = generate_csrf_token();

// 1. XỬ LÝ NÂNG CẤP / GIÁNG CHỨC / XÓA USER (BẮT BUỘC POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = trim($_POST['action'] ?? '');
    $target_id = intval($_POST['id'] ?? 0);

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        render_403("Mã xác thực bảo mật (CSRF) không hợp lệ.");
    }

    if ($target_id > 0) {
    // KIỂM SOÁT PHÂN QUYỀN CHẶT CHẼ:
    // ADMIN (role 1) TUYỆT ĐỐI KHÔNG ĐƯỢC:
    // - Quản lý, tạo, hạ quyền hoặc xóa SUPER_ADMIN (role 2)
    // - Quản lý, hạ quyền hoặc xóa ADMIN (role 1)
    // - Thay đổi role của bất kỳ ai (bao gồm tự phong Super Admin)
    if (!$actor_is_super) {
        if (in_array($action, ['make_super_admin', 'make_admin', 'remove_admin'])) {
            render_403("Tài khoản Quản Trị Viên (Admin) không có quyền thay đổi role hoặc tạo Admin/Super Admin.");
        }
    }

    // Không cho phép tự đổi quyền hoặc xóa chính mình
    if ($target_id === $current_user_id) {
        render_403("Bạn không thể tự thay đổi quyền hoặc xóa chính tài khoản của mình!");
    }

    $q_target = $conn->query("SELECT user_id, username, role FROM users WHERE user_id = $target_id");
    if (!$q_target || $q_target->num_rows === 0) {
        echo "<script>alert('Lỗi: Không tìm thấy người dùng!'); window.location='admin_users.php';</script>";
        exit();
    }
    $target_user = $q_target->fetch_assoc();
    $target_role = intval($target_user['role']);
    $target_name = $target_user['username'];

    if (!$actor_is_super) {
        if ($target_role === ROLE_SUPER_ADMIN) {
            render_403("Tài khoản Quản Trị Viên (Admin) không có quyền can thiệp vào tài khoản Super Admin.");
        }
        if ($target_role === ROLE_ADMIN) {
            render_403("Tài khoản Quản Trị Viên (Admin) không có quyền can thiệp vào tài khoản Admin khác.");
        }
    }

    // THỰC THI CHO SUPER ADMIN (hoặc Admin quản lý Customer hợp lệ)
    if ($action === 'make_admin') {
        if (!$actor_is_super) {
            render_403("Chỉ Super Admin mới có quyền cấp quyền Admin.");
        }
        $conn->query("UPDATE users SET role = 1 WHERE user_id = $target_id");
        write_audit_log($conn, $current_user_id, 'MAKE_ADMIN', 'user', $target_id, ['username' => $target_name]);
        echo "<script>alert('Đã cấp quyền Quản trị viên (Admin) cho @{$target_name}!'); window.location='admin_users.php';</script>";
        exit();
    } elseif ($action === 'make_super_admin') {
        if (!$actor_is_super) {
            render_403("Chỉ Super Admin mới có quyền nâng cấp Super Admin.");
        }
        $conn->query("UPDATE users SET role = 2 WHERE user_id = $target_id");
        write_audit_log($conn, $current_user_id, 'MAKE_SUPER_ADMIN', 'user', $target_id, ['username' => $target_name]);
        echo "<script>alert('Đã cấp quyền Super Admin cho @{$target_name}!'); window.location='admin_users.php';</script>";
        exit();
    } elseif ($action === 'remove_admin') {
        if (!$actor_is_super) {
            render_403("Chỉ Super Admin mới có quyền hạ quyền Quản trị viên.");
        }
        $conn->query("UPDATE users SET role = 0 WHERE user_id = $target_id");
        write_audit_log($conn, $current_user_id, 'REMOVE_ADMIN', 'user', $target_id, ['username' => $target_name]);
        echo "<script>alert('Đã chuyển @{$target_name} về Game Thủ thông thường!'); window.location='admin_users.php';</script>";
        exit();
    } elseif ($action === 'delete') {
        require_permission('customer.manage');
        if ($target_role === ROLE_SUPER_ADMIN) {
            render_403("Không thể xóa tài khoản Super Admin!");
        }
        if ($target_role === ROLE_ADMIN && !$actor_is_super) {
            render_403("Admin không có quyền xóa tài khoản Admin khác!");
        }
        $conn->query("DELETE FROM user_permissions WHERE user_id = $target_id");
        $conn->query("DELETE FROM notifications WHERE user_id = $target_id");
        $conn->query("DELETE FROM users WHERE user_id = $target_id");
        write_audit_log($conn, $current_user_id, 'DELETE_USER', 'user', $target_id, ['username' => $target_name, 'role' => $target_role]);
        echo "<script>alert('Đã xóa tài khoản thành công!'); window.location='admin_users.php';</script>";
        exit();
    }
    }
}

$page_title = "Quản Lý Danh Sách Khách Hàng";
require_once __DIR__ . '/includes/admin_header.php';

// 2. TÌM KIẾM THÀNH VIÊN & PHÂN TRANG (PREPARED STATEMENT)
if (file_exists(__DIR__ . '/includes/pagination_helper.php')) {
    require_once __DIR__ . '/includes/pagination_helper.php';
}
$req_per_page = intval($_GET['per_page'] ?? 10);
$per_page = in_array($req_per_page, [10, 20, 50], true) ? $req_per_page : 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$search_kw = trim($_GET['search_user'] ?? '');
$where_sql = '';
$bind_types = '';
$bind_params = [];
if ($search_kw !== '') {
    $where_sql = " WHERE username LIKE ? OR email LIKE ? ";
    $like_kw = '%' . $search_kw . '%';
    $bind_types = 'ss';
    $bind_params = [$like_kw, $like_kw];
}

// 3. ĐẾM TỔNG & LẤY DANH SÁCH USER THEO TRANG
$total_items = 0;
$st_cnt = $conn->prepare("SELECT COUNT(*) AS total FROM users $where_sql");
if ($st_cnt) {
    if ($bind_types !== '') {
        $st_cnt->bind_param($bind_types, ...$bind_params);
    }
    $st_cnt->execute();
    $row_cnt = $st_cnt->get_result()->fetch_assoc();
    $total_items = intval($row_cnt['total'] ?? 0);
    $st_cnt->close();
}

$total_pages = max(1, (int)ceil($total_items / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$result_users = false;
$st_users = $conn->prepare("SELECT * FROM users $where_sql ORDER BY role DESC, created_at DESC LIMIT ? OFFSET ?");
if ($st_users) {
    $data_types = $bind_types . 'ii';
    $data_params = array_merge($bind_params, [$per_page, $offset]);
    $st_users->bind_param($data_types, ...$data_params);
    $st_users->execute();
    $result_users = $st_users->get_result();
    $st_users->close();
}
?>

<div class="container-fluid px-lg-5 py-4 my-2 text-white">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); font-size: 0.75rem; border: 1px solid rgba(99,102,241,0.4);">
                    <i class="bi bi-people-fill me-1"></i> MEMBERSHIP
                </span>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Quản Lý Danh Sách Thành Viên</h2>
            <p class="text-secondary small mb-0">Quản lý tài khoản game thủ, phân quyền quản trị viên và lịch sử tham gia</p>
        </div>
        
        <form action="" method="GET" class="d-flex gap-2">
            <input type="text" name="search_user" class="form-control form-control-gaming" placeholder="Tìm theo tên hoặc email..." value="<?php echo isset($_GET['search_user']) ? htmlspecialchars($_GET['search_user']) : ''; ?>" style="width: 250px;">
            <button type="submit" class="btn btn-gaming-primary px-3">
                <i class="bi bi-search"></i>
            </button>
            <?php if(!empty($search_query)): ?>
                <a href="admin_users.php" class="btn btn-gaming-secondary" title="Xóa lọc">
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table Container -->
    <div class="table-gaming-container">
        <div class="table-responsive">
            <table class="table table-gaming align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 8%;">ID</th>
                        <th style="width: 28%;">Tài Khoản & Tên Game Thủ</th>
                        <th style="width: 26%;">Địa Chỉ Email</th>
                        <th style="width: 14%;" class="text-center">Vai Trò</th>
                        <th style="width: 14%;">Ngày Đăng Ký</th>
                        <th style="width: 10%;" class="text-end pe-3">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result_users && $result_users->num_rows > 0): ?>
                        <?php while ($u = $result_users->fetch_assoc()): ?>
                            <tr>
                                <td class="text-secondary fw-bold">#<?php echo $u['user_id']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($u['username']); ?>&background=random&color=fff&rounded=true" width="36" height="36" class="rounded-circle shadow-sm">
                                        <div>
                                            <strong class="text-white">@<?php echo htmlspecialchars($u['username']); ?></strong>
                                            <?php if ($u['user_id'] == $_SESSION['user_id']): ?>
                                                <span class="badge ms-1" style="background: rgba(16,185,129,0.2); color: #10b981; font-size: 0.65rem;">BẠN</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-light small"><?php echo htmlspecialchars($u['email']); ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($u['role'] == 2): ?>
                                        <span class="badge" style="background: rgba(244,63,94,0.2); color: #f43f5e; border: 1px solid rgba(244,63,94,0.4); padding: 6px 12px; font-size: 0.75rem;">
                                            <i class="bi bi-shield-shaded me-1"></i> Super Admin
                                        </span>
                                    <?php elseif ($u['role'] == 1): ?>
                                        <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); border: 1px solid rgba(99,102,241,0.4); padding: 6px 12px; font-size: 0.75rem;">
                                            <i class="bi bi-shield-check me-1"></i> Quản Trị Viên
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background: rgba(255,255,255,0.06); color: #94a3b8; border: 1px solid var(--border-color); padding: 6px 12px; font-size: 0.75rem;">
                                            <i class="bi bi-person me-1"></i> Game Thủ
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-secondary small"><?php echo date('d/m/Y H:i', strtotime($u['created_at'] ?? 'now')); ?></span>
                                </td>
                                <td class="text-end pe-3">
                                    <?php if ($u['user_id'] != $_SESSION['user_id']): ?>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-gaming-secondary dropdown-toggle py-1 px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                Tùy chỉnh
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                                                <?php if ($u['role'] == 0): ?>
                                                    <li>
                                                        <form method="POST" onsubmit="return confirm('Bạn có chắc muốn cấp quyền Quản trị viên cho người này?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="action" value="make_admin">
                                                            <input type="hidden" name="id" value="<?php echo intval($u['user_id']); ?>">
                                                            <button type="submit" class="dropdown-item text-info small"><i class="bi bi-arrow-up-circle me-2"></i> Cấp quyền Admin</button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" onsubmit="return confirm('Bạn có chắc muốn cấp quyền Super Admin cho người này?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="action" value="make_super_admin">
                                                            <input type="hidden" name="id" value="<?php echo intval($u['user_id']); ?>">
                                                            <button type="submit" class="dropdown-item text-danger small"><i class="bi bi-shield-shaded me-2"></i> Cấp quyền Super Admin</button>
                                                        </form>
                                                    </li>
                                                <?php elseif ($u['role'] == 1): ?>
                                                    <li>
                                                        <form method="POST" onsubmit="return confirm('Bạn có chắc muốn thăng cấp người này làm Super Admin?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="action" value="make_super_admin">
                                                            <input type="hidden" name="id" value="<?php echo intval($u['user_id']); ?>">
                                                            <button type="submit" class="dropdown-item text-danger small"><i class="bi bi-shield-shaded me-2"></i> Thăng lên Super Admin</button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" onsubmit="return confirm('Bạn có chắc muốn gỡ quyền Admin của người này?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="action" value="remove_admin">
                                                            <input type="hidden" name="id" value="<?php echo intval($u['user_id']); ?>">
                                                            <button type="submit" class="dropdown-item text-warning small"><i class="bi bi-arrow-down-circle me-2"></i> Gỡ quyền Admin</button>
                                                        </form>
                                                    </li>
                                                <?php else: ?>
                                                    <li>
                                                        <form method="POST" onsubmit="return confirm('Bạn có chắc muốn hạ cấp người này về Admin thông thường?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="action" value="make_admin">
                                                            <input type="hidden" name="id" value="<?php echo intval($u['user_id']); ?>">
                                                            <button type="submit" class="dropdown-item text-warning small"><i class="bi bi-arrow-down-circle me-2"></i> Chuyển về Admin</button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" onsubmit="return confirm('Bạn có chắc muốn gỡ toàn bộ quyền Quản trị của người này?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="action" value="remove_admin">
                                                            <input type="hidden" name="id" value="<?php echo intval($u['user_id']); ?>">
                                                            <button type="submit" class="dropdown-item text-warning small"><i class="bi bi-arrow-down-circle me-2"></i> Gỡ quyền Quản trị</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                                
                                                <li><hr class="dropdown-divider border-secondary border-opacity-25"></li>
                                                
                                                <li>
                                                    <form method="POST" onsubmit="return confirm('CẢNH BÁO: Hành động này sẽ xóa vĩnh viễn tài khoản. Tiếp tục?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo intval($u['user_id']); ?>">
                                                        <button type="submit" class="dropdown-item text-danger small"><i class="bi bi-trash me-2"></i> Xóa tài khoản</button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-secondary small fst-italic">Tài khoản này</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-secondary">
                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                Không tìm thấy thành viên nào phù hợp với từ khóa tìm kiếm.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (function_exists('render_pagination')) { echo render_pagination($page, $total_pages, $total_items, $per_page, $_GET); } ?>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>