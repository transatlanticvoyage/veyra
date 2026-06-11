<?php
/**
 * Veyra — SM Redirect Manager admin screen.
 *
 * Self-contained feature: creates the wp_sm_redirects table, registers an admin
 * page at /wp-admin/admin.php?page=sm_redirect_manager under the Veyra menu,
 * provides a CRUD grid + CSV import + "generate from Structure-Medic data",
 * and runs the front-end 301 redirect engine.
 *
 * Kept entirely in this file to avoid cluttering veyra.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VEYRA_SMR_DB_VERSION', '1');

function veyra_smr_table() {
    global $wpdb;
    return $wpdb->prefix . 'sm_redirects';
}

/** Create/upgrade the wp_sm_redirects table when the version changes. */
function veyra_smr_maybe_create_table() {
    if (get_option('veyra_smr_db_version') === VEYRA_SMR_DB_VERSION) {
        return;
    }
    veyra_smr_create_table();
    update_option('veyra_smr_db_version', VEYRA_SMR_DB_VERSION);
}
add_action('init', 'veyra_smr_maybe_create_table');

function veyra_smr_create_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = veyra_smr_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_url varchar(1024) NOT NULL DEFAULT '',
  source_path varchar(512) NOT NULL DEFAULT '',
  target_url varchar(1024) NOT NULL DEFAULT '',
  redirect_type smallint(6) NOT NULL DEFAULT 301,
  wp_post_id bigint(20) unsigned NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  hits int(11) NOT NULL DEFAULT 0,
  last_hit_at datetime NULL,
  notes varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY source_path (source_path(191)),
  KEY is_active (is_active)
) {$charset};");
}
register_activation_hook(VEYRA_PLUGIN_PATH . 'veyra.php', 'veyra_smr_create_table');

/** Normalize any URL or path to a comparable request path: leading slash, no
 *  query/hash, no trailing slash (except root), URL-decoded. */
function veyra_smr_norm_path($url) {
    $p = trim((string) $url);
    if (preg_match('#^https?://#i', $p)) {
        $parts = wp_parse_url($p);
        $p = isset($parts['path']) ? $parts['path'] : '/';
    }
    $p = preg_replace('/[?#].*$/', '', $p);
    $p = '/' . ltrim(rawurldecode($p), '/');
    if (strlen($p) > 1) {
        $p = rtrim($p, '/');
    }
    return $p;
}

/** Like veyra_smr_norm_path but KEEPS the query string (e.g. ColdFusion
 *  showreport.cfm?reportid=495), so query-distinguished old URLs map to distinct
 *  targets. Used for storage + as the primary match (path-only is the fallback). */
function veyra_smr_norm_path_full($url) {
    $u = trim((string) $url);
    $u = preg_replace('/#.*$/', '', $u);
    $path = $u; $q = '';
    if (preg_match('#^https?://#i', $u)) {
        $parts = wp_parse_url($u);
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $q = isset($parts['query']) ? $parts['query'] : '';
    } else {
        $bits = explode('?', $u, 2);
        $path = $bits[0];
        $q = isset($bits[1]) ? $bits[1] : '';
    }
    $path = '/' . ltrim(rawurldecode($path), '/');
    if (strlen($path) > 1) {
        $path = rtrim($path, '/');
    }
    return $q !== '' ? $path . '?' . rawurldecode($q) : $path;
}

// ---------------------------------------------------------------------------
// Front-end 301 redirect engine
// ---------------------------------------------------------------------------
add_action('template_redirect', 'veyra_smr_do_redirect', 1);
function veyra_smr_do_redirect() {
    if (is_admin() || empty($_SERVER['REQUEST_URI'])) {
        return;
    }
    global $wpdb;
    $t = veyra_smr_table();
    $full = veyra_smr_norm_path_full($_SERVER['REQUEST_URI']);
    $path = veyra_smr_norm_path($_SERVER['REQUEST_URI']);
    if ($path === '' || $path === '/') {
        return;
    }
    // Prefer an exact match including the query string; fall back to path-only.
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t} WHERE is_active=1 AND source_path IN (%s,%s) ORDER BY (source_path=%s) DESC LIMIT 1",
        $full, $path, $full));
    if (!$row || empty($row->target_url)) {
        return;
    }
    // Self-loop guard: never redirect to the path we are already on. A directory-form
    // redirect like /organization -> /organization/ would otherwise loop forever,
    // because the normalizer strips the incoming trailing slash (/organization/ ->
    // /organization), which then re-matches the same row. Fall through to WordPress.
    $target_path = veyra_smr_norm_path((string) parse_url($row->target_url, PHP_URL_PATH));
    if ($target_path === $path || $target_path === $full) {
        return;
    }
    $wpdb->query($wpdb->prepare(
        "UPDATE {$t} SET hits=hits+1, last_hit_at=NOW() WHERE id=%d", $row->id));
    $code = intval($row->redirect_type);
    if (!in_array($code, array(301, 302, 307, 308), true)) {
        $code = 301;
    }
    wp_redirect($row->target_url, $code);
    exit;
}

// ---------------------------------------------------------------------------
// Build redirects from the Structure-Medic sm_* tables (old URL -> new permalink)
// ---------------------------------------------------------------------------
function veyra_smr_generate_from_sm() {
    global $wpdb;
    $t  = veyra_smr_table();
    $ps = $wpdb->prefix . 'sm_page_source';
    $ou = $wpdb->prefix . 'sm_original_urls';
    // Table guard.
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ou)) !== $ou) {
        return 0;
    }
    $rows = $wpdb->get_results(
        "SELECT o.original_url, o.redirect_http_code, ps.wp_post_id
         FROM {$ou} o JOIN {$ps} ps ON ps.id = o.page_source_id
         WHERE o.redirect_to_wp = 1");
    $count = 0;
    foreach ($rows as $r) {
        $target = get_permalink(intval($r->wp_post_id));
        if (!$target) {
            continue;
        }
        $source_path = veyra_smr_norm_path_full($r->original_url);
        if ($source_path === '' || $source_path === '/') {
            continue; // never redirect the homepage
        }
        // Skip if the old path already equals the new target path (no-op redirect).
        if ($source_path === veyra_smr_norm_path($target)) {
            continue;
        }
        $type = intval($r->redirect_http_code);
        if (!$type) {
            $type = 301;
        }
        $data = array(
            'source_url'    => $r->original_url,
            'source_path'   => $source_path,
            'target_url'    => $target,
            'redirect_type' => $type,
            'wp_post_id'    => intval($r->wp_post_id),
            'is_active'     => 1,
            'notes'         => 'generated from sm_original_urls',
            'updated_at'    => current_time('mysql'),
        );
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t} WHERE source_path=%s", $source_path));
        if ($existing) {
            $wpdb->update($t, $data, array('id' => intval($existing)));
        } else {
            $wpdb->insert($t, $data);
        }
        $count++;
    }
    return $count;
}

// REST trigger for generation (so it can be run headlessly with an app password).
add_action('rest_api_init', function () {
    register_rest_route('veyra/v1', '/sm-redirects-generate', array(
        'methods'             => 'POST',
        'callback'            => function () {
            return array('ok' => true, 'created' => veyra_smr_generate_from_sm());
        },
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ));
});

// ---------------------------------------------------------------------------
// Admin: menu, notice suppression, action handlers, page render
// ---------------------------------------------------------------------------
add_action('admin_menu', 'veyra_smr_register_menu', 20);
function veyra_smr_register_menu() {
    add_submenu_page(
        'veyra-hub-1',                 // parent (Veyra Hub 1)
        'SM Redirect Manager',         // page title
        'SM Redirect Manager',         // menu label
        'manage_options',              // capability
        'sm_redirect_manager',         // slug -> ?page=sm_redirect_manager
        'veyra_smr_render_page'        // callback
    );
}

/** Aggressive notice/warning/message suppression on this screen only. */
add_action('in_admin_header', 'veyra_smr_suppress_notices', 1);
function veyra_smr_suppress_notices() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'sm_redirect_manager') {
        return;
    }
    remove_all_actions('admin_notices');
    remove_all_actions('all_admin_notices');
    remove_all_actions('network_admin_notices');
    remove_all_actions('user_admin_notices');
    echo '<style>#wpbody-content .notice,#wpbody-content .updated,#wpbody-content .error,'
        . '#wpbody-content .update-nag,#wpbody-content div.notice,#wpbody-content .notice-warning,'
        . '#wpbody-content .notice-info,#wpbody-content .notice-success{display:none !important;}</style>';
}

add_action('admin_init', 'veyra_smr_handle_actions');
function veyra_smr_handle_actions() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'sm_redirect_manager') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $t = veyra_smr_table();

    // Add or edit a single redirect.
    if (isset($_POST['veyra_smr_save']) && check_admin_referer('veyra_smr_save', 'veyra_smr_nonce')) {
        $id     = intval($_POST['id'] ?? 0);
        $source = esc_url_raw(trim(wp_unslash($_POST['source_url'] ?? '')));
        $target = esc_url_raw(trim(wp_unslash($_POST['target_url'] ?? '')));
        $type   = intval($_POST['redirect_type'] ?? 301) ?: 301;
        $active = isset($_POST['is_active']) ? 1 : 0;
        $notes  = sanitize_text_field(wp_unslash($_POST['notes'] ?? ''));
        if ($source && $target) {
            $data = array(
                'source_url'    => $source,
                'source_path'   => veyra_smr_norm_path_full($source),
                'target_url'    => $target,
                'redirect_type' => $type,
                'is_active'     => $active,
                'notes'         => $notes,
                'updated_at'    => current_time('mysql'),
            );
            if ($id) {
                $wpdb->update($t, $data, array('id' => $id));
            } else {
                $wpdb->insert($t, $data);
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=sm_redirect_manager&saved=1'));
        exit;
    }

    // Delete (single).
    if (isset($_GET['delete']) && check_admin_referer('veyra_smr_delete_' . intval($_GET['delete']))) {
        $wpdb->delete($t, array('id' => intval($_GET['delete'])));
        wp_safe_redirect(admin_url('admin.php?page=sm_redirect_manager&deleted=1'));
        exit;
    }

    // Delete (bulk, from checkbox selection).
    if (isset($_POST['veyra_smr_bulk_delete']) && check_admin_referer('veyra_smr_bulk_delete', 'veyra_smr_bulk_nonce')) {
        $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? array())));
        $n = 0;
        if ($ids) {
            // $ids are all integers, so this IN() list is injection-safe.
            $n = $wpdb->query("DELETE FROM {$t} WHERE id IN (" . implode(',', $ids) . ")");
        }
        wp_safe_redirect(admin_url('admin.php?page=sm_redirect_manager&deleted=' . intval($n)));
        exit;
    }

    // CSV import.
    if (isset($_POST['veyra_smr_import']) && check_admin_referer('veyra_smr_import', 'veyra_smr_import_nonce')) {
        $n = veyra_smr_handle_csv_import();
        wp_safe_redirect(admin_url('admin.php?page=sm_redirect_manager&imported=' . intval($n)));
        exit;
    }

    // Generate from Structure-Medic data.
    if (isset($_POST['veyra_smr_generate']) && check_admin_referer('veyra_smr_generate', 'veyra_smr_generate_nonce')) {
        $n = veyra_smr_generate_from_sm();
        wp_safe_redirect(admin_url('admin.php?page=sm_redirect_manager&generated=' . intval($n)));
        exit;
    }

    // Independent feature: designate the "harper page" (link-coagulation target).
    // Stored as a published page ID in the verya_harper_page_for_link_coagulation option.
    if (isset($_POST['verya_harper_save']) && check_admin_referer('verya_harper_save', 'verya_harper_nonce')) {
        update_option('verya_harper_page_for_link_coagulation', intval($_POST['verya_harper_page_for_link_coagulation'] ?? 0));
        wp_safe_redirect(admin_url('admin.php?page=sm_redirect_manager&harper_saved=1'));
        exit;
    }

    // Bulk update: re-point every redirect whose target matches a given URL/path to a new target.
    // Matches by path (so "/about/", "/about", and "http://site/about/" all match the same rows);
    // also re-resolves wp_post_id to the new target so the mj_tf/mj_rd columns stay correct.
    if (isset($_POST['veyra_smr_bulk_target']) && check_admin_referer('veyra_smr_bulk_target', 'veyra_smr_bulk_target_nonce')) {
        global $wpdb;
        $old = trim((string) wp_unslash($_POST['bulk_old_target'] ?? ''));
        $new = trim((string) wp_unslash($_POST['bulk_new_target'] ?? ''));
        $count = 0;
        if ($old !== '' && $new !== '') {
            $old_path   = '/' . trim(strtolower((string) (parse_url($old, PHP_URL_PATH) ?: $old)), '/');
            $new_target = preg_match('#^https?://#i', $new) ? $new : home_url('/' . ltrim($new, '/'));
            $new_pid    = url_to_postid($new_target);
            foreach ($wpdb->get_results("SELECT id, target_url FROM {$t}") as $row) {
                $tp = '/' . trim(strtolower((string) (parse_url($row->target_url, PHP_URL_PATH) ?: $row->target_url)), '/');
                if ($tp === $old_path) {
                    $wpdb->update($t, array(
                        'target_url' => $new_target,
                        'wp_post_id' => ($new_pid ?: null),
                        'updated_at' => current_time('mysql'),
                    ), array('id' => intval($row->id)));
                    $count++;
                }
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=sm_redirect_manager&bulk_target_updated=' . intval($count)));
        exit;
    }

    // Bulk update active status: set is_active on every redirect whose target matches (by path).
    if (isset($_POST['veyra_smr_bulk_active']) && check_admin_referer('veyra_smr_bulk_active', 'veyra_smr_bulk_active_nonce')) {
        global $wpdb;
        $tgt    = trim((string) wp_unslash($_POST['bulk_active_target'] ?? ''));
        $status = (($_POST['bulk_active_status'] ?? '0') === '1') ? 1 : 0;
        $count  = 0;
        if ($tgt !== '') {
            $tgt_path = '/' . trim(strtolower((string) (parse_url($tgt, PHP_URL_PATH) ?: $tgt)), '/');
            foreach ($wpdb->get_results("SELECT id, target_url FROM {$t}") as $row) {
                $tp = '/' . trim(strtolower((string) (parse_url($row->target_url, PHP_URL_PATH) ?: $row->target_url)), '/');
                if ($tp === $tgt_path) {
                    $wpdb->update($t, array('is_active' => $status, 'updated_at' => current_time('mysql')), array('id' => intval($row->id)));
                    $count++;
                }
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=sm_redirect_manager&bulk_active_updated=' . intval($count)));
        exit;
    }
}

/** Import a CSV of: source_url, target_url, redirect_type(optional). */
function veyra_smr_handle_csv_import() {
    global $wpdb;
    $t = veyra_smr_table();
    if (empty($_FILES['veyra_smr_csv']['tmp_name']) || !is_uploaded_file($_FILES['veyra_smr_csv']['tmp_name'])) {
        return 0;
    }
    $fh = fopen($_FILES['veyra_smr_csv']['tmp_name'], 'r');
    if (!$fh) {
        return 0;
    }
    $count = 0;
    $first = true;
    while (($row = fgetcsv($fh)) !== false) {
        if (count(array_filter($row, 'strlen')) === 0) {
            continue;
        }
        // Skip a header row if present.
        if ($first) {
            $first = false;
            $joined = strtolower(implode(',', $row));
            if (strpos($joined, 'source') !== false && strpos($joined, 'target') !== false) {
                continue;
            }
        }
        $source = isset($row[0]) ? esc_url_raw(trim($row[0])) : '';
        $target = isset($row[1]) ? esc_url_raw(trim($row[1])) : '';
        $type   = isset($row[2]) ? (intval($row[2]) ?: 301) : 301;
        if (!$source || !$target) {
            continue;
        }
        $source_path = veyra_smr_norm_path_full($source);
        $data = array(
            'source_url'    => $source,
            'source_path'   => $source_path,
            'target_url'    => $target,
            'redirect_type' => $type,
            'is_active'     => 1,
            'notes'         => 'csv import',
            'updated_at'    => current_time('mysql'),
        );
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE source_path=%s", $source_path));
        if ($existing) {
            $wpdb->update($t, $data, array('id' => intval($existing)));
        } else {
            $wpdb->insert($t, $data);
        }
        $count++;
    }
    fclose($fh);
    return $count;
}

function veyra_smr_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    global $wpdb;
    $t  = veyra_smr_table();
    $ps = $wpdb->prefix . 'sm_page_source';
    $mm = $wpdb->prefix . 'sm_majestic_metrics';
    // Pull Majestic Trust Flow / Referring Domains for the page each redirect targets
    // (redirect.wp_post_id -> page_source -> majestic_metrics). GROUP BY keeps one row
    // per redirect even if a post has more than one source/metrics row.
    $rows = $wpdb->get_results(
        "SELECT r.*, MAX(m.trust_flow) AS mj_tf, MAX(m.external_referring_domains) AS mj_rd
         FROM {$t} r
         LEFT JOIN {$ps} ps ON ps.wp_post_id = r.wp_post_id
         LEFT JOIN {$mm} m  ON m.page_source_id = ps.id
         GROUP BY r.id
         ORDER BY r.id DESC"
    );
    $edit = null;
    if (isset($_GET['edit'])) {
        $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", intval($_GET['edit'])));
    }
    // Edit-form display: show clean paths; only show a full URL when the target is external (off-site).
    $smr_home_host = (string) parse_url(home_url(), PHP_URL_HOST);
    $smr_to_path = static function ($url) {
        $p   = parse_url((string) $url);
        $out = (isset($p['path']) && $p['path'] !== '') ? $p['path'] : '/';
        if (!empty($p['query']))    { $out .= '?' . $p['query']; }
        if (!empty($p['fragment'])) { $out .= '#' . $p['fragment']; }
        return $out;
    };
    // Target display: path only for internal targets, full URL only when external (off-site).
    $smr_target_disp = function ($url) use ($smr_home_host, $smr_to_path) {
        $h = (string) parse_url((string) $url, PHP_URL_HOST);
        return ($h === '' || strcasecmp($h, $smr_home_host) === 0)
            ? $smr_to_path($url)   // internal -> path only
            : (string) $url;       // external -> keep full URL
    };
    $src_display = $edit ? $smr_to_path($edit->source_url) : '';
    $tgt_display = $edit ? $smr_target_disp($edit->target_url) : '';
    $base = admin_url('admin.php?page=sm_redirect_manager');
    ?>
    <div class="wrap veyra-smr">
        <h1>SM Redirect Manager</h1>
        <?php
        if (isset($_GET['saved']))     { echo '<p class="veyra-smr-msg">Redirect saved.</p>'; }
        if (isset($_GET['deleted']))   { echo '<p class="veyra-smr-msg">Deleted ' . intval($_GET['deleted']) . ' redirect(s).</p>'; }
        if (isset($_GET['imported']))  { echo '<p class="veyra-smr-msg">Imported ' . intval($_GET['imported']) . ' redirect(s).</p>'; }
        if (isset($_GET['generated'])) { echo '<p class="veyra-smr-msg">Generated ' . intval($_GET['generated']) . ' redirect(s) from Structure-Medic data.</p>'; }
        if (isset($_GET['harper_saved'])) { echo '<p class="veyra-smr-msg">Harper page saved.</p>'; }
        if (isset($_GET['bulk_target_updated'])) { echo '<p class="veyra-smr-msg">Bulk-updated ' . intval($_GET['bulk_target_updated']) . ' redirect(s).</p>'; }
        if (isset($_GET['bulk_active_updated'])) { echo '<p class="veyra-smr-msg">Updated active status on ' . intval($_GET['bulk_active_updated']) . ' redirect(s).</p>'; }
        ?>

        <p><button type="button" class="button button-primary" id="veyra-smr-new">Create New</button></p>

        <div id="veyra-smr-form" style="<?php echo $edit ? '' : 'display:none;'; ?>">
            <form method="post">
                <?php wp_nonce_field('veyra_smr_save', 'veyra_smr_nonce'); ?>
                <input type="hidden" name="id" value="<?php echo $edit ? intval($edit->id) : 0; ?>">
                <table class="form-table">
                    <tr><th>source_url <span style="font-weight:400">(path on the old site)</span></th>
                        <td><input type="text" name="source_url" style="width:100%" value="<?php echo esc_attr($src_display); ?>"></td></tr>
                    <tr><th>target_url <span style="font-weight:400">(path; full URL only if redirecting off-site)</span></th>
                        <td><input type="text" name="target_url" style="width:100%" value="<?php echo esc_attr($tgt_display); ?>"></td></tr>
                    <tr><th>redirect_type</th>
                        <td><input type="number" name="redirect_type" style="width:90px" value="<?php echo $edit ? intval($edit->redirect_type) : 301; ?>"> <span style="font-weight:400">301 by default</span></td></tr>
                    <tr><th>is_active</th>
                        <td><input type="checkbox" name="is_active" value="1" <?php echo (!$edit || $edit->is_active) ? 'checked' : ''; ?>></td></tr>
                    <tr><th>notes</th>
                        <td><input type="text" name="notes" style="width:100%" value="<?php echo $edit ? esc_attr($edit->notes) : ''; ?>"></td></tr>
                </table>
                <p>
                    <button type="submit" name="veyra_smr_save" value="1" class="button button-primary"><?php echo $edit ? 'Update Redirect' : 'Save Redirect'; ?></button>
                    <a href="<?php echo esc_url($base); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>

        <?php
        // ---- Independent feature: designate the "harper page" (link-coagulation target) ----
        $harper_current = (int) get_option('verya_harper_page_for_link_coagulation', 0);
        $harper_pages   = get_posts(array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ));
        ?>
        <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;margin:18px 0;">
        <div class="veyra-harper" style="padding:14px 16px;border:1px solid #c3c4c7;background:#fff;border-radius:4px;flex:1 1 300px">
            <p style="margin:0 0 4px"><code style="font-size:13px">verya_harper_page_for_link_coagulation</code></p>
            <p style="margin:0 0 10px;color:#646970">Designate the <strong>harper page</strong> &mdash; the default page used for link coagulation (redirect target for link juice).</p>
            <form method="post">
                <?php wp_nonce_field('verya_harper_save', 'verya_harper_nonce'); ?>
                <select name="verya_harper_page_for_link_coagulation" style="width:100%;max-width:100%;padding:6px;font-family:monospace;box-sizing:border-box">
                    <option value="0">&mdash; none &mdash;</option>
                    <?php foreach ($harper_pages as $hp_pg):
                        $hp_perm  = get_permalink($hp_pg->ID);
                        $hp_title = ($hp_pg->post_title !== '') ? $hp_pg->post_title : '(no title)';
                        $hp_label = $hp_title . '  —  ' . $hp_perm;
                    ?>
                        <option value="<?php echo intval($hp_pg->ID); ?>" <?php selected($harper_current, $hp_pg->ID); ?>><?php echo esc_html($hp_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="verya_harper_save" value="1" class="button button-primary">Save</button>
                <?php if ($harper_current && ($hp_sel = get_post($harper_current)) && $hp_sel->post_status === 'publish'): ?>
                    <p style="margin:10px 0 0;color:#1d2327">Current harper page: <strong><?php echo esc_html(get_the_title($hp_sel)); ?></strong> &mdash;
                        <a href="<?php echo esc_url(get_permalink($hp_sel)); ?>" target="_blank"><?php echo esc_html(get_permalink($hp_sel)); ?></a></p>
                <?php endif; ?>
            </form>
        </div>

        <div class="veyra-bulk-target" style="padding:14px 16px;border:1px solid #c3c4c7;background:#fff;border-radius:4px;flex:1 1 300px">
            <p style="margin:0 0 4px;font-weight:600;font-size:14px">Bulk update all redirects for a specific target</p>
            <p style="margin:0 0 12px;color:#646970;font-size:12px">Re-point every redirect whose target is the first URL to the second (path or full URL).</p>
            <form method="post">
                <?php wp_nonce_field('veyra_smr_bulk_target', 'veyra_smr_bulk_target_nonce'); ?>
                <p style="margin:0 0 8px">
                    <label style="display:block;font-size:12px;color:#646970;margin-bottom:2px">existing target_url</label>
                    <input type="text" name="bulk_old_target" style="width:100%;padding:6px;font-family:monospace;box-sizing:border-box" placeholder="/about/">
                </p>
                <p style="margin:0 0 12px">
                    <label style="display:block;font-size:12px;color:#646970;margin-bottom:2px">new target_url</label>
                    <input type="text" name="bulk_new_target" style="width:100%;padding:6px;font-family:monospace;box-sizing:border-box" placeholder="/about-us/">
                </p>
                <button type="submit" name="veyra_smr_bulk_target" value="1" class="button button-primary">Execute changes</button>
            </form>
        </div>

        <div class="veyra-bulk-active" style="padding:14px 16px;border:1px solid #c3c4c7;background:#fff;border-radius:4px;flex:1 1 300px">
            <p style="margin:0 0 4px;font-weight:600;font-size:14px">Bulk update active status</p>
            <p style="margin:0 0 12px;color:#646970;font-size:12px">Set every redirect with this target to active or inactive (path or full URL).</p>
            <form method="post">
                <?php wp_nonce_field('veyra_smr_bulk_active', 'veyra_smr_bulk_active_nonce'); ?>
                <p style="margin:0 0 10px">
                    <label style="display:block;font-size:12px;color:#646970;margin-bottom:2px">target_url</label>
                    <input type="text" name="bulk_active_target" style="width:100%;padding:6px;font-family:monospace;box-sizing:border-box" placeholder="/harper/">
                </p>
                <p style="margin:0 0 12px;font-size:13px">
                    <label style="margin-right:16px"><input type="radio" name="bulk_active_status" value="1"> active</label>
                    <label><input type="radio" name="bulk_active_status" value="0" checked> inactive</label>
                </p>
                <button type="submit" name="veyra_smr_bulk_active" value="1" class="button button-primary">Execute changes</button>
            </form>
        </div>
        </div><!-- /widget flex row -->

        <h2>Existing Redirects (<?php echo count($rows); ?>)</h2>
        <form method="post" id="veyra-smr-bulk-form">
            <?php wp_nonce_field('veyra_smr_bulk_delete', 'veyra_smr_bulk_nonce'); ?>
            <p>
                <button type="submit" name="veyra_smr_bulk_delete" value="1" class="button button-link-delete" id="veyra-smr-bulk-delete-btn"<?php echo $rows ? '' : ' disabled'; ?>>Delete Selected</button>
                <span id="veyra-smr-sel-count" style="margin-left:8px;color:#646970;"></span>
            </p>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <td class="check-column" style="width:2.2em"><input type="checkbox" id="veyra-smr-select-all" title="Select all"></td>
                    <th style="width:50px">id</th>
                    <th style="width:70px;border-left:1px solid gray;border-right:1px solid gray" title="Majestic External Referring Domains of the target page">mj_rd</th>
                    <th style="width:60px;border-left:1px solid gray;border-right:1px solid gray" title="Majestic Trust Flow of the target page">mj_tf</th>
                    <th>source_path</th><th>target_url</th>
                    <th style="width:60px">type</th><th style="width:60px">active</th>
                    <th style="width:60px">hits</th><th style="width:120px">actions</th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="10">No redirects yet. Use <strong>Create New</strong>, import a CSV, or generate from Structure-Medic data below.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr class="<?php echo $r->is_active ? '' : 'veyra-smr-inactive'; ?>">
                        <th scope="row" class="check-column"><input type="checkbox" class="veyra-smr-cb" name="ids[]" value="<?php echo intval($r->id); ?>"></th>
                        <td><?php echo intval($r->id); ?></td>
                        <td style="border-left:1px solid gray;border-right:1px solid gray"><?php echo ($r->mj_rd !== null ? intval($r->mj_rd) : ''); ?></td>
                        <td style="border-left:1px solid gray;border-right:1px solid gray"><strong><?php echo ($r->mj_tf !== null ? intval($r->mj_tf) : ''); ?></strong></td>
                        <td>
                            <button type="button" class="button button-small veyra-smr-open" data-url="<?php echo esc_attr(home_url($r->source_path)); ?>">open</button>
                            <button type="button" class="button button-small veyra-smr-copy" data-url="<?php echo esc_attr(home_url($r->source_path)); ?>">copy</button>
                            <code><?php echo esc_html($r->source_path); ?></code>
                        </td>
                        <td><a href="<?php echo esc_url($r->target_url); ?>" target="_blank"><?php echo esc_html($smr_target_disp($r->target_url)); ?></a></td>
                        <td><?php echo intval($r->redirect_type); ?></td>
                        <td><?php echo $r->is_active ? 'yes' : '<span class="smr-status">inactive</span>'; ?></td>
                        <td><?php echo intval($r->hits); ?></td>
                        <td>
                            <a href="<?php echo esc_url(add_query_arg('edit', intval($r->id), $base)); ?>">edit</a> |
                            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('delete', intval($r->id), $base), 'veyra_smr_delete_' . intval($r->id))); ?>"
                               onclick="return confirm('Delete this redirect?');">delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>

        <hr style="margin:24px 0">
        <h2>Import / Generate</h2>
        <form method="post" enctype="multipart/form-data" style="margin-bottom:16px">
            <?php wp_nonce_field('veyra_smr_import', 'veyra_smr_import_nonce'); ?>
            <strong>CSV import</strong> &mdash; columns: <code>source_url, target_url, redirect_type</code> (type optional, defaults 301):
            <input type="file" name="veyra_smr_csv" accept=".csv">
            <button type="submit" name="veyra_smr_import" value="1" class="button">Import CSV</button>
        </form>
        <form method="post">
            <?php wp_nonce_field('veyra_smr_generate', 'veyra_smr_generate_nonce'); ?>
            <strong>Generate from Structure-Medic data</strong> &mdash; builds 301s from <code>wp_sm_original_urls</code> &rarr; each page's permalink:
            <button type="submit" name="veyra_smr_generate" value="1" class="button">Generate Redirects from SM Data</button>
        </form>
    </div>
    <style>
        .veyra-smr-msg{color:#0a7d28;font-weight:600;}
        .veyra-smr table.form-table th{width:220px;text-align:left;vertical-align:top;}
        .veyra-smr code{font-size:12px;}
        .veyra-smr table.wp-list-table thead th{cursor:pointer;}
        .veyra-smr .smr-arrow{color:#2271b1;}
        .veyra-smr tr.veyra-smr-inactive > td,
        .veyra-smr tr.veyra-smr-inactive > th{background:#fdecea !important;}
        .veyra-smr tr.veyra-smr-inactive code,
        .veyra-smr tr.veyra-smr-inactive a{color:#9a6a6a;}
        .veyra-smr tr.veyra-smr-inactive code{text-decoration:line-through;}
        .veyra-smr .smr-status{color:#b32d2e;font-weight:700;text-transform:uppercase;font-size:11px;letter-spacing:.04em;}
    </style>
    <script>
    /* Sortable columns: click any column header to sort the table (client-side; the table
       is unpaginated so this orders the full result set). Toggles asc/desc, auto-detects numbers. */
    (function(){
        var table = document.querySelector('.veyra-smr table.wp-list-table');
        if (!table) { return; }
        var headerRow = table.querySelector('thead tr');
        if (!headerRow) { return; }
        function cellVal(cell){
            if (!cell) { return ''; }
            var el = cell.querySelector('code, a');
            return ((el ? el.textContent : cell.textContent) || '').trim();
        }
        headerRow.querySelectorAll('th').forEach(function(th){
            if (th.textContent.trim().toLowerCase() === 'actions') { return; }
            th.title = (th.title ? th.title + ' — ' : '') + 'click to sort';
            th.addEventListener('click', function(){
                var tbody = table.querySelector('tbody');
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'))
                    .filter(function(r){ return r.cells.length > 1; });
                if (rows.length < 2) { return; }
                var idx = th.cellIndex;
                var dir = th.getAttribute('data-smr-dir') === 'asc' ? 'desc' : 'asc';
                headerRow.querySelectorAll('th').forEach(function(o){
                    o.removeAttribute('data-smr-dir');
                    var a = o.querySelector('.smr-arrow'); if (a) { a.remove(); }
                });
                th.setAttribute('data-smr-dir', dir);
                var numeric = rows.every(function(r){
                    var v = cellVal(r.cells[idx]);
                    return v === '' || /^-?\d+(\.\d+)?$/.test(v);
                });
                rows.sort(function(a, b){
                    var va = cellVal(a.cells[idx]), vb = cellVal(b.cells[idx]);
                    if (numeric) {
                        var na = (va === '' ? -Infinity : parseFloat(va));
                        var nb = (vb === '' ? -Infinity : parseFloat(vb));
                        return dir === 'asc' ? na - nb : nb - na;
                    }
                    return dir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
                });
                rows.forEach(function(r){ tbody.appendChild(r); });
                var arrow = document.createElement('span');
                arrow.className = 'smr-arrow';
                arrow.textContent = dir === 'asc' ? ' ▲' : ' ▼';
                th.appendChild(arrow);
            });
        });
    })();
    </script>
    <script>
    (function(){
        var b = document.getElementById('veyra-smr-new');
        var f = document.getElementById('veyra-smr-form');
        if (b && f) { b.addEventListener('click', function(){ f.style.display = (f.style.display === 'none' ? '' : 'none'); }); }

        // Open / copy the source path as a URL on THIS site (to test the redirect).
        document.addEventListener('click', function(e){
            var t = e.target;
            if (!t.classList) { return; }
            if (t.classList.contains('veyra-smr-open')) {
                e.preventDefault();
                window.open(t.getAttribute('data-url'), '_blank');
            } else if (t.classList.contains('veyra-smr-copy')) {
                e.preventDefault();
                var ta = document.createElement('textarea');
                ta.value = t.getAttribute('data-url') || '';
                ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.focus(); ta.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
                document.body.removeChild(ta);
                var orig = t.textContent;
                t.textContent = ok ? 'copied!' : 'copy failed';
                setTimeout(function(){ t.textContent = orig; }, 1200);
            }
        });

        // ---- Bulk select + delete (with double confirmation) ----
        var bulkForm  = document.getElementById('veyra-smr-bulk-form');
        var selectAll = document.getElementById('veyra-smr-select-all');
        function cbs(){ return bulkForm ? bulkForm.querySelectorAll('.veyra-smr-cb') : []; }
        function selectedCount(){ var n = 0; cbs().forEach(function(c){ if (c.checked) n++; }); return n; }
        function updateCount(){
            var n = selectedCount();
            var el = document.getElementById('veyra-smr-sel-count');
            if (el) { el.textContent = n ? (n + ' selected') : ''; }
            if (selectAll) {
                var total = cbs().length;
                selectAll.checked = (total > 0 && n === total);
                selectAll.indeterminate = (n > 0 && n < total);
            }
        }
        if (selectAll) {
            selectAll.addEventListener('change', function(){
                cbs().forEach(function(c){ c.checked = selectAll.checked; });
                updateCount();
            });
        }
        if (bulkForm) {
            bulkForm.addEventListener('change', function(e){
                if (e.target.classList && e.target.classList.contains('veyra-smr-cb')) { updateCount(); }
            });
            bulkForm.addEventListener('submit', function(e){
                var n = selectedCount();
                if (n === 0) {
                    e.preventDefault();
                    alert('No redirects selected. Tick the checkboxes for the redirects you want to delete.');
                    return;
                }
                if (!confirm('⚠️  Delete ' + n + ' selected redirect(s)?\n\nThis permanently removes them and cannot be undone.')) {
                    e.preventDefault();
                    return;
                }
                if (!confirm('Are you ABSOLUTELY sure?\n\nClick OK to permanently delete ' + n + ' redirect(s).')) {
                    e.preventDefault();
                    return;
                }
            });
        }
    })();
    </script>
    <?php
}
