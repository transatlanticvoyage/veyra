<?php
/**
 * Veyra — Page Change Drip Manager admin screen.
 *
 * Self-contained feature: registers an admin page at
 * /wp-admin/admin.php?page=page_change_drip_manager under the Veyra menu.
 *
 * Kept entirely in this file to avoid cluttering veyra.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Admin: menu, notice suppression, page render
// ---------------------------------------------------------------------------
add_action('admin_menu', 'veyra_pcdm_register_menu', 20);
function veyra_pcdm_register_menu() {
    add_submenu_page(
        'veyra-hub-1',                    // parent (Veyra Hub 1)
        'Page Change Drip Manager',       // page title
        'Page Change Drip Manager',       // menu label
        'manage_options',                 // capability
        'page_change_drip_manager',       // slug -> ?page=page_change_drip_manager
        'veyra_pcdm_render_page'          // callback
    );
}

/** Aggressive notice/warning/message suppression on this screen only. */
add_action('in_admin_header', 'veyra_pcdm_suppress_notices', 1);
function veyra_pcdm_suppress_notices() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'page_change_drip_manager') {
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

/** Truncate a value for compact table display: first 10 chars + "...". */
function veyra_pcdm_truncate($value) {
    $value = (string) $value;
    if (strlen($value) > 10) {
        return substr($value, 0, 10) . '...';
    }
    return $value;
}

// ---------------------------------------------------------------------------
// AJAX: save the client-computed veyra_switchover_date assignments.
// (The randomized drip-interval algorithm runs in JS; this just persists the
// resulting {post_id: unix_timestamp} map into the wp_options array — one
// veyra_switchover_date entry per post, upserted.)
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_assign_switchover', 'veyra_pcdm_ajax_assign_switchover');
function veyra_pcdm_ajax_assign_switchover() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_run_algo', 'nonce');

    $raw = isset($_POST['assignments']) ? wp_unslash($_POST['assignments']) : '';
    $assignments = json_decode($raw, true);
    if (!is_array($assignments) || !$assignments) {
        wp_send_json_error('no assignments provided', 400);
    }

    $switchover_date = get_option('veyra_switchover_date', array());
    if (!is_array($switchover_date)) {
        $switchover_date = array();
    }
    $count = 0;
    foreach ($assignments as $post_id => $timestamp) {
        $post_id   = intval($post_id);
        $timestamp = intval($timestamp);
        if ($post_id <= 0 || $timestamp <= 0) {
            continue;
        }
        $switchover_date[$post_id] = $timestamp;
        $count++;
    }
    update_option('veyra_switchover_date', $switchover_date, false);

    wp_send_json_success(array('count' => $count));
}

// ---------------------------------------------------------------------------
// AJAX: clear veyra_switchover_date (unset entirely, not just blank it out)
// for the selected post IDs.
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_clear_switchover', 'veyra_pcdm_ajax_clear_switchover');
function veyra_pcdm_ajax_clear_switchover() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_clear_switchover', 'nonce');

    $raw = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '';
    $ids = json_decode($raw, true);
    if (!is_array($ids) || !$ids) {
        wp_send_json_error('no ids provided', 400);
    }

    $switchover_date = get_option('veyra_switchover_date', array());
    if (!is_array($switchover_date)) {
        $switchover_date = array();
    }
    $count = 0;
    foreach ($ids as $id) {
        $id = intval($id);
        if ($id > 0 && isset($switchover_date[$id])) {
            unset($switchover_date[$id]);
            $count++;
        }
    }
    update_option('veyra_switchover_date', $switchover_date, false);

    wp_send_json_success(array('count' => $count));
}

// ---------------------------------------------------------------------------
// WP-Cron: periodically check veyra_switchover_date for due items and deploy
// their veyra_freshly_invented_content_before_deployment_to_live_post_content
// into post_content, mirroring how WP's own post-scheduler wakes up to
// publish scheduled posts.
// ---------------------------------------------------------------------------
add_filter('cron_schedules', 'veyra_pcdm_add_cron_interval');
function veyra_pcdm_add_cron_interval($schedules) {
    $schedules['veyra_pcdm_five_minutes'] = array(
        'interval' => 300,
        'display'  => 'Every 5 Minutes (Veyra Page Change Drip Manager)',
    );
    return $schedules;
}

add_action('init', 'veyra_pcdm_ensure_cron_scheduled');
function veyra_pcdm_ensure_cron_scheduled() {
    if (!wp_next_scheduled('veyra_pcdm_process_switchovers')) {
        wp_schedule_event(time(), 'veyra_pcdm_five_minutes', 'veyra_pcdm_process_switchovers');
    }
}

register_deactivation_hook(VEYRA_PLUGIN_PATH . 'veyra.php', 'veyra_pcdm_clear_cron_schedule');
function veyra_pcdm_clear_cron_schedule() {
    $timestamp = wp_next_scheduled('veyra_pcdm_process_switchovers');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'veyra_pcdm_process_switchovers');
    }
}

/**
 * Deploy the switchover for a specific set of post IDs, right now, regardless
 * of what their veyra_switchover_date says: copy
 * veyra_freshly_invented_content_before_deployment_to_live_post_content into
 * post_content, tag veyra_content_subspecies, and mark veyra_switchover_completed.
 * Shared by the WP-Cron due-date sweep and the manual "perform now" action.
 */
function veyra_pcdm_deploy_switchover($post_ids) {
    $result = array('deployed' => 0, 'skipped' => 0);
    if (!is_array($post_ids) || !$post_ids) {
        return $result;
    }

    $completed = get_option('veyra_switchover_completed', array());
    if (!is_array($completed)) {
        $completed = array();
    }
    $freshly_invented = get_option('veyra_freshly_invented_content_before_deployment_to_live_post_content', array());
    if (!is_array($freshly_invented)) {
        $freshly_invented = array();
    }
    $freshly_post_title = get_option('veyra_freshly_post_title', array());
    if (!is_array($freshly_post_title)) {
        $freshly_post_title = array();
    }
    $subspecies = get_option('veyra_content_subspecies', array());
    if (!is_array($subspecies)) {
        $subspecies = array();
    }

    $subspecies_changed = false;
    $completed_changed  = false;

    foreach ($post_ids as $post_id) {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            continue;
        }
        // Already deployed — skip.
        if (isset($completed[$post_id]) && $completed[$post_id] === 'DONE') {
            $result['skipped']++;
            continue;
        }
        // Nothing staged to deploy.
        if (!isset($freshly_invented[$post_id]) || trim((string) $freshly_invented[$post_id]) === '') {
            $result['skipped']++;
            continue;
        }

        // Copy the staged content into post_content, replacing whatever is there.
        // Also apply the staged replacement post_title, but only when one is set —
        // an empty/missing veyra_freshly_post_title leaves post_title untouched.
        $update_args = array(
            'ID'           => $post_id,
            'post_content' => $freshly_invented[$post_id],
        );
        if (isset($freshly_post_title[$post_id]) && trim((string) $freshly_post_title[$post_id]) !== '') {
            $update_args['post_title'] = $freshly_post_title[$post_id];
        }
        wp_update_post($update_args);

        $subspecies[$post_id] = 'new_freshly_invented_content';
        $subspecies_changed   = true;

        $completed[$post_id] = 'DONE';
        $completed_changed   = true;

        $result['deployed']++;
    }

    if ($subspecies_changed) {
        update_option('veyra_content_subspecies', $subspecies, false);
    }
    if ($completed_changed) {
        update_option('veyra_switchover_completed', $completed, false);
    }

    return $result;
}

add_action('veyra_pcdm_process_switchovers', 'veyra_pcdm_process_due_switchovers');
function veyra_pcdm_process_due_switchovers() {
    $switchover_date = get_option('veyra_switchover_date', array());
    if (!is_array($switchover_date) || !$switchover_date) {
        return;
    }
    $completed = get_option('veyra_switchover_completed', array());
    if (!is_array($completed)) {
        $completed = array();
    }

    $now = time();
    $due_ids = array();
    foreach ($switchover_date as $post_id => $timestamp) {
        $post_id = intval($post_id);
        if (isset($completed[$post_id]) && $completed[$post_id] === 'DONE') {
            continue;
        }
        if (intval($timestamp) > $now) {
            continue;
        }
        $due_ids[] = $post_id;
    }

    if ($due_ids) {
        veyra_pcdm_deploy_switchover($due_ids);
    }
}

// ---------------------------------------------------------------------------
// AJAX: "perform content switchover now" — runs the same deploy logic as the
// cron sweep, but immediately, for whatever post IDs the user selected.
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_switchover_now', 'veyra_pcdm_ajax_switchover_now');
function veyra_pcdm_ajax_switchover_now() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_switchover_now', 'nonce');

    $raw = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '';
    $ids = json_decode($raw, true);
    if (!is_array($ids) || !$ids) {
        wp_send_json_error('no ids provided', 400);
    }

    $result = veyra_pcdm_deploy_switchover($ids);
    wp_send_json_success($result);
}

/**
 * Undo a switchover for a specific set of post IDs: copy
 * veyra_cached_original_wayback_content back into post_content (overriding
 * whatever is currently there), and set veyra_content_subspecies back to
 * actual_copied_historical_content.
 */
function veyra_pcdm_revert_switchover($post_ids) {
    $result = array('reverted' => 0, 'skipped' => 0);
    if (!is_array($post_ids) || !$post_ids) {
        return $result;
    }

    $cached_original = get_option('veyra_cached_original_wayback_content', array());
    if (!is_array($cached_original)) {
        $cached_original = array();
    }
    $subspecies = get_option('veyra_content_subspecies', array());
    if (!is_array($subspecies)) {
        $subspecies = array();
    }

    $subspecies_changed = false;

    foreach ($post_ids as $post_id) {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            continue;
        }
        // Nothing cached to revert to.
        if (!isset($cached_original[$post_id]) || trim((string) $cached_original[$post_id]) === '') {
            $result['skipped']++;
            continue;
        }

        wp_update_post(array(
            'ID'           => $post_id,
            'post_content' => $cached_original[$post_id],
        ));

        $subspecies[$post_id] = 'actual_copied_historical_content';
        $subspecies_changed    = true;

        $result['reverted']++;
    }

    if ($subspecies_changed) {
        update_option('veyra_content_subspecies', $subspecies, false);
    }

    return $result;
}

// ---------------------------------------------------------------------------
// AJAX: "revert switchover" — undoes a switchover for the selected items by
// copying veyra_cached_original_wayback_content back into post_content and
// resetting veyra_content_subspecies to actual_copied_historical_content.
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_revert_switchover', 'veyra_pcdm_ajax_revert_switchover');
function veyra_pcdm_ajax_revert_switchover() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_revert_switchover', 'nonce');

    $raw = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '';
    $ids = json_decode($raw, true);
    if (!is_array($ids) || !$ids) {
        wp_send_json_error('no ids provided', 400);
    }

    $result = veyra_pcdm_revert_switchover($ids);
    wp_send_json_success($result);
}

function veyra_pcdm_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    global $wpdb;
    $posts = $wpdb->get_results(
        "SELECT ID, post_status, post_type, post_title, post_content
         FROM {$wpdb->posts}
         WHERE post_type IN ('post','page')
         AND post_status IN ('publish','future')
         ORDER BY ID DESC"
    );

    $species    = get_option('veyra_content_species', array());
    $subspecies = get_option('veyra_content_subspecies', array());
    $cached_original    = get_option('veyra_cached_original_wayback_content', array());
    $freshly_invented   = get_option('veyra_freshly_invented_content_before_deployment_to_live_post_content', array());
    $switchover_date    = get_option('veyra_switchover_date', array());
    $switchover_completed = get_option('veyra_switchover_completed', array());
    if (!is_array($species))            { $species = array(); }
    if (!is_array($subspecies))         { $subspecies = array(); }
    if (!is_array($cached_original))    { $cached_original = array(); }
    if (!is_array($freshly_invented))   { $freshly_invented = array(); }
    if (!is_array($switchover_date))    { $switchover_date = array(); }
    if (!is_array($switchover_completed)) { $switchover_completed = array(); }
    ?>
    <div class="wrap veyra-pcdm">
        <div class="veyra-pcdm-header-row">
            <h1>Page Change Drip Manager</h1>

            <div class="veyra-pcdm-select-group">
                <span class="veyra-pcdm-tooltip-wrap veyra-pcdm-heading-tooltip" tabindex="0">
                    <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                    <span class="veyra-pcdm-tooltip-popup veyra-pcdm-tooltip-popup--wide">
                        this selects all items where:<br>
                        veyra_content_species = content_direct_from_wayback<br>
                        veyra_content_subspecies = actual_copied_historical_content<br>
                        veyra_freshly_invented_content_before_deployment_to_live_post_content = NOT NULL/EMPTY
                    </span>
                </span>
                <button type="button" class="button" id="veyra-pcdm-select-drip-candidates">select all items that likely need drip changes</button>
            </div>

            <div class="veyra-pcdm-algo-group">
                <label>
                    total days to drip selection over:
                    <input type="text" id="veyra-pcdm-total-days" value="30">
                </label>
                <button type="button" class="button button-primary" id="veyra-pcdm-run-algo"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_run_algo')); ?>">run assignment algo for veyra_switchover_date</button>
            </div>

            <div class="veyra-pcdm-clear-group">
                <button type="button" class="button" id="veyra-pcdm-clear-switchover"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_clear_switchover')); ?>">clear switchover date to empty</button>
            </div>

            <div class="veyra-pcdm-now-group">
                <button type="button" class="button" id="veyra-pcdm-select-past-due">select items with switchover date in past</button>
                <button type="button" class="button button-primary" id="veyra-pcdm-switchover-now"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_switchover_now')); ?>">perform content switchover now</button>
                <button type="button" class="veyra-pcdm-revert-btn" id="veyra-pcdm-revert-switchover"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_revert_switchover')); ?>">revert switchover</button>
            </div>
        </div>

        <table class="wp-list-table veyra-pcdm-table">
            <thead>
                <tr>
                    <th class="check-column"><input type="checkbox" id="veyra-pcdm-select-all"></th>
                    <th><strong>post_id</strong></th>
                    <th><strong>post_status</strong></th>
                    <th><strong>post_type</strong></th>
                    <th><strong>post_title</strong></th>
                    <th><strong>post_content</strong></th>
                    <th><strong>tools</strong></th>
                    <th>
                        <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                            <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                            <span class="veyra-pcdm-tooltip-popup">
                                <button type="button" class="button button-small veyra-pcdm-copy" data-copy="veyra_cached_original_wayback_content">copy</button>
                                <code>veyra_cached_original_wayback_content</code>
                            </span>
                            <strong>veyra_cached_original...</strong>
                        </span>
                    </th>
                    <th>
                        <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                            <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                            <span class="veyra-pcdm-tooltip-popup">
                                <button type="button" class="button button-small veyra-pcdm-copy" data-copy="veyra_freshly_invented_content_before_deployment_to_live_post_content">copy</button>
                                <code>veyra_freshly_invented_content_before_deployment_to_live_post_content</code>
                            </span>
                            <strong>veyra_freshly_invented...</strong>
                        </span>
                    </th>
                    <th class="veyra-pcdm-col-species"><strong>veyra_content_species</strong></th>
                    <th class="veyra-pcdm-col-subspecies"><strong>veyra_content_subspecies</strong></th>
                    <th><strong>veyra_switchover_date</strong></th>
                    <th><strong>veyra_switchover_completed</strong></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$posts): ?>
                <tr><td colspan="13">No posts or pages found.</td></tr>
            <?php else: foreach ($posts as $p):
                $id = intval($p->ID);
                $original_val  = isset($cached_original[$id]) ? $cached_original[$id] : '';
                $invented_val  = isset($freshly_invented[$id]) ? $freshly_invented[$id] : '';
                $species_val   = isset($species[$id]) ? $species[$id] : '';
                $subspecies_val = isset($subspecies[$id]) ? $subspecies[$id] : '';
                $switchover_raw = isset($switchover_date[$id]) ? $switchover_date[$id] : '';
                $switchover_val = (is_numeric($switchover_raw) && intval($switchover_raw) > 0)
                    ? date('Y-m-d H:i:s', intval($switchover_raw))
                    : '';
                $completed_val = isset($switchover_completed[$id]) ? $switchover_completed[$id] : '';
                $invented_empty = (trim((string) $invented_val) === '') ? '1' : '0';
                $switchover_ts = (is_numeric($switchover_raw) && intval($switchover_raw) > 0) ? intval($switchover_raw) : '';
            ?>
                <tr>
                    <td class="check-column">
                        <input type="checkbox" class="veyra-pcdm-cb" value="<?php echo $id; ?>"
                            data-species="<?php echo esc_attr($species_val); ?>"
                            data-subspecies="<?php echo esc_attr($subspecies_val); ?>"
                            data-invented-empty="<?php echo esc_attr($invented_empty); ?>"
                            data-switchover-ts="<?php echo esc_attr($switchover_ts); ?>">
                    </td>
                    <td><?php echo $id; ?></td>
                    <td><?php echo esc_html($p->post_status); ?></td>
                    <td><?php echo esc_html($p->post_type); ?></td>
                    <td class="veyra-pcdm-col-title" title="<?php echo esc_attr($p->post_title); ?>"><?php echo esc_html($p->post_title); ?></td>
                    <td><?php echo esc_html(veyra_pcdm_truncate($p->post_content)); ?></td>
                    <td class="veyra-pcdm-col-tools">
                        <a class="button button-small" href="<?php echo esc_url(get_edit_post_link($id, 'raw')); ?>" target="_blank" rel="noopener">edit</a>
                        <a class="button button-small" href="<?php echo esc_url(get_permalink($id)); ?>" target="_blank" rel="noopener">FE</a>
                    </td>
                    <td><?php echo esc_html(veyra_pcdm_truncate($original_val)); ?></td>
                    <td><?php echo esc_html(veyra_pcdm_truncate($invented_val)); ?></td>
                    <td class="veyra-pcdm-col-species"><?php echo esc_html($species_val); ?></td>
                    <td class="veyra-pcdm-col-subspecies"><?php echo esc_html($subspecies_val); ?></td>
                    <td><?php echo esc_html($switchover_val); ?></td>
                    <td><?php echo esc_html($completed_val); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <div id="veyra-pcdm-modal-overlay" class="veyra-pcdm-modal-overlay">
            <div class="veyra-pcdm-modal-box">
                <h2>Perform content switchover now?</h2>
                <p>
                    This will immediately, for every selected item: copy
                    <code>veyra_freshly_invented_content_before_deployment_to_live_post_content</code>
                    into <code>post_content</code> (erasing what's currently there), set
                    <code>veyra_content_subspecies</code> to <code>new_freshly_invented_content</code>,
                    and mark <code>veyra_switchover_completed</code> as <code>DONE</code>.
                </p>
                <p class="veyra-pcdm-modal-warning">This cannot be undone from this screen.</p>
                <div class="veyra-pcdm-modal-actions">
                    <button type="button" class="veyra-pcdm-modal-btn veyra-pcdm-modal-cancel" id="veyra-pcdm-modal-cancel">Cancel</button>
                    <button type="button" class="veyra-pcdm-modal-btn veyra-pcdm-modal-confirm" id="veyra-pcdm-modal-confirm">Yes, perform switchover now</button>
                </div>
            </div>
        </div>
    </div>
    <style>
        .veyra-pcdm-header-row {
            display: flex;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 14px;
        }
        .veyra-pcdm-header-row h1 { margin: 0; padding-top: 6px; }
        .veyra-pcdm-select-group {
            display: flex;
            align-items: center;
            gap: 4px;
            padding-top: 10px;
        }
        .veyra-pcdm-algo-group {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
            padding-top: 8px;
        }
        .veyra-pcdm-algo-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }
        .veyra-pcdm-algo-group input[type="text"] {
            width: 60px;
        }
        .veyra-pcdm-clear-group {
            display: flex;
            align-items: center;
            padding-top: 10px;
        }
        .veyra-pcdm-now-group {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid gray;
            border-radius: 4px;
            margin-top: 6px;
        }
        .veyra-pcdm-revert-btn {
            background: maroon;
            color: #fff;
            border: 1px solid maroon;
            border-radius: 3px;
            padding: 0 10px;
            line-height: 2.15384615;
            min-height: 30px;
            font-size: 13px;
            cursor: pointer;
        }
        .veyra-pcdm-revert-btn:hover {
            background: #6b0000;
            border-color: #6b0000;
            color: #fff;
        }
        .veyra-pcdm-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 100000;
            align-items: center;
            justify-content: center;
        }
        .veyra-pcdm-modal-overlay.veyra-pcdm-modal-open {
            display: flex;
        }
        .veyra-pcdm-modal-box {
            background: #1d2327;
            color: #f0f0f1;
            border: 1px solid #b32d2e;
            border-radius: 6px;
            padding: 24px 28px;
            max-width: 480px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        }
        .veyra-pcdm-modal-box h2 {
            margin: 0 0 12px;
            color: #fff;
            font-size: 18px;
        }
        .veyra-pcdm-modal-box p {
            font-size: 13px;
            line-height: 1.6;
            color: #c3c4c7;
        }
        .veyra-pcdm-modal-box code {
            background: rgba(255, 255, 255, 0.1);
            color: #f0f0f1;
            padding: 1px 4px;
            border-radius: 3px;
        }
        .veyra-pcdm-modal-warning {
            color: #ff6b6b !important;
            font-weight: 600;
        }
        .veyra-pcdm-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .veyra-pcdm-modal-btn {
            border: none;
            border-radius: 4px;
            padding: 8px 16px;
            font-size: 13px;
            cursor: pointer;
        }
        .veyra-pcdm-modal-cancel {
            background: #3c434a;
            color: #f0f0f1;
        }
        .veyra-pcdm-modal-cancel:hover {
            background: #50575e;
        }
        .veyra-pcdm-modal-confirm {
            background: #b32d2e;
            color: #fff;
            font-weight: 600;
        }
        .veyra-pcdm-modal-confirm:hover {
            background: #d63638;
        }
        .veyra-pcdm-table {
            width: auto !important;
            table-layout: auto !important;
            border-collapse: collapse !important;
        }
        .veyra-pcdm-table th, .veyra-pcdm-table td {
            vertical-align: top;
            border: 1px solid #ccc !important;
            width: auto !important;
            white-space: nowrap;
            background: #fff !important;
        }
        .veyra-pcdm-table .veyra-pcdm-col-species {
            border-left: 2px solid #000 !important;
        }
        .veyra-pcdm-table .veyra-pcdm-col-subspecies {
            border-right: 2px solid #000 !important;
        }
        .veyra-pcdm-col-title {
            max-width: 470px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .veyra-pcdm-col-tools .button {
            margin-right: 4px;
        }
        .veyra-pcdm-col-tools .button:last-child {
            margin-right: 0;
        }
        .veyra-pcdm-table tr.veyra-pcdm-row-selected > td {
            background: #d6e9ff !important;
        }
        .veyra-pcdm-table th {
            background: #f0f0f1 !important;
        }
        .veyra-pcdm-tooltip-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: help;
        }
        .veyra-pcdm-tooltip-icon {
            display: inline-block;
            font-style: normal;
            color: #2271b1;
        }
        .veyra-pcdm-tooltip-popup {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 10;
            background: #1d2327;
            color: #fff;
            padding: 6px 8px;
            border-radius: 4px;
            white-space: nowrap;
            font-weight: 400;
            font-size: 12px;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,.25);
        }
        .veyra-pcdm-tooltip-popup code {
            color: #fff;
            background: transparent;
        }
        .veyra-pcdm-tooltip-wrap:hover .veyra-pcdm-tooltip-popup,
        .veyra-pcdm-tooltip-wrap:focus .veyra-pcdm-tooltip-popup,
        .veyra-pcdm-tooltip-wrap:focus-within .veyra-pcdm-tooltip-popup {
            display: inline-flex;
        }
        .veyra-pcdm-tooltip-popup--wide {
            white-space: normal;
            width: 380px;
            line-height: 1.6;
        }
        .veyra-pcdm-tooltip-wrap:hover .veyra-pcdm-tooltip-popup--wide,
        .veyra-pcdm-tooltip-wrap:focus .veyra-pcdm-tooltip-popup--wide,
        .veyra-pcdm-tooltip-wrap:focus-within .veyra-pcdm-tooltip-popup--wide {
            display: block;
        }
        .veyra-pcdm-heading-tooltip {
            font-size: 13px;
            margin-left: 10px;
            margin-right: 4px;
        }
    </style>
    <script>
    (function(){
        document.addEventListener('click', function(e){
            var t = e.target;
            if (!t.classList || !t.classList.contains('veyra-pcdm-copy')) { return; }
            e.preventDefault();
            var text = t.getAttribute('data-copy') || '';
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.focus(); ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
            document.body.removeChild(ta);
            var orig = t.textContent;
            t.textContent = ok ? 'copied!' : 'copy failed';
            setTimeout(function(){ t.textContent = orig; }, 1200);
        });

        // Header "select all" checkbox toggles every row checkbox.
        var selectAll = document.getElementById('veyra-pcdm-select-all');
        function rowCbs(){ return document.querySelectorAll('.veyra-pcdm-cb'); }

        // Light-blue row highlight whenever a row's checkbox is checked.
        function syncRowHighlight(cb){
            var tr = cb.closest('tr');
            if (tr) { tr.classList.toggle('veyra-pcdm-row-selected', cb.checked); }
        }
        function syncAllRowHighlights(){ rowCbs().forEach(syncRowHighlight); }
        document.addEventListener('change', function(e){
            if (e.target.classList && e.target.classList.contains('veyra-pcdm-cb')) {
                syncRowHighlight(e.target);
            }
        });
        syncAllRowHighlights();

        if (selectAll) {
            selectAll.addEventListener('change', function(){
                rowCbs().forEach(function(c){ c.checked = selectAll.checked; });
                syncAllRowHighlights();
            });
        }

        // "select all items that likely need drip changes" — replaces the current
        // selection with exactly the rows matching the criteria in the tooltip.
        var dripBtn = document.getElementById('veyra-pcdm-select-drip-candidates');
        if (dripBtn) {
            dripBtn.addEventListener('click', function(){
                rowCbs().forEach(function(c){
                    var matches = c.getAttribute('data-species') === 'content_direct_from_wayback'
                        && c.getAttribute('data-subspecies') === 'actual_copied_historical_content'
                        && c.getAttribute('data-invented-empty') === '0';
                    c.checked = matches;
                });
                syncAllRowHighlights();
                if (selectAll) {
                    var all = rowCbs();
                    var checkedCount = 0;
                    all.forEach(function(c){ if (c.checked) checkedCount++; });
                    selectAll.checked = (all.length > 0 && checkedCount === all.length);
                    selectAll.indeterminate = (checkedCount > 0 && checkedCount < all.length);
                }
            });
        }

        // "run assignment algo for veyra_switchover_date" — spreads staggered,
        // non-uniform timestamps across the currently-selected items so the live
        // deployments don't fire at obvious, robotic, evenly-spaced intervals.
        var runAlgoBtn = document.getElementById('veyra-pcdm-run-algo');
        if (runAlgoBtn) {
            runAlgoBtn.addEventListener('click', function(){
                var selected = Array.prototype.slice.call(rowCbs()).filter(function(c){ return c.checked; });
                if (selected.length === 0) {
                    alert('No items selected. Tick the checkboxes for the items you want to drip-schedule first.');
                    return;
                }

                var totalDaysInput = document.getElementById('veyra-pcdm-total-days');
                var totalDays = parseFloat(totalDaysInput ? totalDaysInput.value : '') || 30;
                var totalItems = selected.length;
                var avgIntervalDays = totalDays / totalItems;
                var lowDays  = avgIntervalDays * 0.3;
                var highDays = avgIntervalDays * 1.7;

                // Fisher-Yates shuffle so the drip order itself is randomized too.
                var order = selected.slice();
                for (var i = order.length - 1; i > 0; i--) {
                    var j = Math.floor(Math.random() * (i + 1));
                    var tmp = order[i]; order[i] = order[j]; order[j] = tmp;
                }

                var assignments = {};
                var tsSeconds = Math.floor(Date.now() / 1000) + (10 * 60); // first item: now + 10 minutes
                order.forEach(function(cb, idx){
                    if (idx > 0) {
                        var randDays = lowDays + Math.random() * (highDays - lowDays);
                        tsSeconds += Math.round(randDays * 86400);
                    }
                    assignments[cb.value] = tsSeconds;
                });

                runAlgoBtn.disabled = true;
                var origText = runAlgoBtn.textContent;
                runAlgoBtn.textContent = 'running...';

                var body = new URLSearchParams();
                body.set('action', 'veyra_pcdm_assign_switchover');
                body.set('nonce', runAlgoBtn.getAttribute('data-nonce') || '');
                body.set('assignments', JSON.stringify(assignments));

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            location.reload();
                        } else {
                            alert('Failed to save switchover dates.');
                            runAlgoBtn.disabled = false;
                            runAlgoBtn.textContent = origText;
                        }
                    })
                    .catch(function(){
                        alert('Failed to save switchover dates.');
                        runAlgoBtn.disabled = false;
                        runAlgoBtn.textContent = origText;
                    });
            });
        }

        // "clear switchover date to empty" — unsets veyra_switchover_date for
        // the currently-selected items.
        var clearBtn = document.getElementById('veyra-pcdm-clear-switchover');
        if (clearBtn) {
            clearBtn.addEventListener('click', function(){
                var selected = Array.prototype.slice.call(rowCbs()).filter(function(c){ return c.checked; });
                if (selected.length === 0) {
                    alert('No items selected. Tick the checkboxes for the items you want to clear first.');
                    return;
                }
                var ids = selected.map(function(c){ return c.value; });

                clearBtn.disabled = true;
                var origText = clearBtn.textContent;
                clearBtn.textContent = 'clearing...';

                var body = new URLSearchParams();
                body.set('action', 'veyra_pcdm_clear_switchover');
                body.set('nonce', clearBtn.getAttribute('data-nonce') || '');
                body.set('ids', JSON.stringify(ids));

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            location.reload();
                        } else {
                            alert('Failed to clear switchover dates.');
                            clearBtn.disabled = false;
                            clearBtn.textContent = origText;
                        }
                    })
                    .catch(function(){
                        alert('Failed to clear switchover dates.');
                        clearBtn.disabled = false;
                        clearBtn.textContent = origText;
                    });
            });
        }

        // "select items with switchover date in past" — clears the current
        // selection first, then selects only rows whose switchover date has
        // already passed.
        var selectPastDueBtn = document.getElementById('veyra-pcdm-select-past-due');
        if (selectPastDueBtn) {
            selectPastDueBtn.addEventListener('click', function(){
                var nowSeconds = Math.floor(Date.now() / 1000);
                rowCbs().forEach(function(c){
                    var ts = c.getAttribute('data-switchover-ts');
                    c.checked = (ts !== '' && parseInt(ts, 10) <= nowSeconds);
                });
                syncAllRowHighlights();
                if (selectAll) {
                    var all = rowCbs();
                    var checkedCount = 0;
                    all.forEach(function(c){ if (c.checked) checkedCount++; });
                    selectAll.checked = (all.length > 0 && checkedCount === all.length);
                    selectAll.indeterminate = (checkedCount > 0 && checkedCount < all.length);
                }
            });
        }

        // "perform content switchover now" — requires explicit confirmation via
        // the custom modal before deploying the selected items immediately.
        var switchoverNowBtn = document.getElementById('veyra-pcdm-switchover-now');
        var modalOverlay      = document.getElementById('veyra-pcdm-modal-overlay');
        var modalCancelBtn    = document.getElementById('veyra-pcdm-modal-cancel');
        var modalConfirmBtn   = document.getElementById('veyra-pcdm-modal-confirm');
        var pendingSwitchoverIds = null;

        function closeModal(){
            if (modalOverlay) { modalOverlay.classList.remove('veyra-pcdm-modal-open'); }
            pendingSwitchoverIds = null;
        }

        if (switchoverNowBtn && modalOverlay) {
            switchoverNowBtn.addEventListener('click', function(){
                var selected = Array.prototype.slice.call(rowCbs()).filter(function(c){ return c.checked; });
                if (selected.length === 0) {
                    alert('No items selected. Tick the checkboxes for the items you want to switch over now.');
                    return;
                }
                pendingSwitchoverIds = selected.map(function(c){ return c.value; });
                modalOverlay.classList.add('veyra-pcdm-modal-open');
            });
        }
        if (modalCancelBtn) {
            modalCancelBtn.addEventListener('click', closeModal);
        }
        if (modalOverlay) {
            modalOverlay.addEventListener('click', function(e){
                if (e.target === modalOverlay) { closeModal(); }
            });
        }
        if (modalConfirmBtn) {
            modalConfirmBtn.addEventListener('click', function(){
                if (!pendingSwitchoverIds || !pendingSwitchoverIds.length) {
                    closeModal();
                    return;
                }
                var ids = pendingSwitchoverIds;
                closeModal();

                switchoverNowBtn.disabled = true;
                var origText = switchoverNowBtn.textContent;
                switchoverNowBtn.textContent = 'performing switchover...';

                var body = new URLSearchParams();
                body.set('action', 'veyra_pcdm_switchover_now');
                body.set('nonce', switchoverNowBtn.getAttribute('data-nonce') || '');
                body.set('ids', JSON.stringify(ids));

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            location.reload();
                        } else {
                            alert('Failed to perform switchover.');
                            switchoverNowBtn.disabled = false;
                            switchoverNowBtn.textContent = origText;
                        }
                    })
                    .catch(function(){
                        alert('Failed to perform switchover.');
                        switchoverNowBtn.disabled = false;
                        switchoverNowBtn.textContent = origText;
                    });
            });
        }

        // "revert switchover" — for the selected items, copies
        // veyra_cached_original_wayback_content back into post_content and
        // resets veyra_content_subspecies to actual_copied_historical_content.
        var revertBtn = document.getElementById('veyra-pcdm-revert-switchover');
        if (revertBtn) {
            revertBtn.addEventListener('click', function(){
                var selected = Array.prototype.slice.call(rowCbs()).filter(function(c){ return c.checked; });
                if (selected.length === 0) {
                    alert('No items selected. Tick the checkboxes for the items you want to revert.');
                    return;
                }
                var ids = selected.map(function(c){ return c.value; });

                revertBtn.disabled = true;
                var origText = revertBtn.textContent;
                revertBtn.textContent = 'reverting...';

                var body = new URLSearchParams();
                body.set('action', 'veyra_pcdm_revert_switchover');
                body.set('nonce', revertBtn.getAttribute('data-nonce') || '');
                body.set('ids', JSON.stringify(ids));

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            location.reload();
                        } else {
                            alert('Failed to revert switchover.');
                            revertBtn.disabled = false;
                            revertBtn.textContent = origText;
                        }
                    })
                    .catch(function(){
                        alert('Failed to revert switchover.');
                        revertBtn.disabled = false;
                        revertBtn.textContent = origText;
                    });
            });
        }
    })();
    </script>
    <?php
}
