<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Kết nối Database cùng thư mục includes
require_once __DIR__ . '/connect.php'; 
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <!-- CẤU HÌNH QUAN TRỌNG ĐỂ RESPONSIVE DI ĐỘNG -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ANPLAY STORE</title>
    
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <!-- ĐƯỜNG DẪN CSS CHUẨN CỦA BẠN (Cộng thêm thẻ time() chống lưu cache) -->
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">

    <style>
        /* =========================================================
           CSS TỐI ƯU HÓA GIAO DIỆN DI ĐỘNG (Dưới 992px - Mobile & Tablet)
        ========================================================= */
        @media (max-width: 991.98px) {
            /* 1. Xử lý Sidebar Trượt (Offcanvas) */
            .sidebar {
                position: fixed !important;
                top: 0;
                left: -280px; /* Ẩn ra ngoài màn hình */
                height: 100vh;
                width: 280px;
                z-index: 1045; /* Đè lên mọi thứ */
                transition: left 0.3s ease-in-out;
                display: flex !important; /* Ghi đè class d-none d-lg-flex của Bootstrap */
                flex-direction: column;
                background-color: #111827; /* Màu nền sidebar */
                padding: 20px;
                overflow-y: auto;
                box-shadow: 5px 0 15px rgba(0,0,0,0.5);
            }

            /* Class được Javascript thêm vào khi bấm nút mở Menu */
            .sidebar.active {
                left: 0;
            }

            /* 2. Mở rộng khung nội dung chính tràn màn hình */
            .main-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
            }

            /* 3. Tối ưu Topbar và Nút tìm kiếm */
            .topbar {
                justify-content: space-between;
                padding: 10px 15px;
            }
            .search-container {
                display: none !important; /* Có thể ẩn thanh tìm kiếm dài trên đt hoặc tạo nút thu gọn */
            }

            /* 4. Điều chỉnh Card Game và Nút bấm */
            .card { margin-bottom: 20px; }
            img.card-img-top { height: 180px !important; }
            .btn { width: 100%; display: block; margin-bottom: 10px; }
            .btn-outline-danger { margin-top: 10px; }
            
            h1, h2 { font-size: 1.5rem !important; }
            
            /* Lớp phủ đen màn hình khi mở Menu Mobile */
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.7);
                z-index: 1040;
            }
            .sidebar-overlay.active { display: block; }
        }
    </style>
</head>
<body>

<!-- LỚP PHỦ ĐEN (Tự động hiện khi mở Menu trên Điện thoại) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR BÊN TRÁI -->
<aside class="sidebar" id="mobileSidebar">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <a href="index.php" class="sidebar-brand text-decoration-none">
            <i class="bi bi-controller text-info me-2 fs-2"></i> <span class="text-white fs-4 fw-bold">ANPLAY</span>
        </a>
        <!-- Nút đóng Menu trên Mobile -->
        <button class="btn btn-dark d-lg-none" id="closeSidebarBtn"><i class="bi bi-x-lg"></i></button>
    </div>
    
    <div class="sidebar-title text-secondary small fw-bold mb-2">Menu Chính</div>
    <a href="index.php" class="sidebar-item d-block text-light py-2 text-decoration-none hover-item"><i class="bi bi-house-door-fill text-primary me-2"></i> Trang chủ</a>
    
    <?php if(isset($_SESSION['user_id'])): ?>
        <a href="my_library.php" class="sidebar-item d-block text-light py-2 text-decoration-none hover-item"><i class="bi bi-collection-play-fill text-success me-2"></i> Thư viện của tôi</a>
        
        <?php if($_SESSION['role'] == 1): ?>
            <div class="sidebar-title text-danger small fw-bold mt-4 mb-2">Quản Trị Admin</div>
            <a href="admin_dashboard.php" class="sidebar-item d-block text-light py-2 text-decoration-none hover-item"><i class="bi bi-graph-up-arrow text-danger me-2"></i> Thống kê</a>
            <a href="admin_games.php" class="sidebar-item d-block text-light py-2 text-decoration-none hover-item"><i class="bi bi-box-seam text-danger me-2"></i> Kho Game</a>
            <a href="admin_borrow.php" class="sidebar-item d-block text-light py-2 text-decoration-none hover-item"><i class="bi bi-card-checklist text-danger me-2"></i> Đơn mượn</a>
            <a href="admin_users.php" class="sidebar-item d-block text-light py-2 text-decoration-none hover-item"><i class="bi bi-people-fill text-danger me-2"></i> Quản lý Thành viên</a>
        <?php endif; ?>
    <?php endif; ?>

    <div class="sidebar-title text-secondary small fw-bold mt-4 mb-2">Thể Loại Game</div>
    <?php
    if (isset($conn)) {
        $cate_query = $conn->query("SELECT * FROM categories");
        if ($cate_query && $cate_query->num_rows > 0) {
            $icons = ['bi-crosshair', 'bi-puzzle', 'bi-car-front', 'bi-shield-sword', 'bi-lightning'];
            $i = 0;
            while ($c = $cate_query->fetch_assoc()) {
                $icon = $icons[$i % count($icons)];
                echo '<a href="index.php?category='.$c['category_id'].'" class="sidebar-item d-block text-light py-2 text-decoration-none hover-item"><i class="bi '.$icon.' text-secondary me-2"></i> ' . htmlspecialchars($c['cate_name']) . '</a>';
                $i++;
            }
        }
    }
    ?>
</aside>

<!-- KHUNG LÀM VIỆC CHÍNH BÊN PHẢI -->
<div class="main-wrapper">
    <!-- TOPBAR -->
    <header class="topbar d-flex align-items-center bg-dark p-3 shadow-sm border-bottom border-secondary border-opacity-25">
        
        <!-- NÚT MỞ MENU TRÊN MOBILE (Thay thế d-none d-lg-flex cũ) -->
        <button class="btn text-white d-lg-none me-3" id="openSidebarBtn"><i class="bi bi-list fs-2"></i></button>

        <!-- Form tìm kiếm ẩn trên mobile -->
        <form action="index.php" method="GET" class="search-container d-none d-md-flex align-items-center bg-secondary bg-opacity-25 rounded-pill px-3 py-1 me-auto" style="width: 300px;">
            <i class="bi bi-search text-secondary me-2"></i>
            <input type="text" name="search" class="form-control bg-transparent border-0 text-white shadow-none" placeholder="Tìm kiếm trò chơi..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
        </form>

        <div class="d-flex align-items-center gap-3 ms-auto">
            <a href="#" class="btn btn-dark rounded-circle border-0 d-none d-sm-block" style="background: #1c1c28;"><i class="bi bi-bookmark text-white"></i></a>
            <a href="#" class="btn btn-dark rounded-circle border-0 d-none d-sm-block" style="background: #1c1c28;"><i class="bi bi-bell text-white"></i></a>
            
            <?php if(isset($_SESSION['user_id'])): ?>
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['username']); ?>&background=6366f1&color=fff&rounded=true" width="38" height="38" class="me-2 shadow-sm rounded-circle">
                        <!-- Ẩn tên user trên mobile cho gọn -->
                        <span class="d-none d-md-inline fw-bold">@<?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow border-secondary mt-2">
                        <li><h6 class="dropdown-header text-info d-md-none">@<?php echo htmlspecialchars($_SESSION['username']); ?></h6></li>
                        <li><a class="dropdown-item py-2" href="my_library.php"><i class="bi bi-collection-play me-2"></i>Thư viện Game</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger py-2" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Đăng xuất</a></li>
                    </ul>
                </div>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold">Đăng nhập</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- BẮT ĐẦU VÙNG CUỘN NỘI DUNG -->
    <div class="content-scroll p-3 p-md-4">
        
    <!-- JAVASCRIPT XỬ LÝ MENU TRƯỢT TRÊN ĐIỆN THOẠI -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const openBtn = document.getElementById('openSidebarBtn');
            const closeBtn = document.getElementById('closeSidebarBtn');
            const sidebar = document.getElementById('mobileSidebar');
            const overlay = document.getElementById('sidebarOverlay');

            // Mở Menu
            openBtn.addEventListener('click', function() {
                sidebar.classList.add('active');
                overlay.classList.add('active');
            });

            // Đóng Menu bằng nút X hoặc click vào màn tối
            function closeMenu() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }

            closeBtn.addEventListener('click', closeMenu);
            overlay.addEventListener('click', closeMenu);
        });
    </script>