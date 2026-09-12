<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/header.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('Vui lòng đăng nhập để xem Game của bạn!'); window.location='login.php';</script>";
    exit();
}

$user_id = $_SESSION['user_id'];

// LỌC TRÙNG LẶP & LẤY THÊM CỘT KEY (br.game_key)
$sql = "SELECT br.borrow_id, br.borrow_date, br.due_date, br.status, br.game_key, g.game_id, g.title, g.image_url 
        FROM borrow_records br 
        JOIN games g ON br.game_id = g.game_id 
        WHERE br.borrow_id IN (
            SELECT MAX(borrow_id) 
            FROM borrow_records 
            WHERE user_id = $user_id 
            GROUP BY game_id
        )
        ORDER BY br.status ASC, br.due_date ASC";
$result = $conn->query($sql);
?>

<div class="container mt-5 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-white fw-bold">🎮 Game Của Tôi</h2>
        <span class="text-info fw-bold">Tài khoản: @<?php echo htmlspecialchars($_SESSION['username']); ?></span>
    </div>

    <div class="row">
        <?php 
        if ($result && $result->num_rows > 0): 
            while ($row = $result->fetch_assoc()): 
                $is_overdue = false;
                $due_timestamp = strtotime($row['due_date']);
                if ($row['status'] == 'active' && $due_timestamp < time()) {
                    $is_overdue = true;
                }
                
                $countdown_id = "timer_" . $row['borrow_id'];
        ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm border-0" style="background-color: #1a222c; border: 1px solid #2d3b4e; border-radius: 12px; overflow: hidden;">
                
                <?php if (!empty($row['image_url']) && file_exists($row['image_url'])): ?>
                    <img src="<?php echo $row['image_url']; ?>" class="card-img-top" alt="<?php echo htmlspecialchars($row['title']); ?>" style="height: 220px; object-fit: cover;">
                <?php else: ?>
                    <div class="card-img-top d-flex align-items-center justify-content-center bg-dark text-muted" style="height: 220px;">NO COVER</div>
                <?php endif; ?>

                <div class="card-body d-flex flex-column p-4">
                    <h4 class="card-title text-info fw-bold mb-3"><?php echo htmlspecialchars($row['title']); ?></h4>
                    
                    <p class="card-text mb-1 text-secondary small">Ngày thuê: <span class="text-light"><?php echo date('d/m/Y H:i', strtotime($row['borrow_date'])); ?></span></p>
                    <p class="card-text mb-3 text-secondary small">Hạn trả: <span class="text-light fw-bold"><?php echo date('d/m/Y H:i', $due_timestamp); ?></span></p>

                    <!-- KHU VỰC HIỂN THỊ LICENSE KEY -->
                    <?php if ($row['status'] == 'active' && !$is_overdue): ?>
                    <div class="mt-2 mb-3 p-2 rounded text-center" style="background-color: rgba(25, 135, 84, 0.1); border: 1px dashed #198754;">
                        <small class="text-success d-block mb-1 fw-bold">MÃ BẢN QUYỀN (LICENSE KEY)</small>
                        <?php if (!empty($row['game_key'])): ?>
                            <strong class="text-white user-select-all fs-5" style="letter-spacing: 1.5px;"><?php echo htmlspecialchars($row['game_key']); ?></strong>
                        <?php else: ?>
                            <span class="text-warning small fst-italic">Bấm "CHƠI NGAY" để kích hoạt Key</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- KHU VỰC ĐỒNG HỒ ĐẾM NGƯỢC -->
                    <div class="p-3 rounded mb-4 text-center shadow-sm" style="background-color: #202d39;">
                        <?php if ($row['status'] == 'active'): ?>
                            <?php if ($is_overdue): ?>
                                <h5 class="text-danger fw-bold mb-0">ĐÃ QUÁ HẠN TRẢ!</h5>
                                <p class="small text-muted mt-1 mb-0">Hệ thống đã thu hồi Key, vui lòng gia hạn.</p>
                            <?php else: ?>
                                <span class="text-muted small d-block mb-1">Thời gian còn lại:</span>
                                <h4 class="text-warning fw-bold mb-0" id="<?php echo $countdown_id; ?>">Đang tính toán...</h4>
                                
                                <script>
                                    var countDownDate_<?php echo $row['borrow_id']; ?> = new Date("<?php echo date('M d, Y H:i:s', $due_timestamp); ?>").getTime();
                                    var x_<?php echo $row['borrow_id']; ?> = setInterval(function() {
                                        var now = new Date().getTime();
                                        var distance = countDownDate_<?php echo $row['borrow_id']; ?> - now;
                                        
                                        if (distance < 0) {
                                            clearInterval(x_<?php echo $row['borrow_id']; ?>);
                                            document.getElementById("<?php echo $countdown_id; ?>").innerHTML = "HẾT HẠN";
                                            document.getElementById("<?php echo $countdown_id; ?>").classList.replace('text-warning', 'text-danger');
                                        } else {
                                            var days = Math.floor(distance / (1000 * 60 * 60 * 24));
                                            var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                                            var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                                            var seconds = Math.floor((distance % (1000 * 60)) / 1000);
                                            
                                            var timeStr = "";
                                            if(days > 0) timeStr += days + " ngày ";
                                            timeStr += (hours < 10 ? "0" + hours : hours) + ":" + 
                                                       (minutes < 10 ? "0" + minutes : minutes) + ":" + 
                                                       (seconds < 10 ? "0" + seconds : seconds);
                                            
                                            document.getElementById("<?php echo $countdown_id; ?>").innerHTML = timeStr;
                                        }
                                    }, 1000);
                                </script>
                            <?php endif; ?>
                        <?php else: ?>
                            <h5 class="text-success fw-bold mb-0">ĐÃ TRẢ GAME</h5>
                        <?php endif; ?>
                    </div>

                    <!-- NÚT HÀNH ĐỘNG VÀ FORM GIA HẠN -->
                    <div class="mt-auto">
                        <?php if ($row['status'] == 'active' && !$is_overdue): ?>
                            <a href="download_game.php?id=<?php echo $row['game_id']; ?>" class="btn btn-success w-100 fw-bold py-2 fs-5 shadow mb-2" target="_blank">▶ CHƠI NGAY</a>
                        <?php endif; ?>
                        
                        <?php if ($row['status'] == 'active'): ?>
                            <form method="POST" action="checkout_qr.php" class="mt-2 p-3 border rounded border-secondary shadow-sm" style="background-color: #151d26;">
                                <input type="hidden" name="is_extend" value="1">
                                <input type="hidden" name="borrow_id_to_extend" value="<?php echo $row['borrow_id']; ?>">
                                <input type="hidden" name="game_id" value="<?php echo $row['game_id']; ?>">
                                
                                <p class="small text-info fw-bold mb-2">🔄 Gia hạn thêm ngày:</p>
                                <div class="input-group input-group-sm mb-0">
                                    <select name="extend_days" class="form-select bg-dark text-white border-secondary">
                                        <option value="3">3 Ngày (15.000đ)</option>
                                        <option value="7" selected>7 Ngày (30.000đ)</option>
                                        <option value="14">14 Ngày (50.000đ)</option>
                                        <option value="30">30 Ngày (99.000đ)</option>
                                    </select>
                                    <button type="submit" name="btn_extend_submit" class="btn btn-warning fw-bold text-dark">Thanh Toán</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <a href="borrow_process.php?game_id=<?php echo $row['game_id']; ?>" class="btn btn-outline-warning w-100 py-2">Thuê lại tựa game này</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div class="col-12 text-center py-5">
            <div class="card border-0 p-5 shadow" style="background-color: #243447; border-radius: 15px;">
                <h4 class="text-light mb-3">Kho game của bạn đang trống rỗng!</h4>
                <p class="text-secondary mb-4">Có vẻ như bạn chưa sở hữu tựa game nào. Hãy dạo quanh cửa hàng và chọn cho mình một siêu phẩm nhé.</p>
                <div>
                    <a href="index.php" class="btn btn-primary fw-bold px-4 py-2 fs-5 shadow">🎮 Khám Phá Cửa Hàng</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>