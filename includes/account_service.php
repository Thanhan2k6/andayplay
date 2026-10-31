<?php
// =========================================================================
// THƯ VIỆN BẢO MẬT VÀ QUẢN LÝ DỊCH VỤ CHO THUÊ TÀI KHOẢN GAME
// =========================================================================

if (!defined('APP_SECURITY_KEY')) {
    $env_key = getenv('GAME_ACCOUNT_ENCRYPTION_KEY');
    if ($env_key && strlen($env_key) >= 16) {
        define('APP_SECURITY_KEY', $env_key);
    } else {
        define('APP_SECURITY_KEY', 'ANPLAY_SECURE_ENCRYPTION_KEY_2026_PROD');
    }
}

if (!defined('ANPLAY_CRON_SECRET')) {
    $env_cron = getenv('ANPLAY_CRON_SECRET');
    define('ANPLAY_CRON_SECRET', !empty($env_cron) ? $env_cron : 'ANPLAY_CRON_SEC_9b8e21a7df4e083c6f41');
}

if (!defined('ANPLAY_LEGACY_CRON_SECRET')) {
    define('ANPLAY_LEGACY_CRON_SECRET', 'ANPLAY_CRON_2026');
}

/**
 * Mã hóa mật khẩu tài khoản game bằng OpenSSL AES-256-CBC
 */
function encrypt_game_password($plaintext) {
    if (empty($plaintext)) return '';
    try {
        $cipher = "AES-256-CBC";
        $key = hash('sha256', APP_SECURITY_KEY, true);
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext_raw);
    } catch (Throwable $e) {
        return base64_encode($plaintext);
    }
}

/**
 * Giải mã mật khẩu tài khoản game
 */
function decrypt_game_password($ciphertext_base64) {
    if (empty($ciphertext_base64)) return '';
    try {
        $cipher = "AES-256-CBC";
        $key = hash('sha256', APP_SECURITY_KEY, true);
        $c = base64_decode($ciphertext_base64);
        $ivlen = openssl_cipher_iv_length($cipher);
        if (strlen($c) <= $ivlen) return $ciphertext_base64;
        $iv = substr($c, 0, $ivlen);
        $ciphertext_raw = substr($c, $ivlen);
        $original_plaintext = openssl_decrypt($ciphertext_raw, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        return $original_plaintext !== false ? $original_plaintext : $ciphertext_base64;
    } catch (Throwable $e) {
        return $ciphertext_base64;
    }
}

/**
 * Tạo mật khẩu mạnh ngẫu nhiên bảo mật cao (CSPRNG)
 */
function generate_strong_password($length = 14) {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

/**
 * Sinh tên đăng nhập duy nhất cho game account
 */
function generate_game_username($prefix = 'anplay_acc') {
    return $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
}

/**
 * CSRF Protection
 */
function generate_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Đồng bộ số lượng tài khoản khả dụng trong kho games.available_quantity
 */
function sync_game_inventory($conn, $game_id) {
    $game_id = intval($game_id);
    if ($game_id <= 0 || !$conn) return;
    try {
        $stmt = $conn->prepare("SELECT 
            COUNT(*) as total_acc,
            SUM(CASE WHEN status = 'AVAILABLE' THEN 1 ELSE 0 END) as available_acc
            FROM game_accounts WHERE game_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $game_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $available = intval($res['available_acc'] ?? 0);
            $conn->query("UPDATE games SET quantity = $available WHERE game_id = $game_id");
        }
    } catch (Throwable $e) {}
}

/**
 * Ghi nhật ký cấp phát / thu hồi tài khoản
 */
function log_account_allocation($conn, $account_id, $borrow_id, $user_id, $action, $details = '', $performed_by = 'SYSTEM') {
    if (!$conn) return;
    try {
        $stmt = $conn->prepare("INSERT INTO account_allocation_logs (account_id, borrow_id, user_id, action, details, performed_by, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("iiisss", $account_id, $borrow_id, $user_id, $action, $details, $performed_by);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {}
}

/**
 * Hàm phân bổ tài khoản game an toàn bằng Transaction & Lock FOR UPDATE
 */
function allocate_game_account($conn, $game_id, $user_id, $borrow_id, $duration_hours, $preferred_account_id = null) {
    if (!$conn) return ['success' => false, 'error' => 'Mất kết nối cơ sở dữ liệu.'];
    
    $conn->begin_transaction();
    try {
        $account = null;
        if ($preferred_account_id) {
            $stmt = $conn->prepare("SELECT * FROM game_accounts WHERE account_id = ? AND game_id = ? FOR UPDATE");
            if ($stmt) {
                $stmt->bind_param("ii", $preferred_account_id, $game_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $acc = $res->fetch_assoc();
                    if (in_array($acc['status'], ['AVAILABLE', 'EXPIRED'])) {
                        $account = $acc;
                    }
                }
                $stmt->close();
            }

            if (!$account) {
                $conn->rollback();
                return ['success' => false, 'error' => 'Tài khoản cũ hiện không khả dụng (đang có người thuê hoặc đang bảo trì).'];
            }
        } else {
            $stmt = $conn->prepare("SELECT * FROM game_accounts WHERE game_id = ? AND status = 'AVAILABLE' ORDER BY account_id ASC LIMIT 1 FOR UPDATE");
            if ($stmt) {
                $stmt->bind_param("i", $game_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $account = $res->fetch_assoc();
                }
                $stmt->close();
            }

            if (!$account) {
                $conn->rollback();
                return ['success' => false, 'error' => 'Rất tiếc, kho game tạm thời hết tài khoản sẵn sàng cho thuê.'];
            }
        }

        $account_id = $account['account_id'];
        $now = date('Y-m-d H:i:s');
        $due_date = date('Y-m-d H:i:s', strtotime("+$duration_hours hours"));

        // Cập nhật trạng thái tài khoản
        $acc_upd = $conn->prepare("UPDATE game_accounts SET status = 'RENTED', current_borrow_id = ?, rent_started_at = ?, rent_expires_at = ?, updated_at = NOW() WHERE account_id = ?");
        if ($acc_upd) {
            $acc_upd->bind_param("issi", $borrow_id, $now, $due_date, $account_id);
            $acc_upd->execute();
            $acc_upd->close();
        }

        // Cập nhật đơn thuê
        $bor_upd = $conn->prepare("UPDATE borrow_records SET account_id = ?, due_date = ?, status = 'active', payment_status = 'paid' WHERE borrow_id = ?");
        if ($bor_upd) {
            $bor_upd->bind_param("isi", $account_id, $due_date, $borrow_id);
            $bor_upd->execute();
            $bor_upd->close();
        }

        // Ghi log cấp phát
        log_account_allocation($conn, $account_id, $borrow_id, $user_id, 'ALLOCATED', "Cấp tài khoản cho đơn #$borrow_id, thời hạn $duration_hours giờ (đến $due_date)", 'SYSTEM');

        // Đồng bộ kho
        sync_game_inventory($conn, $game_id);

        $conn->commit();
        return ['success' => true, 'account_id' => $account_id, 'due_date' => $due_date];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => 'Lỗi hệ thống khi cấp phát tài khoản: ' . $e->getMessage()];
    }
}
