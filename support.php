<?php
/**
 * ANPLAY STORE - TRUNG TÂM HỖ TRỢ KHÁCH HÀNG (CUSTOMER SUPPORT CENTER)
 * Danh sách Ticket, tạo Ticket mới, lọc và quản lý yêu cầu của người dùng
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/includes/connect.php';

$user_id = intval($_SESSION['user_id']);
$categories = get_support_categories();

// Đảm bảo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success_msg = "";
$error_msg = "";

// 1. XỬ LÝ TẠO YÊU CẦU MỚI (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_create_ticket'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã bảo mật CSRF không hợp lệ hoặc đã hết hạn. Vui lòng thử lại!";
    } else {
        $cat = trim($_POST['category'] ?? 'other');
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $order_id = !empty($_POST['order_id']) ? intval($_POST['order_id']) : null;
        $priority = trim($_POST['priority'] ?? 'normal');

        if (empty($title) || empty($message)) {
            $error_msg = "Vui lòng nhập đầy đủ tiêu đề và nội dung cần hỗ trợ.";
        } else {
            // Lấy game_id nếu có chọn order_id
            $game_id = null;
            if ($order_id > 0) {
                $chk_ord = $conn->query("SELECT game_id FROM borrow_records WHERE borrow_id = $order_id AND user_id = $user_id LIMIT 1");
                if ($chk_ord && $o = $chk_ord->fetch_assoc()) {
                    $game_id = intval($o['game_id']);
                }
            }

            // Xử lý upload file ảnh đính kèm nếu có
            $attachments = [];
            if (isset($_FILES['attachment']) && !empty($_FILES['attachment']['name'])) {
                $up_res = upload_support_attachment($_FILES['attachment'], 0, $user_id);
                if ($up_res['success']) {
                    $attachments[] = $up_res;
                } else {
                    $error_msg = $up_res['error'];
                }
            }

            if (empty($error_msg)) {
                $res = create_support_ticket($conn, $user_id, $cat, $title, $message, $priority, $order_id, $game_id, $attachments);
                if ($res['success']) {
                    $new_ticket_id = $res['ticket_id'];
                    header("Location: support_detail.php?id=" . $new_ticket_id . "&created=1");
                    exit();
                } else {
                    $error_msg = $res['error'];
                }
            }
        }
    }
}

// 2. LẤY DANH SÁCH ĐƠN HÀNG CỦA USER ĐỂ GỢI Ý KHI TẠO TICKET
$user_orders = [];
try {
    $q_ord = $conn->query("SELECT br.borrow_id, br.order_code, br.due_date, br.status, g.title 
        FROM borrow_records br 
        JOIN games g ON br.game_id = g.game_id 
        WHERE br.user_id = $user_id 
        ORDER BY br.borrow_date DESC LIMIT 15");
    if ($q_ord && $q_ord->num_rows > 0) {
        while ($o = $q_ord->fetch_assoc()) {
            $user_orders[] = $o;
        }
    }
} catch (Throwable $e) {}

// 3. THỐNG KÊ TICKET CỦA USER
$st_open = 0;
$st_waiting = 0;
$st_resolved = 0;
$st_total = 0;

try {
    $q_st = $conn->query("SELECT status, COUNT(*) as c FROM support_tickets WHERE user_id = $user_id GROUP BY status");
    if ($q_st && $q_st->num_rows > 0) {
        while ($r = $q_st->fetch_assoc()) {
            $c = intval($r['c']);
            $st_total += $c;
            if (in_array($r['status'], ['new', 'assigned', 'in_progress'])) {
                $st_open += $c;
            } elseif ($r['status'] === 'waiting_customer') {
                $st_waiting += $c;
            } elseif (in_array($r['status'], ['resolved', 'closed'])) {
                $st_resolved += $c;
            }
        }
    }
} catch (Throwable $e) {}

// 4. LỌC VÀ TÌM KIẾM TICKET
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$where_clauses = ["st.user_id = $user_id"];
if ($filter_status === 'open') {
    $where_clauses[] = "st.status IN ('new', 'assigned', 'in_progress')";
} elseif ($filter_status === 'waiting') {
    $where_clauses[] = "st.status = 'waiting_customer'";
} elseif ($filter_status === 'resolved') {
    $where_clauses[] = "st.status IN ('resolved', 'closed')";
}

if (!empty($search_query)) {
    $esc_search = $conn->real_escape_string($search_query);
    $where_clauses[] = "(st.ticket_code LIKE '%$esc_search%' OR st.title LIKE '%$esc_search%')";
}

$where_sql = implode(" AND ", $where_clauses);
$tickets = [];

try {
    $sql_t = "SELECT st.*, g.title as game_title, br.order_code 
              FROM support_tickets st 
              LEFT JOIN games g ON st.game_id = g.game_id 
              LEFT JOIN borrow_records br ON st.order_id = br.borrow_id 
              WHERE $where_sql 
              ORDER BY st.updated_at DESC";
    $res_t = $conn->query($sql_t);
    if ($res_t && $res_t->num_rows > 0) {
        while ($t = $res_t->fetch_assoc()) {
            $tickets[] = $t;
        }
    }
} catch (Throwable $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 py-4 text-white">
    <!-- Header bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(139,92,246,0.2); color: #8b5cf6; font-size: 0.75rem; border: 1px solid rgba(139,92,246,0.4);">
                    <i class="bi bi-headset me-1"></i> SUPPORT DESK
                </span>
                <span class="text-secondary small">Cam kết hỗ trợ 100% khách hàng</span>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Trung Tâm Hỗ Trợ & Khiếu Nại Khách Hàng</h2>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-gaming-primary d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#createTicketModal">
                <i class="bi bi-plus-circle me-2"></i> Gửi Yêu Cầu Hỗ Trợ Mới
            </button>
        </div>
    </div>

    <!-- Alert thông báo lỗi / thành công -->
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4 rounded-3" style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Cam kết Hỗ trợ Con người & 1 đổi 1 -->
    <div class="p-3 px-4 mb-4 rounded-4 d-flex flex-wrap align-items-center justify-content-between gap-3" style="background: linear-gradient(90deg, rgba(99,102,241,0.12) 0%, rgba(139,92,246,0.12) 100%); border: 1px solid rgba(99,102,241,0.3);">
        <div class="d-flex align-items-center gap-3">
            <div class="p-2 px-3 rounded-circle" style="background: rgba(99,102,241,0.25); color: #818cf8; font-size: 1.5rem;">
                <i class="bi bi-person-check-fill"></i>
            </div>
            <div>
                <h6 class="fw-bold text-white mb-1"><i class="bi bi-shield-check text-success me-1"></i> Đội Ngũ Kỹ Thuật Viên 24/7 Trực Tiếp Xử Lý (Human Support)</h6>
                <p class="text-secondary small mb-0">Mọi yêu cầu hỗ trợ (Ticket) và báo lỗi tài khoản được xử lý bởi nhân viên kỹ thuật thực tế của shop. Cam kết bảo hành 1 đổi 1 ngay nếu tài khoản gặp sự cố đăng nhập.</p>
            </div>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25 px-3 py-2">
                <i class="bi bi-check-circle-fill me-1"></i> Bảo hành 1 đổi 1
            </span>
            <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 px-3 py-2">
                <i class="bi bi-clock-history me-1"></i> Phản hồi nhanh
            </span>
        </div>
    </div>

    <!-- KPI Summary Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="panel-dark p-3 rounded-4 d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 text-warning" style="background: rgba(245,158,11,0.15);">
                    <i class="bi bi-hourglass-split fs-4"></i>
                </div>
                <div>
                    <div class="text-secondary small">Đang xử lý</div>
                    <h3 class="fw-bold mb-0 text-white"><?php echo $st_open; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="panel-dark p-3 rounded-4 d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 text-info" style="background: rgba(6,182,212,0.15);">
                    <i class="bi bi-chat-dots-fill fs-4"></i>
                </div>
                <div>
                    <div class="text-secondary small">Chờ bạn phản hồi</div>
                    <h3 class="fw-bold mb-0 text-white"><?php echo $st_waiting; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="panel-dark p-3 rounded-4 d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 text-success" style="background: rgba(16,185,129,0.15);">
                    <i class="bi bi-check-circle-fill fs-4"></i>
                </div>
                <div>
                    <div class="text-secondary small">Đã giải quyết</div>
                    <h3 class="fw-bold mb-0 text-white"><?php echo $st_resolved; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="panel-dark p-3 rounded-4 d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 text-primary" style="background: rgba(99,102,241,0.15);">
                    <i class="bi bi-collection-fill fs-4"></i>
                </div>
                <div>
                    <div class="text-secondary small">Tổng yêu cầu</div>
                    <h3 class="fw-bold mb-0 text-white"><?php echo $st_total; ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="panel-dark p-3 rounded-4 mb-4">
        <form action="support.php" method="GET" class="row g-2 align-items-center">
            <div class="col-md-6 col-lg-4">
                <div class="position-relative">
                    <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-secondary"></i>
                    <input type="text" name="search" class="form-control form-control-gaming ps-5" placeholder="Tìm theo mã ticket (#SUP-...) hoặc tiêu đề" value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
            </div>
            <div class="col-md-4 col-lg-3">
                <select name="status" class="form-select form-control-gaming" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter_status === 'all') ? 'selected' : ''; ?>>Tất cả trạng thái</option>
                    <option value="open" <?php echo ($filter_status === 'open') ? 'selected' : ''; ?>>Đang xử lý (Mới / Tiếp nhận)</option>
                    <option value="waiting" <?php echo ($filter_status === 'waiting') ? 'selected' : ''; ?>>Chờ bạn phản hồi</option>
                    <option value="resolved" <?php echo ($filter_status === 'resolved') ? 'selected' : ''; ?>>Đã giải quyết & Đóng</option>
                </select>
            </div>
            <div class="col-md-2 col-lg-2">
                <button type="submit" class="btn btn-gaming-secondary w-100">
                    <i class="bi bi-funnel me-1"></i> Lọc
                </button>
            </div>
        </form>
    </div>

    <!-- Ticket List Cards -->
    <?php if (empty($tickets)): ?>
        <div class="panel-dark text-center py-5 rounded-4" style="border: 1px dashed rgba(255,255,255,0.15);">
            <div class="p-4 d-inline-block rounded-circle mb-3" style="background: rgba(139,92,246,0.1);">
                <i class="bi bi-chat-square-text fs-1 text-secondary"></i>
            </div>
            <h5 class="fw-bold text-white mb-2">Bạn chưa có yêu cầu hỗ trợ nào</h5>
            <p class="text-secondary small mb-3">Khi gặp bất kỳ vấn đề nào về tài khoản game hoặc thanh toán, hãy tạo yêu cầu để được hỗ trợ 24/7.</p>
            <button class="btn btn-gaming-primary btn-sm px-4" data-bs-toggle="modal" data-bs-target="#createTicketModal">
                <i class="bi bi-plus-circle me-1"></i> Gửi Yêu Cầu Hỗ Trợ Ngay
            </button>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($tickets as $t): 
                $st_info = get_ticket_status_info($t['status']);
                $pr_info = get_ticket_priority_info($t['priority']);
                $cat_info = $categories[$t['category']] ?? ['label' => 'Yêu cầu hỗ trợ', 'icon' => 'bi-chat-dots'];
            ?>
                <div class="panel-dark p-3 p-md-4 rounded-4 transition-all">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                        <div class="d-flex align-items-start gap-3">
                            <div class="rounded-3 p-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: rgba(139,92,246,0.15); color: #a78bfa;">
                                <i class="bi <?php echo $cat_info['icon']; ?> fs-4"></i>
                            </div>
                            <div>
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                    <span class="fw-bold text-primary font-monospace" style="font-size: 0.95rem;"><?php echo htmlspecialchars($t['ticket_code']); ?></span>
                                    <span class="badge <?php echo $st_info['badge_class']; ?>" style="font-size: 0.72rem;">
                                        <i class="bi <?php echo $st_info['icon']; ?> me-1"></i><?php echo $st_info['label']; ?>
                                    </span>
                                    <?php if ($t['priority'] === 'urgent' || $t['priority'] === 'high'): ?>
                                        <span class="badge <?php echo $pr_info['badge_class']; ?>" style="font-size: 0.72rem;">
                                            <i class="bi bi-lightning-fill me-1"></i><?php echo $pr_info['label']; ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="badge bg-secondary bg-opacity-25 text-light" style="font-size: 0.7rem;">
                                        <?php echo $cat_info['label']; ?>
                                    </span>
                                </div>
                                <h5 class="fw-bold text-white mb-1">
                                    <a href="support_detail.php?id=<?php echo $t['ticket_id']; ?>" class="text-white text-decoration-none hover-primary">
                                        <?php echo htmlspecialchars($t['title']); ?>
                                    </a>
                                </h5>
                                <div class="d-flex flex-wrap align-items-center gap-3 text-secondary small">
                                    <?php if (!empty($t['game_title'])): ?>
                                        <span><i class="bi bi-controller me-1 text-success"></i> Game: <strong><?php echo htmlspecialchars($t['game_title']); ?></strong></span>
                                    <?php endif; ?>
                                    <?php if (!empty($t['order_code'])): ?>
                                        <span><i class="bi bi-receipt me-1 text-info"></i> Đơn: #<?php echo htmlspecialchars($t['order_code']); ?></span>
                                    <?php endif; ?>
                                    <span><i class="bi bi-clock me-1"></i> Cập nhật: <?php echo format_time_ago($t['updated_at']); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Right CTA -->
                        <div class="d-flex align-items-center justify-content-end gap-2 flex-shrink-0">
                            <?php if ($t['status'] === 'resolved' && empty($t['rating'])): ?>
                                <a href="support_detail.php?id=<?php echo $t['ticket_id']; ?>#rating_section" class="btn btn-warning btn-sm py-2 px-3 fw-bold">
                                    <i class="bi bi-star-fill me-1"></i> Đánh giá hỗ trợ
                                </a>
                            <?php endif; ?>
                            <a href="support_detail.php?id=<?php echo $t['ticket_id']; ?>" class="btn btn-gaming-primary btn-sm py-2 px-3 d-flex align-items-center">
                                Xem hội thoại <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL TẠO YÊU CẦU HỖ TRỢ -->
<div class="modal fade" id="createTicketModal" tabindex="-1" aria-labelledby="createTicketModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content panel-dark border border-secondary border-opacity-25 text-white" style="background: #0f121a; border-radius: 20px;">
            <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                <h5 class="modal-title fw-bold" id="createTicketModalLabel">
                    <i class="bi bi-headset text-primary me-2"></i> Gửi Yêu Cầu Hỗ Trợ Cho Đội Ngũ ANPLAY
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <form action="support.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Danh mục sự cố -->
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold">Danh Mục Vấn Đề <span class="text-danger">*</span></label>
                            <select name="category" class="form-select form-control-gaming" required>
                                <?php foreach ($categories as $ckey => $cval): ?>
                                    <option value="<?php echo $ckey; ?>">
                                        <?php echo $cval['label']; ?> — (<?php echo $cval['desc']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Đơn hàng liên quan -->
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold">Đơn Hàng Hoặc Game Liên Quan (Tùy chọn)</label>
                            <select name="order_id" class="form-select form-control-gaming">
                                <option value="">-- Không chọn / Sự cố chung --</option>
                                <?php foreach ($user_orders as $uo): ?>
                                    <option value="<?php echo $uo['borrow_id']; ?>">
                                        Đơn #<?php echo htmlspecialchars($uo['order_code'] ?? $uo['borrow_id']); ?> - <?php echo htmlspecialchars($uo['title']); ?> (<?php echo $uo['status']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Mức độ ưu tiên -->
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold">Mức Độ Ưu Tiên</label>
                            <div class="d-flex flex-wrap gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="priority" id="pri_normal" value="normal" checked>
                                    <label class="form-check-label text-light small" for="pri_normal">Bình thường</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="priority" id="pri_high" value="high">
                                    <label class="form-check-label text-warning small" for="pri_high">Ưu tiên cao (Mất tiền, lỗi tài khoản)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="priority" id="pri_urgent" value="urgent">
                                    <label class="form-check-label text-danger small" for="pri_urgent">Khẩn cấp (Sắp hết giải đấu, cần đổi gấp)</label>
                                </div>
                            </div>
                        </div>

                        <!-- Tiêu đề -->
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold">Tiêu Đề Tóm Tắt <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control form-control-gaming" placeholder="VD: Tài khoản Elden Ring báo sai mật khẩu / Đã chuyển tiền nhưng chưa cấp game" required maxlength="255">
                        </div>

                        <!-- Nội dung chi tiết -->
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold">Nội Dung Chi Tiết Sự Cố <span class="text-danger">*</span></label>
                            <textarea name="message" class="form-control form-control-gaming" rows="5" placeholder="Mô tả cụ thể vấn đề bạn gặp phải, thông báo lỗi hiển thị, thời điểm xảy ra..." required></textarea>
                        </div>

                        <!-- Đính kèm ảnh minh chứng -->
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold">
                                <i class="bi bi-paperclip me-1"></i> Đính Kèm Ảnh Chụp Màn Hình (Tùy chọn)
                            </label>
                            <input type="file" name="attachment" class="form-control form-control-gaming" accept="image/png,image/jpeg,image/webp">
                            <small class="text-secondary d-block mt-1">Chấp nhận JPG, PNG, WEBP tối đa 5MB. Giúp kỹ thuật viên xử lý nhanh hơn 80%.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25 pt-3">
                    <button type="button" class="btn btn-secondary px-4 py-2" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="btn_create_ticket" class="btn btn-gaming-primary px-4 py-2">
                        <i class="bi bi-send-fill me-2"></i> Gửi Yêu Cầu Hỗ Trợ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

