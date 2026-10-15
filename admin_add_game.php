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

<div class="container mt-4 text-white pb-5">
    <h2 class="mb-4 fw-bold">➕ Thêm Game Mới Vào Cửa Hàng</h2>
    <div class="card shadow" style="background-color: #1a222c; border: 1px solid #2d3b4e; border-radius: 10px;">
        <div class="card-body p-4">
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info fw-bold">Tên Game</label>
                        <input type="text" name="title" class="form-control bg-dark text-white border-secondary" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info fw-bold">Thể loại</label>
                        <select name="category_id" class="form-select bg-dark text-white border-secondary" required>
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
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info fw-bold">Nền tảng (Platform)</label>
                        <select name="platform" class="form-select bg-dark text-white border-secondary" required>
                            <option value="PC">🖥️ PC</option>
                            <option value="Mobile">📱 Mobile</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info fw-bold">Mô Hình Dịch Vụ</label>
                        <select name="price_type" id="priceTypeSelect" class="form-select bg-dark text-white border-secondary" required onchange="togglePricingInputs()">
                            <option value="paid">Cho thuê tài khoản trả phí</option>
                            <option value="free">Game Miễn phí (Link tải ngoài)</option>
                        </select>
                    </div>
                </div>

                <!-- CẤU HÌNH BẢNG GIÁ BAN ĐẦU -->
                <div class="p-3 mb-4 rounded border border-secondary" id="pricingBlock" style="background: rgba(0, 243, 255, 0.03);">
                    <h5 class="text-warning fw-bold mb-3"><i class="bi bi-tags-fill me-1"></i> Thiết Lập Bảng Giá Các Gói Thuê</h5>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label text-light small">Gói 1 Giờ (đ):</label>
                            <input type="number" name="price_1h" class="form-control bg-dark text-white border-secondary" value="5000" min="1000" step="1000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-light small">Gói 1 Ngày (đ):</label>
                            <input type="number" name="price_1d" class="form-control bg-dark text-white border-secondary" value="15000" min="1000" step="1000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-light small">Gói 7 Ngày (đ):</label>
                            <input type="number" name="price_7d" class="form-control bg-dark text-white border-secondary" value="30000" min="1000" step="1000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-light small">Gói 30 Ngày (đ):</label>
                            <input type="number" name="price_30d" class="form-control bg-dark text-white border-secondary" value="99000" min="1000" step="1000">
                        </div>
                    </div>
                </div>
                
                <div class="row" id="downloadLinkDiv" style="display: none;">
                    <div class="col-12 mb-3">
                        <label class="form-label text-info fw-bold">Link tải chính thức</label>
                        <input type="url" name="download_link" class="form-control bg-dark text-white border-secondary" placeholder="https://store.steampowered.com/...">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-info fw-bold">Ảnh bìa Game (Sẽ được tự động lưu theo ID)</label>
                    <input type="file" name="image" class="form-control bg-dark text-white border-secondary" accept="image/*">
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-info fw-bold">Mô tả tóm tắt (Hiển thị ngắn)</label>
                    <textarea name="short_description" class="form-control bg-dark text-white border-secondary" rows="3" required></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label text-warning fw-bold">Mô tả chi tiết & Điều khoản dịch vụ</label>
                    <textarea name="long_description" class="form-control bg-dark text-white border-secondary" rows="8" placeholder="Viết bài giới thiệu chi tiết về cốt truyện, tính năng nổi bật..."></textarea>
                </div>
                
                <div class="mb-4">
                    <label class="form-label text-info fw-bold">Cấu hình yêu cầu</label>
                    <input type="text" name="system_req" class="form-control bg-dark text-white border-secondary" required>
                </div>
                
                <div class="text-end pt-3 border-top border-secondary">
                    <a href="admin_games.php" class="btn btn-secondary me-2">Hủy bỏ</a>
                    <button type="submit" name="btn_add" class="btn btn-success px-4 fw-bold">✅ LƯU VÀO KHO</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function togglePricingInputs() {
    var select = document.getElementById('priceTypeSelect');
    var divLink = document.getElementById('downloadLinkDiv');
    var divPrice = document.getElementById('pricingBlock');
    if (select.value === 'free') {
        divLink.style.display = 'block';
        divPrice.style.display = 'none';
    } else {
        divLink.style.display = 'none';
        divPrice.style.display = 'block';
    }
}
</script>

<?php include 'includes/footer.php'; ?>