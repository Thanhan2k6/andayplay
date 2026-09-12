<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php'; 

// Bảo mật quyền Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    echo "<script>window.location='index.php';</script>";
    exit();
}

// XỬ LÝ SỰ KIỆN: Khách hàng trả game
if (isset($_GET['return_id'])) {
    $borrow_id = intval($_GET['return_id']);
    
    $check_sql = $conn->query("SELECT game_id, status FROM borrow_records WHERE borrow_id = $borrow_id");
    
    if ($check_sql && $check_sql->num_rows > 0) {
        $record = $check_sql->fetch_assoc();
        
        if ($record['status'] == 'active') {
            $g_id = $record['game_id'];
            
            $conn->query("UPDATE borrow_records SET status = 'returned' WHERE borrow_id = $borrow_id");
            $conn->query("UPDATE games SET available_quantity = available_quantity + 1 WHERE game_id = $g_id");
            
            echo "<script>alert('Xác nhận thu hồi game thành công! Đã hoàn lại 1 bản vào kho.'); window.location='admin_borrow.php';</script>";
            exit();
        }
    }
}

// Lấy danh sách toàn bộ đơn mượn từ Database
$sql = "SELECT br.borrow_id, br.due_date, br.status, u.username, g.title 
        FROM borrow_records br 
        JOIN users u ON br.user_id = u.user_id 
        JOIN games g ON br.game_id = g.game_id 
        ORDER BY br.status ASC, br.due_date ASC"; 
$result = $conn->query($sql);
?>

<div class="container mt-4 mb-5">
    <h2 class="mb-4 text-white fw-bold">📝 Quản Lý Đơn Mượn Game</h2>
    
    <div class="card shadow border-0" style="background-color: #243447; border-radius: 10px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0" style="background-color: transparent;">
                    <thead class="text-uppercase text-light fs-7" style="background-color: #1e293b; border-bottom: 2px solid #47bfff;">
                        <tr>
                            <th class="py-3 ps-4 text-info">Mã Đơn</th>
                            <th class="py-3 text-white">Người Mượn</th>
                            <th class="py-3 text-white">Tên Game</th>
                            <th class="py-3 text-white">Hạn Trả</th>
                            <th class="py-3 text-white">Trạng Thái</th>
                            <th class="py-3 text-center text-white">Hành Động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): while ($row = $result->fetch_assoc()): 
                            $is_overdue = false;
                            if ($row['status'] == 'active' && strtotime($row['due_date']) < time()) {
                                $is_overdue = true;
                            }
                        ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.08);">
                            <td class="ps-4 text-info fw-bold">#<?php echo $row['borrow_id']; ?></td>
                            <td class="fw-bold text-warning">@<?php echo htmlspecialchars($row['username']); ?></td>
                            <td class="fw-bold text-white"><?php echo htmlspecialchars($row['title']); ?></td>
                            
                            <td class="text-light">
                                <?php echo date('d/m/Y H:i', strtotime($row['due_date'])); ?>
                                <?php if ($is_overdue) echo ' <span class="badge bg-danger ms-1">Quá hạn</span>'; ?>
                            </td>
                            
                            <td>
                                <?php if ($row['status'] == 'active'): ?>
                                    <span class="badge bg-info text-dark fw-bold px-2 py-1">Đang mượn</span>
                                <?php else: ?>
                                    <span class="badge bg-success fw-bold px-2 py-1">Đã trả</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center">
                                <?php if ($row['status'] == 'active'): ?>
                                    <a href="admin_borrow.php?return_id=<?php echo $row['borrow_id']; ?>" 
                                       class="btn btn-sm btn-success fw-bold px-3 shadow-sm"
                                       onclick="return confirm('Bạn có chắc chắn khách hàng đã trả tựa game này? Kho sẽ được tự động cộng thêm 1 bản.');">
                                       Xác nhận Trả
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-secondary px-3" disabled>Hoàn tất</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <div class="py-3">
                                    <h5 class="text-light mb-1">Chưa có đơn mượn nào trong hệ thống</h5>
                                    <p class="small text-secondary mb-0">Khi người dùng đăng ký thuê game, danh sách đơn sẽ hiển thị sáng sủa ở đây.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>