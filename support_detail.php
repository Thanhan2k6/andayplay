<?php
/**
 * ANPLAY STORE - CHI TIẾT VÀ HỘI THOẠI HỖ TRỢ (TICKET CONVERSATION)
 * Giao diện trò chuyện trực tiếp giữa Khách hàng và Đội ngũ Quản trị viên
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
$is_admin = (isset($_SESSION['role']) && in_array(intval($_SESSION['role']), [1, 2]));
$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($ticket_id <= 0) {
    header("Location: support.php");
    exit();
}

// 1. KIỂM TRA QUYỀN TRUY CẬP (IDOR PROTECTION)
$ticket = null;
try {
    $stmt = $conn->prepare("SELECT st.*, u.username as customer_name, u.email as customer_email, g.title as game_title, g.image_url as game_img, br.order_code, br.rental_price, br.status as borrow_status 
        FROM support_tickets st 
        JOIN users u ON st.user_id = u.user_id 
        LEFT JOIN games g ON st.game_id = g.game_id 
        LEFT JOIN borrow_records br ON st.order_id = br.borrow_id 
        WHERE st.ticket_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
} catch (Throwable $e) {}

if (!$ticket) {
    echo "<script>alert('Không tìm thấy yêu cầu hỗ trợ này!'); window.location='support.php';</script>";
    exit();
}

// Nếu không phải Admin và cũng không phải chủ sở hữu Ticket -> CHẶN
if (!$is_admin && intval($ticket['user_id']) !== $user_id) {
    http_response_code(403);
    die("<div style='font-family:sans-serif; text-align:center; padding:50px; background:#0f121a; color:#fff;'>
        <h2 style='color:#ef4444;'>🚫 Truy Cập Bị Từ Chối (403 Forbidden)</h2>
        <p>Bạn không có quyền xem hoặc can thiệp vào yêu cầu hỗ trợ của khách hàng khác.</p>
        <a href='support.php' style='color:#6366f1; text-decoration:underline;'>Quay lại Trung tâm hỗ trợ của bạn</a>
    </div>");
}

// Đảm bảo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success_msg = "";
$error_msg = "";

// 2. XỬ LÝ GỬI TIN NHẮN PHẢN HỒI (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_send_reply'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật không hợp lệ.";
    } else {
        $msg_text = trim($_POST['message'] ?? '');
        if (empty($msg_text)) {
            $error_msg = "Vui lòng nhập nội dung phản hồi.";
        } else {
            $attachments = [];
            if (isset($_FILES['attachment']) && !empty($_FILES['attachment']['name'])) {
                $up_res = upload_support_attachment($_FILES['attachment'], $ticket_id, $user_id);
                if ($up_res['success']) {
                    $attachments[] = $up_res;
                } else {
                    $error_msg = $up_res['error'];
                }
            }

            if (empty($error_msg)) {
                $sender_type = $is_admin ? 'admin' : 'user';
                $reply_res = send_support_message($conn, $ticket_id, $user_id, $sender_type, $msg_text, $attachments);
                if ($reply_res['success']) {
                    header("Location: support_detail.php?id=" . $ticket_id . "#reply_end");
                    exit();
                } else {
                    $error_msg = $reply_res['error'];
                }
            }
        }
    }
}

// 3. XỬ LÝ MỞ LẠI TICKET (RE-OPEN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_reopen_ticket'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật không hợp lệ.";
    } else {
        $reopen_reason = trim($_POST['reopen_reason'] ?? 'Vấn đề vẫn chưa được khắc phục hoàn toàn');
        if (reopen_support_ticket($conn, $ticket_id, $user_id, $reopen_reason)) {
            $success_msg = "Yêu cầu đã được mở lại thành công! Đội ngũ hỗ trợ đã nhận được thông báo.";
            // Reload ticket data
            $ticket['status'] = 'in_progress';
        } else {
            $error_msg = "Không thể mở lại yêu cầu. Vui lòng thử lại sau.";
        }
    }
}

// 4. XỬ LÝ ĐÁNH GIÁ (RATING)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_submit_rating'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực không hợp lệ.";
    } else {
        $rating_stars = intval($_POST['rating'] ?? 5);
        $rating_comment = trim($_POST['rating_comment'] ?? '');
        if (rate_support_ticket($conn, $ticket_id, $user_id, $rating_stars, $rating_comment)) {
            $success_msg = "Cảm ơn bạn đã đánh giá chất lượng dịch vụ!";
            $ticket['rating'] = $rating_stars;
            $ticket['rating_comment'] = $rating_comment;
        } else {
            $error_msg = "Không thể lưu đánh giá. Vui lòng thử lại!";
        }
    }
}

// 5. LẤY TOÀN BỘ TIN NHẮN VÀ ĐÍNH KÈM CỦA TICKET
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
            // Lấy đính kèm của message
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

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 py-4 text-white">
    <!-- Breadcrumb & Back button -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <a href="<?php echo $is_admin ? 'admin_support.php' : 'support.php'; ?>" class="btn btn-outline-secondary btn-sm py-2 px-3 text-white">
            <i class="bi bi-chevron-left me-1"></i> Quay lại danh sách
        </a>
        <div class="d-flex align-items-center gap-2">
            <span class="badge <?php echo $st_info['badge_class']; ?> py-2 px-3" style="font-size: 0.82rem;">
                <i class="bi <?php echo $st_info['icon']; ?> me-1"></i> <?php echo $st_info['label']; ?>
            </span>
            <span class="badge <?php echo $pr_info['badge_class']; ?> py-2 px-3" style="font-size: 0.82rem;">
                <i class="bi bi-flag-fill me-1"></i> <?php echo $pr_info['label']; ?>
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
        <!-- CỘT TRÁI: KHUNG HỘI THOẠI TRÒ CHUYỆN (CONVERSATION) -->
        <div class="col-lg-8">
            <div class="panel-dark p-4 rounded-4 shadow-sm mb-4">
                <!-- Header của Ticket -->
                <div class="pb-3 mb-3 border-bottom border-secondary border-opacity-25">
                    <div class="d-flex align-items-center gap-2 text-secondary small mb-1">
                        <span class="font-monospace text-primary fw-bold"><?php echo htmlspecialchars($ticket['ticket_code']); ?></span>
                        <span>•</span>
                        <span><?php echo $cat_info['label']; ?></span>
                        <span>•</span>
                        <span>Tạo lúc: <?php echo date('d/m/Y H:i', strtotime($ticket['created_at'])); ?></span>
                    </div>
                    <h3 class="fw-bold text-white mb-2"><?php echo htmlspecialchars($ticket['title']); ?></h3>
                </div>

                <!-- DÒNG THỜI GIAN TIN NHẮN (MESSAGES THREAD) -->
                <div class="d-flex flex-column gap-3 mb-4" id="chatThread" style="max-height: 580px; overflow-y: auto; padding-right: 6px;">
                    <?php foreach ($messages as $m): 
                        $is_msg_admin = ($m['sender_type'] === 'admin' || $m['sender_role'] == 1);
                    ?>
                        <div class="d-flex gap-3 <?php echo $is_msg_admin ? 'flex-row' : 'flex-row'; ?>">
                            <!-- Avatar -->
                            <div class="flex-shrink-0">
                                <?php if ($is_msg_admin): ?>
                                    <div class="rounded-circle d-flex align-items-center justify-content-center shadow" style="width: 42px; height: 42px; background: linear-gradient(135deg, #ef4444, #f43f5e); color: white;" title="Quản trị viên">
                                        <i class="bi bi-shield-check fs-5"></i>
                                    </div>
                                <?php else: ?>
                                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($m['sender_name']); ?>&background=6366f1&color=fff&bold=true&rounded=true" width="42" height="42" class="rounded-circle shadow" alt="Avatar">
                                <?php endif; ?>
                            </div>

                            <!-- Message Bubble -->
                            <div class="flex-grow-1" style="max-width: 88%;">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="fw-bold <?php echo $is_msg_admin ? 'text-danger' : 'text-primary'; ?>" style="font-size: 0.88rem;">
                                        <?php echo $is_msg_admin ? '<i class="bi bi-patch-check-fill me-1"></i> ANPLAY Support Team' : '@' . htmlspecialchars($m['sender_name']); ?>
                                    </span>
                                    <?php if ($is_msg_admin): ?>
                                        <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-25" style="font-size: 0.65rem;">Quản Trị Viên</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size: 0.65rem;">Khách hàng</span>
                                    <?php endif; ?>
                                    <span class="text-secondary small" style="font-size: 0.72rem;"><?php echo format_time_ago($m['created_at']); ?></span>
                                </div>

                                <div class="p-3 rounded-4 shadow-sm" style="background: <?php echo $is_msg_admin ? 'rgba(239, 68, 68, 0.08)' : 'rgba(255,255,255,0.04)'; ?>; border: 1px solid <?php echo $is_msg_admin ? 'rgba(239,68,68,0.25)' : 'rgba(255,255,255,0.08)'; ?>; color: #e2e8f0; font-size: 0.92rem; line-height: 1.6;">
                                    <?php echo nl2br(htmlspecialchars($m['message'])); ?>

                                    <!-- ĐÍNH KÈM NẾU CÓ -->
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
                    <div id="reply_end"></div>
                </div>

                <!-- KHU VỰC ĐÁNH GIÁ NẾU TICKET ĐÃ GIẢI QUYẾT -->
                <?php if ($ticket['status'] === 'resolved'): ?>
                    <div class="panel-dark p-3 rounded-4 mb-4 border border-success border-opacity-40" id="rating_section" style="background: rgba(16,185,129,0.06);">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h6 class="fw-bold text-success mb-0">
                                <i class="bi bi-check-circle-fill me-2"></i> Yêu cầu đã được giải quyết
                            </h6>
                            <!-- Nút mở lại ticket nếu khách vẫn gặp vấn đề -->
                            <button class="btn btn-outline-warning btn-sm" data-bs-toggle="collapse" data-bs-target="#reopenForm">
                                <i class="bi bi-arrow-clockwise me-1"></i> Vấn đề chưa được xử lý?
                            </button>
                        </div>

                        <!-- Form Reopen ẩn hiện -->
                        <div class="collapse mb-3 mt-2" id="reopenForm">
                            <form action="support_detail.php?id=<?php echo $ticket_id; ?>" method="POST" class="p-3 rounded-3" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(245,158,11,0.3);">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <label class="form-label text-warning small fw-bold">Mô tả lý do vì sao sự cố vẫn chưa được khắc phục:</label>
                                <textarea name="reopen_reason" class="form-control form-control-gaming mb-2" rows="2" placeholder="VD: Mật khẩu mới vẫn báo sai, hoặc game vẫn bị văng..." required></textarea>
                                <button type="submit" name="btn_reopen_ticket" class="btn btn-warning btn-sm">
                                    <i class="bi bi-exclamation-octagon-fill me-1"></i> Xác Nhận Mở Lại Ticket
                                </button>
                            </form>
                        </div>

                        <!-- Form Đánh giá chất lượng hỗ trợ -->
                        <div class="mt-2">
                            <?php if (!empty($ticket['rating'])): ?>
                                <div class="text-secondary small">
                                    Bạn đã đánh giá: 
                                    <span class="text-warning fw-bold">
                                        <?php echo str_repeat('★', intval($ticket['rating'])) . str_repeat('☆', 5 - intval($ticket['rating'])); ?>
                                    </span>
                                    <?php if (!empty($ticket['rating_comment'])): ?>
                                        <p class="mt-1 fst-italic text-light small mb-0">"<?php echo htmlspecialchars($ticket['rating_comment']); ?>"</p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-secondary small mb-2">Hãy dành 5 giây đánh giá để giúp ANPLAY nâng cao chất lượng phục vụ bạn tốt hơn:</p>
                                <form action="support_detail.php?id=<?php echo $ticket_id; ?>" method="POST" class="d-flex flex-wrap align-items-center gap-3">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <div class="d-flex align-items-center gap-1">
                                        <?php for ($s = 5; $s >= 1; $s--): ?>
                                            <input type="radio" class="btn-check" name="rating" id="star_<?php echo $s; ?>" value="<?php echo $s; ?>" <?php echo ($s === 5) ? 'checked' : ''; ?>>
                                            <label class="btn btn-outline-warning btn-sm py-1 px-2" for="star_<?php echo $s; ?>"><?php echo $s; ?> ★</label>
                                        <?php endfor; ?>
                                    </div>
                                    <input type="text" name="rating_comment" class="form-control form-control-gaming form-control-sm" style="max-width: 260px;" placeholder="Nhận xét ngắn...">
                                    <button type="submit" name="btn_submit_rating" class="btn btn-success btn-sm">
                                        Gửi Đánh Giá
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- FORM NHẬP TIN NHẮN PHẢN HỒI (REPLY INPUT BOX) -->
                <?php if ($ticket['status'] !== 'closed'): ?>
                    <form action="support_detail.php?id=<?php echo $ticket_id; ?>" method="POST" enctype="multipart/form-data" class="pt-3 border-top border-secondary border-opacity-25">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">
                                <i class="bi bi-chat-left-text me-1"></i> Nhập phản hồi của bạn:
                            </label>
                            <textarea name="message" class="form-control form-control-gaming" rows="3" placeholder="Nhập câu hỏi, cập nhật tình hình hoặc cung cấp thêm thông tin cho kỹ thuật viên..." required></textarea>
                        </div>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div class="d-flex align-items-center gap-2">
                                <label for="reply_att" class="btn btn-outline-secondary btn-sm text-secondary" style="cursor: pointer;">
                                    <i class="bi bi-paperclip me-1"></i> Đính kèm ảnh lỗi
                                </label>
                                <input type="file" id="reply_att" name="attachment" class="d-none" accept="image/*" onchange="document.getElementById('att_preview_name').innerText = this.files[0]?.name || '';">
                                <span class="text-info small text-truncate" id="att_preview_name" style="max-width: 200px;"></span>
                            </div>
                            <button type="submit" name="btn_send_reply" class="btn btn-gaming-primary px-4 py-2">
                                <i class="bi bi-send-fill me-1"></i> Gửi Phản Hồi
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="p-3 text-center rounded-3 text-secondary" style="background: rgba(0,0,0,0.2);">
                        <i class="bi bi-lock-fill me-1"></i> Yêu cầu hỗ trợ này đã được đóng hoàn tất. Nếu cần giúp đỡ mới, bạn vui lòng tạo ticket khác.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- CỘT PHẢI: THÔNG TIN CHI TIẾT & BỐI CẢNH (CONTEXT PANEL) -->
        <div class="col-lg-4">
            <!-- Panel thông tin sự cố -->
            <div class="panel-dark p-4 rounded-4 shadow-sm mb-4">
                <h5 class="fw-bold text-white mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-info-circle me-2 text-info"></i> Thông Tin Yêu Cầu
                </h5>

                <div class="d-flex flex-column gap-3 small">
                    <div>
                        <span class="text-secondary d-block">Mã yêu cầu:</span>
                        <strong class="font-monospace text-primary fs-6"><?php echo htmlspecialchars($ticket['ticket_code']); ?></strong>
                    </div>

                    <div>
                        <span class="text-secondary d-block">Danh mục:</span>
                        <strong><?php echo $cat_info['label']; ?></strong>
                    </div>

                    <div>
                        <span class="text-secondary d-block">Trạng thái:</span>
                        <span class="badge <?php echo $st_info['badge_class']; ?> mt-1">
                            <i class="bi <?php echo $st_info['icon']; ?> me-1"></i> <?php echo $st_info['label']; ?>
                        </span>
                    </div>

                    <div>
                        <span class="text-secondary d-block">Mức độ ưu tiên:</span>
                        <span class="badge <?php echo $pr_info['badge_class']; ?> mt-1">
                            <?php echo $pr_info['label']; ?>
                        </span>
                    </div>

                    <div>
                        <span class="text-secondary d-block">Thời gian tạo:</span>
                        <span><?php echo date('d/m/Y H:i:s', strtotime($ticket['created_at'])); ?></span>
                    </div>

                    <div>
                        <span class="text-secondary d-block">Cập nhật lần cuối:</span>
                        <span><?php echo format_time_ago($ticket['updated_at']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Panel thông tin Game / Đơn thuê liên quan -->
            <?php if (!empty($ticket['game_title']) || !empty($ticket['order_code'])): ?>
                <div class="panel-dark p-4 rounded-4 shadow-sm mb-4">
                    <h5 class="fw-bold text-white mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                        <i class="bi bi-controller me-2 text-success"></i> Dịch Vụ Liên Quan
                    </h5>

                    <?php if (!empty($ticket['game_title'])): ?>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php if (!empty($ticket['game_img'])): ?>
                                <img src="<?php echo htmlspecialchars($ticket['game_img']); ?>" width="55" height="55" class="rounded-3 object-fit-cover shadow-sm" alt="Game">
                            <?php endif; ?>
                            <div>
                                <h6 class="fw-bold mb-1 text-white"><?php echo htmlspecialchars($ticket['game_title']); ?></h6>
                                <span class="badge bg-secondary bg-opacity-25 text-info">Bản quyền Steam / PC</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($ticket['order_code'])): ?>
                        <div class="p-3 rounded-3 mb-2" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-secondary small">Mã đơn thuê:</span>
                                <strong class="text-warning">#<?php echo htmlspecialchars($ticket['order_code']); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-secondary small">Giá trị:</span>
                                <span><?php echo number_format($ticket['rental_price'] ?? 0); ?>đ</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-secondary small">Trạng thái đơn:</span>
                                <span class="badge bg-success bg-opacity-25 text-success"><?php echo $ticket['borrow_status']; ?></span>
                            </div>
                        </div>
                        <a href="my_library.php" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Xem Trong Thư Viện
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Panel hỗ trợ nhanh 24/7 -->
            <div class="panel-dark p-4 rounded-4 shadow-sm text-center" style="border: 1px dashed rgba(99,102,241,0.3);">
                <i class="bi bi-shield-check fs-2 text-primary mb-2 d-block"></i>
                <h6 class="fw-bold text-white mb-1">Cam Kết ANPLAY STORE</h6>
                <p class="text-secondary small mb-3">Mọi khiếu nại tài khoản lỗi đều được giải quyết trong vòng 15-30 phút hoặc đổi mới ngay lập tức.</p>
                <div class="badge bg-dark border border-secondary text-secondary p-2 w-100">
                    <i class="bi bi-headset me-1 text-success"></i> Kỹ thuật viên trực tuyến 24/7
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Tự động cuộn xuống tin nhắn cuối
    document.addEventListener("DOMContentLoaded", function() {
        const thread = document.getElementById("chatThread");
        if (thread) {
            thread.scrollTop = thread.scrollHeight;
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

