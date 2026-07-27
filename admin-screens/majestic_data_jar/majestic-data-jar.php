<?php
/**
 * Veyra — Majestic Data Jar admin screen.
 *
 * Read-only dump of every column and row in wp_sm_majestic_metrics, registered
 * at /wp-admin/admin.php?page=majestic_data_jar under the Veyra Hub 1 menu.
 *
 * Kept entirely in this file to avoid cluttering veyra.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

function veyra_mdj_table() {
    global $wpdb;
    return $wpdb->prefix . 'sm_majestic_metrics';
}

// ---------------------------------------------------------------------------
// Admin: menu, notice suppression, page render
// ---------------------------------------------------------------------------
add_action('admin_menu', 'veyra_mdj_register_menu', 20);
function veyra_mdj_register_menu() {
    add_submenu_page(
        'veyra-hub-1',                 // parent (Veyra Hub 1)
        'Majestic Data Jar',           // page title
        'Majestic Data Jar',           // menu label
        'manage_options',              // capability
        'majestic_data_jar',           // slug -> ?page=majestic_data_jar
        'veyra_mdj_render_page'        // callback
    );
}

/** Aggressive notice/warning/message suppression on this screen only. */
add_action('in_admin_header', 'veyra_mdj_suppress_notices', 1);
function veyra_mdj_suppress_notices() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'majestic_data_jar') {
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

function veyra_mdj_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    global $wpdb;
    $t = veyra_mdj_table();

    $table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t);
    $columns = array();
    $rows = array();
    if ($table_exists) {
        $desc = $wpdb->get_results("DESCRIBE {$t}");
        foreach ($desc as $col) {
            $columns[] = $col->Field;
        }
        $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id ASC", ARRAY_A);
    }
    ?>
    <div class="wrap veyra-mdj">
        <h1>Majestic Data Jar</h1>
        <p>Raw contents of <code><?php echo esc_html($t); ?></code>.</p>

        <?php if (!$table_exists): ?>
            <p><strong>Table does not exist yet.</strong> It is created by veyra's Structure-Medic DB upgrade routine (<code>veyra_sm_maybe_upgrade_db()</code>) — visit any admin page once to trigger it, or it will appear after the next Structure-Medic injection.</p>
        <?php else: ?>
            <h2>All Rows (<?php echo count($rows); ?>)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <th><?php echo esc_html(strtolower($col)); ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?php echo count($columns) ?: 1; ?>">No rows in this table yet.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <td><?php echo esc_html((string) $row[$col]); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
