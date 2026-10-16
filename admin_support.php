<?php
/**
 * ANPLAY STORE - QUẢN LÝ HỖ TRỢ KHÁCH HÀNG (ADMIN SUPPORT CENTER)
 * Bảng điều khiển quản lý toàn bộ yêu cầu, phân loại, tìm kiếm và phân quyền xử lý
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/connect.php';

// Kiểm tra quyền Quản trị viên
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    header("Location: index.php");
    exit();
}

$current_page = 'admin_support.php';

// 1. THỐNG KÊ THỰC TẾ TỪ CSDL
$total_tickets = 0;
$new_tickets = 0;
$in_progress_tickets = 0;
$waiting_customer_tickets = 0;
$resolved_tickets = 0;
$urgent_high_tickets = 0;

try {
    $q_stats = $conn->query("SELECT 
        COUNT(*) as total_c,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_c,
        SUM(CASE WHEN status IN ('assigned', 'in_progress') THEN 1 ELSE 0 END) as prog_c,
        SUM(CASE WHEN status = 'waiting_customer' THEN 1 ELSE 0 END) as wait_c,
        SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as res_c,
        SUM(CASE WHEN priority IN ('urgent', 'high') AND status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as urg_c
        FROM support_tickets");
    if ($q_stats && $row = $q_stats->fetch_assoc()) {
        $total_tickets = intval($row['total_c'] ?? 0);
        $new_tickets = intval($row['new_c'] ?? 0);
        $in_progress_tickets = intval($row['prog_c'] ?? 0);
        $waiting_customer_tickets = intval($row['wait_c'] ?? 0);
        $resolved_tickets = intval($row['res_c'] ?? 0);
        $urgent_high_tickets = intval($row['urg_c'] ?? 0);
    }
} catch (Throwable $e) {}

// 2. BỘ LỌC VÀ TÌM KIẾM
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$filter_priority = isset($_GET['priority']) ? trim($_GET['priority']) : 'all';
$filter_category = isset($_GET['category']) ? trim($_GET['category']) : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where_clauses = ["1=1"];

if ($filter_status === 'new') {
    $where_clauses[] = "st.status = 'new'";
} elseif ($filter_status === 'in_progress') {
    $where_clauses[] = "st.status IN ('assigned', 'in_progress')";
} elseif ($filter_status === 'waiting') {
    $where_clauses[] = "st.status = 'waiting_customer'";
} elseif ($filter_status === 'resolved') {
    $where_clauses[] = "st.status = 'resolved'";
} elseif ($filter_status === 'closed') {
    $where_clauses[] = "st.status = 'closed'";
}

if ($filter_priority !== 'all' && in_array($filter_priority, ['low', 'normal', 'high', 'urgent'])) {
    $where_clauses[] = "st.priority = '" . $conn->real_escape_string($filter_priority) . "'";
}

if ($filter_category !== 'all' && !empty($filter_category)) {
    $where_clauses[] = "st.category = '" . $conn->real_escape_string($filter_category) . "'";
}

if (!empty($search)) {
    $esc = $conn->real_escape_string($search);
    $where_clauses[] = "(st.ticket_code LIKE '%$esc%' OR st.title LIKE '%$esc%' OR u.username LIKE '%$esc%' OR u.email LIKE '%$esc%' OR br.order_code LIKE '%$esc%')";
}

$where_sql = implode(" AND ", $where_clauses);

// Phân trang
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$count_sql = "SELECT COUNT(*) as c FROM support_tickets st 
              JOIN users u ON st.user_id = u.user_id 
              LEFT JOIN borrow_records br ON st.order_id = br.borrow_id 
              WHERE $where_sql";
$total_rows = 0;
try {
    $q_c = $conn->query($count_sql);
    if ($q_c && $r = $q_c->fetch_assoc()) {
        $total_rows = intval($r['c'] ?? 0);
    }
} catch (Throwable $e) {}

$total_pages = max(1, ceil($total_rows / $per_page));

// Lấy danh sách ticket
$tickets = [];
try {
    $sql = "SELECT st.*, u.username, u.email, g.title as game_title, br.order_code 
            FROM support_tickets st 
            JOIN users u ON st.user_id = u.user_id 
            LEFT JOIN games g ON st.game_id = g.game_id 
            LEFT JOIN borrow_records br ON st.order_id = br.borrow_id 
            WHERE $where_sql 
            ORDER BY 
                CASE WHEN st.priority = 'urgent' THEN 1 WHEN st.priority = 'high' THEN 2 ELSE 3 END, 
                st.updated_at DESC 
            LIMIT $per_page OFFSET $offset";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        while ($t = $res->fetch_assoc()) {
            $tickets[] = $t;
        }
    }
} catch (Throwable $e) {}

$categories = get_support_categories();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 py-4 text-white">
    <!-- Header Page -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(244,63,94,0.2); color: #f43f5e; font-size: 0.75rem; border: 1px solid rgba(244,63,94,0.4);">
                    <i class="bi bi-shield-check me-1"></i> ADMIN SUPPORT DESK
                </span>
                <span class="text-secondary small">Trung tâm chăm sóc & giải quyết khiếu nại khách hàng</span>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Quản Lý Yêu Cầu Hỗ Trợ Khách Hàng</h2>
        </div>
        <div class="d-flex gap-2">
            <a href="admin_notifications.php" class="btn btn-gaming-secondary d-flex align-items-center">
                <i class="bi bi-broadcast me-1 text-warning"></i> Gửi Thông Báo Toàn Sàn
            </a>
            <a href="admin_dashboard.php" class="btn btn-outline-secondary d-flex align-items-center text-white">
                <i class="bi bi-speedometer2 me-1"></i> Admin Dashboard
            </a>
        </div>
    </div>

    <!-- STATS ROW -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="panel-dark p-3 rounded-4 text-center">
                <span class="text-secondary small d-block mb-1">Tổng Ticket</span>
                <h3 class="fw-bold text-white mb-0"><?php echo $total_tickets; ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="panel-dark p-3 rounded-4 text-center border-info border-opacity-25">
                <span class="text-info small d-block mb-1">Ticket Mới</span>
                <h3 class="fw-bold text-info mb-0"><?php echo $new_tickets; ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="panel-dark p-3 rounded-4 text-center border-warning border-opacity-25">
                <span class="text-warning small d-block mb-1">Đang Xử Lý</span>
                <h3 class="fw-bold text-warning mb-0"><?php echo $in_progress_tickets; ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="panel-dark p-3 rounded-4 text-center">
                <span class="text-secondary small d-block mb-1">Chờ Khách</span>
                <h3 class="fw-bold text-light mb-0"><?php echo $waiting_customer_tickets; ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="panel-dark p-3 rounded-4 text-center border-danger border-opacity-25">
                <span class="text-danger small d-block mb-1">Ưu Tiên Cao</span>
                <h3 class="fw-bold text-danger mb-0"><?php echo $urgent_high_tickets; ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="panel-dark p-3 rounded-4 text-center border-success border-opacity-25">
                <span class="text-success small d-block mb-1">Đã Giải Quyết</span>
                <h3 class="fw-bold text-success mb-0"><?php echo $resolved_tickets; ?></h3>
            </div>
        </div>
    </div>

    <!-- FILTER & SEARCH BAR -->
    <div class="panel-dark p-3 rounded-4 mb-4">
        <form action="admin_support.php" method="GET" class="row g-2 align-items-center">
            <div class="col-lg-4 col-md-6">
                <div class="position-relative">
                    <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-secondary"></i>
                    <input type="text" name="search" class="form-control form-control-gaming ps-5" placeholder="Tìm kiếm theo mã ticket, tiêu đề, user, order..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-lg-2 col-md-3 col-6">
                <select name="status" class="form-select form-control-gaming" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter_status === 'all') ? 'selected' : ''; ?>>Tất cả trạng thái</option>
                    <option value="new" <?php echo ($filter_status === 'new') ? 'selected' : ''; ?>>🔵 Mới gửi (<?php echo $new_tickets; ?>)</option>
                    <option value="in_progress" <?php echo ($filter_status === 'in_progress') ? 'selected' : ''; ?>>🟡 Đang xử lý</option>
                    <option value="waiting" <?php echo ($filter_status === 'waiting') ? 'selected' : ''; ?>>⚪ Chờ khách phản hồi</option>
                    <option value="resolved" <?php echo ($filter_status === 'resolved') ? 'selected' : ''; ?>>✅ Đã giải quyết</option>
                    <option value="closed" <?php echo ($filter_status === 'closed') ? 'selected' : ''; ?>>Đã đóng</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-3 col-6">
                <select name="priority" class="form-select form-control-gaming" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter_priority === 'all') ? 'selected' : ''; ?>>Tất cả ưu tiên</option>
                    <option value="urgent" <?php echo ($filter_priority === 'urgent') ? 'selected' : ''; ?>>🔴 Khẩn cấp</option>
                    <option value="high" <?php echo ($filter_priority === 'high') ? 'selected' : ''; ?>>🟠 Ưu tiên cao</option>
                    <option value="normal" <?php echo ($filter_priority === 'normal') ? 'selected' : ''; ?>>🔵 Bình thường</option>
                    <option value="low" <?php echo ($filter_priority === 'low') ? 'selected' : ''; ?>>⚪ Thấp</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-6 col-6">
                <select name="category" class="form-select form-control-gaming" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter_category === 'all') ? 'selected' : ''; ?>>Tất cả danh mục</option>
                    <?php foreach ($categories as $ckey => $cval): ?>
                        <option value="<?php echo $ckey; ?>" <?php echo ($filter_category === $ckey) ? 'selected' : ''; ?>>
                            <?php echo $cval['label']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-6 col-6 d-flex gap-2">
                <button type="submit" class="btn btn-gaming-secondary w-100">
                    <i class="bi bi-funnel me-1"></i> Lọc
                </button>
                <a href="admin_support.php" class="btn btn-outline-secondary text-secondary" title="Xóa bộ lọc">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- TICKET TABLE -->
    <div class="panel-dark p-0 rounded-4 overflow-hidden shadow-sm">
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                <thead style="background: rgba(255,255,255,0.04); border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <tr class="text-secondary small">
                        <th class="ps-4 py-3">MÃ & TIÊU ĐỀ</th>
                        <th class="py-3">KHÁCH HÀNG</th>
                        <th class="py-3">DANH MỤC</th>
                        <th class="py-3">ƯU TIÊN</th>
                        <th class="py-3">TRẠNG THÁI</th>
                        <th class="py-3">LIÊN QUAN</th>
                        <th class="py-3">CẬP NHẬT</th>
                        <th class="pe-4 py-3 text-end">THAO TÁC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-secondary">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary opacity-50"></i>
                                Không tìm thấy yêu cầu hỗ trợ nào phù hợp với bộ lọc.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): 
                            $st_info = get_ticket_status_info($t['status']);
                            $pr_info = get_ticket_priority_info($t['priority']);
                            $cat_info = $categories[$t['category']] ?? ['label' => 'Hỗ trợ', 'icon' => 'bi-chat-dots'];
                        ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <td class="ps-4 py-3">
                                    <div class="fw-bold font-monospace text-primary" style="font-size: 0.85rem;"><?php echo htmlspecialchars($t['ticket_code']); ?></div>
                                    <a href="admin_support_detail.php?id=<?php echo $t['ticket_id']; ?>" class="fw-bold text-white text-decoration-none hover-primary d-inline-block text-truncate" style="max-width: 280px;">
                                        <?php echo htmlspecialchars($t['title']); ?>
                                    </a>
                                </td>
                                <td class="py-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($t['username']); ?>&background=6366f1&color=fff&bold=true&rounded=true" width="28" height="28" class="rounded-circle shadow-sm" alt="Avatar">
                                        <div>
                                            <div class="fw-bold small text-white">@<?php echo htmlspecialchars($t['username']); ?></div>
                                            <div class="text-secondary small" style="font-size: 0.72rem;"><?php echo htmlspecialchars($t['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3">
                                    <span class="badge bg-secondary bg-opacity-25 text-light" style="font-size: 0.72rem;">
                                        <i class="bi <?php echo $cat_info['icon']; ?> me-1"></i><?php echo $cat_info['label']; ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <span class="badge <?php echo $pr_info['badge_class']; ?>" style="font-size: 0.72rem;">
                                        <?php echo $pr_info['label']; ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <span class="badge <?php echo $st_info['badge_class']; ?>" style="font-size: 0.75rem;">
                                        <i class="bi <?php echo $st_info['icon']; ?> me-1"></i><?php echo $st_info['label']; ?>
                                    </span>
                                </td>
                                <td class="py-3 small text-secondary">
                                    <?php if (!empty($t['game_title'])): ?>
                                        <span class="d-block text-truncate" style="max-width: 140px;" title="<?php echo htmlspecialchars($t['game_title']); ?>">
                                            <i class="bi bi-controller text-success me-1"></i><?php echo htmlspecialchars($t['game_title']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($t['order_code'])): ?>
                                        <span class="badge bg-dark border border-secondary text-warning" style="font-size: 0.68rem;">
                                            #<?php echo htmlspecialchars($t['order_code']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (empty($t['game_title']) && empty($t['order_code'])): ?>
                                        <span class="text-secondary fst-italic">Không có</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 small text-secondary">
                                    <div><?php echo format_time_ago($t['updated_at']); ?></div>
                                    <div style="font-size: 0.7rem;"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></div>
                                </td>
                                <td class="pe-4 py-3 text-end">
                                    <a href="admin_support_detail.php?id=<?php echo $t['ticket_id']; ?>" class="btn btn-gaming-primary btn-sm py-1 px-3">
                                        <i class="bi bi-chat-left-dots-fill me-1"></i> Xử Lý
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Phân trang -->
        <?php if ($total_pages > 1): ?>
            <div class="p-3 border-top border-secondary border-opacity-25 d-flex justify-content-between align-items-center">
                <span class="text-secondary small">Hiển thị <?php echo count($tickets); ?> / <?php echo $total_rows; ?> yêu cầu</span>
                <ul class="pagination pagination-sm gap-1 mb-0">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?php echo ($p === $page) ? 'active' : ''; ?>">
                            <a class="page-link rounded-2" href="admin_support.php?status=<?php echo urlencode($filter_status); ?>&priority=<?php echo urlencode($filter_priority); ?>&category=<?php echo urlencode($filter_category); ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $p; ?>">
                                <?php echo $p; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

