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

$result = false;
try {
    $result = $conn->query($sql);
} catch (Throwable $e) {}
if (!$result) {
    try {
        $sql_fallback = "SELECT br.*, u.username, u.email, g.title, '' as account_username, 'Tiêu chuẩn' as package_name, '' as order_code
                        FROM borrow_records br 
                        JOIN users u ON br.user_id = u.user_id 
                        JOIN games g ON br.game_id = g.game_id 
                        ORDER BY br.borrow_id DESC";
        $result = $conn->query($sql_fallback);
    } catch (Throwable $e) {}
}
?>

<div class="container-fluid px-lg-5 py-4 my-2 text-white">
    <!-- Top Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); font-size: 0.75rem; border: 1px solid rgba(99,102,241,0.4);">
                    <i class="bi bi-card-checklist me-1"></i> RENTAL ORDERS
                </span>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Quản Lý Đơn Thuê Tài Khoản Game</h2>
            <p class="text-secondary small mb-0">Theo dõi lịch sử đơn, tiến độ hạn thuê, doanh thu, thanh toán VietQR và xử lý thu hồi sớm</p>
        </div>
        <div class="d-flex gap-2">
            <a href="cron_process_expirations.php?secret_token=ANPLAY_CRON_2026" class="btn btn-gaming-secondary d-flex align-items-center">
                <i class="bi bi-arrow-clockwise me-1 text-info"></i> Quét Hết Hạn Ngay
            </a>
            <a href="admin_accounts.php" class="btn btn-gaming-primary d-flex align-items-center">
                <i class="bi bi-box-seam me-1"></i> Quản Lý Kho TK
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-dismissible fade show mb-4 text-white" style="background: rgba(16,185,129,0.15); border: 1px solid #10b981;" role="alert">
            <i class="bi bi-check-circle-fill text-success me-2"></i><?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- THANH TÌM KIẾM VÀ LỌC -->
    <div class="p-3 mb-4 rounded-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label text-secondary small fw-bold mb-1">Tìm kiếm từ khóa:</label>
                <input type="text" name="search" class="form-control form-control-gaming" placeholder="Mã đơn, Tên người dùng, Tựa game, Username TK..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label text-secondary small fw-bold mb-1">Trạng thái đơn thuê:</label>
                <select name="status" class="form-select form-control-gaming">
                    <option value="">-- Tất cả trạng thái --</option>
                    <option value="active" <?php echo ($filter_status == 'active') ? 'selected' : ''; ?>>Active (Đang hiệu lực)</option>
                    <option value="expired" <?php echo ($filter_status == 'expired') ? 'selected' : ''; ?>>Expired (Đã hết hạn)</option>
                    <option value="returned" <?php echo ($filter_status == 'returned') ? 'selected' : ''; ?>>Returned (Đã thu hồi về kho)</option>
                    <option value="pending" <?php echo ($filter_status == 'pending') ? 'selected' : ''; ?>>Pending (Chờ xử lý / Chưa cấp TK)</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-gaming-primary px-4 flex-grow-1">
                    <i class="bi bi-search me-1"></i> Tìm Kiếm
                </button>
                <a href="admin_borrow.php" class="btn btn-gaming-secondary">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Table Container -->
    <div class="table-gaming-container">
        <div class="table-responsive">
            <table class="table table-gaming align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 10%;">Mã Đơn</th>
                        <th style="width: 18%;">Khách Hàng</th>
                        <th style="width: 18%;">Tựa Game & Gói</th>
                        <th style="width: 16%;">Tài Khoản Được Cấp</th>
                        <th style="width: 14%;">Thời Hạn Thuê</th>
                        <th style="width: 12%;">Thanh Toán</th>
                        <th style="width: 12%;" class="text-end pe-3">Hành Động</th>
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
                        <td>
                            <strong class="text-info">#<?php echo $row['borrow_id']; ?></strong>
                            <?php if(!empty($row['order_code'])): ?>
                                <div class="text-secondary small font-monospace" style="font-size: 0.72rem;"><?php echo htmlspecialchars($row['order_code']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($row['username']); ?>&background=random&color=fff&rounded=true" width="28" height="28" class="rounded-circle">
                                <div>
                                    <strong class="text-white small">@<?php echo htmlspecialchars($row['username']); ?></strong>
                                    <div class="text-secondary small" style="font-size: 0.72rem;"><?php echo htmlspecialchars($row['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <strong class="text-light"><?php echo htmlspecialchars($row['title']); ?></strong>
                            <div class="small text-secondary" style="font-size: 0.72rem;">
                                <?php echo htmlspecialchars($row['package_name'] ?? 'Chuẩn'); ?> (<?php echo intval($row['duration_hours'] ?? 24); ?>h)
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($row['account_username'])): ?>
                                <span class="badge" style="background: rgba(99,102,241,0.15); color: #c7d2fe; border: 1px solid rgba(99,102,241,0.3); font-size: 0.78rem;">
                                    <i class="bi bi-person-fill me-1"></i><?php echo htmlspecialchars($row['account_username']); ?>
                                </span>
                            <?php else: ?>
                                <span class="status-pill status-pill-danger"><span class="status-dot"></span> Chưa cấp TK</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="small text-light d-block"><?php echo date('d/m/Y H:i', strtotime($row['due_date'])); ?></span>
                            <?php if ($is_overdue): ?>
                                <span class="badge bg-danger bg-opacity-75 text-white" style="font-size: 0.68rem;">ĐÃ QUÁ HẠN</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['payment_status'] === 'paid'): ?>
                                <span class="status-pill status-pill-success"><span class="status-dot"></span> Đã trả</span>
                                <div class="text-warning small fw-bold mt-1"><?php echo number_format($row['rental_price'] ?? 0, 0, ',', '.'); ?>đ</div>
                            <?php else: ?>
                                <span class="status-pill status-pill-warning"><span class="status-dot"></span> <?php echo htmlspecialchars($row['payment_status'] ?? 'pending'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <?php if ($row['status'] == 'active'): ?>
                                <a href="admin_borrow.php?revoke_id=<?php echo $row['borrow_id']; ?>" 
                                   class="btn btn-sm btn-outline-danger py-1 px-3"
                                   onclick="return confirm('Bạn có chắc chắn muốn thu hồi tài khoản này về kho sớm?');">
                                   Thu hồi
                                </a>
                            <?php elseif ($row['payment_status'] == 'paid' && empty($row['account_id'])): ?>
                                <a href="admin_borrow.php?retry_allocate_id=<?php echo $row['borrow_id']; ?>" class="btn btn-sm btn-warning fw-bold text-dark py-1 px-2">
                                    Cấp bù TK
                                </a>
                            <?php else: ?>
                                <span class="badge" style="background: rgba(255,255,255,0.05); color: #64748b; font-size: 0.72rem;">Đã xong</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-secondary">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i> Không có đơn thuê nào phù hợp tiêu chí tìm kiếm.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>