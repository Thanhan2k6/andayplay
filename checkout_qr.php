<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php';

// ==========================================
// 1. XỬ LÝ AUTO-VERIFY SAU 15 GIÂY (CHỐT ĐƠN & CẤP KEY)
// ==========================================
if (isset($_POST['auto_verify_payment'])) {
    $order_data = json_decode(base64_decode(urldecode($_POST['final_order_data'])), true);
    $d = intval($order_data['days']);
    $game_id = intval($order_data['game_id']);
    
    // NẾU LÀ ĐƠN GIA HẠN (Giữ nguyên Key cũ, chỉ tăng ngày)
    if (isset($order_data['is_extend']) && $order_data['is_extend'] == true) {
        $b_id = intval($order_data['borrow_id']);
        
        $old_date_sql = $conn->query("SELECT due_date FROM borrow_records WHERE borrow_id = $b_id");
        $old_date_str = $old_date_sql->fetch_assoc()['due_date'];
        $old_timestamp = strtotime($old_date_str);
        
        // Cấu trúc logic cộng dồn: Nếu quá hạn thì tính từ NOW, nếu chưa thì nối tiếp
        if ($old_timestamp < time()) {
            $new_due_date = date('Y-m-d H:i:s', strtotime("+$d days"));
        } else {
            $new_due_date = date('Y-m-d H:i:s', strtotime("+$d days", $old_timestamp));
        }
        
        $update_sql = "UPDATE borrow_records SET due_date = '$new_due_date', status = 'active' WHERE borrow_id = $b_id";
        if ($conn->query($update_sql) === TRUE) {
            // Đổi hướng về trang nhận Key thay vì my_library
            echo "<script>
                    alert('🎉 GIA HẠN THÀNH CÔNG! Key của bạn đã được duy trì. Hạn trả mới là: " . date('d/m/Y H:i', strtotime($new_due_date)) . "'); 
                    window.location='download_game.php?id=$game_id';
                  </script>";
            exit();
        }
    } 
    // NẾU LÀ ĐƠN THUÊ MỚI (Cấp Key mới)
    else {
        $u_id = intval($order_data['user_id']);
        $due_date = date('Y-m-d H:i:s', strtotime("+$d days"));
        
        // --- TẠO KEY BẢN QUYỀN NGẪU NHIÊN CHUẨN FORM ANPLAY ---
        $game_key = 'ANPLAY-' . strtoupper(substr(md5(uniqid()), 0, 4)) . '-' . strtoupper(substr(md5(rand()), 0, 4)) . '-' . strtoupper(substr(md5(rand()), 0, 4));
        
        // Lưu thông tin kèm Key vào Database (Bổ sung game_key vào câu lệnh INSERT)
        $sql_borrow = "INSERT INTO borrow_records (user_id, game_id, borrow_date, due_date, status, game_key) 
                       VALUES ($u_id, $game_id, NOW(), '$due_date', 'active', '$game_key')";
                       
        if ($conn->query($sql_borrow) === TRUE) {
            $conn->query("UPDATE games SET available_quantity = available_quantity - 1 WHERE game_id = $game_id AND available_quantity > 0");
            
            // Đổi hướng về trang nhận Key
            echo "<script>
                    alert('🎉 THANH TOÁN THÀNH CÔNG! Hệ thống đã cấp License Key bản quyền cho bạn.'); 
                    window.location='download_game.php?id=$game_id';
                  </script>";
            exit();
        }
    }
}

// ==========================================
// 2. NHẬN DỮ LIỆU TỪ TRANG THƯ VIỆN HOẶC TRANG ĐẶT HÀNG
// ==========================================
$order_data = [];
$is_extension = false;

// Nếu là Form Gia hạn post lên
if (isset($_POST['btn_extend_submit'])) {
    $is_extension = true;
    $days = intval($_POST['extend_days']);
    $game_id = intval($_POST['game_id']);
    $borrow_id = intval($_POST['borrow_id_to_extend']);
    
    $price_list = [3 => 15000, 7 => 30000, 14 => 50000, 30 => 99000];
    $amount = isset($price_list[$days]) ? $price_list[$days] : 30000;
    
    $order_data = [
        'is_extend' => true,
        'borrow_id' => $borrow_id,
        'game_id' => $game_id,
        'days' => $days,
        'amount' => $amount
    ];
} 
// Nếu là Thuê mới truyền qua URL
else if (isset($_GET['order'])) {
    $order_json = base64_decode(urldecode($_GET['order']));
    $order_data = json_decode($order_json, true);
} 
else {
    echo "<script>window.location='index.php';</script>";
    exit();
}

// Lấy thông tin game để hiển thị
$game_id = $order_data['game_id'];
$result = $conn->query("SELECT title FROM games WHERE game_id = $game_id");
$game_title = ($result && $result->num_rows > 0) ? $result->fetch_assoc()['title'] : "Game";

// ==========================================
// CẤU HÌNH TÀI KHOẢN NGÂN HÀNG
// ==========================================
$bank_id = "MB"; // Ngân hàng (VD: MB, VCB...)
$account_no = "123426062006"; // SỐ TÀI KHOẢN 
$account_name = "NGUYEN TRAN THANH AN"; // TÊN CHỦ TÀI KHOẢN
// ==========================================

$amount = $order_data['amount'];
$order_code = "ANPLAY" . rand(1000, 9999); 
$addInfo = ($is_extension ? "Gia han " : "Thue moi ") . $order_code;

// Gọi API VietQR
$qr_url = "https://img.vietqr.io/image/{$bank_id}-{$account_no}-compact2.png?amount={$amount}&addInfo=" . urlencode($addInfo) . "&accountName=" . urlencode($account_name);

// Đóng gói Data lần cuối để nhét vào form tự động
$final_order_data = urlencode(base64_encode(json_encode($order_data)));
?>

<div class="container mt-5 mb-5" style="max-width: 550px;">
    <div class="card shadow p-4 border-0" style="background-color: #202d39; border-radius: 12px;">
        <h3 class="text-center text-white fw-bold mb-4">Quét Mã Thanh Toán</h3>
        
        <div class="alert alert-info border-info text-center shadow-sm" style="background-color: rgba(71, 191, 255, 0.1);">
            <p class="mb-1 text-light"><?php echo $is_extension ? "Gia hạn thêm <strong>{$order_data['days']} ngày</strong> cho tựa game:" : "Đơn thuê tựa game:"; ?></p>
            <h5 class="fw-bold text-info mb-3"><?php echo htmlspecialchars($game_title); ?></h5>
            <h4 class="fw-bold text-warning mb-0"><?php echo number_format($amount, 0, ',', '.'); ?> VNĐ</h4>
        </div>

        <div class="text-center bg-white p-3 rounded shadow-sm mb-4 mx-auto" style="width: 300px;">
            <img src="<?php echo $qr_url; ?>" alt="QR Code Payment" class="img-fluid rounded">
        </div>

        <div class="text-center text-light mb-4">
            <div class="spinner-border text-success spinner-border-sm mb-2" role="status"></div>
            <h6 class="text-success fw-bold blinking-text">Hệ thống đang tự động chờ nhận tiền...</h6>
            <p class="small text-muted mb-0">Nội dung CK: <strong class="text-white"><?php echo $addInfo; ?></strong></p>
        </div>

        <!-- FORM ẨN CHỨA DỮ LIỆU ĐỂ JS TỰ SUBMIT -->
        <form id="autoVerifyForm" method="POST" action="">
            <input type="hidden" name="auto_verify_payment" value="1">
            <input type="hidden" name="final_order_data" value="<?php echo $final_order_data; ?>">
            <a href="game_detail.php?id=<?php echo $game_id; ?>" class="btn btn-outline-secondary w-100 py-2" style="border-radius: 8px;">Hủy giao dịch</a>
        </form>
    </div>
</div>

<style>
@keyframes blink { 50% { opacity: 0.4; } }
.blinking-text { animation: blink 1.5s linear infinite; }
</style>

<script>
// Kịch bản demo: Tự động submit sau 15 giây
setTimeout(function() {
    document.getElementById('autoVerifyForm').submit();
}, 15000); 
</script>

<?php include 'includes/footer.php'; ?>