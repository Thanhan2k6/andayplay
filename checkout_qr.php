<?php
// OUTPUT BUFFERING — Prevent headers-already-sent on InfinityFree (output buffering may be OFF)
ob_start();

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/account_service.php';

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header("Location: login.php");
    exit();
}

$user_id = intval($_SESSION['user_id']);
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

// XỬ LÝ TẠO ĐƠN GIA HẠN MỚI TỪ MY_LIBRARY.PHP (TRƯỚC KHI OUTPUT HEADER)
if (isset($_POST['btn_extend_submit'])) {
    $borrow_id_to_extend = intval($_POST['borrow_id_to_extend'] ?? 0);
    $extend_pkg_id = intval($_POST['extend_pkg_id'] ?? 0);

    $old_order = null;
    try {
        $old_order_q = $conn->prepare("SELECT * FROM borrow_records WHERE borrow_id = ? AND user_id = ?");
        if ($old_order_q) {
            $old_order_q->bind_param("ii", $borrow_id_to_extend, $user_id);
            $old_order_q->execute();
            $old_order = $old_order_q->get_result()->fetch_assoc();
            $old_order_q->close();
        }
    } catch (Throwable $e) {}

    if ($old_order) {
        $game_id = intval($old_order['game_id']);
        
        // Tìm gói gia hạn
        $duration_hours = 24;
        $price = 15000;
        $package_id = null;

        if ($extend_pkg_id > 0) {
            try {
                $pkg_res = $conn->query("SELECT * FROM rental_packages WHERE package_id = $extend_pkg_id AND game_id = $game_id");
                if ($pkg_res && $pkg = $pkg_res->fetch_assoc()) {
                    $duration_hours = ($pkg['duration_type'] == 'hours') ? intval($pkg['duration_value']) : (intval($pkg['duration_value']) * 24);
                    $price = floatval($pkg['price']);
                    $package_id = intval($pkg['package_id']);
                }
            } catch (Throwable $e) {}
        } else {
            // Lấy gói rẻ nhất của game hoặc mặc định
            try {
                $pkg_res = $conn->query("SELECT * FROM rental_packages WHERE game_id = $game_id AND is_active = 1 ORDER BY price ASC LIMIT 1");
                if ($pkg_res && $pkg = $pkg_res->fetch_assoc()) {
                    $duration_hours = ($pkg['duration_type'] == 'hours') ? intval($pkg['duration_value']) : (intval($pkg['duration_value']) * 24);
                    $price = floatval($pkg['price']);
                    $package_id = intval($pkg['package_id']);
                }
            } catch (Throwable $e) {}
        }

        $order_code = "EXT" . date('YmdHis') . rand(100, 999);
        $old_acc_id = isset($old_order['account_id']) ? intval($old_order['account_id']) : null;

        $new_order_id = 0;
        try {
            $ins = $conn->prepare("INSERT INTO borrow_records 
                (order_code, user_id, game_id, package_id, duration_hours, rental_price, payment_status, payment_method, rent_type, previous_borrow_id, account_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending', 'Chuyển khoản VietQR', 'extend', ?, ?, 'pending')");
            if ($ins) {
                $ins->bind_param("siiiidsii", $order_code, $user_id, $game_id, $package_id, $duration_hours, $price, $borrow_id_to_extend, $old_acc_id);
                $ins->execute();
                $new_order_id = $conn->insert_id;
                $ins->close();
            }
        } catch (Throwable $e) {}

        // Dự phòng nếu bảng borrow_records cấu trúc cũ
        if ($new_order_id <= 0) {
            try {
                $conn->query("INSERT INTO borrow_records (user_id, game_id, borrow_date, due_date, status) VALUES ($user_id, $game_id, NOW(), DATE_ADD(NOW(), INTERVAL $duration_hours HOUR), 'pending')");
                $new_order_id = $conn->insert_id;
            } catch (Throwable $e) {}
        }

        if ($new_order_id > 0) {
            header("Location: checkout_qr.php?order_id=" . $new_order_id);
            exit();
        }
    }
}

// =====================================================================
// LOAD THÔNG TIN ĐƠN HÀNG VÀ XỬ LÝ POST TRƯỚC KHI OUTPUT HEADER.PHP
// =====================================================================

// Lấy thông tin đơn hàng từ DB
$order = null;
if ($order_id > 0) {
    try {
        $stmt = $conn->prepare("SELECT br.*, g.title, g.image_url, g.platform, rp.package_name, 
                               ga.account_username, ga.account_password_encrypted, ga.server_region, ga.status as account_status 
                               FROM borrow_records br 
                               JOIN games g ON br.game_id = g.game_id 
                               LEFT JOIN rental_packages rp ON br.package_id = rp.package_id 
                               LEFT JOIN game_accounts ga ON br.account_id = ga.account_id 
                               WHERE br.borrow_id = ? AND br.user_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $order_id, $user_id);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } catch (Throwable $e) {}

    // Fallback nếu schema cũ
    if (!$order) {
        try {
            $stmt = $conn->prepare("SELECT br.*, g.title, g.image_url, g.platform, 'Gói Thuê Chuẩn' as package_name, 
                                   '' as account_username, '' as account_password_encrypted, 'Global' as server_region, 'AVAILABLE' as account_status 
                                   FROM borrow_records br 
                                   JOIN games g ON br.game_id = g.game_id 
                                   WHERE br.borrow_id = ? AND br.user_id = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $order_id, $user_id);
                $stmt->execute();
                $order = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        } catch (Throwable $e) {}
    }
}

// Tính toán các flag quan trọng trước khi xử lý POST
$game_id      = $order ? intval($order['game_id']) : 0;
$amount       = $order ? floatval($order['rental_price'] ?? 15000) : 15000;
if ($amount <= 0) $amount = 15000;
$order_code   = $order && !empty($order['order_code']) ? $order['order_code'] : ("ORD" . $order_id);
$is_extension = $order && (($order['rent_type'] ?? '') === 'extend');
$is_paid_and_active = $order && (($order['payment_status'] ?? '') === 'paid' && ($order['status'] ?? '') === 'active');
$has_account  = $order && !empty($order['account_username']);

// XỬ LÝ XÁC NHẬN THANH TOÁN — PHẢI Ở ĐÂY TRƯỚC KHI INCLUDE HEADER.PHP
$allocation_error = null;
if ($order && isset($_POST['confirm_payment_webhook'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $allocation_error = "Phiên bảo mật đã hết hạn. Vui lòng bấm xác nhận lại.";
    } else {
        if ($is_paid_and_active) {
            header("Location: checkout_qr.php?order_id=" . $order_id . "&success=1");
            exit();
        }

        if ($is_extension) {
            $prev_b_id = intval($order['previous_borrow_id'] ?? 0);
            $prev_res = null;
            if ($prev_b_id > 0) {
                try {
                    $prev_res = $conn->query("SELECT due_date, status, account_id FROM borrow_records WHERE borrow_id = $prev_b_id")->fetch_assoc();
                } catch (Throwable $e) {}
            }
            
            $add_hours = intval($order['duration_hours'] ?? 24);
            $curr_due = ($prev_res && !empty($prev_res['due_date'])) ? strtotime($prev_res['due_date']) : time();
            if ($curr_due < time()) {
                $new_due_date = date('Y-m-d H:i:s', strtotime("+$add_hours hours"));
            } else {
                $new_due_date = date('Y-m-d H:i:s', strtotime("+$add_hours hours", $curr_due));
            }

            try {
                if ($prev_b_id > 0) {
                    $upd_prev = $conn->prepare("UPDATE borrow_records SET due_date = ?, status = 'active' WHERE borrow_id = ?");
                    if ($upd_prev) {
                        $upd_prev->bind_param("si", $new_due_date, $prev_b_id);
                        $upd_prev->execute();
                        $upd_prev->close();
                    }
                }

                $acc_id = ($prev_res && !empty($prev_res['account_id'])) ? intval($prev_res['account_id']) : 0;
                if ($acc_id > 0) {
                    $upd_acc = $conn->prepare("UPDATE game_accounts SET status = 'RENTED', rent_expires_at = ?, updated_at = NOW() WHERE account_id = ?");
                    if ($upd_acc) {
                        $upd_acc->bind_param("si", $new_due_date, $acc_id);
                        $upd_acc->execute();
                        $upd_acc->close();
                    }
                }

                try {
                    $upd_this = $conn->prepare("UPDATE borrow_records SET payment_status = 'paid', status = 'active', due_date = ? WHERE borrow_id = ?");
                    if ($upd_this) {
                        $upd_this->bind_param("si", $new_due_date, $order_id);
                        $upd_this->execute();
                        $upd_this->close();
                    }
                } catch (Throwable $e) {
                    $conn->query("UPDATE borrow_records SET status = 'active', due_date = '$new_due_date' WHERE borrow_id = $order_id");
                }

                if ($acc_id > 0) {
                    log_account_allocation($conn, $acc_id, $order_id, $user_id, 'EXTENDED', "Gia hạn thêm $add_hours giờ (đến $new_due_date)", 'SYSTEM');
                }

                if (function_exists('create_notification')) {
                    create_notification($conn, $user_id, 'rental', 'Gia hạn thời gian thuê thành công! ⏱️', "Đơn thuê #$borrow_id_to_extend ({$order['title']}) đã được kéo dài thêm $add_hours giờ.", 'my_library.php', 'EXT-' . $order_id);
                    create_notification($conn, $user_id, 'payment', 'Thanh toán gia hạn thành công 💳', "Thanh toán cho đơn gia hạn #$order_code đã hoàn tất (" . number_format($amount) . "đ).", 'profile.php?tab=orders', 'PAY-EXT-' . $order_id);
                }

                if (function_exists('notify_admins_with_permission')) {
                    notify_admins_with_permission($conn, 'order.view', 'order', "Gia hạn đơn thuê #$order_code ⏱️", "Khách hàng @" . ($_SESSION['username'] ?? 'User') . " vừa gia hạn đơn '{$order['title']}' thêm $add_hours giờ.", 'admin_borrow.php', 'ORD-EXT-' . $order_id);
                }

                header("Location: checkout_qr.php?order_id=" . $order_id . "&success=1&extended=1");
                exit();
            } catch (Throwable $e) {
                $allocation_error = "Lỗi khi xử lý gia hạn: " . $e->getMessage();
            }
        } else {
            $duration_hours = intval($order['duration_hours'] ?? 24);
            $pref_acc = !empty($order['account_id']) ? intval($order['account_id']) : null;

            $alloc_result = allocate_game_account($conn, $game_id, $user_id, $order_id, $duration_hours, $pref_acc);

            if ($alloc_result['success']) {
                if (function_exists('create_notification')) {
                    create_notification($conn, $user_id, 'rental', 'Thuê game thành công! 🎉', "Tài khoản game '{$order['title']}' đã được cấp phát. Hãy vào Thư Viện để lấy thông tin đăng nhập và chơi ngay!", 'my_library.php', 'RENT-' . $order_id);
                    create_notification($conn, $user_id, 'payment', 'Thanh toán thành công 💳', "Đơn hàng #$order_code đã thanh toán thành công số tiền " . number_format($amount) . "đ.", 'profile.php?tab=orders', 'PAY-' . $order_id);
                }

                if (function_exists('notify_admins_with_permission')) {
                    notify_admins_with_permission($conn, 'order.view', 'order', "Đơn thuê mới đã thanh toán #$order_code 💰", "Khách hàng @" . ($_SESSION['username'] ?? 'User') . " vừa thanh toán đơn thuê '{$order['title']}' (" . number_format($amount) . "đ).", 'admin_borrow.php', 'ORD-NEW-' . $order_id);
                }

                header("Location: checkout_qr.php?order_id=" . $order_id . "&success=1");
                exit();
            } else {
                $allocation_error = $alloc_result['error'];
                if (function_exists('create_notification')) {
                    create_notification($conn, $user_id, 'support', 'Sự cố phân bổ tài khoản đơn #' . $order_code . ' ⚠️', "Kho game tạm thời hết tài khoản sẵn sàng hoặc đang bảo trì. Bạn có thể tạo yêu cầu hỗ trợ để được đổi tài khoản ngay.", 'support.php', 'ERR-ALLOC-' . $order_id);
                }

                // Cảnh báo sự cố tài khoản và thanh toán cho Admin & Super Admin
                if (function_exists('notify_admins_with_permission')) {
                    notify_admins_with_permission($conn, 'rental.manage', 'rental', "⚠️ Sự cố kho tài khoản đơn #$order_code", "Đơn #$order_id ({$order['title']}) đã thanh toán nhưng chưa cấp được tài khoản: $allocation_error. Cần xử lý ngay!", 'admin_accounts.php', 'ALLOC-FAIL-' . $order_id);
                }
                if (function_exists('notify_super_admins')) {
                    notify_super_admins($conn, 'payment', "Sự cố giao dịch đơn #$order_code", "Khách hàng @" . ($_SESSION['username'] ?? 'User') . " đã thanh toán " . number_format($amount) . "đ nhưng kho tài khoản không phân bổ được.", 'admin_borrow.php', 'PAY-ISSUE-' . $order_id);
                }

                try {
                    $conn->query("UPDATE borrow_records SET payment_status = 'paid', status = 'pending', error_message = '" . $conn->real_escape_string($allocation_error) . "' WHERE borrow_id = $order_id");
                } catch (Throwable $e) {}
            }
        }
    }
}

// Re-query để cập nhật trạng thái mới nhất nếu vừa xử lý POST
if ($order && !$is_paid_and_active) {
    try {
        $stmt2 = $conn->prepare("SELECT br.*, g.title, g.image_url, g.platform, rp.package_name, 
                               ga.account_username, ga.account_password_encrypted, ga.server_region, ga.status as account_status 
                               FROM borrow_records br 
                               JOIN games g ON br.game_id = g.game_id 
                               LEFT JOIN rental_packages rp ON br.package_id = rp.package_id 
                               LEFT JOIN game_accounts ga ON br.account_id = ga.account_id 
                               WHERE br.borrow_id = ? AND br.user_id = ?");
        if ($stmt2) {
            $stmt2->bind_param("ii", $order_id, $user_id);
            $stmt2->execute();
            $refreshed = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            if ($refreshed) {
                $order = $refreshed;
                $is_paid_and_active = (($order['payment_status'] ?? '') === 'paid' && ($order['status'] ?? '') === 'active');
                $has_account = !empty($order['account_username']);
            }
        }
    } catch (Throwable $e) {}
}

// Giải mã mật khẩu sau khi biết chắc trạng thái
$plain_game_pass = '';
if ($is_paid_and_active && !empty($order['account_password_encrypted'])) {
    $plain_game_pass = decrypt_game_password($order['account_password_encrypted']);
}

// BẮT ĐẦU OUTPUT HTML — INCLUDE HEADER.PHP Ở ĐÂY (SAU KHI ĐÃ XỬ LÝ TOÀN BỘ POST)
require_once __DIR__ . '/includes/header.php';

// Nếu không có đơn hàng hợp lệ
if (!$order) {
    echo "<div class='container my-5 text-center text-white'>
            <div class='panel-dark p-5 mx-auto' style='max-width: 600px;'>
                <i class='bi bi-receipt-cutoff fs-1 text-warning d-block mb-3'></i>
                <h3 class='fw-bold text-white mb-2'>Chưa có thông tin đơn thuê hợp lệ</h3>
                <p class='text-secondary small mb-4'>Vui lòng vào Thư viện hoặc Cửa hàng để tiến hành thuê hoặc gia hạn thời gian chơi game.</p>
                <div class='d-flex justify-content-center gap-2'>
                    <a href='my_library.php' class='btn btn-gaming-secondary px-4 py-2'>Thư viện của tôi</a>
                    <a href='index.php' class='btn btn-gaming-primary px-4 py-2'>Khám phá Cửa hàng</a>
                </div>
            </div>
          </div>";
    include 'includes/footer.php';
    ob_end_flush();
    exit();
}

// Cấu hình VietQR
$bank_id = "MB";
$account_no = "123426062006";
$account_name = "NGUYEN TRAN THANH AN";
$addInfo = "ANPLAY " . $order_code;
$int_amount = intval($amount);
$qr_url = "https://img.vietqr.io/image/{$bank_id}-{$account_no}-compact2.png?amount={$int_amount}&addInfo=" . urlencode($addInfo) . "&accountName=" . urlencode($account_name);
$csrf_token = generate_csrf_token();
?>

<div class="container my-4" style="max-width: 880px;">
    <!-- Step Indicator Progress Bar -->
    <div class="d-flex align-items-center justify-content-center gap-2 gap-md-3 mb-4 flex-wrap">
        <span class="step-pill"><i class="bi bi-check-circle-fill me-1"></i> 1. Chọn Gói</span>
        <i class="bi bi-chevron-right step-arrow"></i>
        <span class="step-pill <?php echo !$is_paid_and_active ? 'step-pill-active' : ''; ?>"><i class="bi bi-qr-code me-1"></i> 2. Quét VietQR</span>
        <i class="bi bi-chevron-right step-arrow"></i>
        <span class="step-pill <?php echo $is_paid_and_active ? 'step-pill-active' : ''; ?>">
            <i class="bi bi-controller me-1"></i> 3. Nhận Tài Khoản
        </span>
    </div>

    <?php if ($is_paid_and_active): ?>
        <!-- ========================================================== -->
        <!-- MÀN HÌNH ĐÃ THANH TOÁN THÀNH CÔNG & THÔNG TIN TÀI KHOẢN   -->
        <!-- ========================================================== -->
        <div class="panel-dark p-4 p-md-5 shadow-lg border border-success border-opacity-25">
            <div class="text-center mb-4">
                <span class="badge bg-success bg-opacity-20 text-success border border-success border-opacity-30 px-3 py-2 fw-bold mb-2">
                    <i class="bi bi-patch-check-fill me-1"></i> THANH TOÁN THÀNH CÔNG — TÀI KHOẢN ĐÃ CẤP PHÁT
                </span>
                <h3 class="fw-bold text-white mb-1">🎉 Chúc Bạn Chơi Game Vui Vẻ!</h3>
                <p class="text-secondary small mb-0">
                    Đơn hàng <strong>#<?php echo htmlspecialchars($order_code); ?></strong> đã hoàn tất xác nhận thanh toán tự động 24/7.
                </p>
            </div>

            <div class="row g-4 align-items-stretch">
                <!-- Cột Trái: Thông tin Đơn hàng & Tựa Game -->
                <div class="col-md-5">
                    <div class="p-3 rounded-4 h-100 d-flex flex-column justify-content-between borrow-summary-card">
                        <div>
                            <?php if (!empty($order['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($order['image_url']); ?>" alt="<?php echo htmlspecialchars($order['title']); ?>" class="w-100 rounded-3 mb-3 object-fit-cover" style="height: 180px;">
                            <?php endif; ?>
                            <h5 class="fw-bold game-title-display mb-2"><?php echo htmlspecialchars($order['title']); ?></h5>
                            <div class="d-flex flex-wrap gap-1 mb-3">
                                <span class="badge bg-primary bg-opacity-20 text-primary border border-primary border-opacity-25">
                                    <i class="bi bi-clock me-1"></i> <?php echo intval($order['duration_hours'] ?? 24); ?> Giờ
                                </span>
                                <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-25">
                                    <i class="bi bi-windows me-1"></i> <?php echo htmlspecialchars($order['platform'] ?? 'Steam'); ?>
                                </span>
                                <span class="badge bg-info bg-opacity-20 text-info border border-info border-opacity-25">
                                    <i class="bi bi-globe me-1"></i> <?php echo htmlspecialchars($order['server_region'] ?? 'Global'); ?>
                                </span>
                            </div>

                            <ul class="list-unstyled text-secondary small mb-0" style="font-size: 0.82rem;">
                                <li class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-10">
                                    <span>Gói thuê:</span>
                                    <strong class="text-light"><?php echo htmlspecialchars($order['package_name'] ?? 'Gói Tiêu Chuẩn'); ?></strong>
                                </li>
                                <li class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-10">
                                    <span>Giá thanh toán:</span>
                                    <strong class="text-warning"><?php echo number_format($amount, 0, ',', '.'); ?> đ</strong>
                                </li>
                                <li class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-10">
                                    <span>Thời gian bắt đầu:</span>
                                    <strong class="text-light"><?php echo !empty($order['borrow_date']) ? date('H:i d/m/Y', strtotime($order['borrow_date'])) : date('H:i d/m/Y'); ?></strong>
                                </li>
                                <li class="d-flex justify-content-between py-1">
                                    <span>Hạn kết thúc:</span>
                                    <strong class="text-danger"><?php echo !empty($order['due_date']) ? date('H:i d/m/Y', strtotime($order['due_date'])) : '24 giờ tiếp theo'; ?></strong>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Cột Phải: Thông tin Đăng nhập Game An toàn -->
                <div class="col-md-7">
                    <div class="p-3 p-md-4 rounded-4 h-100 account-credentials-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-white fw-bold d-flex align-items-center">
                                <i class="bi bi-key-fill text-warning me-2"></i> THÔNG TIN TÀI KHOẢN GAME
                            </span>
                            <span class="badge bg-success bg-opacity-20 text-success border border-success border-opacity-25 small">
                                Sẵn sàng đăng nhập
                            </span>
                        </div>

                        <?php if ($has_account): ?>
                            <!-- Tài khoản Game -->
                            <div class="mb-3">
                                <label class="small text-secondary d-block mb-1">Tên đăng nhập / Email Game:</label>
                                <div class="input-group">
                                    <input type="text" class="form-control form-control-gaming text-white fw-bold font-monospace user-select-all" 
                                           id="game_user_field" value="<?php echo htmlspecialchars($order['account_username']); ?>" readonly>
                                    <button class="btn btn-gaming-secondary" type="button" onclick="copyPlain('<?php echo htmlspecialchars($order['account_username'], ENT_QUOTES); ?>', this)">
                                        <i class="bi bi-clipboard"></i> Sao chép
                                    </button>
                                </div>
                            </div>

                            <!-- Mật khẩu Game Giải mã -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="small text-secondary mb-0">Mật khẩu game bản quyền:</label>
                                    <span class="text-info small" style="font-size: 0.75rem;"><i class="bi bi-shield-lock me-1"></i> Mã hóa AES-256</span>
                                </div>
                                <div class="input-group">
                                    <input type="password" class="form-control form-control-gaming text-warning fw-bold font-monospace user-select-all" 
                                           id="game_pass_field" value="<?php echo htmlspecialchars($plain_game_pass); ?>" readonly>
                                    <button class="btn btn-gaming-secondary" type="button" onclick="togglePassView('game_pass_field', this)" title="Ẩn/Hiện mật khẩu">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-gaming-secondary" type="button" onclick="copyPlain('<?php echo htmlspecialchars($plain_game_pass, ENT_QUOTES); ?>', this)">
                                        <i class="bi bi-clipboard"></i> Sao chép
                                    </button>
                                </div>
                            </div>

                            <!-- Hướng dẫn chơi game an toàn -->
                            <div class="p-3 rounded-3 mb-3" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255, 255, 255, 0.08);">
                                <small class="text-light fw-bold d-block mb-2">
                                    <i class="bi bi-info-circle-fill text-primary me-1"></i> Hướng Dẫn Đăng Nhập & Chơi Game:
                                </small>
                                <ol class="text-secondary small mb-0 ps-3" style="font-size: 0.8rem; line-height: 1.6;">
                                    <li>Mở Client game tương ứng (Steam / Epic Games / EA app) trên máy bạn.</li>
                                    <li>Đăng nhập bằng <strong>Tài khoản</strong> và <strong>Mật khẩu</strong> cấp ở trên.</li>
                                    <li>Sau khi tải/cập nhật game, chuyển client sang <strong>Offline Mode (Chơi ngoại tuyến)</strong> để không bị tranh slot.</li>
                                </ol>
                            </div>

                            <!-- Cảnh báo quy định an toàn -->
                            <div class="p-2 px-3 rounded-3 mb-0" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2);">
                                <span class="text-danger fw-bold small d-block mb-1" style="font-size: 0.78rem;">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> QUY ĐỊNH BẢO MẬT BẮT BUỘC:
                                </span>
                                <span class="text-secondary small d-block" style="font-size: 0.75rem;">
                                    Tuyệt đối không đổi mật khẩu, không kích hoạt Steam Guard gia đình hoặc làm thay đổi thông tin tài khoản. Mọi vi phạm sẽ bị khóa quyền truy cập ngay lập tức.
                                </span>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-hourglass-split fs-1 text-warning d-block mb-2"></i>
                                <h6 class="text-white fw-bold mb-1">Đang phân bổ tài khoản...</h6>
                                <p class="text-secondary small mb-3">Hệ thống đang chỉ định tài khoản game trống cho bạn. Vui lòng nhấn Tải lại trang hoặc liên hệ hỗ trợ.</p>
                                <a href="checkout_qr.php?order_id=<?php echo $order_id; ?>" class="btn btn-sm btn-gaming-primary">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Tải lại trang
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Các Nút Hành Động -->
            <div class="mt-4 pt-3 border-top border-secondary border-opacity-25 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <a href="index.php" class="btn btn-gaming-secondary">
                    <i class="bi bi-house me-1"></i> Về Cửa Hàng
                </a>
                <div class="d-flex gap-2">
                    <a href="support.php?subject=account_issue&order_id=<?php echo $order_id; ?>" class="btn btn-outline-warning">
                        <i class="bi bi-headset me-1"></i> Báo Lỗi / Đổi Tài Khoản
                    </a>
                    <a href="my_library.php" class="btn btn-gaming-primary px-4">
                        <i class="bi bi-collection-play-fill me-1"></i> Vào Thư Viện Của Tôi
                    </a>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ========================================================== -->
        <!-- MÀN HÌNH QUÉT MÃ VIETQR KHI CHƯA THANH TOÁN                -->
        <!-- ========================================================== -->
        <div class="panel-dark p-4 p-md-5 shadow-lg">
            <div class="text-center mb-4">
                <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 px-3 py-2 fw-bold mb-2">
                    <i class="bi bi-qr-code me-1"></i> CỔNG THANH TOÁN TỰ ĐỘNG VIETQR
                </span>
                <h3 class="fw-bold text-white mb-1">Quét Mã QR Để Hoàn Tất Đơn Thuê</h3>
                <p class="text-secondary small mb-0">Hệ thống ngân hàng tự động xác nhận giao dịch 24/7 và cấp tài khoản ngay lập tức</p>
            </div>

            <?php if ($allocation_error): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-4 text-white" style="background: rgba(245, 158, 11, 0.15); border: 1px solid #f59e0b;" role="alert">
                    <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i><strong>Thông báo:</strong> <?php echo htmlspecialchars($allocation_error); ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- ĐỒNG HỒ ĐẾM NGƯỢC 15 PHÚT -->
            <div class="p-3 rounded-4 mb-4 text-center" style="background: rgba(244, 63, 94, 0.1); border: 1px solid rgba(244, 63, 94, 0.25);">
                <small class="text-danger fw-bold d-block mb-1"><i class="bi bi-stopwatch-fill me-1"></i> MÃ QR CÓ HIỆU LỰC TRONG:</small>
                <h4 class="text-white fw-bold mb-0 font-monospace" id="countdown_clock">15:00</h4>
            </div>

            <div class="row g-4 align-items-center">
                <!-- Cột 1: Mã QR VietQR -->
                <div class="col-md-6 text-center">
                    <div class="p-3 bg-white rounded-4 shadow-lg d-inline-block border">
                        <img src="<?php echo $qr_url; ?>" alt="VietQR Payment" class="img-fluid rounded-3" style="max-width: 280px; width: 100%;">
                    </div>
                    <div class="mt-3">
                        <span class="badge bg-dark border border-secondary text-secondary px-3 py-2 small">
                            <i class="bi bi-shield-check text-success me-1"></i> VietQR Napas247 Xác Thực Tự Động
                        </span>
                    </div>
                </div>

                <!-- Cột 2: Thông tin chuyển khoản & 1-Click Copy -->
                <div class="col-md-6">
                    <div class="p-3 rounded-4 mb-3 borrow-summary-card">
                        <small class="summary-label d-block fw-bold mb-1">TỰA GAME & GÓI THUÊ:</small>
                        <h5 class="fw-bold game-title-display mb-1"><?php echo htmlspecialchars($order['title']); ?></h5>
                        <span class="badge bg-primary bg-opacity-25 text-primary">
                            <?php echo htmlspecialchars($order['package_name'] ?? 'Gói Thuê'); ?> (<?php echo intval($order['duration_hours'] ?? 24); ?> giờ)
                        </span>
                    </div>

                    <!-- Số tiền cần thanh toán -->
                    <div class="mb-3">
                        <small class="text-secondary d-block mb-1">Số tiền thanh toán chính xác:</small>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-gaming text-warning fw-bold fs-5 user-select-all" value="<?php echo number_format($amount, 0, ',', '.'); ?> đ" readonly id="amount_val">
                            <button class="btn btn-gaming-secondary" type="button" onclick="copyPlain('<?php echo $int_amount; ?>', this)">
                                <i class="bi bi-clipboard"></i> Sao chép
                            </button>
                        </div>
                    </div>

                    <!-- Số tài khoản MB Bank -->
                    <div class="mb-3">
                        <small class="text-secondary d-block mb-1">Số tài khoản MB Bank:</small>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-gaming text-white fw-bold font-monospace user-select-all" value="<?php echo $account_no; ?>" readonly id="acc_no_val">
                            <button class="btn btn-gaming-secondary" type="button" onclick="copyPlain('<?php echo $account_no; ?>', this)">
                                <i class="bi bi-clipboard"></i> Sao chép
                            </button>
                        </div>
                        <small class="text-secondary d-block mt-1">Chủ TK: <strong class="text-light"><?php echo $account_name; ?></strong></small>
                    </div>

                    <!-- Nội dung chuyển khoản -->
                    <div class="mb-3">
                        <small class="text-secondary d-block mb-1">Nội dung chuyển khoản (Bắt buộc):</small>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-gaming text-info fw-bold font-monospace user-select-all" value="<?php echo $addInfo; ?>" readonly id="memo_val">
                            <button class="btn btn-gaming-secondary" type="button" onclick="copyPlain('<?php echo $addInfo; ?>', this)">
                                <i class="bi bi-clipboard"></i> Sao chép
                            </button>
                        </div>
                        <small class="text-danger d-block mt-1" style="font-size: 0.78rem;">
                            <i class="bi bi-exclamation-circle me-1"></i> Vui lòng giữ nguyên nội dung chuyển khoản để hệ thống tự động nhận diện.
                        </small>
                    </div>
                </div>
            </div>

            <!-- Nút Xác Nhận Đã Chuyển Khoản -->
            <div class="mt-4 pt-3 border-top border-white border-opacity-10 text-center">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <button type="submit" name="confirm_payment_webhook" class="btn btn-gaming-primary w-100 py-3 fs-5 fw-bold shadow">
                        <i class="bi bi-check2-circle me-2 fs-4"></i> TÔI ĐÃ CHUYỂN KHOẢN THÀNH CÔNG (XÁC NHẬN)
                    </button>
                </form>
                <div class="d-flex justify-content-center gap-3 mt-3">
                    <a href="my_library.php" class="text-secondary small text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i> Để sau, quay về Thư viện
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function copyPlain(text, btn) {
    if (!navigator.clipboard) {
        var dummy = document.createElement("input");
        document.body.appendChild(dummy);
        dummy.value = text;
        dummy.select();
        document.execCommand("copy");
        document.body.removeChild(dummy);
        showCopied(btn);
        return;
    }
    navigator.clipboard.writeText(text).then(function() {
        showCopied(btn);
    }).catch(function() {
        showCopied(btn);
    });
}

function showCopied(btn) {
    var original = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check2 text-success"></i> Đã chép';
    setTimeout(function() { btn.innerHTML = original; }, 1500);
}

function togglePassView(fieldId, btn) {
    var field = document.getElementById(fieldId);
    var icon = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'bi bi-eye-slash text-warning';
    } else {
        field.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

// Countdown 15 minutes (chỉ chạy khi chưa thanh toán)
<?php if (!$is_paid_and_active): ?>
(function() {
    var totalSeconds = 15 * 60;
    var timerEl = document.getElementById('countdown_clock');
    var interval = setInterval(function() {
        totalSeconds--;
        if (totalSeconds <= 0) {
            clearInterval(interval);
            if (timerEl) timerEl.innerHTML = "<span class='text-danger'>MÃ QR ĐÃ HẾT HẠN</span>";
        } else {
            var m = Math.floor(totalSeconds / 60);
            var s = totalSeconds % 60;
            if (timerEl) {
                timerEl.innerHTML = (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
            }
        }
    }, 1000);
})();
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ob_end_flush(); ?>
