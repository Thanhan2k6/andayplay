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
    $conn->query("DELETE FROM games WHERE game_id = $del_id");
    echo "<script>alert('Đã xóa game thành công!'); window.location='admin_games.php';</script>";
}
?>

<div class="container mt-4 text-white">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>📦 Quản Lý Kho Game</h2>
        <a href="admin_add_game.php" class="btn btn-success fw-bold">+ Thêm Game Mới</a>
    </div>
    
    <div class="table-responsive shadow">
        <table class="table table-dark table-hover table-bordered align-middle text-center">
            <thead class="table-secondary">
                <tr>
                    <th style="width: 5%;">ID</th>
                    <th class="text-start" style="width: 25%;">Tên Game</th>
                    <th style="width: 15%;">Thể Loại</th>
                    <th style="width: 15%;">Hình Thức</th>
                    <th style="width: 20%;">Kho (Sẵn sàng/Tổng)</th>
                    <th style="width: 20%;">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Lấy danh sách game và tên thể loại
                $sql = "SELECT games.*, categories.cate_name 
                        FROM games 
                        LEFT JOIN categories ON games.category_id = categories.category_id 
                        ORDER BY game_id DESC";
                $result = $conn->query($sql);
                
                if($result && $result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . $row['game_id'] . "</td>";
                        echo "<td class='fw-bold text-info text-start'>" . $row['title'] . "</td>";
                        echo "<td>" . ($row['cate_name'] ? $row['cate_name'] : '<span class="text-muted">Chưa phân loại</span>') . "</td>";
                        
                        // Phân loại hình thức thu phí
                        echo "<td>";
                        if(isset($row['price_type']) && $row['price_type'] == 'free') {
                            echo "<span class='badge bg-primary'>Miễn phí</span>";
                        } else {
                            echo "<span class='badge bg-warning text-dark'>Trả phí (Thuê)</span>";
                        }
                        echo "</td>";

                        // Cột Kho dữ liệu
                        echo "<td>";
                        if(isset($row['price_type']) && $row['price_type'] == 'free') {
                            echo "<span class='text-muted small'>Link tải chính thức</span>";
                        } else {
                            echo "<span class='fw-bold'>" . $row['available_quantity'] . "</span> / " . $row['total_quantity'];
                        }
                        echo "</td>";

                        // Nút hành động
                        echo "<td>
                                <a href='admin_edit_game.php?id=" . $row['game_id'] . "' class='btn btn-sm btn-warning me-1 px-3'>Sửa</a>
                                <a href='admin_games.php?delete_id=" . $row['game_id'] . "' class='btn btn-sm btn-danger px-3' onclick='return confirm(\"Bạn có chắc chắn muốn xóa tựa game này khỏi hệ thống?\")'>Xóa</a>
                              </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' class='text-center py-4'>Chưa có tựa game nào trong hệ thống. Hãy thêm game mới!</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>