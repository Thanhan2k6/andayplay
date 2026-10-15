<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php'; 

// Bảo mật quyền Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 1) {
    echo "<script>window.location='index.php';</script>";
    exit();
}

// Xử lý lưu game và upload ảnh
if(isset($_POST['btn_add'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $title = $conn->real_escape_string($_POST['title']);
        $category_id = intval($_POST['category_id']);
        $platform = $conn->real_escape_string($_POST['platform']);
        $short_desc = $conn->real_escape_string($_POST['short_description']); 
        $long_desc = $conn->real_escape_string($_POST['long_description']); 
        $system_req = $conn->real_escape_string($_POST['system_req']);
        $price_type = $conn->real_escape_string($_POST['price_type']);
        $download_link = ($price_type == 'free') ? $conn->real_escape_string($_POST['download_link']) : '';
        
        $sql_insert = "INSERT INTO games (title, category_id, platform, description, long_description, system_req, total_quantity, available_quantity, price_type, download_link, image_url) 
                VALUES ('$title', $category_id, '$platform', '$short_desc', '$long_desc', '$system_req', 0, 0, '$price_type', '$download_link', '')";
                
        if($conn->query($sql_insert) === TRUE) {
            $new_game_id = $conn->insert_id; 
            
            // Xử lý upload ảnh bìa
            if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                $target_dir = "uploads/";
                if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                $extension = pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION);
                $file_name = $new_game_id . "." . $extension;
                $target_file = $target_dir . $file_name;
                if(move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
                    $conn->query("UPDATE games SET image_url = '$target_file' WHERE game_id = $new_game_id");
                }
            }
            
            // Tự động tạo 4 gói thời hạn thuê chuẩn nếu là game trả phí
            if ($price_type === 'paid') {
                $p1 = floatval($_POST['price_1h'] ?? 5000);
                $p2 = floatval($_POST['price_1d'] ?? 15000);
                $p3 = floatval($_POST['price_7d'] ?? 30000);
                $p4 = floatval($_POST['price_30d'] ?? 99000);

                $conn->query("INSERT INTO rental_packages (game_id, package_name, duration_type, duration_value, price) VALUES 
                    ($new_game_id, '1 Giờ (Trải nghiệm nhanh)', 'hours', 1, $p1),
                    ($new_game_id, '1 Ngày (24 Giờ)', 'days', 1, $p2),
                    ($new_game_id, '7 Ngày (1 Tuần)', 'days', 7, $p3),
                    ($new_game_id, '30 Ngày (1 Tháng)', 'days', 30, $p4)");
            }

            echo "<script>alert('Đã thêm tựa game mới thành công! Bạn có thể vào Kho Tài Khoản để nhập tài khoản cho game này.'); window.location='admin_games.php';</script>";
            exit();
        } else {
            echo "<div class='alert alert-danger text-center'>Lỗi hệ thống: " . $conn->error . "</div>";
        }
    }
}
$csrf_token = generate_csrf_token();
?>

<div class="container-fluid px-lg-5 py-4 my-2 text-white">
    <!-- Breadcrumb & Title -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="admin_dashboard.php" class="text-decoration-none text-secondary">Admin Console</a></li>
            <li class="breadcrumb-item"><a href="admin_games.php" class="text-decoration-none text-secondary">Quản lý Game</a></li>
            <li class="breadcrumb-item active text-white" aria-current="page">Thêm Game Mới</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25">
        <div>
            <h2 class="fw-extrabold text-white mb-1"><i class="bi bi-plus-circle text-primary me-2"></i>Thêm Game Mới Vào Cửa Hàng</h2>
            <p class="text-secondary small mb-0">Điền đầy đủ thông tin game, gói giá thuê và ảnh bìa để mở bán trên ANPLAY STORE</p>
        </div>
        <a href="admin_games.php" class="btn btn-gaming-secondary">
            <i class="bi bi-arrow-left me-1"></i> Trở Lại
        </a>
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
                        <input type="text" name="title" class="form-control form-control-gaming" placeholder="Ví dụ: Cyberpunk 2077, Black Myth: Wukong..." required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-light small fw-bold">Thể Loại <span class="text-danger">*</span></label>
                        <select name="category_id" class="form-select form-control-gaming" required>
                            <option value="">-- Chọn thể loại --</option>
                            <?php
                            $cates = $conn->query("SELECT * FROM categories");
                            if($cates) {
                                while($c = $cates->fetch_assoc()) {
                                    echo "<option value='".$c['category_id']."'>".$c['cate_name']."</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-light small fw-bold">Nền Tảng <span class="text-danger">*</span></label>
                        <select name="platform" class="form-select form-control-gaming" required>
                            <option value="PC">🖥️ PC (Steam, Epic, EA)</option>
                            <option value="PlayStation">🎮 PlayStation (PS4/PS5)</option>
                            <option value="Xbox">💚 Xbox Series</option>
                            <option value="Nintendo">🕹️ Nintendo Switch</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-light small fw-bold">Mô Hình Kinh Doanh</label>
                        <select name="price_type" id="price_type_select" class="form-select form-control-gaming" onchange="togglePricingSection()">
                            <option value="paid" selected>💎 Cho thuê tài khoản có phí (Gói giờ/ngày/tháng)</option>
                            <option value="free">🎁 Game miễn phí (Cung cấp link tải trực tiếp)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-light small fw-bold">Ảnh Bìa Game (Cover 16:9)</label>
                        <input type="file" name="image" class="form-control form-control-gaming" accept="image/*">
                    </div>
                </div>
            </div>

            <!-- Section 2: Gói Giá Thuê Tài Khoản -->
            <div class="mb-5" id="pricing_section">
                <h5 class="fw-bold text-warning mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-tag-fill me-1"></i> 2. Thiết Lập Giá Thuê (VNĐ)
                </h5>
                <div class="row g-3">
                    <div class="col-sm-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                            <label class="form-label text-secondary small fw-bold">Gói 1 Giờ (Trải nghiệm)</label>
                            <div class="input-group">
                                <input type="number" name="price_1h" class="form-control form-control-gaming text-white" value="5000" step="1000">
                                <span class="input-group-text bg-dark border-secondary text-secondary">đ</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                            <label class="form-label text-secondary small fw-bold">Gói 1 Ngày (24h)</label>
                            <div class="input-group">
                                <input type="number" name="price_1d" class="form-control form-control-gaming text-white" value="15000" step="1000">
                                <span class="input-group-text bg-dark border-secondary text-secondary">đ</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                            <label class="form-label text-secondary small fw-bold">Gói 7 Ngày (1 Tuần)</label>
                            <div class="input-group">
                                <input type="number" name="price_7d" class="form-control form-control-gaming text-white" value="30000" step="1000">
                                <span class="input-group-text bg-dark border-secondary text-secondary">đ</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                            <label class="form-label text-secondary small fw-bold">Gói 30 Ngày (1 Tháng)</label>
                            <div class="input-group">
                                <input type="number" name="price_30d" class="form-control form-control-gaming text-white" value="99000" step="1000">
                                <span class="input-group-text bg-dark border-secondary text-secondary">đ</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Link Tải (nếu game miễn phí) -->
            <div class="mb-5 d-none" id="download_link_section">
                <h5 class="fw-bold text-info mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-cloud-arrow-down me-1"></i> Link Tải Bộ Cài Miễn Phí
                </h5>
                <div>
                    <label class="form-label text-light small fw-bold">Đường dẫn tải game</label>
                    <input type="url" name="download_link" class="form-control form-control-gaming" placeholder="https://store.steampowered.com/app/...">
                </div>
            </div>

            <!-- Section 3: Mô tả & Cấu hình -->
            <div class="mb-4">
                <h5 class="fw-bold text-success mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <i class="bi bi-card-text me-1"></i> 3. Mô Tả Chi Tiết & Yêu Cầu Cấu Hình
                </h5>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label text-light small fw-bold">Mô Tả Ngắn (Hiển thị ở danh sách)</label>
                        <textarea name="short_description" class="form-control form-control-gaming" rows="2" placeholder="Tóm tắt ngắn gọn 1-2 câu về nội dung trò chơi..."></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-light small fw-bold">Giới Thiệu Chi Tiết Game</label>
                        <textarea name="long_description" class="form-control form-control-gaming" rows="5" placeholder="Cốt truyện, gameplay, các tính năng nổi bật..."></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-light small fw-bold">Yêu Cầu Cấu Hình Phần Cứng</label>
                        <textarea name="system_req" class="form-control form-control-gaming" rows="5" placeholder="HĐH: Windows 10/11 64-bit&#10;CPU: Intel Core i5 / AMD Ryzen 5&#10;RAM: 16 GB&#10;GPU: GTX 1660 / RTX 3060..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Actions Footer -->
            <div class="pt-3 border-top border-secondary border-opacity-25 d-flex justify-content-end gap-2">
                <a href="admin_games.php" class="btn btn-gaming-secondary px-4 py-2">Hủy bỏ</a>
                <button type="submit" name="btn_add" class="btn btn-gaming-primary px-5 py-2">
                    <i class="bi bi-check-circle me-1"></i> LƯU GAME VÀ TẠO GÓI THUÊ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePricingSection() {
    var select = document.getElementById('price_type_select');
    var pricingSec = document.getElementById('pricing_section');
    var dlSec = document.getElementById('download_link_section');
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