<?php
/**
 * ANPLAY STORE - ZERO-DEPENDENCY NATIVE PHP SMTP CLIENT
 * Hỗ trợ SSL (port 465) và STARTTLS (port 587) với AUTH LOGIN.
 */

if (!defined('ANPLAY_INTERNAL')) {
    define('ANPLAY_INTERNAL', true);
}

if (!function_exists('get_anplay_smtp_config')) {
    /**
     * Nạp cấu hình SMTP từ includes/secrets.local.php hoặc biến môi trường.
     */
    function get_anplay_smtp_config(): array
    {
        global $anplay_secrets;

        $secrets = is_array($anplay_secrets ?? null) ? $anplay_secrets : [];
        if (empty($secrets)) {
            $secrets_file = __DIR__ . '/secrets.local.php';
            if (file_exists($secrets_file)) {
                $loaded = require $secrets_file;
                if (is_array($loaded)) {
                    $secrets = $loaded;
                }
            }
        }

        $smtp = is_array($secrets['smtp'] ?? null) ? $secrets['smtp'] : [];

        $host = trim((string)($smtp['host'] ?? getenv('ANPLAY_SMTP_HOST') ?: ''));
        $port = intval($smtp['port'] ?? getenv('ANPLAY_SMTP_PORT') ?: 587);
        $encryption = strtolower(trim((string)($smtp['encryption'] ?? getenv('ANPLAY_SMTP_ENCRYPTION') ?: ($port === 465 ? 'ssl' : 'tls'))));

        return [
            'host'       => $host,
            'port'       => $port > 0 ? $port : 587,
            'username'   => (string)($smtp['username'] ?? getenv('ANPLAY_SMTP_USER') ?: ''),
            'password'   => (string)($smtp['password'] ?? getenv('ANPLAY_SMTP_PASS') ?: ''),
            'from_email' => trim((string)($smtp['from_email'] ?? getenv('ANPLAY_SMTP_FROM_EMAIL') ?: '')),
            'from_name'  => trim((string)($smtp['from_name'] ?? getenv('ANPLAY_SMTP_FROM_NAME') ?: 'ANPLAY STORE')),
            'encryption' => in_array($encryption, ['ssl', 'tls', 'none'], true) ? $encryption : 'tls',
        ];
    }
}

if (!function_exists('anplay_smtp_read_response')) {
    /**
     * Đọc phản hồi nhiều dòng từ SMTP server và trả về [code, text].
     */
    function anplay_smtp_read_response($fp): array
    {
        $response = '';
        $code = 0;

        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (preg_match('/^(\d{3})([\s-])/', $line, $m)) {
                $code = intval($m[1]);
                if ($m[2] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }

        return ['code' => $code, 'message' => trim($response)];
    }
}

if (!function_exists('anplay_smtp_command')) {
    /**
     * Gửi lệnh SMTP và kiểm tra mã phản hồi kỳ vọng.
     */
    function anplay_smtp_command($fp, string $command, array $expected_codes): array
    {
        fwrite($fp, $command . "\r\n");
        $resp = anplay_smtp_read_response($fp);
        if (!in_array($resp['code'], $expected_codes, true)) {
            return ['ok' => false, 'error' => "SMTP error ({$resp['code']}): {$resp['message']}"];
        }
        return ['ok' => true, 'resp' => $resp];
    }
}

if (!function_exists('send_anplay_email')) {
    /**
     * Gửi email HTML qua SMTP (SSL 465 / STARTTLS 587).
     */
    function send_anplay_email(string $to_email, string $subject, string $html_body): array
    {
        $to_email = trim(str_replace(["\r", "\n"], '', $to_email));
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));

        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Địa chỉ email người nhận không hợp lệ.'];
        }

        $cfg = get_anplay_smtp_config();
        if ($cfg['host'] === '' || strpos($cfg['host'], 'CHANGE_ME') !== false) {
            return ['success' => false, 'error' => 'SMTP chưa được cấu hình.'];
        }

        $from_email = $cfg['from_email'] !== '' ? $cfg['from_email'] : $cfg['username'];
        $from_email = trim(str_replace(["\r", "\n"], '', $from_email));
        if (!filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Địa chỉ email người gửi SMTP không hợp lệ.'];
        }
        $from_name = trim(str_replace(["\r", "\n"], '', $cfg['from_name']));

        $transport = ($cfg['encryption'] === 'ssl') ? 'ssl://' : 'tcp://';
        $remote = $transport . $cfg['host'] . ':' . $cfg['port'];

        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return ['success' => false, 'error' => "Không thể kết nối SMTP ({$errno}): {$errstr}"];
        }

        stream_set_timeout($fp, 15);

        try {
            $greet = anplay_smtp_read_response($fp);
            if ($greet['code'] !== 220) {
                throw new RuntimeException("SMTP greeting failed ({$greet['code']}): {$greet['message']}");
            }

            $ehlo_host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost') ?: 'localhost';
            $res = anplay_smtp_command($fp, 'EHLO ' . $ehlo_host, [250]);
            if (!$res['ok']) {
                throw new RuntimeException($res['error']);
            }

            if ($cfg['encryption'] === 'tls') {
                $res = anplay_smtp_command($fp, 'STARTTLS', [220]);
                if (!$res['ok']) {
                    throw new RuntimeException($res['error']);
                }
                $crypto_method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (!@stream_socket_enable_crypto($fp, true, $crypto_method)) {
                    throw new RuntimeException('Không thể thiết lập kết nối STARTTLS.');
                }
                $res = anplay_smtp_command($fp, 'EHLO ' . $ehlo_host, [250]);
                if (!$res['ok']) {
                    throw new RuntimeException($res['error']);
                }
            }

            if ($cfg['username'] !== '') {
                $res = anplay_smtp_command($fp, 'AUTH LOGIN', [334]);
                if (!$res['ok']) {
                    throw new RuntimeException($res['error']);
                }
                $res = anplay_smtp_command($fp, base64_encode($cfg['username']), [334]);
                if (!$res['ok']) {
                    throw new RuntimeException($res['error']);
                }
                $res = anplay_smtp_command($fp, base64_encode($cfg['password']), [235]);
                if (!$res['ok']) {
                    throw new RuntimeException($res['error']);
                }
            }

            $res = anplay_smtp_command($fp, 'MAIL FROM:<' . $from_email . '>', [250]);
            if (!$res['ok']) {
                throw new RuntimeException($res['error']);
            }

            $res = anplay_smtp_command($fp, 'RCPT TO:<' . $to_email . '>', [250, 251]);
            if (!$res['ok']) {
                throw new RuntimeException($res['error']);
            }

            $res = anplay_smtp_command($fp, 'DATA', [354]);
            if (!$res['ok']) {
                throw new RuntimeException($res['error']);
            }

            $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encoded_from_name = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
            $message_id = '<' . bin2hex(random_bytes(12)) . '@' . $ehlo_host . '>';

            $headers = [
                'Date: ' . date('r'),
                'Message-ID: ' . $message_id,
                'From: ' . $encoded_from_name . ' <' . $from_email . '>',
                'To: <' . $to_email . '>',
                'Subject: ' . $encoded_subject,
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];

            $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($html_body)) . "\r\n.";
            fwrite($fp, $payload . "\r\n");

            $data_resp = anplay_smtp_read_response($fp);
            if ($data_resp['code'] !== 250) {
                throw new RuntimeException("SMTP DATA rejected ({$data_resp['code']}): {$data_resp['message']}");
            }

            @fwrite($fp, "QUIT\r\n");
            fclose($fp);
            return ['success' => true, 'error' => ''];
        } catch (Throwable $e) {
            if (is_resource($fp)) {
                @fclose($fp);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('send_password_reset_email')) {
    /**
     * Gửi email chứa liên kết đặt lại mật khẩu (hiệu lực 15 phút).
     */
    function send_password_reset_email(string $to_email, string $raw_token): array
    {
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $is_https ? 'https' : 'http';
        $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost') ?: 'localhost';
        $base_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $reset_url = $scheme . '://' . $host . $base_path . '/forgot_password.php?token=' . urlencode($raw_token);
        $safe_url = htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8');

        $html_body = '
        <div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; background: #0f172a; color: #f8fafc; padding: 28px; border-radius: 12px;">
            <h2 style="color: #38bdf8; margin-top: 0;">ANPLAY STORE — Khôi phục mật khẩu</h2>
            <p style="color: #cbd5e1; line-height: 1.6;">
                Chúng tôi đã nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn. Liên kết bên dưới có hiệu lực trong <strong>15 phút</strong>:
            </p>
            <p style="margin: 24px 0; text-align: center;">
                <a href="' . $safe_url . '" style="display: inline-block; padding: 12px 24px; background: #6366f1; color: #ffffff; text-decoration: none; font-weight: bold; border-radius: 8px;">
                    Đặt Lại Mật Khẩu Ngay
                </a>
            </p>
            <p style="color: #94a3b8; font-size: 12px; word-break: break-all;">
                Nếu nút không hoạt động, sao chép liên kết sau vào trình duyệt:<br>' . $safe_url . '
            </p>
            <hr style="border: none; border-top: 1px solid #1e293b; margin: 20px 0;">
            <p style="color: #64748b; font-size: 12px; margin-bottom: 0;">
                Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email này. Mật khẩu hiện tại của bạn vẫn an toàn.
            </p>
        </div>';

        return send_anplay_email($to_email, '[ANPLAY STORE] Khôi phục mật khẩu tài khoản', $html_body);
    }
}
