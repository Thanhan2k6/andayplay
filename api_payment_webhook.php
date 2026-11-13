<?php
/**
 * ANPLAY STORE — SERVER-TO-SERVER PAYMENT WEBHOOK
 * Xác thực chữ ký HMAC-SHA256 (X-Webhook-Signature) và kích hoạt đơn thuê / gia hạn.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit();
}

require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/payment_service.php';

$webhook_secret = $anplay_secrets['payment_webhook_secret'] ?? (getenv('PAYMENT_WEBHOOK_SECRET') ?: '');
if (empty($webhook_secret)) {
    error_log('[ANPLAY WEBHOOK] PAYMENT_WEBHOOK_SECRET is not configured.');
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Webhook chưa được cấu hình.']);
    exit();
}

$raw_body = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$expected_sig = hash_hmac('sha256', $raw_body, $webhook_secret);

if (empty($sig_header) || !hash_equals($expected_sig, trim($sig_header))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid webhook signature']);
    exit();
}

$payload = json_decode($raw_body, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit();
}

$order_code = trim($payload['order_code'] ?? '');
$amount = floatval($payload['amount'] ?? 0);
$payment_tx_id = trim($payload['payment_tx_id'] ?? ($payload['transaction_id'] ?? ''));

if (empty($order_code) || $amount <= 0 || empty($payment_tx_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Thiếu order_code, amount hoặc payment_tx_id']);
    exit();
}

$stmt = $conn->prepare("SELECT borrow_id, rental_price, payment_status, status FROM borrow_records WHERE order_code = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Lỗi truy vấn đơn hàng']);
    exit();
}
$stmt->bind_param("s", $order_code);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Không tìm thấy đơn hàng']);
    exit();
}

$expected_amount = floatval($order['rental_price'] ?? 0);
if ($amount < $expected_amount) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Số tiền thanh toán không đủ']);
    exit();
}

$res = process_verified_payment($conn, intval($order['borrow_id']), $payment_tx_id, 'WEBHOOK');
if ($res['success']) {
    http_response_code(200);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(!empty($res['paid_pending_alloc']) ? 202 : 400);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
}
