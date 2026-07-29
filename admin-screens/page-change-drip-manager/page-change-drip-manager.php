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

/** Truncate a value for the narrow vpm-freshly-title column: first 6 chars + "..". */
function veyra_pcdm_truncate_narrow($value) {
    $value = (string) $value;
    if (strlen($value) > 6) {
        return substr($value, 0, 6) . '..';
    }
    return $value;
}

/** Truncate a value for the fixed 200px post_title column: first 30 chars + "..". */
function veyra_pcdm_truncate_title($value) {
    $value = (string) $value;
    if (strlen($value) > 30) {
        return substr($value, 0, 30) . '..';
    }
    return $value;
}

/** Renders one label+input (or label+textarea) field inside a "pop" popup tab.
 *  $field_id becomes the element's id (JS fills it in after the AJAX fetch —
 *  these start empty; no value is echoed server-side). When $data_copy is
 *  given, the label reuses the exact same tooltip-icon + copy-button markup
 *  as the matching <th> in the main table, mirroring its content verbatim. */
function veyra_pcdm_render_pop_field($label, $data_copy, $field_id, $type) {
    echo '<p class="veyra-pcdm-pop-label">';
    if ($data_copy) {
        echo '<span class="veyra-pcdm-tooltip-wrap" tabindex="0">';
        echo '<span class="veyra-pcdm-tooltip-icon">&#9432;</span>';
        echo '<span class="veyra-pcdm-tooltip-popup">';
        echo '<button type="button" class="button button-small veyra-pcdm-copy" data-copy="' . esc_attr($data_copy) . '">copy</button>';
        echo '<code>' . esc_html($data_copy) . '</code>';
        echo '</span>';
        echo '<strong>' . esc_html($label) . '</strong>';
        echo '</span>';
    } else {
        echo '<strong>' . esc_html($label) . '</strong>';
    }
    echo '</p>';
    if ($type === 'text') {
        echo '<input type="text" class="veyra-pcdm-pop-input" id="' . esc_attr($field_id) . '">';
    } else {
        echo '<textarea class="veyra-pcdm-pop-textarea" id="' . esc_attr($field_id) . '"></textarea>';
    }
}

/** Opens one pop-panel's body: a 3-column flex row — left gutter holding this
 *  tab's own Save button (right-justified, sitting just outside/left of the
 *  centered fields column), the fixed 1000px centered+bordered fields column,
 *  and an empty right gutter (mirrors the left one so centering is exact). */
function veyra_pcdm_render_pop_panel_open($tab) {
    echo '<div class="veyra-pcdm-pop-tab-body">';
    echo '<div class="veyra-pcdm-pop-save-gutter">';
    echo '<button type="button" class="button button-primary veyra-pcdm-pop-save-btn" data-pop-tab="' . esc_attr($tab) . '">Save</button>';
    echo '</div>';
    echo '<div class="veyra-pcdm-pop-fields">';
}

/** Closes the markup opened by veyra_pcdm_render_pop_panel_open(). */
function veyra_pcdm_render_pop_panel_close() {
    echo '</div>'; // .veyra-pcdm-pop-fields
    echo '<div class="veyra-pcdm-pop-gutter-right veyra-pcdm-pop-close-area" title="Close">&times;</div>';
    echo '</div>'; // .veyra-pcdm-pop-tab-body
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
 * of what their veyra_switchover_date says: copy the staged replacement title/
 * content into post_title/post_content, tag veyra_content_subspecies, and mark
 * veyra_switchover_completed. Shared by the WP-Cron due-date sweep and the
 * manual "perform now" action.
 *
 * Source priority per post (revised 2026-07-28) — 4 postmeta combinations:
 *   - both vpostmeta_freshly_post_title and vpostmeta_freshly_invented_..._content
 *     set: use both (postmeta wins outright).
 *   - postmeta title BLANK, postmeta content SET: use the postmeta content for
 *     post_content only; post_title is left untouched (not fallen back to
 *     the wp_options pair — the postmeta content is trusted on its own).
 *   - postmeta title SET, postmeta content BLANK: postmeta is not usable as a
 *     pair here, so fall back entirely to the wp_options pair below.
 *   - both postmeta fields blank: fall back entirely to the wp_options pair.
 *
 * Once falling back to the wp_options pair (veyra_freshly_post_title /
 * veyra_freshly_invented_content_before_deployment_to_live_post_content), the
 * original rule applies: content is the gate — if the fallback content is
 * blank, NOTHING is updated (not even a lone title), regardless of whether a
 * fallback title exists; if fallback content is present, it's used for
 * post_content and the fallback title is applied too only when it's also set.
 *
 * End guard (holds for every branch above): post_content is only ever passed
 * to wp_update_post() once confirmed non-blank, and post_title is included in
 * the update args only when non-blank — so this action can never blank out
 * either field. veyra_content_species/subspecies tagging below is unchanged.
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

        $pm_title       = get_post_meta($post_id, 'vpostmeta_freshly_post_title', true);
        $pm_content     = get_post_meta($post_id, 'vpostmeta_freshly_invented_content_before_deployment_to_live_post_content', true);
        $pm_title_set   = trim((string) $pm_title) !== '';
        $pm_content_set = trim((string) $pm_content) !== '';

        $source_title   = '';
        $source_content = '';
        $nothing_to_do   = false;

        if ($pm_title_set && $pm_content_set) {
            // Both postmeta fields set — postmeta wins outright.
            $source_title   = $pm_title;
            $source_content = $pm_content;
        } elseif (!$pm_title_set && $pm_content_set) {
            // Postmeta content only — use it, leave post_title untouched.
            $source_content = $pm_content;
        } else {
            // Postmeta title set with no content, or neither set — fall back
            // to the wp_options pair entirely.
            $opt_title       = isset($freshly_post_title[$post_id]) ? (string) $freshly_post_title[$post_id] : '';
            $opt_content     = isset($freshly_invented[$post_id]) ? (string) $freshly_invented[$post_id] : '';
            $opt_content_set = trim($opt_content) !== '';

            if (!$opt_content_set) {
                // Fallback content missing (whether or not a fallback title
                // exists) — nothing safe to deploy; update neither field.
                $nothing_to_do = true;
            } else {
                $source_content = $opt_content;
                if (trim($opt_title) !== '') {
                    $source_title = $opt_title;
                }
            }
        }

        // Nothing staged to deploy (from whichever source applies) — end guard:
        // never proceed to wp_update_post with blank content.
        if ($nothing_to_do || trim((string) $source_content) === '') {
            $result['skipped']++;
            continue;
        }

        // Copy the staged content into post_content, replacing whatever is there.
        // Also apply the staged replacement post_title, but only when one is set —
        // an empty staged title leaves post_title untouched. wp_update_post()
        // unslashes its array input internally, so values pulled cleanly from
        // get_option()/get_post_meta() must be re-slashed before being passed
        // in — otherwise a real backslash in the staged title/content would
        // get silently stripped (see veyra_pcdm_ajax_save_pop_field()).
        $update_args = array(
            'ID'           => $post_id,
            'post_content' => wp_slash($source_content),
        );
        if (trim((string) $source_title) !== '') {
            $update_args['post_title'] = wp_slash($source_title);
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
 * Deploy the switchover for a specific set of post IDs using ONLY the
 * postmeta pair (vpostmeta_freshly_post_title /
 * vpostmeta_freshly_invented_content_before_deployment_to_live_post_content)
 * — no wp_options fallback at all. Same shape as the original (pre-postmeta)
 * veyra_pcdm_deploy_switchover() logic: content is the gate (skip the post
 * entirely if blank), post_title is applied only when non-blank, and
 * veyra_content_subspecies gets tagged new_freshly_invented_content. Unlike
 * the wp_options version this does not touch veyra_switchover_completed —
 * this button is a separate, on-demand action, not part of the scheduled-
 * switchover completion tracking.
 */
function veyra_pcdm_deploy_switchover_postmeta($post_ids) {
    $result = array('deployed' => 0, 'skipped' => 0);
    if (!is_array($post_ids) || !$post_ids) {
        return $result;
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

        $title   = get_post_meta($post_id, 'vpostmeta_freshly_post_title', true);
        $content = get_post_meta($post_id, 'vpostmeta_freshly_invented_content_before_deployment_to_live_post_content', true);

        // Nothing staged to deploy.
        if (trim((string) $content) === '') {
            $result['skipped']++;
            continue;
        }

        // wp_update_post() unslashes its array input internally, so values
        // pulled cleanly from get_post_meta() must be re-slashed before being
        // passed in — otherwise a real backslash in the staged title/content
        // would get silently stripped (see veyra_pcdm_ajax_save_pop_field()).
        $update_args = array(
            'ID'           => $post_id,
            'post_content' => wp_slash($content),
        );
        if (trim((string) $title) !== '') {
            $update_args['post_title'] = wp_slash($title);
        }
        wp_update_post($update_args);

        $subspecies[$post_id] = 'new_freshly_invented_content';
        $subspecies_changed   = true;

        $result['deployed']++;
    }

    if ($subspecies_changed) {
        update_option('veyra_content_subspecies', $subspecies, false);
    }

    return $result;
}

// ---------------------------------------------------------------------------
// AJAX: "perform content switchover now" (postmeta version) — same shape as
// veyra_pcdm_ajax_switchover_now() but calls the postmeta-only deploy above.
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_switchover_now_postmeta', 'veyra_pcdm_ajax_switchover_now_postmeta');
function veyra_pcdm_ajax_switchover_now_postmeta() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_switchover_now_postmeta', 'nonce');

    $raw = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '';
    $ids = json_decode($raw, true);
    if (!is_array($ids) || !$ids) {
        wp_send_json_error('no ids provided', 400);
    }

    $result = veyra_pcdm_deploy_switchover_postmeta($ids);
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

        // wp_update_post() unslashes its array input internally, so a value
        // pulled cleanly from get_option() must be re-slashed before being
        // passed in — otherwise a real backslash in the cached content would
        // get silently stripped (see veyra_pcdm_ajax_save_pop_field()).
        wp_update_post(array(
            'ID'           => $post_id,
            'post_content' => wp_slash($cached_original[$post_id]),
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

/**
 * Undo a switchover for a specific set of post IDs using ONLY the postmeta
 * field (vpostmeta_cached_original_wayback_content) — no wp_options fallback.
 * Same shape as veyra_pcdm_revert_switchover(): copy the cached original back
 * into post_content (skip if blank), reset veyra_content_subspecies to
 * actual_copied_historical_content.
 */
function veyra_pcdm_revert_switchover_postmeta($post_ids) {
    $result = array('reverted' => 0, 'skipped' => 0);
    if (!is_array($post_ids) || !$post_ids) {
        return $result;
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

        $original_content = get_post_meta($post_id, 'vpostmeta_cached_original_wayback_content', true);
        $original_title    = get_post_meta($post_id, 'vpostmeta_cached_original_wayback_post_title', true);

        // Nothing cached to revert to.
        if (trim((string) $original_content) === '') {
            $result['skipped']++;
            continue;
        }

        // wp_update_post() unslashes its array input internally, so values
        // pulled cleanly from get_post_meta() must be re-slashed before being
        // passed in here — otherwise a real backslash in the cached title/
        // content would get silently stripped (same issue fixed in the "pop"
        // popup's save handler, veyra_pcdm_ajax_save_pop_field()).
        $update_args = array(
            'ID'           => $post_id,
            'post_content' => wp_slash($original_content),
        );
        if (trim((string) $original_title) !== '') {
            $update_args['post_title'] = wp_slash($original_title);
        }
        wp_update_post($update_args);

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
// AJAX: "revert switchover" (postmeta version) — same shape as
// veyra_pcdm_ajax_revert_switchover() but calls the postmeta-only revert above.
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_revert_switchover_postmeta', 'veyra_pcdm_ajax_revert_switchover_postmeta');
function veyra_pcdm_ajax_revert_switchover_postmeta() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_revert_switchover_postmeta', 'nonce');

    $raw = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '';
    $ids = json_decode($raw, true);
    if (!is_array($ids) || !$ids) {
        wp_send_json_error('no ids provided', 400);
    }

    $result = veyra_pcdm_revert_switchover_postmeta($ids);
    wp_send_json_success($result);
}

/** Old wp_options field name => matching new wp_postmeta key, shared by the
 *  migrate/erase tools below. */
function veyra_pcdm_options_to_postmeta_field_map() {
    return array(
        'veyra_cached_original_wayback_post_title' => 'vpostmeta_cached_original_wayback_post_title',
        'veyra_cached_original_wayback_content' => 'vpostmeta_cached_original_wayback_content',
        'veyra_freshly_post_title' => 'vpostmeta_freshly_post_title',
        'veyra_freshly_invented_content_before_deployment_to_live_post_content' => 'vpostmeta_freshly_invented_content_before_deployment_to_live_post_content',
    );
}

/**
 * For each selected post ID, copy that post's value out of each old
 * post-ID-keyed wp_options array into the matching wp_postmeta key. Only
 * copies when the source value is non-blank for that post — a blank/missing
 * source leaves the destination postmeta untouched rather than blanking it.
 */
function veyra_pcdm_migrate_options_to_postmeta($post_ids) {
    $result = array('migrated_posts' => 0, 'fields_written' => 0);
    if (!is_array($post_ids) || !$post_ids) {
        return $result;
    }

    $map = veyra_pcdm_options_to_postmeta_field_map();
    $options_cache = array();
    foreach ($map as $old_option => $new_meta_key) {
        $all = get_option($old_option, array());
        $options_cache[$old_option] = is_array($all) ? $all : array();
    }

    foreach ($post_ids as $post_id) {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            continue;
        }
        $wrote_any = false;
        foreach ($map as $old_option => $new_meta_key) {
            $value = isset($options_cache[$old_option][$post_id]) ? $options_cache[$old_option][$post_id] : '';
            if (trim((string) $value) === '') {
                continue;
            }
            update_post_meta($post_id, $new_meta_key, $value);
            $result['fields_written']++;
            $wrote_any = true;
        }
        if ($wrote_any) {
            $result['migrated_posts']++;
        }
    }

    return $result;
}

// ---------------------------------------------------------------------------
// AJAX: "migrate old wp_options content to wp_postmeta" — for the selected
// post IDs, copies each of the 4 old wp_options values into the matching new
// wp_postmeta key (see veyra_pcdm_migrate_options_to_postmeta()).
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_migrate_options_to_postmeta', 'veyra_pcdm_ajax_migrate_options_to_postmeta');
function veyra_pcdm_ajax_migrate_options_to_postmeta() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_migrate_options_to_postmeta', 'nonce');

    $raw = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '';
    $ids = json_decode($raw, true);
    if (!is_array($ids) || !$ids) {
        wp_send_json_error('no ids provided', 400);
    }

    $result = veyra_pcdm_migrate_options_to_postmeta($ids);
    wp_send_json_success($result);
}

/**
 * For each selected post ID, remove that post's entry from all 4 old
 * wp_options arrays. Unsetting the key is the equivalent of "null/empty" for
 * a shared post-ID-keyed array — the same convention this plugin already
 * uses everywhere else these options are cleared.
 */
function veyra_pcdm_erase_old_options_fields($post_ids) {
    $result = array('erased_posts' => 0, 'fields_erased' => 0);
    if (!is_array($post_ids) || !$post_ids) {
        return $result;
    }

    $map = veyra_pcdm_options_to_postmeta_field_map();
    $options_cache = array();
    $changed = array();
    foreach ($map as $old_option => $new_meta_key) {
        $all = get_option($old_option, array());
        $options_cache[$old_option] = is_array($all) ? $all : array();
        $changed[$old_option] = false;
    }

    foreach ($post_ids as $post_id) {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            continue;
        }
        $erased_any = false;
        foreach ($map as $old_option => $new_meta_key) {
            if (isset($options_cache[$old_option][$post_id])) {
                unset($options_cache[$old_option][$post_id]);
                $changed[$old_option] = true;
                $result['fields_erased']++;
                $erased_any = true;
            }
        }
        if ($erased_any) {
            $result['erased_posts']++;
        }
    }

    foreach ($map as $old_option => $new_meta_key) {
        if (!$changed[$old_option]) {
            continue;
        }
        $autoload = ($old_option === 'veyra_cached_original_wayback_content' || $old_option === 'veyra_freshly_invented_content_before_deployment_to_live_post_content') ? false : true;
        update_option($old_option, $options_cache[$old_option], $autoload);
    }

    return $result;
}

// ---------------------------------------------------------------------------
// AJAX: "erase content from 4 old wp_options fields" — for the selected post
// IDs, unsets each post's entry from all 4 old wp_options arrays.
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_erase_old_options_fields', 'veyra_pcdm_ajax_erase_old_options_fields');
function veyra_pcdm_ajax_erase_old_options_fields() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_erase_old_options_fields', 'nonce');

    $raw = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '';
    $ids = json_decode($raw, true);
    if (!is_array($ids) || !$ids) {
        wp_send_json_error('no ids provided', 400);
    }

    $result = veyra_pcdm_erase_old_options_fields($ids);
    wp_send_json_success($result);
}

// ---------------------------------------------------------------------------
// AJAX: "Save As Default" — persists the current on/off state of the 2 column
// show/hide toggles into veyra_pcdm_column_toggle_defaults, so this page loads
// with that state from then on (until the user saves a different state).
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_save_toggle_defaults', 'veyra_pcdm_ajax_save_toggle_defaults');
function veyra_pcdm_ajax_save_toggle_defaults() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_save_toggle_defaults', 'nonce');

    $show_pm  = isset($_POST['show_pm'])  && $_POST['show_pm']  === '1';
    $show_old = isset($_POST['show_old']) && $_POST['show_old'] === '1';

    update_option('veyra_pcdm_column_toggle_defaults', array(
        'show_pm'  => $show_pm,
        'show_old' => $show_old,
    ));

    wp_send_json_success(array('show_pm' => $show_pm, 'show_old' => $show_old));
}

// ---------------------------------------------------------------------------
// AJAX: "pop" button data — returns, for one post, the live post_title/
// post_content plus all 4 postmeta and all 4 old wp_options title/content
// pairs, for the tabbed popup viewer/editor. Fetched on demand (not embedded
// per-row in the page HTML) so opening the table doesn't have to ship every
// post's full content up front.
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_get_pop_data', 'veyra_pcdm_ajax_get_pop_data');
function veyra_pcdm_ajax_get_pop_data() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_get_pop_data', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if ($post_id <= 0) {
        wp_send_json_error('invalid post id', 400);
    }
    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error('post not found', 404);
    }

    $cached_original_title = get_option('veyra_cached_original_wayback_post_title', array());
    $cached_original       = get_option('veyra_cached_original_wayback_content', array());
    $freshly_title         = get_option('veyra_freshly_post_title', array());
    $freshly_invented      = get_option('veyra_freshly_invented_content_before_deployment_to_live_post_content', array());
    if (!is_array($cached_original_title)) { $cached_original_title = array(); }
    if (!is_array($cached_original))       { $cached_original = array(); }
    if (!is_array($freshly_title))         { $freshly_title = array(); }
    if (!is_array($freshly_invented))      { $freshly_invented = array(); }

    wp_send_json_success(array(
        'post_id'             => $post_id,
        'post_type'           => $post->post_type,
        'post_status'         => $post->post_status,
        'live_title'          => $post->post_title,
        'live_content'        => $post->post_content,
        'pm_wayback_title'    => get_post_meta($post_id, 'vpostmeta_cached_original_wayback_post_title', true),
        'pm_wayback_content'  => get_post_meta($post_id, 'vpostmeta_cached_original_wayback_content', true),
        'pm_freshly_title'    => get_post_meta($post_id, 'vpostmeta_freshly_post_title', true),
        'pm_freshly_content'  => get_post_meta($post_id, 'vpostmeta_freshly_invented_content_before_deployment_to_live_post_content', true),
        'old_wayback_title'   => isset($cached_original_title[$post_id]) ? $cached_original_title[$post_id] : '',
        'old_wayback_content' => isset($cached_original[$post_id]) ? $cached_original[$post_id] : '',
        'old_freshly_title'   => isset($freshly_title[$post_id]) ? $freshly_title[$post_id] : '',
        'old_freshly_content' => isset($freshly_invented[$post_id]) ? $freshly_invented[$post_id] : '',
    ));
}

// ---------------------------------------------------------------------------
// "sim" button — front-end simulation preview. Visiting a post's REAL
// permalink with ?veyra_sim=1&_wpnonce=<nonce> (generated by the "sim" button
// in the tools column) renders that exact page through the site's normal
// theme templates, but with post_title/post_content swapped in-memory for
// vpostmeta_freshly_post_title / vpostmeta_freshly_invented_content_before_
// deployment_to_live_post_content for THIS REQUEST ONLY — nothing is written
// to the database. Lets someone see what the page will look like after a
// switchover without performing one.
//
// Why this can't leak to Googlebot or the public:
//   1. The URL is never linked anywhere on the public site or in the
//      sitemap — it only ever exists as an href inside the admin-only Page
//      Change Drip Manager screen (itself gated on manage_options).
//   2. Even if the exact URL were guessed or leaked, the swap only happens
//      for a logged-in user with manage_options — everyone else (including
//      Googlebot, which never has an admin session) gets a 403 and sees
//      nothing swapped.
//   3. The nonce is scoped to this specific post ID and expires like any
//      other WP nonce (~24-48h), so a stale/leaked link goes dead on its own.
//   4. The real URL with no query string is completely unaffected — it
//      always renders the true live post_title/post_content, for anyone.
// ---------------------------------------------------------------------------
add_action('template_redirect', 'veyra_pcdm_maybe_render_sim_preview');
function veyra_pcdm_maybe_render_sim_preview() {
    if (!isset($_GET['veyra_sim']) || $_GET['veyra_sim'] !== '1') {
        return;
    }
    if (!is_singular()) {
        return;
    }

    $post_id = get_queried_object_id();
    if (!$post_id) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized', 'Unauthorized', array('response' => 403));
    }

    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'veyra_sim_preview_' . $post_id)) {
        wp_die('This simulation preview link is invalid or has expired. Generate a new one from the "sim" button.', 'Invalid Preview Link', array('response' => 403));
    }

    $sim_title   = get_post_meta($post_id, 'vpostmeta_freshly_post_title', true);
    $sim_content = get_post_meta($post_id, 'vpostmeta_freshly_invented_content_before_deployment_to_live_post_content', true);

    // Always override — even when blank. A blank staged field must render as
    // blank in the simulation, never silently fall back to the real live
    // post_title/post_content (that would defeat the point of previewing
    // exactly what's staged, including the gaps).
    add_filter('the_title', function ($title, $id) use ($post_id, $sim_title) {
        return ((int) $id === (int) $post_id) ? (string) $sim_title : $title;
    }, 10, 2);

    add_filter('the_content', function ($content) use ($post_id, $sim_content) {
        if (get_the_ID() !== $post_id) {
            return $content;
        }
        if (trim((string) $sim_content) === '') {
            return '';
        }
        // Manually run the safe subset of the normal the_content chain —
        // re-invoking apply_filters('the_content', ...) here would recurse
        // back into this same filter.
        $out = wptexturize($sim_content);
        $out = wpautop($out);
        $out = shortcode_unautop($out);
        $out = do_shortcode($out);
        return $out;
    }, 10, 1);
}

/** Upsert or delete one post's postmeta value — blank clears the entry,
 *  matching this plugin's existing postmeta-save convention. */
function veyra_pcdm_set_or_delete_postmeta($post_id, $meta_key, $value) {
    if (trim((string) $value) === '') {
        delete_post_meta($post_id, $meta_key);
    } else {
        update_post_meta($post_id, $meta_key, $value);
    }
}

/** Upsert or delete one post's entry in a post-ID-keyed wp_options array —
 *  blank clears the entry, matching this plugin's existing wp_options-save
 *  convention (e.g. veyra_save_snap_height_field()). */
function veyra_pcdm_set_or_delete_option_field($option_name, $post_id, $value, $autoload = true) {
    $all = get_option($option_name, array());
    if (!is_array($all)) { $all = array(); }
    if (trim((string) $value) === '') {
        unset($all[$post_id]);
    } else {
        $all[$post_id] = $value;
    }
    update_option($option_name, $all, $autoload);
}

// ---------------------------------------------------------------------------
// AJAX: "pop" popup per-tab Save button — persists just the title/content
// pair for ONE tab of ONE post. See the slashing note inside the function:
// wp_update_post()/update_post_meta() must get the RAW (still-slashed)
// $_POST value since they unslash internally, while update_option() needs
// an explicitly wp_unslash()'d value — mixing these up is exactly how a
// stray/missing backslash ends up in the database.
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pcdm_save_pop_field', 'veyra_pcdm_ajax_save_pop_field');
function veyra_pcdm_ajax_save_pop_field() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('veyra_pcdm_save_pop_field', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $tab     = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : '';
    if ($post_id <= 0 || !get_post($post_id)) {
        wp_send_json_error('invalid post id', 400);
    }

    // WordPress has two DIFFERENT slashing conventions here, and mixing them up
    // is exactly how stray/missing backslashes end up in the database:
    //   - wp_update_post() / update_post_meta() (via update_metadata()) both
    //     unslash their input THEMSELVES internally, so they must be given the
    //     RAW $_POST value (still slashed, as WP's magic-quotes layer left it)
    //     — pre-unslashing before passing them in would strip real backslashes.
    //   - update_option() does NOT auto-unslash, so it must be given an
    //     explicitly wp_unslash()'d value, matching this plugin's existing
    //     option-saving convention elsewhere in this file.
    $title_raw   = isset($_POST['title'])   ? (string) $_POST['title']   : '';
    $content_raw = isset($_POST['content']) ? (string) $_POST['content'] : '';
    $title_clean   = wp_unslash($title_raw);
    $content_clean = wp_unslash($content_raw);

    switch ($tab) {
        case 'live':
            wp_update_post(array(
                'ID'           => $post_id,
                'post_title'   => $title_raw,
                'post_content' => $content_raw,
            ));
            break;

        case 'vpm-wayback':
            veyra_pcdm_set_or_delete_postmeta($post_id, 'vpostmeta_cached_original_wayback_post_title', $title_raw);
            veyra_pcdm_set_or_delete_postmeta($post_id, 'vpostmeta_cached_original_wayback_content', $content_raw);
            break;

        case 'vpm-freshly':
            veyra_pcdm_set_or_delete_postmeta($post_id, 'vpostmeta_freshly_post_title', $title_raw);
            veyra_pcdm_set_or_delete_postmeta($post_id, 'vpostmeta_freshly_invented_content_before_deployment_to_live_post_content', $content_raw);
            break;

        case 'veyra-wayback':
            veyra_pcdm_set_or_delete_option_field('veyra_cached_original_wayback_post_title', $post_id, $title_clean);
            veyra_pcdm_set_or_delete_option_field('veyra_cached_original_wayback_content', $post_id, $content_clean, false);
            break;

        case 'veyra-freshly':
            veyra_pcdm_set_or_delete_option_field('veyra_freshly_post_title', $post_id, $title_clean);
            veyra_pcdm_set_or_delete_option_field('veyra_freshly_invented_content_before_deployment_to_live_post_content', $post_id, $content_clean, false);
            break;

        default:
            wp_send_json_error('unknown tab', 400);
    }

    wp_send_json_success(array('tab' => $tab, 'post_id' => $post_id));
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
    $cached_original_title = get_option('veyra_cached_original_wayback_post_title', array());
    $freshly_invented   = get_option('veyra_freshly_invented_content_before_deployment_to_live_post_content', array());
    $freshly_title      = get_option('veyra_freshly_post_title', array());
    $switchover_date    = get_option('veyra_switchover_date', array());
    $switchover_completed = get_option('veyra_switchover_completed', array());
    if (!is_array($species))            { $species = array(); }
    if (!is_array($subspecies))         { $subspecies = array(); }
    if (!is_array($cached_original))    { $cached_original = array(); }
    if (!is_array($cached_original_title)) { $cached_original_title = array(); }
    if (!is_array($freshly_invented))   { $freshly_invented = array(); }
    if (!is_array($freshly_title))      { $freshly_title = array(); }
    if (!is_array($switchover_date))    { $switchover_date = array(); }
    if (!is_array($switchover_completed)) { $switchover_completed = array(); }

    $toggle_defaults = get_option('veyra_pcdm_column_toggle_defaults', array());
    if (!is_array($toggle_defaults)) { $toggle_defaults = array(); }
    $show_pm_default  = isset($toggle_defaults['show_pm'])  ? (bool) $toggle_defaults['show_pm']  : true;
    $show_old_default = isset($toggle_defaults['show_old']) ? (bool) $toggle_defaults['show_old'] : true;
    ?>
    <div class="wrap veyra-pcdm">
        <div class="veyra-pcdm-header-row">
            <div class="veyra-pcdm-drip-controls-group">
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
            </div>

            <div class="veyra-pcdm-now-group">
                <button type="button" class="button" id="veyra-pcdm-select-past-due">select items with switchover date in past</button>
                <div class="veyra-pcdm-switchover-btn-row">
                    <button type="button" class="button button-primary" id="veyra-pcdm-switchover-now-postmeta"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_switchover_now_postmeta')); ?>">perform content switchover now</button>
                    <button type="button" class="veyra-pcdm-switchover-now-old" id="veyra-pcdm-switchover-now"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_switchover_now')); ?>">perform content switchover now<br>(old version - uses wp_options)</button>
                </div>
                <div class="veyra-pcdm-revert-btn-row">
                    <button type="button" class="veyra-pcdm-revert-btn" id="veyra-pcdm-revert-switchover-postmeta"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_revert_switchover_postmeta')); ?>">revert switchover</button>
                    <button type="button" class="veyra-pcdm-revert-btn-old" id="veyra-pcdm-revert-switchover"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_revert_switchover')); ?>">revert switchover<br>(old version - uses wp_option)</button>
                </div>
            </div>

            <div class="veyra-pcdm-migrate-group">
                <button type="button" class="button" id="veyra-pcdm-select-all-btn">select all</button>

                <div class="veyra-pcdm-migrate-row">
                    <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                        <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                        <span class="veyra-pcdm-tooltip-popup veyra-pcdm-tooltip-popup--wide">
                            This will copy the contents of the following fields, for each wp_posts item:<br><br>
                            wp_postmeta: veyra_cached_original_wayback_post_title<br>
                            wp_postmeta: veyra_cached_original_wayback_content<br>
                            wp_postmeta: veyra_freshly_post_title<br>
                            wp_postmeta: veyra_freshly_invented_content_before_deployment_to_live_post_content<br><br>
                            And paste it into the following fields and update them:<br><br>
                            wp_postmeta: vpostmeta_cached_original_wayback_post_title<br>
                            wp_postmeta: vpostmeta_cached_original_wayback_content<br>
                            wp_postmeta: vpostmeta_freshly_post_title<br>
                            wp_postmeta: vpostmeta_freshly_invented_content_before_deployment_to_live_post_content
                        </span>
                    </span>
                    <button type="button" class="veyra-pcdm-migrate-btn" id="veyra-pcdm-migrate-options-to-postmeta"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_migrate_options_to_postmeta')); ?>">migrate old wp_options content to wp_postmeta</button>
                </div>

                <div class="veyra-pcdm-migrate-row">
                    <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                        <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                        <span class="veyra-pcdm-tooltip-popup veyra-pcdm-tooltip-popup--wide">
                            This will delete all content (update fields to null/empty) for the following 4 fields for the selected wp posts items:<br><br>
                            wp_postmeta: veyra_cached_original_wayback_post_title<br>
                            wp_postmeta: veyra_cached_original_wayback_content<br>
                            wp_postmeta: veyra_freshly_post_title<br>
                            wp_postmeta: veyra_freshly_invented_content_before_deployment_to_live_post_content
                        </span>
                    </span>
                    <button type="button" class="button" id="veyra-pcdm-erase-old-options-fields"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_erase_old_options_fields')); ?>">erase content from 4 old wp_options fields</button>
                </div>
            </div>

            <div class="veyra-pcdm-column-toggle-group">
                <label class="veyra-pcdm-toggle-row">
                    <span class="veyra-pcdm-toggle-switch">
                        <input type="checkbox" id="veyra-pcdm-toggle-pm-cols" <?php echo $show_pm_default ? 'checked' : ''; ?>>
                        <span class="veyra-pcdm-toggle-slider"></span>
                    </span>
                    show 4 new postmeta fields (phased in)
                </label>
                <label class="veyra-pcdm-toggle-row">
                    <span class="veyra-pcdm-toggle-switch">
                        <input type="checkbox" id="veyra-pcdm-toggle-old-cols" <?php echo $show_old_default ? 'checked' : ''; ?>>
                        <span class="veyra-pcdm-toggle-slider"></span>
                    </span>
                    show 4 old wp_options fields (phasing out)
                </label>
                <button type="button" class="button" id="veyra-pcdm-save-toggle-defaults"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_save_toggle_defaults')); ?>">Save As Default</button>
            </div>
        </div>

        <table class="wp-list-table veyra-pcdm-table">
            <thead>
                <tr>
                    <th class="check-column"><input type="checkbox" id="veyra-pcdm-select-all"></th>
                    <th><strong>post_id</strong></th>
                    <th><strong>post_status</strong></th>
                    <th><strong>post_type</strong></th>
                    <th class="veyra-pcdm-col-title veyra-pcdm-th-bottom-star"><span class="veyra-pcdm-th-star">&#9733;</span><strong>post_title</strong></th>
                    <th class="veyra-pcdm-th-bottom-star"><span class="veyra-pcdm-th-star">&#9733;</span><strong>post_content</strong></th>
                    <th class="veyra-pcdm-col-tools"><strong>tools</strong></th>
                    <th class="veyra-pcdm-col-pm-title veyra-pcdm-col-pm-freshly-title veyra-pcdm-col-group-pm">
                        <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                            <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                            <span class="veyra-pcdm-tooltip-popup">
                                <button type="button" class="button button-small veyra-pcdm-copy" data-copy="vpostmeta_freshly_post_title">copy</button>
                                <code>vpostmeta_freshly_post_title</code>
                            </span>
                            <strong>vpm-freshly<br>-title</strong>
                        </span>
                    </th>
                    <th class="veyra-pcdm-col-pm-freshly-content veyra-pcdm-col-group-pm">
                        <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                            <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                            <span class="veyra-pcdm-tooltip-popup">
                                <button type="button" class="button button-small veyra-pcdm-copy" data-copy="vpostmeta_freshly_invented_content_before_deployment_to_live_post_content">copy</button>
                                <code>vpostmeta_freshly_invented_content_before_deployment_to_live_post_content</code>
                            </span>
                            <strong>vpm-freshly<br>-content</strong>
                        </span>
                    </th>
                    <th class="veyra-pcdm-col-pm-title veyra-pcdm-col-pm-wayback-title veyra-pcdm-col-group-pm veyra-pcdm-col-pm-wayback-title-sep">
                        <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                            <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                            <span class="veyra-pcdm-tooltip-popup">
                                <button type="button" class="button button-small veyra-pcdm-copy" data-copy="vpostmeta_cached_original_wayback_post_title">copy</button>
                                <code>vpostmeta_cached_original_wayback_post_title</code>
                            </span>
                            <strong>vpm-wayback<br>-title</strong>
                        </span>
                    </th>
                    <th class="veyra-pcdm-col-pm-cached-content veyra-pcdm-col-group-pm">
                        <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                            <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                            <span class="veyra-pcdm-tooltip-popup">
                                <button type="button" class="button button-small veyra-pcdm-copy" data-copy="vpostmeta_cached_original_wayback_content">copy</button>
                                <code>vpostmeta_cached_original_wayback_content</code>
                            </span>
                            <strong>vpm-wayback<br>-content</strong>
                        </span>
                    </th>
                    <th class="veyra-pcdm-col-old-title veyra-pcdm-col-old-wayback-title-sep veyra-pcdm-col-group-old">
                        <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                            <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                            <span class="veyra-pcdm-tooltip-popup">
                                <button type="button" class="button button-small veyra-pcdm-copy" data-copy="veyra_cached_original_wayback_post_title">copy</button>
                                <code>veyra_cached_original_wayback_post_title</code>
                            </span>
                            <strong>veyra-wayback<br>-title</strong>
                        </span>
                    </th>
                    <th class="veyra-pcdm-col-orig-content veyra-pcdm-col-group-old">
                        <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                            <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                            <span class="veyra-pcdm-tooltip-popup">
                                <button type="button" class="button button-small veyra-pcdm-copy" data-copy="veyra_cached_original_wayback_content">copy</button>
                                <code>veyra_cached_original_wayback_content</code>
                            </span>
                            <strong>veyra-wayback<br>-content</strong>
                        </span>
                    </th>
                    <th class="veyra-pcdm-col-old-title veyra-pcdm-col-old-freshly-title-sep veyra-pcdm-col-group-old">
                        <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                            <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                            <span class="veyra-pcdm-tooltip-popup">
                                <button type="button" class="button button-small veyra-pcdm-copy" data-copy="veyra_freshly_post_title">copy</button>
                                <code>veyra_freshly_post_title</code>
                            </span>
                            <strong>veyra-freshly<br>-title</strong>
                        </span>
                    </th>
                    <th class="veyra-pcdm-col-group-old">
                        <span class="veyra-pcdm-tooltip-wrap" tabindex="0">
                            <span class="veyra-pcdm-tooltip-icon">&#9432;</span>
                            <span class="veyra-pcdm-tooltip-popup">
                                <button type="button" class="button button-small veyra-pcdm-copy" data-copy="veyra_freshly_invented_content_before_deployment_to_live_post_content">copy</button>
                                <code>veyra_freshly_invented_content_before_deployment_to_live_post_content</code>
                            </span>
                            <strong>veyra-freshly<br>-content</strong>
                        </span>
                    </th>
                    <th class="veyra-pcdm-col-species"><strong>veyra_content_species</strong></th>
                    <th class="veyra-pcdm-col-subspecies"><strong>veyra_content_subspecies</strong></th>
                    <th class="veyra-pcdm-col-switchover-date"><strong>veyra_switchover_date</strong></th>
                    <th class="veyra-pcdm-col-switchover-date"><strong>veyra_switchover_completed</strong></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$posts): ?>
                <tr><td colspan="19">No posts or pages found.</td></tr>
            <?php else: foreach ($posts as $p):
                $id = intval($p->ID);
                $original_val  = isset($cached_original[$id]) ? $cached_original[$id] : '';
                $invented_val  = isset($freshly_invented[$id]) ? $freshly_invented[$id] : '';
                $pm_cached_title_val   = get_post_meta($id, 'vpostmeta_cached_original_wayback_post_title', true);
                $pm_cached_content_val = get_post_meta($id, 'vpostmeta_cached_original_wayback_content', true);
                $pm_title_val = get_post_meta($id, 'vpostmeta_freshly_post_title', true);
                $pm_invented_val = get_post_meta($id, 'vpostmeta_freshly_invented_content_before_deployment_to_live_post_content', true);
                $old_wayback_title_val = isset($cached_original_title[$id]) ? $cached_original_title[$id] : '';
                $old_freshly_title_val = isset($freshly_title[$id]) ? $freshly_title[$id] : '';
                $species_val   = isset($species[$id]) ? $species[$id] : '';
                $subspecies_val = isset($subspecies[$id]) ? $subspecies[$id] : '';
                $switchover_raw = isset($switchover_date[$id]) ? $switchover_date[$id] : '';
                $switchover_val = (is_numeric($switchover_raw) && intval($switchover_raw) > 0)
                    ? date('Y-m-d H:i:s', intval($switchover_raw))
                    : '';
                $completed_val = isset($switchover_completed[$id]) ? $switchover_completed[$id] : '';
                $invented_empty = (trim((string) $invented_val) === '') ? '1' : '0';
                $switchover_ts = (is_numeric($switchover_raw) && intval($switchover_raw) > 0) ? intval($switchover_raw) : '';

                // post_title/post_content match highlighting: same colors/classes as the
                // wayback (blue) and freshly (green) postmeta columns' own has-value
                // highlight — reused here so "same bg color" means literally the same CSS.
                // Wayback match takes priority over freshly match, per instructions; no
                // match at all leaves the cell's existing styling untouched.
                $title_match_class = '';
                if (trim((string) $pm_cached_title_val) !== '' && $p->post_title === $pm_cached_title_val) {
                    $title_match_class = ' veyra-pcdm-col-pm-wayback-has-value';
                } elseif (trim((string) $pm_title_val) !== '' && $p->post_title === $pm_title_val) {
                    $title_match_class = ' veyra-pcdm-col-pm-freshly-has-value';
                }
                $content_match_class = '';
                if (trim((string) $pm_cached_content_val) !== '' && $p->post_content === $pm_cached_content_val) {
                    $content_match_class = ' veyra-pcdm-col-pm-wayback-has-value';
                } elseif (trim((string) $pm_invented_val) !== '' && $p->post_content === $pm_invented_val) {
                    $content_match_class = ' veyra-pcdm-col-pm-freshly-has-value';
                }

                // veyra_content_subspecies cell coloring: same blue/green classes as the
                // wayback/freshly postmeta "has value" highlighting, reused so "same color" is literal.
                $subspecies_match_class = '';
                if ($subspecies_val === 'actual_copied_historical_content') {
                    $subspecies_match_class = ' veyra-pcdm-col-pm-wayback-has-value';
                } elseif ($subspecies_val === 'new_freshly_invented_content') {
                    $subspecies_match_class = ' veyra-pcdm-col-pm-freshly-has-value';
                }
            ?>
                <tr data-post-id="<?php echo $id; ?>">
                    <td class="check-column">
                        <input type="checkbox" class="veyra-pcdm-cb" value="<?php echo $id; ?>"
                            data-species="<?php echo esc_attr($species_val); ?>"
                            data-subspecies="<?php echo esc_attr($subspecies_val); ?>"
                            data-invented-empty="<?php echo esc_attr($invented_empty); ?>"
                            data-switchover-ts="<?php echo esc_attr($switchover_ts); ?>">
                    </td>
                    <td><?php echo $id; ?></td>
                    <td><?php echo esc_html($p->post_status); ?></td>
                    <td class="<?php echo ($p->post_type === 'post') ? 'veyra-pcdm-col-post-type-emphasis' : ''; ?>"><?php echo esc_html($p->post_type); ?></td>
                    <td class="veyra-pcdm-col-title veyra-pcdm-cell-clickable<?php echo $title_match_class; ?>" title="<?php echo esc_attr($p->post_title); ?>" data-pop-tab="live"><?php echo esc_html(veyra_pcdm_truncate_title($p->post_title)); ?></td>
                    <td class="veyra-pcdm-cell-clickable<?php echo $content_match_class; ?>" data-pop-tab="live"><?php echo esc_html(veyra_pcdm_truncate($p->post_content)); ?></td>
                    <td class="veyra-pcdm-col-tools">
                        <button type="button" class="button button-small veyra-pcdm-pop-btn" data-post-id="<?php echo $id; ?>">pop</button>
                        <a class="button button-small" href="<?php echo esc_url(get_edit_post_link($id, 'raw')); ?>" target="_blank" rel="noopener">edit</a>
                        <a class="button button-small veyra-pcdm-fe-btn" href="<?php echo esc_url(get_permalink($id)); ?>" target="_blank" rel="noopener">FE</a>
                        <a class="button button-small veyra-pcdm-sim-btn" href="<?php echo esc_url(add_query_arg(array('veyra_sim' => '1', '_wpnonce' => wp_create_nonce('veyra_sim_preview_' . $id)), get_permalink($id))); ?>" target="_blank" rel="noopener">sim</a>
                    </td>
                    <td class="veyra-pcdm-col-pm-title veyra-pcdm-col-group-pm veyra-pcdm-cell-clickable<?php echo (trim((string) $pm_title_val) !== '') ? ' veyra-pcdm-col-pm-freshly-has-value' : ''; ?>" title="<?php echo esc_attr($pm_title_val); ?>" data-pop-tab="vpm-freshly"><?php echo esc_html(veyra_pcdm_truncate_narrow($pm_title_val)); ?></td>
                    <td class="veyra-pcdm-col-group-pm veyra-pcdm-cell-clickable<?php echo (trim((string) $pm_invented_val) !== '') ? ' veyra-pcdm-col-pm-freshly-has-value' : ''; ?>" data-pop-tab="vpm-freshly"><?php echo esc_html(veyra_pcdm_truncate($pm_invented_val)); ?></td>
                    <td class="veyra-pcdm-col-pm-title veyra-pcdm-col-group-pm veyra-pcdm-cell-clickable veyra-pcdm-col-pm-wayback-title-sep<?php echo (trim((string) $pm_cached_title_val) !== '') ? ' veyra-pcdm-col-pm-wayback-has-value' : ''; ?>" title="<?php echo esc_attr($pm_cached_title_val); ?>" data-pop-tab="vpm-wayback"><?php echo esc_html(veyra_pcdm_truncate_narrow($pm_cached_title_val)); ?></td>
                    <td class="veyra-pcdm-col-pm-cached-content veyra-pcdm-col-group-pm veyra-pcdm-cell-clickable<?php echo (trim((string) $pm_cached_content_val) !== '') ? ' veyra-pcdm-col-pm-wayback-has-value' : ''; ?>" data-pop-tab="vpm-wayback"><?php echo esc_html(veyra_pcdm_truncate($pm_cached_content_val)); ?></td>
                    <td class="veyra-pcdm-col-old-title veyra-pcdm-col-old-wayback-title-sep veyra-pcdm-col-group-old veyra-pcdm-cell-clickable" title="<?php echo esc_attr($old_wayback_title_val); ?>" data-pop-tab="veyra-wayback"><?php echo esc_html(veyra_pcdm_truncate_narrow($old_wayback_title_val)); ?></td>
                    <td class="veyra-pcdm-col-orig-content veyra-pcdm-col-group-old veyra-pcdm-cell-clickable" data-pop-tab="veyra-wayback"><?php echo esc_html(veyra_pcdm_truncate($original_val)); ?></td>
                    <td class="veyra-pcdm-col-old-title veyra-pcdm-col-old-freshly-title-sep veyra-pcdm-col-group-old veyra-pcdm-cell-clickable" title="<?php echo esc_attr($old_freshly_title_val); ?>" data-pop-tab="veyra-freshly"><?php echo esc_html(veyra_pcdm_truncate_narrow($old_freshly_title_val)); ?></td>
                    <td class="veyra-pcdm-col-group-old veyra-pcdm-cell-clickable" data-pop-tab="veyra-freshly"><?php echo esc_html(veyra_pcdm_truncate($invented_val)); ?></td>
                    <td class="veyra-pcdm-col-species"><?php echo esc_html($species_val); ?></td>
                    <td class="veyra-pcdm-col-subspecies<?php echo $subspecies_match_class; ?>"><?php echo esc_html($subspecies_val); ?></td>
                    <td class="<?php echo ($switchover_val !== '') ? 'veyra-pcdm-col-switchover-date-has-value' : ''; ?>"><?php echo esc_html($switchover_val); ?></td>
                    <td class="<?php echo (trim((string) $completed_val) !== '') ? 'veyra-pcdm-col-switchover-date-has-value' : ''; ?>"><?php echo esc_html($completed_val); ?></td>
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

        <div id="veyra-pcdm-modal-overlay-postmeta" class="veyra-pcdm-modal-overlay">
            <div class="veyra-pcdm-modal-box">
                <h2>Perform content switchover now?</h2>
                <p>
                    This will immediately, for every selected item: copy
                    <code>vpostmeta_freshly_post_title</code> into <code>post_title</code>, copy
                    <code>vpostmeta_freshly_invented_content_before_deployment_to_live_post_content</code>
                    into <code>post_content</code> (erasing what's currently there), and set
                    <code>veyra_content_subspecies</code> to <code>new_freshly_invented_content</code>.
                </p>
                <p class="veyra-pcdm-modal-warning">This cannot be undone from this screen.</p>
                <div class="veyra-pcdm-modal-actions">
                    <button type="button" class="veyra-pcdm-modal-btn veyra-pcdm-modal-cancel" id="veyra-pcdm-modal-cancel-postmeta">Cancel</button>
                    <button type="button" class="veyra-pcdm-modal-btn veyra-pcdm-modal-confirm" id="veyra-pcdm-modal-confirm-postmeta">Yes, perform switchover now</button>
                </div>
            </div>
        </div>

        <div id="veyra-pcdm-erase-modal-overlay" class="veyra-pcdm-modal-overlay">
            <div class="veyra-pcdm-modal-box">
                <h2>Erase content from 4 old wp_options fields?</h2>
                <p>
                    This will permanently delete all content (set to null/empty) for the selected items in these fields:
                </p>
                <p>
                    <code>veyra_cached_original_wayback_post_title</code><br>
                    <code>veyra_cached_original_wayback_content</code><br>
                    <code>veyra_freshly_post_title</code><br>
                    <code>veyra_freshly_invented_content_before_deployment_to_live_post_content</code>
                </p>
                <p class="veyra-pcdm-modal-warning">This cannot be undone from this screen.</p>
                <div class="veyra-pcdm-modal-actions">
                    <button type="button" class="veyra-pcdm-modal-btn veyra-pcdm-modal-cancel" id="veyra-pcdm-erase-modal-cancel">Cancel</button>
                    <button type="button" class="veyra-pcdm-modal-btn veyra-pcdm-modal-confirm" id="veyra-pcdm-erase-modal-confirm">Confirm</button>
                </div>
            </div>
        </div>

        <div id="veyra-pcdm-pop-overlay" class="veyra-pcdm-pop-overlay" data-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_get_pop_data')); ?>" data-save-nonce="<?php echo esc_attr(wp_create_nonce('veyra_pcdm_save_pop_field')); ?>">
            <div class="veyra-pcdm-pop-box">
                <div class="veyra-pcdm-pop-header">
                    <h2 id="veyra-pcdm-pop-heading">Popup</h2>
                    <span class="veyra-pcdm-pop-meta" id="veyra-pcdm-pop-meta"></span>
                    <div class="veyra-pcdm-pop-tabs">
                        <button type="button" class="veyra-pcdm-pop-tab veyra-pcdm-pop-tab--active" data-pop-tab="live">live post_content</button>
                        <button type="button" class="veyra-pcdm-pop-tab" data-pop-tab="vpm-freshly">vpm-freshly</button>
                        <button type="button" class="veyra-pcdm-pop-tab" data-pop-tab="vpm-wayback">vpm-wayback</button>
                        <button type="button" class="veyra-pcdm-pop-tab veyra-pcdm-pop-tab--phasing-out" data-pop-tab="veyra-wayback">veyra-wayback</button>
                        <button type="button" class="veyra-pcdm-pop-tab veyra-pcdm-pop-tab--phasing-out" data-pop-tab="veyra-freshly">veyra-freshly</button>
                    </div>
                    <button type="button" class="veyra-pcdm-pop-close" id="veyra-pcdm-pop-close">&times;</button>
                </div>
                <div class="veyra-pcdm-pop-body">
                    <div class="veyra-pcdm-pop-panel veyra-pcdm-pop-panel--active" data-pop-panel="live">
                        <?php
                        veyra_pcdm_render_pop_panel_open('live');
                        veyra_pcdm_render_pop_field('post_title', '', 'veyra-pcdm-pop-live-title', 'text');
                        veyra_pcdm_render_pop_field('post_content', '', 'veyra-pcdm-pop-live-content', 'textarea');
                        veyra_pcdm_render_pop_panel_close();
                        ?>
                    </div>
                    <div class="veyra-pcdm-pop-panel" data-pop-panel="vpm-freshly">
                        <?php
                        veyra_pcdm_render_pop_panel_open('vpm-freshly');
                        veyra_pcdm_render_pop_field('vpm-freshly-title', 'vpostmeta_freshly_post_title', 'veyra-pcdm-pop-pm-freshly-title', 'text');
                        veyra_pcdm_render_pop_field('vpm-freshly-content', 'vpostmeta_freshly_invented_content_before_deployment_to_live_post_content', 'veyra-pcdm-pop-pm-freshly-content', 'textarea');
                        veyra_pcdm_render_pop_panel_close();
                        ?>
                    </div>
                    <div class="veyra-pcdm-pop-panel" data-pop-panel="vpm-wayback">
                        <?php
                        veyra_pcdm_render_pop_panel_open('vpm-wayback');
                        veyra_pcdm_render_pop_field('vpm-wayback-title', 'vpostmeta_cached_original_wayback_post_title', 'veyra-pcdm-pop-pm-wayback-title', 'text');
                        veyra_pcdm_render_pop_field('vpm-wayback-content', 'vpostmeta_cached_original_wayback_content', 'veyra-pcdm-pop-pm-wayback-content', 'textarea');
                        veyra_pcdm_render_pop_panel_close();
                        ?>
                    </div>
                    <div class="veyra-pcdm-pop-panel" data-pop-panel="veyra-wayback">
                        <?php
                        veyra_pcdm_render_pop_panel_open('veyra-wayback');
                        veyra_pcdm_render_pop_field('veyra-wayback-title', 'veyra_cached_original_wayback_post_title', 'veyra-pcdm-pop-old-wayback-title', 'text');
                        veyra_pcdm_render_pop_field('veyra-wayback-content', 'veyra_cached_original_wayback_content', 'veyra-pcdm-pop-old-wayback-content', 'textarea');
                        veyra_pcdm_render_pop_panel_close();
                        ?>
                    </div>
                    <div class="veyra-pcdm-pop-panel" data-pop-panel="veyra-freshly">
                        <?php
                        veyra_pcdm_render_pop_panel_open('veyra-freshly');
                        veyra_pcdm_render_pop_field('veyra-freshly-title', 'veyra_freshly_post_title', 'veyra-pcdm-pop-old-freshly-title', 'text');
                        veyra_pcdm_render_pop_field('veyra-freshly-content', 'veyra_freshly_invented_content_before_deployment_to_live_post_content', 'veyra-pcdm-pop-old-freshly-content', 'textarea');
                        veyra_pcdm_render_pop_panel_close();
                        ?>
                    </div>
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
        .veyra-pcdm-drip-controls-group {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid #3c434a;
            border-radius: 4px;
        }
        .veyra-pcdm-select-group {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .veyra-pcdm-algo-group {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
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
        .veyra-pcdm-migrate-group {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid gray;
            border-radius: 4px;
        }
        .veyra-pcdm-migrate-row {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .veyra-pcdm-column-toggle-group {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid gray;
            border-radius: 4px;
        }
        .veyra-pcdm-toggle-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            cursor: pointer;
        }
        .veyra-pcdm-toggle-switch {
            position: relative;
            display: inline-block;
            width: 36px;
            height: 20px;
            flex: 0 0 36px;
        }
        .veyra-pcdm-toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .veyra-pcdm-toggle-slider {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .2s;
            border-radius: 20px;
        }
        .veyra-pcdm-toggle-slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: #fff;
            transition: .2s;
            border-radius: 50%;
        }
        .veyra-pcdm-toggle-switch input:checked + .veyra-pcdm-toggle-slider {
            background-color: #2271b1;
        }
        .veyra-pcdm-toggle-switch input:checked + .veyra-pcdm-toggle-slider:before {
            transform: translateX(16px);
        }
        .veyra-pcdm-table.veyra-pcdm-hide-pm .veyra-pcdm-col-group-pm {
            display: none;
        }
        .veyra-pcdm-table.veyra-pcdm-hide-old .veyra-pcdm-col-group-old {
            display: none;
        }
        .veyra-pcdm-col-post-type-emphasis {
            font-weight: 700;
            font-style: italic;
        }
        .veyra-pcdm-pop-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 100000;
            align-items: flex-start;
            justify-content: center;
        }
        .veyra-pcdm-pop-overlay.veyra-pcdm-pop-open {
            display: flex;
        }
        .veyra-pcdm-pop-box {
            background: #fff;
            width: 97%;
            height: 96vh;
            margin-top: 2vh;
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,.3);
        }
        .veyra-pcdm-pop-header {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 10px 16px;
            border-bottom: 1px solid #ccc;
            background: #f0f0f1;
            flex: 0 0 auto;
        }
        .veyra-pcdm-pop-header h2 {
            margin: 0;
            font-size: 16px;
            white-space: nowrap;
        }
        .veyra-pcdm-pop-meta {
            font-size: 13px;
            color: #50575e;
            white-space: nowrap;
        }
        .veyra-pcdm-pop-tabs {
            display: flex;
            gap: 6px;
            flex: 1;
            flex-wrap: wrap;
            justify-content: center;
        }
        .veyra-pcdm-pop-tab {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        }
        .veyra-pcdm-pop-tab--phasing-out {
            background: #ccc;
        }
        .veyra-pcdm-pop-tab--active {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
        }
        .veyra-pcdm-pop-close {
            background: transparent;
            border: 1px solid #000;
            align-self: stretch;
            margin: -10px -16px -10px 0;
            font-size: 32px;
            line-height: 1;
            cursor: pointer;
            padding: 0 18px;
        }
        .veyra-pcdm-pop-close:hover {
            background: #f8d7da;
        }
        .veyra-pcdm-pop-body {
            flex: 1;
            min-height: 0;
            display: flex;
            padding: 16px 16px 10px 16px;
        }
        .veyra-pcdm-pop-panel {
            display: none;
            width: 100%;
        }
        .veyra-pcdm-pop-panel--active {
            display: flex;
            flex: 1;
            min-height: 0;
        }
        .veyra-pcdm-pop-tab-body {
            display: flex;
            flex: 1;
            min-height: 0;
            width: 100%;
            background: #f0f0f1;
        }
        .veyra-pcdm-pop-save-gutter {
            flex: 1;
            display: flex;
            justify-content: flex-end;
            align-items: flex-start;
            padding-right: 10px;
        }
        .veyra-pcdm-pop-gutter-right {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #b0b0b0;
            font-size: 48px;
            line-height: 1;
            user-select: none;
        }
        .veyra-pcdm-pop-gutter-right:hover {
            background: #f8d7da;
            color: #b32d2e;
        }
        .veyra-pcdm-pop-fields {
            flex: 0 0 1000px;
            width: 1000px;
            max-width: 1000px;
            box-sizing: border-box;
            border-left: 1px solid #ccc;
            border-right: 1px solid #ccc;
            padding: 0 16px;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .veyra-pcdm-pop-label {
            margin: 0 0 6px;
            font-weight: 700;
            flex: 0 0 auto;
        }
        .veyra-pcdm-pop-input {
            width: 100%;
            box-sizing: border-box;
            margin-bottom: 16px;
            padding: 6px 8px;
            font-size: 14px;
            flex: 0 0 auto;
            background: #fff;
        }
        .veyra-pcdm-pop-textarea {
            width: 100%;
            box-sizing: border-box;
            flex: 1;
            min-height: 0;
            font-family: monospace;
            font-size: 13px;
            padding: 8px;
            resize: none;
            background: #fff;
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
        .veyra-pcdm-switchover-btn-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .veyra-pcdm-switchover-now-old {
            background: #3c434a;
            color: #fff;
            border: 1px solid #3c434a;
            border-radius: 3px;
            padding: 4px 10px;
            line-height: 1.3;
            font-size: 11px;
            text-align: center;
            cursor: pointer;
        }
        .veyra-pcdm-revert-btn:hover {
            background: #6b0000;
            border-color: #6b0000;
            color: #fff;
        }
        .veyra-pcdm-migrate-btn {
            background: #7c3fc4;
            color: #fff;
            border: 1px solid #7c3fc4;
            border-radius: 3px;
            padding: 0 10px;
            line-height: 2.15384615;
            min-height: 30px;
            font-size: 13px;
            cursor: pointer;
        }
        .veyra-pcdm-migrate-btn:hover {
            background: #632fa3;
            border-color: #632fa3;
            color: #fff;
        }
        .veyra-pcdm-fe-btn {
            background: #000;
            border-color: #000;
            color: #fff;
        }
        .veyra-pcdm-fe-btn:hover {
            background: #333;
            border-color: #333;
            color: #fff;
        }
        .veyra-pcdm-sim-btn {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
        }
        .veyra-pcdm-sim-btn:hover {
            background: #135e96;
            border-color: #135e96;
            color: #fff;
        }
        .veyra-pcdm-revert-btn-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .veyra-pcdm-revert-btn-old {
            background: #3c434a;
            color: #fff;
            border: 1px solid #3c434a;
            border-radius: 3px;
            padding: 4px 10px;
            line-height: 1.3;
            font-size: 11px;
            text-align: center;
            cursor: pointer;
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
        .veyra-pcdm-table th.veyra-pcdm-th-bottom-star {
            vertical-align: bottom;
            text-align: center;
        }
        .veyra-pcdm-th-star {
            display: block;
            color: maroon;
            font-size: 12px;
            line-height: 1;
            margin-bottom: 3px;
        }
        .veyra-pcdm-table th.veyra-pcdm-col-pm-title,
        .veyra-pcdm-table td.veyra-pcdm-col-pm-title {
            width: 132px !important;
            max-width: 132px !important;
        }
        .veyra-pcdm-table td.veyra-pcdm-col-pm-title {
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .veyra-pcdm-table th.veyra-pcdm-col-old-title,
        .veyra-pcdm-table td.veyra-pcdm-col-old-title {
            width: 122px !important;
            max-width: 122px !important;
        }
        .veyra-pcdm-table td.veyra-pcdm-col-old-title {
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .veyra-pcdm-table .veyra-pcdm-col-old-freshly-title-sep {
            border-left: 2px solid #000 !important;
        }
        .veyra-pcdm-table .veyra-pcdm-col-tools {
            border-left: 2px solid #000 !important;
            border-right: 2px solid #000 !important;
        }
        .veyra-pcdm-table th.veyra-pcdm-col-pm-freshly-title,
        .veyra-pcdm-table th.veyra-pcdm-col-pm-freshly-content {
            background: #d9f2d9 !important;
        }
        .veyra-pcdm-table td.veyra-pcdm-col-pm-freshly-has-value {
            background: #d9f2d9 !important;
        }
        .veyra-pcdm-table th.veyra-pcdm-col-pm-wayback-title,
        .veyra-pcdm-table th.veyra-pcdm-col-pm-cached-content {
            background: #d6e9fb !important;
        }
        .veyra-pcdm-table td.veyra-pcdm-col-pm-wayback-has-value {
            background: #d6e9fb !important;
        }
        .veyra-pcdm-table .veyra-pcdm-col-pm-cached-content {
            border-right: 2px solid #000 !important;
        }
        .veyra-pcdm-table .veyra-pcdm-col-old-wayback-title-sep {
            border-left: 2px solid #000 !important;
        }
        .veyra-pcdm-table .veyra-pcdm-col-pm-wayback-title-sep {
            border-left: 2px solid #000 !important;
        }
        .veyra-pcdm-table .veyra-pcdm-col-species {
            border-left: 2px solid #000 !important;
        }
        .veyra-pcdm-table .veyra-pcdm-col-subspecies {
            border-right: 2px solid #000 !important;
        }
        .veyra-pcdm-table th.veyra-pcdm-col-species,
        .veyra-pcdm-table th.veyra-pcdm-col-subspecies {
            background: #fbe4e9 !important;
        }
        .veyra-pcdm-table th.veyra-pcdm-col-switchover-date {
            background: #fff8c4 !important;
        }
        .veyra-pcdm-table td.veyra-pcdm-col-switchover-date-has-value {
            background: #fff8c4 !important;
        }
        .veyra-pcdm-table th.veyra-pcdm-col-title,
        .veyra-pcdm-table td.veyra-pcdm-col-title {
            width: 200px !important;
            max-width: 200px !important;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            border-left: 2px solid #000 !important;
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
        .veyra-pcdm-table td.veyra-pcdm-cell-clickable {
            cursor: pointer;
        }
        .veyra-pcdm-table td.veyra-pcdm-cell-clickable:hover {
            outline: 2px solid #2271b1;
            outline-offset: -2px;
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
                var origHtml = switchoverNowBtn.innerHTML;
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
                            switchoverNowBtn.innerHTML = origHtml;
                        }
                    })
                    .catch(function(){
                        alert('Failed to perform switchover.');
                        switchoverNowBtn.disabled = false;
                        switchoverNowBtn.innerHTML = origHtml;
                    });
            });
        }

        // "perform content switchover now" (postmeta version) — same modal-
        // confirm flow as the wp_options version above, mirrored 1:1 against
        // a separate modal + AJAX action (veyra_pcdm_switchover_now_postmeta).
        var switchoverNowPmBtn   = document.getElementById('veyra-pcdm-switchover-now-postmeta');
        var modalOverlayPm       = document.getElementById('veyra-pcdm-modal-overlay-postmeta');
        var modalCancelPmBtn     = document.getElementById('veyra-pcdm-modal-cancel-postmeta');
        var modalConfirmPmBtn    = document.getElementById('veyra-pcdm-modal-confirm-postmeta');
        var pendingSwitchoverPmIds = null;

        function closeModalPm(){
            if (modalOverlayPm) { modalOverlayPm.classList.remove('veyra-pcdm-modal-open'); }
            pendingSwitchoverPmIds = null;
        }

        if (switchoverNowPmBtn && modalOverlayPm) {
            switchoverNowPmBtn.addEventListener('click', function(){
                var selected = Array.prototype.slice.call(rowCbs()).filter(function(c){ return c.checked; });
                if (selected.length === 0) {
                    alert('No items selected. Tick the checkboxes for the items you want to switch over now.');
                    return;
                }
                pendingSwitchoverPmIds = selected.map(function(c){ return c.value; });
                modalOverlayPm.classList.add('veyra-pcdm-modal-open');
            });
        }
        if (modalCancelPmBtn) {
            modalCancelPmBtn.addEventListener('click', closeModalPm);
        }
        if (modalOverlayPm) {
            modalOverlayPm.addEventListener('click', function(e){
                if (e.target === modalOverlayPm) { closeModalPm(); }
            });
        }
        if (modalConfirmPmBtn) {
            modalConfirmPmBtn.addEventListener('click', function(){
                if (!pendingSwitchoverPmIds || !pendingSwitchoverPmIds.length) {
                    closeModalPm();
                    return;
                }
                var ids = pendingSwitchoverPmIds;
                closeModalPm();

                switchoverNowPmBtn.disabled = true;
                var origText = switchoverNowPmBtn.textContent;
                switchoverNowPmBtn.textContent = 'performing switchover...';

                var body = new URLSearchParams();
                body.set('action', 'veyra_pcdm_switchover_now_postmeta');
                body.set('nonce', switchoverNowPmBtn.getAttribute('data-nonce') || '');
                body.set('ids', JSON.stringify(ids));

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            location.reload();
                        } else {
                            alert('Failed to perform switchover.');
                            switchoverNowPmBtn.disabled = false;
                            switchoverNowPmBtn.textContent = origText;
                        }
                    })
                    .catch(function(){
                        alert('Failed to perform switchover.');
                        switchoverNowPmBtn.disabled = false;
                        switchoverNowPmBtn.textContent = origText;
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
                var origHtml = revertBtn.innerHTML;
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
                            revertBtn.innerHTML = origHtml;
                        }
                    })
                    .catch(function(){
                        alert('Failed to revert switchover.');
                        revertBtn.disabled = false;
                        revertBtn.innerHTML = origHtml;
                    });
            });
        }

        // "revert switchover" (postmeta version) — same shape as the
        // wp_options version above, hits veyra_pcdm_revert_switchover_postmeta.
        var revertPmBtn = document.getElementById('veyra-pcdm-revert-switchover-postmeta');
        if (revertPmBtn) {
            revertPmBtn.addEventListener('click', function(){
                var selected = Array.prototype.slice.call(rowCbs()).filter(function(c){ return c.checked; });
                if (selected.length === 0) {
                    alert('No items selected. Tick the checkboxes for the items you want to revert.');
                    return;
                }
                var ids = selected.map(function(c){ return c.value; });

                revertPmBtn.disabled = true;
                var origText = revertPmBtn.textContent;
                revertPmBtn.textContent = 'reverting...';

                var body = new URLSearchParams();
                body.set('action', 'veyra_pcdm_revert_switchover_postmeta');
                body.set('nonce', revertPmBtn.getAttribute('data-nonce') || '');
                body.set('ids', JSON.stringify(ids));

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            location.reload();
                        } else {
                            alert('Failed to revert switchover.');
                            revertPmBtn.disabled = false;
                            revertPmBtn.textContent = origText;
                        }
                    })
                    .catch(function(){
                        alert('Failed to revert switchover.');
                        revertPmBtn.disabled = false;
                        revertPmBtn.textContent = origText;
                    });
            });
        }

        // "migrate old wp_options content to wp_postmeta" — for the selected
        // items, copies the 4 old wp_options values into the matching new
        // vpostmeta_ postmeta keys (non-destructive: old fields are untouched).
        var migrateBtn = document.getElementById('veyra-pcdm-migrate-options-to-postmeta');
        if (migrateBtn) {
            migrateBtn.addEventListener('click', function(){
                var selected = Array.prototype.slice.call(rowCbs()).filter(function(c){ return c.checked; });
                if (selected.length === 0) {
                    alert('No items selected. Tick the checkboxes for the items you want to migrate.');
                    return;
                }
                var ids = selected.map(function(c){ return c.value; });

                migrateBtn.disabled = true;
                var origText = migrateBtn.textContent;
                migrateBtn.textContent = 'migrating...';

                var body = new URLSearchParams();
                body.set('action', 'veyra_pcdm_migrate_options_to_postmeta');
                body.set('nonce', migrateBtn.getAttribute('data-nonce') || '');
                body.set('ids', JSON.stringify(ids));

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            location.reload();
                        } else {
                            alert('Failed to migrate content.');
                            migrateBtn.disabled = false;
                            migrateBtn.textContent = origText;
                        }
                    })
                    .catch(function(){
                        alert('Failed to migrate content.');
                        migrateBtn.disabled = false;
                        migrateBtn.textContent = origText;
                    });
            });
        }

        // "erase content from 4 old wp_options fields" — destructive, so this
        // requires an extra native confirm() on top of requiring a selection.
        var eraseBtn = document.getElementById('veyra-pcdm-erase-old-options-fields');
        var eraseModalOverlay = document.getElementById('veyra-pcdm-erase-modal-overlay');
        var eraseModalCancelBtn = document.getElementById('veyra-pcdm-erase-modal-cancel');
        var eraseModalConfirmBtn = document.getElementById('veyra-pcdm-erase-modal-confirm');
        var pendingEraseIds = null;

        function closeEraseModal(){
            if (eraseModalOverlay) { eraseModalOverlay.classList.remove('veyra-pcdm-modal-open'); }
            pendingEraseIds = null;
        }

        if (eraseBtn && eraseModalOverlay) {
            eraseBtn.addEventListener('click', function(){
                var selected = Array.prototype.slice.call(rowCbs()).filter(function(c){ return c.checked; });
                if (selected.length === 0) {
                    alert('No items selected. Tick the checkboxes for the items you want to erase.');
                    return;
                }
                pendingEraseIds = selected.map(function(c){ return c.value; });
                eraseModalOverlay.classList.add('veyra-pcdm-modal-open');
            });
        }
        if (eraseModalCancelBtn) {
            eraseModalCancelBtn.addEventListener('click', closeEraseModal);
        }
        if (eraseModalOverlay) {
            eraseModalOverlay.addEventListener('click', function(e){
                if (e.target === eraseModalOverlay) { closeEraseModal(); }
            });
        }
        if (eraseModalConfirmBtn) {
            eraseModalConfirmBtn.addEventListener('click', function(){
                if (!pendingEraseIds || !pendingEraseIds.length) {
                    closeEraseModal();
                    return;
                }
                var ids = pendingEraseIds;
                closeEraseModal();

                eraseBtn.disabled = true;
                var origText = eraseBtn.textContent;
                eraseBtn.textContent = 'erasing...';

                var body = new URLSearchParams();
                body.set('action', 'veyra_pcdm_erase_old_options_fields');
                body.set('nonce', eraseBtn.getAttribute('data-nonce') || '');
                body.set('ids', JSON.stringify(ids));

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            location.reload();
                        } else {
                            alert('Failed to erase content.');
                            eraseBtn.disabled = false;
                            eraseBtn.textContent = origText;
                        }
                    })
                    .catch(function(){
                        alert('Failed to erase content.');
                        eraseBtn.disabled = false;
                        eraseBtn.textContent = origText;
                    });
            });
        }

        // "select all" — same effect as checking the header select-all checkbox:
        // dispatches its change event so the one existing handler runs unchanged.
        var selectAllBtn = document.getElementById('veyra-pcdm-select-all-btn');
        if (selectAllBtn && selectAll) {
            selectAllBtn.addEventListener('click', function(){
                selectAll.checked = true;
                selectAll.dispatchEvent(new Event('change'));
            });
        }

        // Column show/hide toggles: on by default; unchecking either one hides
        // its 4 columns (marked with the matching veyra-pcdm-col-group-* class
        // on both <th> and <td>) via a class on the table itself.
        var mainTable = document.querySelector('.veyra-pcdm-table');
        var togglePmCols = document.getElementById('veyra-pcdm-toggle-pm-cols');
        var toggleOldCols = document.getElementById('veyra-pcdm-toggle-old-cols');
        if (togglePmCols && mainTable) {
            var applyPmToggle = function(){ mainTable.classList.toggle('veyra-pcdm-hide-pm', !togglePmCols.checked); };
            togglePmCols.addEventListener('change', applyPmToggle);
            applyPmToggle(); // sync on load in case the saved default is "off"
        }
        if (toggleOldCols && mainTable) {
            var applyOldToggle = function(){ mainTable.classList.toggle('veyra-pcdm-hide-old', !toggleOldCols.checked); };
            toggleOldCols.addEventListener('change', applyOldToggle);
            applyOldToggle(); // sync on load in case the saved default is "off"
        }

        // "Save As Default" — persists the current on/off state of both toggles
        // so this page loads in that state next time (server re-renders the
        // checkboxes' checked attribute from veyra_pcdm_column_toggle_defaults).
        var saveDefaultsBtn = document.getElementById('veyra-pcdm-save-toggle-defaults');
        if (saveDefaultsBtn) {
            saveDefaultsBtn.addEventListener('click', function(){
                saveDefaultsBtn.disabled = true;
                var origText = saveDefaultsBtn.textContent;
                saveDefaultsBtn.textContent = 'saving...';

                var body = new URLSearchParams();
                body.set('action', 'veyra_pcdm_save_toggle_defaults');
                body.set('nonce', saveDefaultsBtn.getAttribute('data-nonce') || '');
                body.set('show_pm', (togglePmCols && togglePmCols.checked) ? '1' : '0');
                body.set('show_old', (toggleOldCols && toggleOldCols.checked) ? '1' : '0');

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            saveDefaultsBtn.textContent = 'saved!';
                            setTimeout(function(){
                                saveDefaultsBtn.textContent = origText;
                                saveDefaultsBtn.disabled = false;
                            }, 1200);
                        } else {
                            alert('Failed to save defaults.');
                            saveDefaultsBtn.disabled = false;
                            saveDefaultsBtn.textContent = origText;
                        }
                    })
                    .catch(function(){
                        alert('Failed to save defaults.');
                        saveDefaultsBtn.disabled = false;
                        saveDefaultsBtn.textContent = origText;
                    });
            });
        }

        // "pop" button — opens a full-height tabbed popup for one post, lazy-
        // loading its live post_title/post_content plus all 4 postmeta and 4
        // old wp_options title/content pairs via AJAX, keyed by post ID.
        var popOverlay = document.getElementById('veyra-pcdm-pop-overlay');
        var popHeading  = document.getElementById('veyra-pcdm-pop-heading');
        var popMeta     = document.getElementById('veyra-pcdm-pop-meta');
        var popCloseBtn = document.getElementById('veyra-pcdm-pop-close');

        var POP_FIELD_MAP = {
            live_title:          'veyra-pcdm-pop-live-title',
            live_content:        'veyra-pcdm-pop-live-content',
            pm_wayback_title:    'veyra-pcdm-pop-pm-wayback-title',
            pm_wayback_content:  'veyra-pcdm-pop-pm-wayback-content',
            pm_freshly_title:    'veyra-pcdm-pop-pm-freshly-title',
            pm_freshly_content:  'veyra-pcdm-pop-pm-freshly-content',
            old_wayback_title:   'veyra-pcdm-pop-old-wayback-title',
            old_wayback_content: 'veyra-pcdm-pop-old-wayback-content',
            old_freshly_title:   'veyra-pcdm-pop-old-freshly-title',
            old_freshly_content: 'veyra-pcdm-pop-old-freshly-content'
        };

        // Which title/content element ids each Save button's tab writes back to.
        var POP_TAB_FIELD_IDS = {
            'live':          { title: 'veyra-pcdm-pop-live-title',        content: 'veyra-pcdm-pop-live-content' },
            'vpm-wayback':   { title: 'veyra-pcdm-pop-pm-wayback-title',  content: 'veyra-pcdm-pop-pm-wayback-content' },
            'vpm-freshly':   { title: 'veyra-pcdm-pop-pm-freshly-title',  content: 'veyra-pcdm-pop-pm-freshly-content' },
            'veyra-wayback': { title: 'veyra-pcdm-pop-old-wayback-title', content: 'veyra-pcdm-pop-old-wayback-content' },
            'veyra-freshly': { title: 'veyra-pcdm-pop-old-freshly-title', content: 'veyra-pcdm-pop-old-freshly-content' }
        };
        var currentPopPostId = null;

        function popSetFields(data) {
            Object.keys(POP_FIELD_MAP).forEach(function(key){
                var el = document.getElementById(POP_FIELD_MAP[key]);
                if (el) { el.value = (data && data[key]) ? data[key] : ''; }
            });
        }

        function popSwitchTab(tabName) {
            document.querySelectorAll('.veyra-pcdm-pop-tab').forEach(function(t){
                t.classList.toggle('veyra-pcdm-pop-tab--active', t.getAttribute('data-pop-tab') === tabName);
            });
            document.querySelectorAll('.veyra-pcdm-pop-panel').forEach(function(p){
                p.classList.toggle('veyra-pcdm-pop-panel--active', p.getAttribute('data-pop-panel') === tabName);
            });
        }

        function closePop() {
            if (popOverlay) { popOverlay.classList.remove('veyra-pcdm-pop-open'); }
        }

        if (popOverlay) {
            // Opens the pop popup for one post, switched to the given tab, and
            // lazy-loads its data. Shared by the "pop" button (always 'live')
            // and the 8 clickable field <td>s below (each opens its own tab).
            function openPopForPost(postId, initialTab) {
                currentPopPostId = postId;
                if (popHeading) { popHeading.textContent = 'Popup ' + postId; }
                if (popMeta) { popMeta.textContent = ''; }
                popSetFields(null);
                popSwitchTab(initialTab);
                popOverlay.classList.add('veyra-pcdm-pop-open');

                var body = new URLSearchParams();
                body.set('action', 'veyra_pcdm_get_pop_data');
                body.set('nonce', popOverlay.getAttribute('data-nonce') || '');
                body.set('post_id', postId);

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            popSetFields(resp.data);
                            if (popMeta && resp.data) {
                                popMeta.textContent = 'post_id: ' + resp.data.post_id + ' | ' + resp.data.live_title
                                    + ' | ' + resp.data.post_type + ' | ' + resp.data.post_status;
                            }
                        } else {
                            alert('Failed to load popup data.');
                        }
                    })
                    .catch(function(){
                        alert('Failed to load popup data.');
                    });
            }

            document.querySelectorAll('.veyra-pcdm-pop-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    openPopForPost(btn.getAttribute('data-post-id'), 'live');
                });
            });

            // Any of the 8 field <td>s (vpm-wayback/vpm-freshly/veyra-wayback/
            // veyra-freshly, title+content each) opens the popup already on
            // its own tab — the whole cell is clickable, not just its text.
            document.querySelectorAll('.veyra-pcdm-cell-clickable').forEach(function(cell){
                cell.addEventListener('click', function(){
                    var row = cell.closest('tr');
                    var postId = row ? row.getAttribute('data-post-id') : null;
                    var tab = cell.getAttribute('data-pop-tab');
                    if (!postId || !tab) { return; }
                    openPopForPost(postId, tab);
                });
            });

            document.querySelectorAll('.veyra-pcdm-pop-tab').forEach(function(tabBtn){
                tabBtn.addEventListener('click', function(){
                    popSwitchTab(tabBtn.getAttribute('data-pop-tab'));
                });
            });

            // Each tab's own Save button persists only that tab's title/content
            // pair, for the post currently open in the popup.
            document.querySelectorAll('.veyra-pcdm-pop-save-btn').forEach(function(saveBtn){
                saveBtn.addEventListener('click', function(){
                    var tab = saveBtn.getAttribute('data-pop-tab');
                    var ids = POP_TAB_FIELD_IDS[tab];
                    if (!ids || !currentPopPostId) { return; }
                    var titleEl   = document.getElementById(ids.title);
                    var contentEl = document.getElementById(ids.content);

                    saveBtn.disabled = true;
                    var origText = saveBtn.textContent;
                    saveBtn.textContent = 'saving...';

                    var body = new URLSearchParams();
                    body.set('action', 'veyra_pcdm_save_pop_field');
                    body.set('nonce', popOverlay.getAttribute('data-save-nonce') || '');
                    body.set('post_id', currentPopPostId);
                    body.set('tab', tab);
                    body.set('title', titleEl ? titleEl.value : '');
                    body.set('content', contentEl ? contentEl.value : '');

                    fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function(r){ return r.json(); })
                        .then(function(resp){
                            if (resp && resp.success) {
                                saveBtn.textContent = 'saved!';
                                setTimeout(function(){
                                    saveBtn.textContent = origText;
                                    saveBtn.disabled = false;
                                }, 1200);
                            } else {
                                alert('Failed to save.');
                                saveBtn.disabled = false;
                                saveBtn.textContent = origText;
                            }
                        })
                        .catch(function(){
                            alert('Failed to save.');
                            saveBtn.disabled = false;
                            saveBtn.textContent = origText;
                        });
                });
            });

            if (popCloseBtn) { popCloseBtn.addEventListener('click', closePop); }
            document.querySelectorAll('.veyra-pcdm-pop-close-area').forEach(function(area){
                area.addEventListener('click', closePop);
            });
            popOverlay.addEventListener('click', function(e){
                if (e.target === popOverlay) { closePop(); }
            });
        }
    })();
    </script>
    <?php
}
