<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/connect.php'; 

$script_hieu_ung = "";

if (isset($_POST['btn_register'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password']; 
    $re_password = $_POST['re_password'];

    if ($password !== $re_password) {
        $script_hieu_ung = "Swal.fire({ icon: 'warning', title: 'Mật khẩu không khớp!', text: 'Mật khẩu xác nhận không trùng khớp, vui lòng kiểm tra lại.', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#f59e0b' });";
    } else {
        $stmt_check = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $stmt_check->bind_param("ss", $username, $email);
        $stmt_check->execute();
        $check = $stmt_check->get_result();

        if ($check && $check->num_rows > 0) {
            $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Tài khoản đã tồn tại!', text: 'Tên đăng nhập hoặc Email này đã có người đăng ký.', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#ef4444' });";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt_insert = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 0)");
            $stmt_insert->bind_param("sss", $username, $email, $hashed_password);

            if ($stmt_insert->execute()) {
                $script_hieu_ung = "
                    var end = Date.now() + (2.5 * 1000);
                    var colors = ['#6366f1', '#06b6d4', '#10b981'];
                    (function frame() {
                        confetti({ particleCount: 5, angle: 60, spread: 55, origin: { x: 0 }, colors: colors, zIndex: 9999 });
                        confetti({ particleCount: 5, angle: 120, spread: 55, origin: { x: 1 }, colors: colors, zIndex: 9999 });
                        if (Date.now() < end) { requestAnimationFrame(frame); }
                    }());

                    Swal.fire({
                        title: 'Tạo Tài Khoản Thành Công!',
                        text: 'Chào mừng bạn gia nhập cộng đồng game thủ ANPLAY STORE.',
                        icon: 'success',
                        background: '#0f1118',
                        color: '#f8fafc',
                        confirmButtonColor: '#10b981',
                        confirmButtonText: 'ĐI TỚI ĐĂNG NHẬP',
                        customClass: { popup: 'z-index-master' }
                    }).then((result) => {
                        window.location.href = 'login.php';
                    });
                ";
            } else {
                $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Lỗi máy chủ', text: 'Có sự cố xảy ra, vui lòng thử lại sau.', background: '#0f1118', color: '#f8fafc' });";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Ký - ANPLAY STORE | Nền Tảng Thuê Tài Khoản Game</title>
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
            --primary-glow: rgba(99, 102, 241, 0.4);
            --accent-cyan: #06b6d4;
            --accent-success: #10b981;
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
            padding: 24px 0;
            background-image: 
                radial-gradient(circle at 85% 15%, rgba(6, 182, 212, 0.12) 0%, transparent 40%),
                radial-gradient(circle at 15% 85%, rgba(99, 102, 241, 0.1) 0%, transparent 40%),
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 40px 40px, 40px 40px;
        }

        .auth-container {
            width: 100%;
            max-width: 480px;
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
            background: linear-gradient(90deg, var(--accent-success), var(--primary));
        }

        .brand-box {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.4);
            margin-bottom: 16px;
        }

        .form-control-gaming {
            background-color: #151824;
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 11px 16px;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.25s ease;
        }

        .form-control-gaming:focus {
            background-color: #1a1e2e;
            border-color: var(--accent-success);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
            color: #fff;
        }

        .input-group-text {
            background-color: #151824;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            border-radius: 10px;
        }

        .btn-gaming-register {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            border: none;
            padding: 12px;
            font-weight: 700;
            border-radius: 10px;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-gaming-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
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
                <i class="bi bi-person-plus-fill fs-3"></i>
            </div>
            <h3 class="fw-extrabold text-white mb-1" style="letter-spacing: -0.5px;">TẠO TÀI KHOẢN MỚI</h3>
        </a>
        <p class="text-secondary small mb-4">Gia nhập ANPLAY STORE để trải nghiệm các siêu phẩm game giá rẻ</p>

        <form method="POST" action="" class="text-start">
            <!-- Username Input -->
            <div class="mb-3">
                <label class="form-label text-light small fw-bold mb-1">Tên đăng nhập</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" name="username" class="form-control form-control-gaming" placeholder="Ví dụ: gamer_pro99" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required autocomplete="username">
                </div>
            </div>

            <!-- Email Input -->
            <div class="mb-3">
                <label class="form-label text-light small fw-bold mb-1">Địa chỉ Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email" class="form-control form-control-gaming" placeholder="name@example.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required autocomplete="email">
                </div>
            </div>

            <!-- Password Input -->
            <div class="mb-3">
                <label class="form-label text-light small fw-bold mb-1">Mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-key"></i></span>
                    <input type="password" name="password" id="reg_pass" class="form-control form-control-gaming" placeholder="Tối thiểu 6 ký tự" required autocomplete="new-password">
                    <button class="btn btn-outline-secondary input-group-text" type="button" onclick="togglePass('reg_pass', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Re-password Input -->
            <div class="mb-4">
                <label class="form-label text-light small fw-bold mb-1">Xác nhận mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-shield-check"></i></span>
                    <input type="password" name="re_password" id="reg_repass" class="form-control form-control-gaming" placeholder="Nhập lại mật khẩu" required autocomplete="new-password">
                    <button class="btn btn-outline-secondary input-group-text" type="button" onclick="togglePass('reg_repass', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" name="btn_register" class="btn btn-gaming-register w-100 py-3 mb-3">
                <i class="bi bi-rocket-takeoff-fill me-1"></i> ĐĂNG KÝ TÀI KHOẢN NGAY
            </button>
        </form>

        <div class="pt-3 border-top border-secondary border-opacity-25 mt-3">
            <span class="text-secondary small">Đã có tài khoản? </span>
            <a href="login.php" class="fw-bold text-decoration-none" style="color: var(--accent-cyan);">Đăng nhập ngay</a>
        </div>

        <div class="mt-3">
            <a href="index.php" class="text-secondary text-decoration-none small">
                <i class="bi bi-arrow-left me-1"></i> Quay về trang chủ
            </a>
        </div>
    </div>
</div>

<script>
function togglePass(inputId, btn) {
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