/**
 * ANPLAY STORE — CHATBOT AI ASSISTANT (Frontend JavaScript)
 * Framework: Vanilla JS, Zero dependencies, XSS Protected
 * Communication: Async Fetch to /api/chatbot.php (Gemini AI Proxy)
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. DOM Elements
    const toggleBtn        = document.getElementById('anplay-chat-toggle');
    const chatBox          = document.getElementById('anplay-chat-box');
    const closeBtn         = document.getElementById('anplay-chat-close');
    const clearBtn         = document.getElementById('anplay-chat-clear');
    const chatForm         = document.getElementById('anplay-chat-form');
    const sendBtn          = document.getElementById('anplay-chat-send');
    const inputField       = document.getElementById('anplay-chat-input');
    const messagesArea     = document.getElementById('anplay-chat-messages');
    const typingIndicator  = document.getElementById('anplay-typing');
    const suggestionChips  = document.querySelectorAll('.anplay-suggestion-chip');

    // 2. CSRF Token
    const csrfMeta = document.querySelector('meta[name="chat-csrf"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // 3. State
    let isSending = false;

    // Helper: Formatted Time
    function getCurrentTime() {
        const now = new Date();
        const h = String(now.getHours()).padStart(2, '0');
        const m = String(now.getMinutes()).padStart(2, '0');
        return `${h}:${m}`;
    }

    // Helper: Escape HTML to prevent XSS
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Helper: Format message markdown
    function formatMessage(text) {
        let safe = escapeHtml(text);
        // Bold: **text** -> <strong>text</strong>
        safe = safe.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Italic: *text* -> <em>text</em>
        safe = safe.replace(/\*(.*?)\*/g, '<em>$1</em>');
        // Newlines -> <br>
        safe = safe.replace(/\n/g, '<br>');
        return safe;
    }

    // Scroll messages to bottom smoothly
    function scrollToBottom() {
        if (!messagesArea) return;
        messagesArea.scrollTo({
            top: messagesArea.scrollHeight,
            behavior: 'smooth'
        });
    }

    // Append Message Row
    function appendMessage(role, text, isError = false) {
        if (!messagesArea) return;

        const row = document.createElement('div');
        row.className = `anplay-msg-row ${role}`;

        const timeStr = getCurrentTime();

        if (role === 'bot') {
            row.innerHTML = `
                <div class="anplay-msg-avatar"><i class="bi bi-robot"></i></div>
                <div class="anplay-msg-content">
                    <div class="anplay-msg bot ${isError ? 'error' : ''}">
                        ${formatMessage(text)}
                    </div>
                    <span class="anplay-msg-time">${timeStr}</span>
                </div>
            `;
        } else {
            row.innerHTML = `
                <div class="anplay-msg-content">
                    <div class="anplay-msg user">
                        ${formatMessage(text)}
                    </div>
                    <span class="anplay-msg-time">${timeStr}</span>
                </div>
            `;
        }

        // Insert before typing indicator
        if (typingIndicator && typingIndicator.parentNode === messagesArea) {
            messagesArea.insertBefore(row, typingIndicator);
        } else {
            messagesArea.appendChild(row);
        }

        scrollToBottom();
    }

    // Toggle Chat Window
    function toggleChat() {
        if (!chatBox) return;
        const isActive = chatBox.classList.contains('active');
        
        if (isActive) {
            chatBox.classList.remove('active');
            if (toggleBtn) toggleBtn.classList.remove('is-open');
        } else {
            chatBox.classList.add('active');
            if (toggleBtn) toggleBtn.classList.add('is-open');
            setTimeout(() => {
                if (inputField) inputField.focus();
                scrollToBottom();
            }, 150);
        }
    }

    if (toggleBtn) toggleBtn.addEventListener('click', toggleChat);
    if (closeBtn)  closeBtn.addEventListener('click', toggleChat);

    // Escape Key to close
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && chatBox && chatBox.classList.contains('active')) {
            toggleChat();
        }
    });

    // Clear Chat History
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (!messagesArea) return;
            // Clear all rows except typing indicator
            const rows = messagesArea.querySelectorAll('.anplay-msg-row');
            rows.forEach(r => r.remove());

            appendMessage('bot', 'Đoạn hội thoại đã được đặt lại! Bạn cần tìm tựa game nào hoặc muốn hỗ trợ thuê tài khoản cứ nhắn cho tôi nhé!');
        });
    }

    // Suggestion Chips Click
    suggestionChips.forEach(chip => {
        chip.addEventListener('click', function() {
            const question = this.getAttribute('data-question');
            if (question && !isSending) {
                if (inputField) inputField.value = question;
                handleSendMessage();
            }
        });
    });

    // Send Message Handler
    async function handleSendMessage() {
        if (!inputField || isSending) return;

        const message = inputField.value.trim();
        if (!message) return; // Prevent empty message

        isSending = true;
        appendMessage('user', message);
        inputField.value = '';
        inputField.disabled = true;
        if (sendBtn) sendBtn.disabled = true;

        // Show typing indicator
        if (typingIndicator) {
            typingIndicator.style.display = 'flex';
            scrollToBottom();
        }

        try {
            // Resolve correct API path whether on root or subdirectory
            let apiEndpoint = 'api/chatbot.php';
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
                    csrf_token: csrfToken
                })
            });

            if (typingIndicator) typingIndicator.style.display = 'none';

            let data = null;
            try {
                data = await response.json();
            } catch (jsonErr) {
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
            inputField.disabled = false;
            if (sendBtn) sendBtn.disabled = false;
            inputField.focus();
            scrollToBottom();
        }
    }

    // Submit via form or send button
    if (chatForm) {
        chatForm.addEventListener('submit', (e) => {
            e.preventDefault();
            handleSendMessage();
        });
    } else if (sendBtn) {
        sendBtn.addEventListener('click', (e) => {
            e.preventDefault();
            handleSendMessage();
        });
    }

    // Handle Enter key (without Shift)
    if (inputField) {
        inputField.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSendMessage();
            }
        });
    }
});