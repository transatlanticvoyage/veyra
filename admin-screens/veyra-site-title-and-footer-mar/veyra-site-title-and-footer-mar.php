<?php
/**
 * Veyra — Site Title and Footer Mar admin screen.
 *
 * Self-contained feature: registers an admin page at
 * /wp-admin/admin.php?page=veyra_site_title_and_footer_mar under the Veyra menu.
 *
 * Kept entirely in this file to avoid cluttering veyra.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Admin: menu, notice suppression, page render
// ---------------------------------------------------------------------------
add_action('admin_menu', 'veyra_stfm_register_menu', 20);
function veyra_stfm_register_menu() {
    add_submenu_page(
        'veyra-hub-1',                          // parent (Veyra Hub 1)
        'Veyra Site Title And Footer Mar',      // page title
        'Veyra Site Title And Footer Mar',      // menu label
        'manage_options',                       // capability
        'veyra_site_title_and_footer_mar',      // slug -> ?page=veyra_site_title_and_footer_mar
        'veyra_stfm_render_page'                // callback
    );
}

/** Aggressive notice/warning/message suppression on this screen only. */
add_action('in_admin_header', 'veyra_stfm_suppress_notices', 1);
function veyra_stfm_suppress_notices() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'veyra_site_title_and_footer_mar') {
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

// ---------------------------------------------------------------------------
// Save handler: WP native site title (blogname) + veyra_footer_text_corepoint
// (a single scalar wp_options value — one instance for the whole site, not
// keyed by post ID like the other veyra_* options).
// ---------------------------------------------------------------------------
add_action('admin_init', 'veyra_stfm_handle_save');
function veyra_stfm_handle_save() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'veyra_site_title_and_footer_mar') {
        return;
    }
    if (!isset($_POST['veyra_stfm_save'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('veyra_stfm_save', 'veyra_stfm_nonce');

    $site_title  = isset($_POST['veyra_stfm_site_title']) ? sanitize_text_field(wp_unslash($_POST['veyra_stfm_site_title'])) : '';
    $footer_text = isset($_POST['veyra_stfm_footer_text']) ? sanitize_text_field(wp_unslash($_POST['veyra_stfm_footer_text'])) : '';

    update_option('blogname', $site_title);
    update_option('veyra_footer_text_corepoint', $footer_text);

    $drip_fields = array(
        'veyra_stfm_site_title_drip_content' => 'veyra_wp_native_site_title_drip_to_content',
        'veyra_stfm_site_title_drip_enabled' => 'veyra_wp_native_site_title_drip_to_enabled',
        'veyra_stfm_site_title_drip_date'    => 'veyra_wp_native_site_title_drip_to_date',
        'veyra_stfm_footer_drip_content'     => 'veyra_footer_text_corepoint_drip_to_content',
        'veyra_stfm_footer_drip_enabled'     => 'veyra_footer_text_corepoint_drip_to_enabled',
        'veyra_stfm_footer_drip_date'        => 'veyra_footer_text_corepoint_drip_to_date',
        'veyra_stfm_site_title_cached'       => 'veyra_wp_native_site_title_cached',
        'veyra_stfm_footer_cached'           => 'veyra_footer_text_corepoint_cached',
        'veyra_stfm_site_title_drip_completed' => 'veyra_wp_native_site_title_drip_completed',
        'veyra_stfm_footer_drip_completed'     => 'veyra_footer_text_corepoint_drip_completed',
        'veyra_stfm_footer_enabled_for_theme'  => 'veyra_footer_text_corepoint_enabled_for_theme_logic',
    );
    foreach ($drip_fields as $post_key => $option_name) {
        $value = isset($_POST[$post_key]) ? sanitize_text_field(wp_unslash($_POST[$post_key])) : '';
        update_option($option_name, $value);
    }

    wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=veyra_site_title_and_footer_mar')));
    exit;
}

// ---------------------------------------------------------------------------
// WP-Cron: periodically check the two drip pairs for a due, enabled date and
// deploy their staged content, mirroring how WP's own post-scheduler wakes up
// to publish scheduled posts.
// ---------------------------------------------------------------------------
add_filter('cron_schedules', 'veyra_stfm_add_cron_interval');
function veyra_stfm_add_cron_interval($schedules) {
    $schedules['veyra_stfm_five_minutes'] = array(
        'interval' => 300,
        'display'  => 'Every 5 Minutes (Veyra Site Title and Footer Mar)',
    );
    return $schedules;
}

add_action('init', 'veyra_stfm_ensure_cron_scheduled');
function veyra_stfm_ensure_cron_scheduled() {
    if (!wp_next_scheduled('veyra_stfm_process_drips')) {
        wp_schedule_event(time(), 'veyra_stfm_five_minutes', 'veyra_stfm_process_drips');
    }
}

register_deactivation_hook(VEYRA_PLUGIN_PATH . 'veyra.php', 'veyra_stfm_clear_cron_schedule');
function veyra_stfm_clear_cron_schedule() {
    $timestamp = wp_next_scheduled('veyra_stfm_process_drips');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'veyra_stfm_process_drips');
    }
}

add_action('veyra_stfm_process_drips', 'veyra_stfm_process_due_drips');
function veyra_stfm_process_due_drips() {
    $now = time();

    // Pair 1: WP native site title.
    $enabled = get_option('veyra_wp_native_site_title_drip_to_enabled', '');
    $date    = get_option('veyra_wp_native_site_title_drip_to_date', '');
    $done    = get_option('veyra_wp_native_site_title_drip_completed', '');
    if ($enabled === '1' && is_numeric($date) && intval($date) > 0 && intval($date) <= $now && $done !== 'DONE') {
        $content = get_option('veyra_wp_native_site_title_drip_to_content', '');
        update_option('blogname', $content);
        update_option('veyra_wp_native_site_title_drip_completed', 'DONE');
    }

    // Pair 2: veyra_footer_text_corepoint.
    $enabled = get_option('veyra_footer_text_corepoint_drip_to_enabled', '');
    $date    = get_option('veyra_footer_text_corepoint_drip_to_date', '');
    $done    = get_option('veyra_footer_text_corepoint_drip_completed', '');
    if ($enabled === '1' && is_numeric($date) && intval($date) > 0 && intval($date) <= $now && $done !== 'DONE') {
        $content = get_option('veyra_footer_text_corepoint_drip_to_content', '');
        update_option('veyra_footer_text_corepoint', $content);
        update_option('veyra_footer_text_corepoint_enabled_for_theme_logic', '1');
        update_option('veyra_footer_text_corepoint_drip_completed', 'DONE');
    }
}

/** Human-readable rendering of a unix timestamp stored in a drip_to_date field. */
function veyra_stfm_friendly_date($timestamp) {
    if (!is_numeric($timestamp) || intval($timestamp) <= 0) {
        return '';
    }
    return date('M j, Y g:i:s A', intval($timestamp));
}

function veyra_stfm_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $site_title  = get_option('blogname', '');
    $footer_text = get_option('veyra_footer_text_corepoint', '');

    $site_title_drip_content   = get_option('veyra_wp_native_site_title_drip_to_content', '');
    $site_title_drip_enabled   = get_option('veyra_wp_native_site_title_drip_to_enabled', '');
    $site_title_drip_date      = get_option('veyra_wp_native_site_title_drip_to_date', '');
    $site_title_drip_completed = get_option('veyra_wp_native_site_title_drip_completed', '');
    $footer_drip_content       = get_option('veyra_footer_text_corepoint_drip_to_content', '');
    $footer_drip_enabled       = get_option('veyra_footer_text_corepoint_drip_to_enabled', '');
    $footer_drip_date          = get_option('veyra_footer_text_corepoint_drip_to_date', '');
    $footer_drip_completed     = get_option('veyra_footer_text_corepoint_drip_completed', '');

    $site_title_cached = get_option('veyra_wp_native_site_title_cached', '');
    $footer_cached      = get_option('veyra_footer_text_corepoint_cached', '');

    $footer_enabled_for_theme = get_option('veyra_footer_text_corepoint_enabled_for_theme_logic', '');
    ?>
    <div class="wrap veyra-stfm">
        <h1>Veyra Site Title And Footer Mar</h1>

        <?php if (isset($_GET['saved'])): ?>
            <p class="veyra-stfm-msg">Saved.</p>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('veyra_stfm_save', 'veyra_stfm_nonce'); ?>

            <p>
                <button type="submit" name="veyra_stfm_save" value="1" class="button button-primary">Save</button>
            </p>

            <table class="wp-list-table veyra-stfm-table">
                <thead>
                    <tr>
                        <th><strong>field-name</strong></th>
                        <th><strong>datum-house</strong></th>
                        <th><strong>adjunct</strong></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>wp native site title:</td>
                        <td><input type="text" name="veyra_stfm_site_title" value="<?php echo esc_attr($site_title); ?>"></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_footer_text_corepoint</td>
                        <td><input type="text" name="veyra_stfm_footer_text" value="<?php echo esc_attr($footer_text); ?>"></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_footer_text_corepoint_enabled_for_theme_logic</td>
                        <td><input type="text" id="veyra_stfm_footer_enabled_for_theme" name="veyra_stfm_footer_enabled_for_theme" value="<?php echo esc_attr($footer_enabled_for_theme); ?>"></td>
                        <td>
                            <label class="veyra-stfm-toggle">
                                <input type="checkbox" class="veyra-stfm-toggle-cb" data-target="veyra_stfm_footer_enabled_for_theme" <?php checked($footer_enabled_for_theme, '1'); ?>>
                                <span class="veyra-stfm-toggle-slider"></span>
                            </label>
                        </td>
                    </tr>
                    <tr class="veyra-stfm-separator-row">
                        <td colspan="3"></td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_wp_native_site_title_drip_to_content</td>
                        <td><input type="text" name="veyra_stfm_site_title_drip_content" value="<?php echo esc_attr($site_title_drip_content); ?>"></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_wp_native_site_title_drip_to_enabled</td>
                        <td><input type="text" id="veyra_stfm_site_title_drip_enabled" name="veyra_stfm_site_title_drip_enabled" value="<?php echo esc_attr($site_title_drip_enabled); ?>"></td>
                        <td>
                            <label class="veyra-stfm-toggle">
                                <input type="checkbox" class="veyra-stfm-toggle-cb" data-target="veyra_stfm_site_title_drip_enabled" <?php checked($site_title_drip_enabled, '1'); ?>>
                                <span class="veyra-stfm-toggle-slider"></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_wp_native_site_title_drip_to_date</td>
                        <td>
                            <input type="text" class="veyra-stfm-date-input" id="veyra_stfm_site_title_drip_date" name="veyra_stfm_site_title_drip_date" value="<?php echo esc_attr($site_title_drip_date); ?>">
                            <span class="veyra-stfm-date-friendly" id="veyra_stfm_site_title_drip_date_friendly"><?php echo esc_html(veyra_stfm_friendly_date($site_title_drip_date)); ?></span>
                        </td>
                        <td class="veyra-stfm-pill-cell" data-target="veyra_stfm_site_title_drip_date">
                            <span class="veyra-stfm-pill" data-days="2">2</span>
                            <span class="veyra-stfm-pill" data-days="4">4</span>
                            <span class="veyra-stfm-pill" data-days="8">8</span>
                            <span class="veyra-stfm-pill" data-days="20">20</span>
                            <span class="veyra-stfm-pill-label">(days from now)</span>
                            <input type="text" class="veyra-stfm-custom-days-input" placeholder="35">
                            <button type="button" class="button button-small veyra-stfm-custom-days-submit">submit</button>
                        </td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_wp_native_site_title_drip_completed</td>
                        <td><input type="text" name="veyra_stfm_site_title_drip_completed" value="<?php echo esc_attr($site_title_drip_completed); ?>"></td>
                        <td class="veyra-stfm-help-text">Set automatically to <code>DONE</code> once the drip above has fired, so the same content isn't re-copied into the site title on every cron check. Leave blank (or clear it) to let this drip fire again the next time its date arrives.</td>
                    </tr>
                    <tr class="veyra-stfm-separator-row">
                        <td colspan="3"></td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_footer_text_corepoint_drip_to_content</td>
                        <td><input type="text" name="veyra_stfm_footer_drip_content" value="<?php echo esc_attr($footer_drip_content); ?>"></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_footer_text_corepoint_drip_to_enabled</td>
                        <td><input type="text" id="veyra_stfm_footer_drip_enabled" name="veyra_stfm_footer_drip_enabled" value="<?php echo esc_attr($footer_drip_enabled); ?>"></td>
                        <td>
                            <label class="veyra-stfm-toggle">
                                <input type="checkbox" class="veyra-stfm-toggle-cb" data-target="veyra_stfm_footer_drip_enabled" <?php checked($footer_drip_enabled, '1'); ?>>
                                <span class="veyra-stfm-toggle-slider"></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_footer_text_corepoint_drip_to_date</td>
                        <td>
                            <input type="text" class="veyra-stfm-date-input" id="veyra_stfm_footer_drip_date" name="veyra_stfm_footer_drip_date" value="<?php echo esc_attr($footer_drip_date); ?>">
                            <span class="veyra-stfm-date-friendly" id="veyra_stfm_footer_drip_date_friendly"><?php echo esc_html(veyra_stfm_friendly_date($footer_drip_date)); ?></span>
                        </td>
                        <td class="veyra-stfm-pill-cell" data-target="veyra_stfm_footer_drip_date">
                            <span class="veyra-stfm-pill" data-days="2">2</span>
                            <span class="veyra-stfm-pill" data-days="4">4</span>
                            <span class="veyra-stfm-pill" data-days="8">8</span>
                            <span class="veyra-stfm-pill" data-days="20">20</span>
                            <span class="veyra-stfm-pill-label">(days from now)</span>
                            <input type="text" class="veyra-stfm-custom-days-input" placeholder="35">
                            <button type="button" class="button button-small veyra-stfm-custom-days-submit">submit</button>
                        </td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_footer_text_corepoint_drip_completed</td>
                        <td><input type="text" name="veyra_stfm_footer_drip_completed" value="<?php echo esc_attr($footer_drip_completed); ?>"></td>
                        <td class="veyra-stfm-help-text">Set automatically to <code>DONE</code> once the drip above has fired, so the same content isn't re-copied into <code>veyra_footer_text_corepoint</code> on every cron check. Leave blank (or clear it) to let this drip fire again the next time its date arrives.</td>
                    </tr>
                    <tr class="veyra-stfm-separator-row">
                        <td colspan="3"></td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_wp_native_site_title_cached</td>
                        <td><input type="text" name="veyra_stfm_site_title_cached" value="<?php echo esc_attr($site_title_cached); ?>"></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>wp_options: veyra_footer_text_corepoint_cached</td>
                        <td><input type="text" name="veyra_stfm_footer_cached" value="<?php echo esc_attr($footer_cached); ?>"></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </form>
    </div>
    <style>
        .veyra-stfm-msg {
            color: #0a7d28;
            font-weight: 600;
        }
        .veyra-stfm-table {
            width: auto;
            border-collapse: collapse;
        }
        .veyra-stfm-table th,
        .veyra-stfm-table td {
            border: 1px solid #ccc;
            padding: 8px 12px;
            vertical-align: middle;
        }
        .veyra-stfm-table th {
            background: #f0f0f1;
        }
        .veyra-stfm-table tbody tr td:first-child {
            font-weight: 700;
        }
        .veyra-stfm-table td input[type="text"] {
            width: 320px;
        }
        .veyra-stfm-table td input.veyra-stfm-date-input {
            width: 160px;
        }
        .veyra-stfm-date-friendly {
            margin-left: 8px;
            font-size: 12px;
            color: #646970;
            font-style: italic;
        }
        .veyra-stfm-table tr.veyra-stfm-separator-row td {
            height: 10px;
            padding: 0;
            border: none;
            background: #000;
        }
        .veyra-stfm-toggle {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
        }
        .veyra-stfm-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .veyra-stfm-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #c3c4c7;
            border-radius: 22px;
            transition: background 0.15s;
        }
        .veyra-stfm-toggle-slider::before {
            content: "";
            position: absolute;
            width: 16px;
            height: 16px;
            left: 3px;
            bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform 0.15s;
        }
        .veyra-stfm-toggle input:checked + .veyra-stfm-toggle-slider {
            background: #2271b1;
        }
        .veyra-stfm-toggle input:checked + .veyra-stfm-toggle-slider::before {
            transform: translateX(18px);
        }
        .veyra-stfm-pill-cell {
            white-space: nowrap;
        }
        .veyra-stfm-pill {
            display: inline-block;
            padding: 3px 10px;
            margin-right: 4px;
            border-radius: 12px;
            background: #f0f0f1;
            border: 1px solid #c3c4c7;
            font-size: 12px;
            cursor: pointer;
        }
        .veyra-stfm-pill:hover {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
        }
        .veyra-stfm-pill-label {
            font-size: 12px;
            color: #646970;
            margin-left: 4px;
        }
        .veyra-stfm-custom-days-input {
            width: 50px;
            margin-left: 8px;
            font-size: 12px;
            padding: 2px 4px;
        }
        .veyra-stfm-custom-days-input::placeholder {
            color: #a7aaad;
        }
        .veyra-stfm-custom-days-submit {
            margin-left: 4px;
        }
        .veyra-stfm-help-text {
            font-size: 12px;
            color: #646970;
            font-style: italic;
            max-width: 320px;
        }
        .veyra-stfm-help-text code {
            font-style: normal;
        }
    </style>
    <script>
    (function(){
        // Toggle switches: mirror checked state as "1"/"0" into the paired text input.
        document.querySelectorAll('.veyra-stfm-toggle-cb').forEach(function(cb){
            cb.addEventListener('change', function(){
                var target = document.getElementById(cb.getAttribute('data-target'));
                if (target) { target.value = cb.checked ? '1' : '0'; }
            });
        });

        // Date pills: "X days from now" — lands on that calendar day with a
        // randomized time of day (HH:MM:SS), written as a unix timestamp.
        var MONTH_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        function formatFriendly(date){
            var h = date.getHours();
            var ampm = h >= 12 ? 'PM' : 'AM';
            var h12 = h % 12; if (h12 === 0) { h12 = 12; }
            var mm = String(date.getMinutes()).padStart(2, '0');
            var ss = String(date.getSeconds()).padStart(2, '0');
            return MONTH_NAMES[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear()
                + ' ' + h12 + ':' + mm + ':' + ss + ' ' + ampm;
        }

        function applyDripDate(cell, days){
            var target = document.getElementById(cell.getAttribute('data-target'));
            if (!target) { return; }

            var now = new Date();
            var targetDay = new Date(now.getFullYear(), now.getMonth(), now.getDate() + days, 0, 0, 0, 0);
            var randomSecondsIntoDay = Math.floor(Math.random() * 86400);
            var landedDate = new Date(targetDay.getTime() + randomSecondsIntoDay * 1000);
            var timestamp = Math.floor(landedDate.getTime() / 1000);

            target.value = timestamp;

            var friendly = document.getElementById(target.id + '_friendly');
            if (friendly) { friendly.textContent = formatFriendly(landedDate); }
        }

        document.querySelectorAll('.veyra-stfm-pill').forEach(function(pill){
            pill.addEventListener('click', function(){
                var cell = pill.closest('.veyra-stfm-pill-cell');
                if (!cell) { return; }
                var days = parseInt(pill.getAttribute('data-days'), 10) || 0;
                applyDripDate(cell, days);
            });
        });

        // Custom "days from now" input + submit button — same calculator as the pills.
        document.querySelectorAll('.veyra-stfm-custom-days-submit').forEach(function(btn){
            btn.addEventListener('click', function(){
                var cell = btn.closest('.veyra-stfm-pill-cell');
                if (!cell) { return; }
                var input = cell.querySelector('.veyra-stfm-custom-days-input');
                if (!input) { return; }

                var raw = input.value.trim();
                if (raw === '' || isNaN(raw) || !/^-?\d+$/.test(raw)) {
                    alert('Please enter a whole number of days.');
                    return;
                }

                applyDripDate(cell, parseInt(raw, 10));
            });
        });
    })();
    </script>
    <?php
}
