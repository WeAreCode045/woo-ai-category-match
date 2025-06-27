<?php
/**
 * Handles the main category matching functionality
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Category_Matcher {
    private $parent;
    
    public function __construct($parent) {
        $this->parent = $parent;
        
        // Register AJAX handlers
        add_action('wp_ajax_waicm_match_chunk', [$this, 'ajax_match_chunk']);
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function enqueue_scripts($hook) {
        if ('toplevel_page_category-matcher' !== $hook) {
            return;
        }
        
        wp_enqueue_script(
            'category-matcher',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/waicm.js',
            ['jquery'],
            '1.0.0',
            true
        );
        
        wp_localize_script('category-matcher', 'categoryMatcher', [
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
            <h1>AI Auto-categorization</h1>
            <div id="waicm-category-matcher-wrap" class="waicm-card">
                <h2>Auto-categorize uncategorized products</h2>
                <button id="waicm-start-btn" class="button button-primary">Start AI Categorization</button>
                <button id="waicm-cancel-btn" class="button" style="display:none;">Cancel</button>
                <div id="waicm-progress-status"></div>
                <div id="waicm-results-list"></div>
            </div>
        </div>
        <?php
    }
    
    public function ajax_match_chunk() {
        check_ajax_referer('waicm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        // Get the chunk of products to process
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $chunk_size = get_option('waicm_chunk_size', 5);
        
        // Get uncategorized products
        $uncat_products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => $chunk_size,
            'offset' => $offset,
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
            wp_send_json_success([
                'processed' => 0,
                'total' => 0,
                'remaining' => 0,
                'message' => 'No uncategorized products found.'
            ]);
        }
        
        // Process products
        $api_key = get_option('waicm_openai_key');
        $results = [];
        
        foreach ($uncat_products as $product_id) {
            $result = $this->process_product($product_id, $api_key);
            if ($result) {
                $results[] = $result;
            }
        }
        
        // Get total remaining uncategorized products
        $total_uncat = $this->count_uncategorized_products();
        $processed = count($uncat_products);
        $remaining = max(0, $total_uncat - $processed - $offset);
        
        wp_send_json_success([
            'processed' => $processed,
            'total' => $total_uncat,
            'remaining' => $remaining,
            'results' => $results,
            'message' => sprintf('Processed %d products', $processed)
        ]);
    }
    
    private function process_product($product_id, $api_key) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }
        
        // Get all categories
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'exclude' => [get_term_by('slug', 'uncategorized', 'product_cat')->term_id]
        ]);
        
        if (is_wp_error($categories) || empty($categories)) {
            return [
                'id' => $product_id,
                'title' => $product->get_name(),
                'status' => 'error',
                'message' => 'No categories found.'
            ];
        }
        
        // Prepare categories for the prompt
        $categories_data = [];
        foreach ($categories as $category) {
            $categories_data[] = [
                'id' => $category->term_id,
                'name' => $category->name,
                'description' => $category->description
            ];
        }
        
        // Build and send the prompt to OpenAI
        $prompt = $this->build_prompt((object)[
            'post_title' => $product->get_name(),
            'post_content' => $product->get_description()
        ], $categories_data);
        
        $response = $this->call_openai($prompt, $api_key);
        
        if (is_wp_error($response)) {
            return [
                'id' => $product_id,
                'title' => $product->get_name(),
                'status' => 'error',
                'message' => $response->get_error_message()
            ];
        }
        
        // Find the best matching category
        $best_category = $this->find_best_category($response, $categories_data);
        
        if ($best_category) {
            // Assign the category to the product
            $result = wp_set_object_terms($product_id, $best_category['id'], 'product_cat', true);
            
            if (is_wp_error($result)) {
                return [
                    'id' => $product_id,
                    'title' => $product->get_name(),
                    'status' => 'error',
                    'message' => $result->get_error_message()
                ];
            }
            
            return [
                'id' => $product_id,
                'title' => $product->get_name(),
                'status' => 'success',
                'category' => $best_category['name'],
                'message' => sprintf('Assigned to category: %s', $best_category['name'])
            ];
        }
        
        return [
            'id' => $product_id,
            'title' => $product->get_name(),
            'status' => 'error',
            'message' => 'Could not determine the best category.'
        ];
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
        $body = [
            'model' => 'gpt-4',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.3,
            'max_tokens' => 100
        ];
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('openai_error', $body['error']['message']);
        }
        
        return $body['choices'][0]['message']['content'];
    }
    
    private function find_best_category($response, $categories) {
        $response = strtolower(trim($response, "\n\t\r\0\x0B\"'“”"));
        
        // Try exact match first
        foreach ($categories as $category) {
            if (strtolower($category['name']) === $response) {
                return $category;
            }
        }
        
        // Try partial match
        foreach ($categories as $category) {
            if (strpos($response, strtolower($category['name'])) !== false) {
                return $category;
            }
        }
        
        // Try similar text
        $best_match = null;
        $best_score = 0;
        
        foreach ($categories as $category) {
            similar_text($response, strtolower($category['name']), $score);
            if ($score > $best_score && $score > 70) { // 70% similarity threshold
                $best_score = $score;
                $best_match = $category;
            }
        }
        
        return $best_match;
    }
    
    private function count_uncategorized_products() {
        $uncat_term = get_term_by('slug', 'uncategorized', 'product_cat');
        if (!$uncat_term) {
            return 0;
        }
        
        $count = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $uncat_term->term_id,
                    'operator' => 'IN'
                ]
            ]
        ]);
        
        return is_array($count) ? count($count) : 0;
    }
}
