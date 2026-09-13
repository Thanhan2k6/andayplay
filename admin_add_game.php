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

// Xử lý lưu game và upload ảnh (Đổi tên theo ID)
if(isset($_POST['btn_add'])) {
    $title = $conn->real_escape_string($_POST['title']);
    $category_id = intval($_POST['category_id']);
    
    // ĐOẠN MỚI: Bắt dữ liệu Nền tảng
    $platform = $conn->real_escape_string($_POST['platform']);
    
    // Lưu ý: Đổi tên biến $description cũ thành $short_desc để đỡ nhầm lẫn với bài viết dài
    $short_desc = $conn->real_escape_string($_POST['short_description']); 
    
    // ĐÂY LÀ BIẾN MỚI NHẬN DỮ LIỆU MÔ TẢ CHI TIẾT
    $long_desc = $conn->real_escape_string($_POST['long_description']); 
    
    $system_req = $conn->real_escape_string($_POST['system_req']);
    $quantity = intval($_POST['quantity']);
    $price_type = $conn->real_escape_string($_POST['price_type']);
    $download_link = ($price_type == 'free') ? $conn->real_escape_string($_POST['download_link']) : '';
    
    // BƯỚC 1: Lưu thông tin vào Database trước với link ảnh rỗng để Database cấp số thứ tự (ID)
    // Cập nhật SQL: Thêm cột platform
    $sql_insert = "INSERT INTO games (title, category_id, platform, short_description, description, system_req, total_quantity, available_quantity, price_type, download_link, image_url) 
            VALUES ('$title', $category_id, '$platform', '$short_desc', '$long_desc', '$system_req', $quantity, $quantity, '$price_type', '$download_link', '')";
            
    if($conn->query($sql_insert) === TRUE) {
        // Lấy số ID vừa được Database tạo ra (Ví dụ: 1, 2, 3...)
        $new_game_id = $conn->insert_id; 
        
        // BƯỚC 2: Xử lý Upload Ảnh và Đổi tên theo ID
        if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $target_dir = "uploads/";
            if(!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            // Lấy phần đuôi mở rộng của ảnh (ví dụ: jpg, png)
            $extension = pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION);
            
            // Ép tên file thành số thứ tự của game (Ví dụ: 1.jpg)
            $file_name = $new_game_id . "." . $extension;
            $target_file = $target_dir . $file_name;
            
            if(move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
                // BƯỚC 3: Cập nhật lại đường dẫn ảnh đã đánh số vào tựa game vừa tạo
                $conn->query("UPDATE games SET image_url = '$target_file' WHERE game_id = $new_game_id");
            }
        }
        
        echo "<script>alert('Đã thêm tựa game mới vào kho!'); window.location='admin_games.php';</script>";
        exit();
    } else {
        echo "<div class='alert alert-danger text-center'>Lỗi hệ thống: " . $conn->error . "</div>";
    }
}
?>

<div class="container mt-4 text-white pb-5">
    <h2 class="mb-4 fw-bold">➕ Thêm Game Mới</h2>
    <div class="card shadow" style="background-color: #202d39; border: none; border-radius: 10px;">
        <div class="card-body p-4">
            <form method="POST" action="" enctype="multipart/form-data">
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
                    <!-- ĐOẠN MỚI: Thêm chọn nền tảng -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info fw-bold">Nền tảng (Platform)</label>
                        <select name="platform" class="form-select bg-dark text-white border-secondary" required>
                            <option value="PC">🖥️ PC</option>
                            <option value="Mobile">📱 Mobile</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-info fw-bold">Loại Game</label>
                        <select name="price_type" id="priceTypeSelect" class="form-select bg-dark text-white border-secondary" required onchange="toggleDownloadLink()">
                            <option value="paid">Game Trả phí (Thuê mượn kho)</option>
                            <option value="free">Game Miễn phí (Link chính thức)</option>
                        </select>
                    </div>
                </div>
                
                <div class="row" id="downloadLinkDiv" style="display: none;">
                    <div class="col-12 mb-3">
                        <label class="form-label text-info fw-bold">Link tải chính thức</label>
                        <input type="url" name="download_link" class="form-control bg-dark text-white border-secondary" placeholder="https://store.steampowered.com/...">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-info fw-bold">Ảnh bìa Game (Sẽ được tự động đánh số ID)</label>
                    <input type="file" name="image" class="form-control bg-dark text-white border-secondary" accept="image/*">
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-info fw-bold">Mô tả tóm tắt (Hiển thị ngắn)</label>
                    <textarea name="short_description" class="form-control bg-dark text-white border-secondary" rows="3" required></textarea>
                </div>

                <!-- NHẬP BÀI GIỚI THIỆU CHI TIẾT -->
                <div class="mb-4">
                    <label class="form-label text-warning fw-bold">Mô tả chi tiết (Bài giới thiệu Game)</label>
                    <textarea name="long_description" class="form-control bg-dark text-white border-secondary" rows="10" placeholder="Viết bài giới thiệu chi tiết về cốt truyện, tính năng nổi bật... Hỗ trợ xuống dòng bằng phím Enter."></textarea>
                    <div class="form-text text-secondary">Nội dung này sẽ hiển thị toàn bộ trên trang Chi tiết Game.</div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <label class="form-label text-info fw-bold">Cấu hình yêu cầu</label>
                        <input type="text" name="system_req" class="form-control bg-dark text-white border-secondary" required>
                    </div>
                    <div class="col-md-6 mb-4">
                        <label class="form-label text-info fw-bold">Số lượng (Nhập kho)</label>
                        <input type="number" name="quantity" class="form-control bg-dark text-white border-secondary" value="1" min="1" required>
                    </div>
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