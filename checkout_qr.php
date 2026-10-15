<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/header.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('Vui lòng đăng nhập!'); window.location='login.php';</script>";
    exit();
}

$user_id = $_SESSION['user_id'];
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

// Xử lý tạo đơn gia hạn mới nếu được submit từ trang my_library.php
if (isset($_POST['btn_extend_submit'])) {
    $borrow_id_to_extend = intval($_POST['borrow_id_to_extend'] ?? 0);
    $extend_pkg_id = intval($_POST['extend_pkg_id'] ?? 0);

    $old_order_q = $conn->prepare("SELECT * FROM borrow_records WHERE borrow_id = ? AND user_id = ?");
    $old_order_q->bind_param("ii", $borrow_id_to_extend, $user_id);
    $old_order_q->execute();
    $old_order = $old_order_q->get_result()->fetch_assoc();
    $old_order_q->close();

    if ($old_order) {
        $game_id = $old_order['game_id'];
        $pkg_res = $conn->query("SELECT * FROM rental_packages WHERE package_id = $extend_pkg_id AND game_id = $game_id");
        if ($pkg_res && $pkg = $pkg_res->fetch_assoc()) {
            $duration_hours = ($pkg['duration_type'] == 'hours') ? $pkg['duration_value'] : ($pkg['duration_value'] * 24);
            $price = floatval($pkg['price']);
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
    echo "<div class='container mt-5 text-center text-white'><div class='panel-dark p-5'><h3 class='text-danger'>Không tìm thấy đơn thuê hợp lệ.</h3><a href='index.php' class='btn btn-gaming-primary mt-3'>Về Cửa Hàng</a></div></div>";
    include 'includes/footer.php';
    exit();
}

$game_id = $order['game_id'];
$amount = floatval($order['rental_price']);
$order_code = $order['order_code'] ? $order['order_code'] : ("ORD" . $order['borrow_id']);
$is_extension = ($order['rent_type'] === 'extend');

// XỬ LÝ XÁC NHẬN THANH TOÁN
$allocation_error = null;
if (isset($_POST['confirm_payment_webhook'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $allocation_error = "Phiên bảo mật đã hết hạn. Vui lòng thử lại.";
    } else {
        if ($order['payment_status'] === 'paid' && $order['status'] === 'active') {
            echo "<script>alert('Đơn này đã được thanh toán và cấp tài khoản trước đó!'); window.location='my_library.php';</script>";
            exit();
        }

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
                $upd_prev = $conn->prepare("UPDATE borrow_records SET due_date = ?, status = 'active' WHERE borrow_id = ?");
                $upd_prev->bind_param("si", $new_due_date, $prev_b_id);
                $upd_prev->execute();
                $upd_prev->close();

                $acc_id = intval($prev_res['account_id']);
                $upd_acc = $conn->prepare("UPDATE game_accounts SET status = 'RENTED', rent_expires_at = ?, updated_at = NOW() WHERE account_id = ?");
                $upd_acc->bind_param("si", $new_due_date, $acc_id);
                $upd_acc->execute();
                $upd_acc->close();

                $upd_this = $conn->prepare("UPDATE borrow_records SET payment_status = 'paid', status = 'active', due_date = ? WHERE borrow_id = ?");
                $upd_this->bind_param("si", $new_due_date, $order_id);
                $upd_this->execute();
                $upd_this->close();

                log_account_allocation($conn, $acc_id, $order_id, $user_id, 'EXTENDED', "Gia hạn thêm $add_hours giờ (hạn mới: $new_due_date)", 'SYSTEM');

                $conn->commit();
                echo "<script>alert('🎉 GIA HẠN THÀNH CÔNG! Thời gian sử dụng đã được kéo dài đến: " . date('d/m/Y H:i', strtotime($new_due_date)) . "'); window.location='my_library.php';</script>";
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $allocation_error = "Lỗi khi xử lý gia hạn: " . $e->getMessage();
            }
        } else {
            $duration_hours = intval($order['duration_hours']);
            $pref_acc = !empty($order['account_id']) ? intval($order['account_id']) : null;

            $alloc_result = allocate_game_account($conn, $game_id, $user_id, $order_id, $duration_hours, $pref_acc);

            if ($alloc_result['success']) {
                echo "<script>alert('🎉 THANH TOÁN THÀNH CÔNG! Hệ thống đã cấp thông tin tài khoản game cho bạn.'); window.location='my_library.php';</script>";
                exit();
            } else {
                $allocation_error = $alloc_result['error'];
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

<div class="container my-4" style="max-width: 860px;">
    <!-- Step Indicator Progress Bar -->
    <div class="d-flex align-items-center justify-content-center gap-3 mb-4">
        <span class="badge bg-secondary bg-opacity-25 text-secondary px-3 py-2 fs-6 rounded-pill">1. Chọn Gói Thuê</span>
        <i class="bi bi-chevron-right text-secondary"></i>
        <span class="badge bg-primary px-3 py-2 fs-6 rounded-pill">2. Quét Mã VietQR</span>
        <i class="bi bi-chevron-right text-secondary"></i>
        <span class="badge bg-secondary bg-opacity-25 text-secondary px-3 py-2 fs-6 rounded-pill">3. Nhận Tài Khoản</span>
    </div>

    <div class="panel-dark p-4 p-md-5 shadow-lg">
        <div class="text-center mb-4">
            <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 px-3 py-2 fw-bold mb-2">
                <i class="bi bi-qr-code me-1"></i> CỔNG THANH TOÁN TỰ ĐỘNG VIETQR
            </span>
            <h3 class="fw-bold text-white mb-1">Quét Mã QR Để Hoàn Tất Đơn Thuê</h3>
            <p class="text-secondary small mb-0">Hệ thống tự động kích hoạt tài khoản game ngay sau khi nhận tiền</p>
        </div>

        <?php if ($allocation_error): ?>
            <div class="alert alert-danger shadow-sm mb-4 border-0" style="background: rgba(244, 63, 94, 0.15); border: 1px solid rgba(244, 63, 94, 0.4) !important;">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i><strong>Lỗi cấp phát:</strong> <?php echo htmlspecialchars($allocation_error); ?>
                <div class="small mt-1 text-light">Đơn hàng của bạn đã được lưu an toàn. Vui lòng liên hệ Admin để nhận tài khoản thay thế.</div>
            </div>
        <?php endif; ?>

        <div class="row g-4 align-items-center">
            <!-- Cột Trái: Ảnh QR Code -->
            <div class="col-md-5 text-center">
                <div class="p-3 bg-white rounded-4 shadow-lg mx-auto" style="max-width: 280px;">
                    <img src="<?php echo $qr_url; ?>" alt="VietQR Payment" class="img-fluid rounded-3 w-100">
                </div>
                <div class="mt-3">
                    <span class="badge bg-dark border border-secondary text-light px-3 py-2">
                        <i class="bi bi-clock-history text-warning me-1"></i> Đơn hết hạn sau: <span id="paymentCountdown" class="text-warning fw-bold">14:59</span>
                    </span>
                </div>
            </div>

            <!-- Cột Phải: Thông tin chuyển khoản chi tiết -->
            <div class="col-md-7">
                <div class="p-4 rounded-4" style="background: #0b0d13; border: 1px solid var(--border-subtle);">
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                        <span class="text-secondary small">Trò chơi:</span>
                        <strong class="text-info"><?php echo htmlspecialchars($order['title']); ?></strong>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                        <span class="text-secondary small">Ngân hàng thụ hưởng:</span>
                        <strong class="text-white">MB Bank (Quân Đội)</strong>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                        <span class="text-secondary small">Số tài khoản:</span>
                        <div class="d-flex align-items-center gap-2">
                            <strong class="text-white user-select-all" id="accNoText"><?php echo $account_no; ?></strong>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="copyToClipboard('<?php echo $account_no; ?>', this)">
                                <i class="bi bi-copy"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                        <span class="text-secondary small">Số tiền cần chuyển:</span>
                        <div class="d-flex align-items-center gap-2">
                            <strong class="gaming-card-price-val fs-5" id="amountText"><?php echo number_format($amount, 0, ',', '.'); ?>đ</strong>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="copyToClipboard('<?php echo $amount; ?>', this)">
                                <i class="bi bi-copy"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-secondary small">Nội dung chuyển khoản:</span>
                        <div class="d-flex align-items-center gap-2">
                            <strong class="text-warning user-select-all fw-bold" id="memoText"><?php echo $addInfo; ?></strong>
                            <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2" onclick="copyToClipboard('<?php echo $addInfo; ?>', this)">
                                <i class="bi bi-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning py-2 mt-3 small mb-0 border-0" style="background: rgba(245, 158, 11, 0.1); color: #fde68a;">
                    <i class="bi bi-exclamation-circle-fill me-1"></i>
                    <strong>Quan trọng:</strong> Vui lòng giữ nguyên nội dung chuyển khoản để hệ thống đối soát tự động.
                </div>
            </div>
        </div>

        <!-- Nút Xác Nhận Thanh Toán -->
        <div class="mt-4 pt-3 border-top border-white border-opacity-10">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="confirm_payment_webhook" value="1">
                
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-gaming-primary py-3 fs-5 fw-bold shadow">
                        <i class="bi bi-check-circle-fill me-2 fs-5"></i> XÁC NHẬN ĐÃ CHUYỂN KHOẢN (LẤY TÀI KHOẢN NGAY)
                    </button>
                    <a href="game_detail.php?id=<?php echo $game_id; ?>" class="btn btn-gaming-secondary py-2 text-center text-secondary">
                        <i class="bi bi-x me-1"></i> Hủy giao dịch này
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Hàm sao chép tiện lợi
function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(function() {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check text-success"></i>';
        setTimeout(() => { btn.innerHTML = originalHtml; }, 2000);
    });
}

// Đồng hồ đếm ngược 15 phút
let timeLeft = 15 * 60;
const timerDisplay = document.getElementById('paymentCountdown');
if (timerDisplay) {
    const timerInterval = setInterval(function() {
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            timerDisplay.innerText = "HẾT GIỜ";
        } else {
            timeLeft--;
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            timerDisplay.innerText = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
    }, 1000);
}
</script>

<?php include 'includes/footer.php'; ?>