<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$page_title = "Chỉnh Sửa Thông Tin Game";
require_once __DIR__ . '/includes/admin_header.php';

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
        $price_type = 'paid';
        $download_link = '';
        
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
            // Cập nhật giá các gói thời hạn thuê hiện có
            if (isset($_POST['pkg_price']) && is_array($_POST['pkg_price'])) {
                foreach ($_POST['pkg_price'] as $pkg_id => $pkg_val) {
                    $pkg_id = intval($pkg_id);
                    $pkg_val = floatval($pkg_val);
                    $conn->query("UPDATE rental_packages SET price = $pkg_val WHERE package_id = $pkg_id AND game_id = $game_id");
                }
            }

            // Tạo các gói mới nếu trước đó game chưa có gói
            if (isset($_POST['new_pkg_1h']) || isset($_POST['new_pkg_1d'])) {
                $p1 = floatval($_POST['new_pkg_1h'] ?? 5000);
                $p2 = floatval($_POST['new_pkg_1d'] ?? 15000);
                $p3 = floatval($_POST['new_pkg_7d'] ?? 30000);
                $p4 = floatval($_POST['new_pkg_30d'] ?? 99000);

                $conn->query("INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
                    SELECT $game_id, '1 Giờ (Trải nghiệm nhanh)', 'hours', 1, $p1, 1
                    WHERE NOT EXISTS (SELECT 1 FROM rental_packages WHERE game_id = $game_id AND duration_type = 'hours' AND duration_value = 1)");
                $conn->query("INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
                    SELECT $game_id, '1 Ngày (24 Giờ)', 'days', 1, $p2, 1
                    WHERE NOT EXISTS (SELECT 1 FROM rental_packages WHERE game_id = $game_id AND duration_type = 'days' AND duration_value = 1)");
                $conn->query("INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
                    SELECT $game_id, '7 Ngày (1 Tuần)', 'days', 7, $p3, 1
                    WHERE NOT EXISTS (SELECT 1 FROM rental_packages WHERE game_id = $game_id AND duration_type = 'days' AND duration_value = 7)");
                $conn->query("INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price, is_active)
                    SELECT $game_id, '30 Ngày (1 Tháng)', 'days', 30, $p4, 1
                    WHERE NOT EXISTS (SELECT 1 FROM rental_packages WHERE game_id = $game_id AND duration_type = 'days' AND duration_value = 30)");
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
                        <input type="text" class="form-control form-control-gaming text-success fw-bold" value="💎 Cho thuê tài khoản game bản quyền" readonly>
                        <input type="hidden" name="price_type" value="paid">
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
            <div class="mb-5" id="edit_pricing_section">
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
                        <div class="col-12 mb-2">
                            <div class="alert text-white" style="background: rgba(245, 158, 11, 0.15); border: 1px solid #f59e0b;">
                                <i class="bi bi-info-circle me-1"></i> Tựa game này chưa có bảng giá thuê cụ thể. Bạn hãy thiết lập giá cho các gói thời hạn bên dưới để lưu vào hệ thống:
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                                <label class="form-label text-secondary small fw-bold mb-1">1 Giờ (Trải nghiệm nhanh)</label>
                                <div class="input-group">
                                    <input type="number" name="new_pkg_1h" class="form-control form-control-gaming text-white" value="5000" step="1000">
                                    <span class="input-group-text bg-dark border-secondary text-secondary">đ</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                                <label class="form-label text-secondary small fw-bold mb-1">1 Ngày (24 Giờ)</label>
                                <div class="input-group">
                                    <input type="number" name="new_pkg_1d" class="form-control form-control-gaming text-white" value="15000" step="1000">
                                    <span class="input-group-text bg-dark border-secondary text-secondary">đ</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                                <label class="form-label text-secondary small fw-bold mb-1">7 Ngày (1 Tuần)</label>
                                <div class="input-group">
                                    <input type="number" name="new_pkg_7d" class="form-control form-control-gaming text-white" value="30000" step="1000">
                                    <span class="input-group-text bg-dark border-secondary text-secondary">đ</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                                <label class="form-label text-secondary small fw-bold mb-1">30 Ngày (1 Tháng)</label>
                                <div class="input-group">
                                    <input type="number" name="new_pkg_30d" class="form-control form-control-gaming text-white" value="99000" step="1000">
                                    <span class="input-group-text bg-dark border-secondary text-secondary">đ</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
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

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>