<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Import file header (Đã bao gồm kết nối CSDL và Sidebar)
require_once __DIR__ . '/includes/header.php';

// Kiểm tra quyền Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    echo "<script>alert('Cảnh báo: Bạn không có quyền truy cập khu vực này!'); window.location='index.php';</script>";
    exit();
}

// ==========================================
// 1. LẤY DỮ LIỆU THỐNG KÊ TỔNG QUAN
// ==========================================
$total_users = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 0")->fetch_assoc()['c'];
$total_games = $conn->query("SELECT COUNT(*) as c FROM games")->fetch_assoc()['c'];
$active_borrows = $conn->query("SELECT COUNT(*) as c FROM borrow_records WHERE status = 'active'")->fetch_assoc()['c'];
$total_borrows = $conn->query("SELECT COUNT(*) as c FROM borrow_records")->fetch_assoc()['c'];

// ==========================================
// 2. CHUẨN BỊ DỮ LIỆU CHO BIỂU ĐỒ (Thống kê theo 12 tháng của năm hiện tại)
// ==========================================
$current_year = date('Y');
$chart_data = array_fill(1, 12, 0); // Tạo mảng 12 tháng với giá trị ban đầu là 0

$sql_chart = "SELECT MONTH(borrow_date) as m, COUNT(*) as cnt 
              FROM borrow_records 
              WHERE YEAR(borrow_date) = $current_year 
              GROUP BY MONTH(borrow_date)";
$res_chart = $conn->query($sql_chart);

if ($res_chart && $res_chart->num_rows > 0) {
    while ($row = $res_chart->fetch_assoc()) {
        $chart_data[(int)$row['m']] = (int)$row['cnt'];
    }
}

// Chuyển mảng PHP thành chuỗi JS để vẽ biểu đồ
$chart_data_js = implode(',', $chart_data);
?>

<!-- NỘI DUNG TRANG DASHBOARD -->
<div class="container-fluid mt-4 mb-5 text-white">
    
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary">
        <h2 class="fw-bold m-0" style="background: linear-gradient(90deg, #f59e0b, #fbbf24); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
            <i class="bi bi-graph-up-arrow text-warning"></i> BÁO CÁO & THỐNG KÊ (NĂM <?php echo $current_year; ?>)
        </h2>
        <button class="btn btn-outline-light btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> In Báo Cáo</button>
    </div>

    <!-- HÀNG THẺ THỐNG KÊ (CARDS) -->
    <div class="row g-4 mb-5">
        <!-- Thẻ Số lượng Game -->
        <div class="col-md-3">
            <div class="card bg-transparent border-0 h-100">
                <div class="card-body rounded-4 d-flex align-items-center p-4" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(37, 99, 235, 0.4)); border: 1px solid rgba(59, 130, 246, 0.3); backdrop-filter: blur(10px);">
                    <div class="me-4 text-primary" style="font-size: 3rem;"><i class="bi bi-controller"></i></div>
                    <div>
                        <h6 class="text-uppercase text-light fw-bold mb-1">Tổng Tựa Game</h6>
                        <h2 class="fw-bold text-white m-0"><?php echo $total_games; ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Thẻ Số lượng Thành viên -->
        <div class="col-md-3">
            <div class="card bg-transparent border-0 h-100">
                <div class="card-body rounded-4 d-flex align-items-center p-4" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(5, 150, 105, 0.4)); border: 1px solid rgba(16, 185, 129, 0.3); backdrop-filter: blur(10px);">
                    <div class="me-4 text-success" style="font-size: 3rem;"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <h6 class="text-uppercase text-light fw-bold mb-1">Thành Viên</h6>
                        <h2 class="fw-bold text-white m-0"><?php echo $total_users; ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Thẻ Đơn đang mượn -->
        <div class="col-md-3">
            <div class="card bg-transparent border-0 h-100">
                <div class="card-body rounded-4 d-flex align-items-center p-4" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(217, 119, 6, 0.4)); border: 1px solid rgba(245, 158, 11, 0.3); backdrop-filter: blur(10px);">
                    <div class="me-4 text-warning" style="font-size: 3rem;"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <h6 class="text-uppercase text-light fw-bold mb-1">Đang Cho Mượn</h6>
                        <h2 class="fw-bold text-white m-0"><?php echo $active_borrows; ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Thẻ Tổng Lượt Giao Dịch -->
        <div class="col-md-3">
            <div class="card bg-transparent border-0 h-100">
                <div class="card-body rounded-4 d-flex align-items-center p-4" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), rgba(79, 70, 229, 0.4)); border: 1px solid rgba(99, 102, 241, 0.3); backdrop-filter: blur(10px);">
                    <div class="me-4 text-info" style="font-size: 3rem;"><i class="bi bi-bag-check-fill"></i></div>
                    <div>
                        <h6 class="text-uppercase text-light fw-bold mb-1">Tổng Lượt Mượn</h6>
                        <h2 class="fw-bold text-white m-0"><?php echo $total_borrows; ?></h2>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KHU VỰC BIỂU ĐỒ -->
    <div class="row">
        <div class="col-12">
            <div class="card bg-transparent border-0">
                <div class="card-body p-4 rounded-4" style="background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(255,255,255,0.1); backdrop-filter: blur(10px);">
                    <h5 class="fw-bold text-info mb-4">Biểu Đồ Lượt Mượn Theo Tháng (Năm <?php echo $current_year; ?>)</h5>
                    
                    <!-- Nơi hiển thị biểu đồ -->
                    <div style="height: 400px; width: 100%;">
                        <canvas id="borrowChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- NHÚNG THƯ VIỆN CHART.JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const ctx = document.getElementById('borrowChart').getContext('2d');
        
        // Cấu hình màu Gradient cho biểu đồ
        let gradient = ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(99, 102, 241, 0.8)'); // Xanh tím đậm
        gradient.addColorStop(1, 'rgba(99, 102, 241, 0.1)'); // Mờ dần xuống dưới

        const borrowChart = new Chart(ctx, {
            type: 'line', // Biểu đồ đường
            data: {
                labels: ['Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6', 'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'],
                datasets: [{
                    label: 'Số lượt mượn game',
                    data: [<?php echo $chart_data_js; ?>], // Đổ dữ liệu từ PHP vào JS
                    borderColor: '#818cf8',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#818cf8',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    fill: true,
                    tension: 0.4 // Làm cong đường nối
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#cbd5e1', font: { family: 'Nunito', size: 14 } }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255,255,255,0.05)', borderColor: 'transparent' },
                        ticks: { color: '#94a3b8', font: { family: 'Nunito' } }
                    },
                    y: {
                        grid: { color: 'rgba(255,255,255,0.05)', borderColor: 'transparent' },
                        ticks: { 
                            color: '#94a3b8', 
                            font: { family: 'Nunito' },
                            stepSize: 1, // Đảm bảo số mượn là số nguyên
                            beginAtZero: true 
                        }
                    }
                }
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>