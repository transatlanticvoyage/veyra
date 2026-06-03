<?php
/**
 * Veyra — Set Custom Blog Feed Page.
 *
 * Lets an admin designate ONE published page as a "custom blog feed" page.
 * On the front end, that page renders the full content of every published blog
 * post (not excerpts), with each post's title linking to its real permalink and
 * a meta/breadcrumb line (date, author, categories).
 *
 * The feed is injected via the_content filter so it renders inside whatever the
 * active theme already draws for a normal page (header, footer, sidebar, title)
 * — no theme template editing required.
 *
 * Designated page is stored in a single option, so only one page can ever be
 * the feed page:
 *     option key: veyra_custom_blog_feed_page  (int page ID, 0 = none)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('VEYRA_CUSTOM_BLOG_FEED_OPTION')) {
    define('VEYRA_CUSTOM_BLOG_FEED_OPTION', 'veyra_custom_blog_feed_page');
}

/* ---------------------------------------------------------------------------
 * Admin menu + page
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'veyra_custom_blog_feed_register_menu', 20);
function veyra_custom_blog_feed_register_menu() {
    add_submenu_page(
        'veyra-hub-1',                          // parent slug (Veyra Hub 1)
        'Set Custom Blog Feed Page',            // page title
        'Custom Blog Feed',                     // menu label
        'manage_options',                       // capability
        'veyra_custom_blog_feed',               // page slug
        'veyra_custom_blog_feed_render_admin'   // callback
    );
}

/**
 * Handle the form posts (set / clear) then render the admin UI.
 */
function veyra_custom_blog_feed_render_admin() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    $notice = '';

    // Set the feed page.
    if (isset($_POST['veyra_cbf_action']) && $_POST['veyra_cbf_action'] === 'set') {
        check_admin_referer('veyra_cbf_set');
        $page_id = isset($_POST['veyra_cbf_page_id']) ? (int) $_POST['veyra_cbf_page_id'] : 0;

        if ($page_id > 0 && get_post_status($page_id) === 'publish' && get_post_type($page_id) === 'page') {
            update_option(VEYRA_CUSTOM_BLOG_FEED_OPTION, $page_id);
            $notice = array('level' => 'success', 'message' => 'Custom blog feed page set to: <strong>' . esc_html(get_the_title($page_id)) . '</strong>');
        } else {
            $notice = array('level' => 'error', 'message' => 'Please choose a published page.');
        }
    }

    // Clear the designation.
    if (isset($_POST['veyra_cbf_action']) && $_POST['veyra_cbf_action'] === 'clear') {
        check_admin_referer('veyra_cbf_clear');
        update_option(VEYRA_CUSTOM_BLOG_FEED_OPTION, 0);
        $notice = array('level' => 'success', 'message' => 'Custom blog feed page cleared. No page is designated.');
    }

    $current_id    = (int) get_option(VEYRA_CUSTOM_BLOG_FEED_OPTION, 0);
    $current_valid = $current_id > 0 && get_post_status($current_id) === 'publish' && get_post_type($current_id) === 'page';
    ?>
    <div class="wrap">
        <h1 style="display: flex; align-items: center; gap: 12px; margin: 0 0 8px 0;">
            <span class="dashicons dashicons-rss" style="font-size: 28px; width: 28px; height: 28px; color: #d97706;"></span>
            Set Custom Blog Feed Page
        </h1>
        <p style="color: #666; margin: 0 0 20px 0; max-width: 640px;">
            Designate one published page as a custom blog feed. On the front end that page will show
            <strong>every published post in full</strong> (not excerpts), each title linking to its own post,
            with a date / author / category line. Only one page can be the feed page at a time.
        </p>

        <?php if ($notice): ?>
            <div class="notice notice-<?php echo $notice['level'] === 'success' ? 'success' : 'error'; ?> inline" style="margin: 0 0 16px 0;">
                <p><?php echo wp_kses_post($notice['message']); ?></p>
            </div>
        <?php endif; ?>

        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 16px 20px; max-width: 640px;">
            <h2 style="margin-top: 0;">Current feed page</h2>
            <?php if ($current_valid): ?>
                <p style="font-size: 14px;">
                    <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                    <strong><?php echo esc_html(get_the_title($current_id)); ?></strong>
                    &nbsp;
                    <a href="<?php echo esc_url(get_permalink($current_id)); ?>" target="_blank" class="button button-small">View feed &nearr;</a>
                    <a href="<?php echo esc_url(get_edit_post_link($current_id)); ?>" class="button button-small">Edit page</a>
                </p>
                <form method="post" style="margin-top: 8px;">
                    <?php wp_nonce_field('veyra_cbf_clear'); ?>
                    <input type="hidden" name="veyra_cbf_action" value="clear" />
                    <button type="submit" class="button" onclick="return confirm('Clear the custom blog feed designation? The page will revert to its normal content.');">
                        Clear designation
                    </button>
                </form>
            <?php elseif ($current_id > 0): ?>
                <p style="color: #b32d2e;">
                    A page (ID <?php echo (int) $current_id; ?>) is designated but is no longer a published page.
                    Pick another page below, or clear it.
                </p>
                <form method="post" style="margin-top: 8px;">
                    <?php wp_nonce_field('veyra_cbf_clear'); ?>
                    <input type="hidden" name="veyra_cbf_action" value="clear" />
                    <button type="submit" class="button">Clear designation</button>
                </form>
            <?php else: ?>
                <p style="color: #666;">No page is currently designated as the custom blog feed.</p>
            <?php endif; ?>
        </div>

        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 16px 20px; max-width: 640px; margin-top: 16px;">
            <h2 style="margin-top: 0;">Choose a page</h2>
            <form method="post">
                <?php wp_nonce_field('veyra_cbf_set'); ?>
                <input type="hidden" name="veyra_cbf_action" value="set" />
                <p>
                    <label for="veyra_cbf_page_id" style="font-weight: 600; display: block; margin-bottom: 6px;">Published page</label>
                    <?php
                    wp_dropdown_pages(array(
                        'name'              => 'veyra_cbf_page_id',
                        'id'                => 'veyra_cbf_page_id',
                        'selected'          => $current_valid ? $current_id : 0,
                        'show_option_none'  => '— Select a page —',
                        'option_none_value' => '0',
                        'post_status'       => 'publish',
                    ));
                    ?>
                </p>
                <p>
                    <button type="submit" class="button button-primary" style="background: #22c55e; border-color: #16a34a;">
                        Set page as custom blog feed page
                    </button>
                </p>
            </form>
            <p style="color: #888; font-size: 12px; margin-bottom: 0;">
                Tip: create a blank page titled e.g. "Blog" first, then designate it here.
            </p>
        </div>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Front-end rendering
 * ------------------------------------------------------------------------- */

add_filter('the_content', 'veyra_custom_blog_feed_filter_content', 20);
function veyra_custom_blog_feed_filter_content($content) {
    static $rendering = false;

    // Prevent recursion when we render each inner post's the_content().
    if ($rendering) {
        return $content;
    }
    if (is_admin()) {
        return $content;
    }

    $feed_page_id = (int) get_option(VEYRA_CUSTOM_BLOG_FEED_OPTION, 0);
    if (!$feed_page_id) {
        return $content;
    }

    // Only on the designated page, only in the main loop of the main query.
    if (!is_page($feed_page_id) || !in_the_loop() || !is_main_query()) {
        return $content;
    }

    $rendering = true;
    $feed_html = veyra_custom_blog_feed_build_html();
    $rendering = false;

    // Append the feed after any content the page itself holds (intro text, etc.).
    return $content . $feed_html;
}

/**
 * Build the full-post feed HTML for every published post, newest first.
 */
function veyra_custom_blog_feed_build_html() {
    $query = new WP_Query(array(
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => -1,
        'orderby'             => 'date',
        'order'               => 'DESC',
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
    ));

    if (!$query->have_posts()) {
        wp_reset_postdata();
        return '<div class="veyra-cbf"><p>No blog posts found.</p></div>';
    }

    ob_start();
    ?>
    <style>
        .veyra-cbf { margin: 24px 0; }
        .veyra-cbf-post { margin: 0 0 40px 0; padding: 0 0 32px 0; border-bottom: 1px solid #e5e5e5; }
        .veyra-cbf-post:last-child { border-bottom: none; }
        .veyra-cbf-title { margin: 0 0 8px 0; line-height: 1.25; }
        .veyra-cbf-title a { text-decoration: none; }
        .veyra-cbf-meta { font-size: 14px; color: #6b7280; margin: 0 0 16px 0; display: flex; flex-wrap: wrap; gap: 6px 0; align-items: center; }
        .veyra-cbf-meta .sep { margin: 0 8px; color: #c4c4c4; }
        .veyra-cbf-meta a { color: #6b7280; text-decoration: none; }
        .veyra-cbf-meta a:hover { text-decoration: underline; }
        .veyra-cbf-content { line-height: 1.7; }
        .veyra-cbf-content img { max-width: 100%; height: auto; }
        .veyra-cbf-readmore { display: inline-block; margin-top: 12px; font-weight: 600; text-decoration: none; }
    </style>
    <div class="veyra-cbf">
        <?php while ($query->have_posts()) : $query->the_post(); ?>
            <article id="veyra-cbf-post-<?php the_ID(); ?>" class="veyra-cbf-post">
                <h2 class="veyra-cbf-title">
                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                </h2>
                <div class="veyra-cbf-meta">
                    <span class="veyra-cbf-date">
                        <span class="dashicons dashicons-calendar-alt" style="font-size:15px;vertical-align:-2px;"></span>
                        <?php echo esc_html(get_the_date()); ?>
                    </span>
                    <span class="sep">&middot;</span>
                    <span class="veyra-cbf-author">
                        <span class="dashicons dashicons-admin-users" style="font-size:15px;vertical-align:-2px;"></span>
                        <?php the_author_posts_link(); ?>
                    </span>
                    <?php
                    $cat_list = get_the_category_list(', ');
                    if (!empty($cat_list)) :
                        ?>
                        <span class="sep">&middot;</span>
                        <span class="veyra-cbf-cats">
                            <span class="dashicons dashicons-category" style="font-size:15px;vertical-align:-2px;"></span>
                            <?php echo wp_kses_post($cat_list); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="veyra-cbf-content">
                    <?php the_content(); ?>
                </div>
                <a class="veyra-cbf-readmore" href="<?php the_permalink(); ?>">View post &rarr;</a>
            </article>
        <?php endwhile; ?>
    </div>
    <?php
    wp_reset_postdata();
    return ob_get_clean();
}
