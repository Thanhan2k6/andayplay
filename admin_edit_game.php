<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php'; 

// Bảo mật: Kiểm tra quyền Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    echo "<script>window.location='index.php';</script>";
    exit();
}

if(!isset($_GET['id'])) {
    echo "<script>window.location='admin_games.php';</script>";
    exit();
}
$game_id = intval($_GET['id']);

$result = $conn->query("SELECT * FROM games WHERE game_id = $game_id");
if(!$result || $result->num_rows == 0) {
    echo "<script>alert('Không tìm thấy tựa game này!'); window.location='admin_games.php';</script>";
    exit();
}
$game = $result->fetch_assoc();

$current_price_type = isset($game['price_type']) ? $game['price_type'] : 'paid';
$current_download_link = isset($game['download_link']) ? $game['download_link'] : '';

// Xử lý khi Admin cập nhật thông tin game và các gói thuê
if(isset($_POST['btn_update'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $title = $conn->real_escape_string($_POST['title']);
        $category_id = intval($_POST['category_id']);
        $platform = isset($_POST['platform']) ? $conn->real_escape_string($_POST['platform']) : 'PC';
        $description = $conn->real_escape_string($_POST['description']);
        $long_description = $conn->real_escape_string($_POST['long_description']);
        $system_req = $conn->real_escape_string($_POST['system_req']);
        $price_type = isset($_POST['price_type']) ? $_POST['price_type'] : 'paid';
        $download_link = ($price_type == 'free') ? $conn->real_escape_string($_POST['download_link']) : '';
        
        $image_url = $game['image_url'];

        if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $target_dir = "uploads/";
            if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $extension = pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION);
            $file_name = $game_id . "." . $extension;
            $target_file = $target_dir . $file_name;
            if(move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
                $image_url = $target_file;
            }
        }

        $sql = "UPDATE games SET 
                title='$title', 
                category_id=$category_id, 
                platform='$platform',
                description='$description', 
                long_description='$long_description', 
                system_req='$system_req', 
                price_type='$price_type',
                download_link='$download_link',
                image_url='$image_url' 
                WHERE game_id=$game_id";

        if($conn->query($sql) === TRUE) {
            // Cập nhật giá các gói thời hạn thuê nếu là game trả phí
            if ($price_type == 'paid' && isset($_POST['pkg_price']) && is_array($_POST['pkg_price'])) {
                foreach ($_POST['pkg_price'] as $pkg_id => $pkg_val) {
                    $pkg_id = intval($pkg_id);
                    $pkg_val = floatval($pkg_val);
                    $conn->query("UPDATE rental_packages SET price = $pkg_val WHERE package_id = $pkg_id AND game_id = $game_id");
                }
            }

            // Đồng bộ lại tồn kho
            sync_game_inventory($conn, $game_id);

            echo "<script>alert('Cập nhật thông tin game và giá thuê thành công!'); window.location='admin_games.php';</script>";
            exit();
        } else {
            echo "<div class='alert alert-danger text-center'>Lỗi cập nhật: " . $conn->error . "</div>";
        }
    }
}

// Lấy danh sách gói thuê của game
$packages = $conn->query("SELECT * FROM rental_packages WHERE game_id = $game_id ORDER BY duration_type DESC, duration_value ASC");
$csrf_token = generate_csrf_token();
?>

<div class="container-fluid px-lg-5 py-4 my-2 text-white">
    <!-- Breadcrumb & Title -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="admin_dashboard.php" class="text-decoration-none text-secondary">Admin Console</a></li>
            <li class="breadcrumb-item"><a href="admin_games.php" class="text-decoration-none text-secondary">Quản lý Game</a></li>
            <li class="breadcrumb-item active text-white" aria-current="page">Chỉnh Sửa</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" style="background: rgba(99,102,241,0.2); color: var(--primary); font-size: 0.75rem; border: 1px solid rgba(99,102,241,0.4);">
                    GAME ID: #<?php echo $game_id; ?>
                </span>
            </div>
            <h2 class="fw-extrabold text-white mb-0">Chỉnh Sửa Game: <span class="text-info"><?php echo htmlspecialchars($game['title']); ?></span></h2>
        </div>
        <div class="d-flex gap-2">
            <a href="admin_accounts.php?filter_game=<?php echo $game_id; ?>" class="btn btn-gaming-secondary">
                <i class="bi bi-box-seam me-1 text-warning"></i> Quản Lý Kho TK
            </a>
            <a href="admin_games.php" class="btn btn-gaming-secondary">
                <i class="bi bi-arrow-left me-1"></i> Trở Lại
            </a>
        </div>
    </div>

    <!-- Form Container -->
    <div class="p-4 p-md-5 rounded-4 shadow-sm" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <!-- Section 1: Thông tin cơ bản -->
            <div class="mb-5">
                <h5 class="fw-bold text-info mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-info-circle me-1"></i> 1. Thông Tin Cơ Bản
                </h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-light small fw-bold">Tên Game <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-gaming" value="<?php echo htmlspecialchars($game['title']); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-light small fw-bold">Thể Loại</label>
                        <select name="category_id" class="form-select form-control-gaming" required>
                            <?php
                            $cates = $conn->query("SELECT * FROM categories");
                            if($cates) {
                                while($c = $cates->fetch_assoc()) {
                                    $selected = ($c['category_id'] == $game['category_id']) ? 'selected' : '';
                                    echo "<option value='".$c['category_id']."' $selected>".$c['cate_name']."</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-light small fw-bold">Nền Tảng</label>
                        <select name="platform" class="form-select form-control-gaming" required>
                            <option value="PC" <?php echo ($game['platform'] == 'PC') ? 'selected' : ''; ?>>🖥️ PC</option>
                            <option value="PlayStation" <?php echo ($game['platform'] == 'PlayStation') ? 'selected' : ''; ?>>🎮 PlayStation</option>
                            <option value="Xbox" <?php echo ($game['platform'] == 'Xbox') ? 'selected' : ''; ?>>💚 Xbox</option>
                            <option value="Nintendo" <?php echo ($game['platform'] == 'Nintendo') ? 'selected' : ''; ?>>🕹️ Nintendo</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-light small fw-bold">Mô Hình Kinh Doanh</label>
                        <select name="price_type" id="edit_price_type" class="form-select form-control-gaming" onchange="toggleEditPricing()">
                            <option value="paid" <?php echo ($current_price_type == 'paid') ? 'selected' : ''; ?>>💎 Cho thuê tài khoản có phí</option>
                            <option value="free" <?php echo ($current_price_type == 'free') ? 'selected' : ''; ?>>🎁 Game miễn phí</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-light small fw-bold">Ảnh Bìa Game (Chọn để thay mới)</label>
                        <input type="file" name="image" class="form-control form-control-gaming" accept="image/*">
                        <?php if(!empty($game['image_url'])): ?>
                            <div class="mt-2 d-flex align-items-center gap-2">
                                <img src="<?php echo htmlspecialchars($game['image_url']); ?>" alt="" style="width: 80px; height: 45px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color);">
                                <span class="small text-secondary">Ảnh hiện tại: <?php echo htmlspecialchars($game['image_url']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Section 2: Gói Giá Thuê -->
            <div class="mb-5 <?php echo ($current_price_type == 'free') ? 'd-none' : ''; ?>" id="edit_pricing_section">
                <h5 class="fw-bold text-warning mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-tag-fill me-1"></i> 2. Giá Các Gói Cho Thuê Tài Khoản
                </h5>
                <div class="row g-3">
                    <?php 
                    if ($packages && $packages->num_rows > 0):
                        while ($pkg = $packages->fetch_assoc()):
                    ?>
                        <div class="col-sm-6 col-md-3">
                            <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                                <label class="form-label text-secondary small fw-bold mb-1">
                                    <?php echo htmlspecialchars($pkg['package_name']); ?>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="pkg_price[<?php echo $pkg['package_id']; ?>]" class="form-control form-control-gaming text-white" value="<?php echo floatval($pkg['price']); ?>" step="1000">
                                    <span class="input-group-text bg-dark border-secondary text-secondary">đ</span>
                                </div>
                            </div>
                        </div>
                    <?php 
                        endwhile;
                    else: 
                    ?>
                        <div class="col-12">
                            <div class="alert text-white" style="background: rgba(245, 158, 11, 0.15); border: 1px solid #f59e0b;">
                                <i class="bi bi-info-circle me-1"></i> Tựa game này chưa có bảng giá thuê cụ thể. Hãy thêm các gói thuê vào database hoặc lưu lại để tạo mặc định.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Link Tải (nếu game miễn phí) -->
            <div class="mb-5 <?php echo ($current_price_type != 'free') ? 'd-none' : ''; ?>" id="edit_dl_section">
                <h5 class="fw-bold text-info mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-cloud-arrow-down me-1"></i> Link Tải Bộ Cài Miễn Phí
                </h5>
                <div>
                    <label class="form-label text-light small fw-bold">Đường dẫn tải game</label>
                    <input type="url" name="download_link" class="form-control form-control-gaming" value="<?php echo htmlspecialchars($current_download_link); ?>">
                </div>
            </div>

            <!-- Section 3: Mô tả & Cấu hình -->
            <div class="mb-4">
                <h5 class="fw-bold text-success mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-card-text me-1"></i> 3. Mô Tả Chi Tiết & Yêu Cầu Cấu Hình
                </h5>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label text-light small fw-bold">Mô Tả Ngắn</label>
                        <textarea name="description" class="form-control form-control-gaming" rows="2"><?php echo htmlspecialchars($game['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-light small fw-bold">Giới Thiệu Chi Tiết Game</label>
                        <textarea name="long_description" class="form-control form-control-gaming" rows="6"><?php echo htmlspecialchars($game['long_description'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-light small fw-bold">Yêu Cầu Cấu Hình Phần Cứng</label>
                        <textarea name="system_req" class="form-control form-control-gaming" rows="6"><?php echo htmlspecialchars($game['system_req'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Actions Footer -->
            <div class="pt-3 border-top border-secondary border-opacity-25 d-flex justify-content-end gap-2">
                <a href="admin_games.php" class="btn btn-gaming-secondary px-4 py-2">Hủy bỏ</a>
                <button type="submit" name="btn_update" class="btn btn-gaming-primary px-5 py-2">
                    <i class="bi bi-check-circle me-1"></i> LƯU THAY ĐỔI
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleEditPricing() {
    var select = document.getElementById('edit_price_type');
    var pricingSec = document.getElementById('edit_pricing_section');
    var dlSec = document.getElementById('edit_dl_section');
    if (select.value === 'free') {
        pricingSec.classList.add('d-none');
        dlSec.classList.remove('d-none');
    } else {
        pricingSec.classList.remove('d-none');
        dlSec.classList.add('d-none');
    }
}
</script>

<?php include 'includes/footer.php'; ?>