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

<div class="container mt-4 text-white pb-5">
    <h2 class="mb-4">Chỉnh Sửa Game: <span class="text-info"><?php echo htmlspecialchars($game['title']); ?></span></h2>
    
    <div class="card shadow" style="background-color: #1a222c; border: 1px solid #2d3b4e;">
        <div class="card-body p-4">
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info fw-bold">Tên Game</label>
                        <input type="text" name="title" class="form-control bg-dark text-white border-secondary" value="<?php echo htmlspecialchars($game['title']); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info fw-bold">Thể loại</label>
                        <select name="category_id" class="form-select bg-dark text-white border-secondary" required>
                            <?php
                            $cates = $conn->query("SELECT * FROM categories");
                            while($c = $cates->fetch_assoc()) {
                                $selected = ($c['category_id'] == $game['category_id']) ? "selected" : "";
                                echo "<option value='".$c['category_id']."' $selected>".$c['cate_name']."</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info fw-bold">Nền tảng (Platform)</label>
                        <select name="platform" class="form-select bg-dark text-white border-secondary" required>
                            <option value="PC" <?php echo (isset($game['platform']) && $game['platform'] == 'PC') ? 'selected' : ''; ?>>🖥️ PC</option>
                            <option value="Mobile" <?php echo (isset($game['platform']) && $game['platform'] == 'Mobile') ? 'selected' : ''; ?>>📱 Mobile</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info fw-bold">Mô Hình Dịch Vụ</label>
                        <select name="price_type" id="priceTypeSelect" class="form-select bg-dark text-white border-secondary" required onchange="toggleDownloadLink()">
                            <option value="paid" <?php if($current_price_type == 'paid') echo 'selected'; ?>>Cho thuê tài khoản trả phí</option>
                            <option value="free" <?php if($current_price_type == 'free') echo 'selected'; ?>>Game Miễn phí (Link tải ngoài)</option>
                        </select>
                    </div>
                </div>

                <!-- CẤU HÌNH BẢNG GIÁ CÁC GÓI THỜI HẠN THUÊ -->
                <div class="p-3 mb-4 rounded border border-secondary" id="packagesDiv" style="<?php echo ($current_price_type == 'paid') ? 'display: block; background: rgba(0, 243, 255, 0.03);' : 'display: none;'; ?>">
                    <h5 class="text-warning fw-bold mb-3"><i class="bi bi-clock-history me-1"></i> Quản Lý Bảng Giá Các Gói Thời Hạn Thuê</h5>
                    <div class="row g-3">
                        <?php if ($packages && $packages->num_rows > 0): ?>
                            <?php while ($pkg = $packages->fetch_assoc()): ?>
                                <div class="col-md-3">
                                    <label class="form-label text-light small"><?php echo htmlspecialchars($pkg['package_name']); ?> (VNĐ):</label>
                                    <input type="number" name="pkg_price[<?php echo $pkg['package_id']; ?>]" class="form-control bg-dark text-white border-secondary" value="<?php echo floatval($pkg['price']); ?>" min="1000" step="1000">
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="col-12"><small class="text-secondary">Chưa có gói thuê, hệ thống sẽ tự sinh các gói chuẩn.</small></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="row" id="downloadLinkDiv" style="<?php echo ($current_price_type == 'free') ? 'display: block;' : 'display: none;'; ?>">
                    <div class="col-12 mb-3">
                        <label class="form-label text-info fw-bold">Link tải chính thức (Dành cho Miễn phí)</label>
                        <input type="url" name="download_link" class="form-control bg-dark text-white border-secondary" value="<?php echo htmlspecialchars($current_download_link); ?>" placeholder="https://store.steampowered.com/...">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-info fw-bold">Ảnh bìa hiện tại:</label>
                    <div class="mb-2">
                        <?php if(!empty($game['image_url']) && file_exists($game['image_url'])) { ?>
                            <img src="<?php echo $game['image_url']; ?>" alt="Cover" style="height: 100px; border-radius: 4px; object-fit: cover;">
                        <?php } else { ?>
                            <span class="text-muted">Chưa có ảnh bìa</span>
                        <?php } ?>
                    </div>
                    <label class="form-label text-info">Đổi ảnh bìa mới (Bỏ trống nếu giữ nguyên)</label>
                    <input type="file" name="image" class="form-control bg-dark text-white border-secondary" accept="image/*">
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-info fw-bold">Mô tả tóm tắt</label>
                    <textarea name="description" class="form-control bg-dark text-white border-secondary" rows="3" required><?php echo htmlspecialchars($game['description']); ?></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label text-warning fw-bold">Mô tả chi tiết & Điều khoản dịch vụ</label>
                    <textarea name="long_description" class="form-control bg-dark text-white border-secondary" rows="10"><?php echo htmlspecialchars($game['long_description'] ?? ''); ?></textarea>
                </div>
                
                <div class="mb-4">
                    <label class="form-label text-info fw-bold">Cấu hình yêu cầu</label>
                    <input type="text" name="system_req" class="form-control bg-dark text-white border-secondary" value="<?php echo htmlspecialchars($game['system_req']); ?>" required>
                </div>
                
                <div class="text-end">
                    <a href="admin_games.php" class="btn btn-secondary me-2">Quay lại</a>
                    <button type="submit" name="btn_update" class="btn btn-warning px-4 fw-bold">LƯU THAY ĐỔI</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleDownloadLink() {
    var select = document.getElementById('priceTypeSelect');
    var div = document.getElementById('downloadLinkDiv');
    var pkgDiv = document.getElementById('packagesDiv');
    if (select.value === 'free') {
        div.style.display = 'block';
        pkgDiv.style.display = 'none';
    } else {
        div.style.display = 'none';
        pkgDiv.style.display = 'block';
    }
}
</script>

<?php include 'includes/footer.php'; ?>