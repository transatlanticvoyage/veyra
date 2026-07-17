<?php
/**
 * Veyra — Change WP User And Pass admin screen.
 *
 * Self-contained feature: registers an admin page at
 * /wp-admin/admin.php?page=veyra_change_wp_user_and_pass under the Veyra menu.
 *
 * Lets the currently logged-in WP admin view/edit every core identity field
 * (user_login, user_nicename, display_name, nickname) and rotate their
 * password. WordPress hashes passwords one-way (phpass) — there is no
 * plaintext to "view", so the password section shows the raw stored hash
 * (wp_users.user_pass) plus a set-new-password control.
 *
 * Kept entirely in this file to avoid cluttering veyra.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Admin: menu, notice suppression, page render
// ---------------------------------------------------------------------------
add_action('admin_menu', 'veyra_cwup_register_menu', 20);
function veyra_cwup_register_menu() {
    add_submenu_page(
        'veyra-hub-1',                          // parent (Veyra Hub 1)
        'Veyra Change Wp User And Pass',        // page title
        'veyra change wp user and pass',        // menu label (anchor text, no underscores)
        'manage_options',                       // capability
        'veyra_change_wp_user_and_pass',        // slug -> ?page=veyra_change_wp_user_and_pass
        'veyra_cwup_render_page'                // callback
    );
}

/** Aggressive notice/warning/message suppression on this screen only. */
add_action('in_admin_header', 'veyra_cwup_suppress_notices', 1);
function veyra_cwup_suppress_notices() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'veyra_change_wp_user_and_pass') {
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
// Save handler: identity fields (user_login, user_nicename, display_name,
// nickname meta) for the CURRENTLY LOGGED-IN user only.
// ---------------------------------------------------------------------------
add_action('admin_init', 'veyra_cwup_handle_identity_save');
function veyra_cwup_handle_identity_save() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'veyra_change_wp_user_and_pass') {
        return;
    }
    if (!isset($_POST['veyra_cwup_identity_save'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('veyra_cwup_identity_save', 'veyra_cwup_identity_nonce');

    $user_id = get_current_user_id();
    $current = get_userdata($user_id);
    if (!$current) {
        return;
    }

    $new_login      = isset($_POST['veyra_cwup_user_login']) ? sanitize_user(wp_unslash($_POST['veyra_cwup_user_login']), true) : '';
    $new_nicename   = isset($_POST['veyra_cwup_user_nicename']) ? sanitize_title(wp_unslash($_POST['veyra_cwup_user_nicename'])) : '';
    $new_display    = isset($_POST['veyra_cwup_display_name']) ? sanitize_text_field(wp_unslash($_POST['veyra_cwup_display_name'])) : '';
    $new_nickname   = isset($_POST['veyra_cwup_nickname']) ? sanitize_text_field(wp_unslash($_POST['veyra_cwup_nickname'])) : '';

    $errors = array();

    if ($new_login === '') {
        $errors[] = 'username_empty';
    } elseif (!validate_username($new_login)) {
        $errors[] = 'username_invalid';
    } elseif ($new_login !== $current->user_login && username_exists($new_login)) {
        $errors[] = 'username_taken';
    }

    if (!empty($errors)) {
        wp_safe_redirect(add_query_arg(array(
            'identity_error' => implode(',', $errors),
        ), admin_url('admin.php?page=veyra_change_wp_user_and_pass')));
        exit;
    }

    // wp_update_user()/wp_insert_user() silently ignore user_login on updates
    // (core always keeps the DB's existing login) — so user_login can only be
    // changed via a direct query against wp_users.
    if ($new_login !== $current->user_login) {
        global $wpdb;
        $wpdb->update($wpdb->users, array('user_login' => $new_login), array('ID' => $user_id));
        clean_user_cache($user_id);

        // The auth cookie embeds the username; if it no longer matches the DB
        // record WP will reject it on the very next request and log the user
        // out. Reissue the cookie against the new login to stay signed in.
        wp_clear_auth_cookie();
        wp_set_auth_cookie($user_id, true);
    }

    wp_update_user(array(
        'ID'           => $user_id,
        'user_nicename' => $new_nicename,
        'display_name'  => $new_display,
    ));

    update_user_meta($user_id, 'nickname', $new_nickname);

    wp_safe_redirect(add_query_arg('identity_saved', '1', admin_url('admin.php?page=veyra_change_wp_user_and_pass')));
    exit;
}

// ---------------------------------------------------------------------------
// Save handler: password (wp_users.user_pass) for the CURRENTLY LOGGED-IN
// user only.
// ---------------------------------------------------------------------------
add_action('admin_init', 'veyra_cwup_handle_password_save');
function veyra_cwup_handle_password_save() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'veyra_change_wp_user_and_pass') {
        return;
    }
    if (!isset($_POST['veyra_cwup_password_save'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('veyra_cwup_password_save', 'veyra_cwup_password_nonce');

    $user_id  = get_current_user_id();
    $new_pass = isset($_POST['veyra_cwup_new_password']) ? wp_unslash($_POST['veyra_cwup_new_password']) : '';
    $confirm  = isset($_POST['veyra_cwup_confirm_password']) ? wp_unslash($_POST['veyra_cwup_confirm_password']) : '';

    if ($new_pass === '' || $confirm === '') {
        wp_safe_redirect(add_query_arg('pwd_error', 'empty', admin_url('admin.php?page=veyra_change_wp_user_and_pass')));
        exit;
    }
    if ($new_pass !== $confirm) {
        wp_safe_redirect(add_query_arg('pwd_error', 'mismatch', admin_url('admin.php?page=veyra_change_wp_user_and_pass')));
        exit;
    }
    if (strlen($new_pass) < 6) {
        wp_safe_redirect(add_query_arg('pwd_error', 'short', admin_url('admin.php?page=veyra_change_wp_user_and_pass')));
        exit;
    }

    wp_set_password($new_pass, $user_id);

    // wp_set_password() destroys all of this user's sessions (including the
    // current one) and does not refresh the auth cookie. Re-authenticate so
    // the admin isn't immediately booted out after rotating their own password.
    wp_clear_auth_cookie();
    wp_set_auth_cookie($user_id, true);

    wp_safe_redirect(add_query_arg('pwd_saved', '1', admin_url('admin.php?page=veyra_change_wp_user_and_pass')));
    exit;
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
function veyra_cwup_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $user_id = get_current_user_id();
    $user    = get_userdata($user_id);

    $user_login    = $user->user_login;
    $user_nicename = $user->user_nicename;
    $display_name  = $user->display_name;
    $nickname      = get_user_meta($user_id, 'nickname', true);
    $user_pass_hash = $user->user_pass;

    $identity_errors = array();
    if (!empty($_GET['identity_error'])) {
        $identity_errors = explode(',', sanitize_text_field(wp_unslash($_GET['identity_error'])));
    }
    $identity_error_messages = array(
        'username_empty'  => 'Username cannot be blank.',
        'username_invalid' => 'That username contains characters WordPress does not allow (letters, numbers, spaces, and _ . - * @ only).',
        'username_taken'  => 'That username is already in use by another account.',
    );

    $pwd_error_messages = array(
        'empty'    => 'Both password fields are required.',
        'mismatch' => 'New Password and Confirm Password do not match.',
        'short'    => 'Password must be at least 6 characters.',
    );
    ?>
    <div class="wrap veyra-cwup">
        <h1>Veyra Change Wp User And Pass</h1>

        <p class="veyra-cwup-current">Currently logged in as: <strong><?php echo esc_html($user_login); ?></strong> (user ID <?php echo esc_html($user_id); ?>)</p>

        <?php if (isset($_GET['identity_saved'])): ?>
            <p class="veyra-cwup-msg veyra-cwup-msg-success">Identity fields saved.</p>
        <?php endif; ?>
        <?php foreach ($identity_errors as $err): if (isset($identity_error_messages[$err])): ?>
            <p class="veyra-cwup-msg veyra-cwup-msg-error"><?php echo esc_html($identity_error_messages[$err]); ?></p>
        <?php endif; endforeach; ?>

        <?php if (isset($_GET['pwd_saved'])): ?>
            <p class="veyra-cwup-msg veyra-cwup-msg-success">Password changed.</p>
        <?php endif; ?>
        <?php if (!empty($_GET['pwd_error']) && isset($pwd_error_messages[$_GET['pwd_error']])): ?>
            <p class="veyra-cwup-msg veyra-cwup-msg-error"><?php echo esc_html($pwd_error_messages[$_GET['pwd_error']]); ?></p>
        <?php endif; ?>

        <h2>Identity Fields</h2>
        <form method="post">
            <?php wp_nonce_field('veyra_cwup_identity_save', 'veyra_cwup_identity_nonce'); ?>

            <table class="wp-list-table veyra-cwup-table">
                <thead>
                    <tr>
                        <th><strong>field</strong></th>
                        <th><strong>value</strong></th>
                        <th><strong>db schema</strong></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Username<br><span class="veyra-cwup-help-text">What you type at wp-login.php.</span></td>
                        <td>
                            <input type="text" id="veyra_cwup_user_login" name="veyra_cwup_user_login" value="<?php echo esc_attr($user_login); ?>">
                            <button type="button" class="button veyra-cwup-copy-btn" data-copy-target="veyra_cwup_user_login">Copy</button>
                        </td>
                        <td><code class="veyra-cwup-schema">wp_users.user_login</code></td>
                    </tr>
                    <tr>
                        <td>Nicename<br><span class="veyra-cwup-help-text">URL-safe slug used in author archive links (<code>/author/{nicename}/</code>) and the REST API. Auto-derived from Username on account creation via <code>sanitize_title()</code>, but can drift from it afterward — editable independently here.</span></td>
                        <td>
                            <input type="text" id="veyra_cwup_user_nicename" name="veyra_cwup_user_nicename" value="<?php echo esc_attr($user_nicename); ?>">
                            <button type="button" class="button veyra-cwup-copy-btn" data-copy-target="veyra_cwup_user_nicename">Copy</button>
                        </td>
                        <td><code class="veyra-cwup-schema">wp_users.user_nicename</code></td>
                    </tr>
                    <tr>
                        <td>Display Name<br><span class="veyra-cwup-help-text">Public-facing name shown on posts/comments/author box. Independent of Username.</span></td>
                        <td>
                            <input type="text" id="veyra_cwup_display_name" name="veyra_cwup_display_name" value="<?php echo esc_attr($display_name); ?>">
                            <button type="button" class="button veyra-cwup-copy-btn" data-copy-target="veyra_cwup_display_name">Copy</button>
                        </td>
                        <td><code class="veyra-cwup-schema">wp_users.display_name</code></td>
                    </tr>
                    <tr>
                        <td>Nickname<br><span class="veyra-cwup-help-text">Feeds the "Nickname" field on the native WP profile screen — one of the choices offered for Display Name there.</span></td>
                        <td>
                            <input type="text" id="veyra_cwup_nickname" name="veyra_cwup_nickname" value="<?php echo esc_attr($nickname); ?>">
                            <button type="button" class="button veyra-cwup-copy-btn" data-copy-target="veyra_cwup_nickname">Copy</button>
                        </td>
                        <td><code class="veyra-cwup-schema">wp_usermeta (meta_key: nickname)</code></td>
                    </tr>
                </tbody>
            </table>

            <p>
                <button type="submit" name="veyra_cwup_identity_save" value="1" class="button button-primary">Save Identity Fields</button>
            </p>
        </form>

        <h2>Password</h2>
        <table class="wp-list-table veyra-cwup-table">
            <thead>
                <tr>
                    <th><strong>field</strong></th>
                    <th><strong>value</strong></th>
                    <th><strong>db schema</strong></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Current Password Hash<br><span class="veyra-cwup-help-text">WordPress hashes passwords one-way (phpass) — the original plaintext is never stored anywhere, so it cannot be "viewed". This is the raw hash on record.</span></td>
                    <td>
                        <input type="text" id="veyra_cwup_pass_hash" readonly value="<?php echo esc_attr($user_pass_hash); ?>">
                        <button type="button" class="button veyra-cwup-copy-btn" data-copy-target="veyra_cwup_pass_hash">Copy</button>
                    </td>
                    <td><code class="veyra-cwup-schema">wp_users.user_pass</code></td>
                </tr>
            </tbody>
        </table>

        <form method="post" class="veyra-cwup-password-form">
            <?php wp_nonce_field('veyra_cwup_password_save', 'veyra_cwup_password_nonce'); ?>

            <table class="wp-list-table veyra-cwup-table">
                <tbody>
                    <tr>
                        <td>New Password</td>
                        <td>
                            <input type="password" id="veyra_cwup_new_password" name="veyra_cwup_new_password" autocomplete="new-password">
                            <button type="button" class="button veyra-cwup-eye-btn" data-eye-target="veyra_cwup_new_password"><span class="dashicons dashicons-visibility"></span></button>
                            <button type="button" class="button veyra-cwup-copy-btn" data-copy-target="veyra_cwup_new_password">Copy</button>
                        </td>
                        <td><code class="veyra-cwup-schema">wp_users.user_pass</code> <span class="veyra-cwup-help-text">(stored hashed via <code>wp_set_password()</code>)</span></td>
                    </tr>
                    <tr>
                        <td>Confirm New Password</td>
                        <td>
                            <input type="password" id="veyra_cwup_confirm_password" name="veyra_cwup_confirm_password" autocomplete="new-password">
                            <button type="button" class="button veyra-cwup-eye-btn" data-eye-target="veyra_cwup_confirm_password"><span class="dashicons dashicons-visibility"></span></button>
                            <button type="button" class="button veyra-cwup-copy-btn" data-copy-target="veyra_cwup_confirm_password">Copy</button>
                        </td>
                        <td></td>
                    </tr>
                </tbody>
            </table>

            <p>
                <button type="submit" name="veyra_cwup_password_save" value="1" class="button button-primary">Change Password</button>
            </p>
        </form>
    </div>

    <style>
        .veyra-cwup-current {
            font-size: 14px;
        }
        .veyra-cwup-msg {
            padding: 8px 12px;
            border-left: 4px solid;
            background: #fff;
        }
        .veyra-cwup-msg-success {
            border-color: #00a32a;
        }
        .veyra-cwup-msg-error {
            border-color: #d63638;
        }
        .veyra-cwup-table {
            width: 100%;
            margin: 12px 0 16px;
            border-collapse: collapse;
        }
        .veyra-cwup-table th,
        .veyra-cwup-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #c3c4c7;
            vertical-align: top;
            text-align: left;
        }
        .veyra-cwup-table thead th {
            background: #f0f0f1;
        }
        .veyra-cwup-table tbody tr td:first-child {
            font-weight: 700;
            width: 260px;
        }
        .veyra-cwup-table td input[type="text"],
        .veyra-cwup-table td input[type="password"] {
            width: 320px;
        }
        .veyra-cwup-help-text {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            font-weight: 400;
            color: #646970;
            font-style: italic;
            max-width: 420px;
        }
        .veyra-cwup-help-text code {
            font-style: normal;
        }
        .veyra-cwup-schema {
            font-size: 12px;
            background: #f0f0f1;
            padding: 3px 6px;
            border-radius: 3px;
        }
        .veyra-cwup-eye-btn .dashicons {
            vertical-align: middle;
        }
    </style>
    <script>
    (function(){
        // Copy-to-clipboard for any field with a paired [data-copy-target] button.
        document.querySelectorAll('.veyra-cwup-copy-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                var el = document.getElementById(btn.getAttribute('data-copy-target'));
                if (!el) { return; }
                var value = ('value' in el) ? el.value : el.textContent;
                navigator.clipboard.writeText(value).then(function(){
                    var original = btn.textContent;
                    btn.textContent = 'Copied!';
                    setTimeout(function(){ btn.textContent = original; }, 1200);
                });
            });
        });

        // Eyeball show/hide toggle for password inputs.
        document.querySelectorAll('.veyra-cwup-eye-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                var el = document.getElementById(btn.getAttribute('data-eye-target'));
                if (!el) { return; }
                var icon = btn.querySelector('.dashicons');
                if (el.type === 'password') {
                    el.type = 'text';
                    if (icon) { icon.classList.remove('dashicons-visibility'); icon.classList.add('dashicons-hidden'); }
                } else {
                    el.type = 'password';
                    if (icon) { icon.classList.remove('dashicons-hidden'); icon.classList.add('dashicons-visibility'); }
                }
            });
        });
    })();
    </script>
    <?php
}
