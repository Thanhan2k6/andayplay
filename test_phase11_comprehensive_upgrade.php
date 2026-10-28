<?php
/**
 * ANPLAY STORE - PHASE 11 COMPREHENSIVE UPGRADE TEST SUITE
 * Kiểm thử tự động toàn diện 12 Phases theo chuẩn Senior Full-Stack
 */

require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/account_service.php';

$passCount = 0;
$failCount = 0;
$totalTests = 0;

function assertTest($name, $condition, $details = "") {
    global $passCount, $failCount, $totalTests;
    $totalTests++;
    if ($condition) {
        $passCount++;
        echo "[\033[32mPASS\033[0m] $name\n";
    } else {
        $failCount++;
        echo "[\033[31mFAIL\033[0m] $name" . ($details ? " -> $details" : "") . "\n";
    }
}

echo "============================================================\n";
echo "   ANPLAY STORE - BẮT ĐẦU CHẠY KIỂM THỬ TOÀN DIỆN (PHASE 11)\n";
echo "============================================================\n\n";

// TEST GROUP 1: DATABASE & SCHEMA INTEGRITY
echo "--- NHÓM 1: CƠ SỞ DỮ LIỆU & BẢNG SẢN PHẨM ---\n";
assertTest("Kết nối Database hoạt động bình thường", isset($conn) && $conn->ping());

$games_res = $conn->query("SELECT COUNT(*) as c FROM games");
$games_count = ($games_res) ? intval($games_res->fetch_assoc()['c']) : 0;
assertTest("Bảng games có dữ liệu hợp lệ (>= 10 games)", $games_count >= 10, "Tìm thấy: $games_count games");

$free_games = $conn->query("SELECT COUNT(*) as c FROM games WHERE price_type = 'free'")->fetch_assoc()['c'];
$paid_games = $conn->query("SELECT COUNT(*) as c FROM games WHERE price_type = 'paid'")->fetch_assoc()['c'];
assertTest("Phân loại game rõ ràng (Free & Paid/Rental)", $free_games > 0 && $paid_games > 0, "Free: $free_games, Paid: $paid_games");

$pkgs_count = $conn->query("SELECT COUNT(*) as c FROM rental_packages WHERE is_active = 1")->fetch_assoc()['c'];
assertTest("Bảng rental_packages có các gói thời hạn kích hoạt", $pkgs_count > 0, "Gói hoạt động: $pkgs_count");

// TEST GROUP 2: FIX MÀN HÌNH ĐEN SAU THANH TOÁN (PHASE 5)
echo "\n--- NHÓM 2: KIỂM TRA SỬA LỖI MÀN HÌNH ĐEN (CHECKOUT_QR.PHP) ---\n";
$checkout_code = file_get_contents(__DIR__ . '/checkout_qr.php');

assertTest("checkout_qr.php có ob_start() ngăn chặn lỗi headers already sent", strpos($checkout_code, 'ob_start();') !== false);
assertTest("checkout_qr.php có ob_end_flush() ở cuối trang", strpos($checkout_code, 'ob_end_flush();') !== false);

$pos_post_handler = strpos($checkout_code, "if (\$order && isset(\$_POST['confirm_payment_webhook']))");
$pos_header_include = strpos($checkout_code, "require_once __DIR__ . '/includes/header.php';");

assertTest(
    "Xử lý POST webhook được đặt TRƯỚC khi include header.php",
    ($pos_post_handler !== false && $pos_header_include !== false && $pos_post_handler < $pos_header_include),
    "Pos POST: $pos_post_handler vs Pos Header: $pos_header_include"
);

// TEST GROUP 3: BẢO MẬT TÀI KHOẢN & IDOR (PHASE 7)
echo "\n--- NHÓM 3: BẢO MẬT AES-256 VÀ CHỐNG IDOR ---\n";
$test_plain_pass = "GamerSecretPass@" . rand(1000, 9999);
$encrypted_pass = encrypt_game_password($test_plain_pass);
$decrypted_pass = decrypt_game_password($encrypted_pass);

assertTest("Mã hóa mật khẩu AES-256-CBC thành công", !empty($encrypted_pass) && $encrypted_pass !== $test_plain_pass);
assertTest("Giải mã mật khẩu trùng khớp 100% với mật khẩu gốc", $decrypted_pass === $test_plain_pass);

assertTest(
    "checkout_qr.php bảo vệ chống IDOR (chỉ chủ đơn hàng mới xem được pass)",
    strpos($checkout_code, "WHERE br.borrow_id = ? AND br.user_id = ?") !== false
);

// TEST GROUP 4: LIGHT / DARK THEME SYSTEM (PHASE 8)
echo "\n--- NHÓM 4: HỆ THỐNG LIGHT / DARK THEME TOGGLE ---\n";
$css_content = file_get_contents(__DIR__ . '/css/style.css');
assertTest("style.css đã tích hợp bộ biến Light Mode [data-theme=\"light\"]", strpos($css_content, '[data-theme="light"]') !== false);
assertTest("style.css Light Mode có override body bg", strpos($css_content, '[data-theme="light"] body') !== false);

$header_content = file_get_contents(__DIR__ . '/includes/header.php');
assertTest("includes/header.php có nút Toggle Theme (themeToggleBtn)", strpos($header_content, 'id="themeToggleBtn"') !== false);
assertTest("includes/header.php có Early Theme Detection script trong head", strpos($header_content, 'localStorage.getItem(\'anplay_theme\')') !== false);
assertTest("includes/header.php có hàm toggleThemeMode()", strpos($header_content, 'toggleThemeMode') !== false);

// TEST GROUP 5: LUỒNG NÚT CHƠI NGAY & BỘ CÀI (PHASE 6)
echo "\n--- NHÓM 5: LUỒNG NÚT 'CHƠI NGAY' & LAUNCHER (PHASE 6) ---\n";
$library_content = file_get_contents(__DIR__ . '/my_library.php');
assertTest("my_library.php có nút 'Chơi Ngay / Bộ Cài' nổi bật", strpos($library_content, 'Chơi Ngay / Bộ Cài') !== false);

$download_content = file_get_contents(__DIR__ . '/download_game.php');
assertTest("download_game.php hỗ trợ mở Steam Client trực tiếp (steam://)", strpos($download_content, 'steam://open/main') !== false);
assertTest("download_game.php có hướng dẫn Epic Games", strpos($download_content, 'Epic Games') !== false);

// TEST GROUP 6: RESPONSIVE MOBILE & CHATBOT (PHASE 10)
echo "\n--- NHÓM 6: MOBILE RESPONSIVE & CHATBOT SPACING ---\n";
$chatbot_css = file_get_contents(__DIR__ . '/css/chatbot.css');
assertTest(
    "Chatbot không che bottom navigation trên mobile (có tính offset nav height)",
    strpos($chatbot_css, 'calc(var(--bottom-nav-height, 64px) + 16px)') !== false
);

// TỔNG KẾT
echo "\n============================================================\n";
echo "                   KẾT QUẢ KIỂM THỬ TỔNG THỂ                 \n";
echo "============================================================\n";
echo "Tổng số bài kiểm tra : $totalTests\n";
echo "Thành công (\033[32mPASS\033[0m)    : $passCount\n";
echo "Thất bại (\033[31mFAIL\033[0m)      : $failCount\n";
$passRate = round(($passCount / $totalTests) * 100, 1);
echo "Tỷ lệ vượt qua       : $passRate%\n";

if ($failCount === 0) {
    echo "\n\033[32m>>> TẤT CẢ CÁC BÀI KIỂM THỬ ĐÃ VƯỢT QUA 100%! HỆ THỐNG ĐÃ SẴN SÀNG! <<<\033[0m\n";
    exit(0);
} else {
    echo "\n\033[31m>>> CÒN LỖI CẦN XỬ LÝ! VUI LÒNG KIỂM TRA LẠI CÁC MỤC BỊ FAIL! <<<\033[0m\n";
    exit(1);
}

