/**
 * ANPLAY STORE — CHATBOT AI WIDGET (Frontend JavaScript)
 * 
 * Luồng xử lý:
 *   Click nút 💬 → Mở cửa sổ → Nhập tin → POST /api/chatbot.php → Hiển thị phản hồi
 * 
 * Bảo mật:
 *   - CSRF token đọc từ meta tag (server-rendered, không hardcode)
 *   - XSS escape tất cả nội dung trước khi render
 *   - Debounce chặn gửi liên tục
 *   - Disable input khi đang chờ phản hồi
 */

document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn   = document.getElementById('anplay-chat-toggle');
    const chatBox     = document.getElementById('anplay-chat-box');
    const closeBtn    = document.getElementById('anplay-chat-close');
    const clearBtn    = document.getElementById('anplay-chat-clear');
    const sendBtn     = document.getElementById('anplay-chat-send');
    const inputField  = document.getElementById('anplay-chat-input');
    const messagesArea = document.getElementById('anplay-chat-messages');
    const typingIndicator = document.getElementById('anplay-typing');

    // Đọc CSRF token từ meta tag (được PHP sinh ra trong footer)
    const csrfToken = document.querySelector('meta[name="chat-csrf"]')?.getAttribute('content') || '';

    // Cờ chặn gửi trùng lặp (debounce)
    let isSending = false;

    // ─── BẬT/TẮT CỬA SỔ CHAT ──────────────────────────
    function toggleChat() {
        if (!chatBox) return;
        chatBox.classList.toggle('active');
        if (chatBox.classList.contains('active') && inputField) {
            inputField.focus();
        }
    }

    if (toggleBtn) toggleBtn.addEventListener('click', toggleChat);
    if (closeBtn)  closeBtn.addEventListener('click', toggleChat);

    // ─── NÚT LÀM MỚI HỘI THOẠI ────────────────────────
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (!messagesArea) return;
            messagesArea.innerHTML = '';
            appendMessage('bot', 'Đã làm mới đoạn chat. Tôi có thể hỗ trợ gì cho bạn về game và dịch vụ của shop?');
            // Re-add typing indicator
            const typingDiv = document.createElement('div');
            typingDiv.className = 'anplay-typing-indicator';
            typingDiv.id = 'anplay-typing';
            typingDiv.style.display = 'none';
            typingDiv.innerHTML = '<span></span><span></span><span></span>';
            messagesArea.appendChild(typingDiv);
        });
    }

    // ─── HÀM CHỐNG XSS ─────────────────────────────────
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text; // textContent an toàn hơn innerText
        return div.innerHTML;
    }

    // ─── ĐỊNH DẠNG TIN NHẮN (sau khi đã escape) ────────
    function formatMessage(text) {
        let safe = escapeHtml(text);
        // Bold: **text** → <strong>text</strong>
        safe = safe.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Xuống dòng
        safe = safe.replace(/\n/g, '<br>');
        return safe;
    }

    // ─── THÊM TIN NHẮN VÀO KHUNG CHAT ──────────────────
    function appendMessage(role, text, isError = false) {
        if (!messagesArea) return;
        const msgDiv = document.createElement('div');
        msgDiv.className = 'anplay-msg ' + role + (isError ? ' error' : '');
        msgDiv.innerHTML = formatMessage(text);

        // Chèn trước typing indicator (nếu có)
        const typing = document.getElementById('anplay-typing');
        if (typing) {
            messagesArea.insertBefore(msgDiv, typing);
        } else {
            messagesArea.appendChild(msgDiv);
        }
        messagesArea.scrollTop = messagesArea.scrollHeight;
    }

    // ─── XỬ LÝ GỬI TIN NHẮN ───────────────────────────
    async function handleSend() {
        if (!inputField || !sendBtn) return;

        const message = inputField.value.trim();
        if (!message) return;       // Chặn tin rỗng
        if (isSending) return;      // Chặn gửi trùng lặp

        isSending = true;
        appendMessage('user', message);
        inputField.value = '';
        inputField.disabled = true;
        sendBtn.disabled = true;

        // Hiển thị trạng thái "đang xử lý"
        const typing = document.getElementById('anplay-typing');
        if (typing) typing.style.display = 'flex';
        messagesArea.scrollTop = messagesArea.scrollHeight;

        try {
            const response = await fetch('api/chatbot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    csrf_token: csrfToken
                })
            });

            if (typing) typing.style.display = 'none';

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    appendMessage('bot', data.reply);
                } else {
                    appendMessage('bot', data.error || 'Đã có lỗi xảy ra.', true);
                }
            } else if (response.status === 429) {
                appendMessage('bot', 'Bạn gửi tin quá nhanh! Vui lòng chờ 1 phút rồi thử lại.', true);
            } else if (response.status === 403) {
                appendMessage('bot', 'Phiên làm việc hết hạn. Vui lòng tải lại trang (F5).', true);
            } else if (response.status === 503) {
                appendMessage('bot', 'Hệ thống AI đang bảo trì. Vui lòng thử lại sau!', true);
            } else {
                // Cố gắng parse error message
                try {
                    const errData = await response.json();
                    appendMessage('bot', errData.error || 'Lỗi máy chủ (' + response.status + '). Vui lòng thử lại!', true);
                } catch {
                    appendMessage('bot', 'Lỗi máy chủ (' + response.status + '). Vui lòng thử lại!', true);
                }
            }
        } catch (err) {
            if (typing) typing.style.display = 'none';
            appendMessage('bot', 'Không thể kết nối đến máy chủ. Kiểm tra kết nối mạng và thử lại!', true);
        } finally {
            isSending = false;
            inputField.disabled = false;
            sendBtn.disabled = false;
            inputField.focus();
        }
    }

    // ─── SỰ KIỆN GỬI TIN NHẮN ──────────────────────────
    if (sendBtn) sendBtn.addEventListener('click', handleSend);
    if (inputField) {
        inputField.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSend();
            }
        });
    }

    // ─── ĐÓNG CHAT KHI NHẤN ESC ────────────────────────
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && chatBox && chatBox.classList.contains('active')) {
            toggleChat();
        }
    });
});