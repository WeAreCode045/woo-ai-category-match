<?php
/**
 * Plugin Name: Woo AI Category Matcher
 * Description: Automatically categorize WooCommerce products using AI
 * Version: 2.0.0
 * Author: WeAreCode045
 * Author URI: https://github.com/WeAreCode045
 * Text Domain: woo-ai-category-matcher
 * Domain Path: /languages
 * License: Custom – See license.txt
 * License URI: https://github.com/WeAreCode045/woo-ai-category-matcher/blob/main/license.txt
 * GitHub Plugin URI: https://github.com/WeAreCode045/woo-ai-category-matcher
 * Primary Branch: main
 *
 * @package WooAICategoryMatcher
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('WAICM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WAICM_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class Woo_AI_Category_Matcher {
    /**
     * Instance of the plugin
     *
     * @var Woo_AI_Category_Matcher
     */
    private static $instance = null;
    
    /**
     * Category matcher instance
     *
     * @var Category_Matcher
     */
    public $category_matcher;
    
    /**
     * External category search instance
     *
     * @var External_Category_Search
     */
    public $external_search;
    
    /**
     * Get the plugin instance
     *
     * @return Woo_AI_Category_Matcher
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Load required files
        $this->load_dependencies();
        
        // Check if WooCommerce is active
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }
        
        // Initialize plugin
        add_action('plugins_loaded', [$this, 'init_plugin']);
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        // Include required files
        require_once WAICM_PLUGIN_DIR . 'includes/class-category-matcher.php';
        require_once WAICM_PLUGIN_DIR . 'includes/class-external-category-search.php';
    }
    
    /**
     * Initialize the plugin
     */
    public function init_plugin() {
        // Initialize components
        $this->category_matcher = new Category_Matcher($this);
        $this->external_search = new External_Category_Search($this);
        
        // Register hooks
        $this->register_hooks();
    }
    
    /**
     * Register hooks
     */
    private function register_hooks() {
        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    
    /**
     * Display WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Woo AI Category Matcher requires WooCommerce to be installed and activated.', 'woo-ai-category-matcher'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        // Add main menu item
        add_menu_page(
            'Woo AI Category Matcher',
            'AI Category Matcher',
            'manage_options',
            'category-matcher',
            [$this->category_matcher, 'render_page'],
            'dashicons-category',
            56
        );
        
        // Add Category Matcher submenu
        add_submenu_page(
            'category-matcher',
            'AI Auto-categorization',
            'AI Categorization',
            'manage_options',
            'category-matcher',
            [$this->category_matcher, 'render_page']
        );
        
        // Add External Category Search submenu
        add_submenu_page(
            'category-matcher',
            'External Category Search',
            'External Search',
            'manage_options',
            'external-category-search',
            [$this->external_search, 'render_page']
        );
        
        // Add Settings submenu
        add_submenu_page(
            'category-matcher',
            'AI Category Matcher Settings',
            'Settings',
            'manage_options',
            'category-matcher-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_assets($hook) {
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
        // Only load on our plugin pages
        $plugin_pages = [
            'toplevel_page_category-matcher',
            'ai-category-matcher_page_external-category-search',
            'ai-category-matcher_page_category-matcher-settings'
        ];
        
        if (!in_array($hook, $plugin_pages)) {
            return;
        }
        
        // Main plugin script
        wp_enqueue_script(
            'category-matcher-admin',
            plugin_dir_url(__FILE__) . 'assets/js/waicm.js',
            ['jquery'],
            '1.0.0',
            true
        );
        
        // Localize script with necessary data
        $nonce = wp_create_nonce('waicm_nonce');
        wp_localize_script('category-matcher-admin', 'categoryMatcher', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
            'admin_ajax' => admin_url('admin-ajax.php'),
            'is_external_search' => (strpos($hook, 'external-category-search') !== false) ? 'yes' : 'no'
        ]);
        
        // Add inline script for nonce
        wp_add_inline_script(
            'category-matcher-admin',
            'var waicm_nonce = "' . $nonce . '";',
            'before'
        );
        
        // Enqueue styles
        wp_enqueue_style(
            'category-matcher-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            '1.0.0'
        );
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
