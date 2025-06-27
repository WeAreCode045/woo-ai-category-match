<?php
/*
Plugin Name: Woo AI Category Matcher
Description: Automatically categorizes uncategorized products using AI.
Version: 1.0.0
Author: Code045
License: Custom – See license.txt
License URI: https://github.com/your-repo/woo-ai-category-matcher/blob/main/license.txt
Text Domain: woo-ai-category-matcher
*/

if (!defined('ABSPATH')) exit;

class Category_Matcher {
    const OPTION_KEY = 'cai_matcher_openai_key';
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_cai_match_chunk', [$this, 'ajax_match_chunk']);
        add_action('wp_ajax_cai_ext_check_all', [$this, 'ajax_ext_check_all']);
        add_action('wp_ajax_cai_assign_found_cats', [$this, 'ajax_assign_found_cats']);
    }

    public function add_admin_menu() {
        add_options_page(
            'Category AI Matcher',
            'Category AI Matcher',
            'manage_options',
            'category-ai-matcher',
            [$this, 'render_admin_page'] // Fixed callback method name
        );
    }

    public function register_settings() {
        register_setting('cai_matcher_settings', self::OPTION_KEY);
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Category AI Matcher</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">OpenAI API Key</th>
                        <td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>" value="<?php echo esc_attr(get_option(self::OPTION_KEY, '')); ?>" size="60"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr>
            <div id="cai-main-wrap">
                <!-- Step 1: AI Auto-categorization -->
                <div id="cai-step1-wrap" style="margin-bottom:30px;padding:15px;border:1px solid #0073aa;background:#f9f9f9;">
                    <h3>Step 1: Auto-categorize uncategorized products</h3>
                    <button id="cai-start-btn" class="button button-primary">Start AI Categorization</button>
                    <button id="cai-cancel-btn" class="button" style="display:none;">Cancel</button>
                    <div id="cai-progress-status"></div>
                    <div id="cai-results-list"></div>
                </div>
                <!-- Step 2: External Site/Category Matching -->
                <div id="cai-step2-wrap" style="padding:15px;border:1px solid #0073aa;background:#f5faff;">
                    <h3>Step 2: Find Categories from External Sites</h3>
                    <div id="cai-step2-status"></div>
                    <label>External Site 1 URL: <input type="text" id="cai-ext-url-1" size="40"></label><br>
                    <label>External Site 2 URL: <input type="text" id="cai-ext-url-2" size="40"></label><br>
                    <label>Instructions for AI (optional):<br>
                        <textarea id="cai-ext-instructions" rows="2" cols="60" placeholder="E.g.: Categories are in a sidebar, or look for breadcrumbs, etc."></textarea>
                    </label><br>
                    <button id="cai-ext-search-btn" class="button">Check all uncategorized products on external sites</button>
                    <button id="cai-cancel-btn-step2" class="button" style="display:none;">Cancel</button>
                    <span id="cai-ext-search-loading" style="display:none;">Checking...</span>
                    <div id="cai-step2-results"></div>
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
            'cai_results' => urlencode(json_encode($results)),
            'cai_remaining' => $remaining
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
            'model' => 'gpt-3.5-turbo',
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
        if ($hook !== 'settings_page_category-ai-matcher') return;
        wp_enqueue_script('cai-matcher-js', plugins_url('wp-category-ai-matcher.js', __FILE__), ['jquery'], null, true);
        wp_localize_script('cai-matcher-js', 'caiMatcher', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cai_match_chunk'),
            'ext_nonce' => wp_create_nonce('cai_ext_check_all'),
        ]);
    }

    public function ajax_assign_found_cats() {
        check_ajax_referer('cai_ext_check_all', 'nonce');
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

    public function ajax_ext_check_all() {
        check_ajax_referer('cai_ext_check_all', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $api_key = get_option(self::OPTION_KEY);
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
                    $titles = array_map(function($prod) { return $prod['title']; }, $batch);
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
                        $existing = array_filter($results, function($r) use ($prod) { return $r['id'] == $prod['id']; });
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

    public function ajax_match_chunk() {
        check_ajax_referer('cai_match_chunk', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $api_key = get_option(self::OPTION_KEY);
        if (!$api_key) {
            wp_send_json_error(['message' => 'OpenAI API key missing.']);
        }
        $total_before = $this->count_uncategorized_products();
        $uncat_products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => 20,
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
        $no_match_products = [];
        // Ensure $no_match_products is always an array
        if (!is_array($no_match_products)) $no_match_products = [];
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
        $new_remaining = $this->count_uncategorized_products();
        $processed = count($uncat_products); // Now counts all processed, not just updated
        wp_send_json_success([
            'results' => $results,
            'remaining' => $new_remaining,
            'processed' => $processed,
            'total' => $total_before,
            'no_match_count' => count($no_match_products),
            'no_match_products' => $no_match_products
        ]);
    }

}

new Category_Ai_Matcher();
