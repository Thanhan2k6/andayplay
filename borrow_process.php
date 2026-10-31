<?php 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/connect.php'; 

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['game_id'])) {
    header("Location: index.php");
    exit();
}

$game_id = intval($_GET['game_id']);
$user_id = intval($_SESSION['user_id']);

// Kiểm tra xem khách hàng có muốn thuê lại một tài khoản cụ thể không (tùy chọn)
$preferred_account_id = isset($_GET['re_rent_acc']) ? intval($_GET['re_rent_acc']) : null;
$rent_type = 'new';
if (isset($_GET['type']) && in_array($_GET['type'], ['new', 're-rent', 'extend'])) {
    $rent_type = $_GET['type'];
}

// BẢO VỆ NGƯỜI DÙNG: Kiểm tra xem họ đang có đơn thuê tài khoản còn hiệu lực của tựa game này không
$is_already_rented = false;
try {
    $check_sql = "SELECT borrow_id FROM borrow_records WHERE user_id = $user_id AND game_id = $game_id AND status = 'active' AND due_date > NOW() LIMIT 1";
    $check_res = $conn->query($check_sql);
    if ($check_res && $check_res->num_rows > 0) {
        $is_already_rented = true;
    }
} catch (Throwable $e) {}

if ($is_already_rented) {
    header("Location: my_library.php");
    exit();
}

$game = null;
try {
    $result = $conn->query("SELECT * FROM games WHERE game_id = $game_id");
    if ($result && $result->num_rows > 0) {
        $game = $result->fetch_assoc();
    }
} catch (Throwable $e) {}

// Đồng bộ và kiểm tra kho tài khoản thực tế
$available_accounts = intval($game['quantity'] ?? 0);
try {
    if (function_exists('sync_game_inventory')) {
        sync_game_inventory($conn, $game_id);
    }
    $acc_check = $conn->query("SELECT COUNT(*) as available_count FROM game_accounts WHERE game_id = $game_id AND status = 'AVAILABLE'");
    if ($acc_check && $row_acc = $acc_check->fetch_assoc()) {
        $available_accounts = intval($row_acc['available_count'] ?? 0);
    }
} catch (Throwable $e) {}

// Nếu là thuê lại tài khoản cũ: Kiểm tra tính hợp lệ
$old_acc_info = null;
if ($preferred_account_id) {
    try {
        $own_check = $conn->prepare("SELECT borrow_id FROM borrow_records WHERE user_id = ? AND game_id = ? AND account_id = ? LIMIT 1");
        if ($own_check) {
            $own_check->bind_param("iii", $user_id, $game_id, $preferred_account_id);
            $own_check->execute();
            $own_res = $own_check->get_result();
            $has_rented_before = ($own_res && $own_res->num_rows > 0);
            $own_check->close();

            if ($has_rented_before) {
                $old_acc_stmt = $conn->prepare("SELECT * FROM game_accounts WHERE account_id = ? AND game_id = ?");
                if ($old_acc_stmt) {
                    $old_acc_stmt->bind_param("ii", $preferred_account_id, $game_id);
                    $old_acc_stmt->execute();
                    $old_acc_info = $old_acc_stmt->get_result()->fetch_assoc();
                    $old_acc_stmt->close();
                }
            } else {
                $preferred_account_id = null;
                $rent_type = 'new';
            }
        }
    } catch (Throwable $e) {
        $preferred_account_id = null;
    }
}

// Lấy danh sách gói thuê từ bảng rental_packages
$packages = [];
try {
    $packages_res = $conn->query("SELECT * FROM rental_packages WHERE game_id = $game_id AND is_active = 1 ORDER BY price ASC");
    if ($packages_res && $packages_res->num_rows > 0) {
        while ($p = $packages_res->fetch_assoc()) {
            $packages[] = $p;
        }
    }
} catch (Throwable $e) {}

if (empty($packages)) {
    $packages = [
        ['package_id' => 1, 'package_name' => '1 Giờ (Trải nghiệm nhanh)', 'duration_type' => 'hours', 'duration_value' => 1, 'price' => 5000],
        ['package_id' => 2, 'package_name' => '1 Ngày (24 Giờ)', 'duration_type' => 'days', 'duration_value' => 1, 'price' => 15000],
        ['package_id' => 3, 'package_name' => '7 Ngày (1 Tuần)', 'duration_type' => 'days', 'duration_value' => 7, 'price' => 30000],
        ['package_id' => 4, 'package_name' => '30 Ngày (1 Tháng)', 'duration_type' => 'days', 'duration_value' => 30, 'price' => 99000],
    ];
}

// XỬ LÝ: Khi người dùng bấm Tiến hành Thanh toán (TRƯỚC KHI OUTPUT HEADER ĐỂ REDIRECT AN TOÀN)
if (isset($_POST['btn_borrow'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo "<script>alert('Phiên bảo mật đã hết hạn. Vui lòng thử lại!');</script>";
    } else {
        $selected_pkg_id = intval($_POST['package_id'] ?? 0);
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

        $duration_hours = ($selected_pkg['duration_type'] == 'hours') ? intval($selected_pkg['duration_value']) : (intval($selected_pkg['duration_value']) * 24);
        $total_cost = floatval($selected_pkg['price']);

        $can_proceed = true;
        if (!$preferred_account_id && $available_accounts <= 0) {
            $can_proceed = false;
            echo "<script>alert('Rất tiếc, tựa game này hiện đang tạm hết tài khoản khả dụng trong kho!');</script>";
        } elseif ($preferred_account_id) {
            if ($old_acc_info && !in_array($old_acc_info['status'], ['AVAILABLE', 'EXPIRED'])) {
                $can_proceed = false;
                echo "<script>alert('Tài khoản cũ hiện không khả dụng (đang có người thuê hoặc đang bảo trì)!');</script>";
            }
        }

        if ($can_proceed) {
            $order_code = "RENT" . date('YmdHis') . rand(100, 999);
            $prev_b_id = null;
            if (isset($_GET['old_borrow_id']) && $preferred_account_id) {
                $cand_id = intval($_GET['old_borrow_id']);
                try {
                    $chk_prev = $conn->prepare("SELECT borrow_id FROM borrow_records WHERE borrow_id = ? AND user_id = ?");
                    if ($chk_prev) {
                        $chk_prev->bind_param("ii", $cand_id, $user_id);
                        $chk_prev->execute();
                        if ($chk_prev->get_result()->num_rows > 0) {
                            $prev_b_id = $cand_id;
                        }
                        $chk_prev->close();
                    }
                } catch (Throwable $e) {}
            }

            $new_borrow_id = 0;
            try {
                $insert_order = $conn->prepare("INSERT INTO borrow_records 
                    (order_code, user_id, game_id, package_id, duration_hours, rental_price, payment_status, payment_method, rent_type, previous_borrow_id, account_id, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, 'pending')");
                if ($insert_order) {
                    $insert_order->bind_param("siiiidssii", $order_code, $user_id, $game_id, $selected_pkg['package_id'], $duration_hours, $total_cost, $payment_method, $rent_type, $prev_b_id, $preferred_account_id);
                    $insert_order->execute();
                    $new_borrow_id = $conn->insert_id;
                    $insert_order->close();
                }
            } catch (Throwable $e) {}

            // Dự phòng schema cũ
            if ($new_borrow_id <= 0) {
                try {
                    $conn->query("INSERT INTO borrow_records (user_id, game_id, borrow_date, due_date, status) VALUES ($user_id, $game_id, NOW(), DATE_ADD(NOW(), INTERVAL $duration_hours HOUR), 'pending')");
                    $new_borrow_id = $conn->insert_id;
                } catch (Throwable $e) {}
            }

            if ($new_borrow_id > 0) {
                header("Location: checkout_qr.php?order_id=" . $new_borrow_id);
                exit();
            }
        }
    }
}

// BẮT ĐẦU LOAD GIAO DIỆN
require_once __DIR__ . '/includes/header.php'; 

if (!$game) {
    echo "<div class='container my-5 text-center text-white'><div class='panel-dark p-5'><h3 class='text-danger'>Tựa game không tồn tại hoặc không hỗ trợ cho thuê tài khoản.</h3><a href='index.php' class='btn btn-gaming-primary mt-3'>Về Cửa Hàng</a></div></div>";
    include 'includes/footer.php';
    exit();
}

$csrf_token = generate_csrf_token();
?>

<div class="container my-4" style="max-width: 960px;">
    <!-- Step Indicator Progress Bar -->
    <div class="d-flex align-items-center justify-content-center gap-2 gap-md-3 mb-4 flex-wrap">
        <span class="step-pill step-pill-active"><i class="bi bi-check-circle-fill me-1"></i> 1. Chọn Gói Thuê</span>
        <i class="bi bi-chevron-right step-arrow"></i>
        <span class="step-pill"><i class="bi bi-qr-code me-1"></i> 2. Quét Mã VietQR</span>
        <i class="bi bi-chevron-right step-arrow"></i>
        <span class="step-pill"><i class="bi bi-controller me-1"></i> 3. Nhận Tài Khoản</span>
    </div>

    <div class="row g-4">
        <!-- Cột trái: Tóm tắt Game -->
        <div class="col-lg-5">
            <div class="panel-dark p-4 h-100 d-flex flex-column">
                <div class="rounded-3 overflow-hidden mb-3 border game-preview-cover-wrapper">
                    <?php if(!empty($game['image_url']) && file_exists($game['image_url'])) { ?>
                        <img src="<?php echo htmlspecialchars($game['image_url']); ?>" alt="<?php echo htmlspecialchars($game['title']); ?>" class="w-100 object-fit-cover" style="height: 200px;">
                    <?php } else { ?>
                        <div class="d-flex align-items-center justify-content-center bg-dark text-muted" style="height: 200px;">
                            <i class="bi bi-controller fs-1"></i>
                        </div>
                    <?php } ?>
                </div>

                <h4 class="fw-bold game-title-display mb-2"><?php echo htmlspecialchars($game['title']); ?></h4>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge-platform"><?php echo htmlspecialchars($game['platform'] ?? 'PC'); ?></span>
                    <span class="badge-gaming badge-gaming-paid">Cho thuê tài khoản</span>
                </div>

                <?php if ($preferred_account_id && $old_acc_info): ?>
                    <div class="alert alert-warning border-0 p-3 rounded-3 mb-3" style="background: rgba(245, 158, 11, 0.15);">
                        <small class="fw-bold text-warning d-block mb-1"><i class="bi bi-arrow-repeat me-1"></i> CHẾ ĐỘ THUÊ LẠI TÀI KHOẢN CŨ</small>
                        <span class="small alert-text-desc">Bạn đang thuê lại tài khoản <strong><?php echo htmlspecialchars($old_acc_info['account_username']); ?></strong> để tiếp tục tiến trình chơi game cũ.</span>
                    </div>
                <?php endif; ?>

                <div class="mt-auto p-3 rounded-3 borrow-summary-card">
                    <div class="d-flex justify-content-between small mb-2">
                        <span class="summary-label">Kho tài khoản:</span>
                        <strong class="<?php echo ($available_accounts > 0) ? 'text-success' : 'text-danger'; ?>">
                            <?php echo ($available_accounts > 0) ? "🟢 Sẵn sàng ($available_accounts TK)" : "🔴 Hết tài khoản"; ?>
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between small mb-2">
                        <span class="summary-label">Hình thức cấp:</span>
                        <strong class="text-info">Tự động sau khi quét QR</strong>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span class="summary-label">Bảo hành:</span>
                        <strong class="summary-value-highlight">1 đổi 1 suốt thời gian thuê</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cột phải: Form chọn gói thuê -->
        <div class="col-lg-7">
            <div class="panel-dark p-4">
                <h4 class="fw-bold form-section-title mb-3">Thiết Lập Đơn Thuê Tài Khoản</h4>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <!-- Lựa chọn gói thuê -->
                    <label class="form-label form-step-label fw-bold small text-uppercase mb-2">1. Chọn Gói Thời Hạn Thuê:</label>
                    <div class="row g-2 mb-4">
                        <?php foreach ($packages as $index => $pkg): ?>
                            <div class="col-sm-6">
                                <label class="p-3 rounded-3 d-flex flex-column justify-content-between h-100 cursor-pointer pkg-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <input type="radio" name="package_id" value="<?php echo $pkg['package_id']; ?>" data-price="<?php echo $pkg['price']; ?>" <?php echo ($index == 0) ? 'checked' : ''; ?> class="form-check-input mt-0">
                                        <span class="badge pkg-type-badge"><?php echo htmlspecialchars($pkg['duration_type'] ?? 'days'); ?></span>
                                    </div>
                                    <strong class="pkg-name d-block mb-1"><?php echo htmlspecialchars($pkg['package_name']); ?></strong>
                                    <div class="pkg-price-val"><?php echo number_format($pkg['price'], 0, ',', '.'); ?>đ</div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Phương thức thanh toán -->
                    <label class="form-label form-step-label fw-bold small text-uppercase mb-2">2. Phương Thức Thanh Toán:</label>
                    <div class="p-3 rounded-3 mb-4 d-flex align-items-center gap-3 payment-method-card">
                        <input type="radio" name="payment_method" value="Chuyển khoản Ngân hàng (VietQR)" checked class="form-check-input">
                        <i class="bi bi-qr-code-scan fs-3 text-info"></i>
                        <div>
                            <strong class="payment-method-name d-block">Quét Mã VietQR (MB Bank)</strong>
                            <small class="payment-method-sub">Xác nhận tự động 24/7 qua Napas, mọi ngân hàng và ví điện tử</small>
                        </div>
                    </div>

                    <!-- Tổng thanh toán & Nút xác nhận -->
                    <div class="p-3 rounded-3 mb-4 d-flex justify-content-between align-items-center total-summary-card">
                        <span class="total-label fw-bold">Tổng thanh toán dự tính:</span>
                        <h3 class="fw-bold total-price-val mb-0" id="totalPriceDisplay"><?php echo number_format($packages[0]['price'], 0, ',', '.'); ?> đ</h3>
                    </div>

                    <?php if ($available_accounts > 0 || $preferred_account_id): ?>
                        <button type="submit" name="btn_borrow" class="btn btn-gaming-primary w-100 py-3 fs-5 fw-bold shadow">
                            <i class="bi bi-arrow-right-circle me-1"></i> TIẾP TỤC ĐẾN BƯỚC QUÉT MÃ QR
                        </button>
                    <?php else: ?>
                        <div class="alert alert-danger p-3 rounded-3 mb-3 text-center border-0 shadow-sm" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3) !important;">
                            <i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>
                            <strong>Tạm Hết Tài Khoản:</strong> Hiện kho tài khoản của tựa game này đang hết. Vui lòng quay lại sau!
                        </div>
                        <button type="button" class="btn btn-secondary w-100 py-3 fs-5 fw-bold" disabled>
                            <i class="bi bi-x-circle-fill me-1"></i> TẠM HẾT TÀI KHOẢN (KHÔNG THỂ THUÊ)
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const radioPkgs = document.querySelectorAll('input[name="package_id"]');
    const totalDisplay = document.getElementById('totalPriceDisplay');

    radioPkgs.forEach(radio => {
        radio.addEventListener('change', function() {
            const price = parseFloat(this.getAttribute('data-price'));
            if (totalDisplay) {
                totalDisplay.innerText = new Intl.NumberFormat('vi-VN').format(price) + ' đ';
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>