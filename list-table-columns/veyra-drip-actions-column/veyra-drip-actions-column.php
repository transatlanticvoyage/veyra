<?php
/**
 * Veyra — "veyra drip actions" column on the posts/pages list tables.
 *
 * Adds a column immediately to the right of the native "date" column on
 * /wp-admin/edit.php (and edit.php?post_type=page, and any other list table
 * that uses the standard column filters), showing whatever drip actions are
 * currently QUEUED for each row's post.
 *
 * Reads wp_veyra_post_drip_actions — the queue behind the "Veyra Post Drip
 * Actions" editor sidebar box. All pending rows for the whole screen are
 * fetched in ONE query, primed on the first cell render, rather than querying
 * per row.
 *
 * Kept entirely in this file to avoid cluttering veyra.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Column key used in the list-table column arrays. */
if (!defined('VEYRA_DDC_COLUMN_KEY')) {
    define('VEYRA_DDC_COLUMN_KEY', 'veyra_drip_actions');
}

// ---------------------------------------------------------------------------
// Column registration
// ---------------------------------------------------------------------------

// Priority 100 so this runs after other plugins have added their columns,
// leaving ours at the far right in practice.
add_filter('manage_posts_columns', 'veyra_ddc_add_column', 100);
add_filter('manage_pages_columns', 'veyra_ddc_add_column', 100);

/**
 * Insert the column directly after "date".
 *
 * Rebuilt key-by-key rather than appended, so the column lands right of "date"
 * even if something else has added columns beyond it. Falls back to appending
 * when there is no date column at all (some custom post types hide it).
 */
function veyra_ddc_add_column($columns) {
    if (!is_array($columns) || isset($columns[VEYRA_DDC_COLUMN_KEY])) {
        return $columns;
    }

    // Two lines to keep the column narrow — WP prints header labels unescaped,
    // so the <br> is honoured.
    $label = 'veyra drip<br>actions';

    if (!isset($columns['date'])) {
        $columns[VEYRA_DDC_COLUMN_KEY] = $label;
        return $columns;
    }

    $rebuilt = array();
    foreach ($columns as $key => $value) {
        $rebuilt[$key] = $value;
        if ($key === 'date') {
            $rebuilt[VEYRA_DDC_COLUMN_KEY] = $label;
        }
    }
    return $rebuilt;
}

add_action('manage_posts_custom_column', 'veyra_ddc_render_column', 10, 2);
add_action('manage_pages_custom_column', 'veyra_ddc_render_column', 10, 2);

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------

/**
 * Pending drip actions for every post on the current screen, keyed by post_id.
 *
 * Primed once per request from the IDs already in the main query, so adding this
 * column costs exactly one extra query no matter how many rows are listed.
 */
function veyra_ddc_pending_map() {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = array();

    if (!function_exists('veyra_pda_table')) {
        return $map;
    }

    $post_ids = array();
    if (!empty($GLOBALS['wp_query']->posts)) {
        foreach ($GLOBALS['wp_query']->posts as $p) {
            $post_ids[] = is_object($p) ? intval($p->ID) : intval($p);
        }
    }
    $post_ids = array_filter(array_unique($post_ids));
    if (!$post_ids) {
        return $map;
    }

    global $wpdb;
    $table = veyra_pda_table();
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return $map;
    }

    $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, post_id, action_value, scheduled_for_gmt, notes
           FROM {$table}
          WHERE status = 'pending' AND post_id IN ({$placeholders})
          ORDER BY scheduled_for_gmt ASC, id ASC",
        $post_ids
    ), ARRAY_A);

    foreach ((array) $rows as $row) {
        $map[intval($row['post_id'])][] = $row;
    }
    return $map;
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/** Human label for an action's target status. Mirrors the drip box's wording. */
function veyra_ddc_action_label($action_value) {
    $map = array(
        'draft'   => 'to draft',
        'trash'   => 'to trash',
        'publish' => 'to published',
        'private' => 'to private',
        'pending' => 'to pending review',
    );
    return isset($map[$action_value]) ? $map[$action_value] : ('to ' . $action_value);
}

function veyra_ddc_render_column($column, $post_id) {
    if ($column !== VEYRA_DDC_COLUMN_KEY) {
        return;
    }
    veyra_ddc_print_styles();

    $map     = veyra_ddc_pending_map();
    $actions = isset($map[intval($post_id)]) ? $map[intval($post_id)] : array();

    if (!$actions) {
        echo '<span class="veyra-ddc-none" title="No drip actions are queued for this post">&mdash;</span>';
        return;
    }

    $now = time();
    echo '<ul class="veyra-ddc-list">';
    foreach ($actions as $row) {
        $due     = strtotime($row['scheduled_for_gmt'] . ' UTC');
        $overdue = ($due <= $now);

        echo '<li class="veyra-ddc-item">';
        echo '<span class="veyra-ddc-action">' . esc_html(veyra_ddc_action_label($row['action_value'])) . '</span>';
        echo '<span class="veyra-ddc-when">'
            . esc_html(get_date_from_gmt($row['scheduled_for_gmt'], 'M j, Y g:i a')) . '</span>';

        if ($overdue) {
            // Pending but already due — WP-Cron has not fired it yet. Worth
            // surfacing: it usually means cron is not running on this site.
            echo '<span class="veyra-ddc-overdue">overdue &mdash; awaiting cron</span>';
        } else {
            echo '<span class="veyra-ddc-rel">in ' . esc_html(human_time_diff($now, $due)) . '</span>';
        }

        if (!empty($row['notes'])) {
            echo '<span class="veyra-ddc-note">&ldquo;' . esc_html($row['notes']) . '&rdquo;</span>';
        }

        echo '<a class="veyra-ddc-conduit" target="_blank" rel="noopener"'
            . ' title="Open row #' . intval($row['id']) . ' in the Drip Actions Jar"'
            . ' href="' . esc_url(add_query_arg(
                array('page' => 'veyra_drip_actions_jar', 'conduit_id' => intval($row['id'])),
                admin_url('admin.php')
            )) . '">conduit</a>';

        echo '</li>';
    }
    echo '</ul>';
}

/** Column styles, printed once per screen. */
function veyra_ddc_print_styles() {
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    ?>
    <style>
    .column-<?php echo esc_html(VEYRA_DDC_COLUMN_KEY); ?>{width:190px;}
    .veyra-ddc-none{color:#c3c4c7;}
    .veyra-ddc-list{margin:0;padding:0;list-style:none;}
    .veyra-ddc-item{margin:0 0 8px;padding:0 0 8px;border-bottom:1px solid #f0f0f1;
        line-height:1.4;}
    .veyra-ddc-item:last-child{margin-bottom:0;padding-bottom:0;border-bottom:none;}
    .veyra-ddc-item span{display:block;}
    .veyra-ddc-action{font-weight:600;color:#1d2327;font-size:12px;}
    .veyra-ddc-when{font-size:11px;color:#50575e;}
    .veyra-ddc-rel{font-size:11px;color:#8c8f94;}
    .veyra-ddc-overdue{font-size:11px;color:#d63638;font-weight:600;}
    .veyra-ddc-note{font-size:11px;color:#787c82;font-style:italic;}
    .veyra-ddc-conduit{display:inline-block !important;margin-top:3px;font-size:10px;
        line-height:1;text-decoration:none;color:#50575e;background:#f0f0f1;
        border:1px solid #c3c4c7;border-radius:9px;padding:3px 8px;font-weight:600;}
    .veyra-ddc-conduit:hover,.veyra-ddc-conduit:focus{background:#2271b1;
        border-color:#2271b1;color:#fff;}
    </style>
    <?php
}
