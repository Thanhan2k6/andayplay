<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['chat_csrf_token'])) {
    $_SESSION['chat_csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/connect.php'; 

$current_page = basename($_SERVER['PHP_SELF']);

// Số lượng thông báo chưa đọc và ticket cần xử lý
$header_unread_notifs = 0;
$header_admin_open_tickets = 0;
if (isset($_SESSION['user_id'])) {
    $hdr_uid = intval($_SESSION['user_id']);
    if (function_exists('get_unread_notification_count')) {
        $header_unread_notifs = get_unread_notification_count($conn, $hdr_uid);
    }
    if (isset($_SESSION['role']) && in_array(intval($_SESSION['role']), [1, 2])) {
        try {
            $q_ot = $conn->query("SELECT COUNT(*) as c FROM support_tickets WHERE status IN ('new', 'assigned', 'in_progress')");
            if ($q_ot && $r_ot = $q_ot->fetch_assoc()) {
                $header_admin_open_tickets = intval($r_ot['c'] ?? 0);
            }
        } catch (Throwable $e) {}
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANPLAY STORE — Gaming Store & Thuê Tài Khoản Game Bản Quyền</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Main Stylesheet & Chatbot Stylesheet -->
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/chatbot.css?v=<?php echo time(); ?>">
</head>
<body>

<!-- Lớp phủ mờ khi mở Menu Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR ĐIỀU HƯỚNG BÊN TRÁI -->
<aside class="sidebar" id="mobileSidebar">
    <!-- Brand Logo -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <a href="index.php" class="sidebar-brand text-decoration-none">
            <div class="brand-icon-box">
                <i class="bi bi-controller"></i>
            </div>
            <span class="brand-text">ANPLAY</span>
        </a>
        <button class="btn btn-sm btn-outline-secondary d-lg-none me-3" id="closeSidebarBtn">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    
    <!-- Menu Chính -->
    <div class="sidebar-title">Khám Phá</div>
    <a href="index.php" class="sidebar-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
        <i class="bi bi-grid-1x2-fill"></i> Cửa Hàng Game
    </a>
    
    <?php if(isset($_SESSION['user_id'])): ?>
        <a href="my_library.php" class="sidebar-item <?php echo ($current_page == 'my_library.php') ? 'active' : ''; ?>">
            <i class="bi bi-collection-play-fill text-success"></i> Thư viện của tôi
        </a>
        <a href="notifications.php" class="sidebar-item <?php echo ($current_page == 'notifications.php') ? 'active' : ''; ?>">
            <i class="bi bi-bell-fill text-warning"></i> Thông báo
            <?php if ($header_unread_notifs > 0): ?>
                <span class="badge bg-danger rounded-pill ms-auto" id="sidebarNotifBadge"><?php echo $header_unread_notifs; ?></span>
            <?php endif; ?>
        </a>
        <a href="support.php" class="sidebar-item <?php echo ($current_page == 'support.php' || $current_page == 'support_detail.php') ? 'active' : ''; ?>">
            <i class="bi bi-headset text-info"></i> Hỗ trợ khách hàng
        </a>
        
        <?php if(isset($_SESSION['role']) && in_array(intval($_SESSION['role']), [1, 2])): ?>
            <!-- Lối tắt vào Admin Console độc lập -->
            <div class="sidebar-title mt-4" style="color: #f43f5e;">Khu Vực Quản Trị</div>
            <a href="admin_dashboard.php" class="sidebar-item" style="background: rgba(244,63,94,0.12); border: 1px solid rgba(244,63,94,0.25); color: #f43f5e; font-weight: 700;">
                <i class="bi bi-shield-shaded text-danger"></i> Đến Bảng Quản Trị
                <?php if ($header_admin_open_tickets > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-auto" title="Yêu cầu hỗ trợ mới"><?php echo $header_admin_open_tickets; ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Thể loại Game -->
    <div class="sidebar-title mt-4">Thể Loại Trò Chơi</div>
    <?php
    if (isset($conn)) {
        $cate_query = $conn->query("SELECT * FROM categories ORDER BY cate_name ASC");
        if ($cate_query && $cate_query->num_rows > 0) {
            $icons = ['bi-crosshair', 'bi-shield-shaded', 'bi-car-front-fill', 'bi-lightning-charge-fill', 'bi-compass-fill', 'bi-puzzle-fill'];
            $i = 0;
            $active_cate = isset($_GET['category']) ? intval($_GET['category']) : 0;
            while ($c = $cate_query->fetch_assoc()) {
                $icon = $icons[$i % count($icons)];
                $is_cat_active = ($active_cate == $c['category_id']) ? 'active' : '';
                echo '<a href="index.php?category='.$c['category_id'].'" class="sidebar-item '.$is_cat_active.'"><i class="bi '.$icon.'"></i> ' . htmlspecialchars($c['cate_name']) . '</a>';
                $i++;
            }
        }
    }
    ?>
    
    <div class="mt-auto p-3 text-center border-top border-secondary border-opacity-10">
        <small class="text-secondary d-block" style="font-size: 0.72rem;">ANPLAY STORE v2.5</small>
        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 mt-1" style="font-size: 0.68rem;">
            <i class="bi bi-shield-check me-1"></i> Hệ thống 24/7
        </span>
    </div>
</aside>

<!-- KHUNG LÀM VIỆC CHÍNH BÊN PHẢI -->
<div class="main-wrapper">
    <!-- TOPBAR TRÊN CÙNG -->
    <header class="topbar">
        <!-- Nút mở Menu trên Mobile -->
        <button class="btn text-white d-lg-none me-2 p-1" id="openSidebarBtn" aria-label="Open Menu">
            <i class="bi bi-list fs-2"></i>
        </button>

        <!-- Form tìm kiếm thông minh (desktop) -->
        <form action="index.php" method="GET" class="search-container d-none d-md-block">
            <i class="bi bi-search search-icon-left"></i>
            <input type="text" name="search" class="search-input-box" placeholder="Tìm kiếm bom tấn, tài khoản Steam, EA, Epic..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            <span class="search-kbd-hint">Ctrl + K</span>
        </form>

        <!-- Nút tìm kiếm mobile -->
        <button class="mobile-search-btn d-md-none" id="mobileSearchBtn" type="button" aria-label="Tìm kiếm">
            <i class="bi bi-search"></i>
        </button>

        <!-- Menu Action bên phải -->
        <!-- Menu Action bên phải -->
        <div class="d-flex align-items-center gap-2 ms-auto">
            <!-- Nút hỗ trợ khách hàng nhanh -->
            <a href="support.php" class="topbar-action-btn" title="Trung tâm hỗ trợ 24/7">
                <i class="bi bi-headset"></i>
            </a>

            <?php if(isset($_SESSION['user_id'])): 
                $hdr_username = htmlspecialchars($_SESSION['username'] ?? 'User');
                $hdr_email = htmlspecialchars($_SESSION['email'] ?? '');
                $hdr_role = intval($_SESSION['role'] ?? 0);
                $hdr_is_admin = in_array($hdr_role, [1, 2]);
                $hdr_is_super = ($hdr_role === 2);
            ?>
                <!-- NOTIFICATION DROPDOWN (DESKTOP & MOBILE) -->
                <div class="dropdown" id="topbarNotifWrapper">
                    <button class="topbar-action-btn position-relative border-0" type="button" id="notifDropdownBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Thông báo" style="cursor: pointer;">
                        <i class="bi bi-bell-fill"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-dark notif-badge <?php echo ($header_unread_notifs > 0) ? '' : 'd-none'; ?>" id="topbarNotifBadge" style="font-size: 0.65rem; padding: 3px 6px;">
                            <?php echo $header_unread_notifs; ?>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow-lg mt-2 p-0 rounded-4" aria-labelledby="notifDropdownBtn" style="width: 360px; max-width: 92vw; background-color: #0f121a; border: 1px solid rgba(255,255,255,0.12); box-shadow: 0 20px 40px -10px rgba(0,0,0,0.9);">
                        <!-- Header -->
                        <div class="p-3 border-bottom border-secondary border-opacity-25 d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-bell-fill text-warning"></i>
                                <h6 class="fw-bold text-white mb-0" style="font-size: 0.95rem;">Thông Báo</h6>
                                <span class="badge bg-danger rounded-pill <?php echo ($header_unread_notifs > 0) ? '' : 'd-none'; ?>" id="notifDropdownHeaderBadge"><?php echo $header_unread_notifs; ?></span>
                            </div>
                            <button class="btn btn-link btn-sm text-decoration-none p-0 text-secondary hover-white" id="markAllReadQuickBtn" style="font-size: 0.75rem;" onclick="markAllNotificationsReadQuick(event)">
                                <i class="bi bi-check2-all me-1"></i> Đã đọc tất cả
                            </button>
                        </div>

                        <!-- Danh sách thông báo nhanh -->
                        <div id="notifDropdownList" class="d-flex flex-column" style="max-height: 380px; overflow-y: auto;">
                            <div class="p-4 text-center text-secondary small" id="notifDropdownLoading">
                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div> Đang tải thông báo...
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="p-2 border-top border-secondary border-opacity-25 text-center bg-dark bg-opacity-25 rounded-bottom-4">
                            <a href="notifications.php" class="text-primary text-decoration-none small fw-bold d-block py-1 hover-underline">
                                Xem tất cả thông báo <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- USER AVATAR DROPDOWN -->
                <div class="dropdown">
                    <button class="user-profile-btn dropdown-toggle text-decoration-none border-0" type="button" id="userMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($hdr_username); ?>&background=6366f1&color=fff&bold=true&rounded=true" width="32" height="32" class="rounded-circle shadow-sm" alt="Avatar">
                        <div class="d-none d-md-flex flex-column text-start">
                            <span class="fw-bold" style="font-size: 0.86rem; line-height: 1.2;">@<?php echo $hdr_username; ?></span>
                            <span class="text-secondary" style="font-size: 0.70rem;">
                                <?php 
                                if ($hdr_is_super) {
                                    echo '<span class="text-danger fw-bold"><i class="bi bi-shield-shaded"></i> Super Admin</span>';
                                } elseif ($hdr_is_admin) {
                                    echo '<span class="text-warning fw-bold"><i class="bi bi-shield-check"></i> Admin</span>';
                                } else {
                                    echo '<span class="text-info"><i class="bi bi-patch-check-fill"></i> Thành viên VIP</span>';
                                }
                                ?>
                            </span>
                        </div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow-lg mt-2 p-2" aria-labelledby="userMenuDropdown" style="min-width: 265px; background-color: #0f121a; border: 1px solid rgba(255,255,255,0.12); border-radius: 16px; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.9);">
                        <!-- USER SUMMARY CARD IN DROPDOWN -->
                        <li class="px-3 py-3 mb-2 rounded-3" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06);">
                            <div class="d-flex align-items-center gap-3">
                                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($hdr_username); ?>&background=6366f1&color=fff&bold=true&rounded=true" width="40" height="40" class="rounded-circle shadow" alt="Avatar">
                                <div class="overflow-hidden">
                                    <div class="fw-bold text-white text-truncate" style="font-size: 0.92rem;">@<?php echo $hdr_username; ?></div>
                                    <?php if(!empty($hdr_email)): ?>
                                        <div class="text-secondary small text-truncate" style="font-size: 0.75rem;"><?php echo $hdr_email; ?></div>
                                    <?php endif; ?>
                                    <div class="mt-1">
                                        <?php if($hdr_is_super): ?>
                                            <span class="badge" style="background: rgba(244,63,94,0.2); color: #f43f5e; border: 1px solid rgba(244,63,94,0.4); font-size: 0.68rem; padding: 2px 6px;">
                                                <i class="bi bi-shield-shaded me-1"></i> Super Admin
                                            </span>
                                        <?php elseif($hdr_is_admin): ?>
                                            <span class="badge" style="background: rgba(99,102,241,0.2); color: #6366f1; border: 1px solid rgba(99,102,241,0.4); font-size: 0.68rem; padding: 2px 6px;">
                                                <i class="bi bi-shield-lock-fill me-1"></i> Quản Trị Viên
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(6,182,212,0.15); color: #06b6d4; border: 1px solid rgba(6,182,212,0.35); font-size: 0.68rem; padding: 2px 6px;">
                                                <i class="bi bi-gem me-1"></i> Khách Hàng VIP
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </li>

                        <!-- SECTION: QUẢN LÝ TÀI KHOẢN -->
                        <li><h6 class="dropdown-header text-uppercase text-secondary fw-bold px-3 py-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">Tài Khoản & Cá Nhân</h6></li>
                        <li>
                            <a class="dropdown-item py-2 px-3 rounded-2 d-flex align-items-center justify-content-between" href="profile.php?tab=overview">
                                <span><i class="bi bi-person-circle me-2 text-primary"></i> Thông tin tài khoản</span>
                                <i class="bi bi-chevron-right text-secondary" style="font-size: 0.75rem;"></i>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2 px-3 rounded-2 d-flex align-items-center justify-content-between" href="notifications.php">
                                <span><i class="bi bi-bell me-2 text-warning"></i> Hộp thư thông báo</span>
                                <?php if ($header_unread_notifs > 0): ?>
                                    <span class="badge bg-danger rounded-pill"><?php echo $header_unread_notifs; ?></span>
                                <?php else: ?>
                                    <i class="bi bi-chevron-right text-secondary" style="font-size: 0.75rem;"></i>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2 px-3 rounded-2 d-flex align-items-center justify-content-between" href="support.php">
                                <span><i class="bi bi-headset me-2 text-info"></i> Hỗ trợ khách hàng</span>
                                <i class="bi bi-chevron-right text-secondary" style="font-size: 0.75rem;"></i>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2 px-3 rounded-2 d-flex align-items-center justify-content-between" href="my_library.php">
                                <span><i class="bi bi-collection-play me-2 text-success"></i> Thư viện & Game thuê</span>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25" style="font-size: 0.65rem;">Active</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2 px-3 rounded-2 d-flex align-items-center justify-content-between" href="profile.php?tab=orders">
                                <span><i class="bi bi-receipt-cutoff me-2 text-info"></i> Lịch sử thuê & Đơn hàng</span>
                                <i class="bi bi-chevron-right text-secondary" style="font-size: 0.75rem;"></i>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2 px-3 rounded-2 d-flex align-items-center justify-content-between" href="profile.php?tab=security">
                                <span><i class="bi bi-shield-lock me-2 text-warning"></i> Đổi mật khẩu & Bảo mật</span>
                                <i class="bi bi-chevron-right text-secondary" style="font-size: 0.75rem;"></i>
                            </a>
                        </li>

                        <!-- SECTION: QUẢN TRỊ VIÊN NẾU CÓ -->
                        <!-- SECTION: LỐI TẮT QUẢN TRỊ NẾU LÀ ADMIN / SUPER ADMIN -->
                        <?php if($hdr_is_admin): ?>
                            <li><hr class="dropdown-divider border-secondary border-opacity-25 my-2"></li>
                            <li><h6 class="dropdown-header text-uppercase text-danger fw-bold px-3 py-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">Bảng Điều Hành</h6></li>
                            <li>
                                <a class="dropdown-item py-2 px-3 rounded-2 d-flex align-items-center justify-content-between text-danger fw-bold" href="admin_dashboard.php">
                                    <span><i class="bi bi-shield-shaded me-2"></i> Mở Admin Console</span>
                                    <i class="bi bi-arrow-right" style="font-size: 0.75rem;"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- LOGOUT -->
                        <li><hr class="dropdown-divider border-secondary border-opacity-25 my-2"></li>
                        <li>
                            <a class="dropdown-item py-2 px-3 rounded-2 text-danger fw-bold d-flex align-items-center" href="logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i> Đăng xuất an toàn
                            </a>
                        </li>
                    </ul>
                </div>
            <?php else: ?>
                <div class="d-flex align-items-center gap-2">
                    <a href="login.php" class="btn btn-gaming-secondary py-1 px-3" style="font-size: 0.85rem; min-height: 38px;">Đăng nhập</a>
                    <a href="register.php" class="btn btn-gaming-primary py-1 px-3" style="font-size: 0.85rem; min-height: 38px;">Đăng ký</a>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <!-- BẮT ĐẦU VÙNG CUỘN NỘI DUNG -->
    <div class="content-scroll">

    <!-- MOBILE SEARCH OVERLAY -->
    <div class="mobile-search-overlay" id="mobileSearchOverlay">
        <form action="index.php" method="GET" class="flex-1 position-relative" style="flex:1">
            <i class="bi bi-search search-icon-left" style="left:14px;top:50%;transform:translateY(-50%);position:absolute;color:var(--text-dim);pointer-events:none;"></i>
            <input type="text" name="search" class="search-input-box" id="mobileSearchInput" placeholder="Tìm kiếm game..." autocomplete="off" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" style="padding-left:42px;">
        </form>
        <button type="button" id="mobileSearchClose" style="background:transparent;border:none;color:var(--text-muted);font-size:1.2rem;padding:6px 8px;cursor:pointer;" aria-label="Đóng tìm kiếm">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <!-- JAVASCRIPT XỬ LÝ MENU MOBILE & PHÍM TẮT -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const openBtn = document.getElementById('openSidebarBtn');
            const closeBtn = document.getElementById('closeSidebarBtn');
            const sidebar = document.getElementById('mobileSidebar');
            const overlay = document.getElementById('sidebarOverlay');

            if (openBtn && sidebar && overlay) {
                openBtn.addEventListener('click', function() {
                    sidebar.classList.add('active');
                    overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });
            }

            function closeMenu() {
                if (sidebar) sidebar.classList.remove('active');
                if (overlay) overlay.classList.remove('active');
                document.body.style.overflow = '';
            }

            if (closeBtn) closeBtn.addEventListener('click', closeMenu);
            if (overlay) overlay.addEventListener('click', closeMenu);

            // ESC key closes sidebar
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeMenu();
                    closeMobileSearch();
                }
                // Ctrl+K focus search
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    const searchInput = document.querySelector('.search-input-box:not(#mobileSearchInput)');
                    if (searchInput && window.innerWidth >= 768) {
                        searchInput.focus();
                    } else {
                        openMobileSearch();
                    }
                }
            });

            // Mobile search overlay
            const mobileSearchBtn = document.getElementById('mobileSearchBtn');
            const mobileSearchOverlay = document.getElementById('mobileSearchOverlay');
            const mobileSearchClose = document.getElementById('mobileSearchClose');
            const mobileSearchInput = document.getElementById('mobileSearchInput');

            function openMobileSearch() {
                if (mobileSearchOverlay) {
                    mobileSearchOverlay.classList.add('active');
                    setTimeout(() => mobileSearchInput && mobileSearchInput.focus(), 100);
                }
            }
            function closeMobileSearch() {
                if (mobileSearchOverlay) mobileSearchOverlay.classList.remove('active');
            }

            if (mobileSearchBtn) mobileSearchBtn.addEventListener('click', openMobileSearch);
            if (mobileSearchClose) mobileSearchClose.addEventListener('click', closeMobileSearch);

            // Set active state for mobile bottom nav
            const currentPage = '<?php echo basename($_SERVER["PHP_SELF"]); ?>';
            document.querySelectorAll('.mobile-nav-item[data-page]').forEach(function(item) {
                const pages = item.getAttribute('data-page').split(',');
                if (pages.includes(currentPage)) {
                    item.classList.add('active');
                }
            });

            // NOTIFICATION DROPDOWN DYNAMIC FETCH & POLLING
            const notifBtn = document.getElementById('notifDropdownBtn');
            const notifList = document.getElementById('notifDropdownList');
            const topbarBadge = document.getElementById('topbarNotifBadge');
            const sidebarBadge = document.getElementById('sidebarNotifBadge');
            const headerBadge = document.getElementById('notifDropdownHeaderBadge');

            function updateBadges(count) {
                const num = parseInt(count) || 0;
                [topbarBadge, headerBadge].forEach(b => {
                    if (b) {
                        b.innerText = num;
                        if (num > 0) {
                            b.classList.remove('d-none');
                        } else {
                            b.classList.add('d-none');
                        }
                    }
                });
                if (sidebarBadge) {
                    sidebarBadge.innerText = num;
                    if (num > 0) {
                        sidebarBadge.classList.remove('d-none');
                    } else {
                        sidebarBadge.classList.add('d-none');
                    }
                }
            }

            function renderNotifications(items) {
                if (!notifList) return;
                if (!items || items.length === 0) {
                    notifList.innerHTML = '<div class="p-4 text-center text-secondary small"><i class="bi bi-bell-slash fs-4 d-block mb-1 opacity-50"></i>Bạn không có thông báo mới</div>';
                    return;
                }
                let html = '';
                items.forEach(item => {
                    const iconInfo = item.icon_info || { icon: 'bi-bell-fill', color: '#6366f1', bg: 'rgba(99,102,241,0.15)', label: 'Hệ thống' };
                    const isUnread = (parseInt(item.is_read) === 0);
                    const linkUrl = item.link ? ('notifications.php?action=read_and_go&id=' + item.id + '&redirect=' + encodeURIComponent(item.link)) : ('notifications.php?action=mark_single&id=' + item.id);
                    
                    html += '<div class="p-3 border-bottom border-secondary border-opacity-10 d-flex align-items-start gap-3 transition-all position-relative" style="background: ' + (isUnread ? 'rgba(99,102,241,0.06)' : 'transparent') + ';">' +
                        '<div class="rounded-3 p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; background: ' + iconInfo.bg + '; color: ' + iconInfo.color + ';">' +
                            '<i class="bi ' + iconInfo.icon + '"></i>' +
                        '</div>' +
                        '<div class="flex-grow-1 overflow-hidden">' +
                            '<div class="d-flex align-items-center justify-content-between gap-1 mb-1">' +
                                '<a href="' + linkUrl + '" class="text-white text-decoration-none small text-truncate fw-bold ' + (isUnread ? 'text-primary' : '') + '" style="max-width: 210px;">' + escapeHtml(item.title) + '</a>' +
                                '<div class="d-flex align-items-center gap-1">' +
                                    (isUnread ? '<span class="p-1 bg-primary rounded-circle" style="width: 6px; height: 6px;"></span>' : '') +
                                    '<button type="button" class="btn btn-link text-secondary p-0 ms-1 opacity-50 hover-danger" style="font-size: 0.8rem;" title="Xóa thông báo" onclick="deleteNotificationQuick(event, ' + item.id + ')"><i class="bi bi-x-lg"></i></button>' +
                                '</div>' +
                            '</div>' +
                            '<a href="' + linkUrl + '" class="text-secondary text-decoration-none small text-truncate d-block" style="font-size: 0.78rem; line-height: 1.3;">' + escapeHtml(item.message) + '</a>' +
                            '<div class="d-flex align-items-center justify-content-between mt-1">' +
                                '<span class="badge py-0 px-1" style="background: ' + iconInfo.bg + '; color: ' + iconInfo.color + '; font-size: 0.65rem;">' + escapeHtml(iconInfo.label) + '</span>' +
                                '<span class="text-secondary small" style="font-size: 0.68rem;"><i class="bi bi-clock me-1"></i>' + (item.time_ago || '') + '</span>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                });
                notifList.innerHTML = html;
            }

            function escapeHtml(text) {
                if (!text) return '';
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return text.replace(/[&<>"']/g, function(m) { return map[m]; });
            }

            if (notifBtn) {
                notifBtn.addEventListener('show.bs.dropdown', function () {
                    fetch('api_notifications.php?action=get_dropdown')
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                updateBadges(data.unread_count);
                                renderNotifications(data.items);
                            }
                        })
                        .catch(() => {});
                });
            }

            window.markAllNotificationsReadQuick = function(e) {
                if (e) e.stopPropagation();
                fetch('api_notifications.php?action=mark_all_read', { method: 'POST' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            updateBadges(0);
                            if (notifBtn) {
                                fetch('api_notifications.php?action=get_dropdown')
                                    .then(r => r.json())
                                    .then(d => d.success && renderNotifications(d.items));
                            }
                        }
                    })
                    .catch(() => {});
            };

            window.deleteNotificationQuick = function(e, notifId) {
                if (e) { e.preventDefault(); e.stopPropagation(); }
                fetch('api_notifications.php?action=delete&id=' + notifId, { method: 'POST' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            updateBadges(data.unread_count);
                            fetch('api_notifications.php?action=get_dropdown')
                                .then(r => r.json())
                                .then(d => d.success && renderNotifications(d.items));
                        }
                    })
                    .catch(() => {});
            };

            // Polling mỗi 35 giây
            <?php if (isset($_SESSION['user_id'])): ?>
            setInterval(function() {
                fetch('api_notifications.php?action=get_unread_count')
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.success) {
                            updateBadges(data.unread_count);
                        }
                    })
                    .catch(() => {});
            }, 35000);
            <?php endif; ?>
        });
    </script>