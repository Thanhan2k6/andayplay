<?php 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
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
    echo "<div class='container mt-5 text-center'><div class='panel-dark p-5'><h3 class='text-danger'>Tựa game không tồn tại hoặc không hỗ trợ cho thuê tài khoản.</h3><a href='index.php' class='btn btn-gaming-primary mt-3'>Về Cửa Hàng</a></div></div>";
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
        $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'Chuyển khoản Ngân hàng (VietQR)');
        
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
            $order_code = "RENT" . date('YmdHis') . rand(100, 999);
            
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

            // Tạo bản ghi đơn thuê ở trạng thái 'pending'
            $insert_order = $conn->prepare("INSERT INTO borrow_records 
                (order_code, user_id, game_id, package_id, duration_hours, rental_price, payment_status, payment_method, rent_type, previous_borrow_id, account_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, 'pending')");

            $insert_order->bind_param("siiiidssii", $order_code, $user_id, $game_id, $selected_pkg['package_id'], $duration_hours, $total_cost, $payment_method, $rent_type, $prev_b_id, $preferred_account_id);
            $insert_order->execute();
            $new_borrow_id = $conn->insert_id;
            $insert_order->close();

            echo "<script>window.location.href = 'checkout_qr.php?order_id=" . $new_borrow_id . "';</script>";
            exit();
        }
    }
}
$csrf_token = generate_csrf_token();
?>

<div class="container my-4" style="max-width: 960px;">
    <!-- Step Indicator Progress Bar -->
    <div class="d-flex align-items-center justify-content-center gap-3 mb-4">
        <span class="badge bg-primary px-3 py-2 fs-6 rounded-pill">1. Chọn Gói Thuê</span>
        <i class="bi bi-chevron-right text-secondary"></i>
        <span class="badge bg-secondary bg-opacity-25 text-secondary px-3 py-2 fs-6 rounded-pill">2. Quét Mã VietQR</span>
        <i class="bi bi-chevron-right text-secondary"></i>
        <span class="badge bg-secondary bg-opacity-25 text-secondary px-3 py-2 fs-6 rounded-pill">3. Nhận Tài Khoản</span>
    </div>

    <div class="row g-4">
        <!-- Cột trái: Tóm tắt Game -->
        <div class="col-lg-5">
            <div class="panel-dark p-4 h-100 d-flex flex-column">
                <div class="rounded-3 overflow-hidden mb-3 border border-secondary border-opacity-25">
                    <?php if(!empty($game['image_url']) && file_exists($game['image_url'])) { ?>
                        <img src="<?php echo $game['image_url']; ?>" alt="<?php echo htmlspecialchars($game['title']); ?>" class="w-100 object-fit-cover" style="height: 200px;">
                    <?php } else { ?>
                        <div class="d-flex align-items-center justify-content-center bg-dark text-muted" style="height: 200px;">
                            <i class="bi bi-controller fs-1"></i>
                        </div>
                    <?php } ?>
                </div>

                <h4 class="fw-bold text-white mb-2"><?php echo htmlspecialchars($game['title']); ?></h4>
                <div class="d-flex gap-2 mb-3">
                    <span class="badge-platform"><?php echo htmlspecialchars($game['platform']); ?></span>
                    <?php if ($preferred_account_id && $old_acc_info): ?>
                        <span class="badge bg-warning text-dark fw-bold">🔁 Thuê lại TK cũ</span>
                    <?php else: ?>
                        <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25">
                            ⚡ Kho: <?php echo $available_accounts; ?> tài khoản
                        </span>
                    <?php endif; ?>
                </div>

                <div class="p-3 rounded-3 mt-auto" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-subtle);">
                    <div class="text-secondary small fw-bold mb-2">QUYỀN LỢI CỦA BẠN:</div>
                    <ul class="text-secondary small mb-0 ps-3" style="line-height: 1.7;">
                        <li>Nhận tài khoản & mật khẩu tức thì</li>
                        <li>Bảo hành 100% thời gian thuê</li>
                        <li>Được hỗ trợ kỹ thuật 24/7</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Cột phải: Form chọn gói & Thanh toán -->
        <div class="col-lg-7">
            <div class="panel-dark p-4 p-md-5">
                <h4 class="fw-bold text-white mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-receipt text-primary"></i> Thiết Lập Đơn Thuê
                </h4>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <!-- Chọn Gói Thời Gian -->
                    <div class="mb-4">
                        <label class="form-label text-white fw-bold mb-2">
                            <i class="bi bi-clock-history text-warning me-1"></i> Chọn thời hạn thuê mong muốn:
                        </label>
                        <select name="package_id" id="packageSelect" class="form-select py-2 fs-6" required onchange="updatePrice()">
                            <?php foreach ($packages as $pkg): ?>
                                <option value="<?php echo $pkg['package_id']; ?>" data-price="<?php echo $pkg['price']; ?>">
                                    <?php echo htmlspecialchars($pkg['package_name']); ?> — <?php echo number_format($pkg['price'], 0, ',', '.'); ?>đ
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-dim d-block mt-1">Thời gian thuê được tính chính xác từng phút ngay sau khi hệ thống cấp tài khoản.</small>
                    </div>

                    <!-- Phương thức thanh toán -->
                    <div class="mb-4">
                        <label class="form-label text-white fw-bold mb-2">
                            <i class="bi bi-credit-card-2-front-fill text-info me-1"></i> Phương thức thanh toán:
                        </label>
                        <div class="p-3 rounded-3 border border-secondary border-opacity-25" style="background: #0b0d13;">
                            <div class="form-check d-flex align-items-center gap-2 mb-2">
                                <input class="form-check-input" type="radio" name="payment_method" id="pay_vietqr" value="Chuyển khoản Ngân hàng (VietQR)" checked>
                                <label class="form-check-label text-white fw-bold" for="pay_vietqr">
                                    <span class="text-success me-1">⚡</span> Chuyển khoản VietQR (Tự động nhận diện 24/7)
                                </label>
                            </div>
                            <div class="form-check d-flex align-items-center gap-2">
                                <input class="form-check-input" type="radio" name="payment_method" id="pay_momo" value="Ví MoMo">
                                <label class="form-check-label text-white" for="pay_momo">
                                    🟣 Ví điện tử MoMo
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Khung Tổng Thanh Toán -->
                    <div class="p-3 rounded-3 mb-4 d-flex justify-content-between align-items-center" style="background: rgba(99, 102, 241, 0.12); border: 1px solid rgba(99, 102, 241, 0.35);">
                        <div>
                            <span class="text-secondary small d-block">TỔNG TIỀN THANH TOÁN</span>
                            <span class="text-white fw-bold">Không phát sinh phụ phí</span>
                        </div>
                        <div class="text-end">
                            <span class="gaming-card-price-val fs-3" id="totalPriceDisplay">
                                <?php echo number_format($packages[0]['price'], 0, ',', '.'); ?>đ
                            </span>
                        </div>
                    </div>

                    <!-- Nút Tiến Hành -->
                    <div class="d-grid gap-2">
                        <?php if ($available_accounts > 0 || ($preferred_account_id && $old_acc_info && in_array($old_acc_info['status'], ['AVAILABLE', 'EXPIRED']))): ?>
                            <button type="submit" name="btn_borrow" class="btn btn-gaming-primary py-3 fs-5 fw-bold">
                                <i class="bi bi-shield-lock-fill me-2"></i> XÁC NHẬN & QUÉT MÃ THANH TOÁN
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary py-3 fs-5 fw-bold" disabled>
                                ❌ HIỆN ĐÃ HẾT TÀI KHOẢN KHẢ DỤNG
                            </button>
                        <?php endif; ?>
                        <a href="game_detail.php?id=<?php echo $game_id; ?>" class="btn btn-gaming-secondary py-2 mt-1 text-center">
                            <i class="bi bi-arrow-left me-1"></i> Quay Lại Trang Chi Tiết
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function updatePrice() {
    var select = document.getElementById("packageSelect");
    var price = select.options[select.selectedIndex].getAttribute("data-price");
    document.getElementById("totalPriceDisplay").innerText = new Intl.NumberFormat('vi-VN').format(price) + "đ";
}
</script>

<?php include 'includes/footer.php'; ?>