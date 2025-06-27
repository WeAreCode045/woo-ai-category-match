<?php
/**
 * Handles the external category search functionality
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class External_Category_Search {
    private $parent;
    
    public function __construct($parent) {
        $this->parent = $parent;
        
        // Register AJAX handlers
        add_action('wp_ajax_waicm_ext_check_all', [$this, 'ajax_ext_check_all']);
        add_action('wp_ajax_waicm_assign_found_cats', [$this, 'ajax_assign_found_cats']);
        add_action('wp_ajax_waicm_get_uncategorized_products', [$this, 'ajax_get_uncategorized_products']);
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function enqueue_scripts($hook) {
        if ('ai-category-matcher_page_external-category-search' !== $hook) {
            return;
        }
        
        wp_enqueue_script(
            'waicm-external-search',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/waicm-external-search.js',
            ['jquery'],
            '1.0.0',
            true
        );
        
        wp_localize_script('waicm-external-search', 'waicm', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('waicm_nonce')
        ]);
    }
    
    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>External Category Search</h1>
            <div id="waicm-external-search-wrap" class="waicm-card">
                <h2>Find Categories from External Sites</h2>
                <div id="waicm-external-search-status"></div>
                <div id="waicm-external-search-progress"></div>
                <div class="waicm-form-group">
                    <label for="waicm-ext-url-1">External Site 1 URL:</label>
                    <input type="text" id="waicm-ext-url-1" class="regular-text">
                </div>
                <div class="waicm-form-group">
                    <label for="waicm-ext-url-2">External Site 2 URL:</label>
                    <input type="text" id="waicm-ext-url-2" class="regular-text">
                </div>
                <div class="waicm-form-group">
                    <label for="waicm-ext-instructions">Instructions for AI (optional):</label>
                    <textarea id="waicm-ext-instructions" rows="3" class="large-text" placeholder="E.g.: Categories are in a sidebar, or look for breadcrumbs, etc."></textarea>
                </div>
                <div class="waicm-button-group">
                    <button id="waicm-ext-search-btn" class="button button-primary">Check all uncategorized products on external sites</button>
                    <button id="waicm-cancel-external-search" class="button" style="display:none;">Cancel</button>
                    <span id="waicm-ext-search-loading" class="spinner" style="float:none;margin-top:0;display:none;"></span>
                </div>
                <div id="waicm-external-search-results"></div>
            </div>
        </div>
        <?php
    }
    
    public function ajax_get_uncategorized_products() {
        check_ajax_referer('waicm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $uncat_products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => get_term_by('slug', 'uncategorized', 'product_cat')->term_id,
                    'operator' => 'IN'
                ]
            ]
        ]);
        
        if (empty($uncat_products)) {
            wp_send_json_success(['products' => []]);
        }
        
        // Get product details
        $products = [];
        foreach ($uncat_products as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $products[] = [
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'url' => get_permalink($product_id)
                ];
            }
        }
        
        wp_send_json_success(['products' => $products]);
    }
    
    public function ajax_ext_check_all() {
        check_ajax_referer('waicm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $url1 = isset($_POST['url1']) ? esc_url_raw($_POST['url1']) : '';
        $url2 = isset($_POST['url2']) ? esc_url_raw($_POST['url2']) : '';
        $instructions = isset($_POST['instructions']) ? sanitize_textarea_field($_POST['instructions']) : '';
        
        if (!$product_id) {
            wp_send_json_error(['message' => 'Invalid product ID']);
        }
        
        if (empty($url1) && empty($url2)) {
            wp_send_json_error(['message' => 'At least one URL is required']);
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => 'Product not found']);
        }
        
        $product_name = $product->get_name();
        $found_categories = [];
        
        // Check first URL if provided
        if (!empty($url1)) {
            $result = $this->check_external_site($url1, $product_name, $instructions);
            if (!is_wp_error($result) && !empty($result['categories'])) {
                $found_categories = array_merge($found_categories, $result['categories']);
            }
        }
        
        // Check second URL if provided
        if (!empty($url2)) {
            $result = $this->check_external_site($url2, $product_name, $instructions);
            if (!is_wp_error($result) && !empty($result['categories'])) {
                $found_categories = array_merge($found_categories, $result['categories']);
            }
        }
        
        // Remove duplicates
        $unique_categories = [];
        $seen = [];
        foreach ($found_categories as $cat) {
            $key = strtolower(trim($cat['name']));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique_categories[] = $cat;
            }
        }
        
        // Get existing categories for selection
        $existing_categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'exclude' => [get_term_by('slug', 'uncategorized', 'product_cat')->term_id]
        ]);
        
        $category_options = '';
        foreach ($existing_categories as $cat) {
            $category_options .= sprintf(
                '<option value="%d">%s</option>',
                esc_attr($cat->term_id),
                esc_html($cat->name)
            );
        }
        
        // Prepare response
        $response = [
            'product_id' => $product_id,
            'product_name' => $product_name,
            'categories' => $unique_categories,
            'existing_categories' => $existing_categories,
            'category_options' => $category_options
        ];
        
        wp_send_json_success($response);
    }
    
    private function check_external_site($url, $product_name, $instructions = '') {
        // This is a placeholder for the actual implementation
        // In a real implementation, you would:
        // 1. Fetch the external page content
        // 2. Parse it to find categories
        // 3. Return the found categories
        
        // For now, we'll simulate a successful response
        return [
            'success' => true,
            'url' => $url,
            'categories' => [
                ['name' => 'Sample Category 1', 'url' => $url . '/category1'],
                ['name' => 'Sample Category 2', 'url' => $url . '/category2']
            ]
        ];
    }
    
    public function ajax_assign_found_cats() {
        check_ajax_referer('waicm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $category_ids = isset($_POST['category_ids']) ? array_map('intval', (array)$_POST['category_ids']) : [];
        
        if (!$product_id || empty($category_ids)) {
            wp_send_json_error(['message' => 'Invalid data']);
        }
        
        $result = wp_set_object_terms($product_id, $category_ids, 'product_cat', true);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => 'Categories assigned successfully',
            'category_names' => array_map('get_term', $category_ids)
        ]);
    }
}
