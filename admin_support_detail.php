<?php
/**
 * ANPLAY STORE - XỬ LÝ VÀ PHẢN HỒI YÊU CẦU HỖ TRỢ (ADMIN TICKET DETAIL & REPLY)
 * Giao diện admin trao đổi trực tiếp với khách hàng, cập nhật trạng thái và giải quyết sự cố
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/connect.php';

// Kiểm tra quyền Quản trị viên (ADMIN hoặc SUPER_ADMIN)
require_admin();

$admin_id = intval($_SESSION['user_id']);
$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($ticket_id <= 0) {
    header("Location: admin_support.php");
    exit();
}

// Đảm bảo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success_msg = "";
$error_msg = "";

// 1. XỬ LÝ CẬP NHẬT TRẠNG THÁI / MỨC ĐỘ ƯU TIÊN / PHÂN CÔNG ADMIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_update_ticket_meta'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật không hợp lệ.";
    } else {
        $new_status = trim($_POST['status'] ?? '');
        $new_priority = trim($_POST['priority'] ?? '');
        $res_note = trim($_POST['resolution_note'] ?? '');
        $new_assigned_to = isset($_POST['assigned_to']) ? intval($_POST['assigned_to']) : 0;

        if (!empty($new_status)) {
            update_ticket_status($conn, $ticket_id, $new_status, $admin_id, true, $res_note);
        }
        if (!empty($new_priority) && in_array($new_priority, ['low', 'normal', 'high', 'urgent'])) {
            $conn->query("UPDATE support_tickets SET priority = '$new_priority', updated_at = NOW() WHERE ticket_id = $ticket_id");
        }
        if ($new_assigned_to > 0) {
            assign_support_ticket($conn, $ticket_id, $new_assigned_to, $admin_id);
        }
        $success_msg = "Cập nhật thông tin yêu cầu thành công!";
    }
}

// 2. XỬ LÝ GỬI PHẢN HỒI CỦA ADMIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_admin_reply'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật không hợp lệ.";
    } else {
        $reply_text = trim($_POST['message'] ?? '');
        $auto_status = trim($_POST['reply_status'] ?? '');

        if (empty($reply_text)) {
            $error_msg = "Vui lòng nhập nội dung câu trả lời cho khách.";
        } else {
            $attachments = [];
            if (isset($_FILES['attachment']) && !empty($_FILES['attachment']['name'])) {
                $up_res = upload_support_attachment($_FILES['attachment'], $ticket_id, $admin_id);
                if ($up_res['success']) {
                    $attachments[] = $up_res;
                } else {
                    $error_msg = $up_res['error'];
                }
            }

            if (empty($error_msg)) {
                $send_res = send_support_message($conn, $ticket_id, $admin_id, 'admin', $reply_text, $attachments);
                if ($send_res['success']) {
                    // Nếu admin chọn đổi trạng thái kèm theo tin nhắn
                    if (!empty($auto_status) && in_array($auto_status, ['in_progress', 'waiting_customer', 'resolved', 'closed'])) {
                        update_ticket_status($conn, $ticket_id, $auto_status, $admin_id, true);
                    }
                    header("Location: admin_support_detail.php?id=" . $ticket_id . "#reply_bottom");
                    exit();
                } else {
                    $error_msg = $send_res['error'];
                }
            }
        }
    }
}

// 3. LẤY THÔNG TIN CHI TIẾT TICKET & KHÁCH HÀNG
$ticket = null;
try {
    $stmt = $conn->prepare("SELECT st.*, u.username as customer_name, u.email as customer_email, u.created_at as user_created_at, 
            g.title as game_title, g.image_url as game_img, 
            br.order_code, br.rental_price, br.status as borrow_status, br.due_date, br.borrow_date, 
            ga.account_username, ga.account_password_encrypted 
        FROM support_tickets st 
        JOIN users u ON st.user_id = u.user_id 
        LEFT JOIN games g ON st.game_id = g.game_id 
        LEFT JOIN borrow_records br ON st.order_id = br.borrow_id 
        LEFT JOIN game_accounts ga ON br.account_id = ga.account_id 
        WHERE st.ticket_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
} catch (Throwable $e) {}

if (!$ticket) {
    echo "<script>alert('Không tìm thấy yêu cầu hỗ trợ này!'); window.location='admin_support.php';</script>";
    exit();
}

// Lấy số lượng đơn đã thuê của khách hàng
$customer_order_count = 0;
try {
    $c_uid = intval($ticket['user_id']);
    $qc = $conn->query("SELECT COUNT(*) as c FROM borrow_records WHERE user_id = $c_uid");
    if ($qc && $rc = $qc->fetch_assoc()) {
        $customer_order_count = intval($rc['c'] ?? 0);
    }
} catch (Throwable $e) {}

// 4. LẤY TOÀN BỘ TIN NHẮN TRONG HỘI THOẠI
$messages = [];
try {
    $q_msg = $conn->query("SELECT sm.*, u.username as sender_name, u.role as sender_role 
        FROM support_messages sm 
        JOIN users u ON sm.sender_id = u.user_id 
        WHERE sm.ticket_id = $ticket_id 
        ORDER BY sm.created_at ASC");
    if ($q_msg && $q_msg->num_rows > 0) {
        while ($m = $q_msg->fetch_assoc()) {
            $m['attachments'] = [];
            $mid = intval($m['message_id']);
            $q_att = $conn->query("SELECT * FROM support_attachments WHERE ticket_id = $ticket_id AND message_id = $mid");
            if ($q_att && $q_att->num_rows > 0) {
                while ($a = $q_att->fetch_assoc()) {
                    $m['attachments'][] = $a;
                }
            }
            $messages[] = $m;
        }
    }
} catch (Throwable $e) {}

$st_info = get_ticket_status_info($ticket['status']);
$pr_info = get_ticket_priority_info($ticket['priority']);
$categories = get_support_categories();
$cat_info = $categories[$ticket['category']] ?? ['label' => 'Hỗ trợ chung', 'icon' => 'bi-chat-dots'];

// Giải mã password tài khoản game nếu cần kiểm tra kỹ thuật
$decrypted_game_pwd = '';
if (!empty($ticket['account_password_encrypted'])) {
    if (function_exists('decrypt_game_password')) {
        $decrypted_game_pwd = decrypt_game_password($ticket['account_password_encrypted']);
    } else {
        $decrypted_game_pwd = $ticket['account_password_encrypted'];
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 py-4 text-white">
    <!-- Top Action Navigation -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <a href="admin_support.php" class="btn btn-outline-secondary btn-sm py-2 px-3 text-white">
            <i class="bi bi-chevron-left me-1"></i> Quay lại danh sách yêu cầu
        </a>
        <div class="d-flex align-items-center gap-2">
            <span class="badge <?php echo $st_info['badge_class']; ?> py-2 px-3" style="font-size: 0.82rem;">
                <i class="bi <?php echo $st_info['icon']; ?> me-1"></i> <?php echo $st_info['label']; ?>
            </span>
            <span class="badge <?php echo $pr_info['badge_class']; ?> py-2 px-3" style="font-size: 0.82rem;">
                <?php echo $pr_info['label']; ?>
            </span>
        </div>
    </div>

    <!-- Alert thông báo -->
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4 rounded-3" style="background: rgba(16,185,129,0.15); border: 1px solid #10b981; color: #6ee7b7;">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4 rounded-3" style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- CỘT TRÁI: KHUNG HỘI THOẠI & TRẢ LỜI KHÁCH HÀNG -->
        <div class="col-lg-8">
            <div class="panel-dark p-4 rounded-4 shadow-sm mb-4">
                <!-- Tiêu đề Ticket -->
                <div class="pb-3 mb-3 border-bottom border-secondary border-opacity-25">
                    <div class="d-flex flex-wrap align-items-center gap-2 text-secondary small mb-1">
                        <span class="font-monospace text-primary fw-bold fs-6"><?php echo htmlspecialchars($ticket['ticket_code']); ?></span>
                        <span>•</span>
                        <span class="badge bg-secondary bg-opacity-25 text-light"><?php echo $cat_info['label']; ?></span>
                        <span>•</span>
                        <span>Tạo lúc: <?php echo date('d/m/Y H:i:s', strtotime($ticket['created_at'])); ?></span>
                    </div>
                    <h3 class="fw-bold text-white mb-2"><?php echo htmlspecialchars($ticket['title']); ?></h3>
                </div>

                <!-- DÒNG THỜI GIAN TIN NHẮN HỘI THOẠI -->
                <div class="d-flex flex-column gap-3 mb-4" id="adminChatThread" style="max-height: 580px; overflow-y: auto; padding-right: 6px;">
                    <?php foreach ($messages as $m): 
                        $is_admin_msg = ($m['sender_type'] === 'admin' || $m['sender_role'] == 1);
                    ?>
                        <div class="d-flex gap-3">
                            <div class="flex-shrink-0">
                                <?php if ($is_admin_msg): ?>
                                    <div class="rounded-circle d-flex align-items-center justify-content-center shadow" style="width: 42px; height: 42px; background: linear-gradient(135deg, #ef4444, #f43f5e); color: white;" title="Quản trị viên">
                                        <i class="bi bi-shield-check fs-5"></i>
                                    </div>
                                <?php else: ?>
                                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($m['sender_name']); ?>&background=6366f1&color=fff&bold=true&rounded=true" width="42" height="42" class="rounded-circle shadow" alt="Avatar">
                                <?php endif; ?>
                            </div>

                            <div class="flex-grow-1" style="max-width: 88%;">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="fw-bold <?php echo $is_admin_msg ? 'text-danger' : 'text-primary'; ?>" style="font-size: 0.88rem;">
                                        <?php echo $is_admin_msg ? '<i class="bi bi-shield-lock-fill me-1"></i> Quản Trị Viên (Bạn / Admin)' : '@' . htmlspecialchars($m['sender_name']); ?>
                                    </span>
                                    <span class="text-secondary small" style="font-size: 0.72rem;"><?php echo format_time_ago($m['created_at']); ?></span>
                                </div>

                                <div class="p-3 rounded-4 shadow-sm" style="background: <?php echo $is_admin_msg ? 'rgba(239,68,68,0.08)' : 'rgba(255,255,255,0.04)'; ?>; border: 1px solid <?php echo $is_admin_msg ? 'rgba(239,68,68,0.25)' : 'rgba(255,255,255,0.08)'; ?>; color: #e2e8f0; font-size: 0.92rem; line-height: 1.6;">
                                    <?php echo nl2br(htmlspecialchars($m['message'])); ?>

                                    <!-- ĐÍNH KÈM -->
                                    <?php if (!empty($m['attachments'])): ?>
                                        <div class="mt-3 pt-2 border-top border-secondary border-opacity-25">
                                            <div class="text-secondary small mb-2"><i class="bi bi-paperclip me-1"></i> Tệp đính kèm:</div>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php foreach ($m['attachments'] as $att): ?>
                                                    <a href="<?php echo htmlspecialchars($att['file_path']); ?>" target="_blank" class="d-inline-flex align-items-center gap-2 p-2 rounded-3 text-decoration-none" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); color: #93c5fd;">
                                                        <i class="bi bi-image fs-5 text-primary"></i>
                                                        <span class="small text-truncate" style="max-width: 180px;"><?php echo htmlspecialchars($att['file_name']); ?></span>
                                                        <i class="bi bi-box-arrow-up-right text-secondary ms-1"></i>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div id="reply_bottom"></div>
                </div>

                <!-- ĐÁNH GIÁ CỦA KHÁCH HÀNG NẾU CÓ -->
                <?php if (!empty($ticket['rating'])): ?>
                    <div class="panel-dark p-3 rounded-4 mb-4 border border-warning border-opacity-30" style="background: rgba(245,158,11,0.06);">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="text-warning fw-bold fs-5">
                                <?php echo str_repeat('★', intval($ticket['rating'])) . str_repeat('☆', 5 - intval($ticket['rating'])); ?>
                            </span>
                            <span class="text-white small fw-bold">Khách hàng đã đánh giá dịch vụ hỗ trợ (<?php echo $ticket['rating']; ?>/5 sao)</span>
                        </div>
                        <?php if (!empty($ticket['rating_comment'])): ?>
                            <p class="mb-0 text-light small fst-italic">"<?php echo htmlspecialchars($ticket['rating_comment']); ?>"</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- FORM PHẢN HỒI CỦA ADMIN -->
                <form action="admin_support_detail.php?id=<?php echo $ticket_id; ?>" method="POST" enctype="multipart/form-data" class="pt-3 border-top border-secondary border-opacity-25">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <!-- Quick Response Templates -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <label class="form-label text-secondary small fw-bold mb-0">
                            <i class="bi bi-chat-left-dots me-1"></i> Trả lời khách hàng:
                        </label>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-secondary small">Mẫu trả lời nhanh:</span>
                            <select class="form-select form-select-sm form-control-gaming py-1" style="width: auto; max-width: 250px;" onchange="insertTemplate(this.value)">
                                <option value="">-- Chọn câu trả lời mẫu --</option>
                                <option value="Xin chào bạn, đội ngũ hỗ trợ ANPLAY đã tiếp nhận thông tin và đang tiến hành kiểm tra xử lý sự cố. Chúng tôi sẽ phản hồi trong giây lát!">Tiếp nhận & kiểm tra</option>
                                <option value="Bạn vui lòng chụp ảnh màn hình lỗi cụ thể và gửi lại tại đây để kỹ thuật viên kiểm tra chính xác nhé!">Yêu cầu ảnh chụp lỗi</option>
                                <option value="Chúng tôi đã cấp lại tài khoản mới vào Thư Viện cho bạn. Bạn vui lòng vào Thư Viện kiểm tra thông tin và đăng nhập lại nhé. Chúc bạn chơi game vui vẻ!">Cấp lại tài khoản mới</option>
                                <option value="Sự cố tài khoản đã được kỹ thuật viên khắc phục thành công. Bạn hãy thử đăng nhập lại ngay bây giờ nhé!">Đã khắc phục xong</option>
                                <option value="Chúng tôi đã gia hạn bù thêm thời gian sử dụng game cho bạn do sự cố kỹ thuật. Cảm ơn bạn đã kiên nhẫn!">Gia hạn thời gian bù</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <textarea name="message" id="adminReplyText" class="form-control form-control-gaming" rows="4" placeholder="Nhập câu trả lời hoặc hướng dẫn xử lý cho khách hàng..." required></textarea>
                    </div>

                    <div class="row g-2 align-items-center">
                        <div class="col-md-5">
                            <div class="d-flex align-items-center gap-2">
                                <label for="admin_att" class="btn btn-outline-secondary btn-sm text-secondary" style="cursor: pointer;">
                                    <i class="bi bi-paperclip me-1"></i> Đính kèm ảnh
                                </label>
                                <input type="file" id="admin_att" name="attachment" class="d-none" accept="image/*" onchange="document.getElementById('admin_att_name').innerText = this.files[0]?.name || '';">
                                <span class="text-info small text-truncate" id="admin_att_name" style="max-width: 150px;"></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select name="reply_status" class="form-select form-select-sm form-control-gaming">
                                <option value="waiting_customer" <?php echo ($ticket['status'] === 'waiting_customer') ? 'selected' : ''; ?>>Chuyển: Chờ khách phản hồi</option>
                                <option value="in_progress" <?php echo ($ticket['status'] === 'in_progress') ? 'selected' : ''; ?>>Chuyển: Đang xử lý</option>
                                <option value="resolved">Chuyển: Đã giải quyết xong</option>
                            </select>
                        </div>
                        <div class="col-md-3 text-end">
                            <button type="submit" name="btn_admin_reply" class="btn btn-gaming-primary w-100 py-2">
                                <i class="bi bi-send-fill me-1"></i> Gửi Trả Lời
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- CỘT PHẢI: ĐIỀU KHIỂN TRẠNG THÁI & BỐI CẢNH ĐƠN HÀNG/KHÁCH HÀNG -->
        <div class="col-lg-4">
            <!-- PANEL THAY ĐỔI TRẠNG THÁI & ƯU TIÊN -->
            <div class="panel-dark p-4 rounded-4 shadow-sm mb-4">
                <h5 class="fw-bold text-white mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-gear-fill me-2 text-warning"></i> Quản Lý Trạng Thái Ticket
                </h5>

                <form action="admin_support_detail.php?id=<?php echo $ticket_id; ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Trạng Thái Ticket</label>
                        <select name="status" class="form-select form-control-gaming">
                            <option value="new" <?php echo ($ticket['status'] === 'new') ? 'selected' : ''; ?>>🔵 Mới gửi</option>
                            <option value="assigned" <?php echo ($ticket['status'] === 'assigned') ? 'selected' : ''; ?>>Đã tiếp nhận</option>
                            <option value="in_progress" <?php echo ($ticket['status'] === 'in_progress') ? 'selected' : ''; ?>>🟡 Đang xử lý</option>
                            <option value="waiting_customer" <?php echo ($ticket['status'] === 'waiting_customer') ? 'selected' : ''; ?>>⚪ Chờ khách phản hồi</option>
                            <option value="resolved" <?php echo ($ticket['status'] === 'resolved') ? 'selected' : ''; ?>>✅ Đã giải quyết</option>
                            <option value="closed" <?php echo ($ticket['status'] === 'closed') ? 'selected' : ''; ?>>Đã đóng hoàn tất</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Mức Độ Ưu Tiên</label>
                        <select name="priority" class="form-select form-control-gaming">
                            <option value="low" <?php echo ($ticket['priority'] === 'low') ? 'selected' : ''; ?>>⚪ Thấp</option>
                            <option value="normal" <?php echo ($ticket['priority'] === 'normal') ? 'selected' : ''; ?>>🔵 Bình thường</option>
                            <option value="high" <?php echo ($ticket['priority'] === 'high') ? 'selected' : ''; ?>>🟠 Ưu tiên cao</option>
                            <option value="urgent" <?php echo ($ticket['priority'] === 'urgent') ? 'selected' : ''; ?>>🔴 Khẩn cấp</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Admin Phụ Trách</label>
                        <select name="assigned_to" class="form-select form-control-gaming">
                            <option value="0">-- Chưa phân công --</option>
                            <?php
                            $q_adms = $conn->query("SELECT user_id, username, role FROM users WHERE role IN (1, 2) ORDER BY role DESC, username ASC");
                            if ($q_adms) {
                                while ($adm = $q_adms->fetch_assoc()) {
                                    $rb = ($adm['role'] == 2) ? '[Super Admin]' : '[Admin]';
                                    $sel = (isset($ticket['assigned_to']) && $ticket['assigned_to'] == $adm['user_id']) ? 'selected' : '';
                                    echo "<option value='{$adm['user_id']}' $sel>@{$adm['username']} $rb</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Ghi Chú Giải Quyết (Gửi kèm nếu đóng/giải quyết)</label>
                        <input type="text" name="resolution_note" class="form-control form-control-gaming" placeholder="VD: Đã cấp lại tài khoản Elden Ring mới">
                    </div>

                    <button type="submit" name="btn_update_ticket_meta" class="btn btn-gaming-secondary w-100 py-2">
                        <i class="bi bi-save me-1"></i> Lưu Cập Nhật
                    </button>
                </form>
            </div>

            <!-- PANEL THÔNG TIN KHÁCH HÀNG -->
            <div class="panel-dark p-4 rounded-4 shadow-sm mb-4">
                <h5 class="fw-bold text-white mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-person-circle me-2 text-primary"></i> Khách Hàng
                </h5>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($ticket['customer_name']); ?>&background=6366f1&color=fff&bold=true&rounded=true" width="48" height="48" class="rounded-circle shadow" alt="Avatar">
                    <div>
                        <div class="fw-bold text-white">@<?php echo htmlspecialchars($ticket['customer_name']); ?></div>
                        <div class="text-secondary small"><?php echo htmlspecialchars($ticket['customer_email']); ?></div>
                    </div>
                </div>
                <div class="d-flex justify-content-between small text-secondary py-1 border-top border-secondary border-opacity-10">
                    <span>Tổng đơn thuê:</span>
                    <strong class="text-white"><?php echo $customer_order_count; ?> đơn</strong>
                </div>
                <div class="d-flex justify-content-between small text-secondary py-1 border-top border-secondary border-opacity-10">
                    <span>Thành viên từ:</span>
                    <span><?php echo date('d/m/Y', strtotime($ticket['user_created_at'])); ?></span>
                </div>
            </div>

            <!-- PANEL THÔNG TIN ĐƠN THUÊ & TÀI KHOẢN GAME (NẾU CÓ) -->
            <?php if (!empty($ticket['game_title']) || !empty($ticket['order_code'])): ?>
                <div class="panel-dark p-4 rounded-4 shadow-sm mb-4">
                    <h5 class="fw-bold text-white mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                        <i class="bi bi-controller me-2 text-success"></i> Đơn Thuê & Game Gặp Sự Cố
                    </h5>

                    <?php if (!empty($ticket['game_title'])): ?>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php if (!empty($ticket['game_img'])): ?>
                                <img src="<?php echo htmlspecialchars($ticket['game_img']); ?>" width="55" height="55" class="rounded-3 object-fit-cover shadow" alt="Game">
                            <?php endif; ?>
                            <div>
                                <h6 class="fw-bold mb-1 text-white"><?php echo htmlspecialchars($ticket['game_title']); ?></h6>
                                <span class="badge bg-secondary bg-opacity-25 text-info">Bản quyền Steam / PC</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($ticket['order_code'])): ?>
                        <div class="p-3 rounded-3 mb-3" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-secondary small">Mã đơn:</span>
                                <strong class="text-warning">#<?php echo htmlspecialchars($ticket['order_code']); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-secondary small">Giá trị:</span>
                                <span><?php echo number_format($ticket['rental_price'] ?? 0); ?>đ</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-secondary small">Hạn thuê:</span>
                                <span class="text-info"><?php echo !empty($ticket['due_date']) ? date('d/m/Y H:i', strtotime($ticket['due_date'])) : 'Chưa kích hoạt'; ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-secondary small">Trạng thái:</span>
                                <span class="badge bg-success bg-opacity-25 text-success"><?php echo $ticket['borrow_status']; ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- THÔNG TIN TÀI KHOẢN GAME HIỆN TẠI -->
                    <?php if (!empty($ticket['account_username'])): ?>
                        <div class="p-3 rounded-3" style="background: rgba(99,102,241,0.08); border: 1px solid rgba(99,102,241,0.25);">
                            <span class="text-secondary small d-block mb-1">Tài khoản cấp cho khách:</span>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-secondary small">Tài khoản:</span>
                                <strong class="text-white font-monospace"><?php echo htmlspecialchars($ticket['account_username']); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-secondary small">Mật khẩu:</span>
                                <strong class="text-warning font-monospace"><?php echo htmlspecialchars($decrypted_game_pwd); ?></strong>
                            </div>
                        </div>
                        <a href="admin_accounts.php" class="btn btn-outline-danger btn-sm w-100 mt-2">
                            <i class="bi bi-box-seam me-1"></i> Mở Kho Tài Khoản Game
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function insertTemplate(val) {
        if (val) {
            const txt = document.getElementById('adminReplyText');
            if (txt) {
                txt.value = val;
                txt.focus();
            }
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        const thread = document.getElementById("adminChatThread");
        if (thread) {
            thread.scrollTop = thread.scrollHeight;
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

