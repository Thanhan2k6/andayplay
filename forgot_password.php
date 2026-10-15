<?php include 'includes/header.php'; ?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow" style="background-color: #202d39; border: none;">
                <div class="card-body p-5 text-white">
                    <h3 class="text-center mb-4">Khôi Phục Mật Khẩu</h3>
                    
                    <?php
                    // BƯỚC 2: Xử lý cập nhật mật khẩu mới vào Database
                    if(isset($_POST['btn_reset'])) {
                        $email = trim($_POST['reset_email'] ?? '');
                        $new_password = $_POST['new_password'] ?? '';
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
                        $update_stmt->bind_param("ss", $hashed_password, $email);
                        if($update_stmt->execute()) {
                            echo '<div class="alert alert-success">Đổi mật khẩu thành công! Hãy đăng nhập lại bằng mật khẩu mới.</div>';
                            $hide_form = true;
                        } else {
                            echo '<div class="alert alert-danger">Lỗi cập nhật hệ thống!</div>';
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
                            <div class="alert alert-info">Tài khoản hợp lệ. Vui lòng thiết lập mật khẩu mới.</div>
                            <form method="POST" action="">
                                <input type="hidden" name="reset_email" value="<?php echo htmlspecialchars($email); ?>">
                                <div class="mb-3">
                                    <label class="form-label">Mật khẩu mới</label>
                                    <input type="password" name="new_password" class="form-control" required placeholder="Nhập mật khẩu mới">
                                </div>
                                <button type="submit" name="btn_reset" class="btn btn-borrow">XÁC NHẬN ĐỔI MẬT KHẨU</button>
                            </form>
                            <?php
                        } else {
                            echo '<div class="alert alert-danger">Email này không tồn tại trong hệ thống!</div>';
                        }
                        $check_stmt->close();
                    } 
                    
                    // NẾU CHƯA LÀM GÌ, HIỆN FORM BƯỚC 1 (Nhập Email)
                    if(!isset($_POST['btn_check_email']) && !isset($hide_form)) {
                    ?>
                        <p class="text-center text-muted small">Nhập địa chỉ Email bạn đã dùng để đăng ký. Hệ thống sẽ cho phép bạn thiết lập lại mật khẩu.</p>
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Email của bạn</label>
                                <input type="email" name="email" class="form-control" required placeholder="name@example.com">
                            </div>
                            <button type="submit" name="btn_check_email" class="btn btn-borrow">TÌM TÀI KHOẢN</button>
                        </form>
                    <?php } ?>
                    
                    <div class="text-center mt-4">
                        <a href="login.php" class="text-info text-decoration-none">&lt; Trở về Đăng nhập</a>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>