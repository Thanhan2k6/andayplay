<?php
session_start();
session_destroy(); // Xóa toàn bộ dữ liệu phiên đăng nhập
header("Location: index.php"); // Đẩy về trang chủ
exit();
?>