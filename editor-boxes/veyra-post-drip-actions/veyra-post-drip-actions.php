<?php
/**
 * Veyra — Post Drip Actions (editor sidebar box).
 *
 * Lets the user queue up actions to run against THIS post at a chosen future
 * date/time — "change status to draft on Sep 1", "move to trash on Oct 15" —
 * and see/cancel whatever is already queued.
 *
 * A post can hold any number of queued actions; each is its own row in
 * {$wpdb->prefix}veyra_post_drip_actions and is independently cancelable.
 * Rows are never deleted — done/canceled/failed rows remain as the queue's
 * own audit trail.
 *
 * Firing: each newly scheduled action gets a wp_schedule_single_event() at its
 * exact minute, so it runs on time rather than whenever a coarse sweep next
 * happens to wake up. A recurring 5-minute sweep (same pattern as the Page
 * Change Drip Manager) is the safety net that catches anything the single
 * event missed — a skipped cron run, a site that was asleep, a row scheduled
 * while cron was broken. Execution claims each row atomically, so the two
 * paths can never double-run the same action.
 *
 * Schema note: the table is declared in veyra.php
 * (Veyra::veyra_editor_boxes_create_tables) so it is built by the plugin's
 * activation hook — deactivate/reactivate Veyra on a live site and it appears.
 * veyra_pda_table() is likewise defined there.
 *
 * Kept entirely in this file to avoid cluttering veyra.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Vocabulary
// ---------------------------------------------------------------------------

/** The statuses a drip action is allowed to set. Filterable. */
function veyra_pda_allowed_statuses() {
    return apply_filters('veyra_pda_allowed_statuses', array(
        'draft'   => 'Change status to draft',
        'trash'   => 'Change status to trash',
        'publish' => 'Change status to published',
        'private' => 'Change status to private',
        'pending' => 'Change status to pending review',
    ));
}

/** Post types that get the box (mirrors the history box's exclusions). */
function veyra_pda_excluded_post_types() {
    return function_exists('veyra_pah_excluded_post_types')
        ? veyra_pah_excluded_post_types()
        : array('revision', 'attachment', 'nav_menu_item');
}

// ---------------------------------------------------------------------------
// Queue-event logging
//
// Mutating the queue is itself a fact worth recording, separate from whatever
// the action later does to the post. These events go into the SAME table as
// status/date changes (veyra_post_status_log) so one post has one chronological
// timeline, and are distinguished there by event_type.
//
// event_target_gmt carries a frozen copy of the action's scheduled_for_gmt, so
// the entry keeps saying what it was aimed at even after the action is canceled
// or rescheduled. drip_action_id still points at the live row for context.
// ---------------------------------------------------------------------------

/**
 * Record one queue mutation against the post's timeline.
 *
 * Silently does nothing if the history feature is absent — the drip queue must
 * keep working on its own if that file is ever removed.
 */
function veyra_pda_log_queue_event($event_type, $drip_row) {
    if (!function_exists('veyra_pah_insert_event') || !is_array($drip_row)) {
        return;
    }
    $post = get_post(intval($drip_row['post_id']));

    veyra_pah_insert_event(array(
        'post_id'          => intval($drip_row['post_id']),
        'post_type'        => $post ? $post->post_type : '',
        'event_type'       => $event_type,
        // The post's status right now, and the status this action targets.
        'old_status'       => $post ? $post->post_status : '',
        'new_status'       => (string) $drip_row['action_value'],
        'event_target_gmt' => (string) $drip_row['scheduled_for_gmt'],
        'drip_action_id'   => intval($drip_row['id']),
        // Deliberately no post_date_* values: the queue changed, the post did not.
    ));

    // Note: log_id on the queue row is NOT set here. It keeps its single
    // meaning — the status-change event this action produced when it actually
    // ran — and is written by veyra_pda_execute_action(). The reverse link is
    // what ties these events back: status_log.drip_action_id -> drip row.
}

/** Fetch one queue row as an array, or null. */
function veyra_pda_get_row($row_id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . veyra_pda_table() . " WHERE id = %d", intval($row_id)), ARRAY_A);
    return $row ?: null;
}

// ---------------------------------------------------------------------------
// Scheduling / cancellation
// ---------------------------------------------------------------------------

/**
 * Queue one action. $local_datetime is site-local wall time ("2026-09-01 03:00"),
 * exactly as typed into the datetime-local input. Returns the new row id, or a
 * WP_Error.
 */
function veyra_pda_schedule_action($post_id, $action_value, $local_datetime, $notes = '') {
    global $wpdb;

    $post_id = intval($post_id);
    if (!$post_id || !get_post($post_id)) {
        return new WP_Error('bad_post', 'That post no longer exists.');
    }
    $allowed = veyra_pda_allowed_statuses();
    if (!isset($allowed[$action_value])) {
        return new WP_Error('bad_action', 'Unrecognized action.');
    }

    // Normalize "2026-09-01T03:00" (datetime-local) to MySQL local, then to GMT.
    $local_datetime = str_replace('T', ' ', trim((string) $local_datetime));
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $local_datetime)) {
        return new WP_Error('bad_date', 'Please pick a valid date and time.');
    }
    if (strlen($local_datetime) === 16) {
        $local_datetime .= ':00';
    }
    $gmt = get_gmt_from_date($local_datetime, 'Y-m-d H:i:s');
    if (strtotime($gmt . ' UTC') <= time()) {
        return new WP_Error('past_date', 'Pick a date and time in the future.');
    }

    $ok = $wpdb->insert(veyra_pda_table(), array(
        'post_id'             => $post_id,
        'action_type'         => 'set_status',
        'action_value'        => $action_value,
        'scheduled_for_gmt'   => $gmt,
        'scheduled_for_local' => $local_datetime,
        'status'              => 'pending',
        'notes'               => (string) $notes,
        'created_at_gmt'      => current_time('mysql', 1),
        'created_by'          => get_current_user_id() ?: null,
    ));
    if (!$ok) {
        return new WP_Error('db_error', 'Could not save the scheduled action.');
    }

    $row_id = intval($wpdb->insert_id);
    wp_schedule_single_event(strtotime($gmt . ' UTC'), 'veyra_pda_run_action', array($row_id));

    veyra_pda_log_queue_event('drip_scheduled', veyra_pda_get_row($row_id));

    return $row_id;
}

/** Cancel a pending action. Never touches rows that already ran. */
function veyra_pda_cancel_action($row_id) {
    global $wpdb;

    $row_id  = intval($row_id);
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE " . veyra_pda_table() . "
            SET status = 'canceled', canceled_at_gmt = %s, canceled_by = %d
          WHERE id = %d AND status = 'pending'",
        current_time('mysql', 1),
        get_current_user_id(),
        $row_id
    ));
    if (!$updated) {
        return false;
    }

    $ts = wp_next_scheduled('veyra_pda_run_action', array($row_id));
    if ($ts) {
        wp_unschedule_event($ts, 'veyra_pda_run_action', array($row_id));
    }

    veyra_pda_log_queue_event('drip_canceled', veyra_pda_get_row($row_id));

    return true;
}

/** A permanently deleted post can't have its queue run — void it. */
add_action('before_delete_post', 'veyra_pda_cancel_actions_for_deleted_post', 10, 1);
function veyra_pda_cancel_actions_for_deleted_post($post_id) {
    global $wpdb;
    $table = veyra_pda_table();
    $ids   = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$table} WHERE post_id = %d AND status = 'pending'", intval($post_id)));
    foreach ($ids as $id) {
        $wpdb->update($table, array(
            'status'          => 'canceled',
            'canceled_at_gmt' => current_time('mysql', 1),
            'result_note'     => 'Canceled automatically — the post was deleted permanently.',
        ), array('id' => intval($id)));
        $ts = wp_next_scheduled('veyra_pda_run_action', array(intval($id)));
        if ($ts) {
            wp_unschedule_event($ts, 'veyra_pda_run_action', array(intval($id)));
        }
        veyra_pda_log_queue_event('drip_auto_canceled', veyra_pda_get_row($id));
    }
}

// ---------------------------------------------------------------------------
// Execution
// ---------------------------------------------------------------------------

/**
 * Run one queued action by row id.
 *
 * The row is claimed with a conditional UPDATE before any work happens, so the
 * single event and the sweep (and two overlapping cron runs) can race freely
 * without the action ever executing twice.
 */
function veyra_pda_execute_action($row_id) {
    global $wpdb;

    $table  = veyra_pda_table();
    $row_id = intval($row_id);

    $claimed = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET status = 'running' WHERE id = %d AND status = 'pending'", $row_id));
    if (!$claimed) {
        return false; // already claimed, canceled, or gone
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $row_id), ARRAY_A);
    if (!$row) {
        return false;
    }

    // Cleared up front so the log_id recorded below can only ever be a row this
    // action itself produced — never a leftover from some earlier transition in
    // the same request (e.g. the sweep running several actions in a row).
    $GLOBALS['veyra_pah_last_insert_id'] = null;

    $finish = function ($status, $note, $before = null, $after = null) use ($wpdb, $table, $row_id) {
        $wpdb->update($table, array(
            'status'          => $status,
            'executed_at_gmt' => current_time('mysql', 1),
            'status_before'   => $before,
            'status_after'    => $after,
            'result_note'     => $note,
            'log_id'          => !empty($GLOBALS['veyra_pah_last_insert_id'])
                ? intval($GLOBALS['veyra_pah_last_insert_id']) : null,
        ), array('id' => $row_id));
        return $status === 'done';
    };

    $post = get_post(intval($row['post_id']));
    if (!$post) {
        return $finish('failed', 'The post no longer exists.');
    }

    $before = $post->post_status;
    $target = $row['action_value'];

    if (!isset(veyra_pda_allowed_statuses()[$target])) {
        return $finish('failed', 'Unrecognized action value: ' . $target);
    }
    if ($before === $target) {
        return $finish('skipped', 'Already ' . $target . ' — nothing to change.', $before, $before);
    }

    // Tell the history box who is responsible, so the resulting log row is
    // attributed to this drip action rather than to "cron".
    $GLOBALS['veyra_pah_source_override'] = array(
        'source'         => 'veyra_drip',
        'detail'         => 'drip action #' . $row_id . ' (' . $target . ')',
        'drip_action_id' => $row_id,
    );

    $extra_note = '';
    try {
        if ($target === 'trash') {
            wp_trash_post($post->ID);
        } else {
            // Coming out of the trash: untrash first so WP restores the slug
            // (it appends __trashed on the way in) and clears the desired-status
            // meta, then move to the target status if untrash didn't land there.
            if ($before === 'trash') {
                wp_untrash_post($post->ID);
                $extra_note = ' Restored from trash first.';
            }
            $args = array('ID' => $post->ID, 'post_status' => $target);

            // wp_insert_post() forces 'future' when post_date is ahead of now,
            // so a "publish it on date X" action against a post still carrying a
            // future date would silently re-schedule instead of publishing.
            // Move the date to now so the user's intent actually happens.
            if ($target === 'publish'
                && strtotime($post->post_date_gmt . ' UTC') > time()) {
                $args['post_date']     = current_time('mysql');
                $args['post_date_gmt'] = current_time('mysql', 1);
                $extra_note .= ' Post date moved to now (it was set in the future).';
            }
            wp_update_post($args);
        }
    } catch (Exception $e) {
        unset($GLOBALS['veyra_pah_source_override']);
        return $finish('failed', 'Error: ' . $e->getMessage(), $before, get_post_status($post->ID));
    }
    unset($GLOBALS['veyra_pah_source_override']);

    $after = get_post_status($post->ID);
    if ($after !== $target) {
        return $finish('failed', 'Tried to set ' . $target . ' but the post is ' . $after . '.' . $extra_note, $before, $after);
    }
    return $finish('done', $before . ' -> ' . $after . '.' . $extra_note, $before, $after);
}

/** Fired by the per-action wp_schedule_single_event(). */
add_action('veyra_pda_run_action', 'veyra_pda_execute_action', 10, 1);

// ---------------------------------------------------------------------------
// Cron: the recurring safety-net sweep
// ---------------------------------------------------------------------------
add_filter('cron_schedules', 'veyra_pda_add_cron_interval');
function veyra_pda_add_cron_interval($schedules) {
    $schedules['veyra_pda_five_minutes'] = array(
        'interval' => 300,
        'display'  => 'Every 5 Minutes (Veyra Post Drip Actions)',
    );
    return $schedules;
}

add_action('init', 'veyra_pda_ensure_cron_scheduled');
function veyra_pda_ensure_cron_scheduled() {
    if (!wp_next_scheduled('veyra_pda_sweep')) {
        wp_schedule_event(time(), 'veyra_pda_five_minutes', 'veyra_pda_sweep');
    }
}

register_deactivation_hook(VEYRA_PLUGIN_PATH . 'veyra.php', 'veyra_pda_clear_cron_schedule');
function veyra_pda_clear_cron_schedule() {
    $timestamp = wp_next_scheduled('veyra_pda_sweep');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'veyra_pda_sweep');
    }
}

add_action('veyra_pda_sweep', 'veyra_pda_process_due_actions');
function veyra_pda_process_due_actions() {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM " . veyra_pda_table() . "
          WHERE status = 'pending' AND scheduled_for_gmt <= %s
          ORDER BY scheduled_for_gmt ASC
          LIMIT 200",
        current_time('mysql', 1)
    ));
    foreach ($ids as $id) {
        veyra_pda_execute_action(intval($id));
    }
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

/** Human label for a queue row's state. */
function veyra_pda_state_label($status) {
    $map = array(
        'pending'  => 'scheduled',
        'running'  => 'running…',
        'done'     => 'done',
        'skipped'  => 'skipped',
        'failed'   => 'failed',
        'canceled' => 'canceled',
    );
    return isset($map[$status]) ? $map[$status] : $status;
}

/** Every action row for a post, pending first, then most recent. */
function veyra_pda_get_actions($post_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . veyra_pda_table() . "
          WHERE post_id = %d
          ORDER BY (status = 'pending') DESC, scheduled_for_gmt ASC, id ASC",
        intval($post_id)
    ), ARRAY_A);
}

/** Render the queue list (shared by initial paint and every AJAX refresh). */
function veyra_pda_render_list($post_id) {
    $rows    = veyra_pda_get_actions($post_id);
    $allowed = veyra_pda_allowed_statuses();

    if (!$rows) {
        echo '<p class="veyra-pda-empty">No actions are scheduled for this post.</p>';
        return;
    }

    echo '<ul class="veyra-pda-list">';
    foreach ($rows as $row) {
        $is_pending = ($row['status'] === 'pending');
        $when_local = get_date_from_gmt($row['scheduled_for_gmt'], 'M j, Y \a\t g:i a');
        $label      = isset($allowed[$row['action_value']])
            ? $allowed[$row['action_value']]
            : ('set status to ' . $row['action_value']);

        echo '<li class="veyra-pda-item veyra-pda-item--' . esc_attr($row['status']) . '">';
        echo '<div class="veyra-pda-action">' . esc_html($label) . '</div>';
        echo '<div class="veyra-pda-when">' . esc_html($when_local);
        if ($is_pending) {
            $diff = human_time_diff(time(), strtotime($row['scheduled_for_gmt'] . ' UTC'));
            echo ' <span class="veyra-pda-ago">(in ' . esc_html($diff) . ')</span>';
        }
        echo '</div>';

        echo '<div class="veyra-pda-state">'
            . '<span class="veyra-pda-badge veyra-pda-badge--' . esc_attr($row['status']) . '">'
            . esc_html(veyra_pda_state_label($row['status'])) . '</span>';
        if (!empty($row['executed_at_gmt'])) {
            echo ' <span class="veyra-pda-ran">ran '
                . esc_html(get_date_from_gmt($row['executed_at_gmt'], 'M j, g:i a')) . '</span>';
        }
        echo '</div>';

        if (!empty($row['notes'])) {
            echo '<div class="veyra-pda-note">' . esc_html($row['notes']) . '</div>';
        }
        if (!empty($row['result_note'])) {
            echo '<div class="veyra-pda-result">' . esc_html($row['result_note']) . '</div>';
        }

        echo '<div class="veyra-pda-row-actions">';
        if ($is_pending) {
            echo '<button type="button" class="button-link veyra-pda-run-now" data-id="'
                . esc_attr($row['id']) . '">Run now</button>';
            echo '<button type="button" class="button-link veyra-pda-cancel" data-id="'
                . esc_attr($row['id']) . '">Cancel</button>';
        }
        // Conduit: jump to this exact row in the Drip Actions Jar. An anchor
        // rather than a scripted button so middle-click / cmd-click behave.
        echo '<a class="veyra-conduit-btn" target="_blank" rel="noopener"'
            . ' title="Open row #' . intval($row['id']) . ' in the Drip Actions Jar"'
            . ' href="' . esc_url(add_query_arg(
                array('page' => 'veyra_drip_actions_jar', 'conduit_id' => intval($row['id'])),
                admin_url('admin.php')
            )) . '">conduit</a>';
        echo '</div>';
        echo '</li>';
    }
    echo '</ul>';
}

// ---------------------------------------------------------------------------
// AJAX
// ---------------------------------------------------------------------------

/** Shared guard: valid nonce, real post, user may edit it. */
function veyra_pda_ajax_guard() {
    check_ajax_referer('veyra_pda_ops', 'nonce');
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'You are not allowed to do that.'), 403);
    }
    return $post_id;
}

/** Every response carries freshly rendered list HTML so the box repaints. */
function veyra_pda_send_list($post_id, $message = '') {
    ob_start();
    veyra_pda_render_list($post_id);
    wp_send_json_success(array('html' => ob_get_clean(), 'message' => $message));
}

add_action('wp_ajax_veyra_pda_schedule', 'veyra_pda_ajax_schedule');
function veyra_pda_ajax_schedule() {
    $post_id = veyra_pda_ajax_guard();

    $action_value = isset($_POST['action_value']) ? sanitize_key(wp_unslash($_POST['action_value'])) : '';
    $when         = isset($_POST['when']) ? sanitize_text_field(wp_unslash($_POST['when'])) : '';
    $notes        = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';

    $result = veyra_pda_schedule_action($post_id, $action_value, $when, $notes);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 400);
    }
    veyra_pda_send_list($post_id, 'Action scheduled.');
}

add_action('wp_ajax_veyra_pda_cancel', 'veyra_pda_ajax_cancel');
function veyra_pda_ajax_cancel() {
    $post_id = veyra_pda_ajax_guard();
    $row_id  = isset($_POST['row_id']) ? intval($_POST['row_id']) : 0;

    $ok = veyra_pda_cancel_action($row_id);
    veyra_pda_send_list($post_id, $ok ? 'Action canceled.' : 'That action was no longer pending.');
}

add_action('wp_ajax_veyra_pda_run_now', 'veyra_pda_ajax_run_now');
function veyra_pda_ajax_run_now() {
    $post_id = veyra_pda_ajax_guard();
    $row_id  = isset($_POST['row_id']) ? intval($_POST['row_id']) : 0;

    // Log the human act of forcing the run BEFORE executing: the row must be
    // read while it still says 'pending', and the resulting status change gets
    // its own row immediately after. Only the manual path logs this — a due
    // action fired by cron has no one to attribute it to.
    veyra_pda_log_queue_event('drip_run_now', veyra_pda_get_row($row_id));

    veyra_pda_execute_action($row_id);
    veyra_pda_send_list($post_id, 'Action run. Reload the editor to see the post\'s new status.');
}

add_action('wp_ajax_veyra_pda_refresh', 'veyra_pda_ajax_refresh');
function veyra_pda_ajax_refresh() {
    $post_id = veyra_pda_ajax_guard();
    veyra_pda_send_list($post_id);
}

// ---------------------------------------------------------------------------
// The meta box
// ---------------------------------------------------------------------------
add_action('add_meta_boxes', 'veyra_pda_register_meta_box', 29);
function veyra_pda_register_meta_box() {
    $excluded = veyra_pda_excluded_post_types();
    foreach (get_post_types(array('show_ui' => true), 'names') as $post_type) {
        if (in_array($post_type, $excluded, true)) {
            continue;
        }
        add_meta_box(
            'veyra_post_drip_actions',
            'Veyra Post Drip Actions',
            'veyra_pda_render_meta_box',
            $post_type,
            'side',
            'low'
        );
    }
}

function veyra_pda_render_meta_box($post) {
    if (!current_user_can('edit_post', $post->ID)) {
        echo '<p>You do not have permission to schedule actions for this post.</p>';
        return;
    }

    // Default the picker to this time tomorrow, in the site's timezone.
    $default_when = wp_date('Y-m-d\TH:i', time() + DAY_IN_SECONDS);

    // wp_timezone_string() gives a bare offset ("+00:00") on sites configured by
    // UTC offset rather than by city — label it so it reads as a timezone.
    $tz_label = wp_timezone_string();
    if (preg_match('/^[+-]/', $tz_label)) {
        $tz_label = 'UTC' . $tz_label;
    }

    veyra_pda_print_styles();
    ?>
    <div class="veyra-pda-box" id="veyra-pda-box" data-post-id="<?php echo esc_attr($post->ID); ?>">

        <div class="veyra-pda-form">
            <label class="veyra-pda-label" for="veyra-pda-action">Action</label>
            <select id="veyra-pda-action" class="veyra-pda-input">
                <?php foreach (veyra_pda_allowed_statuses() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>

            <label class="veyra-pda-label" for="veyra-pda-when">
                Run at <span class="veyra-pda-tz">(<?php echo esc_html($tz_label); ?>)</span>
            </label>
            <input type="datetime-local" id="veyra-pda-when" class="veyra-pda-input"
                   value="<?php echo esc_attr($default_when); ?>">

            <div class="veyra-pda-presets">
                <button type="button" class="button-link veyra-pda-preset" data-days="1">+1 day</button>
                <button type="button" class="button-link veyra-pda-preset" data-days="7">+7 days</button>
                <button type="button" class="button-link veyra-pda-preset" data-days="30">+30 days</button>
                <button type="button" class="button-link veyra-pda-preset" data-days="90">+90 days</button>
            </div>

            <label class="veyra-pda-label" for="veyra-pda-notes">Note (optional)</label>
            <input type="text" id="veyra-pda-notes" class="veyra-pda-input" placeholder="why this is scheduled">

            <button type="button" class="button button-primary veyra-pda-submit" id="veyra-pda-schedule">
                Schedule Action
            </button>
            <div class="veyra-pda-msg" id="veyra-pda-msg"></div>
        </div>

        <div class="veyra-pda-listhead">
            <span>Scheduled &amp; past actions</span>
            <button type="button" class="button-link" id="veyra-pda-refresh">refresh</button>
        </div>
        <div id="veyra-pda-list-wrap">
            <?php veyra_pda_render_list($post->ID); ?>
        </div>
    </div>

    <script>
    (function () {
        var box = document.getElementById('veyra-pda-box');
        if (!box || box.dataset.veyraPdaBound === '1') { return; }
        box.dataset.veyraPdaBound = '1';

        var nonce  = <?php echo wp_json_encode(wp_create_nonce('veyra_pda_ops')); ?>;
        var ajax   = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var postId = box.getAttribute('data-post-id');
        var wrap   = document.getElementById('veyra-pda-list-wrap');
        var msg    = document.getElementById('veyra-pda-msg');
        var whenEl = document.getElementById('veyra-pda-when');

        function say(text, isError) {
            msg.textContent = text || '';
            msg.className = 'veyra-pda-msg' + (isError ? ' veyra-pda-msg--error' : '');
        }

        function post(action, extra, done) {
            var body = new FormData();
            body.append('action', action);
            body.append('nonce', nonce);
            body.append('post_id', postId);
            Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });

            fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.success) {
                        wrap.innerHTML = d.data.html;
                        say(d.data.message || '');
                        // Let the history box know it may have new rows to show.
                        document.dispatchEvent(new CustomEvent('veyra:history-changed'));
                    } else {
                        say((d && d.data && d.data.message) || 'Something went wrong.', true);
                    }
                })
                .catch(function () { say('Request failed.', true); })
                .then(function () { if (done) { done(); } });
        }

        // Format a Date as the datetime-local value the input expects.
        function toLocalValue(date) {
            function pad(n) { return (n < 10 ? '0' : '') + n; }
            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
                + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
        }

        box.addEventListener('click', function (e) {
            var t = e.target;

            if (t.classList.contains('veyra-pda-preset')) {
                e.preventDefault();
                var d = new Date();
                d.setDate(d.getDate() + parseInt(t.getAttribute('data-days'), 10));
                whenEl.value = toLocalValue(d);
                return;
            }

            if (t.id === 'veyra-pda-schedule') {
                e.preventDefault();
                t.disabled = true;
                say('Scheduling…');
                post('veyra_pda_schedule', {
                    action_value: document.getElementById('veyra-pda-action').value,
                    when:         whenEl.value,
                    notes:        document.getElementById('veyra-pda-notes').value
                }, function () { t.disabled = false; });
                return;
            }

            if (t.id === 'veyra-pda-refresh') {
                e.preventDefault();
                post('veyra_pda_refresh', {});
                return;
            }

            if (t.classList.contains('veyra-pda-cancel')) {
                e.preventDefault();
                say('Canceling…');
                post('veyra_pda_cancel', { row_id: t.getAttribute('data-id') });
                return;
            }

            if (t.classList.contains('veyra-pda-run-now')) {
                e.preventDefault();
                say('Running…');
                post('veyra_pda_run_now', { row_id: t.getAttribute('data-id') });
            }
        });
    })();
    </script>
    <?php
}

function veyra_pda_print_styles() {
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    ?>
    <style>
    .veyra-pda-form{margin-bottom:12px;}
    .veyra-pda-label{display:block;font-size:11px;font-weight:600;color:#1d2327;margin:8px 0 3px;}
    .veyra-pda-label:first-child{margin-top:0;}
    .veyra-pda-tz{font-weight:400;color:#8c8f94;}
    .veyra-pda-input{width:100%;box-sizing:border-box;}
    .veyra-pda-presets{margin:6px 0 2px;display:flex;gap:10px;flex-wrap:wrap;}
    .veyra-pda-presets .button-link{font-size:11px;}
    .veyra-pda-submit{width:100%;margin-top:10px !important;}
    .veyra-pda-msg{font-size:11px;color:#50575e;margin-top:6px;min-height:14px;line-height:1.4;}
    .veyra-pda-msg--error{color:#d63638;}
    .veyra-pda-listhead{display:flex;align-items:center;justify-content:space-between;gap:8px;
        font-size:11px;font-weight:600;color:#1d2327;text-transform:uppercase;letter-spacing:.03em;
        border-top:1px solid #dcdcde;padding-top:10px;margin-bottom:6px;}
    .veyra-pda-listhead .button-link{font-size:11px;font-weight:400;text-transform:none;letter-spacing:0;}
    .veyra-pda-empty{font-size:12px;color:#646970;margin:4px 0 0;}
    .veyra-pda-list{margin:0;padding:0;list-style:none;max-height:300px;overflow-y:auto;
        border:1px solid #dcdcde;border-radius:3px;background:#fff;}
    .veyra-pda-item{padding:8px 10px;border-bottom:1px solid #f0f0f1;font-size:12px;line-height:1.45;}
    .veyra-pda-item:last-child{border-bottom:none;}
    .veyra-pda-item--canceled,.veyra-pda-item--skipped{opacity:.6;}
    .veyra-pda-action{font-weight:600;color:#1d2327;}
    .veyra-pda-when{color:#50575e;}
    .veyra-pda-ago{color:#8c8f94;}
    .veyra-pda-state{margin-top:3px;}
    .veyra-pda-ran{font-size:11px;color:#8c8f94;}
    .veyra-pda-note{font-size:11px;color:#787c82;font-style:italic;margin-top:2px;}
    .veyra-pda-result{font-size:11px;color:#787c82;margin-top:2px;}
    .veyra-pda-badge{display:inline-block;padding:0 6px;border-radius:9px;font-size:10px;
        font-weight:600;line-height:16px;color:#fff;background:#8c8f94;text-transform:uppercase;}
    .veyra-pda-badge--pending{background:#dba617;}
    .veyra-pda-badge--running{background:#3582c4;}
    .veyra-pda-badge--done{background:#00a32a;}
    .veyra-pda-badge--failed{background:#d63638;}
    .veyra-pda-badge--skipped{background:#8c8f94;}
    .veyra-pda-badge--canceled{background:#a7aaad;}
    .veyra-pda-row-actions{margin-top:4px;display:flex;gap:10px;align-items:center;}
    .veyra-pda-row-actions .button-link{font-size:11px;}
    .veyra-pda-row-actions .veyra-pda-cancel{color:#d63638;}
    /* Conduit button — jumps to this row in the matching admin jar page.
       Also defined in the Post Actions History box; the rules are identical. */
    .veyra-conduit-btn{margin-left:auto;font-size:10px;line-height:1;text-decoration:none;
        color:#50575e;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:9px;
        padding:3px 8px;font-weight:600;letter-spacing:.02em;white-space:nowrap;}
    .veyra-conduit-btn:hover,.veyra-conduit-btn:focus{background:#2271b1;border-color:#2271b1;
        color:#fff;}
    </style>
    <?php
}
