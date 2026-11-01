<?php
/**
 * Veyra → Post Importer from Birch (admin screen)
 *
 * URL slug:   veyra_post_importer
 * Access URL: /wp-admin/admin.php?page=veyra_post_importer
 * Parent:     Veyra Hub 1  (parent menu slug: veyra-hub-1)
 *
 * Flow:
 *   1. User downloads a zip on the birch wizard side via f5603.
 *      Zip contains: article.txt (3 sections) + N image files.
 *   2. User uploads the zip here, picks post-vs-page + draft-vs-publish,
 *      and clicks f5607. A new WP post or page is created.
 *   3. Images from the zip are sideloaded into the WP Media Library and
 *      embedded at "clean" positions in the post_content (never inside
 *      a paragraph, never directly after a heading).
 */

if (!defined('ABSPATH')) {
    exit;
}

// Per-site media display scheme (wp_options-backed; see that file's header).
require_once __DIR__ . '/media-display-scheme.php';

// ---------------------------------------------------------------------------
// Menu registration — submenu under "Veyra Hub 1"
// ---------------------------------------------------------------------------
/**
 * Bright blue "import" glyph (blue disc + white down-arrow into a tray) shown
 * LEFT of "Veyra Post Importer From Birch" in the sidebar submenu and the
 * admin-bar dropdown.
 */
function veyra_post_importer_icon_svg($size = 16) {
    $size = (int) $size;
    return '<svg class="veyra-pi-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" style="display:inline-block;vertical-align:-3px;margin-right:6px;flex:0 0 auto;">'
        . '<circle cx="12" cy="12" r="12" fill="#0a84ff"/>'
        . '<path d="M12 5.5v8.2M8.4 10.4 12 14l3.6-3.6" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<path d="M6.5 14.5v3h11v-3" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}

add_action('admin_menu', 'veyra_post_importer_register_menu', 20);
function veyra_post_importer_register_menu() {
    add_submenu_page(
        'veyra-hub-1',
        'Post Importer from Birch',
        veyra_post_importer_icon_svg() . 'Veyra Post Importer From Birch',
        'edit_posts',
        'veyra_post_importer',
        'veyra_post_importer_render_page'
    );
}

/**
 * Make this the 2nd item in the Veyra Hub 1 submenu (right under "Veyra Plugin
 * Updater", which veyra_plugin_manager_menu_first() moves to the top at 9999).
 */
add_action('admin_menu', 'veyra_post_importer_menu_second', 10000);
function veyra_post_importer_menu_second() {
    global $submenu;
    if (empty($submenu['veyra-hub-1'])) {
        return;
    }
    $items = array_values($submenu['veyra-hub-1']);
    foreach ($items as $i => $item) {
        if (isset($item[2]) && $item[2] === 'veyra_post_importer') {
            array_splice($items, $i, 1);
            $pos = (isset($items[0][2]) && $items[0][2] === 'veyra_plugin_manager') ? 1 : 0;
            array_splice($items, $pos, 0, [$item]);
            $submenu['veyra-hub-1'] = $items;
            break;
        }
    }
}

// ---------------------------------------------------------------------------
// Aggressive admin-notice suppression — scoped to THIS admin screen only.
// Registered at admin_init so remove_all_actions() runs BEFORE admin_notices
// fires. See earlier notes in plugin history for the timing fix.
// ---------------------------------------------------------------------------
add_action('admin_init', 'veyra_post_importer_maybe_install_suppression');
function veyra_post_importer_maybe_install_suppression() {
    if (!is_admin()) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'veyra_post_importer') return;

    veyra_post_importer_scrub_notice_hooks();

    remove_action('admin_notices', 'update_nag', 3);
    remove_action('admin_notices', 'maintenance_nag', 10);
    remove_action('admin_notices', 'site_admin_notice', 10);
    remove_action('network_admin_notices', 'maintenance_nag', 10);
    remove_action('network_admin_notices', 'site_admin_notice', 10);

    add_action('admin_print_styles',  'veyra_post_importer_scrub_notice_hooks', 0);
    add_action('admin_print_scripts', 'veyra_post_importer_scrub_notice_hooks', 0);
    add_action('in_admin_header',     'veyra_post_importer_scrub_notice_hooks', 0);
    add_action('admin_head',          'veyra_post_importer_emit_hide_css', 0);
}

function veyra_post_importer_scrub_notice_hooks() {
    remove_all_actions('admin_notices');
    remove_all_actions('all_admin_notices');
    remove_all_actions('network_admin_notices');
    remove_all_actions('user_admin_notices');
    global $wp_filter;
    foreach (['admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices'] as $hook) {
        if (isset($wp_filter[$hook])) {
            $wp_filter[$hook] = new WP_Hook();
        }
    }
}

function veyra_post_importer_emit_hide_css() {
    echo '<style type="text/css">
        .notice, .notice-warning, .notice-error, .notice-success, .notice-info,
        .updated, .error, .update-nag, .admin-notice, .notice-alt,
        div.notice, div.updated, div.error, div.update-nag,
        .wrap > .notice, .wrap > .updated, .wrap > .error,
        #adminmenu + .notice, #adminmenu + .updated, #adminmenu + .error,
        #wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error,
        #wpbody-content > .wrap > .notice, #wpbody-content > .wrap > .updated, #wpbody-content > .wrap > .error,
        #wpbody-content > .wrap > h1 + .notice,
        #update-nag, .update-nag, .update-php, .php-update-nag,
        .update-core-php, .maintenance-mode-notice,
        .plugin-update-tr, .plugins-update-tr, .plugin-update,
        .theme-update-message, .update-message, .updating-message,
        .activated, .deactivated, #deprecation-warning,
        .tgmpa, .tgmpa-notice, div.tgmpa, .tgmpa-update-message,
        .welcome-panel,
        .edit-post-header .components-notice-list,
        .edit-post-layout .components-notice,
        .edit-post-sidebar .components-notice,
        .components-snackbar-list,
        .components-notice-list .components-notice,
        .interface-interface-skeleton__notices,
        .edit-post-notices,
        .block-editor-warning,
        .components-notice.is-warning,
        .components-notice.is-error,
        .components-notice.is-success,
        .components-notice.is-info {
            display: none !important;
        }
    </style>';
}

// ---------------------------------------------------------------------------
// Form POST handler — f5607
// Processes the uploaded zip, creates a WP post/page, embeds images.
// Runs before render, so result can be shown inline.
// ---------------------------------------------------------------------------
function veyra_post_importer_handle_submit() {
    // Only run on POSTs that belong to us
    if (empty($_POST['veyra_post_importer_action']) || $_POST['veyra_post_importer_action'] !== 'f5607_import') {
        return null;
    }
    if (!current_user_can('edit_posts')) {
        return ['level' => 'error', 'message' => 'You do not have permission to create posts.'];
    }
    if (empty($_POST['veyra_post_importer_nonce']) || !wp_verify_nonce($_POST['veyra_post_importer_nonce'], 'veyra_post_importer_f5607')) {
        return ['level' => 'error', 'message' => 'Security check failed (invalid nonce). Reload the page and try again.'];
    }
    if (empty($_FILES['veyra_pack']['tmp_name']) || !empty($_FILES['veyra_pack']['error'])) {
        return ['level' => 'error', 'message' => 'No zip file uploaded or upload failed.'];
    }

    $post_type   = (isset($_POST['veyra_post_type']) && $_POST['veyra_post_type'] === 'page') ? 'page' : 'post';
    $post_status = (isset($_POST['veyra_post_status']) && $_POST['veyra_post_status'] === 'publish') ? 'publish' : 'draft';
    $image_style = (isset($_POST['veyra_image_display']) && $_POST['veyra_image_display'] === 'wp_medium') ? 'wp_medium' : 'plain';
    $alignment_mode = veyra_post_importer_resolve_alignment_mode($_POST['veyra_image_alignment'] ?? 'global_random_3');
    // SHOULD_REMAIN_ON_HOMEPAGE — TRUE unless the user picked the second radio.
    $sroh_true = !(isset($_POST['veyra_should_remain_on_homepage']) && $_POST['veyra_should_remain_on_homepage'] === 'false');
    // "use this site's custom media display scheme": the scheme comes from the
    // text box as submitted (so an unsaved edit applies to this run only).
    $scheme = null;
    if ($alignment_mode === 'site_scheme') {
        $sv = veyra_media_scheme_validate(wp_unslash($_POST['veyra_media_scheme_json'] ?? ''));
        if (isset($sv['error'])) {
            return ['level' => 'error', 'message' => 'Media display scheme: ' . $sv['error'] . ' Nothing was imported.'];
        }
        $scheme = $sv['scheme'];
    }

    $tmp_zip = $_FILES['veyra_pack']['tmp_name'];

    // Open zip
    $zip = new ZipArchive();
    if ($zip->open($tmp_zip) !== true) {
        return ['level' => 'error', 'message' => 'Could not open the uploaded zip.'];
    }

    // Extract article.txt
    $article_txt = $zip->getFromName('article.txt');
    if ($article_txt === false) {
        $zip->close();
        return ['level' => 'error', 'message' => 'article.txt not found in the zip.'];
    }

    // Parse the 3-section marker format
    $parsed = veyra_post_importer_parse_article_txt($article_txt);
    if (empty($parsed['page_title']) && empty($parsed['page_content'])) {
        $zip->close();
        return ['level' => 'error', 'message' => 'article.txt is missing both title and content sections.'];
    }

    // Extract images to WP uploads + register as media-library attachments.
    // We keep the attachment ID (not just the URL) so we can emit native-looking
    // <img> tags via wp_get_attachment_image() with the "medium" size — matches
    // what the WP media-library popup inserts.
    $upload_dir = wp_upload_dir();
    $image_assets = []; // each entry: ['id' => int|null, 'url' => string, 'alt' => string]
    $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry_name = $zip->getNameIndex($i);
        if ($entry_name === 'article.txt') continue;
        $ext = strtolower(pathinfo($entry_name, PATHINFO_EXTENSION));
        if (!in_array($ext, $image_exts, true)) continue;
        $data = $zip->getFromIndex($i);
        if ($data === false) continue;
        $filename = wp_unique_filename($upload_dir['path'], basename($entry_name));
        $dest = trailingslashit($upload_dir['path']) . $filename;
        if (file_put_contents($dest, $data) === false) continue;
        $full_url = trailingslashit($upload_dir['url']) . $filename;

        // Derive alt text + title from filename: separators → spaces, strip leading/trailing numbers.
        $alt_text = veyra_post_importer_derive_image_label(pathinfo($filename, PATHINFO_FILENAME));

        // Register as WP attachment so it appears in Media Library + has sized derivatives
        $attach_id = wp_insert_attachment([
            'post_title'     => $alt_text,
            'post_mime_type' => wp_check_filetype($filename)['type'] ?: 'image/' . $ext,
            'post_status'    => 'inherit',
            'post_excerpt'   => $alt_text, // caption
            'post_content'   => '',        // description
        ], $dest);
        if (!is_wp_error($attach_id) && $attach_id) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attach_id, $dest);
            wp_update_attachment_metadata($attach_id, $metadata);
            // Set the alt text on the attachment record — required for
            // wp_get_attachment_image() to emit alt="..." natively.
            update_post_meta($attach_id, '_wp_attachment_image_alt', $alt_text);
            $image_assets[] = ['id' => (int) $attach_id, 'url' => $full_url, 'alt' => $alt_text];
        } else {
            // Fallback: attachment registration failed → still embed raw URL
            $image_assets[] = ['id' => null, 'url' => $full_url, 'alt' => $alt_text];
        }
    }
    $zip->close();

    // Upsert: update an existing post if one is already associated with this backlink_id,
    // otherwise create a new one. (Stores tregnar_associated_linksharn_backlink_id meta.)
    $res = veyra_post_importer_upsert_post($parsed, $image_assets, $post_type, $post_status, $image_style, $alignment_mode, $scheme);
    if (!empty($res['error'])) {
        return ['level' => 'error', 'message' => $res['error']];
    }

    veyra_post_importer_apply_sroh_flag([(int) $res['post_id']], $sroh_true);

    return [
        'level'    => 'success',
        'message'  => sprintf(
            '%1$s %2$s (ID %3$d, status: %4$s). Images embedded: %5$d.%6$s should_remain_on_homepage set to %7$s.',
            $res['updated'] ? 'Updated existing' : 'Created',
            $post_type,
            $res['post_id'],
            $post_status,
            $res['images'],
            $res['backlink_id'] !== '' ? ' [backlink_id ' . $res['backlink_id'] . ']' : '',
            $sroh_true ? 'TRUE' : 'FALSE'
        ),
        'edit_url' => $res['edit_url'],
        'view_url' => $res['view_url'],
    ];
}

/**
 * Find an existing post associated with a linksharn backlink_id via post meta.
 * Returns post ID (int) or 0 if none.
 */
function veyra_post_importer_find_post_by_backlink_id($backlink_id) {
    $backlink_id = trim((string) $backlink_id);
    if ($backlink_id === '') return 0;
    $ids = get_posts([
        'post_type'   => 'any',
        'post_status' => 'any',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => 'tregnar_associated_linksharn_backlink_id',
        'meta_value'  => $backlink_id,
    ]);
    return !empty($ids) ? (int) $ids[0] : 0;
}

/**
 * Create or update a WP post from a parsed article + extracted images.
 * If a post already carries the tregnar_associated_linksharn_backlink_id meta matching
 * $parsed['linksharn_backlink_id'], that post is UPDATED (title, content, date) and its
 * permalink is re-rendered (post_name regenerated from the new title). Otherwise a new
 * post is created. The association meta is always (re)written.
 */
function veyra_post_importer_upsert_post($parsed, $image_assets, $post_type, $post_status, $image_style, $alignment_mode = 'global_random_3', $scheme = null) {
    // Site scheme: build a fresh per-post context (rolls the scheme's per_post
    // size/alignment for THIS post). It replaces $image_style, and the scheme
    // decides size, alignment, markup and link wrapping for every image.
    if ($alignment_mode === 'site_scheme' && is_array($scheme)) {
        $image_style = veyra_media_scheme_make_post_context($scheme);
    }
    // "per_post_random_3" is resolved HERE, once per post: pick one of
    // alignleft/alignright/aligncenter for THIS post only, and reuse it for
    // every image in this post. A different post in the same bulk import
    // rolls its own independent pick (this function runs once per post).
    if ($alignment_mode === 'per_post_random_3') {
        $choices = ['alignleft', 'alignright', 'aligncenter'];
        $alignment_mode = $choices[array_rand($choices)];
    }
    $content_with_images = veyra_post_importer_insert_images($parsed['page_content'], $image_assets, $image_style, $alignment_mode);
    $title       = $parsed['page_title'] ?: 'Birch Import (' . current_time('Y-m-d H:i') . ')';
    $backlink_id = isset($parsed['linksharn_backlink_id']) ? trim($parsed['linksharn_backlink_id']) : '';

    $existing_id = veyra_post_importer_find_post_by_backlink_id($backlink_id);

    $postarr = [
        'post_type'    => $post_type,
        'post_status'  => $post_status,
        'post_title'   => $title,
        'post_content' => $content_with_images,
    ];
    $scheduled = false;
    if (!empty($parsed['birch_frontend_date'])) {
        $ts = strtotime($parsed['birch_frontend_date']);
        if ($ts !== false) {
            // Interpret birch_frontend_date as the site's local wall-clock time
            // (WP runs with PHP default tz = UTC, so strtotime() of the string
            // gives us that wall-clock as a UTC timestamp). Store post_date as
            // local and derive the correct GMT value from it so scheduling works
            // on non-UTC sites too.
            $local_str = gmdate('Y-m-d H:i:s', $ts);
            $postarr['post_date']     = $local_str;
            $postarr['post_date_gmt'] = get_gmt_from_date($local_str);
            // Ensure the date change actually sticks when updating an existing post.
            $postarr['edit_date']     = true;

            // If the publish date is in the future and the user chose to publish,
            // schedule the post (WordPress would auto-flip 'publish' -> 'future',
            // but we set it explicitly so intent is unambiguous on both the
            // insert and update paths). Drafts are left as drafts.
            if ($post_status === 'publish') {
                $gmt_ts = strtotime($postarr['post_date_gmt'] . ' GMT');
                if ($gmt_ts !== false && ($gmt_ts - time()) >= MINUTE_IN_SECONDS) {
                    $postarr['post_status'] = 'future';
                    $scheduled = true;
                }
            }
        }
    }

    if ($existing_id) {
        $postarr['ID']        = $existing_id;
        $postarr['post_name'] = sanitize_title($title); // re-render permalink to match new title
        $post_id  = wp_update_post($postarr, true);
        $updated  = true;
    } else {
        $post_id  = wp_insert_post($postarr, true);
        $updated  = false;
    }
    if (is_wp_error($post_id)) {
        return ['error' => ($updated ? 'wp_update_post' : 'wp_insert_post') . ' failed: ' . $post_id->get_error_message()];
    }

    if ($backlink_id !== '') {
        update_post_meta($post_id, 'tregnar_associated_linksharn_backlink_id', $backlink_id);
    }

    return [
        'post_id'        => (int) $post_id,
        'updated'        => $updated,
        'title'          => $title,
        'backlink_id'    => $backlink_id,
        'images'         => count($image_assets),
        'scheduled'      => $scheduled,
        'scheduled_date' => $scheduled ? get_post_field('post_date', $post_id) : '',
        'edit_url'       => get_edit_post_link($post_id, ''),
        'view_url'       => get_permalink($post_id),
    ];
}

/**
 * Extract a set of zip image entries into WP uploads + register as media-library
 * attachments. $entry_names = array of exact zip entry names (image files only).
 * Returns array of ['id'=>int|null, 'url'=>string, 'alt'=>string].
 */
function veyra_post_importer_extract_zip_images($zip, array $entry_names) {
    $upload_dir   = wp_upload_dir();
    $image_assets = [];
    $image_exts   = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
    foreach ($entry_names as $entry_name) {
        $ext = strtolower(pathinfo($entry_name, PATHINFO_EXTENSION));
        if (!in_array($ext, $image_exts, true)) continue;
        $data = $zip->getFromName($entry_name);
        if ($data === false) continue;
        $filename = wp_unique_filename($upload_dir['path'], basename($entry_name));
        $dest = trailingslashit($upload_dir['path']) . $filename;
        if (file_put_contents($dest, $data) === false) continue;
        $full_url = trailingslashit($upload_dir['url']) . $filename;
        $alt_text = veyra_post_importer_derive_image_label(pathinfo($filename, PATHINFO_FILENAME));
        $attach_id = wp_insert_attachment([
            'post_title'     => $alt_text,
            'post_mime_type' => wp_check_filetype($filename)['type'] ?: 'image/' . $ext,
            'post_status'    => 'inherit',
            'post_excerpt'   => $alt_text,
            'post_content'   => '',
        ], $dest);
        if (!is_wp_error($attach_id) && $attach_id) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attach_id, $dest);
            wp_update_attachment_metadata($attach_id, $metadata);
            update_post_meta($attach_id, '_wp_attachment_image_alt', $alt_text);
            $image_assets[] = ['id' => (int) $attach_id, 'url' => $full_url, 'alt' => $alt_text];
        } else {
            $image_assets[] = ['id' => null, 'url' => $full_url, 'alt' => $alt_text];
        }
    }
    return $image_assets;
}

/**
 * f5608 - bulk import. Each top-level subfolder in the uploaded zip is one article:
 *   <folder>/article.txt  + <folder>/<images>
 * Upserts each (update if backlink_id already associated, else create), de-duping by
 * backlink_id within the run. Returns a result with a per-post list.
 */
function veyra_post_importer_handle_bulk_submit() {
    if (empty($_POST['veyra_post_importer_action']) || $_POST['veyra_post_importer_action'] !== 'f5608_bulk_import') {
        return null;
    }
    if (!current_user_can('edit_posts')) {
        return ['level' => 'error', 'message' => 'You do not have permission to create posts.'];
    }
    if (empty($_POST['veyra_post_importer_nonce']) || !wp_verify_nonce($_POST['veyra_post_importer_nonce'], 'veyra_post_importer_f5607')) {
        return ['level' => 'error', 'message' => 'Security check failed (invalid nonce). Reload and try again.'];
    }
    if (empty($_FILES['veyra_pack']['tmp_name']) || !empty($_FILES['veyra_pack']['error'])) {
        return ['level' => 'error', 'message' => 'No zip file uploaded or upload failed.'];
    }

    $post_type   = (isset($_POST['veyra_post_type']) && $_POST['veyra_post_type'] === 'page') ? 'page' : 'post';
    $post_status = (isset($_POST['veyra_post_status']) && $_POST['veyra_post_status'] === 'publish') ? 'publish' : 'draft';
    $image_style = (isset($_POST['veyra_image_display']) && $_POST['veyra_image_display'] === 'wp_medium') ? 'wp_medium' : 'plain';
    $alignment_mode = veyra_post_importer_resolve_alignment_mode($_POST['veyra_image_alignment'] ?? 'global_random_3');
    // SHOULD_REMAIN_ON_HOMEPAGE — TRUE unless the user picked the second radio.
    $sroh_true = !(isset($_POST['veyra_should_remain_on_homepage']) && $_POST['veyra_should_remain_on_homepage'] === 'false');
    // "use this site's custom media display scheme": the scheme comes from the
    // text box as submitted (so an unsaved edit applies to this run only).
    $scheme = null;
    if ($alignment_mode === 'site_scheme') {
        $sv = veyra_media_scheme_validate(wp_unslash($_POST['veyra_media_scheme_json'] ?? ''));
        if (isset($sv['error'])) {
            return ['level' => 'error', 'message' => 'Media display scheme: ' . $sv['error'] . ' Nothing was imported.'];
        }
        $scheme = $sv['scheme'];
    }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['veyra_pack']['tmp_name']) !== true) {
        return ['level' => 'error', 'message' => 'Could not open the uploaded zip.'];
    }

    // Group entries by top-level folder
    $folders = []; // folder => ['article' => entry|null, 'images' => [entry,...]]
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (substr($name, -1) === '/') continue; // skip dir entries
        $slash = strpos($name, '/');
        if ($slash === false) continue; // bulk pack content lives inside subfolders
        $folder = substr($name, 0, $slash);
        if (!isset($folders[$folder])) $folders[$folder] = ['article' => null, 'images' => []];
        $base = basename($name);
        if ($base === 'article.txt') $folders[$folder]['article'] = $name;
        else $folders[$folder]['images'][] = $name;
    }

    if (empty($folders)) {
        $zip->close();
        return ['level' => 'error', 'message' => 'No subfolders found in the zip. Expected one folder per article (each with article.txt).'];
    }

    $results      = [];
    $seen_ids     = [];
    $imported_ids = [];
    $created      = 0;
    $updated      = 0;
    $skipped      = 0;

    foreach ($folders as $folder => $parts) {
        if (empty($parts['article'])) { $skipped++; continue; }
        $raw = $zip->getFromName($parts['article']);
        if ($raw === false) { $skipped++; continue; }
        $parsed = veyra_post_importer_parse_article_txt($raw);
        if (empty($parsed['page_title']) && empty($parsed['page_content'])) { $skipped++; continue; }

        // De-dupe by backlink_id within this run
        $bid = trim((string) ($parsed['linksharn_backlink_id'] ?? ''));
        if ($bid !== '' && isset($seen_ids[$bid])) { $skipped++; continue; }
        if ($bid !== '') $seen_ids[$bid] = true;

        $image_assets = veyra_post_importer_extract_zip_images($zip, $parts['images']);
        $res = veyra_post_importer_upsert_post($parsed, $image_assets, $post_type, $post_status, $image_style, $alignment_mode, $scheme);
        if (!empty($res['error'])) {
            $results[] = ['error' => $res['error'], 'title' => $parsed['page_title'], 'folder' => $folder];
            $skipped++;
            continue;
        }
        if ($res['updated']) $updated++; else $created++;
        $imported_ids[] = (int) $res['post_id'];
        $results[] = $res;
    }
    $zip->close();

    veyra_post_importer_apply_sroh_flag($imported_ids, $sroh_true);

    return [
        'level'   => 'success',
        'bulk'    => true,
        'message' => sprintf(
            'Bulk import complete: %d created, %d updated, %d skipped (of %d folders). should_remain_on_homepage set to %s for %d post(s).',
            $created, $updated, $skipped, count($folders),
            $sroh_true ? 'TRUE' : 'FALSE',
            count($imported_ids)
        ),
        'results' => $results,
    ];
}

/**
 * Stamp the SHOULD_REMAIN_ON_HOMEPAGE choice onto everything this run imported.
 *
 * The flag lives in the single wp_option Veyra keeps as a { post_id => 1 } map,
 * where absence of a key IS false — so "set to FALSE" means unsetting the key,
 * which is what makes the choice stick when a re-import lands on a post that was
 * already flagged. Written once for the whole run rather than per post, since the
 * option is one shared blob.
 */
function veyra_post_importer_apply_sroh_flag(array $post_ids, $flag_true) {
    if (empty($post_ids)) {
        return;
    }
    $option = class_exists('Veyra') ? Veyra::VEYRA_SROH_OPTION : 'should_remain_on_homepage';
    $map    = get_option($option, []);
    if (!is_array($map)) {
        $map = [];
    }
    foreach ($post_ids as $id) {
        if ($flag_true) {
            $map[$id] = 1;
        } else {
            unset($map[$id]);
        }
    }
    update_option($option, $map);
}

/**
 * Convert an image filename stem into a clean human label used as both the
 * attachment post_title and the alt/caption text.
 *
 * Rules:
 *   1. Replace hyphens and underscores with spaces.
 *   2. Strip any leading tokens that are purely numeric digits.
 *   3. Strip any trailing tokens that are purely numeric digits.
 *
 * Examples:
 *   ac_repair_hub          → "ac repair hub"
 *   ac-repair-hub          → "ac repair hub"
 *   123_ac_repair_hub      → "ac repair hub"
 *   ac_repair_hub_184      → "ac repair hub"
 *   123_ac_repair_hub_184  → "ac repair hub"
 */
function veyra_post_importer_derive_image_label($filename_stem) {
    $label  = str_replace(['-', '_'], ' ', $filename_stem);
    $tokens = explode(' ', $label);
    // Strip leading purely-numeric tokens
    while (!empty($tokens) && ctype_digit($tokens[0])) {
        array_shift($tokens);
    }
    // Strip trailing purely-numeric tokens
    while (!empty($tokens) && ctype_digit((string) end($tokens))) {
        array_pop($tokens);
    }
    return trim(implode(' ', $tokens));
}

/**
 * Parse article.txt with the marker format:
 *   ### birch_frontend_date
 *   ...
 *   ### page_title
 *   ...
 *   ### page_content
 *   ...
 */
function veyra_post_importer_parse_article_txt($raw) {
    $out = ['linksharn_backlink_id' => '', 'birch_frontend_date' => '', 'page_title' => '', 'page_content' => ''];
    // Normalize line endings
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    // Split on markers
    if (preg_match('/###\s*linksharn\.backlink_id\s*\n([\s\S]*?)(?=\n###\s*birch_frontend_date|\n###\s*page_title|\s*$)/i', $raw, $m)) {
        $out['linksharn_backlink_id'] = trim($m[1]);
    }
    if (preg_match('/###\s*birch_frontend_date\s*\n([\s\S]*?)(?=\n###\s*page_title|\s*$)/i', $raw, $m)) {
        $out['birch_frontend_date'] = trim($m[1]);
    }
    if (preg_match('/###\s*page_title\s*\n([\s\S]*?)(?=\n###\s*page_content|\s*$)/i', $raw, $m)) {
        $out['page_title'] = trim($m[1]);
    }
    if (preg_match('/###\s*page_content\s*\n([\s\S]*?)$/i', $raw, $m)) {
        $out['page_content'] = trim($m[1]);
    }
    return $out;
}

/**
 * Insert images at "clean" positions in the content:
 *   - Only BETWEEN paragraph-like segments (never mid-paragraph)
 *   - Never directly after a heading (<h1>..<h6>)
 *   - Every image surrounded by blank lines for clean code-view rendering
 *
 * Content in our system is usually a mix of bare-text paragraphs separated
 * by blank lines, plus occasional <h2>/<h3> tags and inline anchors.
 */
function veyra_post_importer_insert_images($content, array $image_assets, $style = 'plain', $alignment_mode = 'global_random_3') {
    if (empty($image_assets)) return $content;
    if ($content === '') {
        $out = [];
        foreach ($image_assets as $asset) {
            $out[] = veyra_post_importer_format_image_block($asset, $style, $alignment_mode);
        }
        return implode("\n\n", $out);
    }

    // Split on blank lines into "segments" (paragraphs, headings, or inline blocks)
    $segments = preg_split("/\n\s*\n+/", $content);

    // Build list of valid "insert-after" indexes:
    //   - Not after the very last segment (avoid trailing image)
    //   - Not after a heading segment (<h1>..<h6>)
    //   - Not after a segment that is ALREADY an image
    $candidate_indexes = [];
    $count = count($segments);
    for ($i = 0; $i < $count - 1; $i++) {
        $seg = trim($segments[$i]);
        if ($seg === '') continue;
        if (preg_match('/^<h[1-6][\s>]/i', $seg)) continue;
        if (preg_match('/^<img\s/i', $seg)) continue;
        $candidate_indexes[] = $i;
    }

    if (empty($candidate_indexes)) {
        // Fallback: no safe positions — just append all images at the end
        $appended = $content;
        foreach ($image_assets as $asset) {
            $appended .= "\n\n" . veyra_post_importer_format_image_block($asset, $style, $alignment_mode);
        }
        return $appended;
    }

    // Shuffle candidate indexes and take the first N (or fewer if not enough)
    shuffle($candidate_indexes);
    $take = array_slice($candidate_indexes, 0, count($image_assets));
    sort($take);

    // Pair images with indexes in order
    $img_by_idx = [];
    foreach ($take as $n => $idx) {
        if (isset($image_assets[$n])) $img_by_idx[$idx] = $image_assets[$n];
    }

    // Rebuild the segment list with image blocks inserted after chosen indexes
    $rebuilt = [];
    foreach ($segments as $i => $seg) {
        $rebuilt[] = $seg;
        if (isset($img_by_idx[$i])) {
            $rebuilt[] = veyra_post_importer_format_image_block($img_by_idx[$i], $style, $alignment_mode);
        }
    }

    // Any images that didn't get a position → append at end
    $used = count($take);
    $remaining = array_slice($image_assets, $used);
    foreach ($remaining as $asset) {
        $rebuilt[] = veyra_post_importer_format_image_block($asset, $style, $alignment_mode);
    }

    // Join with blank-line separators — guarantees visual spacing in code view
    return implode("\n\n", $rebuilt);
}

/**
 * Resolve the raw ALIGNMENT OPTIONS POST value into an "effective" alignment_mode
 * threaded through upsert_post() → insert_images() → format_image_block().
 * The effective mode is one of:
 *   - the two per-image-random tokens (resolved fresh for every image)
 *   - the per-post-random token (passed through here unresolved; upsert_post()
 *     resolves it once per post, since this function only runs once per form
 *     submit and can't know post boundaries in a bulk import)
 *   - a literal alignment class name (already resolved — covers both the
 *     always-fixed options AND the "one random pick used everywhere" option,
 *     whose random choice is made exactly once, right here, per form submit —
 *     not once per image or per post).
 */
function veyra_post_importer_resolve_alignment_mode($raw) {
    switch ($raw) {
        case 'site_scheme':
        case 'per_image_random_4':
        case 'per_image_random_3':
        case 'per_post_random_3':
            return $raw;
        case 'fixed_left':
            return 'alignleft';
        case 'fixed_right':
            return 'alignright';
        case 'fixed_center':
            return 'aligncenter';
        case 'fixed_none':
        case 'alignnone':
            return 'alignnone';
        case 'global_random_3':
        default:
            // Default option: pick ONE of alignleft/alignright/aligncenter ONCE per
            // submission, and that same class is reused for every image in every
            // post processed by this run (single f5607 import = its one post;
            // bulk f5608 import = every post in the zip).
            $choices = ['alignleft', 'alignright', 'aligncenter'];
            return $choices[array_rand($choices)];
    }
}

/**
 * Pick the WP alignment class for one image, per the resolved alignment_mode.
 * $alignment_mode (already resolved by veyra_post_importer_resolve_alignment_mode()):
 *   - 'per_image_random_4' → randomly one of alignleft / alignright / aligncenter / alignnone, per image
 *   - 'per_image_random_3' → randomly one of alignleft / alignright / alignnone (no aligncenter), per image
 *   - anything else        → treated as an already-resolved, literal fixed class name
 */
function veyra_post_importer_pick_alignment_class($alignment_mode = 'global_random_3') {
    if ($alignment_mode === 'per_image_random_4') {
        $choices = ['alignleft', 'alignright', 'aligncenter', 'alignnone'];
        return $choices[array_rand($choices)];
    }
    if ($alignment_mode === 'per_image_random_3') {
        $choices = ['alignleft', 'alignright', 'alignnone'];
        return $choices[array_rand($choices)];
    }
    if (in_array($alignment_mode, ['alignleft', 'alignright', 'aligncenter', 'alignnone'], true)) {
        return $alignment_mode;
    }
    return 'alignnone'; // safe fallback
}

/**
 * Emit an <img> block for a single image asset.
 *
 * $asset shape: ['id' => int|null, 'url' => string, 'alt' => string]
 * $style:
 *   - 'plain'         → hand-rolled <img src alt /> using the full-size URL (default)
 *   - 'wp_medium'     → native WP markup via wp_get_attachment_image() at the
 *                       "medium" size, with {align} + size-medium + wp-image-{ID}
 *                       classes — matches what the WP media-library popup
 *                       inserts when user picks "Medium" size.
 * $alignment_mode: see veyra_post_importer_pick_alignment_class() — applies to both styles.
 */
function veyra_post_importer_format_image_block($asset, $style = 'plain', $alignment_mode = 'global_random_3') {
    // Site-scheme context object (see upsert_post) overrides style + alignment.
    if (is_object($style) && isset($style->scheme)) {
        return veyra_media_scheme_format_image($asset, $style);
    }
    $url   = isset($asset['url']) ? $asset['url'] : '';
    $alt   = isset($asset['alt']) ? $asset['alt'] : '';
    $id    = isset($asset['id'])  ? $asset['id']  : null;
    $align = veyra_post_importer_pick_alignment_class($alignment_mode);

    if ($style === 'wp_medium' && $id && function_exists('wp_get_attachment_image')) {
        // wp_get_attachment_image auto-adds 'wp-image-{ID}' class + width/height
        // attrs + srcset/sizes when applicable. We add '{align} size-medium'
        // to mirror what the native media-library popup generates on insert.
        $html = wp_get_attachment_image(
            $id,
            'medium',
            false,
            ['class' => $align . ' size-medium wp-image-' . (int) $id]
        );
        if (is_string($html) && $html !== '') {
            return $html;
        }
        // Fall through to plain if WP returned empty for some reason
    }

    // Plain / fallback behavior (original system) — still carries the alignment class
    return '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" class="' . esc_attr($align) . '" />';
}

// ---------------------------------------------------------------------------
// Page render
// ---------------------------------------------------------------------------
function veyra_post_importer_render_page() {
    $result = veyra_post_importer_handle_submit();
    if (!$result) { $result = veyra_post_importer_handle_bulk_submit(); }
    $nonce  = wp_create_nonce('veyra_post_importer_f5607');
    $self   = admin_url('admin.php?page=veyra_post_importer');
    // Generated + saved on first view if this site has no scheme yet.
    $site_scheme      = veyra_media_scheme_get_saved();
    $site_scheme_json = veyra_media_scheme_to_json($site_scheme);
    $scheme_nonce     = wp_create_nonce('veyra_media_scheme');
    $can_save_scheme  = current_user_can('manage_options');
    ?>
    <div class="wrap">
        <?php if (function_exists('veyra_plugin_manager_render_updater_grid')): ?>
            <!-- Same grid as the Veyra Plugin Updater screen, so Veyra can be updated from here. -->
            <div style="margin: 10px 0 22px 0;">
                <?php veyra_plugin_manager_render_updater_grid(); ?>
            </div>
        <?php endif; ?>
        <h1 style="margin-bottom: 20px;">Veyra Post Importer From Birch</h1>

        <?php if ($result): ?>
            <div style="padding: 12px 16px; border-radius: 4px; margin-bottom: 20px;
                        background: <?php echo $result['level'] === 'success' ? '#f0fdf4' : '#fef2f2'; ?>;
                        border: 1px solid <?php echo $result['level'] === 'success' ? '#86efac' : '#fca5a5'; ?>;
                        color: <?php echo $result['level'] === 'success' ? '#166534' : '#991b1b'; ?>;">
                <strong><?php echo $result['level'] === 'success' ? '✓ Success' : '✗ Error'; ?>:</strong>
                <?php echo esc_html($result['message']); ?>
                <?php if ($result['level'] === 'success' && !empty($result['edit_url'])): ?>
                    <div style="margin-top: 8px;">
                        <a href="<?php echo esc_url($result['edit_url']); ?>">Edit post</a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url($result['view_url']); ?>" target="_blank">View post</a>
                    </div>
                <?php endif; ?>
                <?php if (!empty($result['bulk']) && !empty($result['results'])): ?>
                    <div style="margin-top: 10px;">
                        <strong>Imported posts — click "edit" to open each in WP:</strong>
                        <ul style="margin-top: 6px;">
                        <?php foreach ($result['results'] as $r): ?>
                            <?php if (!empty($r['error'])): ?>
                                <li style="color:#991b1b;">✗ <?php echo esc_html($r['title'] ?: $r['folder']); ?> — <?php echo esc_html($r['error']); ?></li>
                            <?php else: ?>
                                <li>
                                    <?php echo $r['updated'] ? '↻ updated' : '＋ created'; ?>:
                                    <strong><?php echo esc_html($r['title']); ?></strong>
                                    <?php if ($r['backlink_id'] !== ''): ?><span style="color:#666;">[backlink_id <?php echo esc_html($r['backlink_id']); ?>]</span><?php endif; ?>
                                    &nbsp;<a href="<?php echo esc_url($r['edit_url']); ?>">edit</a>
                                    &nbsp;|&nbsp;<a href="<?php echo esc_url($r['view_url']); ?>" target="_blank">view</a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url($self); ?>" enctype="multipart/form-data" style="max-width: 1360px;">
            <input type="hidden" name="veyra_post_importer_nonce" value="<?php echo esc_attr($nonce); ?>" />

            <!-- Two columns so the import pack + run buttons sit beside the options
                 instead of below them, keeping the whole screen visible without a
                 scroll. Wraps back to one column on a narrow viewport. -->
            <div style="display: flex; align-items: flex-start; gap: 28px; flex-wrap: wrap;">
            <div style="flex: 1 1 620px; min-width: 420px;">

            <fieldset style="margin-bottom: 16px;">
                <legend style="font-weight: 600; margin-bottom: 6px;">Post type</legend>
                <label style="margin-right: 16px;">
                    <input type="radio" name="veyra_post_type" value="post" checked /> wp post
                </label>
                <label>
                    <input type="radio" name="veyra_post_type" value="page" /> wp page
                </label>
            </fieldset>

            <fieldset style="margin-bottom: 16px;">
                <legend style="font-weight: 600; margin-bottom: 6px;">Post status</legend>
                <label style="margin-right: 16px;">
                    <input type="radio" name="veyra_post_status" value="draft" /> draft
                </label>
                <label>
                    <input type="radio" name="veyra_post_status" value="publish" checked /> publish
                </label>
            </fieldset>

            <fieldset style="margin-bottom: 16px;">
                <legend style="font-weight: 600; margin-bottom: 6px;">IMAGE DISPLAY OPTIONS</legend>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_image_display" value="plain" checked />
                    nothing special (default - current system)
                </label>
                <label style="display: block;">
                    <input type="radio" name="veyra_image_display" value="wp_medium" />
                    use medium wp native settings
                </label>
                <p style="color: #666; font-size: 12px; margin-top: 4px;">
                    <code>nothing special</code> = <code>&lt;img src alt /&gt;</code> using the full-size URL.<br>
                    <code>use medium wp native settings</code> = emits via <code>wp_get_attachment_image($id, 'medium', …)</code> with <code>class="{align} size-medium wp-image-{ID}"</code> + explicit width/height — identical to what the WP media-library popup generates when you pick "Medium".
                </p>
            </fieldset>

            <fieldset style="margin-bottom: 16px;">
                <legend style="font-weight: 600; margin-bottom: 6px;">ALIGNMENT OPTIONS</legend>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_image_alignment" value="site_scheme" checked />
                    use this site's custom media display scheme
                </label>
                <div id="veyra-media-scheme-box" style="margin: 4px 0 12px 24px; padding: 10px 12px; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff;">
                    <textarea id="veyra-media-scheme-json" name="veyra_media_scheme_json" rows="14" spellcheck="false"
                              style="width: 100%; font-family: Menlo, Consolas, monospace; font-size: 12px; line-height: 1.45;"
                              data-saved="<?php echo esc_attr($site_scheme_json); ?>"><?php echo esc_textarea($site_scheme_json); ?></textarea>
                    <div id="veyra-media-scheme-summary" style="font-size: 12px; color: #1d2327; margin: 6px 0;" data-saved-summary="<?php echo esc_attr(veyra_media_scheme_describe($site_scheme)); ?>"><?php echo esc_html(veyra_media_scheme_describe($site_scheme)); ?></div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="button" id="veyra-media-scheme-random">randomly generate new scheme</button>
                        <button type="button" class="button button-primary" id="veyra-media-scheme-save" <?php disabled(!$can_save_scheme); ?>>save as this site's scheme</button>
                        <button type="button" class="button" id="veyra-media-scheme-revert">revert to saved</button>
                        <span id="veyra-media-scheme-status" style="font-size: 12px; color: #666;">saved</span>
                    </div>
                    <p style="color: #666; font-size: 12px; margin: 8px 0 0;">
                        Stored in the <code><?php echo esc_html(VEYRA_MEDIA_SCHEME_OPTION); ?></code> wp_option (no custom tables). Overrides IMAGE DISPLAY OPTIONS.
                        An import uses whatever is in this box when you click f5608. An unsaved edit applies to that run only and does not change the saved scheme.<br>
                        <code>image_size</code> / <code>alignment</code>: relative weights. Sizes: thumbnail, medium, medium_large, large, full. Alignments: alignleft, alignright, aligncenter, alignnone.
                        <code>*_pick</code>: per_post (one roll reused for every image in the post) or per_image.
                        <code>markup</code>: wp_native | plain. <code>link_to</code>: none | media_file. <code>first_image</code>: null or {"size", "alignment"} pinned for each post's first image.
                    </p>
                </div>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_image_alignment" value="global_random_3" />
                    randomly choose alignleft/alignright/aligncenter and use for all images in all posts
                </label>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_image_alignment" value="per_post_random_3" />
                    &#9733; for each post, randomly choose one of these: alignleft/alignright/aligncenter, and use that for all images in that particular post
                </label>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_image_alignment" value="per_image_random_4" />
                    randomly pick alignleft/alignright/aligncenter/alignnone for each image
                </label>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_image_alignment" value="per_image_random_3" />
                    randomly choose from alignleft/alignright/alignnone for each individual image
                </label>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_image_alignment" value="alignnone" />
                    use alignnone for every image
                </label>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_image_alignment" value="fixed_none" />
                    use alignnone on all images in all posts
                </label>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_image_alignment" value="fixed_left" />
                    use alignleft on all images in all posts
                </label>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_image_alignment" value="fixed_right" />
                    use alignright on all images in all posts
                </label>
                <label style="display: block;">
                    <input type="radio" name="veyra_image_alignment" value="fixed_center" />
                    use aligncenter on all images in all posts
                </label>
                <p style="color: #666; font-size: 12px; margin-top: 4px;">
                    The top option (default) follows this site's saved media display scheme. The <code>randomly choose … use for all images in all posts</code> option picks one alignment ONCE per import and applies it to every image in every post processed by that import.
                </p>
            </fieldset>

            <fieldset style="margin-bottom: 16px;">
                <legend style="font-weight: 600; margin-bottom: 6px;">SHOULD_REMAIN_ON_HOMEPAGE</legend>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="veyra_should_remain_on_homepage" value="true" checked />
                    set flag to TRUE for should_remain_on_homepage
                </label>
                <label style="display: block;">
                    <input type="radio" name="veyra_should_remain_on_homepage" value="false" />
                    set flag to FALSE for should_remain_on_homepage
                </label>
                <p style="color: #666; font-size: 12px; margin-top: 4px;">
                    Applied by <strong>both</strong> f5607 and f5608 to every post they create or update.
                    FALSE clears the flag, which matters when a re-import lands on a post that already carries it.
                </p>
            </fieldset>

            </div><!-- /left column -->

            <div style="flex: 1 1 460px; min-width: 380px;">
            <div style="background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px;">

            <fieldset style="margin-bottom: 20px;">
                <legend style="font-weight: 600; margin-bottom: 6px;">Import pack (.zip from birch wizard)</legend>
                <input type="file" name="veyra_pack" accept=".zip" required />
                <p style="color: #666; font-size: 12px; margin-top: 4px;">
                    <strong>Bulk (f5608):</strong> a bulk pack — one <em>subfolder per article</em>, each containing its own article.txt (with <code>### linksharn.backlink_id</code> / <code>### birch_frontend_date</code> / <code>### page_title</code> / <code>### page_content</code>) + images.<br>
                    Both: if a post is already associated with the same <code>linksharn.backlink_id</code> (via the <code>tregnar_associated_linksharn_backlink_id</code> meta), it is <strong>updated</strong> instead of duplicated.
                </p>
            </fieldset>

            <div>
                <button type="submit" name="veyra_post_importer_action" value="f5608_bulk_import" class="button button-primary"
                        style="padding: 8px 20px; font-size: 14px; background: #2563eb; border-color: #1d4ed8; color: #fff;">
                    f5608 - bulk import birch-to-veyra article import pack
                </button>
            </div>

            </div><!-- /panel -->
            </div><!-- /right column -->
            </div><!-- /columns -->
        </form>
    </div>
    <script>
    (function () {
        var ta      = document.getElementById('veyra-media-scheme-json');
        var summary = document.getElementById('veyra-media-scheme-summary');
        var status  = document.getElementById('veyra-media-scheme-status');
        var box     = document.getElementById('veyra-media-scheme-box');
        if (!ta) return;
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce   = <?php echo wp_json_encode($scheme_nonce); ?>;

        function setStatus(text, color) { status.textContent = text; status.style.color = color || '#666'; }
        function refreshDirty() {
            if (ta.value.trim() === ta.getAttribute('data-saved').trim()) setStatus('saved', '#166534');
            else setStatus('unsaved changes: this box is used for the next import, saved scheme unchanged', '#b45309');
        }
        function post(action, extra) {
            var body = new URLSearchParams(Object.assign({ action: action, nonce: nonce }, extra || {}));
            return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); });
        }
        ta.addEventListener('input', refreshDirty);

        document.getElementById('veyra-media-scheme-random').addEventListener('click', function () {
            setStatus('generating…');
            post('veyra_media_scheme_generate').then(function (res) {
                if (!res.success) { setStatus('error: ' + (res.data && res.data.message || 'failed'), '#991b1b'); return; }
                ta.value = res.data.json;
                summary.textContent = res.data.summary;
                refreshDirty();
            }).catch(function () { setStatus('error: request failed', '#991b1b'); });
        });

        document.getElementById('veyra-media-scheme-save').addEventListener('click', function () {
            setStatus('saving…');
            post('veyra_media_scheme_save', { scheme: ta.value }).then(function (res) {
                if (!res.success) { setStatus('not saved: ' + (res.data && res.data.message || 'failed'), '#991b1b'); return; }
                ta.value = res.data.json;
                ta.setAttribute('data-saved', res.data.json);
                summary.setAttribute('data-saved-summary', res.data.summary);
                summary.textContent = res.data.summary;
                refreshDirty();
            }).catch(function () { setStatus('error: request failed', '#991b1b'); });
        });

        document.getElementById('veyra-media-scheme-revert').addEventListener('click', function () {
            ta.value = ta.getAttribute('data-saved');
            summary.textContent = summary.getAttribute('data-saved-summary');
            refreshDirty();
        });

        // Show the scheme box only while its radio is selected.
        var radios = document.querySelectorAll('input[name="veyra_image_alignment"]');
        function syncBox() {
            var sel = document.querySelector('input[name="veyra_image_alignment"]:checked');
            box.style.display = (sel && sel.value === 'site_scheme') ? '' : 'none';
        }
        radios.forEach(function (r) { r.addEventListener('change', syncBox); });
        syncBox();
        refreshDirty();
    })();
    </script>
    <?php
}
