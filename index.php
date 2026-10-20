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

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_text = $conn->real_escape_string($_GET['search']);
    $filter_conditions[] = "g.title LIKE '%$search_text%'";
    $category_title = "Kết quả tìm kiếm cho: \"" . htmlspecialchars($search_text) . "\"";
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
            <div class="p-4 rounded-4" style="background: rgba(15, 18, 29, 0.75); border: 1px solid rgba(255,255,255,0.1); backdrop-filter: blur(12px);">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 rounded-3 text-center" style="background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.25);">
                            <div class="fs-2 fw-black text-warning">5.000đ</div>
                            <span class="small text-secondary fw-semibold">Giá thuê từ / giờ</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 text-center" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.25);">
                            <div class="fs-2 fw-black text-success">30 Giây</div>
                            <span class="small text-secondary fw-semibold">Cấp tài khoản tự động</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 text-center" style="background: rgba(6,182,212,0.1); border: 1px solid rgba(6,182,212,0.25);">
                            <div class="fs-2 fw-black text-info">23+ Tựa</div>
                            <span class="small text-secondary fw-semibold">Game AAA bản quyền</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 text-center" style="background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.25);">
                            <div class="fs-2 fw-black text-white">1 Đổi 1</div>
                            <span class="small text-secondary fw-semibold">Bảo hành suốt thời gian</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4 Điểm Cam Kết Nổi Bật -->
    <div class="row g-3 mt-4 pt-3 border-top border-white border-opacity-10">
        <div class="col-md-3 col-6">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-currency-dollar text-warning fs-5"></i>
                <span class="small fw-bold text-light">Tiết kiệm đến 90% chi phí</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-shield-check text-success fs-5"></i>
                <span class="small fw-bold text-light">Bảo hành 1 đổi 1 nếu lỗi acc</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-qr-code-scan text-info fs-5"></i>
                <span class="small fw-bold text-light">VietQR quét xong nhận acc</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-headset text-primary fs-5"></i>
                <span class="small fw-bold text-light">Kỹ thuật viên 24/7 hỗ trợ</span>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- BỘ LỌC VÀ DANH MỤC TRÒ CHƠI -->
<div id="gameCatalog" class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
    <div>
        <h3 class="fw-bold m-0 text-white fs-4 d-flex align-items-center gap-2">
            <i class="bi bi-joystick text-primary"></i> <?php echo $category_title; ?>
        </h3>
        <span class="text-secondary small">Lựa chọn tựa game yêu thích và trải nghiệm theo gói thời gian linh hoạt</span>
    </div>

    <!-- Thanh công cụ lọc & sắp xếp -->
    <div class="d-flex align-items-center flex-wrap gap-2 w-100 w-lg-auto justify-content-start justify-content-lg-end">
        <!-- Lọc Nền tảng -->
        <form method="GET" action="index.php" class="d-flex align-items-center gap-1">
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
            <?php if(isset($_GET['category'])): ?><input type="hidden" name="category" value="<?php echo intval($_GET['category']); ?>"><?php endif; ?>
            <?php if(isset($_GET['platform'])): ?><input type="hidden" name="platform" value="<?php echo htmlspecialchars($_GET['platform']); ?>"><?php endif; ?>
            <select name="sort" class="form-select form-select-sm py-2 px-3 border-secondary" onchange="this.form.submit()" style="min-width: 130px;">
                <option value="newest" <?php echo (isset($_GET['sort']) && $_GET['sort']=='newest')?'selected':''; ?>>Mới cập nhật</option>
                <option value="oldest" <?php echo (isset($_GET['sort']) && $_GET['sort']=='oldest')?'selected':''; ?>>Cũ nhất</option>
                <option value="name_asc" <?php echo (isset($_GET['sort']) && $_GET['sort']=='name_asc')?'selected':''; ?>>Tên: A - Z</option>
            </select>
        </form>

        <?php if(!empty($_GET['category']) || !empty($_GET['platform']) || !empty($_GET['sort']) || !empty($_GET['search'])): ?>
            <a href="index.php" class="btn btn-sm btn-outline-danger px-3 py-2">
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
                    <?php if ($game['price_type'] == 'free'): ?>
                        <span class="badge-gaming badge-gaming-free">Miễn Phí</span>
                    <?php else: ?>
                        <span class="badge-gaming badge-gaming-paid">Cho Thuê</span>
                    <?php endif; ?>
                </div>

                <!-- Tình trạng kho góc trên bên phải -->
                <div class="position-absolute top-0 end-0 m-3" style="z-index: 5;">
                    <?php if ($game['price_type'] != 'free'): ?>
                        <?php if ($is_user_renting): ?>
                            <span class="badge-gaming badge-gaming-avail"><i class="bi bi-check-circle-fill"></i> Đang Thuê</span>
                        <?php elseif ($avail_acc > 0): ?>
                            <span class="badge-gaming badge-gaming-avail"><i class="bi bi-dot fs-5"></i>Còn <?php echo $avail_acc; ?> TK</span>
                        <?php else: ?>
                            <span class="badge-gaming badge-gaming-out"><i class="bi bi-x-circle-fill"></i>Hết TK</span>
                        <?php endif; ?>
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
                        <?php if ($game['price_type'] == 'free'): ?>
                            <span class="gaming-card-price-label">Hình thức</span>
                            <span class="gaming-card-price-val text-info">FREE</span>
                        <?php else: ?>
                            <span class="gaming-card-price-label">Giá thuê từ</span>
                            <span class="gaming-card-price-val text-warning fw-bold"><?php echo number_format($min_price, 0, ',', '.'); ?>đ<small class="text-secondary fw-normal ms-1" style="font-size: 0.72rem; -webkit-text-fill-color: #94a3b8;">/giờ</small></span>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if ($game['price_type'] == 'free'): ?>
                            <a href="<?php echo $target_url; ?>" class="btn btn-sm btn-gaming-secondary py-1 px-3">Chi tiết</a>
                        <?php else: ?>
                            <?php if ($is_user_renting): ?>
                                <a href="my_library.php" class="btn btn-sm btn-success fw-bold py-1 px-3">
                                    <i class="bi bi-play-fill me-1"></i> Vào Chơi
                                </a>
                            <?php elseif ($avail_acc > 0): ?>
                                <a href="borrow_process.php?game_id=<?php echo $game['game_id']; ?>" class="btn btn-sm btn-gaming-primary py-1 px-3">
                                    <i class="bi bi-lightning-charge-fill me-1"></i> Thuê Ngay
                                </a>
                            <?php else: ?>
                                <a href="<?php echo $target_url; ?>" class="btn btn-sm btn-gaming-secondary py-1 px-3 text-muted">Xem Chi Tiết</a>
                            <?php endif; ?>
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
        <h3 class="fw-bold text-white mt-2 mb-1">Thuê Tài Khoản Chỉ Với 3 Bước</h3>
        <p class="text-secondary small">Hệ thống cấp phát hoàn toàn tự động, nhận tài khoản game ngay sau khi quét mã QR</p>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="panel-dark h-100 text-center p-4">
                <div class="brand-icon-box mx-auto mb-3" style="width: 52px; height: 52px; font-size: 1.5rem;">
                    1
                </div>
                <h5 class="fw-bold text-white mb-2">Chọn Game & Gói Giờ</h5>
                <p class="text-secondary small mb-0">Tìm tựa game bạn muốn chiến và chọn thời hạn phù hợp: 1 Giờ, 1 Ngày, 7 Ngày hoặc 1 Tháng.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel-dark h-100 text-center p-4">
                <div class="brand-icon-box mx-auto mb-3" style="width: 52px; height: 52px; font-size: 1.5rem; background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%);">
                    2
                </div>
                <h5 class="fw-bold text-white mb-2">Quét Mã VietQR</h5>
                <p class="text-secondary small mb-0">Mở app ngân hàng quét mã QR thanh toán với số tiền và nội dung chính xác. Máy chủ xác nhận tức thì.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel-dark h-100 text-center p-4">
                <div class="brand-icon-box mx-auto mb-3" style="width: 52px; height: 52px; font-size: 1.5rem; background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                    3
                </div>
                <h5 class="fw-bold text-white mb-2">Nhận Tài Khoản & Chiến</h5>
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
                    <div class="accordion-item bg-transparent border-secondary border-opacity-25 mb-2">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent text-white fw-bold shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                🎮 Tôi có thể lưu game (Save Game) và chơi tiếp không?
                            </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-secondary small pt-0">
                                Hoàn toàn được! Các game trên Steam/EA/Epic đều tự động lưu file save trên đám mây (Cloud Save) hoặc trên máy tính của bạn. Khi bạn gia hạn hoặc thuê lại tài khoản đó, bạn vẫn có thể chơi tiếp tục mà không lo mất dữ liệu.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item bg-transparent border-secondary border-opacity-25 mb-2">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent text-white fw-bold shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                🔒 Tôi có được phép đổi mật khẩu tài khoản game không?
                            </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-secondary small pt-0">
                                <strong>Tuyệt đối không!</strong> Tài khoản game thuộc quyền sở hữu của ANPLAY STORE. Việc cố tình đổi mật khẩu, đổi email hoặc dùng phần mềm gian lận (cheat/hack) sẽ khiến tài khoản bị khóa vĩnh viễn và không được hoàn tiền.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item bg-transparent border-secondary border-opacity-25">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent text-white fw-bold shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                ⏱️ Khi hết giờ thuê, chuyện gì sẽ xảy ra?
                            </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-secondary small pt-0">
                                Khi hết thời hạn, hệ thống sẽ tự động thu hồi quyền sử dụng tài khoản. Bạn có thể bấm nút <strong>Gia hạn</strong> trước khi hết giờ hoặc chọn <strong>Thuê lại tài khoản cũ</strong> trong Thư viện bất cứ lúc nào.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>