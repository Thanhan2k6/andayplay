-- =========================================================================
-- ANPLAY STORE - MIGRATION V3
-- CHUYỂN TOÀN BỘ GAME SANG MÔ HÌNH CHO THUÊ TÀI KHOẢN (100% RENTAL MODEL)
-- =========================================================================

-- 1. Chuyển toàn bộ các game có price_type = 'free' (hoặc NULL, khác 'paid') sang 'paid'
UPDATE games 
SET price_type = 'paid' 
WHERE price_type != 'paid' OR price_type IS NULL;

-- 2. Đảm bảo cấu trúc bảng rental_packages tồn tại
CREATE TABLE IF NOT EXISTS rental_packages (
    package_id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    package_name VARCHAR(100) NOT NULL,
    duration_type ENUM('hours', 'days') NOT NULL DEFAULT 'days',
    duration_value INT NOT NULL,
    price DECIMAL(12, 2) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_game_id (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tạo các gói thời hạn thuê chuẩn (1 Giờ, 1 Ngày, 7 Ngày, 30 Ngày) cho tất cả các game chưa có gói
-- Gói 1: 1 Giờ (Trải nghiệm nhanh) - 5.000đ
INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
SELECT g.game_id, '1 Giờ (Trải nghiệm nhanh)', 'hours', 1, 5000, 1
FROM games g
WHERE NOT EXISTS (
    SELECT 1 FROM rental_packages rp 
    WHERE rp.game_id = g.game_id AND rp.duration_type = 'hours' AND rp.duration_value = 1
);

-- Gói 2: 1 Ngày (24 Giờ) - 15.000đ
INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
SELECT g.game_id, '1 Ngày (24 Giờ)', 'days', 1, 15000, 1
FROM games g
WHERE NOT EXISTS (
    SELECT 1 FROM rental_packages rp 
    WHERE rp.game_id = g.game_id AND rp.duration_type = 'days' AND rp.duration_value = 1
);

-- Gói 3: 7 Ngày (1 Tuần) - 30.000đ
INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
SELECT g.game_id, '7 Ngày (1 Tuần)', 'days', 7, 30000, 1
FROM games g
WHERE NOT EXISTS (
    SELECT 1 FROM rental_packages rp 
    WHERE rp.game_id = g.game_id AND rp.duration_type = 'days' AND rp.duration_value = 7
);

-- Gói 4: 30 Ngày (1 Tháng) - 99.000đ
INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
SELECT g.game_id, '30 Ngày (1 Tháng)', 'days', 30, 99000, 1
FROM games g
WHERE NOT EXISTS (
    SELECT 1 FROM rental_packages rp 
    WHERE rp.game_id = g.game_id AND rp.duration_type = 'days' AND rp.duration_value = 30
);

-- 4. Đồng bộ lại số lượng tồn kho games.quantity theo số tài khoản AVAILABLE thực tế
UPDATE games g
SET quantity = (
    SELECT COUNT(*) 
    FROM game_accounts ga 
    WHERE ga.game_id = g.game_id AND ga.status = 'AVAILABLE'
);
