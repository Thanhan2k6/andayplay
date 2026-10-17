<?php
/**
 * ANPLAY STORE - TRUNG TÂM THÔNG BÁO (NOTIFICATION CENTER)
 * Quản lý tất cả thông báo hệ thống, đơn hàng, hỗ trợ, khuyến mãi
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
$tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'all';
$valid_tabs = ['all', 'unread', 'rental', 'payment', 'promotion', 'support', 'system'];
if (!in_array($tab, $valid_tabs)) {
    $tab = 'all';
}

// Tự động kiểm tra cảnh báo đơn thuê sắp hết hạn trong 24h
if (function_exists('check_and_notify_expiring_rentals')) {
    check_and_notify_expiring_rentals($conn, $user_id);
}

// Xử lý action click / đánh dấu đọc / xóa
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'read_and_go' && isset($_GET['id'])) {
        $notif_id = intval($_GET['id']);
        mark_notification_read($conn, $notif_id, $user_id);
        $redirect = isset($_GET['redirect']) ? trim($_GET['redirect']) : 'notifications.php';
        // Validate local redirect
        if (strpos($redirect, 'http://') === 0 || strpos($redirect, 'https://') === 0) {
            header("Location: " . $redirect);
        } else {
            header("Location: " . ltrim($redirect, '/'));
        }
        exit();
    } elseif ($_GET['action'] === 'mark_all') {
        mark_all_notifications_read($conn, $user_id);
        header("Location: notifications.php?tab=" . urlencode($tab));
        exit();
    } elseif ($_GET['action'] === 'mark_single' && isset($_GET['id'])) {
        mark_notification_read($conn, intval($_GET['id']), $user_id);
        header("Location: notifications.php?tab=" . urlencode($tab));
        exit();
    } elseif ($_GET['action'] === 'delete' && isset($_GET['id'])) {
        delete_notification($conn, intval($_GET['id']), $user_id);
        header("Location: notifications.php?tab=" . urlencode($tab));
        exit();
    } elseif ($_GET['action'] === 'clear_read') {
        delete_user_notifications($conn, $user_id, true);
        header("Location: notifications.php?tab=" . urlencode($tab));
        exit();
    }
}

// Lấy số lượng thông báo chưa đọc
$total_unread = get_unread_notification_count($conn, $user_id);

// Phân trang
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

$unread_only = ($tab === 'unread');
$filter_type = ($tab === 'unread') ? 'all' : $tab;

// Đếm tổng số để phân trang
$count_conditions = ["user_id = ?"];
$count_types = "i";
$count_params = [$user_id];

if ($unread_only) {
    $count_conditions[] = "is_read = 0";
}
if ($filter_type !== 'all') {
    if ($filter_type === 'rental') {
        $count_conditions[] = "type IN ('rental', 'order')";
    } elseif ($filter_type === 'support') {
        $count_conditions[] = "type IN ('support', 'complaint')";
    } elseif ($filter_type === 'system') {
        $count_conditions[] = "type IN ('system', 'security', 'account')";
    } else {
        $count_conditions[] = "type = ?";
        $count_types .= "s";
        $count_params[] = $filter_type;
    }
}
$where_count_sql = implode(" AND ", $count_conditions);
$total_rows = 0;
try {
    $count_stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE $where_count_sql");
    if ($count_stmt) {
        $count_stmt->bind_param($count_types, ...$count_params);
        $count_stmt->execute();
        $row_c = $count_stmt->get_result()->fetch_assoc();
        $total_rows = intval($row_c['c'] ?? 0);
        $count_stmt->close();
    }
} catch (Throwable $e) {}

$total_pages = max(1, ceil($total_rows / $per_page));

// Lấy danh sách thông báo
$notifications = get_user_notifications($conn, $user_id, $filter_type, $unread_only, $per_page, $offset);

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 py-4 text-white">
    <!-- Header title -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); font-size: 0.75rem; border: 1px solid rgba(99,102,241,0.4);">
                    <i class="bi bi-bell-fill me-1"></i> NOTIFICATION CENTER
                </span>
                <?php if ($total_unread > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?php echo $total_unread; ?> chưa đọc</span>
                <?php endif; ?>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Hộp Thư & Trung Tâm Thông Báo</h2>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($total_unread > 0): ?>
                <a href="notifications.php?action=mark_all&tab=<?php echo urlencode($tab); ?>" class="btn btn-gaming-secondary btn-sm d-flex align-items-center">
                    <i class="bi bi-check2-all me-1 text-success"></i> Đánh dấu tất cả đã đọc
                </a>
            <?php endif; ?>
            <a href="notifications.php?action=clear_read&tab=<?php echo urlencode($tab); ?>" class="btn btn-outline-danger btn-sm d-flex align-items-center" onclick="return confirm('Bạn có chắc muốn dọn dẹp tất cả thông báo đã đọc?');">
                <i class="bi bi-trash3 me-1"></i> Dọn dẹp đã đọc
            </a>
            <a href="support.php" class="btn btn-gaming-primary btn-sm d-flex align-items-center">
                <i class="bi bi-headset me-1"></i> Trung tâm hỗ trợ
            </a>
        </div>
    </div>

    <!-- Category Tabs Filter -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <a href="notifications.php?tab=all" class="btn btn-sm <?php echo ($tab === 'all') ? 'btn-primary fw-bold' : 'btn-outline-secondary text-white'; ?> rounded-pill px-3 py-2">
            <i class="bi bi-grid-fill me-1"></i> Tất cả
        </a>
        <a href="notifications.php?tab=unread" class="btn btn-sm <?php echo ($tab === 'unread') ? 'btn-primary fw-bold' : 'btn-outline-secondary text-white'; ?> rounded-pill px-3 py-2 position-relative">
            <i class="bi bi-envelope-fill me-1"></i> Chưa đọc
            <?php if ($total_unread > 0): ?>
                <span class="badge bg-danger rounded-pill ms-1"><?php echo $total_unread; ?></span>
            <?php endif; ?>
        </a>
        <a href="notifications.php?tab=rental" class="btn btn-sm <?php echo ($tab === 'rental') ? 'btn-primary fw-bold' : 'btn-outline-secondary text-white'; ?> rounded-pill px-3 py-2">
            <i class="bi bi-controller me-1"></i> Đơn thuê
        </a>
        <a href="notifications.php?tab=payment" class="btn btn-sm <?php echo ($tab === 'payment') ? 'btn-primary fw-bold' : 'btn-outline-secondary text-white'; ?> rounded-pill px-3 py-2">
            <i class="bi bi-credit-card-2-front me-1"></i> Thanh toán
        </a>
        <a href="notifications.php?tab=support" class="btn btn-sm <?php echo ($tab === 'support') ? 'btn-primary fw-bold' : 'btn-outline-secondary text-white'; ?> rounded-pill px-3 py-2">
            <i class="bi bi-headset me-1"></i> Hỗ trợ
        </a>
        <a href="notifications.php?tab=promotion" class="btn btn-sm <?php echo ($tab === 'promotion') ? 'btn-primary fw-bold' : 'btn-outline-secondary text-white'; ?> rounded-pill px-3 py-2">
            <i class="bi bi-gift-fill me-1"></i> Khuyến mãi
        </a>
        <a href="notifications.php?tab=system" class="btn btn-sm <?php echo ($tab === 'system') ? 'btn-primary fw-bold' : 'btn-outline-secondary text-white'; ?> rounded-pill px-3 py-2">
            <i class="bi bi-shield-lock-fill me-1"></i> Hệ thống & Bảo mật
        </a>
    </div>

    <!-- Notifications List -->
    <div class="row">
        <div class="col-lg-12">
            <?php if (empty($notifications)): ?>
                <div class="panel-dark text-center py-5 my-3 rounded-4" style="border: 1px dashed rgba(255,255,255,0.15);">
                    <div class="p-4 d-inline-block rounded-circle mb-3" style="background: rgba(99,102,241,0.08);">
                        <i class="bi bi-bell-slash fs-1 text-secondary"></i>
                    </div>
                    <h5 class="fw-bold text-white mb-2">Không có thông báo nào</h5>
                    <p class="text-secondary small mb-3">Hiện tại chưa có thông tin hoặc cập nhật mới trong mục này.</p>
                    <a href="index.php" class="btn btn-gaming-secondary btn-sm px-4">Khám phá cửa hàng</a>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($notifications as $n): 
                        $icon_info = $n['icon_info'];
                        $is_unread = ($n['is_read'] == 0);
                        $click_url = !empty($n['link']) ? "notifications.php?action=read_and_go&id={$n['id']}&redirect=" . urlencode($n['link']) : "notifications.php?action=mark_single&id={$n['id']}&tab=" . urlencode($tab);
                    ?>
                        <div class="panel-dark p-3 p-md-4 rounded-4 position-relative transition-all" style="background: <?php echo $is_unread ? 'rgba(99,102,241,0.06)' : 'rgba(255,255,255,0.02)'; ?>; border: 1px solid <?php echo $is_unread ? 'rgba(99,102,241,0.3)' : 'rgba(255,255,255,0.07)'; ?>;">
                            <div class="d-flex align-items-start gap-3">
                                <!-- Icon Box -->
                                <div class="rounded-3 p-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: <?php echo $icon_info['bg']; ?>; color: <?php echo $icon_info['color']; ?>;">
                                    <i class="bi <?php echo $icon_info['icon']; ?> fs-4"></i>
                                </div>

                                <!-- Content -->
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge" style="background: <?php echo $icon_info['bg']; ?>; color: <?php echo $icon_info['color']; ?>; font-size: 0.7rem; border: 1px solid <?php echo $icon_info['color']; ?>44;">
                                                <?php echo $icon_info['label']; ?>
                                            </span>
                                            <h6 class="fw-bold mb-0 text-white <?php echo $is_unread ? 'text-primary' : ''; ?>">
                                                <?php echo htmlspecialchars($n['title']); ?>
                                            </h6>
                                            <?php if ($is_unread): ?>
                                                <span class="p-1 bg-primary rounded-circle d-inline-block" title="Chưa đọc" style="width: 8px; height: 8px;"></span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-secondary small">
                                            <i class="bi bi-clock me-1"></i><?php echo $n['time_ago']; ?>
                                        </span>
                                    </div>

                                    <p class="text-secondary mb-2" style="font-size: 0.88rem; line-height: 1.5; white-space: pre-line;">
                                        <?php echo htmlspecialchars($n['message']); ?>
                                    </p>

                                    <!-- Action Links -->
                                    <div class="d-flex flex-wrap align-items-center gap-2 pt-1">
                                        <?php if (!empty($n['link'])): ?>
                                            <a href="<?php echo $click_url; ?>" class="btn btn-gaming-primary btn-sm py-1 px-3" style="font-size: 0.8rem;">
                                                Xem chi tiết <i class="bi bi-arrow-right ms-1"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($is_unread): ?>
                                            <a href="notifications.php?action=mark_single&id=<?php echo $n['id']; ?>&tab=<?php echo urlencode($tab); ?>" class="btn btn-outline-secondary btn-sm py-1 px-2 text-secondary" style="font-size: 0.78rem;" title="Đánh dấu đã đọc">
                                                <i class="bi bi-check2"></i> Đã đọc
                                            </a>
                                        <?php endif; ?>

                                        <a href="notifications.php?action=delete&id=<?php echo $n['id']; ?>&tab=<?php echo urlencode($tab); ?>" class="btn btn-outline-danger btn-sm py-1 px-2 text-danger ms-auto" style="font-size: 0.78rem;" title="Xóa thông báo này" onclick="return confirm('Bạn có chắc muốn xóa thông báo này?');">
                                            <i class="bi bi-trash3"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Phân trang -->
                <?php if ($total_pages > 1): ?>
                    <nav class="mt-4 d-flex justify-content-center">
                        <ul class="pagination pagination-sm gap-1">
                            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                                <li class="page-item <?php echo ($p === $page) ? 'active' : ''; ?>">
                                    <a class="page-link rounded-2" href="notifications.php?tab=<?php echo urlencode($tab); ?>&page=<?php echo $p; ?>">
                                        <?php echo $p; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

