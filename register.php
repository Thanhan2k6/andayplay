<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/connect.php'; 

$script_hieu_ung = "";

if (isset($_POST['btn_register'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $re_password = $_POST['re_password'] ?? '';

    if (empty($username) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $script_hieu_ung = "Swal.fire({ icon: 'warning', title: 'Thông tin chưa hợp lệ!', text: 'Vui lòng nhập tên đăng nhập và địa chỉ email hợp lệ.', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#f59e0b' });";
    } elseif (strlen($password) < 8) {
        $script_hieu_ung = "Swal.fire({ icon: 'warning', title: 'Mật khẩu quá ngắn!', text: 'Mật khẩu phải có ít nhất 8 ký tự.', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#f59e0b' });";
    } elseif ($password !== $re_password) {
        $script_hieu_ung = "Swal.fire({ icon: 'warning', title: 'Mật khẩu không khớp!', text: 'Mật khẩu xác nhận không trùng khớp, vui lòng kiểm tra lại.', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#f59e0b' });";
    } else {
        $stmt_check = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");
        if ($stmt_check) {
            $stmt_check->bind_param("ss", $username, $email);
            $stmt_check->execute();
            $check = $stmt_check->get_result();
            $exists = ($check && $check->num_rows > 0);
            $stmt_check->close();

            if ($exists) {
                $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Tài khoản đã tồn tại!', text: 'Tên đăng nhập hoặc Email này đã có người đăng ký.', background: '#0f1118', color: '#f8fafc', confirmButtonColor: '#ef4444' });";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt_insert = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 0)");
                if ($stmt_insert && $stmt_insert->bind_param("sss", $username, $email, $hashed_password) && $stmt_insert->execute()) {
                    $new_user_id = $conn->insert_id;
                    $stmt_insert->close();
                    if (function_exists('create_notification')) {
                        create_notification($conn, $new_user_id, 'account', 'Chào mừng bạn đến với ANPLAY STORE! 🎮', 'Tài khoản của bạn đã được khởi tạo thành công. Bắt đầu khám phá và thuê game bản quyền ngay hôm nay!', 'index.php', 'WELCOME-' . $new_user_id);
                    }
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
                    if ($stmt_insert) { $stmt_insert->close(); }
                    $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Lỗi máy chủ', text: 'Có sự cố xảy ra, vui lòng thử lại sau.', background: '#0f1118', color: '#f8fafc' });";
                }
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

    <!-- Early Theme Detection -->
    <script>
        (function() {
            try {
                var savedTheme = localStorage.getItem('anplay_theme');
                if (savedTheme === 'light') {
                    document.documentElement.setAttribute('data-theme', 'light');
                } else if (!savedTheme && window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
                    document.documentElement.setAttribute('data-theme', 'light');
                } else {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch(e) {}
        })();
    </script>

    <style>
        :root {
            color-scheme: dark;
            --bg-dark: #08090d;
            --bg-card: #0f1118;
            --input-bg: #151824;
            --input-bg-focus: #1a1e2e;
            --border-color: rgba(255, 255, 255, 0.08);
            --primary: #6366f1;
            --primary-glow: rgba(99, 102, 241, 0.25);
            --accent-cyan: #06b6d4;
            --accent-success: #10b981;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        [data-theme="light"] {
            color-scheme: light;
            --bg-dark: #f8fafc;
            --bg-card: #ffffff;
            --input-bg: #ffffff;
            --input-bg-focus: #ffffff;
            --border-color: #cbd5e1;
            --primary: #4f46e5;
            --primary-glow: rgba(79, 70, 229, 0.16);
            --accent-cyan: #0284c7;
            --accent-success: #059669;
            --text-main: #0f172a;
            --text-muted: #64748b;
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
                radial-gradient(circle at 85% 15%, rgba(6, 182, 212, 0.08) 0%, transparent 45%),
                radial-gradient(circle at 15% 85%, rgba(99, 102, 241, 0.06) 0%, transparent 45%);
        }

        [data-theme="light"] body {
            background-image:
                radial-gradient(circle at 85% 15%, rgba(2, 132, 199, 0.06) 0%, transparent 45%),
                radial-gradient(circle at 15% 85%, rgba(79, 70, 229, 0.05) 0%, transparent 45%);
        }

        .auth-theme-toggle {
            position: fixed;
            top: 16px;
            right: 16px;
            z-index: 50;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
        }

        .auth-theme-toggle:hover {
            border-color: var(--primary);
        }

        .auth-container {
            width: 100%;
            max-width: 480px;
            padding: 16px;
            z-index: 10;
        }

        .auth-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 32px 28px;
            box-shadow: 0 16px 36px -12px rgba(0, 0, 0, 0.45);
            position: relative;
            overflow: hidden;
        }

        [data-theme="light"] .auth-card {
            box-shadow: 0 12px 30px -8px rgba(15, 23, 42, 0.1);
        }

        @media (max-width: 400px) {
            .auth-card {
                padding: 24px 18px;
            }
        }

        .auth-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--accent-success), var(--primary));
        }

        .brand-box {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            margin-bottom: 14px;
        }

        .form-control-gaming {
            background-color: var(--input-bg);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 11px 14px;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .form-control-gaming::placeholder {
            color: var(--text-muted);
        }

        .form-control-gaming:focus {
            background-color: var(--input-bg-focus);
            border-color: var(--accent-success);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.18);
            color: var(--text-main);
        }

        .input-group-text {
            background-color: var(--input-bg);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            border-radius: 8px;
        }

        [data-theme="light"] .text-white {
            color: var(--text-main) !important;
        }

        [data-theme="light"] .text-secondary {
            color: var(--text-muted) !important;
        }

        .swal2-popup {
            background: var(--bg-card) !important;
            color: var(--text-main) !important;
            border: 1px solid var(--border-color) !important;
        }
        .swal2-title { color: var(--text-main) !important; }
        .swal2-html-container { color: var(--text-muted) !important; }

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

<button class="auth-theme-toggle" id="authThemeToggleBtn" type="button" onclick="toggleAuthThemeMode()" title="Chuyển chế độ Sáng / Tối" aria-label="Đổi giao diện">
    <i class="bi bi-sun-fill" id="authThemeIconSun" style="display: none; color: #f59e0b;"></i>
    <i class="bi bi-moon-stars-fill" id="authThemeIconMoon"></i>
</button>

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
function toggleAuthThemeMode() {
    var current = document.documentElement.getAttribute('data-theme') || 'dark';
    var next = (current === 'light') ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('anplay_theme', next); } catch(e) {}
    updateAuthThemeIcon(next);
}
function updateAuthThemeIcon(theme) {
    var sun = document.getElementById('authThemeIconSun');
    var moon = document.getElementById('authThemeIconMoon');
    if (sun && moon) {
        sun.style.display = (theme === 'light') ? 'inline-block' : 'none';
        moon.style.display = (theme === 'light') ? 'none' : 'inline-block';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    updateAuthThemeIcon(document.documentElement.getAttribute('data-theme') || 'dark');
});

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