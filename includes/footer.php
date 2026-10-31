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
                        <span class="anplay-chat-badge" style="background: rgba(99, 102, 241, 0.2); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.3);">Trợ lý ảo AI</span>
                    </h4>
                    <p class="anplay-chat-subtitle">
                        <span class="anplay-status-pill"></span> Tư vấn & Hướng dẫn tự động 24/7
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
            <a href="support.php" class="anplay-suggestion-chip text-decoration-none" style="background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.35); color: #f87171;">
                <i class="bi bi-headset text-danger"></i> Gặp nhân viên hỗ trợ
            </a>
            <button class="anplay-suggestion-chip" type="button" data-question="Tôi có thể lưu game (Save Game) và chơi tiếp không?" onclick="anplayQuickAsk(this)">
                <i class="bi bi-cloud-check text-info"></i> Lưu Game & Chơi tiếp?
            </button>
            <button class="anplay-suggestion-chip" type="button" data-question="Tôi có được phép đổi mật khẩu tài khoản game không?" onclick="anplayQuickAsk(this)">
                <i class="bi bi-shield-lock text-warning"></i> Quyền đổi mật khẩu?
            </button>
            <button class="anplay-suggestion-chip" type="button" data-question="Shop có những game gì đang cho thuê?" onclick="anplayQuickAsk(this)">
                <i class="bi bi-joystick text-primary"></i> Game cho thuê?
            </button>
            <button class="anplay-suggestion-chip" type="button" data-question="Hướng dẫn quy trình thuê tài khoản game" onclick="anplayQuickAsk(this)">
                <i class="bi bi-question-circle text-info"></i> Cách thuê game?
            </button>
        </div>

        <!-- Vùng hiển thị tin nhắn -->
        <div class="anplay-chat-messages" id="anplay-chat-messages">
            <!-- Tin nhắn chào mở đầu -->
            <div class="anplay-msg-row bot">
                <div class="anplay-msg-avatar"><i class="bi bi-robot"></i></div>
                <div class="anplay-msg-content">
                    <div class="anplay-msg bot">
                        Xin chào bạn! 👋 Tôi là <strong>Trợ lý ảo tự động của ANPLAY STORE</strong>. 
                        Tôi có thể giúp bạn tra cứu danh mục game, báo giá gói thuê từ <strong>5.000đ</strong> và hướng dẫn các bước thuê tài khoản.
                        <div class="mt-2 pt-2 border-top border-secondary border-opacity-25" style="font-size:0.8rem;">
                            👨‍💻 <em>Nếu cần hỗ trợ kỹ thuật, cấp đổi tài khoản lỗi hoặc khiếu nại, vui lòng bấm nút:</em><br>
                            <a href="support.php" class="btn btn-sm btn-outline-danger py-1 px-2 mt-1 text-decoration-none" style="font-size:0.75rem;">
                                <i class="bi bi-headset me-1"></i> Gặp nhân viên hỗ trợ (Ticket)
                            </a>
                        </div>
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
    let chatHistory = [];
    let lastFailedMessage = '';

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
        safe = safe.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" class="btn btn-sm btn-gaming-primary py-1 px-2 text-white text-decoration-none mt-1 d-inline-block" style="font-size:0.75rem;">$1</a>');
        safe = safe.replace(/\n/g, '<br>');
        return safe;
    }

    // Append Message Row
    function appendMessage(role, text, isError, allowRetry) {
        const messagesArea = document.getElementById('anplay-chat-messages');
        const typingIndicator = document.getElementById('anplay-typing');
        if (!messagesArea) return;

        const row = document.createElement('div');
        row.className = 'anplay-msg-row ' + role;
        const timeStr = getCurrentTime();

        let retryHtml = '';
        if (isError && allowRetry) {
            retryHtml = '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-light py-0 px-2" style="font-size:0.75rem;" onclick="anplayRetryLastMessage()"><i class="bi bi-arrow-clockwise me-1"></i>Thử lại</button></div>';
        }

        if (role === 'bot') {
            row.innerHTML = 
                '<div class="anplay-msg-avatar"><i class="bi bi-robot"></i></div>' +
                '<div class="anplay-msg-content">' +
                    '<div class="anplay-msg bot ' + (isError ? 'error' : '') + '">' +
                        formatMessage(text) + retryHtml +
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

        chatHistory = [];
        lastFailedMessage = '';

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

    // Retry last failed message
    window.anplayRetryLastMessage = function() {
        if (lastFailedMessage && !isSending) {
            const inputField = document.getElementById('anplay-chat-input');
            if (inputField) {
                inputField.value = lastFailedMessage;
                anplaySendMessage();
            }
        }
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
        lastFailedMessage = message;
        appendMessage('user', message);
        inputField.value = '';
        inputField.disabled = true;
        if (sendBtn) sendBtn.disabled = true;

        if (typingIndicator) {
            typingIndicator.style.display = 'flex';
            anplayScrollToBottom();
        }

        try {
            // Adaptive endpoint URL resolution pointing to assistant.php (hoàn toàn không bị InfinityFree chặn)
            let apiEndpoint = 'assistant.php';
            if (window.location.pathname.includes('/quan_ly_thu_vien_game/')) {
                apiEndpoint = '/quan_ly_thu_vien_game/assistant.php';
            } else if (window.location.pathname.startsWith('/')) {
                apiEndpoint = '/assistant.php';
            }

            // Dùng FormData để tương thích 100% với InfinityFree và mọi hosting Apache
            const formData = new FormData();
            formData.append('message', message);
            formData.append('csrf_token', window.ANPLAY_CHAT_CSRF || '');
            formData.append('history', JSON.stringify(chatHistory));

            const response = await fetch(apiEndpoint, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            if (typingIndicator) typingIndicator.style.display = 'none';

            let data = null;
            try {
                data = await response.json();
            } catch (parseErr) {
                data = null;
            }

            // Đồng bộ lại CSRF token nếu backend cấp mới
            if (data && data.new_csrf_token) {
                window.ANPLAY_CHAT_CSRF = data.new_csrf_token;
            }

            if (response.ok && data && data.success) {
                appendMessage('bot', data.reply);
                lastFailedMessage = '';

                // Lưu vào lịch sử hội thoại nhiều lượt
                chatHistory.push({ role: 'user', text: message });
                chatHistory.push({ role: 'model', text: data.reply });
                if (chatHistory.length > 8) {
                    chatHistory = chatHistory.slice(-8);
                }
            } else if (response.status === 429) {
                appendMessage('bot', 'Bạn gửi tin nhắn quá nhanh! Vui lòng chờ 30 giây rồi tiếp tục.', true, true);
            } else if (response.status === 403) {
                const errMsg = (data && data.error) ? data.error : 'Phiên làm việc bảo mật đã hết hạn. Vui lòng tải lại trang (F5) để tiếp tục!';
                appendMessage('bot', errMsg, true, false);
            } else if (response.status === 503) {
                appendMessage('bot', 'Hiện trợ lý AI đang tạm thời không khả dụng. Bạn có thể [Liên hệ nhân viên hỗ trợ](support.php) để được giải quyết ngay.', true, false);
            } else {
                appendMessage('bot', 'Hiện trợ lý AI đang tạm thời không khả dụng. Bạn có thể [Liên hệ nhân viên hỗ trợ](support.php) để được giải quyết ngay.', true, false);
            }
        } catch (netErr) {
            if (typingIndicator) typingIndicator.style.display = 'none';
            appendMessage('bot', 'Hiện trợ lý AI đang tạm thời không khả dụng. Bạn có thể [Liên hệ nhân viên hỗ trợ](support.php) để được giải quyết ngay.', true, false);
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

<!-- MOBILE BOTTOM NAVIGATION (Hiển thị trên mobile, ẩn trên desktop qua CSS) -->
<nav class="mobile-bottom-nav" aria-label="Điều hướng mobile">
    <div class="mobile-bottom-nav-inner">
        <a href="index.php" class="mobile-nav-item" data-page="index.php">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Cửa Hàng</span>
        </a>
        <a href="my_library.php" class="mobile-nav-item" data-page="my_library.php">
            <i class="bi bi-collection-play-fill"></i>
            <span>Thư Viện</span>
        </a>
        <a href="notifications.php" class="mobile-nav-item position-relative" data-page="notifications.php">
            <i class="bi bi-bell-fill"></i>
            <span>Thông Báo</span>
            <?php if (isset($header_unread_notifs) && $header_unread_notifs > 0): ?>
                <span class="badge bg-danger rounded-pill position-absolute" style="top: 4px; right: 20%; font-size: 0.6rem; padding: 2px 5px;"><?php echo $header_unread_notifs; ?></span>
            <?php endif; ?>
        </a>
        <a href="support.php" class="mobile-nav-item" data-page="support.php,support_detail.php">
            <i class="bi bi-headset"></i>
            <span>Hỗ Trợ</span>
        </a>
        <a href="<?php echo isset($_SESSION['user_id']) ? 'profile.php' : 'login.php'; ?>" class="mobile-nav-item" data-page="profile.php,login.php,register.php">
            <i class="bi bi-person-circle"></i>
            <span>Tài Khoản</span>
        </a>
    </div>
</nav>

</body>
</html>