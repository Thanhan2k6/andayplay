<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/header.php';

// Kiểm tra quyền Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    echo "<script>alert('Cảnh báo: Bạn không có quyền truy cập khu vực này!'); window.location='index.php';</script>";
    exit();
}

// 1. THỐNG KÊ THỰC TẾ TỪ CƠ SỞ DỮ LIỆU
$total_users = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 0")->fetch_assoc()['c'];
$total_games = $conn->query("SELECT COUNT(*) as c FROM games WHERE price_type = 'paid'")->fetch_assoc()['c'];

// Thống kê kho tài khoản
$total_accounts = $conn->query("SELECT COUNT(*) as c FROM game_accounts")->fetch_assoc()['c'];
$available_accounts = $conn->query("SELECT COUNT(*) as c FROM game_accounts WHERE status = 'AVAILABLE'")->fetch_assoc()['c'];
$rented_accounts = $conn->query("SELECT COUNT(*) as c FROM game_accounts WHERE status = 'RENTED'")->fetch_assoc()['c'];
$maintenance_accounts = $conn->query("SELECT COUNT(*) as c FROM game_accounts WHERE status = 'MAINTENANCE'")->fetch_assoc()['c'];

// Thống kê đơn thuê
$active_borrows = $conn->query("SELECT COUNT(*) as c FROM borrow_records WHERE status = 'active' AND due_date > NOW()")->fetch_assoc()['c'];
$expiring_soon = $conn->query("SELECT COUNT(*) as c FROM borrow_records WHERE status = 'active' AND due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)")->fetch_assoc()['c'];
$expired_borrows = $conn->query("SELECT COUNT(*) as c FROM borrow_records WHERE status = 'expired' OR (status = 'active' AND due_date <= NOW())")->fetch_assoc()['c'];

// Doanh thu thực tế từ các đơn đã thanh toán
$total_revenue_res = $conn->query("SELECT SUM(rental_price) as rev FROM borrow_records WHERE payment_status = 'paid'")->fetch_assoc();
$total_revenue = floatval($total_revenue_res['rev'] ?? 0);

// Sự cố cấp phát lỗi cần xử lý
$failed_allocations = $conn->query("SELECT COUNT(*) as c FROM borrow_records WHERE payment_status = 'paid' AND (account_id IS NULL OR status = 'pending')")->fetch_assoc()['c'];

// Dữ liệu biểu đồ doanh thu theo 12 tháng của năm hiện tại
$current_year = date('Y');
$chart_revenue = array_fill(1, 12, 0);
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
$chart_rev_js = implode(',', $chart_revenue);
?>

<div class="container-fluid mt-4 mb-5 text-white">
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary">
        <div>
            <h2 class="fw-bold m-0 text-warning"><i class="bi bi-speedometer2 me-2"></i> TRUNG TÂM QUẢN TRỊ & BÁO CÁO THỐNG KÊ</h2>
            <p class="text-secondary small mb-0">Dữ liệu thực tế về kho tài khoản, đơn thuê, doanh thu và cảnh báo sự cố</p>
        </div>
        <div>
            <a href="cron_process_expirations.php?secret_token=ANPLAY_CRON_2026" class="btn btn-outline-info btn-sm me-2">
                <i class="bi bi-arrow-repeat"></i> Chạy Tác Vụ Quét Hết Hạn
            </a>
            <button class="btn btn-outline-light btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> In Báo Cáo</button>
        </div>
    </div>

    <!-- HÀNG THẺ CHỈ SỐ DOANH THU & KHO TÀI KHOẢN -->
    <div class="row g-3 mb-4">
        <!-- Doanh thu -->
        <div class="col-md-3">
            <div class="card p-3 border-0 rounded-4 shadow-sm" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(5, 150, 105, 0.4)); border: 1px solid rgba(16, 185, 129, 0.3);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-uppercase text-light fw-bold">Tổng Doanh Thu</small>
                        <h3 class="fw-bold text-white mt-1 mb-0"><?php echo number_format($total_revenue, 0, ',', '.'); ?> đ</h3>
                    </div>
                    <i class="bi bi-currency-dollar fs-1 text-success"></i>
                </div>
            </div>
        </div>

        <!-- Tài khoản khả dụng / Tổng kho -->
        <div class="col-md-3">
            <div class="card p-3 border-0 rounded-4 shadow-sm" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(37, 99, 235, 0.4)); border: 1px solid rgba(59, 130, 246, 0.3);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-uppercase text-light fw-bold">Kho Tài Khoản (Sẵn/Tổng)</small>
                        <h3 class="fw-bold text-white mt-1 mb-0"><?php echo $available_accounts; ?> / <?php echo $total_accounts; ?></h3>
                    </div>
                    <i class="bi bi-box-seam fs-1 text-primary"></i>
                </div>
            </div>
        </div>

        <!-- Đơn đang thuê hiệu lực -->
        <div class="col-md-3">
            <div class="card p-3 border-0 rounded-4 shadow-sm" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(217, 119, 6, 0.4)); border: 1px solid rgba(245, 158, 11, 0.3);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-uppercase text-light fw-bold">Đơn Đang Hoạt Động</small>
                        <h3 class="fw-bold text-white mt-1 mb-0"><?php echo $active_borrows; ?></h3>
                        <small class="text-warning">Sắp hết hạn: <strong><?php echo $expiring_soon; ?></strong></small>
                    </div>
                    <i class="bi bi-clock-history fs-1 text-warning"></i>
                </div>
            </div>
        </div>

        <!-- Đơn hết hạn & Sự cố -->
        <div class="col-md-3">
            <div class="card p-3 border-0 rounded-4 shadow-sm" style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(185, 28, 28, 0.4)); border: 1px solid rgba(239, 68, 68, 0.3);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-uppercase text-light fw-bold">Bảo Trì / Cần Thu Hồi</small>
                        <h3 class="fw-bold text-white mt-1 mb-0"><?php echo $maintenance_accounts; ?> TK</h3>
                        <small class="text-danger">Lỗi cấp phát: <strong><?php echo $failed_allocations; ?></strong></small>
                    </div>
                    <i class="bi bi-exclamation-triangle-fill fs-1 text-danger"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- KHU VỰC BIỂU ĐỒ DOANH THU & BẢNG CẢNH BÁO -->
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 p-4 shadow-sm" style="background-color: #1a222c; border-radius: 12px; border: 1px solid #2d3b4e;">
                <h5 class="fw-bold text-info mb-3">📈 Biểu Đồ Doanh Thu Thuê Tài Khoản Game (Năm <?php echo $current_year; ?>)</h5>
                <div style="height: 350px;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 p-4 shadow-sm h-100" style="background-color: #1a222c; border-radius: 12px; border: 1px solid #2d3b4e;">
                <h5 class="fw-bold text-warning mb-3">⚠️ Tác Vụ Cần Chú Ý</h5>
                <ul class="list-group list-group-flush bg-transparent">
                    <li class="list-group-item bg-transparent text-light px-0 d-flex justify-content-between align-items-center border-secondary">
                        <span>Tài khoản đang bảo trì / Báo lỗi</span>
                        <a href="admin_accounts.php?filter_status=MAINTENANCE" class="badge bg-warning text-dark px-2 py-1 text-decoration-none">
                            <?php echo $maintenance_accounts; ?> tài khoản
                        </a>
                    </li>
                    <li class="list-group-item bg-transparent text-light px-0 d-flex justify-content-between align-items-center border-secondary">
                        <span>Đơn sắp hết hạn (trong 24h)</span>
                        <span class="badge bg-info px-2 py-1"><?php echo $expiring_soon; ?> đơn</span>
                    </li>
                    <li class="list-group-item bg-transparent text-light px-0 d-flex justify-content-between align-items-center border-secondary">
                        <span>Đơn lỗi chưa cấp tài khoản</span>
                        <a href="admin_borrow.php?status=pending" class="badge bg-danger px-2 py-1 text-decoration-none">
                            <?php echo $failed_allocations; ?> đơn
                        </a>
                    </li>
                    <li class="list-group-item bg-transparent text-light px-0 d-flex justify-content-between align-items-center border-secondary">
                        <span>Tổng thành viên</span>
                        <span class="badge bg-secondary px-2 py-1"><?php echo $total_users; ?> người dùng</span>
                    </li>
                </ul>

                <div class="mt-4 pt-3 border-top border-secondary">
                    <a href="admin_accounts.php" class="btn btn-primary w-100 fw-bold mb-2">+ Nhập Tài Khoản Vào Kho</a>
                    <a href="admin_borrow.php" class="btn btn-outline-secondary w-100">Xem Danh Sách Đơn Thuê</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('revenueChart').getContext('2d');
    let gradient = ctx.createLinearGradient(0, 0, 0, 350);
    gradient.addColorStop(0, 'rgba(16, 185, 129, 0.7)');
    gradient.addColorStop(1, 'rgba(16, 185, 129, 0.05)');

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12'],
            datasets: [{
                label: 'Doanh thu (VNĐ)',
                data: [<?php echo $chart_rev_js; ?>],
                backgroundColor: gradient,
                borderColor: '#10b981',
                borderWidth: 2,
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#cbd5e1' } }
            },
            scales: {
                x: { ticks: { color: '#94a3b8' }, grid: { display: false } },
                y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' } }
            }
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>