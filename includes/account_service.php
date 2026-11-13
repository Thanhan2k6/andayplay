<?php
// =========================================================================
// THƯ VIỆN BẢO MẬT VÀ QUẢN LÝ DỊCH VỤ CHO THUÊ TÀI KHOẢN GAME
// =========================================================================

if (!defined('ANPLAY_INTERNAL')) {
    define('ANPLAY_INTERNAL', true);
}

$__anplay_secrets = [];
$__anplay_secrets_file = __DIR__ . '/secrets.local.php';
if (file_exists($__anplay_secrets_file)) {
    $loaded = require $__anplay_secrets_file;
    if (is_array($loaded)) {
        $__anplay_secrets = $loaded;
    }
}

if (!defined('APP_SECURITY_KEY_CURRENT')) {
    $env_key = getenv('APP_SECURITY_KEY_CURRENT') ?: getenv('GAME_ACCOUNT_ENCRYPTION_KEY');
    $sec_key = $__anplay_secrets['app_security_key_current'] ?? '';
    define('APP_SECURITY_KEY_CURRENT', ($env_key && strlen($env_key) >= 16) ? $env_key : $sec_key);
}

if (!defined('APP_SECURITY_KEY_OLD')) {
    $env_old = getenv('APP_SECURITY_KEY_OLD');
    $sec_old = $__anplay_secrets['app_security_key_old'] ?? '';
    define('APP_SECURITY_KEY_OLD', $env_old !== false && $env_old !== '' ? $env_old : $sec_old);
}

if (!defined('APP_SECURITY_KEY')) {
    define('APP_SECURITY_KEY', APP_SECURITY_KEY_CURRENT);
}

if (!defined('ANPLAY_CRON_SECRET')) {
    $env_cron = getenv('ANPLAY_CRON_SECRET');
    $sec_cron = $__anplay_secrets['cron_secret'] ?? '';
    define('ANPLAY_CRON_SECRET', !empty($env_cron) ? $env_cron : $sec_cron);
}

if (!defined('PAYMENT_WEBHOOK_SECRET')) {
    $env_wh = getenv('PAYMENT_WEBHOOK_SECRET');
    $sec_wh = $__anplay_secrets['payment_webhook_secret'] ?? '';
    define('PAYMENT_WEBHOOK_SECRET', !empty($env_wh) ? $env_wh : $sec_wh);
}

/**
 * Mã hóa mật khẩu tài khoản game (v2: AES-256-CBC + HMAC-SHA256)
 */
function encrypt_game_password($plaintext) {
    if ($plaintext === '' || $plaintext === null) return '';
    if (empty(APP_SECURITY_KEY_CURRENT)) {
        throw new RuntimeException('APP_SECURITY_KEY_CURRENT is not configured.');
    }
    $cipher = 'AES-256-CBC';
    $encKey = hash_hmac('sha256', 'anplay_enc_v2', APP_SECURITY_KEY_CURRENT, true);
    $macKey = hash_hmac('sha256', 'anplay_mac_v2', APP_SECURITY_KEY_CURRENT, true);
    $ivlen = openssl_cipher_iv_length($cipher);
    $iv = random_bytes($ivlen);
    $ciphertext_raw = openssl_encrypt($plaintext, $cipher, $encKey, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext_raw === false) {
        throw new RuntimeException('OpenSSL encryption failed.');
    }
    $hmac = hash_hmac('sha256', $iv . $ciphertext_raw, $macKey, true);
    return 'v2:' . base64_encode($iv . $hmac . $ciphertext_raw);
}

/**
 * Giải mã mật khẩu tài khoản game (hỗ trợ Dual-Key: v2 authenticated & legacy v1)
 */
function decrypt_game_password($stored) {
    if (empty($stored) || !is_string($stored)) return '';
    $cipher = 'AES-256-CBC';
    $ivlen = openssl_cipher_iv_length($cipher);

    // Format v2: v2:<base64(iv[16] . hmac[32] . ciphertext)>
    if (strpos($stored, 'v2:') === 0) {
        if (empty(APP_SECURITY_KEY_CURRENT)) return '';
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) <= ($ivlen + 32)) return '';
        $iv = substr($raw, 0, $ivlen);
        $hmac = substr($raw, $ivlen, 32);
        $ciphertext_raw = substr($raw, $ivlen + 32);

        $macKey = hash_hmac('sha256', 'anplay_mac_v2', APP_SECURITY_KEY_CURRENT, true);
        $calcMac = hash_hmac('sha256', $iv . $ciphertext_raw, $macKey, true);
        if (!hash_equals($calcMac, $hmac)) {
            return '';
        }
        $encKey = hash_hmac('sha256', 'anplay_enc_v2', APP_SECURITY_KEY_CURRENT, true);
        $pt = openssl_decrypt($ciphertext_raw, $cipher, $encKey, OPENSSL_RAW_DATA, $iv);
        return $pt !== false ? $pt : '';
    }

    // Legacy v1 format: base64(iv[16] . ciphertext) encrypted with APP_SECURITY_KEY_OLD (or CURRENT)
    $c = base64_decode($stored, true);
    if ($c === false || strlen($c) <= $ivlen) return '';
    $iv = substr($c, 0, $ivlen);
    $ciphertext_raw = substr($c, $ivlen);

    $candidateKeys = array_filter([APP_SECURITY_KEY_OLD, APP_SECURITY_KEY_CURRENT], function($k) {
        return !empty($k);
    });
    foreach ($candidateKeys as $candidate) {
        $key = hash('sha256', $candidate, true);
        $pt = openssl_decrypt($ciphertext_raw, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($pt !== false && $pt !== '') {
            return $pt;
        }
    }
    return '';
}

/**
 * Tải lên ảnh bìa game an toàn (Whitelist extension, MIME finfo, getimagesize, max 5MB)
 */
function upload_game_image(array $file, string $prefix = 'game'): array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'path' => '', 'error' => 'Lỗi tải file ảnh lên máy chủ.'];
    }
    $maxSize = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxSize) {
        return ['success' => false, 'path' => '', 'error' => 'Dung lượng ảnh không hợp lệ (tối đa 5MB).'];
    }
    $origName = basename($file['name'] ?? '');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExt, true)) {
        return ['success' => false, 'path' => '', 'error' => 'Chỉ chấp nhận ảnh định dạng JPG, JPEG, PNG hoặc WEBP.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($realMime, $allowedMimes, true)) {
        return ['success' => false, 'path' => '', 'error' => 'Định dạng MIME ảnh không hợp lệ.'];
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return ['success' => false, 'path' => '', 'error' => 'Tệp tin không phải là hình ảnh hợp lệ.'];
    }

    $targetDir = __DIR__ . '/../uploads';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix) ?: 'game';
    $safeName = $safePrefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $targetPath = $targetDir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'path' => '', 'error' => 'Không thể lưu file ảnh vào thư mục uploads.'];
    }
    return ['success' => true, 'path' => 'uploads/' . $safeName, 'error' => ''];
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
