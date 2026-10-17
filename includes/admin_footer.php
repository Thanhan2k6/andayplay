<?php
/**
 * ANPLAY STORE - DEDICATED ADMIN FOOTER
 * Footer và Script Quản Trị Hệ Thống chuyên nghiệp
 */
?>
        </main> <!-- /admin-content-area -->

        <!-- ADMIN FOOTER BAR -->
        <footer class="py-3 px-4 border-top border-secondary border-opacity-10 text-secondary small d-flex flex-wrap justify-content-between align-items-center gap-2" style="background: rgba(10, 12, 18, 0.6);">
            <div>
                <span class="text-white fw-bold">ANPLAY ADMIN CONSOLE</span>
                <span class="text-secondary ms-1">v2.0 — Hệ thống Quản trị & Vận hành Nền tảng Thuê Game</span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-success"><i class="bi bi-shield-check me-1"></i>Hệ thống Bảo mật RBAC 3 Tầng</span>
                <span class="text-secondary"><i class="bi bi-clock-history me-1"></i>Máy chủ: <?php echo date('d/m/Y H:i:s'); ?></span>
            </div>
        </footer>
    </div> <!-- /admin-main-container -->
</div> <!-- /admin-layout-wrapper -->

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Admin Core Interactive Scripts -->
<script>
    // 1. Toggle Sidebar Drawer cho Mobile
    function toggleAdminSidebar(open) {
        const sidebar = document.getElementById('adminSidebar');
        const backdrop = document.getElementById('adminSidebarBackdrop');
        if (!sidebar || !backdrop) return;
        if (open) {
            sidebar.classList.add('show');
            backdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        } else {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    // Đóng drawer khi nhấn phím Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            toggleAdminSidebar(false);
        }
    });

    // 2. Notification Center AJAX Dropdown cho Admin
    document.addEventListener('DOMContentLoaded', function() {
        const notifBtn = document.getElementById('adminNotifDropdownBtn');
        const notifList = document.getElementById('adminNotifDropdownList');
        const topbarBadge = document.getElementById('adminTopbarNotifBadge');
        const headerBadge = document.getElementById('adminNotifHeaderBadge');

        function updateAdminBadges(count) {
            const num = parseInt(count) || 0;
            if (topbarBadge) {
                topbarBadge.textContent = num;
                topbarBadge.style.display = (num > 0) ? 'inline-block' : 'none';
            }
            if (headerBadge) {
                headerBadge.textContent = num + ' mới';
            }
        }

        function escapeAdminHtml(text) {
            if (!text) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        function renderAdminNotifications(items) {
            if (!notifList) return;
            if (!items || items.length === 0) {
                notifList.innerHTML = '<div class="p-4 text-center text-secondary small"><i class="bi bi-bell-slash fs-3 d-block mb-2 opacity-50"></i>Không có thông báo mới</div>';
                return;
            }

            let html = '';
            items.forEach(item => {
                const iconInfo = item.icon_info || { icon: 'bi-bell-fill', color: '#6366f1', bg: 'rgba(99,102,241,0.15)' };
                const isUnread = (parseInt(item.is_read) === 0);
                const linkUrl = item.link ? ('notifications.php?action=read_and_go&id=' + item.id + '&redirect=' + encodeURIComponent(item.link)) : ('notifications.php?action=mark_single&id=' + item.id);

                html += '<div class="admin-notif-item ' + (isUnread ? 'unread' : '') + '">' +
                    '<div class="rounded-3 p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 34px; height: 34px; background: ' + iconInfo.bg + '; color: ' + iconInfo.color + ';">' +
                        '<i class="bi ' + iconInfo.icon + '"></i>' +
                    '</div>' +
                    '<div class="flex-grow-1 min-w-0">' +
                        '<div class="d-flex justify-content-between align-items-center mb-1">' +
                            '<a href="' + linkUrl + '" class="text-white text-decoration-none small text-truncate fw-bold ' + (isUnread ? 'text-primary' : '') + '" style="max-width: 200px;">' + escapeAdminHtml(item.title) + '</a>' +
                            '<div class="d-flex align-items-center gap-1">' +
                                (isUnread ? '<span class="p-1 bg-primary rounded-circle" style="width: 6px; height: 6px;"></span>' : '') +
                                '<button type="button" class="btn btn-link text-secondary p-0 ms-1 opacity-50 hover-danger" style="font-size: 0.75rem;" title="Xóa thông báo" onclick="deleteAdminNotificationQuick(event, ' + item.id + ')"><i class="bi bi-x-lg"></i></button>' +
                            '</div>' +
                        '</div>' +
                        '<a href="' + linkUrl + '" class="text-secondary text-decoration-none small text-truncate d-block mb-1" style="font-size: 0.78rem; line-height: 1.3;">' + escapeAdminHtml(item.message) + '</a>' +
                        '<div class="text-secondary" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i>' + escapeAdminHtml(item.time_ago || '') + '</div>' +
                    '</div>' +
                '</div>';
            });
            notifList.innerHTML = html;
        }

        // Tải danh sách thông báo khi bấm mở dropdown
        if (notifBtn) {
            notifBtn.addEventListener('show.bs.dropdown', function () {
                fetch('api_notifications.php?action=get_dropdown')
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            updateAdminBadges(data.unread_count);
                            renderAdminNotifications(data.items);
                        }
                    })
                    .catch(() => {});
            });
        }

        // Đánh dấu tất cả đã đọc
        window.markAllAdminNotificationsReadQuick = function(e) {
            if (e) e.stopPropagation();
            fetch('api_notifications.php?action=mark_all_read', { method: 'POST' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        updateAdminBadges(0);
                        if (notifBtn) {
                            fetch('api_notifications.php?action=get_dropdown')
                                .then(r => r.json())
                                .then(d => d.success && renderAdminNotifications(d.items));
                        }
                    }
                })
                .catch(() => {});
        };

        // Xóa một thông báo
        window.deleteAdminNotificationQuick = function(e, notifId) {
            if (e) { e.preventDefault(); e.stopPropagation(); }
            fetch('api_notifications.php?action=delete&id=' + notifId, { method: 'POST' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        updateAdminBadges(data.unread_count);
                        fetch('api_notifications.php?action=get_dropdown')
                            .then(r => r.json())
                            .then(d => d.success && renderAdminNotifications(d.items));
                    }
                })
                .catch(() => {});
        };

        // Polling định kỳ mỗi 30 giây để cập nhật số lượng thông báo chưa đọc
        setInterval(function() {
            fetch('api_notifications.php?action=get_unread_count')
                .then(r => r.json())
                .then(data => {
                    if (data && data.success) {
                        updateAdminBadges(data.unread_count);
                    }
                })
                .catch(() => {});
        }, 30000);

        // 3. Tự động bọc table trong .table-responsive để chống vỡ khung hình trên mobile
        document.querySelectorAll('table').forEach(function(tbl) {
            if (!tbl.closest('.table-responsive')) {
                const wrapper = document.createElement('div');
                wrapper.className = 'table-responsive';
                tbl.parentNode.insertBefore(wrapper, tbl);
                wrapper.appendChild(tbl);
            }
        });
    });
</script>
</body>
</html>

