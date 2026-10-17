<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/connect.php';
require_admin();
require_once __DIR__ . '/includes/header.php';

// XỬ LÝ: Xóa game
if(isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    // Kiểm tra xem game có tài khoản đang được thuê không
    $chk_rent = $conn->query("SELECT COUNT(*) as c FROM game_accounts WHERE game_id = $del_id AND status = 'RENTED'")->fetch_assoc();
    if ($chk_rent['c'] > 0) {
        echo "<script>alert('Không thể xóa tựa game đang có tài khoản được khách hàng thuê!'); window.location='admin_games.php';</script>";
    } else {
        $conn->query("DELETE FROM games WHERE game_id = $del_id");
        $conn->query("DELETE FROM game_accounts WHERE game_id = $del_id");
        $conn->query("DELETE FROM rental_packages WHERE game_id = $del_id");
        echo "<script>alert('Đã xóa game và dữ liệu liên quan thành công!'); window.location='admin_games.php';</script>";
    }
}
?>

<div class="container-fluid px-lg-5 py-4 my-2 text-white">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); font-size: 0.75rem; border: 1px solid rgba(99,102,241,0.4);">
                    <i class="bi bi-controller me-1"></i> STORE MANAGEMENT
                </span>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Quản Lý Danh Sách & Giá Game</h2>
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
                        <th style="width: 28%;">Tựa Game & Nền Tảng</th>
                        <th style="width: 14%;">Thể Loại</th>
                        <th style="width: 13%;">Mô Hình Bán</th>
                        <th style="width: 22%;">Kho Tài Khoản (Sẵn / Tổng)</th>
                        <th style="width: 18%;" class="text-end pe-3">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT g.*, c.cate_name,
                            (SELECT COUNT(*) FROM game_accounts ga WHERE ga.game_id = g.game_id) as total_acc,
                            (SELECT COUNT(*) FROM game_accounts ga WHERE ga.game_id = g.game_id AND ga.status = 'AVAILABLE') as avail_acc
                            FROM games g 
                            LEFT JOIN categories c ON g.category_id = c.category_id 
                            ORDER BY g.game_id DESC";
                    $result = false;
                    try {
                        $result = $conn->query($sql);
                    } catch (Throwable $e) {}
                    if (!$result) {
                        try {
                            $sql_fallback = "SELECT g.*, c.cate_name, g.quantity as total_acc, g.quantity as avail_acc 
                                             FROM games g 
                                             LEFT JOIN categories c ON g.category_id = c.category_id 
                                             ORDER BY g.game_id DESC";
                            $result = $conn->query($sql_fallback);
                        } catch (Throwable $e) {}
                    }
                    
                    if($result && $result->num_rows > 0):
                        while($row = $result->fetch_assoc()):
                            $total_acc = intval($row['total_acc']);
                            $avail_acc = intval($row['avail_acc']);
                    ?>
                        <tr>
                            <td class="text-secondary fw-bold">#<?php echo $row['game_id']; ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <?php if (!empty($row['image_url']) && file_exists($row['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($row['image_url']); ?>" alt="" style="width: 50px; height: 35px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color);">
                                    <?php else: ?>
                                        <div style="width: 50px; height: 35px; background: #151824; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.65rem; color: #64748b; border: 1px solid var(--border-color);">COVER</div>
                                    <?php endif; ?>
                                    <div>
                                        <a href="game_detail.php?id=<?php echo $row['game_id']; ?>" target="_blank" class="text-white text-decoration-none fw-bold hover-info">
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
                                <span class="text-light small"><?php echo $row['cate_name'] ? htmlspecialchars($row['cate_name']) : '<span class="text-secondary">Chưa phân loại</span>'; ?></span>
                            </td>
                            <td>
                                <?php if(isset($row['price_type']) && $row['price_type'] == 'free'): ?>
                                    <span class="status-pill status-pill-info"><span class="status-dot"></span> Miễn phí</span>
                                <?php else: ?>
                                    <span class="status-pill status-pill-success"><span class="status-dot"></span> Cho thuê tài khoản</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(isset($row['price_type']) && $row['price_type'] == 'free'): ?>
                                    <span class="text-secondary small">Bộ cài miễn phí</span>
                                <?php else: ?>
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="fw-bold fs-6 <?php echo ($avail_acc > 0) ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $avail_acc; ?>
                                            </span>
                                            <span class="text-secondary small">/ <?php echo $total_acc; ?> tài khoản</span>
                                        </div>
                                        <a href="admin_accounts.php?filter_game=<?php echo $row['game_id']; ?>" class="small text-decoration-none" style="color: var(--accent-cyan); font-size: 0.78rem;">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>Xem kho TK game này
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex gap-2">
                                    <a href="admin_edit_game.php?id=<?php echo $row['game_id']; ?>" class="btn btn-sm btn-gaming-secondary py-1 px-3">
                                        <i class="bi bi-pencil-square me-1 text-warning"></i> Sửa & Giá
                                    </a>
                                    <a href="admin_games.php?delete_id=<?php echo $row['game_id']; ?>" class="btn btn-sm btn-outline-danger py-1 px-2" onclick="return confirm('Bạn có chắc chắn muốn xóa tựa game này khỏi hệ thống?')" title="Xóa game">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php 
                        endwhile;
                    else: 
                    ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-secondary">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Chưa có tựa game nào trong hệ thống. Hãy bấm <strong>+ Thêm Game Mới</strong> để tạo!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>