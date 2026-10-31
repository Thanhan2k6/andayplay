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
$sql = "SELECT g.title, g.image_url, g.price_type, g.download_link, g.platform,
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

if (!empty($game['status']) && $game['status'] == 'active' && strtotime($game['due_date']) > time()) {
    $is_allowed = true;
    if (!empty($game['account_password_encrypted'])) {
        $plain_password = decrypt_game_password($game['account_password_encrypted']);
    }
}

if (!$is_allowed) {
    echo "<script>alert('Gói thuê tài khoản của bạn đã hết hạn hoặc chưa được kích hoạt!'); window.location='game_detail.php?id=$game_id';</script>";
    exit();
}
?>

<div class="container py-5 my-3">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-secondary"><i class="bi bi-house me-1"></i>Trang chủ</a></li>
            <li class="breadcrumb-item"><a href="my_library.php" class="text-decoration-none text-secondary">Thư viện của tôi</a></li>
            <li class="breadcrumb-item active text-white" aria-current="page"><?php echo htmlspecialchars($game['title']); ?></li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="p-4 p-md-5 rounded-4 shadow-lg text-white" style="background: var(--bg-card); border: 1px solid var(--border-color); position: relative; overflow: hidden;">
                <!-- Glowing Top Stripe -->
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--primary), var(--accent-cyan));"></div>

                <div class="text-center mb-4">
                    <div class="d-inline-flex p-3 rounded-4 mb-3" style="background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.3);">
                        <i class="bi bi-shield-check fs-2" style="color: var(--primary);"></i>
                    </div>
                    <span class="d-block text-secondary small text-uppercase fw-bold letter-spacing-1 mb-1">TRUNG TÂM TÀI KHOẢN & BỘ CÀI ĐẶT</span>
                    <h2 class="fw-extrabold text-white mb-2"><?php echo htmlspecialchars($game['title']); ?></h2>
                    <div class="d-flex justify-content-center gap-2">
                        <span class="platform-pill"><i class="bi bi-display me-1"></i><?php echo htmlspecialchars($game['platform'] ?? 'PC'); ?></span>
                        <span class="status-pill status-pill-success"><span class="status-dot"></span> ĐANG KÍCH HOẠT</span>
                    </div>
                </div>

                <!-- THÔNG TIN TÀI KHOẢN CHO THUÊ -->
                <div class="p-4 rounded-3 mb-4 text-start" style="background: rgba(15, 17, 24, 0.9); border: 1px solid var(--border-color);">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                            <span class="small fw-bold text-info"><i class="bi bi-person-fill-lock me-1"></i> TÀI KHOẢN GAME ĐÃ CẤP CHO BẠN</span>
                            <span class="badge" style="background: rgba(255,255,255,0.06); color: #94a3b8; font-size: 0.75rem; border: 1px solid var(--border-color);">
                                Khu vực: <?php echo htmlspecialchars($game['server_region'] ?? 'Global'); ?>
                            </span>
                        </div>
                        
                        <!-- Username Row -->
                        <div class="mb-3">
                            <label class="small text-secondary d-block mb-1">Tên đăng nhập / Email:</label>
                            <div class="input-group">
                                <input type="text" class="form-control form-control-gaming text-white fw-bold user-select-all" value="<?php echo htmlspecialchars($game['account_username']); ?>" readonly id="dl_username">
                                <button class="btn btn-gaming-secondary" type="button" onclick="copyValue('dl_username', this)">
                                    <i class="bi bi-clipboard me-1"></i> Sao chép
                                </button>
                            </div>
                        </div>

                        <!-- Password Row -->
                        <div class="mb-3">
                            <label class="small text-secondary d-block mb-1">Mật khẩu game:</label>
                            <div class="input-group">
                                <input type="password" class="form-control form-control-gaming text-warning fw-bold user-select-all" value="<?php echo htmlspecialchars($plain_password); ?>" readonly id="dl_password">
                                <button class="btn btn-gaming-secondary" type="button" onclick="togglePass('dl_password', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-gaming-secondary" type="button" onclick="copyValue('dl_password', this)">
                                    <i class="bi bi-clipboard me-1"></i> Sao chép
                                </button>
                            </div>
                        </div>

                        <!-- Due Date Info -->
                        <div class="d-flex justify-content-between align-items-center pt-2 border-top border-secondary border-opacity-25 text-secondary small">
                            <span><i class="bi bi-clock me-1"></i> Thời hạn sử dụng đến:</span>
                            <strong class="text-danger fs-6"><?php echo date('d/m/Y H:i', strtotime($game['due_date'])); ?></strong>
                        </div>
                    </div>

                <!-- NÚT TẢI BỘ CÀI ĐẶT HOẶC MỞ LAUNCHER CHÍNH THỨC -->
                <div class="mb-4">
                    <?php if (!empty($game['download_link'])): ?>
                        <a href="<?php echo htmlspecialchars($game['download_link']); ?>" target="_blank" class="btn btn-gaming-primary w-100 py-3 fs-5 d-flex align-items-center justify-content-center text-decoration-none shadow mb-2">
                            <i class="bi bi-cloud-arrow-down-fill me-2 fs-4"></i> TẢI XUỐNG BỘ CÀI ĐẶT GAME
                        </a>
                    <?php endif; ?>

                    <div class="p-3 rounded-3 mt-2 text-start" style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-color);">
                        <small class="text-light fw-bold d-block mb-2"><i class="bi bi-laptop me-1 text-primary"></i> Khởi Động Launcher Game Trên Máy Bạn:</small>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="steam://open/main" class="btn btn-sm btn-gaming-secondary py-1 px-3">
                                <i class="bi bi-steam me-1"></i> Mở Steam Client
                            </a>
                            <a href="https://store.steampowered.com/about/" target="_blank" class="btn btn-sm btn-outline-secondary py-1 px-2 text-secondary" style="font-size: 0.78rem;">
                                Cài đặt Steam
                            </a>
                            <a href="https://store.epicgames.com/download" target="_blank" class="btn btn-sm btn-gaming-secondary py-1 px-3">
                                <i class="bi bi-box-arrow-up-right me-1"></i> Epic Games
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quy định an toàn tài khoản -->
                <div class="p-3 rounded-3 mb-4 text-start" style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25);">
                    <small class="text-warning fw-bold d-block mb-1"><i class="bi bi-shield-exclamation me-1"></i> Quy định sử dụng tài khoản thuê:</small>
                    <ul class="text-secondary small mb-0 ps-3">
                        <li>Không tự ý đổi mật khẩu, email hoặc thêm số điện thoại vào tài khoản.</li>
                        <li>Chỉ đăng nhập chơi trên 01 thiết bị trong suốt thời gian thuê.</li>
                        <li>Nghiêm cấm hành vi sử dụng phần mềm thứ ba (hack/cheat) gây khóa tài khoản.</li>
                    </ul>
                </div>

                <div class="text-center">
                    <a href="my_library.php" class="btn btn-gaming-secondary px-4 py-2 text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i> Quay lại Thư viện của tôi
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyValue(inputId, btn) {
    var input = document.getElementById(inputId);
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(function() {
        var originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 text-success me-1"></i> Đã sao chép';
        setTimeout(function() {
            btn.innerHTML = originalHtml;
        }, 1500);
    });
}

function togglePass(inputId, btn) {
    var input = document.getElementById(inputId);
    var icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>

<?php include 'includes/footer.php'; ?>