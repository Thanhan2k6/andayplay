<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/connect.php';

// Kiểm tra quyền Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px; background:#0f172a; color:#fff;'>
        <h2>⛔ Từ chối truy cập</h2>
        <p>Vui lòng đăng nhập bằng tài khoản Quản trị viên (Admin) để thực hiện tính năng này.</p>
        <a href='login.php' style='color:#38bdf8;'>Đăng nhập ngay</a>
    </div>");
}

$target_quantity = 20; // 20 tài khoản mỗi game
$results = [];
$total_added = 0;

// Lấy danh sách toàn bộ game trả phí
$games_res = $conn->query("SELECT game_id, title FROM games WHERE price_type = 'paid' ORDER BY game_id ASC");

if ($games_res && $games_res->num_rows > 0) {
    while ($game = $games_res->fetch_assoc()) {
        $gid = intval($game['game_id']);
        $gtitle = $game['title'];

        // Kiểm tra số lượng tài khoản AVAILABLE hiện tại
        $count_res = $conn->query("SELECT COUNT(*) as c FROM game_accounts WHERE game_id = $gid AND status = 'AVAILABLE'");
        $current_avail = ($count_res && $row = $count_res->fetch_assoc()) ? intval($row['c']) : 0;

        $needed = $target_quantity - $current_avail;
        if ($needed < 0) $needed = 0;

        $added_for_this_game = 0;
        if ($needed > 0) {
            $clean_title = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($gtitle));
            if (empty($clean_title)) $clean_title = "game" . $gid;
            $clean_title = substr($clean_title, 0, 10);

            for ($i = 1; $i <= $needed; $i++) {
                $username = "anplay_" . $clean_title . "_" . rand(100, 999) . $i;
                $plain_pass = generate_strong_password(14);
                $enc_pass = encrypt_game_password($plain_pass);
                $region = "Global (VN/Asia)";
                $note = "Nạp tự động kho 20 tài khoản - " . date('d/m/Y H:i');

                $ins = $conn->prepare("INSERT INTO game_accounts 
                    (game_id, account_username, account_password_encrypted, server_region, status, notes, created_at) 
                    VALUES (?, ?, ?, ?, 'AVAILABLE', ?, NOW())");
                $ins->bind_param("issss", $gid, $username, $enc_pass, $region, $note);
                if ($ins->execute()) {
                    $added_for_this_game++;
                    $total_added++;
                }
                $ins->close();
            }
        }

        // Đồng bộ số lượng tồn kho games.quantity
        sync_game_inventory($conn, $gid);

        $results[] = [
            'id' => $gid,
            'title' => $gtitle,
            'added' => $added_for_this_game,
            'total_avail' => ($current_avail + $added_for_this_game)
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nạp Tự Động 20 Tài Khoản / Game - ANPLAY STORE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #0b0f17; color: #f8fafc; font-family: 'Segoe UI', sans-serif; }
        .card-custom { background-color: #1a222c; border: 1px solid #2d3b4e; border-radius: 12px; }
    </style>
</head>
<body class="py-5">
<div class="container" style="max-width: 900px;">
    <div class="card card-custom p-4 shadow-lg mb-4 text-center">
        <h2 class="text-warning fw-bold"><i class="bi bi-lightning-charge-fill me-2"></i> BÁO CÁO NẠP KHO TÀI KHOẢN GAME TỰ ĐỘNG</h2>
        <p class="text-secondary mb-0">Hệ thống đã tự động rà soát và bổ sung đạt định mức <strong>20 tài khoản khả dụng</strong> cho mỗi tựa game trả phí.</p>
        <div class="mt-3">
            <span class="badge bg-success fs-5 px-3 py-2">Đã thêm mới: <?php echo $total_added; ?> tài khoản</span>
        </div>
    </div>

    <div class="card card-custom p-4 shadow-lg mb-4">
        <h5 class="text-info fw-bold mb-3"><i class="bi bi-list-check me-2"></i> Chi Tiết Từng Tựa Game</h5>
        <div class="table-responsive">
            <table class="table table-dark table-hover table-bordered align-middle text-center mb-0">
                <thead class="table-secondary text-dark">
                    <tr>
                        <th style="width: 10%;">ID Game</th>
                        <th class="text-start" style="width: 50%;">Tên Trò Chơi</th>
                        <th style="width: 20%;">Vừa Nạp Thêm</th>
                        <th style="width: 20%;">Kho Sẵn Sàng (AVAILABLE)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr>
                        <td>#<?php echo $r['id']; ?></td>
                        <td class="text-start fw-bold text-light"><?php echo htmlspecialchars($r['title']); ?></td>
                        <td><span class="badge bg-primary">+<?php echo $r['added']; ?> tài khoản</span></td>
                        <td><span class="badge bg-success fs-6"><?php echo $r['total_avail']; ?> / 20</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="text-center">
        <a href="admin_accounts.php" class="btn btn-info fw-bold px-4 py-2 me-2">
            <i class="bi bi-box-seam me-1"></i> Đến Kho Tài Khoản (Admin)
        </a>
        <a href="index.php" class="btn btn-outline-light px-4 py-2">
            <i class="bi bi-house me-1"></i> Về Cửa Hàng Trang Chủ
        </a>
    </div>
</div>
</body>
</html>

