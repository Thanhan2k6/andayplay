<?php
/**
 * ANPLAY STORE - SUPPORT SERVICE
 * Hệ thống xử lý Trung tâm hỗ trợ khách hàng, Ticket, Conversation & Đính kèm
 */

if (!defined('ANPLAY_SUPPORT_LOADED')) {
    define('ANPLAY_SUPPORT_LOADED', true);
}

require_once __DIR__ . '/notification_service.php';

/**
 * Danh sách danh mục hỗ trợ hợp lệ
 */
function get_support_categories() {
    return [
        'rental' => [
            'label' => 'Thuê game & Đơn thuê',
            'icon' => 'bi-controller',
            'desc' => 'Vấn đề liên quan đến thuê, gia hạn hoặc hủy đơn'
        ],
        'account' => [
            'label' => 'Tài khoản game',
            'icon' => 'bi-shield-lock',
            'desc' => 'Tài khoản không hoạt động, sai pass, bị văng hoặc khóa'
        ],
        'account_not_received' => [
            'label' => 'Chưa nhận được tài khoản',
            'icon' => 'bi-hourglass-split',
            'desc' => 'Đã thanh toán nhưng chưa thấy thông tin đăng nhập'
        ],
        'payment' => [
            'label' => 'Thanh toán & Nạp tiền',
            'icon' => 'bi-credit-card-2-front',
            'desc' => 'Lỗi quét mã QR, chuyển khoản chưa cộng hoặc trừ tiền'
        ],
        'login_issue' => [
            'label' => 'Lỗi đăng nhập website',
            'icon' => 'bi-person-x',
            'desc' => 'Quên mật khẩu, không đăng nhập được tài khoản ANPLAY'
        ],
        'website_bug' => [
            'label' => 'Báo lỗi website',
            'icon' => 'bi-bug',
            'desc' => 'Lỗi giao diện, tính năng không hoạt động đúng'
        ],
        'complaint' => [
            'label' => 'Khiếu nại dịch vụ',
            'icon' => 'bi-exclamation-triangle',
            'desc' => 'Khiếu nại về chất lượng dịch vụ hoặc hỗ trợ'
        ],
        'other' => [
            'label' => 'Yêu cầu khác / Góp ý',
            'icon' => 'bi-chat-dots',
            'desc' => 'Đề xuất game mới hoặc các thắc mắc khác'
        ]
    ];
}

/**
 * Danh sách trạng thái Ticket và nhãn hiển thị
 */
function get_ticket_status_info($status) {
    switch ($status) {
        case 'new':
            return ['label' => 'Mới gửi', 'badge_class' => 'bg-info bg-opacity-25 text-info border border-info border-opacity-50', 'icon' => 'bi-stars'];
        case 'assigned':
            return ['label' => 'Đã tiếp nhận', 'badge_class' => 'bg-primary bg-opacity-25 text-primary border border-primary border-opacity-50', 'icon' => 'bi-person-check'];
        case 'in_progress':
            return ['label' => 'Đang xử lý', 'badge_class' => 'bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50', 'icon' => 'bi-arrow-repeat'];
        case 'waiting_customer':
            return ['label' => 'Chờ khách phản hồi', 'badge_class' => 'bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50', 'icon' => 'bi-clock-history'];
        case 'resolved':
            return ['label' => 'Đã giải quyết', 'badge_class' => 'bg-success bg-opacity-25 text-success border border-success border-opacity-50', 'icon' => 'bi-check-circle-fill'];
        case 'closed':
            return ['label' => 'Đã đóng', 'badge_class' => 'bg-dark text-secondary border border-secondary border-opacity-25', 'icon' => 'bi-x-circle'];
        default:
            return ['label' => 'Chưa xác định', 'badge_class' => 'bg-secondary text-white', 'icon' => 'bi-question-circle'];
    }
}

/**
 * Danh sách mức độ ưu tiên
 */
function get_ticket_priority_info($priority) {
    switch ($priority) {
        case 'urgent':
            return ['label' => 'Khẩn cấp', 'badge_class' => 'bg-danger text-white border border-danger', 'color' => '#ef4444'];
        case 'high':
            return ['label' => 'Ưu tiên cao', 'badge_class' => 'bg-danger bg-opacity-25 text-danger border border-danger border-opacity-50', 'color' => '#f43f5e'];
        case 'normal':
            return ['label' => 'Bình thường', 'badge_class' => 'bg-primary bg-opacity-25 text-primary border border-primary border-opacity-50', 'color' => '#6366f1'];
        case 'low':
        default:
            return ['label' => 'Thấp', 'badge_class' => 'bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-50', 'color' => '#94a3b8'];
    }
}

/**
 * Sinh mã Ticket ngẫu nhiên duy nhất (VD: #SUP-001048)
 */
function generate_ticket_code($conn) {
    for ($i = 0; $i < 10; $i++) {
        $num = rand(1000, 99999);
        $code = "#SUP-" . str_pad($num, 6, "0", STR_PAD_LEFT);
        $stmt = $conn->prepare("SELECT ticket_id FROM support_tickets WHERE ticket_code = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $code);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $stmt->close();
                return $code;
            }
            $stmt->close();
        }
    }
    return "#SUP-" . time();
}

/**
 * Tạo một Ticket hỗ trợ mới
 */
function create_support_ticket($conn, $user_id, $category, $title, $message, $priority = 'normal', $order_id = null, $game_id = null, $attachments = []) {
    if (!$conn || empty($user_id) || empty($title) || empty($message)) {
        return ['success' => false, 'error' => 'Vui lòng điền đầy đủ tiêu đề và nội dung cần hỗ trợ.'];
    }

    $user_id = intval($user_id);
    $category = trim($category);
    $title = trim($title);
    $message = trim($message);
    $order_id = ($order_id && intval($order_id) > 0) ? intval($order_id) : null;
    $game_id = ($game_id && intval($game_id) > 0) ? intval($game_id) : null;

    // Tự động phân mức ưu tiên nếu là thanh toán hoặc không vào được tài khoản
    if (in_array($category, ['payment', 'account_not_received', 'account'])) {
        if ($priority === 'low' || $priority === 'normal') {
            $priority = 'high';
        }
    }
    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'])) {
        $priority = 'normal';
    }

    $ticket_code = generate_ticket_code($conn);

    $conn->begin_transaction();
    try {
        // 1. Thêm Ticket
        $stmt = $conn->prepare("INSERT INTO support_tickets 
            (ticket_code, user_id, category, title, status, priority, order_id, game_id, created_at, updated_at) 
            VALUES (?, ?, ?, ?, 'new', ?, ?, ?, NOW(), NOW())");
        if (!$stmt) {
            throw new Exception("Lỗi khởi tạo truy vấn tạo ticket: " . $conn->error);
        }
        $stmt->bind_param("sissiii", $ticket_code, $user_id, $category, $title, $priority, $order_id, $game_id);
        $stmt->execute();
        $ticket_id = $conn->insert_id;
        $stmt->close();

        // 2. Thêm tin nhắn khởi đầu vào support_messages
        $msg_stmt = $conn->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_type, message, created_at) 
            VALUES (?, ?, 'user', ?, NOW())");
        if (!$msg_stmt) {
            throw new Exception("Lỗi lưu tin nhắn ticket: " . $conn->error);
        }
        $msg_stmt->bind_param("iis", $ticket_id, $user_id, $message);
        $msg_stmt->execute();
        $message_id = $conn->insert_id;
        $msg_stmt->close();

        // 3. Xử lý lưu các file đính kèm nếu có
        if (!empty($attachments) && is_array($attachments)) {
            $att_stmt = $conn->prepare("INSERT INTO support_attachments (ticket_id, message_id, file_name, file_path, file_type, file_size, uploaded_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            if ($att_stmt) {
                foreach ($attachments as $att) {
                    $fn = $att['file_name'];
                    $fp = $att['file_path'];
                    $ft = $att['file_type'];
                    $fs = intval($att['file_size']);
                    $att_stmt->bind_param("iisssii", $ticket_id, $message_id, $fn, $fp, $ft, $fs, $user_id);
                    $att_stmt->execute();
                }
                $att_stmt->close();
            }
        }

        $conn->commit();

        // 4. Gửi thông báo cho TẤT CẢ Quản Trị Viên
        $priority_text = ($priority === 'urgent' || $priority === 'high') ? ' [ƯU TIÊN CAO]' : '';
        notify_all_admins(
            $conn,
            'support',
            "Yêu cầu hỗ trợ mới {$ticket_code}{$priority_text}",
            "Khách hàng vừa gửi ticket: \"{$title}\" (Danh mục: {$category})",
            "admin_support_detail.php?id={$ticket_id}",
            "SUP-NEW-{$ticket_id}",
            5
        );

        // 5. Gửi thông báo xác nhận cho chính khách hàng
        create_notification(
            $conn,
            $user_id,
            'support',
            "Đã ghi nhận yêu cầu hỗ trợ {$ticket_code}",
            "Yêu cầu \"{$title}\" đã được chuyển đến đội ngũ hỗ trợ ANPLAY. Chúng tôi sẽ xử lý sớm nhất!",
            "support_detail.php?id={$ticket_id}",
            "SUP-CONFIRM-{$ticket_id}",
            10
        );

        return [
            'success' => true,
            'ticket_id' => $ticket_id,
            'ticket_code' => $ticket_code
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Gửi tin nhắn trả lời trong Ticket
 */
function send_support_message($conn, $ticket_id, $sender_id, $sender_type, $message, $attachments = []) {
    if (!$conn || empty($ticket_id) || empty($sender_id) || empty($message)) {
        return ['success' => false, 'error' => 'Nội dung tin nhắn không được để trống.'];
    }

    $ticket_id = intval($ticket_id);
    $sender_id = intval($sender_id);
    $sender_type = in_array($sender_type, ['user', 'admin', 'system']) ? $sender_type : 'user';
    $message = trim($message);

    // Lấy thông tin ticket
    $ticket = null;
    $stmt = $conn->prepare("SELECT * FROM support_tickets WHERE ticket_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$ticket) {
        return ['success' => false, 'error' => 'Không tìm thấy thông tin yêu cầu hỗ trợ.'];
    }

    $conn->begin_transaction();
    try {
        // 1. Lưu tin nhắn
        $msg_stmt = $conn->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_type, message, created_at) 
            VALUES (?, ?, ?, ?, NOW())");
        if (!$msg_stmt) throw new Exception($conn->error);
        $msg_stmt->bind_param("iiss", $ticket_id, $sender_id, $sender_type, $message);
        $msg_stmt->execute();
        $message_id = $conn->insert_id;
        $msg_stmt->close();

        // 2. Lưu file đính kèm nếu có
        if (!empty($attachments) && is_array($attachments)) {
            $att_stmt = $conn->prepare("INSERT INTO support_attachments (ticket_id, message_id, file_name, file_path, file_type, file_size, uploaded_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            if ($att_stmt) {
                foreach ($attachments as $att) {
                    $fn = $att['file_name'];
                    $fp = $att['file_path'];
                    $ft = $att['file_type'];
                    $fs = intval($att['file_size']);
                    $att_stmt->bind_param("iisssii", $ticket_id, $message_id, $fn, $fp, $ft, $fs, $sender_id);
                    $att_stmt->execute();
                }
                $att_stmt->close();
            }
        }

        // 3. Cập nhật trạng thái và thời gian của Ticket
        $ticket_code = $ticket['ticket_code'];
        $ticket_title = $ticket['title'];
        $customer_id = intval($ticket['user_id']);

        if ($sender_type === 'admin') {
            // Admin trả lời -> Chuyển sang chờ khách phản hồi (nếu chưa giải quyết/đóng)
            $new_status = ($ticket['status'] === 'new' || $ticket['status'] === 'in_progress') ? 'waiting_customer' : $ticket['status'];
            $first_res_sql = empty($ticket['first_response_at']) ? ", first_response_at = NOW()" : "";
            
            $conn->query("UPDATE support_tickets SET status = '$new_status', last_response_at = NOW(), updated_at = NOW() $first_res_sql WHERE ticket_id = $ticket_id");

            // Bắn thông báo trực tiếp cho KHÁCH HÀNG
            create_notification(
                $conn,
                $customer_id,
                'support',
                "Admin đã phản hồi {$ticket_code}",
                "Đội ngũ hỗ trợ đã phản hồi yêu cầu \"{$ticket_title}\". Nhấn để xem và trao đổi thêm.",
                "support_detail.php?id={$ticket_id}",
                "SUP-ADMIN-REPLY-{$message_id}",
                5
            );
        } else {
            // Khách hàng gửi tin nhắn -> Nếu ticket đang resolved hoặc waiting_customer, đưa về in_progress
            $new_status = in_array($ticket['status'], ['waiting_customer', 'resolved']) ? 'in_progress' : $ticket['status'];
            $conn->query("UPDATE support_tickets SET status = '$new_status', last_response_at = NOW(), updated_at = NOW() WHERE ticket_id = $ticket_id");

            // Bắn thông báo cho QUẢN TRỊ VIÊN
            notify_all_admins(
                $conn,
                'support',
                "Khách hàng phản hồi {$ticket_code}",
                "Khách hàng vừa gửi phản hồi mới cho yêu cầu \"{$ticket_title}\".",
                "admin_support_detail.php?id={$ticket_id}",
                "SUP-USER-REPLY-{$message_id}",
                5
            );
        }

        $conn->commit();
        return ['success' => true, 'message_id' => $message_id];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Cập nhật trạng thái Ticket (Admin xử lý hoặc đóng/mở lại)
 */
function update_ticket_status($conn, $ticket_id, $new_status, $user_id, $is_admin = false, $note = '') {
    if (!$conn) return false;
    $ticket_id = intval($ticket_id);
    $new_status = trim($new_status);

    $ticket = null;
    $stmt = $conn->prepare("SELECT * FROM support_tickets WHERE ticket_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$ticket) return false;

    // Kiểm tra quyền
    if (!$is_admin && $ticket['user_id'] != $user_id) {
        return false; // IDOR Protection
    }

    $extra_sql = "";
    if ($new_status === 'resolved') {
        $extra_sql = ", resolved_at = NOW()";
    } elseif ($new_status === 'closed') {
        $extra_sql = ", closed_at = NOW()";
    }

    $up_stmt = $conn->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW() $extra_sql WHERE ticket_id = ?");
    if ($up_stmt) {
        $up_stmt->bind_param("si", $new_status, $ticket_id);
        $success = $up_stmt->execute();
        $up_stmt->close();

        if ($success) {
            $ticket_code = $ticket['ticket_code'];
            $ticket_title = $ticket['title'];
            $customer_id = intval($ticket['user_id']);

            // Nếu admin đổi trạng thái thành resolved
            if ($new_status === 'resolved') {
                $msg_note = !empty($note) ? ": \"$note\"" : "";
                create_notification(
                    $conn,
                    $customer_id,
                    'support',
                    "Yêu cầu {$ticket_code} đã được giải quyết",
                    "Yêu cầu \"{$ticket_title}\" của bạn đã được giải quyết{$msg_note}. Vui lòng đánh giá chất lượng phục vụ!",
                    "support_detail.php?id={$ticket_id}",
                    "SUP-RESOLVED-{$ticket_id}",
                    10
                );
            } elseif ($new_status === 'closed') {
                create_notification(
                    $conn,
                    $customer_id,
                    'support',
                    "Yêu cầu {$ticket_code} đã được đóng",
                    "Yêu cầu \"{$ticket_title}\" đã đóng hoàn tất. Cảm ơn bạn đã đồng hành cùng ANPLAY!",
                    "support_detail.php?id={$ticket_id}",
                    "SUP-CLOSED-{$ticket_id}",
                    10
                );
            }
        }
        return $success;
    }
    return false;
}

/**
 * Khách hàng mở lại ticket nếu vấn đề chưa được giải quyết
 */
function reopen_support_ticket($conn, $ticket_id, $user_id, $reason = '') {
    if (!$conn) return false;
    $ticket_id = intval($ticket_id);
    $user_id = intval($user_id);
    $reason = trim($reason);

    $ticket = null;
    $stmt = $conn->prepare("SELECT * FROM support_tickets WHERE ticket_id = ? AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $ticket_id, $user_id);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$ticket) return false;

    // Chuyển status về in_progress
    $upd = $conn->prepare("UPDATE support_tickets SET status = 'in_progress', resolved_at = NULL, updated_at = NOW() WHERE ticket_id = ?");
    if ($upd) {
        $upd->bind_param("i", $ticket_id);
        $upd->execute();
        $upd->close();

        // Ghi tin nhắn hệ thống ghi nhận lý do mở lại
        $reopen_msg = "⚠️ [KHÁCH HÀNG MỞ LẠI YÊU CẦU]: Khách hàng thông báo vấn đề chưa được xử lý triệt để." . (!empty($reason) ? "\nLý do: " . $reason : "");
        $conn->query("INSERT INTO support_messages (ticket_id, sender_id, sender_type, message, created_at) 
            VALUES ($ticket_id, $user_id, 'user', '" . $conn->real_escape_string($reopen_msg) . "', NOW())");

        // Bắn thông báo khẩn cấp cho Quản Trị Viên
        notify_all_admins(
            $conn,
            'support',
            "⚠️ Mở lại yêu cầu {$ticket['ticket_code']}",
            "Khách hàng báo vấn đề chưa được giải quyết với yêu cầu \"{$ticket['title']}\".",
            "admin_support_detail.php?id={$ticket_id}",
            "SUP-REOPEN-{$ticket_id}",
            5
        );
        return true;
    }
    return false;
}

/**
 * Đánh giá chất lượng hỗ trợ (1-5 sao)
 */
function rate_support_ticket($conn, $ticket_id, $user_id, $rating, $comment = '') {
    if (!$conn) return false;
    $ticket_id = intval($ticket_id);
    $user_id = intval($user_id);
    $rating = max(1, min(5, intval($rating)));
    $comment = trim($comment);

    $stmt = $conn->prepare("UPDATE support_tickets SET rating = ?, rating_comment = ?, updated_at = NOW() WHERE ticket_id = ? AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param("isii", $rating, $comment, $ticket_id, $user_id);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }
    return false;
}

/**
 * Upload file đính kèm an toàn (Chặn file nguy hiểm .php, .exe, kiểm tra MIME thật)
 */
function upload_support_attachment($file, $ticket_id, $user_id) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'error' => 'Dữ liệu file không hợp lệ.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Lỗi tải file lên máy chủ (Code: ' . $file['error'] . ')'];
    }

    // Giới hạn dung lượng 5MB
    $max_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'Dung lượng file quá lớn. Tối đa cho phép là 5MB.'];
    }

    // Danh sách định dạng cho phép
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $allowed_mimes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif'
    ];

    $filename = basename($file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_extensions)) {
        return ['success' => false, 'error' => 'Chỉ chấp nhận file ảnh chụp màn hình định dạng JPG, PNG, WEBP, GIF.'];
    }

    // Kiểm tra MIME thật bằng finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $real_mime = $finfo->file($file['tmp_name']);

    if (!in_array($real_mime, $allowed_mimes)) {
        return ['success' => false, 'error' => 'Tệp tin không phải là ảnh hợp lệ hoặc có dấu hiệu nguy hiểm.'];
    }

    // Tạo thư mục uploads/support nếu chưa có
    $target_dir = __DIR__ . '/../uploads/support';
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
        // Tạo file index.php và .htaccess chống thực thi mã nguồn
        @file_put_contents($target_dir . '/index.php', '<?php http_response_code(403); exit(); ?>');
        @file_put_contents($target_dir . '/.htaccess', "<FilesMatch \"\\.(php|phtml|php5|php7|phar|sh|pl|cgi)$\">\nDeny from all\n</FilesMatch>\nOptions -Indexes\n");
    }

    // Tạo tên file an toàn bằng hash
    $safe_filename = 'sup_' . intval($ticket_id) . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target_path = $target_dir . '/' . $safe_filename;
    $db_path = 'uploads/support/' . $safe_filename;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        return ['success' => false, 'error' => 'Không thể lưu file đính kèm vào máy chủ.'];
    }

    return [
        'success' => true,
        'file_name' => $filename,
        'file_path' => $db_path,
        'file_type' => $real_mime,
        'file_size' => $file['size']
    ];
}

