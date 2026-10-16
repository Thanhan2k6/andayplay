<?php
/**
 * ANPLAY STORE - API NOTIFICATIONS
 * Endpoint phục vụ polling nhẹ và thao tác thông báo qua AJAX
 */

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/connect.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Chưa đăng nhập', 'unread_count' => 0]);
    exit();
}

$user_id = intval($_SESSION['user_id']);
$action = isset($_GET['action']) ? trim($_GET['action']) : (isset($_POST['action']) ? trim($_POST['action']) : 'get_unread_count');

// 1. LẤY SỐ LƯỢNG CHƯA ĐỌC
if ($action === 'get_unread_count') {
    $count = get_unread_notification_count($conn, $user_id);
    echo json_encode([
        'success' => true,
        'unread_count' => $count
    ]);
    exit();
}

// 2. LẤY DANH SÁCH DROPDOWN NHANH (TOP 6)
if ($action === 'get_dropdown') {
    $count = get_unread_notification_count($conn, $user_id);
    $items = get_user_notifications($conn, $user_id, 'all', false, 6, 0);

    echo json_encode([
        'success' => true,
        'unread_count' => $count,
        'items' => $items
    ]);
    exit();
}

// 3. ĐÁNH DẤU 1 THÔNG BÁO ĐÃ ĐỌC
if ($action === 'mark_read') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    if ($id > 0) {
        $res = mark_notification_read($conn, $id, $user_id);
        $new_count = get_unread_notification_count($conn, $user_id);
        echo json_encode(['success' => $res, 'unread_count' => $new_count]);
        exit();
    }
    echo json_encode(['success' => false, 'error' => 'ID không hợp lệ']);
    exit();
}

// 4. ĐÁNH DẤU TẤT CẢ ĐÃ ĐỌC
if ($action === 'mark_all_read') {
    $res = mark_all_notifications_read($conn, $user_id);
    echo json_encode(['success' => $res, 'unread_count' => 0]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Hành động không hỗ trợ']);

