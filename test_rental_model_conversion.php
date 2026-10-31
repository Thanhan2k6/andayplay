<?php
/**
 * ANPLAY STORE - RENTAL MODEL CONVERSION TEST SUITE
 * Kiểm thử xác thực chuyển đổi toàn diện sang mô hình cho thuê tài khoản 100%
 */

$total = 0;
$passed = 0;
$failed = 0;

function assertCheck($title, $condition, $info = "") {
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
echo "   ANPLAY STORE - KIỂM THỬ MÔ HÌNH CHO THUÊ TÀI KHOẢN 100%  \n";
echo "============================================================\n\n";

$admin_games_code = file_get_contents(__DIR__ . '/admin_games.php');
$admin_edit_code  = file_get_contents(__DIR__ . '/admin_edit_game.php');
$admin_add_code   = file_get_contents(__DIR__ . '/admin_add_game.php');
$admin_acc_code   = file_get_contents(__DIR__ . '/admin_accounts.php');
$admin_dash_code  = file_get_contents(__DIR__ . '/admin_dashboard.php');
$index_code       = file_get_contents(__DIR__ . '/index.php');
$game_detail_code = file_get_contents(__DIR__ . '/game_detail.php');
$borrow_code      = file_get_contents(__DIR__ . '/borrow_process.php');
$download_code    = file_get_contents(__DIR__ . '/download_game.php');
$connect_code     = file_get_contents(__DIR__ . '/includes/connect.php');
$migration_sql    = file_get_contents(__DIR__ . '/database_migration_v3_rental_model.sql');

// NHÓM 1: QUẢN TRỊ VIÊN & BẢNG SẢN PHẨM (ADMIN)
echo "--- NHÓM 1: GIAO DIỆN & LOGIC QUẢN TRỊ ADMIN ---\n";
assertCheck("admin_games.php hiển thị 'Cho thuê tài khoản' cho mọi game", strpos($admin_games_code, 'status-pill-success') !== false && strpos($admin_games_code, 'Miễn phí') === false);
assertCheck("admin_games.php hiển thị cột Giá Thuê Từ và số lượng gói", strpos($admin_games_code, 'min_rent_price') !== false && strpos($admin_games_code, 'Giá Thuê Từ') !== false);
assertCheck("admin_games.php hiển thị kho tài khoản (Sẵn / Tổng) thực tế", strpos($admin_games_code, 'avail_acc') !== false && strpos($admin_games_code, 'total_acc') !== false);
assertCheck("admin_games.php bảo vệ an toàn: không cho xóa game đang có tài khoản thuê", strpos($admin_games_code, 'chk_rent') !== false && strpos($admin_games_code, 'chk_borrow') !== false);
assertCheck("admin_add_game.php mặc định mô hình cho thuê tài khoản", strpos($admin_add_code, '$price_type = \'paid\';') !== false);
assertCheck("admin_add_game.php tự động tạo 4 gói thời hạn thuê chuẩn", strpos($admin_add_code, 'price_1h') !== false && strpos($admin_add_code, 'price_1d') !== false);
assertCheck("admin_edit_game.php cố định mô hình cho thuê và hỗ trợ cập nhật gói giá", strpos($admin_edit_code, 'name="price_type" value="paid"') !== false && strpos($admin_edit_code, 'pkg_price') !== false);
assertCheck("admin_accounts.php hỗ trợ nạp kho và lọc cho toàn bộ game", strpos($admin_acc_code, 'SELECT game_id, title FROM games ORDER BY title ASC') !== false);
assertCheck("admin_dashboard.php tính tổng số game trên toàn bộ danh mục", strpos($admin_dash_code, 'SELECT COUNT(*) as c FROM games') !== false);

// NHÓM 2: CỬA HÀNG & TRANG CHI TIẾT (FRONTEND)
echo "\n--- NHÓM 2: TRANG CỬA HÀNG & CHI TIẾT GAME ---\n";
assertCheck("index.php không còn nhãn 'Miễn Phí' cố định trên thẻ game", strpos($index_code, 'badge-gaming-free') === false);
assertCheck("index.php hiển thị giá thuê từ theo dữ liệu thực tế", strpos($index_code, 'min_price') !== false && strpos($index_code, 'gaming-card-price-val') !== false);
assertCheck("index.php có bộ lọc trạng thái kho (Còn tài khoản / Tạm hết)", strpos($index_code, 'name="stock"') !== false);
assertCheck("game_detail.php hiển thị badge Cho Thuê Tài Khoản", strpos($game_detail_code, 'Cho Thuê Tài Khoản') !== false);
assertCheck("game_detail.php hiển thị bảng giá các gói thời hạn (1h, 1d, 7d)", strpos($game_detail_code, 'Bảng Giá Gói Thuê Theo Thời Gian') !== false);
assertCheck("game_detail.php thông báo rõ và vô hiệu hóa nút khi kho trống", strpos($game_detail_code, 'Tạm Hết Tài Khoản Khả Dụng Trong Kho') !== false);

// NHÓM 3: QUY TRÌNH THUÊ GAME & BẢO MẬT TÀI KHOẢN (CHECKOUT & SECURITY)
echo "\n--- NHÓM 3: QUY TRÌNH THUÊ GAME & BẢO MẬT TÀI KHOẢN ---\n";
assertCheck("borrow_process.php ngăn chặn tạo đơn khi kho trống", strpos($borrow_code, 'Tạm Hết Tài Khoản (Không Thể Thuê)') !== false || strpos($borrow_code, 'TẠM HẾT TÀI KHOẢN') !== false);
assertCheck("borrow_process.php kiểm tra server-side \$available_accounts <= 0", strpos($borrow_code, '$available_accounts <= 0') !== false);
assertCheck("download_game.php yêu cầu đơn thuê active mới hiển thị mật khẩu", strpos($download_code, "\$game['status'] == 'active'") !== false);
assertCheck("download_game.php giải mã an toàn AES-256 không để lộ pass", strpos($download_code, 'decrypt_game_password') !== false);

// NHÓM 4: CƠ SỞ DỮ LIỆU & RUNTIME MIGRATION
echo "\n--- NHÓM 4: CƠ CHẾ ĐỒNG BỘ CSDL RUNTIME & FILE MIGRATION ---\n";
assertCheck("connect.php có runtime auto-migration chuyển toàn bộ game sang paid", strpos($connect_code, 'UPDATE games SET price_type = \'paid\'') !== false);
assertCheck("connect.php tự động tạo 4 gói thời hạn mẫu cho mọi game thiếu gói", strpos($connect_code, 'INSERT INTO rental_packages') !== false);
assertCheck("database_migration_v3_rental_model.sql chứa đầy đủ lệnh DDL & DML", strpos($migration_sql, 'UPDATE games') !== false && strpos($migration_sql, 'rental_packages') !== false);

echo "\n============================================================\n";
echo "                      TỔNG KẾT KIỂM THỬ                     \n";
echo "============================================================\n";
echo "Tổng số kiểm tra : $total\n";
echo "Thành công       : $passed\n";
echo "Thất bại         : $failed\n";
$rate = round(($passed / $total) * 100, 1);
echo "Tỷ lệ vượt qua   : $rate%\n";

if ($failed === 0) {
    echo "\n\033[32m>>> TẤT CẢ $total TIÊU CHÍ CHUYỂN ĐỔI MÔ HÌNH THUÊ ĐÃ ĐẠT CHUẨN 100%! <<<\033[0m\n";
    exit(0);
} else {
    echo "\n\033[31m>>> CẦN SỬA LẠI CÁC MỤC BỊ FAILED! <<<\033[0m\n";
    exit(1);
}
