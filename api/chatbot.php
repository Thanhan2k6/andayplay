<?php
/**
 * ANPLAY STORE — CHATBOT AI BACKEND (Gemini API Proxy)
 * 
 * Luồng xử lý:
 *   Trình duyệt (JS) → POST /api/chatbot.php → PHP xác thực + gửi Gemini → JSON response → JS hiển thị
 * 
 * Bảo mật:
 *   - Chỉ chấp nhận POST request
 *   - Xác thực CSRF token từ session
 *   - Rate limiting theo session (15 req / 60s)
 *   - Giới hạn độ dài tin nhắn
 *   - API key chỉ tồn tại server-side, không bao giờ gửi về client
 *   - Escape đầu ra, không expose thông tin nội bộ
 *   - Không cho AI truy cập/thực thi SQL hoặc thay đổi dữ liệu
 */

// Khởi tạo session (cần cho CSRF + rate limit)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

// ─── 0. CHỈ CHẤP NHẬN POST ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Phương thức không được hỗ trợ.']);
    exit;
}

// ─── 1. NẠP CẤU HÌNH VÀ KẾT NỐI CSDL ───────────────────
define('ANPLAY_CHATBOT_ACCESS', true);

$configPath = __DIR__ . '/../includes/chatbot_config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Cấu hình chatbot chưa được thiết lập. Vui lòng liên hệ Admin.']);
    exit;
}
$config = require $configPath;

// Kiểm tra API key đã được cấu hình chưa
if (empty($config['gemini_api_key']) || $config['gemini_api_key'] === 'YOUR_GEMINI_API_KEY_HERE') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Hệ thống AI đang được cấu hình. Vui lòng thử lại sau hoặc liên hệ Admin.']);
    exit;
}

// Nạp kết nối CSDL (dự án dùng includes/connect.php → biến $conn: mysqli)
$connectPath = __DIR__ . '/../includes/connect.php';
if (file_exists($connectPath)) {
    require_once $connectPath;
}

// ─── 2. KIỂM TRA CSRF TOKEN ──────────────────────────────
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dữ liệu gửi lên không hợp lệ.']);
    exit;
}

$clientToken = $data['csrf_token'] ?? '';
$sessionToken = $_SESSION['chat_csrf_token'] ?? '';

if (empty($clientToken) || empty($sessionToken) || !hash_equals($sessionToken, $clientToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang (F5) rồi thử lại!']);
    exit;
}

// ─── 3. RATE LIMITING (CHỐNG SPAM) ────────────────────────
$now = time();
$rl = $_SESSION['chat_rate_limit'] ?? ['count' => 0, 'window_start' => $now];

if ($now - $rl['window_start'] >= $config['rate_limit']['window_seconds']) {
    // Reset cửa sổ thời gian
    $rl = ['count' => 1, 'window_start' => $now];
} else {
    $rl['count']++;
    if ($rl['count'] > $config['rate_limit']['max_requests']) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Bạn gửi tin quá nhanh! Vui lòng chờ ' . $config['rate_limit']['window_seconds'] . ' giây rồi thử lại.']);
        exit;
    }
}
$_SESSION['chat_rate_limit'] = $rl;

// ─── 4. LÀM SẠCH VÀ XÁC THỰC TIN NHẮN ──────────────────
$userMessage = trim($data['message'] ?? '');

if ($userMessage === '') {
    echo json_encode(['success' => false, 'error' => 'Nội dung tin nhắn không được để trống.']);
    exit;
}

if (mb_strlen($userMessage, 'UTF-8') > $config['max_message_length']) {
    echo json_encode(['success' => false, 'error' => 'Tin nhắn quá dài (tối đa ' . $config['max_message_length'] . ' ký tự).']);
    exit;
}

// ─── 5. LẤY DỮ LIỆU GAME THỰC TẾ (CHỈ THÔNG TIN CÔNG KHAI) ──
$storeCatalog = "Danh sách game hiện có tại ANPLAY STORE:\n";
$gameDataLoaded = false;

try {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $res = $conn->query("SELECT title, platform, price_type FROM games ORDER BY title ASC LIMIT 25");
        if ($res && $res->num_rows > 0) {
            while ($g = $res->fetch_assoc()) {
                $title = $g['title'] ?? 'Game';
                $platform = $g['platform'] ?? 'PC';
                $priceType = ($g['price_type'] ?? '') === 'free' ? 'Miễn phí' : 'Có phí (xem giá tại trang game)';
                $storeCatalog .= "- {$title} ({$platform}) — {$priceType}\n";
            }
            $gameDataLoaded = true;
        }
    }
} catch (Throwable $e) {
    // Không để lỗi DB ảnh hưởng chatbot
}

if (!$gameDataLoaded) {
    $storeCatalog .= "(Chưa tải được danh sách game. Khách có thể xem trực tiếp tại Cửa Hàng trên website.)\n";
}

// ─── 6. XÂY DỰNG SYSTEM PROMPT CHO GEMINI ────────────────
$systemPrompt = "Bạn là trợ lý AI chính thức của ANPLAY STORE — nền tảng cho thuê tài khoản game bản quyền tại Việt Nam.

QUY TẮC BẮT BUỘC:
1. Trả lời ngắn gọn, lịch sự, thân thiện, đúng phong cách game thủ Việt Nam.
2. Chỉ tư vấn dựa trên danh sách game bên dưới. Nếu game không có trong danh sách, nói rõ 'hiện chưa có trong kho'.
3. TUYỆT ĐỐI KHÔNG tự bịa giá tiền, thời gian thuê hoặc thông tin không có thật.
4. TUYỆT ĐỐI KHÔNG cung cấp tài khoản game, mật khẩu, mã OTP hay bất kỳ thông tin bảo mật nào.
5. TUYỆT ĐỐI KHÔNG thực hiện thao tác thanh toán, đổi mật khẩu, hay can thiệp hệ thống.
6. Nếu không biết câu trả lời, hướng dẫn khách liên hệ CSKH qua Zalo: {$config['support_info']['zalo']} hoặc Fanpage: {$config['support_info']['fanpage']}.
7. Giờ hỗ trợ: {$config['support_info']['hours']}.
8. Để thuê game, hướng dẫn khách vào trang chi tiết game trên website → chọn gói thuê → thanh toán qua QR VietQR.

{$storeCatalog}";

// ─── 7. GỬI REQUEST ĐẾN GEMINI API QUA cURL ──────────────
$payload = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [['text' => $userMessage]]
        ]
    ],
    'systemInstruction' => [
        'parts' => [['text' => $systemPrompt]]
    ],
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => 512,
        'topP' => 0.95
    ]
];

$apiUrl = rtrim($config['api_base_url'], '/')
    . '/' . $config['model']
    . ':generateContent?key=' . $config['gemini_api_key'];

// Kiểm tra cURL extension
if (!function_exists('curl_init')) {
    echo json_encode(['success' => false, 'error' => 'Máy chủ chưa bật extension cURL. Vui lòng liên hệ Admin.']);
    exit;
}

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,   // Bật xác thực SSL (an toàn)
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// ─── 8. XỬ LÝ RESPONSE ──────────────────────────────────
// Lỗi kết nối cURL (DNS, timeout, SSL...)
if ($curlErrno !== 0) {
    $userFriendlyError = 'Không thể kết nối đến dịch vụ AI. ';
    if ($curlErrno === CURLE_OPERATION_TIMEDOUT || $curlErrno === CURLE_OPERATION_TIMEOUTED) {
        $userFriendlyError .= 'Máy chủ AI phản hồi quá chậm.';
    } elseif ($curlErrno === CURLE_SSL_CONNECT_ERROR || $curlErrno === CURLE_SSL_CERTPROBLEM) {
        $userFriendlyError .= 'Lỗi chứng chỉ SSL.';
    } else {
        $userFriendlyError .= 'Vui lòng thử lại sau.';
    }
    echo json_encode(['success' => false, 'error' => $userFriendlyError]);
    exit;
}

// Parse JSON response
$resData = json_decode($response, true);

if ($httpCode === 200 && isset($resData['candidates'][0]['content']['parts'][0]['text'])) {
    $botReply = $resData['candidates'][0]['content']['parts'][0]['text'];
    echo json_encode(['success' => true, 'reply' => $botReply]);
} elseif ($httpCode === 400) {
    echo json_encode(['success' => false, 'error' => 'Yêu cầu không hợp lệ. Vui lòng thử câu hỏi khác.']);
} elseif ($httpCode === 401 || $httpCode === 403) {
    echo json_encode(['success' => false, 'error' => 'API Key không hợp lệ hoặc đã hết hạn. Vui lòng liên hệ Admin.']);
} elseif ($httpCode === 429) {
    echo json_encode(['success' => false, 'error' => 'Đã vượt giới hạn sử dụng API. Vui lòng thử lại sau vài phút.']);
} elseif ($httpCode >= 500) {
    echo json_encode(['success' => false, 'error' => 'Máy chủ Google AI đang gặp sự cố. Vui lòng thử lại sau.']);
} else {
    // Kiểm tra blocked content
    $blockReason = $resData['candidates'][0]['finishReason'] ?? '';
    if ($blockReason === 'SAFETY') {
        echo json_encode(['success' => false, 'error' => 'Câu hỏi bị hệ thống an toàn từ chối. Vui lòng đặt câu hỏi khác.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'AI tạm thời chưa thể trả lời. Vui lòng thử lại!']);
    }
}

