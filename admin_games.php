<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$page_title = "Quản Lý Kho Game & Giá Thuê";
require_once __DIR__ . '/includes/admin_header.php';

require_permission('game.view');
$csrf_token = generate_csrf_token();

// XỬ LÝ: Xóa game (BẮT BUỘC POST + CSRF + game.manage)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo "<script>alert('Mã xác thực bảo mật không hợp lệ!'); window.location='admin_games.php';</script>";
        exit();
    }
    require_permission('game.manage');
    $del_id = intval($_POST['delete_id']);

    $st_rent = $conn->prepare("SELECT COUNT(*) as c FROM game_accounts WHERE game_id = ? AND status = 'RENTED'");
    $st_rent->bind_param("i", $del_id);
    $st_rent->execute();
    $chk_rent = $st_rent->get_result()->fetch_assoc();
    $st_rent->close();

    $st_bor = $conn->prepare("SELECT COUNT(*) as c FROM borrow_records WHERE game_id = ? AND status = 'active'");
    $st_bor->bind_param("i", $del_id);
    $st_bor->execute();
    $chk_borrow = $st_bor->get_result()->fetch_assoc();
    $st_bor->close();

    if (($chk_rent && $chk_rent['c'] > 0) || ($chk_borrow && $chk_borrow['c'] > 0)) {
        echo "<script>alert('Không thể xóa tựa game đang có tài khoản hoặc đơn thuê đang trong thời hạn hiệu lực!'); window.location='admin_games.php';</script>";
    } else {
        $d1 = $conn->prepare("DELETE FROM games WHERE game_id = ?");
        $d1->bind_param("i", $del_id);
        $d1->execute();
        $d1->close();

        $d2 = $conn->prepare("DELETE FROM game_accounts WHERE game_id = ? AND status != 'RENTED'");
        $d2->bind_param("i", $del_id);
        $d2->execute();
        $d2->close();

        $d3 = $conn->prepare("DELETE FROM rental_packages WHERE game_id = ?");
        $d3->bind_param("i", $del_id);
        $d3->execute();
        $d3->close();

        write_audit_log($conn, intval($_SESSION['user_id']), 'DELETE_GAME', 'game', $del_id);
        echo "<script>alert('Đã xóa game và dữ liệu kho liên quan thành công!'); window.location='admin_games.php';</script>";
    }
    exit();
}
?>

<div class="container-fluid px-lg-5 py-4 my-2">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); font-size: 0.75rem; border: 1px solid rgba(99,102,241,0.4);">
                    <i class="bi bi-controller me-1"></i> STORE MANAGEMENT
                </span>
            </div>
            <h2 class="fw-extrabold mb-0">Quản Lý Danh Sách & Giá Game</h2>
            <p class="text-secondary small mb-0">Quản lý tựa game, thiết lập gói giá thuê theo giờ/ngày/tuần/tháng và theo dõi tồn kho</p>
        </div>
        <div class="d-flex gap-2">
            <a href="admin_accounts.php" class="btn btn-gaming-secondary d-flex align-items-center">
                <i class="bi bi-box-seam me-1 text-info"></i> Quản Lý Kho Tài Khoản
            </a>
            <a href="admin_add_game.php" class="btn btn-gaming-primary d-flex align-items-center">
                <i class="bi bi-plus-circle me-1"></i> + Thêm Game Mới
            </a>
        </div>
    </div>
    
    <!-- Table Container -->
    <div class="table-gaming-container">
        <div class="table-responsive">
            <table class="table table-gaming align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 5%;">ID</th>
                        <th style="width: 26%;">Tựa Game & Nền Tảng</th>
                        <th style="width: 13%;">Thể Loại</th>
                        <th style="width: 14%;">Mô Hình Kinh Doanh</th>
                        <th style="width: 18%;">Kho Tài Khoản (Sẵn / Tổng)</th>
                        <th style="width: 12%;">Giá Thuê Từ</th>
                        <th style="width: 12%;" class="text-end pe-3">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (file_exists(__DIR__ . '/includes/pagination_helper.php')) {
                        require_once __DIR__ . '/includes/pagination_helper.php';
                    }
                    $req_per_page = intval($_GET['per_page'] ?? 10);
                    $per_page = in_array($req_per_page, [10, 20, 50], true) ? $req_per_page : 10;
                    $page = max(1, (int)($_GET['page'] ?? 1));

                    $total_items = 0;
                    $st_cnt = $conn->prepare("SELECT COUNT(*) AS total FROM games");
                    if ($st_cnt) {
                        $st_cnt->execute();
                        $row_cnt = $st_cnt->get_result()->fetch_assoc();
                        $total_items = intval($row_cnt['total'] ?? 0);
                        $st_cnt->close();
                    }
                    $total_pages = max(1, (int)ceil($total_items / $per_page));
                    $page = min($page, $total_pages);
                    $offset = ($page - 1) * $per_page;

                    $sql = "SELECT g.*, c.cate_name,
                            (SELECT COUNT(*) FROM game_accounts ga WHERE ga.game_id = g.game_id) as total_acc,
                            (SELECT COUNT(*) FROM game_accounts ga WHERE ga.game_id = g.game_id AND ga.status = 'AVAILABLE') as avail_acc,
                            (SELECT MIN(price) FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.is_active = 1) as min_rent_price,
                            (SELECT COUNT(*) FROM rental_packages rp WHERE rp.game_id = g.game_id AND rp.is_active = 1) as total_packages
                            FROM games g 
                            LEFT JOIN categories c ON g.category_id = c.category_id 
                            ORDER BY g.game_id DESC
                            LIMIT ? OFFSET ?";
                    $result = false;
                    $st_g = $conn->prepare($sql);
                    if ($st_g) {
                        $st_g->bind_param("ii", $per_page, $offset);
                        if ($st_g->execute()) {
                            $result = $st_g->get_result();
                        }
                        $st_g->close();
                    }
                    if (!$result) {
                        $sql_fallback = "SELECT g.*, c.cate_name, g.quantity as total_acc, g.quantity as avail_acc, 5000 as min_rent_price, 4 as total_packages 
                                         FROM games g 
                                         LEFT JOIN categories c ON g.category_id = c.category_id 
                                         ORDER BY g.game_id DESC
                                         LIMIT ? OFFSET ?";
                        $st_fb = $conn->prepare($sql_fallback);
                        if ($st_fb) {
                            $st_fb->bind_param("ii", $per_page, $offset);
                            if ($st_fb->execute()) {
                                $result = $st_fb->get_result();
                            }
                            $st_fb->close();
                        }
                    }
                    
                    if($result && $result->num_rows > 0):
                        while($row = $result->fetch_assoc()):
                            $total_acc = intval($row['total_acc']);
                            $avail_acc = intval($row['avail_acc']);
                            $min_price = floatval($row['min_rent_price'] ?? 0);
                            $total_pkgs = intval($row['total_packages'] ?? 0);
                    ?>
                        <tr>
                            <td class="text-secondary fw-bold">#<?php echo $row['game_id']; ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <?php echo render_game_thumb($row['image_url'] ?? '', $row['title'] ?? '', 'anplay-thumb', 'width: 56px; height: 36px; border-radius: 6px; border: 1px solid var(--border-color);'); ?>
                                    <div>
                                        <a href="game_detail.php?id=<?php echo $row['game_id']; ?>" target="_blank" class=" text-decoration-none fw-bold hover-info">
                                            <?php echo htmlspecialchars($row['title']); ?>
                                        </a>
                                        <div class="mt-1">
                                            <span class="badge" style="background: rgba(255,255,255,0.06); color: #94a3b8; font-size: 0.7rem; border: 1px solid var(--border-color);">
                                                <?php echo htmlspecialchars($row['platform'] ?? 'PC'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class=" small"><?php echo $row['cate_name'] ? htmlspecialchars($row['cate_name']) : '<span class="text-secondary">Chưa phân loại</span>'; ?></span>
                            </td>
                            <td>
                                <span class="status-pill status-pill-success"><span class="status-dot"></span> Cho thuê tài khoản</span>
                            </td>
                            <td>
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="fw-bold fs-6 <?php echo ($avail_acc > 0) ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo $avail_acc; ?>
                                        </span>
                                        <span class="text-secondary small">/ <?php echo $total_acc; ?> tài khoản</span>
                                        <?php if ($avail_acc == 0): ?>
                                            <span class="badge bg-danger bg-opacity-20 text-danger border border-danger border-opacity-25" style="font-size: 0.68rem;">Hết TK</span>
                                        <?php endif; ?>
                                    </div>
                                    <a href="admin_accounts.php?filter_game=<?php echo $row['game_id']; ?>" class="small text-decoration-none" style="color: var(--accent-cyan); font-size: 0.78rem;">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Xem kho TK game này
                                    </a>
                                </div>
                            </td>
                            <td>
                                <?php if ($min_price > 0): ?>
                                    <span class="fw-bold text-warning"><?php echo number_format($min_price, 0, ',', '.'); ?>đ</span>
                                    <small class="text-secondary d-block" style="font-size: 0.72rem;"><?php echo $total_pkgs; ?> gói thời hạn</small>
                                <?php else: ?>
                                    <span class="badge bg-warning bg-opacity-20 text-warning border border-warning border-opacity-25" style="font-size: 0.75rem;">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Chưa cấu hình
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex gap-2">
                                    <a href="admin_edit_game.php?id=<?php echo intval($row['game_id']); ?>" class="btn btn-sm btn-gaming-secondary py-1 px-3">
                                        <i class="bi bi-pencil-square me-1 text-warning"></i> Sửa & Giá
                                    </a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Bạn có chắc chắn muốn xóa tựa game này khỏi hệ thống?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="delete_id" value="<?php echo intval($row['game_id']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Xóa game">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php 
                        endwhile;
                    else: 
                    ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-secondary">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Chưa có tựa game nào trong hệ thống. Hãy bấm <strong>+ Thêm Game Mới</strong> để tạo!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (function_exists('render_pagination')) { echo render_pagination($page, $total_pages, $total_items, $per_page, $_GET); } ?>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>