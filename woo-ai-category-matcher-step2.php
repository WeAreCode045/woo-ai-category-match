<?php
/**
 * Woo AI Category Matcher - Step 2: External Site/Category Matching
 * 
 * @package WooAICategoryMatcher
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Category_Matcher_Step2 {
    private $parent;
    
    public function __construct($parent) {
        $this->parent = $parent;
        
        // Register AJAX handlers
        add_action('wp_ajax_waicm_ext_check_all', [$this, 'ajax_ext_check_all']);
        add_action('wp_ajax_waicm_assign_found_cats', [$this, 'ajax_assign_found_cats']);
        add_action('wp_ajax_waicm_get_uncategorized_products', [$this, 'ajax_get_uncategorized_products']);
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // Add admin page
        add_action('admin_menu', [$this, 'add_admin_menu']);
    }
    
    public function enqueue_scripts($hook) {
        if ('settings_page_waicm-step2' !== $hook) {
            return;
        }
        
        wp_enqueue_script(
            'waicm-step2',
            plugin_dir_url(__FILE__) . 'assets/js/waicm-step2.js',
            ['jquery'],
            '1.0.0',
            true
        );
        
        wp_localize_script('waicm-step2', 'waicm', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('waicm_nonce'),
        ]);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'waicm-settings',
            'External Category Matching',
            'External Matching',
            'manage_options',
            'waicm-step2',
            [$this, 'render_admin_page']
        );
    }
    
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>External Category Matching</h1>
            <div id="waicm-step2-wrap" style="padding:15px;border:1px solid #0073aa;background:#f5faff;">
                <h3>Find Categories from External Sites</h3>
                <div id="waicm-step2-status"></div>
                <div id="waicm-step2-progress-status" style="margin: 10px 0;"></div>
                <label>External Site 1 URL: <input type="text" id="waicm-ext-url-1" size="40"></label><br>
                <label>External Site 2 URL: <input type="text" id="waicm-ext-url-2" size="40"></label><br>
                <label>Instructions for AI (optional):<br>
                    <textarea id="waicm-ext-instructions" rows="2" cols="60" placeholder="E.g.: Categories are in a sidebar, or look for breadcrumbs, etc."></textarea>
                </label><br>
                <button id="waicm-ext-search-btn" class="button button-primary">Check all uncategorized products on external sites</button>
                <button id="waicm-cancel-btn-step2" class="button" style="display:none;">Cancel</button>
                <span id="waicm-ext-search-loading" style="display:none;">Checking...</span>
                <div id="waicm-step2-results"></div>
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
                    'title' => $product->get_name(),
                    'description' => $product->get_description()
                ];
            }
        }
        
        wp_send_json_success(['products' => $products]);
    }
    
    public function ajax_ext_check_all() {
        // Set a higher time limit for this request
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // 5 minutes
        }
        
        // Verify nonce
        try {
            check_ajax_referer('waicm_nonce', 'nonce');
        } catch (Exception $e) {
            error_log('Nonce verification failed: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Security check failed. Please refresh the page and try again.']);
        }
        
        // Debug logging
        error_log('AJAX Request Received - Ext Check All');
        error_log('POST data: ' . print_r($_POST, true));
        error_log('Current user ID: ' . get_current_user_id());
        error_log('User can manage options: ' . (current_user_can('manage_options') ? 'Yes' : 'No'));
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        // Check if we have required data
        if (empty($_POST['products']) || !is_array($_POST['products'])) {
            wp_send_json_error(['message' => 'No products to process']);
        }
        
        $api_key = get_option('waicm_settings_openai_key');
        if (!$api_key) {
            wp_send_json_error(['message' => 'OpenAI API key missing.']);
        }
        
        $products = isset($_POST['products']) ? $_POST['products'] : [];
        $url1 = isset($_POST['url1']) ? esc_url_raw($_POST['url1']) : '';
        $url2 = isset($_POST['url2']) ? esc_url_raw($_POST['url2']) : '';
        $instructions = isset($_POST['instructions']) ? sanitize_textarea_field($_POST['instructions']) : '';
        
        if (!$url1 && !$url2) {
            wp_send_json_error(['message' => 'No URLs provided.']);
        }
        
        $results = [];
        
        // Get all WooCommerce categories (id, name)
        $all_categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        
        $category_options = array_map(function($cat) {
            return [
                'id' => $cat->term_id,
                'name' => $cat->name
            ];
        }, $all_categories);
        
        // Batch products in groups of 3
        $product_batches = array_chunk($products, 3);
        
        try {
            foreach ($product_batches as $batch) {
                foreach ([$url1, $url2] as $url) {
                    if (!$url) continue;
                    
                    $html = wp_remote_retrieve_body(wp_remote_get($url, ['timeout' => 15]));
                    if (empty($html)) continue;
                    
                    // Prepare titles for this batch
                    $titles = array_map(function($prod) { 
                        return $prod['title']; 
                    }, $batch);
                    
                    if (empty($titles)) {
                        error_log('Batch titles empty: ' . print_r($batch, true));
                        continue;
                    }
                    
                    $cat_map = $this->ai_extract_categories_batch($titles, $html, $api_key, $instructions);
                    
                    if (!is_array($cat_map)) {
                        error_log('cat_map not array: ' . print_r($cat_map, true));
                        continue;
                    }
                    
                    foreach ($batch as $prod) {
                        $title = $prod['title'];
                        $cat = isset($cat_map[$title]) ? $cat_map[$title] : 'not found';
                        
                        // Only add result if not already present for this product
                        $existing = array_filter($results, function($r) use ($prod) { 
                            return $r['id'] == $prod['id']; 
                        });
                        
                        if (!$existing) {
                            $results[] = [
                                'id' => $prod['id'],
                                'title' => $title,
                                'category' => $cat
                            ];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('ajax_ext_check_all error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(['message' => 'Server error: ' . $e->getMessage()]);
        }
        
        wp_send_json_success([
            'results' => $results,
            'all_categories' => $category_options
        ]);
    }
    
    // Batch AI extraction for up to 3 product titles
    private function ai_extract_categories_batch($product_titles, $html, $api_key, $instructions = '') {
        $prompt = "Given the following HTML and a list of product titles, for each product, determine if it is present on the site and, if so, what is its category. If the main page does not contain categories, try to find a sitemap or category listing on the site and use that information. Respond in JSON: {\"Product Title 1\":\"Category or not found\", ...}.\n";
        
        if (!empty($instructions)) {
            $prompt .= "Instructions for searching categories: {$instructions}\n";
        }
        
        $prompt .= "Product Titles: " . implode('; ', $product_titles) . "\n";
        $prompt .= "HTML:\n" . mb_substr($html, 0, 6000);
        $prompt .= "\nRespond only in JSON with each product title as a key.";
        
        $response = $this->call_openai($prompt, $api_key);
        
        // Try to extract the JSON from the response
        $json = null;
        if ($response) {
            $matches = [];
            if (preg_match('/\{.*\}/s', $response, $matches)) {
                $json = json_decode($matches[0], true);
            }
        }
        
        if (is_array($json)) {
            return $json;
        } else {
            // fallback: return all as 'not found'
            $out = [];
            foreach ($product_titles as $t) $out[$t] = 'not found';
            return $out;
        }
    }
    
    private function call_openai($prompt, $api_key) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3,
            ]),
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            error_log('OpenAI API Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            error_log('OpenAI API Error: ' . $data['error']['message']);
            return false;
        }

        return $data['choices'][0]['message']['content'] ?? '';
    }
    
    public function ajax_assign_found_cats() {
        check_ajax_referer('waicm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $updates = isset($_POST['updates']) ? $_POST['updates'] : [];
        
        if (empty($updates) || !is_array($updates)) {
            wp_send_json_error(['message' => 'No updates provided.']);
        }
        
        $errors = [];
        
        foreach ($updates as $item) {
            $prod_id = isset($item['id']) ? intval($item['id']) : 0;
            $cat_name = isset($item['category']) ? sanitize_text_field($item['category']) : '';
            
            if (!$prod_id || !$cat_name) {
                $errors[] = $prod_id;
                continue;
            }
            
            $term = get_term_by('name', $cat_name, 'product_cat');
            
            if ($term && !is_wp_error($term)) {
                wp_set_object_terms($prod_id, [$term->term_id], 'product_cat');
            } else {
                $errors[] = $prod_id;
            }
        }
        
        if (empty($errors)) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => 'Could not assign category for product IDs: ' . implode(',', $errors)]);
        }
    }
}
