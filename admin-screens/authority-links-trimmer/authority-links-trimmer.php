<?php
/**
 * Veyra → Authority Links Trimmer (admin screen)
 *
 * URL slug:   veyra_authority_links_trimmer
 * Access URL: /wp-admin/admin.php?page=veyra_authority_links_trimmer
 * Parent:     Veyra Hub 1 (3rd item, right under Post Importer From Birch)
 *
 * Scans every <a href> in every post + page (any status except trash /
 * auto-draft / revisions) and classifies each outbound link:
 *   - money_site : host is (or is a subdomain of) a domain in the money-site list
 *   - internal   : host is this site's own host
 *   - authority  : any other external domain
 *   - other      : relative / anchor / mailto / tel / javascript links
 *
 * MONEY-SITE LIST: tregnar's sitespren rows with is_rnr = TRUE. Stored per site
 * in the wp_option `veyra_rnr_money_domains` (array, autoload off) and edited on
 * this screen. It is deliberately NOT shipped inside the plugin, because the
 * veyra GitHub repo is public.
 *
 * Read-only for now: it reports, it does not edit posts.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VEYRA_ALT_MONEY_OPTION = 'veyra_rnr_money_domains';
const VEYRA_ALT_BATCH        = 150;

/** Bright purple disc with a white chain link + scissors cut, shown LEFT of the label. */
function veyra_alt_icon_svg($size = 16) {
    $size = (int) $size;
    return '<svg class="veyra-alt-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" style="display:inline-block;vertical-align:-3px;margin-right:6px;flex:0 0 auto;">'
        . '<circle cx="12" cy="12" r="12" fill="#8b2cf5"/>'
        . '<path d="M10.6 13.4a3 3 0 0 0 4.2 0l2.1-2.1a3 3 0 0 0-4.2-4.2l-.9.9" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>'
        . '<path d="M13.4 10.6a3 3 0 0 0-4.2 0l-2.1 2.1a3 3 0 0 0 4.2 4.2l.9-.9" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>'
        . '</svg>';
}

// ---------------------------------------------------------------------------
// Menu: register, then slot in as the 3rd item (after Plugin Updater + Importer)
// ---------------------------------------------------------------------------
add_action('admin_menu', 'veyra_alt_register_menu', 20);
function veyra_alt_register_menu() {
    add_submenu_page(
        'veyra-hub-1',
        'Authority Links Trimmer',
        veyra_alt_icon_svg() . 'Authority Links Trimmer',
        'edit_posts',
        'veyra_authority_links_trimmer',
        'veyra_alt_render_page'
    );
}

// Runs after veyra_plugin_manager_menu_first (9999) and veyra_post_importer_menu_second (10000).
add_action('admin_menu', 'veyra_alt_menu_third', 10001);
function veyra_alt_menu_third() {
    global $submenu;
    if (empty($submenu['veyra-hub-1'])) {
        return;
    }
    $items = array_values($submenu['veyra-hub-1']);
    $mine  = null;
    foreach ($items as $i => $item) {
        if (isset($item[2]) && $item[2] === 'veyra_authority_links_trimmer') {
            $mine = $item;
            array_splice($items, $i, 1);
            break;
        }
    }
    if (!$mine) {
        return;
    }
    $pos = 0;
    foreach ($items as $i => $item) {
        if (isset($item[2]) && in_array($item[2], ['veyra_plugin_manager', 'veyra_post_importer'], true)) {
            $pos = $i + 1;
        }
    }
    array_splice($items, $pos, 0, [$mine]);
    $submenu['veyra-hub-1'] = $items;
}

// ---------------------------------------------------------------------------
// Money-site list helpers
// ---------------------------------------------------------------------------

/** Normalise one host/URL/domain to a bare lowercase host without www. */
function veyra_alt_normalize_host($s) {
    $s = strtolower(trim((string) $s));
    if ($s === '') return '';
    if (strpos($s, '//') === false) {
        $s = 'http://' . $s;
    }
    $host = parse_url($s, PHP_URL_HOST);
    if (!$host) return '';
    $host = rtrim($host, '.');
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return $host;
}

function veyra_alt_get_money_domains() {
    $list = get_option(VEYRA_ALT_MONEY_OPTION, []);
    return is_array($list) ? $list : [];
}

/** Parse free text (one per line, commas/spaces ok) into a sorted unique host list. */
function veyra_alt_parse_domain_text($text) {
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string) $text) as $tok) {
        $h = veyra_alt_normalize_host($tok);
        if ($h !== '' && strpos($h, '.') !== false) {
            $out[$h] = true;
        }
    }
    $out = array_keys($out);
    sort($out);
    return $out;
}

/** Money-site domain matched by $host (exact or subdomain), or ''. */
function veyra_alt_match_money($host, array $money_set) {
    $parts = explode('.', $host);
    for ($i = 0; $i < count($parts) - 1; $i++) {
        $cand = implode('.', array_slice($parts, $i));
        if (isset($money_set[$cand])) {
            return $cand;
        }
    }
    return '';
}

// ---------------------------------------------------------------------------
// Link extraction + classification
// ---------------------------------------------------------------------------
function veyra_alt_extract_links($html) {
    $links = [];
    if (stripos($html, '<a') === false) {
        return $links;
    }
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    foreach ($doc->getElementsByTagName('a') as $a) {
        if (!$a->hasAttribute('href')) continue;
        $text = trim(preg_replace('/\s+/u', ' ', $a->textContent));
        if ($text === '' && $a->getElementsByTagName('img')->length) {
            $text = '[image]';
        }
        $links[] = [
            'href'   => trim($a->getAttribute('href')),
            'anchor' => $text,
            'rel'    => trim($a->getAttribute('rel')),
        ];
    }
    return $links;
}

function veyra_alt_classify($href, $site_host, array $money_set) {
    $lower = strtolower($href);
    if ($href === '' || $lower[0] === '#' || preg_match('/^(mailto|tel|sms|javascript|data):/', $lower)) {
        return ['other', ''];
    }
    if (strpos($lower, '//') === 0) {
        $href = 'https:' . $href;
    } elseif (!preg_match('#^[a-z][a-z0-9+.-]*://#', $lower)) {
        return ['internal', $site_host]; // relative URL
    }
    $host = veyra_alt_normalize_host($href);
    if ($host === '') {
        return ['other', ''];
    }
    if ($host === $site_host) {
        return ['internal', $host];
    }
    $money = veyra_alt_match_money($host, $money_set);
    if ($money !== '') {
        return ['money_site', $host];
    }
    return ['authority', $host];
}

// ---------------------------------------------------------------------------
// AJAX
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_alt_scan_batch', 'veyra_alt_ajax_scan_batch');
function veyra_alt_ajax_scan_batch() {
    check_ajax_referer('veyra_alt', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permission denied.'], 403);
    }
    global $wpdb;
    $after = isset($_POST['after_id']) ? (int) $_POST['after_id'] : 0;

    $statuses = ['publish', 'future', 'draft', 'pending', 'private'];
    $in = implode(',', array_fill(0, count($statuses), '%s'));
    $sql = $wpdb->prepare(
        "SELECT ID, post_title, post_type, post_status, post_content
           FROM {$wpdb->posts}
          WHERE post_type IN ('post','page') AND post_status IN ($in) AND ID > %d
          ORDER BY ID ASC LIMIT %d",
        array_merge($statuses, [$after, VEYRA_ALT_BATCH])
    );
    $posts = $wpdb->get_results($sql);

    $total = 0;
    if ($after === 0) {
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status IN ($in)",
            $statuses
        ));
    }

    $site_host = veyra_alt_normalize_host(home_url());
    $money_set = array_fill_keys(veyra_alt_get_money_domains(), true);

    $rows    = [];
    $last_id = $after;
    foreach ($posts as $p) {
        $last_id = (int) $p->ID;
        foreach (veyra_alt_extract_links($p->post_content) as $l) {
            list($kind, $host) = veyra_alt_classify($l['href'], $site_host, $money_set);
            $rows[] = [
                'post_id' => (int) $p->ID,
                'title'   => $p->post_title,
                'type'    => $p->post_type,
                'status'  => $p->post_status,
                'href'    => $l['href'],
                'anchor'  => $l['anchor'],
                'rel'     => $l['rel'],
                'host'    => $host,
                'kind'    => $kind,
            ];
        }
    }

    wp_send_json_success([
        'rows'     => $rows,
        'scanned'  => count($posts),
        'last_id'  => $last_id,
        'done'     => count($posts) < VEYRA_ALT_BATCH,
        'total'    => $total,
        'edit_base'=> admin_url('post.php?action=edit&post='),
    ]);
}

add_action('wp_ajax_veyra_alt_save_money', 'veyra_alt_ajax_save_money');
function veyra_alt_ajax_save_money() {
    check_ajax_referer('veyra_alt', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Only administrators can change the money-site list.'], 403);
    }
    $list = veyra_alt_parse_domain_text(wp_unslash($_POST['domains'] ?? ''));
    if (get_option(VEYRA_ALT_MONEY_OPTION, null) === null) {
        add_option(VEYRA_ALT_MONEY_OPTION, $list, '', 'no');
    } else {
        update_option(VEYRA_ALT_MONEY_OPTION, $list, false);
    }
    update_option('veyra_rnr_money_domains_saved_at', current_time('mysql'), false);
    wp_send_json_success(['domains' => $list, 'count' => count($list)]);
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
function veyra_alt_render_page() {
    $money     = veyra_alt_get_money_domains();
    $saved_at  = get_option('veyra_rnr_money_domains_saved_at', '');
    $can_save  = current_user_can('manage_options');
    $site_host = veyra_alt_normalize_host(home_url());
    ?>
    <div class="wrap" id="veyra-alt">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:14px;"><?php echo veyra_alt_icon_svg(26); ?>Authority Links Trimmer</h1>

        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;margin-bottom:18px;">
            <div style="flex:1 1 420px;">
                <p style="margin-top:0;">
                    Scans every link in every post and page on <strong><?php echo esc_html($site_host); ?></strong>.
                    A link counts as an <span class="veyra-alt-pill k-authority">authority</span> link when its domain is
                    external and <em>not</em> one of our money sites (tregnar <code>sitespren.is_rnr = TRUE</code>).
                    Links to money sites (and their subdomains) are marked <span class="veyra-alt-pill k-money_site">money site</span>.
                </p>
                <button type="button" class="button button-primary" id="veyra-alt-scan" style="background:#8b2cf5;border-color:#7c1fe0;padding:6px 18px;font-size:14px;">
                    scan all links in all posts + pages
                </button>
                <span id="veyra-alt-progress" style="margin-left:10px;color:#555;"></span>
                <?php if (empty($money)): ?>
                    <p style="color:#b45309;margin:10px 0 0;"><strong>The money-site list is empty</strong>, so every external link will show as authority. Paste the is_rnr domains on the right first.</p>
                <?php endif; ?>
            </div>

            <details style="flex:0 1 420px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:10px 12px;" <?php echo empty($money) ? 'open' : ''; ?>>
                <summary style="cursor:pointer;font-weight:600;">
                    Money sites (is_rnr = TRUE): <span id="veyra-alt-money-count"><?php echo count($money); ?></span> domains
                    <?php if ($saved_at): ?><span style="font-weight:400;color:#666;"> · saved <?php echo esc_html($saved_at); ?></span><?php endif; ?>
                </summary>
                <p style="font-size:12px;color:#666;margin:8px 0;">
                    One domain per line. Stored in the <code><?php echo esc_html(VEYRA_ALT_MONEY_OPTION); ?></code> wp_option on this site only
                    (kept out of the plugin because the veyra repo is public). Subdomains and www. match automatically.
                </p>
                <textarea id="veyra-alt-money" rows="10" style="width:100%;font-family:Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea(implode("\n", $money)); ?></textarea>
                <div style="margin-top:6px;display:flex;gap:8px;align-items:center;">
                    <button type="button" class="button button-primary" id="veyra-alt-money-save" <?php disabled(!$can_save); ?>>save money-site list</button>
                    <span id="veyra-alt-money-status" style="font-size:12px;color:#666;"></span>
                </div>
            </details>
        </div>

        <div id="veyra-alt-results" style="display:none;">
            <div id="veyra-alt-summary" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;"></div>
            <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
                <input type="search" id="veyra-alt-search" placeholder="filter by domain, URL, anchor or post title" style="min-width:320px;">
                <label><input type="checkbox" id="veyra-alt-group"> one row per domain</label>
                <span id="veyra-alt-shown" style="color:#666;"></span>
            </div>
            <table class="widefat striped" id="veyra-alt-table" style="table-layout:auto;">
                <thead></thead>
                <tbody></tbody>
            </table>
        </div>

        <style>
            #veyra-alt .veyra-alt-pill { display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600;color:#fff;white-space:nowrap; }
            #veyra-alt .k-authority  { background:#8b2cf5; }
            #veyra-alt .k-money_site { background:#16a34a; }
            #veyra-alt .k-internal   { background:#64748b; }
            #veyra-alt .k-other      { background:#a8a29e; }
            #veyra-alt .veyra-alt-filter { cursor:pointer;border:2px solid transparent; }
            #veyra-alt .veyra-alt-filter.is-active { border-color:#1d2327; }
            #veyra-alt-table td { vertical-align:top;word-break:break-word; }
            #veyra-alt-table td.u { max-width:420px; }
        </style>

        <script>
        (function () {
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce   = <?php echo wp_json_encode(wp_create_nonce('veyra_alt')); ?>;
            var LABELS  = { authority: 'authority', money_site: 'money site', internal: 'internal', other: 'other' };
            var rows = [], filterKind = 'authority', editBase = '';
            var $ = function (id) { return document.getElementById(id); };

            function post(data) {
                var body = new URLSearchParams(Object.assign({ nonce: nonce }, data));
                return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
            }
            function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
            function pill(kind) { return '<span class="veyra-alt-pill k-' + kind + '">' + LABELS[kind] + '</span>'; }

            $('veyra-alt-money-save') && $('veyra-alt-money-save').addEventListener('click', function () {
                $('veyra-alt-money-status').textContent = 'saving…';
                post({ action: 'veyra_alt_save_money', domains: $('veyra-alt-money').value }).then(function (res) {
                    if (!res.success) { $('veyra-alt-money-status').textContent = 'not saved: ' + ((res.data && res.data.message) || 'failed'); return; }
                    $('veyra-alt-money').value = res.data.domains.join('\n');
                    $('veyra-alt-money-count').textContent = res.data.count;
                    $('veyra-alt-money-status').textContent = 'saved ' + res.data.count + ' domains. Re-scan to apply.';
                });
            });

            $('veyra-alt-scan').addEventListener('click', function () {
                var btn = this; btn.disabled = true; rows = []; var scanned = 0, total = 0;
                $('veyra-alt-progress').textContent = 'scanning…';
                (function next(after) {
                    post({ action: 'veyra_alt_scan_batch', after_id: after }).then(function (res) {
                        if (!res.success) throw new Error((res.data && res.data.message) || 'scan failed');
                        var d = res.data;
                        if (after === 0) total = d.total;
                        editBase = d.edit_base;
                        rows = rows.concat(d.rows); scanned += d.scanned;
                        $('veyra-alt-progress').textContent = 'scanned ' + scanned + ' / ' + total + ' posts + pages, ' + rows.length + ' links';
                        if (d.done) { btn.disabled = false; btn.textContent = 're-scan'; render(); }
                        else next(d.last_id);
                    }).catch(function (e) { btn.disabled = false; $('veyra-alt-progress').textContent = 'error: ' + e.message; });
                })(0);
            });

            function render() {
                $('veyra-alt-results').style.display = '';
                var counts = { authority: 0, money_site: 0, internal: 0, other: 0 }, hosts = { authority: {}, money_site: {} };
                rows.forEach(function (r) { counts[r.kind]++; if (hosts[r.kind]) hosts[r.kind][r.host] = 1; });
                var sum = '';
                ['authority', 'money_site', 'internal', 'other', 'all'].forEach(function (k) {
                    var n = k === 'all' ? rows.length : counts[k];
                    var extra = hosts[k] ? ' · ' + Object.keys(hosts[k]).length + ' domains' : '';
                    sum += '<span class="veyra-alt-pill veyra-alt-filter k-' + (k === 'all' ? 'internal' : k) + (filterKind === k ? ' is-active' : '') +
                           '" data-k="' + k + '" style="' + (k === 'all' ? 'background:#1d2327;' : '') + 'font-size:13px;padding:4px 10px;">' +
                           (k === 'all' ? 'all links' : LABELS[k]) + ': ' + n + extra + '</span>';
                });
                $('veyra-alt-summary').innerHTML = sum;
                Array.prototype.forEach.call(document.querySelectorAll('.veyra-alt-filter'), function (el) {
                    el.addEventListener('click', function () { filterKind = el.getAttribute('data-k'); render(); });
                });
                drawTable();
            }

            function drawTable() {
                var q = $('veyra-alt-search').value.trim().toLowerCase();
                var list = rows.filter(function (r) {
                    if (filterKind !== 'all' && r.kind !== filterKind) return false;
                    if (!q) return true;
                    return (r.host + ' ' + r.href + ' ' + r.anchor + ' ' + r.title).toLowerCase().indexOf(q) !== -1;
                });
                var thead = $('veyra-alt-table').tHead, tbody = $('veyra-alt-table').tBodies[0], html = '';
                if ($('veyra-alt-group').checked) {
                    thead.innerHTML = '<tr><th>type</th><th>domain</th><th>links</th><th>posts</th><th>example URL</th></tr>';
                    var g = {};
                    list.forEach(function (r) {
                        var key = r.kind + '|' + r.host;
                        if (!g[key]) g[key] = { kind: r.kind, host: r.host, n: 0, posts: {}, href: r.href };
                        g[key].n++; g[key].posts[r.post_id] = 1;
                    });
                    var arr = Object.keys(g).map(function (k) { return g[k]; }).sort(function (a, b) { return b.n - a.n; });
                    arr.forEach(function (x) {
                        html += '<tr><td>' + pill(x.kind) + '</td><td><strong>' + esc(x.host) + '</strong></td><td>' + x.n + '</td><td>' +
                                Object.keys(x.posts).length + '</td><td class="u"><a href="' + esc(x.href) + '" target="_blank" rel="noopener">' + esc(x.href) + '</a></td></tr>';
                    });
                    $('veyra-alt-shown').textContent = arr.length + ' domains shown';
                } else {
                    thead.innerHTML = '<tr><th>type</th><th>domain</th><th>link URL</th><th>anchor text</th><th>rel</th><th>post / page</th><th>status</th></tr>';
                    list.forEach(function (r) {
                        html += '<tr><td>' + pill(r.kind) + '</td><td>' + esc(r.host) + '</td>' +
                                '<td class="u"><a href="' + esc(r.href) + '" target="_blank" rel="noopener">' + esc(r.href) + '</a></td>' +
                                '<td>' + esc(r.anchor) + '</td><td>' + esc(r.rel) + '</td>' +
                                '<td><a href="' + esc(editBase + r.post_id) + '" target="_blank">' + esc(r.title || '(no title)') + '</a> <span style="color:#888;">' + r.type + ' #' + r.post_id + '</span></td>' +
                                '<td>' + esc(r.status) + '</td></tr>';
                    });
                    $('veyra-alt-shown').textContent = list.length + ' links shown';
                }
                tbody.innerHTML = html || '<tr><td colspan="7" style="color:#666;">No links match.</td></tr>';
            }
            $('veyra-alt-search').addEventListener('input', drawTable);
            $('veyra-alt-group').addEventListener('change', drawTable);
        })();
        </script>
    </div>
    <?php
}
