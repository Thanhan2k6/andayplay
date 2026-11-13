<?php
/**
 * ANPLAY STORE - Shared Pagination UI Helper
 * Theme-aware, responsive pagination bar with per-page selector & GET param preservation.
 */

if (!function_exists('render_pagination')) {
    /**
     * Render a responsive, theme-aware pagination bar.
     *
     * @param int    $current_page     Current 1-based page number
     * @param int    $total_pages      Total number of pages
     * @param int    $total_items      Total number of matching records
     * @param int    $per_page         Items per page
     * @param array  $params           Existing GET parameters to preserve
     * @param array  $per_page_options Allowed per-page options
     * @param string $page_param       Query parameter name for page
     * @param string $per_page_param   Query parameter name for per-page
     * @return string Rendered HTML
     */
    function render_pagination(
        int $current_page,
        int $total_pages,
        int $total_items,
        int $per_page,
        array $params = [],
        array $per_page_options = [10, 20, 50],
        string $page_param = 'page',
        string $per_page_param = 'per_page'
    ): string {
        $total_items = max(0, $total_items);
        $per_page = max(1, $per_page);
        $total_pages = max(1, $total_pages);
        $current_page = max(1, min($current_page, $total_pages));

        // Base params without page param (and preserve all other filters/search/sort)
        $base_params = $params;
        unset($base_params[$page_param]);

        $build_url = static function (array $overrides) use ($base_params): string {
            $merged = array_merge($base_params, $overrides);
            // Remove empty string values only? Keep them if explicitly set, just build query cleanly
            $qs = http_build_query($merged);
            return '?' . $qs;
        };

        if ($total_items === 0) {
            $summary_html = 'Hiển thị <strong>0 bản ghi</strong>';
        } else {
            $start_item = (($current_page - 1) * $per_page) + 1;
            $end_item = min($total_items, $current_page * $per_page);
            $summary_html = 'Hiển thị <strong>' . number_format($start_item) . '–' . number_format($end_item) . '</strong> trong tổng số <strong>' . number_format($total_items) . '</strong> bản ghi';
        }

        // Per-page selector
        if (!in_array($per_page, $per_page_options, true)) {
            $per_page_options[] = $per_page;
            sort($per_page_options);
        }

        $per_page_html = '<div class="anplay-per-page-wrap d-flex align-items-center gap-2">'
            . '<label class="anplay-pagination-label small mb-0 text-nowrap" for="anplayPerPageSelect_' . htmlspecialchars($page_param, ENT_QUOTES, 'UTF-8') . '">Hiển thị:</label>'
            . '<select id="anplayPerPageSelect_' . htmlspecialchars($page_param, ENT_QUOTES, 'UTF-8') . '" class="form-select form-select-sm anplay-per-page-select" aria-label="Số bản ghi mỗi trang" onchange="if(this.value){window.location.href=this.value;}">';

        foreach ($per_page_options as $opt) {
            $opt_int = (int)$opt;
            $opt_url = htmlspecialchars($build_url([$per_page_param => $opt_int, $page_param => 1]), ENT_QUOTES, 'UTF-8');
            $selected = ($opt_int === $per_page) ? ' selected' : '';
            $per_page_html .= '<option value="' . $opt_url . '"' . $selected . '>' . $opt_int . ' bản ghi/trang</option>';
        }
        $per_page_html .= '</select></div>';

        // Page buttons with ellipsis
        $nav_html = '';
        if ($total_pages > 1) {
            $nav_html .= '<nav aria-label="Phân trang"><ul class="pagination anplay-pagination mb-0">';

            // Previous
            if ($current_page > 1) {
                $prev_url = htmlspecialchars($build_url([$per_page_param => $per_page, $page_param => $current_page - 1]), ENT_QUOTES, 'UTF-8');
                $nav_html .= '<li class="page-item"><a class="page-link" href="' . $prev_url . '" rel="prev" aria-label="Trang trước"><i class="bi bi-chevron-left"></i><span class="d-none d-sm-inline ms-1">Trang trước</span></a></li>';
            } else {
                $nav_html .= '<li class="page-item disabled"><span class="page-link" aria-disabled="true"><i class="bi bi-chevron-left"></i><span class="d-none d-sm-inline ms-1">Trang trước</span></span></li>';
            }

            // Determine visible page numbers
            $pages_to_show = [];
            if ($total_pages <= 7) {
                $pages_to_show = range(1, $total_pages);
            } else {
                $pages_to_show[] = 1;
                $window_start = max(2, $current_page - 1);
                $window_end = min($total_pages - 1, $current_page + 1);
                if ($window_start > 2) {
                    $pages_to_show[] = '...';
                }
                for ($i = $window_start; $i <= $window_end; $i++) {
                    $pages_to_show[] = $i;
                }
                if ($window_end < $total_pages - 1) {
                    $pages_to_show[] = '...';
                }
                $pages_to_show[] = $total_pages;
            }

            foreach ($pages_to_show as $p) {
                if ($p === '...') {
                    $nav_html .= '<li class="page-item disabled"><span class="page-link anplay-page-ellipsis">&hellip;</span></li>';
                } elseif ((int)$p === $current_page) {
                    $nav_html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . (int)$p . '</span></li>';
                } else {
                    $p_url = htmlspecialchars($build_url([$per_page_param => $per_page, $page_param => (int)$p]), ENT_QUOTES, 'UTF-8');
                    $nav_html .= '<li class="page-item"><a class="page-link" href="' . $p_url . '">' . (int)$p . '</a></li>';
                }
            }

            // Next
            if ($current_page < $total_pages) {
                $next_url = htmlspecialchars($build_url([$per_page_param => $per_page, $page_param => $current_page + 1]), ENT_QUOTES, 'UTF-8');
                $nav_html .= '<li class="page-item"><a class="page-link" href="' . $next_url . '" rel="next" aria-label="Trang sau"><span class="d-none d-sm-inline me-1">Trang sau</span><i class="bi bi-chevron-right"></i></a></li>';
            } else {
                $nav_html .= '<li class="page-item disabled"><span class="page-link" aria-disabled="true"><span class="d-none d-sm-inline me-1">Trang sau</span><i class="bi bi-chevron-right"></i></span></li>';
            }

            $nav_html .= '</ul></nav>';
        }

        return '<div class="anplay-pagination-bar">'
            . '<div class="anplay-pagination-summary">' . $summary_html . '</div>'
            . '<div class="anplay-pagination-controls">' . $per_page_html . $nav_html . '</div>'
            . '</div>';
    }
}
