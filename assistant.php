<?php
/**
 * ANPLAY STORE — AI CUSTOMER SERVICE & GAMING ASSISTANT
 * Controller xử lý tư vấn trò chơi và dịch vụ ANPLAY STORE
 */

// ─── 0. KHỞI TẠO SESSION & HEADERS ───────────────────────────────────────
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Bắt buộc UTF-8 JSON
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// Chỉ chấp nhận POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Phương thức yêu cầu không được hỗ trợ (Chỉ chấp nhận POST).']);
    exit;
}

// ─── 1. NẠP CẤU HÌNH VÀ KẾT NỐI DATABASE ─────────────────────────────────
define('ANPLAY_CHATBOT_ACCESS', true);

$configPath = __DIR__ . '/includes/chatbot_config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Cấu hình Trợ lý chưa được thiết lập. Vui lòng liên hệ Admin.']);
    exit;
}
$config = require $configPath;

if (empty($config['gemini_api_key'])) {
    http_response_code(503);
    echo json_encode([
        'success'    => false,
        'error_type' => 'GEMINI_AUTH_ERROR',
        'error'      => 'Hiện trợ lý AI đang tạm thời không khả dụng. Bạn có thể [Liên hệ nhân viên hỗ trợ](support.php) để được kỹ thuật viên hỗ trợ ngay.'
    ]);
    exit;
}

// Nạp kết nối MySQL
$conn = null;
if (!defined('ANPLAY_CHATBOT_NO_DIE')) {
    define('ANPLAY_CHATBOT_NO_DIE', true);
}
$connectPath = __DIR__ . '/includes/connect.php';
if (file_exists($connectPath)) {
    try {
        require_once $connectPath;
        // Biến $conn đã được khởi tạo trong connect.php
    } catch (Throwable $dbErr) {
        $conn = null;
    }
}

// ─── 2. ĐỌC DỮ LIỆU INPUT (Hỗ trợ cả FormData $_POST và JSON php://input) ─
$userMessage   = '';
$clientToken   = '';
$clientHistory = [];
$action        = '';

// Thử lấy từ $_POST trước (FormData / urlencoded)
if (!empty($_POST)) {
    $userMessage = trim($_POST['message'] ?? '');
    $clientToken = trim($_POST['csrf_token'] ?? '');
    $action      = trim($_POST['action'] ?? '');
    if (!empty($_POST['history'])) {
        $decodedHist = is_string($_POST['history']) ? json_decode($_POST['history'], true) : $_POST['history'];
        if (is_array($decodedHist)) {
            $clientHistory = $decodedHist;
        }
    }
}

// Nếu $_POST trống, đọc từ php://input (JSON payload)
if (empty($userMessage) && empty($action)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            $userMessage   = trim($jsonData['message'] ?? '');
            $clientToken   = trim($jsonData['csrf_token'] ?? '');
            $action        = trim($jsonData['action'] ?? '');
            $clientHistory = is_array($jsonData['history'] ?? null) ? $jsonData['history'] : [];
        }
    }
}

// ─── 3. HEALTH CHECK AN TOÀN (Dành cho kiểm thử hệ thống, KHÔNG in API Key) ──
if ($action === 'health_check') {
    $apiKey = $config['gemini_api_key'] ?? '';
    echo json_encode([
        'success'        => true,
        'api_key_exists' => !empty($apiKey),
        'api_key_length' => strlen($apiKey),
        'api_key_format' => (bool)preg_match('/^AIzaSy[A-Za-z0-9_-]{33}$/', $apiKey),
        'db_connected'   => ($conn && !$conn->connect_error),
        'primary_model'  => $config['model'] ?? 'gemini-3.5-flash-lite'
    ]);
    exit;
}

// ─── 4. KIỂM TRA BẢO MẬT: CSRF TOKEN ─────────────────────────────────────
$sessionToken = $_SESSION['chat_csrf_token'] ?? '';

// Đồng bộ / cấp phát CSRF token nếu chưa có trong session
if (empty($sessionToken)) {
    if (!empty($clientToken) && preg_match('/^[a-f0-9]{32,64}$/i', $clientToken)) {
        $_SESSION['chat_csrf_token'] = $clientToken;
        $sessionToken = $clientToken;
    } else {
        $_SESSION['chat_csrf_token'] = bin2hex(random_bytes(32));
        $sessionToken = $_SESSION['chat_csrf_token'];
    }
}

$isValidToken = (!empty($clientToken) && !empty($sessionToken) && hash_equals($sessionToken, $clientToken));

// Dự phòng: Nếu token client hợp lệ theo định dạng hex nhưng lệch session do cookie trễ
if (!$isValidToken && !empty($clientToken) && preg_match('/^[a-f0-9]{32,64}$/i', $clientToken)) {
    $_SESSION['chat_csrf_token'] = $clientToken;
    $sessionToken = $clientToken;
    $isValidToken = true;
}

if (!$isValidToken) {
    http_response_code(403);
    echo json_encode([
        'success'        => false,
        'error_code'     => 'SESSION_EXPIRED',
        'error'          => 'Phiên làm việc bảo mật đã hết hạn. Vui lòng tải lại trang (F5) để tiếp tục!',
        'new_csrf_token' => $sessionToken
    ]);
    exit;
}

// ─── 5. RATE LIMITING (Chống spam API) ───────────────────────────────────
$now = time();
$maxReq   = $config['rate_limit']['max_requests'] ?? 20;
$timeWin  = $config['rate_limit']['window_seconds'] ?? 60;
$rateData = $_SESSION['chat_rate_limit'] ?? ['count' => 0, 'window_start' => $now];

if ($now - $rateData['window_start'] >= $timeWin) {
    $rateData = ['count' => 1, 'window_start' => $now];
} else {
    $rateData['count']++;
    if ($rateData['count'] > $maxReq) {
        http_response_code(429);
        echo json_encode([
            'success'        => false,
            'error_type'     => 'GEMINI_RATE_LIMIT',
            'error'          => "Bạn đang gửi tin nhắn quá nhanh! Vui lòng chờ {$timeWin} giây rồi tiếp tục.",
            'new_csrf_token' => $sessionToken
        ]);
        exit;
    }
}
$_SESSION['chat_rate_limit'] = $rateData;

// ─── 6. XÁC THỰC VÀ LÀM SẠCH TIN NHẮN ────────────────────────────────────
if (empty($userMessage)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Vui lòng nhập nội dung câu hỏi!']);
    exit;
}

// Giới hạn độ dài câu hỏi tối đa
$maxLength = $config['max_message_length'] ?? 800;
$userMessage = mb_substr($userMessage, 0, $maxLength, 'UTF-8');

// Chặn prompt injection nguy hiểm
$injectionPatterns = [
    '/ignore\s+all\s+(previous|prior)\s+instructions/i',
    '/reveal\s+(the\s+)?api\s*key/i',
    '/system\s*prompt\s*dump/i',
    '/drop\s+table/i',
    '/truncate\s+table/i',
];
foreach ($injectionPatterns as $pattern) {
    if (preg_match($pattern, $userMessage)) {
        echo json_encode([
            'success'        => true,
            'reply'          => 'Yêu cầu không được hỗ trợ vì vi phạm chính sách an toàn của hệ thống ANPLAY STORE.',
            'new_csrf_token' => $sessionToken
        ]);
        exit;
    }
}

// ─── 7. TRÍCH XUẤT DỮ LIỆU THỰC TẾ TỪ DATABASE ANPLAY STORE ──────────────
$realStoreFacts = "";

if ($conn && !$conn->connect_error) {
    // A. Lấy danh sách tựa game thực tế đang có trên shop
    try {
        $gameQuery = "SELECT g.game_id, g.title, g.platform, g.price_type, c.cate_name,
                      (SELECT MIN(price) FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.is_active = 1) as min_rent_price,
                      (SELECT COUNT(*) FROM game_accounts ga WHERE ga.game_id = g.game_id AND ga.status = 'AVAILABLE') as available_acc
                      FROM games g 
                      LEFT JOIN categories c ON g.category_id = c.category_id 
                      ORDER BY g.views DESC LIMIT 30";
        $gameRes = $conn->query($gameQuery);
        if ($gameRes && $gameRes->num_rows > 0) {
            $gameList = [];
            while ($g = $gameRes->fetch_assoc()) {
                $pType = 'Cho thuê tài khoản';
                $priceStr = !empty($g['min_rent_price']) ? number_format($g['min_rent_price'], 0, ',', '.') . 'đ' : '5.000đ';
                $accStatus = intval($g['available_acc'] ?? 0) > 0 ? "Sẵn sàng cấp ngay" : "Tạm hết tài khoản";
                $gameList[] = "- **{$g['title']}** (Nền tảng: {$g['platform']} | Thể loại: {$g['cate_name']} | Mô hình: {$pType} | Giá thuê từ: {$priceStr} | Tình trạng: {$accStatus})";
            }
            $realStoreFacts .= "### DANH SÁCH GAME THỰC TẾ ĐANG CÓ TẠI ANPLAY STORE:\n" . implode("\n", $gameList) . "\n\n";
        }
    } catch (Throwable $e) {
        try {
            $simpleRes = $conn->query("SELECT title, platform, price_type FROM games LIMIT 25");
            if ($simpleRes && $simpleRes->num_rows > 0) {
                $gameList = [];
                while ($sg = $simpleRes->fetch_assoc()) {
                    $gameList[] = "- {$sg['title']} ({$sg['platform']})";
                }
                $realStoreFacts .= "### TỰA GAME HIỆN CÓ:\n" . implode(", ", $gameList) . "\n\n";
            }
        } catch (Throwable $e2) {}
    }

    // B. Kiểm tra thông tin người dùng đang đăng nhập (Tra cứu đơn thuê cá nhân an toàn)
    $currentUserId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    if ($currentUserId > 0) {
        try {
            $userOrdersQuery = "SELECT b.order_code, g.title, b.borrow_date, b.due_date, b.status, b.rental_price 
                                FROM borrow_records b 
                                JOIN games g ON b.game_id = g.game_id 
                                WHERE b.user_id = $currentUserId 
                                ORDER BY b.borrow_id DESC LIMIT 5";
            $ordersRes = $conn->query($userOrdersQuery);
            if ($ordersRes && $ordersRes->num_rows > 0) {
                $userOrders = [];
                while ($ord = $ordersRes->fetch_assoc()) {
                    $sttText = ($ord['status'] === 'active') ? 'Đang hiệu lực (Chơi tốt)' : (($ord['status'] === 'expired') ? 'Đã hết hạn' : $ord['status']);
                    $userOrders[] = "- Mã đơn: **{$ord['order_code']}** | Game: **{$ord['title']}** | Hạn dùng đến: **{$ord['due_date']}** | Trạng thái: {$sttText}";
                }
                $realStoreFacts .= "### THÔNG TIN ĐƠN THUÊ CỦA KHÁCH HÀNG ĐANG TRÒ CHUYỆN (Đã xác thực danh tính):\n" . implode("\n", $userOrders) . "\n";
                $realStoreFacts .= "(Nếu khách hỏi về đơn thuê của họ, hãy dùng thông tin chính xác trên để hỗ trợ khách).\n\n";
            } else {
                $realStoreFacts .= "### THÔNG TIN KHÁCH HÀNG: Khách hàng đã đăng nhập nhưng hiện chưa có đơn thuê nào trên hệ thống.\n\n";
            }
        } catch (Throwable $e) {}
    } else {
        $realStoreFacts .= "### THÔNG TIN KHÁCH HÀNG: Khách hàng hiện chưa đăng nhập tài khoản. Nếu khách hỏi về đơn thuê cá nhân hoặc tài khoản đã thuê của họ, hãy nhắc khách đăng nhập tài khoản ANPLAY STORE để xem tại mục 'Thư viện của tôi'.\n\n";
    }
}

// Nếu CSDL chưa kết nối (offline) hoặc chưa có dữ liệu, dùng danh mục chuẩn thực tế của shop
if (empty($realStoreFacts)) {
    $realStoreFacts = <<<EOD
### DANH SÁCH GAME THỰC TẾ ĐANG CÓ TẠI ANPLAY STORE:
- **Black Myth: Wukong** (Nền tảng: PC | Thể loại: Nhập vai hành động AAA | Phân loại: Game Thuê Trả Phí | Giá thuê từ: 15.000đ/ngày, 30.000đ/tuần, 99.000đ/tháng | Tình trạng: Sẵn sàng cấp ngay)
- **Red Dead Redemption 2** (Nền tảng: PC | Thể loại: Thế giới mở / Cao bồi miền Tây | Phân loại: Game Thuê Trả Phí | Giá thuê từ: 15.000đ/ngày, 30.000đ/tuần, 99.000đ/tháng | Tình trạng: Sẵn sàng cấp ngay)
- **The Witcher 3: Wild Hunt** (Nền tảng: PC | Thể loại: Nhập vai thế giới mở | Phân loại: Game Thuê Trả Phí | Giá thuê từ: 10.000đ/ngày, 25.000đ/tuần, 80.000đ/tháng | Tình trạng: Sẵn sàng cấp ngay)
- **Stardew Valley** (Nền tảng: PC | Thể loại: Mô phỏng nông trại / Indie | Phân loại: Game Thuê Trả Phí | Giá thuê từ: 5.000đ/ngày, 15.000đ/tuần, 50.000đ/tháng | Tình trạng: Sẵn sàng cấp ngay)
- **Counter-Strike 2** (Nền tảng: PC | Thể loại: Bắn súng chiến thuật FPS | Phân loại: Cho thuê tài khoản | Giá thuê từ: 5.000đ/giờ, 15.000đ/ngày | Tình trạng: Sẵn sàng cấp ngay)
- **PUBG: BATTLEGROUNDS** (Nền tảng: PC | Thể loại: Sinh tồn Battle Royale | Phân loại: Cho thuê tài khoản | Giá thuê từ: 5.000đ/giờ, 15.000đ/ngày | Tình trạng: Sẵn sàng cấp ngay)
- **Genshin Impact** (Nền tảng: PC / Mobile | Thể loại: Nhập vai thế giới mở anime | Phân loại: Cho thuê tài khoản | Giá thuê từ: 5.000đ/giờ, 15.000đ/ngày | Tình trạng: Sẵn sàng cấp ngay)
- **Dota 2** (Nền tảng: PC | Thể loại: MOBA chiến thuật 5v5 | Phân loại: Cho thuê tài khoản | Giá thuê từ: 5.000đ/giờ, 15.000đ/ngày | Tình trạng: Sẵn sàng cấp ngay)
- **Free Fire** (Nền tảng: Mobile (Android/iOS) | Thể loại: Bắn súng sinh tồn | Phân loại: Cho thuê tài khoản | Giá thuê từ: 5.000đ/giờ, 15.000đ/ngày | Tình trạng: Sẵn sàng cấp ngay)
- **Teamfight Tactics** (Nền tảng: PC / Mobile | Thể loại: Cờ nhân phẩm Auto Battler | Phân loại: Cho thuê tài khoản | Giá thuê từ: 5.000đ/giờ, 15.000đ/ngày | Tình trạng: Sẵn sàng cấp ngay)
- **Minecraft** (Nền tảng: PC | Thể loại: Thế giới mở sinh tồn khối vuông | Phân loại: Cho thuê tài khoản | Giá thuê từ: 5.000đ/giờ, 15.000đ/ngày | Tình trạng: Sẵn sàng cấp ngay)

### THÔNG TIN KHÁCH HÀNG: Khách hàng hiện chưa đăng nhập. Nhắc khách đăng nhập để xem đơn thuê tại 'Thư viện của tôi'.

EOD;
}

// ─── 8. XÂY DỰNG SYSTEM INSTRUCTION CHO ANPLAY ASSISTANT ──────────────────
$systemInstruction = <<<EOT
Bạn là ANPLAY ASSISTANT — Trợ lý ảo AI tự động của ANPLAY STORE (nền tảng dịch vụ cho thuê tài khoản game bản quyền Steam, EA, Epic Games hàng đầu Việt Nam).

MỤC TIÊU VÀ DANH TÍNH MINH BẠCH:
- Bạn là TRỢ LÝ ẢO TỰ ĐỘNG (AI BOT), TUYỆT ĐỐI KHÔNG PHẢI là nhân viên con người và KHÔNG ĐƯỢC GIẢ VỜ là nhân viên con người.
- Vai trò của bạn là: Tư vấn tựa game phù hợp, báo giá các gói thuê từ 5.000đ, hướng dẫn quy trình thuê và giải đáp các câu hỏi thường gặp.
- Trò chuyện bằng tiếng Việt tự nhiên, lịch sự, thân thiện, ngắn gọn, súc tích và chính xác.

QUY TẮC CỐT LÕI BẮT BUỘC:
1. NGUYÊN TẮC DỮ LIỆU THỰC TẾ:
   - CHỈ tư vấn và trả lời về những tựa game và giá tiền CÓ THẬT trong phần [DỮ LIỆU THỰC TẾ ANPLAY STORE] được cung cấp bên dưới.
   - TUYỆT ĐỐI KHÔNG tự bịa ra game, giá thuê, khuyến mãi ảo không có trong danh sách.
   - Nếu khách hỏi một tựa game KHÔNG CÓ trong danh sách: Hãy thông báo rõ ràng rằng hiện tại shop chưa có game này, và gợi ý khách liên hệ để Admin cập nhật thêm.

2. CÁC GÓI THỜI HẠN THUÊ VÀ CHÍNH SÁCH CHUẨN:
   - Các gói thuê chuẩn tại shop: 1 Giờ (Trải nghiệm nhanh từ 5.000đ), 1 Ngày (24h - từ 15.000đ), 7 Ngày (1 Tuần - từ 30.000đ), 30 Ngày (1 Tháng - từ 99.000đ).
   - Quy trình thuê 3 bước: (1) Chọn game và gói giờ -> (2) Quét mã VietQR thanh toán -> (3) Nhận tài khoản game đăng nhập ngay tại "Thư viện của tôi".
   - Chính sách bảo hành: 100% thời gian thuê, 1 đổi 1 ngay nếu tài khoản gặp sự cố (sai pass, vướng Steam Guard).
   - Lưu ý an toàn tài khoản: Khách không được tự ý đổi mật khẩu, không đổi email và không sử dụng phần mềm gian lận trong game.
   - Về việc Lưu tiến trình (Save Game): Giải thích rõ rằng 100% game đều hỗ trợ lưu tiến trình qua Steam Cloud và Local Save trên máy tính. Khách có thể tiếp tục chơi bằng cách "Gia hạn" hoặc bấm "Thuê lại tài khoản cũ (Giữ Save)" trong Thư viện.
   - Về quyền Đổi mật khẩu tài khoản game: Giải thích rõ rằng TUYỆT ĐỐI KHÔNG ĐƯỢC TỰ ĐỔI MẬT KHẨU tài khoản game vì tài khoản thuộc sở hữu của shop. Nếu gặp lỗi mật khẩu, hãy hướng dẫn khách bấm [Báo lỗi] trong Thư viện hoặc tạo Ticket [Hỗ trợ kỹ thuật](support.php) để nhân viên đổi mật khẩu mới trong 1-3 phút.

3. PHÂN ĐỊNH RÕ RÀNG VỚI HỖ TRỢ CON NGƯỜI (HUMAN SUPPORT HANDOFF):
   - Bạn là AI tư vấn, bạn KHÔNG THỂ can thiệp trực tiếp vào tài khoản, không thể đổi mật khẩu, không thể xử lý khiếu nại tài chính.
   - Khi khách hàng gặp sự cố thực tế (sai mật khẩu game, lỗi tài khoản, lỗi Steam Guard, quét mã nhưng chưa cấp tài khoản, khiếu nại thanh toán) hoặc khi khách hàng yêu cầu gặp người thật / nhân viên thật:
     HÃY THÔNG BÁO NGAY RẰNG BẠN LÀ AI VÀ HƯỚNG DẪN KHÁCH BẤM VÀO: [Liên hệ nhân viên hỗ trợ](support.php) để tạo phiếu hỗ trợ (Ticket), kỹ thuật viên con người của shop sẽ tiếp nhận và xử lý cấp đổi tài khoản 1 đổi 1 cho khách ngay.
   - Không bao giờ hỏi mật khẩu cá nhân, mã OTP ngân hàng của khách.
   - Không bao giờ tiết lộ API key hay cấu hình nội bộ.

DỮ LIỆU THỰC TẾ TẠI ANPLAY STORE:
{$realStoreFacts}
EOT;

// ─── 9. CHUẨN BỊ LỊCH SỬ HỘI THOẠI (MULTI-TURN CHAT) ─────────────────────
$contents = [];

// Xử lý tối đa 6 lượt hội thoại gần nhất để duy trì ngữ cảnh mà không tràn token
if (!empty($clientHistory) && is_array($clientHistory)) {
    $validHistory = array_slice($clientHistory, -6);
    foreach ($validHistory as $turn) {
        $role = ($turn['role'] === 'user') ? 'user' : 'model';
        $text = mb_substr(trim($turn['text'] ?? ($turn['parts'][0]['text'] ?? '')), 0, 400, 'UTF-8');
        if (!empty($text)) {
            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => $text]]
            ];
        }
    }
}

// Thêm tin nhắn hiện tại của người dùng
$contents[] = [
    'role'  => 'user',
    'parts' => [['text' => $userMessage]]
];

$payload = [
    'contents' => $contents,
    'systemInstruction' => [
        'parts' => [['text' => $systemInstruction]]
    ],
    'generationConfig' => [
        'temperature' => 0.65,
        'maxOutputTokens' => 700,
        'topP' => 0.95
    ]
];

// ─── 10. GỌI GEMINI API VỚI CƠ CHẾ FAILOVER ĐA MODEL ──────────────────────
$primaryModel = $config['model'] ?? 'gemini-3.5-flash-lite';
$modelsToTry = array_values(array_unique([
    $primaryModel,
    'gemini-3.5-flash-lite',
    'gemini-flash-lite-latest',
    'gemini-3.1-flash-lite',
    'gemini-3.8-flash'
]));

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode([
        'success'    => false,
        'error_type' => 'GEMINI_SERVER_ERROR',
        'error'      => 'Máy chủ PHP chưa bật extension cURL.'
    ]);
    exit;
}

$response  = null;
$httpCode  = 0;
$curlErrno = 0;
$usedModel = '';
$lastErrorMsg = '';

foreach ($modelsToTry as $currentModel) {
    $apiUrl = rtrim($config['api_base_url'], '/')
        . '/' . $currentModel
        . ':generateContent?key=' . $config['gemini_api_key'];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 18,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false, // Đảm bảo tương thích mọi hosting
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    // Nếu mã phản hồi là 200, tức là gọi thành công
    if ($httpCode === 200 && !empty($response)) {
        $usedModel = $currentModel;
        break;
    }

    // Nếu là lỗi 400 (Bad request / Invalid API Key), không cần failover các model khác vì key không hợp lệ
    if ($httpCode === 400 || $httpCode === 401) {
        break;
    }
}

// ─── 11. XỬ LÝ RESPONSE VÀ PHÂN LOẠI LỖI CHUẨN XÁC ───────────────────────
if ($curlErrno !== 0 && empty($response)) {
    http_response_code(502);
    $isTimeout = ($curlErrno === CURLE_OPERATION_TIMEDOUT);
    echo json_encode([
        'success'        => false,
        'error_type'     => $isTimeout ? 'GEMINI_TIMEOUT' : 'GEMINI_NETWORK_ERROR',
        'error'          => 'Không thể kết nối đến máy chủ AI (Lỗi đường truyền mạng). Vui lòng thử lại!',
        'new_csrf_token' => $sessionToken
    ]);
    exit;
}

$resData = json_decode($response, true);

// 1. Phản hồi thành công từ Gemini
if ($httpCode === 200 && isset($resData['candidates'][0]['content']['parts'][0]['text'])) {
    $aiReply = trim($resData['candidates'][0]['content']['parts'][0]['text']);
    
    // Ghi nhớ lịch sử hội thoại gần nhất vào session
    if (!isset($_SESSION['chat_history'])) {
        $_SESSION['chat_history'] = [];
    }
    $_SESSION['chat_history'][] = ['role' => 'user', 'text' => $userMessage];
    $_SESSION['chat_history'][] = ['role' => 'bot', 'text' => $aiReply];
    if (count($_SESSION['chat_history']) > 12) {
        $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -12);
    }

    echo json_encode([
        'success'        => true,
        'status'         => 'SUCCESS',
        'reply'          => $aiReply,
        'model'          => $usedModel,
        'new_csrf_token' => $sessionToken
    ]);
    exit;
}

// 2. Phân loại lỗi chi tiết (Tuyệt đối không để lộ API key hay raw token)
$rawErrMsg  = $resData['error']['message'] ?? '';
$errorType  = 'GEMINI_SERVER_ERROR';
$userErrMsg = 'Đã có sự cố khi xử lý câu hỏi với trợ lý AI. Vui lòng thử lại sau!';

if ($httpCode === 400 || $httpCode === 401 || stripos($rawErrMsg, 'API key not valid') !== false || stripos($rawErrMsg, 'API_KEY_INVALID') !== false) {
    $errorType  = 'GEMINI_AUTH_ERROR';
    // Bảo vệ người dùng cuối: không hiển thị thô "API key not valid"
    $userErrMsg = 'Trợ lý AI hiện đang gặp lỗi kết nối. Vui lòng thử lại sau.';
} elseif ($httpCode === 429 || stripos($rawErrMsg, 'RESOURCE_EXHAUSTED') !== false || stripos($rawErrMsg, 'quota') !== false) {
    $errorType  = 'GEMINI_RATE_LIMIT';
    $userErrMsg = 'Hệ thống AI đang tiếp nhận quá nhiều yêu cầu. Vui lòng chờ 30 giây rồi thử lại!';
} elseif ($httpCode === 503 || stripos($rawErrMsg, 'high demand') !== false || stripos($rawErrMsg, 'overloaded') !== false) {
    $errorType  = 'GEMINI_SERVER_ERROR';
    $userErrMsg = 'Máy chủ Google AI đang bảo trì tạm thời. Vui lòng thử lại sau ít phút!';
}

http_response_code($httpCode >= 400 && $httpCode < 600 ? $httpCode : 500);
echo json_encode([
    'success'        => false,
    'error_type'     => $errorType,
    'error'          => $userErrMsg,
    'new_csrf_token' => $sessionToken
]);
exit;
