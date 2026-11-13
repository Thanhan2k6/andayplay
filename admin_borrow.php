<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$page_title = "Quản Lý Đơn Thuê & Giao Dịch";
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/includes/payment_service.php';

require_permission('order.view');

$message = "";
$error = "";

// XỬ LÝ CÁC HÀNH ĐỘNG THAY ĐỔI TRẠNG THÁI (BẮT BUỘC POST + CSRF + order.manage)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Mã xác thực bảo mật không hợp lệ. Vui lòng tải lại trang.";
    } else {
        require_permission('order.manage');
        $admin_id = intval($_SESSION['user_id']);
        $admin_name = $_SESSION['username'] ?? 'admin';

        // 1. THU HỒI TÀI KHOẢN THỦ CÔNG
        if (isset($_POST['revoke_id'])) {
            $borrow_id = intval($_POST['revoke_id']);
            $st_chk = $conn->prepare("SELECT borrow_id, game_id, account_id, user_id FROM borrow_records WHERE borrow_id = ? LIMIT 1");
            if ($st_chk) {
                $st_chk->bind_param("i", $borrow_id);
                $st_chk->execute();
                $record = $st_chk->get_result()->fetch_assoc();
                $st_chk->close();

                if ($record) {
                    $g_id = intval($record['game_id']);
                    $a_id = intval($record['account_id'] ?? 0);
                    $u_id = intval($record['user_id']);

                    $conn->begin_transaction();
                    try {
                        $u_br = $conn->prepare("UPDATE borrow_records SET status = 'returned', revoke_status = 'revoked' WHERE borrow_id = ?");
                        $u_br->bind_param("i", $borrow_id);
                        $u_br->execute();
                        $u_br->close();

                        if ($a_id > 0) {
                            $u_ga = $conn->prepare("UPDATE game_accounts SET status = 'AVAILABLE', current_borrow_id = NULL, rent_expires_at = NULL WHERE account_id = ?");
                            $u_ga->bind_param("i", $a_id);
                            $u_ga->execute();
                            $u_ga->close();
                            log_account_allocation($conn, $a_id, $borrow_id, $u_id, 'MANUAL_REVOKED', "Admin thu hồi tài khoản thủ công", 'ADMIN: ' . $admin_name);
                            sync_game_inventory($conn, $g_id);
                        }

                        $conn->commit();
                        write_audit_log($conn, $admin_id, 'REVOKE_RENTAL', 'borrow_record', $borrow_id, ['account_id' => $a_id]);
                        $message = "Đã xác nhận thu hồi tài khoản cho đơn #$borrow_id thành công!";
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = "Lỗi khi thu hồi: " . $e->getMessage();
                    }
                }
            }
        }

        // 2. CẤP LẠI TÀI KHOẢN
        if (isset($_POST['retry_allocate_id'])) {
            $retry_b_id = intval($_POST['retry_allocate_id']);
            $st_ret = $conn->prepare("SELECT borrow_id, game_id, user_id, duration_hours FROM borrow_records WHERE borrow_id = ? LIMIT 1");
            if ($st_ret) {
                $st_ret->bind_param("i", $retry_b_id);
                $st_ret->execute();
                $ret_order = $st_ret->get_result()->fetch_assoc();
                $st_ret->close();

                if ($ret_order) {
                    $alloc_res = allocate_game_account($conn, intval($ret_order['game_id']), intval($ret_order['user_id']), $retry_b_id, intval($ret_order['duration_hours']));
                    if ($alloc_res['success']) {
                        write_audit_log($conn, $admin_id, 'RETRY_ALLOCATE', 'borrow_record', $retry_b_id, ['account_id' => $alloc_res['account_id']]);
                        $message = "Đã cấp bù tài khoản thành công cho đơn #$retry_b_id!";
                    } else {
                        $error = "Cấp bù thất bại: " . $alloc_res['error'];
                    }
                }
            }
        }

        // 3. DUYỆT THANH TOÁN THỦ CÔNG (KÍCH HOẠT ĐƠN MỚI HOẶC GIA HẠN)
        if (isset($_POST['approve_payment_id'])) {
            $approve_id = intval($_POST['approve_payment_id']);
            $pay_res = process_verified_payment($conn, $approve_id, 'MANUAL-' . date('YmdHis') . '-' . $approve_id, 'ADMIN:' . $admin_name);
            if ($pay_res['success']) {
                write_audit_log($conn, $admin_id, 'APPROVE_PAYMENT', 'borrow_record', $approve_id, ['order_code' => $pay_res['order_code'] ?? '']);
                $message = "Đã xác nhận thanh toán & kích hoạt đơn #$approve_id thành công!";
            } else {
                $error = "Duyệt thanh toán thất bại: " . ($pay_res['error'] ?? 'Lỗi không xác định');
            }
        }
    }
}

$csrf_token = generate_csrf_token();

// LỌC, TÌM KIẾM & PHÂN TRANG (PREPARED STATEMENT)
if (file_exists(__DIR__ . '/includes/pagination_helper.php')) {
    require_once __DIR__ . '/includes/pagination_helper.php';
}
$req_per_page = intval($_GET['per_page'] ?? 10);
$per_page = in_array($req_per_page, [10, 20, 50], true) ? $req_per_page : 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';

$where = [];
$bind_types = '';
$bind_params = [];
if ($search !== '') {
    $where[] = "(br.order_code LIKE ? OR u.username LIKE ? OR g.title LIKE ? OR ga.account_username LIKE ?)";
    $like = '%' . $search . '%';
    $bind_types .= 'ssss';
    array_push($bind_params, $like, $like, $like, $like);
}
if ($filter_status !== '') {
    $where[] = "br.status = ?";
    $bind_types .= 's';
    $bind_params[] = $filter_status;
}
$where_str = count($where) > 0 ? " WHERE " . implode(" AND ", $where) : "";

$total_items = 0;
$st_cnt = $conn->prepare("SELECT COUNT(*) AS total
    FROM borrow_records br
    JOIN users u ON br.user_id = u.user_id
    JOIN games g ON br.game_id = g.game_id
    LEFT JOIN game_accounts ga ON br.account_id = ga.account_id
    $where_str");
if ($st_cnt) {
    if ($bind_types !== '') {
        $st_cnt->bind_param($bind_types, ...$bind_params);
    }
    $st_cnt->execute();
    $row_cnt = $st_cnt->get_result()->fetch_assoc();
    $total_items = intval($row_cnt['total'] ?? 0);
    $st_cnt->close();
}

$total_pages = max(1, (int)ceil($total_items / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "SELECT br.*, u.username, u.email, g.title, ga.account_username, rp.package_name
        FROM borrow_records br 
        JOIN users u ON br.user_id = u.user_id 
        JOIN games g ON br.game_id = g.game_id 
        LEFT JOIN game_accounts ga ON br.account_id = ga.account_id
        LEFT JOIN rental_packages rp ON br.package_id = rp.package_id
        $where_str
        ORDER BY br.borrow_id DESC
        LIMIT ? OFFSET ?";

$result = false;
$st_list = $conn->prepare($sql);
if ($st_list) {
    $data_types = $bind_types . 'ii';
    $data_params = array_merge($bind_params, [$per_page, $offset]);
    $st_list->bind_param($data_types, ...$data_params);
    if ($st_list->execute()) {
        $result = $st_list->get_result();
    }
    $st_list->close();
}
?>

<div class="container-fluid px-lg-5 py-4 my-2">
    <!-- Top Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); font-size: 0.75rem; border: 1px solid rgba(99,102,241,0.4);">
                    <i class="bi bi-card-checklist me-1"></i> RENTAL ORDERS
                </span>
            </div>
            <h2 class="fw-extrabold mb-0">Quản Lý Đơn Thuê Tài Khoản Game</h2>
            <p class="text-secondary small mb-0">Theo dõi lịch sử đơn, tiến độ hạn thuê, doanh thu, thanh toán VietQR và xử lý thu hồi sớm</p>
        </div>
        <div class="d-flex gap-2">
            <a href="cron_process_expirations.php" class="btn btn-gaming-secondary d-flex align-items-center" target="_blank" title="Kích hoạt quét và thu hồi đơn hết hạn">
                <i class="bi bi-arrow-clockwise me-1 text-info"></i> Quét Hết Hạn Ngay
            </a>
            <a href="admin_accounts.php" class="btn btn-gaming-primary d-flex align-items-center">
                <i class="bi bi-box-seam me-1"></i> Quản Lý Kho TK
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-check-circle-fill text-success me-2"></i><?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- THANH TÌM KIẾM VÀ LỌC -->
    <div class="p-3 mb-4 rounded-3" style="background: var(--bg-card); border: 1px solid var(--border-color);">
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
                        if ($row['status'] == 'active' && !empty($row['due_date']) && strtotime($row['due_date']) < time()) {
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
                                    <strong class=" small">@<?php echo htmlspecialchars($row['username']); ?></strong>
                                    <div class="text-secondary small" style="font-size: 0.72rem;"><?php echo htmlspecialchars($row['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <strong class=""><?php echo htmlspecialchars($row['title']); ?></strong>
                            <div class="small text-secondary" style="font-size: 0.72rem;">
                                <?php echo htmlspecialchars($row['package_name'] ?? 'Chuẩn'); ?> (<?php echo intval($row['duration_hours'] ?? 24); ?>h)
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($row['account_username'])): ?>
                                <span class="badge badge-gaming-account">
                                    <i class="bi bi-person-fill me-1"></i><?php echo htmlspecialchars($row['account_username']); ?>
                                </span>
                            <?php else: ?>
                                <span class="status-pill status-pill-danger"><span class="status-dot"></span> Chưa cấp TK</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['due_date'])): ?>
                                <span class="small d-block"><?php echo date('d/m/Y H:i', strtotime($row['due_date'])); ?></span>
                            <?php else: ?>
                                <span class="small text-secondary d-block">Chờ kích hoạt</span>
                            <?php endif; ?>
                            <?php if ($is_overdue): ?>
                                <span class="badge bg-danger bg-opacity-75" style="font-size: 0.68rem;">ĐÃ QUÁ HẠN</span>
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
                                <form method="POST" class="d-inline" onsubmit="return confirm('Bạn có chắc chắn muốn thu hồi tài khoản này về kho sớm?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="revoke_id" value="<?php echo intval($row['borrow_id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-3">Thu hồi</button>
                                </form>
                            <?php elseif (($row['payment_status'] ?? 'pending') === 'pending'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Xác nhận đã nhận tiền và kích hoạt đơn #<?php echo intval($row['borrow_id']); ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="approve_payment_id" value="<?php echo intval($row['borrow_id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-success fw-bold py-1 px-2">Duyệt TT</button>
                                </form>
                            <?php elseif ($row['payment_status'] == 'paid' && empty($row['account_id'])): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="retry_allocate_id" value="<?php echo intval($row['borrow_id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-warning fw-bold text-dark py-1 px-2">Cấp bù TK</button>
                                </form>
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
    <?php if (function_exists('render_pagination')) { echo render_pagination($page, $total_pages, $total_items, $per_page, $_GET); } ?>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>