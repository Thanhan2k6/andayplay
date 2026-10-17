<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/connect.php';

// BẢO VỆ NGHIÊM NGẶT SERVER-SIDE: Tối thiểu phải là ADMIN (role 1 hoặc 2) mới được truy cập
require_admin();

$current_user_id = intval($_SESSION['user_id']);
$actor_is_super = is_super_admin();

// 1. XỬ LÝ NÂNG CẤP / GIÁNG CHỨC / XÓA USER (SERVER-SIDE AUTHORIZATION)
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$target_id = intval($_GET['id'] ?? ($_POST['id'] ?? 0));

if (!empty($action) && $target_id > 0) {
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

// Nạp layout Header sau khi đã xác thực xong quyền hạn
require_once __DIR__ . '/includes/header.php';

// 2. TÌM KIẾM THÀNH VIÊN
$search_query = "";
if (isset($_GET['search_user']) && !empty($_GET['search_user'])) {
    $search = $conn->real_escape_string($_GET['search_user']);
    $search_query = " WHERE username LIKE '%$search%' OR email LIKE '%$search%' ";
}

// 3. LẤY DANH SÁCH USER
$sql_users = "SELECT * FROM users $search_query ORDER BY role DESC, created_at DESC";
$result_users = $conn->query($sql_users);
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
                                                        <a class="dropdown-item text-info small" href="admin_users.php?action=make_admin&id=<?php echo $u['user_id']; ?>" onclick="return confirm('Bạn có chắc muốn cấp quyền Quản trị viên cho người này?');">
                                                            <i class="bi bi-arrow-up-circle me-2"></i> Cấp quyền Admin
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item text-danger small" href="admin_users.php?action=make_super_admin&id=<?php echo $u['user_id']; ?>" onclick="return confirm('Bạn có chắc muốn cấp quyền Super Admin cho người này?');">
                                                            <i class="bi bi-shield-shaded me-2"></i> Cấp quyền Super Admin
                                                        </a>
                                                    </li>
                                                <?php elseif ($u['role'] == 1): ?>
                                                    <li>
                                                        <a class="dropdown-item text-danger small" href="admin_users.php?action=make_super_admin&id=<?php echo $u['user_id']; ?>" onclick="return confirm('Bạn có chắc muốn thăng cấp người này làm Super Admin?');">
                                                            <i class="bi bi-shield-shaded me-2"></i> Thăng lên Super Admin
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item text-warning small" href="admin_users.php?action=remove_admin&id=<?php echo $u['user_id']; ?>" onclick="return confirm('Bạn có chắc muốn gỡ quyền Admin của người này?');">
                                                            <i class="bi bi-arrow-down-circle me-2"></i> Gỡ quyền Admin
                                                        </a>
                                                    </li>
                                                <?php else: ?>
                                                    <li>
                                                        <a class="dropdown-item text-warning small" href="admin_users.php?action=make_admin&id=<?php echo $u['user_id']; ?>" onclick="return confirm('Bạn có chắc muốn hạ cấp người này về Admin thông thường?');">
                                                            <i class="bi bi-arrow-down-circle me-2"></i> Chuyển về Admin
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item text-warning small" href="admin_users.php?action=remove_admin&id=<?php echo $u['user_id']; ?>" onclick="return confirm('Bạn có chắc muốn gỡ toàn bộ quyền Quản trị của người này?');">
                                                            <i class="bi bi-arrow-down-circle me-2"></i> Gỡ quyền Quản trị
                                                        </a>
                                                    </li>
                                                <?php endif; ?>
                                                
                                                <li><hr class="dropdown-divider border-secondary border-opacity-25"></li>
                                                
                                                <li>
                                                    <a class="dropdown-item text-danger small" href="admin_users.php?action=delete&id=<?php echo $u['user_id']; ?>" onclick="return confirm('CẢNH BÁO: Hành động này sẽ xóa vĩnh viễn tài khoản. Tiếp tục?');">
                                                        <i class="bi bi-trash me-2"></i> Xóa tài khoản
                                                    </a>
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
</div>

<?php include 'includes/footer.php'; ?>