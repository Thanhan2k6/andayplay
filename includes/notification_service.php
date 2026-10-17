<?php
/**
 * ANPLAY STORE - NOTIFICATION SERVICE
 * Hệ thống quản lý và phân phối thông báo người dùng & Quản trị viên
 */

if (!defined('ANPLAY_NOTIFICATION_LOADED')) {
    define('ANPLAY_NOTIFICATION_LOADED', true);
}

/**
 * Tự động đồng bộ và khởi tạo cấu trúc bảng CSDL nếu chưa có (Dự phòng cho môi trường Hosting)
 */
function ensure_notification_and_support_schema($conn) {
    static $checked = false;
    if ($checked || !$conn) return;
    $checked = true;
    try {
        $chk = $conn->query("SHOW TABLES LIKE 'notifications'");
        if (!$chk || $chk->num_rows === 0) {
            $conn->query("CREATE TABLE IF NOT EXISTS `notifications` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `user_id` INT NOT NULL,
              `type` VARCHAR(50) NOT NULL DEFAULT 'system',
              `title` VARCHAR(255) NOT NULL,
              `message` TEXT NOT NULL,
              `link` VARCHAR(255) DEFAULT NULL,
              `related_id` VARCHAR(100) DEFAULT NULL,
              `is_read` TINYINT(1) NOT NULL DEFAULT 0,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_user_read_time` (`user_id`, `is_read`, `created_at`),
              INDEX `idx_type` (`type`),
              INDEX `idx_related` (`related_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

            $conn->query("CREATE TABLE IF NOT EXISTS `support_tickets` (
              `ticket_id` INT AUTO_INCREMENT PRIMARY KEY,
              `ticket_code` VARCHAR(30) NOT NULL UNIQUE,
              `user_id` INT NOT NULL,
              `category` VARCHAR(100) NOT NULL,
              `title` VARCHAR(255) NOT NULL,
              `status` ENUM('new', 'assigned', 'in_progress', 'waiting_customer', 'resolved', 'closed') NOT NULL DEFAULT 'new',
              `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
              `order_id` INT DEFAULT NULL,
              `game_id` INT DEFAULT NULL,
              `rating` TINYINT DEFAULT NULL,
              `rating_comment` TEXT DEFAULT NULL,
              `first_response_at` DATETIME DEFAULT NULL,
              `last_response_at` DATETIME DEFAULT NULL,
              `resolved_at` DATETIME DEFAULT NULL,
              `closed_at` DATETIME DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_ticket_user` (`user_id`),
              INDEX `idx_ticket_status` (`status`),
              INDEX `idx_ticket_priority` (`priority`),
              INDEX `idx_ticket_order` (`order_id`),
              INDEX `idx_ticket_updated` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

            $conn->query("CREATE TABLE IF NOT EXISTS `support_messages` (
              `message_id` INT AUTO_INCREMENT PRIMARY KEY,
              `ticket_id` INT NOT NULL,
              `sender_id` INT NOT NULL,
              `sender_type` ENUM('user', 'admin', 'system') NOT NULL,
              `message` TEXT NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_msg_ticket` (`ticket_id`, `created_at`),
              INDEX `idx_msg_sender` (`sender_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

            $conn->query("CREATE TABLE IF NOT EXISTS `support_attachments` (
              `attachment_id` INT AUTO_INCREMENT PRIMARY KEY,
              `ticket_id` INT NOT NULL,
              `message_id` INT DEFAULT NULL,
              `file_name` VARCHAR(255) NOT NULL,
              `file_path` VARCHAR(255) NOT NULL,
              `file_type` VARCHAR(100) NOT NULL,
              `file_size` INT NOT NULL,
              `uploaded_by` INT NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_att_ticket` (`ticket_id`),
              INDEX `idx_att_message` (`message_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
        }

        // Đảm bảo cột assigned_to trong bảng support_tickets
        $col_assigned = $conn->query("SHOW COLUMNS FROM support_tickets LIKE 'assigned_to'");
        if ($col_assigned && $col_assigned->num_rows === 0) {
            $conn->query("ALTER TABLE support_tickets ADD COLUMN assigned_to INT DEFAULT NULL AFTER game_id, ADD INDEX idx_st_assigned (assigned_to)");
        }

        // Đảm bảo cột status trong bảng users
        $col_u_status = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
        if ($col_u_status && $col_u_status->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN status ENUM('active','suspended','banned') NOT NULL DEFAULT 'active' AFTER role");
        }
    } catch (Throwable $e) {}
}

if (isset($conn)) {
    ensure_notification_and_support_schema($conn);
}

/**
 * Tạo thông báo mới cho người dùng hoặc admin
 * Có cơ chế Deduplication / Idempotency để tránh spam cùng một thông báo
 */
function create_notification($conn, $user_id, $type, $title, $message, $link = null, $related_id = null, $dedup_window_minutes = 30) {
    if (!$conn || empty($user_id)) {
        return false;
    }

    $user_id = intval($user_id);
    $type = trim($type);
    $title = trim($title);
    $message = trim($message);
    $link = !empty($link) ? trim($link) : null;
    $related_id = !empty($related_id) ? trim($related_id) : null;

    // 1. Kiểm tra Deduplication nếu có related_id và window > 0
    if ($related_id !== null && $dedup_window_minutes > 0) {
        $window = intval($dedup_window_minutes);
        $chk_stmt = $conn->prepare("SELECT id FROM notifications 
            WHERE user_id = ? AND type = ? AND related_id = ? AND created_at >= NOW() - INTERVAL ? MINUTE 
            LIMIT 1");
        if ($chk_stmt) {
            $chk_stmt->bind_param("issi", $user_id, $type, $related_id, $window);
            $chk_stmt->execute();
            $res = $chk_stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $existing = $res->fetch_assoc();
                $chk_stmt->close();
                return intval($existing['id']); // Đã có thông báo tương đương gần đây
            }
            $chk_stmt->close();
        }
    }

    // 2. Thêm thông báo mới
    $ins_stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, related_id, is_read, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
    if ($ins_stmt) {
        $ins_stmt->bind_param("isssss", $user_id, $type, $title, $message, $link, $related_id);
        if ($ins_stmt->execute()) {
            $new_id = $conn->insert_id;
            $ins_stmt->close();
            return $new_id;
        }
        $ins_stmt->close();
    }
    return false;
}

/**
 * Gửi thông báo tới Quản Trị Viên hỗ trợ (role = 1) và Quản Trị Cấp Cao (role = 2)
 * Có hỗ trợ loại trừ người gửi và gán per-user related_id để tránh chặn chéo
 */
function notify_all_admins($conn, $type, $title, $message, $link = null, $related_id = null, $dedup_window_minutes = 15, $exclude_user_id = 0) {
    if (!$conn) return 0;
    $count = 0;
    $exclude_user_id = intval($exclude_user_id);
    try {
        $where = "WHERE role IN (1, 2)";
        if ($exclude_user_id > 0) {
            $where .= " AND user_id != $exclude_user_id";
        }
        $admins = $conn->query("SELECT user_id, role FROM users $where");
        if ($admins && $admins->num_rows > 0) {
            while ($adm = $admins->fetch_assoc()) {
                $adm_id = intval($adm['user_id']);
                $user_related_id = ($related_id !== null) ? "{$related_id}-U{$adm_id}" : null;
                if (create_notification($conn, $adm_id, $type, $title, $message, $link, $user_related_id, $dedup_window_minutes)) {
                    $count++;
                }
            }
        }
    } catch (Throwable $e) {}
    return $count;
}

/**
 * Gửi thông báo chỉ cho SUPER_ADMIN (role = 2)
 */
function notify_super_admins($conn, $type, $title, $message, $link = null, $related_id = null, $dedup_window_minutes = 15, $exclude_user_id = 0) {
    if (!$conn) return 0;
    $count = 0;
    $exclude_user_id = intval($exclude_user_id);
    try {
        $where = "WHERE role = 2";
        if ($exclude_user_id > 0) {
            $where .= " AND user_id != $exclude_user_id";
        }
        $admins = $conn->query("SELECT user_id FROM users $where");
        if ($admins && $admins->num_rows > 0) {
            while ($adm = $admins->fetch_assoc()) {
                $adm_id = intval($adm['user_id']);
                $user_related_id = ($related_id !== null) ? "{$related_id}-U{$adm_id}" : null;
                if (create_notification($conn, $adm_id, $type, $title, $message, $link, $user_related_id, $dedup_window_minutes)) {
                    $count++;
                }
            }
        }
    } catch (Throwable $e) {}
    return $count;
}

/**
 * Gửi thông báo broadcast cho nhóm đối tượng
 * $target_group: 'all' (tất cả user), 'customers' (role=0), 'vip' (role=0), 'admins' (role IN (1, 2))
 */
function broadcast_notification($conn, $title, $message, $type = 'promotion', $link = null, $target_group = 'all') {
    if (!$conn) return 0;
    $where = "";
    if ($target_group === 'customers' || $target_group === 'vip') {
        $where = "WHERE role = 0";
    } elseif ($target_group === 'admins') {
        $where = "WHERE role IN (1, 2)";
    }

    $count = 0;
    try {
        $users = $conn->query("SELECT user_id FROM users $where");
        if ($users && $users->num_rows > 0) {
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, related_id, is_read, created_at) 
                VALUES (?, ?, ?, ?, ?, NULL, 0, NOW())");
            if ($stmt) {
                while ($u = $users->fetch_assoc()) {
                    $u_id = intval($u['user_id']);
                    $stmt->bind_param("issss", $u_id, $type, $title, $message, $link);
                    if ($stmt->execute()) {
                        $count++;
                    }
                }
                $stmt->close();
            }
        }
    } catch (Throwable $e) {}
    return $count;
}

/**
 * Lấy số lượng thông báo chưa đọc của user
 */
function get_unread_notification_count($conn, $user_id) {
    if (!$conn || empty($user_id)) return 0;
    $user_id = intval($user_id);
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as unread_c FROM notifications WHERE user_id = ? AND is_read = 0");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return intval($res['unread_c'] ?? 0);
        }
    } catch (Throwable $e) {}
    return 0;
}

/**
 * Lấy danh sách thông báo của user (phân trang & lọc)
 */
function get_user_notifications($conn, $user_id, $filter_type = 'all', $unread_only = false, $limit = 10, $offset = 0) {
    if (!$conn || empty($user_id)) return [];
    $user_id = intval($user_id);
    $limit = intval($limit);
    $offset = intval($offset);

    $conditions = ["user_id = ?"];
    $types = "i";
    $params = [$user_id];

    if ($unread_only) {
        $conditions[] = "is_read = 0";
    }

    if ($filter_type !== 'all' && !empty($filter_type)) {
        if ($filter_type === 'rental') {
            $conditions[] = "type IN ('rental', 'order')";
        } elseif ($filter_type === 'support') {
            $conditions[] = "type IN ('support', 'complaint')";
        } elseif ($filter_type === 'system') {
            $conditions[] = "type IN ('system', 'security', 'account')";
        } else {
            $conditions[] = "type = ?";
            $types .= "s";
            $params[] = $filter_type;
        }
    }

    $where_sql = implode(" AND ", $conditions);
    $sql = "SELECT * FROM notifications WHERE $where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $items = [];
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['time_ago'] = format_time_ago($row['created_at']);
                $row['icon_info'] = get_notification_icon_info($row['type']);
                $items[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $e) {}
    return $items;
}

/**
 * Đánh dấu một thông báo đã đọc (có kiểm tra quyền sở hữu IDOR)
 */
function mark_notification_read($conn, $notification_id, $user_id) {
    if (!$conn) return false;
    $notification_id = intval($notification_id);
    $user_id = intval($user_id);
    try {
        // Kiểm tra quyền sở hữu IDOR
        $chk = $conn->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("ii", $notification_id, $user_id);
            $chk->execute();
            $res = $chk->get_result();
            $exists = ($res && $res->num_rows > 0);
            $chk->close();
            if (!$exists) {
                return false;
            }
        }
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $notification_id, $user_id);
            $success = $stmt->execute();
            $stmt->close();
            return $success;
        }
    } catch (Throwable $e) {}
    return false;
}

/**
 * Đánh dấu tất cả thông báo đã đọc
 */
function mark_all_notifications_read($conn, $user_id) {
    if (!$conn) return false;
    $user_id = intval($user_id);
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $success = $stmt->execute();
            $stmt->close();
            return $success;
        }
    } catch (Throwable $e) {}
    return false;
}

/**
 * Định dạng thời gian tương đối thân thiện kiểu Việt Nam
 */
function format_time_ago($datetime) {
    $timestamp = strtotime($datetime);
    if (!$timestamp) return $datetime;
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'Vừa xong';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' phút trước';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' giờ trước';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' ngày trước';
    } else {
        return date('d/m/Y H:i', $timestamp);
    }
}

/**
 * Xóa một thông báo (có bảo vệ chống IDOR)
 */
function delete_notification($conn, $notification_id, $user_id) {
    if (!$conn) return false;
    $notification_id = intval($notification_id);
    $user_id = intval($user_id);
    try {
        // Kiểm tra quyền sở hữu IDOR
        $chk = $conn->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("ii", $notification_id, $user_id);
            $chk->execute();
            $res = $chk->get_result();
            $exists = ($res && $res->num_rows > 0);
            $chk->close();
            if (!$exists) {
                return false;
            }
        }
        $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $notification_id, $user_id);
            $success = $stmt->execute();
            $stmt->close();
            return $success;
        }
    } catch (Throwable $e) {}
    return false;
}

/**
 * Xóa danh sách thông báo của người dùng (tùy chọn chỉ xóa những thông báo đã đọc)
 */
function delete_user_notifications($conn, $user_id, $only_read = false) {
    if (!$conn) return false;
    $user_id = intval($user_id);
    $where = "WHERE user_id = ?";
    if ($only_read) {
        $where .= " AND is_read = 1";
    }
    try {
        $stmt = $conn->prepare("DELETE FROM notifications $where");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $success = $stmt->execute();
            $stmt->close();
            return $success;
        }
    } catch (Throwable $e) {}
    return false;
}

/**
 * Gửi thông báo tới các Quản Trị Viên sở hữu quyền hạn cụ thể (và luôn gửi tới Super Admin)
 */
function notify_admins_with_permission($conn, $permission_key, $type, $title, $message, $link = null, $related_id = null, $dedup_window_minutes = 15, $exclude_user_id = 0) {
    if (!$conn) return 0;
    $count = 0;
    $exclude_user_id = intval($exclude_user_id);
    try {
        $where = "WHERE role IN (1, 2) AND status = 'active'";
        if ($exclude_user_id > 0) {
            $where .= " AND user_id != $exclude_user_id";
        }
        $admins = $conn->query("SELECT user_id, role FROM users $where");
        if ($admins && $admins->num_rows > 0) {
            while ($adm = $admins->fetch_assoc()) {
                $adm_id = intval($adm['user_id']);
                $adm_role = intval($adm['role']);
                
                // Super Admin luôn nhận mọi thông báo quản trị
                // Admin thường chỉ nhận nếu có quyền cụ thể hoặc quyền mặc định
                $can_receive = ($adm_role === ROLE_SUPER_ADMIN);
                if (!$can_receive && function_exists('has_user_permission')) {
                    $can_receive = has_user_permission($conn, $adm_id, $permission_key);
                }

                if ($can_receive) {
                    $user_related_id = ($related_id !== null) ? "{$related_id}-U{$adm_id}" : null;
                    if (create_notification($conn, $adm_id, $type, $title, $message, $link, $user_related_id, $dedup_window_minutes)) {
                        $count++;
                    }
                }
            }
        }
    } catch (Throwable $e) {}
    return $count;
}

/**
 * Tự động kiểm tra và cảnh báo các đơn thuê sắp hết hạn trong vòng 24 giờ tới
 */
function check_and_notify_expiring_rentals($conn, $user_id = null) {
    if (!$conn) return 0;
    $count = 0;
    $where = "WHERE br.status = 'active' AND br.due_date > NOW() AND br.due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)";
    if ($user_id !== null) {
        $uid = intval($user_id);
        $where .= " AND br.user_id = $uid";
    }

    try {
        $sql = "SELECT br.borrow_id, br.user_id, br.due_date, g.title 
                FROM borrow_records br 
                JOIN games g ON br.game_id = g.game_id 
                $where";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $b_id = intval($row['borrow_id']);
                $u_id = intval($row['user_id']);
                $due_fmt = date('d/m/Y H:i', strtotime($row['due_date']));
                $g_title = $row['title'];

                // Deduplication window 1440 phút (24h) để chỉ thông báo 1 lần
                if (create_notification(
                    $conn,
                    $u_id,
                    'rental',
                    "Gói thuê game sắp hết hạn ⚠️",
                    "Đơn thuê #{$b_id} ({$g_title}) sẽ hết hạn vào lúc {$due_fmt}. Bạn hãy gia hạn sớm để tránh gián đoạn tiến trình chơi game nhé!",
                    "my_library.php",
                    "EXP-SOON-{$b_id}",
                    1440
                )) {
                    $count++;
                }
            }
        }
    } catch (Throwable $e) {}
    return $count;
}

/**
 * Lấy Icon và màu sắc tương ứng với từng loại thông báo
 */
function get_notification_icon_info($type) {
    switch ($type) {
        case 'rental':
            return ['icon' => 'bi-controller', 'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.15)', 'label' => 'Đơn thuê'];
        case 'payment':
            return ['icon' => 'bi-credit-card-2-front', 'color' => '#06b6d4', 'bg' => 'rgba(6,182,212,0.15)', 'label' => 'Thanh toán'];
        case 'support':
            return ['icon' => 'bi-headset', 'color' => '#8b5cf6', 'bg' => 'rgba(139,92,246,0.15)', 'label' => 'Hỗ trợ'];
        case 'promotion':
            return ['icon' => 'bi-gift-fill', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.15)', 'label' => 'Khuyến mãi'];
        case 'account':
            return ['icon' => 'bi-person-badge-fill', 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.15)', 'label' => 'Tài khoản'];
        case 'security':
            return ['icon' => 'bi-shield-exclamation', 'color' => '#f43f5e', 'bg' => 'rgba(244,63,94,0.15)', 'label' => 'Bảo mật'];
        case 'complaint':
            return ['icon' => 'bi-exclamation-octagon-fill', 'color' => '#ef4444', 'bg' => 'rgba(239,68,68,0.15)', 'label' => 'Khiếu nại'];
        case 'order':
            return ['icon' => 'bi-receipt-cutoff', 'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.15)', 'label' => 'Đơn hàng'];
        case 'game':
            return ['icon' => 'bi-joystick', 'color' => '#ec4899', 'bg' => 'rgba(236,72,153,0.15)', 'label' => 'Game Store'];
        case 'system':
        default:
            return ['icon' => 'bi-bell-fill', 'color' => '#94a3b8', 'bg' => 'rgba(148,163,184,0.15)', 'label' => 'Hệ thống'];
    }
}

