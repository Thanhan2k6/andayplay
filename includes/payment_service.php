<?php
/**
 * ANPLAY STORE — PAYMENT VERIFICATION & ATOMIC ACTIVATION SERVICE
 * Dùng chung bởi api_payment_webhook.php và admin_borrow.php (duyệt thủ công).
 * Đảm bảo kích hoạt đơn mới / gia hạn đơn cũ hoàn toàn atomic (Transaction + FOR UPDATE).
 */

function process_verified_payment($conn, $order_id, $payment_tx_id = null, $performed_by = 'WEBHOOK') {
    $order_id = intval($order_id);
    if (!$conn || $order_id <= 0) {
        return ['success' => false, 'error' => 'Đơn hàng không hợp lệ.'];
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT br.*, g.title
                                FROM borrow_records br
                                JOIN games g ON br.game_id = g.game_id
                                WHERE br.borrow_id = ? LIMIT 1 FOR UPDATE");
        if (!$stmt) {
            throw new Exception('Không thể khóa bản ghi đơn hàng.');
        }
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Không tìm thấy đơn hàng #' . $order_id];
        }

        // Idempotency: nếu đã thanh toán và kích hoạt rồi thì trả về thành công luôn
        if (($order['payment_status'] ?? '') === 'paid' && ($order['status'] ?? '') === 'active') {
            $conn->commit();
            return ['success' => true, 'already_paid' => true, 'order_code' => $order['order_code'] ?? ('ORD' . $order_id)];
        }

        // Chống trùng lặp payment_tx_id nếu có
        if (!empty($payment_tx_id)) {
            $chk_tx = $conn->prepare("SELECT borrow_id FROM borrow_records WHERE payment_tx_id = ? AND borrow_id != ? LIMIT 1");
            if ($chk_tx) {
                $chk_tx->bind_param("si", $payment_tx_id, $order_id);
                $chk_tx->execute();
                $dup = $chk_tx->get_result()->num_rows > 0;
                $chk_tx->close();
                if ($dup) {
                    $conn->rollback();
                    return ['success' => false, 'error' => 'Mã giao dịch đã được sử dụng cho đơn khác.'];
                }
            }
        }

        $user_id = intval($order['user_id']);
        $game_id = intval($order['game_id']);
        $amount = floatval($order['rental_price'] ?? 0);
        $order_code = !empty($order['order_code']) ? $order['order_code'] : ('ORD' . $order_id);
        $is_extension = (($order['rent_type'] ?? '') === 'extend');

        if ($is_extension) {
            $prev_b_id = intval($order['previous_borrow_id'] ?? 0);
            $prev_res = null;
            if ($prev_b_id > 0) {
                $st_prev = $conn->prepare("SELECT due_date, status, account_id FROM borrow_records WHERE borrow_id = ? LIMIT 1 FOR UPDATE");
                if ($st_prev) {
                    $st_prev->bind_param("i", $prev_b_id);
                    $st_prev->execute();
                    $prev_res = $st_prev->get_result()->fetch_assoc();
                    $st_prev->close();
                }
            }

            $add_hours = max(1, intval($order['duration_hours'] ?? 24));
            $curr_due = ($prev_res && !empty($prev_res['due_date'])) ? strtotime($prev_res['due_date']) : time();
            $new_due_date = ($curr_due < time())
                ? date('Y-m-d H:i:s', strtotime("+$add_hours hours"))
                : date('Y-m-d H:i:s', strtotime("+$add_hours hours", $curr_due));

            if ($prev_b_id > 0) {
                $upd_prev = $conn->prepare("UPDATE borrow_records SET due_date = ?, status = 'active' WHERE borrow_id = ?");
                if (!$upd_prev || !$upd_prev->bind_param("si", $new_due_date, $prev_b_id) || !$upd_prev->execute()) {
                    throw new Exception('Không thể cập nhật đơn thuê gốc khi gia hạn.');
                }
                $upd_prev->close();
            }

            $acc_id = ($prev_res && !empty($prev_res['account_id'])) ? intval($prev_res['account_id']) : 0;
            if ($acc_id > 0) {
                $upd_acc = $conn->prepare("UPDATE game_accounts SET status = 'RENTED', rent_expires_at = ?, updated_at = NOW() WHERE account_id = ?");
                if (!$upd_acc || !$upd_acc->bind_param("si", $new_due_date, $acc_id) || !$upd_acc->execute()) {
                    throw new Exception('Không thể cập nhật hạn tài khoản game.');
                }
                $upd_acc->close();
            }

            $upd_this = $conn->prepare("UPDATE borrow_records SET payment_status = 'paid', status = 'active', due_date = ?, payment_tx_id = COALESCE(?, payment_tx_id) WHERE borrow_id = ?");
            if (!$upd_this || !$upd_this->bind_param("ssi", $new_due_date, $payment_tx_id, $order_id) || !$upd_this->execute()) {
                throw new Exception('Không thể hoàn tất đơn gia hạn.');
            }
            $upd_this->close();

            if ($acc_id > 0 && function_exists('log_account_allocation')) {
                log_account_allocation($conn, $acc_id, $order_id, $user_id, 'EXTENDED', "Gia hạn thêm $add_hours giờ (đến $new_due_date)", $performed_by);
            }

            $conn->commit();

            if (function_exists('create_notification')) {
                create_notification($conn, $user_id, 'rental', 'Gia hạn thời gian thuê thành công! ⏱️', "Đơn thuê #{$prev_b_id} ({$order['title']}) đã được kéo dài thêm $add_hours giờ.", 'my_library.php', 'EXT-' . $order_id);
                create_notification($conn, $user_id, 'payment', 'Thanh toán gia hạn thành công 💳', "Thanh toán cho đơn gia hạn #$order_code đã hoàn tất (" . number_format($amount) . "đ).", 'profile.php?tab=orders', 'PAY-EXT-' . $order_id);
            }
            if (function_exists('notify_admins_with_permission')) {
                notify_admins_with_permission($conn, 'order.view', 'order', "Gia hạn đơn thuê #$order_code ⏱️", "Đơn gia hạn '{$order['title']}' (+{$add_hours} giờ) đã được xác nhận thanh toán.", 'admin_borrow.php', 'ORD-EXT-' . $order_id);
            }

            return ['success' => true, 'type' => 'extend', 'order_code' => $order_code, 'due_date' => $new_due_date];
        } else {
            // Lưu payment_tx_id trước khi cấp phát (nếu có)
            if (!empty($payment_tx_id)) {
                $upd_tx = $conn->prepare("UPDATE borrow_records SET payment_tx_id = ? WHERE borrow_id = ?");
                if ($upd_tx) {
                    $upd_tx->bind_param("si", $payment_tx_id, $order_id);
                    $upd_tx->execute();
                    $upd_tx->close();
                }
            }
            $conn->commit();

            $duration_hours = max(1, intval($order['duration_hours'] ?? 24));
            $pref_acc = !empty($order['account_id']) ? intval($order['account_id']) : null;

            $alloc_result = allocate_game_account($conn, $game_id, $user_id, $order_id, $duration_hours, $pref_acc);
            if ($alloc_result['success']) {
                if (function_exists('create_notification')) {
                    create_notification($conn, $user_id, 'rental', 'Thuê game thành công! 🎉', "Tài khoản game '{$order['title']}' đã được cấp phát. Hãy vào Thư Viện để lấy thông tin đăng nhập và chơi ngay!", 'my_library.php', 'RENT-' . $order_id);
                    create_notification($conn, $user_id, 'payment', 'Thanh toán thành công 💳', "Đơn hàng #$order_code đã thanh toán thành công số tiền " . number_format($amount) . "đ.", 'profile.php?tab=orders', 'PAY-' . $order_id);
                }
                if (function_exists('notify_admins_with_permission')) {
                    notify_admins_with_permission($conn, 'order.view', 'order', "Đơn thuê mới đã thanh toán #$order_code 💰", "Đơn thuê '{$order['title']}' (" . number_format($amount) . "đ) đã xác nhận thanh toán.", 'admin_borrow.php', 'ORD-NEW-' . $order_id);
                }
                return ['success' => true, 'type' => 'new', 'order_code' => $order_code, 'account_id' => $alloc_result['account_id']];
            } else {
                $err_msg = $alloc_result['error'] ?? 'Hết tài khoản khả dụng';
                $upd_err = $conn->prepare("UPDATE borrow_records SET payment_status = 'paid', status = 'pending', error_message = ? WHERE borrow_id = ?");
                if ($upd_err) {
                    $upd_err->bind_param("si", $err_msg, $order_id);
                    $upd_err->execute();
                    $upd_err->close();
                }
                if (function_exists('create_notification')) {
                    create_notification($conn, $user_id, 'support', 'Sự cố phân bổ tài khoản đơn #' . $order_code . ' ⚠️', "Kho game tạm thời hết tài khoản sẵn sàng. Bạn có thể tạo yêu cầu hỗ trợ để được đổi tài khoản ngay.", 'support.php', 'ERR-ALLOC-' . $order_id);
                }
                if (function_exists('notify_admins_with_permission')) {
                    notify_admins_with_permission($conn, 'rental.manage', 'rental', "⚠️ Sự cố kho tài khoản đơn #$order_code", "Đơn #$order_id ({$order['title']}) đã thanh toán nhưng chưa cấp được tài khoản: $err_msg.", 'admin_accounts.php', 'ALLOC-FAIL-' . $order_id);
                }
                return ['success' => false, 'paid_pending_alloc' => true, 'error' => $err_msg];
            }
        }
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[ANPLAY PAYMENT SERVICE] ' . $e->getMessage());
        return ['success' => false, 'error' => 'Lỗi hệ thống khi xử lý thanh toán.'];
    }
}
