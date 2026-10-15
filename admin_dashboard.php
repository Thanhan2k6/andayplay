<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/header.php';

// Kiểm tra quyền Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    echo "<script>alert('Cảnh báo: Bạn không có quyền truy cập khu vực này!'); window.location='index.php';</script>";
    exit();
}

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
$total_games = safe_count_query($conn, "SELECT COUNT(*) as c FROM games WHERE price_type = 'paid'");

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
            <a href="cron_process_expirations.php?secret_token=ANPLAY_CRON_2026" class="btn btn-gaming-secondary btn-sm d-flex align-items-center">
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
</script>

<?php include 'includes/footer.php'; ?>