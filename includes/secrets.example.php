<?php
/**
 * ANPLAY STORE — TEMPLATE CẤU HÌNH BÍ MẬT (EXPOSED-SAFE EXAMPLE)
 * Sao chép file này thành `includes/secrets.local.php` (đã được .gitignore)
 * và điền giá trị thật cho môi trường tương ứng (staging / production).
 */

if (!defined('ANPLAY_INTERNAL') && !defined('ANPLAY_CHATBOT_ACCESS')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    // Database (local/staging hoặc production)
    'db_local' => [
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',
        'name' => 'anplay_staging',
    ],
    'db_prod' => [
        'host' => 'CHANGE_ME_DB_HOST',
        'user' => 'CHANGE_ME_DB_USER',
        'pass' => 'CHANGE_ME_DB_PASS',
        'name' => 'CHANGE_ME_DB_NAME',
    ],

    // Mã hóa tài khoản game (Dual-Key Rotation)
    'app_security_key_current' => 'CHANGE_ME_64_HEX_CHARS',
    'app_security_key_old'     => '',

    // Cron & Payment Webhook
    'cron_secret'            => 'CHANGE_ME_CRON_SECRET',
    'payment_webhook_secret' => 'CHANGE_ME_WEBHOOK_SECRET',

    // Gemini AI Chatbot
    'gemini_api_key' => 'CHANGE_ME_GEMINI_API_KEY',

    // SMTP Email (cho forgot_password.php trên production)
    'smtp' => [
        'host'       => 'CHANGE_ME_SMTP_HOST',       // VD: smtp.gmail.com hoặc smtp-relay.brevo.com
        'port'       => 587,                         // 587 (STARTTLS) hoặc 465 (SSL)
        'encryption' => 'tls',                       // 'tls', 'ssl', hoặc 'none'
        'username'   => 'CHANGE_ME_SMTP_USER',
        'password'   => 'CHANGE_ME_SMTP_PASS',
        'from_email' => 'no-reply@anplaystore.local',
        'from_name'  => 'ANPLAY STORE',
    ],
];
