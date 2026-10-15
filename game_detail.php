<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/connect.php'; 

// KIỂM TRA ID TRÒ CHƠI
$game_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

// XỬ LÝ: Khi người dùng gửi bình luận (Xử lý TRƯỚC KHI output header để redirect an toàn)
if (isset($_POST['btn_comment'])) {
    if (!$user_id) {
        header("Location: login.php");
        exit();
    }
    
    $content = trim($_POST['comment_content'] ?? '');
    if (!empty($content) && $game_id > 0) {
        $clean_content = $conn->real_escape_string($content);
        try {
            $conn->query("INSERT INTO comments (game_id, user_id, content) VALUES ($game_id, $user_id, '$clean_content')");
        } catch (Throwable $e) {}
    }
    
    header("Location: game_detail.php?id=$game_id#commentsSection");
    exit();
}

// BẮT ĐẦU OUTPUT GIAO DIỆN CHUNG
require_once __DIR__ . '/includes/header.php'; 

if ($game_id <= 0) {
    echo "<div class='container mt-5 text-center text-white'><div class='panel-dark p-5'><h3>Không tìm thấy tựa game yêu cầu.</h3><a href='index.php' class='btn btn-gaming-primary mt-3'>Về Cửa Hàng</a></div></div>";
    include 'includes/footer.php';
    exit();
}

// LẤY THÔNG TIN GAME
$game = null;
try {
    $sql_game = "SELECT g.*, c.cate_name FROM games g LEFT JOIN categories c ON g.category_id = c.category_id WHERE g.game_id = $game_id";
    $result_game = $conn->query($sql_game);
    if ($result_game && $result_game->num_rows > 0) {
        $game = $result_game->fetch_assoc();
    }
} catch (Throwable $e) {}

if (!$game) {
    echo "<div class='container mt-5 text-center text-white'><div class='panel-dark p-5'><h3>Trò chơi không tồn tại hoặc đã bị ẩn.</h3><a href='index.php' class='btn btn-gaming-primary mt-3'>Về Cửa Hàng</a></div></div>";
    include 'includes/footer.php';
    exit();
}

// Tăng lượt xem (Views) an toàn
try {
    $conn->query("UPDATE games SET views = COALESCE(views, 0) + 1 WHERE game_id = $game_id");
} catch (Throwable $e) {}

// Đồng bộ số lượng tài khoản khả dụng thực tế
$available_accounts = intval($game['quantity'] ?? 0);
try {
    if (function_exists('sync_game_inventory')) {
        sync_game_inventory($conn, $game_id);
    }
    $acc_query = $conn->query("SELECT 
        COUNT(*) as total_acc,
        SUM(CASE WHEN status = 'AVAILABLE' THEN 1 ELSE 0 END) as avail_acc 
        FROM game_accounts WHERE game_id = $game_id");
    if ($acc_query && $acc_stat = $acc_query->fetch_assoc()) {
        $available_accounts = intval($acc_stat['avail_acc'] ?? 0);
    }
} catch (Throwable $e) {}

// KIỂM TRA ĐƠN THUÊ ĐANG HIỆU LỰC CỦA NGƯỜI DÙNG HIỆN TẠI
$is_rented = false;
$active_order = null;
if ($user_id) {
    try {
        $check_rent = $conn->query("SELECT * FROM borrow_records WHERE user_id = $user_id AND game_id = $game_id AND status = 'active' AND due_date > NOW() LIMIT 1");
        if ($check_rent && $check_rent->num_rows > 0) {
            $is_rented = true;
            $active_order = $check_rent->fetch_assoc();
        }
    } catch (Throwable $e) {}
}

// Lấy danh sách gói thuê cấu hình cho game này
$packages = [];
try {
    $packages_res = $conn->query("SELECT * FROM rental_packages WHERE game_id = $game_id AND is_active = 1 ORDER BY price ASC");
    if ($packages_res && $packages_res->num_rows > 0) {
        while ($p = $packages_res->fetch_assoc()) {
            $packages[] = $p;
        }
    }
} catch (Throwable $e) {}

// Gói dự phòng nếu CSDL chưa nạp bảng rental_packages
if (empty($packages) && ($game['price_type'] ?? 'paid') == 'paid') {
    $packages = [
        ['package_id' => 1, 'package_name' => '1 Giờ (Trải nghiệm nhanh)', 'price' => 5000, 'duration_type' => 'hours', 'duration_value' => 1],
        ['package_id' => 2, 'package_name' => '1 Ngày (24 Giờ trọn gói)', 'price' => 15000, 'duration_type' => 'days', 'duration_value' => 1],
        ['package_id' => 3, 'package_name' => '7 Ngày (1 Tuần phá đảo)', 'price' => 50000, 'duration_type' => 'days', 'duration_value' => 7]
    ];
}
?>

<div class="container-fluid px-0 px-md-3 mt-2 mb-5">
    <!-- Breadcrumb điều hướng -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb" style="font-size: 0.88rem;">
            <li class="breadcrumb-item"><a href="index.php" class="text-secondary"><i class="bi bi-house me-1"></i> Trang chủ</a></li>
            <li class="breadcrumb-item"><a href="index.php?category=<?php echo intval($game['category_id'] ?? 0); ?>" class="text-secondary"><?php echo htmlspecialchars($game['cate_name'] ?? 'Thể loại'); ?></a></li>
            <li class="breadcrumb-item active text-white" aria-current="page"><?php echo htmlspecialchars($game['title']); ?></li>
        </ol>
    </nav>

    <!-- KHU VỰC THÔNG TIN CHÍNH (PRODUCT SHOWCASE) -->
    <div class="panel-dark p-4 p-lg-5 mb-5">
        <div class="row g-4 align-items-start">
            <!-- Cột 1: Ảnh Bìa Game -->
            <div class="col-lg-5 col-md-6 text-center">
                <div class="position-relative rounded-4 overflow-hidden shadow-lg border border-secondary border-opacity-25" style="background: #0b0d13;">
                    <?php if (!empty($game['image_url']) && file_exists($game['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($game['image_url']); ?>" class="img-fluid w-100" alt="<?php echo htmlspecialchars($game['title']); ?>" style="max-height: 480px; object-fit: cover;">
                    <?php else: ?>
                        <div class="d-flex flex-column align-items-center justify-content-center text-muted" style="height: 380px;">
                            <i class="bi bi-controller fs-1 mb-2 text-secondary"></i>
                            <span>CHƯA CÓ ẢNH BÌA</span>
                        </div>
                    <?php endif; ?>

                    <!-- Platform Tag góc trên ảnh -->
                    <div class="position-absolute top-0 start-0 m-3 d-flex gap-2">
                        <span class="badge-platform px-3 py-2 fs-6">
                            <?php echo (($game['platform'] ?? 'PC') == 'PC') ? '<i class="bi bi-display me-1"></i>PC Game' : '<i class="bi bi-phone me-1"></i>Mobile Game'; ?>
                        </span>
                        <?php if (($game['price_type'] ?? '') == 'free'): ?>
                            <span class="badge-gaming badge-gaming-free px-3 py-2 fs-6">Miễn Phí</span>
                        <?php else: ?>
                            <span class="badge-gaming badge-gaming-paid px-3 py-2 fs-6">Tài Khoản Thuê</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tình trạng kho tài khoản -->
                <?php if (($game['price_type'] ?? '') != 'free'): ?>
                <div class="mt-3 p-3 rounded-3 bg-dark border border-secondary border-opacity-25 d-flex justify-content-between align-items-center">
                    <span class="text-secondary small fw-bold"><i class="bi bi-box-seam me-1"></i> Trạng thái kho:</span>
                    <?php if ($available_accounts > 0): ?>
                        <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 px-3 py-2 fw-bold">
                            🟢 Sẵn sàng cấp (Còn <?php echo $available_accounts; ?> tài khoản)
                        </span>
                    <?php else: ?>
                        <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-50 px-3 py-2 fw-bold">
                            🔴 Tạm hết tài khoản
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Cột 2: Thông tin chi tiết & Gói giá -->
            <div class="col-lg-7 col-md-6 d-flex flex-column">
                <h1 class="fw-bold text-white mb-2" style="font-size: 2.2rem; line-height: 1.25;">
                    <?php echo htmlspecialchars($game['title']); ?>
                </h1>

                <!-- Meta Pills -->
                <div class="d-flex flex-wrap align-items-center gap-3 my-3">
                    <span class="text-secondary small">
                        <i class="bi bi-tag-fill text-primary me-1"></i> <?php echo !empty($game['cate_name']) ? htmlspecialchars($game['cate_name']) : 'Khác'; ?>
                    </span>
                    <span class="text-secondary small">
                        <i class="bi bi-star-fill text-warning me-1"></i> <strong>4.9 / 5.0</strong> (Đánh giá game thủ)
                    </span>
                    <span class="text-secondary small">
                        <i class="bi bi-eye-fill text-info me-1"></i> <?php echo number_format($game['views'] ?? 0); ?> lượt xem
                    </span>
                </div>

                <!-- Mô tả ngắn gọn -->
                <?php if (!empty($game['description'])): ?>
                    <p class="text-secondary" style="font-size: 0.98rem; line-height: 1.6;">
                        <?php echo htmlspecialchars($game['description']); ?>
                    </p>
                <?php endif; ?>

                <!-- BẢNG BÁO GIÁ CÁC GÓI THUÊ -->
                <?php if (($game['price_type'] ?? '') != 'free'): ?>
                    <div class="my-3 p-3 rounded-4" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-subtle);">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold text-white small text-uppercase tracking-wider">
                                <i class="bi bi-clock-history text-warning me-1"></i> Bảng Giá Gói Thuê Theo Thời Gian
                            </span>
                            <span class="text-success small fw-bold"><i class="bi bi-check-circle me-1"></i> Bảo hành trọn thời hạn</span>
                        </div>
                        <div class="row g-2">
                            <?php foreach ($packages as $idx => $pkg): ?>
                                <div class="col-sm-4 col-6">
                                    <div class="p-3 rounded-3 text-center border border-secondary border-opacity-25 h-100 d-flex flex-column justify-content-center" style="background: #0f121a;">
                                        <small class="text-secondary d-block fw-bold mb-1" style="font-size: 0.78rem;"><?php echo htmlspecialchars($pkg['package_name']); ?></small>
                                        <div class="gaming-card-price-val fs-5"><?php echo number_format($pkg['price'], 0, ',', '.'); ?>đ</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- KHU VỰC NÚT THUÊ CHÍNH -->
                <div class="mt-4 pt-3 border-top border-white border-opacity-10">
                    <?php if (($game['price_type'] ?? '') == 'free'): ?>
                        <div class="p-3 rounded-3 mb-3" style="background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.3);">
                            <span class="text-info fw-bold"><i class="bi bi-gift-fill me-1"></i> Trò chơi này được phát hành hoàn toàn miễn phí.</span>
                        </div>
                        <?php $dlink = !empty($game['download_link']) ? $game['download_link'] : '#'; ?>
                        <a href="<?php echo $dlink; ?>" target="_blank" class="btn btn-gaming-cyan w-100 py-3 fs-5 fw-bold">
                            <i class="bi bi-cloud-arrow-down-fill me-2 fs-4"></i> TẢI XUỐNG BỘ CÀI CHÍNH THỨC
                        </a>
                    <?php elseif ($is_rented): ?>
                        <div class="alert alert-success d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 p-3 rounded-3 mb-3 border-0 shadow" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4) !important;">
                            <div>
                                <strong class="fs-6 text-white"><i class="bi bi-check-circle-fill text-success me-2 fs-5"></i> Bạn đang thuê tài khoản trò chơi này!</strong>
                                <div class="text-secondary small mt-1">Hết hạn vào: <strong class="text-warning"><?php echo date('d/m/Y H:i', strtotime($active_order['due_date'])); ?></strong></div>
                            </div>
                            <a href="my_library.php" class="btn btn-gaming-primary py-2 px-4 text-nowrap">
                                <i class="bi bi-controller me-1"></i> Vào Chơi Game
                            </a>
                        </div>
                    <?php else: ?>
                        <?php if ($available_accounts > 0): ?>
                            <a href="borrow_process.php?game_id=<?php echo $game_id; ?>" class="btn btn-gaming-primary w-100 py-3 fs-5 fw-bold shadow-lg">
                                <i class="bi bi-lightning-charge-fill me-2 fs-4 text-warning"></i> THUÊ TÀI KHOẢN NGAY (CẤP TỰ ĐỘNG)
                            </a>
                            <small class="text-secondary text-center d-block mt-2" style="font-size: 0.8rem;">
                                <i class="bi bi-shield-check text-success me-1"></i> Thanh toán VietQR tự động — Nhận thông tin đăng nhập ngay sau 30 giây
                            </small>
                        <?php else: ?>
                            <button class="btn btn-secondary w-100 py-3 fs-5 fw-bold" disabled>
                                <i class="bi bi-x-circle-fill me-2"></i> Tạm Hết Tài Khoản Khả Dụng Trong Kho
                            </button>
                            <small class="text-secondary text-center d-block mt-2">Vui lòng quay lại sau hoặc liên hệ Admin để bổ sung thêm tài khoản.</small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- KHU VỰC THÔNG TIN BỔ SUNG & BÌNH LUẬN -->
    <div class="row g-4">
        <!-- Cột trái: Giới thiệu & Cấu hình -->
        <div class="col-lg-7">
            <!-- Mô tả chi tiết -->
            <div class="panel-dark p-4 mb-4">
                <h4 class="fw-bold text-white mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-file-text-fill text-primary"></i> Chi Tiết Trò Chơi
                </h4>
                <div class="text-light" style="line-height: 1.8; font-size: 0.95rem;">
                    <?php 
                    if (!empty($game['long_description'])) {
                        echo nl2br(htmlspecialchars($game['long_description'])); 
                    } elseif (!empty($game['description'])) {
                        echo nl2br(htmlspecialchars($game['description']));
                    } else {
                        echo "<p class='text-secondary fst-italic'>Chưa có thông tin mô tả chi tiết cho tựa game này.</p>";
                    }
                    ?>
                </div>
            </div>

            <!-- Cấu hình yêu cầu -->
            <?php if (!empty($game['system_req'])): ?>
            <div class="panel-dark p-4 mb-4">
                <h4 class="fw-bold text-white mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-cpu-fill text-info"></i> Yêu Cầu Cấu Hình Thiết Bị
                </h4>
                <div class="p-3 rounded-3" style="background: #0b0d13; border: 1px solid var(--border-subtle); font-family: monospace; font-size: 0.9rem; line-height: 1.7; color: #cbd5e1;">
                    <?php echo nl2br(htmlspecialchars($game['system_req'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Điều khoản sử dụng tài khoản -->
            <div class="panel-dark p-4" style="border-left: 4px solid var(--primary);">
                <h5 class="fw-bold text-white mb-2"><i class="bi bi-shield-exclamation text-warning me-2"></i> Quy Định Khi Thuê Tài Khoản Game</h5>
                <ul class="text-secondary small mb-0 ps-3" style="line-height: 1.7;">
                    <li>Không tự ý đổi mật khẩu, email hoặc bất kỳ thông tin bảo mật nào của tài khoản được cấp.</li>
                    <li>Không sử dụng phần mềm thứ ba gian lận (hack/cheat) làm ảnh hưởng đến độ uy tín của tài khoản.</li>
                    <li>Hết hạn thuê, quyền truy cập tài khoản sẽ tự động thu hồi. Bạn có thể gia hạn hoặc thuê lại tài khoản cũ bất kỳ lúc nào.</li>
                </ul>
            </div>
        </div>

        <!-- Cột phải: Đánh giá & Bình luận -->
        <div class="col-lg-5" id="commentsSection">
            <div class="panel-dark p-4">
                <h4 class="fw-bold text-white mb-3 d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-chat-left-text-fill text-info me-2"></i> Đánh Giá Từ Game Thủ</span>
                </h4>
                
                <!-- Form gửi bình luận -->
                <form method="POST" action="game_detail.php?id=<?php echo $game_id; ?>#commentsSection" class="mb-4">
                    <div class="mb-3">
                        <textarea name="comment_content" class="form-control" rows="3" placeholder="Chia sẻ cảm nhận hoặc trải nghiệm chơi game của bạn..." required style="resize: none;"></textarea>
                    </div>
                    <button type="submit" name="btn_comment" class="btn btn-gaming-primary w-100 py-2">
                        <i class="bi bi-send-fill me-1"></i> Gửi Đánh Giá Của Bạn
                    </button>
                </form>

                <!-- Danh sách bình luận -->
                <div class="comments-list pe-1" style="max-height: 480px; overflow-y: auto;">
                    <?php
                    $comments_loaded = false;
                    try {
                        $sql_comments = "SELECT c.*, u.username FROM comments c JOIN users u ON c.user_id = u.user_id WHERE c.game_id = $game_id ORDER BY c.comment_id DESC";
                        $result_comments = $conn->query($sql_comments);
                        
                        if ($result_comments && $result_comments->num_rows > 0) {
                            $comments_loaded = true;
                            while ($cmt = $result_comments->fetch_assoc()) {
                    ?>
                        <div class="d-flex gap-3 mb-3 pb-3 border-bottom border-white border-opacity-10">
                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($cmt['username'] ?? 'Gamer'); ?>&background=1e293b&color=38bdf8&bold=true&rounded=true" width="40" height="40" class="rounded-circle flex-shrink-0" alt="Avatar">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-white small">@<?php echo htmlspecialchars($cmt['username'] ?? 'Gamer'); ?></strong>
                                    <span class="text-secondary" style="font-size: 0.75rem;">
                                        <?php 
                                        $ctime = isset($cmt['created_at']) ? strtotime($cmt['created_at']) : (isset($cmt['comment_date']) ? strtotime($cmt['comment_date']) : time());
                                        echo date('d/m/Y H:i', $ctime); 
                                        ?>
                                    </span>
                                </div>
                                <p class="text-light mb-0" style="font-size: 0.9rem; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($cmt['content'] ?? '')); ?></p>
                            </div>
                        </div>
                    <?php 
                            }
                        }
                    } catch (Throwable $e) {}
                    
                    if (!$comments_loaded):
                    ?>
                        <div class="text-center py-4 text-secondary">
                            <i class="bi bi-chat-dots fs-2 d-block mb-2 text-secondary"></i>
                            <p class="small mb-0">Chưa có bình luận nào. Hãy là người đầu tiên trải nghiệm tựa game này!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>