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
 * Fallback thumbnail dạng SVG inline (không phát sinh HTTP 404 nếu thiếu file ảnh)
 */
function anplay_fallback_thumb_data_uri(): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 90">'
         . '<rect width="160" height="90" fill="#131726"/>'
         . '<rect x="1" y="1" width="158" height="88" rx="8" fill="none" stroke="#334155" stroke-opacity="0.6"/>'
         . '<path d="M62 35h36a14 14 0 0 1 13.4 17.9l-2.6 8.8A7 7 0 0 1 96 63l-5.6-6.2a4 4 0 0 0-3-1.3H72.6a4 4 0 0 0-3 1.3L64 63a7 7 0 0 1-12.8-1.3l-2.6-8.8A14 14 0 0 1 62 35Zm4 8v6m-3-3h6m17-1.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm7 3.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z" fill="none" stroke="#818cf8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>'
         . '</svg>';
    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
}

/**
 * Kiểm tra đường dẫn ảnh bìa game (local hoặc URL ngoài) và phân tích tỷ lệ khung hình
 */
function anplay_resolve_game_image(?string $imageUrl): array {
    static $cache = [];
    $clean = str_replace('\\', '/', trim((string)$imageUrl));
    if ($clean === '' || strpos($clean, '..') !== false || strpos($clean, "\0") !== false) {
        return ['exists' => false, 'src' => '', 'is_contained' => false, 'width' => 0, 'height' => 0];
    }
    if (isset($cache[$clean])) {
        return $cache[$clean];
    }

    if (preg_match('#^https?://#i', $clean)) {
        $result = ['exists' => true, 'src' => $clean, 'is_contained' => false, 'width' => 0, 'height' => 0];
        $cache[$clean] = $result;
        return $result;
    }

    $rel = ltrim($clean, '/');
    $fsPath = __DIR__ . '/../' . $rel;
    if (!is_file($fsPath)) {
        $result = ['exists' => false, 'src' => '', 'is_contained' => false, 'width' => 0, 'height' => 0];
        $cache[$clean] = $result;
        return $result;
    }

    $info = @getimagesize($fsPath);
    $w = is_array($info) ? intval($info[0] ?? 0) : 0;
    $h = is_array($info) ? intval($info[1] ?? 0) : 0;
    $isContained = ($w > 0 && $h > 0) ? (($w / $h) < 1.2 || ($w / $h) > 2.4) : false;

    $result = ['exists' => true, 'src' => $rel, 'is_contained' => $isContained, 'width' => $w, 'height' => $h];
    $cache[$clean] = $result;
    return $result;
}

/**
 * Render khối ảnh bìa game tự động cân chỉnh (landscape phủ full, portrait/vuông/siêu rộng căn giữa trên nền mờ)
 */
function render_game_cover_media(?string $imageUrl, string $title, array $opts = []): string {
    $meta = anplay_resolve_game_image($imageUrl);
    $extraImgClass = trim((string)($opts['img_class'] ?? ''));
    $loading = (($opts['loading'] ?? 'lazy') === 'eager') ? 'eager' : 'lazy';
    $placeholderIcon = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($opts['placeholder_icon'] ?? 'bi-controller')) ?: 'bi-controller';
    $placeholderText = trim((string)($opts['placeholder_text'] ?? 'ANPLAY GAME'));
    $safeTitle = htmlspecialchars($title !== '' ? $title : 'ANPLAY Game', ENT_QUOTES, 'UTF-8');

    $frameClasses = 'anplay-cover-media';
    if ($meta['exists'] && $meta['is_contained']) {
        $frameClasses .= ' is-contained';
    } elseif (!$meta['exists']) {
        $frameClasses .= ' is-missing';
    }

    $fgClasses = trim('anplay-cover-fg ' . $extraImgClass);
    $html = '<div class="' . $frameClasses . '">';
    if ($meta['exists']) {
        $safeSrc = htmlspecialchars($meta['src'], ENT_QUOTES, 'UTF-8');
        $html .= '<img src="' . $safeSrc . '" class="anplay-cover-bg" alt="" aria-hidden="true" loading="' . $loading . '" decoding="async">';
        $html .= '<img src="' . $safeSrc . '" class="' . htmlspecialchars($fgClasses, ENT_QUOTES, 'UTF-8') . '" alt="' . $safeTitle . '" loading="' . $loading . '" decoding="async" onload="window.anplayFitCover && window.anplayFitCover(this)" onerror="window.anplayImgError && window.anplayImgError(this)">';
    }
    $html .= '<div class="anplay-cover-fallback">'
           . '<i class="bi ' . htmlspecialchars($placeholderIcon, ENT_QUOTES, 'UTF-8') . ' fs-1"></i>'
           . ($placeholderText !== '' ? '<span class="small fw-bold">' . htmlspecialchars($placeholderText, ENT_QUOTES, 'UTF-8') . '</span>' : '')
           . '</div>';
    $html .= '</div>';
    return $html;
}

/**
 * Render thumbnail game nhỏ đồng nhất (bảng admin, profile, ticket hỗ trợ) có fallback an toàn
 */
function render_game_thumb(?string $imageUrl, string $title = '', string $class = 'anplay-thumb', string $style = 'width: 50px; height: 35px;'): string {
    $meta = anplay_resolve_game_image($imageUrl);
    $fallback = htmlspecialchars(anplay_fallback_thumb_data_uri(), ENT_QUOTES, 'UTF-8');
    $src = $meta['exists'] ? htmlspecialchars($meta['src'], ENT_QUOTES, 'UTF-8') : $fallback;
    $alt = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    return '<img src="' . $src . '" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" loading="lazy" onerror="window.anplayImgError && window.anplayImgError(this)">';
}

/**
 * Script toàn cục tự động cân tỷ lệ ảnh bìa và xử lý lỗi tải ảnh cho mọi game hiện tại & tương lai
 */
function render_game_cover_script(): string {
    $fallback = json_encode(anplay_fallback_thumb_data_uri(), JSON_UNESCAPED_SLASHES);
    return '<script>(function(){'
        . 'var FB=' . $fallback . ';'
        . 'window.anplayFitCover=function(img){'
        . 'if(!img||!img.naturalWidth||!img.naturalHeight)return;'
        . 'var f=img.closest(".anplay-cover-media");if(!f)return;'
        . 'var r=img.naturalWidth/img.naturalHeight;'
        . 'var c=(r<1.2||r>2.4);'
        . 'f.classList.toggle("is-contained",c);'
        . 'var bg=f.querySelector(".anplay-cover-bg");'
        . 'if(c&&bg&&img.getAttribute("src")&&bg.getAttribute("src")!==img.getAttribute("src")){bg.setAttribute("src",img.getAttribute("src"));}'
        . '};'
        . 'window.anplayImgError=function(img){'
        . 'if(!img)return;'
        . 'var f=img.closest(".anplay-cover-media");'
        . 'if(f){f.classList.add("is-missing");return;}'
        . 'if(!img.dataset.anplayFb){img.dataset.anplayFb="1";img.src=FB;}'
        . '};'
        . 'document.addEventListener("DOMContentLoaded",function(){'
        . 'document.querySelectorAll(".anplay-cover-fg").forEach(function(img){'
        . 'if(img.complete){if(img.naturalWidth>0)window.anplayFitCover(img);else window.anplayImgError(img);}'
        . '});'
        . '});'
        . '})();</script>';
}

/**
 * Widget xem trước ảnh bìa 16:9 cho form thêm/sửa game trong Admin
 */
function render_admin_cover_preview_widget(string $inputId, ?string $currentImageUrl = ''): string {
    $meta = anplay_resolve_game_image($currentImageUrl);
    $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', $inputId) ?: 'gameCoverInput';
    $previewHtml = render_game_cover_media($meta['exists'] ? $meta['src'] : '', 'Xem trước ảnh bìa', ['loading' => 'eager', 'placeholder_text' => 'CHƯA CHỌN ẢNH']);
    $initInfo = $meta['exists'] && $meta['width'] > 0
        ? ($meta['width'] . '×' . $meta['height'] . 'px — ' . ($meta['is_contained'] ? 'Tỷ lệ dọc/vuông (tự căn giữa trên nền mờ)' : 'Tỷ lệ ngang 16:9 chuẩn'))
        : 'Hỗ trợ JPG, PNG, WEBP (tối đa 5MB). Mọi kích thước sẽ tự động căn chuẩn khung 16:9.';

    return '<div class="mt-2">'
        . '<div id="' . $uid . '_box" class="rounded-3 border overflow-hidden position-relative" style="max-width: 280px; aspect-ratio: 16/9; border-color: var(--border-color, rgba(255,255,255,0.12)) !important;">'
        . $previewHtml
        . '</div>'
        . '<div id="' . $uid . '_meta" class="small text-secondary mt-1">' . htmlspecialchars($initInfo, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<script>(function(){'
        . 'var inp=document.getElementById(' . json_encode($uid) . ');'
        . 'var box=document.getElementById(' . json_encode($uid . '_box') . ');'
        . 'var meta=document.getElementById(' . json_encode($uid . '_meta') . ');'
        . 'if(!inp||!box||!meta)return;'
        . 'var lastUrl="";'
        . 'inp.addEventListener("change",function(){'
        . 'var file=inp.files&&inp.files[0];'
        . 'if(!file)return;'
        . 'if(!/^image\/(jpeg|png|webp)$/i.test(file.type)&&!/\.(jpe?g|png|webp)$/i.test(file.name)){'
        . 'meta.className="small text-danger mt-1";meta.textContent="Chỉ hỗ trợ định dạng JPG, JPEG, PNG hoặc WEBP.";return;}'
        . 'if(file.size>5*1024*1024){'
        . 'meta.className="small text-danger mt-1";meta.textContent="Dung lượng vượt quá 5MB, vui lòng chọn ảnh nhẹ hơn.";return;}'
        . 'if(lastUrl)URL.revokeObjectURL(lastUrl);'
        . 'lastUrl=URL.createObjectURL(file);'
        . 'var frame=box.querySelector(".anplay-cover-media");'
        . 'if(!frame)return;'
        . 'frame.classList.remove("is-missing");'
        . 'var bg=frame.querySelector(".anplay-cover-bg");'
        . 'var fg=frame.querySelector(".anplay-cover-fg");'
        . 'if(!bg){bg=document.createElement("img");bg.className="anplay-cover-bg";bg.alt="";bg.setAttribute("aria-hidden","true");frame.insertBefore(bg,frame.firstChild);}'
        . 'if(!fg){fg=document.createElement("img");fg.className="anplay-cover-fg";fg.alt="Preview";frame.insertBefore(fg,frame.querySelector(".anplay-cover-fallback"));}'
        . 'fg.onload=function(){window.anplayFitCover&&window.anplayFitCover(fg);var c=frame.classList.contains("is-contained");meta.className="small text-info mt-1";meta.textContent=fg.naturalWidth+"×"+fg.naturalHeight+"px — "+(c?"Ảnh dọc/vuông: tự động căn giữa trên nền mờ":"Ảnh ngang: phủ khít khung 16:9");};'
        . 'bg.src=lastUrl;fg.src=lastUrl;'
        . '});'
        . '})();</script>'
        . '</div>';
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
