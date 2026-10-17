<?php
/**
 * ANPLAY STORE - DEDICATED ADMIN SIDEBAR
 * Menu điều hướng chuyên biệt 100% dành cho Quản Trị Viên (Admin & Super Admin)
 * Tách biệt hoàn toàn khỏi giao diện người dùng (User Storefront)
 */

if (!defined('ROLE_ADMIN')) {
    define('ROLE_ADMIN', 1);
}
if (!defined('ROLE_SUPER_ADMIN')) {
    define('ROLE_SUPER_ADMIN', 2);
}

$sidebar_current = basename($_SERVER['PHP_SELF']);
$sidebar_user_role = isset($_SESSION['role']) ? intval($_SESSION['role']) : 0;
$sidebar_is_super = ($sidebar_user_role === ROLE_SUPER_ADMIN);

// Thống kê nhanh hiển thị trên badge menu
$sb_new_tickets = 0;
$sb_maint_accounts = 0;
try {
    if (isset($conn) && $conn) {
        $q_st = $conn->query("SELECT COUNT(*) as c FROM support_tickets WHERE status = 'new'");
        if ($q_st && $r_st = $q_st->fetch_assoc()) {
            $sb_new_tickets = intval($r_st['c'] ?? 0);
        }
        $q_ma = $conn->query("SELECT COUNT(*) as c FROM game_accounts WHERE status = 'MAINTENANCE'");
        if ($q_ma && $r_ma = $q_ma->fetch_assoc()) {
            $sb_maint_accounts = intval($r_ma['c'] ?? 0);
        }
    }
} catch (Throwable $e) {}
?>
<aside class="admin-sidebar" id="adminSidebar">
    <!-- Brand / Header Sidebar -->
    <div class="admin-sidebar-header d-flex justify-content-between align-items-center">
        <a href="admin_dashboard.php" class="admin-brand text-decoration-none d-flex align-items-center gap-2">
            <div class="admin-brand-icon">
                <i class="bi bi-shield-shaded"></i>
            </div>
            <div>
                <div class="admin-brand-title">ANPLAY</div>
                <div class="admin-brand-subtitle">ADMIN CONSOLE</div>
            </div>
        </a>
        <button type="button" class="btn btn-sm btn-outline-secondary d-lg-none" onclick="toggleAdminSidebar(false)" aria-label="Đóng menu">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <!-- Navigation Menu -->
    <div class="admin-sidebar-nav custom-scrollbar">
        <!-- Section: TỔNG QUAN -->
        <div class="admin-nav-group-title">TỔNG QUAN HỆ THỐNG</div>
        <a href="admin_dashboard.php" class="admin-nav-item <?php echo ($sidebar_current === 'admin_dashboard.php') ? 'active' : ''; ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Bảng Điều Khiển</span>
        </a>

        <!-- Section: QUẢN LÝ CỬA HÀNG -->
        <div class="admin-nav-group-title">QUẢN LÝ CỬA HÀNG</div>
        <a href="admin_games.php" class="admin-nav-item <?php echo in_array($sidebar_current, ['admin_games.php', 'admin_edit_game.php']) ? 'active' : ''; ?>">
            <i class="bi bi-controller"></i>
            <span>Kho Game & Giá Thuê</span>
        </a>
        <a href="admin_add_game.php" class="admin-nav-item <?php echo ($sidebar_current === 'admin_add_game.php') ? 'active' : ''; ?>">
            <i class="bi bi-plus-circle"></i>
            <span>Thêm Game Mới</span>
        </a>
        <a href="admin_accounts.php" class="admin-nav-item <?php echo ($sidebar_current === 'admin_accounts.php') ? 'active' : ''; ?>">
            <i class="bi bi-key-fill"></i>
            <span>Kho Tài Khoản Game</span>
            <?php if ($sb_maint_accounts > 0): ?>
                <span class="badge bg-warning text-dark ms-auto" title="Tài khoản cần bảo trì"><?php echo $sb_maint_accounts; ?></span>
            <?php endif; ?>
        </a>

        <!-- Section: VẬN HÀNH & GIAO DỊCH -->
        <div class="admin-nav-group-title">VẬN HÀNH & KHÁCH HÀNG</div>
        <a href="admin_borrow.php" class="admin-nav-item <?php echo ($sidebar_current === 'admin_borrow.php') ? 'active' : ''; ?>">
            <i class="bi bi-receipt-cutoff"></i>
            <span>Quản Lý Đơn Thuê</span>
        </a>
        <a href="admin_users.php" class="admin-nav-item <?php echo ($sidebar_current === 'admin_users.php') ? 'active' : ''; ?>">
            <i class="bi bi-people-fill"></i>
            <span>Quản Lý Khách Hàng</span>
        </a>

        <!-- Section: HỖ TRỢ & TRUYỀN THÔNG -->
        <div class="admin-nav-group-title">CHĂM SÓC & TRUYỀN THÔNG</div>
        <a href="admin_support.php" class="admin-nav-item <?php echo in_array($sidebar_current, ['admin_support.php', 'admin_support_detail.php']) ? 'active' : ''; ?>">
            <i class="bi bi-headset"></i>
            <span>Trung Tâm Hỗ Trợ</span>
            <?php if ($sb_new_tickets > 0): ?>
                <span class="badge bg-danger rounded-pill ms-auto" title="Yêu cầu mới"><?php echo $sb_new_tickets; ?></span>
            <?php endif; ?>
        </a>
        <a href="admin_notifications.php" class="admin-nav-item <?php echo ($sidebar_current === 'admin_notifications.php') ? 'active' : ''; ?>">
            <i class="bi bi-bell-fill"></i>
            <span>Trung Tâm Thông Báo</span>
        </a>

        <!-- Section: QUẢN TRỊ HỆ THỐNG (SUPER ADMIN ONLY) -->
        <?php if ($sidebar_is_super): ?>
            <div class="admin-nav-group-title text-danger d-flex align-items-center gap-1">
                <i class="bi bi-shield-lock-fill"></i> QUẢN TRỊ HỆ THỐNG
            </div>
            <a href="admin_management.php" class="admin-nav-item <?php echo ($sidebar_current === 'admin_management.php') ? 'active' : ''; ?>">
                <i class="bi bi-person-gear text-danger"></i>
                <span>Quản Lý Admin</span>
            </a>
            <a href="admin_roles.php" class="admin-nav-item <?php echo ($sidebar_current === 'admin_roles.php') ? 'active' : ''; ?>">
                <i class="bi bi-diagram-3-fill text-danger"></i>
                <span>Phân Quyền & Role</span>
            </a>
            <a href="admin_system.php" class="admin-nav-item <?php echo ($sidebar_current === 'admin_system.php') ? 'active' : ''; ?>">
                <i class="bi bi-sliders text-danger"></i>
                <span>Cấu Hình & Audit Log</span>
            </a>
        <?php endif; ?>
    </div>

    <!-- Sidebar Footer / Quick User Profile & Exit -->
    <div class="admin-sidebar-footer">
        <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded-3 bg-dark bg-opacity-50 border border-secondary border-opacity-25">
            <div class="admin-avatar-sm">
                <?php echo strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)); ?>
            </div>
            <div class="flex-grow-1 text-truncate">
                <div class="fw-bold text-white small text-truncate">@<?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></div>
                <div class="text-secondary" style="font-size: 0.72rem;">
                    <?php if ($sidebar_is_super): ?>
                        <span class="text-danger fw-semibold"><i class="bi bi-stars"></i> SUPER ADMIN</span>
                    <?php else: ?>
                        <span class="text-primary fw-semibold"><i class="bi bi-shield-check"></i> ADMIN</span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="logout.php" class="btn btn-sm btn-outline-danger p-1 px-2" title="Đăng xuất khỏi hệ thống">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
        <div class="text-center">
            <a href="index.php" target="_blank" class="admin-store-link small text-decoration-none">
                <i class="bi bi-box-arrow-up-right me-1"></i> Mở Cửa Hàng (Tab Mới)
            </a>
        </div>
    </div>
</aside>

