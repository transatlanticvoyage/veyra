<?php
/**
 * Plugin Name: Veyra
 * Plugin URI: https://github.com/transatlanticvoyage/veyra
 * Description: Veyra WordPress plugin.
 * Version: 1.2.2
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
define('VEYRA_PLUGIN_VERSION', '1.2.2');
define('VEYRA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('VEYRA_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Table-name accessors for the post-editor sidebar boxes.
 *
 * Defined up here (rather than as class methods) so the feature code under
 * /editor-boxes/ can reach them without depending on the $veyra instance
 * existing yet. The schema itself is defined in
 * Veyra::veyra_editor_boxes_create_tables() further down this file.
 */
function veyra_pda_table() {
    global $wpdb;
    return $wpdb->prefix . 'veyra_post_drip_actions';
}
function veyra_pah_table() {
    global $wpdb;
    return $wpdb->prefix . 'veyra_post_status_log';
}

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

        // Post-editor sidebar boxes (veyra_post_drip_actions / veyra_post_status_log).
        // Same deal as the sm_* tables: created on plugin activation AND checked
        // on init, so a deactivate/reactivate on a live site builds them, and a
        // plain code upload picks up schema changes without reactivating.
        add_action('init', array($this, 'veyra_editor_boxes_maybe_upgrade_db'), 4);

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

        // Postmeta-backed phase-in versions of the same 4 wayback fields (per-post rows,
        // not a shared wp_option array — see veyra_postmeta_field_definitions()). Rendered
        // by the same veyra_wayback_render_editor_box() call above; saved on the same native
        // save_post hook, no separate save button.
        add_action('save_post', array($this, 'veyra_postmeta_save_editor'), 10, 2);

        add_action('admin_print_styles-post.php', array($this, 'veyra_sm_editor_styles'));
        add_action('admin_print_styles-post-new.php', array($this, 'veyra_sm_editor_styles'));
        add_action('admin_print_footer_scripts', array($this, 'veyra_sm_editor_js'));

        // "should_remain_rolled_off" column on the native Posts list screen (edit.php).
        // Adds a column to the LEFT of the checkbox with a toggle switch. State is
        // backed by a single wp_option ('should_remain_rolled_off_of_homepage')
        // keyed by post ID (value 1 = TRUE), so each post owns exactly one entry;
        // absence of a key means FALSE. Mirrors the veyra_content_species pattern.
        add_filter('manage_post_posts_columns', array($this, 'veyra_srro_add_column'));
        add_action('manage_post_posts_custom_column', array($this, 'veyra_srro_render_column'), 10, 2);
        add_action('wp_ajax_veyra_toggle_srro', array($this, 'ajax_toggle_srro'));
        add_action('admin_footer-edit.php', array($this, 'veyra_flag_toggle_footer_assets'));

        // "should_remain_on_homepage" column, immediately to the RIGHT of the
        // should_remain_rolled_off column. Same storage shape as srro, its own
        // wp_option ('should_remain_on_homepage'). Registered AFTER the srro filter
        // above so it runs second and can splice itself in after that key — srro
        // prepends, this one inserts, so the pair always stays adjacent.
        add_filter('manage_post_posts_columns', array($this, 'veyra_sroh_add_column'));
        add_action('manage_post_posts_custom_column', array($this, 'veyra_sroh_render_column'), 10, 2);
        add_action('wp_ajax_veyra_toggle_sroh', array($this, 'ajax_toggle_sroh'));

        // Auto-calibration of posts_per_page around the two should_remain_* flags.
        // ON by default with no install step: the option row is only ever written
        // when the user switches it OFF, and a missing row reads as ON. Hooked
        // outside any is_admin() guard so wp-cron publishing a scheduled post
        // calibrates too.
        add_action('wp_ajax_veyra_toggle_autocal', array($this, 'ajax_toggle_autocal'));
        add_action('transition_post_status', array($this, 'veyra_autocal_on_transition'), 10, 3);
        // Permanent deletion bypasses transition_post_status entirely, so it needs
        // its own entry point. Fires while the row still exists.
        add_action('before_delete_post', array($this, 'veyra_autocal_on_delete'), 10, 2);
        // "f800 - rescan" button beside the auto-calibrate switch: a one-shot full
        // check that every should_remain_on_homepage post is on feed page 1, fixing
        // any that aren't by back-dating competitors and/or raising posts_per_page.
        add_action('wp_ajax_veyra_autocal_rescan', array($this, 'ajax_autocal_rescan'));
        // "f900 - clean now": stricter sibling of f800 — page 1 ends up holding ONLY
        // should_remain_on_homepage posts, and posts_per_page is lowered to fit.
        add_action('wp_ajax_veyra_autocal_clean', array($this, 'ajax_autocal_clean'));

        // "Blog pages show at most" (posts_per_page) controller placed on edit.php
        // next to the Add Post button, plus a dynamic "ROLLED-OFF POSTS BELOW"
        // separator row inserted into the list after the Nth published row.
        add_action('wp_ajax_veyra_save_posts_per_page', array($this, 'ajax_save_posts_per_page'));
        add_action('admin_footer-edit.php', array($this, 'veyra_reading_controls_footer_assets'));

        // Drafts float to the top of the Posts list (default sort only) under their
        // own "DRAFTS" separator row, drawn by veyra_reading_controls_footer_assets().
        add_filter('posts_orderby', array($this, 'veyra_drafts_first_orderby'), 10, 2);

        // "Date Switch" row action (after Preview) on the Posts list: hover reveals
        // a panel of random-date buttons in Fast / Medium / Slow interval sets.
        add_filter('post_row_actions', array($this, 'veyra_ds_row_action'), 10, 2);
        add_action('wp_ajax_veyra_date_switch', array($this, 'ajax_date_switch'));
        add_action('admin_footer-edit.php', array($this, 'veyra_ds_footer_assets'));

        // "boldcrypted" column (rightmost) on the Posts list + "f400 - boldcrypt scan"
        // button. The scan stores each post_title with <b class="niche|city|state">
        // around recognised terms in post meta '_veyra_boldcrypted'. Source lists
        // live in data/boldcrypt/. Priority 99 so it's appended after every other
        // column, Date included.
        add_filter('manage_post_posts_columns', array($this, 'veyra_bc_add_column'), 99);
        add_action('manage_post_posts_custom_column', array($this, 'veyra_bc_render_column'), 10, 2);
        add_action('wp_ajax_veyra_bc_scan_batch', array($this, 'ajax_bc_scan_batch'));
        add_action('admin_footer-edit.php', array($this, 'veyra_bc_footer_assets'));

        // rocket_chamber above the Posts list: row pagination + column pagination
        // button bars, ported in look from tregnar's /sitejar rocket chamber.
        add_action('wp_ajax_veyra_rocket_rows_per_page', array($this, 'ajax_rocket_rows_per_page'));
        add_action('admin_footer-edit.php', array($this, 'veyra_rocket_footer_assets'));

        // "link_synopsis" column on the native Posts list screen (edit.php).
        // Leftmost column: a compact per-post table of the OUTBOUND links found in
        // that post's post_content. Backed by post meta ('_veyra_link_synopsis')
        // holding a JSON array — exactly one meta row per post, so it is removed
        // automatically when the post is deleted and is primed in a single query by
        // the list table's own meta cache (no per-row lookups).
        //
        // Priority 20 (not the default 10) so this filter always runs AFTER
        // veyra_srro_add_column. Both callbacks PREPEND their column, so whichever
        // runs last ends up furthest left; the explicit priority makes that ordering
        // deterministic instead of depending on the order these lines appear here.
        add_filter('manage_post_posts_columns', array($this, 'veyra_lsyn_add_column'), 20);
        add_action('manage_post_posts_custom_column', array($this, 'veyra_lsyn_render_column'), 10, 2);
        add_action('wp_ajax_veyra_lsyn_start', array($this, 'ajax_lsyn_start'));
        add_action('wp_ajax_veyra_lsyn_scan_batch', array($this, 'ajax_lsyn_scan_batch'));
        add_action('wp_ajax_veyra_lsyn_finish', array($this, 'ajax_lsyn_finish'));
        // MS (money site) toggle on each link_synopsis row. Site-wide, keyed by the
        // link's registrable domain — see VEYRA_MONEY_SITES_OPTION.
        add_action('wp_ajax_veyra_toggle_money_site', array($this, 'ajax_toggle_money_site'));
        add_action('admin_footer-edit.php', array($this, 'veyra_lsyn_footer_assets'));
        add_filter('default_hidden_columns', array($this, 'veyra_lsyn_default_hidden_columns'), 10, 2);
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

        // Sitemap-style link list; independent of Elementor fallback mode.
        add_shortcode('veyra_sitemap_render_all_published_pages', array($this, 'veyra_sitemap_render_all_published_pages_shortcode'));
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
     * Shortcode that renders a linebreak-separated list of links to every
     * published page on the site (post_type 'page' only — posts are excluded),
     * with the homepage listed first under the anchor text "Home".
     *
     * Usage: [veyra_sitemap_render_all_published_pages]
     */
    public function veyra_sitemap_render_all_published_pages_shortcode($atts) {
        $links = array();

        // Homepage always comes first, regardless of whether the front page
        // is a static WP page or the native posts/blog feed.
        $links[] = '<a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', 'veyra') . '</a>';

        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));

        foreach ($pages as $page) {
            // Raw post_title, not get_the_title() — bypasses the 'the_title' filter
            // chain (themes/plugins can hook it to shorten titles for card/SEO
            // display), so the sitemap always links with the full, untruncated title.
            $links[] = '<a href="' . esc_url(get_permalink($page->ID)) . '">' . esc_html($page->post_title) . '</a>';
        }

        return implode('<br>' . "\n", $links);
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
            'dashicons-networking',
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

        $cached_fossil_content = isset($_POST['veyra_cached_fossil_content']) ? veyra_clean_fossil_content(wp_kses_post(wp_unslash($_POST['veyra_cached_fossil_content']))) : '';
        update_option('veyra_cached_fossil_content', $cached_fossil_content);

        $cached_fossil_content_below_feed = isset($_POST['veyra_cached_fossil_content_below_feed']) ? veyra_clean_fossil_content(wp_kses_post(wp_unslash($_POST['veyra_cached_fossil_content_below_feed']))) : '';
        update_option('veyra_cached_fossil_content_below_feed', $cached_fossil_content_below_feed);

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
        $cached_fossil_content = get_option('veyra_cached_fossil_content', '');
        $cached_fossil_content_below_feed = get_option('veyra_cached_fossil_content_below_feed', '');
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

            <h2 class="nav-tab-wrapper" id="veyra-hub-tabs">
                <a href="#" class="nav-tab nav-tab-active" data-veyra-tab="main-controls">Main Controls</a>
                <a href="#" class="nav-tab" data-veyra-tab="historical-cached">Historical Cached
                    <span class="veyra-tab-badge<?php echo ($cached_fossil_content !== '') ? ' veyra-tab-badge--active' : ''; ?>">content-above</span>
                    <span class="veyra-tab-badge<?php echo ($cached_fossil_content_below_feed !== '') ? ' veyra-tab-badge--active' : ''; ?>">content-below</span>
                </a>
            </h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="veyra_save_options" />
                <?php wp_nonce_field('veyra_save_options', 'veyra_options_nonce'); ?>

                <?php submit_button('Save Settings', 'primary', 'submit', false, array('style' => 'margin:16px 0;')); ?>

                <div class="veyra-tab-panel" data-veyra-panel="main-controls">

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

                </div><!-- /.veyra-tab-panel[main-controls] -->

                <div class="veyra-tab-panel" data-veyra-panel="historical-cached" style="display:none;">

                <table class="form-table">
                    <tr>
                        <th scope="row">veyra_cached_fossil_content</th>
                        <td>
                            <textarea name="veyra_cached_fossil_content" rows="12" cols="80" class="large-text code"><?php echo esc_textarea($cached_fossil_content); ?></textarea>
                            <p class="description">Backup storage only &mdash; this field has no frontend implementation on the site. Use it to stash content you want kept somewhere safe.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">veyra_cached_fossil_content_below_feed</th>
                        <td>
                            <textarea name="veyra_cached_fossil_content_below_feed" rows="12" cols="80" class="large-text code"><?php echo esc_textarea($cached_fossil_content_below_feed); ?></textarea>
                            <p class="description">Backup storage only &mdash; this field has no frontend implementation on the site. Use it to stash content you want kept somewhere safe.</p>
                        </td>
                    </tr>
                </table>

                </div><!-- /.veyra-tab-panel[historical-cached] -->

                <?php submit_button('Save Settings'); ?>
            </form>
        </div>

        <style>
            .veyra-tab-badge{display:inline-block;margin-left:6px;padding:1px 8px;font-size:11px;font-weight:600;line-height:1.6;border-radius:10px;background:#dcdcde;color:#8c8f94;vertical-align:middle;}
            .veyra-tab-badge--active{background:#2271b1;color:#fff;}
        </style>
        <script>
        (function(){
            var tabs = document.querySelectorAll('#veyra-hub-tabs .nav-tab');
            var panels = document.querySelectorAll('.veyra-tab-panel');
            var STORAGE_KEY = 'veyra_hub_active_tab';
            function activate(tabKey) {
                tabs.forEach(function(t){ t.classList.toggle('nav-tab-active', t.getAttribute('data-veyra-tab') === tabKey); });
                panels.forEach(function(p){ p.style.display = (p.getAttribute('data-veyra-panel') === tabKey) ? '' : 'none'; });
            }
            tabs.forEach(function(t){
                t.addEventListener('click', function(e){
                    e.preventDefault();
                    var key = t.getAttribute('data-veyra-tab');
                    activate(key);
                    try { localStorage.setItem(STORAGE_KEY, key); } catch (err) {}
                });
            });
            var saved = null;
            try { saved = localStorage.getItem(STORAGE_KEY); } catch (err) {}
            if (saved && document.querySelector('#veyra-hub-tabs [data-veyra-tab="' + saved + '"]')) {
                activate(saved);
            }
        })();
        </script>
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
            // "Birch Post Importer" shortcut: blue background, white text. Two IDs
            // in the selector outrank core's admin-bar and color-scheme rules, so
            // no !important is needed.
            . '#wpadminbar #wp-admin-bar-veyra-birch-post-importer > .ab-item{'
            . 'background:#2271b1;color:#fff;font-weight:600;}'
            . '#wpadminbar #wp-admin-bar-veyra-birch-post-importer:hover > .ab-item,'
            . '#wpadminbar #wp-admin-bar-veyra-birch-post-importer.hover > .ab-item,'
            . '#wpadminbar #wp-admin-bar-veyra-birch-post-importer > .ab-item:focus{'
            . 'background:#135e96;color:#fff;}'
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
            array('Plugin Updater',                 'veyra_plugin_manager',            'manage_options'),
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

        // Standalone top-level shortcut straight to the Birch post importer, so
        // it takes one click instead of opening the Veyra Hub 1 dropdown first.
        // Styled blue/white in veyra_admin_bar_styles(). The edit_posts check at
        // the top of this method already covers the importer screen's own cap.
        $wp_admin_bar->add_node(array(
            'id'    => 'veyra-birch-post-importer',
            'title' => 'Birch Post Importer',
            'href'  => admin_url('admin.php?page=veyra_post_importer'),
            'meta'  => array(
                'title' => 'Open the Veyra Post Importer From Birch screen',
            ),
        ));
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

    // =====================================================================
    // Post-editor sidebar boxes — DB schema
    //
    // Backs the two boxes at the bottom of the post/page editor sidebar:
    //   - "Veyra Post Drip Actions"   -> veyra_post_drip_actions (the queue)
    //   - "Veyra Post Actions History" -> veyra_post_status_log  (the audit log)
    //
    // The behaviour for both lives in /editor-boxes/<folder>/, but the schema is
    // defined here so it is created by the SAME activation hook as the sm_*
    // tables. Practical effect on a live site: upload the plugin changes, then
    // deactivate + reactivate Veyra and both tables appear. The init-time
    // version check below is the belt-and-braces path for when the plugin is
    // updated in place without ever being reactivated.
    // =====================================================================

    /** Bump this when the DDL in veyra_editor_boxes_create_tables() changes.
     *  v2: post_date before/after tracking on the status log.
     *  v3: event_target_gmt, so drip-queue events snapshot what they aim at. */
    const VEYRA_EDITOR_BOXES_DB_VERSION = '3';

    /** Map logical table key => full table name (with wpdb prefix). */
    public function veyra_editor_boxes_tables() {
        return array(
            'drip_actions' => veyra_pda_table(),
            'status_log'   => veyra_pah_table(),
        );
    }

    /**
     * Create or upgrade the editor-box tables when the version changes.
     *
     * The stored version is only advanced once the resulting schema has been
     * VERIFIED to contain every expected column. Without that check a dbDelta
     * that silently no-ops would still mark the upgrade as done, and the code
     * would then spend the rest of its life writing to columns that do not
     * exist — failing on every insert with no obvious cause. Leaving the option
     * untouched instead means the upgrade is simply retried on the next request.
     */
    public function veyra_editor_boxes_maybe_upgrade_db() {
        if (get_option('veyra_editor_boxes_db_version') === self::VEYRA_EDITOR_BOXES_DB_VERSION) {
            return;
        }
        $this->veyra_editor_boxes_create_tables();

        $missing = $this->veyra_editor_boxes_missing_columns();
        if ($missing) {
            error_log('Veyra: editor-box DB upgrade incomplete, missing columns: '
                . implode(', ', $missing) . ' — will retry on the next request.');
            return;
        }

        update_option('veyra_editor_boxes_db_version', self::VEYRA_EDITOR_BOXES_DB_VERSION, false);
    }

    /**
     * Columns the current code writes to, that are not actually present in the
     * database. Empty array means the schema is good. Used as the gate above and
     * available for diagnostics.
     */
    public function veyra_editor_boxes_missing_columns() {
        global $wpdb;

        $expected = array(
            veyra_pda_table() => array(
                'id', 'post_id', 'action_type', 'action_value', 'scheduled_for_gmt',
                'scheduled_for_local', 'status', 'executed_at_gmt', 'status_before',
                'status_after', 'result_note', 'log_id', 'notes', 'created_at_gmt',
                'created_by', 'canceled_at_gmt', 'canceled_by',
            ),
            veyra_pah_table() => array(
                'id', 'post_id', 'post_type', 'event_type', 'old_status', 'new_status',
                'changed_at_gmt', 'post_date_before', 'post_date_after',
                'post_date_gmt_before', 'post_date_gmt_after', 'user_id', 'user_login',
                'source', 'source_detail', 'drip_action_id', 'event_target_gmt',
            ),
        );

        $missing = array();
        foreach ($expected as $table => $columns) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                $missing[] = $table . ' (table absent)';
                continue;
            }
            $actual = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
            foreach (array_diff($columns, (array) $actual) as $col) {
                $missing[] = $table . '.' . $col;
            }
        }
        return $missing;
    }

    /**
     * Run dbDelta for both editor-box tables. Safe to call repeatedly.
     *
     * Also fired directly by register_activation_hook(), which is why it does
     * not consult the version option itself — reactivating the plugin should
     * always re-assert the schema, even if the stored version already matches.
     */
    public function veyra_editor_boxes_create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t       = $this->veyra_editor_boxes_tables();
        $charset = $wpdb->get_charset_collate();

        // Box 1 — one row per SCHEDULED action, so a post can hold several
        // queued actions at once, each independently cancelable. Rows are never
        // deleted: done/canceled/failed rows are the audit trail of the queue.
        // The `due` index is what the WP-Cron sweep reads.
        dbDelta("CREATE TABLE {$t['drip_actions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  action_type varchar(64) NOT NULL DEFAULT 'set_status',
  action_value varchar(191) NOT NULL DEFAULT '',
  scheduled_for_gmt datetime NOT NULL,
  scheduled_for_local datetime NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  executed_at_gmt datetime NULL,
  status_before varchar(20) NULL,
  status_after varchar(20) NULL,
  result_note text,
  log_id bigint(20) unsigned NULL,
  notes text,
  created_at_gmt datetime NOT NULL,
  created_by bigint(20) unsigned NULL,
  canceled_at_gmt datetime NULL,
  canceled_by bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  KEY due (status, scheduled_for_gmt),
  KEY post_status (post_id, status)
) {$charset};");

        // Box 2 — one row per post event, written by transition_post_status
        // (status changes) and post_updated (date changes, plus the before/after
        // dates on a status change) no matter what caused it. Completely
        // independent of the drip queue above; drip-caused changes just land
        // here with source='veyra_drip' and drip_action_id set.
        //
        // Why post_date is stored twice: WP leaves post_date_gmt as
        // '0000-00-00 00:00:00' on drafts (a "floating" date) while post_date
        // always carries a real value. Detection and display therefore run off
        // the local post_date pair; the _gmt pair is the unambiguous canonical
        // record, and is NULL whenever the date was floating.
        //
        // event_target_gmt holds the future time a drip-queue event points at
        // (a copy of that action's scheduled_for_gmt), frozen at write time.
        // Denormalized deliberately, for the same reason user_login is: an
        // audit row must keep saying what was true when it was written, even
        // after the drip row it references moves on to canceled/done. The live
        // state of that action is fetched separately, via a LEFT JOIN on
        // drip_action_id, and rendered as clearly-marked present-tense context.
        // NULL on every event that is not a drip-queue event.
        dbDelta("CREATE TABLE {$t['status_log']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  post_type varchar(20) NOT NULL DEFAULT '',
  event_type varchar(32) NOT NULL DEFAULT 'status_change',
  old_status varchar(20) NOT NULL DEFAULT '',
  new_status varchar(20) NOT NULL DEFAULT '',
  changed_at_gmt datetime NOT NULL,
  post_date_before datetime NULL,
  post_date_after datetime NULL,
  post_date_gmt_before datetime NULL,
  post_date_gmt_after datetime NULL,
  user_id bigint(20) unsigned NULL,
  user_login varchar(60) NOT NULL DEFAULT '',
  source varchar(32) NOT NULL DEFAULT '',
  source_detail varchar(191) NOT NULL DEFAULT '',
  drip_action_id bigint(20) unsigned NULL,
  event_target_gmt datetime NULL,
  PRIMARY KEY  (id),
  KEY post_time (post_id, changed_at_gmt),
  KEY changed (changed_at_gmt)
) {$charset};");

        $this->veyra_editor_boxes_migrate_v2();
    }

    /**
     * v1 -> v2: fold the old single post_date_gmt column into the new
     * before/after pair, then drop it.
     *
     * Lives here (rather than in the version-guarded upgrade path) so that the
     * activation hook — which calls create_tables directly — performs it too.
     * Guarded on the legacy column still existing, so it is a no-op every time
     * after the first.
     */
    private function veyra_editor_boxes_migrate_v2() {
        global $wpdb;
        $table = veyra_pah_table();

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!is_array($columns) || !in_array('post_date_gmt', $columns, true)) {
            return;
        }

        // The legacy column held the post's date as of the event, i.e. "after".
        $wpdb->query("UPDATE {$table}
                         SET post_date_gmt_after = post_date_gmt
                       WHERE post_date_gmt_after IS NULL
                         AND post_date_gmt IS NOT NULL");
        $wpdb->query("ALTER TABLE {$table} DROP COLUMN post_date_gmt");
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

    /* ---------------------------------------------------------------------
     * should_remain_rolled_off + should_remain_on_homepage columns
     * (Posts list screen)
     *
     * Two independent per-post booleans stating the OPPOSITE intents:
     *   should_remain_rolled_off_of_homepage — must never appear on feed page 1
     *   should_remain_on_homepage            — must never fall off feed page 1
     *
     * Each is backed by its own single wp_option holding a { post_id => 1 } map.
     * Absence of a key means FALSE, so neither option row has to exist for the
     * feature to work — nothing to install, nothing to migrate.
     * ------------------------------------------------------------------- */

    const VEYRA_SRRO_OPTION = 'should_remain_rolled_off_of_homepage';
    const VEYRA_SROH_OPTION = 'should_remain_on_homepage';

    /** Read one of the flag maps, always as an array. */
    private function veyra_flag_get_map($option) {
        $map = get_option($option, array());
        return is_array($map) ? $map : array();
    }

    /** Whether a given post is flagged TRUE in the given map. */
    private function veyra_flag_is_on($option, $post_id) {
        $map = $this->veyra_flag_get_map($option);
        return !empty($map[(int) $post_id]);
    }

    private function veyra_srro_is_on($post_id) {
        return $this->veyra_flag_is_on(self::VEYRA_SRRO_OPTION, $post_id);
    }

    private function veyra_sroh_is_on($post_id) {
        return $this->veyra_flag_is_on(self::VEYRA_SROH_OPTION, $post_id);
    }

    /** Add the srro column to the LEFT of the checkbox (cb) on the Posts list screen. */
    public function veyra_srro_add_column($columns) {
        $new = array();
        // Prepend our column so it renders before the checkbox column.
        $new['veyra_srro'] = '<span class="veyra-flag-th">should_remain<br>_rolled_off</span>';
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
        }
        return $new;
    }

    /** Add the sroh column immediately AFTER the srro column. */
    public function veyra_sroh_add_column($columns) {
        $label = '<span class="veyra-flag-th">should_remain<br>_on_homepage</span>';
        if (!isset($columns['veyra_srro'])) {
            // srro isn't in the list (filter order changed, or it was removed) —
            // fall back to prepending so the column never silently disappears.
            return array_merge(array('veyra_sroh' => $label), $columns);
        }
        $new = array();
        foreach ($columns as $key => $val) {
            $new[$key] = $val;
            if ($key === 'veyra_srro') {
                $new['veyra_sroh'] = $label;
            }
        }
        return $new;
    }

    /**
     * Render one flag toggle.
     *
     * The AJAX action rides on the button as a data attribute so a single delegated
     * click handler drives every flag column — see veyra_flag_toggle_footer_assets().
     */
    private function veyra_flag_render_toggle($modifier_class, $option, $ajax_action, $post_id) {
        $on = $this->veyra_flag_is_on($option, $post_id);
        printf(
            '<button type="button" class="veyra-flag-toggle %1$s%2$s" data-post="%3$d" data-action="%4$s" data-label="%5$s" role="switch" aria-checked="%6$s" title="%5$s: %7$s"><span class="veyra-flag-knob"></span></button>',
            esc_attr($modifier_class),
            $on ? ' is-on' : '',
            (int) $post_id,
            esc_attr($ajax_action),
            esc_attr($option),
            $on ? 'true' : 'false',
            $on ? 'TRUE' : 'FALSE'
        );
    }

    public function veyra_srro_render_column($column, $post_id) {
        if ($column !== 'veyra_srro') {
            return;
        }
        $this->veyra_flag_render_toggle('veyra-srro-toggle', self::VEYRA_SRRO_OPTION, 'veyra_toggle_srro', $post_id);
    }

    public function veyra_sroh_render_column($column, $post_id) {
        if ($column !== 'veyra_sroh') {
            return;
        }
        $this->veyra_flag_render_toggle('veyra-sroh-toggle', self::VEYRA_SROH_OPTION, 'veyra_toggle_sroh', $post_id);
    }

    /** AJAX: flip the boolean for a post and persist the single option. */
    private function veyra_flag_ajax_toggle($option) {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error('Invalid post id');
        }
        $map = $this->veyra_flag_get_map($option);
        $new_on = empty($map[$post_id]); // toggle
        if ($new_on) {
            $map[$post_id] = 1;
        } else {
            unset($map[$post_id]);
        }
        update_option($option, $map);
        wp_send_json_success(array('post_id' => $post_id, 'on' => $new_on ? 1 : 0));
    }

    public function ajax_toggle_srro() {
        $this->veyra_flag_ajax_toggle(self::VEYRA_SRRO_OPTION);
    }

    public function ajax_toggle_sroh() {
        $this->veyra_flag_ajax_toggle(self::VEYRA_SROH_OPTION);
    }

    /** Inline CSS + JS for both flag toggles, only on the Posts list screen. */
    public function veyra_flag_toggle_footer_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'post') {
            return;
        }
        $nonce = wp_create_nonce('veyra_elephant_tools');
        ?>
        <style>
            .column-veyra_srro,
            .column-veyra_sroh { width: 90px; text-align: center; }
            .veyra-flag-th { display: inline-block; line-height: 1.2; font-weight: 600; }
            /* One switch widget for every flag column (and for the auto-calibrate
               switch in the reading controller, which prints on this same screen).
               Only the "on" colour differs per flag. */
            .veyra-flag-toggle {
                position: relative; width: 34px; height: 18px; border-radius: 9px;
                border: none; padding: 0; cursor: pointer; background-color: #cbd5e1;
                transition: background-color 0.2s; vertical-align: middle;
            }
            /* Red = "keep this one OFF the homepage", blue = "keep this one ON it". */
            .veyra-srro-toggle.is-on { background-color: #dc2626; }
            .veyra-sroh-toggle.is-on,
            .veyra-autocal-toggle.is-on { background-color: #2563eb; }
            .veyra-flag-toggle.is-busy { opacity: 0.5; cursor: wait; }
            .veyra-flag-knob {
                position: absolute; top: 2px; left: 2px; width: 14px; height: 14px;
                border-radius: 50%; background-color: #fff; transition: left 0.2s;
                box-shadow: 0 1px 2px rgba(0,0,0,0.3);
            }
            .veyra-flag-toggle.is-on .veyra-flag-knob { left: 18px; }
        </style>
        <script>
        (function(){
            var AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var NONCE = <?php echo wp_json_encode($nonce); ?>;
            // [data-post] deliberately narrows this to the per-row flag switches.
            // The auto-calibrate switch shares the .veyra-flag-toggle look but carries
            // no post id and is driven by its own handler in the reading controller.
            document.addEventListener('click', function(e){
                var btn = e.target.closest ? e.target.closest('.veyra-flag-toggle[data-post]') : null;
                if (!btn) return;
                e.preventDefault();
                if (btn.classList.contains('is-busy')) return;
                btn.classList.add('is-busy');
                var body = new URLSearchParams();
                body.append('action', btn.getAttribute('data-action'));
                body.append('nonce', NONCE);
                body.append('post_id', btn.getAttribute('data-post'));
                fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res && res.success) {
                            var on = !!res.data.on;
                            btn.classList.toggle('is-on', on);
                            btn.setAttribute('aria-checked', on ? 'true' : 'false');
                            btn.setAttribute('title', btn.getAttribute('data-label') + ': ' + (on ? 'TRUE' : 'FALSE'));
                        } else {
                            alert('Failed to toggle: ' + (res && res.data ? res.data : 'unknown error'));
                        }
                    })
                    .catch(function(err){ alert('Toggle request failed: ' + err.message); })
                    .finally(function(){ btn.classList.remove('is-busy'); });
            });
        })();
        </script>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Auto-calibrate posts_per_page for the should_remain_* flags
     *
     * Keeps the native "Blog pages show at most N posts" setting in sync with the
     * two flags above so the user never has to reason about N by hand:
     *
     *   • a post leaves the feed (publish -> draft/trash/future/anything else) and
     *     the post it pulls up into the last page-1 slot is flagged
     *     should_remain_rolled_off  ->  N = N - 1, so that post stays off page 1.
     *
     *   • a post enters the feed (draft/future/anything else -> publish, whether by
     *     hand or by wp-cron) and the post it pushes off the bottom of page 1 is
     *     flagged should_remain_on_homepage  ->  N = N + 1, so that post stays on.
     *
     * Each transition shifts the feed by exactly one slot, so each transition can
     * move N by at most one. Nothing is recomputed in bulk and nothing is written
     * unless a flagged post is actually about to cross the page-1 boundary.
     * ------------------------------------------------------------------- */

    /** Set to '0' only when the user switches the feature off; missing = ON. */
    const VEYRA_AUTOCAL_OPTION = 'veyra_auto_calibrate_for_should_remain_posts';

    /**
     * ON unless explicitly switched off.
     *
     * The default lives here in code rather than in a row written at activation,
     * which is what lets a plain file update switch the feature on for every
     * existing site — no schema run, no deactivate/reactivate.
     */
    private function veyra_autocal_is_enabled() {
        return get_option(self::VEYRA_AUTOCAL_OPTION, '1') !== '0';
    }

    /** AJAX: turn auto-calibration on/off from the reading controller. */
    public function ajax_toggle_autocal() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $on = isset($_POST['on']) && $_POST['on'] === '1';
        update_option(self::VEYRA_AUTOCAL_OPTION, $on ? '1' : '0');
        wp_send_json_success(array('on' => $on ? 1 : 0));
    }

    /**
     * The post IDs rendered on feed page 1, in display order, for a given feed state.
     *
     * Mirrors WP_Query for the posts page (class-wp-query.php, "Put sticky posts at
     * the top of the posts array"): the first $per_page published posts by
     * post_date DESC, then core's sticky hoist. Published stickies INSIDE that
     * window are relocated to the front; published stickies OUTSIDE it are spliced
     * in on top without displacing anything. That second half is why a site using
     * stickies renders more than $per_page rows on page 1, and why this can return
     * more than $per_page IDs. Core applies the hoist to page 1 only, which is the
     * only page this calculation ever asks about.
     *
     * Core adds no tiebreaker to its ORDER BY, so posts sharing a post_date to the
     * second come back in whatever order MySQL likes; ID DESC is appended here
     * purely to make this calculation deterministic.
     *
     * The two hypothetical parameters let a caller ask what page 1 looked like on
     * the other side of the single post that just changed:
     *
     * @param int          $per_page
     * @param int          $exclude_id Treat this post as not published / not there.
     * @param WP_Post|null $extra      Treat this post as published.
     * @return int[]
     */
    private function veyra_feed_page_one_ids($per_page, $exclude_id = 0, $extra = null) {
        global $wpdb;
        $per_page   = max(1, (int) $per_page);
        $exclude_id = (int) $exclude_id;

        $where = "post_type = 'post' AND post_status = 'publish'";
        $args  = array();
        if ($exclude_id) {
            $where  .= ' AND ID <> %d';
            $args[]  = $exclude_id;
        }
        $args[] = $per_page;
        $window = $this->veyra_feed_rows($wpdb->prepare(
            "SELECT ID, post_date FROM {$wpdb->posts}
              WHERE {$where}
              ORDER BY post_date DESC, ID DESC
              LIMIT %d",
            $args
        ));

        // A hypothetical extra post would have taken a slot in the window, pushing
        // the row that sat in the last one out of it.
        $add_extra = ($extra instanceof WP_Post) && (int) $extra->ID !== $exclude_id;
        if ($add_extra) {
            $window = array_slice($this->veyra_feed_splice($window, $extra), 0, $per_page);
        }

        $sticky = array_filter(array_map('intval', (array) get_option('sticky_posts', array())));
        if (empty($sticky)) {
            return wp_list_pluck($window, 'ID');
        }

        // Every PUBLISHED sticky heads page 1, window or no window. In-window ones
        // are always newer than out-of-window ones, so core's two-step hoist leaves
        // the whole front block in plain post_date DESC order.
        $args  = $sticky;
        $where = 'ID IN (' . implode(',', array_fill(0, count($sticky), '%d')) . ")
                  AND post_type = 'post' AND post_status = 'publish'";
        if ($exclude_id) {
            $where  .= ' AND ID <> %d';
            $args[]  = $exclude_id;
        }
        $front = $this->veyra_feed_rows($wpdb->prepare(
            "SELECT ID, post_date FROM {$wpdb->posts}
              WHERE {$where}
              ORDER BY post_date DESC, ID DESC",
            $args
        ));
        if ($add_extra && in_array((int) $extra->ID, $sticky, true)) {
            $front = $this->veyra_feed_splice($front, $extra);
        }

        $front_ids = wp_list_pluck($front, 'ID');
        $rest      = array();
        foreach ($window as $row) {
            if (!in_array($row->ID, $front_ids, true)) {
                $rest[] = $row->ID;
            }
        }
        return array_merge($front_ids, $rest);
    }

    /** Run a prepared ID/post_date query and normalize the rows (ID always an int). */
    private function veyra_feed_rows($prepared_sql) {
        global $wpdb;
        $out = array();
        foreach ((array) $wpdb->get_results($prepared_sql) as $row) {
            $out[] = (object) array('ID' => (int) $row->ID, 'post_date' => $row->post_date);
        }
        return $out;
    }

    /** Insert $post into an already post_date-DESC-ordered row list at its own slot. */
    private function veyra_feed_splice($rows, $post) {
        $entry = (object) array('ID' => (int) $post->ID, 'post_date' => $post->post_date);
        $at    = count($rows);
        foreach ($rows as $i => $row) {
            if ($row->post_date < $entry->post_date
                || ($row->post_date === $entry->post_date && $row->ID < $entry->ID)) {
                $at = $i;
                break;
            }
        }
        array_splice($rows, $at, 0, array($entry));
        return $rows;
    }

    /** posts_per_page if calibration should run at all, or 0 to stand down. */
    private function veyra_autocal_per_page() {
        if (!$this->veyra_autocal_is_enabled()) {
            return 0;
        }
        $per_page = (int) get_option('posts_per_page', 10);
        // -1 means "show every post" — there is no page 2 to protect.
        return $per_page >= 1 ? $per_page : 0;
    }

    /** transition_post_status: a post entered or left the published feed. */
    public function veyra_autocal_on_transition($new_status, $old_status, $post) {
        if ($new_status === $old_status) {
            return;
        }
        if (!($post instanceof WP_Post) || $post->post_type !== 'post') {
            return;
        }
        $per_page = $this->veyra_autocal_per_page();
        if (!$per_page) {
            return;
        }

        if ($old_status === 'publish' && $new_status !== 'publish') {
            // The DB already reflects the departure, so the BEFORE line-up is the
            // current feed with this post put back where it used to sit.
            $this->veyra_autocal_apply(
                $this->veyra_feed_page_one_ids($per_page, 0, $post),
                $this->veyra_feed_page_one_ids($per_page),
                $per_page,
                (int) $post->ID
            );
        } elseif ($new_status === 'publish' && $old_status !== 'publish') {
            // ...and here the BEFORE line-up is the current feed with it taken out.
            $this->veyra_autocal_apply(
                $this->veyra_feed_page_one_ids($per_page, (int) $post->ID),
                $this->veyra_feed_page_one_ids($per_page),
                $per_page,
                (int) $post->ID
            );
        }
    }

    /**
     * before_delete_post: a post is about to be erased for good.
     *
     * Permanent deletion never fires transition_post_status, so without this a
     * published post could be nuked straight out of page 1 and pull a rolled-off
     * post up behind it uncalibrated. The row still exists at this point, which
     * makes the AFTER line-up the hypothetical one here.
     */
    public function veyra_autocal_on_delete($post_id, $post = null) {
        if (!($post instanceof WP_Post)) {
            $post = get_post($post_id);
        }
        if (!($post instanceof WP_Post) || $post->post_type !== 'post') {
            return;
        }
        // Only a PUBLISHED post is leaving the feed here. Emptying the trash deletes
        // posts that left it back at trash time and were calibrated for then.
        if ($post->post_status !== 'publish') {
            return;
        }
        $per_page = $this->veyra_autocal_per_page();
        if (!$per_page) {
            return;
        }

        $this->veyra_autocal_apply(
            $this->veyra_feed_page_one_ids($per_page),
            $this->veyra_feed_page_one_ids($per_page, (int) $post->ID),
            $per_page,
            (int) $post->ID
        );
    }

    /**
     * Compare the two page-1 line-ups and move posts_per_page by one if this change
     * crossed a flagged post over the boundary.
     *
     * Diffing the actual line-ups instead of doing index arithmetic is what makes
     * sticky posts a non-issue: whatever core would hoist is already baked into both
     * sides of the comparison, so a sticky that never moves never shows up in a diff.
     *
     * $ignore_id is the post that just changed. It sits on exactly one side of the
     * diff by definition, and its own flags must not fire anything — the flags say
     * where a post should sit, not what publishing or deleting it should cost.
     */
    private function veyra_autocal_apply($before, $after, $per_page, $ignore_id) {
        foreach (array_diff($after, $before) as $id) {   // newly ON page 1
            if ($id !== $ignore_id && $this->veyra_srro_is_on($id)) {
                if ($per_page > 1) {                     // never drive the setting below 1
                    update_option('posts_per_page', $per_page - 1);
                }
                return;
            }
        }
        foreach (array_diff($before, $after) as $id) {   // newly OFF page 1
            if ($id !== $ignore_id && $this->veyra_sroh_is_on($id)) {
                update_option('posts_per_page', $per_page + 1);
                return;
            }
        }
    }

    /* ---------------------------------------------------------------------
     * f800 - rescan
     *
     * The transition hooks above only ever react to a single post crossing the
     * page-1 boundary. This is the full, on-demand version: look at EVERY
     * published should_remain_on_homepage post and make sure all of them are on
     * feed page 1.
     *
     * A non-sticky post is on page 1 iff its rank in the post_date DESC feed is
     * <= posts_per_page (published stickies always are — see
     * veyra_feed_page_one_ids()). So the setting needed is simply the rank of the
     * lowest-ranked flagged post.
     *
     * With "randomly back date non-should-remain posts" ticked, the rescan first
     * tries to make room without touching the setting: it takes the plain posts
     * (no flag of either kind, not sticky) ranked ABOVE the oldest flagged post
     * and back-dates as many as needed below it. Each one is moved to a random
     * date + time (to the second) 3–7 days before the previous point, starting
     * from the oldest flagged post's date, so they trail off like a drip instead
     * of piling onto one timestamp. The oldest of those candidates are moved
     * first, which leaves the top of the homepage untouched. Whatever shortfall
     * remains is then covered by enlarging posts_per_page. The setting is never
     * lowered here.
     * ------------------------------------------------------------------- */

    /** AJAX: run the f800 rescan. POST backdate=1|0. */
    public function ajax_autocal_rescan() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $backdate = isset($_POST['backdate']) && $_POST['backdate'] === '1';
        wp_send_json_success($this->veyra_autocal_rescan($backdate));
    }

    /** Published posts in feed order (post_date DESC, ID DESC — same as veyra_feed_page_one_ids()). */
    private function veyra_feed_all_rows() {
        global $wpdb;
        return $this->veyra_feed_rows(
            "SELECT ID, post_date FROM {$wpdb->posts}
              WHERE post_type = 'post' AND post_status = 'publish'
              ORDER BY post_date DESC, ID DESC"
        );
    }

    /**
     * 1-based rank of each non-sticky flagged post in the feed, keyed by post ID.
     * Stickies are left out because core hoists them onto page 1 regardless.
     */
    private function veyra_sroh_ranks($rows, $sroh_map, $sticky) {
        $ranks = array();
        foreach ($rows as $i => $row) {
            if (!empty($sroh_map[$row->ID]) && !in_array($row->ID, $sticky, true)) {
                $ranks[$row->ID] = $i + 1;
            }
        }
        return $ranks;
    }

    /**
     * Move each post to a random date + time (to the second) 3–7 days before the
     * previous point, starting from $from_date, so they trail off like a drip
     * instead of piling onto one timestamp. Pass $ids oldest first.
     *
     * @param int[]  $ids
     * @param string $from_date local post_date the drip starts below
     * @param bool   $dry       compute the dates but write nothing
     * @return array[] { id, title, from, to }
     */
    private function veyra_backdate_drip($ids, $from_date, $dry = false) {
        global $wpdb;
        $out   = array();
        // post_date is site-local; treating it as UTC for the arithmetic and
        // formatting it back the same way keeps it local end to end.
        $point = strtotime($from_date . ' UTC');
        foreach ($ids as $id) {
            $point   -= wp_rand(3 * DAY_IN_SECONDS, 7 * DAY_IN_SECONDS);
            $new_date = gmdate('Y-m-d H:i:s', $point);
            $old_date = get_post_field('post_date', $id);
            if (!$dry) {
                // Straight to the row rather than wp_update_post(): a date shift on an
                // already-published post isn't a save, and save_post handlers here
                // (wayback / postmeta boxes) expect an editor $_POST behind them.
                $wpdb->update(
                    $wpdb->posts,
                    array('post_date' => $new_date, 'post_date_gmt' => get_gmt_from_date($new_date)),
                    array('ID' => $id),
                    array('%s', '%s'),
                    array('%d')
                );
                clean_post_cache($id);
            }
            $out[] = array(
                'id'    => (int) $id,
                'title' => get_the_title($id),
                'from'  => $old_date,
                'to'    => $new_date,
            );
        }
        return $out;
    }

    /** @return array summary for the UI */
    private function veyra_autocal_rescan($backdate) {

        $raw_per_page = (int) get_option('posts_per_page', 10);
        $sroh_map = $this->veyra_flag_get_map(self::VEYRA_SROH_OPTION);
        $srro_map = $this->veyra_flag_get_map(self::VEYRA_SRRO_OPTION);
        $sticky   = array_filter(array_map('intval', (array) get_option('sticky_posts', array())));

        $rows  = $this->veyra_feed_all_rows();
        $ranks = $this->veyra_sroh_ranks($rows, $sroh_map, $sticky);

        $result = array(
            'flagged_total'    => count(array_filter($sroh_map)),
            'flagged_published'=> 0,
            'missing_before'   => 0,
            'backdated'        => array(),
            'per_page_before'  => $raw_per_page,
            'per_page_after'   => $raw_per_page,
        );
        foreach ($rows as $row) {
            if (!empty($sroh_map[$row->ID])) {
                $result['flagged_published']++;
            }
        }

        // -1 = "show every post": everything is already on the one and only page.
        if ($raw_per_page < 1 || empty($ranks)) {
            return $result;
        }
        $per_page = $raw_per_page;

        foreach ($ranks as $rank) {
            if ($rank > $per_page) {
                $result['missing_before']++;
            }
        }
        if (!$result['missing_before']) {
            return $result;
        }

        if ($backdate) {
            $needed = max($ranks);              // rank of the oldest flagged post
            $excess = $needed - $per_page;      // rows that must leave the space above it

            // Plain posts sitting above the oldest flagged post, oldest first.
            $candidates = array();
            for ($i = $needed - 2; $i >= 0; $i--) {
                $id = $rows[$i]->ID;
                if (empty($sroh_map[$id]) && empty($srro_map[$id]) && !in_array($id, $sticky, true)) {
                    $candidates[] = $id;
                }
            }
            $to_move = array_slice($candidates, 0, $excess);

            $result['backdated'] = $this->veyra_backdate_drip($to_move, $rows[$needed - 1]->post_date);

            if ($to_move) {
                $rows  = $this->veyra_feed_all_rows();
                $ranks = $this->veyra_sroh_ranks($rows, $sroh_map, $sticky);
            }
        }

        $needed = max($ranks);
        if ($needed > $per_page) {
            update_option('posts_per_page', $needed);
            $result['per_page_after'] = $needed;
        }
        return $result;
    }

    /* ---------------------------------------------------------------------
     * f900 - clean now
     *
     * Like f800, but the target is stricter: feed page 1 shows ONLY
     * should_remain_on_homepage posts. Every published, non-sticky post that
     * is NOT flagged on-homepage and ranks above the oldest flagged post —
     * should_remain_rolled_off posts and plain posts alike — is back-dated
     * below it with the same 3–7 day drip, oldest first. posts_per_page is then
     * set to exactly what's left above that point — the flagged posts plus any
     * stickies dated among them — which LOWERS it in the usual case (f800 only
     * ever raises it).
     *
     * Stickies are left alone: core hoists them onto page 1 whatever their date,
     * so an unflagged sticky can't be cleaned off by back-dating. They're counted
     * in the summary so the UI can say so.
     *
     * dry=1 computes the plan without writing, for the confirmation popup.
     *
     * Published posts only (as with f800): drafts, scheduled, pending and
     * private posts don't exist for this — they're never counted, ranked or
     * back-dated, and their dates are ignored.
     * ------------------------------------------------------------------- */

    /** AJAX: run (or preview, dry=1) the f900 clean. */
    public function ajax_autocal_clean() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $dry = isset($_POST['dry']) && $_POST['dry'] === '1';
        $res = $this->veyra_autocal_clean($dry);
        if (is_wp_error($res)) {
            wp_send_json_error($res->get_error_message());
        }
        wp_send_json_success($res);
    }

    /** @return array|WP_Error summary for the UI */
    private function veyra_autocal_clean($dry) {
        $raw_per_page = (int) get_option('posts_per_page', 10);
        $sroh_map = $this->veyra_flag_get_map(self::VEYRA_SROH_OPTION);
        $srro_map = $this->veyra_flag_get_map(self::VEYRA_SRRO_OPTION);
        $sticky   = array_filter(array_map('intval', (array) get_option('sticky_posts', array())));

        $rows  = $this->veyra_feed_all_rows();
        $ranks = $this->veyra_sroh_ranks($rows, $sroh_map, $sticky);
        if (empty($ranks)) {
            return new WP_Error('veyra_no_sroh', 'No published, non-sticky posts are marked should_remain_on_homepage — nothing to keep, so nothing was changed.');
        }

        $needed     = max($ranks);
        $candidates = array();
        $srro_moved = 0;
        for ($i = $needed - 2; $i >= 0; $i--) {
            $id = $rows[$i]->ID;
            if (empty($sroh_map[$id]) && !in_array($id, $sticky, true)) {
                $candidates[] = $id;
                if (!empty($srro_map[$id])) {
                    $srro_moved++;
                }
            }
        }

        $unflagged_sticky = 0;
        foreach ($rows as $row) {
            if (in_array($row->ID, $sticky, true) && empty($sroh_map[$row->ID])) {
                $unflagged_sticky++;
            }
        }

        // The oldest flagged post's rank once every candidate has dropped below it.
        // Not simply count($ranks): stickies dated above it still occupy window
        // slots (core hoists them, it doesn't skip them).
        $target = $needed - count($candidates);
        $result = array(
            'dry'              => $dry,
            'flagged'          => count($ranks),
            'backdated'        => $this->veyra_backdate_drip($candidates, $rows[$needed - 1]->post_date, $dry),
            'srro_backdated'   => $srro_moved,
            'unflagged_sticky' => $unflagged_sticky,
            'per_page_before'  => $raw_per_page,
            'per_page_after'   => $target,
        );
        if (!$dry && $raw_per_page !== $target) {
            update_option('posts_per_page', $target);
        }
        return $result;
    }

    /* ---------------------------------------------------------------------
     * link_synopsis column (Posts list screen)
     *
     * Per post we store the OUTBOUND links found in post_content as a JSON array
     * in a single post meta row. Post meta (not a wp_option) because: the list
     * table primes the whole screen's meta in one query, the row dies with the
     * post, and each post is written independently so a batched re-scan can never
     * clobber another post's data the way a single shared option blob would.
     *
     * Shape: [ { anchor, target_url, is_nofollow: true|false, rel_raw, position } ]
     * is_nofollow is a real JSON boolean, so it round-trips as true/false rather
     * than as a "yes"/"no" string that every consumer would have to interpret.
     * Absent meta   = never scanned.
     * Empty array   = scanned, no outbound links found.
     * ------------------------------------------------------------------- */

    /** Post meta key holding the JSON link array. Underscore-prefixed = hidden from the custom-fields UI. */
    const VEYRA_LSYN_META = '_veyra_link_synopsis';

    /** Option holding the unix timestamp of the last full refresh. */
    const VEYRA_LSYN_LAST_REFRESH_OPTION = 'veyra_link_synopsis_last_refresh';

    /** How many posts each AJAX batch scans. */
    const VEYRA_LSYN_BATCH_SIZE = 25;

    /**
     * "Money site" marks, toggled by hand with the MS button on link_synopsis rows.
     *
     * A money site is a property of the DESTINATION domain, not of any one link or
     * post, so this is one site-wide { registrable_domain => 1 } map — the same
     * shape as the should_remain_* flags. Marking it on any row marks every link to
     * that domain (www. and subdomains included) across every post. Absent key =
     * FALSE, so nothing to install.
     */
    const VEYRA_MONEY_SITES_OPTION = 'veyra_money_sites';

    /** Request-level cache: the column renders once per row. */
    private $veyra_money_sites_cache = null;

    private function veyra_money_sites_map() {
        if ($this->veyra_money_sites_cache === null) {
            $this->veyra_money_sites_cache = $this->veyra_flag_get_map(self::VEYRA_MONEY_SITES_OPTION);
        }
        return $this->veyra_money_sites_cache;
    }

    /** AJAX: flip the money-site mark for a domain. POST domain=<host or registrable domain>. */
    public function ajax_toggle_money_site() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        $domain = $this->veyra_lsyn_registrable_domain(isset($_POST['domain']) ? wp_unslash($_POST['domain']) : '');
        if ($domain === '') {
            wp_send_json_error('Invalid domain');
        }
        $map = $this->veyra_flag_get_map(self::VEYRA_MONEY_SITES_OPTION);
        $new_on = empty($map[$domain]);
        if ($new_on) {
            $map[$domain] = 1;
        } else {
            unset($map[$domain]);
        }
        update_option(self::VEYRA_MONEY_SITES_OPTION, $map);
        wp_send_json_success(array('domain' => $domain, 'on' => $new_on ? 1 : 0));
    }

    /**
     * Reduce a hostname to its registrable domain, so subdomains of this site count
     * as INTERNAL (blog.foo.com is not "outbound" from foo.com) and www./non-www are
     * the same thing. Covers the common multi-label public suffixes; this is a
     * pragmatic list, not the full Public Suffix List, which is fine here because a
     * miss only means an exotic ccTLD subdomain is reported as outbound.
     */
    private function veyra_lsyn_registrable_domain($host) {
        $host = strtolower(trim((string) $host, '. '));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n <= 2) {
            return $host;
        }
        $last_two = $parts[$n - 2] . '.' . $parts[$n - 1];
        $multi_label_suffixes = array(
            'co.uk', 'org.uk', 'me.uk', 'net.uk', 'ltd.uk', 'plc.uk', 'sch.uk', 'ac.uk', 'gov.uk',
            'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
            'co.nz', 'net.nz', 'org.nz', 'co.za', 'org.za',
            'co.jp', 'ne.jp', 'or.jp', 'co.kr', 'com.tw', 'com.hk', 'com.sg', 'com.cn',
            'co.in', 'net.in', 'org.in', 'com.br', 'net.br', 'com.mx', 'com.ar',
            'com.tr', 'com.pl', 'com.ua', 'co.il',
        );
        if (in_array($last_two, $multi_label_suffixes, true) && $n >= 3) {
            return $parts[$n - 3] . '.' . $last_two;
        }
        return $last_two;
    }

    /** This site's registrable domain, used as the internal/outbound dividing line. */
    private function veyra_lsyn_home_domain() {
        return $this->veyra_lsyn_registrable_domain(parse_url(home_url(), PHP_URL_HOST));
    }

    /**
     * Is this href an outbound link? Relative URLs, bare fragments, query-only hrefs
     * and non-web schemes (mailto:, tel:, javascript:, data:, ftp:) are all excluded;
     * protocol-relative "//host/path" is treated as a real absolute URL.
     */
    private function veyra_lsyn_is_outbound($href, $home_domain) {
        $href = trim($href);
        if ($href === '') {
            return false;
        }
        if (strpos($href, '//') === 0) {
            $href = 'https:' . $href; // protocol-relative
        }
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($scheme === '') {
            return false; // relative path, #fragment or ?query — same site by definition
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false; // mailto: tel: javascript: data: ftp: …
        }
        $host = (string) parse_url($href, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }
        return $this->veyra_lsyn_registrable_domain($host) !== $home_domain;
    }

    /**
     * Parse post_content and return its outbound links, in document order.
     * DOMDocument rather than regex, so entity-encoded hrefs, odd quoting and
     * attribute order are all handled by a real parser.
     */
    private function veyra_lsyn_extract_links($html, $home_domain) {
        $links = array();
        $html  = (string) $html;
        if (trim($html) === '' || stripos($html, '<a') === false) {
            return $links;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        // The meta charset makes libxml treat the fragment as UTF-8; the wrapper div
        // keeps loadHTML from complaining about a missing root element.
        $loaded = $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div>' . $html . '</div>'
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $links;
        }

        $position = 0;
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if (!$this->veyra_lsyn_is_outbound($href, $home_domain)) {
                continue;
            }

            // rel parsed token-wise: rel="nofollow noopener" must match, and
            // sponsored/ugc are treated as nofollow because they behave the same way.
            $rel_raw = trim($a->getAttribute('rel'));
            $tokens  = $rel_raw === '' ? array() : preg_split('/\s+/', strtolower($rel_raw), -1, PREG_SPLIT_NO_EMPTY);
            $is_nofollow = (bool) array_intersect($tokens, array('nofollow', 'sponsored', 'ugc'));

            $anchor = trim(preg_replace('/\s+/u', ' ', $a->textContent));
            if ($anchor === '') {
                // Image link: fall back to the alt text so the row isn't blank.
                $imgs = $a->getElementsByTagName('img');
                if ($imgs->length) {
                    $alt = trim($imgs->item(0)->getAttribute('alt'));
                    $anchor = $alt !== '' ? '[img] ' . $alt : '[img]';
                }
            }

            $links[] = array(
                'anchor'      => $anchor,
                'target_url'  => $href,
                'is_nofollow' => $is_nofollow, // real boolean -> true/false in the JSON
                'rel_raw'     => $rel_raw,
                'position'    => $position++,
            );
        }
        return $links;
    }

    /**
     * Coerce a stored is_nofollow value to a boolean. Rows written before this field
     * became a real boolean hold the string "yes"/"no", so they keep rendering
     * correctly until the next refresh overwrites them.
     */
    private function veyra_lsyn_is_nofollow_value($value) {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), array('true', 'yes', '1'), true);
        }
        return (bool) $value;
    }

    /** Read a post's stored links. Returns null when the post has never been scanned. */
    private function veyra_lsyn_get_links($post_id) {
        $raw = get_post_meta($post_id, self::VEYRA_LSYN_META, true);
        if ($raw === '' || $raw === null || $raw === false) {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Scan one post and persist its links. Returns the number of outbound links found. */
    private function veyra_lsyn_scan_post($post, $home_domain) {
        $links = $this->veyra_lsyn_extract_links($post->post_content, $home_domain);
        // wp_slash: update_post_meta runs the value through wp_unslash(), which would
        // otherwise strip backslashes out of JSON escape sequences in anchor text.
        update_post_meta($post->ID, self::VEYRA_LSYN_META, wp_slash(wp_json_encode($links)));
        return count($links);
    }

    /** Add the link_synopsis column, prepended so it lands leftmost (see priority 20 in the constructor). */
    public function veyra_lsyn_add_column($columns) {
        $new = array('veyra_lsyn' => '<span class="veyra-lsyn-th">link_synopsis</span>');
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
        }
        return $new;
    }

    /**
     * Split a value into escaped display text plus (when it was truncated) a hover ⓘ
     * that reveals the full string. Returned as two pieces so the caller can keep the
     * icon OUTSIDE a surrounding <a>, otherwise clicking the icon would follow the link.
     *
     * The tooltip is driven by data-veyra-tip rather than the native title attribute,
     * which has a ~1s delay, can't be styled and wraps long URLs badly.
     */
    private function veyra_lsyn_truncate_cell($text, $limit) {
        $text = (string) $text;
        $len  = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($len <= $limit) {
            return array('text' => esc_html($text), 'tip' => '');
        }
        $short = function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
        return array(
            'text' => esc_html($short) . '…',
            'tip'  => '<span class="veyra-lsyn-tip" tabindex="0" role="button" aria-label="show full value" data-veyra-tip="' . esc_attr($text) . '">&#9432;</span>',
        );
    }

    /**
     * Same as veyra_lsyn_truncate_cell(), but the host is wrapped in its own span
     * (.veyra-lsyn-host) so a money-site mark can bold just the domain portion.
     * Truncation counts the whole URL, so a long host can itself be cut short.
     *
     * @return array { text, tip, host }
     */
    private function veyra_lsyn_url_cell($url, $limit) {
        $url  = (string) $url;
        $cut  = $this->veyra_lsyn_truncate_cell($url, $limit);
        $host = (string) parse_url(strpos($url, '//') === 0 ? 'https:' . $url : $url, PHP_URL_HOST);
        $cut['host'] = $host;
        if ($host === '') {
            return $cut;
        }
        // Byte offsets below; a non-ASCII URL keeps the plain (unbolded) cut rather
        // than risk splitting a multibyte character, which esc_html() would blank.
        $at = stripos($url, $host);
        if ($at === false || $at >= $limit || preg_match('/[^\x00-\x7F]/', $url)) {
            return $cut;
        }
        $len   = function_exists('mb_strlen') ? mb_strlen($url, 'UTF-8') : strlen($url);
        $short = $len > $limit ? (function_exists('mb_substr') ? mb_substr($url, 0, $limit, 'UTF-8') : substr($url, 0, $limit)) : $url;
        $pre   = substr($short, 0, $at);
        $mid   = substr($short, $at, strlen($host));
        $post  = (string) substr($short, $at + strlen($mid));
        $cut['text'] = esc_html($pre)
            . '<span class="veyra-lsyn-host">' . esc_html($mid) . '</span>'
            . esc_html($post) . ($len > $limit ? '…' : '');
        return $cut;
    }

    /** Render the compact per-post links table inside our column's cell. */
    public function veyra_lsyn_render_column($column, $post_id) {
        if ($column !== 'veyra_lsyn') {
            return;
        }
        $links = $this->veyra_lsyn_get_links($post_id);
        if ($links === null) {
            echo '<span class="veyra-lsyn-empty">not scanned</span>';
            return;
        }
        if (!$links) {
            echo '<span class="veyra-lsyn-empty">no outbound links</span>';
            return;
        }

        $money_map = $this->veyra_money_sites_map();
        echo '<table class="veyra-lsyn-tbl"><tbody>';
        foreach ($links as $link) {
            $nofollow = $this->veyra_lsyn_is_nofollow_value(isset($link['is_nofollow']) ? $link['is_nofollow'] : false);
            $anchor   = $this->veyra_lsyn_truncate_cell(isset($link['anchor']) ? $link['anchor'] : '', 26);
            $raw_url  = isset($link['target_url']) ? $link['target_url'] : '';
            // No server-side cut for URLs: .veyra-lsyn-val ellipsises them right at
            // the cell edge, so the text reaches the border at any column width. A
            // fixed character cap left a wide dead gap whenever the column was wider
            // than the cap. The ⓘ can't know whether CSS cut the text, so it's shown
            // for any URL long enough that it plausibly was.
            $url      = $this->veyra_lsyn_url_cell($raw_url, 2048);
            if ($url['tip'] === '' && strlen($raw_url) > 30) {
                $url['tip'] = '<span class="veyra-lsyn-tip" tabindex="0" role="button" aria-label="show full value" data-veyra-tip="' . esc_attr($raw_url) . '">&#9432;</span>';
            }
            $reg      = $this->veyra_lsyn_registrable_domain($url['host']);
            $money    = $reg !== '' && !empty($money_map[$reg]);
            // The value is wrapped in .veyra-lsyn-val (which does the ellipsis) while
            // the ⓘ sits outside it, so overflow clipping can never hide the icon.
            // MS / D / C each get their own <td> so they line up down the column.
            printf(
                '<tr><td class="veyra-lsyn-nf %s">%s</td>'
                . '<td class="veyra-lsyn-anchor"><span class="veyra-lsyn-val">%s</span>%s</td>'
                . '<td class="veyra-lsyn-bslot is-ms"><button type="button" class="veyra-lsyn-btn veyra-lsyn-ms%s" data-domain="%s" aria-pressed="%s" title="money site: %s">MS</button></td>'
                . '<td class="veyra-lsyn-bslot"><button type="button" class="veyra-lsyn-btn veyra-lsyn-copy" data-copy="%s" title="copy domain">D</button></td>'
                . '<td class="veyra-lsyn-bslot"><button type="button" class="veyra-lsyn-btn veyra-lsyn-copy" data-copy="%s" title="copy entire url">C</button></td>'
                . '<td class="veyra-lsyn-url%s" data-domain="%s"><a class="veyra-lsyn-val" href="%s" target="_blank" rel="noopener noreferrer">%s</a>%s</td></tr>',
                $nofollow ? 'is-nofollow' : 'is-follow',
                $nofollow ? 'TRUE' : 'FALSE',
                $anchor['text'],
                $anchor['tip'],
                $money ? ' is-on' : '',
                esc_attr($reg),
                $money ? 'true' : 'false',
                $money ? 'TRUE' : 'FALSE',
                esc_attr($url['host']),
                esc_attr($raw_url),
                $money ? ' is-money' : '',
                esc_attr($reg),
                esc_url($raw_url),
                $url['text'],
                $url['tip']
            );
        }
        echo '</tbody></table>';
    }

    /** Columns hidden by default on the posts list, so Title gets a usable width. */
    private function veyra_lsyn_toggleable_columns() {
        return array('author', 'categories', 'tags', 'comments');
    }

    /**
     * Hide the noisy columns by default on the Posts list screen. This filter only
     * applies when the user has no saved preference, so once someone toggles a
     * column their own choice wins from then on.
     */
    public function veyra_lsyn_default_hidden_columns($hidden, $screen) {
        if (!$screen || $screen->id !== 'edit-post') {
            return $hidden;
        }
        return array_values(array_unique(array_merge((array) $hidden, $this->veyra_lsyn_toggleable_columns())));
    }

    /** Human-readable "last run" label, or an empty string if it has never run. */
    private function veyra_lsyn_last_refresh_label() {
        $ts = (int) get_option(self::VEYRA_LSYN_LAST_REFRESH_OPTION, 0);
        if (!$ts) {
            return '';
        }
        return 'last run: ' . wp_date('M j, Y g:i a', $ts);
    }

    /** Shared guard for the three link_synopsis AJAX endpoints. */
    private function veyra_lsyn_ajax_guard() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
    }

    /**
     * AJAX: return every post ID to scan. The client slices this list into batches,
     * so the set stays fixed for the whole run (an offset/limit scheme would drift
     * if a post were published or trashed mid-scan).
     */
    public function ajax_lsyn_start() {
        $this->veyra_lsyn_ajax_guard();
        $ids = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'any', // all statuses incl. drafts; excludes trash/auto-draft
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ));
        wp_send_json_success(array(
            'ids'        => array_map('intval', $ids),
            'batch_size' => self::VEYRA_LSYN_BATCH_SIZE,
        ));
    }

    /** AJAX: scan one batch of post IDs and store each post's links. */
    public function ajax_lsyn_scan_batch() {
        $this->veyra_lsyn_ajax_guard();
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
        if (!$ids) {
            wp_send_json_error('No post ids supplied');
        }
        $home_domain = $this->veyra_lsyn_home_domain();
        $scanned = 0;
        $found   = 0;
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'post') {
                continue;
            }
            $found += $this->veyra_lsyn_scan_post($post, $home_domain);
            $scanned++;
        }
        wp_send_json_success(array('scanned' => $scanned, 'links' => $found));
    }

    /** AJAX: stamp the completion time once every batch has finished. */
    public function ajax_lsyn_finish() {
        $this->veyra_lsyn_ajax_guard();
        update_option(self::VEYRA_LSYN_LAST_REFRESH_OPTION, time(), false);
        wp_send_json_success(array('label' => $this->veyra_lsyn_last_refresh_label()));
    }

    /** Column styles, hover tooltip, and the batched refresh button + progress bar. */
    public function veyra_lsyn_footer_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'post') {
            return;
        }
        $nonce = wp_create_nonce('veyra_elephant_tools');
        ?>
        <style>
            /* A percentage, not a fixed 360px: the posts list is table-layout:fixed,
               so a pixel width is taken off the top and the Title column absorbs the
               entire loss. A percentage shares the squeeze with the other columns. */
            /* 40% (was 20%): doubles the visible URL text before it ellipsises. The
               URL sub-column is what's left after the 44px nf column and the 40%
               anchor, so doubling the outer width more than doubles it. */
            /* 36% (was 29%): +120px at the ~1700px table this is used at. Plain %
               because Chrome treats calc() widths on fixed-layout columns as auto.
               ~100px came out of slack the layout was spreading over the px columns,
               the rest off boldcrypted (8.4%). Title keeps its 18%. */
            .fixed .column-veyra_lsyn { width: 36%; }
            /* Boxed on all 4 sides, and tight on the right so the URL's ellipsis + ⓘ
               end ~4px from the border (core pads cells 10px; the URL sub-cell had 6px). */
            .wp-list-table tbody td.column-veyra_lsyn { border: 1px solid #c3c4c7; padding-right: 4px; }
            table.veyra-lsyn-tbl td.veyra-lsyn-url { padding-right: 0; }
            /* Box every header cell on all 4 sides. The checkbox header is a <td>,
               not a <th>, in core's markup, so it's included to keep the row whole.
               widefat tables use separate borders, so neighbours drop their left
               edge to avoid a doubled 2px line between cells. */
            .wp-list-table thead th,
            .wp-list-table thead td,
            .wp-list-table tfoot th,
            .wp-list-table tfoot td { border: 1px solid #c3c4c7; }
            .wp-list-table thead tr > * + *,
            .wp-list-table tfoot tr > * + * { border-left-width: 0; }
            /* No zebra striping: every row on core's lighter (white) background, each
               row ruled top and bottom. (0,2,2) beats core's ".striped > tbody >
               :nth-child(odd)". Cells get a bottom rule and the first row also a top
               one, so the line between two rows is 1px rather than a doubled 2px.
               Separator rows keep their own colours (set on their <td>s). */
            .wp-list-table.striped > tbody > tr { background-color: #fff; }
            .wp-list-table > tbody > tr > td,
            .wp-list-table > tbody > tr > th { border-bottom: 1px solid #dcdcde; }
            .wp-list-table > tbody > tr:first-child > td,
            .wp-list-table > tbody > tr:first-child > th { border-top: 1px solid #dcdcde; }
            /* Title gets an explicit share. It used to get no width rule and live off
               whatever was left over, but once link_synopsis (then 40%), boldcrypted
               (16%), Date (core 14%) and the px columns added up past the table width,
               "left over" was 0px and Title wrapped one character per line. Percent
               columns now total 64% (Title 18 + link_synopsis 29 + boldcrypted 10 +
               Date 7), leaving the rest for the px columns and cell padding at ~1700px.
               Narrower windows scroll sideways instead of crushing Title; rocket_chamber
               Cols/page can trim columns. */
            .fixed .column-title { width: 18%; }
            .fixed .column-date { width: 7%; }
            .fixed .column-veyra_srro,
            .fixed .column-veyra_sroh { width: 90px; }
            .fixed .check-column { width: 2.2em; }
            /* Narrow these if they get toggled back on, so Title keeps usable width.
               Core sets author 10%, categories/tags 15%, comments 5.5em. */
            .fixed .column-author { width: 8%; }
            .fixed .column-categories,
            .fixed .column-tags { width: 10%; }
            .fixed .column-comments { width: 4em; }

            .veyra-lsyn-th { display: inline-block; font-weight: 700; text-transform: lowercase; }
            .veyra-lsyn-empty { color: #a7aaad; font-style: italic; font-size: 11px; }
            .veyra-lsyn-tbl { border-collapse: collapse; width: 100%; table-layout: fixed; margin: 0; }
            /* "table.veyra-lsyn-tbl td" (0,1,2) deliberately outweighs core's
               ".widefat td" (0,1,1). That rule targets these nested cells DIRECTLY
               with padding:8px 10px, font-size:13px and line-height:1.5em — and a
               directly-matching declaration always beats a value inherited from the
               parent table, so setting the font on .veyra-lsyn-tbl alone did nothing.
               Every mini-table row was rendering at core's 13px/8px-padded size:
               ~35px per link instead of ~16px. */
            table.veyra-lsyn-tbl td {
                padding: 0 6px 0 0;
                border: none;
                font-size: 11px;
                line-height: 1.5;
                vertical-align: top;
                white-space: nowrap;
            }
            .veyra-lsyn-nf { width: 44px; text-align: center; font-weight: 600; }
            .veyra-lsyn-nf.is-nofollow { color: #b32d2e; }
            .veyra-lsyn-nf.is-follow { color: #2271b1; }
            /* Fixed px, not a %: anchors are cut at 26 chars server-side, so a share
               of a wide column just left dead space before the URL. ~26 chars at
               11px plus the ⓘ. */
            .veyra-lsyn-anchor { width: 170px; }
            .veyra-lsyn-bslot { width: 20px; text-align: center; }
            table.veyra-lsyn-tbl td.veyra-lsyn-bslot { padding: 0 3px 0 0; }
            .veyra-lsyn-bslot.is-ms { width: 26px; }
            .veyra-lsyn-btn {
                display: inline-block; min-width: 18px; height: 15px; padding: 0 3px;
                font-size: 9px; font-weight: 700; line-height: 13px; text-align: center;
                border: 1px solid #8c8f94; border-radius: 3px; background: #fff; color: #2c3338;
                cursor: pointer; vertical-align: middle;
            }
            .veyra-lsyn-btn:hover { background: #f0f0f1; border-color: #2271b1; color: #2271b1; }
            /* MS reads as "off" until switched on. */
            .veyra-lsyn-ms { min-width: 22px; border-color: #dcdcde; color: #c3c4c7; background: #f6f7f7; }
            .veyra-lsyn-ms.is-on,
            .veyra-lsyn-ms.is-on:hover { background: #00a32a; border-color: #008a20; color: #fff; }
            .veyra-lsyn-ms.is-busy { opacity: 0.5; cursor: wait; }
            .veyra-lsyn-url.is-money .veyra-lsyn-host { font-weight: 700; }
            #veyra-copied-pop {
                position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%);
                z-index: 100020; pointer-events: none; text-align: center; opacity: 0;
                max-width: 80vw; word-break: break-all;
            }
            #veyra-copied-pop .veyra-copied-head { font-size: 20px; font-weight: 700; color: #2271b1; }
            #veyra-copied-pop .veyra-copied-val { margin-top: 4px; font-size: 14px; color: #00a32a; }
            .veyra-lsyn-url a { text-decoration: none; }
            /* URL text at 1.5x the mini-table's 11px. Needs (0,2,2) to beat the
               "table.veyra-lsyn-tbl td" font-size rule above. */
            table.veyra-lsyn-tbl td.veyra-lsyn-url { font-size: 16.5px; }
            /* Guarantees one line per link: the value ellipsises inside its own box
               while the ⓘ stays outside it and can never be clipped away. */
            .veyra-lsyn-val {
                display: inline-block; max-width: calc(100% - 14px);
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
                vertical-align: bottom;
            }
            .veyra-lsyn-tip {
                display: inline-block; margin-left: 3px; cursor: help; color: #2271b1;
                font-size: 12px; line-height: 1; vertical-align: baseline;
            }
            .veyra-lsyn-tip:focus { outline: 1px solid #2271b1; }
            /* position:fixed so the bubble escapes the list-table cell's clipping */
            #veyra-lsyn-tooltip {
                position: fixed; z-index: 100010; display: none; max-width: 460px;
                background: #1d2327; color: #fff; padding: 8px 10px; border-radius: 4px;
                font-size: 12px; line-height: 1.45; word-break: break-all;
                box-shadow: 0 2px 10px rgba(0,0,0,0.35);
            }
            .veyra-lsyn-refresh-wrap { display: inline-flex; align-items: center; gap: 8px; margin-left: 10px; vertical-align: middle; }
            .veyra-lsyn-lastrun { font-size: 12px; color: #646970; font-style: italic; }
            .veyra-lsyn-cols {
                display: inline-flex; align-items: center; gap: 10px; margin-left: 10px;
                vertical-align: middle; font-size: 12px;
                background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; padding: 3px 8px;
            }
            .veyra-lsyn-cols-label { color: #646970; font-weight: 600; }
            .veyra-lsyn-cols label { display: inline-flex; align-items: center; gap: 3px; white-space: nowrap; }
            .veyra-lsyn-cols input[type="checkbox"] { margin: 0; }
            .veyra-lsyn-progress { margin: 10px 0 4px 0; max-width: 620px; }
            .veyra-lsyn-progress-track { height: 14px; background: #dcdcde; border-radius: 7px; overflow: hidden; }
            .veyra-lsyn-progress-fill { display: block; height: 100%; width: 0; background: #2271b1; transition: width 0.2s ease; }
            .veyra-lsyn-progress-text { margin-top: 5px; font-size: 12px; color: #3c434a; }
            .veyra-lsyn-progress-text.is-done { color: #46b450; font-weight: 600; }
            .veyra-lsyn-progress-text.is-error { color: #d63638; font-weight: 600; }
        </style>
        <script>
        (function(){
            var AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var NONCE = <?php echo wp_json_encode($nonce); ?>;
            var LAST_RUN = <?php echo wp_json_encode($this->veyra_lsyn_last_refresh_label()); ?>;
            var TOGGLE_COLUMNS = <?php echo wp_json_encode($this->veyra_lsyn_toggleable_columns()); ?>;

            function post(action, extra){
                var body = new URLSearchParams();
                body.append('action', action);
                body.append('nonce', NONCE);
                if (extra) { extra.forEach(function(pair){ body.append(pair[0], pair[1]); }); }
                return fetch(AJAX_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function(r){ return r.json(); }).then(function(res){
                    if (!res || !res.success) {
                        throw new Error((res && res.data) ? res.data : 'request failed');
                    }
                    return res.data;
                });
            }

            // --- D / C copy buttons + "Copied!" popup --------------------------
            function copyText(text){
                if (navigator.clipboard && window.isSecureContext) {
                    return navigator.clipboard.writeText(text);
                }
                // http:// admin (e.g. Local) has no async clipboard API.
                return new Promise(function(resolve, reject){
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed'; ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    var ok = false;
                    try { ok = document.execCommand('copy'); } catch (err) {}
                    document.body.removeChild(ta);
                    ok ? resolve() : reject(new Error('copy failed'));
                });
            }
            var pop = null, popTimer = null;
            function showCopied(text){
                if (!pop) {
                    pop = document.createElement('div');
                    pop.id = 'veyra-copied-pop';
                    pop.innerHTML = '<div class="veyra-copied-head">Copied!</div><div class="veyra-copied-val"></div>';
                    document.body.appendChild(pop);
                }
                pop.querySelector('.veyra-copied-val').textContent = text;
                clearTimeout(popTimer);
                pop.style.transition = 'opacity 0.5s ease';   // fade in 0.5s
                void pop.offsetWidth;
                pop.style.opacity = '1';
                popTimer = setTimeout(function(){
                    pop.style.transition = 'opacity 1s ease'; // fade out 1s
                    pop.style.opacity = '0';
                }, 500);
            }
            document.addEventListener('click', function(e){
                var b = e.target.closest ? e.target.closest('.veyra-lsyn-copy') : null;
                if (!b) return;
                e.preventDefault();
                var text = b.getAttribute('data-copy') || '';
                if (!text) return;
                copyText(text).then(function(){ showCopied(text); })
                    .catch(function(err){ alert('Copy failed: ' + err.message); });
            });

            // --- MS (money site) toggle ---------------------------------------
            document.addEventListener('click', function(e){
                var b = e.target.closest ? e.target.closest('.veyra-lsyn-ms') : null;
                if (!b) return;
                e.preventDefault();
                var domain = b.getAttribute('data-domain');
                if (!domain || b.classList.contains('is-busy')) return;
                b.classList.add('is-busy');
                post('veyra_toggle_money_site', [['domain', domain]]).then(function(d){
                    var on = !!d.on;
                    // Every row linking to the same domain changes together.
                    document.querySelectorAll('.veyra-lsyn-ms[data-domain="' + d.domain + '"]').forEach(function(x){
                        x.classList.toggle('is-on', on);
                        x.setAttribute('aria-pressed', on ? 'true' : 'false');
                        x.setAttribute('title', 'money site: ' + (on ? 'TRUE' : 'FALSE'));
                    });
                    document.querySelectorAll('.veyra-lsyn-url[data-domain="' + d.domain + '"]').forEach(function(x){
                        x.classList.toggle('is-money', on);
                    });
                }).catch(function(err){ alert('Failed to toggle money site: ' + err.message); })
                  .finally(function(){ b.classList.remove('is-busy'); });
            });

            // --- Hover tooltip for truncated anchors / urls ------------------
            var tip = null;
            function tooltipEl(){
                if (!tip) {
                    tip = document.createElement('div');
                    tip.id = 'veyra-lsyn-tooltip';
                    document.body.appendChild(tip);
                }
                return tip;
            }
            function showTip(target){
                var text = target.getAttribute('data-veyra-tip');
                if (!text) return;
                var el = tooltipEl();
                el.textContent = text;
                el.style.display = 'block';
                el.style.left = '0px';
                el.style.top = '0px';
                var rect = target.getBoundingClientRect();
                var box = el.getBoundingClientRect();
                var left = Math.min(rect.left, window.innerWidth - box.width - 12);
                var top = rect.bottom + 6;
                if (top + box.height > window.innerHeight - 8) { top = rect.top - box.height - 6; }
                el.style.left = Math.max(8, left) + 'px';
                el.style.top = Math.max(8, top) + 'px';
            }
            function hideTip(){ if (tip) { tip.style.display = 'none'; } }

            document.addEventListener('mouseover', function(e){
                var t = e.target.closest ? e.target.closest('.veyra-lsyn-tip') : null;
                if (t) showTip(t);
            });
            document.addEventListener('mouseout', function(e){
                var t = e.target.closest ? e.target.closest('.veyra-lsyn-tip') : null;
                if (t) hideTip();
            });
            document.addEventListener('focusin', function(e){
                var t = e.target.closest ? e.target.closest('.veyra-lsyn-tip') : null;
                if (t) showTip(t);
            });
            document.addEventListener('focusout', hideTip);
            window.addEventListener('scroll', hideTip, true);

            // --- Refresh button + progress bar ------------------------------
            var els = {};

            function buildRefreshUI(){
                if (document.querySelector('.veyra-lsyn-refresh-wrap')) return true;
                // Sit to the right of the "Blog pages show at most" controller, which is
                // itself injected by JS; fall back to the Add Post button if it's absent.
                var anchor = document.querySelector('.veyra-reading-ctl')
                          || document.querySelector('.wrap .page-title-action');
                if (!anchor) return false;

                var wrap = document.createElement('span');
                wrap.className = 'veyra-lsyn-refresh-wrap';
                wrap.innerHTML = '<button type="button" class="button veyra-lsyn-refresh">refresh link data for link_synopsis</button>'
                    + '<span class="veyra-lsyn-lastrun"></span>';
                anchor.insertAdjacentElement('afterend', wrap);

                var progress = document.createElement('div');
                progress.className = 'veyra-lsyn-progress';
                progress.style.display = 'none';
                progress.innerHTML = '<div class="veyra-lsyn-progress-track"><span class="veyra-lsyn-progress-fill"></span></div>'
                    + '<div class="veyra-lsyn-progress-text"></div>';
                var header = document.querySelector('.wrap .wp-header-end') || document.querySelector('.wrap h1');
                if (header) { header.insertAdjacentElement('afterend', progress); }
                else { wrap.insertAdjacentElement('afterend', progress); }

                els.button   = wrap.querySelector('.veyra-lsyn-refresh');
                els.lastrun  = wrap.querySelector('.veyra-lsyn-lastrun');
                els.progress = progress;
                els.fill     = progress.querySelector('.veyra-lsyn-progress-fill');
                els.text     = progress.querySelector('.veyra-lsyn-progress-text');

                els.lastrun.textContent = LAST_RUN;
                els.button.addEventListener('click', runRefresh);

                buildColumnToggles(wrap);
                return true;
            }

            // --- Column show/hide toggles -----------------------------------
            // These proxy to WordPress's own Screen Options checkboxes rather than
            // reimplementing persistence: clicking .hide-column-tog fires core's
            // handler, which shows/hides the column AND saves the choice to user meta
            // (action=hidden-columns). So the setting survives reloads for free and
            // our toggles can never disagree with the Screen Options panel.
            function coreToggleFor(col){
                return document.getElementById(col + '-hide');
            }

            function columnIsVisible(col){
                var core = coreToggleFor(col);
                if (core) return core.checked;
                var th = document.querySelector('th.column-' + col);
                return !!th && !th.classList.contains('hidden');
            }

            function buildColumnToggles(anchorEl){
                if (document.querySelector('.veyra-lsyn-cols')) return;

                var available = TOGGLE_COLUMNS.filter(function(col){
                    return coreToggleFor(col) || document.querySelector('th.column-' + col);
                });
                if (!available.length) return;

                var box = document.createElement('span');
                box.className = 'veyra-lsyn-cols';
                box.innerHTML = '<span class="veyra-lsyn-cols-label">show columns:</span>'
                    + available.map(function(col){
                        return '<label><input type="checkbox" data-col="' + col + '"'
                             + (columnIsVisible(col) ? ' checked' : '') + '> ' + col + '</label>';
                    }).join('');
                anchorEl.insertAdjacentElement('afterend', box);

                box.addEventListener('change', function(e){
                    var input = e.target;
                    if (!input.matches('input[data-col]')) return;
                    var col = input.getAttribute('data-col');
                    var core = coreToggleFor(col);
                    if (core) {
                        // .click() flips checked AND fires the event core listens for.
                        if (core.checked !== input.checked) { core.click(); }
                    } else {
                        // No Screen Options panel on this screen — toggle for the
                        // current view only (nothing to persist to).
                        document.querySelectorAll('.column-' + col).forEach(function(cell){
                            cell.classList.toggle('hidden', !input.checked);
                        });
                    }
                });

                // Keep our checkboxes in step if the user uses Screen Options directly.
                document.addEventListener('click', function(e){
                    var tog = e.target.closest ? e.target.closest('.hide-column-tog') : null;
                    if (!tog) return;
                    var mine = box.querySelector('input[data-col="' + tog.value + '"]');
                    if (mine) { mine.checked = tog.checked; }
                });
            }

            function setProgress(pct, message, state){
                els.progress.style.display = 'block';
                els.fill.style.width = Math.max(0, Math.min(100, pct)) + '%';
                els.text.textContent = message;
                els.text.className = 'veyra-lsyn-progress-text' + (state ? ' is-' + state : '');
            }

            function runRefresh(){
                els.button.disabled = true;
                setProgress(0, 'starting…');

                post('veyra_lsyn_start').then(function(data){
                    var ids = data.ids || [];
                    var size = data.batch_size || 25;
                    var total = ids.length;
                    if (!total) {
                        setProgress(100, 'no posts to scan', 'done');
                        els.button.disabled = false;
                        return null;
                    }

                    var batches = [];
                    for (var i = 0; i < total; i += size) { batches.push(ids.slice(i, i + size)); }

                    var scanned = 0, links = 0;
                    // Sequential, one batch per request — keeps each request small so a
                    // large site can't hit the PHP time limit mid-scan.
                    return batches.reduce(function(chain, batch){
                        return chain.then(function(){
                            var pairs = batch.map(function(id){ return ['ids[]', id]; });
                            return post('veyra_lsyn_scan_batch', pairs).then(function(res){
                                scanned += res.scanned;
                                links += res.links;
                                setProgress(
                                    (scanned / total) * 100,
                                    'scanning… ' + scanned + ' / ' + total + ' posts · ' + links + ' outbound links found'
                                );
                            });
                        });
                    }, Promise.resolve()).then(function(){
                        return post('veyra_lsyn_finish').then(function(res){
                            els.lastrun.textContent = res.label || '';
                            setProgress(100, '✓ done — scanned ' + scanned + ' posts, found ' + links + ' outbound links. reloading…', 'done');
                            setTimeout(function(){ window.location.reload(); }, 1500);
                        });
                    });
                }).catch(function(err){
                    setProgress(100, 'failed: ' + err.message, 'error');
                    els.button.disabled = false;
                });
            }

            // The "Blog pages show at most" controller we anchor to is injected by a
            // separate script on the same hook, so poll briefly for it before falling
            // back, rather than depending on script execution order.
            function init(){
                if (buildRefreshUI()) return;
                var tries = 0;
                var timer = setInterval(function(){
                    if (buildRefreshUI() || ++tries > 40) { clearInterval(timer); }
                }, 50);
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>
        <?php
    }

    /* ---------------------------------------------------------------------
     * "Blog pages show at most" (posts_per_page) controller + rolled-off separator
     * ------------------------------------------------------------------- */

    /**
     * posts_orderby: on the Posts list screen's main query, sort drafts ahead of
     * everything else, keeping the existing order within each group.
     *
     * Only when the user hasn't picked a sort (no ?orderby=) — clicking a column
     * header gets core's plain ordering back. Drafts are listed and paginated
     * like any other row, so with many drafts they fill page 1 of the list first.
     */
    public function veyra_drafts_first_orderby($orderby, $query) {
        global $pagenow, $wpdb;
        if (!is_admin() || $pagenow !== 'edit.php' || !$query->is_main_query()) {
            return $orderby;
        }
        $type = $query->get('post_type');
        if (($type !== 'post' && $type !== '') || !empty($_GET['orderby'])) {
            return $orderby;
        }
        return "({$wpdb->posts}.post_status = 'draft') DESC" . ($orderby !== '' ? ', ' . $orderby : '');
    }

    /* ---------------------------------------------------------------------
     * Date Switch (Posts list row action)
     *
     * Each button asks the server for a random post_date (to the second) inside
     * a day range relative to NOW — e.g. 2–4 days ago, or 4–7 days ahead. The
     * random pick happens server-side so it's anchored to the site's clock and
     * timezone, not the browser's.
     *
     * Saved through wp_update_post() so it behaves exactly like editing the date
     * in Quick Edit: a published post given a future date becomes Scheduled, a
     * scheduled post given a past date publishes, drafts stay drafts. Every
     * save_post handler in this plugin bails without its own editor nonce, so an
     * AJAX save can't disturb their data, and transition_post_status still fires
     * for the auto-calibrate hooks.
     * ------------------------------------------------------------------- */

    /** post_row_actions: add "Date Switch" right after Preview / View. */
    public function veyra_ds_row_action($actions, $post) {
        if (!($post instanceof WP_Post) || $post->post_type !== 'post' || !current_user_can('edit_post', $post->ID)) {
            return $actions;
        }
        $actions['veyra_date_switch'] = sprintf(
            '<a href="#" class="veyra-ds-trigger" data-post="%d" role="button" aria-haspopup="true">Date Switch</a>',
            (int) $post->ID
        );
        return $actions;
    }

    /**
     * AJAX: set a post's date.
     *   kind=now                         current site time
     *   kind=range, dir=past|future,
     *       min=<days>, max=<days>      random second inside that window
     *   kind=exact, value=Y-m-dTH:i     a picked date, seconds randomized
     */
    public function ajax_date_switch() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $post    = $post_id ? get_post($post_id) : null;
        if (!$post || $post->post_type !== 'post') {
            wp_send_json_error('Invalid post id');
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Unauthorized');
        }

        $kind = isset($_POST['kind']) ? sanitize_key($_POST['kind']) : '';
        $now  = current_time('timestamp'); // site-local "timestamp", matches post_date
        if ($kind === 'now') {
            $ts = $now;
        } elseif ($kind === 'range') {
            $min = isset($_POST['min']) ? floatval($_POST['min']) : 0;
            $max = isset($_POST['max']) ? floatval($_POST['max']) : 0;
            if ($min < 0 || $max <= $min || $max > 3650) {
                wp_send_json_error('Invalid range');
            }
            $offset = wp_rand((int) round($min * DAY_IN_SECONDS), (int) round($max * DAY_IN_SECONDS));
            $ts = (isset($_POST['dir']) && $_POST['dir'] === 'past') ? $now - $offset : $now + $offset;
        } elseif ($kind === 'exact') {
            $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/', $value, $m)
                || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59) {
                wp_send_json_error('Invalid date');
            }
            $ts = gmmktime((int) $m[4], (int) $m[5], wp_rand(0, 59), (int) $m[2], (int) $m[3], (int) $m[1]);
        } else {
            wp_send_json_error('Invalid kind');
        }

        $new_date = gmdate('Y-m-d H:i:s', $ts);
        $res = wp_update_post(array(
            'ID'            => $post_id,
            'post_date'     => $new_date,
            'post_date_gmt' => get_gmt_from_date($new_date),
            'edit_date'     => true,
        ), true);
        if (is_wp_error($res)) {
            wp_send_json_error($res->get_error_message());
        }

        $post   = get_post($post_id);
        $labels = array('publish' => 'Published', 'future' => 'Scheduled');
        $label  = isset($labels[$post->post_status]) ? $labels[$post->post_status] : 'Last Modified';
        wp_send_json_success(array(
            'post_id' => $post_id,
            'date'    => $post->post_date,
            'status'  => $post->post_status,
            'label'   => $label,
            'display' => sprintf(
                '%s at %s',
                mysql2date(get_option('date_format'), $post->post_date),
                mysql2date(get_option('time_format'), $post->post_date)
            ),
        ));
    }

    /** CSS + JS for the Date Switch panel, Posts list screen only. */
    public function veyra_ds_footer_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'post') {
            return;
        }
        $nonce = wp_create_nonce('veyra_elephant_tools');
        // Each mode: one past range, NOW, three future ranges. Medium ≈ 2× Fast,
        // Slow ≈ 2× Medium.
        $modes = array(
            'fast'   => array('label' => 'Fast Mode', 'past' => array(2, 4),  'future' => array(array(2, 4),  array(4, 7),   array(7, 12))),
            'medium' => array('label' => 'Medium',    'past' => array(4, 8),  'future' => array(array(4, 8),  array(8, 14),  array(14, 24))),
            'slow'   => array('label' => 'Slow',      'past' => array(8, 16), 'future' => array(array(8, 16), array(16, 28), array(28, 48))),
        );
        // "Cus." dropdown: wider windows, mostly further out.
        $custom = array(
            'future' => array(array(12, 20), array(20, 30), array(30, 45), array(45, 60), array(60, 90), array(90, 120), array(120, 180), array(180, 270), array(270, 365)),
            'past'   => array(array(4, 7), array(7, 14), array(14, 30), array(30, 60), array(60, 120), array(120, 365)),
        );
        ?>
        <style>
            .veyra-ds-panel {
                display: none; margin-top: 4px; padding: 6px; background: #fff;
                border: 1px solid #c3c4c7; border-radius: 3px; box-shadow: 0 2px 6px rgba(0,0,0,0.12);
                position: relative; z-index: 5;
            }
            .veyra-ds-panel.is-open { display: block; }
            .veyra-ds-tabs, .veyra-ds-btns { display: flex; }
            .veyra-ds-tabs { margin-bottom: 5px; }
            /* Joined: neighbours share one border, no gap. */
            .veyra-ds-tab, .veyra-ds-btn {
                margin: 0; border: 1px solid #8c8f94; background: #f6f7f7; color: #2c3338;
                font-size: 11px; font-weight: 600; line-height: 1; cursor: pointer; border-radius: 0;
            }
            .veyra-ds-tab { padding: 4px 10px; }
            /* nowrap + no grow/shrink: the Title column can be narrow, and a wrapped
               "NO/W" or "7-/12" is unreadable. */
            .veyra-ds-tab, .veyra-ds-btn { white-space: nowrap; flex: 0 0 auto; }
            .veyra-ds-btn { padding: 6px 7px; min-width: 0; }
            .veyra-ds-tab + .veyra-ds-tab, .veyra-ds-btn + .veyra-ds-btn { border-left-width: 0; }
            .veyra-ds-tab:first-child, .veyra-ds-btn:first-child { border-radius: 3px 0 0 3px; }
            .veyra-ds-tab:last-child,  .veyra-ds-btn:last-child  { border-radius: 0 3px 3px 0; }
            .veyra-ds-tab.is-active { background: #2271b1; border-color: #2271b1; color: #fff; }
            .veyra-ds-btn:hover { background: #e5f0fa; }
            .veyra-ds-btn.is-past { color: #8a4b00; }
            .veyra-ds-btn.is-now  { color: #00701c; }
            .veyra-ds-btn.is-future { color: #135e96; }
            .veyra-ds-btn.is-busy, .veyra-ds-panel.is-busy .veyra-ds-btn { opacity: 0.5; cursor: wait; }
            .veyra-ds-status { margin-top: 5px; font-size: 11px; font-weight: 600; min-height: 1em; }
            /* position:fixed so it isn't clipped by the list table. */
            .veyra-ds-drop {
                display: none; position: fixed; z-index: 100015; min-width: 190px; max-height: 340px; overflow-y: auto;
                background: #fff; border: 1px solid #8c8f94; border-radius: 4px; box-shadow: 0 6px 18px rgba(0,0,0,0.2);
                padding: 4px 0; font-size: 12px;
            }
            .veyra-ds-drop.is-open { display: block; }
            .veyra-ds-drop-h { padding: 4px 10px 2px; font-size: 10px; font-weight: 700; text-transform: uppercase; color: #646970; }
            .veyra-ds-drop-item { display: block; width: 100%; text-align: left; padding: 4px 12px; border: 0; background: none; cursor: pointer; font-size: 12px; }
            .veyra-ds-drop-item:hover { background: #2271b1; color: #fff; }
            .veyra-ds-drop-exact { display: flex; gap: 4px; padding: 6px 10px 4px; border-top: 1px solid #dcdcde; margin-top: 4px; }
            .veyra-ds-drop-exact input { font-size: 12px; min-height: 26px; flex: 1; }
        </style>
        <script>
        (function(){
            var AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var NONCE = <?php echo wp_json_encode($nonce); ?>;
            var MODES = <?php echo wp_json_encode($modes); ?>;
            var CUSTOM = <?php echo wp_json_encode($custom); ?>;
            var mode = 'fast';
            try { var saved = localStorage.getItem('veyra_ds_mode'); if (saved && MODES[saved]) mode = saved; } catch (e) {}

            var panel = null, drop = null, current = null, hideTimer = null, dropTimer = null;

            function btnHtml(cls, attrs, label, title){
                return '<button type="button" class="veyra-ds-btn ' + cls + '" ' + attrs + ' title="' + title + '">' + label + '</button>';
            }
            function renderButtons(){
                var m = MODES[mode], h = '';
                h += btnHtml('is-past', 'data-kind="range" data-dir="past" data-min="' + m.past[0] + '" data-max="' + m.past[1] + '"',
                             m.past[0] + '-' + m.past[1], 'random time ' + m.past[0] + '–' + m.past[1] + ' days ago');
                h += btnHtml('is-now', 'data-kind="now"', 'NOW', 'right now');
                m.future.forEach(function(r){
                    h += btnHtml('is-future', 'data-kind="range" data-dir="future" data-min="' + r[0] + '" data-max="' + r[1] + '"',
                                 r[0] + '-' + r[1], 'random time ' + r[0] + '–' + r[1] + ' days from now');
                });
                h += btnHtml('veyra-ds-custom', '', 'Cus.', 'more ranges / pick a date');
                panel.querySelector('.veyra-ds-btns').innerHTML = h;
                panel.querySelectorAll('.veyra-ds-tab').forEach(function(t){
                    t.classList.toggle('is-active', t.getAttribute('data-mode') === mode);
                });
            }
            function buildPanel(){
                panel = document.createElement('div');
                panel.className = 'veyra-ds-panel';
                var tabs = '';
                Object.keys(MODES).forEach(function(k){
                    tabs += '<button type="button" class="veyra-ds-tab" data-mode="' + k + '">' + MODES[k].label + '</button>';
                });
                panel.innerHTML = '<div class="veyra-ds-tabs">' + tabs + '</div><div class="veyra-ds-btns"></div><div class="veyra-ds-status"></div>';
                renderButtons();

                drop = document.createElement('div');
                drop.className = 'veyra-ds-drop';
                var d = '<div class="veyra-ds-drop-h">Future</div>';
                CUSTOM.future.forEach(function(r){
                    d += '<button type="button" class="veyra-ds-drop-item" data-kind="range" data-dir="future" data-min="' + r[0] + '" data-max="' + r[1] + '">' + r[0] + '–' + r[1] + ' days ahead</button>';
                });
                d += '<div class="veyra-ds-drop-h">Past</div>';
                CUSTOM.past.forEach(function(r){
                    d += '<button type="button" class="veyra-ds-drop-item" data-kind="range" data-dir="past" data-min="' + r[0] + '" data-max="' + r[1] + '">' + r[0] + '–' + r[1] + ' days ago</button>';
                });
                d += '<div class="veyra-ds-drop-exact"><input type="datetime-local" class="veyra-ds-exact-val"><button type="button" class="button button-small veyra-ds-exact-go">Set</button></div>';
                drop.innerHTML = d;
                document.body.appendChild(drop);

                [panel, drop].forEach(function(el){
                    el.addEventListener('mouseenter', keep);
                    el.addEventListener('mouseleave', scheduleHide);
                });
                panel.addEventListener('click', onPanelClick);
                drop.addEventListener('click', onDropClick);
                panel.addEventListener('mouseover', function(e){
                    if (e.target.closest('.veyra-ds-custom')) { openDrop(e.target.closest('.veyra-ds-custom')); }
                    else if (e.target.closest('.veyra-ds-btn, .veyra-ds-tab')) { scheduleDropHide(); }
                });
                drop.addEventListener('mouseenter', function(){ clearTimeout(dropTimer); });
                drop.addEventListener('mouseleave', scheduleDropHide);
            }

            function openFor(trigger){
                if (!panel) buildPanel();
                keep();
                // closest('td, th'): current WordPress renders the primary (Title) cell as a
                // <th scope="row">, so looking for a <td> alone finds nothing.
                var td = trigger.closest('td, th');
                if (!td) return;
                if (current !== trigger) {
                    current = trigger;
                    panel.setAttribute('data-post', trigger.getAttribute('data-post'));
                    panel.querySelector('.veyra-ds-status').textContent = '';
                    closeDrop();
                    // Directly below the row-actions bar, full width of the cell.
                    var bar = trigger.closest('.row-actions');
                    (bar || td.lastElementChild).insertAdjacentElement('afterend', panel);
                }
                panel.classList.add('is-open');
            }
            function keep(){ clearTimeout(hideTimer); }
            function scheduleHide(){
                clearTimeout(hideTimer);
                hideTimer = setTimeout(function(){
                    if (panel && panel.classList.contains('is-busy')) return;
                    if (panel) panel.classList.remove('is-open');
                    closeDrop();
                    current = null;
                }, 350);
            }
            function openDrop(btn){
                clearTimeout(dropTimer);
                var r = btn.getBoundingClientRect();
                drop.classList.add('is-open');
                var h = drop.offsetHeight;
                var top = r.bottom + 2;
                if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 2);
                drop.style.top = top + 'px';
                drop.style.left = Math.min(r.left, window.innerWidth - drop.offsetWidth - 8) + 'px';
            }
            function closeDrop(){ if (drop) drop.classList.remove('is-open'); }
            function scheduleDropHide(){
                clearTimeout(dropTimer);
                dropTimer = setTimeout(closeDrop, 300);
            }

            function onPanelClick(e){
                e.preventDefault();
                var tab = e.target.closest('.veyra-ds-tab');
                if (tab) {
                    mode = tab.getAttribute('data-mode');
                    try { localStorage.setItem('veyra_ds_mode', mode); } catch (err) {}
                    renderButtons();
                    return;
                }
                var b = e.target.closest('.veyra-ds-btn');
                if (!b) return;
                if (b.classList.contains('veyra-ds-custom')) { openDrop(b); return; }
                apply(b.dataset);
            }
            function onDropClick(e){
                var item = e.target.closest('.veyra-ds-drop-item');
                if (item) { e.preventDefault(); closeDrop(); apply(item.dataset); return; }
                if (e.target.closest('.veyra-ds-exact-go')) {
                    e.preventDefault();
                    var v = drop.querySelector('.veyra-ds-exact-val').value;
                    if (!v) return;
                    closeDrop();
                    apply({ kind: 'exact', value: v });
                }
            }

            function apply(spec){
                if (!panel || panel.classList.contains('is-busy')) return;
                var postId = panel.getAttribute('data-post');
                var status = panel.querySelector('.veyra-ds-status');
                panel.classList.add('is-busy');
                status.style.color = '#787c82'; status.textContent = 'saving…';
                var body = new URLSearchParams();
                body.append('action', 'veyra_date_switch');
                body.append('nonce', NONCE);
                body.append('post_id', postId);
                ['kind', 'dir', 'min', 'max', 'value'].forEach(function(k){ if (spec[k] !== undefined) body.append(k, spec[k]); });
                fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res || !res.success) throw new Error((res && res.data) ? res.data : 'save failed');
                        var d = res.data;
                        status.style.color = '#00701c';
                        status.textContent = '✓ ' + d.date + ' — ' + d.label;
                        var row = document.getElementById('post-' + d.post_id);
                        if (row) {
                            var cell = row.querySelector('td.column-date');
                            if (cell) { cell.innerHTML = ''; cell.appendChild(document.createTextNode(d.label)); cell.appendChild(document.createElement('br')); cell.appendChild(document.createTextNode(d.display)); }
                            row.className = row.className.replace(/\bstatus-\S+/, 'status-' + d.status);
                        }
                    })
                    .catch(function(err){ status.style.color = '#dc3232'; status.textContent = err.message; })
                    .finally(function(){ panel.classList.remove('is-busy'); });
            }

            document.addEventListener('mouseover', function(e){
                var t = e.target.closest ? e.target.closest('.veyra-ds-trigger') : null;
                if (t) openFor(t);
            });
            document.addEventListener('mouseout', function(e){
                var t = e.target.closest ? e.target.closest('.veyra-ds-trigger') : null;
                if (t && !(e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('.veyra-ds-trigger'))) scheduleHide();
            });
            // Click opens it too (touch screens, keyboard).
            document.addEventListener('click', function(e){
                var t = e.target.closest ? e.target.closest('.veyra-ds-trigger') : null;
                if (t) { e.preventDefault(); openFor(t); }
            });
        })();
        </script>
        <?php
    }

    /* ---------------------------------------------------------------------
     * boldcrypt (Posts list column + f400 scan)
     *
     * Colour-codes each post_title: niche terms, cities and states each get a
     * <b> with their own class (niche / city / state), stored per post in post
     * meta so the list never re-parses titles on render.
     *
     * Source lists (data/boldcrypt/):
     *   niches.php — per-niche bases (expanded with prefixes / suffixes) + fixed terms
     *   cities.php — top 2,000 US cities by 2024 Census estimate + context_only words
     *   states.php — 50 states + DC, name and postal code
     *
     * Matching is word-based: the title is split into word tokens and, at each
     * position, the LONGEST phrase found in any list wins ("Kansas City" beats
     * "Kansas", "termite control" beats "termite"). Tokens of a phrase must be
     * separated by whitespace only, so "Plumbing - Austin" is never one phrase.
     * Names match case-insensitively; postal codes only as an exact uppercase
     * word so "In" / "Or" / "Me" don't light up. Equal-length ties go niche >
     * state > city, except a city directly followed by a state code wins
     * ("Washington DC", "New York NY").
     * ------------------------------------------------------------------- */

    const VEYRA_BC_META = '_veyra_boldcrypted';
    const VEYRA_BC_LAST_SCAN_OPTION = 'veyra_boldcrypt_last_scan';
    const VEYRA_BC_BATCH_SIZE = 200;

    /** Word tokens with byte offsets. "St." "O'Fallon" "Winston-Salem" "a.c." stay whole; "&" is its own token. */
    private function veyra_bc_tokens($text) {
        preg_match_all("/[\\p{L}\\p{N}]+(?:['\\x{2019}.\\-][\\p{L}\\p{N}]+)*\\.?|&/u", $text, $m, PREG_OFFSET_CAPTURE);
        $out = array();
        foreach ($m[0] as $hit) {
            $out[] = array('raw' => $hit[0], 'start' => $hit[1], 'end' => $hit[1] + strlen($hit[0]), 'norm' => $this->veyra_bc_norm_token($hit[0]));
        }
        return $out;
    }

    private function veyra_bc_norm_token($raw) {
        $t = str_replace(array("\xE2\x80\x99", '.'), array("'", ''), $raw);
        return function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
    }

    /** A list phrase in the same normalized form the matcher builds from titles. */
    private function veyra_bc_norm_phrase($phrase) {
        return implode(' ', wp_list_pluck($this->veyra_bc_tokens($phrase), 'norm'));
    }

    /** Pluralize the last word of a phrase (cheap English rules; extra keys are harmless). */
    private function veyra_bc_plural($phrase) {
        if (preg_match('/(s|x|z|ch|sh)$/', $phrase)) {
            return $phrase . 'es';
        }
        if (preg_match('/[^aeiou]y$/', $phrase)) {
            return substr($phrase, 0, -1) . 'ies';
        }
        return $phrase . 's';
    }

    /**
     * Build (once per request) the lookup maps from the data files.
     *
     * Niche phrases are NOT pre-expanded (bases × prefixes × suffixes would be over
     * a million keys); the matcher composes [prefix] base [suffix] at scan time
     * from four small maps instead.
     */
    private function veyra_bc_dictionary() {
        static $dict = null;
        if ($dict !== null) {
            return $dict;
        }
        $dir    = plugin_dir_path(__FILE__) . 'data/boldcrypt/';
        $niches = include $dir . 'niches.php';
        $cities = include $dir . 'cities.php';
        $states = include $dir . 'states.php';

        $dict = array(
            'exact' => array(), 'base' => array(), 'prefix' => array(), 'suffix' => array(),
            'state' => array(), 'city' => array(), 'codes' => array(), 'context_only' => array(), 'max' => 1,
        );
        $add = function ($map, $phrase, $plural = false) use (&$dict) {
            foreach ($plural ? array($phrase, $this->veyra_bc_plural($phrase)) : array($phrase) as $p) {
                $key = $this->veyra_bc_norm_phrase($p);
                if ($key !== '') {
                    $dict[$map][$key] = true;
                    $dict['max'] = max($dict['max'], substr_count($key, ' ') + 1);
                }
            }
        };

        foreach ($niches['niches'] as $niche) {
            foreach ((array) (isset($niche['terms']) ? $niche['terms'] : array()) as $t) {
                $add('exact', $t, true);
            }
            foreach ((array) (isset($niche['bases']) ? $niche['bases'] : array()) as $t) {
                $add('base', $t, true);
            }
        }
        foreach ($niches['prefixes'] as $t) {
            $add('prefix', $t);
        }
        foreach ($niches['suffixes'] as $t) {
            $add('suffix', $t, true);
        }
        foreach ($states as $code => $name) {
            $add('state', $name);
            $dict['codes'][$code] = true;
        }
        foreach ($cities['cities'] as $name => $code) {
            $add('city', $name);
        }
        foreach ($cities['context_only'] as $name) {
            $dict['context_only'][$this->veyra_bc_norm_phrase($name)] = true;
        }
        return $dict;
    }

    /**
     * Phrase keys starting at each token: $keys[$i][$n] = normalized text of
     * tokens $i .. $i+$n-1, only while they're whitespace-joined.
     */
    private function veyra_bc_keys($tokens, $text, $max) {
        $keys  = array();
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $key = '';
            for ($n = 1; $n <= $max && $i + $n <= $count; $n++) {
                if ($n > 1 && !$this->veyra_bc_joined($tokens[$i + $n - 2], $tokens[$i + $n - 1], $text)) {
                    break;
                }
                $key .= ($n > 1 ? ' ' : '') . $tokens[$i + $n - 1]['norm'];
                $keys[$i][$n] = $key;
            }
        }
        return $keys;
    }

    /** Lengths $n for which the phrase at $i is in $map. */
    private function veyra_bc_lens($keys, $i, $map) {
        $out = array();
        if (isset($keys[$i])) {
            foreach ($keys[$i] as $n => $key) {
                if (isset($map[$key])) {
                    $out[] = $n;
                }
            }
        }
        return $out;
    }

    /**
     * Longest niche phrase at $i, in tokens (0 = none): an exact term, or
     * [prefix] base [suffix]. Each part must sit directly after the last.
     */
    private function veyra_bc_niche_len($keys, $i, $dict) {
        $best = 0;
        foreach ($this->veyra_bc_lens($keys, $i, $dict['exact']) as $n) {
            $best = max($best, $n);
        }
        $starts = array(0);
        foreach ($this->veyra_bc_lens($keys, $i, $dict['prefix']) as $p) {
            $starts[] = $p;
        }
        foreach ($starts as $p) {
            foreach ($this->veyra_bc_lens($keys, $i + $p, $dict['base']) as $b) {
                $best = max($best, $p + $b);
                foreach ($this->veyra_bc_lens($keys, $i + $p + $b, $dict['suffix']) as $sfx) {
                    $best = max($best, $p + $b + $sfx);
                }
            }
        }
        return $best;
    }

    /** Is token $t an exact uppercase state code ("TX", "D.C.")? */
    private function veyra_bc_is_code($t, $dict) {
        $c = str_replace('.', '', $t['raw']);
        return strlen($c) === 2 && ctype_upper($c) && isset($dict['codes'][$c]);
    }

    /** Does a state name or code start at token $i? */
    private function veyra_bc_state_at($tokens, $i, $text, $dict) {
        if (!isset($tokens[$i])) {
            return false;
        }
        if ($this->veyra_bc_is_code($tokens[$i], $dict)) {
            return true;
        }
        $key = '';
        for ($n = 0; $n < 3 && isset($tokens[$i + $n]); $n++) {
            if ($n && !$this->veyra_bc_joined($tokens[$i + $n - 1], $tokens[$i + $n], $text)) {
                break;
            }
            $key .= ($n ? ' ' : '') . $tokens[$i + $n]['norm'];
            if (isset($dict['state'][$key])) {
                return true;
            }
        }
        return false;
    }

    /** Only whitespace between two tokens? */
    private function veyra_bc_joined($a, $b, $text) {
        return trim(substr($text, $a['end'], $b['start'] - $a['end'])) === '';
    }

    /** Wrap matches in one plain-text chunk; returns escaped HTML. */
    private function veyra_bc_mark_text($text, $dict) {
        $tokens = $this->veyra_bc_tokens($text);
        $count  = count($tokens);
        $html   = '';
        $pos    = 0;
        $places = array('in', 'near', 'around', 'serving', 'servicing', 'throughout', 'of');

        $keys   = $this->veyra_bc_keys($tokens, $text, $dict['max']);

        for ($i = 0; $i < $count; ) {
            $hit   = null;
            $cands = array(); // $cands[$n][kind] = true
            if ($len = $this->veyra_bc_niche_len($keys, $i, $dict)) {
                $cands[$len]['niche'] = true;
            }
            foreach (array('state', 'city') as $kind) {
                foreach ($this->veyra_bc_lens($keys, $i, $dict[$kind]) as $len) {
                    $cands[$len][$kind] = true;
                }
            }
            if ($n = ($cands ? max(array_keys($cands)) : 0)) {
                $kinds = $cands[$n];
                $next  = $i + $n;
                if (isset($kinds['city'])) {
                    $followed_by_code = isset($tokens[$next]) && $this->veyra_bc_is_code($tokens[$next], $dict);
                    if ($followed_by_code) {
                        $hit = 'city';
                    }
                    $ckey = implode(' ', wp_list_pluck(array_slice($tokens, $i, $n), 'norm'));
                    if (isset($dict['context_only'][$ckey]) && !$hit) {
                        $prev_ok  = $i > 0 && in_array($tokens[$i - 1]['norm'], $places, true);
                        $comma_ok = (bool) preg_match('/^\s*,/', substr($text, $tokens[$next - 1]['end']));
                        if (!$prev_ok && !$comma_ok && !$this->veyra_bc_state_at($tokens, $next, $text, $dict)) {
                            unset($kinds['city']);
                        }
                    }
                }
                if (!$hit) {
                    foreach (array('niche', 'state', 'city') as $kind) {
                        if (isset($kinds[$kind])) {
                            $hit = $kind;
                            break;
                        }
                    }
                }
            }
            if (!$hit && $this->veyra_bc_is_code($tokens[$i], $dict)) {
                $hit = 'state';
                $n   = 1;
            }
            if (!$hit) {
                $i++;
                continue;
            }
            $start = $tokens[$i]['start'];
            $end   = $tokens[$i + $n - 1]['end'];
            $html .= esc_html(substr($text, $pos, $start - $pos))
                   . '<b class="' . $hit . '">' . esc_html(substr($text, $start, $end - $start)) . '</b>';
            $pos = $end;
            $i  += $n;
        }
        return $html . esc_html(substr($text, $pos));
    }

    /** Boldcrypt a whole title. Existing HTML tags in the title pass through untouched. */
    public function veyra_bc_boldcrypt($title) {
        $dict  = $this->veyra_bc_dictionary();
        $parts = preg_split('/(<[^>]*>)/', (string) $title, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out   = '';
        foreach ($parts as $k => $part) {
            $out .= ($k % 2) ? $part : $this->veyra_bc_mark_text(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $dict);
        }
        return $out;
    }

    /** Plain text of a title or of stored boldcrypt HTML, for staleness checks. */
    private function veyra_bc_plain($html) {
        return trim(html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function veyra_bc_add_column($columns) {
        $columns['veyra_bc'] = '<span class="veyra-bc-th">boldcrypted</span>';
        return $columns;
    }

    public function veyra_bc_render_column($column, $post_id) {
        if ($column !== 'veyra_bc') {
            return;
        }
        $html = get_post_meta($post_id, self::VEYRA_BC_META, true);
        if ($html === '') {
            echo '<span class="veyra-bc-empty">not scanned</span>';
            return;
        }
        echo '<span class="veyra-bc">' . wp_kses($html, array('b' => array('class' => true))) . '</span>';
        if ($this->veyra_bc_plain($html) !== $this->veyra_bc_plain(get_post_field('post_title', $post_id))) {
            echo ' <span class="veyra-bc-stale" title="the title changed since the last f400 scan">stale</span>';
        }
    }

    /** AJAX: boldcrypt one batch of posts. POST offset=<n>. */
    public function ajax_bc_scan_batch() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        global $wpdb;
        $offset = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;
        // Every real post: published, drafts, scheduled, pending, private. Not trash,
        // auto-drafts or revisions.
        $where = "post_type = 'post' AND post_status NOT IN ('trash', 'auto-draft', 'inherit')";
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}");
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE {$where} ORDER BY ID ASC LIMIT %d OFFSET %d",
            self::VEYRA_BC_BATCH_SIZE,
            $offset
        ));
        foreach ($rows as $row) {
            update_post_meta((int) $row->ID, self::VEYRA_BC_META, wp_slash($this->veyra_bc_boldcrypt($row->post_title)));
        }
        $next = $offset + count($rows);
        $done = !$rows || $next >= $total;
        if ($done) {
            update_option(self::VEYRA_BC_LAST_SCAN_OPTION, time(), false);
        }
        wp_send_json_success(array('next' => $next, 'total' => $total, 'done' => $done));
    }

    /** Column colours + the f400 button, Posts list screen only. */
    public function veyra_bc_footer_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'post') {
            return;
        }
        $nonce = wp_create_nonce('veyra_elephant_tools');
        $last  = (int) get_option(self::VEYRA_BC_LAST_SCAN_OPTION, 0);
        $last_label = $last ? 'last scan: ' . wp_date('Y-m-d H:i', $last) : 'never scanned';
        ?>
        <style>
            /* 8.4% (was 10%) hands part of link_synopsis's +120px over (see its rule). */
            .fixed .column-veyra_bc { width: 8.4%; }
            .veyra-bc-th { font-weight: 700; }
            .veyra-bc b { font-weight: 700; }
            /* One colour per kind; state names and postal codes share theirs. */
            .veyra-bc b.niche { color: #15803d; }
            .veyra-bc b.city  { color: #1d4ed8; }
            .veyra-bc b.state { color: #9333ea; }
            .veyra-bc-empty { color: #a7aaad; font-style: italic; font-size: 11px; }
            .veyra-bc-stale { font-size: 10px; font-weight: 700; color: #b45309; text-transform: uppercase; }
            .veyra-bc-wrap {
                display: inline-flex; align-items: center; gap: 6px; margin-left: 10px; vertical-align: middle;
                padding: 2px 6px; border: 1px solid #8c8f94; border-radius: 4px; background: #fff; font-size: 12px;
            }
            .veyra-bc-last { color: #646970; font-style: italic; }
            .veyra-bc-status { font-weight: 600; }
            .veyra-bc-legend b { font-weight: 700; margin-left: 4px; }
            .veyra-bc-legend .niche { color: #15803d; }
            .veyra-bc-legend .city  { color: #1d4ed8; }
            .veyra-bc-legend .state { color: #9333ea; }
        </style>
        <script>
        (function(){
            var AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var NONCE = <?php echo wp_json_encode($nonce); ?>;
            var LAST = <?php echo wp_json_encode($last_label); ?>;

            function build(){
                if (document.querySelector('.veyra-bc-wrap')) return;
                var anchor = document.querySelector('.veyra-lsyn-refresh-wrap')
                          || document.querySelector('.veyra-reading-ctl')
                          || document.querySelector('.wrap .page-title-action');
                if (!anchor) return;
                var wrap = document.createElement('span');
                wrap.className = 'veyra-bc-wrap';
                wrap.innerHTML = '<button type="button" class="button veyra-bc-btn">f400 - boldcrypt scan</button>'
                    + '<span class="veyra-bc-legend"><b class="niche">niche</b><b class="city">city</b><b class="state">state</b></span>'
                    + '<span class="veyra-bc-last"></span><span class="veyra-bc-status"></span>';
                anchor.insertAdjacentElement('afterend', wrap);
                wrap.querySelector('.veyra-bc-last').textContent = LAST;
                var btn = wrap.querySelector('.veyra-bc-btn');
                var status = wrap.querySelector('.veyra-bc-status');

                btn.addEventListener('click', function(){
                    btn.disabled = true;
                    status.style.color = '#787c82';
                    status.textContent = 'scanning…';
                    (function step(offset){
                        var body = new URLSearchParams();
                        body.append('action', 'veyra_bc_scan_batch');
                        body.append('nonce', NONCE);
                        body.append('offset', offset);
                        fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                            .then(function(r){ return r.json(); })
                            .then(function(res){
                                if (!res || !res.success) throw new Error((res && res.data) ? res.data : 'scan failed');
                                var d = res.data;
                                status.textContent = d.next + ' / ' + d.total;
                                if (!d.done) { step(d.next); return; }
                                status.style.color = '#46b450';
                                status.textContent = '✓ ' + d.total + ' titles scanned — reloading…';
                                setTimeout(function(){ window.location.reload(); }, 1200);
                            })
                            .catch(function(err){
                                status.style.color = '#dc3232'; status.textContent = err.message; btn.disabled = false;
                            });
                    })(0);
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', build);
            } else {
                build();
            }
        })();
        </script>
        <?php
    }

    /* ---------------------------------------------------------------------
     * rocket_chamber (Posts list)
     *
     * Same look as the rocket chamber on tregnar's /sitejar page, but only the
     * two systems that make sense here:
     *
     *   row pagination    — server-side, WordPress's own: Rows/page writes the
     *                       user's native "Number of items per page" screen
     *                       option (edit_post_per_page) and reloads; Row page
     *                       moves ?paged=N. Prev/next wrap around (last -> 1).
     *   column pagination — client-side: Checkbox and Title stay put (sticky);
     *                       every other column that Screen Options leaves
     *                       visible is paged Cols/page at a time. Hidden with a
     *                       generated stylesheet so rows added later (Quick Edit)
     *                       follow too. Choice remembered per browser.
     * ------------------------------------------------------------------- */

    /** AJAX: set the native per-page screen option for the Posts list. */
    public function ajax_rocket_rows_per_page() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        $n = isset($_POST['value']) ? intval($_POST['value']) : 0;
        if ($n < 1) {
            wp_send_json_error('Invalid value');
        }
        update_user_meta(get_current_user_id(), 'edit_post_per_page', $n);
        wp_send_json_success(array('value' => $n));
    }

    public function veyra_rocket_footer_assets() {
        global $wp_list_table;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'post' || !is_object($wp_list_table)) {
            return;
        }
        $state = array(
            'perPage'    => (int) $wp_list_table->get_pagination_arg('per_page'),
            'total'      => (int) $wp_list_table->get_pagination_arg('total_items'),
            'totalPages' => max(1, (int) $wp_list_table->get_pagination_arg('total_pages')),
            'page'       => max(1, (int) $wp_list_table->get_pagenum()),
        );
        $nonce = wp_create_nonce('veyra_elephant_tools');
        ?>
        <style>
            .rocket_chamber_div { border: 1px solid #000; padding: 0; margin: 10px 0 8px; position: relative; background: #fff; }
            .rocket_chamber_div .rc-title {
                position: absolute; top: 4px; left: 4px; font-size: 16px; font-weight: 700;
                display: flex; align-items: center; gap: 6px; color: #000;
            }
            .rocket_chamber_div .rc-inner { margin-top: 24px; padding: 4px 8px 0; }
            .rocket_chamber_div table.rc-grid { border-collapse: collapse; }
            .rocket_chamber_div table.rc-grid td { border: 1px solid #000; padding: 4px; vertical-align: bottom; }
            .rocket_chamber_div .rc-head { font-size: 16px; display: flex; align-items: center; justify-content: center; gap: 8px; }
            .rocket_chamber_div .rc-head b { font-weight: 700; }
            .rocket_chamber_div .rc-head .rc-count { font-size: 14px; font-weight: 400; }
            .rocket_chamber_div .rc-bars { display: flex; align-items: flex-end; gap: 16px; }
            .rocket_chamber_div .rc-bar { display: flex; align-items: center; }
            .rocket_chamber_div .rc-label { font-size: 12px; color: #4b5563; margin-right: 8px; }
            .rocket_chamber_div .rc-group { display: inline-flex; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
            .rocket_chamber_div .rc-btn {
                position: relative; display: inline-flex; align-items: center; margin: 0 -1px 0 0;
                padding: 10px 8px; font-size: 14px; line-height: 20px; font-weight: 500; cursor: pointer;
                background: #fff; color: #374151; border: 1px solid #d1d5db; border-radius: 0; min-height: 0;
            }
            .rocket_chamber_div .rc-btn:first-child { border-top-left-radius: 4px; border-bottom-left-radius: 4px; }
            .rocket_chamber_div .rc-btn:last-child { border-top-right-radius: 4px; border-bottom-right-radius: 4px; margin-right: 0; }
            .rocket_chamber_div .rc-btn svg { width: 16px; height: 16px; color: #4b5563; }
            .rocket_chamber_div .rc-btn:focus { z-index: 10; outline: none; box-shadow: 0 0 0 2px #3b82f6; }
            /* Row bars: blue active, gray-300 borders, row page numbers semibold. */
            .rocket_chamber_div .rc-row .rc-btn:hover { background: #f9fafb; }
            .rocket_chamber_div .rc-row .rc-btn.is-active { background: #2563eb; color: #fff; border-color: #2563eb; z-index: 5; }
            .rocket_chamber_div .rc-row .rc-btn.is-active:hover { background: #1d4ed8; }
            .rocket_chamber_div .rc-row .rc-num { font-weight: 600; color: #111827; }
            /* Row page prev/next wheels are rounded-md (6px) in the original. */
            .rocket_chamber_div .rc-row nav .rc-btn:first-child { border-radius: 6px 0 0 6px; }
            .rocket_chamber_div .rc-row nav .rc-btn:last-child { border-radius: 0 6px 6px 0; }
            /* Column bars: yellow active with black border, gray-200 borders. */
            .rocket_chamber_div .rc-col .rc-group { box-shadow: none; }
            .rocket_chamber_div .rc-col .rc-btn { border-color: #e5e7eb; font-weight: 400; }
            .rocket_chamber_div .rc-col .rc-btn:hover { background: #e5e7eb; }
            .rocket_chamber_div .rc-col .rc-btn.is-active { background: #f8f782; color: #000; border-color: #000; z-index: 5; }
            .rocket_chamber_div .rc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        </style>
        <style id="veyra-colpage-css"></style>
        <script>
        (function(){
            var AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var NONCE = <?php echo wp_json_encode($nonce); ?>;
            var S = <?php echo wp_json_encode($state); ?>;
            // Counted from the DOM: the posts list table never fills ->items (it
            // renders from the global $wp_query), so the server can't report it.
            S.onPage = document.querySelectorAll('#the-list tr[id^="post-"]').length;
            var STICKY = ['cb', 'title'];
            var ROW_SIZES = [10, 25, 50, 100, 200, 'All'];
            var COL_SIZES = [6, 8, 10, 12, 15];
            var WHEEL_PREV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>';
            var WHEEL_NEXT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.13-9.36L23 10"/></svg>';
            var ROCKET = '<svg width="22" height="22" viewBox="0 0 24 24" fill="black" style="transform:rotate(15deg)">'
                + '<ellipse cx="12" cy="8" rx="3" ry="6" fill="black"/><path d="M12 2 L15 8 L9 8 Z" fill="black"/>'
                + '<path d="M9 12 L7 14 L9 16 Z" fill="black"/><path d="M15 12 L17 14 L15 16 Z" fill="black"/>'
                + '<path d="M10 14 L9 18 L10.5 16 L12 20 L13.5 16 L15 18 L14 14 Z" fill="black"/>'
                + '<circle cx="12" cy="6" r="1" fill="white"/></svg>';

            // colsChoice is a number from COL_SIZES or 'all'; colsPerPage() resolves it.
            var colsChoice = 12, colPage = 1;
            try {
                var c = localStorage.getItem('veyra_rc_cols_per_page');
                if (c === 'all') colsChoice = 'all';
                else if (COL_SIZES.indexOf(parseInt(c, 10)) !== -1) colsChoice = parseInt(c, 10);
                var p = parseInt(localStorage.getItem('veyra_rc_col_page'), 10);
                if (p > 0) colPage = p;
            } catch (e) {}
            var chamber;

            // --- row pagination (server-side) --------------------------------
            function goToPage(n){
                var url = new URL(window.location.href);
                if (n <= 1) url.searchParams.delete('paged'); else url.searchParams.set('paged', n);
                window.location.href = url.toString();
            }
            function setRowsPerPage(v){
                var n = v === 'All' ? Math.max(1, S.total) : v;
                var body = new URLSearchParams();
                body.append('action', 'veyra_rocket_rows_per_page');
                body.append('nonce', NONCE);
                body.append('value', n);
                fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res || !res.success) throw new Error((res && res.data) ? res.data : 'save failed');
                        goToPage(1);
                    })
                    .catch(function(err){ alert('Rows/page failed: ' + err.message); });
            }
            function rowBar1(){
                var h = '<div class="rc-bar rc-row"><span class="rc-label">Rows/page:</span><div class="rc-group" role="group">';
                ROW_SIZES.forEach(function(v){
                    var on = v === 'All' ? S.perPage >= S.total : S.perPage === v;
                    h += '<button type="button" class="rc-btn' + (on ? ' is-active' : '') + '" data-rows="' + v + '">' + v + '</button>';
                });
                return h + '</div></div>';
            }
            function rowBar2(){
                if (!S.total) return '';
                var h = '<div class="rc-bar rc-row"><span class="rc-label">Row page:</span><nav class="rc-group" aria-label="Pagination">';
                h += '<button type="button" class="rc-btn rc-wrap" data-rowpage="' + (S.page === 1 ? S.totalPages : S.page - 1) + '" title="previous (wraps to last)">' + WHEEL_PREV + '</button>';
                var start = Math.max(1, S.page - 2), end = Math.min(S.totalPages, start + 4);
                if (end - start < 4) start = Math.max(1, end - 4);
                for (var i = start; i <= end; i++) {
                    h += '<button type="button" class="rc-btn rc-num' + (i === S.page ? ' is-active' : '') + '" data-rowpage="' + i + '">' + i + '</button>';
                }
                h += '<button type="button" class="rc-btn rc-wrap" data-rowpage="' + (S.page === S.totalPages ? 1 : S.page + 1) + '" title="next (wraps to first)">' + WHEEL_NEXT + '</button>';
                return h + '</nav></div>';
            }

            // --- column pagination (client-side) -----------------------------
            /** Column keys in header order, minus the ones Screen Options hides. */
            function columnKeys(){
                var out = [];
                document.querySelectorAll('.wp-list-table thead tr > [id]').forEach(function(cell){
                    if (!cell.classList.contains('hidden')) out.push(cell.id);
                });
                return out;
            }
            function pagedKeys(){ return columnKeys().filter(function(k){ return STICKY.indexOf(k) === -1; }); }
            function colsPerPage(){ return colsChoice === 'all' ? Math.max(1, pagedKeys().length) : colsChoice; }
            function totalColPages(){
                return Math.max(1, Math.ceil(pagedKeys().length / colsPerPage()));
            }
            function applyColumns(){
                var keys = pagedKeys(), pages = totalColPages();
                if (colPage > pages) colPage = pages;
                var per = colsPerPage(), from = (colPage - 1) * per, show = keys.slice(from, from + per);
                var css = keys.filter(function(k){ return show.indexOf(k) === -1; })
                    .map(function(k){ return '.wp-list-table .column-' + k + '{display:none!important}'; }).join('\n');
                document.getElementById('veyra-colpage-css').textContent = css;
                try {
                    localStorage.setItem('veyra_rc_cols_per_page', colsChoice);
                    localStorage.setItem('veyra_rc_col_page', colPage);
                } catch (e) {}
                document.dispatchEvent(new CustomEvent('veyra:columns-changed'));
                return show.length;
            }
            function colBar1(){
                var h = '<div class="rc-bar rc-col"><span class="rc-label">Cols/page:</span><div class="rc-group">';
                COL_SIZES.forEach(function(v){
                    h += '<button type="button" class="rc-btn' + (colsChoice === v ? ' is-active' : '') + '" data-cols="' + v + '">' + v + '</button>';
                });
                h += '<button type="button" class="rc-btn' + (colsChoice === 'all' ? ' is-active' : '') + '" data-cols="all">ALL</button>';
                return h + '</div></div>';
            }
            function colBar2(){
                var pages = totalColPages();
                if (pages <= 1) return '';
                var h = '<div class="rc-bar rc-col"><span class="rc-label">Col page:</span><div class="rc-group">';
                h += '<button type="button" class="rc-btn" data-colpage="1"' + (colPage === 1 ? ' disabled' : '') + '>≪</button>';
                h += '<button type="button" class="rc-btn" data-colpage="' + (colPage === 1 ? pages : colPage - 1) + '" title="previous (wraps to last)">' + WHEEL_PREV + '</button>';
                var first = Math.max(1, Math.min(pages - 4, colPage - 2));
                for (var i = 0; i < Math.min(5, pages); i++) {
                    var n = first + i;
                    h += '<button type="button" class="rc-btn' + (n === colPage ? ' is-active' : '') + '" data-colpage="' + n + '">' + n + '</button>';
                }
                h += '<button type="button" class="rc-btn" data-colpage="' + (colPage === pages ? 1 : colPage + 1) + '" title="next (wraps to first)">' + WHEEL_NEXT + '</button>';
                h += '<button type="button" class="rc-btn" data-colpage="' + pages + '"' + (colPage === pages ? ' disabled' : '') + '>≫</button>';
                return h + '</div></div>';
            }

            function render(){
                var shown = applyColumns();
                chamber.querySelector('.rc-row-count').innerHTML = 'Showing <b>' + S.onPage + '</b> of <b>' + S.total + '</b> results';
                chamber.querySelector('.rc-col-count').innerHTML = 'Showing <b>' + shown + '</b> non-sticky columns of <b>' + pagedKeys().length + '</b> columns';
                chamber.querySelector('.rc-row-bars').innerHTML = rowBar1() + rowBar2();
                chamber.querySelector('.rc-col-bars').innerHTML = colBar1() + colBar2();
            }

            function build(){
                var table = document.querySelector('.wp-list-table');
                if (!table || document.querySelector('.rocket_chamber_div')) return;
                chamber = document.createElement('div');
                chamber.className = 'rocket_chamber_div';
                chamber.innerHTML = '<div class="rc-title">' + ROCKET + 'rocket_chamber</div>'
                    + '<div class="rc-inner"><table class="rc-grid"><tbody>'
                    + '<tr><td><div class="rc-head"><b>row pagination</b><span class="rc-count rc-row-count"></span></div></td>'
                    +     '<td><div class="rc-head"><b>column pagination</b><span class="rc-count rc-col-count"></span></div></td></tr>'
                    + '<tr><td><div class="rc-bars rc-row-bars"></div></td><td><div class="rc-bars rc-col-bars"></div></td></tr>'
                    + '</tbody></table></div>';
                var nav = document.querySelector('#posts-filter .tablenav.top');
                (nav || table).parentNode.insertBefore(chamber, nav || table);

                chamber.addEventListener('click', function(e){
                    var b = e.target.closest('button');
                    if (!b || b.disabled) return;
                    e.preventDefault();
                    if (b.hasAttribute('data-rows')) {
                        var v = b.getAttribute('data-rows');
                        setRowsPerPage(v === 'All' ? 'All' : parseInt(v, 10));
                    } else if (b.hasAttribute('data-rowpage')) {
                        var n = parseInt(b.getAttribute('data-rowpage'), 10);
                        if (n !== S.page) goToPage(n);
                    } else if (b.hasAttribute('data-cols')) {
                        var c = b.getAttribute('data-cols');
                        colsChoice = c === 'all' ? 'all' : parseInt(c, 10);
                        colPage = 1;
                        render();
                    } else if (b.hasAttribute('data-colpage')) {
                        colPage = parseInt(b.getAttribute('data-colpage'), 10);
                        render();
                    }
                });
                // Screen Options column toggles change what's pageable.
                document.addEventListener('click', function(e){
                    if (e.target.closest && (e.target.closest('.hide-column-tog') || e.target.closest('.veyra-lsyn-cols input[data-col]'))) setTimeout(render, 0);
                });
                render();
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', build);
            } else {
                build();
            }
        })();
        </script>
        <?php
    }

    /** AJAX: save the "Blog pages show at most" setting (posts_per_page). */
    public function ajax_save_posts_per_page() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $n = isset($_POST['value']) ? intval($_POST['value']) : 0;
        if ($n < 1) {
            wp_send_json_error('Value must be a whole number of 1 or greater.');
        }
        update_option('posts_per_page', $n);
        wp_send_json_success(array('value' => $n));
    }

    /**
     * On the Posts list screen: inject a "Blog pages show at most" controller next
     * to the Add Post button, and insert a bold "ROLLED-OFF POSTS BELOW" separator
     * row into the list after the Nth published post (N = posts_per_page).
     */
    public function veyra_reading_controls_footer_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'post') {
            return;
        }
        $per_page    = (int) get_option('posts_per_page', 10);
        $can_edit    = current_user_can('manage_options');
        $autocal_on  = $this->veyra_autocal_is_enabled();
        $nonce       = wp_create_nonce('veyra_elephant_tools');
        ?>
        <style>
            .veyra-reading-ctl {
                display: inline-flex; align-items: center; gap: 6px; margin-left: 10px;
                vertical-align: middle; font-size: 13px;
                background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; padding: 3px 8px;
            }
            .veyra-reading-ctl input[type="number"] { width: 64px; }
            .veyra-reading-ctl .veyra-reading-status { color: #46b450; font-weight: 600; }
            /* Divider so the auto-calibrate switch reads as its own control rather
               than as part of the Save button's group. The switch itself reuses
               .veyra-flag-toggle, printed by veyra_flag_toggle_footer_assets() on
               this same screen. */
            .veyra-reading-ctl .veyra-autocal {
                display: inline-flex; align-items: center; gap: 6px;
                margin-left: 4px; padding-left: 10px; border-left: 1px solid #c3c4c7;
            }
            /* f800 and f900 each sit in their own bordered box. */
            .veyra-reading-ctl .veyra-rescan,
            .veyra-reading-ctl .veyra-clean {
                display: inline-flex; align-items: center; gap: 6px;
                margin-left: 4px; padding: 2px 6px;
                border: 1px solid #8c8f94; border-radius: 4px; background: #fff;
            }
            .veyra-reading-ctl .veyra-clean-status { font-weight: 600; }
            #veyra-clean-modal {
                position: fixed; inset: 0; z-index: 100030; display: none;
                align-items: center; justify-content: center; background: rgba(0,0,0,0.45);
            }
            #veyra-clean-modal.is-open { display: flex; }
            #veyra-clean-modal .veyra-clean-box {
                background: #fff; border-radius: 8px; padding: 22px 24px; width: 440px; max-width: calc(100vw - 32px);
                box-shadow: 0 10px 40px rgba(0,0,0,0.35); font-size: 13px; line-height: 1.5;
            }
            #veyra-clean-modal h2 { margin: 0 0 10px; font-size: 17px; }
            #veyra-clean-modal ul { margin: 0 0 14px 18px; list-style: disc; }
            #veyra-clean-modal .veyra-clean-warn { color: #b32d2e; }
            #veyra-clean-modal .veyra-clean-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
            #veyra-clean-modal .veyra-clean-go {
                font-size: 15px; font-weight: 700; padding: 8px 20px; border: none; border-radius: 5px;
                background: #2271b1; color: #fff; cursor: pointer;
            }
            #veyra-clean-modal .veyra-clean-go:hover { background: #135e96; }
            #veyra-clean-modal .veyra-clean-go:disabled { opacity: 0.6; cursor: wait; }
            .veyra-reading-ctl .veyra-rescan label { display: inline-flex; align-items: center; gap: 3px; }
            .veyra-reading-ctl .veyra-rescan input[type="checkbox"] { margin: 0; }
            .veyra-reading-ctl .veyra-rescan-status { font-weight: 600; }
            tr.veyra-drafts-separator td {
                height: 20px; line-height: 20px; padding: 0 10px;
                background: #b45309; color: #fff; font-weight: 700; letter-spacing: 0.02em;
                font-size: 12px; text-transform: uppercase;
            }
            tr.veyra-drafts-separator.is-end td { background: #4b5563; text-transform: none; }
            tr.veyra-rolloff-separator td {
                height: 20px; line-height: 20px; padding: 0 10px;
                background: #111827; color: #fff; font-weight: 700; letter-spacing: 0.02em;
                font-size: 12px; text-transform: uppercase;
            }
        </style>
        <script>
        (function(){
            var AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var NONCE = <?php echo wp_json_encode($nonce); ?>;
            var CAN_EDIT = <?php echo $can_edit ? 'true' : 'false'; ?>;
            var perPage = <?php echo (int) $per_page; ?>;
            var AUTOCAL_ON = <?php echo $autocal_on ? 'true' : 'false'; ?>;

            // --- 1) Controller next to the "Add Post" button -----------------
            function buildController(){
                if (!CAN_EDIT) return;
                var addBtn = document.querySelector('.wrap .page-title-action');
                if (!addBtn || document.querySelector('.veyra-reading-ctl')) return;

                var wrap = document.createElement('span');
                wrap.className = 'veyra-reading-ctl';
                wrap.innerHTML = '<span>Blog pages show at most</span>'
                    + '<input type="number" min="1" step="1" class="veyra-reading-input" value="' + perPage + '"> '
                    + '<span>posts</span>'
                    + '<button type="button" class="button button-primary veyra-reading-save">Save</button>'
                    + '<span class="veyra-reading-status"></span>'
                    + '<span class="veyra-autocal">'
                    +   '<button type="button" class="veyra-flag-toggle veyra-autocal-toggle' + (AUTOCAL_ON ? ' is-on' : '') + '"'
                    +     ' role="switch" aria-checked="' + (AUTOCAL_ON ? 'true' : 'false') + '"'
                    +     ' title="auto-calibrate for should-remain posts: ' + (AUTOCAL_ON ? 'ON' : 'OFF') + '">'
                    +     '<span class="veyra-flag-knob"></span>'
                    +   '</button>'
                    +   '<span>auto-calibrate for should-remain posts</span>'
                    + '</span>'
                    + '<span class="veyra-rescan">'
                    +   '<button type="button" class="button veyra-rescan-btn">f800 - rescan</button>'
                    +   '<label><input type="checkbox" class="veyra-rescan-backdate" checked>'
                    +     'randomly back date non-"should-remain" posts</label>'
                    +   '<span class="veyra-rescan-status"></span>'
                    + '</span>'
                    + '<span class="veyra-clean">'
                    +   '<button type="button" class="button veyra-clean-btn">f900 - clean now</button>'
                    +   '<span class="veyra-clean-status"></span>'
                    + '</span>';
                addBtn.insertAdjacentElement('afterend', wrap);

                var input  = wrap.querySelector('.veyra-reading-input');
                var save   = wrap.querySelector('.veyra-reading-save');
                var status = wrap.querySelector('.veyra-reading-status');
                var acBtn  = wrap.querySelector('.veyra-autocal-toggle');
                var rsBtn  = wrap.querySelector('.veyra-rescan-btn');
                var rsBack = wrap.querySelector('.veyra-rescan-backdate');
                var rsStat = wrap.querySelector('.veyra-rescan-status');

                // --- f900: preview (dry run) -> confirm popup -> run -------------
                var clBtn  = wrap.querySelector('.veyra-clean-btn');
                var clStat = wrap.querySelector('.veyra-clean-status');
                function cleanPost(dry){
                    var body = new URLSearchParams();
                    body.append('action', 'veyra_autocal_clean');
                    body.append('nonce', NONCE);
                    body.append('dry', dry ? '1' : '0');
                    return fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (!res || !res.success) throw new Error((res && res.data) ? res.data : 'request failed');
                            return res.data;
                        });
                }
                function openCleanModal(plan){
                    var m = document.getElementById('veyra-clean-modal');
                    if (!m) {
                        m = document.createElement('div');
                        m.id = 'veyra-clean-modal';
                        document.body.appendChild(m);
                        m.addEventListener('click', function(e){ if (e.target === m) m.classList.remove('is-open'); });
                        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') m.classList.remove('is-open'); });
                    }
                    var n = plan.backdated.length;
                    var items = '<li>Keep only the <b>' + plan.flagged + '</b> should_remain_on_homepage posts on the homepage</li>'
                        + '<li>Back-date <b>' + n + '</b> post' + (n === 1 ? '' : 's') + ' (random 3–7 day drip)'
                        + (plan.srro_backdated ? ', incl. ' + plan.srro_backdated + ' marked should_remain_rolled_off' : '') + '</li>'
                        + '<li>Blog pages show at most: <b>' + plan.per_page_before + ' → ' + plan.per_page_after + '</b></li>';
                    if (plan.unflagged_sticky) {
                        items += '<li class="veyra-clean-warn">' + plan.unflagged_sticky + ' sticky post' + (plan.unflagged_sticky === 1 ? '' : 's')
                            + ' not marked on-homepage will still show (stickies ignore dates)</li>';
                    }
                    m.innerHTML = '<div class="veyra-clean-box" role="dialog" aria-modal="true">'
                        + '<h2>f900 - clean now?</h2><ul>' + items + '</ul>'
                        + '<div class="veyra-clean-actions">'
                        +   '<button type="button" class="button veyra-clean-cancel">Cancel</button>'
                        +   '<button type="button" class="veyra-clean-go">Yes, clean now</button>'
                        + '</div></div>';
                    m.classList.add('is-open');
                    var go = m.querySelector('.veyra-clean-go');
                    go.focus();
                    m.querySelector('.veyra-clean-cancel').addEventListener('click', function(){ m.classList.remove('is-open'); });
                    go.addEventListener('click', function(){
                        go.disabled = true; go.textContent = 'cleaning…';
                        cleanPost(false).then(function(d){
                            m.classList.remove('is-open');
                            perPage = d.per_page_after; input.value = perPage;
                            clStat.style.color = '#46b450';
                            clStat.textContent = 'back-dated ' + d.backdated.length + ', posts_per_page ' + d.per_page_before + ' → ' + d.per_page_after + ' — reloading…';
                            console.log('[veyra f900] back-dated posts', d.backdated);
                            setTimeout(function(){ window.location.reload(); }, 2500);
                        }).catch(function(err){
                            m.classList.remove('is-open');
                            clStat.style.color = '#dc3232'; clStat.textContent = err.message;
                        });
                    });
                }
                clBtn.addEventListener('click', function(){
                    clBtn.disabled = true; clStat.style.color = '#787c82'; clStat.textContent = 'checking…';
                    cleanPost(true).then(function(plan){
                        clStat.textContent = '';
                        if (!plan.backdated.length && plan.per_page_before === plan.per_page_after) {
                            clStat.style.color = '#46b450'; clStat.textContent = 'already clean — nothing to change';
                            return;
                        }
                        openCleanModal(plan);
                    }).catch(function(err){ clStat.style.color = '#dc3232'; clStat.textContent = err.message; })
                      .finally(function(){ clBtn.disabled = false; });
                });

                rsBtn.addEventListener('click', function(){
                    rsBtn.disabled = true; rsStat.style.color = '#787c82'; rsStat.textContent = 'scanning…';
                    var body = new URLSearchParams();
                    body.append('action', 'veyra_autocal_rescan');
                    body.append('nonce', NONCE);
                    body.append('backdate', rsBack.checked ? '1' : '0');
                    fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (!res || !res.success) {
                                rsStat.style.color = '#dc3232'; rsStat.textContent = (res && res.data) ? res.data : 'rescan failed';
                                return;
                            }
                            var d = res.data, moved = d.backdated.length;
                            var grew = d.per_page_after !== d.per_page_before;
                            var msg = d.flagged_published + ' should_remain_on_homepage';
                            if (!d.missing_before) {
                                msg += ' — all already on homepage, nothing changed';
                            } else {
                                msg += ', ' + d.missing_before + ' were off homepage';
                                if (moved) msg += ' — back-dated ' + moved;
                                msg += grew ? (moved ? ',' : ' —') + ' posts_per_page ' + d.per_page_before + ' → ' + d.per_page_after
                                            : (moved ? '' : ' — no change');
                            }
                            rsStat.style.color = '#46b450'; rsStat.textContent = msg;
                            if (moved) {
                                console.log('[veyra f800] back-dated posts', d.backdated);
                            }
                            if (grew) {
                                perPage = d.per_page_after;
                                input.value = perPage;
                            }
                            // Back-dating reorders the list, so redraw it from the server.
                            if (moved) {
                                rsStat.textContent = msg + ' — reloading…';
                                setTimeout(function(){ window.location.reload(); }, 2500);
                            } else if (grew) {
                                insertSeparator();
                            }
                        })
                        .catch(function(err){ rsStat.style.color = '#dc3232'; rsStat.textContent = 'failed: ' + err.message; })
                        .finally(function(){ rsBtn.disabled = false; });
                });

                acBtn.addEventListener('click', function(){
                    if (acBtn.classList.contains('is-busy')) return;
                    acBtn.classList.add('is-busy');
                    var next = !acBtn.classList.contains('is-on');
                    var body = new URLSearchParams();
                    body.append('action', 'veyra_toggle_autocal');
                    body.append('nonce', NONCE);
                    body.append('on', next ? '1' : '0');
                    fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (res && res.success) {
                                var on = !!res.data.on;
                                acBtn.classList.toggle('is-on', on);
                                acBtn.setAttribute('aria-checked', on ? 'true' : 'false');
                                acBtn.setAttribute('title', 'auto-calibrate for should-remain posts: ' + (on ? 'ON' : 'OFF'));
                            } else {
                                alert('Failed to toggle: ' + (res && res.data ? res.data : 'unknown error'));
                            }
                        })
                        .catch(function(err){ alert('Toggle request failed: ' + err.message); })
                        .finally(function(){ acBtn.classList.remove('is-busy'); });
                });

                save.addEventListener('click', function(){
                    var val = parseInt(input.value, 10);
                    if (!val || val < 1) { status.style.color = '#dc3232'; status.textContent = 'enter a number ≥ 1'; return; }
                    save.disabled = true; status.style.color = '#787c82'; status.textContent = 'saving…';
                    var body = new URLSearchParams();
                    body.append('action', 'veyra_save_posts_per_page');
                    body.append('nonce', NONCE);
                    body.append('value', val);
                    fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (res && res.success) {
                                perPage = res.data.value;
                                status.style.color = '#46b450'; status.textContent = '✓ saved';
                                insertSeparator(); // reposition the rolled-off line
                            } else {
                                status.style.color = '#dc3232'; status.textContent = (res && res.data) ? res.data : 'save failed';
                            }
                        })
                        .catch(function(err){ status.style.color = '#dc3232'; status.textContent = 'failed: ' + err.message; })
                        .finally(function(){ save.disabled = false; setTimeout(function(){ status.textContent=''; }, 4000); });
                });
            }

            // --- 2) "ROLLED-OFF POSTS BELOW" separator row -------------------
            function insertSeparator(){
                var tbody = document.getElementById('the-list');
                if (!tbody) return;

                // remove any prior separator we inserted
                var old = tbody.querySelector('tr.veyra-rolloff-separator');
                if (old) old.parentNode.removeChild(old);

                // count published post rows in display order; the front-end feed
                // page 1 holds the first `perPage` published posts — the rest are
                // "rolled off" the homepage feed.
                var rows = tbody.querySelectorAll('tr[id^="post-"]');
                var publishedSeen = 0, anchor = null;
                for (var i = 0; i < rows.length; i++) {
                    if (rows[i].className.indexOf('status-publish') === -1) continue;
                    publishedSeen++;
                    if (publishedSeen === perPage) { anchor = rows[i]; break; }
                }
                // Only show the separator if there is at least one published row
                // AFTER the cutoff (i.e. something actually rolled off on this page).
                if (!anchor) return;
                var hasBelow = false, n = anchor.nextElementSibling;
                while (n) { if (n.id && n.id.indexOf('post-') === 0 && n.className.indexOf('status-publish') !== -1) { hasBelow = true; break; } n = n.nextElementSibling; }
                if (!hasBelow) return;

                var tr = document.createElement('tr');
                tr.className = 'veyra-rolloff-separator';
                var td = document.createElement('td');
                td.colSpan = visibleColumnCount();
                td.textContent = 'ROLLED-OFF POSTS BELOW (not no feed page)';
                tr.appendChild(td);
                anchor.parentNode.insertBefore(tr, anchor.nextSibling);
            }

            /**
             * Count only the columns actually being rendered.
             *
             * Hidden columns (Screen Options / our toggles) are display:none, so they
             * form no column in the table. Spanning them anyway — the old code used
             * tbody.rows[0].cells.length, which counts hidden cells — makes the colSpan
             * exceed the real column count, and the browser invents anonymous columns
             * to make up the difference. In this table-layout:fixed table the free
             * space is then split evenly between Title and those phantom columns, so
             * Title collapses (measured: 1078px -> 216px with 4 hidden columns) and the
             * remainder shows up as dead space. That is why the wasted space only ever
             * appeared once this separator row was on screen.
             */
            function visibleColumnCount(){
                var head = document.querySelector('.wp-list-table thead tr');
                if (!head) { return 1; }
                var n = 0;
                for (var i = 0; i < head.cells.length; i++) {
                    if (window.getComputedStyle(head.cells[i]).display !== 'none') { n++; }
                }
                return n || 1;
            }

            /** Keep the span correct when columns are shown/hidden without a reload. */
            function refreshSeparatorSpan(){
                var n = visibleColumnCount();
                document.querySelectorAll('tr.veyra-rolloff-separator > td, tr.veyra-drafts-separator > td').forEach(function(td){
                    td.colSpan = n;
                });
            }

            // --- 3) "DRAFTS" section at the top of the list ------------------
            // The rows are already sorted drafts-first server-side
            // (veyra_drafts_first_orderby); this only labels the block. Drawn only
            // if the drafts really do form one run at the top, so a column sort or
            // a status filter never gets a misleading label.
            function insertDraftsSection(){
                var tbody = document.getElementById('the-list');
                if (!tbody) return;
                var rows = tbody.querySelectorAll('tr[id^="post-"]');
                var drafts = 0;
                while (drafts < rows.length && rows[drafts].classList.contains('status-draft')) drafts++;
                if (!drafts) return;
                for (var i = drafts; i < rows.length; i++) {
                    if (rows[i].classList.contains('status-draft')) return;
                }
                function sep(text, extra){
                    var tr = document.createElement('tr');
                    tr.className = 'veyra-drafts-separator' + (extra ? ' ' + extra : '');
                    var td = document.createElement('td');
                    td.colSpan = visibleColumnCount();
                    td.textContent = text;
                    tr.appendChild(td);
                    return tr;
                }
                tbody.insertBefore(sep('DRAFTS (' + drafts + ' on this page)'), rows[0]);
                if (drafts < rows.length) {
                    tbody.insertBefore(sep('Posts On Home Page', 'is-end'), rows[drafts]);
                }
            }

            // Column visibility changes come from core's Screen Options checkboxes and
            // from the link_synopsis toggles, both of which act without a page reload.
            document.addEventListener('click', function(e){
                if (!e.target.closest) { return; }
                if (e.target.closest('.hide-column-tog') || e.target.closest('.veyra-lsyn-cols input[data-col]')) {
                    // Let the other handler finish toggling before re-measuring.
                    setTimeout(refreshSeparatorSpan, 0);
                }
            });
            // ...and from rocket_chamber column pagination.
            document.addEventListener('veyra:columns-changed', refreshSeparatorSpan);

            function init(){ buildController(); insertDraftsSection(); insertSeparator(); }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>
        <?php
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
     *  post ID, so each page/post owns exactly one entry per option.
     *
     *  A postmeta-backed version of the same 4 fields (see veyra_postmeta_field_definitions())
     *  is rendered above these, between two black phase-labeled separators, while both storage
     *  paths run in parallel (2026-07-28: wp_options doesn't scale — a single shared option
     *  holding every post's content on a large site gets unserialized/re-serialized on every
     *  post save and can blow the PHP memory limit; postmeta is per-post and doesn't have that
     *  problem). Nothing about this old wp_options path changes. */
    public function veyra_wayback_render_editor_box($post) {
        if (!$post || !in_array($post->post_type, array('post', 'page'), true)) {
            return;
        }
        wp_nonce_field('veyra_wayback_save', 'veyra_wayback_nonce');
        wp_nonce_field('veyra_postmeta_save', 'veyra_postmeta_nonce');

        $this->veyra_render_phase_separator('New Post Meta Fields Being Phased In');
        $prev_pair = null;
        foreach ($this->veyra_postmeta_field_definitions() as $field) {
            $is_second_in_pair = ($prev_pair === $field['pair']);
            if ($field['type'] === 'text') {
                $this->veyra_render_postmeta_text_field($post->ID, $field['meta_key'], $field['pair'], $is_second_in_pair);
            } else {
                $this->veyra_render_postmeta_textarea_field($post->ID, $field['meta_key'], $field['pair'], $is_second_in_pair);
            }
            $prev_pair = $field['pair'];
        }
        $this->veyra_render_phase_separator('Old WP Options Fields Being Phased Out');

        $this->veyra_render_wayback_title_field($post->ID);
        $this->veyra_render_snap_height_box($post->ID, 'veyra_cached_original_wayback_content');
        $this->veyra_render_freshly_post_title_field($post->ID);
        $this->veyra_render_snap_height_box($post->ID, 'veyra_freshly_invented_content_before_deployment_to_live_post_content');
    }

    /** Field/type/pair map for the postmeta-backed phase-in boxes: one row per post (unlike
     *  their wp_options counterparts, which share one big post-ID-keyed array per field).
     *  'pair' groups each field with its title/content sibling — used to color each pair
     *  (blue for wayback, green for freshly) and to collapse the vertical gap between a
     *  pair's own two boxes (2026-07-28: freshly ordered first per current layout). */
    private function veyra_postmeta_field_definitions() {
        return array(
            array('meta_key' => 'vpostmeta_freshly_post_title', 'type' => 'text', 'pair' => 'freshly'),
            array('meta_key' => 'vpostmeta_freshly_invented_content_before_deployment_to_live_post_content', 'type' => 'textarea', 'pair' => 'freshly'),
            array('meta_key' => 'vpostmeta_cached_original_wayback_post_title', 'type' => 'text', 'pair' => 'wayback'),
            array('meta_key' => 'vpostmeta_cached_original_wayback_content', 'type' => 'textarea', 'pair' => 'wayback'),
        );
    }

    /** Static 26px black/white divider labeling which group of boxes follows it. */
    private function veyra_render_phase_separator($text) {
        echo '<div class="veyra-postmeta-separator">' . esc_html($text) . '</div>';
    }

    /** Single-line text input for a postmeta-backed field — same markup/classes as the
     *  wp_options title fields below, but sourced from get_post_meta() (one row per post).
     *  $pair colors the box (blue=wayback, green=freshly, matching Page Change Drip
     *  Manager's own colors); $is_second_in_pair zeroes the box's top margin so a pair's
     *  title+content boxes sit flush against each other with no gap. */
    private function veyra_render_postmeta_text_field($post_id, $meta_key, $pair, $is_second_in_pair) {
        $value = get_post_meta($post_id, $meta_key, true);
        $classes = 'veyra-wayback-box veyra-wayback-title-box veyra-postmeta-pair-' . esc_attr($pair);
        if ($is_second_in_pair) { $classes .= ' veyra-postmeta-pair-second'; }

        echo '<div class="' . $classes . '">';
        echo '<p class="veyra-wayback-label">wp_postmeta: ' . esc_html($meta_key) . '</p>';
        echo '<input type="text" class="veyra-sm-input" name="' . esc_attr($meta_key) . '" value="' . esc_attr($value) . '" />';
        echo '</div>';
    }

    /** Snap-height labeled textarea for a postmeta-backed field — same markup/classes (and
     *  therefore the same generic height-snap JS) as veyra_render_snap_height_box(), but
     *  sourced from get_post_meta() (one row per post, not a shared post-ID-keyed array).
     *  $pair/$is_second_in_pair behave exactly as in veyra_render_postmeta_text_field(). */
    private function veyra_render_postmeta_textarea_field($post_id, $meta_key, $pair, $is_second_in_pair) {
        $value = get_post_meta($post_id, $meta_key, true);
        $textarea_id  = 'veyra-snap-' . str_replace('_', '-', $meta_key);
        $heightbar_id = $textarea_id . '-heightbar';
        $classes = 'veyra-wayback-box veyra-postmeta-pair-' . esc_attr($pair);
        if ($is_second_in_pair) { $classes .= ' veyra-postmeta-pair-second'; }

        echo '<div class="' . $classes . '" id="' . esc_attr($textarea_id) . '-box">';
        echo '<div class="veyra-wayback-header-row">';
        echo '<p class="veyra-wayback-label">wp_postmeta: ' . esc_html($meta_key) . '</p>';
        echo '<div class="veyra-wayback-heightbar" id="' . esc_attr($heightbar_id) . '">';
        echo '<button type="button" class="button veyra-wayback-height-btn veyra-wayback-height-btn--active" data-h="100">height: 100 px</button>';
        echo '<span class="veyra-wayback-sep">|</span>';
        echo '<button type="button" class="button veyra-wayback-height-btn" data-h="300">300 px</button>';
        echo '<span class="veyra-wayback-sep">|</span>';
        echo '<button type="button" class="button veyra-wayback-height-btn" data-h="full">100% of contents</button>';
        echo '</div>';
        echo '</div>';
        echo '<textarea id="' . esc_attr($textarea_id) . '" class="veyra-wayback-textarea" name="' . esc_attr($meta_key) . '">' . esc_textarea($value) . '</textarea>';
        echo '</div>';
    }

    /** Persist each postmeta-backed phase-in field into its own per-post meta row. Runs off
     *  the same native save_post hook as veyra_wayback_save_editor (no new save button —
     *  both simply fire when the existing Publish/Update/Save Draft button is clicked). */
    public function veyra_postmeta_save_editor($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (wp_is_post_revision($post_id)) { return; }
        if (!isset($_POST['veyra_postmeta_nonce']) || !wp_verify_nonce($_POST['veyra_postmeta_nonce'], 'veyra_postmeta_save')) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }

        foreach ($this->veyra_postmeta_field_definitions() as $field) {
            $meta_key = $field['meta_key'];
            if (!isset($_POST[$meta_key])) { continue; }

            $value = ($field['type'] === 'text')
                ? sanitize_text_field(wp_unslash($_POST[$meta_key]))
                : wp_unslash($_POST[$meta_key]);

            if (trim($value) === '') {
                delete_post_meta($post_id, $meta_key);
            } else {
                update_post_meta($post_id, $meta_key, $value);
            }
        }
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
        echo '<div class="veyra-wayback-header-row">';
        echo '<p class="veyra-wayback-label">wp_options: ' . esc_html($option_name) . '</p>';
        echo '<div class="veyra-wayback-heightbar" id="' . esc_attr($heightbar_id) . '">';
        echo '<button type="button" class="button veyra-wayback-height-btn veyra-wayback-height-btn--active" data-h="100">height: 100 px</button>';
        echo '<span class="veyra-wayback-sep">|</span>';
        echo '<button type="button" class="button veyra-wayback-height-btn" data-h="300">300 px</button>';
        echo '<span class="veyra-wayback-sep">|</span>';
        echo '<button type="button" class="button veyra-wayback-height-btn" data-h="full">100% of contents</button>';
        echo '</div>';
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
        .veyra-sm-input { width:100%; background:#fff; }
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
        .veyra-postmeta-pair-wayback { background:#d6e9fb; }
        .veyra-postmeta-pair-freshly { background:#d9f2d9; }
        .veyra-postmeta-pair-second { margin-top:0; }
        .veyra-wayback-label { font-weight:700; font-size:14px; margin:0 0 10px; }
        .veyra-wayback-header-row { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 0 10px; }
        .veyra-wayback-header-row .veyra-wayback-label { margin:0; }
        .veyra-wayback-heightbar { margin:0; display:flex; align-items:center; justify-content:flex-end; gap:8px; }
        .veyra-wayback-sep { color:#c3c4c7; }
        .veyra-wayback-height-btn { padding:0 5px; font-size:9.75px; line-height:1.4; min-height:20px; }
        .veyra-wayback-height-btn--active { background:#2271b1; color:#fff; border-color:#2271b1; }
        .veyra-wayback-textarea { width:100%; box-sizing:border-box; font-family:monospace; font-size:12px; height:100px; overflow-y:auto; resize:none; display:block; background:#fff; }
        .veyra-postmeta-separator { height:26px; line-height:26px; background:#000; color:#fff; font-weight:700; font-size:13px; padding:0 14px; margin:20px 0 0 0; }
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

// Create the post-editor sidebar box tables (veyra_post_drip_actions /
// veyra_post_status_log) on activation. On a live site: upload the plugin
// changes, then deactivate + reactivate Veyra and both tables are built.
// veyra_editor_boxes_maybe_upgrade_db() on init covers in-place code updates
// where the plugin is never reactivated.
register_activation_hook(__FILE__, array($veyra, 'veyra_editor_boxes_create_tables'));

// ---------------------------------------------------------------------------
// Admin screens — each under /admin-screens/<folder>/ self-registers its menu
// and render callback. Keeps page-specific code out of this main file.
// ---------------------------------------------------------------------------
require_once VEYRA_PLUGIN_PATH . 'admin-screens/post_importer_from_birch/post-importer-from-birch.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/veyra_plugin_manager/veyra-plugin-manager.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/sm-redirect-manager/sm-redirect-manager.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/majestic_data_jar/majestic-data-jar.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/custom_blog_feed/custom-blog-feed.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/page-change-drip-manager/page-change-drip-manager.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/veyra-site-title-and-footer-mar/veyra-site-title-and-footer-mar.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/veyra-change-wp-user-and-pass/veyra-change-wp-user-and-pass.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/veyra-drip-actions-jar/veyra-drip-actions-jar.php';
require_once VEYRA_PLUGIN_PATH . 'admin-screens/post-status-log-jar/post-status-log-jar.php';

// ---------------------------------------------------------------------------
// Post-editor sidebar boxes — each under /editor-boxes/<folder>/ self-registers
// its meta box, AJAX handlers and (for the drip queue) its cron hooks. Their
// tables are declared in this file; see veyra_editor_boxes_create_tables().
// ---------------------------------------------------------------------------
require_once VEYRA_PLUGIN_PATH . 'editor-boxes/veyra-post-actions-history/veyra-post-actions-history.php';
require_once VEYRA_PLUGIN_PATH . 'editor-boxes/veyra-post-drip-actions/veyra-post-drip-actions.php';

// ---------------------------------------------------------------------------
// List-table columns — each under /list-table-columns/<folder>/ self-registers
// its column on the relevant wp-admin list screens.
// ---------------------------------------------------------------------------
require_once VEYRA_PLUGIN_PATH . 'list-table-columns/veyra-drip-actions-column/veyra-drip-actions-column.php';