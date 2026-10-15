<?php 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include 'includes/header.php'; 
?>

<div class="container py-5 my-4">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="p-4 p-md-5 rounded-4 shadow-lg text-white" style="background: var(--bg-card); border: 1px solid var(--border-color); position: relative; overflow: hidden;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--primary), var(--accent-cyan));"></div>
                
                <div class="text-center mb-4">
                    <div class="d-inline-flex p-3 rounded-4 mb-3" style="background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.3);">
                        <i class="bi bi-shield-lock-fill fs-2" style="color: var(--primary);"></i>
                    </div>
                    <h3 class="fw-bold mb-1">Khôi Phục Mật Khẩu</h3>
                    <p class="text-secondary small">Thiết lập lại mật khẩu an toàn cho tài khoản ANPLAY của bạn</p>
                </div>
                
                <?php
                // BƯỚC 2: Xử lý cập nhật mật khẩu mới vào Database
                if(isset($_POST['btn_reset'])) {
                    $email = trim($_POST['reset_email'] ?? '');
                    $new_password = $_POST['new_password'] ?? '';
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                    $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
                    $update_stmt->bind_param("ss", $hashed_password, $email);
                    if($update_stmt->execute()) {
                        echo '<div class="alert alert-dismissible fade show text-white mb-4" style="background: rgba(16,185,129,0.15); border: 1px solid #10b981;">
                                <i class="bi bi-check-circle-fill text-success me-2"></i><strong>Thành công!</strong> Đổi mật khẩu thành công. Bạn có thể đăng nhập lại ngay bây giờ.
                              </div>';
                        echo '<div class="text-center"><a href="login.php" class="btn btn-gaming-primary w-100 py-2">ĐI ĐẾN ĐĂNG NHẬP</a></div>';
                        $hide_form = true;
                    } else {
                        echo '<div class="alert alert-danger mb-4">Lỗi cập nhật hệ thống, vui lòng thử lại!</div>';
                    }
                    $update_stmt->close();
                }

                // BƯỚC 1: Xử lý kiểm tra Email có trong hệ thống không
                if(isset($_POST['btn_check_email'])) {
                    $email = trim($_POST['email'] ?? '');
                    $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                    $check_stmt->bind_param("s", $email);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();

                    if($result && $result->num_rows > 0) {
                        // Email đúng -> Hiện form cho phép đặt mật khẩu mới
                        ?>
                        <div class="alert text-white mb-4" style="background: rgba(6,182,212,0.15); border: 1px solid #06b6d4;">
                            <i class="bi bi-info-circle me-1 text-info"></i> Xác thực tài khoản thành công! Hãy nhập mật khẩu mới bên dưới.
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="reset_email" value="<?php echo htmlspecialchars($email); ?>">
                            <div class="mb-4">
                                <label class="form-label text-light small fw-bold">Mật khẩu mới</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-key"></i></span>
                                    <input type="password" name="new_password" id="reset_pass_input" class="form-control form-control-gaming" required placeholder="Nhập mật khẩu mới">
                                    <button class="btn btn-gaming-secondary" type="button" onclick="togglePass('reset_pass_input', this)">
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
                        echo '<div class="alert alert-danger mb-4">Địa chỉ Email này không tồn tại trong hệ thống ANPLAY!</div>';
                    }
                    $check_stmt->close();
                } 
                
                // NẾU CHƯA LÀM GÌ, HIỆN FORM BƯỚC 1 (Nhập Email)
                if(!isset($_POST['btn_check_email']) && !isset($hide_form)) {
                ?>
                    <form method="POST" action="">
                        <div class="mb-4">
                            <label class="form-label text-light small fw-bold">Email tài khoản</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" class="form-control form-control-gaming" required placeholder="name@example.com">
                            </div>
                            <small class="text-secondary d-block mt-2">Nhập đúng địa chỉ email đã đăng ký tài khoản để xác minh.</small>
                        </div>
                        <button type="submit" name="btn_check_email" class="btn btn-gaming-primary w-100 py-3 fw-bold">
                            <i class="bi bi-search me-1"></i> TÌM TÀI KHOẢN
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