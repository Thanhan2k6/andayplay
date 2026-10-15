<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/connect.php'; 

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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

    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
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
        
        <?php if(isset($_SESSION['role']) && $_SESSION['role'] == 1): ?>
            <!-- Khu vực Quản trị Admin -->
            <div class="sidebar-title mt-4" style="color: #f43f5e;">Quản Trị Hệ Thống</div>
            <a href="admin_dashboard.php" class="sidebar-item <?php echo ($current_page == 'admin_dashboard.php') ? 'active' : ''; ?>">
                <i class="bi bi-graph-up-arrow text-danger"></i> Thống kê doanh thu
            </a>
            <a href="admin_games.php" class="sidebar-item <?php echo ($current_page == 'admin_games.php' || $current_page == 'admin_add_game.php' || $current_page == 'admin_edit_game.php') ? 'active' : ''; ?>">
                <i class="bi bi-joystick text-danger"></i> Quản lý Cửa Hàng
            </a>
            <a href="admin_accounts.php" class="sidebar-item <?php echo ($current_page == 'admin_accounts.php') ? 'active' : ''; ?>">
                <i class="bi bi-box-seam-fill text-danger"></i> Kho Tài Khoản Game
            </a>
            <a href="admin_borrow.php" class="sidebar-item <?php echo ($current_page == 'admin_borrow.php') ? 'active' : ''; ?>">
                <i class="bi bi-receipt-cutoff text-danger"></i> Quản lý Đơn Thuê
            </a>
            <a href="admin_users.php" class="sidebar-item <?php echo ($current_page == 'admin_users.php') ? 'active' : ''; ?>">
                <i class="bi bi-people-fill text-danger"></i> Quản lý Khách Hàng
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

        <!-- Form tìm kiếm thông minh -->
        <form action="index.php" method="GET" class="search-container d-none d-md-block">
            <i class="bi bi-search search-icon-left"></i>
            <input type="text" name="search" class="search-input-box" placeholder="Tìm kiếm bom tấn, tài khoản Steam, EA, Epic..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            <span class="search-kbd-hint">Ctrl + K</span>
        </form>

        <!-- Menu Action bên phải -->
        <div class="d-flex align-items-center gap-3 ms-auto">
            <!-- Nút liên hệ hỗ trợ nhanh -->
            <a href="https://t.me" target="_blank" class="topbar-action-btn d-none d-sm-inline-flex" title="Hỗ trợ trực tuyến 24/7">
                <i class="bi bi-headset"></i>
            </a>

            <?php if(isset($_SESSION['user_id'])): 
                $hdr_username = htmlspecialchars($_SESSION['username'] ?? 'User');
                $hdr_email = htmlspecialchars($_SESSION['email'] ?? '');
                $hdr_is_admin = (isset($_SESSION['role']) && $_SESSION['role'] == 1);
            ?>
                <div class="dropdown">
                    <button class="user-profile-btn dropdown-toggle text-decoration-none border-0" type="button" id="userMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($hdr_username); ?>&background=6366f1&color=fff&bold=true&rounded=true" width="34" height="34" class="rounded-circle shadow-sm" alt="Avatar">
                        <div class="d-none d-md-flex flex-column text-start">
                            <span class="fw-bold" style="font-size: 0.88rem; line-height: 1.2;">@<?php echo $hdr_username; ?></span>
                            <span class="text-secondary" style="font-size: 0.72rem;">
                                <?php echo $hdr_is_admin ? '<span class="text-danger fw-bold"><i class="bi bi-shield-check"></i> Admin</span>' : '<span class="text-info"><i class="bi bi-patch-check-fill"></i> Thành viên VIP</span>'; ?>
                            </span>
                        </div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow-lg mt-2 p-2" aria-labelledby="userMenuDropdown" style="min-width: 270px; background-color: #0f121a; border: 1px solid rgba(255,255,255,0.12); border-radius: 16px; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.9);">
                        <!-- USER SUMMARY CARD IN DROPDOWN -->
                        <li class="px-3 py-3 mb-2 rounded-3" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06);">
                            <div class="d-flex align-items-center gap-3">
                                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($hdr_username); ?>&background=6366f1&color=fff&bold=true&rounded=true" width="42" height="42" class="rounded-circle shadow" alt="Avatar">
                                <div class="overflow-hidden">
                                    <div class="fw-bold text-white text-truncate" style="font-size: 0.95rem;">@<?php echo $hdr_username; ?></div>
                                    <?php if(!empty($hdr_email)): ?>
                                        <div class="text-secondary small text-truncate" style="font-size: 0.75rem;"><?php echo $hdr_email; ?></div>
                                    <?php endif; ?>
                                    <div class="mt-1">
                                        <?php if($hdr_is_admin): ?>
                                            <span class="badge" style="background: rgba(244,63,94,0.2); color: #f43f5e; border: 1px solid rgba(244,63,94,0.4); font-size: 0.68rem; padding: 2px 6px;">
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
                        <?php if($hdr_is_admin): ?>
                            <li><hr class="dropdown-divider border-secondary border-opacity-25 my-2"></li>
                            <li><h6 class="dropdown-header text-uppercase text-danger fw-bold px-3 py-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">Khu Vực Quản Trị</h6></li>
                            <li>
                                <a class="dropdown-item py-2 px-3 rounded-2 d-flex align-items-center text-danger" href="admin_dashboard.php">
                                    <i class="bi bi-speedometer2 me-2"></i> Bảng điều khiển Admin
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
                    <a href="login.php" class="btn btn-gaming-secondary py-1 px-3" style="font-size: 0.85rem;">Đăng nhập</a>
                    <a href="register.php" class="btn btn-gaming-primary py-1 px-3" style="font-size: 0.85rem;">Đăng ký</a>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <!-- BẮT ĐẦU VÙNG CUỘN NỘI DUNG -->
    <div class="content-scroll">
        
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
                });
            }

            function closeMenu() {
                if (sidebar) sidebar.classList.remove('active');
                if (overlay) overlay.classList.remove('active');
            }

            if (closeBtn) closeBtn.addEventListener('click', closeMenu);
            if (overlay) overlay.addEventListener('click', closeMenu);

            // Phím tắt Ctrl + K để focus ô tìm kiếm
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    const searchInput = document.querySelector('.search-input-box');
                    if (searchInput) searchInput.focus();
                }
            });
        });
    </script>