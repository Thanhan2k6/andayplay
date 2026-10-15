<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php'; 

// BẢO MẬT: Kiểm tra quyền Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    echo "<script>alert('Từ chối truy cập! Bạn không phải Quản trị viên.'); window.location='index.php';</script>";
    exit();
}

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

<div class="container-fluid mt-4 text-white">
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary">
        <div>
            <h2 class="fw-bold m-0 text-info">📦 Quản Lý Cửa Hàng & Kho Game</h2>
            <p class="text-secondary small mb-0">Quản lý danh sách tựa game, nền tảng, thiết lập giá thuê và theo dõi số lượng tài khoản</p>
        </div>
        <div>
            <a href="admin_accounts.php" class="btn btn-primary fw-bold me-2"><i class="bi bi-box-seam me-1"></i> Quản Lý Kho Tài Khoản</a>
            <a href="admin_add_game.php" class="btn btn-success fw-bold"><i class="bi bi-plus-circle me-1"></i> + Thêm Game Mới</a>
        </div>
    </div>
    
    <div class="table-responsive shadow">
        <table class="table table-dark table-hover table-bordered align-middle text-center">
            <thead class="table-secondary">
                <tr>
                    <th style="width: 5%;">ID</th>
                    <th class="text-start" style="width: 25%;">Tên Game</th>
                    <th style="width: 12%;">Thể Loại</th>
                    <th style="width: 10%;">Nền Tảng</th>
                    <th style="width: 12%;">Mô Hình</th>
                    <th style="width: 18%;">Kho Tài Khoản (Sẵn / Tổng)</th>
                    <th style="width: 18%;">Hành Động</th>
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
                $result = $conn->query($sql);
                
                if($result && $result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        $total_acc = intval($row['total_acc']);
                        $avail_acc = intval($row['avail_acc']);

                        echo "<tr>";
                        echo "<td>#" . $row['game_id'] . "</td>";
                        echo "<td class='fw-bold text-info text-start'>" . htmlspecialchars($row['title']) . "</td>";
                        echo "<td>" . ($row['cate_name'] ? htmlspecialchars($row['cate_name']) : '<span class="text-muted">Chưa phân loại</span>') . "</td>";
                        echo "<td><span class='badge bg-secondary'>" . htmlspecialchars($row['platform']) . "</span></td>";
                        
                        echo "<td>";
                        if(isset($row['price_type']) && $row['price_type'] == 'free') {
                            echo "<span class='badge bg-primary'>Miễn phí</span>";
                        } else {
                            echo "<span class='badge bg-warning text-dark'>Cho thuê tài khoản</span>";
                        }
                        echo "</td>";

                        echo "<td>";
                        if(isset($row['price_type']) && $row['price_type'] == 'free') {
                            echo "<span class='text-muted small'>Link tải chính thức</span>";
                        } else {
                            $badge_avail = ($avail_acc > 0) ? 'text-success' : 'text-danger';
                            echo "<span class='fw-bold fs-6 $badge_avail'>" . $avail_acc . "</span> / " . $total_acc . " tài khoản";
                            echo "<br><a href='admin_accounts.php?filter_game=" . $row['game_id'] . "' class='small text-info text-decoration-none'>[Quản lý kho TK]</a>";
                        }
                        echo "</td>";

                        echo "<td>
                                <a href='admin_edit_game.php?id=" . $row['game_id'] . "' class='btn btn-sm btn-warning me-1 px-3 fw-bold'>Sửa & Giá</a>
                                <a href='admin_games.php?delete_id=" . $row['game_id'] . "' class='btn btn-sm btn-danger px-3' onclick='return confirm(\"Bạn có chắc chắn muốn xóa tựa game này khỏi hệ thống?\")'>Xóa</a>
                              </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='7' class='text-center py-4'>Chưa có tựa game nào trong hệ thống. Hãy thêm game mới!</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>