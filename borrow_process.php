<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php'; 

if(!isset($_SESSION['user_id'])) {
    echo "<script>alert('Vui lòng đăng nhập tài khoản để thuê mượn game!'); window.location='login.php';</script>";
    exit();
}

if(!isset($_GET['game_id'])) {
    echo "<script>window.location='index.php';</script>";
    exit();
}

$game_id = intval($_GET['game_id']);
$user_id = $_SESSION['user_id'];

// --- BẢO VỆ NGƯỜI DÙNG: Kiểm tra xem họ đã có Key game này chưa ---
$check_sql = "SELECT * FROM borrow_records WHERE user_id = $user_id AND game_id = $game_id AND status = 'active' AND due_date > NOW()";
$check_res = $conn->query($check_sql);
if ($check_res && $check_res->num_rows > 0) {
    echo "<script>alert('Bạn đang sở hữu License Key của tựa game này rồi! Hệ thống sẽ chuyển bạn đến trang Quản lý bản quyền.'); window.location='download_game.php?id=$game_id';</script>";
    exit();
}
// -----------------------------------------------------------------

$result = $conn->query("SELECT * FROM games WHERE game_id = $game_id AND price_type = 'paid'");
if($result->num_rows == 0) {
    echo "<div class='container mt-5'><div class='alert alert-danger text-center shadow-sm'>Tựa game không tồn tại hoặc không cho thuê.</div></div>";
    include 'includes/footer.php';
    exit();
}
$game = $result->fetch_assoc();

// XỬ LÝ: Khi bấm Xác Nhận Thanh Toán
if(isset($_POST['btn_borrow'])) {
    $days = intval($_POST['borrow_days']);
    $payment_method = $_POST['payment_method'];
    
    $price_list = [3 => 15000, 7 => 30000, 14 => 50000, 30 => 99000];
    $total_cost = isset($price_list[$days]) ? $price_list[$days] : 30000;
    
    if($game['available_quantity'] > 0) {
        $order_data = urlencode(base64_encode(json_encode([
            'user_id' => $user_id,
            'game_id' => $game_id,
            'days' => $days,
            'amount' => $total_cost,
            'method' => $payment_method
        ])));
        
        // Chuyển hướng sang trang quét QR
        echo "<script>window.location.href = 'checkout_qr.php?order=" . $order_data . "';</script>";
        exit();
    } else {
        echo "<script>alert('Rất tiếc, tựa game này hiện đã hết bản quyền!');</script>";
    }
}
?>

<div class="container mt-5 mb-5" style="max-width: 600px;">
    <h2 class="mb-4 text-center text-white fw-bold">Thanh Toán Đơn Thuê Game</h2>
    
    <div class="card shadow-lg p-4 border-secondary border-opacity-25" style="background-color: #202d39; border-radius: 12px;">
        <div class="text-center mb-4">
            <?php if(!empty($game['image_url']) && file_exists($game['image_url'])) { ?>
                <img src="<?php echo $game['image_url']; ?>" alt="<?php echo htmlspecialchars($game['title']); ?>" style="height: 150px; border-radius: 8px; object-fit: cover; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
            <?php } ?>
            <h4 class="text-info fw-bold mt-3"><?php echo htmlspecialchars($game['title']); ?></h4>
            <span class="badge bg-success px-3 py-2 fs-6 mt-1"><i class="bi bi-box-seam me-1"></i> Kho còn: <?php echo $game['available_quantity']; ?> bản</span>
        </div>

        <form method="POST" action="">
            <div class="mb-4">
                <label class="form-label text-light fw-bold mb-2">⏳ Chọn gói thời gian thuê (Cấp Key Tự Động):</label>
                <select name="borrow_days" id="borrowDays" class="form-select bg-dark text-white border-secondary shadow-sm py-2" required onchange="updatePrice()">
                    <option value="3" data-price="15000">3 Ngày (Trải nghiệm nhanh) - 15.000đ</option>
                    <option value="7" data-price="30000" selected>7 Ngày (Mặc định) - 30.000đ</option>
                    <option value="14" data-price="50000">14 Ngày (Gói chuyên sâu) - 50.000đ</option>
                    <option value="30" data-price="99000">30 Ngày (Cày cuốc liên tục) - 99.000đ</option>
                </select>
            </div>
            
            <div class="mb-4">
                <label class="form-label text-light fw-bold mb-2">💳 Phương thức thanh toán:</label>
                <div class="p-3 rounded shadow-sm" style="background-color: #151d26; border: 1px solid #2d3b4e;">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="payment_method" id="pay_momo" value="Ví MoMo" checked>
                        <label class="form-check-label text-white ms-1" for="pay_momo">🟣 Thanh toán qua Ví MoMo</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="payment_method" id="pay_vnpay" value="VNPAY">
                        <label class="form-check-label text-white ms-1" for="pay_vnpay">🔵 Cổng thanh toán VNPAY (QR Code)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="pay_bank" value="Chuyển khoản Ngân hàng">
                        <label class="form-check-label text-white ms-1" for="pay_bank">🏦 Chuyển khoản Ngân hàng nội địa</label>
                    </div>
                </div>
            </div>

            <div class="alert alert-info border-info mb-4 shadow-sm" style="background-color: rgba(71, 191, 255, 0.1);">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-info fs-5">Tổng thanh toán:</span>
                    <h3 class="mb-0 fw-bold text-warning" id="totalPriceDisplay">30.000 VNĐ</h3>
                </div>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" name="btn_borrow" class="btn btn-primary fw-bold py-3 fs-5 shadow" style="background: linear-gradient(to right, #e1306c, #f56040); border: none; border-radius: 8px;">
                    🔒 XÁC NHẬN THANH TOÁN
                </button>
                <a href="game_detail.php?id=<?php echo $game_id; ?>" class="btn btn-outline-secondary py-2 mt-2 fw-bold" style="border-radius: 8px;">Hủy bỏ - Trở về</a>
            </div>
        </form>
    </div>
</div>

<script>
function updatePrice() {
    var select = document.getElementById("borrowDays");
    var price = select.options[select.selectedIndex].getAttribute("data-price");
    document.getElementById("totalPriceDisplay").innerText = new Intl.NumberFormat('vi-VN').format(price) + " VNĐ";
}
</script>

<?php include 'includes/footer.php'; ?>