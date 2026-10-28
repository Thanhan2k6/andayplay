<?php
/**
 * ANPLAY STORE - DEDICATED ADMIN HEADER & LAYOUT
 * Layout Quản Trị chuyên biệt 100% dành cho Quản Trị Viên (Admin & Super Admin)
 * Tách biệt hoàn toàn khỏi giao diện người dùng
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/connect.php';

// Kiểm tra quyền Quản trị viên (Bảo vệ bảo mật server-side)
require_admin();

$admin_user_id = intval($_SESSION['user_id']);
$admin_username = $_SESSION['username'] ?? 'Admin';
$admin_role = isset($_SESSION['role']) ? intval($_SESSION['role']) : 1;
$admin_is_super = ($admin_role === ROLE_SUPER_ADMIN);

// Số lượng thông báo chưa đọc của Admin
$admin_unread_notifs = 0;
if (function_exists('get_unread_notification_count')) {
    $admin_unread_notifs = get_unread_notification_count($conn, $admin_user_id);
}

// Số lượng yêu cầu hỗ trợ mở cần xử lý
$admin_open_tickets = 0;
try {
    $q_ot = $conn->query("SELECT COUNT(*) as c FROM support_tickets WHERE status IN ('new', 'assigned', 'in_progress')");
    if ($q_ot && $r_ot = $q_ot->fetch_assoc()) {
        $admin_open_tickets = intval($r_ot['c'] ?? 0);
    }
} catch (Throwable $e) {}

$page_file_name = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' — ANPLAY ADMIN' : 'ANPLAY ADMIN CONSOLE'; ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Early Theme Detection -->
    <script>
        (function() {
            try {
                var savedTheme = localStorage.getItem('anplay_theme');
                if (savedTheme === 'light') {
                    document.documentElement.setAttribute('data-theme', 'light');
                } else if (!savedTheme && window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
                    document.documentElement.setAttribute('data-theme', 'light');
                } else {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch(e) {}
        })();
    </script>

    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">

    <style>
        /* =========================================================
           DEDICATED ADMIN CONSOLE LAYOUT STYLES
           Tách biệt hoàn toàn khỏi giao diện người dùng
           ========================================================= */
        :root {
            --admin-bg-base: #090b10;
            --admin-bg-surface: #0f121d;
            --admin-bg-card: #151926;
            --admin-border: rgba(255, 255, 255, 0.08);
            --admin-border-focus: rgba(99, 102, 241, 0.4);
            --admin-sidebar-width: 260px;
            --admin-topbar-height: 64px;
        }

        body.admin-body {
            background-color: var(--admin-bg-base);
            color: #f1f5f9;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 0;
            display: block !important;
            min-height: 100vh;
            min-height: 100dvh;
            height: auto !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
        }

        .admin-layout-wrapper {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* ADMIN SIDEBAR */
        .admin-sidebar {
            width: var(--admin-sidebar-width);
            min-width: var(--admin-sidebar-width);
            background: var(--admin-bg-surface);
            border-right: 1px solid var(--admin-border);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1040;
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .admin-sidebar-header {
            height: var(--admin-topbar-height);
            padding: 0 1.25rem;
            border-bottom: 1px solid var(--admin-border);
        }

        .admin-brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #fff;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
        }

        .admin-brand-title {
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #fff;
            line-height: 1.1;
        }

        .admin-brand-subtitle {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 1px;
            color: #f43f5e;
            text-transform: uppercase;
        }

        .admin-sidebar-nav {
            flex-grow: 1;
            overflow-y: auto;
            padding: 1rem 0.85rem;
        }

        .admin-nav-group-title {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
            padding: 0.75rem 0.65rem 0.35rem;
            margin-top: 0.35rem;
        }

        .admin-nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.65rem 0.85rem;
            border-radius: 10px;
            color: #94a3b8;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            margin-bottom: 0.2rem;
        }

        .admin-nav-item:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
            transform: translateX(3px);
        }

        .admin-nav-item.active {
            color: #fff;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.25) 0%, rgba(99, 102, 241, 0.1) 100%);
            border: 1px solid rgba(99, 102, 241, 0.4);
            font-weight: 700;
        }

        .admin-nav-item.active i {
            color: #818cf8;
        }

        .admin-nav-item i {
            font-size: 1.1rem;
            width: 22px;
            text-align: center;
        }

        .admin-sidebar-footer {
            padding: 0.85rem;
            border-top: 1px solid var(--admin-border);
            background: rgba(10, 12, 18, 0.4);
        }

        .admin-avatar-sm {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            color: #fff;
            font-weight: 800;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .admin-store-link {
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.78rem;
            display: inline-block;
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.2s;
        }

        .admin-store-link:hover {
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.1);
            border-color: rgba(56, 189, 248, 0.2);
        }

        /* MAIN CONTAINER */
        .admin-main-container {
            flex-grow: 1;
            margin-left: var(--admin-sidebar-width);
            width: calc(100% - var(--admin-sidebar-width));
            min-width: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: 100dvh;
            background-color: var(--admin-bg-base);
        }

        /* TOPBAR */
        .admin-topbar {
            height: var(--admin-topbar-height);
            background: rgba(15, 18, 29, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 1030;
        }

        .admin-content-area {
            flex-grow: 1;
            padding: 1.5rem;
            min-width: 0;
        }

        /* MOBILE SIDEBAR & OVERLAY */
        .admin-sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            z-index: 1035;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        @media (max-width: 991.98px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }
            .admin-sidebar.show {
                transform: translateX(0);
            }
            .admin-sidebar-backdrop.show {
                display: block;
                opacity: 1;
            }
            .admin-main-container {
                margin-left: 0;
                width: 100%;
            }
            .admin-topbar {
                padding: 0 1rem;
            }
            .admin-content-area {
                padding: 1rem 0.75rem;
            }
        }

        /* NOTIFICATION DROPDOWN STYLES */
        .admin-notif-dropdown {
            width: 360px;
            max-width: 90vw;
            background: #111420;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.8);
            padding: 0;
            overflow: hidden;
        }

        .admin-notif-item {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            transition: background 0.2s;
            text-decoration: none;
            color: #cbd5e1;
        }

        .admin-notif-item:hover {
            background: rgba(255, 255, 255, 0.04);
            color: #fff;
        }

        .admin-notif-item.unread {
            background: rgba(99, 102, 241, 0.08);
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 5px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }
    </style>
</head>
<body class="admin-body">

<!-- Mobile Sidebar Backdrop -->
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop" onclick="toggleAdminSidebar(false)"></div>

<div class="admin-layout-wrapper">
    <!-- ADMIN SIDEBAR -->
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

    <!-- ADMIN MAIN CONTAINER -->
    <div class="admin-main-container">
        <!-- TOPBAR -->
        <header class="admin-topbar">
            <!-- Left: Mobile Toggle & Console Label -->
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-outline-secondary btn-sm d-lg-none" onclick="toggleAdminSidebar(true)" aria-label="Mở menu">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div class="d-none d-sm-flex align-items-center gap-2">
                    <span class="badge bg-dark border border-secondary border-opacity-50 text-secondary px-2 py-1 small">
                        <i class="bi bi-terminal-fill me-1 text-info"></i> CONSOLE
                    </span>
                    <span class="text-secondary small">|</span>
                    <span class="text-white fw-bold small text-uppercase" style="letter-spacing: 0.5px;">
                        <?php
                            $labels = [
                                'admin_dashboard.php' => 'Bảng Điều Khiển Tổng Quan',
                                'admin_games.php' => 'Quản Lý Kho Game',
                                'admin_add_game.php' => 'Thêm Tựa Game Mới',
                                'admin_edit_game.php' => 'Chỉnh Sửa Thông Tin Game',
                                'admin_accounts.php' => 'Quản Trị Kho Tài Khoản Game',
                                'admin_borrow.php' => 'Quản Lý Đơn Thuê & Giao Dịch',
                                'admin_users.php' => 'Quản Lý Danh Sách Khách Hàng',
                                'admin_support.php' => 'Trung Tâm Hỗ Trợ Khách Hàng',
                                'admin_support_detail.php' => 'Chi Tiết & Phản Hồi Yêu Cầu Hỗ Trợ',
                                'admin_notifications.php' => 'Trung Tâm Phát Tán Thông Báo',
                                'admin_management.php' => 'Quản Trị Ban Quản Trị & Quyền Cấp Cao',
                                'admin_roles.php' => 'Ma Trận Quyền & Phân Cấp Vai Trò',
                                'admin_system.php' => 'Cấu Hình Hệ Thống & Nhật Ký Kiểm Toán'
                            ];
                            echo $labels[$page_file_name] ?? 'Quản Trị Hệ Thống';
                        ?>
                    </span>
                </div>
            </div>

            <!-- Right: Actions, Notifications & Profile -->
            <div class="d-flex align-items-center gap-2 gap-sm-3">
                <!-- Nút mở cửa hàng ngoài storefront -->
                <a href="index.php" target="_blank" class="btn btn-sm btn-outline-secondary d-none d-md-inline-flex align-items-center gap-1 py-1 px-3 text-decoration-none">
                    <i class="bi bi-box-arrow-up-right text-info"></i>
                    <span>Xem Cửa Hàng</span>
                </a>

                <!-- Alert Support Ticket (Hỗ trợ khách hàng) -->
                <a href="admin_support.php?status=new" class="btn btn-sm btn-outline-secondary position-relative p-2" title="Yêu cầu hỗ trợ khách hàng">
                    <i class="bi bi-headset fs-6 text-info"></i>
                    <?php if ($admin_open_tickets > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-dark" style="font-size: 0.65rem;">
                            <?php echo $admin_open_tickets; ?>
                        </span>
                    <?php endif; ?>
                </a>

                <!-- Notification Bell Dropdown -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary position-relative p-2" type="button" id="adminNotifDropdownBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Thông báo quản trị">
                        <i class="bi bi-bell-fill fs-6 text-warning"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-dark" id="adminTopbarNotifBadge" style="font-size: 0.65rem; <?php echo ($admin_unread_notifs > 0) ? '' : 'display: none;'; ?>">
                            <?php echo $admin_unread_notifs; ?>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end admin-notif-dropdown" aria-labelledby="adminNotifDropdownBtn">
                        <div class="p-3 border-bottom border-secondary border-opacity-25 d-flex justify-content-between align-items-center bg-dark bg-opacity-50">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold text-white small">Thông Báo Quản Trị</span>
                                <span class="badge bg-danger rounded-pill" id="adminNotifHeaderBadge"><?php echo $admin_unread_notifs; ?> mới</span>
                            </div>
                            <button type="button" class="btn btn-link text-info p-0 small text-decoration-none" onclick="markAllAdminNotificationsReadQuick(event)" style="font-size: 0.78rem;">
                                <i class="bi bi-check2-all me-1"></i>Đọc tất cả
                            </button>
                        </div>
                        <div class="custom-scrollbar" id="adminNotifDropdownList" style="max-height: 340px; overflow-y: auto;">
                            <div class="p-4 text-center text-secondary small">
                                <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                                <div>Đang tải thông báo...</div>
                            </div>
                        </div>
                        <div class="p-2 border-top border-secondary border-opacity-25 text-center bg-dark bg-opacity-25">
                            <a href="admin_notifications.php" class="text-primary small text-decoration-none fw-bold">
                                Xem tất cả thông báo &rarr;
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Admin Profile Dropdown -->
                <div class="dropdown">
                    <button class="btn btn-sm d-flex align-items-center gap-2 p-1 px-2 rounded-pill bg-dark bg-opacity-50 border border-secondary border-opacity-25" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="admin-avatar-sm" style="width: 28px; height: 28px; font-size: 0.8rem;">
                            <?php echo strtoupper(substr($admin_username, 0, 1)); ?>
                        </div>
                        <div class="d-none d-sm-block text-start" style="line-height: 1.1;">
                            <span class="text-white small fw-bold d-block text-truncate" style="max-width: 100px;">@<?php echo htmlspecialchars($admin_username); ?></span>
                            <?php if ($admin_is_super): ?>
                                <span class="text-danger fw-bold" style="font-size: 0.65rem;">SUPER ADMIN</span>
                            <?php else: ?>
                                <span class="text-primary fw-bold" style="font-size: 0.65rem;">ADMIN</span>
                            <?php endif; ?>
                        </div>
                        <i class="bi bi-chevron-down text-secondary small"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg" style="background: #111420; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; min-width: 200px;">
                        <li class="px-3 py-2 border-bottom border-secondary border-opacity-25">
                            <div class="text-white fw-bold small text-truncate">@<?php echo htmlspecialchars($admin_username); ?></div>
                            <div class="text-secondary small" style="font-size: 0.75rem;">
                                <?php echo $admin_is_super ? 'Quản Trị Cấp Cao' : 'Quản Trị Viên'; ?>
                            </div>
                        </li>
                        <li><a class="dropdown-item text-light small py-2" href="admin_dashboard.php"><i class="bi bi-speedometer2 me-2 text-primary"></i>Bảng điều khiển</a></li>
                        <li><a class="dropdown-item text-light small py-2" href="admin_notifications.php"><i class="bi bi-bell me-2 text-warning"></i>Trung tâm thông báo</a></li>
                        <?php if ($admin_is_super): ?>
                            <li><a class="dropdown-item text-light small py-2" href="admin_management.php"><i class="bi bi-shield-shaded me-2 text-danger"></i>Quản trị hệ thống</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider border-secondary border-opacity-25"></li>
                        <li><a class="dropdown-item text-danger small py-2 fw-semibold" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Đăng xuất</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- ADMIN CONTENT AREA -->
        <main class="admin-content-area">

