<?php 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/mail_service.php';
include 'includes/header.php'; 

$csrf_token = generate_csrf_token();
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$url_token = trim($_GET['token'] ?? '');
$hide_form = false;
?>

<div class="container py-5 my-4">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="p-4 p-md-5 rounded-3 shadow-lg" style="background: var(--surface-card); border: 1px solid var(--border-default); position: relative; overflow: hidden;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--primary), var(--accent-cyan));"></div>
                
                <div class="text-center mb-4">
                    <div class="d-inline-flex p-3 rounded-3 mb-3" style="background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.3);">
                        <i class="bi bi-shield-lock-fill fs-2" style="color: var(--primary);"></i>
                    </div>
                    <h3 class="fw-bold mb-1">Khôi Phục Mật Khẩu</h3>
                    <p class="text-secondary small">Thiết lập lại mật khẩu an toàn cho tài khoản ANPLAY của bạn</p>
                </div>
                
                <?php
                // BƯỚC 2: Xử lý cập nhật mật khẩu mới khi có token hợp lệ
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_reset'])) {
                    $post_csrf = $_POST['csrf_token'] ?? '';
                    $raw_token = trim($_POST['reset_token'] ?? '');
                    $new_password = $_POST['new_password'] ?? '';
                    $confirm_password = $_POST['confirm_password'] ?? '';
                    $rl_key = 'pwd_reset_verify:' . $client_ip;
                    $rl = check_rate_limit($conn, 'pwd_reset_verify', $client_ip, 5, 900);
                    $rl_allowed = is_array($rl) ? !empty($rl['allowed']) : (bool)$rl;

                    if (!verify_csrf_token($post_csrf)) {
                        echo '<div class="alert alert-danger mb-4">Phiên bảo mật không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang!</div>';
                    } elseif (!$rl_allowed) {
                        $wait_mins = is_array($rl) ? max(1, ceil(($rl['retry_after'] ?? 900) / 60)) : 15;
                        echo '<div class="alert alert-danger mb-4">Bạn đã thử quá nhiều lần. Vui lòng đợi ' . $wait_mins . ' phút rồi thử lại.</div>';
                    } elseif (empty($raw_token) || !preg_match('/^[a-f0-9]{64}$/i', $raw_token)) {
                        record_failed_attempt($conn, 'pwd_reset_verify', $client_ip, 5, 900);
                        echo '<div class="alert alert-danger mb-4">Mã xác thực đặt lại mật khẩu không hợp lệ hoặc đã hết hạn!</div>';
                    } elseif (strlen($new_password) < 8) {
                        echo '<div class="alert alert-warning mb-4">Mật khẩu mới phải có ít nhất 8 ký tự!</div>';
                        $url_token = $raw_token;
                    } elseif ($confirm_password !== '' && $new_password !== $confirm_password) {
                        echo '<div class="alert alert-warning mb-4">Mật khẩu xác nhận không khớp!</div>';
                        $url_token = $raw_token;
                    } else {
                        $token_hash = hash('sha256', $raw_token);
                        $conn->begin_transaction();
                        try {
                            $chk = $conn->prepare("SELECT id, user_id, email FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1 FOR UPDATE");
                            if (!$chk) {
                                throw new Exception('Không thể kiểm tra token.');
                            }
                            $chk->bind_param("s", $token_hash);
                            $chk->execute();
                            $reset_row = $chk->get_result()->fetch_assoc();
                            $chk->close();

                            if (!$reset_row) {
                                $conn->rollback();
                                record_failed_attempt($conn, 'pwd_reset_verify', $client_ip, 5, 900);
                                echo '<div class="alert alert-danger mb-4">Liên kết đặt lại mật khẩu không hợp lệ, đã được sử dụng hoặc đã hết hạn.</div>';
                            } else {
                                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                                $uid = intval($reset_row['user_id']);
                                $rid = intval($reset_row['id']);

                                $upd_user = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                                if (!$upd_user) {
                                    throw new Exception('Lỗi chuẩn bị cập nhật mật khẩu.');
                                }
                                $upd_user->bind_param("si", $hashed_password, $uid);
                                if (!$upd_user->execute()) {
                                    $upd_user->close();
                                    throw new Exception('Không thể cập nhật mật khẩu.');
                                }
                                $upd_user->close();

                                $mark_used = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
                                if ($mark_used) {
                                    $mark_used->bind_param("i", $rid);
                                    $mark_used->execute();
                                    $mark_used->close();
                                }

                                // Vô hiệu hóa các token cũ khác của user này
                                $inv_other = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL AND id != ?");
                                if ($inv_other) {
                                    $inv_other->bind_param("ii", $uid, $rid);
                                    $inv_other->execute();
                                    $inv_other->close();
                                }

                                $conn->commit();
                                clear_rate_limit($conn, 'pwd_reset_verify', $client_ip);

                                echo '<div class="alert alert-dismissible fade show mb-4" style="background: rgba(16,185,129,0.15); border: 1px solid #10b981;">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i><strong>Thành công!</strong> Đổi mật khẩu thành công. Bạn có thể đăng nhập lại ngay bây giờ.
                                      </div>';
                                echo '<div class="text-center"><a href="login.php" class="btn btn-gaming-primary w-100 py-2">ĐI ĐẾN ĐĂNG NHẬP</a></div>';
                                $hide_form = true;
                            }
                        } catch (Throwable $e) {
                            $conn->rollback();
                            error_log('[ANPLAY FORGOT PASSWORD RESET] ' . $e->getMessage());
                            echo '<div class="alert alert-danger mb-4">Lỗi cập nhật hệ thống, vui lòng thử lại!</div>';
                        }
                    }
                }

                // BƯỚC 1: Yêu cầu tạo token khôi phục mật khẩu (chống email enumeration)
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['btn_check_email']) || isset($_POST['btn_request_reset']))) {
                    $post_csrf = $_POST['csrf_token'] ?? '';
                    $email = trim($_POST['email'] ?? '');
                    $rl_req_id = $client_ip . '|' . strtolower($email);
                    $rl_req = check_rate_limit($conn, 'pwd_reset_req', $rl_req_id, 5, 900);
                    $rl_req_allowed = is_array($rl_req) ? !empty($rl_req['allowed']) : (bool)$rl_req;

                    if (!verify_csrf_token($post_csrf)) {
                        echo '<div class="alert alert-danger mb-4">Phiên bảo mật không hợp lệ. Vui lòng thử lại!</div>';
                    } elseif (!$rl_req_allowed) {
                        echo '<div class="alert alert-danger mb-4">Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau ít phút.</div>';
                    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        echo '<div class="alert alert-warning mb-4">Vui lòng nhập địa chỉ email hợp lệ.</div>';
                    } else {
                        record_failed_attempt($conn, 'pwd_reset_req', $rl_req_id, 5, 900);
                        $mail_failed_in_prod = false;
                        $check_stmt = $conn->prepare("SELECT user_id, username, email FROM users WHERE email = ? LIMIT 1");
                        if ($check_stmt) {
                            $check_stmt->bind_param("s", $email);
                            $check_stmt->execute();
                            $user_row = $check_stmt->get_result()->fetch_assoc();
                            $check_stmt->close();

                            if ($user_row) {
                                $raw_token = bin2hex(random_bytes(32));
                                $token_hash = hash('sha256', $raw_token);
                                $uid = intval($user_row['user_id']);

                                // Hủy token chưa dùng trước đó
                                $inv = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
                                if ($inv) {
                                    $inv->bind_param("i", $uid);
                                    $inv->execute();
                                    $inv->close();
                                }

                                $ins = $conn->prepare("INSERT INTO password_resets (user_id, email, token_hash, expires_at, request_ip, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?, NOW())");
                                if ($ins) {
                                    $ins->bind_param("isss", $uid, $user_row['email'], $token_hash, $client_ip);
                                    $ins->execute();
                                    $ins->close();
                                }

                                // Chỉ ghi log non-web-root khi chạy môi trường local/staging để phục vụ kiểm thử
                                if (is_local_environment()) {
                                    $log_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anplay_reset_tokens.log';
                                    @file_put_contents($log_path, date('Y-m-d H:i:s') . " | {$user_row['email']} | token={$raw_token}\n", FILE_APPEND);
                                    $_SESSION['_staging_last_reset_token'] = $raw_token;
                                }

                                $smtp_cfg = get_anplay_smtp_config();
                                $smtp_configured = ($smtp_cfg['host'] !== '' && strpos($smtp_cfg['host'], 'CHANGE_ME') === false);
                                if ($smtp_configured) {
                                    $mail_res = send_password_reset_email($user_row['email'], $raw_token);
                                    if (empty($mail_res['success'])) {
                                        error_log('[ANPLAY SMTP RESET] ' . ($mail_res['error'] ?? 'Unknown SMTP error'));
                                        if (!is_local_environment()) {
                                            $mail_failed_in_prod = true;
                                        }
                                    }
                                } elseif (!is_local_environment()) {
                                    error_log('[ANPLAY SMTP RESET] SMTP is not configured on production.');
                                    $mail_failed_in_prod = true;
                                }
                            }
                        }

                        if ($mail_failed_in_prod) {
                            echo '<div class="alert alert-warning mb-4">
                                    <i class="bi bi-exclamation-triangle me-1"></i> Dịch vụ gửi email khôi phục mật khẩu tạm thời không khả dụng. Vui lòng liên hệ bộ phận hỗ trợ ANPLAY để được trợ giúp.
                                  </div>';
                        } else {
                            // Luôn trả thông báo trung lập để không lộ email nào đã đăng ký
                            echo '<div class="alert mb-4" style="background: rgba(6,182,212,0.15); border: 1px solid #06b6d4;">
                                    <i class="bi bi-envelope-check me-1 text-info"></i> Nếu địa chỉ email tồn tại trong hệ thống ANPLAY, hướng dẫn và liên kết đặt lại mật khẩu (hiệu lực 15 phút) đã được gửi tới hộp thư của bạn.
                                  </div>';
                        }
                    }
                }

                // Hiển thị form nhập mật khẩu mới nếu có token hợp lệ trên URL
                if (!$hide_form && !empty($url_token)) {
                    $token_valid_ui = false;
                    if (preg_match('/^[a-f0-9]{64}$/i', $url_token)) {
                        $th = hash('sha256', $url_token);
                        $vt = $conn->prepare("SELECT id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
                        if ($vt) {
                            $vt->bind_param("s", $th);
                            $vt->execute();
                            $token_valid_ui = ($vt->get_result()->num_rows > 0);
                            $vt->close();
                        }
                    }

                    if ($token_valid_ui) {
                        ?>
                        <form method="POST" action="forgot_password.php">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="reset_token" value="<?php echo htmlspecialchars($url_token); ?>">
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Mật khẩu mới (tối thiểu 8 ký tự)</label>
                                <div class="input-group">
                                    <span class="input-group-text" style="background: var(--surface-secondary); border-color: var(--border-default); color: var(--text-muted);"><i class="bi bi-key"></i></span>
                                    <input type="password" name="new_password" id="reset_pass_input" class="form-control form-control-gaming" required minlength="8" placeholder="Nhập mật khẩu mới">
                                    <button class="btn btn-gaming-secondary" type="button" onclick="togglePass('reset_pass_input', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small fw-bold">Xác nhận mật khẩu mới</label>
                                <div class="input-group">
                                    <span class="input-group-text" style="background: var(--surface-secondary); border-color: var(--border-default); color: var(--text-muted);"><i class="bi bi-key-fill"></i></span>
                                    <input type="password" name="confirm_password" id="reset_confirm_input" class="form-control form-control-gaming" required minlength="8" placeholder="Nhập lại mật khẩu mới">
                                    <button class="btn btn-gaming-secondary" type="button" onclick="togglePass('reset_confirm_input', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="submit" name="btn_reset" class="btn btn-gaming-primary w-100 py-3 fw-bold">
                                <i class="bi bi-check2-circle me-1"></i> XÁC NHẬN ĐỔI MẬT KHẨU
                            </button>
                        </form>
                        <?php
                    } else {
                        echo '<div class="alert alert-danger mb-4">Liên kết khôi phục mật khẩu không hợp lệ hoặc đã hết hạn. Vui lòng gửi yêu cầu mới.</div>';
                        $url_token = '';
                    }
                }

                // Form Bước 1 (Nhập Email)
                if (!$hide_form && empty($url_token)) {
                ?>
                    <form method="POST" action="forgot_password.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="mb-4">
                            <label class="form-label small fw-bold">Email tài khoản</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background: var(--surface-secondary); border-color: var(--border-default); color: var(--text-muted);"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" class="form-control form-control-gaming" required placeholder="name@example.com">
                            </div>
                            <small class="text-secondary d-block mt-2">Nhập địa chỉ email đã đăng ký tài khoản để nhận liên kết khôi phục.</small>
                        </div>
                        <button type="submit" name="btn_request_reset" class="btn btn-gaming-primary w-100 py-3 fw-bold">
                            <i class="bi bi-send me-1"></i> GỬI YÊU CẦU KHÔI PHỤC
                        </button>
                    </form>
                <?php } ?>
                
                <div class="text-center mt-4 pt-3 border-top border-secondary border-opacity-25">
                    <a href="login.php" class="text-decoration-none small" style="color: var(--accent-cyan);">
                        <i class="bi bi-arrow-left me-1"></i> Quay lại Đăng nhập
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
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
