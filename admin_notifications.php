<?php
/**
 * ANPLAY STORE - QUẢN LÝ GỬI THÔNG BÁO HỆ THỐNG & KHUYẾN MÃI (ADMIN NOTIFICATION DISPATCHER)
 * Gửi thông báo broadcast hoặc chỉ định cho khách hàng, nhóm thành viên hoặc cá nhân
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/connect.php';

// Kiểm tra quyền Quản trị viên (ADMIN hoặc SUPER_ADMIN)
require_admin();

// Kiểm tra quyền xem trung tâm thông báo
if (!check_user_permission($conn, $_SESSION['user_id'], 'notification.view')) {
    header("Location: admin_dashboard.php?error=unauthorized");
    exit();
}

$current_page = 'admin_notifications.php';

// Đảm bảo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success_msg = "";
$error_msg = "";

// XỬ LÝ GỬI THÔNG BÁO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_send_notification'])) {
    if (!check_user_permission($conn, $_SESSION['user_id'], 'notification.send')) {
        $error_msg = "Bạn không có quyền gửi thông báo hệ thống (cần quyền notification.send).";
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Mã xác thực bảo mật không hợp lệ.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $type = trim($_POST['type'] ?? 'promotion');
        $target = trim($_POST['target'] ?? 'all');
        $link = trim($_POST['link'] ?? '');
        $specific_user = trim($_POST['specific_user'] ?? '');

        if (empty($title) || empty($message)) {
            $error_msg = "Vui lòng nhập đầy đủ tiêu đề và nội dung thông báo.";
        } else {
            $sent_count = 0;
            if ($target === 'specific') {
                // Gửi cho một user cụ thể (nhập username hoặc user_id)
                $find_stmt = $conn->prepare("SELECT user_id, username FROM users WHERE username = ? OR user_id = ? LIMIT 1");
                if ($find_stmt) {
                    $user_id_cand = intval($specific_user);
                    $find_stmt->bind_param("si", $specific_user, $user_id_cand);
                    $find_stmt->execute();
                    $u_res = $find_stmt->get_result();
                    if ($u_res && $target_u = $u_res->fetch_assoc()) {
                        $target_uid = intval($target_u['user_id']);
                        if (create_notification($conn, $target_uid, $type, $title, $message, $link, null, 0)) {
                            $sent_count = 1;
                        }
                    } else {
                        $error_msg = "Không tìm thấy khách hàng '$specific_user' trong hệ thống.";
                    }
                    $find_stmt->close();
                }
            } else {
                // Broadcast nhóm
                $sent_count = broadcast_notification($conn, $title, $message, $type, $link, $target);
            }

            if ($sent_count > 0) {
                write_audit_log($conn, $_SESSION['user_id'], 'SEND_NOTIFICATION', 'notification', 0, [
                    'target' => $target,
                    'type' => $type,
                    'title' => $title,
                    'sent_count' => $sent_count
                ]);
                $success_msg = "Đã gửi thành công thông báo tới $sent_count tài khoản người dùng!";
            } elseif (empty($error_msg)) {
                $error_msg = "Không có người dùng nào nhận được thông báo (có thể không có tài khoản phù hợp).";
            }
        }
    }
}

// LẤY DANH SÁCH THÔNG BÁO GẦN ĐÂY ĐỂ XEM LỊCH SỬ
$recent_notifs = [];
try {
    $q_rec = $conn->query("SELECT n.*, u.username, u.email 
        FROM notifications n 
        JOIN users u ON n.user_id = u.user_id 
        ORDER BY n.created_at DESC LIMIT 30");
    if ($q_rec && $q_rec->num_rows > 0) {
        while ($r = $q_rec->fetch_assoc()) {
            $recent_notifs[] = $r;
        }
    }
} catch (Throwable $e) {}

$page_title = "Trung Tâm Thông Báo Quản Trị";
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="container-fluid px-lg-5 py-4 text-white">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(245,158,11,0.2); color: #f59e0b; font-size: 0.75rem; border: 1px solid rgba(245,158,11,0.4);">
                    <i class="bi bi-broadcast me-1"></i> BROADCAST CENTER
                </span>
                <span class="text-secondary small">Gửi thông báo toàn sàn & khuyến mãi</span>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Hệ Thống Phát Thông Báo & Khuyến Mãi</h2>
        </div>
        <div class="d-flex gap-2">
            <a href="admin_support.php" class="btn btn-gaming-secondary d-flex align-items-center">
                <i class="bi bi-headset me-1 text-primary"></i> Quản Lý Hỗ Trợ
            </a>
            <a href="admin_dashboard.php" class="btn btn-outline-secondary d-flex align-items-center text-white">
                <i class="bi bi-speedometer2 me-1"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Alert -->
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
        <!-- CỘT TRÁI: FORM GỬI THÔNG BÁO -->
        <div class="col-lg-5">
            <div class="panel-dark p-4 rounded-4 shadow-sm">
                <h5 class="fw-bold text-white mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-send-check-fill me-2 text-warning"></i> Soạn Thông Báo Mới
                </h5>

                <form action="admin_notifications.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <!-- Đối tượng nhận -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Đối Tượng Nhận Thông Báo <span class="text-danger">*</span></label>
                        <select name="target" id="targetSelect" class="form-select form-control-gaming" onchange="toggleSpecificUser(this.value)" required>
                            <option value="all">○ Tất cả người dùng trên hệ thống</option>
                            <option value="customers">○ Toàn bộ khách hàng (Thành viên / VIP)</option>
                            <option value="admins">○ Chỉ Quản trị viên (Admin)</option>
                            <option value="specific">○ Khách hàng cụ thể (Nhập Username hoặc ID)</option>
                        </select>
                    </div>

                    <!-- Ô nhập user cụ thể nếu chọn specific -->
                    <div class="mb-3 d-none" id="specificUserContainer">
                        <label class="form-label text-secondary small fw-bold">Username Hoặc ID Khách Hàng</label>
                        <input type="text" name="specific_user" class="form-control form-control-gaming" placeholder="VD: anday123 hoặc 2">
                    </div>

                    <!-- Phân loại thông báo -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Phân Loại Thông Báo <span class="text-danger">*</span></label>
                        <select name="type" class="form-select form-control-gaming" required>
                            <option value="promotion">🎁 Khuyến mãi & Giảm giá</option>
                            <option value="game">🎮 Cập nhật kho game mới</option>
                            <option value="system">🛡️ Hệ thống & Bảo trì</option>
                            <option value="account">🔐 Tài khoản & Bảo mật</option>
                        </select>
                    </div>

                    <!-- Tiêu đề -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Tiêu Đề Thông Báo <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-gaming" placeholder="VD: 🎉 Siêu Flash Sale cuối tuần: Thuê Black Myth Wukong chỉ 10K!" required maxlength="255">
                    </div>

                    <!-- Nội dung -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Nội Dung Chi Tiết <span class="text-danger">*</span></label>
                        <textarea name="message" class="form-control form-control-gaming" rows="4" placeholder="Nhập chi tiết nội dung chương trình hoặc thông điệp gửi tới khách..." required></textarea>
                    </div>

                    <!-- Đường link điều hướng -->
                    <div class="mb-4">
                        <label class="form-label text-secondary small fw-bold">Liên Kết Điều Hướng (Deep Link - Tùy chọn)</label>
                        <input type="text" name="link" class="form-control form-control-gaming" placeholder="VD: index.php?search=Elden hoặc my_library.php">
                        <small class="text-secondary d-block mt-1">Khách nhấn vào thông báo sẽ được chuyển thẳng tới trang này.</small>
                    </div>

                    <button type="submit" name="btn_send_notification" class="btn btn-gaming-primary w-100 py-3 fw-bold">
                        <i class="bi bi-broadcast me-2"></i> Phát Thông Báo Ngay
                    </button>
                </form>
            </div>
        </div>

        <!-- CỘT PHẢI: LỊCH SỬ THÔNG BÁO VỪA PHÁT -->
        <div class="col-lg-7">
            <div class="panel-dark p-4 rounded-4 shadow-sm">
                <h5 class="fw-bold text-white mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-clock-history me-2 text-info"></i> Lịch Sử Thông Báo Gần Đây
                </h5>

                <?php if (empty($recent_notifs)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-bell-slash fs-2 d-block mb-2 opacity-50"></i>
                        Chưa có thông báo nào được lưu trong hệ thống.
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2" style="max-height: 600px; overflow-y: auto;">
                        <?php foreach ($recent_notifs as $rn): 
                            $ic = get_notification_icon_info($rn['type']);
                        ?>
                            <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06);">
                                <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge" style="background: <?php echo $ic['bg']; ?>; color: <?php echo $ic['color']; ?>; font-size: 0.68rem;">
                                            <?php echo $ic['label']; ?>
                                        </span>
                                        <strong class="text-white small"><?php echo htmlspecialchars($rn['title']); ?></strong>
                                    </div>
                                    <span class="text-secondary small" style="font-size: 0.72rem;"><?php echo format_time_ago($rn['created_at']); ?></span>
                                </div>
                                <p class="text-secondary small mb-1" style="font-size: 0.82rem; line-height: 1.4;">
                                    <?php echo htmlspecialchars($rn['message']); ?>
                                </p>
                                <div class="d-flex justify-content-between align-items-center text-secondary" style="font-size: 0.72rem;">
                                    <span>Gửi đến: <strong class="text-primary">@<?php echo htmlspecialchars($rn['username']); ?></strong></span>
                                    <?php if (!empty($rn['link'])): ?>
                                        <span class="text-info text-truncate" style="max-width: 200px;">Link: <?php echo htmlspecialchars($rn['link']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleSpecificUser(val) {
        const box = document.getElementById('specificUserContainer');
        if (box) {
            if (val === 'specific') {
                box.classList.remove('d-none');
            } else {
                box.classList.add('d-none');
            }
        }
    }
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>

