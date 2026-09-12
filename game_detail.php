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

if ($result_game->num_rows == 0) {
    echo "<div class='container mt-5 text-center text-white'><h3>Trò chơi không tồn tại.</h3></div>";
    include 'includes/footer.php';
    exit();
}
$game = $result_game->fetch_assoc();

// Tăng lượt xem (Views) khi có người click vào xem chi tiết
$conn->query("UPDATE games SET views = COALESCE(views, 0) + 1 WHERE game_id = $game_id");

// --- BỘ NÃO KIỂM TRA QUYỀN TRUY CẬP (BẺ GÃY VÒNG LẶP THANH TOÁN) ---
$is_rented = false;
if ($user_id) {
    // Kiểm tra xem có đơn thuê nào đang active và chưa hết hạn cho game này không
    $check_rent = $conn->query("SELECT status FROM borrow_records WHERE user_id = $user_id AND game_id = $game_id AND status = 'active' AND due_date > NOW()");
    if ($check_rent && $check_rent->num_rows > 0) {
        $is_rented = true;
    }
}
// -------------------------------------------------------------------
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
            <h1 class="fw-bold text-info mb-3" style="font-size: 2.5rem; text-shadow: 1px 1px 2px rgba(0,0,0,0.5);"><?php echo htmlspecialchars($game['title']); ?></h1>
            <p class="mb-3">
                <span class="badge bg-secondary px-3 py-2 fs-6"><i class="bi bi-tag-fill"></i> <?php echo !empty($game['cate_name']) ? $game['cate_name'] : 'Khác'; ?></span>
                <?php if($game['platform'] == 'Mobile'): ?>
                    <span class="badge bg-success px-3 py-2 fs-6 ms-1"><i class="bi bi-phone"></i> Mobile</span>
                <?php else: ?>
                    <span class="badge bg-primary px-3 py-2 fs-6 ms-1"><i class="bi bi-pc-display"></i> PC</span>
                <?php endif; ?>
            </p>
            
            <div class="d-flex flex-wrap text-light mb-4 mt-3 fs-5 fw-bold" style="background-color: #202d39; padding: 15px; border-radius: 8px;">
                <span class="me-4 mb-2">⭐ Đánh giá: <span class="text-warning">4.8/5</span></span>
                <span class="me-4 mb-2">⬇️ Lượt tải: <span class="text-white"><?php echo number_format($game['download_count'] ?? 0); ?></span></span>
                <span class="mb-2">👁️ Lượt xem: <span class="text-white"><?php echo number_format($game['views'] ?? 0); ?></span></span>
            </div>

            <!-- XỬ LÝ NÚT BẤM (ĐÃ ĐƯỢC FIX LỖI VÒNG LẶP) -->
            <div class="mt-auto pt-3 border-top border-secondary">
                <?php if ($game['price_type'] == 'free' || $is_rented == true): ?>
                    
                    <!-- NẾU MIỄN PHÍ HOẶC ĐÃ THANH TOÁN -> HIỆN NÚT TẢI -->
                    <?php if ($is_rented): ?>
                        <h4 class="text-success fw-bold mb-3"><i class="bi bi-check-circle-fill"></i> Trạng thái: Đã Mở Khóa</h4>
                    <?php else: ?>
                        <h4 class="text-success fw-bold mb-3"><i class="bi bi-gift-fill"></i> Game Miễn Phí</h4>
                    <?php endif; ?>
                    
                    <a href="download_game.php?id=<?php echo $game_id; ?>" class="btn btn-success btn-lg fw-bold px-5 py-3 shadow w-100 d-flex justify-content-center align-items-center">
                        <i class="bi bi-cloud-arrow-down-fill me-2 fs-4"></i> TẢI XUỐNG & CHƠI NGAY
                    </a>

                <?php else: ?>
                    
                    <!-- NẾU TRẢ PHÍ VÀ CHƯA THANH TOÁN -> HIỆN NÚT ĐI THUÊ -->
                    <h4 class="text-warning fw-bold mb-3">Game Trả Phí</h4>
                    <p class="text-light fs-5 mb-3">Kho còn: <span class="badge <?php echo ($game['available_quantity'] > 0) ? 'bg-success' : 'bg-danger'; ?>"><?php echo $game['available_quantity']; ?> bản</span></p>
                    
                    <?php if ($game['available_quantity'] > 0): ?>
                        <a href="checkout_qr.php?id=<?php echo $game_id; ?>" class="btn btn-warning fw-bold btn-lg px-5 py-3 text-dark shadow w-100 d-flex justify-content-center align-items-center">
                            <i class="bi bi-cart-check-fill me-2 fs-4"></i> ĐĂNG KÝ THUÊ
                        </a>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-lg px-5 py-3 fw-bold w-100 d-flex justify-content-center align-items-center" disabled>
                            <i class="bi bi-x-circle-fill me-2 fs-4"></i> Đã hết bản quyền
                        </button>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- KHU VỰC MÔ TẢ VÀ BÌNH LUẬN (GIỮ NGUYÊN) -->
    <div class="row mt-5">
        <!-- Cột Mô Tả -->
        <div class="col-lg-7 mb-4">
            <div class="card bg-transparent border-0 text-white">
                <h3 class="fw-bold mb-3 border-bottom border-info pb-2">📝 Mô Tả Trò Chơi</h3>
                <div class="text-white fs-6" style="line-height: 1.8; text-align: justify;">
                    <?php 
                    // ƯU TIÊN HIỂN THỊ BÀI VIẾT CHI TIẾT
                    if (!empty($game['long_description'])) {
                        echo nl2br(htmlspecialchars($game['long_description'])); 
                    } 
                    // NẾU CHƯA CÓ BÀI CHI TIẾT THÌ HIỂN THỊ TẠM MÔ TẢ NGẮN
                    elseif (!empty($game['description'])) {
                        echo nl2br(htmlspecialchars($game['description']));
                    } 
                    // NẾU KHÔNG CÓ CẢ HAI
                    else {
                        echo "<p class='text-secondary fst-italic'>Chưa có thông tin mô tả chi tiết cho tựa game này.</p>";
                    }
                    ?>
                </div>
            </div>
        </div>

        <!-- Cột Bình Luận -->
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 p-4" style="background-color: #202d39; border-radius: 12px;">
                <h4 class="text-white fw-bold mb-4">💬 Đánh giá & Bình luận</h4>
                
                <!-- Form viết bình luận -->
                <form method="POST" action="" class="mb-4">
                    <div class="form-group mb-3">
                        <textarea name="comment_content" class="form-control bg-dark text-white border-secondary" rows="3" placeholder="Chia sẻ cảm nghĩ của bạn về tựa game này..." required style="resize: none;"></textarea>
                    </div>
                    <button type="submit" name="btn_comment" class="btn btn-info fw-bold w-100 py-2">Gửi Bình Luận</button>
                </form>

                <!-- Danh sách bình luận -->
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
                            <p class="text-secondary mb-0">Chưa có bình luận nào. Hãy là người đầu tiên đánh giá siêu phẩm này!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>