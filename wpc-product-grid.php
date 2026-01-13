<?php
/**
 * Plugin Name: WPC Product Grid System
 * Description: WPC 產品與解決方案管理系統。包含產品網格、篩選器、CPT (產品/解決方案/下載/FAQ) 定義及詢價單功能。
 * Version: 3.3
 * Author: WPC Engineering Team
 * Text Domain: wpc
 */

/**
 * ============================================================================
 * 1. Shortcodes: 前台顯示元件
 * ============================================================================
 */

/**
 * [wpc_products] 產品網格短代碼
 * 用於在任何頁面顯示產品列表，支援網址參數篩選 (category, interface, function)。
 *
 * @param array $atts Shortcode 屬性 (目前未自訂屬性，全靠 GET 參數)
 * @return string HTML 輸出
 */
function wpc_product_grid_shortcode($atts) {
    // 參數處理
    $filter_cat   = sanitize_text_field($_GET['category'] ?? '');
    $filter_iface = sanitize_text_field($_GET['interface'] ?? '');
    $filter_func  = sanitize_text_field($_GET['function'] ?? '');

    // 設定 WP_Query 查詢參數
    $args = [
        'post_type'      => 'wpc_product',
        'posts_per_page' => -1,            // 顯示所有產品 (或改為 12 搭配分頁)
        'post_status'    => 'publish',
        'no_found_rows'  => true,          // 優化效能
        'tax_query'      => ['relation' => 'AND'],
    ];

    // --- Taxonomy 篩選邏輯 ---
    if ($filter_cat && $filter_cat !== 'all') {
        $args['tax_query'][] = [
            'taxonomy' => 'wpc_product_cat',
            'field'    => 'slug',
            'terms'    => $filter_cat,
        ];
    }
    if ($filter_iface && $filter_iface !== 'all') {
        $args['tax_query'][] = [
            'taxonomy' => 'wpc_product_cat',
            'field'    => 'slug',
            'terms'    => $filter_iface,
        ];
    }
    if ($filter_func && $filter_func !== 'all') {
        $args['tax_query'][] = [
            'taxonomy' => 'wpc_product_cat',
            'field'    => 'slug',
            'terms'    => $filter_func,
        ];
    }

    // 分頁處理
    $paged = (get_query_var('paged')) ? get_query_var('paged') : ((get_query_var('page')) ? get_query_var('page') : 1);
    $args['paged'] = $paged;

    $query = new WP_Query($args);
    
    ob_start();
    echo '<div class="wpc-product-grid">';

    if ($query->have_posts()) :
        while ($query->have_posts()) : $query->the_post();
            $id = get_the_ID();
            $img_url = get_the_post_thumbnail_url($id, 'medium');
            $excerpt = wp_trim_words(get_the_excerpt() ?: get_the_content(), 40, '...');
            
            // 取得顯示用的 Badge (優先顯示最上層分類)
            $terms = get_the_terms($id, 'wpc_product_cat');
            $badge = 'Product';
            if ($terms && !is_wp_error($terms)) {
                $badge = $terms[0]->name;
                foreach ($terms as $term) {
                    // 若有 parent=0 (頂層分類)，優先使用
                    if ($term->parent == 0) {
                        $badge = $term->name;
                        break;
                    }
                }
            }
            ?>
            <div class="wpc-card">
                <span class="wpc-badge"><?php echo esc_html($badge); ?></span>
                <div class="wpc-card-img">
                    <img src="<?php echo esc_url($img_url ?: 'https://via.placeholder.com/300x200?text=No+Image'); ?>" alt="<?php the_title_attribute(); ?>">
                </div>
                <div class="wpc-card-body">
                    <h3 class="wpc-card-title"><?php the_title(); ?></h3>
                    <div class="wpc-card-excerpt"><?php echo esc_html($excerpt); ?></div>
                    <a href="<?php the_permalink(); ?>" class="wpc-btn-outline">查看詳情</a>
                </div>
            </div>
            <?php
        endwhile;
    else :
        echo '<div class="wpc-error" style="grid-column: 1/-1; text-align:center; padding:40px; color: var(--wpc-text);">沒有找到符合篩選條件的產品。</div>';
    endif;

    echo '</div>'; // End Grid

    // 分頁導航
    if ($query->max_num_pages > 1) {
        echo '<div class="wpc-pagination">';
        echo paginate_links([
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'current'   => max(1, $paged),
            'total'     => $query->max_num_pages,
            'prev_text' => '&larr;',
            'next_text' => '&rarr;',
            'type'      => 'list'
        ]);
        echo '</div>';
    }
    
    wp_reset_postdata();
    return ob_get_clean();
}
add_shortcode('wpc_products', 'wpc_product_grid_shortcode');

/**
 * [wpc_sidebar] 側邊欄篩選器短代碼
 * 提供多層級篩選 (類別 -> 介面 -> 功能)
 */
function wpc_sidebar_filter_shortcode() {
    $current_cat   = strtolower(sanitize_text_field($_GET['category'] ?? 'all'));

    // 若為分類Archive頁面，自動對應當前分類
    if ($current_cat === 'all' && is_tax('wpc_product_cat')) {
        $term = get_queried_object();
        if ($term && isset($term->slug)) {
            $current_cat = strtolower($term->slug);
        }
    }

    $current_iface = strtolower(sanitize_text_field($_GET['interface'] ?? ''));
    $current_func  = strtolower(sanitize_text_field($_GET['function'] ?? ''));
    
    // --- 定義篩選結構 ---
    // 1. 主分類
    $cat_slugs = ['daq', 'motion', 'embedded', 'drone', 'instrumentation', 'signal-conditioners']; 
    
    // 2. DAQ 介面 (僅在 cat=daq 時顯示)
    $iface_keywords = [
        'USB'      => 'USB', 
        'Ethernet' => 'Ethernet', 
        'WiFi'     => 'WiFi', 
        'Embedded' => 'Embedded' 
    ];

    // 3. DAQ 功能 (依介面不同顯示)
    $func_map = [
        'ethernet' => ['voltage-in', 'digital', 'current-in', 'switches', 'voltage-out', 'digital-potentiometer', 'temperature-ethernet-daq'],
        'usb'      => ['digital-i-o', 'interface', 'temperature', 'voltage-i-o']
    ];

    $all_terms = get_terms(['taxonomy' => 'wpc_product_cat', 'hide_empty' => false]);
    
    // 輔助：找 Term 物件
    $find_term = function($keyword) use ($all_terms) {
        $keyword = strtolower($keyword);
        foreach ($all_terms as $t) {
            if (strtolower($t->slug) === $keyword) return $t;
            if (strtolower($t->name) === $keyword) return $t;
            if (strpos(strtolower($t->name), $keyword) !== false) return $t;
        }
        return null;
    };

    // 輔助：產生篩選連結
    $render_link = function($param_key, $param_value, $label, $current_value) {
        $is_active = ($current_value === strtolower($param_value));
        if ($param_value === 'all' && ($current_value === 'all' ||  $current_value === '')) $is_active = true;

        $class = $is_active ? 'active' : '';
        
        $args = $_GET; 
        if ($param_value === 'all') unset($args[$param_key]);
        else $args[$param_key] = $param_value;

        // 連動重置
        if ($param_key === 'category') { unset($args['interface']); unset($args['function']); }
        if ($param_key === 'interface') { unset($args['function']); }

        $base_url = remove_query_arg(array_keys($_GET));
        $url      = add_query_arg($args, $base_url);

        echo sprintf('<a href="%s" class="wpc-filter-item %s"><span class="radio-icon"></span>%s</a>',
            esc_url($url), esc_attr($class), esc_html($label)
        );
    };

    ob_start();
    ?>
    <div class="wpc-sidebar-wrapper">
        <!-- Level 1: 類別 -->
        <div class="wpc-filter-group">
            <div class="wpc-filter-title">產品類別</div>
            <div class="wpc-filter-list">
                <?php 
                $render_link('category', 'all', '全部顯示', $current_cat);
                foreach ($cat_slugs as $slug) {
                    $term = $find_term($slug);
                    if ($term) $render_link('category', $term->slug, $term->name, $current_cat);
                }
                ?>
            </div>
        </div>

        <!-- Level 2: 介面 (限 DAQ) -->
        <?php if ($current_cat === 'daq') : ?>
        <div class="wpc-filter-group">
            <div class="wpc-filter-title">傳輸介面</div>
            <div class="wpc-filter-list">
                <?php 
                $render_link('interface', 'all', '不限', $current_iface);
                foreach ($iface_keywords as $key => $label) {
                    $term = $find_term($key);
                    if ($term) $render_link('interface', $term->slug, $label, $current_iface);
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Level 3: 功能 -->
        <?php 
        $active_funcs = [];
        if ($current_cat === 'daq' && !empty($current_iface) && $current_iface !== 'all') {
            if (strpos($current_iface, 'ethernet') !== false) $active_funcs = $func_map['ethernet'];
            elseif (strpos($current_iface, 'usb') !== false) $active_funcs = $func_map['usb'];
        }
        
        if (!empty($active_funcs)) : 
        ?>
        <div class="wpc-filter-group">
            <div class="wpc-filter-title">產品功能</div>
            <div class="wpc-filter-list">
                <?php 
                $render_link('function', 'all', '不限', $current_func);
                foreach ($active_funcs as $slug) {
                    $term = $find_term($slug);
                    if ($term) $render_link('function', $term->slug, $term->name, $current_func);
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('wpc_sidebar', 'wpc_sidebar_filter_shortcode');

/**
 * ============================================================================
 * 2. Custom Post Types & Taxonomies
 * ============================================================================
 */
function wpc_register_custom_post_types() {

    // 1. 產品 (Products)
    register_post_type( 'wpc_product', array(
        'labels' => array(
            'name'          => '產品',
            'singular_name' => '產品',
            'menu_name'     => '產品管理',
            'all_items'     => '所有產品',
            'add_new_item'  => '新增產品',
        ),
        'public'              => true,
        'has_archive'         => true,
        'menu_icon'           => 'dashicons-microphone', // 或 dashicons-products
        'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'custom-fields' ),
        'show_in_rest'        => false, // 暫閉 REST API 以強制使用傳統編輯器 (視需求開啟)
        'rewrite'             => array( 'slug' => 'products' ),
        'publicly_queryable'  => true,
    ));

    // 2. 解決方案 (Solutions)
    register_post_type( 'wpc_solution', array(
        'labels' => array(
            'name'          => '解決方案',
            'singular_name' => '解決方案',
            'menu_name'     => '解決方案',
        ),
        'public'       => true,
        'has_archive'  => true,
        'menu_icon'    => 'dashicons-lightbulb',
        'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
        'show_in_rest' => true,
        'rewrite'      => array( 'slug' => 'solutions' ),
    ));

    // 3. 下載資源 (Downloads)
    register_post_type( 'wpc_download', array(
        'labels' => array(
            'name'          => '下載資源',
            'singular_name' => '檔案',
            'menu_name'     => '下載中心',
            'add_new'       => '上傳新檔案',
        ),
        'public'       => true,
        'has_archive'  => true,
        'menu_icon'    => 'dashicons-download',
        'supports'     => array( 'title', 'excerpt', 'custom-fields' ),
        'show_in_rest' => true,
        'rewrite'      => array( 'slug' => 'downloads' ),
    ));

    // 4. 常見問題 (FAQ)
    register_post_type( 'wpc_faq', array(
        'labels' => array(
            'name'          => '常見問題',
            'singular_name' => '問題',
            'menu_name'     => '常見問題 (FAQ)',
        ),
        'public'       => true,
        'has_archive'  => true,
        'menu_icon'    => 'dashicons-format-chat',
        'supports'     => array( 'title', 'editor' ),
        'show_in_rest' => true,
        'rewrite'      => array( 'slug' => 'faq' ),
    ));
}
add_action( 'init', 'wpc_register_custom_post_types' );


function wpc_register_custom_taxonomies() {
    // 產品分類 (Product Categories)
    register_taxonomy( 'wpc_product_cat', array( 'wpc_product' ), array(
        'labels'            => array( 'name' => '產品分類', 'singular_name' => '分類' ),
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => array( 'slug' => 'product-category' ),
    ));

    // 文件類型 (Download Types)
    register_taxonomy( 'wpc_download_type', array( 'wpc_download' ), array(
        'labels'            => array( 'name' => '文件類型', 'singular_name' => '類型' ),
        'hierarchical'      => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
    ));

    // FAQ 分類
    register_taxonomy( 'wpc_faq_category', array( 'wpc_faq' ), array(
        'labels'            => array( 'name' => '問題分類', 'singular_name' => '分類' ),
        'hierarchical'      => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
    ));
}
add_action( 'init', 'wpc_register_custom_taxonomies' );


/**
 * ============================================================================
 * 3. Icon Helper
 * ============================================================================
 */
function wpc_get_icon_svg($name) {
    switch($name) {
        case 'cpu': 
            return '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/></svg>';
        case 'plane': 
            return '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12h5l3 5v5a2 2 0 0 0 4 0v-5l3-5h5a1 1 0 0 0 1-1.7l-4.5-5.3A4 4 0 0 0 15 3h-5a4 4 0 0 0-3.5 2L2 10.3a1 1 0 0 0 0 1.7z"/></svg>';
        case 'factory': 
            return '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M17 18h1"/><path d="M12 18h1"/><path d="M7 18h1"/></svg>';
        case 'building': 
            return '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="9" y1="22" x2="9" y2="22.01"/><line x1="15" y1="22" x2="15" y2="22.01"/><line x1="9" y1="6" x2="9" y2="6.01"/><line x1="15" y1="6" x2="15" y2="6.01"/><line x1="9" y1="10" x2="9" y2="10.01"/><line x1="15" y1="10" x2="15" y2="10.01"/><line x1="9" y1="14" x2="9" y2="14.01"/><line x1="15" y1="14" x2="15" y2="14.01"/><line x1="9" y1="18" x2="9" y2="18.01"/><line x1="15" y1="18" x2="15" y2="18.01"/></svg>';
        case 'alert-triangle': 
            return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        case 'wifi': 
            return '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>';
        default: 
            return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
    }
}

/**
 * ============================================================================
 * 4. 表單與信件處理 (Form Handling)
 * ============================================================================
 */

// 詢價單提交 (admin-post via form action)
add_action( 'admin_post_wpc_submit_rfq', 'wpc_handle_rfq_submission' );
add_action( 'admin_post_nopriv_wpc_submit_rfq', 'wpc_handle_rfq_submission' );

function wpc_handle_rfq_submission() {
    // 1. 驗證與消毒
    $name    = sanitize_text_field( $_POST['rfq_name'] );
    $email   = sanitize_email( $_POST['rfq_email'] );
    $company = sanitize_text_field( $_POST['rfq_company'] );
    $phone   = sanitize_text_field( $_POST['rfq_phone'] );
    $message = sanitize_textarea_field( $_POST['rfq_message'] );
    $items   = stripslashes( $_POST['rfq_items'] ); // JSON Array

    // 2. 準備信件內容
    $to      = get_option('admin_email');
    $subject = '[WPC 詢價單] 來自 ' . $name;
    
    $body  = "收到新的詢價需求：\n\n";
    $body .= "姓名: $name\n";
    $body .= "Email: $email\n";
    $body .= "公司: $company\n";
    $body .= "電話: $phone\n";
    $body .= "需求說明:\n$message\n\n";
    $body .= "--- 詢價產品清單 ---\n";
    
    $cart_items = json_decode($items, true);
    if ($cart_items && is_array($cart_items)) {
        foreach ($cart_items as $item) {
            $body .= "- " . $item['title'] . " (ID: " . $item['id'] . ", Model: " . $item['model'] . ")\n";
        }
    } else {
        $body .= "(無產品)\n";
    }

    // 3. 發送信件
    wp_mail( $to, $subject, $body );

    // 4. 跳轉至感謝頁
    wp_redirect( home_url('/thank-you/?type=rfq') );
    exit;
}

// 聯絡表單提交
add_action( 'admin_post_wpc_submit_contact', 'wpc_handle_contact_submission' );
add_action( 'admin_post_nopriv_wpc_submit_contact', 'wpc_handle_contact_submission' );

function wpc_handle_contact_submission() {
    $name    = sanitize_text_field( $_POST['contact_name'] );
    $email   = sanitize_email( $_POST['contact_email'] );
    $subject_in = sanitize_text_field( $_POST['contact_subject'] );
    $message = sanitize_textarea_field( $_POST['contact_message'] );

    $to      = get_option('admin_email');
    $subject = '[WPC 聯絡表單] ' . $subject_in;
    $body    = "姓名: $name\nEmail: $email\n\n訊息:\n$message";

    wp_mail( $to, $subject, $body );
    
    wp_redirect( home_url('/thank-you/?type=contact') );
    exit;
}

/**
 * 簡易感謝頁面 (Virtual Page)
 * 當網址包含 /thank-you 時，攔截內容並顯示感謝訊息，無需建立實體 Page。
 */
add_filter( 'the_content', 'wpc_thank_you_content' );
function wpc_thank_you_content( $content ) {
    if ( isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'thank-you') !== false ) {
        // Pixel / GA4 Event Snippet
        $ga4_script = "<script>
          if(typeof gtag === 'function') {
            gtag('event', 'conversion', {'send_to': 'AW-CONVERSION_ID/LABEL'});
          }
        </script>";

        ob_start();
        ?>
        <div style="text-align: center; padding: 100px 20px;">
            <div style="font-size: 4rem;">✅</div>
            <h1>感謝您的聯繫！</h1>
            <p style="font-size: 1.2rem; color: #666;">我們已收到您的訊息，將儘速由專人與您聯繫。</p>
            <a href="<?php echo home_url(); ?>" class="wpc-btn-primary" style="margin-top: 30px; display:inline-block;">返回首頁</a>
            <?php echo $ga4_script; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    return $content;
}

// 確保 /thank-you 不會跳 404
add_action( 'template_redirect', function() {
    if ( isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'thank-you') !== false && is_404() ) {
        status_header( 200 );
        include( get_query_template( 'page' ) );
        die();
    }
});

