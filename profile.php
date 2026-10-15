<?php
/**
 * ANPLAY STORE - TRUNG TÂM QUẢN LÝ TÀI KHOẢN NGƯỜI DÙNG (PROFILE & ACCOUNT MANAGEMENT)
 * Thiết kế theo phong cách Gaming Marketplace cao cấp, Dark Theme chuẩn mực.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Bắt buộc đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/includes/connect.php';

$user_id = intval($_SESSION['user_id']);
$tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'overview';
if (!in_array($tab, ['overview', 'info', 'security', 'orders'])) {
    $tab = 'overview';
}

$success_msg = "";
$error_msg = "";

// Đảm bảo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper xác thực CSRF
function verify_csrf($token) {
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ----------------------------------------------------
// 1. XỬ LÝ CẬP NHẬT THÔNG TIN CÁ NHÂN (TAB: INFO)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_update_profile'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật (CSRF) không hợp lệ hoặc đã hết hạn.";
        $tab = 'info';
    } else {
        $new_email = trim($_POST['email'] ?? '');
        
        if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Địa chỉ email không đúng định dạng.";
            $tab = 'info';
        } else {
            // Kiểm tra email đã bị tài khoản khác sử dụng chưa
            $chk_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            if ($chk_stmt) {
                $chk_stmt->bind_param("si", $new_email, $user_id);
                $chk_stmt->execute();
                if ($chk_stmt->get_result()->num_rows > 0) {
                    $error_msg = "Địa chỉ email này đã được sử dụng bởi một tài khoản khác.";
                    $tab = 'info';
                }
                $chk_stmt->close();
            }

            if (empty($error_msg)) {
                $up_stmt = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ?");
                if ($up_stmt) {
                    $up_stmt->bind_param("si", $new_email, $user_id);
                    if ($up_stmt->execute()) {
                        $_SESSION['email'] = $new_email;
                        $success_msg = "Cập nhật thông tin tài khoản thành công!";
                        $tab = 'info';
                    } else {
                        $error_msg = "Lỗi khi lưu dữ liệu vào hệ thống. Vui lòng thử lại!";
                        $tab = 'info';
                    }
                    $up_stmt->close();
                }
            }
        }
    }
}

// ----------------------------------------------------
// 2. XỬ LÝ ĐỔI MẬT KHẨU (TAB: SECURITY)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_change_password'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật không hợp lệ.";
        $tab = 'security';
    } else {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
            $error_msg = "Vui lòng nhập đầy đủ tất cả các trường mật khẩu.";
            $tab = 'security';
        } elseif (strlen($new_pass) < 6) {
            $error_msg = "Mật khẩu mới phải có độ dài ít nhất 6 ký tự.";
            $tab = 'security';
        } elseif ($new_pass !== $confirm_pass) {
            $error_msg = "Mật khẩu xác nhận không khớp với mật khẩu mới.";
            $tab = 'security';
        } else {
            // Lấy hash hiện tại của user
            $get_u = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
            if ($get_u) {
                $get_u->bind_param("i", $user_id);
                $get_u->execute();
                $u_data = $get_u->get_result()->fetch_assoc();
                $get_u->close();

                if ($u_data && (password_verify($current_pass, $u_data['password']) || $current_pass === $u_data['password'])) {
                    $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
                    $up_pass = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    if ($up_pass) {
                        $up_pass->bind_param("si", $new_hash, $user_id);
                        if ($up_pass->execute()) {
                            $success_msg = "Đổi mật khẩu thành công! Tài khoản của bạn đã được bảo vệ với mật khẩu mới.";
                            $tab = 'security';
                        } else {
                            $error_msg = "Không thể cập nhật mật khẩu. Vui lòng thử lại.";
                            $tab = 'security';
                        }
                        $up_pass->close();
                    }
                } else {
                    $error_msg = "Mật khẩu hiện tại không chính xác.";
                    $tab = 'security';
                }
            }
        }
    }
}

// ----------------------------------------------------
// 3. TẢI THÔNG TIN NGƯỜI DÙNG TỪ CSDL
// ----------------------------------------------------
$user_info = null;
$u_stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
if ($u_stmt) {
    $u_stmt->bind_param("i", $user_id);
    $u_stmt->execute();
    $user_info = $u_stmt->get_result()->fetch_assoc();
    $u_stmt->close();
}

if (!$user_info) {
    header("Location: logout.php");
    exit();
}

// Đồng bộ session
$_SESSION['username'] = $user_info['username'];
$_SESSION['email'] = $user_info['email'];
$_SESSION['role'] = $user_info['role'];

// ----------------------------------------------------
// 4. TÍNH TOÁN THỐNG KÊ (KPIs)
// ----------------------------------------------------
$total_rented = 0;
$active_rentals = 0;
$total_spent = 0;

try {
    // Tổng số đơn thuê
    $res_cnt = $conn->query("SELECT COUNT(*) as c FROM borrow_records WHERE user_id = $user_id");
    if ($res_cnt && $r = $res_cnt->fetch_assoc()) {
        $total_rented = intval($r['c']);
    }

    // Đơn thuê đang hoạt động
    $res_act = $conn->query("SELECT COUNT(*) as c FROM borrow_records WHERE user_id = $user_id AND status = 'active' AND due_date > NOW()");
    if ($res_act && $r = $res_act->fetch_assoc()) {
        $active_rentals = intval($r['c']);
    }

    // Tổng chi tiêu
    $res_spent = $conn->query("SELECT SUM(rental_price) as s FROM borrow_records WHERE user_id = $user_id AND (payment_status = 'completed' OR status = 'active')");
    if ($res_spent && $r = $res_spent->fetch_assoc()) {
        $total_spent = floatval($r['s'] ?? 0);
    }
} catch (Throwable $e) {}

// ----------------------------------------------------
// 5. TẢI LỊCH SỬ THUÊ / ĐƠN HÀNG (TAB: ORDERS & OVERVIEW)
// ----------------------------------------------------
$orders = [];
try {
    $sql_orders = "SELECT b.*, g.title, g.image_url, g.platform 
                   FROM borrow_records b
                   LEFT JOIN games g ON b.game_id = g.game_id
                   WHERE b.user_id = $user_id
                   ORDER BY b.borrow_id DESC";
    $res_orders = $conn->query($sql_orders);
    if ($res_orders && $res_orders->num_rows > 0) {
        while ($ord = $res_orders->fetch_assoc()) {
            $orders[] = $ord;
        }
    }
} catch (Throwable $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 py-4 my-2 text-white">
    <!-- HERO PROFILE HEADER -->
    <div class="gaming-card p-4 mb-4 position-relative overflow-hidden" style="background: linear-gradient(135deg, rgba(21, 24, 34, 0.95) 0%, rgba(15, 17, 24, 0.95) 100%); border: 1px solid var(--border-color);">
        <div class="position-absolute end-0 top-0 p-4 opacity-10 d-none d-lg-block pointer-events-none">
            <i class="bi bi-controller text-white" style="font-size: 10rem;"></i>
        </div>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-4 position-relative">
            <div class="d-flex align-items-center gap-4">
                <div class="position-relative">
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($user_info['username']); ?>&background=6366f1&color=fff&size=128&bold=true&rounded=true" 
                         width="88" height="88" class="rounded-circle shadow-lg border border-2 border-primary" alt="Avatar">
                    <span class="position-absolute bottom-0 end-0 p-2 bg-success border border-dark rounded-circle" title="Online"></span>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h2 class="fw-extrabold text-white mb-0">@<?php echo htmlspecialchars($user_info['username']); ?></h2>
                        <?php if ($user_info['role'] == 1): ?>
                            <span class="badge" style="background: rgba(244,63,94,0.2); color: #f43f5e; border: 1px solid rgba(244,63,94,0.4); font-size: 0.75rem;">
                                <i class="bi bi-shield-check me-1"></i> Quản Trị Viên
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: rgba(6,182,212,0.15); color: #06b6d4; border: 1px solid rgba(6,182,212,0.35); font-size: 0.75rem;">
                                <i class="bi bi-gem me-1"></i> Thành Viên VIP
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="text-secondary small d-flex flex-wrap align-items-center gap-3">
                        <span><i class="bi bi-envelope me-1 text-info"></i> <?php echo htmlspecialchars($user_info['email']); ?></span>
                        <span><i class="bi bi-calendar-check me-1 text-warning"></i> Tham gia: <?php echo date('d/m/Y', strtotime($user_info['created_at'] ?? 'now')); ?></span>
                        <span><i class="bi bi-shield-lock me-1 text-success"></i> ID: #<?php echo $user_id; ?></span>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <a href="my_library.php" class="btn btn-gaming-primary py-2 px-3">
                    <i class="bi bi-collection-play me-1"></i> Kho Game Của Tôi
                </a>
                <a href="logout.php" class="btn btn-gaming-secondary py-2 px-3 text-danger border-danger border-opacity-25">
                    <i class="bi bi-box-arrow-right me-1"></i> Đăng xuất
                </a>
            </div>
        </div>
    </div>

    <!-- NOTIFICATION ALERTS -->
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-dismissible fade show mb-4 text-white" style="background: rgba(16,185,129,0.15); border: 1px solid #10b981;" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill text-success fs-5 me-2"></i>
                <div class="fw-semibold"><?php echo htmlspecialchars($success_msg); ?></div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-dismissible fade show mb-4 text-white" style="background: rgba(244,63,94,0.15); border: 1px solid #f43f5e;" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill text-danger fs-5 me-2"></i>
                <div class="fw-semibold"><?php echo htmlspecialchars($error_msg); ?></div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- KPI STATS CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 rounded-4 h-100 d-flex align-items-center justify-content-between" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div>
                    <span class="text-secondary small text-uppercase fw-bold">Tổng lượt thuê</span>
                    <h3 class="fw-bold text-white mb-0 mt-1"><?php echo $total_rented; ?></h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(99,102,241,0.12); color: var(--primary);">
                    <i class="bi bi-controller fs-4"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 rounded-4 h-100 d-flex align-items-center justify-content-between" style="background: var(--bg-card); border: 1px solid rgba(16,185,129,0.3);">
                <div>
                    <span class="text-secondary small text-uppercase fw-bold">Đang hoạt động</span>
                    <h3 class="fw-bold text-success mb-0 mt-1"><?php echo $active_rentals; ?></h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(16,185,129,0.12); color: #10b981;">
                    <i class="bi bi-lightning-charge-fill fs-4"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 rounded-4 h-100 d-flex align-items-center justify-content-between" style="background: var(--bg-card); border: 1px solid rgba(6,182,212,0.3);">
                <div>
                    <span class="text-secondary small text-uppercase fw-bold">Tổng chi tiêu</span>
                    <h3 class="fw-bold text-info mb-0 mt-1"><?php echo number_format($total_spent, 0, ',', '.'); ?>đ</h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(6,182,212,0.12); color: #06b6d4;">
                    <i class="bi bi-wallet2 fs-4"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 rounded-4 h-100 d-flex align-items-center justify-content-between" style="background: var(--bg-card); border: 1px solid rgba(245,158,11,0.3);">
                <div>
                    <span class="text-secondary small text-uppercase fw-bold">Hạng tài khoản</span>
                    <h3 class="fw-bold text-warning mb-0 mt-1"><?php echo ($user_info['role'] == 1) ? 'ADMIN' : 'VIP TIER'; ?></h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(245,158,11,0.12); color: #f59e0b;">
                    <i class="bi bi-trophy-fill fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN TABS CONTAINER -->
    <div class="row g-4">
        <!-- TAB NAVIGATION (VERTICAL PILLS ON DESKTOP) -->
        <div class="col-lg-3">
            <div class="gaming-card p-3" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="nav flex-column nav-pills gap-2" id="profileTabs" role="tablist">
                    <a href="profile.php?tab=overview" class="nav-link py-3 px-3 rounded-3 d-flex align-items-center gap-3 <?php echo ($tab === 'overview') ? 'active bg-primary text-white fw-bold shadow' : 'text-secondary'; ?>" style="border: 1px solid <?php echo ($tab === 'overview') ? 'var(--primary)' : 'transparent'; ?>;">
                        <i class="bi bi-grid-fill fs-5"></i>
                        <div>
                            <div class="text-white fw-bold">Tổng Quan</div>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">Thông số & đơn thuê gần nhất</small>
                        </div>
                    </a>

                    <a href="profile.php?tab=info" class="nav-link py-3 px-3 rounded-3 d-flex align-items-center gap-3 <?php echo ($tab === 'info') ? 'active bg-primary text-white fw-bold shadow' : 'text-secondary'; ?>" style="border: 1px solid <?php echo ($tab === 'info') ? 'var(--primary)' : 'transparent'; ?>;">
                        <i class="bi bi-person-lines-fill fs-5"></i>
                        <div>
                            <div class="text-white fw-bold">Thông Tin Cá Nhân</div>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">Tên, email và liên hệ</small>
                        </div>
                    </a>

                    <a href="profile.php?tab=orders" class="nav-link py-3 px-3 rounded-3 d-flex align-items-center gap-3 <?php echo ($tab === 'orders') ? 'active bg-primary text-white fw-bold shadow' : 'text-secondary'; ?>" style="border: 1px solid <?php echo ($tab === 'orders') ? 'var(--primary)' : 'transparent'; ?>;">
                        <i class="bi bi-receipt-cutoff fs-5"></i>
                        <div>
                            <div class="text-white fw-bold">Lịch Sử Đơn Hàng</div>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">Hóa đơn và chi tiết thuê game</small>
                        </div>
                    </a>

                    <a href="profile.php?tab=security" class="nav-link py-3 px-3 rounded-3 d-flex align-items-center gap-3 <?php echo ($tab === 'security') ? 'active bg-primary text-white fw-bold shadow' : 'text-secondary'; ?>" style="border: 1px solid <?php echo ($tab === 'security') ? 'var(--primary)' : 'transparent'; ?>;">
                        <i class="bi bi-shield-lock-fill fs-5"></i>
                        <div>
                            <div class="text-white fw-bold">Đổi Mật Khẩu & Bảo Mật</div>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">Bảo vệ tài khoản và phiên đăng nhập</small>
                        </div>
                    </a>
                </div>

                <hr class="border-secondary border-opacity-25 my-3">

                <!-- QUICK SUPPORT BOX -->
                <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-headset text-info fs-5"></i>
                        <span class="fw-bold small text-white">Hỗ Trợ Kỹ Thuật 24/7</span>
                    </div>
                    <p class="text-secondary small mb-2" style="font-size: 0.75rem;">Gặp sự cố với tài khoản thuê hoặc cần hỗ trợ nạp tiền?</p>
                    <a href="https://t.me" target="_blank" class="btn btn-sm btn-gaming-secondary w-100 py-1" style="font-size: 0.75rem;">
                        <i class="bi bi-telegram me-1"></i> Chat Telegram CSKH
                    </a>
                </div>
            </div>
        </div>

        <!-- TAB CONTENT (RIGHT PANEL) -->
        <div class="col-lg-9">
            <?php if ($tab === 'overview'): ?>
                <!-- ================= TAB 1: OVERVIEW ================= -->
                <div class="gaming-card p-4 mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <h4 class="fw-bold text-white mb-1"><i class="bi bi-grid-fill me-2 text-primary"></i> Tổng Quan Tài Khoản</h4>
                    <p class="text-secondary small mb-4">Thông tin trạng thái thành viên và hoạt động gần đây của bạn tại ANPLAY STORE</p>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 h-100" style="background: #141724; border: 1px solid var(--border-color);">
                                <div class="text-secondary small mb-1">Tên đăng nhập hệ thống</div>
                                <div class="fw-bold text-white fs-5">@<?php echo htmlspecialchars($user_info['username']); ?></div>
                                <div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i> Tên người dùng là duy nhất và không thể thay đổi</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 h-100" style="background: #141724; border: 1px solid var(--border-color);">
                                <div class="text-secondary small mb-1">Email liên kết nhận thông báo</div>
                                <div class="fw-bold text-info fs-5"><?php echo htmlspecialchars($user_info['email']); ?></div>
                                <div class="text-success small mt-2"><i class="bi bi-patch-check-fill me-1"></i> Đã xác thực nhận mã kích hoạt game</div>
                            </div>
                        </div>
                    </div>

                    <!-- GẦN ĐÂY: DANH SÁCH GAME ĐANG THUÊ -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-white mb-0"><i class="bi bi-joystick me-2 text-warning"></i> Hoạt Động Thuê Gần Đây</h5>
                        <a href="profile.php?tab=orders" class="small text-primary text-decoration-none fw-semibold">Xem toàn bộ lịch sử <i class="bi bi-arrow-right"></i></a>
                    </div>

                    <div class="table-gaming-container">
                        <div class="table-responsive">
                            <table class="table table-gaming align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Mã đơn</th>
                                        <th>Tựa Game</th>
                                        <th>Thời lượng</th>
                                        <th>Hạn sử dụng</th>
                                        <th>Trạng thái</th>
                                        <th class="text-end pe-3">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $recent_orders = array_slice($orders, 0, 5);
                                    if (!empty($recent_orders)):
                                        foreach ($recent_orders as $ord):
                                            $due_ts = strtotime($ord['due_date'] ?? 'now');
                                            $is_expired = ($ord['status'] === 'expired' || ($ord['status'] === 'active' && $due_ts < time()));
                                    ?>
                                        <tr>
                                            <td class="text-secondary font-monospace small">#<?php echo htmlspecialchars($ord['order_code'] ?? ('RENT' . $ord['borrow_id'])); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <?php if (!empty($ord['image_url'])): ?>
                                                        <img src="<?php echo htmlspecialchars($ord['image_url']); ?>" width="36" height="36" class="rounded object-fit-cover">
                                                    <?php endif; ?>
                                                    <span class="text-white fw-bold"><?php echo htmlspecialchars($ord['title'] ?? 'Game #' . $ord['game_id']); ?></span>
                                                </div>
                                            </td>
                                            <td class="text-light small"><?php echo intval($ord['duration_hours'] ?? 24); ?> giờ</td>
                                            <td class="text-light small"><?php echo !empty($ord['due_date']) ? date('d/m/Y H:i', $due_ts) : '---'; ?></td>
                                            <td>
                                                <?php if ($ord['status'] === 'active' && !$is_expired): ?>
                                                    <span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid rgba(16,185,129,0.4);">Đang kích hoạt</span>
                                                <?php elseif ($is_expired): ?>
                                                    <span class="badge" style="background: rgba(244,63,94,0.15); color: #f43f5e; border: 1px solid rgba(244,63,94,0.3);">Hết hạn</span>
                                                <?php else: ?>
                                                    <span class="badge" style="background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3);"><?php echo strtoupper($ord['status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="my_library.php" class="btn btn-sm btn-gaming-secondary py-1 px-2" style="font-size: 0.78rem;">
                                                    Vào Thư Viện
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-secondary">
                                                <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                                Chưa có dữ liệu thuê game nào. Hãy khám phá ngay cửa hàng!
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($tab === 'info'): ?>
                <!-- ================= TAB 2: EDIT PROFILE INFO ================= -->
                <div class="gaming-card p-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <h4 class="fw-bold text-white mb-1"><i class="bi bi-person-lines-fill me-2 text-primary"></i> Thông Tin Cá Nhân</h4>
                    <p class="text-secondary small mb-4">Cập nhật thông tin tài khoản và địa chỉ email để nhận thông báo thời hạn thuê game</p>

                    <form method="POST" action="profile.php?tab=info">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label text-light small fw-bold">Tên đăng nhập (Username):</label>
                                <div class="input-group">
                                    <span class="input-group-text border-secondary border-opacity-25 bg-dark text-secondary"><i class="bi bi-at"></i></span>
                                    <input type="text" class="form-control form-control-gaming text-secondary" value="<?php echo htmlspecialchars($user_info['username']); ?>" readonly disabled>
                                </div>
                                <small class="text-muted d-block mt-1">Tên đăng nhập được cố định sau khi đăng ký tài khoản.</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label text-light small fw-bold">Địa chỉ Email liên kết:</label>
                                <div class="input-group">
                                    <span class="input-group-text border-secondary border-opacity-25 bg-dark text-secondary"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" class="form-control form-control-gaming text-white" value="<?php echo htmlspecialchars($user_info['email']); ?>" required placeholder="name@example.com">
                                </div>
                                <small class="text-secondary d-block mt-1">Dùng để nhận hóa đơn, thông tin tài khoản và khôi phục mật khẩu khi cần.</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label text-light small fw-bold">Cấp bậc thành viên:</label>
                                <input type="text" class="form-control form-control-gaming text-secondary" value="<?php echo ($user_info['role'] == 1) ? 'Quản Trị Viên (Administrator)' : 'Khách Hàng VIP (Customer)'; ?>" readonly disabled>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label text-light small fw-bold">Ngày tham gia hệ thống:</label>
                                <input type="text" class="form-control form-control-gaming text-secondary" value="<?php echo date('d/m/Y H:i:s', strtotime($user_info['created_at'] ?? 'now')); ?>" readonly disabled>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 pt-3 border-top border-secondary border-opacity-25">
                            <a href="profile.php?tab=overview" class="btn btn-gaming-secondary">Hủy bỏ</a>
                            <button type="submit" name="btn_update_profile" class="btn btn-gaming-primary">
                                <i class="bi bi-check2-circle me-1"></i> Lưu Thay Đổi
                            </button>
                        </div>
                    </form>
                </div>

            <?php elseif ($tab === 'orders'): ?>
                <!-- ================= TAB 3: ORDERS & TRANSACTION HISTORY ================= -->
                <div class="gaming-card p-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                        <div>
                            <h4 class="fw-bold text-white mb-1"><i class="bi bi-receipt-cutoff me-2 text-info"></i> Lịch Sử Đơn Hàng & Giao Dịch</h4>
                            <p class="text-secondary small mb-0">Theo dõi toàn bộ các đơn hàng thuê game, trạng thái thanh toán và thời hạn sử dụng</p>
                        </div>
                        <span class="badge bg-secondary bg-opacity-25 text-white border border-secondary px-3 py-2">
                            Tổng số đơn: <?php echo count($orders); ?>
                        </span>
                    </div>

                    <div class="table-gaming-container">
                        <div class="table-responsive">
                            <table class="table table-gaming align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 14%;">Mã Đơn Hàng</th>
                                        <th style="width: 26%;">Sản Phẩm / Game</th>
                                        <th style="width: 12%;">Giá Tiền</th>
                                        <th style="width: 14%;">Phương Thức</th>
                                        <th style="width: 14%;">Thanh Toán</th>
                                        <th style="width: 10%;">Trạng Thái</th>
                                        <th style="width: 10%;" class="text-end pe-3">Chi Tiết</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($orders)): ?>
                                        <?php foreach ($orders as $ord): 
                                            $due_ts = strtotime($ord['due_date'] ?? 'now');
                                            $is_expired = ($ord['status'] === 'expired' || ($ord['status'] === 'active' && $due_ts < time()));
                                            $pay_status = $ord['payment_status'] ?? 'pending';
                                        ?>
                                            <tr>
                                                <td class="font-monospace fw-bold text-info small">
                                                    #<?php echo htmlspecialchars($ord['order_code'] ?? ('RENT' . $ord['borrow_id'])); ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <?php if (!empty($ord['image_url'])): ?>
                                                            <img src="<?php echo htmlspecialchars($ord['image_url']); ?>" width="40" height="40" class="rounded object-fit-cover shadow-sm">
                                                        <?php endif; ?>
                                                        <div>
                                                            <div class="text-white fw-bold"><?php echo htmlspecialchars($ord['title'] ?? 'Game #' . $ord['game_id']); ?></div>
                                                            <div class="text-secondary small" style="font-size: 0.72rem;">
                                                                <i class="bi bi-clock me-1"></i> Gói <?php echo intval($ord['duration_hours'] ?? 24); ?> giờ
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="fw-bold text-warning">
                                                    <?php echo number_format(floatval($ord['rental_price'] ?? 0), 0, ',', '.'); ?>đ
                                                </td>
                                                <td>
                                                    <span class="text-secondary small">
                                                        <i class="bi bi-qr-code me-1"></i> <?php echo htmlspecialchars($ord['payment_method'] ?? 'VietQR'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($pay_status === 'completed' || $ord['status'] === 'active'): ?>
                                                        <span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid rgba(16,185,129,0.4);">
                                                            <i class="bi bi-check2 me-1"></i> Đã thanh toán
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge" style="background: rgba(245,158,11,0.2); color: #f59e0b; border: 1px solid rgba(245,158,11,0.4);">
                                                            <i class="bi bi-hourglass-split me-1"></i> Chờ duyệt
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($ord['status'] === 'active' && !$is_expired): ?>
                                                        <span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid rgba(16,185,129,0.4);">Kích hoạt</span>
                                                    <?php elseif ($is_expired): ?>
                                                        <span class="badge" style="background: rgba(244,63,94,0.15); color: #f43f5e; border: 1px solid rgba(244,63,94,0.3);">Đã hết hạn</span>
                                                    <?php else: ?>
                                                        <span class="badge" style="background: rgba(255,255,255,0.06); color: #94a3b8;"><?php echo strtoupper($ord['status']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <?php if ($ord['status'] === 'pending'): ?>
                                                        <a href="checkout_qr.php?order_id=<?php echo $ord['borrow_id']; ?>" class="btn btn-sm btn-gaming-primary py-1 px-2" style="font-size: 0.75rem;">
                                                            Thanh Toán
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="my_library.php" class="btn btn-sm btn-gaming-secondary py-1 px-2" style="font-size: 0.75rem;">
                                                            Xem Game
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5 text-secondary">
                                                <i class="bi bi-cart-x fs-1 d-block mb-2"></i>
                                                Bạn chưa có đơn hàng nào trong lịch sử giao dịch.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($tab === 'security'): ?>
                <!-- ================= TAB 4: CHANGE PASSWORD & SECURITY ================= -->
                <div class="gaming-card p-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <h4 class="fw-bold text-white mb-1"><i class="bi bi-shield-lock-fill me-2 text-warning"></i> Đổi Mật Khẩu & Bảo Mật Tài Khoản</h4>
                    <p class="text-secondary small mb-4">Khuyến cáo sử dụng mật khẩu mạnh kết hợp chữ hoa, chữ thường, số và ký tự đặc biệt</p>

                    <div class="row g-4">
                        <div class="col-lg-7">
                            <form method="POST" action="profile.php?tab=security">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                                <div class="mb-3">
                                    <label class="form-label text-light small fw-bold">Mật khẩu hiện tại:</label>
                                    <div class="input-group">
                                        <input type="password" name="current_password" id="cur_pass" class="form-control form-control-gaming" required placeholder="Nhập mật khẩu đang sử dụng">
                                        <button class="btn btn-gaming-secondary" type="button" onclick="togglePasswordVisibility('cur_pass', this)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-light small fw-bold">Mật khẩu mới:</label>
                                    <div class="input-group">
                                        <input type="password" name="new_password" id="new_pass" class="form-control form-control-gaming" minlength="6" required placeholder="Tối thiểu 6 ký tự">
                                        <button class="btn btn-gaming-secondary" type="button" onclick="togglePasswordVisibility('new_pass', this)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label text-light small fw-bold">Xác nhận mật khẩu mới:</label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" id="conf_pass" class="form-control form-control-gaming" minlength="6" required placeholder="Nhập lại mật khẩu mới">
                                        <button class="btn btn-gaming-secondary" type="button" onclick="togglePasswordVisibility('conf_pass', this)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end gap-2 pt-3 border-top border-secondary border-opacity-25">
                                    <button type="submit" name="btn_change_password" class="btn btn-gaming-primary">
                                        <i class="bi bi-shield-check me-1"></i> Cập Nhật Mật Khẩu Mới
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="col-lg-5">
                            <div class="p-4 rounded-3 h-100" style="background: #141724; border: 1px solid var(--border-color);">
                                <h6 class="fw-bold text-white mb-3 d-flex align-items-center">
                                    <i class="bi bi-shield-check text-success me-2 fs-5"></i> Trạng Thái Bảo Mật
                                </h6>
                                <ul class="list-unstyled text-secondary small mb-4">
                                    <li class="mb-2 d-flex align-items-center gap-2">
                                        <i class="bi bi-check-circle-fill text-success"></i> Mã hóa mật khẩu chuẩn Bcrypt
                                    </li>
                                    <li class="mb-2 d-flex align-items-center gap-2">
                                        <i class="bi bi-check-circle-fill text-success"></i> Chống tấn công giả mạo CSRF
                                    </li>
                                    <li class="mb-2 d-flex align-items-center gap-2">
                                        <i class="bi bi-check-circle-fill text-success"></i> Mã hóa thông tin đăng nhập tài khoản game AES-256
                                    </li>
                                    <li class="mb-2 d-flex align-items-center gap-2">
                                        <i class="bi bi-info-circle-fill text-info"></i> Phiên đăng nhập tự hủy khi đóng trình duyệt
                                    </li>
                                </ul>

                                <div class="p-3 rounded-2" style="background: rgba(244,63,94,0.08); border: 1px solid rgba(244,63,94,0.2);">
                                    <span class="text-danger fw-bold small d-block mb-1"><i class="bi bi-exclamation-octagon me-1"></i> Cảnh Báo An Toàn:</span>
                                    <span class="text-secondary small" style="font-size: 0.75rem;">
                                        Không chia sẻ tài khoản ANPLAY cho bất kỳ ai. Nhân viên ANPLAY không bao giờ yêu cầu bạn cung cấp mật khẩu cá nhân.
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(fieldId, btn) {
    var field = document.getElementById(fieldId);
    var icon = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        field.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

