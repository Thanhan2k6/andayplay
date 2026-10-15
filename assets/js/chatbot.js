/**
 * ANPLAY STORE — CHATBOT AI ASSISTANT (Fallback & Companion Controller)
 * Framework: Vanilla JS, Zero dependencies, XSS Protected
 */

(function() {
    // If inline script in footer.php already bound everything, keep this safe and synchronized
    function initCompanion() {
        const toggleBtn = document.getElementById('anplay-chat-toggle');
        const closeBtn = document.getElementById('anplay-chat-close');
        const clearBtn = document.getElementById('anplay-chat-clear');

        if (toggleBtn && !toggleBtn.hasAttribute('data-bound')) {
            toggleBtn.setAttribute('data-bound', 'true');
            toggleBtn.addEventListener('click', (e) => {
                if (window.anplayToggleChat) window.anplayToggleChat(e);
            });
        }

        if (closeBtn && !closeBtn.hasAttribute('data-bound')) {
            closeBtn.setAttribute('data-bound', 'true');
            closeBtn.addEventListener('click', (e) => {
                if (window.anplayToggleChat) window.anplayToggleChat(e);
            });
        }

        if (clearBtn && !clearBtn.hasAttribute('data-bound')) {
            clearBtn.setAttribute('data-bound', 'true');
            clearBtn.addEventListener('click', (e) => {
                if (window.anplayClearChat) window.anplayClearChat(e);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCompanion);
    } else {
        initCompanion();
    }
})();