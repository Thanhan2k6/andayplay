-- =========================================================================
-- MIGRATION: NÂNG CẤP HỆ THỐNG QUẢN LÝ THUÊ TÀI KHOẢN GAME TRẢ PHÍ THEO THỜI HẠN
-- =========================================================================

-- 1. BẢNG GÓI THỜI HẠN THUÊ GAME (rental_packages)
CREATE TABLE IF NOT EXISTS rental_packages (
    package_id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    package_name VARCHAR(100) NOT NULL,
    duration_type ENUM('hours', 'days') NOT NULL DEFAULT 'days',
    duration_value INT NOT NULL, -- Ví dụ: 1 (giờ), 1 (ngày), 7 (ngày), 30 (ngày)
    price DECIMAL(12, 2) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_game_id (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. BẢNG KHO TÀI KHOẢN GAME (game_accounts)
-- Trạng thái: AVAILABLE, RESERVED, RENTED, EXPIRED, MAINTENANCE, DISABLED
CREATE TABLE IF NOT EXISTS game_accounts (
    account_id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    account_username VARCHAR(150) NOT NULL,
    account_password_encrypted TEXT NOT NULL,
    server_region VARCHAR(50) DEFAULT 'Global',
    status ENUM('AVAILABLE', 'RESERVED', 'RENTED', 'EXPIRED', 'MAINTENANCE', 'DISABLED') NOT NULL DEFAULT 'AVAILABLE',
    current_borrow_id INT NULL,
    rent_started_at DATETIME NULL,
    rent_expires_at DATETIME NULL,
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_game_status (game_id, status),
    KEY idx_current_borrow (current_borrow_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. CẬP NHẬT HOẶC MỞ RỘNG BẢNG ĐƠN THUÊ (borrow_records)
-- Thêm các trường hỗ trợ thuê tài khoản, mã đơn, gói thuê, tài khoản cấp, giá, lịch sử
ALTER TABLE borrow_records ADD COLUMN order_code VARCHAR(50) NULL AFTER borrow_id;
ALTER TABLE borrow_records ADD COLUMN account_id INT NULL AFTER game_id;
ALTER TABLE borrow_records ADD COLUMN package_id INT NULL AFTER account_id;
ALTER TABLE borrow_records ADD COLUMN duration_hours INT NOT NULL DEFAULT 168 AFTER package_id;
ALTER TABLE borrow_records ADD COLUMN rental_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00 AFTER duration_hours;
ALTER TABLE borrow_records ADD COLUMN payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending' AFTER rental_price;
ALTER TABLE borrow_records ADD COLUMN payment_method VARCHAR(50) NULL AFTER payment_status;
ALTER TABLE borrow_records ADD COLUMN rent_type ENUM('new', 'extend', 're-rent') DEFAULT 'new' AFTER payment_method;
ALTER TABLE borrow_records ADD COLUMN previous_borrow_id INT NULL AFTER rent_type;
ALTER TABLE borrow_records ADD COLUMN revoke_status ENUM('none', 'pending', 'revoked', 'failed') DEFAULT 'none' AFTER status;
ALTER TABLE borrow_records ADD COLUMN error_message TEXT NULL AFTER revoke_status;

-- Cập nhật cột status của borrow_records để chuẩn hóa
-- active: Đang thuê hiệu lực
-- expired: Đã hết hạn
-- returned: Đã thu hồi/hoàn tất
-- pending: Đang chờ thanh toán/cấp tài khoản
-- cancelled: Bị hủy

-- 4. BẢNG LỊCH SỬ CẤP PHÁT & THU HỒI TÀI KHOẢN (account_allocation_logs)
CREATE TABLE IF NOT EXISTS account_allocation_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    borrow_id INT NULL,
    user_id INT NOT NULL,
    action ENUM('ALLOCATED', 'EXTENDED', 'EXPIRED_REVOKED', 'MANUAL_REVOKED', 'PASSWORD_CHANGED', 'MAINTENANCE_TRIGGERED') NOT NULL,
    details TEXT NULL,
    performed_by VARCHAR(50) DEFAULT 'SYSTEM', -- 'SYSTEM' hoặc 'ADMIN: username'
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_account_id (account_id),
    KEY idx_borrow_id (borrow_id),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. BẢNG HÀNG ĐỢI TÁC VỤ TỰ ĐỘNG (BACKGROUND TASK QUEUE)
CREATE TABLE IF NOT EXISTS system_tasks_queue (
    task_id INT AUTO_INCREMENT PRIMARY KEY,
    task_type ENUM('EXPIRE_ACCOUNT', 'REVOKE_SESSION', 'CHANGE_PASSWORD', 'NOTIFY_USER') NOT NULL,
    target_id INT NOT NULL, -- borrow_id hoặc account_id
    payload JSON NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    error_log TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    KEY idx_status_task (status, task_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. TẠO CÁC GÓI THỜI HẠN MẪU CHO TẤT CẢ GAME TRẢ PHÍ HIỆN CÓ NẾU CHƯA CÓ
INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price)
SELECT g.game_id, '1 Giờ (Trải nghiệm nhanh)', 'hours', 1, 5000
FROM games g
WHERE g.price_type = 'paid'
AND NOT EXISTS (SELECT 1 FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.duration_value = 1 AND rp.duration_type = 'hours');

INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price)
SELECT g.game_id, '1 Ngày (24 Giờ)', 'days', 1, 15000
FROM games g
WHERE g.price_type = 'paid'
AND NOT EXISTS (SELECT 1 FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.duration_value = 1 AND rp.duration_type = 'days');

INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price)
SELECT g.game_id, '7 Ngày (1 Tuần)', 'days', 7, 30000
FROM games g
WHERE g.price_type = 'paid'
AND NOT EXISTS (SELECT 1 FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.duration_value = 7 AND rp.duration_type = 'days');

INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price)
SELECT g.game_id, '30 Ngày (1 Tháng)', 'days', 30, 99000
FROM games g
WHERE g.price_type = 'paid'
AND NOT EXISTS (SELECT 1 FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.duration_value = 30 AND rp.duration_type = 'days');

