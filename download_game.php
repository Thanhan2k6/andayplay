<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/header.php';

// Kiểm tra quyền
if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('Vui lòng đăng nhập!'); window.location='login.php';</script>";
    exit();
}

if (!isset($_GET['id'])) {
    echo "<script>window.location='index.php';</script>";
    exit();
}

$game_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// Lấy thông tin game và kiểm tra đơn thuê
$sql = "SELECT g.title, g.image_url, g.price_type, g.download_link, 
               br.borrow_id, br.due_date, br.status, br.account_id,
               ga.account_username, ga.account_password_encrypted, ga.server_region
        FROM games g 
        LEFT JOIN borrow_records br ON g.game_id = br.game_id AND br.user_id = $user_id AND br.status = 'active'
        LEFT JOIN game_accounts ga ON br.account_id = ga.account_id
        WHERE g.game_id = $game_id";
$result = $conn->query($sql);

if (!$result || $result->num_rows == 0) {
    echo "<script>window.location='index.php';</script>";
    exit();
}
$game = $result->fetch_assoc();

$is_allowed = false;
$plain_password = "";

if ($game['price_type'] == 'free') {
    $is_allowed = true;
} else {
    if (!empty($game['status']) && $game['status'] == 'active' && strtotime($game['due_date']) > time()) {
        $is_allowed = true;
        if (!empty($game['account_password_encrypted'])) {
            $plain_password = decrypt_game_password($game['account_password_encrypted']);
        }
    }
}

if (!$is_allowed) {
    echo "<script>alert('Gói thuê tài khoản của bạn đã hết hạn hoặc chưa được kích hoạt!'); window.location='game_detail.php?id=$game_id';</script>";
    exit();
}
?>

<div class="container mt-5 mb-5 d-flex justify-content-center">
    <div class="card bg-dark text-white border-secondary shadow-lg rounded-4 w-100" style="max-width: 650px;">
        <div class="card-body p-5 text-center">
            
            <h3 class="fw-bold mb-3 text-info"><i class="bi bi-shield-check"></i> TRUNG TÂM TÀI KHOẢN & BỘ CÀI</h3>
            <h4 class="text-warning fw-bold mb-4 text-uppercase"><?php echo htmlspecialchars($game['title']); ?></h4>
            
            <?php if ($game['price_type'] != 'free'): ?>
                <!-- THÔNG TIN TÀI KHOẢN CHO THUÊ -->
                <div class="bg-black p-4 rounded-3 border border-secondary mb-4 text-start shadow-sm">
                    <small class="text-info d-block mb-3 fw-bold"><i class="bi bi-person-fill-lock me-1"></i> THÔNG TIN TÀI KHOẢN GAME ĐÃ CẤP CHO BẠN:</small>
                    
                    <div class="mb-3">
                        <small class="text-secondary">Tên đăng nhập / Email:</small>
                        <div class="d-flex justify-content-between align-items-center bg-dark p-2 rounded border border-secondary mt-1">
                            <strong class="text-white user-select-all"><?php echo htmlspecialchars($game['account_username']); ?></strong>
                            <button class="btn btn-sm btn-outline-info py-0 px-2" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($game['account_username']); ?>')">Sao chép</button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <small class="text-secondary">Mật khẩu:</small>
                        <div class="d-flex justify-content-between align-items-center bg-dark p-2 rounded border border-secondary mt-1">
                            <strong class="text-warning user-select-all"><?php echo htmlspecialchars($plain_password); ?></strong>
                            <button class="btn btn-sm btn-outline-warning py-0 px-2" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($plain_password); ?>')">Sao chép</button>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center text-muted small">
                        <span>Máy chủ: <strong class="text-light"><?php echo htmlspecialchars($game['server_region'] ?? 'Global'); ?></strong></span>
                        <span>Thời hạn đến: <strong class="text-danger"><?php echo date('d/m/Y H:i', strtotime($game['due_date'])); ?></strong></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- NÚT TẢI BỘ CÀI (NẾU CÓ PHÂN PHỐI HỢP PHÁP) -->
            <?php if (!empty($game['download_link'])): ?>
                <a href="<?php echo htmlspecialchars($game['download_link']); ?>" target="_blank" class="btn btn-primary btn-lg rounded-pill px-5 shadow fw-bold w-100 py-3 mb-3 d-flex align-items-center justify-content-center">
                    <i class="bi bi-cloud-arrow-down-fill me-2 fs-4"></i> TẢI XUỐNG BỘ CÀI ĐẶT CHÍNH THỨC
                </a>
            <?php endif; ?>
            
            <div>
                <a href="my_library.php" class="btn btn-outline-secondary px-4 py-2"><i class="bi bi-arrow-left"></i> Về Thư Viện Của Tôi</a>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>