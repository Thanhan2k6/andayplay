-- =========================================================================
-- ANPLAY STORE - MIGRATION V4: CRON ENGINE & EXPIRATION AUTOMATION
-- =========================================================================

-- 1. BẢNG NHẬT KÝ THỰC THI TIẾN TRÌNH CRON (cron_execution_logs)
CREATE TABLE IF NOT EXISTS `cron_execution_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME DEFAULT NULL,
  `duration_ms` INT DEFAULT 0,
  `scanned_count` INT DEFAULT 0,
  `expired_count` INT DEFAULT 0,
  `warned_count` INT DEFAULT 0,
  `extended_count` INT DEFAULT 0,
  `status` ENUM('success', 'warning', 'failed') NOT NULL DEFAULT 'success',
  `error_message` TEXT DEFAULT NULL,
  `triggered_by` VARCHAR(50) NOT NULL DEFAULT 'web_cron',
  `details_json` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_cron_status_time` (`status`, `start_time`),
  INDEX `idx_cron_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. BỔ SUNG CÁC CỘT THEO DÕI VÀ CHỐNG GỬI TRÙNG LẶP CẢNH BÁO VÀO BẢNG ĐƠN THUÊ (borrow_records)
-- (Hệ thống đã có hàm tự động kiểm tra và thêm cột an toàn trong connect.php và cron_process_expirations.php)
ALTER TABLE `borrow_records` 
  ADD COLUMN IF NOT EXISTS `warn_24h_sent` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `warn_1h_sent` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `warn_15m_sent` TINYINT(1) NOT NULL DEFAULT 0;
