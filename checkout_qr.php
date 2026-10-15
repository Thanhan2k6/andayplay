<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('Vui lòng đăng nhập!'); window.location='login.php';</script>";
    exit();
}

$user_id = $_SESSION['user_id'];
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

// XỬ LÝ: Khi người dùng bấm gia hạn trực tiếp từ My Library qua POST
if (isset($_POST['btn_extend_submit'])) {
    $borrow_id_to_extend = intval($_POST['borrow_id_to_extend']);
    $extend_pkg_id = intval($_POST['extend_pkg_id']);
    
    // Kiểm tra đơn cũ thuộc về user
    $chk_stmt = $conn->prepare("SELECT br.*, g.title FROM borrow_records br JOIN games g ON br.game_id = g.game_id WHERE br.borrow_id = ? AND br.user_id = ?");
    $chk_stmt->bind_param("ii", $borrow_id_to_extend, $user_id);
    $chk_stmt->execute();
    $old_order = $chk_stmt->get_result()->fetch_assoc();
    $chk_stmt->close();

    if ($old_order) {
        $game_id = $old_order['game_id'];
        $pkg_stmt = $conn->prepare("SELECT * FROM rental_packages WHERE package_id = ? AND game_id = ?");
        $pkg_stmt->bind_param("ii", $extend_pkg_id, $game_id);
        $pkg_stmt->execute();
        $pkg = $pkg_stmt->get_result()->fetch_assoc();
        $pkg_stmt->close();

        if ($pkg) {
            $duration_hours = ($pkg['duration_type'] == 'hours') ? $pkg['duration_value'] : ($pkg['duration_value'] * 24);
            $price = $pkg['price'];
            $order_code = "EXT" . date('YmdHis') . rand(100, 999);

            $ins = $conn->prepare("INSERT INTO borrow_records 
                (order_code, user_id, game_id, package_id, duration_hours, rental_price, payment_status, payment_method, rent_type, previous_borrow_id, account_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending', 'Chuyển khoản VietQR', 'extend', ?, ?, 'pending')");
            $ins->bind_param("siiiidsii", $order_code, $user_id, $game_id, $pkg['package_id'], $duration_hours, $price, $borrow_id_to_extend, $old_order['account_id']);
            $ins->execute();
            $order_id = $conn->insert_id;
            $ins->close();

            echo "<script>window.location.href = 'checkout_qr.php?order_id=" . $order_id . "';</script>";
            exit();
        }
    }
}

// Lấy thông tin đơn hàng từ DB
$stmt = $conn->prepare("SELECT br.*, g.title, g.image_url, rp.package_name, ga.account_username 
                       FROM borrow_records br 
                       JOIN games g ON br.game_id = g.game_id 
                       LEFT JOIN rental_packages rp ON br.package_id = rp.package_id 
                       LEFT JOIN game_accounts ga ON br.account_id = ga.account_id 
                       WHERE br.borrow_id = ? AND br.user_id = ?");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    echo "<div class='container mt-5 text-center text-white'><div class='alert alert-danger'>Không tìm thấy đơn thuê hợp lệ.</div></div>";
    include 'includes/footer.php';
    exit();
}

$game_id = $order['game_id'];
$amount = floatval($order['rental_price']);
$order_code = $order['order_code'] ? $order['order_code'] : ("ORD" . $order['borrow_id']);
$is_extension = ($order['rent_type'] === 'extend');

// =========================================================================
// XỬ LÝ XÁC NHẬN THANH TOÁN (SIMULATOR / WEBHOOK XÁC THỰC SERVER-SIDE)
// =========================================================================
$allocation_error = null;
if (isset($_POST['confirm_payment_webhook'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $allocation_error = "Yêu cầu không hợp lệ hoặc phiên bảo mật hết hạn.";
    } else {
        // Kiểm tra xem đơn đã được thanh toán chưa để chống thanh toán lặp
        if ($order['payment_status'] === 'paid' && $order['status'] === 'active') {
            echo "<script>alert('Đơn này đã được thanh toán và cấp tài khoản trước đó!'); window.location='my_library.php';</script>";
            exit();
        }

        // NẾU LÀ GIA HẠN ĐƠN HIỆN CÓ:
        if ($is_extension) {
            $prev_b_id = intval($order['previous_borrow_id']);
            $prev_res = $conn->query("SELECT due_date, status, account_id FROM borrow_records WHERE borrow_id = $prev_b_id")->fetch_assoc();
            
            $add_hours = intval($order['duration_hours']);
            $curr_due = strtotime($prev_res['due_date']);
            if ($curr_due < time()) {
                $new_due_date = date('Y-m-d H:i:s', strtotime("+$add_hours hours"));
            } else {
                $new_due_date = date('Y-m-d H:i:s', strtotime("+$add_hours hours", $curr_due));
            }

            $conn->begin_transaction();
            try {
                // Cập nhật đơn cũ
                $upd_prev = $conn->prepare("UPDATE borrow_records SET due_date = ?, status = 'active' WHERE borrow_id = ?");
                $upd_prev->bind_param("si", $new_due_date, $prev_b_id);
                $upd_prev->execute();
                $upd_prev->close();

                // Cập nhật tài khoản game
                $acc_id = intval($prev_res['account_id']);
                $upd_acc = $conn->prepare("UPDATE game_accounts SET status = 'RENTED', rent_expires_at = ?, updated_at = NOW() WHERE account_id = ?");
                $upd_acc->bind_param("si", $new_due_date, $acc_id);
                $upd_acc->execute();
                $upd_acc->close();

                // Cập nhật đơn gia hạn hiện tại
                $upd_this = $conn->prepare("UPDATE borrow_records SET payment_status = 'paid', status = 'active', due_date = ? WHERE borrow_id = ?");
                $upd_this->bind_param("si", $new_due_date, $order_id);
                $upd_this->execute();
                $upd_this->close();

                log_account_allocation($conn, $acc_id, $order_id, $user_id, 'EXTENDED', "Gia hạn thêm $add_hours giờ (hạn mới: $new_due_date)", 'SYSTEM');

                $conn->commit();
                echo "<script>alert('🎉 GIA HẠN THÀNH CÔNG! Thời gian sử dụng tài khoản đã được kéo dài đến: " . date('d/m/Y H:i', strtotime($new_due_date)) . "'); window.location='my_library.php';</script>";
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $allocation_error = "Lỗi khi xử lý gia hạn: " . $e->getMessage();
            }
        } 
        // NẾU LÀ THUÊ MỚI HOẶC THUÊ LẠI TÀI KHOẢN CŨ:
        else {
            $duration_hours = intval($order['duration_hours']);
            $pref_acc = !empty($order['account_id']) ? intval($order['account_id']) : null;

            // Thực hiện cấp phát tài khoản an toàn qua Service
            $alloc_result = allocate_game_account($conn, $game_id, $user_id, $order_id, $duration_hours, $pref_acc);

            if ($alloc_result['success']) {
                echo "<script>alert('🎉 THANH TOÁN THÀNH CÔNG! Hệ thống đã cấp thông tin tài khoản game cho bạn.'); window.location='my_library.php';</script>";
                exit();
            } else {
                $allocation_error = $alloc_result['error'];
                // Đánh dấu đơn thất bại để admin hỗ trợ
                $conn->query("UPDATE borrow_records SET payment_status = 'paid', status = 'pending', error_message = '" . $conn->real_escape_string($allocation_error) . "' WHERE borrow_id = $order_id");
            }
        }
    }
}

// Cấu hình VietQR
$bank_id = "MB";
$account_no = "123426062006";
$account_name = "NGUYEN TRAN THANH AN";
$addInfo = "ANPLAY " . $order_code;
$qr_url = "https://img.vietqr.io/image/{$bank_id}-{$account_no}-compact2.png?amount={$amount}&addInfo=" . urlencode($addInfo) . "&accountName=" . urlencode($account_name);
$csrf_token = generate_csrf_token();
?>

<div class="container mt-5 mb-5" style="max-width: 600px;">
    <div class="card shadow p-4 border-0" style="background-color: #1a222c; border-radius: 12px; border: 1px solid #2d3b4e;">
        <h3 class="text-center text-white fw-bold mb-4">Xác Nhận & Quét Mã Thanh Toán</h3>

        <?php if ($allocation_error): ?>
            <div class="alert alert-danger shadow-sm mb-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Lỗi cấp phát:</strong> <?php echo htmlspecialchars($allocation_error); ?>
                <div class="small mt-1 text-light">Đơn hàng của bạn đã được ghi nhận vào hệ thống. Vui lòng liên hệ bộ phận hỗ trợ hoặc thử lại.</div>
            </div>
        <?php endif; ?>
        
        <div class="alert alert-info border-info text-center shadow-sm" style="background-color: rgba(71, 191, 255, 0.1);">
            <p class="mb-1 text-light">
                <?php echo $is_extension ? "Gia hạn tài khoản tựa game:" : "Thuê tài khoản tựa game:"; ?>
            </p>
            <h4 class="fw-bold text-info mb-2"><?php echo htmlspecialchars($order['title']); ?></h4>
            <p class="text-secondary small mb-2">
                Gói: <strong class="text-light"><?php echo htmlspecialchars($order['package_name'] ?? ($order['duration_hours'] . ' Giờ')); ?></strong>
                | Mã đơn: <strong class="text-warning">#<?php echo htmlspecialchars($order_code); ?></strong>
            </p>
            <h3 class="fw-bold text-warning mb-0"><?php echo number_format($amount, 0, ',', '.'); ?> VNĐ</h3>
        </div>

        <div class="text-center bg-white p-3 rounded shadow-sm mb-4 mx-auto" style="width: 290px;">
            <img src="<?php echo $qr_url; ?>" alt="VietQR Payment" class="img-fluid rounded">
        </div>

        <div class="text-center text-light mb-4">
            <div class="spinner-border text-success spinner-border-sm mb-2" role="status"></div>
            <h6 class="text-success fw-bold blinking-text">Hệ thống đang sẵn sàng ghi nhận thanh toán...</h6>
            <p class="small text-muted mb-0">Nội dung chuyển khoản: <strong class="text-white user-select-all"><?php echo $addInfo; ?></strong></p>
        </div>

        <!-- FORM XÁC NHẬN THANH TOÁN (HỖ TRỢ WEBHOOK VÀ NÚT XÁC THỰC MÔ PHỎNG) -->
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="confirm_payment_webhook" value="1">
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-success fw-bold py-3 fs-5 shadow" style="border-radius: 8px;">
                    ✅ TÔI ĐÃ CHUYỂN KHOẢN THÀNH CÔNG
                </button>
                <a href="game_detail.php?id=<?php echo $game_id; ?>" class="btn btn-outline-secondary py-2 mt-1" style="border-radius: 8px;">Hủy giao dịch</a>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes blink { 50% { opacity: 0.4; } }
.blinking-text { animation: blink 1.5s linear infinite; }
</style>

<?php include 'includes/footer.php'; ?>