<?php
// =========================================================
// HỆ THỐNG KẾT NỐI DATABASE THÔNG MINH (TỰ ĐỘNG NHẬN DIỆN MÔI TRƯỜNG)
// =========================================================

// Kiểm tra xem code đang chạy trên máy tính (localhost) hay chạy trên Hosting thật
if ($_SERVER['HTTP_HOST'] == 'localhost' || $_SERVER['HTTP_HOST'] == '127.0.0.1') {
    
    // MÔI TRƯỜNG DEV: Chạy trên XAMPP máy tính của bạn
    $host = "localhost";
    $user = "root";
    $pass = "";
    $db = "game_library_db";
    
} else {
    
    // MÔI TRƯỜNG PROD: Chạy trên Hosting InfinityFree (Bạn hãy thay các thông số bên dưới)
    $host = "sqlXXX.infinityfree.com";         // Thay bằng MySQL Hostname của bạn
    $user = "if0_XXXXXXX";                     // Thay bằng MySQL Username của bạn
    $pass = "MatKhauHostingCuaBan";            // Thay bằng Mật khẩu Hosting của bạn
    $db   = "if0_XXXXXXX_game_library_db";     // Thay bằng MySQL Database Name của bạn
    
}

// Khởi tạo kết nối đến CSDL
$conn = new mysqli($host, $user, $pass, $db);

// Kiểm tra kết nối
if ($conn->connect_error) {
    die("Kết nối thất bại. Vui lòng kiểm tra lại thông số Database: " . $conn->connect_error);
}

// Thiết lập font chữ tiếng Việt
$conn->set_charset("utf8");

// =========================================================
// HỆ THỐNG QUẢN LÝ BẢN QUYỀN (DRM) & AUTO-THU HỒI KEY
// =========================================================

// Tự động quét và vô hiệu hóa (đổi status thành 'expired') 
// các đơn thuê / License Key đã vượt quá thời gian hiện tại
$conn->query("UPDATE borrow_records SET status = 'expired' WHERE due_date < NOW() AND status = 'active'");

// =========================================================
?>