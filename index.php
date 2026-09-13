<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/header.php'; 
?>

<style>
/* =========================================================================
   GIAO DIỆN CYBERPUNK 2077
========================================================================= */
/* 1. Nhập Font chữ Viễn tưởng từ Google */
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&display=swap');

/* 2. CÀI ĐẶT HÌNH NỀN WEB (BACKGROUND) TÙY CHỈNH */
body {
    background-color: #050505 !important;
    /* Lớp gradient rgba(0,0,0, 0.8) dùng để phủ mờ ảnh nền, giúp chữ nổi lên không bị lóa */
    background-image: 
        linear-gradient(rgba(5, 5, 10, 0.85), rgba(5, 5, 10, 0.95)), 
        url('https://images.unsplash.com/photo-1605806616949-1e87b487cb2a?q=80&w=1920&auto=format&fit=crop') !important; 
    background-size: cover !important;
    background-position: center !important;
    background-attachment: fixed !important; /* Giữ nền đứng im khi cuộn chuột */
    color: #fff;
}

/* 3. Áp dụng font Cyber cho Tiêu đề */
h3, h5, .sidebar-brand span, .game-badge {
    font-family: 'Orbitron', sans-serif !important;
    text-transform: uppercase;
}
h5 { text-shadow: 0 0 5px rgba(255,255,255,0.3); }

/* 4. Lột xác Thẻ Game thành khối kim loại sắc cạnh (Kính mờ) */
.game-card {
    background: rgba(9, 10, 15, 0.6) !important;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid #1f2233 !important;
    border-right: 3px solid #00f3ff !important; /* Viền hông Cyan */
    border-bottom: 3px solid #ff003c !important; /* Viền đáy Đỏ */
    border-radius: 0 !important; /* Xóa mọi góc bo tròn */
    position: relative;
    overflow: hidden;
    transition: all 0.15s ease-out;
}

/* Hiệu ứng tia quét màn hình (Scanline) */
.game-card::after {
    content: "";
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    background: linear-gradient(rgba(0, 243, 255, 0.03) 50%, transparent 50%);
    background-size: 100% 4px;
    pointer-events: none;
    z-index: 5;
}

/* Hiệu ứng Glitch khi trỏ chuột */
.game-card:hover {
    transform: translate(-5px, -5px);
    border-color: #fce205 !important; /* Viền chuyển màu Vàng Cyber */
    box-shadow: 6px 6px 0px rgba(0, 243, 255, 0.8), -6px -6px 15px rgba(255, 0, 60, 0.4) !important;
}

/* Xóa bo tròn ảnh, zoom chéo ảnh khi hover */
.cover-wrapper, .game-cover { border-radius: 0 !important; }
.game-card:hover .game-cover {
    transform: scale(1.1) rotate(1deg);
    filter: contrast(1.2) saturate(1.5);
}

/* Đổi màu chữ tên game khi hover */
.game-card:hover h5 {
    color: #00f3ff !important;
    text-shadow: 2px 2px 0px #ff003c;
}

/* 5. Cắt vát góc Nhãn dán (Badge) chuẩn công nghệ */
.game-badge {
    border-radius: 0 !important;
    border: none !important;
    clip-path: polygon(0 0, 100% 0, 85% 100%, 0 100%);
    padding: 6px 18px 6px 10px !important;
    font-weight: 900 !important;
    letter-spacing: 1px;
    backdrop-filter: none !important;
    opacity: 1 !important;
}
.badge-free { background: rgba(0, 243, 255, 0.9) !important; color: #000 !important; }
.badge-paid { background: rgba(255, 0, 60, 0.9) !important; color: #fff !important; }

/* Huy hiệu con (PC/Mobile) thành khung dây điện */
.badge {
    border-radius: 0 !important;
    font-family: 'Orbitron', sans-serif;
    border: 1px solid currentColor !important;
    background: transparent !important;
    box-shadow: inset 0 0 5px currentColor;
}

/* 6. Bộ Lọc (Select) phong cách Hacker */
.form-select {
    background-color: rgba(0, 0, 0, 0.7) !important;
    backdrop-filter: blur(5px);
    border: 1px solid #00f3ff !important;
    color: #00f3ff !important;
    border-radius: 0 !important;
    font-family: 'Orbitron', sans-serif;
    box-shadow: inset 0 0 8px rgba(0, 243, 255, 0.2) !important;
}
.form-select:hover, .form-select:focus {
    box-shadow: 0 0 15px #00f3ff, inset 0 0 10px #00f3ff !important;
}
</style>

<?php
// =========================================================================
// HỆ THỐNG AUTO-DETECT (TỰ ĐỘNG NHẬN DIỆN NỀN TẢNG PC/MOBILE)
// =========================================================================
$mobile_keywords = ['Free Fire', 'Liên Quân', 'PUBG', 'TFT', 'Teamfight Tactics', 'Genshin', 'Tốc Chiến', 'Mobile'];
$pc_keywords = ['Cyberpunk', 'Red Dead', 'Sekiro', 'Hogwarts', 'EA SPORTS', 'FC 24', 'GTA', 'God of War'];

// Chạy tự động cập nhật Database dựa trên tên game
foreach ($mobile_keywords as $keyword) {
    $conn->query("UPDATE games SET platform = 'Mobile' WHERE title LIKE '%$keyword%' AND platform != 'Mobile'");
}
foreach ($pc_keywords as $keyword) {
    $conn->query("UPDATE games SET platform = 'PC' WHERE title LIKE '%$keyword%' AND platform != 'PC'");
}
// =========================================================================

// 1. KIỂM TRA GAME ĐÃ THUÊ
$borrowed_games = [];
if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $check_borrow_sql = "SELECT game_id FROM borrow_records WHERE user_id = $uid AND status = 'active' AND due_date > NOW()";
    $borrow_res = $conn->query($check_borrow_sql);
    if ($borrow_res && $borrow_res->num_rows > 0) {
        while ($b_row = $borrow_res->fetch_assoc()) { $borrowed_games[] = $b_row['game_id']; }
    }
}

// 2. XỬ LÝ LỌC VÀ SẮP XẾP
$filter_conditions = [];
$category_title = "🔥 Kho Tàng Game Bản Quyền"; 

if (isset($_GET['category']) && !empty($_GET['category'])) {
    $cat_id = intval($_GET['category']);
    $filter_conditions[] = "g.category_id = $cat_id";
    $cat_name_sql = $conn->query("SELECT cate_name FROM categories WHERE category_id = $cat_id");
    if ($cat_name_sql && $cat_name_sql->num_rows > 0) {
        $category_title = "🎮 Thể loại: " . htmlspecialchars($cat_name_sql->fetch_assoc()['cate_name']);
    }
}

if (isset($_GET['platform']) && !empty($_GET['platform'])) {
    $platform = $conn->real_escape_string($_GET['platform']);
    $filter_conditions[] = "g.platform = '$platform'";
    $category_title .= " | Nền tảng: " . htmlspecialchars($platform);
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_text = $conn->real_escape_string($_GET['search']);
    $filter_conditions[] = "g.title LIKE '%$search_text%'";
    $category_title = "🔍 Kết quả tìm kiếm: '" . htmlspecialchars($search_text) . "'";
}

$where_clause = "";
if (count($filter_conditions) > 0) {
    $where_clause = " WHERE " . implode(" AND ", $filter_conditions);
}

$order_by = " ORDER BY g.game_id DESC"; 
if (isset($_GET['sort'])) {
    if ($_GET['sort'] == 'name_asc') { $order_by = " ORDER BY g.title ASC"; }
    elseif ($_GET['sort'] == 'name_desc') { $order_by = " ORDER BY g.title DESC"; }
    elseif ($_GET['sort'] == 'oldest') { $order_by = " ORDER BY g.game_id ASC"; }
}

$sql = "SELECT g.*, c.cate_name FROM games g LEFT JOIN categories c ON g.category_id = c.category_id $where_clause $order_by";
$result = $conn->query($sql);
?>

<!-- THANH CÔNG CỤ LỌC (HỖ TRỢ RESPONSIVE TRÊN ĐIỆN THOẠI) -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 pb-3 border-bottom border-info gap-3">
    <h3 class="fw-bold m-0 text-white fs-5 fs-md-3" style="color: #00f3ff !important; text-shadow: 0 0 10px #00f3ff;"><?php echo $category_title; ?></h3>
    
    <div class="d-flex align-items-center flex-wrap gap-2 gap-md-3 w-100 w-md-auto justify-content-end">
        <form method="GET" action="index.php" class="d-flex align-items-center gap-2">
            <?php if(isset($_GET['category'])): ?><input type="hidden" name="category" value="<?php echo intval($_GET['category']); ?>"><?php endif; ?>
            <span class="text-secondary small text-nowrap d-none d-sm-inline">Nền tảng:</span>
            <select name="platform" class="form-select form-select-sm border-secondary" onchange="this.form.submit()" style="min-width: 100px;">
                <option value="">Tất cả</option>
                <option value="PC" <?php echo (isset($_GET['platform']) && $_GET['platform']=='PC')?'selected':''; ?>>PC</option>
                <option value="Mobile" <?php echo (isset($_GET['platform']) && $_GET['platform']=='Mobile')?'selected':''; ?>>Mobile</option>
            </select>
        </form>

        <form method="GET" action="index.php" class="d-flex align-items-center gap-2">
            <?php if(isset($_GET['platform'])): ?><input type="hidden" name="platform" value="<?php echo htmlspecialchars($_GET['platform']); ?>"><?php endif; ?>
            <span class="text-secondary small text-nowrap d-none d-sm-inline">Sắp xếp:</span>
            <select name="sort" class="form-select form-select-sm border-secondary" onchange="this.form.submit()" style="min-width: 110px;">
                <option value="newest" <?php echo (isset($_GET['sort']) && $_GET['sort']=='newest')?'selected':''; ?>>Mới nhất</option>
                <option value="oldest" <?php echo (isset($_GET['sort']) && $_GET['sort']=='oldest')?'selected':''; ?>>Cũ nhất</option>
                <option value="name_asc" <?php echo (isset($_GET['sort']) && $_GET['sort']=='name_asc')?'selected':''; ?>>A - Z</option>
            </select>
        </form>

        <?php if(!empty($_GET['category']) || !empty($_GET['platform']) || !empty($_GET['sort']) || !empty($_GET['search'])): ?>
            <a href="index.php" class="btn btn-outline-danger btn-sm rounded-0 px-3" style="font-family: 'Orbitron';">Xóa lọc X</a>
        <?php endif; ?>
    </div>
</div>

<!-- LƯỚI GAME -->
<div class="game-grid mb-5">
    <?php 
    if ($result && $result->num_rows > 0): 
        while ($game = $result->fetch_assoc()): 
            
            // XỬ LÝ CHUYỂN HƯỚNG THÔNG MINH
            if ($game['platform'] == 'Mobile') {
                $encoded_title = urlencode($game['title']);
                $target_url = "https://play.google.com/store/search?q={$encoded_title}&c=apps";
                $target_attr = 'target="_blank" rel="noopener noreferrer"'; 
            } else {
                $target_url = "game_detail.php?id=" . $game['game_id'];
                $target_attr = "";
            }
    ?>
        
        <a href="<?php echo $target_url; ?>" <?php echo $target_attr; ?> class="game-card text-decoration-none d-flex flex-column">
            
            <?php if ($game['price_type'] == 'free'): ?>
                <span class="game-badge badge-free position-absolute top-0 start-0 m-2 px-2 py-1 small" style="z-index:10;">Miễn phí</span>
            <?php else: ?>
                <span class="game-badge badge-paid position-absolute top-0 start-0 m-2 px-2 py-1 small" style="z-index:10;">Trả phí</span>
            <?php endif; ?>

            <div class="cover-wrapper w-100" style="height: 180px;">
                <?php if (!empty($game['image_url']) && file_exists($game['image_url'])): ?>
                    <img src="<?php echo $game['image_url']; ?>" class="game-cover w-100 h-100 object-fit-cover" alt="<?php echo htmlspecialchars($game['title']); ?>">
                <?php else: ?>
                    <div class="game-cover d-flex align-items-center justify-content-center text-muted w-100 h-100" style="background: #111;">NO COVER</div>
                <?php endif; ?>
            </div>

            <div class="p-3 d-flex flex-column" style="flex-grow: 1;">
                <h5 class="fw-bold text-white mb-1 fs-6 text-truncate" title="<?php echo htmlspecialchars($game['title']); ?>">
                    <?php echo htmlspecialchars($game['title']); ?>
                </h5>
                <small class="text-secondary mb-3 d-flex align-items-center">
                    <i class="bi bi-tag-fill me-1"></i> <?php echo !empty($game['cate_name']) ? $game['cate_name'] : 'Khác'; ?> 
                    
                    <?php if($game['platform'] == 'Mobile'): ?>
                        <span class="badge ms-auto" style="color: #00f3ff;"><i class="bi bi-phone"></i> Mobile</span>
                    <?php else: ?>
                        <span class="badge ms-auto" style="color: #fce205;"><i class="bi bi-pc-display"></i> PC</span>
                    <?php endif; ?>
                </small>
                
                <div class="d-flex justify-content-between align-items-center mt-auto">
                    <span class="small fw-bold" style="color: #fce205;">⭐ 4.8</span>
                    
                    <?php if ($game['platform'] == 'Mobile'): ?>
                         <span class="badge" style="color: #00f3ff; background: rgba(0,243,255,0.1) !important;"><i class="bi bi-google-play"></i> Tải ngay</span>
                    <?php elseif ($game['price_type'] == 'free'): ?>
                        <span class="badge" style="color: #00f3ff; background: rgba(0,243,255,0.1) !important;">Sẵn sàng</span>
                    <?php else: ?>
                        <?php if (in_array($game['game_id'], $borrowed_games)): ?>
                            <span class="badge" style="color: #00f3ff; background: rgba(0,243,255,0.1) !important;">Đã mở khóa <i class="bi bi-check"></i></span>
                        <?php else: ?>
                            <?php if ($game['available_quantity'] > 0): ?>
                                <span class="badge" style="color: #fce205; background: rgba(252,226,5,0.1) !important;">Còn <?php echo $game['available_quantity']; ?></span>
                            <?php else: ?>
                                <span class="badge" style="color: #ff003c; border-color: #ff003c !important; background: rgba(255,0,60,0.1) !important;">Hết hàng</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </a>

    <?php endwhile; else: ?>
        <div class="col-12 w-100 text-center py-5">
            <i class="bi bi-cpu fs-1 mb-3 d-block" style="color: #ff003c;"></i>
            <h4 style="font-family: 'Orbitron'; color: #00f3ff;">SYSTEM: Không tìm thấy tựa game nào phù hợp.</h4>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>