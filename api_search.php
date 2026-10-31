<?php
/**
 * ANPLAY STORE - API LIVE SEARCH
 * Tìm kiếm game thời gian thực từ cơ sở dữ liệu MySQL
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/connect.php';

if (!isset($conn) || !$conn || $conn->connect_error) {
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi kết nối cơ sở dữ liệu',
        'results' => []
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$query = isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['query']) ? trim($_GET['query']) : '');

if (mb_strlen($query, 'UTF-8') < 1) {
    echo json_encode([
        'success' => true,
        'query'   => '',
        'count'   => 0,
        'results' => []
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $search_param = '%' . $query . '%';
    $sql = "SELECT g.game_id, g.title, g.image_url, g.platform, g.description, c.cate_name,
                   COALESCE((SELECT MIN(price) FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.is_active = 1), 5000) as min_rent_price,
                   (SELECT COUNT(*) FROM game_accounts ga WHERE ga.game_id = g.game_id AND ga.status = 'AVAILABLE') as available_acc
            FROM games g
            LEFT JOIN categories c ON g.category_id = c.category_id
            WHERE (g.title LIKE ? OR g.description LIKE ? OR c.cate_name LIKE ? OR g.platform LIKE ?)
            ORDER BY available_acc DESC, g.game_id DESC
            LIMIT 8";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        // Fallback nếu có lỗi prepare
        $safe_q = $conn->real_escape_string($query);
        $res = $conn->query("SELECT g.game_id, g.title, g.image_url, g.platform, g.description, c.cate_name, 5000 as min_rent_price, g.quantity as available_acc
                             FROM games g
                             LEFT JOIN categories c ON g.category_id = c.category_id
                             WHERE (g.title LIKE '%$safe_q%' OR g.description LIKE '%$safe_q%' OR c.cate_name LIKE '%$safe_q%')
                             LIMIT 8");
        $results = [];
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $results[] = [
                    'game_id'       => intval($row['game_id']),
                    'title'         => $row['title'],
                    'image_url'     => !empty($row['image_url']) ? $row['image_url'] : 'img/default-game.jpg',
                    'platform'      => $row['platform'] ?? 'PC',
                    'cate_name'     => $row['cate_name'] ?? 'Hành động',
                    'min_price'     => floatval($row['min_rent_price']),
                    'min_price_fmt' => number_format(floatval($row['min_rent_price']), 0, ',', '.') . 'đ/giờ',
                    'available_acc' => intval($row['available_acc']),
                    'url'           => 'game_detail.php?id=' . $row['game_id']
                ];
            }
        }
    } else {
        $stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
        $stmt->execute();
        $res = $stmt->get_result();
        $results = [];

        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $results[] = [
                    'game_id'       => intval($row['game_id']),
                    'title'         => $row['title'],
                    'image_url'     => !empty($row['image_url']) ? $row['image_url'] : 'img/default-game.jpg',
                    'platform'      => $row['platform'] ?? 'PC',
                    'cate_name'     => $row['cate_name'] ?? 'Hành động',
                    'min_price'     => floatval($row['min_rent_price']),
                    'min_price_fmt' => number_format(floatval($row['min_rent_price']), 0, ',', '.') . 'đ/giờ',
                    'available_acc' => intval($row['available_acc']),
                    'url'           => 'game_detail.php?id=' . $row['game_id']
                ];
            }
        }
        $stmt->close();
    }

    echo json_encode([
        'success' => true,
        'query'   => $query,
        'count'   => count($results),
        'results' => $results
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi truy vấn: ' . $e->getMessage(),
        'results' => []
    ], JSON_UNESCAPED_UNICODE);
}
