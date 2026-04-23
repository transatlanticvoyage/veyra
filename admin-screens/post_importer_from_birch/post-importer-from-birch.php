<?php
/**
 * Veyra → Post Importer from Birch (admin screen)
 *
 * URL slug:   veyra_post_importer
 * Access URL: /wp-admin/admin.php?page=veyra_post_importer
 * Parent:     Veyra Hub 1  (parent menu slug: veyra-hub-1)
 *
 * All code for this screen lives in this file so the rest of the plugin
 * stays uncluttered by page-specific logic. Include it once from veyra.php
 * via require_once and it self-registers its menu + render callback.
 */

if (!defined('ABSPATH')) {
    exit; // block direct access
}

// ---------------------------------------------------------------------------
// Menu registration — adds as submenu under "Veyra Hub 1"
// ---------------------------------------------------------------------------
add_action('admin_menu', 'veyra_post_importer_register_menu', 20);
function veyra_post_importer_register_menu() {
    add_submenu_page(
        'veyra-hub-1',                      // parent slug (Veyra main menu item)
        'Post Importer from Birch',         // page <title>
        'Post Importer from Birch',         // menu label
        'edit_posts',                       // capability (mirrors Veyra Hub 1)
        'veyra_post_importer',              // menu slug → ?page=veyra_post_importer
        'veyra_post_importer_render_page'   // render callback (below)
    );
}

// ---------------------------------------------------------------------------
// Aggressive admin-notice suppression — scoped to this screen only.
// Ported from the canonical pattern in
//   ruplin/includes/class-admin.php::suppress_all_admin_notices()
// Kept local to this file (standalone function) so it doesn't depend on
// the veyra main class and doesn't affect any other admin screens.
// ---------------------------------------------------------------------------
function veyra_post_importer_suppress_admin_notices() {
    // Strip all notice actions as early as possible in the admin page render
    add_action('admin_print_styles', function () {
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('network_admin_notices');
        global $wp_filter;
        if (isset($wp_filter['user_admin_notices'])) {
            unset($wp_filter['user_admin_notices']);
        }
    }, 0);

    // CSS fallback — hides any notices that still slip through
    add_action('admin_head', function () {
        echo '<style type="text/css">
            .notice, .notice-warning, .notice-error, .notice-success, .notice-info,
            .updated, .error, .update-nag, .admin-notice,
            div.notice, div.updated, div.error, div.update-nag,
            .wrap > .notice, .wrap > .updated, .wrap > .error,
            #adminmenu + .notice, #adminmenu + .updated, #adminmenu + .error,
            .update-php, .php-update-nag,
            .plugin-update-tr, .theme-update-message,
            .update-message, .updating-message,
            #update-nag, #deprecation-warning,
            .update-core-php, .notice-alt,
            .activated, .deactivated {
                display: none !important;
            }
        </style>';
    });

    // Second-pass scrub for hooks registered late in the pipeline
    add_action('admin_print_scripts', function () {
        global $wp_filter;
        $notice_hooks = [
            'admin_notices',
            'all_admin_notices',
            'network_admin_notices',
            'user_admin_notices',
        ];
        foreach ($notice_hooks as $hook) {
            if (isset($wp_filter[$hook])) {
                $wp_filter[$hook] = new WP_Hook();
            }
        }
    }, 0);
}

// ---------------------------------------------------------------------------
// Page render — intentionally blank for now. Content to be built in follow-up.
// ---------------------------------------------------------------------------
function veyra_post_importer_render_page() {
    // Suppress admin notices on this screen only
    veyra_post_importer_suppress_admin_notices();
    ?>
    <div class="wrap">
        <!-- Post Importer from Birch — page body intentionally blank. -->
    </div>
    <?php
}
