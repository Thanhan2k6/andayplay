<?php
/**
 * ANPLAY STORE - THEME & CONTRAST VALIDATION TEST SUITE
 * Kiểm tra xác thực hệ thống biến màu, độ tương phản và Light/Dark mode
 */

$total = 0;
$passed = 0;
$failed = 0;

function assertTheme($title, $condition, $info = "") {
    global $total, $passed, $failed;
    $total++;
    if ($condition) {
        $passed++;
        echo "[\033[32mPASS\033[0m] $title\n";
    } else {
        $failed++;
        echo "[\033[31mFAIL\033[0m] $title" . ($info ? " -> $info" : "") . "\n";
    }
}

echo "============================================================\n";
echo "   ANPLAY STORE - KIỂM THỬ XÁC THỰC LIGHT & DARK MODE      \n";
echo "============================================================\n\n";

$style_css = file_get_contents(__DIR__ . '/css/style.css');
$chatbot_css = file_get_contents(__DIR__ . '/css/chatbot.css');
$index_php = file_get_contents(__DIR__ . '/index.php');
$header_php = file_get_contents(__DIR__ . '/includes/header.php');
$admin_hdr = file_get_contents(__DIR__ . '/includes/admin_header.php');
$admin_ftr = file_get_contents(__DIR__ . '/includes/admin_footer.php');

// 1. Kiểm tra Design Tokens
echo "--- NHÓM 1: HỆ THỐNG BIẾN MÀU TẬP TRUNG (DESIGN TOKENS) ---\n";
assertTheme("Định nghĩa --text-heading trong :root", strpos($style_css, '--text-heading:') !== false);
assertTheme("Định nghĩa --text-heading trong [data-theme=\"light\"]", strpos($style_css, '--text-heading: #0f172a;') !== false);
assertTheme("Định nghĩa --page-bg trong [data-theme=\"light\"]", strpos($style_css, '--page-bg: #f8fafc;') !== false);
assertTheme("Định nghĩa --surface-bg trong [data-theme=\"light\"]", strpos($style_css, '--surface-bg: #ffffff;') !== false);
assertTheme("Định nghĩa --text-muted trong [data-theme=\"light\"] (#64748b - WCAG AA)", strpos($style_css, '--text-muted: #64748b;') !== false);

// 2. Kiểm tra tương phản tiêu đề & text-white override
echo "\n--- NHÓM 2: TỰ ĐỘNG ĐẢO MÀU TIÊU ĐỀ & TRÁNH CHỮ TRẮNG NỀN SÁNG ---\n";
assertTheme("Tất cả h1-h6 tự động nhận màu --text-heading trong Light Mode", strpos($style_css, '[data-theme="light"] h1') !== false && strpos($style_css, 'color: var(--text-heading) !important;') !== false);
assertTheme("Class .text-white tự động chuyển sang chữ đậm trong Light Mode", strpos($style_css, '[data-theme="light"] .text-white:not') !== false);
assertTheme("Ngoại lệ nút và badge màu giữ nguyên chữ trắng trong Light Mode", strpos($style_css, '[data-theme="light"] .btn-gaming-primary *') !== false && strpos($style_css, 'color: #ffffff !important;') !== false);

// 3. Kiểm tra Banner Hero & Thẻ thống kê
echo "\n--- NHÓM 3: BANNER HERO, THẺ THỐNG KÊ & DÒNG CAM KẾT ---\n";
assertTheme("index.php đã tích hợp class .hero-stats-box", strpos($index_php, 'hero-stats-box') !== false);
assertTheme("Thẻ '1 Đổi 1' không dùng text-white, dùng màu hổ phách tương phản", strpos($index_php, 'text-accent-amber') !== false && strpos($index_php, '<div class="fs-2 fw-black text-white">1 Đổi 1</div>') === false);
assertTheme("index.php đã tích hợp .hero-commitments-bar và .hero-commit-text", strpos($index_php, 'hero-commitments-bar') !== false && strpos($index_php, 'hero-commit-text') !== false);
assertTheme("style.css có rule định dạng .hero-stats-box và các thẻ stat riêng", strpos($style_css, '.hero-stats-box') !== false && strpos($style_css, '.hero-stat-card') !== false);

// 4. Kiểm tra Tiêu đề Catalog & Dropdowns
echo "\n--- NHÓM 4: TIÊU ĐỀ CATALOG & DROPDOWNS KHÔNG BỊ TRẮNG CHỮ ---\n";
assertTheme("Tiêu đề Catalog trong index.php không bị class text-white cố định", strpos($index_php, '<h3 class="fw-bold m-0 catalog-title') !== false);
assertTheme("Dropdown Notifications và User dùng biến CSS linh hoạt", strpos($header_php, 'background-color: var(--surface-bg);') !== false);

// 5. Kiểm tra Chatbot Light Mode
echo "\n--- NHÓM 5: KHUNG CHAT AI CHATBOT TRONG LIGHT MODE ---\n";
assertTheme("chatbot.css có quy tắc [data-theme=\"light\"] .anplay-chat-box", strpos($chatbot_css, '[data-theme="light"] .anplay-chat-box') !== false);
assertTheme("chatbot.css đổi màu tin nhắn bot sang nền trắng chữ đen", strpos($chatbot_css, '[data-theme="light"] .anplay-msg-bot .anplay-msg-content') !== false);

// 6. Kiểm tra Admin Theme Switcher
echo "\n--- NHÓM 6: GIAO DIỆN QUẢN TRỊ ADMIN CONSOLE ---\n";
assertTheme("admin_header.php có nút chuyển đổi theme adminThemeToggleBtn", strpos($admin_hdr, 'id="adminThemeToggleBtn"') !== false);
assertTheme("admin_footer.php có hàm toggleAdminThemeMode()", strpos($admin_ftr, 'toggleAdminThemeMode') !== false);
assertTheme("admin_header.php có Early Theme Detection tránh giật màn hình", strpos($admin_hdr, 'localStorage.getItem(\'anplay_theme\')') !== false);

// 7. Kiểm tra Trang Thuê Game & Checkout VietQR (borrow_process.php & checkout_qr.php)
echo "\n--- NHÓM 7: QUY TRÌNH THUÊ GAME & THANH TOÁN QR ---\n";
$borrow_php = file_get_contents(__DIR__ . '/borrow_process.php');
$checkout_php = file_get_contents(__DIR__ . '/checkout_qr.php');

assertTheme("borrow_process.php có step progress bar đồng bộ (.step-pill)", strpos($borrow_php, 'step-pill') !== false);
assertTheme("borrow_process.php không dùng inline style nền đen cứng trên pkg-card", strpos($borrow_php, 'style="background: #0f121a;"') === false);
assertTheme("checkout_qr.php đã chuyển sang dùng class ngữ nghĩa .step-pill", strpos($checkout_php, 'step-pill') !== false);
assertTheme("checkout_qr.php không có inline style nền đen cứng #0b0d13", strpos($checkout_php, 'style="background: #0b0d13;') === false);
assertTheme("style.css có rule [data-theme=\"light\"] cho .pkg-card và .borrow-summary-card", strpos($style_css, '[data-theme="light"] .pkg-card') !== false && strpos($style_css, '[data-theme="light"] .borrow-summary-card') !== false);

// 8. Kiểm tra Chống Cache Hosting (Cache Busting)
echo "\n--- NHÓM 8: CƠ CHẾ CHỐNG CACHE HOSTING INFINITYFREE ---\n";
assertTheme("header.php có cache busting query string trên style.css", strpos($header_php, 'style.css?v=') !== false);
assertTheme("admin_header.php có cache busting query string trên style.css", strpos($admin_hdr, 'style.css?v=') !== false);

// 9. Kiểm tra Reset Gradient Hero Banner Trong Light Mode
echo "\n--- NHÓM 9: RESET GRADIENT CHỮ HERO BANNER LIGHT MODE ---\n";
assertTheme("style.css reset -webkit-text-fill-color cho hero-title span trong Light Mode", strpos($style_css, '[data-theme="light"] .hero-title span') !== false && strpos($style_css, '-webkit-text-fill-color: #1e293b !important;') !== false);
assertTheme("style.css reset background: none cho hero-title span trong Light Mode", strpos($style_css, 'background: none !important;') !== false);

echo "\n============================================================\n";
echo "                      TỔNG KẾT KIỂM THỬ                     \n";
echo "============================================================\n";
echo "Tổng số kiểm tra : $total\n";
echo "Thành công       : $passed\n";
echo "Thất bại         : $failed\n";
$rate = round(($passed / $total) * 100, 1);
echo "Tỷ lệ vượt qua   : $rate%\n";

if ($failed === 0) {
    echo "\n\033[32m>>> TẤT CẢ $total TIÊU CHÍ LIGHT/DARK THEME ĐÃ ĐẠT CHUẨN 100%! <<<\033[0m\n";
    exit(0);
} else {
    echo "\n\033[31m>>> CẦN SỬA LẠI CÁC MỤC BỊ FAILED! <<<\033[0m\n";
    exit(1);
}

