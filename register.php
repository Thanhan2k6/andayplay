<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Gọi file kết nối CSDL
require_once 'includes/connect.php'; 

$script_hieu_ung = "";

// Lắng nghe tín hiệu từ nút Đăng ký
if (isset($_POST['btn_register'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password']; 
    $re_password = $_POST['re_password'];

    // Kiểm tra mật khẩu khớp nhau
    if ($password !== $re_password) {
        $script_hieu_ung = "Swal.fire({ icon: 'warning', title: 'Oop!', text: 'Mật khẩu nhập lại không khớp.', background: '#1e293b', color: '#fff' });";
    } else {
        // Kiểm tra trùng lặp
        $check = $conn->query("SELECT * FROM users WHERE username = '$username' OR email = '$email'");
        if ($check && $check->num_rows > 0) {
            $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Trùng lặp!', text: 'Tên đăng nhập hoặc Email đã có người sử dụng.', background: '#1e293b', color: '#fff' });";
        } else {
            // Thêm vào Database
            $sql_insert = "INSERT INTO users (username, email, password, role) VALUES ('$username', '$email', '$password', 0)";
            if ($conn->query($sql_insert) === TRUE) {
                
                // HIỆU ỨNG BẮN PHÁO HOA KHI ĐĂNG KÝ THÀNH CÔNG
                $script_hieu_ung = "
                    var end = Date.now() + (2 * 1000);
                    var colors = ['#10b981', '#ffffff']; // Tone màu xanh của nút đăng ký
                    (function frame() {
                        confetti({ particleCount: 5, angle: 60, spread: 55, origin: { x: 0 }, colors: colors, zIndex: 9999 });
                        confetti({ particleCount: 5, angle: 120, spread: 55, origin: { x: 1 }, colors: colors, zIndex: 9999 });
                        if (Date.now() < end) { requestAnimationFrame(frame); }
                    }());

                    Swal.fire({
                        title: 'Tạo Tài Khoản Thành Công!',
                        text: 'Chào mừng bạn gia nhập cộng đồng ANPLAY.',
                        icon: 'success',
                        background: '#1e293b',
                        color: '#fff',
                        confirmButtonColor: '#10b981',
                        confirmButtonText: 'ĐI TỚI ĐĂNG NHẬP',
                        customClass: { popup: 'z-index-master' } // Nổi lên trên cùng
                    }).then((result) => {
                        window.location.href = 'login.php';
                    });
                ";
            } else {
                $script_hieu_ung = "Swal.fire({ icon: 'error', title: 'Lỗi máy chủ', text: 'Vui lòng thử lại sau.', background: '#1e293b', color: '#fff' });";
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
    <title>Đăng Ký - ANPLAY STORE</title>
    <!-- Bootstrap 5 & Google Fonts -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- THƯ VIỆN PHÁO HOA & THÔNG BÁO -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body {
            font-family: 'Nunito', sans-serif;
            background: url('https://images.unsplash.com/photo-1511512578047-dfb367046420?q=80&w=2071&auto=format&fit=crop') center/cover no-repeat;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px 0;
        }
        .overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
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
            max-width: 500px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            z-index: 1; /* Cực kỳ quan trọng */
        }
        .form-control {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255,255,255,0.1);
            color: #fff;
            padding: 10px 20px;
            border-radius: 10px;
        }
        .form-control:focus {
            background: rgba(15, 23, 42, 0.9);
            border-color: #10b981;
            box-shadow: 0 0 0 0.25rem rgba(16, 185, 129, 0.25);
            color: #fff;
        }
        .btn-green {
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            color: #fff;
            padding: 12px;
            border-radius: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
        }
        .btn-green:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(16, 185, 129, 0.4); color: #fff; }
        
        /* Đảm bảo SweetAlert nằm trên cùng */
        .z-index-master { z-index: 10000 !important; }
    </style>
</head>
<body>

<div class="overlay"></div>

<div class="auth-card text-white">
    <div class="text-center mb-4">
        <h2 class="fw-bold" style="background: linear-gradient(90deg, #10b981, #34d399); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">TẠO TÀI KHOẢN MỚI</h2>
        <p class="text-secondary">Trở thành thành viên của ANPLAY</p>
    </div>

    <!-- Nơi Form gửi dữ liệu đi -->
    <form method="POST" action="">
        <div class="mb-3">
            <label class="form-label text-light fw-bold">Tên đăng nhập (Username)</label>
            <input type="text" name="username" class="form-control" placeholder="Tên hiển thị..." value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label text-light fw-bold">Địa chỉ Email</label>
            <input type="email" name="email" class="form-control" placeholder="name@example.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label text-light fw-bold">Mật khẩu</label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>
        <div class="mb-4">
            <label class="form-label text-light fw-bold">Xác nhận Mật khẩu</label>
            <input type="password" name="re_password" class="form-control" placeholder="••••••••" required>
        </div>
        
        <!-- NÚT INPUT NÀY SẼ ĐẢM BẢO GỬI DỮ LIỆU THÀNH CÔNG -->
        <input type="submit" name="btn_register" class="btn btn-green w-100 mb-3" value="✍️ ĐĂNG KÝ THÀNH VIÊN">
    </form>
    
    <div class="text-center mt-2">
        <span class="text-secondary">Đã có tài khoản? </span>
        <a href="login.php" class="text-success fw-bold text-decoration-none">Đăng nhập</a>
    </div>
</div>

<script>
    // Chạy script hiệu ứng nếu có
    <?php echo $script_hieu_ung; ?>
</script>

</body>
</html>