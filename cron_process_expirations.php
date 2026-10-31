<?php
// =========================================================================
// ANPLAY STORE - CRON ENGINE & BACKGROUND AUTOMATION WORKER
// Tự động quét thời hạn thuê, gửi cảnh báo đa mốc, thu hồi tài khoản hết hạn,
// đồng bộ đơn gia hạn và ghi nhận nhật ký hệ thống.
// =========================================================================

date_default_timezone_set('Asia/Ho_Chi_Minh');
$cron_start_time = microtime(true);
$log_timestamp = date('Y-m-d H:i:s');

// 1. CẤU HÌNH TOKEN BẢO MẬT
if (!defined('ANPLAY_CRON_SECRET')) {
    $env_secret = getenv('ANPLAY_CRON_SECRET');
    define('ANPLAY_CRON_SECRET', !empty($env_secret) ? $env_secret : 'ANPLAY_CRON_SEC_9b8e21a7df4e083c6f41');
}
if (!defined('ANPLAY_LEGACY_CRON_SECRET')) {
    define('ANPLAY_LEGACY_CRON_SECRET', 'ANPLAY_CRON_2026');
}

// 2. XÁC THỰC QUYỀN TRUY CẬP (CLI, SECRET TOKEN HOẶC ADMIN SESSION)
$is_cli = (php_sapi_name() === 'cli');
$is_admin = false;
$triggered_by = $is_cli ? 'cli' : 'web_cron';

if (!$is_cli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $provided_token = $_GET['secret_token'] ?? $_GET['token'] ?? '';
    if (empty($provided_token) && isset($_SERVER['HTTP_X_CRON_KEY'])) {
        $provided_token = trim($_SERVER['HTTP_X_CRON_KEY']);
    }
    if (empty($provided_token) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $provided_token = $matches[1];
        }
    }

    $token_valid = (!empty($provided_token) && (
        hash_equals(ANPLAY_CRON_SECRET, $provided_token) ||
        hash_equals(ANPLAY_LEGACY_CRON_SECRET, $provided_token)
    ));

    if (isset($_SESSION['role']) && in_array(intval($_SESSION['role']), [1, 2])) {
        $is_admin = true;
        $admin_user = $_SESSION['username'] ?? 'admin';
        $triggered_by = "admin:$admin_user";
    }

    if (!$token_valid && !$is_admin) {
        $is_json = (isset($_GET['format']) && $_GET['format'] === 'json') ||
                    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        http_response_code(403);
        if ($is_json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'status' => 'forbidden',
                'error' => 'Truy cập từ chối! Yêu cầu Secret Token hợp lệ hoặc phiên Quản trị viên.'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            echo "<!DOCTYPE html><html lang='vi'><head><meta charset='UTF-8'><title>403 Forbidden - ANPLAY Cron</title>";
            echo "<style>body{background:#0a0c14;color:#f87171;font-family:sans-serif;padding:50px;text-align:center;}";
            echo ".box{display:inline-block;padding:30px;background:#161926;border:1px solid #ef4444;border-radius:12px;}";
            echo "a{color:#818cf8;text-decoration:none;font-weight:bold;}</style></head><body>";
            echo "<div class='box'><h2>⛔ 403 - TRUY CẬP TỪ CHỐI</h2>";
            echo "<p>Chỉ cho phép chạy bằng CLI hoặc Secret Token xác thực Quản trị viên ANPLAY STORE.</p>";
            echo "<p><a href='admin_dashboard.php'>Quay lại Bảng Quản Trị</a></p></div></body></html>";
        }
        exit();
    }
}

// 3. KHÓA CHỐNG CHẠY ĐỒNG THỜI (CONCURRENCY LOCKING)
$lock_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anplay_cron_execution.lock';
$lock_handle = @fopen($lock_file, 'c+');

if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
    $busy_msg = "Một tiến trình quét Cron khác đang chạy. Vui lòng thử lại sau 30 giây.";
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'status' => 'busy',
            'message' => $busy_msg
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(429);
        echo "[$log_timestamp] [CONCURRENCY_LOCKED] $busy_msg\n";
    }
    if ($lock_handle) { fclose($lock_handle); }
    exit();
}

// 4. KẾT NỐI DATABASE VÀ KHỞI TẠO DỊCH VỤ
// Tuyệt đối không gán đè $_SERVER['HTTP_HOST'] = 'localhost' để kết nối đúng MySQL hosting
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/account_service.php';
require_once __DIR__ . '/includes/notification_service.php';

if (!isset($conn) || !$conn || $conn->connect_error) {
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);
    http_response_code(500);
    die("[$log_timestamp] [DATABASE_ERROR] Không thể kết nối cơ sở dữ liệu: " . ($conn ? $conn->connect_error : 'Unknown'));
}

// Đảm bảo schema nhật ký và tracking
if (function_exists('ensure_cron_schema')) {
    ensure_cron_schema($conn);
}

// Khởi tạo các biến thống kê
$scanned_count = 0;
$expired_count = 0;
$extended_count = 0;
$warn_24h_count = 0;
$warn_1h_count = 0;
$warn_15m_count = 0;
$error_count = 0;
$execution_logs = [];

$execution_logs[] = "[$log_timestamp] Bắt đầu phiên quét Cron bởi: $triggered_by";

// =========================================================================
// PHẦN 1: QUÉT VÀ XỬ LÝ ĐƠN HẾT HẠN (TRƯỜNG HỢP 3 & 4)
// =========================================================================
$sql_expired = "SELECT br.borrow_id, br.user_id, br.game_id, br.account_id, br.due_date, br.order_code,
                       u.username, u.email, g.title
                FROM borrow_records br
                JOIN users u ON br.user_id = u.user_id
                JOIN games g ON br.game_id = g.game_id
                WHERE br.status = 'active' AND br.due_date <= NOW()
                ORDER BY br.due_date ASC";

$res_expired = $conn->query($sql_expired);

if ($res_expired && $res_expired->num_rows > 0) {
    while ($row = $res_expired->fetch_assoc()) {
        $scanned_count++;
        $borrow_id   = intval($row['borrow_id']);
        $account_id  = intval($row['account_id']);
        $user_id     = intval($row['user_id']);
        $game_id     = intval($row['game_id']);
        $game_title  = $row['title'];
        $username    = $row['username'];
        $due_date    = $row['due_date'];
        $order_code  = $row['order_code'] ?? ("ORD" . $borrow_id);
        $due_fmt     = date('H:i d/m/Y', strtotime($due_date));

        // TRƯỜNG HỢP 4: KIỂM TRA ĐƠN CÓ ĐƯỢC GIA HẠN HỢP LỆ KHÔNG
        // Kiểm tra xem có bản ghi gia hạn nào đã thanh toán liên kết với đơn này
        $chk_ext = $conn->prepare("SELECT borrow_id, due_date FROM borrow_records 
                                   WHERE previous_borrow_id = ? AND payment_status = 'paid' AND due_date > NOW() 
                                   ORDER BY due_date DESC LIMIT 1");
        $chk_ext->bind_param("i", $borrow_id);
        $chk_ext->execute();
        $ext_res = $chk_ext->get_result();
        $has_extension = ($ext_res && $ext_res->num_rows > 0);
        $ext_data = $has_extension ? $ext_res->fetch_assoc() : null;
        $chk_ext->close();

        if ($has_extension && !empty($ext_data['due_date'])) {
            // Đã gia hạn thành công: Đồng bộ hạn mới cho đơn gốc, không thu hồi tài khoản
            $new_due = $ext_data['due_date'];
            $conn->query("UPDATE borrow_records SET due_date = '$new_due', status = 'active' WHERE borrow_id = $borrow_id");
            if ($account_id > 0) {
                $conn->query("UPDATE game_accounts SET status = 'RENTED', rent_expires_at = '$new_due', updated_at = NOW() WHERE account_id = $account_id");
            }
            $extended_count++;
            $execution_logs[] = "  [EXTENDED] Đơn #$borrow_id ($game_title) đã được gia hạn đến " . date('H:i d/m/Y', strtotime($new_due));
            continue;
        }

        // TRƯỜNG HỢP 3: ĐƠN ĐÃ HẾT HẠN THỰC SỰ - TIẾN HÀNH THU HỒI
        $execution_logs[] = "  [EXPIRING] Phát hiện đơn #$borrow_id (User: @$username, Game: $game_title) hết hạn lúc $due_fmt";

        $conn->begin_transaction();
        try {
            // 1. Cập nhật đơn thuê sang 'expired' và 'revoked'
            $u_bor = $conn->prepare("UPDATE borrow_records SET status = 'expired', revoke_status = 'revoked', return_date = NOW() WHERE borrow_id = ?");
            if ($u_bor) {
                $u_bor->bind_param("i", $borrow_id);
                $u_bor->execute();
                $u_bor->close();
            }

            // 2. Thu hồi và giải phóng tài khoản game trong kho
            if ($account_id > 0) {
                $acc_stmt = $conn->prepare("SELECT * FROM game_accounts WHERE account_id = ? FOR UPDATE");
                if ($acc_stmt) {
                    $acc_stmt->bind_param("i", $account_id);
                    $acc_stmt->execute();
                    $acc_data = $acc_stmt->get_result()->fetch_assoc();
                    $acc_stmt->close();

                    if ($acc_data && intval($acc_data['current_borrow_id']) === $borrow_id) {
                        // Chuyển sang AVAILABLE sẵn sàng cho khách hàng tiếp theo thuê
                        $new_status = 'AVAILABLE';
                        $u_acc = $conn->prepare("UPDATE game_accounts SET status = ?, current_borrow_id = NULL, rent_expires_at = NULL, updated_at = NOW() WHERE account_id = ?");
                        if ($u_acc) {
                            $u_acc->bind_param("si", $new_status, $account_id);
                            $u_acc->execute();
                            $u_acc->close();
                        }

                        // Ghi log cấp phát / thu hồi
                        if (function_exists('log_account_allocation')) {
                            log_account_allocation($conn, $account_id, $borrow_id, $user_id, 'EXPIRED_REVOKED', "Đã tự động thu hồi quyền xem thông tin tài khoản trên web sau khi hết hạn.", 'CRON_WORKER');
                        }
                    }

                    // Đồng bộ lại tồn kho thực tế của game
                    if (function_exists('sync_game_inventory')) {
                        sync_game_inventory($conn, $game_id);
                    }
                }
            }

            // 3. Gửi thông báo hết hạn tới khách hàng (Deduplication 24h)
            if (function_exists('create_notification')) {
                create_notification(
                    $conn,
                    $user_id,
                    'rental',
                    'Thời gian thuê game đã kết thúc ⌛',
                    "Đơn thuê #$borrow_id ($game_title) đã hết hạn lúc $due_fmt. Bạn có thể thuê lại hoặc chọn game mới bất cứ lúc nào!",
                    'my_library.php',
                    'EXP-DONE-' . $borrow_id,
                    1440
                );
            }

            // 4. Ghi nhận tác vụ hoàn tất vào system_tasks_queue
            $task_payload = json_encode([
                'borrow_id'   => $borrow_id,
                'order_code'  => $order_code,
                'user_id'     => $user_id,
                'game_title'  => $game_title,
                'expired_at'  => $due_date,
                'worker'      => 'CRON_AUTOMATION'
            ], JSON_UNESCAPED_UNICODE);

            $t_stmt = $conn->prepare("INSERT INTO system_tasks_queue (task_type, target_id, payload, status, created_at) VALUES ('EXPIRE_ACCOUNT', ?, ?, 'completed', NOW())");
            if ($t_stmt) {
                $t_stmt->bind_param("is", $borrow_id, $task_payload);
                $t_stmt->execute();
                $t_stmt->close();
            }

            $conn->commit();
            $expired_count++;
            $execution_logs[] = "     -> [OK] Đã thu hồi quyền xem tài khoản đơn #$borrow_id và hoàn trả kho game.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error_count++;
            $err_msg = $e->getMessage();
            $execution_logs[] = "     -> [FAIL] Lỗi thu hồi đơn #$borrow_id: $err_msg";

            // TRƯỜNG HỢP 5: XỬ LÝ LỖI / DỮ LIỆU BẤT THƯỜNG
            $safe_err = $conn->real_escape_string($err_msg);
            $conn->query("UPDATE borrow_records SET revoke_status = 'failed', error_message = '$safe_err' WHERE borrow_id = $borrow_id");
        }
    }
}

// =========================================================================
// PHẦN 2: BỘ QUÉT CẢNH BÁO ĐA MỐC THỜI HẠN (24H - 1H - 15 PHÚT)
// =========================================================================
$sql_expiring = "SELECT br.borrow_id, br.user_id, br.game_id, br.due_date, br.order_code,
                        COALESCE(br.warn_24h_sent, 0) as warn_24h_sent,
                        COALESCE(br.warn_1h_sent, 0) as warn_1h_sent,
                        COALESCE(br.warn_15m_sent, 0) as warn_15m_sent,
                        u.username, g.title
                 FROM borrow_records br
                 JOIN users u ON br.user_id = u.user_id
                 JOIN games g ON br.game_id = g.game_id
                 WHERE br.status = 'active' 
                   AND br.due_date > NOW() 
                   AND br.due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
                 ORDER BY br.due_date ASC";

$res_expiring = $conn->query($sql_expiring);

if ($res_expiring && $res_expiring->num_rows > 0) {
    $now_epoch = time();

    while ($exp_row = $res_expiring->fetch_assoc()) {
        $scanned_count++;
        $b_id       = intval($exp_row['borrow_id']);
        $u_id       = intval($exp_row['user_id']);
        $g_title    = $exp_row['title'];
        $due_epoch  = strtotime($exp_row['due_date']);
        $due_fmt    = date('H:i d/m/Y', $due_epoch);
        $diff_sec   = $due_epoch - $now_epoch;

        $sent_24h = intval($exp_row['warn_24h_sent']);
        $sent_1h  = intval($exp_row['warn_1h_sent']);
        $sent_15m = intval($exp_row['warn_15m_sent']);

        // MỐC 1: SẮP HẾT HẠN TRONG 15 PHÚT (diff <= 900s)
        if ($diff_sec <= 900 && $sent_15m === 0) {
            $mins_left = max(1, ceil($diff_sec / 60));
            $notif_title = "Chỉ còn {$mins_left} phút thuê game cuối cùng! 🚨";
            $notif_msg = "Đơn thuê #$b_id ($g_title) sẽ hết hạn lúc $due_fmt (khoảng $mins_left phút nữa). Hãy gia hạn ngay để không bị ngắt quãng tiến trình chơi!";
            $extend_link = "checkout_qr.php?borrow_id=$b_id&action=extend";

            if (create_notification($conn, $u_id, 'rental', $notif_title, $notif_msg, $extend_link, "EXP-WARN-15M-$b_id", 1440)) {
                $conn->query("UPDATE borrow_records SET warn_15m_sent = 1 WHERE borrow_id = $b_id");
                $warn_15m_count++;
                $execution_logs[] = "  [WARN-15M] Đã gửi cảnh báo khẩn cấp 15 phút tới user #$u_id (Đơn #$b_id, còn {$mins_left}m)";
            }
        }
        // MỐC 2: SẮP HẾT HẠN TRONG 1 GIỜ (diff <= 3600s VÀ > 900s)
        elseif ($diff_sec <= 3600 && $diff_sec > 900 && $sent_1h === 0) {
            $mins_left = max(1, round($diff_sec / 60));
            $notif_title = "Khẩn cấp: Gói thuê còn 1 giờ nữa sẽ hết hạn ⏳";
            $notif_msg = "Đơn thuê #$b_id ($g_title) sẽ kết thúc sau $mins_left phút (lúc $due_fmt). Sau mốc này, thông tin tài khoản sẽ tự động khóa.";
            $extend_link = "checkout_qr.php?borrow_id=$b_id&action=extend";

            if (create_notification($conn, $u_id, 'rental', $notif_title, $notif_msg, $extend_link, "EXP-WARN-1H-$b_id", 1440)) {
                $conn->query("UPDATE borrow_records SET warn_1h_sent = 1 WHERE borrow_id = $b_id");
                $warn_1h_count++;
                $execution_logs[] = "  [WARN-1H] Đã gửi cảnh báo 1 giờ tới user #$u_id (Đơn #$b_id, còn {$mins_left}m)";
            }
        }
        // MỐC 3: SẮP HẾT HẠN TRONG 24 GIỜ (diff <= 86400s VÀ > 3600s)
        elseif ($diff_sec <= 86400 && $diff_sec > 3600 && $sent_24h === 0) {
            $hours_left = max(1, round($diff_sec / 3600));
            $notif_title = "Gói thuê game sắp hết hạn trong 24 giờ ⚠️";
            $notif_msg = "Đơn thuê #$b_id ($g_title) sẽ hết hạn lúc $due_fmt (còn khoảng $hours_left giờ). Bạn có thể gia hạn sớm để duy trì tài khoản liên tục.";
            $extend_link = "checkout_qr.php?borrow_id=$b_id&action=extend";

            if (create_notification($conn, $u_id, 'rental', $notif_title, $notif_msg, $extend_link, "EXP-WARN-24H-$b_id", 1440)) {
                $conn->query("UPDATE borrow_records SET warn_24h_sent = 1 WHERE borrow_id = $b_id");
                $warn_24h_count++;
                $execution_logs[] = "  [WARN-24H] Đã gửi nhắc nhở 24h tới user #$u_id (Đơn #$b_id, còn {$hours_left}h)";
            }
        }
    }
}

// =========================================================================
// PHẦN 3: GHI NHẬT KÝ VÀO BẢNG CRON_EXECUTION_LOGS
// =========================================================================
$cron_end_time = microtime(true);
$duration_ms = round(($cron_end_time - $cron_start_time) * 1000);
$total_warns = $warn_24h_count + $warn_1h_count + $warn_15m_count;

$final_status = 'success';
if ($error_count > 0) {
    $final_status = ($expired_count > 0 || $total_warns > 0) ? 'warning' : 'failed';
}

$details_data = [
    'warn_breakdown' => [
        '24h' => $warn_24h_count,
        '1h'  => $warn_1h_count,
        '15m' => $warn_15m_count
    ],
    'extended_count' => $extended_count,
    'error_count'    => $error_count,
    'logs'           => array_slice($execution_logs, 0, 50)
];
$details_json = json_encode($details_data, JSON_UNESCAPED_UNICODE);

$ins_log = $conn->prepare("INSERT INTO cron_execution_logs 
    (start_time, end_time, duration_ms, scanned_count, expired_count, warned_count, extended_count, status, error_message, triggered_by, details_json) 
    VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)");

if ($ins_log) {
    $err_summary = ($error_count > 0) ? "$error_count bản ghi gặp lỗi trong quá trình xử lý" : null;
    $ins_log->bind_param("siiiiissss", 
        $log_timestamp, 
        $duration_ms, 
        $scanned_count, 
        $expired_count, 
        $total_warns, 
        $extended_count, 
        $final_status, 
        $err_summary, 
        $triggered_by, 
        $details_json
    );
    $ins_log->execute();
    $ins_log->close();
}

// GIẢI PHÓNG KHÓA TIẾN TRÌNH
flock($lock_handle, LOCK_UN);
fclose($lock_handle);

// =========================================================================
// PHẦN 4: ĐỊNH DẠNG ĐẦU RA (CLI / JSON / HTML)
// =========================================================================
$wants_json = (isset($_GET['format']) && $_GET['format'] === 'json') ||
              (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($is_cli) {
    echo "[$log_timestamp] Hoàn thành trong {$duration_ms}ms | Quét: $scanned_count | Thu hồi: $expired_count | Cảnh báo: $total_warns (24h: $warn_24h_count, 1h: $warn_1h_count, 15m: $warn_15m_count) | Gia hạn: $extended_count | Lỗi: $error_count\n";
    if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
        exit(0);
    }
    return;
}

if ($wants_json) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'        => ($final_status !== 'failed'),
        'status'         => $final_status,
        'timestamp'      => $log_timestamp,
        'duration_ms'    => $duration_ms,
        'triggered_by'   => $triggered_by,
        'summary'        => [
            'scanned_total'    => $scanned_count,
            'expired_revoked'  => $expired_count,
            'extensions_kept'  => $extended_count,
            'warnings_sent'    => [
                'total' => $total_warns,
                '24h'   => $warn_24h_count,
                '1h'    => $warn_1h_count,
                '15m'   => $warn_15m_count
            ],
            'errors'           => $error_count
        ],
        'execution_logs' => $execution_logs
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}

// GIAO DIỆN HTML CONSOLE HIỆN ĐẠI CHO QUẢN TRỊ VIÊN HOẶC TRUY CẬP TRỰC TIẾP
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANPLAY Cron Engine Console</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-body: #0b0d14;
            --bg-card: #121522;
            --border-color: rgba(255, 255, 255, 0.08);
            --primary: #6366f1;
            --accent-cyan: #06b6d4;
            --accent-green: #10b981;
            --accent-orange: #f59e0b;
        }
        body {
            background-color: var(--bg-body);
            color: #e2e8f0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
        }
        .console-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            max-width: 900px;
            width: 100%;
            overflow: hidden;
        }
        .kpi-box {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
        }
        .terminal-box {
            background: #07090e;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            padding: 16px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            color: #94a3b8;
            max-height: 280px;
            overflow-y: auto;
        }
        .terminal-box .log-line {
            line-height: 1.6;
            white-space: pre-wrap;
        }
        .pulse-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 10px #10b981;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(0.9); opacity: 0.7; }
            50% { transform: scale(1.2); opacity: 1; }
            100% { transform: scale(0.9); opacity: 0.7; }
        }
    </style>
</head>
<body>
    <div class="console-card p-4 p-md-5">
        <!-- Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge" style="background: rgba(99,102,241,0.2); color: #818cf8; border: 1px solid rgba(99,102,241,0.4);">
                        <i class="bi bi-robot me-1"></i> ANPLAY CRON AUTOMATION
                    </span>
                    <span class="pulse-badge" style="background: rgba(16,185,129,0.15); color: #10b981;">
                        <span class="pulse-dot"></span> HOÀN TẤT THÀNH CÔNG
                    </span>
                </div>
                <h3 class="fw-bold text-white mb-0">Hệ Thống Quét & Xử Lý Đơn Thuê Game Tự Động</h3>
                <small class="text-secondary">Thời điểm kích hoạt: <?php echo $log_timestamp; ?> | Thực thi: <?php echo $duration_ms; ?>ms | Nguồn: <?php echo htmlspecialchars($triggered_by); ?></small>
            </div>
            <div class="d-flex gap-2">
                <a href="cron_process_expirations.php?secret_token=<?php echo ANPLAY_CRON_SECRET; ?>" class="btn btn-sm btn-outline-info d-flex align-items-center">
                    <i class="bi bi-arrow-repeat me-1"></i> Quét Lại Ngay
                </a>
                <a href="admin_dashboard.php" class="btn btn-sm btn-primary d-flex align-items-center" style="background: var(--primary); border: none;">
                    <i class="bi bi-speedometer2 me-1"></i> Về Bảng Quản Trị
                </a>
            </div>
        </div>

        <!-- 4 KPI Box -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="kpi-box text-center">
                    <span class="text-secondary small text-uppercase fw-bold">Tổng Đơn Quét</span>
                    <h3 class="fw-bold text-white my-1"><?php echo $scanned_count; ?></h3>
                    <small class="text-info"><i class="bi bi-search me-1"></i>Đang theo dõi</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-box text-center">
                    <span class="text-secondary small text-uppercase fw-bold">Đã Thu Hồi Hết Hạn</span>
                    <h3 class="fw-bold text-danger my-1"><?php echo $expired_count; ?></h3>
                    <small class="text-danger"><i class="bi bi-lock-fill me-1"></i>Khóa tài khoản</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-box text-center">
                    <span class="text-secondary small text-uppercase fw-bold">Cảnh Báo Đã Gửi</span>
                    <h3 class="fw-bold text-warning my-1"><?php echo $total_warns; ?></h3>
                    <small class="text-warning">24h: <?php echo $warn_24h_count; ?> | 1h: <?php echo $warn_1h_count; ?> | 15m: <?php echo $warn_15m_count; ?></small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-box text-center">
                    <span class="text-secondary small text-uppercase fw-bold">Đơn Đã Gia Hạn</span>
                    <h3 class="fw-bold text-success my-1"><?php echo $extended_count; ?></h3>
                    <small class="text-success"><i class="bi bi-check-all me-1"></i>Duy trì liên tục</small>
                </div>
            </div>
        </div>

        <!-- Live Terminal Log -->
        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-bold text-uppercase"><i class="bi bi-terminal me-1"></i> Nhật Ký Xử Lý Chi Tiết</span>
                <span class="badge bg-secondary bg-opacity-25 text-secondary font-monospace"><?php echo count($execution_logs); ?> dòng log</span>
            </div>
            <div class="terminal-box">
                <?php foreach ($execution_logs as $line): ?>
                    <div class="log-line text-light"><?php echo htmlspecialchars($line); ?></div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- External Cron Guide Hint -->
        <div class="p-3 rounded-3" style="background: rgba(99,102,241,0.08); border: 1px dashed rgba(99,102,241,0.3);">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-info-circle-fill text-info"></i>
                <span class="fw-bold text-info small">HƯỚNG DẪN CẤU HÌNH TỰ ĐỘNG CHẠY 24/7 TRÊN HOSTING:</span>
            </div>
            <p class="small text-secondary mb-0">
                Để hệ thống tự động quét mỗi 1 – 5 phút mà không cần mở trang này, hãy thêm URL sau vào dịch vụ Web Cron miễn phí như <strong>cron-job.org</strong> hoặc <strong>UptimeRobot</strong>:<br>
                <code class="text-warning user-select-all d-inline-block mt-1 p-1 rounded" style="background: #07090e;">
                    https://anplaystore.free.nf/cron_process_expirations.php?secret_token=<?php echo ANPLAY_CRON_SECRET; ?>&format=json
                </code>
            </p>
        </div>
    </div>
</body>
</html>
