<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/header.php';

// Kiểm tra quyền Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    echo "<script>alert('Từ chối truy cập!'); window.location='index.php';</script>";
    exit();
}

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
            // Tự động sinh tên đăng nhập & mật khẩu bảo mật CSPRNG theo mục 5
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
            // Mã hóa mật khẩu bằng AES-256
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
        
        // Lấy game_id để sync
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

// 4. XỬ LÝ NẠP HÀNG LOẠT 20 TÀI KHOẢN CHO MỖI GAME (HOẶC TẤT CẢ GAME)
if (isset($_POST['btn_batch_generate'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed!";
    } else {
        $target_game_id = intval($_POST['batch_game_id'] ?? 0);
        $accounts_per_game = intval($_POST['batch_quantity'] ?? 20);
        if ($accounts_per_game < 1) $accounts_per_game = 20;
        if ($accounts_per_game > 50) $accounts_per_game = 50;

        $games_to_seed = [];
        if ($target_game_id > 0) {
            $g_res = $conn->query("SELECT game_id, title FROM games WHERE game_id = $target_game_id");
            if ($g_res && $g_row = $g_res->fetch_assoc()) {
                $games_to_seed[] = $g_row;
            }
        } else {
            $g_res = $conn->query("SELECT game_id, title FROM games WHERE price_type = 'paid'");
            if ($g_res && $g_res->num_rows > 0) {
                while ($g_row = $g_res->fetch_assoc()) {
                    $games_to_seed[] = $g_row;
                }
            }
        }

        if (empty($games_to_seed)) {
            $error = "Không tìm thấy tựa game trả phí nào để tạo tài khoản!";
        } else {
            $total_created = 0;
            $conn->begin_transaction();
            try {
                foreach ($games_to_seed as $g) {
                    $gid = intval($g['game_id']);
                    $clean_title = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($g['title']));
                    if (empty($clean_title)) $clean_title = "game" . $gid;
                    $clean_title = substr($clean_title, 0, 10);

                    for ($i = 1; $i <= $accounts_per_game; $i++) {
                        $uname = "anplay_" . $clean_title . "_" . rand(100, 999) . $i;
                        $plain_pass = generate_strong_password(14);
                        $enc_pass = encrypt_game_password($plain_pass);
                        $region = "Global (VN/Asia)";
                        $note = "Nạp tự động kho - " . date('d/m/Y H:i');

                        $ins_stmt = $conn->prepare("INSERT INTO game_accounts 
                            (game_id, account_username, account_password_encrypted, server_region, status, notes, created_at) 
                            VALUES (?, ?, ?, ?, 'AVAILABLE', ?, NOW())");
                        $ins_stmt->bind_param("issss", $gid, $uname, $enc_pass, $region, $note);
                        $ins_stmt->execute();
                        $new_id = $conn->insert_id;
                        $ins_stmt->close();

                        log_account_allocation($conn, $new_id, null, $_SESSION['user_id'], 'MAINTENANCE_TRIGGERED', "Nạp hàng loạt vào kho", 'ADMIN: ' . $_SESSION['username']);
                        $total_created++;
                    }
                    sync_game_inventory($conn, $gid);
                }
                $conn->commit();
                $message = "🎉 THÀNH CÔNG RỰC RỠ! Đã nạp thành công $total_created tài khoản mới cho " . count($games_to_seed) . " tựa game! Số lượng hiển thị của mỗi game đã được cập nhật chính xác.";
            } catch (Throwable $ex) {
                $conn->rollback();
                $error = "Lỗi khi nạp hàng loạt: " . $ex->getMessage();
            }
        }
    }
}

// LẤY DANH SÁCH GAME ĐỂ FILTER VÀ CHỌN
$games_list = false;
try {
    $games_list = $conn->query("SELECT game_id, title FROM games WHERE price_type = 'paid' ORDER BY title ASC");
} catch (Throwable $e) {}

// FILTER
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

<div class="container-fluid mt-4 mb-5 text-white">
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary">
        <div>
            <h2 class="fw-bold m-0 text-info"><i class="bi bi-box-seam-fill me-2"></i> QUẢN LÝ KHO TÀI KHOẢN GAME</h2>
            <p class="text-secondary small mb-0">Quản lý kho tài khoản, nhập kho tự động/thủ công, kiểm tra trạng thái và thu hồi</p>
        </div>
        <div>
            <button class="btn btn-warning fw-bold px-3 py-2 text-dark shadow me-2" data-bs-toggle="modal" data-bs-target="#batchGenerateModal">
                <i class="bi bi-lightning-charge-fill me-1"></i> Nạp Hàng Loạt 20 TK / Game
            </button>
            <button class="btn btn-success fw-bold px-4 py-2" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                <i class="bi bi-plus-circle me-1"></i> + Nhập Tài Khoản Vào Kho
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- THANH LỌC VÀ TÌM KIẾM -->
    <div class="card p-3 mb-4 border-0 shadow-sm" style="background-color: #1a222c;">
        <form method="GET" action="" class="row g-3 align-items-center">
            <div class="col-md-4">
                <label class="form-label text-secondary small mb-1">Lọc theo Tựa Game:</label>
                <select name="filter_game" class="form-select bg-dark text-white border-secondary">
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
            <div class="col-md-3">
                <label class="form-label text-secondary small mb-1">Lọc theo Trạng Thái:</label>
                <select name="filter_status" class="form-select bg-dark text-white border-secondary">
                    <option value="">-- Tất cả trạng thái --</option>
                    <option value="AVAILABLE" <?php echo ($filter_status == 'AVAILABLE') ? 'selected' : ''; ?>>🟢 AVAILABLE (Sẵn sàng)</option>
                    <option value="RENTED" <?php echo ($filter_status == 'RENTED') ? 'selected' : ''; ?>>🔵 RENTED (Đang thuê)</option>
                    <option value="EXPIRED" <?php echo ($filter_status == 'EXPIRED') ? 'selected' : ''; ?>>🔴 EXPIRED (Hết hạn)</option>
                    <option value="MAINTENANCE" <?php echo ($filter_status == 'MAINTENANCE') ? 'selected' : ''; ?>>🟡 MAINTENANCE (Bảo trì/Báo lỗi)</option>
                    <option value="DISABLED" <?php echo ($filter_status == 'DISABLED') ? 'selected' : ''; ?>>⚪ DISABLED (Vô hiệu)</option>
                </select>
            </div>
            <div class="col-md-3 mt-md-4 pt-md-2">
                <button type="submit" class="btn btn-primary px-4 fw-bold">Lọc Kho</button>
                <a href="admin_accounts.php" class="btn btn-outline-secondary ms-2">Đặt lại</a>
            </div>
        </form>
    </div>

    <!-- BẢNG DANH SÁCH TÀI KHOẢN -->
    <div class="card border-0 shadow" style="background-color: #1a222c; border-radius: 10px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0">
                    <thead style="background-color: #151d26;">
                        <tr>
                            <th class="py-3 ps-3 text-info">ID</th>
                            <th class="py-3">Tựa Game</th>
                            <th class="py-3">Tên Đăng Nhập</th>
                            <th class="py-3">Mật Khẩu Đã Mã Hóa</th>
                            <th class="py-3 text-center">Trạng Thái</th>
                            <th class="py-3">Đơn Thuê Hiện Tại</th>
                            <th class="py-3">Hạn Thuê</th>
                            <th class="py-3 text-center">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($accounts_res && $accounts_res->num_rows > 0): 
                            while ($acc = $accounts_res->fetch_assoc()): 
                                $status_badge = 'bg-secondary';
                                if ($acc['status'] == 'AVAILABLE') $status_badge = 'bg-success';
                                elseif ($acc['status'] == 'RENTED') $status_badge = 'bg-primary';
                                elseif ($acc['status'] == 'EXPIRED') $status_badge = 'bg-danger';
                                elseif ($acc['status'] == 'MAINTENANCE') $status_badge = 'bg-warning text-dark';
                                elseif ($acc['status'] == 'DISABLED') $status_badge = 'bg-dark text-muted';
                                
                                // Admin có thể giải mã mật khẩu trực tiếp tại trang quản trị
                                $plain_pass = decrypt_game_password($acc['account_password_encrypted']);
                        ?>
                            <tr>
                                <td class="ps-3 fw-bold text-secondary">#<?php echo $acc['account_id']; ?></td>
                                <td class="fw-bold text-white"><?php echo htmlspecialchars($acc['title']); ?></td>
                                <td class="text-info fw-bold user-select-all"><?php echo htmlspecialchars($acc['account_username']); ?></td>
                                <td>
                                    <span class="text-warning small user-select-all font-monospace"><?php echo htmlspecialchars($plain_pass); ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $status_badge; ?> px-2 py-1"><?php echo $acc['status']; ?></span>
                                </td>
                                <td>
                                    <?php if ($acc['current_borrow_id']): ?>
                                        <a href="admin_borrow.php?search=<?php echo $acc['current_borrow_id']; ?>" class="text-info">
                                            #<?php echo $acc['current_borrow_id']; ?> (@<?php echo htmlspecialchars($acc['renter_username'] ?? 'Khách'); ?>)
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">Không có</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php if ($acc['rent_expires_at']): ?>
                                        <?php echo date('d/m/Y H:i', strtotime($acc['rent_expires_at'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-info me-1" data-bs-toggle="modal" data-bs-target="#editModal_<?php echo $acc['account_id']; ?>">Sửa / Đổi Pass</button>
                                    <?php if ($acc['status'] !== 'RENTED'): ?>
                                        <a href="admin_accounts.php?delete_account=<?php echo $acc['account_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Bạn chắc chắn muốn xóa tài khoản này khỏi kho?')">Xóa</a>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- MODAL SỬA TÀI KHOẢN -->
                            <div class="modal fade" id="editModal_<?php echo $acc['account_id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content bg-dark text-white border-secondary">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="edit_account_id" value="<?php echo $acc['account_id']; ?>">
                                            <div class="modal-header border-secondary">
                                                <h5 class="modal-title text-info fw-bold">Chỉnh Sửa Tài Khoản #<?php echo $acc['account_id']; ?></h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label text-secondary small">Tên đăng nhập:</label>
                                                    <input type="text" class="form-control bg-dark text-white border-secondary" value="<?php echo htmlspecialchars($acc['account_username']); ?>" readonly>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label text-secondary small">Trạng thái kho:</label>
                                                    <select name="edit_status" class="form-select bg-dark text-white border-secondary">
                                                        <option value="AVAILABLE" <?php echo ($acc['status'] == 'AVAILABLE') ? 'selected' : ''; ?>>AVAILABLE (Sẵn sàng)</option>
                                                        <option value="RESERVED" <?php echo ($acc['status'] == 'RESERVED') ? 'selected' : ''; ?>>RESERVED (Giữ chỗ)</option>
                                                        <option value="RENTED" <?php echo ($acc['status'] == 'RENTED') ? 'selected' : ''; ?>>RENTED (Đang thuê)</option>
                                                        <option value="EXPIRED" <?php echo ($acc['status'] == 'EXPIRED') ? 'selected' : ''; ?>>EXPIRED (Hết hạn)</option>
                                                        <option value="MAINTENANCE" <?php echo ($acc['status'] == 'MAINTENANCE') ? 'selected' : ''; ?>>MAINTENANCE (Bảo trì/Kiểm tra)</option>
                                                        <option value="DISABLED" <?php echo ($acc['status'] == 'DISABLED') ? 'selected' : ''; ?>>DISABLED (Vô hiệu hóa)</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label text-secondary small">Đổi mật khẩu mới (Bỏ trống nếu giữ nguyên):</label>
                                                    <input type="text" name="new_password" class="form-control bg-dark text-white border-secondary" placeholder="Nhập mật khẩu mới...">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label text-secondary small">Ghi chú kiểm tra / Lý do lỗi:</label>
                                                    <textarea name="edit_notes" class="form-control bg-dark text-white border-secondary" rows="3"><?php echo htmlspecialchars($acc['notes'] ?? ''); ?></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-secondary">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                                                <button type="submit" name="btn_update_account" class="btn btn-primary fw-bold">Lưu Thay Đổi</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-secondary">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i> Chưa có tài khoản nào trong kho thỏa mãn bộ lọc.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL THÊM TÀI KHOẢN MỚI -->
<div class="modal fade" id="addAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-white border-secondary">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-success fw-bold"><i class="bi bi-plus-circle me-1"></i> Nhập Tài Khoản Vào Kho</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-info fw-bold">Chọn tựa game:</label>
                        <select name="game_id" class="form-select bg-dark text-white border-secondary" required>
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
                        <label class="form-check-label text-warning fw-bold" for="autoGenerateSwitch">Tự động sinh thông tin đăng nhập (CSPRNG Strong Password)</label>
                    </div>

                    <div id="manualInputs">
                        <div class="mb-3">
                            <label class="form-label text-light">Tên đăng nhập / Email Game:</label>
                            <input type="text" name="account_username" id="inp_username" class="form-control bg-dark text-white border-secondary" placeholder="Ví dụ: steam_vip_01@game.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light">Mật khẩu game:</label>
                            <input type="text" name="account_password" id="inp_password" class="form-control bg-dark text-white border-secondary" placeholder="Nhập mật khẩu an toàn...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-light">Server / Khu vực:</label>
                        <input type="text" name="server_region" class="form-control bg-dark text-white border-secondary" value="Global">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-light">Ghi chú nội bộ:</label>
                        <textarea name="notes" class="form-control bg-dark text-white border-secondary" rows="2" placeholder="Ví dụ: Tài khoản có sẵn DLC..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="btn_add_account" class="btn btn-success fw-bold">Xác Nhận Nhập Kho</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL NẠP HÀNG LOẠT TỰ ĐỘNG 20 TÀI KHOẢN -->
<div class="modal fade" id="batchGenerateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-warning fw-bold"><i class="bi bi-lightning-charge-fill me-2"></i> Nạp Hàng Loạt Tài Khoản Game Tự Động</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle-fill me-1"></i> Hệ thống sẽ tự động tạo tài khoản với tên đăng nhập duy nhất và mật khẩu ngẫu nhiên độ an toàn cao (mã hóa chuẩn AES-256). Khi có người thuê, số lượng khả dụng sẽ tự động giảm đi 1!
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-info fw-bold">Chọn phạm vi game:</label>
                        <select name="batch_game_id" class="form-select bg-dark text-white border-secondary" required>
                            <option value="0">⭐ TẤT CẢ GAME TRẢ PHÍ (Khuyên dùng)</option>
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
                        <label class="form-label text-warning fw-bold">Số lượng tài khoản cần nạp cho mỗi game:</label>
                        <input type="number" name="batch_quantity" class="form-control bg-dark text-white border-secondary" value="20" min="1" max="50" required>
                        <div class="form-text text-secondary">Mặc định: 20 tài khoản / game (Trạng thái: AVAILABLE).</div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
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

<?php include 'includes/footer.php'; ?>

