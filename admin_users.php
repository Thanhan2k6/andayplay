<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Import file header (Đã bao gồm kết nối CSDL và Sidebar)
require_once __DIR__ . '/includes/header.php';

// Kiểm tra quyền Admin (Chỉ role = 1 mới được vào)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    echo "<script>
            alert('Cảnh báo: Bạn không có quyền truy cập khu vực này!');
            window.location='index.php';
          </script>";
    exit();
}

// 1. XỬ LÝ NÂNG CẤP / GIÁNG CHỨC ADMIN
if (isset($_GET['action']) && isset($_GET['id'])) {
    $target_id = intval($_GET['id']);
    
    // Không cho phép Admin tự đổi quyền hoặc xóa chính mình
    if ($target_id == $_SESSION['user_id']) {
        echo "<script>alert('Lỗi: Bạn không thể tự thay đổi quyền của chính mình!'); window.location='admin_users.php';</script>";
    } else {
        if ($_GET['action'] == 'make_admin') {
            $conn->query("UPDATE users SET role = 1 WHERE user_id = $target_id");
            echo "<script>alert('Đã cấp quyền Quản trị viên cho tài khoản này!'); window.location='admin_users.php';</script>";
        } elseif ($_GET['action'] == 'remove_admin') {
            $conn->query("UPDATE users SET role = 0 WHERE user_id = $target_id");
            echo "<script>alert('Đã gỡ quyền Quản trị viên của tài khoản này!'); window.location='admin_users.php';</script>";
        } elseif ($_GET['action'] == 'delete') {
            // Xóa user (Lưu ý: Thực tế nên dùng khóa ngoại Cascade hoặc ẩn user thay vì xóa hẳn)
            $conn->query("DELETE FROM users WHERE user_id = $target_id");
            echo "<script>alert('Đã xóa tài khoản thành công!'); window.location='admin_users.php';</script>";
        }
    }
}

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

<!-- NỘI DUNG TRANG QUẢN LÝ -->
<div class="container-fluid mt-4 mb-5 text-white">
    
    <!-- Tiêu đề và Thanh tìm kiếm -->
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary">
        <h2 class="fw-bold m-0" style="background: linear-gradient(90deg, #10b981, #34d399); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
            <i class="bi bi-people-fill text-success"></i> QUẢN LÝ THÀNH VIÊN
        </h2>
        
        <form action="" method="GET" class="d-flex gap-2">
            <input type="text" name="search_user" class="form-control bg-dark text-white border-secondary" placeholder="Tìm theo tên hoặc Email..." value="<?php echo isset($_GET['search_user']) ? htmlspecialchars($_GET['search_user']) : ''; ?>" style="width: 250px;">
            <button type="submit" class="btn btn-primary fw-bold px-3">Tìm Kiếm</button>
            <?php if(!empty($search_query)): ?>
                <a href="admin_users.php" class="btn btn-outline-secondary">Xóa lọc</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Bảng Danh Sách User (Style Kính Mờ) -->
    <div class="card bg-transparent border-0">
        <div class="card-body p-0">
            <div class="table-responsive rounded-3" style="border: 1px solid rgba(255, 255, 255, 0.1); background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(10px);">
                <table class="table table-dark table-hover mb-0 align-middle">
                    <thead style="background: rgba(15, 23, 42, 0.8);">
                        <tr>
                            <th class="py-3 px-4 text-center">ID</th>
                            <th class="py-3">Tài khoản</th>
                            <th class="py-3">Địa chỉ Email</th>
                            <th class="py-3 text-center">Vai trò</th>
                            <th class="py-3">Ngày tham gia</th>
                            <th class="py-3 text-center">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_users && $result_users->num_rows > 0): ?>
                            <?php while ($u = $result_users->fetch_assoc()): ?>
                                <tr>
                                    <td class="py-3 px-4 text-center text-secondary fw-bold">#<?php echo $u['user_id']; ?></td>
                                    
                                    <td class="py-3">
                                        <div class="d-flex align-items-center">
                                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($u['username']); ?>&background=random&color=fff&rounded=true" width="40" height="40" class="me-3 shadow-sm">
                                            <span class="fw-bold text-info">@<?php echo htmlspecialchars($u['username']); ?></span>
                                        </div>
                                    </td>
                                    
                                    <td class="py-3 text-light"><?php echo htmlspecialchars($u['email']); ?></td>
                                    
                                    <td class="py-3 text-center">
                                        <?php if ($u['role'] == 1): ?>
                                            <span class="badge bg-danger px-3 py-2 shadow-sm"><i class="bi bi-shield-lock-fill"></i> Admin</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary px-3 py-2"><i class="bi bi-person-fill"></i> Member</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="py-3 text-muted small">
                                        <?php echo date('d/m/Y H:i', strtotime($u['created_at'] ?? 'now')); ?>
                                    </td>
                                    
                                    <td class="py-3 text-center">
                                        <?php if ($u['user_id'] != $_SESSION['user_id']): // Ẩn nút sửa trên chính tài khoản đang login ?>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    Tùy chỉnh
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow">
                                                    <?php if ($u['role'] == 0): ?>
                                                        <li><a class="dropdown-item text-success fw-bold" href="admin_users.php?action=make_admin&id=<?php echo $u['user_id']; ?>" onclick="return confirm('Bạn có chắc muốn thăng cấp người này làm Admin?');"><i class="bi bi-arrow-up-circle-fill me-2"></i>Cấp quyền Admin</a></li>
                                                    <?php else: ?>
                                                        <li><a class="dropdown-item text-warning fw-bold" href="admin_users.php?action=remove_admin&id=<?php echo $u['user_id']; ?>" onclick="return confirm('Bạn có chắc muốn gỡ quyền Admin của người này?');"><i class="bi bi-arrow-down-circle-fill me-2"></i>Gỡ quyền Admin</a></li>
                                                    <?php endif; ?>
                                                    
                                                    <li><hr class="dropdown-divider border-secondary"></li>
                                                    
                                                    <li><a class="dropdown-item text-danger fw-bold" href="admin_users.php?action=delete&id=<?php echo $u['user_id']; ?>" onclick="return confirm('CẢNH BÁO: Hành động này sẽ xóa vĩnh viễn tài khoản. Tiếp tục?');"><i class="bi bi-trash-fill me-2"></i>Xóa tài khoản</a></li>
                                                </ul>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-success">Tài khoản của bạn</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-secondary">
                                    <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                    Không tìm thấy dữ liệu thành viên nào.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>