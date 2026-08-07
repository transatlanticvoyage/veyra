<?php
/**
 * Veyra — Post Status Log Jar admin screen.
 *
 * Read-only dump of every column and row in wp_veyra_post_status_log (the audit
 * log behind the "Veyra Post Actions History" editor sidebar box), registered at
 * /wp-admin/admin.php?page=post_status_log_jar under the Veyra Hub 1 menu.
 *
 * Kept entirely in this file to avoid cluttering veyra.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Rows shown before the list is truncated. Overridable in the UI via ?limit=all. */
if (!defined('VEYRA_PSLJ_DEFAULT_LIMIT')) {
    define('VEYRA_PSLJ_DEFAULT_LIMIT', 500);
}

/** Full name of the table this jar displays. veyra_pah_table() is defined in
 *  veyra.php; fall back to the literal name if this file is ever loaded alone. */
function veyra_pslj_table() {
    if (function_exists('veyra_pah_table')) {
        return veyra_pah_table();
    }
    global $wpdb;
    return $wpdb->prefix . 'veyra_post_status_log';
}

// ---------------------------------------------------------------------------
// Admin: menu, notice suppression, page render
// ---------------------------------------------------------------------------
add_action('admin_menu', 'veyra_pslj_register_menu', 20);
function veyra_pslj_register_menu() {
    add_submenu_page(
        'veyra-hub-1',                  // parent (Veyra Hub 1)
        'Post Status Log Jar',          // page title
        'Post Status Log Jar',          // menu label
        'manage_options',               // capability
        'post_status_log_jar',          // slug -> ?page=post_status_log_jar
        'veyra_pslj_render_page'        // callback
    );
}

/** Aggressive notice/warning/message suppression on this screen only. */
add_action('in_admin_header', 'veyra_pslj_suppress_notices', 1);
function veyra_pslj_suppress_notices() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'post_status_log_jar') {
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

function veyra_pslj_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    global $wpdb;
    $t = veyra_pslj_table();

    $table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t);

    $columns    = array();
    $col_types  = array();
    $rows       = array();
    $total_rows = 0;
    $show_all   = (isset($_GET['limit']) && $_GET['limit'] === 'all');

    // "Conduit" arrival: a row id handed over by the Veyra Post Actions History
    // box in the post editor, so the user lands looking straight at the row they
    // clicked from.
    $conduit_id    = isset($_GET['conduit_id']) ? intval($_GET['conduit_id']) : 0;
    $conduit_found = false;

    if ($table_exists) {
        foreach ($wpdb->get_results("DESCRIBE {$t}") as $col) {
            $columns[]              = $col->Field;
            $col_types[$col->Field] = $col->Type . ($col->Null === 'NO' ? ' NOT NULL' : ' NULL');
        }
        $total_rows = intval($wpdb->get_var("SELECT COUNT(*) FROM {$t}"));

        // Newest first — this is an append-only audit log, so the recent end is
        // the interesting one. On a conduit arrival the target row is sorted to
        // the very top, which both puts it where the eye lands and guarantees it
        // is on the page even when it would otherwise fall outside the row cap.
        $order = $conduit_id
            ? $wpdb->prepare('ORDER BY (id = %d) DESC, id DESC', $conduit_id)
            : 'ORDER BY id DESC';

        $sql = "SELECT * FROM {$t} {$order}";
        if (!$show_all) {
            $sql .= ' LIMIT ' . intval(VEYRA_PSLJ_DEFAULT_LIMIT);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);

        if ($conduit_id) {
            foreach ($rows as $r) {
                if (intval($r['id']) === $conduit_id) {
                    $conduit_found = true;
                    break;
                }
            }
        }
    }
    ?>
    <div class="wrap veyra-pslj">
        <h1>Post Status Log Jar</h1>

        <p class="veyra-pslj-tablename"><?php echo esc_html($t); ?></p>

        <?php if (!$table_exists): ?>
            <p><strong>Table does not exist yet.</strong> It is created by
            <code>Veyra::veyra_editor_boxes_create_tables()</code> — deactivate and
            reactivate the Veyra plugin, or load any admin page once to let the
            <code>init</code> version check build it.</p>
        <?php else: ?>

            <?php if ($conduit_id): ?>
                <div class="veyra-pslj-conduit-banner">
                    <?php if ($conduit_found): ?>
                        <span class="veyra-pslj-star">&#9733;</span>
                        Arrived from the post editor &mdash; row
                        <strong>id&nbsp;<?php echo esc_html($conduit_id); ?></strong>
                        is highlighted and pinned to the top of the table.
                    <?php else: ?>
                        <span class="veyra-pslj-star">&#9733;</span>
                        Arrived from the post editor, but row
                        <strong>id&nbsp;<?php echo esc_html($conduit_id); ?></strong>
                        no longer exists in this table.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p class="veyra-pslj-count">
                <?php if ($show_all || $total_rows <= count($rows)): ?>
                    Showing all <strong><?php echo number_format_i18n($total_rows); ?></strong> row(s).
                <?php else: ?>
                    Showing the most recent <strong><?php echo number_format_i18n(count($rows)); ?></strong>
                    of <strong><?php echo number_format_i18n($total_rows); ?></strong> row(s).
                    <a href="<?php echo esc_url(add_query_arg('limit', 'all')); ?>">show all</a>
                <?php endif; ?>
            </p>

            <div class="veyra-pslj-scroll">
                <table class="wp-list-table widefat striped veyra-pslj-table">
                    <thead>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th title="<?php echo esc_attr($col_types[$col]); ?>">
                                <strong><?php echo esc_html(strtolower($col)); ?></strong>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="<?php echo count($columns) ?: 1; ?>">No rows in this table yet.</td></tr>
                    <?php else: foreach ($rows as $row):
                        $is_conduit = ($conduit_id && intval($row['id']) === $conduit_id);
                        $first_col  = true; ?>
                        <tr<?php echo $is_conduit ? ' class="veyra-pslj-conduit-row"' : ''; ?>>
                            <?php foreach ($columns as $col): ?>
                                <td>
                                    <?php if ($first_col && $is_conduit): ?>
                                        <span class="veyra-pslj-star"
                                              title="You came here from this item in the post editor">&#9733;</span>
                                    <?php endif; ?>
                                    <?php $first_col = false; ?>
                                    <?php if ($row[$col] === null): ?>
                                        <span class="veyra-pslj-null">NULL</span>
                                    <?php elseif ($row[$col] === ''): ?>
                                        <span class="veyra-pslj-empty">(empty)</span>
                                    <?php else: ?>
                                        <?php echo esc_html((string) $row[$col]); ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <style>
    .veyra-pslj-tablename{font-family:Menlo,Consolas,monospace;font-size:15px;font-weight:600;
        color:#1d2327;background:#f0f0f1;border-left:4px solid #2271b1;padding:8px 12px;
        display:inline-block;margin:4px 0 14px;}
    .veyra-pslj-count{margin:0 0 10px;color:#50575e;}
    .veyra-pslj-scroll{overflow-x:auto;max-width:100%;}
    .veyra-pslj-table{min-width:100%;width:auto;}
    .veyra-pslj-table th{white-space:nowrap;font-family:Menlo,Consolas,monospace;font-size:11px;}
    .veyra-pslj-table th strong{font-weight:700;}
    .veyra-pslj-table td{font-size:12px;vertical-align:top;max-width:340px;
        overflow-wrap:anywhere;word-break:break-word;}
    .veyra-pslj-null{color:#a7aaad;font-style:italic;}
    .veyra-pslj-empty{color:#c3c4c7;font-style:italic;}
    /* Conduit arrival: the row the user clicked through from. */
    .veyra-pslj-star{color:#d63638;font-size:14px;line-height:1;margin-right:4px;}
    .veyra-pslj-conduit-banner{background:#fcf0f1;border-left:4px solid #d63638;
        padding:10px 12px;margin:0 0 12px;max-width:900px;}
    .veyra-pslj-table tr.veyra-pslj-conduit-row > td{background:#fcf0f1 !important;
        border-top:2px solid #d63638;border-bottom:2px solid #d63638;font-weight:600;}
    </style>
    <?php
}
