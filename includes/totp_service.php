<?php
/**
 * ANPLAY STORE — TOTP TWO-FACTOR AUTHENTICATION SERVICE (RFC 6238)
 * Framework: Native PHP, Zero dependencies, Cryptographically secure
 */

class TotpService {
    private static $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Sinh khóa bí mật Base32 ngẫu nhiên 16 ký tự (128-bit)
     */
    public static function generateSecret($length = 16) {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::$base32Chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Giải mã chuỗi Base32 thành binary
     */
    private static function base32Decode($base32) {
        $base32 = strtoupper(trim($base32));
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';
        for ($i = 0; $i < strlen($base32); $i++) {
            $val = strpos(self::$base32Chars, $base32[$i]);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $output;
    }

    /**
     * Tính mã xác thực 6 số (TOTP) tại một time slice (chu kỳ 30 giây)
     */
    public static function getCode($secret, $timeSlice = null) {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }
        $secretKey = self::base32Decode($secret);
        if (empty($secretKey)) return '000000';

        // Đóng gói số nguyên 64-bit Big-Endian (RFC 4226)
        $timePacked = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $timePacked, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncatedHash = substr($hash, $offset, 4);
        $value = unpack('N', $truncatedHash)[1] & 0x7FFFFFFF;
        return str_pad($value % 1000000, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Kiểm tra tính hợp lệ của mã 6 số (cho phép dung sai ±1 chu kỳ = 30s)
     */
    public static function verifyCode($secret, $code, $discrepancy = 1) {
        $code = trim(strval($code));
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $currentTimeSlice = floor(time() / 30);
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculated = self::getCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculated, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sinh 8 mã dự phòng (Backup Codes) dạng XXXX-XXXX
     */
    public static function generateBackupCodes($count = 8) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $c = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
            $codes[] = $c;
        }
        return $codes;
    }

    /**
     * Băm danh sách mã dự phòng để KHÔNG lưu trữ Plaintext trong CSDL
     */
    public static function hashBackupCodes(array $plainCodes) {
        $hashed = [];
        foreach ($plainCodes as $c) {
            $clean = strtoupper(str_replace(['-', ' '], '', trim($c)));
            $hashed[] = hash('sha256', $clean);
        }
        return json_encode($hashed);
    }

    /**
     * Xác thực và tiêu hủy một mã dự phòng (Single-use Backup Code)
     */
    public static function verifyAndConsumeBackupCode($conn, $userId, $inputCode) {
        $userId = intval($userId);
        $clean = strtoupper(str_replace(['-', ' '], '', trim($inputCode)));
        if (empty($clean)) return false;

        $inputHash = hash('sha256', $clean);

        $stmt = $conn->prepare("SELECT two_factor_backup_codes FROM users WHERE user_id = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res || empty($res['two_factor_backup_codes'])) return false;

        $storedHashes = json_decode($res['two_factor_backup_codes'], true);
        if (!is_array($storedHashes)) return false;

        $matchKey = false;
        foreach ($storedHashes as $k => $h) {
            if (hash_equals($h, $inputHash)) {
                $matchKey = $k;
                break;
            }
        }

        if ($matchKey !== false) {
            // Tiêu hủy mã đã dùng (Single-use)
            unset($storedHashes[$matchKey]);
            $newJson = json_encode(array_values($storedHashes));
            $upd = $conn->prepare("UPDATE users SET two_factor_backup_codes = ? WHERE user_id = ?");
            if ($upd) {
                $upd->bind_param("si", $newJson, $userId);
                $upd->execute();
                $upd->close();
            }
            return true;
        }

        return false;
    }

    /**
     * Tạo đường dẫn quét mã QR chuẩn OTPAuth
     */
    public static function getQrCodeUrl($username, $secret, $issuer = 'ANPLAY STORE') {
        $label = rawurlencode($issuer . ':' . $username);
        $otpauth = "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode($issuer);
        return "https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=10&data=" . urlencode($otpauth);
    }
}

