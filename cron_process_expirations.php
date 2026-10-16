<?php
// =========================================================================
// CRON / BACKGROUND TASK WORKER: TỰ ĐỘNG THU HỒI TÀI KHOẢN HẾT HẠN
// Chạy độc lập qua CLI (Windows Task Scheduler hoặc Linux Cron)
// Lệnh chạy: C:\xampp\php\php.exe -f c:\xampp\htdocs\quan_ly_thu_vien_game\cron_process_expirations.php
// =========================================================================

// Cho phép chạy từ CLI hoặc qua Web gọi nội bộ
if (php_sapi_name() !== 'cli' && (!isset($_GET['secret_token']) || $_GET['secret_token'] !== 'ANPLAY_CRON_2026')) {
    // Nếu gọi từ web mà không có secret_token thì kiểm tra xem có phải Admin login không
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] != 1) {
        http_response_code(403);
        die("Truy cập từ chối! Chỉ chạy bằng CLI hoặc quyền Quản trị.");
    }
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/account_service.php';

$log_time = date('Y-m-d H:i:s');
echo "[$log_time] Bắt đầu tiến trình kiểm tra và xử lý đơn thuê hết hạn...\n";

// 1. Tìm các đơn thuê đang 'active' nhưng due_date đã < NOW()
$sql_expired = "SELECT br.borrow_id, br.user_id, br.game_id, br.account_id, br.due_date, u.username, u.email, g.title
                FROM borrow_records br
                JOIN users u ON br.user_id = u.user_id
                JOIN games g ON br.game_id = g.game_id
                WHERE br.status = 'active' AND br.due_date <= NOW()";

$res = $conn->query($sql_expired);
$count = 0;

if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $count++;
        $borrow_id = intval($row['borrow_id']);
        $account_id = intval($row['account_id']);
        $user_id = intval($row['user_id']);
        $game_id = intval($row['game_id']);
        $game_title = $row['title'];
        $username = $row['username'];

        echo "  -> Phát hiện đơn #$borrow_id (User: @$username, Game: $game_title) đã hết hạn lúc {$row['due_date']}\n";

        // Bắt đầu Transaction xử lý thu hồi an toàn cho đơn này
        $conn->begin_transaction();
        try {
            // 1. Cập nhật trạng thái đơn thuê sang 'expired'
            $u_bor = $conn->prepare("UPDATE borrow_records SET status = 'expired', revoke_status = 'revoked' WHERE borrow_id = ?");
            $u_bor->bind_param("i", $borrow_id);
            $u_bor->execute();
            $u_bor->close();

            // 2. Xử lý tài khoản game
            if ($account_id > 0) {
                // Kiểm tra xem nền tảng game có API đổi mật khẩu hay thu hồi từ xa không
                // GIỚI HẠN KỸ THUẬT: Với các game bản quyền thông thường (Steam, Epic, Riot, EA...) không có API đối tác mở cho đổi pass tự động,
                // hệ thống chuyển trạng thái sang EXPIRED và ghi nhận tác vụ cho Admin/Hệ thống thu hồi phiên.
                
                // Nếu tài khoản đang được gắn với chính đơn này
                $acc_stmt = $conn->prepare("SELECT * FROM game_accounts WHERE account_id = ? FOR UPDATE");
                $acc_stmt->bind_param("i", $account_id);
                $acc_stmt->execute();
                $acc_data = $acc_stmt->get_result()->fetch_assoc();
                $acc_stmt->close();

                if ($acc_data && $acc_data['current_borrow_id'] == $borrow_id) {
                    // Chuyển sang trạng thái EXPIRED để sẵn sàng tái sử dụng hoặc chờ bảo trì kiểm tra
                    // Mặc định: Chuyển sang AVAILABLE sau khi thu hồi phiên trên web, hoặc MAINTENANCE nếu cần đổi mật khẩu thủ công
                    $new_status = 'AVAILABLE'; // Hoặc 'EXPIRED' nếu quy định cần admin xác nhận
                    
                    $u_acc = $conn->prepare("UPDATE game_accounts SET status = ?, current_borrow_id = NULL, rent_expires_at = NULL, updated_at = NOW() WHERE account_id = ?");
                    $u_acc->bind_param("si", $new_status, $account_id);
                    $u_acc->execute();
                    $u_acc->close();

                    // Ghi log cấp phát/thu hồi
                    log_account_allocation($conn, $account_id, $borrow_id, $user_id, 'EXPIRED_REVOKED', "Đã tự động thu hồi quyền xem thông tin đăng nhập trên web sau khi hết hạn.", 'SYSTEM_WORKER');
                }

                // Đồng bộ lại số lượng tồn kho của game
                sync_game_inventory($conn, $game_id);
            }

            // Gửi thông báo hết hạn tới khách hàng
            if (function_exists('create_notification')) {
                create_notification($conn, $user_id, 'rental', 'Thời gian thuê game đã kết thúc ⌛', "Đơn thuê #$borrow_id ($game_title) đã hết hạn. Bạn có thể gia hạn hoặc thuê lại tài khoản bất kỳ lúc nào!", 'my_library.php', 'EXP-' . $borrow_id);
            }

            // 3. Thêm tác vụ vào hàng đợi hệ thống nếu cần thông báo hoặc xử lý API ngoài
            $task_payload = json_encode([
                'borrow_id' => $borrow_id,
                'user_id' => $user_id,
                'email' => $row['email'],
                'game_title' => $game_title,
                'expired_at' => $row['due_date']
            ]);
            $t_stmt = $conn->prepare("INSERT INTO system_tasks_queue (task_type, target_id, payload, status, created_at) VALUES ('EXPIRE_ACCOUNT', ?, ?, 'completed', NOW())");
            $t_stmt->bind_param("is", $borrow_id, $task_payload);
            $t_stmt->execute();
            $t_stmt->close();

            $conn->commit();
            echo "     [OK] Đã thu hồi quyền truy cập đơn #$borrow_id thành công.\n";
        } catch (Exception $e) {
            $conn->rollback();
            echo "     [FAIL] Lỗi khi xử lý đơn #$borrow_id: " . $e->getMessage() . "\n";
            // Ghi nhận lỗi vào đơn
            $conn->query("UPDATE borrow_records SET revoke_status = 'failed', error_message = '" . $conn->real_escape_string($e->getMessage()) . "' WHERE borrow_id = $borrow_id");
        }
    }
} else {
    echo "  -> Không có đơn thuê nào quá hạn cần xử lý.\n";
}

echo "[$log_time] Hoàn thành: Đã xử lý $count đơn thuê.\n";
?>

