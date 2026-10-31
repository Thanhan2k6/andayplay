<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/header.php'; 

// KIỂM TRA CÁC GAME NGƯỜI DÙNG ĐANG THUÊ HIỆU LỰC
$rented_games = [];
if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $check_borrow_sql = "SELECT game_id FROM borrow_records WHERE user_id = $uid AND status = 'active' AND due_date > NOW()";
    $borrow_res = $conn->query($check_borrow_sql);
    if ($borrow_res && $borrow_res->num_rows > 0) {
        while ($b_row = $borrow_res->fetch_assoc()) { $rented_games[] = $b_row['game_id']; }
    }
}

// LỌC VÀ TÌM KIẾM DỮ LIỆU
$filter_conditions = [];
$category_title = "TẤT CẢ TỰA GAME CHO THUÊ"; 

if (isset($_GET['stock']) && in_array($_GET['stock'], ['available', 'out'])) {
    $stock_filter = $_GET['stock'];
    if ($stock_filter === 'available') {
        $filter_conditions[] = "(SELECT COUNT(*) FROM game_accounts ga WHERE ga.game_id = g.game_id AND ga.status = 'AVAILABLE') > 0";
        $category_title .= " (Còn Tài Khoản)";
    } else {
        $filter_conditions[] = "(SELECT COUNT(*) FROM game_accounts ga WHERE ga.game_id = g.game_id AND ga.status = 'AVAILABLE') = 0";
        $category_title .= " (Tạm Hết Tài Khoản)";
    }
} elseif (isset($_GET['type']) && in_array($_GET['type'], ['free', 'paid'])) {
    $price_type = $conn->real_escape_string($_GET['type']);
    if ($price_type === 'free') {
        $filter_conditions[] = "1=1";
    } else {
        $filter_conditions[] = "g.price_type = 'paid'";
    }
}

if (isset($_GET['category']) && !empty($_GET['category'])) {
    $cat_id = intval($_GET['category']);
    $filter_conditions[] = "g.category_id = $cat_id";
    $cat_name_sql = $conn->query("SELECT cate_name FROM categories WHERE category_id = $cat_id");
    if ($cat_name_sql && $cat_name_sql->num_rows > 0) {
        $category_title = "Thể Loại: " . htmlspecialchars($cat_name_sql->fetch_assoc()['cate_name']);
    }
}

if (isset($_GET['platform']) && !empty($_GET['platform'])) {
    $platform = $conn->real_escape_string($_GET['platform']);
    $filter_conditions[] = "g.platform = '$platform'";
    $category_title .= " (" . htmlspecialchars($platform) . ")";
}

if (isset($_GET['search']) && trim($_GET['search']) !== '') {
    $search_text = $conn->real_escape_string(trim($_GET['search']));
    $filter_conditions[] = "(g.title LIKE '%$search_text%' OR g.description LIKE '%$search_text%' OR c.cate_name LIKE '%$search_text%' OR g.platform LIKE '%$search_text%')";
    $category_title = "Kết quả tìm kiếm cho: \"" . htmlspecialchars(trim($_GET['search'])) . "\"";
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

$result = false;
$schema_warning = false;
try {
    $sql = "SELECT g.*, c.cate_name,
            (SELECT MIN(price) FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.is_active = 1) as min_rent_price,
            (SELECT COUNT(*) FROM game_accounts ga WHERE ga.game_id = g.game_id AND ga.status = 'AVAILABLE') as real_available_acc
            FROM games g 
            LEFT JOIN categories c ON g.category_id = c.category_id 
            $where_clause $order_by";
    $result = $conn->query($sql);
} catch (Throwable $e) {
    $result = false;
}

if (!$result) {
    $schema_warning = true;
    try {
        $sql_fallback = "SELECT g.*, c.cate_name, 0 as min_rent_price, g.quantity as real_available_acc 
                         FROM games g 
                         LEFT JOIN categories c ON g.category_id = c.category_id 
                         $where_clause $order_by";
        $result = $conn->query($sql_fallback);
    } catch (Throwable $e) {
        $result = false;
    }
}
?>

<?php if ($schema_warning && isset($_SESSION['role']) && $_SESSION['role'] == 1): ?>
    <div class="alert alert-warning py-3 mb-4 rounded-3 shadow border-0" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.4) !important; color: #fde68a;">
        <i class="bi bi-info-circle-fill me-2 fs-5"></i>
        <strong>Lưu ý Quản trị:</strong> CSDL đang hiển thị ở chế độ tương thích dự phòng. Hãy import file <code>database_migration_v2.sql</code> trên phpMyAdmin để kích hoạt tính năng kho tài khoản và bảng giá mới.
    </div>
<?php endif; ?>

<!-- HERO BANNER GAMING STORE CAO CẤP -->
<?php if (!isset($_GET['search']) && !isset($_GET['category']) && !isset($_GET['platform'])): ?>
<section class="gaming-hero">
    <div class="row align-items-center g-4">
        <div class="col-lg-7">
            <div class="hero-tag">
                <i class="bi bi-controller text-primary"></i> DỊCH VỤ THUÊ TÀI KHOẢN GAME BẢN QUYỀN
            </div>
            <h1 class="hero-title">
                CHIẾN GAME BOM TẤN AAA<br>
                <span>GIÁ THUÊ CHỈ TỪ <strong class="text-warning">5.000Đ</strong> / GIỜ</span>
            </h1>
            <p class="hero-desc">
                Trải nghiệm trọn vẹn Black Myth: Wukong, Cyberpunk 2077, Elden Ring, EA FC 24... trên Steam, EA, Epic Games. Cấp tài khoản tự động trong 30 giây, bảo hành 1 đổi 1 suốt thời gian thuê.
            </p>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <a href="#gameCatalog" class="btn btn-gaming-primary px-4 py-3 fs-6">
                    <i class="bi bi-controller fs-5"></i> THUÊ GAME NGAY (TỪ 5.000Đ)
                </a>
                <a href="#rentalGuide" class="btn btn-gaming-secondary px-4 py-3 fs-6">
                    <i class="bi bi-lightning-charge fs-5"></i> QUY TRÌNH 3 BƯỚC
                </a>
            </div>
        </div>
        <div class="col-lg-5 d-none d-lg-block">
            <!-- Hero Stats Card -->
            <div class="p-4 rounded-4 hero-stats-box">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 rounded-3 text-center hero-stat-card hero-stat-price">
                            <div class="fs-2 fw-black hero-stat-val text-warning">5.000đ</div>
                            <span class="small hero-stat-label fw-semibold">Giá thuê từ / giờ</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 text-center hero-stat-card hero-stat-speed">
                            <div class="fs-2 fw-black hero-stat-val text-success">30 Giây</div>
                            <span class="small hero-stat-label fw-semibold">Cấp tài khoản tự động</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 text-center hero-stat-card hero-stat-games">
                            <div class="fs-2 fw-black hero-stat-val text-info">23+ Tựa</div>
                            <span class="small hero-stat-label fw-semibold">Game AAA bản quyền</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 text-center hero-stat-card hero-stat-warranty">
                            <div class="fs-2 fw-black hero-stat-val text-accent-amber">1 Đổi 1</div>
                            <span class="small hero-stat-label fw-semibold">Bảo hành suốt thời gian</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4 Điểm Cam Kết Nổi Bật -->
    <div class="row g-3 mt-4 pt-3 hero-commitments-bar">
        <div class="col-md-3 col-6">
            <div class="d-flex align-items-center gap-2 hero-commit-item">
                <i class="bi bi-currency-dollar text-warning fs-5"></i>
                <span class="small fw-bold hero-commit-text">Tiết kiệm đến 90% chi phí</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="d-flex align-items-center gap-2 hero-commit-item">
                <i class="bi bi-shield-check text-success fs-5"></i>
                <span class="small fw-bold hero-commit-text">Bảo hành 1 đổi 1 nếu lỗi acc</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="d-flex align-items-center gap-2 hero-commit-item">
                <i class="bi bi-qr-code-scan text-info fs-5"></i>
                <span class="small fw-bold hero-commit-text">VietQR quét xong nhận acc</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="d-flex align-items-center gap-2 hero-commit-item">
                <i class="bi bi-headset text-primary fs-5"></i>
                <span class="small fw-bold hero-commit-text">Kỹ thuật viên 24/7 hỗ trợ</span>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- BỘ LỌC VÀ DANH MỤC TRÒ CHƠI -->
<div id="gameCatalog" class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center mb-4 pb-3 catalog-header-bar gap-3">
    <div>
        <h3 class="fw-bold m-0 catalog-title fs-4 d-flex align-items-center gap-2">
            <i class="bi bi-joystick text-primary"></i> <?php echo $category_title; ?>
        </h3>
        <span class="catalog-subtitle small">Lựa chọn tựa game yêu thích và trải nghiệm theo gói thời gian linh hoạt</span>
    </div>

    <!-- Thanh công cụ lọc & sắp xếp -->
    <div class="d-flex align-items-center flex-wrap gap-2 w-100 w-lg-auto justify-content-start justify-content-lg-end">
        <!-- Tìm kiếm Game Trực Tiếp Tại Danh Sách (Search for a Game) -->
        <form method="GET" action="index.php" class="d-flex align-items-center gap-1" id="catalogSearchForm">
            <?php if(isset($_GET['stock'])): ?><input type="hidden" name="stock" value="<?php echo htmlspecialchars($_GET['stock']); ?>"><?php endif; ?>
            <?php if(isset($_GET['category'])): ?><input type="hidden" name="category" value="<?php echo intval($_GET['category']); ?>"><?php endif; ?>
            <?php if(isset($_GET['platform'])): ?><input type="hidden" name="platform" value="<?php echo htmlspecialchars($_GET['platform']); ?>"><?php endif; ?>
            <?php if(isset($_GET['sort'])): ?><input type="hidden" name="sort" value="<?php echo htmlspecialchars($_GET['sort']); ?>"><?php endif; ?>
            <div class="input-group input-group-sm">
                <input type="text" name="search" id="catalogSearchInput" class="form-control form-control-sm border-secondary search-catalog-input" placeholder="Tìm kiếm tựa game..." value="<?php echo isset($_GET['search']) ? htmlspecialchars(trim($_GET['search'])) : ''; ?>" autocomplete="off" style="min-width: 170px;">
                <button type="submit" class="btn btn-sm btn-gaming-primary" id="btnCatalogSearch" title="Nhấn để tìm kiếm tựa game">
                    <i class="bi bi-search me-1"></i> Tìm
                </button>
            </div>
        </form>

        <!-- Lọc Tình trạng Kho -->
        <form method="GET" action="index.php" class="d-flex align-items-center gap-1">
            <?php if(isset($_GET['search'])): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($_GET['search']); ?>"><?php endif; ?>
            <?php if(isset($_GET['category'])): ?><input type="hidden" name="category" value="<?php echo intval($_GET['category']); ?>"><?php endif; ?>
            <?php if(isset($_GET['platform'])): ?><input type="hidden" name="platform" value="<?php echo htmlspecialchars($_GET['platform']); ?>"><?php endif; ?>
            <?php if(isset($_GET['sort'])): ?><input type="hidden" name="sort" value="<?php echo htmlspecialchars($_GET['sort']); ?>"><?php endif; ?>
            <select name="stock" class="form-select form-select-sm py-2 px-3 border-secondary" onchange="this.form.submit()" style="min-width: 145px;">
                <option value="">🎯 Tất cả kho game</option>
                <option value="available" <?php echo (isset($_GET['stock']) && $_GET['stock']=='available')?'selected':''; ?>>🟢 Còn tài khoản sẵn</option>
                <option value="out" <?php echo (isset($_GET['stock']) && $_GET['stock']=='out')?'selected':''; ?>>🔴 Tạm hết tài khoản</option>
            </select>
        </form>

        <!-- Lọc Nền tảng -->
        <form method="GET" action="index.php" class="d-flex align-items-center gap-1">
            <?php if(isset($_GET['search'])): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($_GET['search']); ?>"><?php endif; ?>
            <?php if(isset($_GET['stock'])): ?><input type="hidden" name="stock" value="<?php echo htmlspecialchars($_GET['stock']); ?>"><?php endif; ?>
            <?php if(isset($_GET['category'])): ?><input type="hidden" name="category" value="<?php echo intval($_GET['category']); ?>"><?php endif; ?>
            <?php if(isset($_GET['sort'])): ?><input type="hidden" name="sort" value="<?php echo htmlspecialchars($_GET['sort']); ?>"><?php endif; ?>
            <select name="platform" class="form-select form-select-sm py-2 px-3 border-secondary" onchange="this.form.submit()" style="min-width: 120px;">
                <option value="">Tất cả thiết bị</option>
                <option value="PC" <?php echo (isset($_GET['platform']) && $_GET['platform']=='PC')?'selected':''; ?>>🖥️ Game PC</option>
                <option value="Mobile" <?php echo (isset($_GET['platform']) && $_GET['platform']=='Mobile')?'selected':''; ?>>📱 Mobile</option>
            </select>
        </form>

        <!-- Sắp xếp -->
        <form method="GET" action="index.php" class="d-flex align-items-center gap-1">
            <?php if(isset($_GET['search'])): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($_GET['search']); ?>"><?php endif; ?>
            <?php if(isset($_GET['stock'])): ?><input type="hidden" name="stock" value="<?php echo htmlspecialchars($_GET['stock']); ?>"><?php endif; ?>
            <?php if(isset($_GET['category'])): ?><input type="hidden" name="category" value="<?php echo intval($_GET['category']); ?>"><?php endif; ?>
            <?php if(isset($_GET['platform'])): ?><input type="hidden" name="platform" value="<?php echo htmlspecialchars($_GET['platform']); ?>"><?php endif; ?>
            <select name="sort" class="form-select form-select-sm py-2 px-3 border-secondary" onchange="this.form.submit()" style="min-width: 130px;">
                <option value="newest" <?php echo (isset($_GET['sort']) && $_GET['sort']=='newest')?'selected':''; ?>>Mới cập nhật</option>
                <option value="oldest" <?php echo (isset($_GET['sort']) && $_GET['sort']=='oldest')?'selected':''; ?>>Cũ nhất</option>
                <option value="name_asc" <?php echo (isset($_GET['sort']) && $_GET['sort']=='name_asc')?'selected':''; ?>>Tên: A - Z</option>
            </select>
        </form>

        <?php if(!empty($_GET['type']) || !empty($_GET['category']) || !empty($_GET['platform']) || !empty($_GET['sort']) || !empty($_GET['search'])): ?>
            <a href="index.php" class="btn btn-sm btn-outline-danger px-3 py-2" title="Xóa toàn bộ bộ lọc và từ khóa tìm kiếm">
                <i class="bi bi-x-circle me-1"></i> Xóa Lọc
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- LƯỚI CARD GAME (GAMING MARKETPLACE GRID) -->
<div class="game-grid mb-5">
    <?php 
    if ($result && $result->num_rows > 0): 
        while ($game = $result->fetch_assoc()): 
            $target_url = "game_detail.php?id=" . $game['game_id'];
            $avail_acc = intval($game['real_available_acc'] ?? 0);
            $min_price = floatval($game['min_rent_price'] ?? 5000);
            $is_user_renting = in_array($game['game_id'], $rented_games);
    ?>
        
        <div class="gaming-card">
            <!-- Cover Ảnh & Badge nổi trên ảnh -->
            <a href="<?php echo $target_url; ?>" class="gaming-card-cover-wrapper text-decoration-none">
                <?php if (!empty($game['image_url']) && file_exists($game['image_url'])): ?>
                    <img src="<?php echo $game['image_url']; ?>" class="gaming-card-cover" alt="<?php echo htmlspecialchars($game['title']); ?>" loading="lazy">
                <?php else: ?>
                    <div class="gaming-card-cover d-flex align-items-center justify-content-center text-muted" style="background: #11141e;">
                        <i class="bi bi-controller fs-1 text-secondary"></i>
                    </div>
                <?php endif; ?>
                
                <div class="gaming-card-overlay"></div>

                <!-- Tag Platform góc trên bên trái -->
                <div class="position-absolute top-0 start-0 m-3 d-flex gap-1" style="z-index: 5;">
                    <span class="badge-platform">
                        <?php echo ($game['platform'] == 'PC') ? '<i class="bi bi-display me-1"></i>PC' : '<i class="bi bi-phone me-1"></i>Mobile'; ?>
                    </span>
                    <span class="badge-gaming badge-gaming-paid">Cho Thuê</span>
                </div>

                <!-- Tình trạng kho góc trên bên phải -->
                <div class="position-absolute top-0 end-0 m-3" style="z-index: 5;">
                    <?php if ($is_user_renting): ?>
                        <span class="badge-gaming badge-gaming-avail"><i class="bi bi-check-circle-fill"></i> Đang Thuê</span>
                    <?php elseif ($avail_acc > 0): ?>
                        <span class="badge-gaming badge-gaming-avail"><i class="bi bi-dot fs-5"></i>Còn <?php echo $avail_acc; ?> TK</span>
                    <?php else: ?>
                        <span class="badge-gaming badge-gaming-out"><i class="bi bi-x-circle-fill"></i>Hết TK</span>
                    <?php endif; ?>
                </div>
            </a>

            <!-- Nội dung Card -->
            <div class="gaming-card-body">
                <a href="<?php echo $target_url; ?>" class="text-decoration-none">
                    <h5 class="gaming-card-title" title="<?php echo htmlspecialchars($game['title']); ?>">
                        <?php echo htmlspecialchars($game['title']); ?>
                    </h5>
                </a>

                <div class="gaming-card-meta">
                    <span><i class="bi bi-tag-fill me-1 text-primary"></i> <?php echo !empty($game['cate_name']) ? htmlspecialchars($game['cate_name']) : 'Khác'; ?></span>
                    <?php if (isset($game['views']) && $game['views'] > 0): ?>
                        <span class="ms-auto"><i class="bi bi-eye me-1"></i> <?php echo number_format($game['views']); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Phần Giá & Nút hành động đáy card -->
                <div class="gaming-card-footer">
                    <div>
                        <?php if ($min_price > 0): ?>
                            <span class="gaming-card-price-label">Giá thuê từ</span>
                            <span class="gaming-card-price-val text-warning fw-bold"><?php echo number_format($min_price, 0, ',', '.'); ?>đ<small class="text-secondary fw-normal ms-1" style="font-size: 0.72rem; -webkit-text-fill-color: #94a3b8;">/giờ</small></span>
                        <?php else: ?>
                            <span class="gaming-card-price-label">Giá thuê</span>
                            <span class="badge bg-warning bg-opacity-20 text-warning" style="font-size: 0.75rem;">Chưa cấu hình</span>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if ($is_user_renting): ?>
                            <a href="my_library.php" class="btn btn-sm btn-success fw-bold py-1 px-3">
                                <i class="bi bi-play-fill me-1"></i> Vào Chơi
                            </a>
                        <?php elseif ($avail_acc > 0 && $min_price > 0): ?>
                            <a href="borrow_process.php?game_id=<?php echo $game['game_id']; ?>" class="btn btn-sm btn-gaming-primary py-1 px-3">
                                <i class="bi bi-lightning-charge-fill me-1"></i> Thuê Ngay
                            </a>
                        <?php elseif ($avail_acc == 0): ?>
                            <a href="<?php echo $target_url; ?>" class="btn btn-sm btn-outline-danger py-1 px-2 text-nowrap" style="font-size: 0.78rem;">
                                Hết TK (Xem)
                            </a>
                        <?php else: ?>
                            <a href="<?php echo $target_url; ?>" class="btn btn-sm btn-gaming-secondary py-1 px-3 text-muted">Xem Chi Tiết</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php endwhile; else: ?>
        <div class="col-12 text-center py-5">
            <div class="panel-dark p-5">
                <i class="bi bi-search fs-1 mb-3 text-secondary d-block"></i>
                <h4 class="text-white fw-bold mb-2">Không tìm thấy tựa game nào phù hợp!</h4>
                <p class="text-secondary mb-4">Vui lòng thử lại với từ khóa khác hoặc xóa bộ lọc để hiển thị toàn bộ game.</p>
                <a href="index.php" class="btn btn-gaming-primary px-4 py-2">Xem Tất Cả Trò Chơi</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- SECTION: 3 BƯỚC THUÊ GAME NHANH CHÓNG -->
<section id="rentalGuide" class="mb-5 pt-4">
    <div class="text-center mb-4 pb-2">
        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2 text-uppercase fw-bold" style="font-size: 0.75rem;">Quy trình đơn giản</span>
        <h3 class="fw-bold section-main-title mt-2 mb-1">Thuê Tài Khoản Chỉ Với 3 Bước</h3>
        <p class="section-sub-title small">Hệ thống cấp phát hoàn toàn tự động, nhận tài khoản game ngay sau khi quét mã QR</p>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="panel-dark h-100 text-center p-4">
                <div class="brand-icon-box mx-auto mb-3" style="width: 52px; height: 52px; font-size: 1.5rem;">
                    1
                </div>
                <h5 class="fw-bold step-title mb-2">Chọn Game & Gói Giờ</h5>
                <p class="text-secondary small mb-0">Tìm tựa game bạn muốn chiến và chọn thời hạn phù hợp: 1 Giờ, 1 Ngày, 7 Ngày hoặc 1 Tháng.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel-dark h-100 text-center p-4">
                <div class="brand-icon-box mx-auto mb-3" style="width: 52px; height: 52px; font-size: 1.5rem; background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%);">
                    2
                </div>
                <h5 class="fw-bold step-title mb-2">Quét Mã VietQR</h5>
                <p class="text-secondary small mb-0">Mở app ngân hàng quét mã QR thanh toán với số tiền và nội dung chính xác. Máy chủ xác nhận tức thì.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel-dark h-100 text-center p-4">
                <div class="brand-icon-box mx-auto mb-3" style="width: 52px; height: 52px; font-size: 1.5rem; background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                    3
                </div>
                <h5 class="fw-bold step-title mb-2">Nhận Tài Khoản & Chiến</h5>
                <p class="text-secondary small mb-0">Thông tin đăng nhập hiển thị ngay trong <strong>Thư viện của tôi</strong>. Đăng nhập Steam/Epic và thưởng thức game!</p>
            </div>
        </div>
    </div>
</section>

<!-- SECTION: CÂU HỎI THƯỜNG GẶP (FAQ) -->
<section class="mb-5">
    <div class="panel-dark p-4 p-md-5">
        <div class="row align-items-center g-4">
            <div class="col-lg-5">
                <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-3 py-2 text-uppercase fw-bold" style="font-size: 0.75rem;">Hỗ trợ khách hàng</span>
                <h3 class="fw-bold text-white mt-2 mb-3">Câu Hỏi Thường Gặp</h3>
                <p class="text-secondary">Những thắc mắc phổ biến nhất khi khách hàng trải nghiệm dịch vụ thuê tài khoản game tại ANPLAY STORE.</p>
                <div class="d-flex align-items-center gap-2 text-info mt-3">
                    <i class="bi bi-headset fs-4"></i>
                    <span class="fw-bold">Cần hỗ trợ riêng? Liên hệ kỹ thuật viên 24/7</span>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="accordion accordion-flush" id="faqAccordion">
                    <!-- FAQ 1: Lưu Game & Chơi Tiếp -->
                    <div class="accordion-item bg-transparent border-secondary border-opacity-25 mb-3" style="border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color);">
                        <h2 class="accordion-header" id="headingFaq1">
                            <button class="accordion-button collapsed bg-transparent fw-bold shadow-none py-3 px-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq1" aria-expanded="false" aria-controls="faq1" style="color: var(--text-heading) !important; font-size: 1rem;">
                                <i class="bi bi-cloud-arrow-down text-info me-2 fs-5"></i> 🎮 Tôi có thể lưu game (Save Game) và chơi tiếp không?
                            </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse" aria-labelledby="headingFaq1" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-secondary small pt-1 pb-3 px-3">
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                                        <i class="bi bi-check-circle-fill me-1"></i> 100% Game Hỗ Trợ Save Tiến Trình
                                    </span>
                                    <span class="badge" style="background: rgba(99, 102, 241, 0.15); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.3);">
                                        <i class="bi bi-steam me-1"></i> Steam Cloud & Local AppData
                                    </span>
                                </div>
                                <p class="mb-2 text-light" style="line-height: 1.6;">
                                    <strong>Hoàn toàn được!</strong> Các tựa game bản quyền trên Steam, EA, Epic Games đều tự động kích hoạt tính năng lưu tiến trình màn chơi:
                                </p>
                                <ul class="mb-3 ps-3 text-secondary" style="line-height: 1.7;">
                                    <li><strong>Đồng bộ đám mây (Cloud Save):</strong> Tiến trình game được máy chủ bản quyền lưu tự động gắn liền với tài khoản game bạn thuê.</li>
                                    <li><strong>Lưu cục bộ an toàn:</strong> Bản sao lưu file save được lưu ngay trên máy tính của bạn (tại <code>%USERPROFILE%\Documents</code> hoặc <code>Saved Games</code>).</li>
                                    <li><strong>Tiếp tục chơi sau khi gia hạn hoặc thuê lại:</strong> Khi hết giờ, bạn chỉ cần bấm <strong>"Gia hạn"</strong> hoặc chọn <strong>"Thuê lại tài khoản cũ (Giữ Save)"</strong> tại Thư viện, toàn bộ cấp độ, vật phẩm và màn chơi của bạn được giữ trọn vẹn 100%!</li>
                                </ul>
                                <div class="p-2 rounded-3 mb-2" style="background: rgba(255,255,255,0.03); border: 1px dashed var(--border-color); font-size: 0.8rem;">
                                    <i class="bi bi-info-circle-fill text-info me-1"></i> <em>Lưu ý kỹ thuật:</em> Do game chạy qua Client chính thức trên máy tính của bạn, ANPLAY STORE không can thiệp vào bộ nhớ phần cứng cá nhân nhằm đảm bảo tính bảo mật và riêng tư cao nhất.
                                </div>
                                <a href="my_library.php" class="btn btn-sm btn-gaming-secondary py-1 px-3 text-decoration-none">
                                    <i class="bi bi-controller me-1 text-primary"></i> Đến Thư Viện Của Tôi Kiểm Tra Tiến Trình
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 2: Quyền Đổi Mật Khẩu -->
                    <div class="accordion-item bg-transparent border-secondary border-opacity-25 mb-3" style="border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color);">
                        <h2 class="accordion-header" id="headingFaq2">
                            <button class="accordion-button collapsed bg-transparent fw-bold shadow-none py-3 px-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq2" aria-expanded="false" aria-controls="faq2" style="color: var(--text-heading) !important; font-size: 1rem;">
                                <i class="bi bi-shield-lock text-warning me-2 fs-5"></i> 🔒 Tôi có được phép đổi mật khẩu tài khoản game không?
                            </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" aria-labelledby="headingFaq2" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-secondary small pt-1 pb-3 px-3">
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3);">
                                        <i class="bi bi-slash-circle-fill me-1"></i> Chính Sách: Nghiêm Cấm Tự Ý Đổi Mật Khẩu
                                    </span>
                                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                                        <i class="bi bi-shield-check me-1"></i> Bảo Hành Đổi Trả 1 Đổi 1
                                    </span>
                                </div>
                                <p class="mb-2 text-light" style="line-height: 1.6;">
                                    <strong class="text-danger">Tuyệt đối không!</strong> Tài khoản game thuộc quyền sở hữu bản quyền của hệ thống ANPLAY STORE được cung cấp theo hình thức cho thuê thời hạn:
                                </p>
                                <ul class="mb-3 ps-3 text-secondary" style="line-height: 1.7;">
                                    <li><strong>Lý do an toàn hệ thống:</strong> Việc tự ý thay đổi mật khẩu, đổi email đăng ký hoặc kích hoạt bảo mật gia đình sẽ vi phạm nghiêm trọng Điều khoản dịch vụ và kích hoạt cơ chế khóa tài khoản vĩnh viễn.</li>
                                    <li><strong>Quy trình khi gặp lỗi mật khẩu:</strong> Nếu bạn đăng nhập gặp thông báo sai mật khẩu hoặc vướng mã xác thực Steam Guard, hãy nhấn nút <strong>[Báo lỗi tài khoản]</strong> ngay trên giao diện Thư viện. Kỹ thuật viên ANPLAY STORE sẽ kiểm tra và cấp mật khẩu mới hoặc đổi tài khoản 1 đổi 1 ngay lập tức.</li>
                                    <li><strong>Phân biệt mật khẩu:</strong> Bạn có toàn quyền tự do đổi mật khẩu tài khoản website ANPLAY STORE của mình tại trang <em>Hồ sơ & Bảo mật</em>, nhưng không được can thiệp vào tài khoản game được cấp.</li>
                                </ul>
                                <a href="support.php" class="btn btn-sm btn-outline-danger py-1 px-3 text-decoration-none">
                                    <i class="bi bi-headset me-1"></i> Yêu Cầu Kỹ Thuật Viên Hỗ Trợ Đổi Tài Khoản
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 3: Hết Giờ Thuê -->
                    <div class="accordion-item bg-transparent border-secondary border-opacity-25" style="border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color);">
                        <h2 class="accordion-header" id="headingFaq3">
                            <button class="accordion-button collapsed bg-transparent fw-bold shadow-none py-3 px-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq3" aria-expanded="false" aria-controls="faq3" style="color: var(--text-heading) !important; font-size: 1rem;">
                                <i class="bi bi-clock-history text-success me-2 fs-5"></i> ⏱️ Khi hết giờ thuê, chuyện gì sẽ xảy ra?
                            </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" aria-labelledby="headingFaq3" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-secondary small pt-1 pb-3 px-3">
                                <p class="mb-2 text-light" style="line-height: 1.6;">
                                    Khi hết thời hạn thuê, hệ thống tự động khóa hiển thị mật khẩu trên website để hoàn tất phiên thuê:
                                </p>
                                <ul class="mb-3 ps-3 text-secondary" style="line-height: 1.7;">
                                    <li>Bạn sẽ nhận được thông báo trước <strong>24 giờ, 1 giờ và 15 phút</strong> để chủ động gia hạn.</li>
                                    <li>Nếu chưa hoàn thành game, bạn chỉ cần bấm nút <strong>"Gia hạn"</strong> trước khi hết giờ hoặc bấm <strong>"Thuê lại tài khoản cũ"</strong> bất kỳ lúc nào để giữ nguyên toàn bộ file Save.</li>
                                </ul>
                                <a href="my_library.php" class="btn btn-sm btn-gaming-secondary py-1 px-3 text-decoration-none">
                                    <i class="bi bi-arrow-repeat me-1 text-info"></i> Xem Lịch Sử & Gia Hạn Đơn Thuê
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- SCRIPT BẢO ĐẢM TƯƠNG TÁC CLICK FAQ ACCORDION HOẠT ĐỘNG 100% -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const faqButtons = document.querySelectorAll('#faqAccordion .accordion-button');
    faqButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            const targetSelector = this.getAttribute('data-bs-target');
            if (!targetSelector) return;
            const targetEl = document.querySelector(targetSelector);
            if (!targetEl) return;

            // Nếu bootstrap JS chưa bắt event hoặc bị trễ, kích hoạt thủ công
            const isShown = targetEl.classList.contains('show');
            document.querySelectorAll('#faqAccordion .accordion-collapse').forEach(function(col) {
                if (col !== targetEl) {
                    col.classList.remove('show');
                    const otherBtn = document.querySelector(`[data-bs-target="#${col.id}"]`);
                    if (otherBtn) {
                        otherBtn.classList.add('collapsed');
                        otherBtn.setAttribute('aria-expanded', 'false');
                    }
                }
            });

            if (isShown) {
                targetEl.classList.remove('show');
                this.classList.add('collapsed');
                this.setAttribute('aria-expanded', 'false');
            } else {
                targetEl.classList.add('show');
                this.classList.remove('collapsed');
                this.setAttribute('aria-expanded', 'true');
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>