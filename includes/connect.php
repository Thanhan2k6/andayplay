<?php
// =========================================================
// HỆ THỐNG KẾT NỐI DATABASE THÔNG MINH
// =========================================================

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// Kiểm tra xem code đang chạy trên máy tính (localhost / CLI) hay chạy trên Hosting thật
$http_host = $_SERVER['HTTP_HOST'] ?? '';
$is_prod_domain = (
    strpos($http_host, 'free.nf') !== false ||
    strpos($http_host, 'infinityfree') !== false ||
    strpos($http_host, 'anplaystore') !== false
);

if (defined('FORCE_PROD_DB') && FORCE_PROD_DB) {
    $is_local = false;
} elseif (defined('FORCE_LOCAL_DB') && FORCE_LOCAL_DB) {
    $is_local = true;
} elseif ($is_prod_domain) {
    // Luôn ưu tiên kết nối hosting nếu phát hiện domain hosting
    $is_local = false;
} else {
    $is_local = (
        php_sapi_name() === 'cli' ||
        empty($http_host) ||
        strpos($http_host, 'localhost') !== false ||
        strpos($http_host, '127.0.0.1') !== false
    );
}

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
require_once __DIR__ . '/auth_service.php';

/**
 * Tự động đồng bộ và khởi tạo bảng nhật ký Cron và các cột cảnh báo
 */
function ensure_cron_schema($conn) {
    static $cron_schema_checked = false;
    if ($cron_schema_checked || !$conn || !($conn instanceof mysqli)) return;
    $cron_schema_checked = true;

    try {
        // 1. Tạo bảng nhật ký chạy Cron
        $conn->query("CREATE TABLE IF NOT EXISTS `cron_execution_logs` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `start_time` DATETIME NOT NULL,
          `end_time` DATETIME DEFAULT NULL,
          `duration_ms` INT DEFAULT 0,
          `scanned_count` INT DEFAULT 0,
          `expired_count` INT DEFAULT 0,
          `warned_count` INT DEFAULT 0,
          `extended_count` INT DEFAULT 0,
          `status` ENUM('success', 'warning', 'failed') NOT NULL DEFAULT 'success',
          `error_message` TEXT DEFAULT NULL,
          `triggered_by` VARCHAR(50) NOT NULL DEFAULT 'web_cron',
          `details_json` TEXT DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_cron_status_time` (`status`, `start_time`),
          INDEX `idx_cron_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 2. Bổ sung các cột tracking cảnh báo mốc 24h, 1h, 15m vào borrow_records nếu chưa có
        $chk_warn = $conn->query("SHOW COLUMNS FROM `borrow_records` LIKE 'warn_24h_sent'");
        if ($chk_warn && $chk_warn->num_rows === 0) {
            $conn->query("ALTER TABLE `borrow_records` 
                ADD COLUMN `warn_24h_sent` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `warn_1h_sent` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `warn_15m_sent` TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) {}
}

if (isset($conn) && $conn instanceof mysqli) {
    ensure_cron_schema($conn);
}

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

            // Kiểm tra xem đơn này có bản ghi gia hạn đã thanh toán không
            $chk_ext = $conn->query("SELECT due_date FROM borrow_records WHERE previous_borrow_id = $b_id AND payment_status = 'paid' AND due_date > '$now_ts' ORDER BY due_date DESC LIMIT 1");
            if ($chk_ext && $chk_ext->num_rows > 0) {
                $ext_row = $chk_ext->fetch_assoc();
                $new_due = $ext_row['due_date'];
                $conn->query("UPDATE borrow_records SET due_date = '$new_due', status = 'active' WHERE borrow_id = $b_id");
                if ($a_id > 0) {
                    $conn->query("UPDATE game_accounts SET status = 'RENTED', rent_expires_at = '$new_due' WHERE account_id = $a_id");
                }
                continue;
            }

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

// =========================================================
// AUTO-MIGRATION: ĐỒNG BỘ TOÀN BỘ GAME SANG MÔ HÌNH CHO THUÊ
// =========================================================
try {
    if (isset($conn) && $conn instanceof mysqli) {
        $check_free_games = $conn->query("SELECT 1 FROM games WHERE price_type != 'paid' OR price_type IS NULL LIMIT 1");
        if ($check_free_games && $check_free_games->num_rows > 0) {
            $conn->query("UPDATE games SET price_type = 'paid' WHERE price_type != 'paid' OR price_type IS NULL");

            // Tạo các gói thuê mẫu cho toàn bộ game nếu chưa có
            $conn->query("INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
                SELECT g.game_id, '1 Giờ (Trải nghiệm nhanh)', 'hours', 1, 5000, 1
                FROM games g
                WHERE NOT EXISTS (SELECT 1 FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.duration_type = 'hours' AND rp.duration_value = 1)");

            $conn->query("INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
                SELECT g.game_id, '1 Ngày (24 Giờ)', 'days', 1, 15000, 1
                FROM games g
                WHERE NOT EXISTS (SELECT 1 FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.duration_type = 'days' AND rp.duration_value = 1)");

            $conn->query("INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
                SELECT g.game_id, '7 Ngày (1 Tuần)', 'days', 7, 30000, 1
                FROM games g
                WHERE NOT EXISTS (SELECT 1 FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.duration_type = 'days' AND rp.duration_value = 7)");

            $conn->query("INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
                SELECT g.game_id, '30 Ngày (1 Tháng)', 'days', 30, 99000, 1
                FROM games g
                WHERE NOT EXISTS (SELECT 1 FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.duration_type = 'days' AND rp.duration_value = 30)");

            // Đồng bộ số lượng tồn kho theo số tài khoản khả dụng thực tế
            $conn->query("UPDATE games g SET quantity = (SELECT COUNT(*) FROM game_accounts ga WHERE ga.game_id = g.game_id AND ga.status = 'AVAILABLE')");
        }
    }
} catch (Throwable $e) {}
?>