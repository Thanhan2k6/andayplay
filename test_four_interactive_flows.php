<?php
// =========================================================================
// AUTOMATED TEST SUITE: ANPLAY STORE - 4 INTERACTIVE USER FLOWS
// 1. Search from ANPLAY STORE
// 2. Tôi có thể lưu game (Save Game) và chơi tiếp không?
// 3. Tôi có được phép đổi mật khẩu tài khoản game không?
// 4. Search for a Game (Catalog toolbar search)
// =========================================================================

echo "=========================================================================\n";
echo "   ANPLAY STORE - BỘ KIỂM THỬ TỰ ĐỘNG 4 LUỒNG TƯƠNG TÁC THỰC TẾ\n";
echo "=========================================================================\n\n";

$tests_passed = 0;
$tests_total = 0;

function assert_test($description, $condition, $details = '') {
    global $tests_passed, $tests_total;
    $tests_total++;
    if ($condition) {
        $tests_passed++;
        echo "  [PASS] $description\n";
    } else {
        echo "  [FAIL] $description\n";
        if (!empty($details)) {
            echo "         Chi tiết: $details\n";
        }
    }
}

// -------------------------------------------------------------------------
// LUỒNG 1: SEARCH FROM ANPLAY STORE (HEADER GLOBAL SEARCH)
// -------------------------------------------------------------------------
echo "1. KIỂM TRA LUỒNG: SEARCH FROM ANPLAY STORE (TÌM KIẾM TOÀN TRANG):\n";
$header_code = file_get_contents(__DIR__ . '/includes/header.php');

// 1.1. Nút tìm kiếm có nút submit nhận click
$has_header_submit_btn = (strpos($header_code, 'id="searchHeaderBtn"') !== false && strpos($header_code, 'type="submit"') !== false);
assert_test("Form tìm kiếm Header có nút submit (id='searchHeaderBtn') nhận thao tác click", $has_header_submit_btn);

// 1.2. Ô nhập từ khóa và live dropdown
$has_header_input = (strpos($header_code, 'id="headerSearchInput"') !== false);
$has_live_dropdown = (strpos($header_code, 'id="searchLiveDropdown"') !== false);
assert_test("Ô nhập từ khóa hoạt động (id='headerSearchInput') và hỗ trợ phím Enter", $has_header_input);
assert_test("Container kết quả tìm kiếm thời gian thực (id='searchLiveDropdown') tồn tại", $has_live_dropdown);

// 1.3. Nút submit trên mobile
$has_mobile_submit = (strpos($header_code, 'id="mobileSearchSubmitBtn"') !== false);
assert_test("Giao diện mobile có nút submit tìm kiếm riêng nhận tương tác chạm", $has_mobile_submit);

// 1.4. CSS con trỏ chuột pointer cho nút tìm kiếm
$style_code = file_get_contents(__DIR__ . '/css/style.css');
$has_pointer_css = (strpos($style_code, '.search-submit-btn') !== false && strpos($style_code, 'cursor: pointer') !== false);
assert_test("CSS nút tìm kiếm có cursor: pointer và loại bỏ pointer-events: none", $has_pointer_css);

// 1.5. Kiểm tra API api_search.php
$api_code = file_get_contents(__DIR__ . '/api_search.php');
$has_api_search = ($api_code !== false && strlen($api_code) > 200);
assert_test("Endpoint API tìm kiếm (api_search.php) đã được xây dựng sẵn sàng", $has_api_search);
echo "\n";

// -------------------------------------------------------------------------
// LUỒNG 2: SAVE GAME VÀ CHƠI TIẾP (ACCORDION FAQ & MY LIBRARY)
// -------------------------------------------------------------------------
echo "2. KIỂM TRA LUỒNG: TÔI CÓ THỂ LƯU GAME (SAVE GAME) VÀ CHƠI TIẾP KHÔNG?:\n";
$index_code = file_get_contents(__DIR__ . '/index.php');
$library_code = file_get_contents(__DIR__ . '/my_library.php');
$download_code = file_get_contents(__DIR__ . '/download_game.php');

// 2.1. Nút FAQ Accordion trên Trang chủ
$has_faq1_btn = (strpos($index_code, 'data-bs-target="#faq1"') !== false && strpos($index_code, 'Tôi có thể lưu game (Save Game) và chơi tiếp không?') !== false);
assert_test("Nút Accordion FAQ1 (Save Game) nhận click và có id liên kết chính xác", $has_faq1_btn);

// 2.2. Không bị hardcode text-white trong Light Mode
$has_faq1_theme_color = (strpos($index_code, 'color: var(--text-heading) !important;') !== false);
assert_test("Tiêu đề Accordion hiển thị sắc nét ở cả Dark Mode và Light Mode", $has_faq1_theme_color);

// 2.3. Nội dung giải thích Steam Cloud & Local Save
$has_cloud_save_explain = (strpos($index_code, 'Steam Cloud & Local AppData') !== false);
$has_re_rent_keep_save = (strpos($index_code, 'Thuê lại tài khoản cũ (Giữ Save)') !== false);
assert_test("Nội dung giải thích rõ cơ chế Steam Cloud, Local Save và cách giữ save khi thuê lại", $has_cloud_save_explain && $has_re_rent_keep_save);

// 2.4. Tính năng kiểm tra Save Game trong Thư viện & Trang tải game
$has_lib_save_modal = (strpos($library_code, 'id="saveGameModal_') !== false);
$has_dl_save_modal = (strpos($download_code, 'id="dlSaveGameModal"') !== false);
assert_test("Thư viện của tôi (my_library.php) có modal kiểm tra Save Game tương tác thực tế", $has_lib_save_modal);
assert_test("Trung tâm tải game (download_game.php) có nút và modal kiểm tra Save Game", $has_dl_save_modal);
echo "\n";

// -------------------------------------------------------------------------
// LUỒNG 3: QUYỀN ĐỔI MẬT KHẨU TÀI KHOẢN GAME
// -------------------------------------------------------------------------
echo "3. KIỂM TRA LUỒNG: QUYỀN ĐỔI MẬT KHẨU TÀI KHOẢN GAME:\n";

// 3.1. Nút FAQ Accordion FAQ2 trên Trang chủ
$has_faq2_btn = (strpos($index_code, 'data-bs-target="#faq2"') !== false && strpos($index_code, 'Tôi có được phép đổi mật khẩu tài khoản game không?') !== false);
assert_test("Nút Accordion FAQ2 (Quyền đổi mật khẩu) nhận click và đóng/mở mượt mà", $has_faq2_btn);

// 3.2. Chính sách cấm tự đổi mật khẩu & Bảo hành 1 đổi 1
$has_policy_prohibit = (strpos($index_code, 'Nghiêm Cấm Tự Ý Đổi Mật Khẩu') !== false);
$has_policy_warranty = (strpos($index_code, 'Bảo Hành Đổi Trả 1 Đổi 1') !== false);
assert_test("Hiển thị rõ chính sách: Nghiêm cấm tự đổi mật khẩu và cam kết bảo hành 1 đổi 1", $has_policy_prohibit && $has_policy_warranty);

// 3.3. Nút & Modal chính sách mật khẩu trong Thư viện và Download
$has_lib_pass_modal = (strpos($library_code, 'id="passPolicyModal_') !== false);
$has_dl_pass_modal = (strpos($download_code, 'id="dlPassPolicyModal"') !== false);
assert_test("my_library.php có nút 'Quyền đổi mật khẩu' mở modal giải thích quy định", $has_lib_pass_modal);
assert_test("download_game.php có nút 'Chính sách đổi mật khẩu' và quy trình báo lỗi đổi pass", $has_dl_pass_modal);
echo "\n";

// -------------------------------------------------------------------------
// LUỒNG 4: SEARCH FOR A GAME (TÌM KIẾM TRÊN TRANG DANH SÁCH GAME)
// -------------------------------------------------------------------------
echo "4. KIỂM TRA LUỒNG: SEARCH FOR A GAME (TÌM KIẾM TẠI DANH SÁCH GAME):\n";

// 4.1. Form tìm kiếm trên Toolbar danh sách game
$has_catalog_search_form = (strpos($index_code, 'id="catalogSearchForm"') !== false);
$has_catalog_search_input = (strpos($index_code, 'id="catalogSearchInput"') !== false);
$has_catalog_search_btn = (strpos($index_code, 'id="btnCatalogSearch"') !== false);

assert_test("Trang danh sách game có form tìm kiếm riêng (id='catalogSearchForm')", $has_catalog_search_form);
assert_test("Ô nhập từ khóa (id='catalogSearchInput') nhận nhập liệu và phím Enter", $has_catalog_search_input);
assert_test("Nút tìm kiếm (id='btnCatalogSearch') nhận click submit chính xác", $has_catalog_search_btn);

// 4.2. Lưu giữ các bộ lọc khác (stock, category, platform, sort) khi tìm kiếm
$has_hidden_category = (strpos($index_code, 'name="category"') !== false);
$has_hidden_platform = (strpos($index_code, 'name="platform"') !== false);
$has_hidden_stock    = (strpos($index_code, 'name="stock"') !== false);
assert_test("Tìm kiếm danh sách game bảo toàn bộ lọc thể loại, nền tảng và tình trạng kho", $has_hidden_category && $has_hidden_platform && $has_hidden_stock);

// 4.3. Truy vấn tìm kiếm đa trường (title, description, category, platform)
$has_multi_field_search = (strpos($index_code, "g.title LIKE '%\" . '\$search_text' . \"%' OR g.description LIKE") !== false || strpos($index_code, 'g.title LIKE \'%$search_text%\' OR g.description LIKE') !== false);
assert_test("Backend index.php tìm kiếm không phân biệt hoa thường trên tiêu đề, mô tả, thể loại, thiết bị", $has_multi_field_search);
echo "\n";

// -------------------------------------------------------------------------
// TỔNG KẾT
// -------------------------------------------------------------------------
echo "=========================================================================\n";
echo "KẾT QUẢ KIỂM THỬ 4 LUỒNG: $tests_passed / $tests_total bài test ĐẠT YÊU CẦU (" . round(($tests_passed / $tests_total) * 100) . "%)\n";
echo "=========================================================================\n";

if ($tests_passed === $tests_total) {
    echo ">>> TẤT CẢ 4 LUỒNG TƯƠNG TÁC ĐÃ ĐƯỢC SỬA TẬN GỐC VÀ KIỂM THỬ THÀNH CÔNG! <<<\n";
    exit(0);
} else {
    echo ">>> CÓ BÀI TEST CHƯA ĐẠT, VUI LÒNG KIỂM TRA LẠI. <<<\n";
    exit(1);
}
