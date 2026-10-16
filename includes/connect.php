<?php
// =========================================================
// HỆ THỐNG KẾT NỐI DATABASE THÔNG MINH
// =========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kiểm tra xem code đang chạy trên máy tính (localhost / CLI) hay chạy trên Hosting thật
$is_local = (
    php_sapi_name() === 'cli' ||
    !isset($_SERVER['HTTP_HOST']) ||
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false
);

if ($is_local) {
    $host = "localhost";
    $user = "root";
    $pass = "";
    $db = "game_library_db";
} else {
    // MÔI TRƯỜNG PROD: Hosting thật
    $host = "sql312.infinityfree.com";
    $user = "if0_42898364";
    $pass = "Thanhan2k6";
    $db   = "if0_42898364_gamelib";
}

// Vô hiệu hóa ném ngoại lệ tự động của MySQLi trên PHP 8.1+ để tránh ngắt hiển thị HTML
mysqli_report(MYSQLI_REPORT_OFF);

// Khởi tạo kết nối đến CSDL
$conn = @new mysqli($host, $user, $pass, $db);

// Kiểm tra kết nối
if ($conn->connect_error) {
    if (defined('ANPLAY_CHATBOT_NO_DIE')) {
        $conn = null;
    } else {
        die("Kết nối thất bại. Vui lòng kiểm tra lại thông số Database: " . $conn->connect_error);
    }
} else {
    // Thiết lập font chữ tiếng Việt
    $conn->set_charset("utf8mb4");
}

// Nạp thư viện dịch vụ tài khoản, thông báo và hỗ trợ
require_once __DIR__ . '/account_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/support_service.php';

// =========================================================
// AUTO-CHECK TRÊN WEB (DỰ PHÒNG KHI CHƯA CHẠY CRON)
// Cập nhật các đơn đã hết hạn và thu hồi tài khoản
// =========================================================
try {
    $now_ts = date('Y-m-d H:i:s');
    $expired_records = $conn->query("SELECT borrow_id, account_id, game_id FROM borrow_records WHERE due_date <= '$now_ts' AND status = 'active'");
    if ($expired_records && $expired_records->num_rows > 0) {
        while ($exp = $expired_records->fetch_assoc()) {
            $b_id = intval($exp['borrow_id']);
            $a_id = isset($exp['account_id']) ? intval($exp['account_id']) : 0;
            $g_id = intval($exp['game_id']);

            $conn->query("UPDATE borrow_records SET status = 'expired', revoke_status = 'revoked' WHERE borrow_id = $b_id");
            if ($a_id > 0) {
                $conn->query("UPDATE game_accounts SET status = 'AVAILABLE', current_borrow_id = NULL, rent_expires_at = NULL WHERE account_id = $a_id AND current_borrow_id = $b_id");
                if (function_exists('sync_game_inventory')) {
                    sync_game_inventory($conn, $g_id);
                }
            }
        }
    }
} catch (Throwable $e) {
    // Tránh gián đoạn hệ thống nếu CSDL chưa hoàn tất chạy migration
}
?>