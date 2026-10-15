<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php';

if (!isset($_GET['id'])) {
    echo "<script>window.location='index.php';</script>";
    exit();
}

$game_id = intval($_GET['id']);
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

// XỬ LÝ: Khi người dùng gửi bình luận
if (isset($_POST['btn_comment'])) {
    if (!$user_id) {
        echo "<script>alert('Vui lòng đăng nhập để bình luận!'); window.location='login.php';</script>";
        exit();
    } else {
        $content = $conn->real_escape_string($_POST['comment_content']);
        if (!empty($content)) {
            $conn->query("INSERT INTO comments (game_id, user_id, content) VALUES ($game_id, $user_id, '$content')");
            echo "<script>window.location='game_detail.php?id=$game_id';</script>";
            exit();
        }
    }
}

// LẤY THÔNG TIN GAME
$sql_game = "SELECT g.*, c.cate_name FROM games g LEFT JOIN categories c ON g.category_id = c.category_id WHERE g.game_id = $game_id";
$result_game = $conn->query($sql_game);

if (!$result_game || $result_game->num_rows == 0) {
    echo "<div class='container mt-5 text-center text-white'><h3>Trò chơi không tồn tại.</h3></div>";
    include 'includes/footer.php';
    exit();
}
$game = $result_game->fetch_assoc();

// Tăng lượt xem (Views)
$conn->query("UPDATE games SET views = COALESCE(views, 0) + 1 WHERE game_id = $game_id");

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
if (empty($packages) && $game['price_type'] == 'paid') {
    $packages = [
        ['package_id' => 1, 'package_name' => '1 Giờ (Trải nghiệm)', 'price' => 5000, 'duration_type' => 'hours', 'duration_value' => 1],
        ['package_id' => 2, 'package_name' => '1 Ngày (24 Giờ)', 'price' => 15000, 'duration_type' => 'days', 'duration_value' => 1],
        ['package_id' => 3, 'package_name' => '7 Ngày (1 Tuần)', 'price' => 50000, 'duration_type' => 'days', 'duration_value' => 7]
    ];
}
?>

<div class="container mt-4 mb-5">
    <!-- KHU VỰC THÔNG TIN CHÍNH -->
    <div class="row p-4 rounded shadow-sm border border-secondary" style="background-color: #1a222c;">
        <!-- Ảnh Bìa -->
        <div class="col-md-4 mb-4 mb-md-0 text-center">
            <?php if (!empty($game['image_url']) && file_exists($game['image_url'])): ?>
                <img src="<?php echo $game['image_url']; ?>" class="img-fluid rounded shadow w-100" alt="<?php echo htmlspecialchars($game['title']); ?>" style="max-height: 400px; object-fit: cover;">
            <?php else: ?>
                <div class="d-flex align-items-center justify-content-center bg-dark text-white rounded shadow border border-secondary w-100" style="height: 350px;">NO COVER</div>
            <?php endif; ?>
        </div>
        
        <!-- Thông số & Nút Action -->
        <div class="col-md-8 text-white ps-md-4 d-flex flex-column">
            <h1 class="fw-bold text-info mb-2" style="font-size: 2.3rem;"><?php echo htmlspecialchars($game['title']); ?></h1>
            <p class="mb-3">
                <span class="badge bg-secondary px-3 py-2 fs-6"><i class="bi bi-tag-fill"></i> <?php echo !empty($game['cate_name']) ? $game['cate_name'] : 'Khác'; ?></span>
                <?php if($game['platform'] == 'Mobile'): ?>
                    <span class="badge bg-success px-3 py-2 fs-6 ms-1"><i class="bi bi-phone"></i> Mobile</span>
                <?php else: ?>
                    <span class="badge bg-primary px-3 py-2 fs-6 ms-1"><i class="bi bi-pc-display"></i> PC</span>
                <?php endif; ?>
            </p>
            
            <div class="d-flex flex-wrap text-light mb-4 mt-2 fs-6 fw-bold" style="background-color: #202d39; padding: 12px; border-radius: 8px;">
                <span class="me-4 mb-1">⭐ Đánh giá: <span class="text-warning">4.9/5</span></span>
                <span class="me-4 mb-1">👁️ Lượt xem: <span class="text-white"><?php echo number_format($game['views'] ?? 0); ?></span></span>
                <span class="mb-1">⚡ Hình thức: <span class="text-info"><?php echo ($game['price_type'] == 'free') ? 'Game Miễn Phí' : 'Cho thuê tài khoản trả phí'; ?></span></span>
            </div>

            <!-- BẢNG BÁO GIÁ CÁC GÓI THUÊ -->
            <?php if ($game['price_type'] != 'free'): ?>
                <div class="mb-4">
                    <h5 class="fw-bold text-warning mb-2"><i class="bi bi-tags-fill me-1"></i> Bảng giá các gói thời hạn thuê:</h5>
                    <div class="row g-2">
                        <?php if (count($packages) > 0): ?>
                            <?php foreach ($packages as $pkg): ?>
                                <div class="col-6 col-md-3">
                                    <div class="p-2 rounded text-center border border-secondary" style="background: rgba(255, 255, 255, 0.05);">
                                        <small class="text-secondary d-block"><?php echo htmlspecialchars($pkg['package_name']); ?></small>
                                        <strong class="text-info fs-6"><?php echo number_format($pkg['price'], 0, ',', '.'); ?>đ</strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12"><small class="text-secondary">Chưa cấu hình gói giá cụ thể, áp dụng mức giá cơ bản từ 5.000đ.</small></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- KHU VỰC NÚT THAO TÁC -->
            <div class="mt-auto pt-3 border-top border-secondary">
                <?php if ($game['price_type'] == 'free'): ?>
                    <h4 class="text-success fw-bold mb-3"><i class="bi bi-gift-fill me-1"></i> Trò Chơi Miễn Phí</h4>
                    <?php $dlink = !empty($game['download_link']) ? $game['download_link'] : '#'; ?>
                    <a href="<?php echo $dlink; ?>" target="_blank" class="btn btn-success btn-lg fw-bold px-5 py-3 shadow w-100 d-flex justify-content-center align-items-center">
                        <i class="bi bi-cloud-arrow-down-fill me-2 fs-4"></i> TẢI XUỐNG BỘ CÀI CHÍNH THỨC
                    </a>
                <?php elseif ($is_rented): ?>
                    <div class="alert alert-success d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <strong class="fs-6"><i class="bi bi-check-circle-fill me-1"></i> Bạn đang thuê tài khoản game này!</strong>
                            <div class="small">Hết hạn vào: <?php echo date('d/m/Y H:i', strtotime($active_order['due_date'])); ?></div>
                        </div>
                        <a href="my_library.php" class="btn btn-success fw-bold px-3">Xem Tài Khoản Ngay</a>
                    </div>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <span class="text-secondary">Trạng thái kho tài khoản:</span>
                            <?php if ($available_accounts > 0): ?>
                                <span class="badge bg-success ms-2 fs-6">Sẵn sàng (Còn <?php echo $available_accounts; ?> tài khoản)</span>
                            <?php else: ?>
                                <span class="badge bg-danger ms-2 fs-6">Tạm hết tài khoản</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($available_accounts > 0): ?>
                        <a href="borrow_process.php?game_id=<?php echo $game_id; ?>" class="btn btn-warning fw-bold btn-lg px-5 py-3 text-dark shadow w-100 d-flex justify-content-center align-items-center" style="border-radius: 8px;">
                            <i class="bi bi-cart-check-fill me-2 fs-4"></i> THUÊ TÀI KHOẢN NGAY
                        </a>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-lg px-5 py-3 fw-bold w-100 d-flex justify-content-center align-items-center" disabled>
                            <i class="bi bi-x-circle-fill me-2 fs-4"></i> Hiện Đã Hết Tài Khoản Khả Dụng
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- KHU VỰC MÔ TẢ VÀ BÌNH LUẬN -->
    <div class="row mt-5">
        <div class="col-lg-7 mb-4">
            <div class="card bg-transparent border-0 text-white">
                <h3 class="fw-bold mb-3 border-bottom border-info pb-2">📝 Giới Thiệu & Điều Kiện Sử Dụng</h3>
                <div class="text-white fs-6 mb-4" style="line-height: 1.8; text-align: justify;">
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

                <div class="p-3 rounded border border-secondary" style="background: rgba(0, 243, 255, 0.05);">
                    <h6 class="text-info fw-bold"><i class="bi bi-shield-exclamation me-1"></i> Chính sách sử dụng tài khoản:</h6>
                    <ul class="text-secondary small mb-0 ps-3">
                        <li>Không tự ý đổi mật khẩu, email hoặc cài đặt bảo mật của tài khoản được cấp.</li>
                        <li>Sau khi hết hạn thuê, thông tin tài khoản sẽ tự động khóa và thu hồi quyền xem trên website.</li>
                        <li>Khách hàng có thể chọn <strong>Gia hạn</strong> hoặc <strong>Thuê lại tài khoản cũ</strong> để tiếp tục sử dụng.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Cột Bình Luận -->
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 p-4" style="background-color: #202d39; border-radius: 12px;">
                <h4 class="text-white fw-bold mb-4">💬 Đánh Giá Trải Nghiệm</h4>
                
                <form method="POST" action="" class="mb-4">
                    <div class="form-group mb-3">
                        <textarea name="comment_content" class="form-control bg-dark text-white border-secondary" rows="3" placeholder="Chia sẻ đánh giá hoặc trải nghiệm thuê game của bạn..." required style="resize: none;"></textarea>
                    </div>
                    <button type="submit" name="btn_comment" class="btn btn-info fw-bold w-100 py-2">Gửi Bình Luận</button>
                </form>

                <div class="comments-list pe-2" style="max-height: 500px; overflow-y: auto;">
                    <?php
                    $sql_comments = "SELECT c.*, u.username FROM comments c JOIN users u ON c.user_id = u.user_id WHERE c.game_id = $game_id ORDER BY c.created_at DESC";
                    $result_comments = $conn->query($sql_comments);
                    
                    if ($result_comments && $result_comments->num_rows > 0):
                        while ($cmt = $result_comments->fetch_assoc()):
                    ?>
                        <div class="d-flex mb-3 pb-3 border-bottom border-secondary">
                            <div class="me-3">
                                <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center text-white fw-bold fs-5" style="width: 45px; height: 45px;">
                                    <?php echo strtoupper(substr($cmt['username'], 0, 1)); ?>
                                </div>
                            </div>
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <h6 class="text-info fw-bold mb-0">@<?php echo htmlspecialchars($cmt['username']); ?></h6>
                                    <span class="text-secondary small"><?php echo date('d/m/Y H:i', strtotime($cmt['created_at'])); ?></span>
                                </div>
                                <p class="text-white mb-0" style="line-height: 1.5; font-size: 0.95rem;"><?php echo nl2br(htmlspecialchars($cmt['content'])); ?></p>
                            </div>
                        </div>
                    <?php 
                        endwhile;
                    else:
                    ?>
                        <div class="text-center py-4">
                            <p class="text-secondary mb-0">Chưa có bình luận nào. Hãy là người đầu tiên trải nghiệm tựa game này!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>