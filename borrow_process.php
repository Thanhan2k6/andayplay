<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php'; 

if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('Vui lòng đăng nhập tài khoản để thuê tài khoản game!'); window.location='login.php';</script>";
    exit();
}

if (!isset($_GET['game_id'])) {
    echo "<script>window.location='index.php';</script>";
    exit();
}

$game_id = intval($_GET['game_id']);
$user_id = $_SESSION['user_id'];

// Kiểm tra xem khách hàng có muốn thuê lại một tài khoản cụ thể không (tùy chọn)
$preferred_account_id = isset($_GET['re_rent_acc']) ? intval($_GET['re_rent_acc']) : null;
$rent_type = 'new';
if (isset($_GET['type']) && in_array($_GET['type'], ['new', 're-rent', 'extend'])) {
    $rent_type = $_GET['type'];
}

// BẢO VỆ NGƯỜI DÙNG: Kiểm tra xem họ đang có đơn thuê tài khoản còn hiệu lực của tựa game này không
$check_sql = "SELECT br.*, ga.account_username 
              FROM borrow_records br 
              LEFT JOIN game_accounts ga ON br.account_id = ga.account_id 
              WHERE br.user_id = $user_id AND br.game_id = $game_id AND br.status = 'active' AND br.due_date > NOW()";
$check_res = $conn->query($check_sql);
if ($check_res && $check_res->num_rows > 0) {
    echo "<script>alert('Bạn đang có đơn thuê còn hiệu lực của tựa game này! Hệ thống chuyển bạn đến Thư viện để xem thông tin đăng nhập.'); window.location='my_library.php';</script>";
    exit();
}

$result = $conn->query("SELECT * FROM games WHERE game_id = $game_id AND price_type = 'paid'");
if (!$result || $result->num_rows == 0) {
    echo "<div class='container mt-5'><div class='alert alert-danger text-center shadow-sm'>Tựa game không tồn tại hoặc không hỗ trợ cho thuê tài khoản.</div></div>";
    include 'includes/footer.php';
    exit();
}
$game = $result->fetch_assoc();

// Đồng bộ và kiểm tra kho tài khoản thực tế
sync_game_inventory($conn, $game_id);
$acc_check = $conn->query("SELECT COUNT(*) as available_count FROM game_accounts WHERE game_id = $game_id AND status = 'AVAILABLE'")->fetch_assoc();
$available_accounts = intval($acc_check['available_count'] ?? 0);

// Nếu là thuê lại tài khoản cũ: Kiểm tra tính hợp lệ và quyền sở hữu trước đó của khách hàng (chống IDOR)
$old_acc_info = null;
if ($preferred_account_id) {
    // Xác minh khách hàng này thực sự từng thuê tài khoản này trước đây
    $own_check = $conn->prepare("SELECT borrow_id FROM borrow_records WHERE user_id = ? AND game_id = ? AND account_id = ? LIMIT 1");
    $own_check->bind_param("iii", $user_id, $game_id, $preferred_account_id);
    $own_check->execute();
    $own_res = $own_check->get_result();
    $has_rented_before = ($own_res && $own_res->num_rows > 0);
    $own_check->close();

    if ($has_rented_before) {
        $old_acc_stmt = $conn->prepare("SELECT * FROM game_accounts WHERE account_id = ? AND game_id = ?");
        $old_acc_stmt->bind_param("ii", $preferred_account_id, $game_id);
        $old_acc_stmt->execute();
        $old_acc_info = $old_acc_stmt->get_result()->fetch_assoc();
        $old_acc_stmt->close();
    } else {
        // Nếu không thuộc lịch sử thuê của khách, từ chối cấp ưu tiên tài khoản này và chuyển sang thuê mới
        $preferred_account_id = null;
        $rent_type = 'new';
    }
}

// Lấy danh sách gói thuê từ bảng rental_packages
$packages_res = $conn->query("SELECT * FROM rental_packages WHERE game_id = $game_id AND is_active = 1 ORDER BY price ASC");
$packages = [];
if ($packages_res && $packages_res->num_rows > 0) {
    while ($p = $packages_res->fetch_assoc()) {
        $packages[] = $p;
    }
} else {
    // Gói mặc định nếu chưa cấu hình gói
    $packages = [
        ['package_id' => 1, 'package_name' => '1 Giờ (Trải nghiệm nhanh)', 'duration_type' => 'hours', 'duration_value' => 1, 'price' => 5000],
        ['package_id' => 2, 'package_name' => '1 Ngày (24 Giờ)', 'duration_type' => 'days', 'duration_value' => 1, 'price' => 15000],
        ['package_id' => 3, 'package_name' => '7 Ngày (1 Tuần)', 'duration_type' => 'days', 'duration_value' => 7, 'price' => 30000],
        ['package_id' => 4, 'package_name' => '30 Ngày (1 Tháng)', 'duration_type' => 'days', 'duration_value' => 30, 'price' => 99000],
    ];
}

// XỬ LÝ: Khi người dùng bấm Tiến hành Thanh toán
if (isset($_POST['btn_borrow'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo "<script>alert('Yêu cầu không hợp lệ (CSRF check failed)! Vui lòng thử lại.');</script>";
    } else {
        $selected_pkg_id = intval($_POST['package_id']);
        $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'Ví MoMo');
        
        $selected_pkg = null;
        foreach ($packages as $pkg) {
            if ($pkg['package_id'] == $selected_pkg_id) {
                $selected_pkg = $pkg;
                break;
            }
        }
        
        if (!$selected_pkg) {
            $selected_pkg = $packages[0];
        }

        $duration_hours = ($selected_pkg['duration_type'] == 'hours') ? $selected_pkg['duration_value'] : ($selected_pkg['duration_value'] * 24);
        $total_cost = floatval($selected_pkg['price']);

        // Kiểm tra tính khả dụng của kho
        $can_proceed = false;
        if ($preferred_account_id) {
            if ($old_acc_info && in_array($old_acc_info['status'], ['AVAILABLE', 'EXPIRED'])) {
                $can_proceed = true;
            } else {
                echo "<script>alert('Tài khoản cũ hiện không khả dụng (đang có người thuê hoặc đang bảo trì)!');</script>";
            }
        } else {
            if ($available_accounts > 0) {
                $can_proceed = true;
            } else {
                echo "<script>alert('Rất tiếc, tựa game này hiện đã hết tài khoản sẵn sàng cho thuê!');</script>";
            }
        }

        if ($can_proceed) {
            // Tạo mã đơn ngẫu nhiên duy nhất
            $order_code = "RENT" . date('YmdHis') . rand(100, 999);
            
            // Xác thực đơn thuê cũ thuộc quyền sở hữu của chính user
            $prev_b_id = null;
            if (isset($_GET['old_borrow_id']) && $preferred_account_id) {
                $chk_prev = $conn->prepare("SELECT borrow_id FROM borrow_records WHERE borrow_id = ? AND user_id = ?");
                $cand_id = intval($_GET['old_borrow_id']);
                $chk_prev->bind_param("ii", $cand_id, $user_id);
                $chk_prev->execute();
                if ($chk_prev->get_result()->num_rows > 0) {
                    $prev_b_id = $cand_id;
                }
                $chk_prev->close();
            }

            // Tạo bản ghi đơn thuê ở trạng thái 'pending' trong Database
            $insert_order = $conn->prepare("INSERT INTO borrow_records 
                (order_code, user_id, game_id, package_id, duration_hours, rental_price, payment_status, payment_method, rent_type, previous_borrow_id, account_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, 'pending')");

            $insert_order->bind_param("siiiidssii", $order_code, $user_id, $game_id, $selected_pkg['package_id'], $duration_hours, $total_cost, $payment_method, $rent_type, $prev_b_id, $preferred_account_id);
            $insert_order->execute();
            $new_borrow_id = $conn->insert_id;
            $insert_order->close();

            // Chuyển hướng sang trang quét mã thanh toán xác minh
            echo "<script>window.location.href = 'checkout_qr.php?order_id=" . $new_borrow_id . "';</script>";
            exit();
        }
    }
}
$csrf_token = generate_csrf_token();
?>

<div class="container mt-5 mb-5" style="max-width: 650px;">
    <h2 class="mb-4 text-center text-white fw-bold">Thuê Tài Khoản Game Trả Phí</h2>
    
    <div class="card shadow-lg p-4 border-secondary border-opacity-25" style="background-color: #1a222c; border-radius: 12px;">
        <div class="text-center mb-4">
            <?php if(!empty($game['image_url']) && file_exists($game['image_url'])) { ?>
                <img src="<?php echo $game['image_url']; ?>" alt="<?php echo htmlspecialchars($game['title']); ?>" style="height: 150px; border-radius: 8px; object-fit: cover; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
            <?php } ?>
            <h4 class="text-info fw-bold mt-3"><?php echo htmlspecialchars($game['title']); ?></h4>
            
            <?php if ($preferred_account_id && $old_acc_info): ?>
                <div class="badge bg-warning text-dark px-3 py-2 fs-6 mt-1">
                    <i class="bi bi-arrow-repeat me-1"></i> Thuê lại tài khoản cũ: <strong><?php echo htmlspecialchars($old_acc_info['account_username']); ?></strong>
                </div>
            <?php else: ?>
                <span class="badge <?php echo ($available_accounts > 0) ? 'bg-success' : 'bg-danger'; ?> px-3 py-2 fs-6 mt-1">
                    <i class="bi bi-person-badge me-1"></i> Kho tài khoản khả dụng: <?php echo $available_accounts; ?> tài khoản
                </span>
            <?php endif; ?>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="mb-4">
                <label class="form-label text-light fw-bold mb-2">⏳ Chọn gói thời gian thuê tài khoản:</label>
                <select name="package_id" id="packageSelect" class="form-select bg-dark text-white border-secondary shadow-sm py-2" required onchange="updatePrice()">
                    <?php foreach ($packages as $pkg): ?>
                        <option value="<?php echo $pkg['package_id']; ?>" data-price="<?php echo $pkg['price']; ?>">
                            <?php echo htmlspecialchars($pkg['package_name']); ?> - <?php echo number_format($pkg['price'], 0, ',', '.'); ?>đ
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text text-secondary mt-1">
                    *Thời gian thuê bắt đầu được tính sau khi hệ thống xác nhận thanh toán và cấp phát tài khoản thành công.
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label text-light fw-bold mb-2">💳 Phương thức thanh toán:</label>
                <div class="p-3 rounded shadow-sm" style="background-color: #151d26; border: 1px solid #2d3b4e;">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="payment_method" id="pay_bank" value="Chuyển khoản Ngân hàng (VietQR)" checked>
                        <label class="form-check-label text-white ms-1" for="pay_bank">🏦 Chuyển khoản VietQR (Tự động nhận diện)</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="payment_method" id="pay_momo" value="Ví MoMo">
                        <label class="form-check-label text-white ms-1" for="pay_momo">🟣 Thanh toán qua Ví MoMo</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="pay_vnpay" value="VNPAY">
                        <label class="form-check-label text-white ms-1" for="pay_vnpay">🔵 Cổng thanh toán VNPAY (QR Code)</label>
                    </div>
                </div>
            </div>

            <div class="alert alert-info border-info mb-4 shadow-sm" style="background-color: rgba(71, 191, 255, 0.1);">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-info fs-5">Tổng thanh toán:</span>
                    <h3 class="mb-0 fw-bold text-warning" id="totalPriceDisplay"><?php echo number_format($packages[0]['price'], 0, ',', '.'); ?> VNĐ</h3>
                </div>
            </div>
            
            <div class="d-grid gap-2">
                <?php if ($available_accounts > 0 || ($preferred_account_id && $old_acc_info && in_array($old_acc_info['status'], ['AVAILABLE', 'EXPIRED']))): ?>
                    <button type="submit" name="btn_borrow" class="btn btn-primary fw-bold py-3 fs-5 shadow" style="background: linear-gradient(to right, #00c6ff, #0072ff); border: none; border-radius: 8px;">
                        🔒 TIẾN HÀNH THANH TOÁN & NHẬN TÀI KHOẢN
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-secondary fw-bold py-3 fs-5" disabled>
                        ❌ HẾT TÀI KHOẢN KHẢ DỤNG
                    </button>
                <?php endif; ?>
                <a href="game_detail.php?id=<?php echo $game_id; ?>" class="btn btn-outline-secondary py-2 mt-2 fw-bold" style="border-radius: 8px;">Hủy bỏ - Trở về</a>
            </div>
        </form>
    </div>
</div>

<script>
function updatePrice() {
    var select = document.getElementById("packageSelect");
    var price = select.options[select.selectedIndex].getAttribute("data-price");
    document.getElementById("totalPriceDisplay").innerText = new Intl.NumberFormat('vi-VN').format(price) + " VNĐ";
}
</script>

<?php include 'includes/footer.php'; ?>