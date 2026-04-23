<?php
/**
 * Plugin Name: Veyra
 * Plugin URI: https://github.com/transatlanticvoyage/veyra
 * Description: Veyra WordPress plugin.
 * Version: 1.2.0
 * Author: Veyra Team
 * License: GPL v2 or later
 */

// test comment - veyra now lives on lagoon site

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('VEYRA_PLUGIN_VERSION', '1.2.0');
define('VEYRA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('VEYRA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Initialize plugin
class Veyra {
    
    private $elementor_available = false;
    
    public function __construct() {
        // Check if Elementor is available
        $this->elementor_available = $this->is_elementor_available();
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_bar_menu', array($this, 'add_elephant_tools_to_admin_bar'), 100);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_veyra_get_post_title', array($this, 'ajax_get_post_title'));
        add_action('wp_ajax_veyra_update_post_title', array($this, 'ajax_update_post_title'));
        add_action('wp_ajax_veyra_inject_replex_content', array($this, 'ajax_inject_replex_content'));
        
        // Add fallback AJAX action for non-Elementor mode
        if (!$this->elementor_available) {
            add_action('wp_ajax_veyra_inject_shortcode_content', array($this, 'ajax_inject_shortcode_content'));
        }

        // Handle admin option saves
        add_action('admin_post_veyra_save_options', array($this, 'save_options'));

        // Show full post on blog feed pages if option is enabled
        if (get_option('veyra_show_full_post_on_blog_feed_pages', false)) {
            add_filter('pre_option_rss_use_excerpt', function() { return '0'; });
            add_filter('the_content_more_link', '__return_empty_string');
            add_filter('the_excerpt', array($this, 'replace_excerpt_with_content'));
            add_filter('get_the_excerpt', array($this, 'replace_excerpt_with_content_filter'), 10, 2);
        }

        // Fossil content: inject custom HTML at top of blog feed page 1 only
        if (get_option('veyra_show_fossil_content_on_blog_feed_page', false)) {
            add_action('loop_start', array($this, 'inject_fossil_content'));
        }
    }
    
    /**
     * Check if Elementor is available and active
     */
    private function is_elementor_available() {
        return class_exists('Elementor\\Plugin') && is_plugin_active('elementor/elementor.php');
    }
    
    public function init() {
        // Register shortcodes for fallback mode
        if (!$this->elementor_available) {
            add_shortcode('veyra_content', array($this, 'veyra_content_shortcode'));
            add_filter('the_content', array($this, 'process_veyra_codes_in_content'), 10);
        }
    }
    
    /**
     * Shortcode for displaying dynamic content in non-Elementor mode
     * Usage: [veyra_content code="hero_title" post_id="123"]
     */
    public function veyra_content_shortcode($atts) {
        $atts = shortcode_atts(array(
            'code' => '',
            'post_id' => get_the_ID(),
            'default' => ''
        ), $atts);
        
        if (empty($atts['code'])) {
            return $atts['default'];
        }
        
        $post_id = intval($atts['post_id']);
        $content = get_post_meta($post_id, 'veyra_' . $atts['code'], true);
        
        return !empty($content) ? $content : $atts['default'];
    }
    
    /**
     * Process ##codes in post content automatically
     */
    public function process_veyra_codes_in_content($content) {
        global $post;
        
        if (!$post) {
            return $content;
        }
        
        // Get stored mappings
        $mappings = get_post_meta($post->ID, 'veyra_code_mappings', true);
        
        if (!is_array($mappings)) {
            return $content;
        }
        
        // Replace ##codes with actual content
        foreach ($mappings as $code => $replacement) {
            $content = str_replace('##' . $code, $replacement, $content);
        }
        
        return $content;
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Veyra Hub 1',
            'Veyra Hub 1',
            'edit_posts',
            'veyra-hub-1',
            array($this, 'render_hub_page'),
            'dashicons-sun',
            21
        );
    }

    public function save_options() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('veyra_save_options', 'veyra_options_nonce');

        $show_full = isset($_POST['veyra_show_full_post_on_blog_feed_pages']) ? true : false;
        update_option('veyra_show_full_post_on_blog_feed_pages', $show_full);

        $show_fossil = isset($_POST['veyra_show_fossil_content_on_blog_feed_page']) ? true : false;
        update_option('veyra_show_fossil_content_on_blog_feed_page', $show_fossil);

        $fossil_content = isset($_POST['veyra_fossil_content']) ? wp_kses_post(wp_unslash($_POST['veyra_fossil_content'])) : '';
        update_option('veyra_fossil_content', $fossil_content);

        $hauser_emblem = isset($_POST['veyra_hauser_themes_header_emblem_text']) ? sanitize_text_field($_POST['veyra_hauser_themes_header_emblem_text']) : '';
        update_option('veyra_hauser_themes_header_emblem_text', $hauser_emblem);

        // Save WP native reading options
        if (isset($_POST['show_on_front'])) {
            update_option('show_on_front', sanitize_text_field($_POST['show_on_front']));
        }
        if (isset($_POST['page_on_front'])) {
            update_option('page_on_front', intval($_POST['page_on_front']));
        }
        if (isset($_POST['page_for_posts'])) {
            update_option('page_for_posts', intval($_POST['page_for_posts']));
        }
        if (isset($_POST['posts_per_page'])) {
            update_option('posts_per_page', intval($_POST['posts_per_page']));
        }
        if (isset($_POST['posts_per_rss'])) {
            update_option('posts_per_rss', intval($_POST['posts_per_rss']));
        }
        if (isset($_POST['rss_use_excerpt'])) {
            update_option('rss_use_excerpt', intval($_POST['rss_use_excerpt']));
        }
        if (isset($_POST['blog_public'])) {
            update_option('blog_public', intval($_POST['blog_public']));
        } else {
            update_option('blog_public', 1);
        }

        wp_redirect(admin_url('admin.php?page=veyra-hub-1&saved=1'));
        exit;
    }

    public function inject_fossil_content($query) {
        if (!$query->is_main_query() || !is_home() || is_paged()) {
            return;
        }
        $content = get_option('veyra_fossil_content', '');
        if (!empty($content)) {
            $content = wp_unslash($content);
            echo '<div class="veyra-fossil-content">' . wpautop($content) . '</div>';
        }
    }

    public function replace_excerpt_with_content($excerpt) {
        if (is_home() || is_archive() || is_search()) {
            global $post;
            if ($post) {
                return apply_filters('the_content', $post->post_content);
            }
        }
        return $excerpt;
    }

    public function replace_excerpt_with_content_filter($excerpt, $post) {
        if (is_home() || is_archive() || is_search()) {
            if ($post) {
                return apply_filters('the_content', $post->post_content);
            }
        }
        return $excerpt;
    }

    public function render_hub_page() {
        $show_full = get_option('veyra_show_full_post_on_blog_feed_pages', false);
        $show_fossil = get_option('veyra_show_fossil_content_on_blog_feed_page', false);
        $fossil_content = get_option('veyra_fossil_content', '');
        $hauser_emblem = get_option('veyra_hauser_themes_header_emblem_text', '');
        $saved = isset($_GET['saved']) ? true : false;

        // WP native reading options
        $show_on_front = get_option('show_on_front', 'posts');
        $page_on_front = get_option('page_on_front', 0);
        $page_for_posts = get_option('page_for_posts', 0);
        $posts_per_page = get_option('posts_per_page', 10);
        $posts_per_rss = get_option('posts_per_rss', 10);
        $rss_use_excerpt = get_option('rss_use_excerpt', 0);
        $blog_public = get_option('blog_public', 1);
        $pages = get_pages();
        ?>
        <div class="wrap">
            <h1>Veyra Hub 1</h1>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="veyra_save_options" />
                <?php wp_nonce_field('veyra_save_options', 'veyra_options_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">veyra_hauser_themes_header_emblem_text</th>
                        <td>
                            <input type="text" name="veyra_hauser_themes_header_emblem_text" value="<?php echo esc_attr($hauser_emblem); ?>" class="regular-text" />
                            <p class="description">Site title text displayed in theme headers. If empty, no title is shown.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">veyra_show_full_post_on_blog_feed_pages</th>
                        <td>
                            <label>
                                <input type="checkbox" name="veyra_show_full_post_on_blog_feed_pages" value="1" <?php checked($show_full); ?> />
                                When enabled, blog feed pages (home, archive, search) display the full post content instead of excerpts.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">veyra_show_fossil_content_on_blog_feed_page</th>
                        <td>
                            <label>
                                <input type="checkbox" name="veyra_show_fossil_content_on_blog_feed_page" value="1" <?php checked($show_fossil); ?> />
                                When enabled, displays fossil content at the top of the blog feed page (page 1 only, not paginated pages).
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">veyra_fossil_content</th>
                        <td>
                            <textarea name="veyra_fossil_content" rows="12" cols="80" class="large-text code"><?php echo esc_textarea($fossil_content); ?></textarea>
                            <p class="description">HTML content to display at the top of the blog feed page (before posts). Only shown on page 1. Line breaks are automatically converted to paragraphs.</p>
                        </td>
                    </tr>
                </table>

                <hr style="margin: 30px 0;" />

                <h2>WP Native Options</h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">WP Native - Site Title</th>
                        <td>
                            <code><?php echo esc_html(get_option('blogname', '')); ?></code>
                            <p class="description">Set via <a href="<?php echo esc_url(admin_url('options-general.php')); ?>">Settings &rarr; General</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WP Native - Site Description</th>
                        <td>
                            <code><?php echo esc_html(get_option('blogdescription', '')); ?></code>
                            <p class="description">Set via <a href="<?php echo esc_url(admin_url('options-general.php')); ?>">Settings &rarr; General</a></p>
                        </td>
                    </tr>
                </table>

                <hr style="margin: 30px 0;" />

                <h2>From WP Native Reading Page</h2>
                <h3>Reading Settings</h3>

                <table class="form-table">
                    <tr>
                        <th scope="row">Your homepage displays</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="show_on_front" value="posts" <?php checked($show_on_front, 'posts'); ?> />
                                    Your latest posts
                                </label>
                                <br />
                                <label>
                                    <input type="radio" name="show_on_front" value="page" <?php checked($show_on_front, 'page'); ?> />
                                    A static page (select below)
                                </label>
                                <ul style="margin-left: 25px;">
                                    <li>
                                        <label for="page_on_front">Homepage:
                                            <select name="page_on_front" id="page_on_front">
                                                <option value="0">&mdash; Select &mdash;</option>
                                                <?php foreach ($pages as $p): ?>
                                                    <option value="<?php echo $p->ID; ?>" <?php selected($page_on_front, $p->ID); ?>><?php echo esc_html($p->post_title); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    </li>
                                    <li>
                                        <label for="page_for_posts">Posts page:
                                            <select name="page_for_posts" id="page_for_posts">
                                                <option value="0">&mdash; Select &mdash;</option>
                                                <?php foreach ($pages as $p): ?>
                                                    <option value="<?php echo $p->ID; ?>" <?php selected($page_for_posts, $p->ID); ?>><?php echo esc_html($p->post_title); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    </li>
                                </ul>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="posts_per_page">Blog pages show at most</label></th>
                        <td>
                            <input name="posts_per_page" type="number" step="1" min="1" id="posts_per_page" value="<?php echo esc_attr($posts_per_page); ?>" class="small-text" /> posts
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="posts_per_rss">Syndication feeds show the most recent</label></th>
                        <td>
                            <input name="posts_per_rss" type="number" step="1" min="1" id="posts_per_rss" value="<?php echo esc_attr($posts_per_rss); ?>" class="small-text" /> items
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">For each post in a feed, include</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="rss_use_excerpt" value="0" <?php checked($rss_use_excerpt, 0); ?> />
                                    Full text
                                </label>
                                <br />
                                <label>
                                    <input type="radio" name="rss_use_excerpt" value="1" <?php checked($rss_use_excerpt, 1); ?> />
                                    Excerpt
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Search engine visibility</th>
                        <td>
                            <label>
                                <input type="checkbox" name="blog_public" value="0" <?php checked($blog_public, 0); ?> />
                                Discourage search engines from indexing this site
                            </label>
                            <p class="description">It is up to search engines to honor this request.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }

    public function add_elephant_tools_to_admin_bar($wp_admin_bar) {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return;
        }
        
        $logo_svg = '<img src="' . VEYRA_PLUGIN_URL . 'assets/images/veyra-sun-logo.svg" style="width: 16px; height: 16px; margin-right: 6px; vertical-align: middle;" alt="Veyra Logo" />';
        
        $wp_admin_bar->add_node(array(
            'id' => 'veyra-elephant-tools',
            'title' => $logo_svg . 'Veyra Elephant Tools',
            'href' => '#',
            'meta' => array(
                'onclick' => 'VeyraElephantTools.openModal(); return false;'
            )
        ));
    }
    
    public function enqueue_scripts() {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return;
        }
        
        wp_enqueue_script('veyra-elephant-tools', VEYRA_PLUGIN_URL . 'assets/elephant-tools.js', array('jquery'), VEYRA_PLUGIN_VERSION . '.' . time(), true);
        wp_enqueue_style('veyra-elephant-tools', VEYRA_PLUGIN_URL . 'assets/elephant-tools.css', array(), VEYRA_PLUGIN_VERSION . '.' . time());
        
        wp_localize_script('veyra-elephant-tools', 'veyra_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('veyra_elephant_tools'),
            'current_post_id' => get_queried_object_id(),
            'plugin_url' => VEYRA_PLUGIN_URL
        ));
    }
    
    public function ajax_get_post_title() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);
        
        if (!$post) {
            wp_send_json_error('Post not found');
        }
        
        wp_send_json_success(array(
            'post_title' => $post->post_title,
            'post_id' => $post_id
        ));
    }
    
    public function ajax_update_post_title() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $new_title = sanitize_text_field($_POST['new_title']);
        
        if (!$post_id || !$new_title) {
            wp_send_json_error('Invalid data');
        }
        
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_title' => $new_title
        ));
        
        if (is_wp_error($result)) {
            wp_send_json_error('Failed to update post');
        }
        
        wp_send_json_success(array(
            'message' => 'Post title updated successfully',
            'new_title' => $new_title
        ));
    }
    
    public function ajax_inject_replex_content() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        $replex_content = isset($_POST['replex_content']) ? wp_kses_post($_POST['replex_content']) : '';
        $auto_update_title = isset($_POST['auto_update_title']) ? intval($_POST['auto_update_title']) : 0;
        
        // Remove backslashes from all shortcode content
        $replex_content = preg_replace_callback('/\[[^[\]]+\]/', function($matches) {
            return stripslashes($matches[0]);
        }, $replex_content);
        
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
            return;
        }
        
        if (empty($replex_content)) {
            wp_send_json_error('No content provided');
            return;
        }
        
        // Parse the replex content to extract ##code and replacement text
        $replacements = $this->parse_replex_content($replex_content);
        
        if (empty($replacements)) {
            wp_send_json_error('No valid ##codes found in content');
            return;
        }

        // Handle post title update if toggle is enabled
        $title_updated = false;
        if ($auto_update_title) {
            $new_title = $this->extract_first_title_from_replex($replex_content);
            if ($new_title) {
                $result = wp_update_post(array(
                    'ID' => $post_id,
                    'post_title' => $new_title
                ));
                if (!is_wp_error($result)) {
                    $title_updated = true;
                }
            }
        }

        // Route to appropriate handler based on Elementor availability
        if ($this->elementor_available) {
            return $this->handle_elementor_content_injection($post_id, $replacements, $title_updated);
        } else {
            return $this->handle_fallback_content_injection($post_id, $replacements, $title_updated);
        }
    }
    
    /**
     * Parse replex content into code/replacement pairs
     */
    private function parse_replex_content($replex_content) {
        $replacements = array();
        $lines = preg_split('/\r\n|\r|\n/', $replex_content);
        $current_code = '';
        $current_text = '';
        
        foreach ($lines as $line) {
            // Check if line starts with ##
            if (preg_match('/^##(.+)$/', $line, $matches)) {
                // Save previous code/text pair if exists
                if ($current_code && $current_text) {
                    $replacements[$current_code] = trim($current_text);
                }
                // Start new code
                $current_code = trim($matches[1]);
                $current_text = '';
            } else if ($current_code) {
                // Add to current text
                $current_text .= ($current_text ? "\n" : "") . $line;
            }
        }
        
        // Save last code/text pair if exists
        if ($current_code && $current_text) {
            $replacements[$current_code] = trim($current_text);
        }
        
        return $replacements;
    }
    
    /**
     * Handle content injection for Elementor pages
     */
    private function handle_elementor_content_injection($post_id, $replacements, $title_updated) {
        // Get Elementor data
        $data = get_post_meta($post_id, '_elementor_data', true);
        if (!$data) {
            wp_send_json_error('No Elementor data found for this page. Make sure this page uses Elementor.');
            return;
        }

        // Decode the data
        $elements = json_decode($data, true);
        if (!is_array($elements)) {
            wp_send_json_error('Failed to decode Elementor data');
            return;
        }

        // Process the elements
        $processed_count = 0;
        $elements = $this->process_replex_elements($elements, $replacements, $processed_count);

        // Save the updated data
        $updated_data = wp_json_encode($elements);
        if ($updated_data === false) {
            wp_send_json_error('Failed to encode updated data');
            return;
        }

        // Use Elementor's internal save mechanism if available
        if (class_exists('Elementor\Plugin')) {
            $elementor_instance = \Elementor\Plugin::$instance;
            $document = $elementor_instance->documents->get($post_id);
            if ($document) {
                // Update the document data
                $document->save([
                    'elements' => $elements,
                    'settings' => $document->get_settings()
                ]);
                
                // Clear Elementor cache
                if (isset($elementor_instance->files_manager) && method_exists($elementor_instance->files_manager, 'clear_cache')) {
                    $elementor_instance->files_manager->clear_cache();
                }
                
                $message = 'Content replaced successfully! ' . $processed_count . ' replacement(s) made.';
                if ($title_updated) {
                    $message .= ' Post title updated.';
                }
                wp_send_json_success(array(
                    'message' => $message
                ));
                return;
            }
        }

        // Fallback: Save directly to post meta
        update_post_meta($post_id, '_elementor_data', $updated_data);
        
        $message = 'Content replaced successfully! ' . $processed_count . ' replacement(s) made.';
        if ($title_updated) {
            $message .= ' Post title updated.';
        }
        wp_send_json_success(array(
            'message' => $message
        ));
    }
    
    /**
     * Handle content injection for non-Elementor pages (using post content and custom fields)
     */
    private function handle_fallback_content_injection($post_id, $replacements, $title_updated) {
        $processed_count = 0;
        
        // Get current post content
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Post not found');
            return;
        }
        
        $post_content = $post->post_content;
        $original_content = $post_content;
        
        // Replace codes in post content
        foreach ($replacements as $code => $replacement_text) {
            $post_content = str_replace('##' . $code, $replacement_text, $post_content, $count);
            $processed_count += $count;
            
            // Also save as custom field for future reference
            update_post_meta($post_id, 'veyra_' . $code, $replacement_text);
        }
        
        // Update post content if changes were made
        if ($post_content !== $original_content) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $post_content
            ));
        }
        
        // Store the mappings for potential future use
        update_post_meta($post_id, 'veyra_code_mappings', $replacements);
        
        $message = 'Content replaced successfully! ' . $processed_count . ' replacement(s) made.';
        if ($title_updated) {
            $message .= ' Post title updated.';
        }
        $message .= ' (Non-Elementor mode: Content saved to post content and custom fields)';
        
        wp_send_json_success(array(
            'message' => $message
        ));
    }
    
    private function process_replex_elements($elements, $replacements, &$processed_count) {
        foreach ($elements as &$el) {
            if (isset($el['settings']) && is_array($el['settings'])) {
                // Store original settings
                $original_settings = $el['settings'];
                
                // Update content fields based on widget type
                if (isset($el['widgetType'])) {
                    switch ($el['widgetType']) {
                        case 'heading':
                            if (isset($original_settings['title'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['title'] === $code) {
                                        $el['settings']['title'] = $replacement_text;
                                        $processed_count++;
                                        
                                        // Preserve typography and other settings
                                        foreach ($original_settings as $setting_key => $setting_val) {
                                            if ($setting_key !== 'title' && !isset($el['settings'][$setting_key])) {
                                                $el['settings'][$setting_key] = $setting_val;
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                            
                        case 'text-editor':
                            if (isset($original_settings['editor'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    // Check for the code both with and without HTML tags
                                    $code_with_tags = '<p>' . $code . '</p>';
                                    if ($original_settings['editor'] === $code || $original_settings['editor'] === $code_with_tags) {
                                        // If the original had HTML tags, wrap the new content in them
                                        if (strpos($original_settings['editor'], '<p>') !== false) {
                                            $el['settings']['editor'] = '<p>' . $replacement_text . '</p>';
                                        } else {
                                            $el['settings']['editor'] = $replacement_text;
                                        }
                                        $processed_count++;
                                        
                                        // Preserve typography and other settings
                                        foreach ($original_settings as $setting_key => $setting_val) {
                                            if ($setting_key !== 'editor' && !isset($el['settings'][$setting_key])) {
                                                $el['settings'][$setting_key] = $setting_val;
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                            
                        case 'image-box':
                            if (isset($original_settings['title_text'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['title_text'] === $code) {
                                        $el['settings']['title_text'] = $replacement_text;
                                        $processed_count++;
                                        
                                        // Preserve title typography
                                        if (isset($original_settings['title_typography_typography'])) {
                                            $el['settings']['title_typography_typography'] = $original_settings['title_typography_typography'];
                                        }
                                    }
                                }
                            }
                            if (isset($original_settings['description_text'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['description_text'] === $code) {
                                        $el['settings']['description_text'] = $replacement_text;
                                        $processed_count++;
                                        
                                        // Preserve description typography
                                        if (isset($original_settings['description_typography_typography'])) {
                                            $el['settings']['description_typography_typography'] = $original_settings['description_typography_typography'];
                                        }
                                    }
                                }
                            }
                            break;
                            
                        case 'button':
                            if (isset($original_settings['text'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['text'] === $code) {
                                        $el['settings']['text'] = $replacement_text;
                                        $processed_count++;
                                    }
                                }
                            }
                            break;
                            
                        case 'icon-box':
                            if (isset($original_settings['title_text'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['title_text'] === $code) {
                                        $el['settings']['title_text'] = $replacement_text;
                                        $processed_count++;
                                    }
                                }
                            }
                            if (isset($original_settings['description_text'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['description_text'] === $code) {
                                        $el['settings']['description_text'] = $replacement_text;
                                        $processed_count++;
                                    }
                                }
                            }
                            break;
                    }
                }
            }
            
            // Process child elements
            if (isset($el['elements']) && is_array($el['elements'])) {
                $el['elements'] = $this->process_replex_elements($el['elements'], $replacements, $processed_count);
            }
        }
        return $elements;
    }
    
    private function extract_first_title_from_replex($content) {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $found_first_code = false;
        
        foreach ($lines as $line) {
            // Check if line starts with ##
            if (preg_match('/^##(.+)$/', $line)) {
                $found_first_code = true;
                continue;
            }
            // If we found the first code and this line has content, return it
            if ($found_first_code && trim($line)) {
                return trim($line);
            }
        }
        return null;
    }
}

// Start the plugin
new Veyra();

// ---------------------------------------------------------------------------
// Admin screens — each under /admin-screens/<folder>/ self-registers its menu
// and render callback. Keeps page-specific code out of this main file.
// ---------------------------------------------------------------------------
require_once VEYRA_PLUGIN_PATH . 'admin-screens/post_importer_from_birch/post-importer-from-birch.php';