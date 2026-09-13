<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/header.php'; 
?>

<style>
/* Hiệu ứng mượt mà cho thanh công cụ lọc */
.form-select {
    background-color: #1a1c23 !important;
    border: 1px solid #2d303e !important;
    transition: all 0.3s ease;
}
.form-select:hover, .form-select:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 12px rgba(59, 130, 246, 0.4) !important;
}

/* Hiệu ứng Nổi bật (Hover) cho Thẻ Game chuẩn Gaming */
.game-card {
    background: linear-gradient(180deg, #1e202c 0%, #13141c 100%);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.game-card:hover {
    transform: translateY(-8px); /* Nhảy thẻ lên nhẹ nhàng */
    border-color: rgba(59, 130, 246, 0.6); /* Viền sáng Neon Xanh */
    box-shadow: 0 15px 30px rgba(0, 0, 0, 0.6), 0 0 20px rgba(59, 130, 246, 0.3);
}

/* Hiệu ứng Zoom ảnh bìa bên trong thẻ */
.cover-wrapper {
    overflow: hidden; /* Ngăn ảnh tràn ra ngoài góc bo tròn */
}
.game-cover {
    transition: transform 0.5s ease;
}
.game-card:hover .game-cover {
    transform: scale(1.1); /* Phóng to ảnh khi trỏ chuột */
}

/* Nhãn dán kính mờ (Glassmorphism) */
.game-badge {
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 4px 15px rgba(0,0,0,0.5);
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
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 pb-3 border-bottom border-secondary gap-3">
    <h3 class="fw-bold m-0 text-white fs-5 fs-md-3"><?php echo $category_title; ?></h3>
    
    <div class="d-flex align-items-center flex-wrap gap-2 gap-md-3 w-100 w-md-auto justify-content-end">
        <form method="GET" action="index.php" class="d-flex align-items-center gap-2">
            <?php if(isset($_GET['category'])): ?><input type="hidden" name="category" value="<?php echo intval($_GET['category']); ?>"><?php endif; ?>
            <span class="text-secondary small text-nowrap d-none d-sm-inline">Nền tảng:</span>
            <select name="platform" class="form-select form-select-sm text-white border-secondary" onchange="this.form.submit()" style="min-width: 100px;">
                <option value="">Tất cả</option>
                <option value="PC" <?php echo (isset($_GET['platform']) && $_GET['platform']=='PC')?'selected':''; ?>>PC</option>
                <option value="Mobile" <?php echo (isset($_GET['platform']) && $_GET['platform']=='Mobile')?'selected':''; ?>>Mobile</option>
            </select>
        </form>

        <form method="GET" action="index.php" class="d-flex align-items-center gap-2">
            <?php if(isset($_GET['platform'])): ?><input type="hidden" name="platform" value="<?php echo htmlspecialchars($_GET['platform']); ?>"><?php endif; ?>
            <span class="text-secondary small text-nowrap d-none d-sm-inline">Sắp xếp:</span>
            <select name="sort" class="form-select form-select-sm text-white border-secondary" onchange="this.form.submit()" style="min-width: 110px;">
                <option value="newest" <?php echo (isset($_GET['sort']) && $_GET['sort']=='newest')?'selected':''; ?>>Mới nhất</option>
                <option value="oldest" <?php echo (isset($_GET['sort']) && $_GET['sort']=='oldest')?'selected':''; ?>>Cũ nhất</option>
                <option value="name_asc" <?php echo (isset($_GET['sort']) && $_GET['sort']=='name_asc')?'selected':''; ?>>A - Z</option>
            </select>
        </form>

        <?php if(!empty($_GET['category']) || !empty($_GET['platform']) || !empty($_GET['sort']) || !empty($_GET['search'])): ?>
            <a href="index.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">Xóa lọc X</a>
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
                // Tạo link tìm kiếm thẳng trên Play Store
                $target_url = "https://play.google.com/store/search?q={$encoded_title}&c=apps";
                $target_attr = 'target="_blank" rel="noopener noreferrer"'; 
            } else {
                // PC Game thì vào trang chi tiết để thuê/tải
                $target_url = "game_detail.php?id=" . $game['game_id'];
                $target_attr = "";
            }
    ?>
        
        <a href="<?php echo $target_url; ?>" <?php echo $target_attr; ?> class="game-card shadow text-decoration-none d-flex flex-column">
            
            <?php if ($game['price_type'] == 'free'): ?>
                <span class="game-badge position-absolute top-0 start-0 m-2 rounded px-2 py-1 small fw-bold text-white" style="z-index:10; background: rgba(59, 130, 246, 0.85);">Miễn phí</span>
            <?php else: ?>
                <span class="game-badge position-absolute top-0 start-0 m-2 rounded px-2 py-1 small fw-bold text-white" style="z-index:10; background: rgba(239, 68, 68, 0.85);">Trả phí</span>
            <?php endif; ?>

            <!-- Bọc ảnh vào thẻ cover-wrapper để bo tròn mượt và không bị tràn viền khi zoom -->
            <div class="cover-wrapper w-100" style="height: 180px;">
                <?php if (!empty($game['image_url']) && file_exists($game['image_url'])): ?>
                    <img src="<?php echo $game['image_url']; ?>" class="game-cover w-100 h-100 object-fit-cover" alt="<?php echo htmlspecialchars($game['title']); ?>">
                <?php else: ?>
                    <div class="game-cover d-flex align-items-center justify-content-center text-muted w-100 h-100" style="background: #111;">NO COVER</div>
                <?php endif; ?>
            </div>

            <!-- Khối nội dung bên dưới -->
            <div class="p-3 d-flex flex-column" style="flex-grow: 1;">
                <h5 class="fw-bold text-white mb-1 fs-6 text-truncate" title="<?php echo htmlspecialchars($game['title']); ?>">
                    <?php echo htmlspecialchars($game['title']); ?>
                </h5>
                <small class="text-secondary mb-3 d-flex align-items-center">
                    <i class="bi bi-tag-fill me-1"></i> <?php echo !empty($game['cate_name']) ? $game['cate_name'] : 'Khác'; ?> 
                    
                    <?php if($game['platform'] == 'Mobile'): ?>
                        <span class="badge ms-auto rounded-pill" style="background: #059669;"><i class="bi bi-phone"></i> Mobile</span>
                    <?php else: ?>
                        <span class="badge ms-auto rounded-pill" style="background: #2563eb;"><i class="bi bi-pc-display"></i> PC</span>
                    <?php endif; ?>
                </small>
                
                <div class="d-flex justify-content-between align-items-center mt-auto">
                    <span class="text-warning small fw-bold">⭐ 4.8</span>
                    
                    <?php if ($game['platform'] == 'Mobile'): ?>
                         <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);"><i class="bi bi-google-play"></i> Tải ngay</span>
                    <?php elseif ($game['price_type'] == 'free'): ?>
                        <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3);">Sẵn sàng</span>
                    <?php else: ?>
                        <?php if (in_array($game['game_id'], $borrowed_games)): ?>
                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);">Đã mở khóa <i class="bi bi-check"></i></span>
                        <?php else: ?>
                            <?php if ($game['available_quantity'] > 0): ?>
                                <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3);">Còn <?php echo $game['available_quantity']; ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger text-white">Hết hàng</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </a>

    <?php endwhile; else: ?>
        <div class="col-12 w-100 text-center py-5">
            <i class="bi bi-emoji-frown fs-1 text-secondary mb-3 d-block"></i>
            <h4 class="text-secondary">Oop! Không tìm thấy tựa game nào phù hợp.</h4>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>