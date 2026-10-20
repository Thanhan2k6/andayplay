<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/connect.php'; 
require_once __DIR__ . '/includes/totp_service.php';

// Hủy chế độ 2FA pending nếu user bấm hủy
if (isset($_GET['cancel_2fa'])) {
    unset(
        $_SESSION['2fa_pending'],
        $_SESSION['2fa_pending_user_id'],
        $_SESSION['2fa_pending_username'],
        $_SESSION['2fa_pending_email'],
        $_SESSION['2fa_pending_role'],
        $_SESSION['2fa_pending_secret']
    );
    header("Location: login.php");
    exit();
}

$script_hieu_ung = "";

// 1. XỬ LÝ ĐĂNG NHẬP BƯỚC 1: USERNAME + PASSWORD
if (isset($_POST['btn_login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? ''; 

    $stmt_u = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
    if ($stmt_u) {
        $stmt_u->bind_param("ss", $username, $username);
        $stmt_u->execute();
        $result = $stmt_u->get_result();

        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // Dùng password_verify để so khớp mật khẩu mã hóa Bcrypt
            if (password_verify($password, $user['password']) || $password == $user['password']) {
                if (isset($user['status']) && in_array($user['status'], ['banned', 'suspended'])) {
                    $status_txt = ($user['status'] === 'banned') ? 'bị khóa vĩnh viễn' : 'đang bị tạm ngưng hoạt động';
                    $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Tài khoản đã bị khóa', text: 'Tài khoản của bạn $status_txt bởi Quản Trị Cấp Cao. Vui lòng liên hệ ban quản trị để được hỗ trợ!', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#ef4444' });";
                } elseif (!empty($user['two_factor_enabled']) && intval($user['two_factor_enabled']) === 1) {
                    // CHUYỂN TIẾP SANG BƯỚC 2: XÁC THỰC 2 LỚP 2FA
                    $_SESSION['2fa_pending'] = true;
                    $_SESSION['2fa_pending_user_id'] = $user['user_id'];
                    $_SESSION['2fa_pending_username'] = $user['username'];
                    $_SESSION['2fa_pending_email'] = $user['email'];
                    $_SESSION['2fa_pending_role'] = $user['role'];
                    $_SESSION['2fa_pending_secret'] = $user['two_factor_secret'];
                } else {
                    // ĐĂNG NHẬP TRỰC TIẾP (KHI KHÔNG BẬT 2FA)
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];

                    $script_hieu_ung = "
                        var duration = 2.5 * 1000;
                        var animationEnd = Date.now() + duration;
                        var defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 9999 };
                        function randomInRange(min, max) { return Math.random() * (max - min) + min; }
                        var interval = setInterval(function() {
                            var timeLeft = animationEnd - Date.now();
                            if (timeLeft <= 0) { return clearInterval(interval); }
                            var particleCount = 40 * (timeLeft / duration);
                            confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } }));
                            confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } }));
                        }, 250);

                        Swal.fire({
                            title: '🎉 Đăng Nhập Thành Công!',
                            text: 'Chào mừng chiến binh @" . htmlspecialchars($user['username']) . " quay lại ANPLAY STORE.',
                            icon: 'success',
                            background: '#0f1118',
                            color: '#f8fafc',
                            confirmButtonColor: '#6366f1',
                            confirmButtonText: 'VÀO CHIẾN GAME NGAY',
                            customClass: { popup: 'z-index-master' }
                        }).then((result) => {
                            window.location.href = 'index.php';
                        });
                    ";
                }
            } else {
                $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Đăng nhập thất bại', text: 'Mật khẩu không chính xác, vui lòng kiểm tra lại.', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#ef4444' });";
            }
        } else {
            $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Tài khoản không tồn tại', text: 'Tài khoản hoặc Email chưa được đăng ký trên hệ thống.', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#ef4444' });";
        }
        $stmt_u->close();
    }
}

// 2. XỬ LÝ XÁC THỰC BƯỚC 2 (2FA OTP HOẶC MÃ DỰ PHÒNG)
if (isset($_POST['btn_verify_2fa'])) {
    if (!empty($_SESSION['2fa_pending']) && !empty($_SESSION['2fa_pending_user_id'])) {
        $code = trim($_POST['totp_code'] ?? '');
        $uid = intval($_SESSION['2fa_pending_user_id']);
        $secret = $_SESSION['2fa_pending_secret'] ?? '';

        $valid_otp = TotpService::verifyCode($secret, $code);
        $valid_backup = false;
        if (!$valid_otp) {
            $valid_backup = TotpService::verifyAndConsumeBackupCode($conn, $uid, $code);
        }

        if ($valid_otp || $valid_backup) {
            $_SESSION['user_id'] = $_SESSION['2fa_pending_user_id'];
            $_SESSION['username'] = $_SESSION['2fa_pending_username'];
            $_SESSION['email'] = $_SESSION['2fa_pending_email'];
            $_SESSION['role'] = $_SESSION['2fa_pending_role'];

            $logged_user = $_SESSION['username'];

            unset(
                $_SESSION['2fa_pending'],
                $_SESSION['2fa_pending_user_id'],
                $_SESSION['2fa_pending_username'],
                $_SESSION['2fa_pending_email'],
                $_SESSION['2fa_pending_role'],
                $_SESSION['2fa_pending_secret']
            );

            $script_hieu_ung = "
                var duration = 2.5 * 1000;
                var animationEnd = Date.now() + duration;
                var defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 9999 };
                function randomInRange(min, max) { return Math.random() * (max - min) + min; }
                var interval = setInterval(function() {
                    var timeLeft = animationEnd - Date.now();
                    if (timeLeft <= 0) { return clearInterval(interval); }
                    var particleCount = 40 * (timeLeft / duration);
                    confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } }));
                    confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } }));
                }, 250);

                Swal.fire({
                    title: '🛡️ Xác Thực 2FA Thành Công!',
                    text: 'Chào mừng chiến binh @" . htmlspecialchars($logged_user) . " quay lại ANPLAY STORE.',
                    icon: 'success',
                    background: '#0f1118',
                    color: '#f8fafc',
                    confirmButtonColor: '#10b981',
                    confirmButtonText: 'VÀO CHIẾN GAME NGAY',
                    customClass: { popup: 'z-index-master' }
                }).then((result) => {
                    window.location.href = 'index.php';
                });
            ";
        } else {
            $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Xác thực 2FA thất bại', text: 'Mã xác thực 6 số không đúng hoặc mã dự phòng đã được sử dụng. Vui lòng thử lại!', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#ef4444' });";
        }
    }
}

$is_2fa_step = !empty($_SESSION['2fa_pending']) && !empty($_SESSION['2fa_pending_user_id']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_2fa_step ? 'Xác Thực 2FA' : 'Đăng Nhập'; ?> - ANPLAY STORE | Nền Tảng Thuê Tài Khoản Game</title>
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --bg-dark: #08090d;
            --bg-card: #0f1118;
            --border-color: rgba(255, 255, 255, 0.08);
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --primary-glow: rgba(99, 102, 241, 0.4);
            --accent-cyan: #06b6d4;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            position: relative;
            background-image: 
                radial-gradient(circle at 15% 20%, rgba(99, 102, 241, 0.12) 0%, transparent 40%),
                radial-gradient(circle at 85% 80%, rgba(6, 182, 212, 0.1) 0%, transparent 40%),
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 40px 40px, 40px 40px;
        }

        .auth-container {
            width: 100%;
            max-width: 440px;
            padding: 20px;
            z-index: 10;
        }

        .auth-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 36px 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
            position: relative;
            overflow: hidden;
        }

        .auth-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--accent-cyan));
        }

        .brand-box {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 0 20px var(--primary-glow);
            margin-bottom: 16px;
        }

        .brand-box-2fa {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.4);
        }

        .form-control-gaming {
            background-color: #151824;
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.25s ease;
        }

        .form-control-gaming:focus {
            background-color: #1a1e2e;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            color: #fff;
        }

        .input-group-text {
            background-color: #151824;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            border-radius: 10px;
        }

        .btn-gaming-primary {
            background: linear-gradient(135deg, var(--primary) 0%, #4f46e5 100%);
            color: #ffffff;
            border: none;
            padding: 12px;
            font-weight: 700;
            border-radius: 10px;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px var(--primary-glow);
        }

        .btn-gaming-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px var(--primary-glow);
            color: #fff;
        }

        .btn-gaming-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            border: none;
            padding: 12px;
            font-weight: 700;
            border-radius: 10px;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35);
        }

        .btn-gaming-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.5);
            color: #fff;
        }

        .z-index-master { z-index: 10000 !important; }
    </style>
</head>
<body>

<div class="auth-container">
    <div class="auth-card text-center">
        <?php if ($is_2fa_step): ?>
            <!-- ============================================== -->
            <!-- BƯỚC 2: XÁC THỰC 2 LỚP (2FA / GOOGLE AUTH)      -->
            <!-- ============================================== -->
            <div class="brand-box brand-box-2fa">
                <i class="bi bi-shield-lock-fill fs-3"></i>
            </div>
            <h3 class="fw-extrabold text-white mb-1" style="letter-spacing: -0.5px;">XÁC THỰC <span style="color: #10b981;">2 BƯỚC</span></h3>
            <p class="text-secondary small mb-4">
                Xin chào <strong class="text-white">@<?php echo htmlspecialchars($_SESSION['2fa_pending_username']); ?></strong>, vui lòng nhập mã OTP 6 số từ Google Authenticator hoặc mã dự phòng để đăng nhập.
            </p>

            <form method="POST" action="" class="text-start">
                <div class="mb-4">
                    <label class="form-label text-light small fw-bold mb-1">Mã xác thực hoặc Mã dự phòng</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key-fill text-warning"></i></span>
                        <input type="text" name="totp_code" class="form-control form-control-gaming font-monospace text-center fs-5 fw-bold text-warning" 
                               placeholder="000000 hoặc XXXX-XXXX" required autofocus autocomplete="one-time-code">
                    </div>
                    <small class="text-secondary d-block mt-1" style="font-size: 0.75rem;">
                        <i class="bi bi-info-circle me-1"></i> Mã 6 số trên điện thoại thay đổi mỗi 30 giây.
                    </small>
                </div>

                <button type="submit" name="btn_verify_2fa" class="btn btn-gaming-success w-100 py-3 mb-3 fw-bold">
                    <i class="bi bi-shield-check me-1 fs-5"></i> XÁC THỰC & ĐĂNG NHẬP
                </button>
            </form>

            <div class="pt-3 border-top border-secondary border-opacity-25 mt-3">
                <a href="login.php?cancel_2fa=1" class="text-secondary text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i> Đăng nhập bằng tài khoản khác
                </a>
            </div>

        <?php else: ?>
            <!-- ============================================== -->
            <!-- BƯỚC 1: ĐĂNG NHẬP USERNAME / EMAIL & PASSWORD  -->
            <!-- ============================================== -->
            <a href="index.php" class="text-decoration-none">
                <div class="brand-box">
                    <i class="bi bi-controller fs-3"></i>
                </div>
                <h3 class="fw-extrabold text-white mb-1" style="letter-spacing: -0.5px;">ANPLAY <span style="color: var(--accent-cyan);">STORE</span></h3>
            </a>
            <p class="text-secondary small mb-4">Đăng nhập tài khoản để vào thế giới game bản quyền</p>

            <form method="POST" action="" class="text-start">
                <!-- Username Input -->
                <div class="mb-3">
                    <label class="form-label text-light small fw-bold mb-1">Email hoặc Tên đăng nhập</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" class="form-control form-control-gaming" placeholder="Tên tài khoản hoặc email..." value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required autocomplete="username">
                    </div>
                </div>

                <!-- Password Input -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label text-light small fw-bold mb-0">Mật khẩu</label>
                        <a href="forgot_password.php" class="small text-decoration-none" style="color: var(--accent-cyan); font-size: 0.8rem;">Quên mật khẩu?</a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key"></i></span>
                        <input type="password" name="password" id="login_password" class="form-control form-control-gaming" placeholder="••••••••" required autocomplete="current-password">
                        <button class="btn btn-outline-secondary input-group-text" type="button" onclick="toggleAuthPass('login_password', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" name="btn_login" class="btn btn-gaming-primary w-100 py-3 mb-3">
                    <i class="bi bi-box-arrow-in-right me-1"></i> ĐĂNG NHẬP VÀO CỬA HÀNG
                </button>
            </form>

            <div class="pt-3 border-top border-secondary border-opacity-25 mt-3">
                <span class="text-secondary small">Chưa có tài khoản ANPLAY? </span>
                <a href="register.php" class="fw-bold text-decoration-none" style="color: var(--accent-cyan);">Đăng ký ngay</a>
            </div>

            <div class="mt-3">
                <a href="index.php" class="text-secondary text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i> Quay về trang chủ
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleAuthPass(inputId, btn) {
    var input = document.getElementById(inputId);
    var icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
<?php echo $script_hieu_ung; ?>
</script>

</body>
</html>
