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
        
        // SỬA LỖI TẠI ĐÂY: Dùng password_verify để so khớp mật khẩu mã hóa Bcrypt
        if (password_verify($password, $user['password']) || $password == $user['password']) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            $script_hieu_ung = "
                var duration = 3 * 1000;
                var animationEnd = Date.now() + duration;
                var defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 9999 };
                function randomInRange(min, max) { return Math.random() * (max - min) + min; }
                var interval = setInterval(function() {
                    var timeLeft = animationEnd - Date.now();
                    if (timeLeft <= 0) { return clearInterval(interval); }
                    var particleCount = 50 * (timeLeft / duration);
                    confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } }));
                    confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } }));
                }, 250);

                Swal.fire({
                    title: '🎉 Đăng Nhập Thành Công!',
                    text: 'Chào mừng chiến binh @" . htmlspecialchars($user['username']) . " quay lại ANPLAY STORE.',
                    icon: 'success',
                    background: '#1e293b',
                    color: '#fff',
                    confirmButtonColor: '#6366f1',
                    confirmButtonText: 'VÀO CHIẾN GAME NGAY',
                    customClass: { popup: 'z-index-master' }
                }).then((result) => {
                    window.location.href = 'index.php';
                });
            ";
        } else {
            $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Thất bại!', text: 'Mật khẩu không chính xác.', background: '#1e293b', color: '#fff' });";
        }
    } else {
        $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Thất bại!', text: 'Tài khoản hoặc Email không tồn tại.', background: '#1e293b', color: '#fff' });";
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập - ANPLAY STORE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body {
            font-family: 'Nunito', sans-serif;
            background: url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop') center/cover no-repeat;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(11, 11, 18, 0.85); 
            backdrop-filter: blur(5px);
        }
        .auth-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            z-index: 1; 
        }
        .form-control {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255,255,255,0.1);
            color: #fff;
            padding: 12px 20px;
            border-radius: 10px;
        }
        .form-control:focus {
            background: rgba(15, 23, 42, 0.9);
            border-color: #6366f1;
            box-shadow: 0 0 0 0.25rem rgba(99, 102, 241, 0.25);
            color: #fff;
        }
        .btn-purple {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            border: none;
            color: #fff;
            padding: 12px;
            border-radius: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
        }
        .btn-purple:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(99, 102, 241, 0.4); color: #fff; }
        .z-index-master { z-index: 10000 !important; }
    </style>
</head>
<body>

<div class="overlay"></div>

<div class="auth-card text-white">
    <div class="text-center mb-4">
        <h2 class="fw-bold" style="background: linear-gradient(90deg, #fff, #6366f1); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">ANPLAY STORE</h2>
        <p class="text-secondary">Đăng nhập để vào thế giới game</p>
    </div>

    <form method="POST" action="">
        <div class="mb-3">
            <label class="form-label text-light fw-bold">Email hoặc Tên đăng nhập</label>
            <input type="text" name="username" class="form-control" placeholder="Nhập tên tài khoản..." value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
        </div>
        <div class="mb-4">
            <div class="d-flex justify-content-between">
                <label class="form-label text-light fw-bold">Mật khẩu</label>
                <a href="#" class="text-info text-decoration-none small">Quên mật khẩu?</a>
            </div>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>
        <input type="submit" name="btn_login" class="btn btn-purple w-100 mb-3" value="🚀 BẮT ĐẦU ĐĂNG NHẬP">
    </form>
    
    <div class="text-center mt-3">
        <span class="text-secondary">Chưa có tài khoản? </span>
        <a href="register.php" class="text-info fw-bold text-decoration-none">Đăng ký ngay</a>
    </div>
</div>

<script>
    <?php echo $script_hieu_ung; ?>
</script>

</body>
</html>