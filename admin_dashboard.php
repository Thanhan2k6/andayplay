<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/connect.php';
$page_title = "Bảng Điều Khiển Tổng Quan";
require_once __DIR__ . '/includes/admin_header.php';

function safe_count_query($conn, $sql) {
    try {
        $res = $conn->query($sql);
        if ($res && $row = $res->fetch_assoc()) {
            return intval($row['c'] ?? 0);
        }
    } catch (Throwable $e) {}
    return 0;
}

// 1. THỐNG KÊ THỰC TẾ TỪ CƠ SỞ DỮ LIỆU
$total_users = safe_count_query($conn, "SELECT COUNT(*) as c FROM users WHERE role = 0");
$total_games = safe_count_query($conn, "SELECT COUNT(*) as c FROM games");

// Thống kê kho tài khoản
$total_accounts = safe_count_query($conn, "SELECT COUNT(*) as c FROM game_accounts");
$available_accounts = safe_count_query($conn, "SELECT COUNT(*) as c FROM game_accounts WHERE status = 'AVAILABLE'");
$rented_accounts = safe_count_query($conn, "SELECT COUNT(*) as c FROM game_accounts WHERE status = 'RENTED'");
$maintenance_accounts = safe_count_query($conn, "SELECT COUNT(*) as c FROM game_accounts WHERE status = 'MAINTENANCE'");

// Thống kê đơn thuê
$active_borrows = safe_count_query($conn, "SELECT COUNT(*) as c FROM borrow_records WHERE status = 'active' AND due_date > NOW()");
$expiring_soon = safe_count_query($conn, "SELECT COUNT(*) as c FROM borrow_records WHERE status = 'active' AND due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)");
$expired_borrows = safe_count_query($conn, "SELECT COUNT(*) as c FROM borrow_records WHERE status = 'expired' OR (status = 'active' AND due_date <= NOW())");

// Doanh thu thực tế từ các đơn đã thanh toán
$total_revenue = 0.0;
try {
    $total_revenue_res = $conn->query("SELECT SUM(rental_price) as rev FROM borrow_records WHERE payment_status = 'paid'");
    if ($total_revenue_res && $row = $total_revenue_res->fetch_assoc()) {
        $total_revenue = floatval($row['rev'] ?? 0);
    }
} catch (Throwable $e) {}

// Sự cố cấp phát lỗi cần xử lý
$failed_allocations = safe_count_query($conn, "SELECT COUNT(*) as c FROM borrow_records WHERE payment_status = 'paid' AND (account_id IS NULL OR status = 'pending')");

// Dữ liệu biểu đồ doanh thu theo 12 tháng của năm hiện tại
$current_year = date('Y');
$chart_revenue = array_fill(1, 12, 0);
try {
    $sql_rev = "SELECT MONTH(borrow_date) as m, SUM(rental_price) as sum_rev 
                FROM borrow_records 
                WHERE YEAR(borrow_date) = $current_year AND payment_status = 'paid'
                GROUP BY MONTH(borrow_date)";
    $res_rev = $conn->query($sql_rev);
    if ($res_rev && $res_rev->num_rows > 0) {
        while ($r = $res_rev->fetch_assoc()) {
            $chart_revenue[(int)$r['m']] = (float)$r['sum_rev'];
        }
    }
} catch (Throwable $e) {}
$chart_rev_js = implode(',', $chart_revenue);

// 2. NHẬT KÝ TIẾN TRÌNH CRON TỰ ĐỘNG
$last_cron_log = null;
$recent_cron_logs = [];
try {
    $cron_res = $conn->query("SELECT * FROM cron_execution_logs ORDER BY id DESC LIMIT 10");
    if ($cron_res && $cron_res->num_rows > 0) {
        $recent_cron_logs = $cron_res->fetch_all(MYSQLI_ASSOC);
        $last_cron_log = $recent_cron_logs[0] ?? null;
    }
} catch (Throwable $e) {}
?>

<div class="container-fluid px-lg-5 py-4 my-2 text-white">
    <!-- Top Bar Navigation -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); font-size: 0.75rem; border: 1px solid rgba(99,102,241,0.4);">
                    <i class="bi bi-shield-lock-fill me-1"></i> ADMIN CONSOLE
                </span>
                <span class="text-secondary small">Năm hoạt động: <?php echo $current_year; ?></span>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Trung Tâm Quản Trị & Báo Cáo Doanh Thu</h2>
        </div>
        <div class="d-flex gap-2">
            <a href="cron_process_expirations.php?secret_token=ANPLAY_CRON_SEC_9b8e21a7df4e083c6f41" target="_blank" class="btn btn-gaming-secondary btn-sm d-flex align-items-center" title="Xem bảng điều khiển Cron Worker">
                <i class="bi bi-arrow-repeat me-1 text-info"></i> Quét Đơn Hết Hạn
            </a>
            <button class="btn btn-gaming-secondary btn-sm d-flex align-items-center" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> In Báo Cáo
            </button>
        </div>
    </div>

    <!-- 4 KPI SaaS Cards -->
    <div class="row g-3 mb-4">
        <!-- Doanh Thu -->
        <div class="col-sm-6 col-xl-3">
            <div class="p-4 rounded-4 h-100 position-relative overflow-hidden" style="background: var(--bg-card); border: 1px solid rgba(16, 185, 129, 0.3);">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small text-uppercase fw-bold letter-spacing-1">Tổng Doanh Thu</span>
                        <h3 class="fw-extrabold text-white mt-2 mb-1"><?php echo number_format($total_revenue, 0, ',', '.'); ?> đ</h3>
                        <span class="badge bg-success bg-opacity-25 text-success small">Thanh toán tự động</span>
                    </div>
                    <div class="p-3 rounded-3" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                        <i class="bi bi-cash-stack fs-3"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kho Tài Khoản (Sẵn / Tổng) -->
        <div class="col-sm-6 col-xl-3">
            <div class="p-4 rounded-4 h-100 position-relative overflow-hidden" style="background: var(--bg-card); border: 1px solid rgba(6, 182, 212, 0.3);">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small text-uppercase fw-bold letter-spacing-1">Kho Tài Khoản</span>
                        <h3 class="fw-extrabold text-white mt-2 mb-1"><?php echo $available_accounts; ?> <span class="fs-6 text-secondary fw-normal">/ <?php echo $total_accounts; ?> TK</span></h3>
                        <span class="badge" style="background: rgba(6, 182, 212, 0.15); color: var(--accent-cyan); font-size: 0.75rem;">
                            Đang cho thuê: <?php echo $rented_accounts; ?>
                        </span>
                    </div>
                    <div class="p-3 rounded-3" style="background: rgba(6, 182, 212, 0.15); color: var(--accent-cyan);">
                        <i class="bi bi-box-seam fs-3"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Đơn Đang Hoạt Động -->
        <div class="col-sm-6 col-xl-3">
            <div class="p-4 rounded-4 h-100 position-relative overflow-hidden" style="background: var(--bg-card); border: 1px solid rgba(245, 158, 11, 0.3);">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small text-uppercase fw-bold letter-spacing-1">Đơn Đang Hoạt Động</span>
                        <h3 class="fw-extrabold text-warning mt-2 mb-1"><?php echo $active_borrows; ?></h3>
                        <span class="text-secondary small">Sắp hết hạn trong 24h: <strong class="text-warning"><?php echo $expiring_soon; ?></strong></span>
                    </div>
                    <div class="p-3 rounded-3" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                        <i class="bi bi-clock-history fs-3"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tài Khoản Bảo Trì & Sự Cố -->
        <div class="col-sm-6 col-xl-3">
            <div class="p-4 rounded-4 h-100 position-relative overflow-hidden" style="background: var(--bg-card); border: 1px solid rgba(239, 68, 68, 0.3);">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small text-uppercase fw-bold letter-spacing-1">Bảo Trì / Cần Kiểm Tra</span>
                        <h3 class="fw-extrabold text-danger mt-2 mb-1"><?php echo $maintenance_accounts; ?> <span class="fs-6 text-secondary fw-normal">TK</span></h3>
                        <span class="text-secondary small">Lỗi cấp phát: <strong class="text-danger"><?php echo $failed_allocations; ?></strong></span>
                    </div>
                    <div class="p-3 rounded-3" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;">
                        <i class="bi bi-exclamation-octagon fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CRON AUTOMATION & EXPIRATION MONITOR WIDGET -->
    <div class="p-4 rounded-4 mb-4 position-relative overflow-hidden" style="background: linear-gradient(135deg, rgba(30, 27, 75, 0.6) 0%, rgba(15, 23, 42, 0.8) 100%); border: 1px solid rgba(99, 102, 241, 0.35);">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="badge" style="background: rgba(16,185,129,0.18); color: #10b981; border: 1px solid rgba(16,185,129,0.35); font-weight: 700;">
                        <span class="status-dot d-inline-block rounded-circle me-1" style="width: 8px; height: 8px; background: #10b981; box-shadow: 0 0 8px #10b981;"></span>
                        CRON WORKER: ĐANG CHẠY TỰ ĐỘNG
                    </span>
                    <span class="text-secondary small">
                        <?php if ($last_cron_log): ?>
                            Chạy gần nhất: <strong class="text-info"><?php echo date('H:i:s d/m/Y', strtotime($last_cron_log['start_time'])); ?></strong> 
                            <span class="badge bg-secondary bg-opacity-25 text-light"><?php echo $last_cron_log['duration_ms']; ?>ms</span>
                        <?php else: ?>
                            <span class="text-warning">Chưa có nhật ký ghi nhận</span>
                        <?php endif; ?>
                    </span>
                </div>
                <h5 class="fw-bold text-white mb-1">
                    <i class="bi bi-shield-check text-info me-1"></i> Tự Động Quét, Thu Hồi Kho & Cảnh Báo Đơn Thuê Hết Hạn
                </h5>
                <p class="text-secondary small mb-0">
                    Thu hồi quyền xem mật khẩu tức thì khi hết hạn, tái cấp kho <span class="badge bg-success bg-opacity-25 text-success">AVAILABLE</span>, gửi 3 mốc cảnh báo (24h, 1h, 15 phút) và tự nhận diện đơn gia hạn hợp lệ.
                </p>
            </div>
            <div class="col-lg-5 text-lg-end">
                <div class="d-flex flex-wrap gap-2 justify-content-lg-end align-items-center">
                    <button type="button" class="btn btn-gaming-primary btn-sm px-3 py-2" id="btn-quick-cron-scan" onclick="runQuickCronScan(this)">
                        <i class="bi bi-lightning-charge-fill me-1"></i> Quét Hết Hạn Ngay
                    </button>
                    <button type="button" class="btn btn-gaming-secondary btn-sm px-3 py-2" data-bs-toggle="modal" data-bs-target="#modalCronLogs">
                        <i class="bi bi-clock-history me-1"></i> Xem Nhật Ký (<?php echo count($recent_cron_logs); ?>)
                    </button>
                    <button type="button" class="btn btn-outline-info btn-sm px-3 py-2" data-bs-toggle="modal" data-bs-target="#modalCronConfig">
                        <i class="bi bi-gear-wide-connected me-1"></i> Cấu Hình Web-Cron
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Biểu đồ & Tác Vụ Cần Xử Lý -->
    <div class="row g-4">
        <!-- Revenue Chart (8 cols) -->
        <div class="col-lg-8">
            <div class="p-4 rounded-4 h-100" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="fw-bold text-white mb-1"><i class="bi bi-graph-up text-info me-2"></i>Doanh Thu Thuê Tài Khoản Game (Năm <?php echo $current_year; ?>)</h5>
                        <p class="text-secondary small mb-0">Biểu đồ tổng hợp doanh thu theo từng tháng dựa trên giao dịch thực tế</p>
                    </div>
                    <span class="badge" style="background: rgba(99,102,241,0.15); color: var(--primary);">12 THÁNG</span>
                </div>
                <div style="height: 330px;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Tasks & Alerts (4 cols) -->
        <div class="col-lg-4">
            <div class="p-4 rounded-4 h-100 d-flex flex-column" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <h5 class="fw-bold text-white mb-3"><i class="bi bi-bell text-warning me-2"></i>Tác Vụ Cần Chú Ý</h5>
                
                <div class="d-flex flex-column gap-3 mb-4">
                    <!-- Item 1 -->
                    <div class="p-3 rounded-3 d-flex justify-content-between align-items-center" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                        <div>
                            <span class="d-block small text-light fw-semibold">Tài khoản báo lỗi / Bảo trì</span>
                            <small class="text-secondary">Cần kiểm tra lại thông tin đăng nhập</small>
                        </div>
                        <a href="admin_accounts.php?filter_status=MAINTENANCE" class="status-pill status-pill-warning text-decoration-none">
                            <?php echo $maintenance_accounts; ?> TK
                        </a>
                    </div>

                    <!-- Item 2 -->
                    <div class="p-3 rounded-3 d-flex justify-content-between align-items-center" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                        <div>
                            <span class="d-block small text-light fw-semibold">Đơn sắp hết hạn (trong 24h)</span>
                            <small class="text-secondary">Chuẩn bị tự động thu hồi tài khoản</small>
                        </div>
                        <span class="status-pill status-pill-info">
                            <?php echo $expiring_soon; ?> đơn
                        </span>
                    </div>

                    <!-- Item 3 -->
                    <div class="p-3 rounded-3 d-flex justify-content-between align-items-center" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                        <div>
                            <span class="d-block small text-light fw-semibold">Đơn lỗi chờ cấp bù tài khoản</span>
                            <small class="text-secondary">Khách đã trả tiền nhưng kho hết TK</small>
                        </div>
                        <a href="admin_borrow.php?status=pending" class="status-pill status-pill-danger text-decoration-none">
                            <?php echo $failed_allocations; ?> đơn
                        </a>
                    </div>

                    <!-- Item 4 -->
                    <div class="p-3 rounded-3 d-flex justify-content-between align-items-center" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                        <div>
                            <span class="d-block small text-light fw-semibold">Tổng khách hàng đăng ký</span>
                            <small class="text-secondary">Tài khoản game thủ trên website</small>
                        </div>
                        <span class="badge" style="background: rgba(255,255,255,0.08); color: #e2e8f0;">
                            <?php echo $total_users; ?> người dùng
                        </span>
                    </div>
                </div>

                <!-- Quick Action Buttons -->
                <div class="mt-auto d-grid gap-2">
                    <a href="admin_accounts.php" class="btn btn-gaming-primary py-2 text-decoration-none text-center">
                        <i class="bi bi-plus-circle me-1"></i> Quản Lý & Nhập Kho Tài Khoản
                    </a>
                    <a href="admin_borrow.php" class="btn btn-gaming-secondary py-2 text-decoration-none text-center">
                        <i class="bi bi-card-checklist me-1"></i> Xem Toàn Bộ Đơn Thuê Game
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('revenueChart').getContext('2d');
    let gradient = ctx.createLinearGradient(0, 0, 0, 330);
    gradient.addColorStop(0, 'rgba(99, 102, 241, 0.7)');
    gradient.addColorStop(1, 'rgba(99, 102, 241, 0.05)');

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6', 'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'],
            datasets: [{
                label: 'Doanh thu (VNĐ)',
                data: [<?php echo $chart_rev_js; ?>],
                backgroundColor: gradient,
                borderColor: '#6366f1',
                borderWidth: 2,
                borderRadius: 8,
                hoverBackgroundColor: '#818cf8'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#cbd5e1', font: { family: 'Plus Jakarta Sans', weight: 'bold' } } },
                tooltip: {
                    backgroundColor: '#0f1118',
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 1,
                    titleColor: '#fff',
                    bodyColor: '#cbd5e1',
                    callbacks: {
                        label: function(context) {
                            return 'Doanh thu: ' + new Intl.NumberFormat('vi-VN').format(context.raw) + ' VNĐ';
                        }
                    }
                }
            },
            scales: {
                x: { 
                    ticks: { color: '#94a3b8', font: { family: 'Plus Jakarta Sans' } }, 
                    grid: { display: false } 
                },
                y: { 
                    ticks: { 
                        color: '#94a3b8', 
                        font: { family: 'Plus Jakarta Sans' },
                        callback: function(value) { return new Intl.NumberFormat('vi-VN').format(value) + 'đ'; }
                    }, 
                    grid: { color: 'rgba(255,255,255,0.05)' } 
                }
            }
        }
    });
});

// Hàm gọi Quét Hết Hạn Ngay tức thì bằng AJAX
function runQuickCronScan(btn) {
    if (!btn) return;
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Đang quét...';

    fetch('cron_process_expirations.php?format=json')
        .then(response => {
            if (!response.ok) {
                if (response.status === 429) {
                    throw new Error('Tiến trình Cron khác đang hoạt động. Vui lòng thử lại sau 30 giây.');
                }
                throw new Error('Máy chủ phản hồi mã lỗi HTTP ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Thành công!';
            btn.classList.remove('btn-gaming-primary');
            btn.classList.add('btn-success');

            const s = data.summary || {};
            const warns = (s.warnings_sent ? s.warnings_sent.total : 0);
            const msg = `✅ Quét hoàn tất trong ${data.duration_ms}ms!\n\n` +
                        `• Tổng đơn kiểm tra: ${s.scanned_total || 0}\n` +
                        `• Đơn hết hạn thu hồi: ${s.expired_revoked || 0}\n` +
                        `• Cảnh báo đã gửi: ${warns}\n` +
                        `• Đơn gia hạn duy trì: ${s.extensions_kept || 0}\n` +
                        `• Lỗi phát sinh: ${s.errors || 0}`;
            alert(msg);
            setTimeout(() => { window.location.reload(); }, 800);
        })
        .catch(err => {
            alert('⚠️ Lỗi khi chạy quét tự động: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
}

function copyCronUrl(btn) {
    const input = document.getElementById('cronUrlInput');
    if (!input) return;
    input.select();
    navigator.clipboard.writeText(input.value).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Đã sao chép!';
        btn.classList.remove('btn-gaming-secondary');
        btn.classList.add('btn-success');
        setTimeout(() => {
            btn.innerHTML = orig;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-gaming-secondary');
        }, 2000);
    });
}
</script>

<!-- MODAL: NHẬT KÝ CRON WORKER -->
<div class="modal fade" id="modalCronLogs" tabindex="-1" aria-labelledby="modalCronLogsLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content text-white" style="background: var(--bg-card); border: 1px solid var(--border-color);">
            <div class="modal-header border-bottom border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold" id="modalCronLogsLabel">
                    <i class="bi bi-clock-history text-info me-2"></i> Lịch Sử Quét Cron Gần Nhất
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <?php if (empty($recent_cron_logs)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-inbox fs-1 d-block mb-2 text-muted"></i>
                        Chưa có bản ghi nhật ký quét Cron nào trong cơ sở dữ liệu.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                            <thead>
                                <tr class="text-secondary small text-uppercase">
                                    <th>#ID</th>
                                    <th>Thời Điểm</th>
                                    <th>Nguồn Kích Hoạt</th>
                                    <th>Thời Lượng</th>
                                    <th>Quét</th>
                                    <th>Thu Hồi</th>
                                    <th>Cảnh Báo</th>
                                    <th>Gia Hạn</th>
                                    <th>Trạng Thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_cron_logs as $log): 
                                    $st = $log['status'];
                                    $badge_class = ($st === 'success') ? 'bg-success bg-opacity-25 text-success' : (($st === 'warning') ? 'bg-warning bg-opacity-25 text-warning' : 'bg-danger bg-opacity-25 text-danger');
                                ?>
                                    <tr>
                                        <td class="font-monospace text-secondary">#<?php echo $log['id']; ?></td>
                                        <td class="fw-semibold text-light"><?php echo date('H:i:s d/m/Y', strtotime($log['start_time'])); ?></td>
                                        <td>
                                            <span class="badge" style="background: rgba(255,255,255,0.06); color: #cbd5e1;">
                                                <?php echo htmlspecialchars($log['triggered_by']); ?>
                                            </span>
                                        </td>
                                        <td class="font-monospace text-info"><?php echo $log['duration_ms']; ?>ms</td>
                                        <td class="fw-bold"><?php echo $log['scanned_count']; ?></td>
                                        <td class="text-danger fw-bold"><?php echo $log['expired_count']; ?></td>
                                        <td class="text-warning fw-bold"><?php echo $log['warned_count']; ?></td>
                                        <td class="text-success fw-bold"><?php echo $log['extended_count']; ?></td>
                                        <td>
                                            <span class="badge <?php echo $badge_class; ?> text-uppercase">
                                                <?php echo $st; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-top border-secondary border-opacity-25">
                <button type="button" class="btn btn-gaming-secondary" data-bs-dismiss="modal">Đóng</button>
                <a href="cron_process_expirations.php?secret_token=ANPLAY_CRON_SEC_9b8e21a7df4e083c6f41" target="_blank" class="btn btn-gaming-primary">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Mở Bảng Console Đầy Đủ
                </a>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: HƯỚNG DẪN CẤU HÌNH WEB-CRON 24/7 -->
<div class="modal fade" id="modalCronConfig" tabindex="-1" aria-labelledby="modalCronConfigLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content text-white" style="background: var(--bg-card); border: 1px solid var(--border-color);">
            <div class="modal-header border-bottom border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold" id="modalCronConfigLabel">
                    <i class="bi bi-gear-wide-connected text-info me-2"></i> Thiết Lập Tự Động Quét Hết Hạn 24/7 (Web-Cron)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-info bg-info bg-opacity-10 border-info border-opacity-25 text-light mb-4">
                    <i class="bi bi-info-circle-fill text-info me-2"></i>
                    <strong>Môi trường Hosting InfinityFree:</strong> Máy chủ không mở cổng SSH terminal định kỳ vĩnh viễn. Để hệ thống tự động quét mỗi 1 đến 5 phút mà không cần người dùng truy cập, giải pháp chuẩn công nghiệp là sử dụng <strong>External Web-Cron</strong> (như cron-job.org hoặc UptimeRobot).
                </div>

                <h6 class="fw-bold text-white mb-2">1. Đường Dẫn Web-Cron Bảo Mật Của Bạn:</h6>
                <div class="input-group mb-4">
                    <input type="text" id="cronUrlInput" class="form-control form-control-gaming text-warning font-monospace" readonly
                           value="https://anplaystore.free.nf/cron_process_expirations.php?secret_token=ANPLAY_CRON_SEC_9b8e21a7df4e083c6f41&format=json">
                    <button class="btn btn-gaming-secondary" type="button" onclick="copyCronUrl(this)">
                        <i class="bi bi-clipboard me-1"></i> Sao chép URL
                    </button>
                </div>

                <h6 class="fw-bold text-white mb-2">2. Hướng Dẫn Cấu Hình Trên cron-job.org (Miễn Phí):</h6>
                <ol class="text-secondary small mb-3 ps-3" style="line-height: 1.8;">
                    <li>Truy cập <a href="https://cron-job.org" target="_blank" class="text-info fw-bold">cron-job.org</a> và đăng ký một tài khoản miễn phí.</li>
                    <li>Vào mục <strong>Cronjobs</strong> &rarr; Chọn <strong>Create Cronjob</strong>.</li>
                    <li>Dán URL ở trên vào ô <strong>URL</strong>.</li>
                    <li>Đặt <strong>Schedule / Execution interval</strong>: Chọn <code>Every 1 minute</code> hoặc <code>Every 5 minutes</code>.</li>
                    <li>Phương thức Request: Chọn <strong>GET</strong>.</li>
                    <li>Nhấn <strong>Create</strong> để kích hoạt. Kể từ lúc này, hệ thống sẽ tự động quét, cảnh báo và thu hồi tài khoản đúng thời gian thực 24/7!</li>
                </ol>

                <div class="p-3 rounded-3" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.25);">
                    <div class="d-flex align-items-center gap-2 text-success small fw-bold">
                        <i class="bi bi-shield-check fs-5"></i>
                        <span>Lớp bảo vệ dự phòng bổ sung:</span>
                    </div>
                    <p class="text-secondary small mb-0 mt-1">
                        ANPLAY STORE đã tích hợp sẵn cơ chế <em>Inline Auto-Check</em> trong <code>connect.php</code>. Khi có bất kỳ lượt truy cập nào trên website, các đơn đã quá hạn sẽ tự động được thu hồi ngay cả khi dịch vụ Web-Cron bên ngoài gặp sự cố tạm thời.
                    </p>
                </div>
            </div>
            <div class="modal-footer border-top border-secondary border-opacity-25">
                <button type="button" class="btn btn-gaming-secondary" data-bs-dismiss="modal">Đã Hiểu</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>