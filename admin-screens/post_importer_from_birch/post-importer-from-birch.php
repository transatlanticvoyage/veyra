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

// ---------------------------------------------------------------------------
// Menu registration — submenu under "Veyra Hub 1"
// ---------------------------------------------------------------------------
add_action('admin_menu', 'veyra_post_importer_register_menu', 20);
function veyra_post_importer_register_menu() {
    add_submenu_page(
        'veyra-hub-1',
        'Post Importer from Birch',
        'Veyra Post Importer From Birch',
        'edit_posts',
        'veyra_post_importer',
        'veyra_post_importer_render_page'
    );
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

    // Extract images to WP uploads + collect URLs
    $upload_dir = wp_upload_dir();
    $image_urls = [];
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
        $url = trailingslashit($upload_dir['url']) . $filename;

        // Register as WP attachment so it appears in Media Library
        $attach_id = wp_insert_attachment([
            'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
            'post_mime_type' => wp_check_filetype($filename)['type'] ?: 'image/' . $ext,
            'post_status'    => 'inherit',
        ], $dest);
        if (!is_wp_error($attach_id) && $attach_id) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attach_id, $dest);
            wp_update_attachment_metadata($attach_id, $metadata);
        }
        $image_urls[] = $url;
    }
    $zip->close();

    // Insert images into content at safe positions
    $content_with_images = veyra_post_importer_insert_images($parsed['page_content'], $image_urls);

    // Build post
    $postarr = [
        'post_type'    => $post_type,
        'post_status'  => $post_status,
        'post_title'   => $parsed['page_title'] ?: 'Birch Import (' . current_time('Y-m-d H:i') . ')',
        'post_content' => $content_with_images,
    ];
    // Only override post_date if the frontend date parses cleanly; otherwise let WP use now()
    if (!empty($parsed['birch_frontend_date'])) {
        $ts = strtotime($parsed['birch_frontend_date']);
        if ($ts !== false) {
            $postarr['post_date']     = gmdate('Y-m-d H:i:s', $ts);
            $postarr['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
        }
    }

    $post_id = wp_insert_post($postarr, true);
    if (is_wp_error($post_id)) {
        return ['level' => 'error', 'message' => 'wp_insert_post failed: ' . $post_id->get_error_message()];
    }

    $edit_url = get_edit_post_link($post_id, '');
    $view_url = get_permalink($post_id);
    return [
        'level'    => 'success',
        'message'  => sprintf(
            'Created %1$s (ID %2$d, status: %3$s). Images embedded: %4$d.',
            $post_type,
            $post_id,
            $post_status,
            count($image_urls)
        ),
        'edit_url' => $edit_url,
        'view_url' => $view_url,
    ];
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
    $out = ['birch_frontend_date' => '', 'page_title' => '', 'page_content' => ''];
    // Normalize line endings
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    // Split on markers
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
function veyra_post_importer_insert_images($content, array $image_urls) {
    if (empty($image_urls)) return $content;
    if ($content === '') {
        return implode("\n\n", array_map('veyra_post_importer_format_image_block', $image_urls));
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
        foreach ($image_urls as $u) {
            $appended .= "\n\n" . veyra_post_importer_format_image_block($u);
        }
        return $appended;
    }

    // Shuffle candidate indexes and take the first N (or fewer if not enough)
    shuffle($candidate_indexes);
    $take = array_slice($candidate_indexes, 0, count($image_urls));
    sort($take);

    // Pair images with indexes in order
    $img_by_idx = [];
    foreach ($take as $n => $idx) {
        if (isset($image_urls[$n])) $img_by_idx[$idx] = $image_urls[$n];
    }

    // Rebuild the segment list with image blocks inserted after chosen indexes
    $rebuilt = [];
    foreach ($segments as $i => $seg) {
        $rebuilt[] = $seg;
        if (isset($img_by_idx[$i])) {
            $rebuilt[] = veyra_post_importer_format_image_block($img_by_idx[$i]);
        }
    }

    // Any images that didn't get a position → append at end
    $used = count($take);
    $remaining = array_slice($image_urls, $used);
    foreach ($remaining as $u) {
        $rebuilt[] = veyra_post_importer_format_image_block($u);
    }

    // Join with blank-line separators — guarantees visual spacing in code view
    return implode("\n\n", $rebuilt);
}

function veyra_post_importer_format_image_block($url) {
    $alt = pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_FILENAME);
    $alt = str_replace(['-', '_'], ' ', $alt);
    return '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" />';
}

// ---------------------------------------------------------------------------
// Page render
// ---------------------------------------------------------------------------
function veyra_post_importer_render_page() {
    $result = veyra_post_importer_handle_submit();
    $nonce  = wp_create_nonce('veyra_post_importer_f5607');
    $self   = admin_url('admin.php?page=veyra_post_importer');
    ?>
    <div class="wrap">
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
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url($self); ?>" enctype="multipart/form-data" style="max-width: 640px;">
            <input type="hidden" name="veyra_post_importer_action" value="f5607_import" />
            <input type="hidden" name="veyra_post_importer_nonce" value="<?php echo esc_attr($nonce); ?>" />

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
                    <input type="radio" name="veyra_post_status" value="draft" checked /> draft
                </label>
                <label>
                    <input type="radio" name="veyra_post_status" value="publish" /> publish
                </label>
            </fieldset>

            <fieldset style="margin-bottom: 20px;">
                <legend style="font-weight: 600; margin-bottom: 6px;">Import pack (.zip from birch wizard f5603)</legend>
                <input type="file" name="veyra_pack" accept=".zip" required />
                <p style="color: #666; font-size: 12px; margin-top: 4px;">
                    Expected contents: article.txt (with <code>### birch_frontend_date</code> / <code>### page_title</code> / <code>### page_content</code> sections) plus any image files.
                </p>
            </fieldset>

            <button type="submit" class="button button-primary"
                    style="padding: 8px 20px; font-size: 14px; background: #22c55e; border-color: #16a34a; color: #fff;">
                f5607 - import birch-to-veyra article import pack
            </button>
        </form>
    </div>
    <?php
}
