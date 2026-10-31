<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$page_title = "Quản Trị Kho Tài Khoản Game";
require_once __DIR__ . '/includes/admin_header.php';

$message = "";
$error = "";

// 1. XỬ LÝ NHẬP TÀI KHOẢN MỚI THỦ CÔNG HOẶC TỰ ĐỘNG SINH MẬT KHẨU
if (isset($_POST['btn_add_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed!";
    } else {
        $game_id = intval($_POST['game_id']);
        $auto_generate = isset($_POST['auto_generate_auth']);
        
        if ($auto_generate) {
            $username = generate_game_username('acc_' . $game_id);
            $password = generate_strong_password(14);
        } else {
            $username = trim($_POST['account_username']);
            $password = trim($_POST['account_password']);
        }
        
        $server_region = $conn->real_escape_string($_POST['server_region'] ?? 'Global');
        $notes = $conn->real_escape_string($_POST['notes'] ?? '');

        if (empty($username) || empty($password)) {
            $error = "Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu!";
        } else {
            $encrypted_pass = encrypt_game_password($password);

            $stmt = $conn->prepare("INSERT INTO game_accounts (game_id, account_username, account_password_encrypted, server_region, status, notes, created_at) VALUES (?, ?, ?, ?, 'AVAILABLE', ?, NOW())");
            $stmt->bind_param("issss", $game_id, $username, $encrypted_pass, $server_region, $notes);
            if ($stmt->execute()) {
                $new_acc_id = $conn->insert_id;
                log_account_allocation($conn, $new_acc_id, null, $_SESSION['user_id'], 'MAINTENANCE_TRIGGERED', "Admin nhập tài khoản mới vào kho", 'ADMIN: ' . $_SESSION['username']);
                sync_game_inventory($conn, $game_id);
                $message = "Đã nhập thành công tài khoản [$username] vào kho! (Mật khẩu: $password)";
            } else {
                $error = "Lỗi thêm tài khoản: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// 2. XỬ LÝ ĐỔI TRẠNG THÁI / ĐỔI MẬT KHẨU / BẢO TRÌ TÀI KHOẢN
if (isset($_POST['btn_update_account'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $acc_id = intval($_POST['edit_account_id']);
        $new_status = $_POST['edit_status'];
        $new_notes = $conn->real_escape_string($_POST['edit_notes']);
        $new_pass = trim($_POST['new_password'] ?? '');

        $sql_extra = "";
        if (!empty($new_pass)) {
            $enc_pass = encrypt_game_password($new_pass);
            $sql_extra = ", account_password_encrypted = '$enc_pass'";
            log_account_allocation($conn, $acc_id, null, $_SESSION['user_id'], 'PASSWORD_CHANGED', "Admin đã cập nhật mật khẩu mới", 'ADMIN: ' . $_SESSION['username']);
        }

        $conn->query("UPDATE game_accounts SET status = '$new_status', notes = '$new_notes' $sql_extra WHERE account_id = $acc_id");
        
        $g_id = $conn->query("SELECT game_id FROM game_accounts WHERE account_id = $acc_id")->fetch_assoc()['game_id'];
        sync_game_inventory($conn, $g_id);
        $message = "Đã cập nhật trạng thái tài khoản #$acc_id thành công!";
    }
}

// 3. XÓA TÀI KHOẢN (Chỉ khi không đang được thuê)
if (isset($_GET['delete_account'])) {
    $del_id = intval($_GET['delete_account']);
    $chk = $conn->query("SELECT status, game_id FROM game_accounts WHERE account_id = $del_id")->fetch_assoc();
    if ($chk && $chk['status'] !== 'RENTED') {
        $conn->query("DELETE FROM game_accounts WHERE account_id = $del_id");
        sync_game_inventory($conn, $chk['game_id']);
        $message = "Đã xóa tài khoản #$del_id khỏi kho.";
    } else {
        $error = "Không thể xóa tài khoản đang trong thời hạn cho thuê!";
    }
}

// 4. XỬ LÝ NẠP HÀNG LOẠT 20 TÀI KHOẢN CHO MỖI GAME
if (isset($_POST['btn_batch_generate'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed!";
    } else {
        $target_game_id = intval($_POST['batch_game_id'] ?? 0);
        $accounts_per_game = intval($_POST['batch_quantity'] ?? 20);
        if ($accounts_per_game < 1) $accounts_per_game = 20;
        if ($accounts_per_game > 50) $accounts_per_game = 50;

        $target_games = [];
        if ($target_game_id > 0) {
            $target_games[] = $target_game_id;
        } else {
            $all_games_q = $conn->query("SELECT game_id FROM games");
            while ($g_row = $all_games_q->fetch_assoc()) {
                $target_games[] = intval($g_row['game_id']);
            }
        }

        $total_seeded = 0;
        foreach ($target_games as $g_id) {
            $existing_count = intval($conn->query("SELECT COUNT(*) as c FROM game_accounts WHERE game_id = $g_id")->fetch_assoc()['c']);
            $needed = $accounts_per_game - $existing_count;
            if ($needed > 0) {
                for ($i = 1; $i <= $needed; $i++) {
                    $u_name = "anplay_" . $g_id . "_" . str_pad($existing_count + $i, 2, '0', STR_PAD_LEFT);
                    $plain_pwd = "AnPlay@" . strtoupper(bin2hex(random_bytes(3))) . "!";
                    $enc_pwd = encrypt_game_password($plain_pwd);
                    $reg = 'Global';
                    $note = 'Auto Batch Seed';

                    $stmt = $conn->prepare("INSERT INTO game_accounts (game_id, account_username, account_password_encrypted, server_region, status, notes, created_at) VALUES (?, ?, ?, ?, 'AVAILABLE', ?, NOW())");
                    $stmt->bind_param("issss", $g_id, $u_name, $enc_pwd, $reg, $note);
                    if ($stmt->execute()) {
                        $total_seeded++;
                    }
                    $stmt->close();
                }
                sync_game_inventory($conn, $g_id);
            }
        }
        $message = "Đã nạp thành công $total_seeded tài khoản vào kho để đảm bảo đủ $accounts_per_game tài khoản cho mỗi game!";
    }
}

// LẤY DANH SÁCH GAME ĐỂ LỌC
$games_list = $conn->query("SELECT game_id, title FROM games ORDER BY title ASC");

// LẤY BỘ LỌC
$filter_game = isset($_GET['filter_game']) ? intval($_GET['filter_game']) : 0;
$filter_status = isset($_GET['filter_status']) ? $conn->real_escape_string($_GET['filter_status']) : '';

$where = [];
if ($filter_game > 0) $where[] = "ga.game_id = $filter_game";
if (!empty($filter_status)) $where[] = "ga.status = '$filter_status'";
$where_str = count($where) > 0 ? " WHERE " . implode(" AND ", $where) : "";

$accounts_res = false;
try {
    $accounts_res = $conn->query("SELECT ga.*, g.title, u.username as renter_username, br.due_date as rental_due_date
        FROM game_accounts ga
        JOIN games g ON ga.game_id = g.game_id
        LEFT JOIN borrow_records br ON ga.current_borrow_id = br.borrow_id
        LEFT JOIN users u ON br.user_id = u.user_id
        $where_str
        ORDER BY ga.account_id DESC");
} catch (Throwable $e) {}

$csrf_token = generate_csrf_token();
?>

<div class="container-fluid px-lg-5 py-4 my-2 text-white">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); font-size: 0.75rem; border: 1px solid rgba(99,102,241,0.4);">
                    <i class="bi bi-box-seam-fill me-1"></i> INVENTORY CONTROL
                </span>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Quản Lý Kho Tài Khoản Game</h2>
            <p class="text-secondary small mb-0">Nhập kho tự động, mã hóa AES-256 an toàn, quản lý trạng thái và tự động giảm tồn kho khi có khách thuê</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-warning fw-bold text-dark d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#batchGenerateModal">
                <i class="bi bi-lightning-charge-fill me-1"></i> Nạp Hàng Loạt 20 TK / Game
            </button>
            <button class="btn btn-gaming-primary d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                <i class="bi bi-plus-circle me-1"></i> + Nhập Tài Khoản
            </button>
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

    <!-- THANH LỌC VÀ TÌM KIẾM -->
    <div class="p-3 mb-4 rounded-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label text-secondary small fw-bold mb-1">Lọc theo Tựa Game:</label>
                <select name="filter_game" class="form-select form-control-gaming">
                    <option value="0">-- Tất cả tựa game --</option>
                    <?php 
                    if ($games_list && $games_list->num_rows > 0):
                        $games_list->data_seek(0);
                        while ($g = $games_list->fetch_assoc()): ?>
                            <option value="<?php echo $g['game_id']; ?>" <?php echo ($filter_game == $g['game_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($g['title']); ?>
                            </option>
                    <?php 
                        endwhile; 
                    endif; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label text-secondary small fw-bold mb-1">Lọc theo Trạng Thái Kho:</label>
                <select name="filter_status" class="form-select form-control-gaming">
                    <option value="">-- Tất cả trạng thái --</option>
                    <option value="AVAILABLE" <?php echo ($filter_status == 'AVAILABLE') ? 'selected' : ''; ?>>🟢 AVAILABLE (Sẵn sàng cho thuê)</option>
                    <option value="RENTED" <?php echo ($filter_status == 'RENTED') ? 'selected' : ''; ?>>🔵 RENTED (Đang cho khách thuê)</option>
                    <option value="EXPIRED" <?php echo ($filter_status == 'EXPIRED') ? 'selected' : ''; ?>>🔴 EXPIRED (Hết hạn)</option>
                    <option value="MAINTENANCE" <?php echo ($filter_status == 'MAINTENANCE') ? 'selected' : ''; ?>>🟡 MAINTENANCE (Bảo trì/Báo lỗi)</option>
                    <option value="DISABLED" <?php echo ($filter_status == 'DISABLED') ? 'selected' : ''; ?>>⚪ DISABLED (Vô hiệu)</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-gaming-primary px-4 flex-grow-1">
                    <i class="bi bi-funnel me-1"></i> Lọc Kho
                </button>
                <a href="admin_accounts.php" class="btn btn-gaming-secondary">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- BẢNG DANH SÁCH TÀI KHOẢN -->
    <div class="table-gaming-container">
        <div class="table-responsive">
            <table class="table table-gaming align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 5%;">ID</th>
                        <th style="width: 22%;">Tựa Game</th>
                        <th style="width: 20%;">Tên Đăng Nhập</th>
                        <th style="width: 15%;">Mật Khẩu Game</th>
                        <th style="width: 13%;" class="text-center">Trạng Thái</th>
                        <th style="width: 13%;">Đơn Thuê</th>
                        <th style="width: 12%;" class="text-end pe-3">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($accounts_res && $accounts_res->num_rows > 0): 
                        while ($acc = $accounts_res->fetch_assoc()): 
                            $status_class = 'status-pill-secondary';
                            if ($acc['status'] == 'AVAILABLE') $status_class = 'status-pill-success';
                            elseif ($acc['status'] == 'RENTED') $status_class = 'status-pill-info';
                            elseif ($acc['status'] == 'EXPIRED') $status_class = 'status-pill-danger';
                            elseif ($acc['status'] == 'MAINTENANCE') $status_class = 'status-pill-warning';
                            
                            $plain_pass = decrypt_game_password($acc['account_password_encrypted']);
                    ?>
                        <tr>
                            <td class="text-secondary fw-bold">#<?php echo $acc['account_id']; ?></td>
                            <td class="fw-bold text-white"><?php echo htmlspecialchars($acc['title']); ?></td>
                            <td>
                                <span class="text-light fw-bold user-select-all"><?php echo htmlspecialchars($acc['account_username']); ?></span>
                                <div class="small text-secondary" style="font-size: 0.72rem;">Khu vực: <?php echo htmlspecialchars($acc['server_region'] ?? 'Global'); ?></div>
                            </td>
                            <td>
                                <span class="text-warning small user-select-all font-monospace fw-bold"><?php echo htmlspecialchars($plain_pass); ?></span>
                            </td>
                            <td class="text-center">
                                <span class="status-pill <?php echo $status_class; ?>">
                                    <span class="status-dot"></span> <?php echo $acc['status']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($acc['current_borrow_id']): ?>
                                    <a href="admin_borrow.php?search=<?php echo $acc['current_borrow_id']; ?>" class="text-info text-decoration-none small">
                                        <i class="bi bi-receipt me-1"></i>Đơn #<?php echo $acc['current_borrow_id']; ?> (@<?php echo htmlspecialchars($acc['renter_username'] ?? 'Khách'); ?>)
                                    </a>
                                <?php else: ?>
                                    <span class="text-secondary small">Chưa thuê</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex gap-1">
                                    <button class="btn btn-sm btn-gaming-secondary py-1 px-2" data-bs-toggle="modal" data-bs-target="#editModal_<?php echo $acc['account_id']; ?>" title="Chỉnh sửa tài khoản">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($acc['status'] !== 'RENTED'): ?>
                                        <a href="admin_accounts.php?delete_account=<?php echo $acc['account_id']; ?>" class="btn btn-sm btn-outline-danger py-1 px-2" onclick="return confirm('Bạn chắc chắn muốn xóa tài khoản này khỏi kho?')" title="Xóa">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- MODAL SỬA TÀI KHOẢN -->
                        <div class="modal fade" id="editModal_<?php echo $acc['account_id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content" style="background: var(--bg-card); border: 1px solid var(--border-color); color: #fff;">
                                    <form method="POST" action="">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="edit_account_id" value="<?php echo $acc['account_id']; ?>">
                                        <div class="modal-header border-secondary border-opacity-25">
                                            <h5 class="modal-title text-info fw-bold">Chỉnh Sửa Tài Khoản #<?php echo $acc['account_id']; ?></h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label text-secondary small fw-bold">Tên đăng nhập:</label>
                                                <input type="text" class="form-control form-control-gaming" value="<?php echo htmlspecialchars($acc['account_username']); ?>" readonly>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label text-secondary small fw-bold">Trạng thái kho:</label>
                                                <select name="edit_status" class="form-select form-control-gaming">
                                                    <option value="AVAILABLE" <?php echo ($acc['status'] == 'AVAILABLE') ? 'selected' : ''; ?>>AVAILABLE (Sẵn sàng)</option>
                                                    <option value="RESERVED" <?php echo ($acc['status'] == 'RESERVED') ? 'selected' : ''; ?>>RESERVED (Giữ chỗ)</option>
                                                    <option value="RENTED" <?php echo ($acc['status'] == 'RENTED') ? 'selected' : ''; ?>>RENTED (Đang thuê)</option>
                                                    <option value="EXPIRED" <?php echo ($acc['status'] == 'EXPIRED') ? 'selected' : ''; ?>>EXPIRED (Hết hạn)</option>
                                                    <option value="MAINTENANCE" <?php echo ($acc['status'] == 'MAINTENANCE') ? 'selected' : ''; ?>>MAINTENANCE (Bảo trì/Kiểm tra)</option>
                                                    <option value="DISABLED" <?php echo ($acc['status'] == 'DISABLED') ? 'selected' : ''; ?>>DISABLED (Vô hiệu hóa)</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label text-secondary small fw-bold">Đổi mật khẩu mới (Bỏ trống nếu giữ nguyên):</label>
                                                <input type="text" name="new_password" class="form-control form-control-gaming" placeholder="Nhập mật khẩu mới...">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label text-secondary small fw-bold">Ghi chú kiểm tra / Lý do lỗi:</label>
                                                <textarea name="edit_notes" class="form-control form-control-gaming" rows="3"><?php echo htmlspecialchars($acc['notes'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer border-secondary border-opacity-25">
                                            <button type="button" class="btn btn-gaming-secondary" data-bs-dismiss="modal">Đóng</button>
                                            <button type="submit" name="btn_update_account" class="btn btn-gaming-primary">Lưu Thay Đổi</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                    <?php endwhile; else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-secondary">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i> Chưa có tài khoản nào trong kho khớp với bộ lọc hiện tại.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL THÊM TÀI KHOẢN MỚI -->
<div class="modal fade" id="addAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--bg-card); border: 1px solid var(--border-color); color: #fff;">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="modal-header border-secondary border-opacity-25">
                    <h5 class="modal-title text-success fw-bold"><i class="bi bi-plus-circle me-1"></i> Nhập Tài Khoản Vào Kho</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-info small fw-bold">Chọn tựa game:</label>
                        <select name="game_id" class="form-select form-control-gaming" required>
                            <?php 
                            if ($games_list && $games_list->num_rows > 0):
                                $games_list->data_seek(0);
                                while ($g = $games_list->fetch_assoc()): ?>
                                    <option value="<?php echo $g['game_id']; ?>"><?php echo htmlspecialchars($g['title']); ?></option>
                            <?php 
                                endwhile; 
                            endif; ?>
                        </select>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="auto_generate_auth" id="autoGenerateSwitch" onchange="toggleManualInputs(this.checked)">
                        <label class="form-check-label text-warning small fw-bold" for="autoGenerateSwitch">Tự động sinh thông tin đăng nhập (CSPRNG Strong Password)</label>
                    </div>

                    <div id="manualInputs">
                        <div class="mb-3">
                            <label class="form-label text-light small fw-bold">Tên đăng nhập / Email Game:</label>
                            <input type="text" name="account_username" id="inp_username" class="form-control form-control-gaming" placeholder="Ví dụ: steam_vip_01@game.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light small fw-bold">Mật khẩu game:</label>
                            <input type="text" name="account_password" id="inp_password" class="form-control form-control-gaming" placeholder="Nhập mật khẩu an toàn...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-light small fw-bold">Server / Khu vực:</label>
                        <input type="text" name="server_region" class="form-control form-control-gaming" value="Global">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-light small fw-bold">Ghi chú nội bộ:</label>
                        <textarea name="notes" class="form-control form-control-gaming" rows="2" placeholder="Ví dụ: Tài khoản có sẵn DLC..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-gaming-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="btn_add_account" class="btn btn-gaming-primary">Xác Nhận Nhập Kho</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL NẠP HÀNG LOẠT TỰ ĐỘNG 20 TÀI KHOẢN -->
<div class="modal fade" id="batchGenerateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--bg-card); border: 1px solid var(--border-color); color: #fff;">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="modal-header border-secondary border-opacity-25">
                    <h5 class="modal-title text-warning fw-bold"><i class="bi bi-lightning-charge-fill me-2"></i> Nạp Hàng Loạt 20 Tài Khoản / Game</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="p-3 rounded-3 small mb-3" style="background: rgba(6,182,212,0.1); border: 1px solid rgba(6,182,212,0.3); color: #cbd5e1;">
                        <i class="bi bi-info-circle text-info me-1"></i> Hệ thống sẽ tự động tạo tài khoản với username độc lập và mật khẩu ngẫu nhiên độ an toàn cao (mã hóa chuẩn AES-256). Khi có người thuê, số lượng khả dụng sẽ tự động trừ đi 1!
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-info small fw-bold">Chọn phạm vi game:</label>
                        <select name="batch_game_id" class="form-select form-control-gaming" required>
                            <option value="0">⭐ TẤT CẢ GAME CHO THUÊ</option>
                            <?php 
                            if ($games_list && $games_list->num_rows > 0):
                                $games_list->data_seek(0);
                                while ($g = $games_list->fetch_assoc()): ?>
                                    <option value="<?php echo $g['game_id']; ?>">Chỉ game: <?php echo htmlspecialchars($g['title']); ?></option>
                            <?php 
                                endwhile; 
                            endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-warning small fw-bold">Số lượng tài khoản cho mỗi game:</label>
                        <input type="number" name="batch_quantity" class="form-control form-control-gaming" value="20" min="1" max="50" required>
                        <div class="form-text text-secondary">Mặc định: 20 tài khoản / game (Trạng thái: AVAILABLE).</div>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-gaming-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" name="btn_batch_generate" class="btn btn-warning text-dark fw-bold">
                        <i class="bi bi-lightning-fill me-1"></i> BẮT ĐẦU NẠP TÀI KHOẢN NGAY
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleManualInputs(isAuto) {
    var div = document.getElementById('manualInputs');
    var u = document.getElementById('inp_username');
    var p = document.getElementById('inp_password');
    if (isAuto) {
        div.style.display = 'none';
        u.removeAttribute('required');
        p.removeAttribute('required');
    } else {
        div.style.display = 'block';
        u.setAttribute('required', 'required');
        p.setAttribute('required', 'required');
    }
}
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
