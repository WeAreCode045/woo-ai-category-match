<?php
/*
Plugin Name: Woo AI Category Matcher
Plugin URI: https://github.com/WeAreCode045/woo-ai-category-matcher
Description: Automatically categorize uncategorized WooCommerce products using AI.
Version: 1.0.5
Author: Code045
Author URI: https://code045.nl
License: Custom – See license.txt
License URI: https://github.com/WeAreCode045/woo-ai-category-matcher/blob/main/license.txt

GitHub Plugin URI: https://github.com/WeAreCode045/woo-ai-category-matcher
Primary Branch: main
*/

if (!defined('ABSPATH')) exit;

class Category_Matcher {
    const OPTION_KEY = 'waicm_settings_openai_key';
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // AJAX actions for logged-in users
        add_action('wp_ajax_waicm_match_chunk', [$this, 'ajax_match_chunk']);
        
        // Add nonce for security
        add_action('admin_head', function() {
            if (function_exists('wp_create_nonce')) {
                echo '<meta name="waicm_nonce" value="' . wp_create_nonce('waicm_nonce') . '" />';
            }
        });
        
        // Include and initialize step 2 functionality
        require_once plugin_dir_path(__FILE__) . 'woo-ai-category-matcher-step2.php';
        new Category_Matcher_Step2($this);
    }
    
    /**
     * AJAX handler to get all uncategorized products
     */
    // Moved to Category_Matcher_Step2 class

    public function add_admin_menu() {
        add_options_page(
            'Woo AI Category Matcher',
            'Woo AI Category Matcher',
            'manage_options',
            'waicm-settings',
            [$this, 'render_admin_page'] // Fixed callback method name
        );
    }

    public function register_settings() {
        register_setting('waicm_settings_group', self::OPTION_KEY);
        
        add_settings_section(
            'waicm_settings_section',
            'AI Category Matcher Settings',
            [$this, 'settings_section_callback'],
            'waicm-settings'
        );
        
        add_settings_field(
            'openai_api_key',
            'OpenAI API Key',
            [$this, 'openai_key_callback'],
            'waicm-settings',
            'waicm_settings_section'
        );
        
        add_settings_field(
            'chunk_size',
            'Processing Chunk Size',
            [$this, 'chunk_size_callback'],
            'waicm-settings',
            'waicm_settings_section',
            ['label_for' => 'chunk_size']
        );
        
        // Register the chunk size setting
        register_setting('waicm_settings_group', 'waicm_chunk_size', [
            'type' => 'integer',
            'default' => 5,
            'sanitize_callback' => 'absint'
        ]);
    }
    
    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>Configure the settings for the Woo AI Category Matcher plugin.</p>';
    }
    
    /**
     * OpenAI API Key field callback
     */
    public function openai_key_callback() {
        $api_key = get_option(self::OPTION_KEY, '');
        echo '<input type="text" id="' . esc_attr(self::OPTION_KEY) . '" name="' . esc_attr(self::OPTION_KEY) . '" value="' . esc_attr($api_key) . '" class="regular-text" />';
        echo '<p class="description">Enter your OpenAI API key. Get it from <a href="https://platform.openai.com/account/api-keys" target="_blank">OpenAI</a>.</p>';
    }
    
    /**
     * Chunk size field callback
     */
    public function chunk_size_callback() {
        $chunk_size = get_option('waicm_chunk_size', 5);
        echo '<input type="number" id="chunk_size" name="waicm_chunk_size" value="' . esc_attr($chunk_size) . '" min="1" max="20" step="1" class="small-text" />';
        echo '<p class="description">Number of products to process in each batch (1-20). Lower values reduce server load but may be slower.</p>';
    }
    
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Woo AI Category Matcher</h1>
            <form method="post" action="options.php">
                <?php settings_fields('waicm_settings_group'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">OpenAI API Key</th>
                        <td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>" value="<?php echo esc_attr(get_option(self::OPTION_KEY, '')); ?>" size="60"></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Processing Chunk Size</th>
                        <td><input type="number" name="waicm_chunk_size" value="<?php echo esc_attr(get_option('waicm_chunk_size', 5)); ?>" size="5"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr>
            <div id="waicm-main-wrap">
                <!-- Step 1: AI Auto-categorization -->
                <div id="waicm-step1-wrap" style="margin-bottom:30px;padding:15px;border:1px solid #0073aa;background:#f9f9f9;">
                    <h3>Step 1: Auto-categorize uncategorized products</h3>
                    <button id="waicm-start-btn" class="button button-primary">Start AI Categorization</button>
                    <button id="waicm-cancel-btn" class="button" style="display:none;">Cancel</button>
                    <div id="waicm-progress-status"></div>
                    <div id="waicm-results-list"></div>
                </div>
                <div style="padding:15px;background:#f5f5f5;border:1px solid #ddd;">
                    <h3>External Category Matching</h3>
                    <p>External site category matching has been moved to a separate menu item.</p>
                    <a href="<?php echo admin_url('admin.php?page=waicm-step2'); ?>" class="button">Go to External Category Matching</a>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_match_products() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $api_key = get_option(self::OPTION_KEY);
        if (!$api_key) {
            wp_die('OpenAI API key is missing.');
        }
        $results = $this->categorize_products_with_ai($api_key);
        $remaining = $this->count_uncategorized_products();
        $redirect_url = add_query_arg([
            'waicm_results' => urlencode(json_encode($results)),
            'waicm_remaining' => $remaining
        ], wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }

    private function categorize_products_with_ai($api_key) {
        $uncat_products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => 20, // Process in chunks of 20
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => get_term_by('slug', 'uncategorized', 'product_cat')->term_id,
                ]
            ]
        ]);
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        $cat_data = array_map(function($cat) {
            return [
                'id' => $cat->term_id,
                'name' => $cat->name,
                'description' => $cat->description,
            ];
        }, $categories);
        $results = [];
        foreach ($uncat_products as $product) {
            $best_cat = $this->get_best_category_ai($product, $cat_data, $api_key);
            if ($best_cat) {
                wp_set_object_terms($product->ID, [$best_cat['id']], 'product_cat');
                $results[] = [
                    'product' => $product->post_title,
                    'category' => $best_cat['name'],
                ];
            } else {
                $results[] = [
                    'product' => $product->post_title,
                    'category' => 'No match',
                ];
            }
        }
        return $results;
    }

    private function get_best_category_ai($product, $categories, $api_key) {
        $prompt = $this->build_prompt($product, $categories);
        $response = $this->call_openai($prompt, $api_key);
        if (!$response) return null;
        foreach ($categories as $cat) {
            if (stripos($response, $cat['name']) !== false) {
                return $cat;
            }
        }
        return null;
    }

    private function build_prompt($product, $categories) {
        $cat_list = "";
        foreach ($categories as $cat) {
            $cat_list .= "- {$cat['name']}: {$cat['description']}\n";
        }
        $prompt = "Given the following product title and description, select the most relevant category from the list.\n";
        $prompt .= "Product Title: {$product->post_title}\n";
        $prompt .= "Product Description: {$product->post_content}\n";
        $prompt .= "Categories:\n{$cat_list}";
        $prompt .= "\nRespond only with the category name.";
        return $prompt;
    }

    private function call_openai($prompt, $api_key) {
        $body = json_encode([
            'model' => 'gpt-4.1-nano',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 20,
            'temperature' => 0
        ]);
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => $body,
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) return null;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['choices'][0]['message']['content'] ?? null;
    }
    private function count_uncategorized_products() {
        $uncat = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => get_term_by('slug', 'uncategorized', 'product_cat')->term_id,
                ]
            ]
        ]);
        return count($uncat);
    }
    public function enqueue_admin_scripts($hook) {
        if ('settings_page_waicm-settings' !== $hook) {
            return;
        }
        
        wp_enqueue_script('waicm-admin', plugin_dir_url(__FILE__) . 'assets/js/waicm.js', ['jquery'], '1.0.0', true);
        
        // Create a nonce for AJAX requests
        $nonce = wp_create_nonce('waicm_nonce');
        
        // Add the nonce to the page as a meta tag for JavaScript
        wp_add_inline_script('waicm-admin', 'var waicm_nonce = "' . $nonce . '";', 'before');
        
        // Localize the script with data
        wp_localize_script('waicm-admin', 'waicm', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
            'ajax_nonce' => $nonce,  // For backward compatibility
            'admin_ajax' => admin_url('admin-ajax.php')
        ]);
    }
    
    // Removed duplicate ajax_match_chunk method

    public function ajax_assign_found_cats() {
        check_ajax_referer('waicm_ext_check_all', 'nonce');
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

    // Moved to Category_Matcher_Step2 class

    // Moved to Category_Matcher_Step2 class

    public function ajax_match_chunk() {
        // Enable error logging for debugging
        error_log('=== AJAX MATCH CHUNK STARTED ===');
        error_log('POST data: ' . print_r($_POST, true));
        error_log('REQUEST data: ' . print_r($_REQUEST, true));
        
        // Check if this is an AJAX request
        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            $error = 'Not an AJAX request. DOING_AJAX: ' . (defined('DOING_AJAX') ? 'defined' : 'not defined');
            error_log($error);
            wp_send_json_error(['message' => $error]);
        }
        
        // DEBUG: Skip nonce verification
        error_log('DEBUG: Nonce verification is DISABLED for debugging');
        
        // Check if user is logged in and has proper permissions
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in to perform this action.']);
        }
        
        if (!current_user_can('manage_options')) {
            $error = 'User capability check failed. Current user ID: ' . get_current_user_id() . 
                   ', Capabilities: ' . print_r(wp_get_current_user()->allcaps, true);
            error_log($error);
            wp_send_json_error(['message' => 'You do not have sufficient permissions to perform this action.']);
        }
        
        $api_key = get_option(self::OPTION_KEY);
        if (!$api_key) {
            wp_send_json_error(['message' => 'OpenAI API key is missing.']);
        }
        
        // Get chunk size from settings with a default of 5
        $chunk_size = get_option('waicm_chunk_size', 5);
        $chunk_size = max(1, min(20, (int)$chunk_size)); // Ensure it's between 1 and 20
        
        // First, get total count of uncategorized products
        $total_uncat = $this->count_uncategorized_products();
        
        if ($total_uncat === 0) {
            wp_send_json_success([
                'total_chunks' => 0,
                'current_chunk' => 0,
                'total_products' => 0,
                'processed' => 0,
                'remaining' => 0,
                'results' => []
            ]);
        }
        
        // Calculate chunks
        $total_chunks = ceil($total_uncat / $chunk_size);
        $current_chunk = isset($_POST['current_chunk']) ? intval($_POST['current_chunk']) + 1 : 1;
        
        // Get products for current chunk
        $uncat_products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => $chunk_size,
            'post_status' => 'publish',
            'fields' => 'all',
            'offset' => ($current_chunk - 1) * $chunk_size,
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
            wp_send_json_success([
                'total_chunks' => $total_chunks,
                'current_chunk' => $current_chunk,
                'total_products' => $total_uncat,
                'processed' => 0,
                'remaining' => $total_uncat,
                'results' => []
            ]);
        }
        
        $results = [];
        $cat_data = [];
        $all_cats = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        
        foreach ($all_cats as $cat) {
            $cat_data[] = ['id' => $cat->term_id, 'name' => $cat->name];
        }
        
        $unmatched_cat = get_term_by('slug', 'unmatched', 'product_cat');
        if (!$unmatched_cat) {
            $unmatched_cat = wp_insert_term('Unmatched', 'product_cat', ['slug' => 'unmatched']);
            $unmatched_cat_id = is_wp_error($unmatched_cat) ? 0 : $unmatched_cat['term_id'];
        } else {
            $unmatched_cat_id = $unmatched_cat->term_id;
        }
        
        $processed = 0;
        foreach ($uncat_products as $product) {
            $best_cat = $this->get_best_category_ai($product, $cat_data, $api_key);
            if ($best_cat) {
                wp_set_object_terms($product->ID, [$best_cat['id']], 'product_cat');
                $results[] = ['product' => $product->post_title, 'category' => $best_cat['name']];
            } else {
                wp_set_object_terms($product->ID, [$unmatched_cat_id], 'product_cat');
                $results[] = ['product' => $product->post_title, 'category' => 'No match found'];
            }
            $processed++;
            clean_post_cache($product->ID);
        }
        
        // Get updated count of remaining uncategorized products
        $remaining = $this->count_uncategorized_products();
        
        wp_send_json_success([
            'total_chunks' => $total_chunks,
            'current_chunk' => $current_chunk,
            'total_products' => $total_uncat,
            'processed' => $processed,
            'remaining' => $remaining,
            'results' => $results
        ]);
    }

}

new Category_Matcher();
