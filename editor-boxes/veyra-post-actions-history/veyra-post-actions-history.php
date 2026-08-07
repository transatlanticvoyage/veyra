<?php
/**
 * Veyra — Post Actions History (editor sidebar box).
 *
 * A standalone audit log of every post_status change AND every post_date change
 * on every post/page, regardless of what caused it: the classic editor,
 * Gutenberg/REST, WP-Cron's scheduled-publish sweep, WP-CLI, XML-RPC, importers,
 * other plugins, or the Veyra Post Drip Actions runner. It is NOT tied to the
 * drip system — the drip runner simply shows up in here like any other cause.
 *
 * Two hooks feed it. transition_post_status records status movement;
 * post_updated (the only core hook given both the before and after post
 * objects) records date movement and backfills the before/after dates onto a
 * status row. One save produces exactly one row, however much it changed.
 *
 * Storage: a custom table, {$wpdb->prefix}veyra_post_status_log, one row per
 * event. (Postmeta was considered and rejected: a single serialized array per
 * post grows unbounded and read-modify-writes race under concurrent saves,
 * while one meta row per event pollutes postmeta and is copied onto revisions.)
 *
 * Known blind spot: a direct `UPDATE wp_posts SET post_status=...` (or
 * `SET post_date=...`) in raw SQL bypasses both hooks and therefore cannot be
 * recorded here. Everything routed through wp_insert_post()/wp_update_post()/
 * wp_publish_post()/wp_trash_post()/wp_untrash_post() is caught.
 *
 * Kept entirely in this file to avoid cluttering veyra.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Capture
//
// Schema note: the veyra_post_status_log table is declared in veyra.php
// (Veyra::veyra_editor_boxes_create_tables) so it is built by the plugin's
// activation hook — deactivate/reactivate Veyra on a live site and it appears.
// veyra_pah_table() is likewise defined there.
// ---------------------------------------------------------------------------

/** Post types whose status changes are noise, not history. Filterable. */
function veyra_pah_excluded_post_types() {
    return apply_filters('veyra_pah_excluded_post_types', array(
        'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'custom_css',
        'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part',
        'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
        'scheduled-action', 'action-scheduler-log',
    ));
}

/**
 * Work out what mechanism is driving the current request.
 *
 * The Veyra drip runner (and anything else that wants to claim credit) can set
 * $GLOBALS['veyra_pah_source_override'] to an array of source/detail/
 * drip_action_id for the duration of its own wp_update_post() call.
 */
function veyra_pah_detect_source() {
    if (!empty($GLOBALS['veyra_pah_source_override']) && is_array($GLOBALS['veyra_pah_source_override'])) {
        $o = $GLOBALS['veyra_pah_source_override'];
        return array(
            'source'         => isset($o['source']) ? (string) $o['source'] : 'unknown',
            'source_detail'  => isset($o['detail']) ? (string) $o['detail'] : '',
            'drip_action_id' => isset($o['drip_action_id']) ? intval($o['drip_action_id']) : null,
        );
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';

    if (defined('WP_CLI') && WP_CLI) {
        $source = 'wpcli';
        $uri    = '';
    } elseif (wp_doing_cron()) {
        $source = 'cron';
        $uri    = '';
    } elseif (defined('REST_REQUEST') && REST_REQUEST) {
        $source = 'rest';
    } elseif (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
        $source = 'xmlrpc';
    } elseif (wp_doing_ajax()) {
        $source = 'ajax';
        // "/wp-admin/admin-ajax.php" on its own says nothing — name the handler.
        if (!empty($_REQUEST['action'])) {
            $uri .= ' (' . sanitize_key(wp_unslash($_REQUEST['action'])) . ')';
        }
    } elseif (is_admin()) {
        $source = 'admin';
    } else {
        $source = 'unknown';
    }

    return array(
        'source'         => $source,
        'source_detail'  => mb_substr($uri, 0, 191, 'UTF-8'),
        'drip_action_id' => null,
    );
}

/** Normalize a WP date field for storage/comparison: WP's "floating date"
 *  placeholder ('0000-00-00 00:00:00', which post_date_gmt carries on drafts)
 *  and empty strings both become NULL. */
function veyra_pah_normalize_date($value) {
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return null;
    }
    return $value;
}

/** Insert one log row. Returns the new row id (also stashed in a global so the
 *  drip runner can link its action row to the event it produced). */
function veyra_pah_insert_event($args) {
    global $wpdb;

    $src  = veyra_pah_detect_source();
    $user = wp_get_current_user();

    $row = array(
        'post_id'              => intval($args['post_id']),
        'post_type'            => isset($args['post_type']) ? (string) $args['post_type'] : '',
        'event_type'           => isset($args['event_type']) ? (string) $args['event_type'] : 'status_change',
        'old_status'           => isset($args['old_status']) ? (string) $args['old_status'] : '',
        'new_status'           => isset($args['new_status']) ? (string) $args['new_status'] : '',
        'changed_at_gmt'       => current_time('mysql', 1),
        'post_date_before'     => veyra_pah_normalize_date(isset($args['post_date_before']) ? $args['post_date_before'] : ''),
        'post_date_after'      => veyra_pah_normalize_date(isset($args['post_date_after']) ? $args['post_date_after'] : ''),
        'post_date_gmt_before' => veyra_pah_normalize_date(isset($args['post_date_gmt_before']) ? $args['post_date_gmt_before'] : ''),
        'post_date_gmt_after'  => veyra_pah_normalize_date(isset($args['post_date_gmt_after']) ? $args['post_date_gmt_after'] : ''),
        'event_target_gmt'     => veyra_pah_normalize_date(isset($args['event_target_gmt']) ? $args['event_target_gmt'] : ''),
        'user_id'              => $user && $user->ID ? intval($user->ID) : null,
        'user_login'           => $user && $user->ID ? (string) $user->user_login : '',
        'source'               => $src['source'],
        'source_detail'        => $src['source_detail'],
        // An explicit id wins: queue events (drip_scheduled etc.) name the row
        // they describe directly, whereas a status change caused by the runner
        // gets it indirectly, from the source override the runner sets.
        'drip_action_id'       => isset($args['drip_action_id'])
            ? intval($args['drip_action_id'])
            : $src['drip_action_id'],
    );

    $wpdb->insert(veyra_pah_table(), $row);
    $insert_id = intval($wpdb->insert_id);
    $GLOBALS['veyra_pah_last_insert_id'] = $insert_id;

    return $insert_id;
}

/**
 * The workhorse: fires on every status transition WP knows about.
 *
 * Deliberate skips — auto-draft stubs WP creates just by opening "Add New",
 * and no-op saves where the status didn't actually move (transition_post_status
 * fires on every save, not just real transitions).
 */
add_action('transition_post_status', 'veyra_pah_log_transition', 10, 3);
function veyra_pah_log_transition($new_status, $old_status, $post) {
    if (!($post instanceof WP_Post)) {
        return;
    }
    if (in_array($post->post_type, veyra_pah_excluded_post_types(), true)) {
        return;
    }
    if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
        return;
    }
    if ($new_status === $old_status) {
        return; // plain save with no status movement
    }
    if ($new_status === 'auto-draft') {
        return; // empty stub created by clicking "Add New"
    }

    if ($old_status === 'new' || $old_status === 'auto-draft') {
        $event_type = 'created';
    } elseif ($new_status === 'trash') {
        $event_type = 'trashed';
    } elseif ($old_status === 'trash') {
        $event_type = 'untrashed';
    } else {
        $event_type = 'status_change';
    }

    // $post here is the post AFTER the write, so only the "after" dates are
    // knowable at this point. For updates to an existing post, post_updated
    // fires immediately after this and backfills the real "before" values via
    // the stash below. For the two paths where post_updated never fires —
    // wp_publish_post() (the cron scheduled-publish sweep) and a direct
    // wp_insert_post() of a brand-new post — neither of those alters post_date,
    // so before == after is the correct record.
    $row_id = veyra_pah_insert_event(array(
        'post_id'              => $post->ID,
        'post_type'            => $post->post_type,
        'event_type'           => $event_type,
        'old_status'           => $old_status,
        'new_status'           => $new_status,
        'post_date_before'     => $event_type === 'created' ? '' : $post->post_date,
        'post_date_after'      => $post->post_date,
        'post_date_gmt_before' => $event_type === 'created' ? '' : $post->post_date_gmt,
        'post_date_gmt_after'  => $post->post_date_gmt,
    ));

    // Hand this row off to veyra_pah_log_post_updated() so a single save that
    // moved both status and date produces one enriched row, not two.
    $GLOBALS['veyra_pah_pending_rows'][$post->ID] = array(
        'row_id'     => $row_id,
        'new_status' => $new_status,
        'is_created' => ($event_type === 'created'),
    );
}

/**
 * Catches post_date changes, and completes the picture on status changes.
 *
 * post_updated is the only core hook handed BOTH the before and after post
 * objects, which is what makes a before/after date comparison possible at all.
 * It fires on every update of an existing post, immediately after
 * transition_post_status, so it does one of three things:
 *
 *   1. a status row was just written for this post -> enrich it in place with
 *      the true before/after dates (no second row);
 *   2. the date moved but the status did not -> insert a 'date_change' row;
 *   3. neither moved -> do nothing (a plain content save logs nothing).
 *
 * Change detection runs off the LOCAL post_date, not post_date_gmt: WP leaves
 * post_date_gmt floating at '0000-00-00 00:00:00' on drafts, so a draft whose
 * date the user edited would otherwise look unchanged.
 */
add_action('post_updated', 'veyra_pah_log_post_updated', 10, 3);
function veyra_pah_log_post_updated($post_id, $post_after, $post_before) {
    if (!($post_after instanceof WP_Post) || !($post_before instanceof WP_Post)) {
        return;
    }
    if (in_array($post_after->post_type, veyra_pah_excluded_post_types(), true)) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if ($post_after->post_status === 'auto-draft') {
        return; // still an untouched stub
    }

    $date_before     = veyra_pah_normalize_date($post_before->post_date);
    $date_after      = veyra_pah_normalize_date($post_after->post_date);
    $gmt_before      = veyra_pah_normalize_date($post_before->post_date_gmt);
    $gmt_after       = veyra_pah_normalize_date($post_after->post_date_gmt);
    $date_changed    = ($date_before !== $date_after);

    // Case 1 — claim the row transition_post_status just wrote for this post.
    $pending = null;
    if (!empty($GLOBALS['veyra_pah_pending_rows'][$post_id])) {
        $candidate = $GLOBALS['veyra_pah_pending_rows'][$post_id];
        unset($GLOBALS['veyra_pah_pending_rows'][$post_id]);
        // Only claim it if it really describes this same write.
        if ($candidate['new_status'] === $post_after->post_status) {
            $pending = $candidate;
        }
    }

    if ($pending) {
        global $wpdb;
        // On creation there is no meaningful "before" date to report.
        $wpdb->update(veyra_pah_table(), array(
            'post_date_before'     => $pending['is_created'] ? null : $date_before,
            'post_date_after'      => $date_after,
            'post_date_gmt_before' => $pending['is_created'] ? null : $gmt_before,
            'post_date_gmt_after'  => $gmt_after,
        ), array('id' => intval($pending['row_id'])));
        return;
    }

    // Case 2 — the date moved on its own.
    if ($date_changed) {
        veyra_pah_insert_event(array(
            'post_id'              => $post_id,
            'post_type'            => $post_after->post_type,
            'event_type'           => 'date_change',
            'old_status'           => $post_before->post_status,
            'new_status'           => $post_after->post_status,
            'post_date_before'     => $date_before,
            'post_date_after'      => $date_after,
            'post_date_gmt_before' => $gmt_before,
            'post_date_gmt_after'  => $gmt_after,
        ));
    }

    // Case 3 — nothing we track moved; deliberately silent.
}

/** Permanent deletion never surfaces as a status transition — catch it here.
 *  The log row is intentionally left behind after the post is gone. */
add_action('before_delete_post', 'veyra_pah_log_delete', 10, 2);
function veyra_pah_log_delete($post_id, $post = null) {
    if (!($post instanceof WP_Post)) {
        $post = get_post($post_id);
    }
    if (!($post instanceof WP_Post)) {
        return;
    }
    if (in_array($post->post_type, veyra_pah_excluded_post_types(), true)) {
        return;
    }
    if (wp_is_post_revision($post->ID)) {
        return;
    }

    // Deletion doesn't move the date — record it identically on both sides so
    // no spurious date delta renders.
    veyra_pah_insert_event(array(
        'post_id'              => $post->ID,
        'post_type'            => $post->post_type,
        'event_type'           => 'deleted',
        'old_status'           => $post->post_status,
        'new_status'           => 'deleted',
        'post_date_before'     => $post->post_date,
        'post_date_after'      => $post->post_date,
        'post_date_gmt_before' => $post->post_date_gmt,
        'post_date_gmt_after'  => $post->post_date_gmt,
    ));
}

// ---------------------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------------------

/** Human label for a WP post status (falls back to the raw slug). */
function veyra_pah_status_label($status) {
    $map = array(
        'publish'    => 'published',
        'future'     => 'scheduled',
        'draft'      => 'draft',
        'pending'    => 'pending review',
        'private'    => 'private',
        'trash'      => 'trash',
        'auto-draft' => 'auto-draft',
        'inherit'    => 'inherit',
        'new'        => 'new',
        'deleted'    => 'deleted permanently',
    );
    return isset($map[$status]) ? $map[$status] : ($status !== '' ? $status : '—');
}

/** One-line summary of what happened, phrased for a human reading the sidebar.
 *  Returns HTML (it contains an arrow entity), so the status labels — which can
 *  be an arbitrary slug registered by another plugin — are escaped here. */
function veyra_pah_event_sentence($row) {
    $old = esc_html(veyra_pah_status_label($row['old_status']));
    $new = esc_html(veyra_pah_status_label($row['new_status']));

    switch ($row['event_type']) {
        case 'created':
            return 'created as ' . $new;
        case 'trashed':
            return 'moved to trash (from ' . $old . ')';
        case 'untrashed':
            return 'restored from trash to ' . $new;
        case 'deleted':
            return 'deleted permanently (was ' . $old . ')';
        case 'date_change':
            return 'post date changed <span class="veyra-pah-still">(still ' . $new . ')</span>';

        // Drip-queue events. $new holds the action's target status.
        case 'drip_scheduled':
            return 'drip action scheduled &mdash; set to ' . $new;
        case 'drip_canceled':
            return 'drip action canceled &mdash; would have set ' . $new;
        case 'drip_run_now':
            return 'drip action run manually &mdash; set to ' . $new;
        case 'drip_auto_canceled':
            return 'drip action voided &mdash; post was deleted';

        default:
            return $old . ' &rarr; ' . $new;
    }
}

/**
 * The "for <date>" line on a drip-queue event.
 *
 * Read straight from the frozen event_target_gmt snapshot — never from the drip
 * row — so this keeps saying what the action was aimed at even after the action
 * is canceled, rescheduled or run.
 */
function veyra_pah_event_target_html($row) {
    if (empty($row['event_target_gmt'])) {
        return '';
    }
    $when = get_date_from_gmt($row['event_target_gmt'], 'M j, Y g:i a');
    $verb = ($row['event_type'] === 'drip_run_now') ? 'was scheduled for' : 'for';
    return $verb . ' <strong>' . esc_html($when) . '</strong>';
}

/**
 * Present-tense context about the referenced drip action's CURRENT state.
 *
 * This is the one place the join is allowed to speak, and it is phrased and
 * styled as "since ..." so it can never be mistaken for what the row recorded
 * at the time. Only shown on the scheduling event — repeating it on the cancel
 * or run event would be noise.
 */
function veyra_pah_drip_now_html($row) {
    if ($row['event_type'] !== 'drip_scheduled' || empty($row['drip_now_status'])) {
        return '';
    }
    switch ($row['drip_now_status']) {
        case 'canceled':
            return 'since canceled';
        case 'done':
            return 'since run';
        case 'failed':
            return 'since failed';
        case 'skipped':
            return 'since skipped (no change needed)';
        case 'pending':
            return 'still pending';
        default:
            return '';
    }
}

/** Format a stored site-local post_date for display. These are already local
 *  wall time, so mysql2date is used WITHOUT a timezone conversion. */
function veyra_pah_format_post_date($local_datetime) {
    return mysql2date('M j, Y g:i a', $local_datetime, false);
}

/**
 * The "date: X -> Y" line, rendered only when the post_date actually moved.
 *
 * Returns HTML, or '' when there is nothing to say. Note that a draft carries a
 * floating date, so going draft -> published legitimately shows a date move the
 * user never typed: WP stamps the date to "now" at publish time.
 */
function veyra_pah_date_delta_html($row) {
    $before = isset($row['post_date_before']) ? $row['post_date_before'] : null;
    $after  = isset($row['post_date_after']) ? $row['post_date_after'] : null;

    if ($before === $after) {
        return '';
    }
    if (!$before && $after) {
        return 'date set to <strong>' . esc_html(veyra_pah_format_post_date($after)) . '</strong>';
    }
    if ($before && !$after) {
        return 'date cleared (was ' . esc_html(veyra_pah_format_post_date($before)) . ')';
    }
    return 'date: ' . esc_html(veyra_pah_format_post_date($before))
        . ' &rarr; <strong>' . esc_html(veyra_pah_format_post_date($after)) . '</strong>';
}

/** CSS modifier class keyed off the resulting status, for the colored chip. */
function veyra_pah_status_class($status) {
    $known = array('publish', 'future', 'draft', 'pending', 'private', 'trash', 'deleted');
    return in_array($status, $known, true) ? 'veyra-pah-chip--' . $status : 'veyra-pah-chip--other';
}

/**
 * Fetch the log for one post, newest first.
 *
 * The LEFT JOIN deliberately does NOT supply what the row says about itself —
 * event_target_gmt already holds a frozen snapshot of what a queue event aimed
 * at. It supplies the referenced action's CURRENT state, so the renderer can
 * append present-tense context ("since canceled") without rewriting history.
 */
function veyra_pah_get_events($post_id, $limit = 300) {
    global $wpdb;
    $log  = veyra_pah_table();
    $drip = function_exists('veyra_pda_table') ? veyra_pda_table() : $wpdb->prefix . 'veyra_post_drip_actions';

    return $wpdb->get_results($wpdb->prepare(
        "SELECT l.*,
                d.status              AS drip_now_status,
                d.notes               AS drip_notes,
                d.result_note         AS drip_result_note
           FROM {$log} l
           LEFT JOIN {$drip} d ON d.id = l.drip_action_id
          WHERE l.post_id = %d
          ORDER BY l.changed_at_gmt DESC, l.id DESC
          LIMIT %d",
        intval($post_id),
        intval($limit)
    ), ARRAY_A);
}

/** True when this event describes a change to the drip QUEUE rather than to the
 *  post itself. Used for the "show drip queue events" filter. */
function veyra_pah_is_queue_event($event_type) {
    return in_array($event_type, array(
        'drip_scheduled', 'drip_canceled', 'drip_run_now', 'drip_auto_canceled',
    ), true);
}

/** Render the event list markup (shared by the initial paint and the refresh). */
function veyra_pah_render_list($post_id) {
    $events = veyra_pah_get_events($post_id);

    if (!$events) {
        echo '<p class="veyra-pah-empty">No status or date changes have been recorded '
            . 'for this post yet. Logging began when this feature was installed — anything '
            . 'that happened before then is not shown.</p>';
        return;
    }

    echo '<ul class="veyra-pah-list">';
    foreach ($events as $row) {
        $when_local = get_date_from_gmt($row['changed_at_gmt'], 'M j, Y \a\t g:i a');
        $ago        = human_time_diff(strtotime($row['changed_at_gmt'] . ' UTC'), time()) . ' ago';

        $is_queue = veyra_pah_is_queue_event($row['event_type']);

        echo '<li class="veyra-pah-item' . ($is_queue ? ' veyra-pah-item--queue' : '') . '">';
        echo '<div class="veyra-pah-when">' . esc_html($when_local)
            . ' <span class="veyra-pah-ago">(' . esc_html($ago) . ')</span></div>';

        echo '<div class="veyra-pah-what">';
        $chip = $is_queue
            ? 'veyra-pah-chip--queue'
            : veyra_pah_status_class($row['new_status']);
        echo '<span class="veyra-pah-chip ' . esc_attr($chip) . '"></span>';
        echo wp_kses_post(veyra_pah_event_sentence($row));
        echo '</div>';

        if ($is_queue) {
            $target = veyra_pah_event_target_html($row);
            if ($target !== '') {
                echo '<div class="veyra-pah-date">' . wp_kses_post($target);
                if (!empty($row['drip_action_id'])) {
                    echo ' <span class="veyra-pah-ref">(drip #'
                        . intval($row['drip_action_id']) . ')</span>';
                }
                echo '</div>';
            }
            if (!empty($row['drip_notes'])) {
                echo '<div class="veyra-pah-note">&ldquo;'
                    . esc_html($row['drip_notes']) . '&rdquo;</div>';
            }
            $now = veyra_pah_drip_now_html($row);
            if ($now !== '') {
                echo '<div class="veyra-pah-now">' . esc_html($now) . '</div>';
            }
        }

        $date_delta = veyra_pah_date_delta_html($row);
        if ($date_delta !== '') {
            echo '<div class="veyra-pah-date">' . wp_kses_post($date_delta) . '</div>';
        } elseif ($row['new_status'] === 'future' && !empty($row['post_date_after'])) {
            // Scheduled without the date itself moving — still worth showing the
            // date it was aimed at, since that's the whole point of the status.
            echo '<div class="veyra-pah-date">scheduled for <strong>'
                . esc_html(veyra_pah_format_post_date($row['post_date_after'])) . '</strong></div>';
        }

        $who = $row['user_login'] !== '' ? $row['user_login'] : 'no user (system)';
        echo '<div class="veyra-pah-meta">'
            . '<span class="veyra-pah-who">' . esc_html($who) . '</span>'
            . ' &middot; <span class="veyra-pah-source">' . esc_html($row['source']) . '</span>';
        if (!empty($row['drip_action_id'])) {
            echo ' &middot; <span class="veyra-pah-drip">drip #' . intval($row['drip_action_id']) . '</span>';
        }
        echo '</div>';

        // Conduit: jump to this exact row in the Post Status Log Jar. An anchor
        // rather than a scripted button so middle-click / cmd-click behave.
        echo '<div class="veyra-pah-row-actions">';
        echo '<a class="veyra-conduit-btn" target="_blank" rel="noopener"'
            . ' title="Open row #' . intval($row['id']) . ' in the Post Status Log Jar"'
            . ' href="' . esc_url(add_query_arg(
                array('page' => 'post_status_log_jar', 'conduit_id' => intval($row['id'])),
                admin_url('admin.php')
            )) . '">conduit</a>';
        echo '</div>';
        echo '</li>';
    }
    echo '</ul>';
}

// ---------------------------------------------------------------------------
// AJAX: refresh the list in place
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_pah_refresh', 'veyra_pah_ajax_refresh');
function veyra_pah_ajax_refresh() {
    check_ajax_referer('veyra_pah_ops', 'nonce');
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error('unauthorized', 403);
    }
    ob_start();
    veyra_pah_render_list($post_id);
    // The current-status line is painted at page load, so a drip action run from
    // the box above would leave it stale — send the live value back with it.
    wp_send_json_success(array(
        'html'   => ob_get_clean(),
        'status' => veyra_pah_status_label(get_post_status($post_id)),
    ));
}

// ---------------------------------------------------------------------------
// The meta box
// ---------------------------------------------------------------------------
add_action('add_meta_boxes', 'veyra_pah_register_meta_box', 30);
function veyra_pah_register_meta_box() {
    $excluded = veyra_pah_excluded_post_types();
    foreach (get_post_types(array('show_ui' => true), 'names') as $post_type) {
        if (in_array($post_type, $excluded, true)) {
            continue;
        }
        add_meta_box(
            'veyra_post_actions_history',
            'Veyra Post Actions History',
            'veyra_pah_render_meta_box',
            $post_type,
            'side',
            'low'
        );
    }
}

function veyra_pah_render_meta_box($post) {
    if (!current_user_can('edit_post', $post->ID)) {
        echo '<p>You do not have permission to view this history.</p>';
        return;
    }
    veyra_pah_print_styles();
    ?>
    <div class="veyra-pah-box" id="veyra-pah-box" data-post-id="<?php echo esc_attr($post->ID); ?>">
        <div class="veyra-pah-toolbar">
            <span class="veyra-pah-current">current status:
                <strong id="veyra-pah-current-status"><?php
                    echo esc_html(veyra_pah_status_label($post->post_status));
                ?></strong></span>
            <button type="button" class="button button-small" id="veyra-pah-refresh">Refresh</button>
        </div>
        <label class="veyra-pah-toggle">
            <input type="checkbox" id="veyra-pah-show-queue" checked>
            show drip queue events
        </label>
        <div id="veyra-pah-list-wrap">
            <?php veyra_pah_render_list($post->ID); ?>
        </div>
    </div>
    <script>
    (function () {
        var box = document.getElementById('veyra-pah-box');
        if (!box || box.dataset.veyraPahBound === '1') { return; }
        box.dataset.veyraPahBound = '1';

        var nonce  = <?php echo wp_json_encode(wp_create_nonce('veyra_pah_ops')); ?>;
        var ajax   = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var postId = box.getAttribute('data-post-id');
        var btn    = document.getElementById('veyra-pah-refresh');
        var wrap   = document.getElementById('veyra-pah-list-wrap');

        function refresh() {
            btn.disabled = true;
            btn.textContent = 'Refreshing…';
            var body = new FormData();
            body.append('action', 'veyra_pah_refresh');
            body.append('nonce', nonce);
            body.append('post_id', postId);
            fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.success) {
                        wrap.innerHTML = d.data.html;
                        var cur = document.getElementById('veyra-pah-current-status');
                        if (cur && d.data.status) { cur.textContent = d.data.status; }
                    }
                })
                .catch(function () {})
                .then(function () {
                    btn.disabled = false;
                    btn.textContent = 'Refresh';
                });
        }

        btn.addEventListener('click', refresh);
        // The drip box fires this after scheduling/canceling/running an action.
        document.addEventListener('veyra:history-changed', refresh);

        // Queue-event filter. Purely a display filter — the rows are always
        // fetched and always in the table; this just hides them. Preference is
        // remembered per browser, so it survives reloads without a DB round-trip.
        var toggle = document.getElementById('veyra-pah-show-queue');
        var STORAGE_KEY = 'veyraPahShowQueueEvents';

        function applyFilter() {
            box.classList.toggle('veyra-pah-hide-queue', !toggle.checked);
        }

        try {
            if (window.localStorage.getItem(STORAGE_KEY) === '0') { toggle.checked = false; }
        } catch (e) { /* private mode — just use the default */ }
        applyFilter();

        toggle.addEventListener('change', function () {
            applyFilter();
            try { window.localStorage.setItem(STORAGE_KEY, toggle.checked ? '1' : '0'); }
            catch (e) { /* nothing to do */ }
        });
    })();
    </script>
    <?php
}

/** Styles for the history list, printed once per screen. */
function veyra_pah_print_styles() {
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    ?>
    <style>
    .veyra-pah-toolbar{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;}
    .veyra-pah-current{font-size:11px;color:#50575e;}
    .veyra-pah-empty{font-size:12px;color:#646970;margin:4px 0 0;line-height:1.5;}
    .veyra-pah-list{margin:0;padding:0;list-style:none;max-height:340px;overflow-y:auto;
        border:1px solid #dcdcde;border-radius:3px;background:#fff;}
    .veyra-pah-item{padding:8px 10px;border-bottom:1px solid #f0f0f1;font-size:12px;line-height:1.45;}
    .veyra-pah-item:last-child{border-bottom:none;}
    .veyra-pah-when{font-weight:600;color:#1d2327;}
    .veyra-pah-ago{font-weight:400;color:#8c8f94;}
    .veyra-pah-what{color:#1d2327;margin-top:2px;}
    .veyra-pah-still{color:#8c8f94;font-size:11px;}
    .veyra-pah-date{color:#3c434a;font-size:11px;margin-top:2px;padding-left:13px;
        border-left:2px solid #dcdcde;margin-left:2px;}
    .veyra-pah-meta{color:#787c82;font-size:11px;margin-top:1px;}
    .veyra-pah-chip{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;
        vertical-align:middle;background:#c3c4c7;}
    .veyra-pah-chip--publish{background:#00a32a;}
    .veyra-pah-chip--future{background:#dba617;}
    .veyra-pah-chip--draft{background:#8c8f94;}
    .veyra-pah-chip--pending{background:#996800;}
    .veyra-pah-chip--private{background:#3582c4;}
    .veyra-pah-chip--trash{background:#d63638;}
    .veyra-pah-chip--deleted{background:#8a1f21;}
    .veyra-pah-chip--queue{background:#7c3aed;}
    /* Drip-queue events: tinted + purple rule so they read as a different class
       of fact (something happened to the QUEUE, not to the post). */
    .veyra-pah-item--queue{background:#faf8ff;border-left:3px solid #7c3aed;}
    .veyra-pah-hide-queue .veyra-pah-item--queue{display:none;}
    .veyra-pah-toggle{display:block;font-size:11px;color:#50575e;margin:0 0 8px;cursor:pointer;}
    .veyra-pah-toggle input{margin:0 4px 0 0;vertical-align:middle;}
    .veyra-pah-ref{color:#8c8f94;font-weight:400;}
    .veyra-pah-note{font-size:11px;color:#787c82;font-style:italic;margin-top:2px;padding-left:13px;}
    /* Present-tense context pulled live from the drip row via the JOIN. Styled
       distinctly from the frozen snapshot above it so the two are never confused. */
    .veyra-pah-now{font-size:11px;color:#7c3aed;margin-top:2px;padding-left:13px;font-weight:600;}
    .veyra-pah-row-actions{margin-top:5px;display:flex;}
    /* Conduit button — jumps to this row in the matching admin jar page.
       Also defined in the Post Drip Actions box; the rules are identical. */
    .veyra-conduit-btn{margin-left:auto;font-size:10px;line-height:1;text-decoration:none;
        color:#50575e;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:9px;
        padding:3px 8px;font-weight:600;letter-spacing:.02em;white-space:nowrap;}
    .veyra-conduit-btn:hover,.veyra-conduit-btn:focus{background:#2271b1;border-color:#2271b1;
        color:#fff;}
    </style>
    <?php
}
