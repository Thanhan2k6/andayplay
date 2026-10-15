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

<!-- =========================================================
     ANPLAY ASSISTANT — GAMING AI CHATBOT WIDGET
     ========================================================= -->
<div class="anplay-chatbot-wrapper" id="anplayChatbotWrapper">
    <!-- Nút mở Chatbot nổi góc dưới phải -->
    <button class="anplay-chat-toggle" id="anplay-chat-toggle" type="button" aria-label="Mở trợ lý AI ANPLAY">
        <span class="anplay-chat-toggle-icon">
            <i class="bi bi-robot"></i>
        </span>
        <span class="anplay-chat-ping"></span>
        <span class="anplay-chat-tooltip">ANPLAY ASSISTANT • Hỏi đáp AI</span>
    </button>

    <!-- Hộp thoại Chatbox -->
    <div class="anplay-chat-box" id="anplay-chat-box" role="dialog" aria-label="Khung chat trợ lý AI">
        <!-- Header -->
        <div class="anplay-chat-header">
            <div class="anplay-chat-title-group">
                <div class="anplay-chat-avatar">
                    <i class="bi bi-robot"></i>
                    <span class="anplay-chat-avatar-status" title="AI sẵn sàng 24/7"></span>
                </div>
                <div class="anplay-chat-title-text">
                    <h4>
                        ANPLAY ASSISTANT 
                        <span class="anplay-chat-badge">AI 3.8</span>
                    </h4>
                    <p class="anplay-chat-subtitle">
                        <span class="anplay-status-pill"></span> Trợ lý tư vấn game bản quyền
                    </p>
                </div>
            </div>
            <div class="anplay-chat-actions">
                <button class="anplay-header-btn" id="anplay-chat-clear" type="button" title="Làm mới đoạn hội thoại">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
                <button class="anplay-header-btn close-btn" id="anplay-chat-close" type="button" title="Đóng cửa sổ chat">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>

        <!-- Gợi ý câu hỏi nhanh (Quick Suggestion Chips) -->
        <div class="anplay-chat-suggestions" id="anplay-chat-suggestions">
            <button class="anplay-suggestion-chip" type="button" data-question="Shop có những game gì đang cho thuê?">
                <i class="bi bi-joystick text-primary"></i> Game cho thuê?
            </button>
            <button class="anplay-suggestion-chip" type="button" data-question="Hướng dẫn quy trình thuê tài khoản game">
                <i class="bi bi-question-circle text-info"></i> Cách thuê game?
            </button>
            <button class="anplay-suggestion-chip" type="button" data-question="Thuê game tại ANPLAY STORE có uy tín không?">
                <i class="bi bi-shield-check text-success"></i> Có uy tín không?
            </button>
            <button class="anplay-suggestion-chip" type="button" data-question="Sau khi thuê thì kiểm tra tài khoản ở đâu?">
                <i class="bi bi-collection-play text-warning"></i> Xem tài khoản đã thuê?
            </button>
        </div>

        <!-- Vùng hiển thị tin nhắn -->
        <div class="anplay-chat-messages" id="anplay-chat-messages">
            <!-- Tin nhắn chào mở đầu -->
            <div class="anplay-msg-row bot">
                <div class="anplay-msg-avatar"><i class="bi bi-robot"></i></div>
                <div class="anplay-msg-content">
                    <div class="anplay-msg bot">
                        Xin chào game thủ! 👋 Tôi là <strong>ANPLAY ASSISTANT</strong>. 
                        Tôi có thể giúp bạn tìm kiếm game bản quyền, báo giá thuê và hướng dẫn trải nghiệm kho game AAA của shop!
                    </div>
                    <span class="anplay-msg-time"><?php echo date('H:i'); ?></span>
                </div>
            </div>

            <!-- Typing indicator -->
            <div class="anplay-typing-indicator" id="anplay-typing" style="display:none;">
                <span></span><span></span><span></span>
            </div>
        </div>

        <!-- Khung nhập liệu & Nút gửi -->
        <form class="anplay-chat-input-area" id="anplay-chat-form" onsubmit="return false;">
            <div class="anplay-input-wrapper">
                <input type="text" id="anplay-chat-input" class="anplay-chat-input" placeholder="Nhập câu hỏi của bạn (Enter để gửi)..." autocomplete="off" maxlength="600" required>
            </div>
            <button type="submit" id="anplay-chat-send" class="anplay-chat-send-btn" title="Gửi tin nhắn">
                <i class="bi bi-send-fill"></i>
            </button>
        </form>

        <!-- Footer lưu ý -->
        <div class="anplay-chat-footer-note">
            <span>Powered by Google Gemini AI • Câu trả lời mang tính chất tham khảo</span>
        </div>
    </div>
</div>

<script src="assets/js/chatbot.js?v=<?php echo time(); ?>"></script>
</body>
</html>