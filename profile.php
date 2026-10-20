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
require_once __DIR__ . '/includes/totp_service.php';

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

// Tải thông tin người dùng từ CSDL sớm
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
                        if (function_exists('create_notification')) {
                            create_notification($conn, $user_id, 'account', 'Cập nhật email thành công 👤', 'Địa chỉ email mới của bạn đã được cập nhật thành: ' . $new_email, 'profile.php?tab=info');
                        }
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
                            if (function_exists('create_notification')) {
                                create_notification($conn, $user_id, 'account', 'Mật khẩu tài khoản đã được thay đổi 🔐', 'Mật khẩu của bạn vừa được cập nhật thành công lúc ' . date('H:i d/m/Y') . '. Nếu không phải bạn thực hiện, vui lòng liên hệ ngay với hỗ trợ.', 'profile.php?tab=security');
                            }
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
// 3. XỬ LÝ 2FA (TWO-FACTOR AUTHENTICATION)
// ----------------------------------------------------
if (isset($_GET['dismiss_backup'])) {
    unset($_SESSION['newly_generated_backup_codes']);
    header("Location: profile.php?tab=security");
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'cancel_2fa') {
    unset($_SESSION['temp_2fa_secret'], $_SESSION['temp_2fa_backup_codes']);
    header("Location: profile.php?tab=security");
    exit();
}

// Bật 2FA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_confirm_enable_2fa'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật (CSRF) không hợp lệ.";
        $tab = 'security';
    } else {
        $pwd = $_POST['current_password'] ?? '';
        $otp = trim($_POST['totp_code'] ?? '');
        $temp_secret = $_SESSION['temp_2fa_secret'] ?? '';
        $temp_backup = $_SESSION['temp_2fa_backup_codes'] ?? [];

        if (empty($temp_secret) || empty($temp_backup)) {
            $error_msg = "Phiên thiết lập 2FA đã hết hạn hoặc không hợp lệ. Vui lòng bấm thiết lập lại.";
            $tab = 'security';
        } elseif (!password_verify($pwd, $user_info['password']) && $pwd !== $user_info['password']) {
            $error_msg = "Mật khẩu tài khoản không chính xác.";
            $tab = 'security';
        } elseif (!TotpService::verifyCode($temp_secret, $otp)) {
            $error_msg = "Mã xác thực 6 số không chính xác hoặc đã quá hạn 30 giây. Vui lòng kiểm tra đồng hồ thiết bị và thử lại.";
            $tab = 'security';
        } else {
            $hashed_backup = TotpService::hashBackupCodes($temp_backup);
            $stmt = $conn->prepare("UPDATE users SET two_factor_enabled = 1, two_factor_secret = ?, two_factor_backup_codes = ? WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param("ssi", $temp_secret, $hashed_backup, $user_id);
                $stmt->execute();
                $stmt->close();

                $user_info['two_factor_enabled'] = 1;
                $user_info['two_factor_secret'] = $temp_secret;
                $user_info['two_factor_backup_codes'] = $hashed_backup;
                $_SESSION['newly_generated_backup_codes'] = $temp_backup;
                unset($_SESSION['temp_2fa_secret'], $_SESSION['temp_2fa_backup_codes']);

                if (function_exists('create_notification')) {
                    create_notification($conn, $user_id, 'account', 'Xác thực 2 bước (2FA) đã được kích hoạt 🛡️', 'Tài khoản của bạn hiện đã được bảo vệ với xác thực 2 lớp qua Google Authenticator.', 'profile.php?tab=security');
                }
                $success_msg = "Kích hoạt xác thực 2 bước (2FA) thành công! Hãy lưu lại danh sách mã dự phòng bên dưới.";
                $tab = 'security';
            } else {
                $error_msg = "Lỗi hệ thống khi lưu cài đặt 2FA. Vui lòng thử lại.";
                $tab = 'security';
            }
        }
    }
}

// Tắt 2FA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_disable_2fa'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật không hợp lệ.";
        $tab = 'security';
    } else {
        $pwd = $_POST['current_password'] ?? '';
        $otp = trim($_POST['totp_code'] ?? '');

        if (!password_verify($pwd, $user_info['password']) && $pwd !== $user_info['password']) {
            $error_msg = "Mật khẩu tài khoản không chính xác.";
            $tab = 'security';
        } else {
            $valid_otp = TotpService::verifyCode($user_info['two_factor_secret'] ?? '', $otp);
            $valid_backup = false;
            if (!$valid_otp) {
                $valid_backup = TotpService::verifyAndConsumeBackupCode($conn, $user_id, $otp);
            }

            if (!$valid_otp && !$valid_backup) {
                $error_msg = "Mã xác thực 6 số hoặc mã dự phòng không đúng.";
                $tab = 'security';
            } else {
                $stmt = $conn->prepare("UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL, two_factor_backup_codes = NULL WHERE user_id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $stmt->close();

                    $user_info['two_factor_enabled'] = 0;
                    $user_info['two_factor_secret'] = null;
                    $user_info['two_factor_backup_codes'] = null;
                    unset($_SESSION['newly_generated_backup_codes']);

                    if (function_exists('create_notification')) {
                        create_notification($conn, $user_id, 'account', 'Xác thực 2 bước (2FA) đã bị tắt ⚠️', 'Bạn vừa tắt xác thực 2 lớp cho tài khoản ANPLAY lúc ' . date('H:i d/m/Y'), 'profile.php?tab=security');
                    }
                    $success_msg = "Đã tắt xác thực 2 bước (2FA) thành công.";
                    $tab = 'security';
                }
            }
        }
    }
}

// Tạo lại mã dự phòng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_regenerate_backup_codes'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật không hợp lệ.";
        $tab = 'security';
    } else {
        $pwd = $_POST['current_password'] ?? '';
        $otp = trim($_POST['totp_code'] ?? '');

        if (!password_verify($pwd, $user_info['password']) && $pwd !== $user_info['password']) {
            $error_msg = "Mật khẩu tài khoản không chính xác.";
            $tab = 'security';
        } elseif (!TotpService::verifyCode($user_info['two_factor_secret'] ?? '', $otp)) {
            $error_msg = "Mã xác thực OTP 6 số không chính xác.";
            $tab = 'security';
        } else {
            $new_backup = TotpService::generateBackupCodes(8);
            $hashed = TotpService::hashBackupCodes($new_backup);
            $stmt = $conn->prepare("UPDATE users SET two_factor_backup_codes = ? WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $hashed, $user_id);
                $stmt->execute();
                $stmt->close();

                $user_info['two_factor_backup_codes'] = $hashed;
                $_SESSION['newly_generated_backup_codes'] = $new_backup;

                if (function_exists('create_notification')) {
                    create_notification($conn, $user_id, 'account', 'Mã dự phòng 2FA đã được tạo mới 🔑', 'Danh sách 8 mã dự phòng mới đã được cấp cho tài khoản của bạn.', 'profile.php?tab=security');
                }
                $success_msg = "Đã tạo lại danh sách 8 mã dự phòng mới! Hãy lưu lại các mã bên dưới.";
                $tab = 'security';
            }
        }
    }
}

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
                    <a href="notifications.php" class="nav-link py-3 px-3 rounded-3 d-flex align-items-center gap-3 text-secondary" style="border: 1px solid transparent;">
                        <i class="bi bi-bell-fill fs-5 text-warning"></i>
                        <div>
                            <div class="text-white fw-bold">Thông Báo Hệ Thống</div>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">Hộp thư, cập nhật và khuyến mãi</small>
                        </div>
                    </a>

                    <a href="support.php" class="nav-link py-3 px-3 rounded-3 d-flex align-items-center gap-3 text-secondary" style="border: 1px solid transparent;">
                        <i class="bi bi-headset fs-5 text-info"></i>
                        <div>
                            <div class="text-white fw-bold">Hỗ Trợ & Khiếu Nại</div>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">Tạo ticket và trò chuyện với kỹ thuật</small>
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
                    <p class="text-secondary small mb-2" style="font-size: 0.75rem;">Gặp sự cố với tài khoản thuê hoặc lỗi thanh toán?</p>
                    <a href="support.php" class="btn btn-sm btn-gaming-primary w-100 py-1 mb-2" style="font-size: 0.78rem;">
                        <i class="bi bi-plus-circle me-1"></i> Gửi Yêu Cầu Hỗ Trợ
                    </a>
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

                <!-- ================= 2FA (TWO-FACTOR AUTHENTICATION) ================= -->
                <div class="gaming-card p-4 mt-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                        <div>
                            <h4 class="fw-bold text-white mb-1">
                                <i class="bi bi-shield-check text-primary me-2"></i> Xác Thực 2 Bước (2FA / Google Authenticator)
                            </h4>
                            <p class="text-secondary small mb-0">Bảo vệ tài khoản và kho game của bạn với lớp bảo mật RFC 6238 TOTP tiêu chuẩn ngân hàng</p>
                        </div>
                        <div>
                            <?php if (($user_info['two_factor_enabled'] ?? 0) == 1): ?>
                                <span class="badge bg-success bg-opacity-20 text-success border border-success border-opacity-30 px-3 py-2 fw-bold">
                                    <i class="bi bi-shield-fill-check me-1"></i> 2FA ĐANG BẬT
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-20 text-secondary border border-secondary border-opacity-30 px-3 py-2 fw-bold">
                                    <i class="bi bi-shield-x me-1"></i> 2FA ĐANG TẮT
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($_SESSION['newly_generated_backup_codes'])): ?>
                        <!-- HIỂN THỊ MÃ DỰ PHÒNG KHI VỪA TẠO HOẶC KÍCH HOẠT -->
                        <div class="p-3 p-md-4 rounded-3 mb-4" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3);">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="text-success fw-bold mb-0">
                                    <i class="bi bi-key-fill me-1"></i> 8 MÃ DỰ PHÒNG CỦA BẠN (LƯU LẠI NGAY)
                                </h6>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="copyAllBackupCodes(this)">
                                    <i class="bi bi-clipboard me-1"></i> Sao chép tất cả
                                </button>
                            </div>
                            <p class="text-secondary small mb-3">Mỗi mã chỉ dùng được 1 lần để đăng nhập khi mất điện thoại hoặc không mở được Authenticator. Hệ thống chỉ lưu mã băm bảo mật SHA-256 nên các mã này sẽ không hiển thị lại sau khi rời trang.</p>
                            <div class="row g-2 mb-3">
                                <?php foreach ($_SESSION['newly_generated_backup_codes'] as $bcode): ?>
                                    <div class="col-6 col-md-3">
                                        <div class="p-2 text-center rounded font-monospace fw-bold text-white bg-dark border border-secondary border-opacity-25 user-select-all backup-code-item">
                                            <?php echo htmlspecialchars($bcode); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-end">
                                <a href="profile.php?tab=security&dismiss_backup=1" class="btn btn-sm btn-gaming-secondary">
                                    <i class="bi bi-check2 me-1"></i> Tôi đã lưu các mã an toàn
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (($user_info['two_factor_enabled'] ?? 0) == 1): ?>
                        <!-- TRẠNG THÁI: 2FA ĐANG BẬT -->
                        <?php 
                        $stored_backup_codes = json_decode($user_info['two_factor_backup_codes'] ?? '[]', true);
                        $remaining_codes_count = is_array($stored_backup_codes) ? count($stored_backup_codes) : 0;
                        ?>
                        <div class="row g-4 align-items-center">
                            <div class="col-md-7">
                                <div class="p-3 rounded-3 mb-3" style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2);">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="p-3 rounded-circle bg-success bg-opacity-20 text-success fs-3">
                                            <i class="bi bi-shield-lock-fill"></i>
                                        </div>
                                        <div>
                                            <h6 class="text-white fw-bold mb-1">Tài khoản được bảo vệ bởi xác thực 2 bước</h6>
                                            <p class="text-secondary small mb-0">Mỗi lần đăng nhập sẽ yêu cầu mật khẩu và mã OTP 6 số từ Google Authenticator hoặc mã dự phòng.</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center justify-content-between p-3 rounded-3" style="background: #141724; border: 1px solid var(--border-color);">
                                    <div>
                                        <span class="text-light small fw-bold d-block">Mã dự phòng khả dụng:</span>
                                        <span class="text-secondary small">Dùng khi không truy cập được điện thoại</span>
                                    </div>
                                    <span class="badge <?php echo $remaining_codes_count > 2 ? 'bg-primary' : 'bg-warning text-dark'; ?> fs-6 px-3 py-2">
                                        <?php echo $remaining_codes_count; ?> / 8 mã
                                    </span>
                                </div>
                            </div>

                            <div class="col-md-5">
                                <div class="d-grid gap-2">
                                    <button class="btn btn-gaming-secondary text-white py-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapseRegenBackup">
                                        <i class="bi bi-arrow-repeat me-1"></i> Tạo Lại Mã Dự Phòng Mới
                                    </button>
                                    <button class="btn btn-outline-danger py-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDisable2fa">
                                        <i class="bi bi-shield-slash me-1"></i> Tắt Xác Thực 2 Bước (2FA)
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Form Tạo Lại Mã Dự Phòng -->
                        <div class="collapse mt-3" id="collapseRegenBackup">
                            <div class="p-3 rounded-3" style="background: #111420; border: 1px solid var(--border-subtle);">
                                <h6 class="text-warning fw-bold mb-2"><i class="bi bi-key me-1"></i> Tạo Lại Danh Sách 8 Mã Dự Phòng Mới</h6>
                                <p class="text-secondary small mb-3">Mọi mã dự phòng cũ sẽ bị hủy bỏ. Vui lòng xác thực mật khẩu và mã OTP hiện tại:</p>
                                <form method="POST" action="profile.php?tab=security" class="row g-2 align-items-end">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <div class="col-md-5">
                                        <label class="form-label text-secondary small mb-1">Mật khẩu tài khoản:</label>
                                        <input type="password" name="current_password" required class="form-control form-control-sm form-control-gaming" placeholder="Mật khẩu của bạn">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label text-secondary small mb-1">Mã OTP 6 số:</label>
                                        <input type="text" name="totp_code" maxlength="6" pattern="[0-9]{6}" required class="form-control form-control-sm form-control-gaming font-monospace text-center" placeholder="000000">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" name="btn_regenerate_backup_codes" class="btn btn-sm btn-warning w-100 py-1">
                                            Cấp 8 Mã Mới
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Form Tắt 2FA -->
                        <div class="collapse mt-3" id="collapseDisable2fa">
                            <div class="p-3 rounded-3" style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.25);">
                                <h6 class="text-danger fw-bold mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i> Xác Nhận Tắt Bảo Vệ 2FA</h6>
                                <p class="text-secondary small mb-3">Tài khoản của bạn sẽ giảm mức độ an toàn. Vui lòng nhập mật khẩu và mã OTP (hoặc mã dự phòng) để xác nhận:</p>
                                <form method="POST" action="profile.php?tab=security" class="row g-2 align-items-end">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <div class="col-md-5">
                                        <label class="form-label text-secondary small mb-1">Mật khẩu tài khoản:</label>
                                        <input type="password" name="current_password" required class="form-control form-control-sm form-control-gaming" placeholder="Mật khẩu của bạn">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label text-secondary small mb-1">Mã OTP 6 số hoặc Mã dự phòng:</label>
                                        <input type="text" name="totp_code" required class="form-control form-control-sm form-control-gaming font-monospace text-center" placeholder="OTP / XXXX-XXXX">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" name="btn_disable_2fa" class="btn btn-sm btn-danger w-100 py-1">
                                            Xác Nhận Tắt 2FA
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- TRẠNG THÁI: 2FA ĐANG TẮT -->
                        <?php if (isset($_GET['action']) && $_GET['action'] === 'setup_2fa'): ?>
                            <?php 
                            if (empty($_SESSION['temp_2fa_secret'])) {
                                $_SESSION['temp_2fa_secret'] = TotpService::generateSecret(16);
                                $_SESSION['temp_2fa_backup_codes'] = TotpService::generateBackupCodes(8);
                            }
                            $temp_secret = $_SESSION['temp_2fa_secret'];
                            $temp_backup = $_SESSION['temp_2fa_backup_codes'];
                            $qr_url = TotpService::getQrCodeUrl($user_info['username'], $temp_secret);
                            ?>
                            <div class="p-3 p-md-4 rounded-3" style="background: #111420; border: 1px solid rgba(99, 102, 241, 0.3);">
                                <h5 class="fw-bold text-white mb-3"><i class="bi bi-qr-code-scan text-primary me-2"></i> Thiết Lập Xác Thực 2 Bước (3 Bước Đơn Giản)</h5>

                                <div class="row g-4">
                                    <!-- Bước 1: Quét mã QR -->
                                    <div class="col-md-4 text-center">
                                        <div class="p-2 bg-white rounded-3 d-inline-block border mb-2">
                                            <img src="<?php echo $qr_url; ?>" alt="QR Code 2FA" class="img-fluid" style="width: 170px; height: 170px;">
                                        </div>
                                        <small class="text-secondary d-block mb-1">Dùng Google Authenticator quét mã</small>
                                        <div class="mt-2">
                                            <small class="text-muted d-block" style="font-size: 0.72rem;">Hoặc nhập khóa bí mật thủ công:</small>
                                            <span class="font-monospace text-info fw-bold user-select-all small d-block"><?php echo $temp_secret; ?></span>
                                        </div>
                                    </div>

                                    <!-- Bước 2 & 3: Lưu mã dự phòng & Nhập OTP -->
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label class="text-light fw-bold small d-block mb-1">
                                                Bước 2: Lưu 8 mã dự phòng (Phòng khi mất điện thoại):
                                            </label>
                                            <div class="row g-1 mb-2">
                                                <?php foreach ($temp_backup as $tc): ?>
                                                    <div class="col-6 col-sm-3">
                                                        <div class="p-1 px-2 text-center rounded font-monospace small text-white bg-dark border border-secondary border-opacity-25 user-select-all">
                                                            <?php echo htmlspecialchars($tc); ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <small class="text-secondary" style="font-size: 0.75rem;">
                                                <i class="bi bi-info-circle me-1"></i> Mã dự phòng dùng một lần sẽ tự động có hiệu lực sau khi bạn kích hoạt thành công.
                                            </small>
                                        </div>

                                        <form method="POST" action="profile.php?tab=security" class="pt-2 border-top border-secondary border-opacity-25">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <label class="text-light fw-bold small d-block mb-2">Bước 3: Nhập Mật khẩu & Mã OTP 6 số để kích hoạt:</label>

                                            <div class="row g-2 mb-3">
                                                <div class="col-sm-6">
                                                    <input type="password" name="current_password" required class="form-control form-control-gaming" placeholder="Mật khẩu tài khoản...">
                                                </div>
                                                <div class="col-sm-6">
                                                    <input type="text" name="totp_code" maxlength="6" pattern="[0-9]{6}" required class="form-control form-control-gaming font-monospace text-center fs-5 text-warning fw-bold" placeholder="000000" autocomplete="one-time-code">
                                                </div>
                                            </div>

                                            <div class="d-flex gap-2">
                                                <a href="profile.php?tab=security&action=cancel_2fa" class="btn btn-gaming-secondary">Hủy bỏ</a>
                                                <button type="submit" name="btn_confirm_enable_2fa" class="btn btn-gaming-primary flex-grow-1">
                                                    <i class="bi bi-check2-circle me-1"></i> Hoàn Tất & Bật Bảo Vệ 2FA
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <p class="text-secondary small mb-3">
                                        Khi bật xác thực 2 bước (2FA), bạn sẽ cần nhập thêm mã 6 số từ ứng dụng bảo mật (Google Authenticator, Microsoft Authenticator) mỗi khi đăng nhập. Điều này ngăn chặn hacker dù họ có biết mật khẩu của bạn.
                                    </p>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-dark border border-secondary text-secondary small py-2 px-3">
                                            <i class="bi bi-google text-danger me-1"></i> Google Authenticator
                                        </span>
                                        <span class="badge bg-dark border border-secondary text-secondary small py-2 px-3">
                                            <i class="bi bi-microsoft text-primary me-1"></i> Microsoft Authenticator
                                        </span>
                                        <span class="badge bg-dark border border-secondary text-secondary small py-2 px-3">
                                            <i class="bi bi-shield text-info me-1"></i> Twilio Authy
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                    <a href="profile.php?tab=security&action=setup_2fa" class="btn btn-gaming-primary py-2 px-4 shadow">
                                        <i class="bi bi-qr-code-scan me-1"></i> Bắt Đầu Thiết Lập 2FA
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
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

function copyAllBackupCodes(btn) {
    var codes = [];
    document.querySelectorAll('.backup-code-item').forEach(function(el) {
        codes.push(el.innerText.trim());
    });
    var text = "MÃ DỰ PHÒNG ANPLAY STORE (2FA BACKUP CODES):\n" + codes.join("\n") + "\n(Lưu ý: Mỗi mã chỉ dùng 1 lần)";
    navigator.clipboard.writeText(text).then(function() {
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 text-success"></i> Đã chép tất cả!';
        setTimeout(function() { btn.innerHTML = orig; }, 2000);
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

