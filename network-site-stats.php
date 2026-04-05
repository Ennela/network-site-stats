<?php
/**
 * Plugin Name:       Network Site Stats
 * Plugin URI:        https://github.com/your-username/network-site-stats
 * Description:       Giúp Super Admin theo dõi tình trạng các trang web con trong Multisite Network. Hiển thị ID, tên site, số bài viết, bài mới nhất và URL của từng sub-site.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Sinh Viên - MSSV
 * Author URI:        https://yourwebsite.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       network-site-stats
 * Network:           true
 */
/*
 * ⚠️ QUAN TRỌNG: Dòng "Network: true" ở trên là khai báo bắt buộc
 * để WordPress cho phép plugin được kích hoạt ở chế độ "Network Activate".
 * Nếu thiếu dòng này, plugin sẽ chỉ hoạt động cho từng site riêng lẻ.
 */

// Bảo mật: Ngăn truy cập trực tiếp vào file plugin
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// PHẦN 1: HẰNG SỐ VÀ KHỞI TẠO
// ============================================================

define( 'NSS_VERSION',     '1.0.0' );
define( 'NSS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'NSS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Đăng ký menu và style khi plugin khởi động.
 * Hook 'network_admin_menu' chỉ kích hoạt trong ngữ cảnh Network Admin (/wp-admin/network/).
 */
add_action( 'network_admin_menu',     'nss_add_network_menu'   );
add_action( 'admin_enqueue_scripts',  'nss_enqueue_styles'     );


// ============================================================
// PHẦN 2: ĐĂNG KÝ MENU VÀO NETWORK ADMIN
// ============================================================

/**
 * Thêm menu "Site Stats" vào thanh điều hướng của Network Admin.
 *
 * Hook: network_admin_menu
 * Capability: 'manage_network' — chỉ Super Admin có quyền này.
 */
function nss_add_network_menu() {
    add_menu_page(
        'Network Site Stats',           // Tiêu đề trang (tab title)
        '📊 Site Stats',                // Nhãn menu hiển thị trong sidebar
        'manage_network',               // Quyền truy cập (chỉ Super Admin)
        'network-site-stats',           // Menu slug (phải là duy nhất)
        'nss_render_stats_page',        // Callback: hàm render nội dung trang
        'dashicons-chart-bar',          // Icon Dashicons
        3                               // Vị trí trong menu (3 = gần đầu)
    );
}


// ============================================================
// PHẦN 3: CÁC HÀM LẤY DỮ LIỆU THỐNG KÊ
// ============================================================

/**
 * Lấy số lượng bài viết đã publish của một site.
 *
 * Cần dùng switch_to_blog() trước khi gọi hàm này.
 * wp_count_posts() đọc từ bảng wp_{ID}_posts khi đang trong ngữ cảnh của site đó.
 *
 * @return int Số bài viết có status = 'publish'.
 */
function nss_get_post_count() {
    $counts = wp_count_posts( 'post' );
    return isset( $counts->publish ) ? (int) $counts->publish : 0;
}

/**
 * Lấy thông tin bài viết mới nhất của site hiện tại.
 *
 * Cần dùng switch_to_blog() trước khi gọi hàm này.
 *
 * @return string Chuỗi ngày tháng hoặc thông báo "Chưa có bài viết".
 */
function nss_get_latest_post_date() {
    $posts = get_posts( array(
        'numberposts' => 1,
        'post_type'   => 'post',
        'post_status' => 'publish',
        'orderby'     => 'date',
        'order'       => 'DESC',
    ) );

    if ( ! empty( $posts ) ) {
        return wp_date( 'd/m/Y H:i', strtotime( $posts[0]->post_date ) );
    }

    return 'Chưa có bài viết';
}

/**
 * Tập hợp toàn bộ dữ liệu của tất cả site trong network.
 *
 * Đây là hàm cốt lõi quan trọng nhất:
 * - get_sites(): Trả về danh sách tất cả các sub-site trong network
 * - switch_to_blog($id): Chuyển ngữ cảnh toàn cục sang sub-site đó
 *   (tất cả hàm WP như get_bloginfo, wp_count_posts... sẽ đọc dữ liệu của site đó)
 * - restore_current_blog(): Bắt buộc phải gọi sau switch_to_blog() để tránh lỗi!
 *
 * @return array Mảng chứa dữ liệu thống kê của tất cả site.
 */
function nss_get_all_site_stats() {
    // Lấy tất cả site trong network, không giới hạn số lượng
    $sites = get_sites( array(
        'number' => 0,       // 0 = lấy tất cả (không giới hạn)
        'orderby' => 'id',
        'order'   => 'ASC',
    ) );

    $stats = array();

    foreach ( $sites as $site ) {
        $blog_id = (int) $site->blog_id;

        /*
         * switch_to_blog($blog_id):
         * Thay đổi global $blog_id và các biến toàn cục liên quan.
         * Sau lệnh này, mọi hàm WP đọc dữ liệu sẽ lấy từ bảng wp_{blog_id}_*
         * Ví dụ: Site ID=2 → đọc từ bảng wp_2_posts, wp_2_options, v.v.
         */
        switch_to_blog( $blog_id );

        $stats[] = array(
            'blog_id'         => $blog_id,
            'blog_name'       => get_bloginfo( 'name' ),
            'blog_url'        => get_bloginfo( 'url' ),
            'post_count'      => nss_get_post_count(),
            'latest_post_date' => nss_get_latest_post_date(),
            'admin_url'       => admin_url(),
            'registered'      => $site->registered,
        );

        /*
         * restore_current_blog():
         * PHẢI gọi sau mỗi switch_to_blog() để trả lại ngữ cảnh về site đang xử lý.
         * Quên gọi hàm này sẽ gây lỗi nghiêm trọng: các truy vấn tiếp theo
         * sẽ bị nhầm sang site con, dẫn đến dữ liệu sai hoặc lỗi PHP.
         */
        restore_current_blog();
    }

    return $stats;
}


// ============================================================
// PHẦN 4: RENDER TRANG THỐNG KÊ
// ============================================================

/**
 * Render toàn bộ HTML của trang Network Site Stats.
 * Callback được gọi bởi add_menu_page() khi truy cập vào trang.
 */
function nss_render_stats_page() {
    // Kiểm tra quyền Super Admin
    if ( ! current_user_can( 'manage_network' ) ) {
        wp_die( __( 'Bạn không có quyền truy cập trang này.', 'network-site-stats' ) );
    }

    $all_stats     = nss_get_all_site_stats();
    $total_sites   = count( $all_stats );
    $total_posts   = array_sum( array_column( $all_stats, 'post_count' ) );
    ?>
    <div class="wrap nss-wrap">

        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-chart-bar" style="font-size:30px;height:30px;vertical-align:middle;margin-right:8px;"></span>
            Network Site Stats
        </h1>
        <hr class="wp-header-end">

        <!-- Thẻ tóm tắt tổng quan -->
        <div class="nss-summary-cards">
            <div class="nss-card nss-card--blue">
                <div class="nss-card-icon">🌐</div>
                <div class="nss-card-body">
                    <div class="nss-card-number"><?php echo esc_html( $total_sites ); ?></div>
                    <div class="nss-card-label">Tổng số Site</div>
                </div>
            </div>
            <div class="nss-card nss-card--green">
                <div class="nss-card-icon">📝</div>
                <div class="nss-card-body">
                    <div class="nss-card-number"><?php echo esc_html( $total_posts ); ?></div>
                    <div class="nss-card-label">Tổng số Bài viết</div>
                </div>
            </div>
            <div class="nss-card nss-card--purple">
                <div class="nss-card-icon">⏰</div>
                <div class="nss-card-body">
                    <div class="nss-card-number"><?php echo esc_html( current_time( 'H:i' ) ); ?></div>
                    <div class="nss-card-label">Cập nhật lúc <?php echo esc_html( current_time( 'd/m/Y' ) ); ?></div>
                </div>
            </div>
        </div>

        <!-- Bảng danh sách chi tiết từng site -->
        <div class="nss-table-wrap">
            <table class="wp-list-table widefat fixed striped nss-table">
                <thead>
                    <tr>
                        <th class="column-id">ID</th>
                        <th class="column-name">Tên Site (Blog Name)</th>
                        <th class="column-url">URL</th>
                        <th class="column-posts">Số Bài Viết</th>
                        <th class="column-date">Bài Viết Mới Nhất</th>
                        <th class="column-registered">Ngày Tạo</th>
                        <th class="column-actions">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $all_stats ) ) : ?>
                        <?php foreach ( $all_stats as $site_data ) : ?>
                            <tr>
                                <td class="column-id">
                                    <strong>#<?php echo esc_html( $site_data['blog_id'] ); ?></strong>
                                </td>
                                <td class="column-name">
                                    <strong><?php echo esc_html( $site_data['blog_name'] ); ?></strong>
                                </td>
                                <td class="column-url">
                                    <a href="<?php echo esc_url( $site_data['blog_url'] ); ?>" target="_blank">
                                        <?php echo esc_html( $site_data['blog_url'] ); ?>
                                    </a>
                                </td>
                                <td class="column-posts">
                                    <span class="nss-badge <?php echo $site_data['post_count'] > 0 ? 'nss-badge--green' : 'nss-badge--gray'; ?>">
                                        <?php echo esc_html( $site_data['post_count'] ); ?> bài
                                    </span>
                                </td>
                                <td class="column-date">
                                    <?php echo esc_html( $site_data['latest_post_date'] ); ?>
                                </td>
                                <td class="column-registered">
                                    <?php echo esc_html( date( 'd/m/Y', strtotime( $site_data['registered'] ) ) ); ?>
                                </td>
                                <td class="column-actions">
                                    <a href="<?php echo esc_url( $site_data['admin_url'] ); ?>" class="button button-small" target="_blank">
                                        Admin
                                    </a>
                                    <a href="<?php echo esc_url( $site_data['blog_url'] ); ?>" class="button button-small" target="_blank">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="7" style="text-align:center;padding:20px;">
                                Không tìm thấy site nào trong network.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" style="padding:10px 10px 10px 0;">
                            <strong>Tổng cộng: <?php echo esc_html( $total_sites ); ?> site</strong>
                        </th>
                        <th colspan="4" style="padding:10px 0;">
                            <strong>Tổng bài viết: <?php echo esc_html( $total_posts ); ?></strong>
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>

    </div><!-- .nss-wrap -->
    <?php
}


// ============================================================
// PHẦN 5: STYLES
// ============================================================

/**
 * Enqueue CSS cho trang Network Site Stats.
 * Chỉ tải style khi đang ở đúng trang của plugin (tiết kiệm tài nguyên).
 *
 * @param string $hook Tên hook của trang admin hiện tại.
 */
function nss_enqueue_styles( $hook ) {
    // Kiểm tra có đang ở trang của plugin không
    // Hook sẽ có dạng: 'toplevel_page_network-site-stats'
    if ( strpos( $hook, 'network-site-stats' ) === false ) {
        return;
    }

    // Inline CSS (nhúng trực tiếp không cần file riêng)
    $css = '
        .nss-wrap { max-width: 1200px; }

        /* === Summary Cards === */
        .nss-summary-cards {
            display: flex;
            gap: 16px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        .nss-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px 24px;
            border-radius: 10px;
            flex: 1;
            min-width: 200px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .nss-card--blue   { background: linear-gradient(135deg,#e8f4fd,#d1e9f9); border-left: 4px solid #2980b9; }
        .nss-card--green  { background: linear-gradient(135deg,#e9f7ef,#d0f0e0); border-left: 4px solid #27ae60; }
        .nss-card--purple { background: linear-gradient(135deg,#f3e9fd,#e4d0f9); border-left: 4px solid #8e44ad; }
        .nss-card-icon    { font-size: 2.2rem; }
        .nss-card-number  { font-size: 2rem; font-weight: 700; line-height: 1; color: #1a1a2e; }
        .nss-card-label   { font-size: 0.78rem; color: #555; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }

        /* === Table === */
        .nss-table-wrap { margin-top: 8px; }
        .nss-table th, .nss-table td { vertical-align: middle !important; }
        .column-id      { width: 60px; }
        .column-posts   { width: 100px; text-align: center !important; }
        .column-date    { width: 170px; }
        .column-registered { width: 110px; }
        .column-actions { width: 130px; }

        /* === Badge === */
        .nss-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 0.82rem; font-weight: 600; }
        .nss-badge--green { background: #d4edda; color: #155724; }
        .nss-badge--gray  { background: #e9ecef; color: #6c757d; }
    ';

    wp_add_inline_style( 'common', $css );
}


// ============================================================
// PHẦN 6: KÍCH HOẠT / VÔ HIỆU HÓA PLUGIN (MULTISITE)
// ============================================================

/**
 * Hàm chạy khi plugin được kích hoạt.
 * Trong Multisite, nếu Network Activated thì $network_wide = true.
 *
 * @param bool $network_wide True nếu đang Network Activate.
 */
function nss_activate( $network_wide ) {
    if ( $network_wide ) {
        // Lưu version vào sitewide options (bảng wp_sitemeta)
        update_site_option( 'nss_version', NSS_VERSION );
    } else {
        // Lưu version vào options của site hiện tại
        update_option( 'nss_version', NSS_VERSION );
    }
}
register_activation_hook( __FILE__, 'nss_activate' );

/**
 * Hàm chạy khi plugin bị vô hiệu hóa.
 */
function nss_deactivate() {
    delete_site_option( 'nss_version' );
}
register_deactivation_hook( __FILE__, 'nss_deactivate' );
