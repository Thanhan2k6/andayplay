<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/includes/connect.php';
$user_id = intval($_SESSION['user_id']);
$current_page = 'my_library.php';

// Xử lý báo lỗi tài khoản & Tạo Ticket hỗ trợ
$report_message = "";
$report_error = "";
$report_ticket_id = 0;

if (isset($_POST['btn_report_error'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $report_error = "Mã xác thực bảo mật không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng thử lại.";
    } else {
        $rep_borrow_id = intval($_POST['report_borrow_id'] ?? 0);
        $rep_issue = trim($_POST['issue_description'] ?? '');
        if (empty($rep_issue)) {
            $rep_issue = 'Khách hàng báo lỗi đăng nhập / sự cố tài khoản game.';
        }

        // Lấy thông tin đơn thuê và game của khách
        $stmt_chk = $conn->prepare("SELECT br.borrow_id, br.account_id, br.game_id, br.status as borrow_status, 
                                            g.title as game_title, ga.account_username 
                                     FROM borrow_records br 
                                     JOIN games g ON br.game_id = g.game_id 
                                     LEFT JOIN game_accounts ga ON br.account_id = ga.account_id 
                                     WHERE br.borrow_id = ? AND br.user_id = ? LIMIT 1");
        if ($stmt_chk) {
            $stmt_chk->bind_param("ii", $rep_borrow_id, $user_id);
            $stmt_chk->execute();
            $borrow_info = $stmt_chk->get_result()->fetch_assoc();
            $stmt_chk->close();
        } else {
            $borrow_info = null;
        }

        if (!$borrow_info) {
            $report_error = "Không tìm thấy đơn thuê hợp lệ để gửi yêu cầu hỗ trợ.";
        } else {
            $game_title = $borrow_info['game_title'];
            $game_id = intval($borrow_info['game_id']);
            $rep_acc_id = !empty($borrow_info['account_id']) ? intval($borrow_info['account_id']) : null;
            $acc_user = !empty($borrow_info['account_username']) ? $borrow_info['account_username'] : 'Chưa cấp';

            // Lấy username người gửi
            $customer_name = $_SESSION['username'] ?? '';
            if (empty($customer_name)) {
                $u_row = $conn->query("SELECT username FROM users WHERE user_id = $user_id LIMIT 1")->fetch_assoc();
                $customer_name = $u_row['username'] ?? 'User#' . $user_id;
            }

            // 1. Chuyển tài khoản sang trạng thái MAINTENANCE nếu có tài khoản
            if ($rep_acc_id) {
                $esc_issue = $conn->real_escape_string($rep_issue);
                $conn->query("UPDATE game_accounts SET status = 'MAINTENANCE', notes = CONCAT(COALESCE(notes, ''), ' | Khách @$customer_name báo lỗi: $esc_issue') WHERE account_id = $rep_acc_id");
                if (function_exists('log_account_allocation')) {
                    log_account_allocation($conn, $rep_acc_id, $rep_borrow_id, $user_id, 'MAINTENANCE_TRIGGERED', "Báo lỗi từ khách: $rep_issue", 'USER');
                }
            }

            // 2. Tạo Support Ticket chính thức và gửi Notification cho Admin + Super Admin
            $ticket_title = "Báo lỗi tài khoản: {$game_title} (Đơn #{$rep_borrow_id})";
            $ticket_body = "Khách hàng @{$customer_name} báo sự cố tài khoản đơn thuê #{$rep_borrow_id} (Game: {$game_title}, Tài khoản: {$acc_user}):\n" . $rep_issue;
            
            $ticket_res = create_support_ticket(
                $conn,
                $user_id,
                'account',
                $ticket_title,
                $ticket_body,
                'high',
                $rep_borrow_id,
                $game_id
            );

            if ($ticket_res['success']) {
                $report_ticket_id = intval($ticket_res['ticket_id']);
                $ticket_code = $ticket_res['ticket_code'];

                $report_message = "Đã gửi báo lỗi thành công! Đội ngũ hỗ trợ kỹ thuật 24/7 sẽ kiểm tra và cấp đổi tài khoản mới cho bạn sớm nhất.";
            } else {
                $report_error = "Lỗi tạo yêu cầu hỗ trợ: " . ($ticket_res['error'] ?? 'Không rõ nguyên nhân');
            }
        }
    }
}

// LẤY DANH SÁCH CÁC ĐƠN THUÊ CỦA USER KÈM THÔNG TIN TÀI KHOẢN GAME
$result = false;
try {
    $sql = "SELECT br.borrow_id, br.borrow_date, br.due_date, br.status, br.rent_type, br.duration_hours,
                   g.game_id, g.title, g.image_url, g.platform,
                   ga.account_id, ga.account_username, ga.account_password_encrypted, ga.status as account_status, ga.server_region
            FROM borrow_records br 
            JOIN games g ON br.game_id = g.game_id 
            LEFT JOIN game_accounts ga ON br.account_id = ga.account_id
            WHERE br.user_id = $user_id
            ORDER BY br.borrow_id DESC";
    $result = $conn->query($sql);
} catch (Throwable $e) {
    $result = false;
}

if (!$result) {
    try {
        $sql_fallback = "SELECT br.borrow_id, br.borrow_date, br.due_date, br.status, 'new' as rent_type, 168 as duration_hours,
                               g.game_id, g.title, g.image_url, g.platform,
                               NULL as account_id, '' as account_username, '' as account_password_encrypted, 'AVAILABLE' as account_status, 'Global' as server_region
                        FROM borrow_records br 
                        JOIN games g ON br.game_id = g.game_id 
                        WHERE br.user_id = $user_id
                        ORDER BY br.borrow_id DESC";
        $result = $conn->query($sql_fallback);
    } catch (Throwable $e) {
        $result = false;
    }
}
$csrf_token = generate_csrf_token();

// Tính sơ bộ thống kê của khách
$total_orders = 0;
$active_orders = 0;
$expired_orders = 0;
$rows_data = [];

if ($result && $result->num_rows > 0) {
    while ($r = $result->fetch_assoc()) {
        $due_t = strtotime($r['due_date']);
        $is_over = ($r['status'] === 'expired' || ($r['status'] === 'active' && $due_t < time()));
        $is_act = ($r['status'] === 'active' && !$is_over);
        if ($is_act) {
            $active_orders++;
        } else {
            $expired_orders++;
        }
        $total_orders++;
        $rows_data[] = $r;
    }
}
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4 my-2">
    <!-- Breadcrumb & Top Bar -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-secondary"><i class="bi bi-house me-1"></i>Trang chủ</a></li>
            <li class="breadcrumb-item active text-white" aria-current="page">Kho Game & Tài Khoản Thuê</li>
        </ol>
    </nav>

    <!-- Header Section -->
    <div class="row align-items-center mb-4 g-3">
        <div class="col-lg-7">
            <div class="d-flex align-items-center gap-3">
                <div class="p-3 rounded-4 d-inline-flex align-items-center justify-content-center" style="background: rgba(99,102,241,0.15); border: 1px solid rgba(99,102,241,0.3);">
                    <i class="bi bi-controller fs-2" style="color: var(--primary);"></i>
                </div>
                <div>
                    <h2 class="fw-bold text-white mb-1">Kho Game & Tài Khoản Thuê</h2>
                    <p class="text-secondary small mb-0">Quản lý các tài khoản game đang thuê, đồng hồ đếm ngược, lấy mật khẩu và gia hạn nhanh.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-5 text-lg-end">
            <div class="d-inline-flex gap-2 align-items-center p-2 px-3 rounded-pill" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['username']); ?>&background=6366f1&color=fff&rounded=true" width="28" height="28" class="rounded-circle">
                <span class="text-light small fw-bold">@<?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); font-size: 0.72rem;">VIP MEMBER</span>
            </div>
        </div>
    </div>

    <!-- KPI Metric Quick Summary -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="p-3 rounded-4 h-100 d-flex align-items-center justify-content-between" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div>
                    <span class="text-secondary small text-uppercase fw-bold">Tổng lượt thuê</span>
                    <h3 class="fw-bold text-white mb-0 mt-1"><?php echo $total_orders; ?></h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.05); color: #cbd5e1;">
                    <i class="bi bi-collection-play fs-4"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 rounded-4 h-100 d-flex align-items-center justify-content-between" style="background: var(--bg-card); border: 1px solid rgba(16,185,129,0.3);">
                <div>
                    <span class="text-secondary small text-uppercase fw-bold">Đang hiệu lực</span>
                    <h3 class="fw-bold text-success mb-0 mt-1"><?php echo $active_orders; ?></h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(16,185,129,0.15); color: #10b981;">
                    <i class="bi bi-shield-check fs-4"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 rounded-4 h-100 d-flex align-items-center justify-content-between" style="background: var(--bg-card); border: 1px solid rgba(239,68,68,0.25);">
                <div>
                    <span class="text-secondary small text-uppercase fw-bold">Đã hoàn tất / Hết hạn</span>
                    <h3 class="fw-bold text-secondary mb-0 mt-1"><?php echo $expired_orders; ?></h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(239,68,68,0.12); color: #ef4444;">
                    <i class="bi bi-hourglass-bottom fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <?php if ($report_message): ?>
        <div class="alert alert-dismissible fade show mb-4 text-white" style="background: rgba(16,185,129,0.15); border: 1px solid #10b981;" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill text-success fs-5 me-2"></i>
                <div>
                    <div><?php echo $report_message; ?></div>
                    <?php if (!empty($report_ticket_id)): ?>
                        <div class="mt-2 small">
                            <a href="support_detail.php?id=<?php echo $report_ticket_id; ?>" class="btn btn-sm btn-outline-info text-white fw-bold py-1 px-3">
                                <i class="bi bi-headset me-1"></i> Theo dõi xử lý tại Yêu cầu #<?php echo $report_ticket_id; ?> &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($report_error): ?>
        <div class="alert alert-dismissible fade show mb-4 text-white" style="background: rgba(239,68,68,0.15); border: 1px solid #ef4444;" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill text-danger fs-5 me-2"></i>
                <div><?php echo htmlspecialchars($report_error); ?></div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Main Grid of Rented Games -->
    <div class="row g-4">
        <?php 
        if (count($rows_data) > 0): 
            foreach ($rows_data as $row): 
                $due_timestamp = strtotime($row['due_date']);
                $is_overdue = ($row['status'] === 'expired' || ($row['status'] === 'active' && $due_timestamp < time()));
                $is_active = ($row['status'] === 'active' && !$is_overdue);
                
                $countdown_id = "timer_" . $row['borrow_id'];
                
                // Giải mã mật khẩu game an toàn (Chỉ khi active)
                $plain_password = "";
                if ($is_active && !empty($row['account_password_encrypted'])) {
                    $plain_password = decrypt_game_password($row['account_password_encrypted']);
                }
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="gaming-card h-100 d-flex flex-column">
                <!-- Cover Image & Overlays -->
                <div class="gaming-card-img-wrapper" style="aspect-ratio: 16/9; position: relative;">
                    <?php if (!empty($row['image_url']) && file_exists($row['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($row['image_url']); ?>" class="gaming-card-img" alt="<?php echo htmlspecialchars($row['title']); ?>">
                    <?php else: ?>
                        <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center bg-dark text-secondary">
                            <i class="bi bi-image fs-1 mb-2"></i>
                            <span class="small fw-bold">ANPLAY GAME</span>
                        </div>
                    <?php endif; ?>

                    <!-- Platform Pill -->
                    <span class="platform-pill position-absolute top-0 start-0 m-3">
                        <i class="bi bi-display me-1"></i><?php echo htmlspecialchars($row['platform'] ?? 'PC'); ?>
                    </span>

                    <!-- Status Pill -->
                    <div class="position-absolute top-0 end-0 m-3">
                        <?php if ($is_active): ?>
                            <span class="status-pill status-pill-success">
                                <span class="status-dot"></span> ĐANG KÍCH HOẠT
                            </span>
                        <?php elseif ($is_overdue): ?>
                            <span class="status-pill status-pill-danger">
                                <span class="status-dot"></span> ĐÃ HẾT HẠN
                            </span>
                        <?php else: ?>
                            <span class="status-pill status-pill-warning">
                                <span class="status-dot"></span> <?php echo strtoupper(htmlspecialchars($row['status'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Card Body -->
                <div class="card-body p-4 d-flex flex-column flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h4 class="fw-bold text-white mb-0 text-truncate" title="<?php echo htmlspecialchars($row['title']); ?>">
                            <?php echo htmlspecialchars($row['title']); ?>
                        </h4>
                    </div>

                    <div class="d-flex justify-content-between text-secondary small mb-3">
                        <span><i class="bi bi-calendar-event me-1"></i> Thuê: <span class="text-light"><?php echo date('d/m/Y H:i', strtotime($row['borrow_date'])); ?></span></span>
                        <span>Hạn: <strong class="text-warning"><?php echo date('d/m/Y H:i', $due_timestamp); ?></strong></span>
                    </div>

                    <!-- THÔNG TIN TÀI KHOẢN (CREDENTIALS BOX) -->
                    <div class="p-3 rounded-3 mb-3" style="background: rgba(15, 17, 24, 0.8); border: 1px solid var(--border-color);">
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom border-secondary border-opacity-25">
                            <span class="small fw-bold text-info"><i class="bi bi-shield-lock-fill me-1"></i> THÔNG TIN ĐĂNG NHẬP</span>
                            <span class="badge" style="background: rgba(255,255,255,0.06); color: #94a3b8; font-size: 0.7rem; border: 1px solid var(--border-color);">
                                <?php echo htmlspecialchars($row['server_region'] ?? 'Global'); ?>
                            </span>
                        </div>

                        <?php if ($is_active && !empty($row['account_username'])): ?>
                            <!-- Username -->
                            <div class="mb-2">
                                <label class="small text-secondary d-block mb-1" style="font-size: 0.75rem;">Tài khoản / Email:</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm form-control-gaming text-white fw-bold user-select-all" value="<?php echo htmlspecialchars($row['account_username']); ?>" readonly id="user_<?php echo $row['borrow_id']; ?>">
                                    <button class="btn btn-sm btn-gaming-secondary copy-btn" type="button" data-clipboard-target="#user_<?php echo $row['borrow_id']; ?>" onclick="copyText('user_<?php echo $row['borrow_id']; ?>', this)">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Password with Show/Hide -->
                            <div>
                                <label class="small text-secondary d-block mb-1" style="font-size: 0.75rem;">Mật khẩu game:</label>
                                <div class="input-group input-group-sm">
                                    <input type="password" class="form-control form-control-sm form-control-gaming text-warning fw-bold user-select-all" value="<?php echo htmlspecialchars($plain_password); ?>" readonly id="pass_<?php echo $row['borrow_id']; ?>">
                                    <button class="btn btn-sm btn-gaming-secondary" type="button" onclick="togglePass('pass_<?php echo $row['borrow_id']; ?>', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-gaming-secondary copy-btn" type="button" onclick="copyText('pass_<?php echo $row['borrow_id']; ?>', this)">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-2">
                                <i class="bi bi-lock-fill text-danger d-block mb-1 fs-5"></i>
                                <span class="text-danger small fw-semibold">
                                    Thông tin tài khoản đã khóa do hết hạn thuê hoặc chưa thanh toán.
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- COUNTDOWN TIMER BOX -->
                    <div class="p-3 rounded-3 mb-3 text-center" style="background: rgba(99, 102, 241, 0.08); border: 1px dashed rgba(99, 102, 241, 0.3);">
                        <?php if ($is_active): ?>
                            <span class="text-secondary small d-block mb-1" style="font-size: 0.75rem;">THỜI GIAN THUÊ CÒN LẠI</span>
                            <div class="fw-bold fs-5 text-warning font-monospace" id="<?php echo $countdown_id; ?>">
                                <span class="spinner-border spinner-border-sm me-1" role="status"></span> Đang đồng bộ...
                            </div>
                            
                            <script>
                                (function() {
                                    var countDownDate_<?php echo $row['borrow_id']; ?> = new Date("<?php echo date('M d, Y H:i:s', $due_timestamp); ?>").getTime();
                                    var x_<?php echo $row['borrow_id']; ?> = setInterval(function() {
                                        var now = new Date().getTime();
                                        var distance = countDownDate_<?php echo $row['borrow_id']; ?> - now;
                                        var el = document.getElementById("<?php echo $countdown_id; ?>");
                                        
                                        if (distance < 0) {
                                            clearInterval(x_<?php echo $row['borrow_id']; ?>);
                                            if (el) {
                                                el.innerHTML = "<span class='text-danger'>ĐÃ HẾT HẠN</span>";
                                            }
                                        } else {
                                            var days = Math.floor(distance / (1000 * 60 * 60 * 24));
                                            var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                                            var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                                            var seconds = Math.floor((distance % (1000 * 60)) / 1000);
                                            
                                            var timeStr = "";
                                            if (days > 0) timeStr += days + "d ";
                                            timeStr += (hours < 10 ? "0" + hours : hours) + ":" + 
                                                       (minutes < 10 ? "0" + minutes : minutes) + ":" + 
                                                       (seconds < 10 ? "0" + seconds : seconds);
                                            
                                            if (el) { el.innerHTML = timeStr; }
                                        }
                                    }, 1000);
                                })();
                            </script>
                        <?php else: ?>
                            <span class="text-danger fw-bold d-block">HẾT HẠN SỬ DỤNG</span>
                            <small class="text-secondary" style="font-size: 0.75rem;">Quyền đăng nhập đã được thu hồi an toàn.</small>
                        <?php endif; ?>
                    </div>

                    <!-- ACTION FOOTER: EXTEND / REPORT / RE-RENT -->
                    <div class="mt-auto">
                        <!-- FORM GIA HẠN THỜI GIAN THUÊ (HỖ TRỢ CẢ ĐƠN ĐANG CHẠY LẪN ĐƠN ĐÃ HẾT HẠN) -->
                        <form method="POST" action="checkout_qr.php" class="p-2 rounded-3 mb-2" style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color);">
                            <input type="hidden" name="borrow_id_to_extend" value="<?php echo $row['borrow_id']; ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <small class="text-light fw-bold" style="font-size: 0.75rem;">
                                    <i class="bi bi-arrow-repeat text-warning me-1"></i> <?php echo $is_active ? 'Gia hạn thêm giờ chơi:' : '⚡ Kích hoạt lại / Gia hạn tiếp:'; ?>
                                </small>
                            </div>
                            <div class="input-group input-group-sm">
                                <select name="extend_pkg_id" class="form-select form-select-sm form-control-gaming text-white">
                                    <?php
                                    $pkgs_cur = false;
                                    try {
                                        $pkgs_cur = $conn->query("SELECT * FROM rental_packages WHERE game_id = {$row['game_id']} AND is_active = 1 ORDER BY price ASC");
                                    } catch (Throwable $e) {
                                        $pkgs_cur = false;
                                    }
                                    if ($pkgs_cur && $pkgs_cur->num_rows > 0):
                                        while ($pkg_item = $pkgs_cur->fetch_assoc()):
                                    ?>
                                        <option value="<?php echo $pkg_item['package_id']; ?>">
                                            +<?php echo htmlspecialchars($pkg_item['package_name']); ?> (<?php echo number_format($pkg_item['price'], 0, ',', '.'); ?>đ)
                                        </option>
                                    <?php 
                                        endwhile; 
                                    else:
                                    ?>
                                        <option value="0">+Gói 24h tiêu chuẩn (15.000đ)</option>
                                    <?php endif; ?>
                                </select>
                                <button type="submit" name="btn_extend_submit" class="btn btn-sm btn-gaming-primary">
                                    <?php echo $is_active ? 'Gia Hạn' : 'Kích Hoạt'; ?>
                                </button>
                            </div>
                        </form>

                        <?php if ($is_active): ?>
                            <!-- Nút Chơi ngay & Báo lỗi tài khoản -->
                            <div class="d-flex gap-2">
                                <a href="download_game.php?id=<?php echo $row['game_id']; ?>" class="btn btn-sm btn-gaming-primary flex-grow-1 text-center text-decoration-none fw-bold">
                                    <i class="bi bi-controller me-1"></i> Chơi Ngay / Bộ Cài
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reportModal_<?php echo $row['borrow_id']; ?>" title="Báo lỗi đổi tài khoản">
                                    <i class="bi bi-flag-fill"></i> Báo lỗi
                                </button>
                            </div>
                        <?php else: ?>
                            <!-- KHI ĐÃ HẾT HẠN: LỰA CHỌN THUÊ LẠI HOẶC THUÊ MỚI -->
                            <div class="d-grid gap-2">
                                <?php if ($row['account_id']): ?>
                                    <a href="borrow_process.php?game_id=<?php echo $row['game_id']; ?>&type=re-rent&re_rent_acc=<?php echo $row['account_id']; ?>&old_borrow_id=<?php echo $row['borrow_id']; ?>" 
                                       class="btn btn-sm btn-gaming-secondary text-white text-decoration-none py-1">
                                        <i class="bi bi-arrow-repeat me-1"></i> Thuê lại tài khoản cũ (Giữ Save)
                                    </a>
                                <?php endif; ?>

                                <a href="borrow_process.php?game_id=<?php echo $row['game_id']; ?>&type=new" 
                                   class="btn btn-sm btn-outline-info text-decoration-none py-1">
                                    <i class="bi bi-plus-circle me-1"></i> Thuê tài khoản mới khác
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL BÁO LỖI TÀI KHOẢN -->
        <?php if ($is_active): ?>
        <div class="modal fade" id="reportModal_<?php echo $row['borrow_id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content modal-gaming shadow-lg">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="report_borrow_id" value="<?php echo $row['borrow_id']; ?>">
                        
                        <div class="modal-header">
                            <h5 class="modal-title fw-bold text-warning d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> Báo Lỗi Tài Khoản Game
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-secondary small mb-3">
                                Bạn đang báo lỗi cho game <strong class="text-white"><?php echo htmlspecialchars($row['title']); ?></strong>. 
                                Đội ngũ kỹ thuật ANPLAY STORE cam kết hỗ trợ đổi tài khoản ngay nếu phát hiện lỗi sai mật khẩu, bị khóa Steam Guard hoặc tài khoản bị ban.
                            </p>
                            
                            <label class="form-label text-light small fw-bold">Chi tiết sự cố gặp phải:</label>
                            <textarea name="issue_description" class="form-control form-control-gaming" rows="4" placeholder="Ví dụ: Báo sai mật khẩu, Steam yêu cầu mã OTP, tài khoản đang có người khác đăng nhập..." required></textarea>
                            
                            <div class="mt-3 p-2 rounded" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2);">
                                <small class="text-danger d-block">
                                    <i class="bi bi-info-circle me-1"></i> Lưu ý: Khi báo lỗi, tài khoản sẽ được chuyển sang chế độ bảo trì để kỹ thuật viên kiểm tra.
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer border-secondary border-opacity-25">
                            <button type="button" class="btn btn-gaming-secondary" data-bs-dismiss="modal">Đóng</button>
                            <button type="submit" name="btn_report_error" class="btn btn-danger fw-bold">
                                <i class="bi bi-send-fill me-1"></i> Gửi Yêu Cầu Hỗ Trợ
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endforeach; else: ?>
        <!-- EMPTY STATE -->
        <div class="col-12 py-5 text-center">
            <div class="p-5 rounded-4 mx-auto" style="max-width: 600px; background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="mb-3 d-inline-flex p-4 rounded-circle" style="background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.25);">
                    <i class="bi bi-controller fs-1" style="color: var(--primary);"></i>
                </div>
                <h3 class="fw-bold text-white mb-2">Thư Viện Trống Trải!</h3>
                <p class="text-secondary mb-4">Bạn chưa thuê bất kỳ tài khoản game nào. Hãy khám phá ngay kho game AAA bản quyền của ANPLAY với giá chỉ từ 5.000đ.</p>
                <a href="index.php" class="btn btn-gaming-primary px-4 py-2 text-decoration-none">
                    <i class="bi bi-compass me-1"></i> Khám Phá Cửa Hàng Game
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyText(inputId, btn) {
    var copyText = document.getElementById(inputId);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(function() {
        var originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 text-success"></i>';
        setTimeout(function() {
            btn.innerHTML = originalHtml;
        }, 1500);
    });
}

function togglePass(inputId, btn) {
    var input = document.getElementById(inputId);
    var icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>

<?php include 'includes/footer.php'; ?>