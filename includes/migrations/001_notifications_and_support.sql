-- =========================================================================
-- ANPLAY STORE - MIGRATION 001: NOTIFICATION & CUSTOMER SUPPORT SYSTEMS
-- Tương thích hoàn toàn MySQL 5.7+ / MariaDB 10.3+ / Hosting InfinityFree
-- =========================================================================

-- 1. BẢNG THÔNG BÁO NGƯỜI DÙNG & HỆ THỐNG
CREATE TABLE IF NOT EXISTS `notifications` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. BẢNG YÊU CẦU HỖ TRỢ (TICKETS)
CREATE TABLE IF NOT EXISTS `support_tickets` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. BẢNG TIN NHẮN TRONG TICKET (CONVERSATION MESSAGES)
CREATE TABLE IF NOT EXISTS `support_messages` (
  `message_id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `sender_id` INT NOT NULL,
  `sender_type` ENUM('user', 'admin', 'system') NOT NULL,
  `message` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_msg_ticket` (`ticket_id`, `created_at`),
  INDEX `idx_msg_sender` (`sender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. BẢNG FILE ĐÍNH KÈM HỖ TRỢ (ATTACHMENTS)
CREATE TABLE IF NOT EXISTS `support_attachments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

