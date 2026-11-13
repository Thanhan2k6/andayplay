-- =========================================================================
-- ANPLAY STORE - MIGRATION 002: SCHEMA INTEGRITY, SECURITY & INDEXES
-- Idempotent Forward Migration (MySQL 5.7+ / 8.0+ / MariaDB 10.3+)
-- Safe for both local staging (anplay_staging) and phpMyAdmin import.
-- =========================================================================

SET @db_name = DATABASE();

-- -------------------------------------------------------------------------
-- 1. BORROW_RECORDS: Fix due_date NULLability & ensure v2/v4/v5 columns
-- -------------------------------------------------------------------------
ALTER TABLE `borrow_records` MODIFY COLUMN `due_date` DATETIME NULL DEFAULT NULL;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='order_code'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `order_code` VARCHAR(50) NULL AFTER `borrow_id`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='account_id'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `account_id` INT NULL AFTER `game_id`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='package_id'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `package_id` INT NULL AFTER `account_id`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='duration_hours'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `duration_hours` INT NOT NULL DEFAULT 168 AFTER `package_id`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='rental_price'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `rental_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `duration_hours`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='payment_status'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `payment_status` ENUM(''pending'',''paid'',''failed'',''refunded'') DEFAULT ''pending'' AFTER `rental_price`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='payment_method'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `payment_method` VARCHAR(50) NULL AFTER `payment_status`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='payment_tx_id'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `payment_tx_id` VARCHAR(100) NULL AFTER `payment_method`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND INDEX_NAME='uq_borrow_payment_tx'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD UNIQUE KEY `uq_borrow_payment_tx` (`payment_tx_id`)'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='rent_type'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `rent_type` ENUM(''new'',''extend'',''re-rent'') DEFAULT ''new'' AFTER `payment_tx_id`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='previous_borrow_id'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `previous_borrow_id` INT NULL AFTER `rent_type`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='revoke_status'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `revoke_status` ENUM(''none'',''pending'',''revoked'',''failed'') DEFAULT ''none'' AFTER `status`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='error_message'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `error_message` TEXT NULL AFTER `revoke_status`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='return_date'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `return_date` DATETIME NULL DEFAULT NULL AFTER `due_date`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='warn_24h_sent'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `warn_24h_sent` TINYINT(1) NOT NULL DEFAULT 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='warn_1h_sent'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `warn_1h_sent` TINYINT(1) NOT NULL DEFAULT 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND COLUMN_NAME='warn_15m_sent'),
    'SELECT 1',
    'ALTER TABLE `borrow_records` ADD COLUMN `warn_15m_sent` TINYINT(1) NOT NULL DEFAULT 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------
-- 2. USERS: Add 2FA columns & status column
-- -------------------------------------------------------------------------
SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='users' AND COLUMN_NAME='status'),
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `status` ENUM(''active'',''suspended'',''banned'') NOT NULL DEFAULT ''active'' AFTER `role`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='users' AND COLUMN_NAME='two_factor_enabled'),
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='users' AND COLUMN_NAME='two_factor_secret'),
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `two_factor_secret` VARCHAR(64) NULL DEFAULT NULL AFTER `two_factor_enabled`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='users' AND COLUMN_NAME='two_factor_backup_codes'),
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `two_factor_backup_codes` TEXT NULL AFTER `two_factor_secret`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------
-- 3. GAMES: Add quantity column & sync quantity/available_quantity
-- -------------------------------------------------------------------------
SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='games' AND COLUMN_NAME='quantity'),
    'SELECT 1',
    'ALTER TABLE `games` ADD COLUMN `quantity` INT NOT NULL DEFAULT 0 AFTER `available_quantity`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `games` g
SET
    g.`quantity` = (SELECT COUNT(*) FROM `game_accounts` ga WHERE ga.`game_id` = g.`game_id` AND ga.`status` = 'AVAILABLE'),
    g.`available_quantity` = (SELECT COUNT(*) FROM `game_accounts` ga WHERE ga.`game_id` = g.`game_id` AND ga.`status` = 'AVAILABLE');

-- -------------------------------------------------------------------------
-- 4. SUPPORT_TICKETS: Ensure assigned_to column exists
-- -------------------------------------------------------------------------
SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='support_tickets' AND COLUMN_NAME='assigned_to'),
    'SELECT 1',
    'ALTER TABLE `support_tickets` ADD COLUMN `assigned_to` INT NULL DEFAULT NULL AFTER `game_id`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='support_tickets' AND INDEX_NAME='idx_st_assigned'),
    'SELECT 1',
    'ALTER TABLE `support_tickets` ADD INDEX `idx_st_assigned` (`assigned_to`)'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------
-- 5. SECURITY, RBAC & AUTH TABLES
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `target_type` VARCHAR(50) DEFAULT NULL,
    `target_id` INT DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT DEFAULT NULL,
    `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `description` VARCHAR(255) DEFAULT NULL,
    `updated_by` INT DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
    `permission_key` VARCHAR(50) PRIMARY KEY,
    `permission_name` VARCHAR(100) NOT NULL,
    `module` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_permissions` (
    `user_id` INT NOT NULL,
    `permission_key` VARCHAR(50) NOT NULL,
    `granted_by` INT DEFAULT NULL,
    `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `permission_key`),
    INDEX `idx_perm_user` (`user_id`),
    INDEX `idx_perm_key` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `permissions` (`permission_key`, `permission_name`, `module`, `description`) VALUES
('support.view', 'Xem danh sách yêu cầu hỗ trợ', 'support', 'Xem danh sách và chi tiết các yêu cầu hỗ trợ của khách hàng'),
('support.reply', 'Phản hồi yêu cầu hỗ trợ', 'support', 'Trả lời tin nhắn và cập nhật trạng thái xử lý ticket'),
('notification.view', 'Xem lịch sử thông báo', 'notification', 'Xem các thông báo đã gửi toàn sàn'),
('notification.send', 'Gửi thông báo hệ thống', 'notification', 'Phát tán thông báo tới toàn sàn hoặc cá nhân khách hàng'),
('customer.view', 'Xem danh sách khách hàng', 'customer', 'Xem danh sách thành viên và thông tin cá nhân'),
('customer.manage', 'Quản lý khách hàng', 'customer', 'Khóa, mở khóa và can thiệp tài khoản khách hàng'),
('game.view', 'Xem danh sách trò chơi', 'game', 'Xem kho game và chi tiết sản phẩm trong admin'),
('game.manage', 'Quản lý kho trò chơi', 'game', 'Thêm mới, chỉnh sửa thông tin và xóa game'),
('rental.view', 'Xem kho tài khoản game', 'rental', 'Xem danh sách tài khoản Steam/EA/Epic sẵn có'),
('rental.manage', 'Quản trị kho tài khoản', 'rental', 'Thêm mới tài khoản game, đổi mật khẩu và bảo trì'),
('order.view', 'Xem danh sách đơn thuê', 'order', 'Xem lịch sử các giao dịch thuê tài khoản game'),
('order.manage', 'Quản trị đơn thuê', 'order', 'Xác nhận gia hạn, hoàn tất hoặc thu hồi đơn thuê'),
('admin.manage', 'Quản trị ban quản trị', 'admin', 'Tạo tài khoản Admin, khóa, mở khóa và xóa Admin (Độc quyền Super Admin)'),
('role.manage', 'Quản lý phân quyền', 'role', 'Cấp quyền và điều chỉnh vai trò thành viên (Độc quyền Super Admin)'),
('audit.view', 'Xem nhật ký kiểm toán', 'audit', 'Xem lịch sử hành động bảo mật của hệ thống (Độc quyền Super Admin)'),
('system.manage', 'Cấu hình hệ thống toàn sàn', 'system', 'Điều chỉnh thông tin sàn và các cài đặt bảo mật (Độc quyền Super Admin)');

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL DEFAULT NULL,
    `request_ip` VARCHAR(45) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_pr_token_hash` (`token_hash`),
    INDEX `idx_pr_email_exp` (`email`, `expires_at`),
    INDEX `idx_pr_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `auth_rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `action_key` VARCHAR(50) NOT NULL,
    `identifier` VARCHAR(191) NOT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_rl_action_ident` (`action_key`, `identifier`),
    INDEX `idx_rl_locked` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 6. MISSING INDEXES ON CORE TABLES
-- -------------------------------------------------------------------------
SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND INDEX_NAME='idx_borrow_status_due'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD INDEX `idx_borrow_status_due` (`status`, `due_date`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND INDEX_NAME='idx_borrow_user_game_status'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD INDEX `idx_borrow_user_game_status` (`user_id`, `game_id`, `status`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND INDEX_NAME='idx_borrow_prev'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD INDEX `idx_borrow_prev` (`previous_borrow_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND INDEX_NAME='idx_borrow_account'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD INDEX `idx_borrow_account` (`account_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND INDEX_NAME='idx_borrow_order_code'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD INDEX `idx_borrow_order_code` (`order_code`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND INDEX_NAME='idx_borrow_game'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD INDEX `idx_borrow_game` (`game_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND INDEX_NAME='idx_borrow_package'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD INDEX `idx_borrow_package` (`package_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='comments' AND INDEX_NAME='idx_comments_game'), 'SELECT 1', 'ALTER TABLE `comments` ADD INDEX `idx_comments_game` (`game_id`, `comment_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='comments' AND INDEX_NAME='idx_comments_user'), 'SELECT 1', 'ALTER TABLE `comments` ADD INDEX `idx_comments_user` (`user_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='site_visits' AND INDEX_NAME='idx_visit_date'), 'SELECT 1', 'ALTER TABLE `site_visits` ADD INDEX `idx_visit_date` (`visit_date`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='games' AND INDEX_NAME='idx_games_price_type'), 'SELECT 1', 'ALTER TABLE `games` ADD INDEX `idx_games_price_type` (`price_type`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='games' AND INDEX_NAME='idx_games_title'), 'SELECT 1', 'ALTER TABLE `games` ADD INDEX `idx_games_title` (`title`(100))'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------
-- 7. ORPHAN CLEANUP & FOREIGN KEYS
-- -------------------------------------------------------------------------
DELETE rp FROM `rental_packages` rp LEFT JOIN `games` g ON rp.`game_id` = g.`game_id` WHERE g.`game_id` IS NULL;
DELETE ga FROM `game_accounts` ga LEFT JOIN `games` g ON ga.`game_id` = g.`game_id` WHERE g.`game_id` IS NULL;
DELETE c FROM `comments` c LEFT JOIN `games` g ON c.`game_id` = g.`game_id` WHERE g.`game_id` IS NULL;
DELETE c FROM `comments` c LEFT JOIN `users` u ON c.`user_id` = u.`user_id` WHERE u.`user_id` IS NULL;
DELETE n FROM `notifications` n LEFT JOIN `users` u ON n.`user_id` = u.`user_id` WHERE u.`user_id` IS NULL;
DELETE sm FROM `support_messages` sm LEFT JOIN `support_tickets` st ON sm.`ticket_id` = st.`ticket_id` WHERE st.`ticket_id` IS NULL;
DELETE sa FROM `support_attachments` sa LEFT JOIN `support_tickets` st ON sa.`ticket_id` = st.`ticket_id` WHERE st.`ticket_id` IS NULL;
DELETE st FROM `support_tickets` st LEFT JOIN `users` u ON st.`user_id` = u.`user_id` WHERE u.`user_id` IS NULL;
DELETE up FROM `user_permissions` up LEFT JOIN `users` u ON up.`user_id` = u.`user_id` WHERE u.`user_id` IS NULL;
DELETE up FROM `user_permissions` up LEFT JOIN `permissions` p ON up.`permission_key` = p.`permission_key` WHERE p.`permission_key` IS NULL;
DELETE pr FROM `password_resets` pr LEFT JOIN `users` u ON pr.`user_id` = u.`user_id` WHERE u.`user_id` IS NULL;

UPDATE `borrow_records` br LEFT JOIN `game_accounts` ga ON br.`account_id` = ga.`account_id` SET br.`account_id` = NULL WHERE br.`account_id` IS NOT NULL AND ga.`account_id` IS NULL;
UPDATE `borrow_records` br LEFT JOIN `rental_packages` rp ON br.`package_id` = rp.`package_id` SET br.`package_id` = NULL WHERE br.`package_id` IS NOT NULL AND rp.`package_id` IS NULL;
UPDATE `borrow_records` br LEFT JOIN `borrow_records` prev ON br.`previous_borrow_id` = prev.`borrow_id` SET br.`previous_borrow_id` = NULL WHERE br.`previous_borrow_id` IS NOT NULL AND prev.`borrow_id` IS NULL;
DELETE br FROM `borrow_records` br LEFT JOIN `users` u ON br.`user_id` = u.`user_id` WHERE u.`user_id` IS NULL;
DELETE br FROM `borrow_records` br LEFT JOIN `games` g ON br.`game_id` = g.`game_id` WHERE g.`game_id` IS NULL;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='rental_packages' AND CONSTRAINT_NAME='fk_rp_game'), 'SELECT 1', 'ALTER TABLE `rental_packages` ADD CONSTRAINT `fk_rp_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='game_accounts' AND CONSTRAINT_NAME='fk_ga_game'), 'SELECT 1', 'ALTER TABLE `game_accounts` ADD CONSTRAINT `fk_ga_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND CONSTRAINT_NAME='fk_br_user'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD CONSTRAINT `fk_br_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND CONSTRAINT_NAME='fk_br_game'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD CONSTRAINT `fk_br_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`) ON DELETE RESTRICT'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND CONSTRAINT_NAME='fk_br_account'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD CONSTRAINT `fk_br_account` FOREIGN KEY (`account_id`) REFERENCES `game_accounts` (`account_id`) ON DELETE SET NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND CONSTRAINT_NAME='fk_br_package'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD CONSTRAINT `fk_br_package` FOREIGN KEY (`package_id`) REFERENCES `rental_packages` (`package_id`) ON DELETE SET NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='borrow_records' AND CONSTRAINT_NAME='fk_br_prev'), 'SELECT 1', 'ALTER TABLE `borrow_records` ADD CONSTRAINT `fk_br_prev` FOREIGN KEY (`previous_borrow_id`) REFERENCES `borrow_records` (`borrow_id`) ON DELETE SET NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='comments' AND CONSTRAINT_NAME='fk_comments_game'), 'SELECT 1', 'ALTER TABLE `comments` ADD CONSTRAINT `fk_comments_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='comments' AND CONSTRAINT_NAME='fk_comments_user'), 'SELECT 1', 'ALTER TABLE `comments` ADD CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='notifications' AND CONSTRAINT_NAME='fk_notif_user'), 'SELECT 1', 'ALTER TABLE `notifications` ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='support_tickets' AND CONSTRAINT_NAME='fk_st_user'), 'SELECT 1', 'ALTER TABLE `support_tickets` ADD CONSTRAINT `fk_st_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='support_messages' AND CONSTRAINT_NAME='fk_sm_ticket'), 'SELECT 1', 'ALTER TABLE `support_messages` ADD CONSTRAINT `fk_sm_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`ticket_id`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='support_attachments' AND CONSTRAINT_NAME='fk_sa_ticket'), 'SELECT 1', 'ALTER TABLE `support_attachments` ADD CONSTRAINT `fk_sa_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`ticket_id`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='user_permissions' AND CONSTRAINT_NAME='fk_up_user'), 'SELECT 1', 'ALTER TABLE `user_permissions` ADD CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='user_permissions' AND CONSTRAINT_NAME='fk_up_perm'), 'SELECT 1', 'ALTER TABLE `user_permissions` ADD CONSTRAINT `fk_up_perm` FOREIGN KEY (`permission_key`) REFERENCES `permissions` (`permission_key`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND TABLE_NAME='password_resets' AND CONSTRAINT_NAME='fk_pr_user'), 'SELECT 1', 'ALTER TABLE `password_resets` ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
