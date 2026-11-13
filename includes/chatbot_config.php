<?php
/**
 * ANPLAY STORE — CẤU HÌNH CHATBOT AI (GEMINI API)
 * File này NẰM TRONG thư mục includes/ và chỉ được nạp bởi server-side PHP.
 * Không bao giờ được truy cập trực tiếp từ trình duyệt.
 */

// Chặn truy cập trực tiếp
if (!defined('ANPLAY_CHATBOT_ACCESS')) {
    http_response_code(403);
    exit('Forbidden');
}

// 1. Lấy từ biến môi trường hệ thống nếu có
$envKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');

// 2. Nạp từ file bí mật cục bộ (.gitignore): ưu tiên secrets.local.php, sau đó chatbot_secrets.local.php
$localKey = '';
$unifiedSecretsFile = __DIR__ . '/secrets.local.php';
if (file_exists($unifiedSecretsFile)) {
    $secrets = require $unifiedSecretsFile;
    if (is_array($secrets) && !empty($secrets['gemini_api_key'])) {
        $localKey = trim($secrets['gemini_api_key']);
    }
}
if (empty($localKey)) {
    $localSecretsFile = __DIR__ . '/chatbot_secrets.local.php';
    if (file_exists($localSecretsFile)) {
        $secrets = require $localSecretsFile;
        if (is_array($secrets) && !empty($secrets['gemini_api_key'])) {
            $localKey = trim($secrets['gemini_api_key']);
        }
    }
}

$activeApiKey = !empty($envKey) ? $envKey : $localKey;

return [
    // Khóa API Gemini chính thức
    'gemini_api_key' => $activeApiKey,

    // Model chính thức hỗ trợ generateContent tốt nhất và phản hồi tức thì
    'model' => 'gemini-3.5-flash-lite',

    // Endpoint chuẩn Gemini API v1beta
    'api_base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/',

    // Giới hạn tần suất: 20 tin nhắn / 60 giây / session
    'rate_limit' => [
        'max_requests'   => 20,
        'window_seconds' => 60
    ],

    // Giới hạn độ dài tin nhắn (ký tự UTF-8)
    'max_message_length' => 800,

    // Thông tin hỗ trợ hiển thị trong system prompt
    'support_info' => [
        'fanpage' => 'https://facebook.com/anplaystore',
        'zalo'    => '09xx.xxx.xxx',
        'hours'   => '08:00 - 23:00'
    ]
];
