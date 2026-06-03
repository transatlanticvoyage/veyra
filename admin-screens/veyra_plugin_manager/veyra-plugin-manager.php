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

add_action('admin_menu', 'veyra_plugin_manager_register_menu', 20);
function veyra_plugin_manager_register_menu() {
    add_submenu_page(
        'veyra-hub-1',                          // parent slug (Veyra Hub 1)
        'Veyra Plugin Manager',                 // page title
        'Plugin Manager',                       // menu label
        'manage_options',                       // capability
        'veyra_plugin_manager',                 // page slug
        'veyra_plugin_manager_render_page'      // callback
    );
}

/* ---------------------------------------------------------------------------
 * AJAX handlers
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_veyra_toggle_plugin', 'veyra_plugin_manager_ajax_toggle');
add_action('wp_ajax_veyra_delete_plugin', 'veyra_plugin_manager_ajax_delete');
add_action('wp_ajax_veyra_update_from_github', 'veyra_plugin_manager_ajax_update_github');

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
    } else {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('plugins', 'plugins');
        }
        delete_site_transient('update_plugins');
        wp_send_json_success($result['message']);
    }
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

    $needs_update = ($remote_version && version_compare($remote_version, $info['version'], '>'));
    $host_blocked = veyra_plugin_manager_is_blocked_host();
    ?>
    <div class="wrap">

        <h1 style="display: flex; align-items: center; gap: 14px; margin: 0 0 8px 0;">
            <span class="dashicons dashicons-sun" style="font-size: 30px; width: 30px; height: 30px; color: #d97706;"></span>
            Veyra Plugin Manager
        </h1>
        <p style="color: #666; margin: 0 0 20px 0;">Manage the Veyra plugin and update it from GitHub.</p>

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
                        <?php echo esc_html($info['version']); ?>
                    </td>
                    <td style="border: 1px solid #555; padding: 8px;">
                        <span class="status-badge <?php echo $is_active ? 'active' : 'inactive'; ?>">
                            <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td style="border: 1px solid #555; padding: 8px;">
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
                                Local: <strong><?php echo esc_html($info['version']); ?></strong>
                            </span>
                        <?php else: ?>
                            <span style="font-size: 12px; color: #888;">
                                Local: <strong><?php echo esc_html($info['version']); ?></strong><br>
                                <em>remote unavailable</em>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

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
                if (!confirm('Update the Veyra plugin from GitHub?')) {
                    return;
                }
                $button.prop('disabled', true).text('Updating...');
                $.post(ajaxurl, {
                    action: 'veyra_update_from_github',
                    plugin: plugin,
                    nonce: '<?php echo esc_js(wp_create_nonce('veyra_plugin_update')); ?>'
                }).done(function(response) {
                    if (response.success) {
                        alert('Veyra updated successfully from GitHub!');
                        setTimeout(function() { location.reload(); }, 500);
                    } else {
                        alert('Error: ' + response.data);
                        $button.prop('disabled', false).text('Update From Github');
                    }
                }).fail(function() {
                    alert('Update failed. Please try again.');
                    $button.prop('disabled', false).text('Update From Github');
                });
            }
        });
    });
    </script>
    <?php
}
