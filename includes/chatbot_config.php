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

return [
    // ====> THAY API KEY GEMINI CỦA BẠN VÀO ĐÂY <====
    // Lấy key tại: https://aistudio.google.com/apikey
    'gemini_api_key' => 'YOUR_GEMINI_API_KEY_HERE',

    // Model khuyến nghị cho phản hồi nhanh
    'model' => 'gemini-2.0-flash',

    // Endpoint chuẩn Gemini API v1beta
    'api_base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/',

    // Giới hạn tần suất: 15 tin nhắn / 60 giây / session
    'rate_limit' => [
        'max_requests'   => 15,
        'window_seconds' => 60
    ],

    // Giới hạn độ dài tin nhắn (ký tự UTF-8)
    'max_message_length' => 600,

    // Thông tin hỗ trợ hiển thị trong system prompt
    'support_info' => [
        'fanpage' => 'https://facebook.com/anplaystore',
        'zalo'    => '09xx.xxx.xxx',
        'hours'   => '08:00 - 23:00'
    ]
];

