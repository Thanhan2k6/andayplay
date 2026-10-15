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
    <button class="anplay-chat-toggle" id="anplay-chat-toggle" type="button" onclick="anplayToggleChat(event)" aria-label="Mở trợ lý AI ANPLAY">
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
                        <span class="anplay-chat-badge">AI 3.5 Lite</span>
                    </h4>
                    <p class="anplay-chat-subtitle">
                        <span class="anplay-status-pill"></span> Trợ lý tư vấn game bản quyền
                    </p>
                </div>
            </div>
            <div class="anplay-chat-actions">
                <button class="anplay-header-btn" id="anplay-chat-clear" type="button" onclick="anplayClearChat(event)" title="Làm mới đoạn hội thoại">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
                <button class="anplay-header-btn close-btn" id="anplay-chat-close" type="button" onclick="anplayToggleChat(event)" title="Đóng cửa sổ chat">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>

        <!-- Gợi ý câu hỏi nhanh (Quick Suggestion Chips) -->
        <div class="anplay-chat-suggestions" id="anplay-chat-suggestions">
            <button class="anplay-suggestion-chip" type="button" data-question="Shop có những game gì đang cho thuê?" onclick="anplayQuickAsk(this)">
                <i class="bi bi-joystick text-primary"></i> Game cho thuê?
            </button>
            <button class="anplay-suggestion-chip" type="button" data-question="Hướng dẫn quy trình thuê tài khoản game" onclick="anplayQuickAsk(this)">
                <i class="bi bi-question-circle text-info"></i> Cách thuê game?
            </button>
            <button class="anplay-suggestion-chip" type="button" data-question="Thuê game tại ANPLAY STORE có uy tín không?" onclick="anplayQuickAsk(this)">
                <i class="bi bi-shield-check text-success"></i> Có uy tín không?
            </button>
            <button class="anplay-suggestion-chip" type="button" data-question="Sau khi thuê thì kiểm tra tài khoản ở đâu?" onclick="anplayQuickAsk(this)">
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
        <form class="anplay-chat-input-area" id="anplay-chat-form" onsubmit="anplaySubmitChat(event); return false;">
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

<!-- =========================================================
     INLINE CHATBOT CONTROLLER (Cam kết chạy 100% không delay)
     ========================================================= -->
<script>
window.ANPLAY_CHAT_CSRF = "<?php echo htmlspecialchars($_SESSION['chat_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>";

(function() {
    let isSending = false;

    // Toggle Chat Window
    window.anplayToggleChat = function(e) {
        if (e && e.preventDefault) {
            e.preventDefault();
            e.stopPropagation();
        }
        const chatBox = document.getElementById('anplay-chat-box');
        const toggleBtn = document.getElementById('anplay-chat-toggle');
        const inputField = document.getElementById('anplay-chat-input');
        if (!chatBox) return;

        const isOpen = chatBox.classList.toggle('active');
        if (toggleBtn) {
            if (isOpen) {
                toggleBtn.classList.add('is-open');
            } else {
                toggleBtn.classList.remove('is-open');
            }
        }

        if (isOpen) {
            setTimeout(function() {
                if (inputField) inputField.focus();
                anplayScrollToBottom();
            }, 100);
        }
    };

    // Scroll to bottom
    window.anplayScrollToBottom = function() {
        const messagesArea = document.getElementById('anplay-chat-messages');
        if (!messagesArea) return;
        messagesArea.scrollTo({
            top: messagesArea.scrollHeight,
            behavior: 'smooth'
        });
    };

    // Format current time
    function getCurrentTime() {
        const now = new Date();
        const h = String(now.getHours()).padStart(2, '0');
        const m = String(now.getMinutes()).padStart(2, '0');
        return h + ':' + m;
    }

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Markdown simple formatter
    function formatMessage(text) {
        let safe = escapeHtml(text);
        safe = safe.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        safe = safe.replace(/\*(.*?)\*/g, '<em>$1</em>');
        safe = safe.replace(/\n/g, '<br>');
        return safe;
    }

    // Append Message Row
    function appendMessage(role, text, isError) {
        const messagesArea = document.getElementById('anplay-chat-messages');
        const typingIndicator = document.getElementById('anplay-typing');
        if (!messagesArea) return;

        const row = document.createElement('div');
        row.className = 'anplay-msg-row ' + role;
        const timeStr = getCurrentTime();

        if (role === 'bot') {
            row.innerHTML = 
                '<div class="anplay-msg-avatar"><i class="bi bi-robot"></i></div>' +
                '<div class="anplay-msg-content">' +
                    '<div class="anplay-msg bot ' + (isError ? 'error' : '') + '">' +
                        formatMessage(text) +
                    '</div>' +
                    '<span class="anplay-msg-time">' + timeStr + '</span>' +
                '</div>';
        } else {
            row.innerHTML = 
                '<div class="anplay-msg-content">' +
                    '<div class="anplay-msg user">' +
                        formatMessage(text) +
                    '</div>' +
                    '<span class="anplay-msg-time">' + timeStr + '</span>' +
                '</div>';
        }

        if (typingIndicator && typingIndicator.parentNode === messagesArea) {
            messagesArea.insertBefore(row, typingIndicator);
        } else {
            messagesArea.appendChild(row);
        }

        anplayScrollToBottom();
    }

    // Clear Chat
    window.anplayClearChat = function(e) {
        if (e && e.preventDefault) {
            e.preventDefault();
            e.stopPropagation();
        }
        const messagesArea = document.getElementById('anplay-chat-messages');
        if (!messagesArea) return;

        const rows = messagesArea.querySelectorAll('.anplay-msg-row');
        rows.forEach(function(r) { r.remove(); });

        appendMessage('bot', 'Đoạn hội thoại đã được đặt lại! Bạn cần tìm tựa game nào hoặc muốn hỗ trợ thuê tài khoản cứ nhắn cho tôi nhé!');
    };

    // Quick Question Chip Click
    window.anplayQuickAsk = function(btn) {
        if (!btn || isSending) return;
        const question = btn.getAttribute('data-question');
        const inputField = document.getElementById('anplay-chat-input');
        if (question && inputField) {
            inputField.value = question;
            anplaySendMessage();
        }
    };

    // Submit form handler
    window.anplaySubmitChat = function(e) {
        if (e && e.preventDefault) e.preventDefault();
        anplaySendMessage();
    };

    // Send Message core logic
    async function anplaySendMessage() {
        const inputField = document.getElementById('anplay-chat-input');
        const sendBtn = document.getElementById('anplay-chat-send');
        const typingIndicator = document.getElementById('anplay-typing');
        if (!inputField || isSending) return;

        const message = inputField.value.trim();
        if (!message) return;

        isSending = true;
        appendMessage('user', message);
        inputField.value = '';
        inputField.disabled = true;
        if (sendBtn) sendBtn.disabled = true;

        if (typingIndicator) {
            typingIndicator.style.display = 'flex';
            anplayScrollToBottom();
        }

        try {
            // Adaptive endpoint URL resolution
            let apiEndpoint = '/api/chatbot.php';
            if (window.location.pathname.includes('/quan_ly_thu_vien_game/')) {
                apiEndpoint = '/quan_ly_thu_vien_game/api/chatbot.php';
            }

            const response = await fetch(apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    message: message,
                    csrf_token: window.ANPLAY_CHAT_CSRF
                })
            });

            if (typingIndicator) typingIndicator.style.display = 'none';

            let data = null;
            try {
                data = await response.json();
            } catch (parseErr) {
                data = null;
            }

            if (response.ok && data && data.success) {
                appendMessage('bot', data.reply);
            } else if (response.status === 429) {
                appendMessage('bot', 'Bạn gửi tin nhắn quá nhanh! Vui lòng chờ 1 phút rồi tiếp tục.', true);
            } else if (response.status === 403) {
                appendMessage('bot', 'Phiên làm việc bảo mật đã hết hạn. Vui lòng tải lại trang (F5) để tiếp tục!', true);
            } else if (response.status === 503) {
                appendMessage('bot', (data && data.error) ? data.error : 'Hệ thống AI đang được bảo trì. Vui lòng thử lại sau ít phút!', true);
            } else {
                const errMsg = (data && data.error) ? data.error : 'Đã có sự cố khi kết nối tới trợ lý AI. Vui lòng thử lại!';
                appendMessage('bot', errMsg, true);
            }
        } catch (netErr) {
            if (typingIndicator) typingIndicator.style.display = 'none';
            appendMessage('bot', 'Không thể kết nối đến máy chủ. Vui lòng kiểm tra lại đường truyền mạng hoặc thử lại sau!', true);
        } finally {
            isSending = false;
            if (inputField) {
                inputField.disabled = false;
                inputField.focus();
            }
            if (sendBtn) sendBtn.disabled = false;
            anplayScrollToBottom();
        }
    }

    // Setup Keyboard listener
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const chatBox = document.getElementById('anplay-chat-box');
            if (chatBox && chatBox.classList.contains('active')) {
                window.anplayToggleChat();
            }
        }
    });

    // Ensure Enter key sends message
    function bindInputKey() {
        const inputField = document.getElementById('anplay-chat-input');
        if (inputField) {
            inputField.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    anplaySendMessage();
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindInputKey);
    } else {
        bindInputKey();
    }
})();
</script>

<script src="assets/js/chatbot.js?v=<?php echo time(); ?>"></script>
</body>
</html>