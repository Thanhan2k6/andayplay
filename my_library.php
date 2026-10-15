<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('Vui lòng đăng nhập để xem Game của bạn!'); window.location='login.php';</script>";
    exit();
}

$user_id = $_SESSION['user_id'];

// Xử lý báo lỗi tài khoản
$report_message = "";
if (isset($_POST['btn_report_error'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $rep_borrow_id = intval($_POST['report_borrow_id']);
        $rep_issue = $conn->real_escape_string($_POST['issue_description'] ?? 'Khách báo lỗi đăng nhập');
        
        $chk_acc = $conn->query("SELECT account_id FROM borrow_records WHERE borrow_id = $rep_borrow_id AND user_id = $user_id")->fetch_assoc();
        if ($chk_acc && $chk_acc['account_id']) {
            $rep_acc_id = intval($chk_acc['account_id']);
            // Chuyển tài khoản sang trạng thái MAINTENANCE để admin kiểm tra
            $conn->query("UPDATE game_accounts SET status = 'MAINTENANCE', notes = CONCAT(COALESCE(notes, ''), ' | Khách báo lỗi: $rep_issue') WHERE account_id = $rep_acc_id");
            log_account_allocation($conn, $rep_acc_id, $rep_borrow_id, $user_id, 'MAINTENANCE_TRIGGERED', "Báo lỗi từ khách: $rep_issue", 'USER');
            $report_message = "Đã gửi báo lỗi thành công! Đội ngũ kỹ thuật sẽ kiểm tra và hỗ trợ bạn sớm nhất.";
        }
    }
}

// LẤY DANH SÁCH CÁC ĐƠN THUÊ CỦA USER KÈM THÔNG TIN TÀI KHOẢN GAME
$sql = "SELECT br.borrow_id, br.borrow_date, br.due_date, br.status, br.rent_type, br.duration_hours,
               g.game_id, g.title, g.image_url, g.platform,
               ga.account_id, ga.account_username, ga.account_password_encrypted, ga.status as account_status, ga.server_region
        FROM borrow_records br 
        JOIN games g ON br.game_id = g.game_id 
        LEFT JOIN game_accounts ga ON br.account_id = ga.account_id
        WHERE br.user_id = $user_id
        ORDER BY br.borrow_id DESC";
$result = $conn->query($sql);
$csrf_token = generate_csrf_token();
?>

<div class="container mt-5 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom border-secondary">
        <div>
            <h2 class="text-white fw-bold mb-1"><i class="bi bi-controller text-info"></i> Quản Lý Tài Khoản Thuê Của Tôi</h2>
            <p class="text-secondary small mb-0">Theo dõi thời hạn thuê, nhận thông tin đăng nhập và quản lý gia hạn</p>
        </div>
        <span class="text-info fw-bold bg-dark px-3 py-2 rounded border border-secondary">
            <i class="bi bi-person-circle me-1"></i> @<?php echo htmlspecialchars($_SESSION['username']); ?>
        </span>
    </div>

    <?php if ($report_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($report_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <?php 
        if ($result && $result->num_rows > 0): 
            while ($row = $result->fetch_assoc()): 
                $due_timestamp = strtotime($row['due_date']);
                $is_overdue = ($row['status'] === 'expired' || ($row['status'] === 'active' && $due_timestamp < time()));
                $is_active = ($row['status'] === 'active' && !$is_overdue);
                
                $countdown_id = "timer_" . $row['borrow_id'];
                
                // Giải mã mật khẩu game an toàn (Chỉ giải mã khi đơn ĐANG CÒN HIỆU LỰC)
                $plain_password = "";
                if ($is_active && !empty($row['account_password_encrypted'])) {
                    $plain_password = decrypt_game_password($row['account_password_encrypted']);
                }
        ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm border-0" style="background-color: #1a222c; border: 1px solid #2d3b4e; border-radius: 12px; overflow: hidden;">
                
                <?php if (!empty($row['image_url']) && file_exists($row['image_url'])): ?>
                    <img src="<?php echo $row['image_url']; ?>" class="card-img-top" alt="<?php echo htmlspecialchars($row['title']); ?>" style="height: 200px; object-fit: cover;">
                <?php else: ?>
                    <div class="card-img-top d-flex align-items-center justify-content-center bg-dark text-muted" style="height: 200px;">NO COVER</div>
                <?php endif; ?>

                <div class="card-body d-flex flex-column p-4">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h4 class="card-title text-info fw-bold mb-0"><?php echo htmlspecialchars($row['title']); ?></h4>
                        <?php if ($is_active): ?>
                            <span class="badge bg-success">Đang thuê</span>
                        <?php elseif ($is_overdue): ?>
                            <span class="badge bg-danger">Hết hạn</span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($row['status']); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <p class="card-text mb-1 text-secondary small">
                        Bắt đầu: <span class="text-light"><?php echo date('d/m/Y H:i', strtotime($row['borrow_date'])); ?></span>
                    </p>
                    <p class="card-text mb-3 text-secondary small">
                        Hết hạn: <span class="text-light fw-bold"><?php echo date('d/m/Y H:i', $due_timestamp); ?></span>
                    </p>

                    <!-- KHU VỰC THÔNG TIN ĐĂNG NHẬP TÀI KHOẢN GAME -->
                    <div class="mt-2 mb-3 p-3 rounded" style="background-color: rgba(13, 110, 253, 0.08); border: 1px solid rgba(13, 110, 253, 0.3);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-info fw-bold"><i class="bi bi-shield-lock-fill me-1"></i> THÔNG TIN ĐĂNG NHẬP</small>
                            <span class="badge bg-dark text-light border border-secondary"><?php echo htmlspecialchars($row['server_region'] ?? 'Global'); ?></span>
                        </div>
                        
                        <?php if ($is_active && !empty($row['account_username'])): ?>
                            <div class="mb-2">
                                <small class="text-secondary d-block">Tên đăng nhập / Email:</small>
                                <div class="d-flex align-items-center justify-content-between bg-dark p-2 rounded border border-secondary">
                                    <strong class="text-white user-select-all"><?php echo htmlspecialchars($row['account_username']); ?></strong>
                                    <button class="btn btn-sm btn-outline-info py-0 px-2" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($row['account_username']); ?>')">Copy</button>
                                </div>
                            </div>
                            <div>
                                <small class="text-secondary d-block">Mật khẩu game:</small>
                                <div class="d-flex align-items-center justify-content-between bg-dark p-2 rounded border border-secondary">
                                    <strong class="text-warning user-select-all" id="pass_<?php echo $row['borrow_id']; ?>"><?php echo htmlspecialchars($plain_password); ?></strong>
                                    <button class="btn btn-sm btn-outline-warning py-0 px-2" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($plain_password); ?>')">Copy</button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-2">
                                <span class="text-danger small fst-italic">
                                    <i class="bi bi-lock-fill"></i> Thông tin đăng nhập đã bị khóa do hết hạn thuê hoặc đơn chưa hoàn tất.
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ĐỒNG HỒ ĐẾM NGƯỢC THỜI HẠN -->
                    <div class="p-3 rounded mb-3 text-center shadow-sm" style="background-color: #202d39;">
                        <?php if ($is_active): ?>
                            <span class="text-muted small d-block mb-1">Thời gian thuê còn lại:</span>
                            <h4 class="text-warning fw-bold mb-0" id="<?php echo $countdown_id; ?>">Đang tính toán...</h4>
                            
                            <script>
                                (function() {
                                    var countDownDate_<?php echo $row['borrow_id']; ?> = new Date("<?php echo date('M d, Y H:i:s', $due_timestamp); ?>").getTime();
                                    var x_<?php echo $row['borrow_id']; ?> = setInterval(function() {
                                        var now = new Date().getTime();
                                        var distance = countDownDate_<?php echo $row['borrow_id']; ?> - now;
                                        
                                        if (distance < 0) {
                                            clearInterval(x_<?php echo $row['borrow_id']; ?>);
                                            var el = document.getElementById("<?php echo $countdown_id; ?>");
                                            if (el) {
                                                el.innerHTML = "ĐÃ HẾT HẠN";
                                                el.classList.replace('text-warning', 'text-danger');
                                            }
                                        } else {
                                            var days = Math.floor(distance / (1000 * 60 * 60 * 24));
                                            var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                                            var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                                            var seconds = Math.floor((distance % (1000 * 60)) / 1000);
                                            
                                            var timeStr = "";
                                            if (days > 0) timeStr += days + " ngày ";
                                            timeStr += (hours < 10 ? "0" + hours : hours) + ":" + 
                                                       (minutes < 10 ? "0" + minutes : minutes) + ":" + 
                                                       (seconds < 10 ? "0" + seconds : seconds);
                                            
                                            var el = document.getElementById("<?php echo $countdown_id; ?>");
                                            if (el) { el.innerHTML = timeStr; }
                                        }
                                    }, 1000);
                                })();
                            </script>
                        <?php else: ?>
                            <h5 class="text-danger fw-bold mb-0">HẾT HẠN THUÊ</h5>
                            <small class="text-secondary">Quyền xem thông tin tài khoản đã bị thu hồi.</small>
                        <?php endif; ?>
                    </div>

                    <!-- KHU VỰC HÀNH ĐỘNG: GIA HẠN / THUÊ LẠI TÀI KHOẢN CŨ / THUÊ TÀI KHOẢN MỚI -->
                    <div class="mt-auto">
                        <?php if ($is_active): ?>
                            <!-- GIA HẠN ĐƠN ĐANG CÒN HIỆU LỰC -->
                            <form method="POST" action="checkout_qr.php" class="p-2 border rounded border-secondary mb-2" style="background-color: #151d26;">
                                <input type="hidden" name="borrow_id_to_extend" value="<?php echo $row['borrow_id']; ?>">
                                <small class="text-info fw-bold d-block mb-1">🔄 Gia hạn thêm thời gian:</small>
                                <div class="input-group input-group-sm">
                                    <select name="extend_pkg_id" class="form-select bg-dark text-white border-secondary">
                                        <?php
                                        $pkgs_cur = $conn->query("SELECT * FROM rental_packages WHERE game_id = {$row['game_id']} AND is_active = 1 ORDER BY price ASC");
                                        while ($pkg_item = $pkgs_cur->fetch_assoc()):
                                        ?>
                                            <option value="<?php echo $pkg_item['package_id']; ?>">
                                                +<?php echo htmlspecialchars($pkg_item['package_name']); ?> (<?php echo number_format($pkg_item['price'], 0, ',', '.'); ?>đ)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                    <button type="submit" name="btn_extend_submit" class="btn btn-warning fw-bold text-dark">Gia hạn</button>
                                </div>
                            </form>
                            
                            <!-- Báo lỗi tài khoản -->
                            <button type="button" class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#reportModal_<?php echo $row['borrow_id']; ?>">
                                <i class="bi bi-flag-fill me-1"></i> Báo lỗi tài khoản
                            </button>
                        <?php else: ?>
                            <!-- HAI LỰA CHỌN SAU KHI HẾT HẠN THEO ĐÚNG MỤC 8 -->
                            <div class="d-grid gap-2">
                                <!-- LỰA CHỌN A: THUÊ LẠI TÀI KHOẢN CŨ -->
                                <?php if ($row['account_id']): ?>
                                    <a href="borrow_process.php?game_id=<?php echo $row['game_id']; ?>&type=re-rent&re_rent_acc=<?php echo $row['account_id']; ?>&old_borrow_id=<?php echo $row['borrow_id']; ?>" 
                                       class="btn btn-warning fw-bold text-dark shadow-sm">
                                        🔁 Thuê lại tài khoản cũ
                                    </a>
                                <?php endif; ?>

                                <!-- LỰA CHỌN B: THUÊ TÀI KHOẢN MỚI -->
                                <a href="borrow_process.php?game_id=<?php echo $row['game_id']; ?>&type=new" 
                                   class="btn btn-outline-info fw-bold">
                                    ✨ Thuê tài khoản mới
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL BÁO LỖI -->
        <?php if ($is_active): ?>
        <div class="modal fade" id="reportModal_<?php echo $row['borrow_id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content bg-dark text-white border-secondary">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="report_borrow_id" value="<?php echo $row['borrow_id']; ?>">
                        <div class="modal-header border-secondary">
                            <h5 class="modal-title text-warning fw-bold"><i class="bi bi-exclamation-octagon-fill me-2"></i> Báo Lỗi Tài Khoản Game</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-secondary small">Nếu bạn không thể đăng nhập hoặc tài khoản gặp sự cố (sai pass, bị khóa...), hãy gửi mô tả bên dưới:</p>
                            <textarea name="issue_description" class="form-control bg-secondary bg-opacity-25 text-white border-secondary" rows="4" placeholder="Ví dụ: Mật khẩu báo không đúng trên Steam / Game yêu cầu mã xác minh OTP..." required></textarea>
                        </div>
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                            <button type="submit" name="btn_report_error" class="btn btn-danger fw-bold">Gửi Báo Cáo Kỹ Thuật</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endwhile; else: ?>
        <div class="col-12 text-center py-5">
            <div class="card border-0 p-5 shadow" style="background-color: #1a222c; border-radius: 15px;">
                <h4 class="text-light mb-3">Bạn chưa có đơn thuê tài khoản game nào!</h4>
                <p class="text-secondary mb-4">Khám phá ngay các tựa game đình đám để trải nghiệm với giá thuê cực kỳ tiết kiệm.</p>
                <div>
                    <a href="index.php" class="btn btn-primary fw-bold px-4 py-2 fs-5 shadow">🎮 Khám Phá Cửa Hàng Game</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>