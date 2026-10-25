<?php
/**
 * Veyra Plugin Manager — single-plugin admin screen.
 *
 * Mirrors the design of the aardvark "papluginsmar" page, but manages only the
 * one plugin (Veyra itself): shows its installed vs. remote version and the
 * same per-plugin action buttons (Update From Github, Activate, De Plus
 * Re-activate, Deactivate, Delete).
 *
 * Update from GitHub is intentionally blocked on local dev environments
 * (.local / localhost / 192.168.*), matching aardvark.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Veyra's own GitHub branch to pull updates from.
 */
if (!defined('VEYRA_GITHUB_BRANCH')) {
    define('VEYRA_GITHUB_BRANCH', 'main');
}

/**
 * Return the basics about the veyra plugin itself.
 *  - basename:   'veyra/veyra.php' (data-plugin value used by toggle/delete)
 *  - dir_name:   'veyra'           (folder to swap on update)
 *  - version:    installed version
 *  - github_url: from the Plugin URI header
 *  - branch:     VEYRA_GITHUB_BRANCH
 */
function veyra_plugin_manager_self_info() {
    $main_file = VEYRA_PLUGIN_PATH . 'veyra.php';

    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $data = get_plugin_data($main_file, false, false);

    $basename = plugin_basename($main_file);     // e.g. veyra/veyra.php
    $dir_name = dirname($basename);              // e.g. veyra

    return array(
        'main_file'  => 'veyra.php',
        'basename'   => $basename,
        'dir_name'   => $dir_name === '.' ? 'veyra' : $dir_name,
        'name'       => !empty($data['Name']) ? $data['Name'] : 'Veyra',
        'version'    => !empty($data['Version']) ? $data['Version'] : VEYRA_PLUGIN_VERSION,
        'description'=> !empty($data['Description']) ? $data['Description'] : '',
        'github_url' => !empty($data['PluginURI']) ? $data['PluginURI'] : 'https://github.com/transatlanticvoyage/veyra',
        'branch'     => VEYRA_GITHUB_BRANCH,
    );
}

/**
 * True if the current request host is a local/dev environment we refuse to
 * run GitHub updates on (matches aardvark's blocked list).
 */
function veyra_plugin_manager_is_blocked_host() {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $blocked = array('localhost', '127.0.0.1', '.local', '.test', '.dev', '192.168.');
    foreach ($blocked as $needle) {
        if (stripos($host, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/* ---------------------------------------------------------------------------
 * Menu registration
 * ------------------------------------------------------------------------- */

/**
 * Small colourful "update" glyph (gradient disc + white refresh arrows) shown
 * left of "Veyra Plugin Updater" in the sidebar submenu and admin-bar dropdown.
 */
function veyra_plugin_manager_icon_svg($size = 16) {
    $size = (int) $size;
    return '<svg class="veyra-pm-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" style="display:inline-block;vertical-align:-3px;margin-right:6px;flex:0 0 auto;">'
        . '<defs><linearGradient id="veyraPmIconG" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="#f59e0b"/><stop offset="0.5" stop-color="#ec4899"/><stop offset="1" stop-color="#6366f1"/>'
        . '</linearGradient></defs>'
        . '<circle cx="12" cy="12" r="12" fill="url(#veyraPmIconG)"/>'
        . '<path d="M17.2 9.2A5.6 5.6 0 0 0 7 8.4M6.8 14.8A5.6 5.6 0 0 0 17 15.6" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>'
        . '<path d="M17.8 5.6v3.9h-3.9zM6.2 18.4v-3.9h3.9z" fill="#fff"/>'
        . '</svg>';
}

add_action('admin_menu', 'veyra_plugin_manager_register_menu', 20);
function veyra_plugin_manager_register_menu() {
    add_submenu_page(
        'veyra-hub-1',                          // parent slug (Veyra Hub 1)
        'Veyra Plugin Updater',                 // page title
        veyra_plugin_manager_icon_svg() . 'Veyra Plugin Updater', // menu label
        'manage_options',                       // capability
        'veyra_plugin_manager',                 // page slug
        'veyra_plugin_manager_render_page'      // callback
    );
}

/**
 * Move "Veyra Plugin Updater" to the top of the Veyra Hub 1 submenu. Runs after
 * every other screen has registered its submenu item.
 */
add_action('admin_menu', 'veyra_plugin_manager_menu_first', 9999);
function veyra_plugin_manager_menu_first() {
    global $submenu;
    if (empty($submenu['veyra-hub-1'])) {
        return;
    }
    foreach ($submenu['veyra-hub-1'] as $i => $item) {
        if (isset($item[2]) && $item[2] === 'veyra_plugin_manager') {
            unset($submenu['veyra-hub-1'][$i]);
            array_unshift($submenu['veyra-hub-1'], $item);
            break;
        }
    }
}

/**
 * WP points a top-level menu link at its FIRST submenu item, which is now the
 * updater. Keep the "Veyra Hub 1" top-level link going to the hub page itself.
 */
add_action('admin_footer', 'veyra_plugin_manager_toplevel_href');
function veyra_plugin_manager_toplevel_href() {
    ?>
    <script>
    (function () {
        var a = document.querySelector('#toplevel_page_veyra-hub-1 > a');
        if (a) { a.href = <?php echo wp_json_encode(admin_url('admin.php?page=veyra-hub-1')); ?>; }
    })();
    </script>
    <?php
}

/* ---------------------------------------------------------------------------
 * AJAX handlers
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_veyra_toggle_plugin', 'veyra_plugin_manager_ajax_toggle');
add_action('wp_ajax_veyra_delete_plugin', 'veyra_plugin_manager_ajax_delete');
add_action('wp_ajax_veyra_update_from_github', 'veyra_plugin_manager_ajax_update_github');
add_action('wp_ajax_veyra_pm_check_update', 'veyra_plugin_manager_ajax_check_update');

/**
 * Is the GitHub branch different from what is installed here?
 *
 * The Version: header is not bumped on every commit, so comparing versions
 * misses most updates. Instead this reads the branch's file tree (one GitHub
 * API call) and compares each file's git blob SHA with a SHA computed from the
 * local copy. Any changed or missing file = update available. Local-only files
 * are ignored. Falls back to the Version: header if the API call fails.
 *
 * Returns array(
 *   'state'          => 'available' | 'current' | 'unknown',
 *   'changed'        => int files that differ (tree check only),
 *   'remote_version' => string|null,
 *   'local_version'  => string,
 *   'method'         => 'tree' | 'version' | 'none',
 *   'error'          => string (only when state is unknown),
 * )
 * Cached for 10 minutes; $force skips the cache.
 */
function veyra_plugin_manager_update_status($force = false) {
    $cache_key = 'veyra_pm_update_status';
    if (!$force) {
        $cached = get_site_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $info = veyra_plugin_manager_self_info();
    require_once VEYRA_PLUGIN_PATH . 'includes/class-veyra-github-client.php';
    $repo_info = Veyra_GitHub_Client::parse_github_url($info['github_url']);

    $status = array(
        'state'          => 'unknown',
        'changed'        => 0,
        'remote_version' => null,
        'local_version'  => $info['version'],
        'method'         => 'none',
    );
    if (!$repo_info) {
        $status['error'] = 'Could not parse GitHub URL: ' . $info['github_url'];
        return $status;
    }

    $client = new Veyra_GitHub_Client();
    $rv = $client->get_remote_version($repo_info['owner'], $repo_info['repo'], $info['branch'], $info['main_file']);
    if (!is_array($rv)) {
        $status['remote_version'] = $rv;
    }

    $tree = $client->get_tree_blobs($repo_info['owner'], $repo_info['repo'], $info['branch']);
    if (!isset($tree['error'])) {
        $changed = 0;
        foreach ($tree as $path => $sha) {
            $local = VEYRA_PLUGIN_PATH . $path;
            if (!is_file($local) || !is_readable($local)) {
                $changed++;
                continue;
            }
            $body = file_get_contents($local);
            if ($body === false || sha1('blob ' . strlen($body) . "\0" . $body) !== $sha) {
                $changed++;
            }
        }
        $status['method']  = 'tree';
        $status['changed'] = $changed;
        $status['state']   = $changed > 0 ? 'available' : 'current';
    } elseif ($status['remote_version'] !== null) {
        $status['method'] = 'version';
        $status['state']  = version_compare($status['remote_version'], $info['version'], '>') ? 'available' : 'current';
    } else {
        $status['error'] = $tree['error'];
    }

    // Don't cache failures, so the next page load retries.
    if ($status['state'] !== 'unknown') {
        set_site_transient($cache_key, $status, 10 * MINUTE_IN_SECONDS);
    }
    return $status;
}

function veyra_plugin_manager_ajax_check_update() {
    check_ajax_referer('veyra_plugin_update', 'nonce');
    if (!current_user_can('update_plugins')) {
        wp_send_json_error('Insufficient permissions');
    }
    $force = !empty($_POST['force']);
    wp_send_json_success(veyra_plugin_manager_update_status($force));
}

function veyra_plugin_manager_ajax_toggle() {
    check_ajax_referer('veyra_plugin_action', 'nonce');

    if (!current_user_can('activate_plugins')) {
        wp_die('Insufficient permissions');
    }

    $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
    $action = isset($_POST['toggle_action']) ? sanitize_text_field(wp_unslash($_POST['toggle_action'])) : '';

    if ($action === 'activate') {
        $result = activate_plugin($plugin);
    } else {
        $result = deactivate_plugins($plugin);
    }

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success('Plugin ' . $action . 'd successfully');
    }
}

function veyra_plugin_manager_ajax_delete() {
    check_ajax_referer('veyra_plugin_delete', 'nonce');

    if (!current_user_can('delete_plugins')) {
        wp_die('Insufficient permissions');
    }

    $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';

    if (!function_exists('delete_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $result = delete_plugins(array($plugin));

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success('Plugin deleted successfully');
    }
}

function veyra_plugin_manager_ajax_update_github() {
    check_ajax_referer('veyra_plugin_update', 'nonce');

    if (!current_user_can('update_plugins')) {
        wp_die('Insufficient permissions');
    }

    // Primary defense: block local/dev environments.
    if (veyra_plugin_manager_is_blocked_host()) {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '(unknown)';
        wp_send_json_error('GitHub update is disabled on local development environments for safety. Current domain: ' . $host);
    }

    $info = veyra_plugin_manager_self_info();

    require_once VEYRA_PLUGIN_PATH . 'includes/class-veyra-plugin-installer.php';

    $installer = new Veyra_Plugin_Installer();
    $result = $installer->update_from_github(
        $info['github_url'],
        $info['branch'],
        $info['dir_name']
    );

    if (isset($result['error'])) {
        wp_send_json_error($result['error']);
    }

    if (function_exists('wp_cache_delete')) {
        wp_cache_delete('plugins', 'plugins');
    }
    delete_site_transient('update_plugins');
    delete_site_transient('veyra_pm_update_status');

    // Re-read the plugin header straight off disk. The folder has already been
    // swapped, so this reports the version that is now installed, letting the
    // page refresh its own numbers without a reload.
    $new_info = veyra_plugin_manager_self_info();

    wp_send_json_success(array(
        'message' => $result['message'],
        'version' => $new_info['version'],
    ));
}

/* ---------------------------------------------------------------------------
 * Page render
 * ------------------------------------------------------------------------- */

function veyra_plugin_manager_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    $info = veyra_plugin_manager_self_info();

    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $is_active = is_plugin_active($info['basename']);

    // Fetch the remote version from veyra.php on the branch.
    require_once VEYRA_PLUGIN_PATH . 'includes/class-veyra-github-client.php';
    $repo_info      = Veyra_GitHub_Client::parse_github_url($info['github_url']);
    $remote_version = null;
    $remote_error   = null;
    if ($repo_info) {
        $client = new Veyra_GitHub_Client();
        $rv = $client->get_remote_version($repo_info['owner'], $repo_info['repo'], $info['branch'], $info['main_file']);
        if (is_array($rv) && isset($rv['error'])) {
            $remote_error = $rv['error'];
        } else {
            $remote_version = $rv;
        }
    }

    // Same commit-level check the Posts-list button uses; version compare only
    // if that check could not run.
    $update_status = veyra_plugin_manager_update_status();
    if ($update_status['state'] !== 'unknown') {
        $needs_update = ($update_status['state'] === 'available');
        $remote_error = null;
    } else {
        $needs_update = ($remote_version && version_compare($remote_version, $info['version'], '>'));
    }
    $host_blocked = veyra_plugin_manager_is_blocked_host();
    ?>
    <div class="wrap">

        <h1 style="display: flex; align-items: center; gap: 14px; margin: 0 0 8px 0;">
            <span class="dashicons dashicons-sun" style="font-size: 30px; width: 30px; height: 30px; color: #d97706;"></span>
            Veyra Plugin Updater
        </h1>
        <p style="color: #666; margin: 0 0 20px 0;">Manage the Veyra plugin and update it from GitHub.</p>

        <!-- Persistent result banner for "Update From Github". Stays on screen
             until dismissed or the page is navigated away from. -->
        <div id="veyra-pm-banner" style="display: none; position: relative; margin: 0 0 16px 0; padding: 12px 42px 12px 14px; border: 1px solid transparent; border-left-width: 4px; border-radius: 4px; font-size: 14px; line-height: 1.5;">
            <strong id="veyra-pm-banner-title"></strong>
            <span id="veyra-pm-banner-text"></span>
            <button type="button" id="veyra-pm-banner-dismiss" aria-label="Dismiss this notice"
                    style="position: absolute; top: 8px; right: 10px; padding: 0; background: none; border: 0; color: inherit; opacity: 0.55; font-size: 18px; line-height: 1; cursor: pointer;">&times;</button>
        </div>

        <?php if ($host_blocked): ?>
            <div class="notice notice-warning inline" style="margin: 0 0 16px 0;">
                <p><strong>Local environment detected.</strong> "Update From Github" is disabled here for safety. It will run on live (non-local) sites only.</p>
            </div>
        <?php endif; ?>

        <table style="width: 100%; border-collapse: collapse; background: #fff;">
            <thead>
                <tr style="background: #1d2327; color: #fff;">
                    <th style="border: 1px solid #555; padding: 8px; text-align: left;">Plugin</th>
                    <th style="border: 1px solid #555; padding: 8px; text-align: left;">Version</th>
                    <th style="border: 1px solid #555; padding: 8px; text-align: left;">Status</th>
                    <th style="border: 1px solid #555; padding: 8px; text-align: left;">Update Available</th>
                    <th style="border: 1px solid #555; padding: 8px; text-align: left;">Actions</th>
                    <th style="border: 1px solid #555; padding: 8px; text-align: left; border-left: 3px solid #000;">GitHub Repo</th>
                    <th style="border: 1px solid #555; padding: 8px; text-align: left;">Branch</th>
                    <th style="border: 1px solid #555; padding: 8px; text-align: left;">Remote / Local</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="border: 1px solid #555; padding: 8px;">
                        <strong><?php echo esc_html($info['name']); ?></strong>
                    </td>
                    <td style="border: 1px solid #555; padding: 8px;">
                        <span class="veyra-pm-local-version"><?php echo esc_html($info['version']); ?></span>
                    </td>
                    <td style="border: 1px solid #555; padding: 8px;">
                        <span class="status-badge <?php echo $is_active ? 'active' : 'inactive'; ?>">
                            <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td style="border: 1px solid #555; padding: 8px;" id="veyra-pm-update-available">
                        <?php
                        if ($remote_error) {
                            echo '<span style="color:#888;" title="' . esc_attr($remote_error) . '">unknown</span>';
                        } else {
                            echo $needs_update
                                ? '<span style="color: #d63638;">Yes</span>'
                                : '<span style="color: #00a32a;">No</span>';
                        }
                        ?>
                    </td>
                    <td style="border: 1px solid #555; padding: 8px;">
                        <div style="display: inline-flex; border-radius: 6px; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);">
                            <!-- Update From Github -->
                            <?php $upd_disabled = $host_blocked; ?>
                            <button class="plugin-action-btn" data-plugin="<?php echo esc_attr($info['basename']); ?>" data-action="update-github"
                                    style="padding: 10px 8px; font-size: 14px; border: 1px solid #D1D5DB; border-radius: 6px 0 0 6px; margin-right: -1px; cursor: pointer; <?php echo $upd_disabled ? 'background: #f0f0f0; color: #888; cursor: not-allowed;' : 'background: #2271b1; color: white;'; ?>"
                                    <?php echo $upd_disabled ? 'disabled title="Disabled on local environments"' : ''; ?>>
                                Update From Github
                            </button>

                            <!-- Activate -->
                            <button class="plugin-action-btn" data-plugin="<?php echo esc_attr($info['basename']); ?>" data-action="activate"
                                    style="padding: 10px 8px; font-size: 14px; border: 1px solid #D1D5DB; margin-right: -1px; cursor: pointer; <?php echo $is_active ? 'background: #f0f0f0; color: #888; cursor: not-allowed;' : 'background: #00a32a; color: white;'; ?>"
                                    <?php echo $is_active ? 'disabled' : ''; ?>>
                                Activate
                            </button>

                            <!-- De Plus Re-activate -->
                            <button class="plugin-action-btn de-plus-reactivate-btn" data-plugin="<?php echo esc_attr($info['basename']); ?>" data-action="de-plus-reactivate"
                                    style="padding: 10px 8px; font-size: 14px; border: 1px solid #D1D5DB; margin-right: -1px; cursor: pointer; background: #2563EB; color: white;"
                                    <?php echo !$is_active ? 'disabled' : ''; ?>>
                                <span class="btn-text">de plus re-activate</span>
                                <div class="spinner" style="display: none;"></div>
                            </button>

                            <!-- Deactivate -->
                            <button class="plugin-action-btn" data-plugin="<?php echo esc_attr($info['basename']); ?>" data-action="deactivate"
                                    style="padding: 10px 8px; font-size: 14px; border: 1px solid #D1D5DB; margin-right: -1px; cursor: pointer; <?php echo !$is_active ? 'background: #f0f0f0; color: #888; cursor: not-allowed;' : 'background: #d63638; color: white;'; ?>"
                                    <?php echo !$is_active ? 'disabled' : ''; ?>>
                                Deactivate
                            </button>

                            <!-- Delete -->
                            <button class="plugin-action-btn" data-plugin="<?php echo esc_attr($info['basename']); ?>" data-action="delete"
                                    style="padding: 10px 8px; font-size: 14px; border: 1px solid #D1D5DB; border-radius: 0 6px 6px 0; cursor: pointer; background: #b32d2e; color: white;">
                                Delete
                            </button>
                        </div>
                    </td>
                    <td style="border: 1px solid #555; padding: 8px; border-left: 3px solid #000;">
                        <a href="<?php echo esc_url($info['github_url']); ?>" target="_blank" style="color: #2271b1; text-decoration: none; font-size: 12px;">
                            <?php echo esc_html(ltrim((string) parse_url($info['github_url'], PHP_URL_PATH), '/')); ?>
                        </a>
                    </td>
                    <td style="border: 1px solid #555; padding: 8px;">
                        <span style="background: #f0f6fc; color: #0969da; padding: 2px 6px; border-radius: 3px; font-size: 12px; font-family: monospace;">
                            <?php echo esc_html($info['branch']); ?>
                        </span>
                    </td>
                    <td style="border: 1px solid #555; padding: 8px;">
                        <?php if ($remote_version): ?>
                            <span style="font-size: 12px;">
                                Remote: <strong><?php echo esc_html($remote_version); ?></strong><br>
                                Local: <strong class="veyra-pm-local-version"><?php echo esc_html($info['version']); ?></strong>
                            </span>
                        <?php else: ?>
                            <span style="font-size: 12px; color: #888;">
                                Local: <strong class="veyra-pm-local-version"><?php echo esc_html($info['version']); ?></strong><br>
                                <em>remote unavailable</em>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 24px; padding: 16px; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; max-width: 640px;">
            <h2 style="margin: 0 0 6px 0; font-size: 14px;">Sitemap Shortcode</h2>
            <p style="color: #666; margin: 0 0 10px 0; font-size: 13px;">
                Drop this shortcode into any page or post to render a linebreak-separated list of links to every published page on the site (posts are not included), with a "Home" link listed first.
            </p>
            <div style="display: flex; gap: 8px; align-items: center;">
                <input type="text" readonly id="veyra-sitemap-shortcode-field"
                       value="[veyra_sitemap_render_all_published_pages]"
                       onclick="this.select();"
                       style="flex: 1; font-family: monospace; font-size: 13px; padding: 8px; border: 1px solid #8c8f94; border-radius: 4px; background: #f6f7f7;">
                <button type="button" id="veyra-sitemap-shortcode-copy"
                        style="padding: 8px 12px; font-size: 13px; border: 1px solid #2271b1; border-radius: 4px; background: #2271b1; color: #fff; cursor: pointer;">
                    Copy
                </button>
            </div>
        </div>

        <style>
            .status-badge { padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; }
            .status-badge.active { background: #d1e7dd; color: #0f5132; }
            .status-badge.inactive { background: #f8d7da; color: #842029; }
            @keyframes veyra-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            .de-plus-reactivate-btn .spinner {
                width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid #fff;
                border-radius: 50%; animation: veyra-spin 1s linear infinite; margin: 0 auto;
            }
        </style>
    </div>

    <script>
    jQuery(document).ready(function($) {
        const VEYRA_BANNER_COLORS = {
            success: { bg: '#edfaef', border: '#00a32a', text: '#0f5132' },
            error:   { bg: '#fcf0f1', border: '#d63638', text: '#842029' }
        };

        // Persistent inline banner used instead of alert() for update results.
        function veyraShowBanner(type, title, text) {
            const c = VEYRA_BANNER_COLORS[type] || VEYRA_BANNER_COLORS.success;
            $('#veyra-pm-banner-title').text(title ? title + ' ' : '');
            $('#veyra-pm-banner-text').text(text || '');
            $('#veyra-pm-banner').css({
                'background': c.bg,
                'border-color': c.border,
                'color': c.text
            }).show();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        $('#veyra-pm-banner-dismiss').on('click', function() {
            $('#veyra-pm-banner').hide();
        });

        $('#veyra-sitemap-shortcode-copy').on('click', function() {
            const $field = $('#veyra-sitemap-shortcode-field');
            const $btn = $(this);
            $field.get(0).select();
            navigator.clipboard.writeText($field.val()).then(function() {
                const original = $btn.text();
                $btn.text('Copied!');
                setTimeout(function() { $btn.text(original); }, 1500);
            }).catch(function() {
                document.execCommand('copy');
            });
        });

        $('.plugin-action-btn').on('click', function() {
            const $button = $(this);
            const plugin = $button.data('plugin');
            const action = $button.data('action');

            if ($button.prop('disabled')) {
                return;
            }

            if (action === 'delete') {
                if (!confirm('Are you sure you want to delete the Veyra plugin? This will remove it entirely and cannot be undone.')) {
                    return;
                }
            }

            if (action === 'activate' || action === 'deactivate') {
                $.post(ajaxurl, {
                    action: 'veyra_toggle_plugin',
                    plugin: plugin,
                    toggle_action: action,
                    nonce: '<?php echo esc_js(wp_create_nonce('veyra_plugin_action')); ?>'
                }).done(function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            } else if (action === 'de-plus-reactivate') {
                $button.find('.btn-text').text('Deactivating...').show();
                $button.find('.spinner').hide();
                $button.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'veyra_toggle_plugin',
                    plugin: plugin,
                    toggle_action: 'deactivate',
                    nonce: '<?php echo esc_js(wp_create_nonce('veyra_plugin_action')); ?>'
                }).done(function(response) {
                    if (response.success) {
                        setTimeout(function() {
                            $button.find('.btn-text').text('Reactivating...').show();
                            $.post(ajaxurl, {
                                action: 'veyra_toggle_plugin',
                                plugin: plugin,
                                toggle_action: 'activate',
                                nonce: '<?php echo esc_js(wp_create_nonce('veyra_plugin_action')); ?>'
                            }).done(function(response) {
                                if (response.success) {
                                    $button.find('.btn-text').text('Successful!').show();
                                    $button.css('background', '#00a32a');
                                    setTimeout(function() { location.reload(); }, 1000);
                                } else {
                                    alert('Error reactivating: ' + response.data);
                                    $button.find('.btn-text').text('de plus re-activate').show();
                                    $button.prop('disabled', false).css('background', '#2563EB');
                                }
                            }).fail(function() {
                                alert('Failed to reactivate plugin');
                                $button.find('.btn-text').text('de plus re-activate').show();
                                $button.prop('disabled', false).css('background', '#2563EB');
                            });
                        }, 1000);
                    } else {
                        alert('Error deactivating: ' + response.data);
                        $button.find('.btn-text').text('de plus re-activate').show();
                        $button.prop('disabled', false).css('background', '#2563EB');
                    }
                }).fail(function() {
                    alert('Failed to deactivate plugin');
                    $button.find('.btn-text').text('de plus re-activate').show();
                    $button.prop('disabled', false).css('background', '#2563EB');
                });
            } else if (action === 'delete') {
                $.post(ajaxurl, {
                    action: 'veyra_delete_plugin',
                    plugin: plugin,
                    nonce: '<?php echo esc_js(wp_create_nonce('veyra_plugin_delete')); ?>'
                }).done(function(response) {
                    if (response.success) {
                        alert('Veyra plugin deleted.');
                        window.location.href = '<?php echo esc_js(admin_url('plugins.php')); ?>';
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            } else if (action === 'update-github') {
                // No confirmation prompt — the click runs the update immediately.
                $('#veyra-pm-banner').hide();
                $button.prop('disabled', true).text('Updating...');
                $.post(ajaxurl, {
                    action: 'veyra_update_from_github',
                    plugin: plugin,
                    nonce: '<?php echo esc_js(wp_create_nonce('veyra_plugin_update')); ?>'
                }).done(function(response) {
                    if (response.success) {
                        const data = response.data || {};
                        let text = data.message || 'Plugin updated successfully from GitHub.';
                        if (data.version) {
                            text += ' Now running version ' + data.version + '.';
                            // Refresh the on-page numbers so no reload is needed.
                            $('.veyra-pm-local-version').text(data.version);
                            $('#veyra-pm-update-available').html('<span style="color: #00a32a;">No</span>');
                        }
                        veyraShowBanner('success', 'Success:', text);
                    } else {
                        const msg = (typeof response.data === 'string' && response.data)
                            ? response.data
                            : 'The server rejected the update but gave no reason.';
                        veyraShowBanner('error', 'Update failed:', msg);
                    }
                    $button.prop('disabled', false).text('Update From Github');
                }).fail(function(xhr) {
                    veyraShowBanner('error', 'Update failed:', 'The request did not complete (HTTP ' + (xhr && xhr.status ? xhr.status : 0) + '). The plugin may have been left partially updated — check the version above before retrying.');
                    $button.prop('disabled', false).text('Update From Github');
                });
            }
        });
    });
    </script>
    <?php
}

/* ---------------------------------------------------------------------------
 * "Update From GitHub" button on the native list screens (edit.php), placed
 * right of "Add New Post". Status is fetched by AJAX after the page loads so
 * GitHub latency never slows the list down; the click runs the same
 * veyra_update_from_github action as the Veyra Plugin Updater screen.
 * ------------------------------------------------------------------------- */

add_action('admin_footer-edit.php', 'veyra_plugin_manager_list_button');
function veyra_plugin_manager_list_button() {
    if (!current_user_can('update_plugins')) {
        return;
    }
    ?>
    <style id="veyra-pm-list-css">
        .veyra-pm-gh{display:inline-flex;align-items:center;gap:7px;margin-left:4px;position:relative;top:-3px;padding:4px 10px;
            border:1px solid #1f2328;border-radius:3px;background:#24292f;color:#fff;font-size:13px;font-weight:600;line-height:1.6;cursor:pointer;vertical-align:top;}
        .veyra-pm-gh:hover{background:#32383f;}
        .veyra-pm-gh:disabled{opacity:.7;cursor:wait;}
        .veyra-pm-gh svg{flex:0 0 auto;}
        .veyra-pm-gh-pill{display:inline-flex;align-items:center;gap:4px;padding:0 7px;border-radius:9px;font-size:11px;font-weight:600;line-height:18px;background:#57606a;color:#fff;white-space:nowrap;}
        .veyra-pm-gh-pill::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.9;}
        .veyra-pm-gh-pill.is-available{background:#fb8500;}
        .veyra-pm-gh-pill.is-current{background:#1a7f37;}
        .veyra-pm-gh-pill.is-unknown{background:#6e7781;}
        #veyra-pm-gh-notice{margin:10px 0 6px;}
    </style>
    <script>
    // Built on window load, after the other heading controls (inserted on
    // DOMContentLoaded right after "Add New Post"), so this lands directly
    // beside the native button instead of being pushed to the end of the row.
    window.addEventListener('load', function () {
        var ajax  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode(wp_create_nonce('veyra_plugin_update')); ?>;
        var mgr   = <?php echo wp_json_encode(admin_url('admin.php?page=veyra_plugin_manager')); ?>;

        var anchor = document.querySelector('.wrap .page-title-action') || document.querySelector('.wrap h1.wp-heading-inline');
        if (!anchor) { return; }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'veyra-pm-gh';
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path fill="#fff" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>'
            + '<span class="veyra-pm-gh-label">Update From GitHub</span>'
            + '<span class="veyra-pm-gh-pill is-unknown">checking…</span>';
        anchor.insertAdjacentElement('afterend', btn);

        var label = btn.querySelector('.veyra-pm-gh-label');
        var pill  = btn.querySelector('.veyra-pm-gh-pill');

        function setPill(state, text, title) {
            pill.className = 'veyra-pm-gh-pill is-' + state;
            pill.textContent = text;
            btn.title = title || '';
        }

        function showStatus(d) {
            var v = 'Installed: ' + d.local_version + (d.remote_version ? '  ·  GitHub: ' + d.remote_version : '');
            if (d.state === 'available') {
                setPill('available', 'update available',
                    v + (d.method === 'tree' ? '\n' + d.changed + ' file(s) differ from GitHub main' : ''));
            } else if (d.state === 'current') {
                setPill('current', 'up to date', v);
            } else {
                setPill('unknown', 'status unknown', d.error || 'Could not reach GitHub');
            }
        }

        function post(data) {
            var body = new URLSearchParams(data);
            body.append('nonce', nonce);
            return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                });
        }

        function notice(type, html) {
            var old = document.getElementById('veyra-pm-gh-notice');
            if (old) { old.remove(); }
            var n = document.createElement('div');
            n.id = 'veyra-pm-gh-notice';
            n.className = 'notice notice-' + type + ' is-dismissible';
            n.innerHTML = '<p>' + html + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';
            n.querySelector('.notice-dismiss').addEventListener('click', function () { n.remove(); });
            var end = document.querySelector('.wrap .wp-header-end');
            (end || btn).insertAdjacentElement('afterend', n);
        }

        function esc(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function check(force) {
            setPill('unknown', 'checking…');
            return post({ action: 'veyra_pm_check_update', force: force ? 1 : 0 })
                .then(function (res) {
                    if (res && res.success) { showStatus(res.data); }
                    else { setPill('unknown', 'status unknown', res && res.data ? String(res.data) : ''); }
                })
                .catch(function (e) { setPill('unknown', 'status unknown', e.message); });
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            label.textContent = 'Updating…';
            post({ action: 'veyra_update_from_github', plugin: 'veyra/veyra.php' })
                .then(function (res) {
                    if (res && res.success) {
                        var d = res.data || {};
                        notice('success', '<strong>Veyra updated from GitHub.</strong> ' + esc(d.message || '')
                            + (d.version ? ' Now running version ' + esc(d.version) + '.' : ''));
                        return check(true);
                    }
                    notice('error', '<strong>Update failed:</strong> '
                        + esc(res && typeof res.data === 'string' && res.data ? res.data : 'The server rejected the update but gave no reason.')
                        + ' <a href="' + mgr + '">Open Veyra Plugin Updater</a>');
                })
                .catch(function (e) {
                    notice('error', '<strong>Update failed:</strong> the request did not complete (' + esc(e.message)
                        + '). The plugin may be partially updated — check <a href="' + mgr + '">Veyra Plugin Updater</a> before retrying.');
                })
                .then(function () {
                    btn.disabled = false;
                    label.textContent = 'Update From GitHub';
                });
        });

        check(false);
    });
    </script>
    <?php
}
