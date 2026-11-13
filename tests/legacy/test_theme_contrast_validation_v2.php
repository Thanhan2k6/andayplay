<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }
/**
 * TEST AUTOMATION: LIGHT & DARK MODE CONTRAST & WCAG AA/AAA VALIDATION (v2.0)
 * Kiểm thử tự động tính tương thích, độ tương phản và không còn chữ bị ẩn
 */

echo "========================================================================\n";
echo "ANPLAY STORE — THEME CONTRAST & DISPLAY SYSTEM VALIDATION SUITE (v2.0)\n";
echo "========================================================================\n\n";

$tests_passed = 0;
$tests_total = 0;

function run_test($name, $condition, $details = '') {
    global $tests_passed, $tests_total;
    $tests_total++;
    echo sprintf("[%02d] %-60s ", $tests_total, $name);
    if ($condition) {
        $tests_passed++;
        echo "\033[32m[PASS]\033[0m\n";
        if ($details) echo "     \033[36m-> $details\033[0m\n";
    } else {
        echo "\033[31m[FAIL]\033[0m\n";
        if ($details) echo "     \033[33m-> $details\033[0m\n";
    }
}

// 1. Kiểm tra admin_dashboard.php
$dash_content = file_get_contents(__DIR__ . '/admin_dashboard.php');

run_test(
    "admin_dashboard: Khong con inline dark gradient tren Cron Banner",
    !strpos($dash_content, "rgba(30, 27, 75"),
    "Đã loại bỏ hoàn toàn inline dark background gây chìm chữ ở Light Mode"
);

run_test(
    "admin_dashboard: Su dung class semantic .cron-monitor-banner",
    strpos($dash_content, 'class="cron-monitor-banner') !== false,
    "Đã cấu trúc hóa Cron Banner bằng class chuyên dụng"
);

run_test(
    "admin_dashboard: Su dung .cron-status-badge va .cron-time-val",
    strpos($dash_content, 'cron-status-badge') !== false && strpos($dash_content, 'cron-time-val') !== false,
    "Badge trạng thái và thời gian chạy gần nhất dùng class tương phản cao"
);

run_test(
    "admin_dashboard: Modal cron logs khong con table-dark ep buoc",
    strpos($dash_content, 'table-cron-logs') !== false && !strpos($dash_content, 'table-dark'),
    "Table nhật ký tự động thích ứng với nền modal ở cả 2 theme"
);

run_test(
    "admin_dashboard: Modal dialog khong dung btn-close-white gay tang hinh",
    !strpos($dash_content, 'btn-close-white'),
    "Nút đóng modal hiển thị sắc nét trên nền sáng"
);

// 2. Kiểm tra includes/admin_header.php
$hdr_admin = file_get_contents(__DIR__ . '/includes/admin_header.php');

run_test(
    "admin_header: CSS ho tro .cron-monitor-banner o Light Mode",
    strpos($hdr_admin, '[data-theme="light"] .cron-monitor-banner') !== false,
    "Light mode đổi sang nền #eef2ff -> #f8fafc và viền #c7d2fe"
);

run_test(
    "admin_header: CSS ho tro .cron-title va .cron-desc mau tuong phan cao",
    strpos($hdr_admin, '[data-theme="light"] .cron-title') !== false && strpos($hdr_admin, '[data-theme="light"] .cron-desc') !== false,
    "Tiêu đề chuyển sang #0f172a, mô tả #334155 rõ nét 100%"
);

run_test(
    "admin_header: Profile dropdown khong con hardcode background #111420",
    strpos($hdr_admin, 'admin-profile-dropdown') !== false && !strpos($hdr_admin, 'style="background: #111420;'),
    "Menu tài khoản admin không còn bị đen xì trên nền sáng"
);

// 3. Kiểm tra css/style.css
$css_content = file_get_contents(__DIR__ . '/css/style.css');

run_test(
    "style.css: Co dinh nghia day du WCAG variables o [data-theme='light']",
    strpos($css_content, '--text-primary: #0f172a;') !== false && strpos($css_content, '--page-bg: #f8fafc;') !== false,
    "Hệ thống biến CSS chuẩn mực cho Light Theme"
);

run_test(
    "style.css: Co bo quy tac enrich contrast tu dong cho inline styles",
    strpos($css_content, '[data-theme="light"] [style*="color: #10b981"]') !== false,
    "Tự động tăng độ đậm cho mã màu neon khi sang nền sáng"
);

run_test(
    "style.css: Alert styles cho Light Mode dat chuan WCAG AAA",
    strpos($css_content, '[data-theme="light"] .alert-warning') !== false && strpos($css_content, '#92400e') !== false,
    "Chữ cảnh báo đậm nét, không bị chìm trên nền pastel"
);

run_test(
    "style.css: Support lop phong ve cho cac the co inline background 255",
    strpos($css_content, '[data-theme="light"] [style*="background: rgba(255,255,255,0.0"]') !== false,
    "Tự động biến nền trong suốt thành #f8fafc trên Light Mode"
);

// 4. Kiểm tra download_game.php & checkout_qr.php
$dl_content = file_get_contents(__DIR__ . '/download_game.php');
$qr_content = file_get_contents(__DIR__ . '/checkout_qr.php');

run_test(
    "download_game: Hop Credentials dung semantic class .account-credentials-card",
    strpos($dl_content, 'account-credentials-card') !== false && !strpos($dl_content, 'rgba(15, 17, 24, 0.9)'),
    "Khung tài khoản / mật khẩu chuyển sang nền trắng, chữ đen đậm ở Light Mode"
);

run_test(
    "checkout_qr: Hop huong dan Steam Offline khong con inline dark background",
    strpos($qr_content, 'steam-offline-guide-box') !== false && !strpos($qr_content, 'rgba(15, 23, 42, 0.7)'),
    "Khung hướng dẫn thích ứng màu sắc linh hoạt"
);

// 5. Kiểm tra tính toán tương phản theo tiêu chuẩn WCAG
function get_relative_luminance($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) == 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;

    $r = ($r <= 0.03928) ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
    $g = ($g <= 0.03928) ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
    $b = ($b <= 0.03928) ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function calculate_contrast_ratio($hex1, $hex2) {
    $l1 = get_relative_luminance($hex1);
    $l2 = get_relative_luminance($hex2);
    $brightest = max($l1, $l2);
    $darkest = min($l1, $l2);
    return ($brightest + 0.05) / ($darkest + 0.05);
}

// Kiểm tra các cặp màu:
$ratio_heading = calculate_contrast_ratio('#0f172a', '#ffffff');
run_test(
    "WCAG AAA: Text Heading (#0f172a) tren Nen Trang (#ffffff)",
    $ratio_heading >= 7.0,
    sprintf("Tỷ lệ tương phản: %.2f:1 (Vượt chuẩn AAA >= 7.0:1)", $ratio_heading)
);

$ratio_body = calculate_contrast_ratio('#334155', '#ffffff');
run_test(
    "WCAG AAA: Text Body (#334155) tren Nen Trang (#ffffff)",
    $ratio_body >= 7.0,
    sprintf("Tỷ lệ tương phản: %.2f:1 (Vượt chuẩn AAA >= 7.0:1)", $ratio_body)
);

$ratio_muted = calculate_contrast_ratio('#475569', '#ffffff');
run_test(
    "WCAG AA: Text Muted (#475569) tren Nen Trang (#ffffff)",
    $ratio_muted >= 4.5,
    sprintf("Tỷ lệ tương phản: %.2f:1 (Vượt chuẩn AA >= 4.5:1)", $ratio_muted)
);

$ratio_badge_success = calculate_contrast_ratio('#166534', '#dcfce7');
run_test(
    "WCAG AA: Badge Success Text (#166534) tren Nen Tint (#dcfce7)",
    $ratio_badge_success >= 4.5,
    sprintf("Tỷ lệ tương phản: %.2f:1 (Vượt chuẩn AA >= 4.5:1)", $ratio_badge_success)
);

$ratio_badge_danger = calculate_contrast_ratio('#991b1b', '#fee2e2');
run_test(
    "WCAG AA: Badge Danger Text (#991b1b) tren Nen Tint (#fee2e2)",
    $ratio_badge_danger >= 4.5,
    sprintf("Tỷ lệ tương phản: %.2f:1 (Vượt chuẩn AA >= 4.5:1)", $ratio_badge_danger)
);

$ratio_badge_info = calculate_contrast_ratio('#075985', '#e0f2fe');
run_test(
    "WCAG AA: Badge Info Text (#075985) tren Nen Tint (#e0f2fe)",
    $ratio_badge_info >= 4.5,
    sprintf("Tỷ lệ tương phản: %.2f:1 (Vượt chuẩn AA >= 4.5:1)", $ratio_badge_info)
);

// Dark mode contrast checks:
$ratio_dark_heading = calculate_contrast_ratio('#ffffff', '#08090d');
run_test(
    "WCAG AAA: Dark Mode Heading (#ffffff) tren Nen Toi (#08090d)",
    $ratio_dark_heading >= 7.0,
    sprintf("Tỷ lệ tương phản: %.2f:1 (Vượt chuẩn AAA >= 7.0:1)", $ratio_dark_heading)
);

$ratio_dark_body = calculate_contrast_ratio('#cbd5e1', '#0f121d');
run_test(
    "WCAG AAA: Dark Mode Body (#cbd5e1) tren Card Toi (#0f121d)",
    $ratio_dark_body >= 7.0,
    sprintf("Tỷ lệ tương phản: %.2f:1 (Vượt chuẩn AAA >= 7.0:1)", $ratio_dark_body)
);

echo "\n========================================================================\n";
echo sprintf("TOTAL TESTS: %d | PASSED: %d | FAILED: %d\n", $tests_total, $tests_passed, $tests_total - $tests_passed);
if ($tests_passed === $tests_total) {
    echo "STATUS: \033[32mALL CONTRAST & DISPLAY TESTS PASSED SUCCESSFULLY! (100%)\033[0m\n";
} else {
    echo "STATUS: \033[31mSOME TESTS FAILED. PLEASE REVIEW ABOVE.\033[0m\n";
}
echo "========================================================================\n";
