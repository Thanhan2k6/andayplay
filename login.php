<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/connect.php'; 

$script_hieu_ung = "";

if (isset($_POST['btn_login'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password']; 

    $sql = "SELECT * FROM users WHERE username = '$username' OR email = '$username'";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Dùng password_verify để so khớp mật khẩu mã hóa Bcrypt
        if (password_verify($password, $user['password']) || $password == $user['password']) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
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
        } else {
            $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Đăng nhập thất bại', text: 'Mật khẩu không chính xác, vui lòng kiểm tra lại.', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#ef4444' });";
        }
    } else {
        $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Tài khoản không tồn tại', text: 'Tài khoản hoặc Email chưa được đăng ký trên hệ thống.', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#ef4444' });";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập - ANPLAY STORE | Nền Tảng Thuê Tài Khoản Game</title>
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

        .z-index-master { z-index: 10000 !important; }
    </style>
</head>
<body>

<div class="auth-container">
    <div class="auth-card text-center">
        <!-- Brand Icon & Title -->
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