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
if($result->num_rows == 0) {
    echo "<script>alert('Không tìm thấy tựa game này!'); window.location='admin_games.php';</script>";
    exit();
}
$game = $result->fetch_assoc();

$current_price_type = isset($game['price_type']) ? $game['price_type'] : 'paid';
$current_download_link = isset($game['download_link']) ? $game['download_link'] : '';

// Xử lý khi Admin bấm nút Cập nhật
if(isset($_POST['btn_update'])) {
    // Ép kiểu chuỗi để tránh lỗi khi gõ dấu nháy đơn (') trong bài viết
    $title = $conn->real_escape_string($_POST['title']);
    $category_id = intval($_POST['category_id']);
    
    // ĐOẠN MỚI: Bắt dữ liệu Nền tảng
    $platform = isset($_POST['platform']) ? $conn->real_escape_string($_POST['platform']) : 'PC';
    
    $description = $conn->real_escape_string($_POST['description']); // Mô tả tóm tắt
    $long_description = $conn->real_escape_string($_POST['long_description']); // Mô tả chi tiết
    $system_req = $conn->real_escape_string($_POST['system_req']);
    $total_quantity = intval($_POST['total_quantity']);
    
    // Cập nhật phân loại giá
    $price_type = isset($_POST['price_type']) ? $_POST['price_type'] : 'paid';
    $download_link = ($price_type == 'free') ? $conn->real_escape_string($_POST['download_link']) : '';
    
    $image_url = $game['image_url'];

    // THUẬT TOÁN ĐÁNH SỐ TÊN ẢNH KHI CẬP NHẬT THEO YÊU CẦU GIÁO VIÊN
    if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $target_dir = "uploads/";
        if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        
        // Lấy đuôi file (jpg, png)
        $extension = pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION);
        
        // Đặt tên ảnh bằng chính số ID của game đang sửa (Ví dụ ID 5 -> 5.jpg)
        $file_name = $game_id . "." . $extension;
        $target_file = $target_dir . $file_name;
        
        if(move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
            $image_url = $target_file;
        }
    }

    $diff = $total_quantity - $game['total_quantity'];
    $new_available = $game['available_quantity'] + $diff;
    if($new_available < 0) $new_available = 0;

    // CẬP NHẬT SQL: Thêm cột platform vào lệnh UPDATE
    $sql = "UPDATE games SET 
            title='$title', 
            category_id=$category_id, 
            platform='$platform',
            description='$description', 
            long_description='$long_description', 
            system_req='$system_req', 
            total_quantity=$total_quantity, 
            available_quantity=$new_available,
            price_type='$price_type',
            download_link='$download_link',
            image_url='$image_url' 
            WHERE game_id=$game_id";

    if($conn->query($sql) === TRUE) {
        echo "<script>alert('Cập nhật thông tin game thành công!'); window.location='admin_games.php';</script>";
        exit();
    } else {
        echo "<div class='alert alert-danger text-center'>Lỗi cập nhật: " . $conn->error . "</div>";
    }
}
?>

<div class="container mt-4 text-white pb-5">
    <h2 class="mb-4">Chỉnh Sửa Game: <span class="text-info"><?php echo htmlspecialchars($game['title']); ?></span></h2>
    
    <div class="card shadow" style="background-color: #202d39; border: none;">
        <div class="card-body p-4">
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info">Tên Game</label>
                        <input type="text" name="title" class="form-control bg-dark text-white border-secondary" value="<?php echo htmlspecialchars($game['title']); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info">Thể loại</label>
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
                    <!-- ĐOẠN MỚI: Thêm chọn nền tảng -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info">Nền tảng (Platform)</label>
                        <select name="platform" class="form-select bg-dark text-white border-secondary" required>
                            <option value="PC" <?php echo (isset($game['platform']) && $game['platform'] == 'PC') ? 'selected' : ''; ?>>🖥️ PC</option>
                            <option value="Mobile" <?php echo (isset($game['platform']) && $game['platform'] == 'Mobile') ? 'selected' : ''; ?>>📱 Mobile</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info">Hình Thức Tính Phí</label>
                        <select name="price_type" id="priceTypeSelect" class="form-select bg-dark text-white border-secondary" required onchange="toggleDownloadLink()">
                            <option value="paid" <?php if($current_price_type == 'paid') echo 'selected'; ?>>Game Trả phí (Thuê mượn kho)</option>
                            <option value="free" <?php if($current_price_type == 'free') echo 'selected'; ?>>Game Miễn phí (Link chính thức)</option>
                        </select>
                    </div>
                </div>
                
                <div class="row" id="downloadLinkDiv" style="<?php echo ($current_price_type == 'free') ? 'display: block;' : 'display: none;'; ?>">
                    <div class="col-12 mb-3">
                        <label class="form-label text-info">Link tải chính thức (Dành cho Miễn phí)</label>
                        <input type="url" name="download_link" class="form-control bg-dark text-white border-secondary" value="<?php echo htmlspecialchars($current_download_link); ?>" placeholder="https://store.steampowered.com/...">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-info">Ảnh bìa hiện tại (ID: <?php echo $game_id; ?>):</label>
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
                    <label class="form-label text-info">Mô tả tóm tắt</label>
                    <textarea name="description" class="form-control bg-dark text-white border-secondary" rows="3" required><?php echo htmlspecialchars($game['description']); ?></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label text-warning fw-bold">Mô tả chi tiết (Bài giới thiệu Game)</label>
                    <textarea name="long_description" class="form-control bg-dark text-white border-secondary" rows="12" placeholder="Viết bài giới thiệu chi tiết về cốt truyện, tính năng nổi bật... Hỗ trợ xuống dòng bằng phím Enter."><?php echo htmlspecialchars($game['long_description'] ?? ''); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <label class="form-label text-info">Cấu hình yêu cầu</label>
                        <input type="text" name="system_req" class="form-control bg-dark text-white border-secondary" value="<?php echo htmlspecialchars($game['system_req']); ?>" required>
                    </div>
                    <div class="col-md-6 mb-4">
                        <label class="form-label text-info">Tổng số lượng bản quyền trong kho</label>
                        <input type="number" name="total_quantity" class="form-control bg-dark text-white border-secondary" value="<?php echo $game['total_quantity']; ?>" min="1" required>
                    </div>
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
    if (select.value === 'free') {
        div.style.display = 'block';
    } else {
        div.style.display = 'none';
    }
}
</script>

<?php include 'includes/footer.php'; ?>