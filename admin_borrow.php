<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/header.php'; 

// Bảo mật quyền Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    echo "<script>window.location='index.php';</script>";
    exit();
}

$message = "";
$error = "";

// 1. XỬ LÝ THU HỒI TÀI KHOẢN THỦ CÔNG
if (isset($_GET['revoke_id'])) {
    $borrow_id = intval($_GET['revoke_id']);
    
    $check_sql = $conn->query("SELECT * FROM borrow_records WHERE borrow_id = $borrow_id");
    if ($check_sql && $check_sql->num_rows > 0) {
        $record = $check_sql->fetch_assoc();
        $g_id = intval($record['game_id']);
        $a_id = intval($record['account_id']);
        $u_id = intval($record['user_id']);

        $conn->begin_transaction();
        try {
            // Cập nhật đơn thuê thành returned
            $conn->query("UPDATE borrow_records SET status = 'returned', revoke_status = 'revoked' WHERE borrow_id = $borrow_id");
            
            // Cập nhật tài khoản về AVAILABLE hoặc MAINTENANCE nếu cần đổi mật khẩu
            if ($a_id > 0) {
                $conn->query("UPDATE game_accounts SET status = 'AVAILABLE', current_borrow_id = NULL, rent_expires_at = NULL WHERE account_id = $a_id");
                log_account_allocation($conn, $a_id, $borrow_id, $u_id, 'MANUAL_REVOKED', "Admin thu hồi tài khoản thủ công", 'ADMIN: ' . $_SESSION['username']);
                sync_game_inventory($conn, $g_id);
            }

            $conn->commit();
            $message = "Đã xác nhận thu hồi tài khoản cho đơn #$borrow_id thành công! Tài khoản đã sẵn sàng cho lượt thuê mới.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Lỗi khi thu hồi: " . $e->getMessage();
        }
    }
}

// 2. XỬ LÝ CẤP LẠI TÀI KHOẢN (CHO CÁC ĐƠN BỊ LỖI KHI CẤP PHÁT BAN ĐẦU)
if (isset($_GET['retry_allocate_id'])) {
    $retry_b_id = intval($_GET['retry_allocate_id']);
    $ret_order = $conn->query("SELECT * FROM borrow_records WHERE borrow_id = $retry_b_id")->fetch_assoc();
    if ($ret_order) {
        $alloc_res = allocate_game_account($conn, $ret_order['game_id'], $ret_order['user_id'], $retry_b_id, $ret_order['duration_hours']);
        if ($alloc_res['success']) {
            $message = "Đã cấp bù tài khoản thành công cho đơn #$retry_b_id!";
        } else {
            $error = "Cấp bù thất bại: " . $alloc_res['error'];
        }
    }
}

// LỌC VÀ TÌM KIẾM
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';

$where = [];
if (!empty($search)) {
    $where[] = "(br.order_code LIKE '%$search%' OR u.username LIKE '%$search%' OR g.title LIKE '%$search%' OR ga.account_username LIKE '%$search%')";
}
if (!empty($filter_status)) {
    $where[] = "br.status = '$filter_status'";
}
$where_str = count($where) > 0 ? " WHERE " . implode(" AND ", $where) : "";

// Lấy danh sách toàn bộ đơn thuê từ Database
$sql = "SELECT br.*, u.username, u.email, g.title, ga.account_username, rp.package_name
        FROM borrow_records br 
        JOIN users u ON br.user_id = u.user_id 
        JOIN games g ON br.game_id = g.game_id 
        LEFT JOIN game_accounts ga ON br.account_id = ga.account_id
        LEFT JOIN rental_packages rp ON br.package_id = rp.package_id
        $where_str
        ORDER BY br.borrow_id DESC"; 
$result = $conn->query($sql);
?>

<div class="container-fluid mt-4 mb-5 text-white">
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary">
        <div>
            <h2 class="fw-bold m-0 text-warning"><i class="bi bi-card-checklist me-2"></i> QUẢN LÝ ĐƠN THUÊ TÀI KHOẢN</h2>
            <p class="text-secondary small mb-0">Theo dõi toàn bộ lịch sử thuê, thanh toán, hạn trả, gia hạn và xử lý sự cố cấp phát</p>
        </div>
        <div>
            <a href="cron_process_expirations.php?secret_token=ANPLAY_CRON_2026" class="btn btn-outline-info fw-bold btn-sm me-2">
                <i class="bi bi-arrow-clockwise me-1"></i> Quét Đơn Hết Hạn Ngay
            </a>
            <a href="admin_accounts.php" class="btn btn-success fw-bold btn-sm">
                <i class="bi bi-box-seam me-1"></i> Quản Lý Kho Tài Khoản
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- THANH TÌM KIẾM VÀ LỌC -->
    <div class="card p-3 mb-4 border-0 shadow-sm" style="background-color: #1a222c;">
        <form method="GET" action="" class="row g-3 align-items-center">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control bg-dark text-white border-secondary" placeholder="Tìm theo Mã đơn, Khách hàng, Tựa game, Tên tài khoản..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select bg-dark text-white border-secondary">
                    <option value="">-- Tất cả trạng thái đơn --</option>
                    <option value="active" <?php echo ($filter_status == 'active') ? 'selected' : ''; ?>>Active (Đang thuê)</option>
                    <option value="expired" <?php echo ($filter_status == 'expired') ? 'selected' : ''; ?>>Expired (Đã hết hạn)</option>
                    <option value="returned" <?php echo ($filter_status == 'returned') ? 'selected' : ''; ?>>Returned (Đã thu hồi)</option>
                    <option value="pending" <?php echo ($filter_status == 'pending') ? 'selected' : ''; ?>>Pending (Chờ xử lý / Lỗi cấp phát)</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary fw-bold px-3">Tìm Kiếm</button>
                <a href="admin_borrow.php" class="btn btn-outline-secondary ms-2">Đặt lại</a>
            </div>
        </form>
    </div>

    <div class="card shadow border-0" style="background-color: #1a222c; border-radius: 10px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0">
                    <thead style="background-color: #151d26;">
                        <tr>
                            <th class="py-3 ps-3 text-info">Mã Đơn</th>
                            <th class="py-3">Khách Hàng</th>
                            <th class="py-3">Tựa Game</th>
                            <th class="py-3">Tài Khoản Được Cấp</th>
                            <th class="py-3">Hạn Thuê</th>
                            <th class="py-3">Thanh Toán</th>
                            <th class="py-3">Trạng Thái Đơn</th>
                            <th class="py-3 text-center">Hành Động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): while ($row = $result->fetch_assoc()): 
                            $is_overdue = false;
                            if ($row['status'] == 'active' && strtotime($row['due_date']) < time()) {
                                $is_overdue = true;
                            }
                        ?>
                        <tr>
                            <td class="ps-3 text-info fw-bold">
                                #<?php echo $row['borrow_id']; ?>
                                <br><small class="text-secondary"><?php echo htmlspecialchars($row['order_code'] ?? ''); ?></small>
                            </td>
                            <td>
                                <strong class="text-warning">@<?php echo htmlspecialchars($row['username']); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($row['email']); ?></small>
                            </td>
                            <td class="fw-bold text-white"><?php echo htmlspecialchars($row['title']); ?></td>
                            <td>
                                <?php if (!empty($row['account_username'])): ?>
                                    <span class="badge bg-dark border border-info text-info px-2 py-1 user-select-all"><?php echo htmlspecialchars($row['account_username']); ?></span>
                                <?php else: ?>
                                    <span class="text-danger small fst-italic">Chưa cấp tài khoản</span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php echo date('d/m/Y H:i', strtotime($row['due_date'])); ?>
                                <?php if ($is_overdue): ?>
                                    <span class="badge bg-danger ms-1">Quá hạn</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['payment_status'] === 'paid'): ?>
                                    <span class="badge bg-success">Đã thanh toán</span>
                                    <br><small class="text-warning fw-bold"><?php echo number_format($row['rental_price'] ?? 0, 0, ',', '.'); ?>đ</small>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($row['payment_status'] ?? 'pending'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['status'] == 'active'): ?>
                                    <span class="badge bg-info text-dark fw-bold px-2 py-1">Đang thuê</span>
                                <?php elseif ($row['status'] == 'expired'): ?>
                                    <span class="badge bg-danger fw-bold px-2 py-1">Đã hết hạn</span>
                                <?php elseif ($row['status'] == 'returned'): ?>
                                    <span class="badge bg-success fw-bold px-2 py-1">Đã thu hồi</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark fw-bold px-2 py-1"><?php echo htmlspecialchars($row['status']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($row['status'] == 'active'): ?>
                                    <a href="admin_borrow.php?revoke_id=<?php echo $row['borrow_id']; ?>" 
                                       class="btn btn-sm btn-danger fw-bold px-3 shadow-sm"
                                       onclick="return confirm('Bạn có chắc chắn muốn thu hồi tài khoản này về kho sớm?');">
                                       Thu hồi
                                    </a>
                                <?php elseif ($row['payment_status'] == 'paid' && empty($row['account_id'])): ?>
                                    <a href="admin_borrow.php?retry_allocate_id=<?php echo $row['borrow_id']; ?>" class="btn btn-sm btn-warning fw-bold px-2">
                                        Cấp bù TK
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary px-2" disabled>Hoàn tất</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-secondary">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i> Không có đơn thuê nào phù hợp tiêu chí tìm kiếm.
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