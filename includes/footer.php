    <!-- FOOTER CAO CẤP ANPLAY STORE -->
    <footer class="gaming-footer">
        <div class="row g-4">
            <!-- Cột 1: Thông tin thương hiệu -->
            <div class="col-lg-4 col-md-6">
                <a href="index.php" class="footer-brand text-decoration-none">
                    <span class="brand-text">ANPLAY STORE</span>
                </a>
                <p class="footer-desc mt-2">
                    Nền tảng cho thuê tài khoản game bản quyền hàng đầu Việt Nam. Trải nghiệm game đỉnh cao bản quyền Steam, EA, Epic Games với chi phí tiết kiệm đến 90%.
                </p>
                <div class="d-flex align-items-center gap-2 mt-3">
                    <span class="badge bg-dark border border-secondary text-light px-3 py-2">
                        <i class="bi bi-shield-lock-fill text-success me-1"></i> Mã hóa AES-256
                    </span>
                    <span class="badge bg-dark border border-secondary text-light px-3 py-2">
                        <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Cấp tài khoản 24/7
                    </span>
                </div>
            </div>

            <!-- Cột 2: Danh mục nhanh -->
            <div class="col-lg-2 col-md-6 col-6">
                <div class="footer-heading">Khám Phá</div>
                <a href="index.php" class="footer-link">Cửa hàng trò chơi</a>
                <a href="index.php?platform=PC" class="footer-link">Game PC bom tấn</a>
                <a href="index.php?platform=Mobile" class="footer-link">Game Mobile Hot</a>
                <a href="my_library.php" class="footer-link">Thư viện của tôi</a>
            </div>

            <!-- Cột 3: Chính sách & Cam kết -->
            <div class="col-lg-3 col-md-6 col-6">
                <div class="footer-heading">Chính Sách & Cam Kết</div>
                <div class="footer-link"><i class="bi bi-check2 text-success me-1"></i> Bảo hành 100% thời gian thuê</div>
                <div class="footer-link"><i class="bi bi-check2 text-success me-1"></i> 1 đổi 1 nếu tài khoản lỗi</div>
                <div class="footer-link"><i class="bi bi-check2 text-success me-1"></i> Không lưu mã PIN/OTP cá nhân</div>
                <div class="footer-link"><i class="bi bi-check2 text-success me-1"></i> Hỗ trợ kỹ thuật qua Telegram</div>
            </div>

            <!-- Cột 4: Phương thức thanh toán -->
            <div class="col-lg-3 col-md-6">
                <div class="footer-heading">Thanh Toán An Toàn</div>
                <p class="text-secondary small mb-3">Hỗ trợ quét mã QR tự động xác thực qua mọi ngân hàng và ví điện tử Việt Nam.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-secondary bg-opacity-25 border border-secondary text-white px-2 py-2 fw-normal">VietQR 24/7</span>
                    <span class="badge bg-secondary bg-opacity-25 border border-secondary text-white px-2 py-2 fw-normal">Napas 247</span>
                    <span class="badge bg-secondary bg-opacity-25 border border-secondary text-white px-2 py-2 fw-normal">Momo / VNPay</span>
                </div>
            </div>
        </div>

        <!-- Dòng bản quyền đáy trang -->
        <div class="footer-bottom">
            <div>
                © <?php echo date('Y'); ?> <strong>ANPLAY STORE</strong>. Hệ thống quản lý dịch vụ cho thuê tài khoản game.
            </div>
            <div class="d-flex gap-3">
                <span class="text-secondary">Bảo mật thông tin</span>
                <span class="text-secondary">Điều khoản dịch vụ</span>
                <span class="text-success"><i class="bi bi-circle-fill me-1" style="font-size: 8px;"></i> Máy chủ Hoạt động Tốt</span>
            </div>
        </div>
    </footer>

</div> <!-- Đóng thẻ .content-scroll mở ở header -->
</div> <!-- Đóng thẻ .main-wrapper mở ở header -->

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php
// Khởi tạo phiên và mã CSRF token cho chatbot
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['chat_csrf_token'])) {
    $_SESSION['chat_csrf_token'] = bin2hex(random_bytes(32));
}
?>
<meta name="chat-csrf" content="<?php echo $_SESSION['chat_csrf_token']; ?>">
<link rel="stylesheet" href="css/chatbot.css">

<!-- Nút mở Chatbot và Khung Chat -->
<div class="anplay-chatbot-wrapper">
    <div class="anplay-chat-toggle" id="anplay-chat-toggle" title="Chat với AI">
        💬
    </div>

    <div class="anplay-chat-box" id="anplay-chat-box">
        <div class="anplay-chat-header">
            <div class="anplay-chat-title">
                <span class="anplay-status-dot"></span>
                <span>ANPLAY ASSISTANT</span>
            </div>
            <div class="anplay-chat-actions">
                <button id="anplay-chat-clear" title="Làm mới">🔄</button>
                <button id="anplay-chat-close" title="Đóng">✕</button>
            </div>
        </div>

        <div class="anplay-chat-messages" id="anplay-chat-messages">
            <div class="anplay-msg bot">
                Xin chào! Tôi là trợ lý AI của ANPLAY STORE. Bạn cần tìm game gì hay hỗ trợ thuê tài khoản nào không?
            </div>
            <div class="anplay-typing-indicator" id="anplay-typing" style="display:none;">
                <span></span><span></span><span></span>
            </div>
        </div>

        <div class="anplay-chat-input-area">
            <input type="text" id="anplay-chat-input" placeholder="Hỏi gì đó..." autocomplete="off" maxlength="600">
            <button id="anplay-chat-send">Gửi</button>
        </div>
    </div>
</div>

<script src="assets/js/chatbot.js"></script>
</body>
</html>