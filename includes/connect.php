<?php
// =========================================================
// HỆ THỐNG KẾT NỐI DATABASE THÔNG MINH
// =========================================================

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

if (!defined('ANPLAY_INTERNAL')) {
    define('ANPLAY_INTERNAL', true);
}

// Nạp cấu hình bí mật cục bộ (gitignored) nếu tồn tại
$anplay_secrets = [];
$secrets_file = __DIR__ . '/secrets.local.php';
if (file_exists($secrets_file)) {
    $loaded_secrets = require $secrets_file;
    if (is_array($loaded_secrets)) {
        $anplay_secrets = $loaded_secrets;
    }
}

if (!function_exists('is_local_environment')) {
    function is_local_environment() {
        $http_host = $_SERVER['HTTP_HOST'] ?? '';
        $is_prod_domain = (
            strpos($http_host, 'free.nf') !== false ||
            strpos($http_host, 'infinityfree') !== false ||
            strpos($http_host, 'anplaystore') !== false
        );
        if (defined('FORCE_PROD_DB') && FORCE_PROD_DB) {
            return false;
        }
        if (defined('FORCE_LOCAL_DB') && FORCE_LOCAL_DB) {
            return true;
        }
        if ($is_prod_domain) {
            return false;
        }
        return (
            php_sapi_name() === 'cli' ||
            empty($http_host) ||
            strpos($http_host, 'localhost') !== false ||
            strpos($http_host, '127.0.0.1') !== false
        );
    }
}

$is_local = is_local_environment();

if ($is_local) {
    $local_cfg = is_array($anplay_secrets['db_local'] ?? null) ? $anplay_secrets['db_local'] : [];
    $host = $local_cfg['host'] ?? ($anplay_secrets['db_host'] ?? (getenv('ANPLAY_DB_HOST') ?: 'localhost'));
    $user = $local_cfg['user'] ?? ($anplay_secrets['db_user'] ?? (getenv('ANPLAY_DB_USER') ?: 'root'));
    $pass = $local_cfg['pass'] ?? ($anplay_secrets['db_pass'] ?? (getenv('ANPLAY_DB_PASS') !== false ? getenv('ANPLAY_DB_PASS') : ''));
    $db   = $local_cfg['name'] ?? ($anplay_secrets['db_name'] ?? (getenv('ANPLAY_DB_NAME') ?: 'anplay_staging'));
} else {
    // MÔI TRƯỜNG PROD: Đọc thông số từ secrets.local.php hoặc biến môi trường
    $prod_cfg = is_array($anplay_secrets['db_prod'] ?? null) ? $anplay_secrets['db_prod'] : [];
    $host = $prod_cfg['host'] ?? ($anplay_secrets['prod_db_host'] ?? (getenv('ANPLAY_PROD_DB_HOST') ?: ''));
    $user = $prod_cfg['user'] ?? ($anplay_secrets['prod_db_user'] ?? (getenv('ANPLAY_PROD_DB_USER') ?: ''));
    $pass = $prod_cfg['pass'] ?? ($anplay_secrets['prod_db_pass'] ?? (getenv('ANPLAY_PROD_DB_PASS') ?: ''));
    $db   = $prod_cfg['name'] ?? ($anplay_secrets['prod_db_name'] ?? (getenv('ANPLAY_PROD_DB_NAME') ?: ''));
}

// Vô hiệu hóa ném ngoại lệ tự động của MySQLi trên PHP 8.1+ để tránh ngắt hiển thị HTML
mysqli_report(MYSQLI_REPORT_OFF);

$conn = null;
if (!empty($host) && !empty($user) && !empty($db)) {
    $conn = @new mysqli($host, $user, $pass, $db);
    // Fallback sang game_library_db trên local nếu anplay_staging chưa được khởi tạo
    if ($is_local && $conn->connect_error && $db === 'anplay_staging' && empty($anplay_secrets['db_name']) && !getenv('ANPLAY_DB_NAME')) {
        $fallback_conn = @new mysqli($host, $user, $pass, 'game_library_db');
        if (!$fallback_conn->connect_error) {
            $conn = $fallback_conn;
        }
    }
}

// Kiểm tra kết nối — tuyệt đối không leak thông tin nhạy cảm ra HTTP response
if (!$conn || $conn->connect_error) {
    $err_detail = $conn ? $conn->connect_error : 'Missing DB configuration';
    error_log('[ANPLAY DB ERROR] ' . $err_detail);
    if (defined('ANPLAY_CHATBOT_NO_DIE')) {
        $conn = null;
    } else {
        http_response_code(503);
        die("Hệ thống đang bảo trì kết nối cơ sở dữ liệu. Vui lòng thử lại sau.");
    }
} else {
    $conn->set_charset("utf8mb4");
}

// Nạp thư viện dịch vụ tài khoản, thông báo, hỗ trợ và phân quyền
require_once __DIR__ . '/account_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/support_service.php';
require_once __DIR__ . '/auth_service.php';

// Rate-limit helpers (fallback nếu auth_service.php chưa định nghĩa)
if (!function_exists('check_rate_limit')) {
    function check_rate_limit($conn, $action_key, $identifier, $max_attempts = 5, $window_seconds = 900) {
        if (!$conn || !($conn instanceof mysqli)) {
            return ['allowed' => true, 'remaining' => $max_attempts, 'retry_after' => 0];
        }
        try {
            $stmt = $conn->prepare("SELECT attempts, locked_until, UNIX_TIMESTAMP(updated_at) AS last_ts FROM auth_rate_limits WHERE action_key = ? AND identifier = ? LIMIT 1");
            if (!$stmt) {
                return ['allowed' => true, 'remaining' => $max_attempts, 'retry_after' => 0];
            }
            $stmt->bind_param("ss", $action_key, $identifier);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return ['allowed' => true, 'remaining' => $max_attempts, 'retry_after' => 0];
            }

            $now = time();
            if (!empty($row['locked_until'])) {
                $locked_ts = strtotime($row['locked_until']);
                if ($locked_ts > $now) {
                    return ['allowed' => false, 'remaining' => 0, 'retry_after' => ($locked_ts - $now)];
                }
            }

            if (($now - intval($row['last_ts'])) > $window_seconds) {
                return ['allowed' => true, 'remaining' => $max_attempts, 'retry_after' => 0];
            }

            $attempts = intval($row['attempts']);
            if ($attempts >= $max_attempts) {
                return ['allowed' => false, 'remaining' => 0, 'retry_after' => $window_seconds];
            }
            return ['allowed' => true, 'remaining' => max(0, $max_attempts - $attempts), 'retry_after' => 0];
        } catch (Throwable $e) {
            error_log('[ANPLAY RATE LIMIT] ' . $e->getMessage());
            return ['allowed' => true, 'remaining' => $max_attempts, 'retry_after' => 0];
        }
    }
}

if (!function_exists('record_failed_attempt')) {
    function record_failed_attempt($conn, $action_key, $identifier, $max_attempts = 5, $lock_seconds = 900) {
        if (!$conn || !($conn instanceof mysqli)) return;
        try {
            $stmt = $conn->prepare("SELECT id, attempts, UNIX_TIMESTAMP(updated_at) AS last_ts FROM auth_rate_limits WHERE action_key = ? AND identifier = ? LIMIT 1");
            if (!$stmt) return;
            $stmt->bind_param("ss", $action_key, $identifier);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $now = time();
            if (!$row) {
                $attempts = 1;
                $locked_until = ($attempts >= $max_attempts) ? date('Y-m-d H:i:s', $now + $lock_seconds) : null;
                $ins = $conn->prepare("INSERT INTO auth_rate_limits (action_key, identifier, attempts, locked_until, updated_at) VALUES (?, ?, ?, ?, NOW())");
                if ($ins) {
                    $ins->bind_param("ssis", $action_key, $identifier, $attempts, $locked_until);
                    $ins->execute();
                    $ins->close();
                }
            } else {
                $attempts = (($now - intval($row['last_ts'])) > $lock_seconds) ? 1 : (intval($row['attempts']) + 1);
                $locked_until = ($attempts >= $max_attempts) ? date('Y-m-d H:i:s', $now + $lock_seconds) : null;
                $upd = $conn->prepare("UPDATE auth_rate_limits SET attempts = ?, locked_until = ?, updated_at = NOW() WHERE id = ?");
                if ($upd) {
                    $rid = intval($row['id']);
                    $upd->bind_param("isi", $attempts, $locked_until, $rid);
                    $upd->execute();
                    $upd->close();
                }
            }
        } catch (Throwable $e) {
            error_log('[ANPLAY RATE LIMIT RECORD] ' . $e->getMessage());
        }
    }
}

if (!function_exists('clear_rate_limit')) {
    function clear_rate_limit($conn, $action_key, $identifier) {
        if (!$conn || !($conn instanceof mysqli)) return;
        try {
            $stmt = $conn->prepare("DELETE FROM auth_rate_limits WHERE action_key = ? AND identifier = ?");
            if ($stmt) {
                $stmt->bind_param("ss", $action_key, $identifier);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('[ANPLAY RATE LIMIT CLEAR] ' . $e->getMessage());
        }
    }
}
