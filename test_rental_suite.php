<?php
// =========================================================================
// TEST SUITE TỰ ĐỘNG: KIỂM THỬ TOÀN DIỆN MÔ HÌNH THUÊ TÀI KHOẢN GAME
// =========================================================================

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/account_service.php';

echo "=========================================================\n";
echo "BẮT ĐẦU KIỂM THỬ HỆ THỐNG THUÊ TÀI KHOẢN GAME TRẢ PHÍ\n";
echo "=========================================================\n\n";

function assert_test($description, $condition) {
    if ($condition) {
        echo "  [PASS] $description\n";
    } else {
        echo "  [FAIL] $description\n";
    }
}

// 1. Kiểm tra mã hóa & giải mã mật khẩu
echo "1. KIỂM THỬ MÃ HÓA & GIẢI MÃ MẬT KHẨU TÀI KHOẢN GAME:\n";
$sample_pass = "P@ssw0rd_Secure_#2026!";
$encrypted = encrypt_game_password($sample_pass);
$decrypted = decrypt_game_password($encrypted);
assert_test("Mật khẩu mã hóa không được rỗng và khác mật khẩu gốc", !empty($encrypted) && $encrypted !== $sample_pass);
assert_test("Giải mã đúng 100% mật khẩu ban đầu", $decrypted === $sample_pass);
echo "\n";

// 2. Tìm hoặc tạo User test & Game test
echo "2. CHUẨN BỊ DỮ LIỆU KIỂM THỬ (User & Game & Account):\n";
$test_user = $conn->query("SELECT user_id FROM users LIMIT 1")->fetch_assoc();
if (!$test_user) {
    $conn->query("INSERT INTO users (username, email, password, role) VALUES ('test_player', 'test@anplay.vn', '" . password_hash('123456', PASSWORD_DEFAULT) . "', 0)");
    $user_id = $conn->insert_id;
} else {
    $user_id = intval($test_user['user_id']);
}
echo "  -> User test ID: $user_id\n";

$test_game = $conn->query("SELECT game_id FROM games WHERE price_type = 'paid' LIMIT 1")->fetch_assoc();
if (!$test_game) {
    $conn->query("INSERT INTO games (title, category_id, platform, description, price_type, total_quantity, available_quantity) VALUES ('Cyberpunk 2077 VIP', 1, 'PC', 'Game test thuê', 'paid', 0, 0)");
    $game_id = $conn->insert_id;
} else {
    $game_id = intval($test_game['game_id']);
}
echo "  -> Game test ID: $game_id\n";

// Thêm 1 tài khoản game mẫu vào kho
$test_account_user = "steam_test_" . time();
$test_account_pass = "GamePass@" . rand(1000, 9999);
$enc_acc_pass = encrypt_game_password($test_account_pass);

$ins_acc = $conn->prepare("INSERT INTO game_accounts (game_id, account_username, account_password_encrypted, status, created_at) VALUES (?, ?, ?, 'AVAILABLE', NOW())");
$ins_acc->bind_param("iss", $game_id, $test_account_user, $enc_acc_pass);
$ins_acc->execute();
$account_id = $conn->insert_id;
$ins_acc->close();
sync_game_inventory($conn, $game_id);
assert_test("Đã nhập tài khoản test #$account_id vào kho ở trạng thái AVAILABLE", $account_id > 0);
echo "\n";

// 3. Test Cấp phát tài khoản khi thuê mới
echo "3. KIỂM THỬ LUỒNG THUÊ MỚI & CẤP PHÁT TÀI KHOẢN (allocate_game_account):\n";
$order_code = "TEST" . time();
$duration_hours = 24; // Gói 1 ngày
$conn->query("INSERT INTO borrow_records (order_code, user_id, game_id, duration_hours, rental_price, payment_status, payment_method, rent_type, status) 
              VALUES ('$order_code', $user_id, $game_id, $duration_hours, 15000, 'pending', 'VietQR', 'new', 'pending')");
$borrow_id = $conn->insert_id;

$alloc_res = allocate_game_account($conn, $game_id, $user_id, $borrow_id, $duration_hours, $account_id);
assert_test("Cấp phát tài khoản thành công qua Transaction", $alloc_res['success'] === true);

// Kiểm tra trạng thái trong DB
$chk_acc = $conn->query("SELECT status, current_borrow_id FROM game_accounts WHERE account_id = $account_id")->fetch_assoc();
assert_test("Trạng thái tài khoản chuyển sang RENTED và gắn current_borrow_id = $borrow_id", $chk_acc['status'] === 'RENTED' && $chk_acc['current_borrow_id'] == $borrow_id);

$chk_bor = $conn->query("SELECT status, payment_status, account_id FROM borrow_records WHERE borrow_id = $borrow_id")->fetch_assoc();
assert_test("Đơn thuê chuyển sang active và payment_status = paid", $chk_bor['status'] === 'active' && $chk_bor['payment_status'] === 'paid');
echo "\n";

// 4. Test Gia hạn thời gian thuê
echo "4. KIỂM THỬ LUỒNG GIA HẠN THỜI HẠN:\n";
$old_due = $conn->query("SELECT due_date FROM borrow_records WHERE borrow_id = $borrow_id")->fetch_assoc()['due_date'];
$add_hours = 48; // Gia hạn thêm 2 ngày
$new_due = date('Y-m-d H:i:s', strtotime("+$add_hours hours", strtotime($old_due)));
$conn->query("UPDATE borrow_records SET due_date = '$new_due' WHERE borrow_id = $borrow_id");
$conn->query("UPDATE game_accounts SET rent_expires_at = '$new_due' WHERE account_id = $account_id");
log_account_allocation($conn, $account_id, $borrow_id, $user_id, 'EXTENDED', "Gia hạn thêm $add_hours giờ", 'TEST_SUITE');

$updated_due = $conn->query("SELECT due_date FROM borrow_records WHERE borrow_id = $borrow_id")->fetch_assoc()['due_date'];
assert_test("Thời gian hết hạn mới được cộng dồn chính xác", $updated_due === $new_due);
echo "\n";

// 5. Test Tự động thu hồi khi hết hạn (Mô phỏng thời gian quá hạn)
echo "5. KIỂM THỬ TỰ ĐỘNG THU HỒI KHI HẾT HẠN (cron_process_expirations):\n";
$expired_time = date('Y-m-d H:i:s', strtotime("-10 minutes"));
$conn->query("UPDATE borrow_records SET due_date = '$expired_time' WHERE borrow_id = $borrow_id");
$conn->query("UPDATE game_accounts SET rent_expires_at = '$expired_time' WHERE account_id = $account_id");

// Chạy cron worker
require __DIR__ . '/cron_process_expirations.php';

$after_cron_bor = $conn->query("SELECT status, revoke_status FROM borrow_records WHERE borrow_id = $borrow_id")->fetch_assoc();
assert_test("Đơn thuê chuyển sang trạng thái expired và revoke_status = revoked", $after_cron_bor['status'] === 'expired' && $after_cron_bor['revoke_status'] === 'revoked');

$after_cron_acc = $conn->query("SELECT status, current_borrow_id FROM game_accounts WHERE account_id = $account_id")->fetch_assoc();
assert_test("Tài khoản game được tự động giải phóng về AVAILABLE và gỡ borrow_id", $after_cron_acc['status'] === 'AVAILABLE' && empty($after_cron_acc['current_borrow_id']));
echo "\n";

// 6. Test Thuê lại tài khoản cũ (Re-rent)
echo "6. KIỂM THỬ THUÊ LẠI TÀI KHOẢN CŨ (Re-rent specific account):\n";
$re_rent_code = "RERENT" . time();
$conn->query("INSERT INTO borrow_records (order_code, user_id, game_id, account_id, duration_hours, rental_price, payment_status, payment_method, rent_type, previous_borrow_id, status) 
              VALUES ('$re_rent_code', $user_id, $game_id, $account_id, 24, 15000, 'pending', 'VietQR', 're-rent', $borrow_id, 'pending')");
$new_re_rent_id = $conn->insert_id;

$re_rent_res = allocate_game_account($conn, $game_id, $user_id, $new_re_rent_id, 24, $account_id);
assert_test("Cấp phát lại đúng tài khoản cũ thành công", $re_rent_res['success'] === true && $re_rent_res['account_id'] == $account_id);
echo "\n";

// Dọn dẹp bản ghi kiểm thử
$conn->query("DELETE FROM borrow_records WHERE borrow_id IN ($borrow_id, $new_re_rent_id)");
$conn->query("DELETE FROM game_accounts WHERE account_id = $account_id");
$conn->query("DELETE FROM account_allocation_logs WHERE account_id = $account_id");
sync_game_inventory($conn, $game_id);

echo "=========================================================\n";
echo "TOÀN BỘ KỊCH BẢN KIỂM THỬ ĐÃ THÀNH CÔNG VÀ AN TOÀN!\n";
echo "=========================================================\n";
?>

