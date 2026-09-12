<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/header.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('Vui lòng đăng nhập!'); window.location='login.php';</script>";
    exit();
}

if (isset($_GET['id'])) {
    $game_id = intval($_GET['id']);
    $user_id = $_SESSION['user_id'];
    
    // Tự động cộng 1 lượt tải
    $conn->query("UPDATE games SET download_count = download_count + 1 WHERE game_id = $game_id");
    
    // Lấy thông tin game và thông tin đơn thuê kết hợp Key
    $sql = "SELECT g.title, g.image_url, g.price_type, g.download_link, b.game_key, b.due_date, b.status, b.borrow_id 
            FROM games g 
            LEFT JOIN borrow_records b ON g.game_id = b.game_id AND b.user_id = $user_id AND b.status = 'active'
            WHERE g.game_id = $game_id";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $game = $result->fetch_assoc();
        
        $is_allowed = false;
        $key_display = "GAME MIỄN PHÍ - KHÔNG CẦN KEY";
        $expiry_display = "Vĩnh viễn";
        
        // KIỂM TRA ĐIỀU KIỆN VÀ CẤP PHÁT KEY BÙ
        if ($game['price_type'] == 'free') {
            $is_allowed = true; // Game Free luôn qua cửa
        } else {
            // Nếu có đơn active và thời gian hết hạn lớn hơn thời gian hiện tại
            if (!empty($game['status']) && $game['status'] == 'active' && strtotime($game['due_date']) > time()) {
                $is_allowed = true;
                $expiry_display = date('d/m/Y H:i', strtotime($game['due_date']));
                
                // TỰ ĐỘNG CẤP BÙ KEY (Nếu đơn hàng bị thiếu Key)
                if (empty($game['game_key'])) {
                    // Sinh Key mới
                    $new_key = 'ANPLAY-' . strtoupper(substr(md5(uniqid()), 0, 4)) . '-' . strtoupper(substr(md5(rand()), 0, 4)) . '-' . strtoupper(substr(md5(rand()), 0, 4));
                    
                    // Lưu ngay vào Database để đưa vào Thư viện của khách
                    $b_id = intval($game['borrow_id']);
                    $conn->query("UPDATE borrow_records SET game_key = '$new_key' WHERE borrow_id = $b_id");
                    
                    $key_display = $new_key; // Hiển thị Key mới sinh
                } else {
                    // Nếu đã có sẵn Key thì lôi ra hiển thị
                    $key_display = $game['game_key'];
                }
            }
        }
        
        // Nếu không đủ điều kiện (Chưa mua hoặc đã hết hạn) -> Đá về trang chi tiết
        if (!$is_allowed) {
            echo "<script>alert('Truy cập từ chối: Gói thuê đã hết hạn hoặc bạn chưa thanh toán!'); window.location='game_detail.php?id=$game_id';</script>";
            exit();
        }
    } else {
        echo "<script>window.location='index.php';</script>";
        exit();
    }
} else {
    echo "<script>window.location='index.php';</script>";
    exit();
}
?>

<div class="container mt-5 mb-5 d-flex justify-content-center">
    <div class="card bg-dark text-white border-secondary shadow-lg rounded-4 w-100" style="max-width: 650px;">
        <div class="card-body p-5 text-center">
            
            <h3 class="fw-bold mb-4 text-info"><i class="bi bi-shield-check"></i> TRUNG TÂM BẢN QUYỀN</h3>
            
            <h4 class="text-warning fw-bold mb-4 text-uppercase"><?php echo htmlspecialchars($game['title']); ?></h4>
            
            <!-- KHU VỰC HIỂN THỊ KEY -->
            <div class="bg-black p-4 rounded-3 border border-secondary mb-4 position-relative shadow-sm">
                <small class="text-secondary d-block mb-2 fw-bold">LICENSE KEY CỦA BẠN</small>
                <h2 class="text-success fw-bold m-0 user-select-all" id="gameKey" style="letter-spacing: 2px;">
                    <?php echo $key_display; ?>
                </h2>
                
                <?php if($game['price_type'] != 'free'): ?>
                    <button onclick="copyKey()" class="btn btn-outline-light btn-sm mt-3 rounded-pill px-4 shadow">
                        <i class="bi bi-clipboard"></i> Sao chép Key
                    </button>
                <?php endif; ?>
            </div>

            <!-- THỜI GIAN HẾT HẠN -->
            <div class="mb-5 text-light bg-secondary bg-opacity-25 p-3 rounded-3 border border-secondary border-opacity-50">
                <p class="mb-1"><i class="bi bi-clock-history text-warning me-1"></i> Thời hạn kích hoạt đến:</p>
                <h5 class="fw-bold text-danger m-0"><?php echo $expiry_display; ?></h5>
                <?php if($game['price_type'] != 'free'): ?>
                    <small class="text-secondary fst-italic d-block mt-2" style="font-size: 0.85rem;">*Hệ thống sẽ tự động thu hồi Key và khóa quyền truy cập sau thời điểm này.</small>
                <?php endif; ?>
            </div>

            <!-- NÚT TẢI FILE CHÍNH THỨC -->
            <?php 
                $link = !empty($game['download_link']) ? $game['download_link'] : '#'; 
            ?>
            <a href="<?php echo $link; ?>" target="_blank" class="btn btn-primary btn-lg rounded-pill px-5 shadow fw-bold w-100 py-3 d-flex align-items-center justify-content-center">
                <i class="bi bi-cloud-arrow-down-fill me-2 fs-4"></i> TẢI XUỐNG BỘ CÀI ĐẶT
            </a>
            
            <div class="mt-4">
                <a href="game_detail.php?id=<?php echo $game_id; ?>" class="text-secondary text-decoration-none hover-text-white transition"><i class="bi bi-arrow-left"></i> Trở lại trang chi tiết</a>
            </div>
        </div>
    </div>
</div>

<script>
// Chức năng copy Key bằng Javascript
function copyKey() {
    var copyText = document.getElementById("gameKey").innerText;
    navigator.clipboard.writeText(copyText).then(function() {
        alert("Thành công! Đã sao chép Key: " + copyText);
    }, function(err) {
        console.error('Không thể sao chép', err);
        alert('Lỗi sao chép, vui lòng bôi đen mã thủ công.');
    });
}
</script>

<style>
.hover-text-white:hover { color: #fff !important; }
.transition { transition: all 0.3s ease; }
</style>

<?php include 'includes/footer.php'; ?>