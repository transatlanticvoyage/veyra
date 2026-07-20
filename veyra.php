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
// Test comment: VSCode source control sync test - 2026-05-28

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('VEYRA_PLUGIN_VERSION', '1.2.0');
define('VEYRA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('VEYRA_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Normalize fossil content before it is saved or rendered.
 *
 * Guards against LITERAL escape sequences ending up in the option value — e.g. a
 * two-character backslash-n ("\n") instead of a real newline. These can sneak in
 * when content is round-tripped through a tool that escapes newlines (mysql batch
 * output, JSON, etc.); wpautop never converts them, so they show up as visible
 * "\n\n" text on the page. We collapse "\r\n", "\n", "\r", "\t" literals to a
 * single space so they can never render as garbage, regardless of how they got in.
 */
function veyra_clean_fossil_content($content) {
    return str_replace(array("\\r\\n", "\\n", "\\r", "\\t"), ' ', (string) $content);
}

// Initialize plugin
class Veyra {
    
    private $elementor_available = false;
    
    public function __construct() {
        // Check if Elementor is available
        $this->elementor_available = $this->is_elementor_available();
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_bar_menu', array($this, 'add_elephant_tools_to_admin_bar'), 100);
        // Keep the admin-bar item's icon + label on one line (front-end & admin).
        add_action('wp_head', array($this, 'veyra_admin_bar_styles'));
        add_action('admin_head', array($this, 'veyra_admin_bar_styles'));
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

        // Fossil content (below feed): inject custom HTML at the BOTTOM of blog feed page 1.
        // loop_end handles the no-pagination case; the navigation filter places it AFTER
        // the pagination buttons ("1 2 Next ») when pagination is present.
        if (get_option('veyra_show_fossil_content_below_feed_on_blog_feed_page', false)) {
            add_action('loop_end', array($this, 'inject_fossil_content_below_feed'));
            add_filter('navigation_markup_template', array($this, 'append_fossil_below_to_pagination'), 10, 2);
        }

        // Structure-Medic (sm_*) source-data tables + ingest REST endpoint.
        // Tables are created/upgraded automatically on init when the schema
        // version changes — no plugin reactivation required.
        add_action('init', array($this, 'veyra_sm_maybe_upgrade_db'));
        add_action('rest_api_init', array($this, 'veyra_sm_register_routes'));

        // Structure-Medic post-editor panel (Classic editor): a full-width bar
        // above the title showing/editing this page's sm_* data. Inputs live
        // inside the post form, so native Save/Publish persists them via save_post.
        add_action('edit_form_top', array($this, 'veyra_sm_render_editor_bar'));
        add_action('save_post', array($this, 'veyra_sm_save_editor'), 10, 2);

        // Content-species post-editor panel: a second full-width bar (same style
        // as the sm bar above) for tagging what "species" of content this page
        // holds. Backed by a single wp_option ('veyra_content_species') keyed by
        // post ID, so each page/post owns exactly one entry in that option.
        add_action('edit_form_top', array($this, 'veyra_species_render_editor_bar'));
        add_action('save_post', array($this, 'veyra_species_save_editor'), 10, 2);

        // Cached-original-wayback-content paste box: rendered just below the native
        // post_content editor. Backed by a single wp_option
        // ('veyra_cached_original_wayback_content') keyed by post ID, so each
        // page/post owns exactly one entry in that option.
        add_action('edit_form_after_editor', array($this, 'veyra_wayback_render_editor_box'));
        add_action('save_post', array($this, 'veyra_wayback_save_editor'), 10, 2);

        add_action('admin_print_styles-post.php', array($this, 'veyra_sm_editor_styles'));
        add_action('admin_print_styles-post-new.php', array($this, 'veyra_sm_editor_styles'));
        add_action('admin_print_footer_scripts', array($this, 'veyra_sm_editor_js'));
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

        $home_anchor = isset($_POST['veyra_custom_defined_anchor_for_homepage_nav_item']) ? sanitize_text_field(wp_unslash($_POST['veyra_custom_defined_anchor_for_homepage_nav_item'])) : '';
        update_option('veyra_custom_defined_anchor_for_homepage_nav_item', $home_anchor);

        $show_full = isset($_POST['veyra_show_full_post_on_blog_feed_pages']) ? true : false;
        update_option('veyra_show_full_post_on_blog_feed_pages', $show_full);

        $show_fossil = isset($_POST['veyra_show_fossil_content_on_blog_feed_page']) ? true : false;
        update_option('veyra_show_fossil_content_on_blog_feed_page', $show_fossil);

        $fossil_content = isset($_POST['veyra_fossil_content']) ? veyra_clean_fossil_content(wp_kses_post(wp_unslash($_POST['veyra_fossil_content']))) : '';
        update_option('veyra_fossil_content', $fossil_content);

        $show_fossil_below = isset($_POST['veyra_show_fossil_content_below_feed_on_blog_feed_page']) ? true : false;
        update_option('veyra_show_fossil_content_below_feed_on_blog_feed_page', $show_fossil_below);

        $fossil_content_below = isset($_POST['veyra_fossil_content_below_feed']) ? veyra_clean_fossil_content(wp_kses_post(wp_unslash($_POST['veyra_fossil_content_below_feed']))) : '';
        update_option('veyra_fossil_content_below_feed', $fossil_content_below);

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
        $content = veyra_clean_fossil_content(get_option('veyra_fossil_content', ''));
        if (!empty($content)) {
            echo '<div class="veyra-fossil-content">' . wpautop($content) . '</div>';
        }
    }

    private function fossil_below_html() {
        $content = veyra_clean_fossil_content(get_option('veyra_fossil_content_below_feed', ''));
        if (empty($content)) {
            return '';
        }
        return '<div class="veyra-fossil-content veyra-fossil-content-below-feed">' . wpautop($content) . '</div>';
    }

    public function inject_fossil_content_below_feed($query) {
        if (!$query->is_main_query() || !is_home() || is_paged()) {
            return;
        }
        // When pagination is present, the content is appended AFTER the buttons by
        // append_fossil_below_to_pagination(); only emit here if there is no pagination.
        if ($query->max_num_pages > 1) {
            return;
        }
        echo $this->fossil_below_html();
    }

    // Appends the below-feed fossil content after the posts pagination nav ("1 2 Next »").
    public function append_fossil_below_to_pagination($template, $class) {
        if (is_admin()) {
            return $template;
        }
        if (strpos($class, 'pagination') === false || strpos($class, 'comment') !== false) {
            return $template; // only the posts pagination, not comments/post navigation
        }
        if (!is_home() || is_paged()) {
            return $template; // blog feed, page 1 only
        }
        $html = $this->fossil_below_html();
        if ($html === '') {
            return $template;
        }
        // _navigation_markup() runs sprintf() on this template afterward, so any literal
        // '%' in our HTML must be escaped to survive it.
        return $template . str_replace('%', '%%', $html);
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
        $home_anchor = get_option('veyra_custom_defined_anchor_for_homepage_nav_item', '');
        $show_full = get_option('veyra_show_full_post_on_blog_feed_pages', false);
        $show_fossil = get_option('veyra_show_fossil_content_on_blog_feed_page', false);
        $fossil_content = get_option('veyra_fossil_content', '');
        $show_fossil_below = get_option('veyra_show_fossil_content_below_feed_on_blog_feed_page', false);
        $fossil_content_below = get_option('veyra_fossil_content_below_feed', '');
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
                        <th scope="row">veyra_custom_defined_anchor_for_homepage_nav_item</th>
                        <td>
                            <input type="text" name="veyra_custom_defined_anchor_for_homepage_nav_item" value="<?php echo esc_attr($home_anchor); ?>" class="regular-text" placeholder="Home" />
                            <p class="description">The nav-menu anchor text that the CleanPress theme treats as the "Home" link, so it gets the active (blue) styling when a visitor is on the homepage &mdash; even when the homepage only shows posts. Leave blank to use the default anchor <code>Home</code>. Set to a custom value (e.g. <code>Main</code>, <code>Inicio</code>) to match a differently-labelled home link.</p>
                        </td>
                    </tr>
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
                    <tr>
                        <th scope="row">veyra_fossil_content_below_feed</th>
                        <td>
                            <textarea name="veyra_fossil_content_below_feed" rows="12" cols="80" class="large-text code"><?php echo esc_textarea($fossil_content_below); ?></textarea>
                            <p class="description">HTML content to display at the BOTTOM of the blog feed page (after posts). Only shown on page 1. Line breaks are automatically converted to paragraphs.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">veyra_show_fossil_content_below_feed_on_blog_feed_page</th>
                        <td>
                            <label>
                                <input type="checkbox" name="veyra_show_fossil_content_below_feed_on_blog_feed_page" value="1" <?php checked($show_fossil_below); ?> />
                                When enabled, displays the below-feed fossil content at the bottom of the blog feed page (page 1 only, not paginated pages).
                            </label>
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

    /** Prevent the "Veyra Hub 1" admin-bar label from wrapping below its icon
     *  (which bled out of the 32px bar). Forces icon + text onto one centered
     *  line. Printed only when the admin bar is showing. */
    public function veyra_admin_bar_styles() {
        if (!is_admin_bar_showing()) {
            return;
        }
        echo '<style id="veyra-adminbar-fix">'
            . '#wpadminbar #wp-admin-bar-veyra-hub-1 > .ab-item{'
            . 'display:flex;align-items:center;white-space:nowrap;}'
            . '#wpadminbar #wp-admin-bar-veyra-hub-1 > .ab-item img{'
            . 'width:16px;height:16px;margin:0 6px 0 0;vertical-align:middle;flex:0 0 auto;}'
            . '</style>';
    }

    public function add_elephant_tools_to_admin_bar($wp_admin_bar) {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return;
        }

        $logo_svg = '<img src="' . VEYRA_PLUGIN_URL . 'assets/images/veyra-sun-logo.svg" style="width: 16px; height: 16px; margin-right: 6px; vertical-align: middle;" alt="Veyra Logo" />';

        // Top-level admin-bar item (formerly "Veyra Elephant Tools"): now "Veyra Hub 1",
        // linking to the hub page and opening a dropdown that mirrors the wp-admin
        // "Veyra Hub 1" sidebar submenu.
        $wp_admin_bar->add_node(array(
            'id'    => 'veyra-hub-1',
            'title' => $logo_svg . 'Veyra Hub 1',
            'href'  => admin_url('admin.php?page=veyra-hub-1'),
        ));

        // Submenu items under Veyra Hub 1. The admin menu ($submenu) is only built in
        // wp-admin, so on the front end we list the hub's registered pages explicitly.
        // Each: [ menu label, page slug, capability ].
        $hub_items = array(
            array('Veyra Hub 1',                    'veyra-hub-1',                     'edit_posts'),
            array('Veyra Post Importer From Birch', 'veyra_post_importer',             'edit_posts'),
            array('Plugin Manager',                 'veyra_plugin_manager',            'manage_options'),
            array('SM Redirect Manager',            'sm_redirect_manager',             'manage_options'),
            array('Custom Blog Feed',               'veyra_custom_blog_feed',          'manage_options'),
            array('Page Change Drip Manager',       'page_change_drip_manager',        'manage_options'),
            array('Veyra Site Title And Footer Mar','veyra_site_title_and_footer_mar', 'manage_options'),
            array('veyra change wp user and pass',  'veyra_change_wp_user_and_pass',   'manage_options'),
        );
        foreach ($hub_items as $item) {
            if (!current_user_can($item[2])) {
                continue;
            }
            $wp_admin_bar->add_node(array(
                'id'     => 'veyra-hub-1-' . $item[1],
                'parent' => 'veyra-hub-1',
                'title'  => $item[0],
                'href'   => admin_url('admin.php?page=' . $item[1]),
            ));
        }
    }
    
    public function enqueue_scripts() {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return;
        }
        
        // elephant-tools modal JS/CSS enqueue removed — no longer used (the admin-bar
        // item is now the "Veyra Hub 1" dropdown). Method is no longer hooked.
        
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

    // =====================================================================
    // Structure-Medic source data (sm_* tables)
    // Stores per-page provenance + Majestic backlink data for injected pages.
    // =====================================================================

    const VEYRA_SM_DB_VERSION = '3';

    /** Base folder that holds the per-domain original source sites fed into
     *  Structure-Medic. Combined with the run's domain + local_file_path to build
     *  the "open original file" link in the editor. */
    const VEYRA_SM_SOURCE_BASE = '/Users/kc/Documents/repos/safari-traunch/';

    /** Map logical table key => full table name (with wpdb prefix). */
    private function veyra_sm_tables() {
        global $wpdb;
        $p = $wpdb->prefix;
        return array(
            'runs'       => $p . 'sm_injection_runs',
            'page'       => $p . 'sm_page_source',
            'urls'       => $p . 'sm_original_urls',
            'metrics'    => $p . 'sm_majestic_metrics',
            'ttf'        => $p . 'sm_topical_trust_flow',
            'backlinks'  => $p . 'sm_referring_backlinks',
        );
    }

    /** Create or upgrade the sm_* tables via dbDelta when the version changes. */
    public function veyra_sm_maybe_upgrade_db() {
        if (get_option('veyra_sm_db_version') === self::VEYRA_SM_DB_VERSION) {
            return;
        }
        $this->veyra_sm_create_tables();
        update_option('veyra_sm_db_version', self::VEYRA_SM_DB_VERSION);
    }

    /** Run dbDelta for all sm_* tables. Safe to call repeatedly (idempotent). */
    public function veyra_sm_create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = $this->veyra_sm_tables();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$t['runs']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  domain varchar(255) NOT NULL DEFAULT '',
  project_run varchar(255) NOT NULL DEFAULT '',
  majestic_export_file varchar(512) NOT NULL DEFAULT '',
  run_mode varchar(32) NOT NULL DEFAULT '',
  pages_injected int(11) NOT NULL DEFAULT 0,
  run_started_at datetime NULL,
  run_finished_at datetime NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY domain (domain),
  KEY project_run (project_run)
) {$charset};");

        dbDelta("CREATE TABLE {$t['page']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NULL,
  wp_post_id bigint(20) unsigned NOT NULL,
  original_html_slug varchar(512) NOT NULL DEFAULT '',
  canonical_original_url varchar(1024) NOT NULL DEFAULT '',
  local_file_path varchar(1024) NOT NULL DEFAULT '',
  wp_slug varchar(255) NOT NULL DEFAULT '',
  wp_full_path varchar(512) NOT NULL DEFAULT '',
  wp_parent_slug varchar(512) NOT NULL DEFAULT '',
  is_pdf tinyint(1) NOT NULL DEFAULT 0,
  is_asset tinyint(1) NOT NULL DEFAULT 0,
  is_front_page tinyint(1) NOT NULL DEFAULT 0,
  majestic_category varchar(255) NOT NULL DEFAULT '',
  retention_reason varchar(255) NOT NULL DEFAULT '',
  page_title text,
  post_date_extracted datetime NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY wp_post_id (wp_post_id),
  KEY original_html_slug (original_html_slug(191)),
  KEY canonical_original_url (canonical_original_url(191)),
  KEY run_id (run_id)
) {$charset};");

        dbDelta("CREATE TABLE {$t['urls']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  page_source_id bigint(20) unsigned NOT NULL,
  original_url varchar(1024) NOT NULL DEFAULT '',
  url_path varchar(1024) NOT NULL DEFAULT '',
  url_extension varchar(32) NOT NULL DEFAULT '',
  has_query_string tinyint(1) NOT NULL DEFAULT 0,
  is_canonical tinyint(1) NOT NULL DEFAULT 0,
  redirect_to_wp tinyint(1) NOT NULL DEFAULT 1,
  redirect_http_code smallint(6) NOT NULL DEFAULT 301,
  PRIMARY KEY  (id),
  KEY page_source_id (page_source_id),
  KEY original_url (original_url(191))
) {$charset};");

        dbDelta("CREATE TABLE {$t['metrics']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  page_source_id bigint(20) unsigned NOT NULL,
  external_referring_domains int(11) NOT NULL DEFAULT 0,
  external_inbound_links int(11) NOT NULL DEFAULT 0,
  external_referring_urls int(11) NOT NULL DEFAULT 0,
  trust_flow smallint(6) NOT NULL DEFAULT 0,
  citation_flow smallint(6) NOT NULL DEFAULT 0,
  last_crawl_result varchar(64) NOT NULL DEFAULT '',
  last_seen date NULL,
  language varchar(16) NOT NULL DEFAULT '',
  language_confidence int(11) NOT NULL DEFAULT 0,
  consolidation_qty_rows int(11) NOT NULL DEFAULT 0,
  consolidation_highest_tf smallint(6) NOT NULL DEFAULT 0,
  consolidation_lowest_tf smallint(6) NOT NULL DEFAULT 0,
  consolidation_highest_ref_domains int(11) NOT NULL DEFAULT 0,
  consolidation_lowest_ref_domains int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY page_source_id (page_source_id),
  KEY trust_flow (trust_flow)
) {$charset};");

        dbDelta("CREATE TABLE {$t['ttf']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  page_source_id bigint(20) unsigned NOT NULL,
  ttf_rank tinyint(3) unsigned NOT NULL DEFAULT 0,
  topic varchar(128) NOT NULL DEFAULT '',
  value smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY page_source_id (page_source_id)
) {$charset};");

        dbDelta("CREATE TABLE {$t['backlinks']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  page_source_id bigint(20) unsigned NOT NULL,
  source_url varchar(1024) NOT NULL DEFAULT '',
  source_domain varchar(255) NOT NULL DEFAULT '',
  anchor_text text,
  source_trust_flow smallint(6) NOT NULL DEFAULT 0,
  link_type varchar(16) NOT NULL DEFAULT '',
  first_seen date NULL,
  last_seen date NULL,
  PRIMARY KEY  (id),
  KEY page_source_id (page_source_id),
  KEY source_domain (source_domain)
) {$charset};");
    }

    /** Register the ingest REST route used by structure-medic Phase 10. */
    public function veyra_sm_register_routes() {
        register_rest_route('veyra/v1', '/sm-page-source', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'veyra_sm_ingest'),
            'permission_callback' => function () { return current_user_can('edit_pages'); },
        ));
        register_rest_route('veyra/v1', '/sm-editor-save', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'veyra_sm_editor_save_rest'),
            'permission_callback' => function ($req) {
                return current_user_can('edit_post', intval($req->get_param('wp_post_id')));
            },
        ));
        // Returns the wp_post_ids that Structure-Medic currently manages (from
        // sm_page_source). The injector deletes ONLY these before re-injecting —
        // so manual posts and other pages are never touched.
        register_rest_route('veyra/v1', '/sm-page-ids', array(
            'methods'             => 'GET',
            'callback'            => function () {
                global $wpdb;
                $t = $this->veyra_sm_tables();
                $ids = $wpdb->get_col("SELECT wp_post_id FROM {$t['page']}");
                return array('ids' => array_map('intval', $ids));
            },
            'permission_callback' => function () { return current_user_can('edit_pages'); },
        ));
    }

    /**
     * Upsert all structure-medic source data for one injected WP page.
     * Idempotent: re-posting for the same wp_post_id replaces its sm_* rows.
     */
    public function veyra_sm_ingest($request) {
        global $wpdb;
        $t = $this->veyra_sm_tables();
        $b = $request->get_json_params();

        $wp_post_id = isset($b['wp_post_id']) ? intval($b['wp_post_id']) : 0;
        if (!$wp_post_id || !get_post($wp_post_id)) {
            return new WP_Error('veyra_sm_bad_post', 'Missing or unknown wp_post_id', array('status' => 400));
        }

        // 1. Upsert the run row (one per domain + project_run).
        $run    = isset($b['run']) ? $b['run'] : array();
        $domain = isset($run['domain']) ? $run['domain'] : '';
        $proj   = isset($run['project_run']) ? $run['project_run'] : '';
        $run_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t['runs']} WHERE domain=%s AND project_run=%s", $domain, $proj));
        if (!$run_id) {
            $wpdb->insert($t['runs'], array(
                'domain' => $domain,
                'project_run' => $proj,
                'majestic_export_file' => isset($run['majestic_export_file']) ? $run['majestic_export_file'] : '',
                'run_mode' => isset($run['run_mode']) ? $run['run_mode'] : '',
            ));
            $run_id = $wpdb->insert_id;
        }

        // 2. Remove any existing rows for this page (idempotent re-inject).
        $old = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t['page']} WHERE wp_post_id=%d", $wp_post_id));
        if ($old) {
            foreach (array('urls', 'metrics', 'ttf', 'backlinks') as $k) {
                $wpdb->delete($t[$k], array('page_source_id' => $old));
            }
            $wpdb->delete($t['page'], array('id' => $old));
        }

        // 3. Insert the page_source row.
        $pg = isset($b['page']) ? $b['page'] : array();
        $wpdb->insert($t['page'], array(
            'run_id' => $run_id,
            'wp_post_id' => $wp_post_id,
            'original_html_slug' => isset($pg['original_html_slug']) ? $pg['original_html_slug'] : '',
            'canonical_original_url' => isset($pg['canonical_original_url']) ? $pg['canonical_original_url'] : '',
            'local_file_path' => isset($pg['local_file_path']) ? $pg['local_file_path'] : '',
            'wp_slug' => isset($pg['wp_slug']) ? $pg['wp_slug'] : '',
            'wp_full_path' => isset($pg['wp_full_path']) ? $pg['wp_full_path'] : '',
            'wp_parent_slug' => isset($pg['wp_parent_slug']) ? $pg['wp_parent_slug'] : '',
            'is_pdf' => !empty($pg['is_pdf']) ? 1 : 0,
            'is_asset' => !empty($pg['is_asset']) ? 1 : 0,
            'is_front_page' => !empty($pg['is_front_page']) ? 1 : 0,
            'majestic_category' => isset($pg['majestic_category']) ? $pg['majestic_category'] : '',
            'retention_reason' => isset($pg['retention_reason']) ? $pg['retention_reason'] : '',
            'page_title' => isset($pg['page_title']) ? $pg['page_title'] : '',
            'post_date_extracted' => !empty($pg['post_date_extracted']) ? $pg['post_date_extracted'] : null,
        ));
        $page_source_id = $wpdb->insert_id;
        if (!$page_source_id) {
            return new WP_Error('veyra_sm_insert_failed',
                'page_source insert failed: ' . $wpdb->last_error,
                array('status' => 500));
        }

        // 4. Majestic metrics (1:1).
        if (!empty($b['metrics'])) {
            $m = $b['metrics'];
            $wpdb->insert($t['metrics'], array(
                'page_source_id' => $page_source_id,
                'external_referring_domains' => intval($m['external_referring_domains'] ?? 0),
                'external_inbound_links' => intval($m['external_inbound_links'] ?? 0),
                'external_referring_urls' => intval($m['external_referring_urls'] ?? 0),
                'trust_flow' => intval($m['trust_flow'] ?? 0),
                'citation_flow' => intval($m['citation_flow'] ?? 0),
                'last_crawl_result' => $m['last_crawl_result'] ?? '',
                'last_seen' => !empty($m['last_seen']) ? $m['last_seen'] : null,
                'language' => $m['language'] ?? '',
                'language_confidence' => intval($m['language_confidence'] ?? 0),
                'consolidation_qty_rows' => intval($m['consolidation_qty_rows'] ?? 0),
                'consolidation_highest_tf' => intval($m['consolidation_highest_tf'] ?? 0),
                'consolidation_lowest_tf' => intval($m['consolidation_lowest_tf'] ?? 0),
                'consolidation_highest_ref_domains' => intval($m['consolidation_highest_ref_domains'] ?? 0),
                'consolidation_lowest_ref_domains' => intval($m['consolidation_lowest_ref_domains'] ?? 0),
            ));
        }

        // 5. Original URLs (1:many).
        if (!empty($b['original_urls']) && is_array($b['original_urls'])) {
            foreach ($b['original_urls'] as $u) {
                $wpdb->insert($t['urls'], array(
                    'page_source_id' => $page_source_id,
                    'original_url' => $u['original_url'] ?? '',
                    'url_path' => $u['url_path'] ?? '',
                    'url_extension' => $u['url_extension'] ?? '',
                    'has_query_string' => !empty($u['has_query_string']) ? 1 : 0,
                    'is_canonical' => !empty($u['is_canonical']) ? 1 : 0,
                    'redirect_to_wp' => isset($u['redirect_to_wp']) ? (!empty($u['redirect_to_wp']) ? 1 : 0) : 1,
                    'redirect_http_code' => intval($u['redirect_http_code'] ?? 301),
                ));
            }
        }

        // 6. Topical Trust Flow (1:many).
        if (!empty($b['topical_trust_flow']) && is_array($b['topical_trust_flow'])) {
            foreach ($b['topical_trust_flow'] as $ttf) {
                $wpdb->insert($t['ttf'], array(
                    'page_source_id' => $page_source_id,
                    'ttf_rank' => intval($ttf['rank'] ?? 0),
                    'topic' => $ttf['topic'] ?? '',
                    'value' => intval($ttf['value'] ?? 0),
                ));
            }
        }

        return array('ok' => true, 'page_source_id' => $page_source_id, 'run_id' => $run_id);
    }

    // ---- Post-editor panel: view/edit sm_* data for the current page --------

    /** Columns shown read-only and never written from the editor. */
    private function veyra_sm_structural_cols() {
        return array('id', 'page_source_id', 'run_id', 'wp_post_id', 'created_at', 'updated_at');
    }

    /** Load all sm_* rows for a post, grouped (and ordered) by table name. */
    private function veyra_sm_load_for_post($post_id) {
        global $wpdb;
        $t = $this->veyra_sm_tables();
        $ps = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['page']} WHERE wp_post_id=%d", $post_id), ARRAY_A);
        if (!$ps) {
            return array();
        }
        $psid = intval($ps['id']);
        $out = array();
        if (!empty($ps['run_id'])) {
            $run = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['runs']} WHERE id=%d", $ps['run_id']), ARRAY_A);
            if ($run) { $out[$t['runs']] = array($run); }
        }
        $out[$t['page']] = array($ps);
        foreach (array('metrics' => 'id', 'urls' => 'id', 'ttf' => 'ttf_rank', 'backlinks' => 'id') as $k => $order) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$t[$k]} WHERE page_source_id=%d ORDER BY {$order}", $psid), ARRAY_A);
            if ($rows) { $out[$t[$k]] = $rows; }
        }
        return $out;
    }

    /** Full-width bar above the title (edit_form_top) with the sm_* fields. */
    public function veyra_sm_render_editor_bar($post) {
        if (!$post || !in_array($post->post_type, array('post', 'page'), true)) {
            return;
        }
        $data = $this->veyra_sm_load_for_post($post->ID);
        $struct = $this->veyra_sm_structural_cols();
        // Domain for the "open original file" link (from this run's injection row).
        $runs_table = $this->veyra_sm_tables()['runs'];
        $sm_domain = '';
        if (!empty($data[$runs_table][0]['domain'])) {
            $sm_domain = $data[$runs_table][0]['domain'];
        }
        $file_prefix = 'file://' . rtrim(self::VEYRA_SM_SOURCE_BASE, '/') . '/' . $sm_domain;
        echo '<div class="veyra-sm-bar" id="veyra-sm-bar">';
        echo '<div class="veyra-sm-bar__header" id="veyra-sm-bar-header">'
            . '<span class="veyra-sm-bar__label">veyra structure medic info</span>'
            . '<span class="veyra-sm-bar__toggle" id="veyra-sm-toggle">[collapse]</span></div>';
        echo '<div class="veyra-sm-bar__body" id="veyra-sm-bar-body">';
        wp_nonce_field('veyra_sm_save', 'veyra_sm_nonce');
        echo '<div class="veyra-sm-actions"><button type="button" class="button button-primary" id="veyra-sm-save">Save SM Data</button> <span id="veyra-sm-save-status" class="veyra-sm-status"></span></div>';
        if (empty($data)) {
            echo '<p class="veyra-sm-empty">No Structure-Medic data is associated with this page.</p>';
        } else {
            foreach ($data as $table => $rows) {
                echo '<div class="veyra-sm-table">';
                echo '<div class="veyra-sm-table__name">' . esc_html($table) . '</div>';
                foreach ($rows as $row) {
                    $rid = isset($row['id']) ? intval($row['id']) : 0;
                    echo '<div class="veyra-sm-row">';
                    foreach ($row as $col => $val) {
                        $is_struct = in_array($col, $struct, true);
                        $is_url = (stripos($col, 'url') !== false) && !$is_struct;
                        $name = 'sm[' . esc_attr($table) . '][' . $rid . '][' . esc_attr($col) . ']';
                        echo '<div class="veyra-sm-field">';
                        echo '<label>' . esc_html($col) . '</label>';
                        if ($col === 'local_file_path') {
                            echo '<div class="veyra-sm-linkrow">';
                            echo '<input type="text" class="veyra-sm-input" name="' . $name . '" value="' . esc_attr($val) . '" />';
                            echo '<button type="button" class="button veyra-sm-openfile" data-prefix="' . esc_attr($file_prefix) . '">open</button>';
                            echo '<button type="button" class="button veyra-sm-copyfile" data-prefix="' . esc_attr($file_prefix) . '">copy path</button>';
                            echo '</div>';
                        } elseif ($is_url) {
                            echo '<div class="veyra-sm-linkrow">';
                            echo '<input type="text" class="veyra-sm-input" name="' . $name . '" value="' . esc_attr($val) . '" />';
                            echo '<button type="button" class="button veyra-sm-open">open</button>';
                            echo '<button type="button" class="button veyra-sm-wayback">wayback</button>';
                            echo '</div>';
                        } elseif ($is_struct) {
                            echo '<input type="text" readonly="readonly" class="veyra-sm-input veyra-sm-ro" value="' . esc_attr($val) . '" />';
                        } else {
                            echo '<input type="text" class="veyra-sm-input" name="' . $name . '" value="' . esc_attr($val) . '" />';
                        }
                        echo '</div>';
                    }
                    echo '</div>';
                }
                echo '</div>';
            }
        }
        echo '</div></div>';
    }

    /** Persist edited sm_* fields on native Save/Publish (save_post). */
    public function veyra_sm_save_editor($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (wp_is_post_revision($post_id)) { return; }
        if (!isset($_POST['veyra_sm_nonce']) || !wp_verify_nonce($_POST['veyra_sm_nonce'], 'veyra_sm_save')) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }
        if (empty($_POST['sm']) || !is_array($_POST['sm'])) { return; }
        $this->veyra_sm_apply_updates($post_id, wp_unslash($_POST['sm']));
    }

    /** REST save used by the bar's standalone "Save SM Data" button. */
    public function veyra_sm_editor_save_rest($request) {
        $post_id = intval($request->get_param('wp_post_id'));
        if (!$post_id || !get_post($post_id)) {
            return new WP_Error('veyra_sm_bad_post', 'Unknown post', array('status' => 400));
        }
        $n = $this->veyra_sm_apply_updates($post_id, $request->get_param('sm'));
        return array('ok' => true, 'rows_updated' => $n);
    }

    /** Apply edited sm_* field values (shared by save_post and REST save).
     *  Whitelists tables/columns and only touches rows owned by $post_id. */
    private function veyra_sm_apply_updates($post_id, $sm) {
        global $wpdb;
        if (!is_array($sm)) { return 0; }
        $struct = $this->veyra_sm_structural_cols();

        $valid = $this->veyra_sm_load_for_post($post_id);
        $owned = array();
        foreach ($valid as $table => $rows) {
            foreach ($rows as $r) { $owned[$table][intval($r['id'])] = true; }
        }

        $updated = 0;
        foreach ($sm as $table => $rows) {
            if (!isset($owned[$table]) || !is_array($rows)) { continue; }
            $cols = $wpdb->get_col("DESCRIBE `" . esc_sql($table) . "`");
            foreach ($rows as $rid => $fields) {
                $rid = intval($rid);
                if (empty($owned[$table][$rid]) || !is_array($fields)) { continue; }
                $update = array();
                foreach ($fields as $col => $val) {
                    if (in_array($col, $struct, true) || !in_array($col, $cols, true)) { continue; }
                    $update[$col] = is_scalar($val) ? (string) $val : '';
                }
                if ($update) {
                    $wpdb->update($table, $update, array('id' => $rid));
                    $updated++;
                }
            }
        }
        return $updated;
    }

    /** Full-width bar (edit_form_top) for tagging this post's content species. */
    public function veyra_species_render_editor_bar($post) {
        if (!$post || !in_array($post->post_type, array('post', 'page'), true)) {
            return;
        }
        $all_species = get_option('veyra_content_species', array());
        if (!is_array($all_species)) { $all_species = array(); }
        $species_value = isset($all_species[$post->ID]) ? $all_species[$post->ID] : '';

        $all_subspecies = get_option('veyra_content_subspecies', array());
        if (!is_array($all_subspecies)) { $all_subspecies = array(); }
        $subspecies_value = isset($all_subspecies[$post->ID]) ? $all_subspecies[$post->ID] : '';

        echo '<div class="veyra-sm-bar veyra-species-bar" id="veyra-species-bar">';
        echo '<div class="veyra-sm-bar__header" id="veyra-species-bar-header">'
            . '<span class="veyra-sm-bar__label">veyra content species info</span>'
            . '<span class="veyra-sm-bar__toggle" id="veyra-species-toggle">[collapse]</span></div>';
        echo '<div class="veyra-sm-bar__body" id="veyra-species-bar-body">';
        wp_nonce_field('veyra_species_save', 'veyra_species_nonce');
        echo '<div class="veyra-species-row">';
        echo '<label class="veyra-species-label" for="veyra-species-input">wp_options: veyra_content_species</label>';
        echo '<input type="text" id="veyra-species-input" class="veyra-sm-input veyra-species-input" name="veyra_content_species" value="' . esc_attr($species_value) . '" />';
        echo '<div class="veyra-species-pills">';
        echo '<button type="button" class="veyra-species-pill veyra-species-pill--clear" data-value="">(clear)</button>';
        $pills = array('content_direct_from_wayback', 'new_content_in_style_of_wayback_topic', 'desirable_commercial_content');
        foreach ($pills as $pill) {
            echo '<button type="button" class="veyra-species-pill" data-value="' . esc_attr($pill) . '">'
                . '<span class="veyra-species-pill__main">' . esc_html($pill) . '</span>'
                . '<span class="veyra-species-pill__copy" data-copy-value="' . esc_attr($pill) . '" title="Copy value to clipboard">c</span>'
                . '</button>';
        }
        echo '</div>';
        echo '</div>';

        // Shown when the species value is exactly "content_direct_from_wayback" OR a
        // subspecies value is already saved (so existing tags stay visible even if
        // species is later changed away) — kept in sync live by JS as either field
        // changes (typed or set via pill click).
        $subspecies_visible = ($species_value === 'content_direct_from_wayback' || $subspecies_value !== '');
        echo '<div class="veyra-species-row veyra-subspecies-row" id="veyra-subspecies-row"' . ($subspecies_visible ? '' : ' style="display:none;"') . '>';
        echo '<label class="veyra-species-label" for="veyra-subspecies-input">wp_options: veyra_content_subspecies</label>';
        echo '<input type="text" id="veyra-subspecies-input" class="veyra-sm-input veyra-species-input" name="veyra_content_subspecies" value="' . esc_attr($subspecies_value) . '" />';
        echo '<div class="veyra-species-pills">';
        echo '<button type="button" class="veyra-species-pill veyra-species-pill--clear" data-value="">(clear)</button>';
        $subspecies_pills = array(
            'actual_copied_historical_content - direct from wayback',
            'new_freshly_invented_content - placed on page direct from wayback',
        );
        foreach ($subspecies_pills as $raw_pill) {
            $parts = array_map('trim', explode(' - ', $raw_pill, 2));
            $pill_value = $parts[0];
            $pill_desc  = isset($parts[1]) ? $parts[1] : '';
            echo '<div class="veyra-species-pill-wrap">';
            echo '<button type="button" class="veyra-species-pill" data-value="' . esc_attr($pill_value) . '">'
                . '<span class="veyra-species-pill__main">' . esc_html($pill_value) . '</span>'
                . '<span class="veyra-species-pill__copy" data-copy-value="' . esc_attr($pill_value) . '" title="Copy value to clipboard">c</span>'
                . '</button>';
            if ($pill_desc !== '') {
                echo '<div class="veyra-species-pill-desc">' . esc_html($pill_desc) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<span class="veyra-species-note">NOTE: only use subspecies value when species is "content_direct_from_wayback"</span>';
        echo '</div>';

        echo '</div></div>';
    }

    /** Persist this post's content-species/subspecies tags into their shared, post-ID-keyed wp_options. */
    public function veyra_species_save_editor($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (wp_is_post_revision($post_id)) { return; }
        if (!isset($_POST['veyra_species_nonce']) || !wp_verify_nonce($_POST['veyra_species_nonce'], 'veyra_species_save')) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }

        if (isset($_POST['veyra_content_species'])) {
            $value = sanitize_text_field(wp_unslash($_POST['veyra_content_species']));
            $all_species = get_option('veyra_content_species', array());
            if (!is_array($all_species)) { $all_species = array(); }
            if ($value === '') {
                unset($all_species[$post_id]);
            } else {
                $all_species[$post_id] = $value;
            }
            update_option('veyra_content_species', $all_species);
        }

        if (isset($_POST['veyra_content_subspecies'])) {
            $sub_value = sanitize_text_field(wp_unslash($_POST['veyra_content_subspecies']));
            $all_subspecies = get_option('veyra_content_subspecies', array());
            if (!is_array($all_subspecies)) { $all_subspecies = array(); }
            if ($sub_value === '') {
                unset($all_subspecies[$post_id]);
            } else {
                $all_subspecies[$post_id] = $sub_value;
            }
            update_option('veyra_content_subspecies', $all_subspecies);
        }
    }

    /** Option names backing the snap-height paste boxes rendered below the post_content editor. */
    private function veyra_snap_height_option_names() {
        return array(
            'veyra_cached_original_wayback_content',
            'veyra_freshly_invented_content_before_deployment_to_live_post_content',
        );
    }

    /** Paste boxes (edit_form_after_editor) below the native content editor: caching a
     *  page's original wayback content, and staging freshly-invented content before it's
     *  deployed into the live post_content. Each is backed by its own wp_option keyed by
     *  post ID, so each page/post owns exactly one entry per option. */
    public function veyra_wayback_render_editor_box($post) {
        if (!$post || !in_array($post->post_type, array('post', 'page'), true)) {
            return;
        }
        wp_nonce_field('veyra_wayback_save', 'veyra_wayback_nonce');
        $this->veyra_render_wayback_title_field($post->ID);
        $this->veyra_render_snap_height_box($post->ID, 'veyra_cached_original_wayback_content');
        $this->veyra_render_freshly_post_title_field($post->ID);
        $this->veyra_render_snap_height_box($post->ID, 'veyra_freshly_invented_content_before_deployment_to_live_post_content');
    }

    /** Renders the single-line text input for this post's cached original wayback
     *  post_title — one instance per post, same post-ID-keyed wp_option pattern as
     *  the species/subspecies fields above. Sits just above the wayback-content box. */
    private function veyra_render_wayback_title_field($post_id) {
        $all = get_option('veyra_cached_original_wayback_post_title', array());
        if (!is_array($all)) { $all = array(); }
        $value = isset($all[$post_id]) ? $all[$post_id] : '';

        echo '<div class="veyra-wayback-box veyra-wayback-title-box">';
        echo '<p class="veyra-wayback-label">wp_options: veyra_cached_original_wayback_post_title</p>';
        echo '<input type="text" class="veyra-sm-input" name="veyra_cached_original_wayback_post_title" value="' . esc_attr($value) . '" />';
        echo '</div>';
    }

    /** Renders the single-line text input for this post's freshly-invented replacement
     *  post_title — one instance per post, same post-ID-keyed wp_option pattern as the
     *  wayback title field above. Sits just above the freshly-invented-content box. */
    private function veyra_render_freshly_post_title_field($post_id) {
        $all = get_option('veyra_freshly_post_title', array());
        if (!is_array($all)) { $all = array(); }
        $value = isset($all[$post_id]) ? $all[$post_id] : '';

        echo '<div class="veyra-wayback-box veyra-wayback-title-box">';
        echo '<p class="veyra-wayback-label">wp_options: veyra_freshly_post_title</p>';
        echo '<input type="text" class="veyra-sm-input" name="veyra_freshly_post_title" value="' . esc_attr($value) . '" />';
        echo '</div>';
    }

    /** Renders one snap-height labeled textarea box for a given post-ID-keyed wp_option. */
    private function veyra_render_snap_height_box($post_id, $option_name) {
        $all = get_option($option_name, array());
        if (!is_array($all)) { $all = array(); }
        $value = isset($all[$post_id]) ? $all[$post_id] : '';
        $textarea_id  = 'veyra-snap-' . str_replace('_', '-', $option_name);
        $heightbar_id = $textarea_id . '-heightbar';

        echo '<div class="veyra-wayback-box" id="' . esc_attr($textarea_id) . '-box">';
        echo '<p class="veyra-wayback-label">wp_options: ' . esc_html($option_name) . '</p>';
        echo '<div class="veyra-wayback-heightbar" id="' . esc_attr($heightbar_id) . '">';
        echo '<button type="button" class="button veyra-wayback-height-btn veyra-wayback-height-btn--active" data-h="100">height: 100 px</button>';
        echo '<span class="veyra-wayback-sep">|</span>';
        echo '<button type="button" class="button veyra-wayback-height-btn" data-h="300">300 px</button>';
        echo '<span class="veyra-wayback-sep">|</span>';
        echo '<button type="button" class="button veyra-wayback-height-btn" data-h="full">100% of contents</button>';
        echo '</div>';
        echo '<textarea id="' . esc_attr($textarea_id) . '" class="veyra-wayback-textarea" name="' . esc_attr($option_name) . '">' . esc_textarea($value) . '</textarea>';
        echo '</div>';
    }

    /** Persist each snap-height box's paste into its own shared, post-ID-keyed wp_option. */
    public function veyra_wayback_save_editor($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (wp_is_post_revision($post_id)) { return; }
        if (!isset($_POST['veyra_wayback_nonce']) || !wp_verify_nonce($_POST['veyra_wayback_nonce'], 'veyra_wayback_save')) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }

        if (isset($_POST['veyra_cached_original_wayback_post_title'])) {
            $title_value = sanitize_text_field(wp_unslash($_POST['veyra_cached_original_wayback_post_title']));
            $all_titles = get_option('veyra_cached_original_wayback_post_title', array());
            if (!is_array($all_titles)) { $all_titles = array(); }
            if ($title_value === '') {
                unset($all_titles[$post_id]);
            } else {
                $all_titles[$post_id] = $title_value;
            }
            update_option('veyra_cached_original_wayback_post_title', $all_titles);
        }

        if (isset($_POST['veyra_freshly_post_title'])) {
            $freshly_title_value = sanitize_text_field(wp_unslash($_POST['veyra_freshly_post_title']));
            $all_freshly_titles = get_option('veyra_freshly_post_title', array());
            if (!is_array($all_freshly_titles)) { $all_freshly_titles = array(); }
            if ($freshly_title_value === '') {
                unset($all_freshly_titles[$post_id]);
            } else {
                $all_freshly_titles[$post_id] = $freshly_title_value;
            }
            update_option('veyra_freshly_post_title', $all_freshly_titles);
        }

        foreach ($this->veyra_snap_height_option_names() as $option_name) {
            $this->veyra_save_snap_height_field($post_id, $option_name);
        }
    }

    /** Upserts (or clears) this post's entry in one snap-height option's post-ID-keyed array. */
    private function veyra_save_snap_height_field($post_id, $option_name) {
        if (!isset($_POST[$option_name])) { return; }

        $value = wp_unslash($_POST[$option_name]);
        $all = get_option($option_name, array());
        if (!is_array($all)) { $all = array(); }

        if (trim($value) === '') {
            unset($all[$post_id]);
        } else {
            $all[$post_id] = $value;
        }
        // autoload=false: these options can grow large (raw pasted page content per post)
        // and are only ever read on the single post's edit screen, so they should never
        // be loaded on every front-end/admin request.
        update_option($option_name, $all, false);
    }

    /** Inline styles for the editor bar (only on the post edit screens). */
    public function veyra_sm_editor_styles() {
        echo '<style>
        .veyra-sm-bar { border:1px solid #c3c4c7; background:#fff; margin:0 0 16px 0; }
        .veyra-sm-bar__header { background:#1d2327; color:#fff; padding:10px 14px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; }
        .veyra-sm-bar__label { font-weight:700; font-size:16px; font-style:normal; }
        .veyra-sm-bar__toggle { font-size:12px; opacity:.85; }
        .veyra-sm-bar.collapsed .veyra-sm-bar__body { display:none; }
        .veyra-sm-bar__body { padding:14px; }
        .veyra-sm-empty { font-style:italic; color:#646970; }
        .veyra-sm-table { border:1px solid #c3c4c7; padding:12px; margin:0 0 14px 0; }
        .veyra-sm-table__name { font-weight:700; font-size:16px; font-style:normal; margin-bottom:10px; }
        .veyra-sm-row { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px,1fr)); gap:10px 18px; margin-bottom:8px; padding-bottom:8px; border-bottom:1px dashed #e0e0e0; }
        .veyra-sm-field label { display:block; font-weight:700; font-size:16px; font-style:normal; margin-bottom:3px; }
        .veyra-sm-input { width:100%; }
        .veyra-sm-ro { background:#f0f0f1; color:#646970; }
        .veyra-sm-linkrow { display:flex; gap:4px; align-items:center; }
        .veyra-sm-linkrow .veyra-sm-input { flex:1; }
        .veyra-sm-actions { margin:0 0 12px 0; }
        .veyra-sm-status { margin-left:10px; font-style:italic; color:#646970; }
        .veyra-species-row { display:flex; align-items:center; flex-wrap:wrap; gap:14px; }
        .veyra-species-label { font-weight:700; font-size:14px; white-space:nowrap; }
        .veyra-species-input { flex:1 1 260px; max-width:420px; }
        .veyra-species-pills { display:flex; gap:8px; flex-wrap:wrap; }
        .veyra-species-pill { display:inline-flex; align-items:stretch; border-radius:999px; padding:0; background:#f0f0f1; border:1px solid #c3c4c7; cursor:pointer; font-size:12px; line-height:1.4; overflow:hidden; }
        .veyra-species-pill:hover { background:#dcdcde; }
        .veyra-species-pill__main { display:flex; align-items:center; padding:5px 16px; }
        .veyra-species-pill__copy { display:flex; align-items:center; justify-content:center; width:16px; flex:0 0 16px; border-left:1px solid #c3c4c7; font-weight:700; color:#646970; }
        .veyra-species-pill__copy:hover { background:#c3c4c7; color:#1d2327; }
        .veyra-species-pill--clear { padding:5px 16px; background:transparent; border-color:transparent; color:#a7aaad; font-style:italic; }
        .veyra-species-pill--clear:hover { background:#f0f0f1; color:#646970; }
        .veyra-subspecies-row { margin-top:14px; padding-top:14px; border-top:1px dashed #e0e0e0; }
        .veyra-species-pill-wrap { display:flex; flex-direction:column; align-items:flex-start; gap:4px; }
        .veyra-species-pill-desc { font-size:11px; color:#646970; font-style:italic; max-width:240px; line-height:1.3; }
        .veyra-species-note { font-size:12px; color:#b32d2e; font-style:italic; }
        .veyra-wayback-box { border:1px solid #c3c4c7; background:#fff; margin:20px 0 0 0; padding:14px; }
        .veyra-wayback-label { font-weight:700; font-size:14px; margin:0 0 10px; }
        .veyra-wayback-heightbar { margin:0 0 8px; display:flex; align-items:center; gap:8px; }
        .veyra-wayback-sep { color:#c3c4c7; }
        .veyra-wayback-height-btn--active { background:#2271b1; color:#fff; border-color:#2271b1; }
        .veyra-wayback-textarea { width:100%; box-sizing:border-box; font-family:monospace; font-size:12px; height:100px; overflow-y:auto; resize:none; display:block; }
        </style>';
    }

    /** Collapse toggle + open / wayback link buttons (post edit screen only). */
    public function veyra_sm_editor_js() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'post') { return; }
        $cfg = array(
            'url'    => esc_url_raw(rest_url('veyra/v1/sm-editor-save')),
            'nonce'  => wp_create_nonce('wp_rest'),
            'postId' => isset($_GET['post']) ? intval($_GET['post']) : (int) get_the_ID(),
        );
        ?>
        <script>
        window.veyraSm = <?php echo wp_json_encode($cfg); ?>;
        (function(){
            // Collapse/expand toggle — shared by every ".veyra-sm-bar" box (sm bar + species bar).
            document.querySelectorAll('.veyra-sm-bar').forEach(function(barEl){
                var headerEl = barEl.querySelector('.veyra-sm-bar__header');
                var toggleEl = barEl.querySelector('.veyra-sm-bar__toggle');
                if (!headerEl) { return; }
                var key = barEl.id === 'veyra-sm-bar' ? 'veyra_sm_collapsed' : 'veyra_sm_collapsed_' + barEl.id;
                function apply(c){
                    if (c) { barEl.classList.add('collapsed'); if (toggleEl) toggleEl.textContent = '[expand]'; }
                    else  { barEl.classList.remove('collapsed'); if (toggleEl) toggleEl.textContent = '[collapse]'; }
                }
                apply(localStorage.getItem(key) === '1');
                headerEl.addEventListener('click', function(){
                    var c = !barEl.classList.contains('collapsed');
                    apply(c); localStorage.setItem(key, c ? '1' : '0');
                });
            });

            // Content-species pills: clicking the main area fills the text input; clicking
            // the right-edge "c" strip copies that pill's value to the clipboard instead.
            function veyraSpeciesCopy(str) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(str).catch(function(){ veyraSpeciesCopyFallback(str); });
                } else {
                    veyraSpeciesCopyFallback(str);
                }
            }
            function veyraSpeciesCopyFallback(str) {
                var ta = document.createElement('textarea');
                ta.value = str;
                ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.focus(); ta.select();
                try { document.execCommand('copy'); } catch (err) { /* no-op */ }
                document.body.removeChild(ta);
            }
            // Subspecies strip shows when species is exactly "content_direct_from_wayback"
            // OR a subspecies value is already present — recomputed on every change to
            // either field, whether typed or set via a pill click.
            function veyraUpdateSubspeciesVisibility() {
                var speciesInput    = document.getElementById('veyra-species-input');
                var subspeciesInput = document.getElementById('veyra-subspecies-input');
                var row             = document.getElementById('veyra-subspecies-row');
                if (!speciesInput || !subspeciesInput || !row) { return; }
                var show = (speciesInput.value.trim() === 'content_direct_from_wayback') || (subspeciesInput.value.trim() !== '');
                row.style.display = show ? '' : 'none';
            }

            var speciesBar = document.getElementById('veyra-species-bar');
            if (speciesBar) {
                speciesBar.addEventListener('click', function(e){
                    var copyEl = e.target.closest ? e.target.closest('.veyra-species-pill__copy') : null;
                    if (copyEl) {
                        e.preventDefault(); e.stopPropagation();
                        var copyVal = copyEl.getAttribute('data-copy-value') || '';
                        if (!copyVal) { return; }
                        veyraSpeciesCopy(copyVal);
                        var orig = copyEl.textContent;
                        copyEl.textContent = '✓';
                        setTimeout(function(){ copyEl.textContent = orig; }, 1000);
                        return;
                    }
                    var pillEl = e.target.closest ? e.target.closest('.veyra-species-pill') : null;
                    if (pillEl) {
                        e.preventDefault();
                        var rowEl = pillEl.closest('.veyra-species-row');
                        var input = rowEl ? rowEl.querySelector('input.veyra-species-input') : document.getElementById('veyra-species-input');
                        if (input) { input.value = pillEl.getAttribute('data-value') || ''; }
                        veyraUpdateSubspeciesVisibility();
                    }
                });
            }

            var veyraSpeciesInputEl    = document.getElementById('veyra-species-input');
            var veyraSubspeciesInputEl = document.getElementById('veyra-subspecies-input');
            if (veyraSpeciesInputEl)    { veyraSpeciesInputEl.addEventListener('input', veyraUpdateSubspeciesVisibility); }
            if (veyraSubspeciesInputEl) { veyraSubspeciesInputEl.addEventListener('input', veyraUpdateSubspeciesVisibility); }
            veyraUpdateSubspeciesVisibility();

            // Snap-height paste boxes (cached wayback content, freshly-invented content, ...):
            // each ".veyra-wayback-heightbar" button bar snaps the height of its own
            // sibling textarea within the same ".veyra-wayback-box" container. Handles
            // any number of these boxes on the screen, not just a single hardcoded pair.
            document.querySelectorAll('.veyra-wayback-heightbar').forEach(function(heightbar){
                var box = heightbar.closest('.veyra-wayback-box');
                var textarea = box ? box.querySelector('.veyra-wayback-textarea') : null;
                if (!textarea) { return; }
                heightbar.addEventListener('click', function(e){
                    var btn = e.target.closest ? e.target.closest('.veyra-wayback-height-btn') : null;
                    if (!btn) { return; }
                    e.preventDefault();
                    var h = btn.getAttribute('data-h');
                    if (h === 'full') {
                        textarea.style.height = 'auto';
                        textarea.style.height = textarea.scrollHeight + 'px';
                    } else {
                        textarea.style.height = h + 'px';
                    }
                    heightbar.querySelectorAll('.veyra-wayback-height-btn').forEach(function(b){
                        b.classList.toggle('veyra-wayback-height-btn--active', b === btn);
                    });
                });
            });

            var bar = document.getElementById('veyra-sm-bar');
            if (!bar) { return; }
            bar.addEventListener('click', function(e){
                var t = e.target;
                if (!t.classList) { return; }
                if (t.classList.contains('veyra-sm-open') || t.classList.contains('veyra-sm-wayback')) {
                    e.preventDefault();
                    var rowEl = t.closest('.veyra-sm-linkrow');
                    var inp = rowEl ? rowEl.querySelector('input') : null;
                    var url = inp ? inp.value.trim() : '';
                    if (!url) { return; }
                    if (t.classList.contains('veyra-sm-wayback')) {
                        window.open('https://web.archive.org/web/*/' + url, '_blank');
                    } else {
                        window.open(url, '_blank');
                    }
                } else if (t.classList.contains('veyra-sm-openfile') || t.classList.contains('veyra-sm-copyfile')) {
                    e.preventDefault();
                    var rowEl2 = t.closest('.veyra-sm-linkrow');
                    var inp2 = rowEl2 ? rowEl2.querySelector('input') : null;
                    var rel = inp2 ? inp2.value.trim() : '';
                    if (!rel) { return; }
                    var prefix = t.getAttribute('data-prefix') || '';
                    // prefix = file://<base>/<domain> ; rel = /legal.html (path may have spaces)
                    var fileUrl = prefix + '/' + encodeURI(rel.replace(/^\/+/, ''));
                    if (t.classList.contains('veyra-sm-openfile')) {
                        window.open(fileUrl, '_blank');
                    } else {
                        // Copy to clipboard. navigator.clipboard needs a secure context
                        // (lagoon.local is plain http), so use the textarea fallback.
                        var ta = document.createElement('textarea');
                        ta.value = fileUrl;
                        ta.style.position = 'fixed'; ta.style.opacity = '0';
                        document.body.appendChild(ta); ta.focus(); ta.select();
                        var ok = false;
                        try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
                        document.body.removeChild(ta);
                        var orig = t.textContent;
                        t.textContent = ok ? 'copied!' : 'copy failed';
                        setTimeout(function(){ t.textContent = orig; }, 1500);
                    }
                }
            });
            var saveBtn = document.getElementById('veyra-sm-save');
            var statusEl = document.getElementById('veyra-sm-save-status');
            if (saveBtn && window.veyraSm) {
                saveBtn.addEventListener('click', function(e){
                    e.preventDefault(); e.stopPropagation();
                    var sm = {};
                    bar.querySelectorAll('input[name^="sm["]').forEach(function(inp){
                        var m = inp.name.match(/^sm\[([^\]]+)\]\[([^\]]+)\]\[([^\]]+)\]$/);
                        if (!m) { return; }
                        sm[m[1]] = sm[m[1]] || {};
                        sm[m[1]][m[2]] = sm[m[1]][m[2]] || {};
                        sm[m[1]][m[2]][m[3]] = inp.value;
                    });
                    if (statusEl) { statusEl.textContent = 'saving\u2026'; }
                    fetch(window.veyraSm.url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.veyraSm.nonce },
                        body: JSON.stringify({ wp_post_id: window.veyraSm.postId, sm: sm })
                    }).then(function(r){ return r.json(); })
                      .then(function(d){ if (statusEl) { statusEl.textContent = (d && d.ok) ? ('saved (' + d.rows_updated + ' rows updated)') : 'save failed'; } })
                      .catch(function(){ if (statusEl) { statusEl.textContent = 'save error'; } });
                });
            }
        })();
        </script>
        <?php
    }
}

// Start the plugin
$veyra = new Veyra();

// Create sm_* tables on activation (init-time check also covers code updates).
register_activation_hook(__FILE__, array($veyra, 'veyra_sm_create_tables'));

// ---------------------------------------------------------------------------
// Admin screens — each under /admin-screens/<folder>/ self-registers its menu
// and render callback. Keeps page-specific code out of this main file.
// ---------------------------------------------------------------------------
require_once VEYRA_PLUGIN_PATH . 'admin-screens/post_importer_from_birch/post-importer-from-birch.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/veyra_plugin_manager/veyra-plugin-manager.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/sm-redirect-manager/sm-redirect-manager.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/custom_blog_feed/custom-blog-feed.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/page-change-drip-manager/page-change-drip-manager.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/veyra-site-title-and-footer-mar/veyra-site-title-and-footer-mar.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/veyra-change-wp-user-and-pass/veyra-change-wp-user-and-pass.php';