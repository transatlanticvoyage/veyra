<?php
/**
 * Veyra → per-site MEDIA DISPLAY SCHEME (used by the Birch post importer)
 *
 * Each live site keeps its own image display tendencies (size, alignment,
 * link wrapping, first-image override) so imported posts look like they
 * belong to that site rather than to a network.
 *
 * STORAGE: one native wp_options row, `veyra_media_display_scheme` (JSON string,
 * autoload = no). No custom table, no activation hook, no SQL: on a fresh site
 * the first read generates a random scheme and saves it, so every site gets a
 * stable scheme of its own the first time the importer screen (or an import)
 * touches it.
 *
 * Scheme shape (scheme_version 1):
 * {
 *   "scheme_version": 1,
 *   "markup": "wp_native" | "plain",
 *   "image_size": { "<size>": weight, ... },      sizes: thumbnail medium medium_large large full
 *   "size_pick": "per_post" | "per_image",
 *   "alignment": { "<class>": weight, ... },      classes: alignleft alignright aligncenter alignnone
 *   "alignment_pick": "per_post" | "per_image",
 *   "link_to": "none" | "media_file",
 *   "first_image": null | { "size": "<size>", "alignment": "<class>" }
 * }
 * Weights are relative (they need not sum to 100). "per_post" = rolled once per
 * post and reused for every image in it; "per_image" = rolled for each image.
 * first_image (optional) pins the first image of each post; either key may be
 * omitted to fall back to the normal roll.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VEYRA_MEDIA_SCHEME_OPTION = 'veyra_media_display_scheme';

function veyra_media_scheme_sizes() {
    return ['thumbnail', 'medium', 'medium_large', 'large', 'full'];
}

function veyra_media_scheme_alignments() {
    return ['alignleft', 'alignright', 'aligncenter', 'alignnone'];
}

/** Weighted random key from a { key => weight } map. */
function veyra_media_scheme_weighted_pick(array $weights) {
    $total = array_sum($weights);
    if ($total <= 0) {
        return array_key_first($weights);
    }
    $r = mt_rand(1, (int) $total);
    foreach ($weights as $key => $w) {
        $r -= (int) $w;
        if ($r <= 0) {
            return $key;
        }
    }
    return array_key_last($weights);
}

/**
 * Build a random but plausible scheme. Real sites have a strong habit with
 * occasional exceptions, so most schemes lean hard on one dominant choice.
 */
function veyra_media_scheme_generate_random() {
    $pick = function (array $arr) { return $arr[array_rand($arr)]; };

    // Size habit: one dominant size, sometimes a minority second size.
    $size_pool = ['thumbnail' => 1, 'medium' => 5, 'medium_large' => 2, 'large' => 3, 'full' => 2];
    $main_size = veyra_media_scheme_weighted_pick($size_pool);
    $image_size = [$main_size => 100];
    if (mt_rand(1, 100) <= 45) {
        $others = array_values(array_diff(veyra_media_scheme_sizes(), [$main_size]));
        $second = $pick($others);
        $minor  = $pick([10, 15, 20, 25, 30]);
        $image_size = [$main_size => 100 - $minor, $second => $minor];
    }

    // Alignment habit. Big images are rarely floated, so bias toward
    // none/center when the dominant size is large/full.
    $big = in_array($main_size, ['large', 'full', 'medium_large'], true);
    $align_pool = $big
        ? ['alignnone' => 5, 'aligncenter' => 4, 'alignleft' => 1, 'alignright' => 1]
        : ['alignright' => 5, 'alignleft' => 4, 'alignnone' => 2, 'aligncenter' => 2];
    $main_align = veyra_media_scheme_weighted_pick($align_pool);
    $alignment = [$main_align => 100];
    if (mt_rand(1, 100) <= 50) {
        $others = array_values(array_diff(veyra_media_scheme_alignments(), [$main_align]));
        $second = $pick($others);
        $minor  = $pick([10, 20, 25, 30, 40]);
        $alignment = [$main_align => 100 - $minor, $second => $minor];
    }

    $first_image = null;
    if (mt_rand(1, 100) <= 30) {
        $first_image = [
            'size'      => $pick(['large', 'full', 'medium_large']),
            'alignment' => $pick(['alignnone', 'aligncenter']),
        ];
    }

    return [
        'scheme_version' => 1,
        'markup'         => mt_rand(1, 100) <= 80 ? 'wp_native' : 'plain',
        'image_size'     => $image_size,
        'size_pick'      => mt_rand(1, 100) <= 60 ? 'per_post' : 'per_image',
        'alignment'      => $alignment,
        'alignment_pick' => mt_rand(1, 100) <= 55 ? 'per_post' : 'per_image',
        'link_to'        => mt_rand(1, 100) <= 70 ? 'none' : 'media_file',
        'first_image'    => $first_image,
    ];
}

/**
 * Validate + normalise a scheme (array or JSON string).
 * Returns ['scheme' => array] or ['error' => string].
 */
function veyra_media_scheme_validate($input) {
    if (is_string($input)) {
        $decoded = json_decode($input, true);
        if (!is_array($decoded)) {
            return ['error' => 'Scheme is not valid JSON (' . json_last_error_msg() . ').'];
        }
        $input = $decoded;
    }
    if (!is_array($input)) {
        return ['error' => 'Scheme must be a JSON object.'];
    }

    $weights = function ($map, array $allowed, $label) {
        if (!is_array($map) || empty($map)) {
            return ['error' => "\"$label\" must be an object like {\"" . $allowed[0] . "\": 100}."];
        }
        $out = [];
        foreach ($map as $k => $w) {
            if (!in_array($k, $allowed, true)) {
                return ['error' => "\"$label\" has unknown key \"$k\". Allowed: " . implode(', ', $allowed) . '.'];
            }
            if (!is_numeric($w) || $w < 0) {
                return ['error' => "\"$label.$k\" must be a number >= 0."];
            }
            if ((int) $w > 0) {
                $out[$k] = (int) $w;
            }
        }
        if (empty($out)) {
            return ['error' => "\"$label\" needs at least one weight above 0."];
        }
        return ['ok' => $out];
    };

    $sizes  = $weights($input['image_size'] ?? null, veyra_media_scheme_sizes(), 'image_size');
    if (isset($sizes['error'])) return $sizes;
    $aligns = $weights($input['alignment'] ?? null, veyra_media_scheme_alignments(), 'alignment');
    if (isset($aligns['error'])) return $aligns;

    $enum = function ($val, array $allowed, $default, $label) {
        if ($val === null) return ['ok' => $default];
        if (!in_array($val, $allowed, true)) {
            return ['error' => "\"$label\" must be one of: " . implode(', ', $allowed) . '.'];
        }
        return ['ok' => $val];
    };
    $markup = $enum($input['markup'] ?? null, ['wp_native', 'plain'], 'wp_native', 'markup');
    if (isset($markup['error'])) return $markup;
    $size_pick = $enum($input['size_pick'] ?? null, ['per_post', 'per_image'], 'per_post', 'size_pick');
    if (isset($size_pick['error'])) return $size_pick;
    $align_pick = $enum($input['alignment_pick'] ?? null, ['per_post', 'per_image'], 'per_post', 'alignment_pick');
    if (isset($align_pick['error'])) return $align_pick;
    $link_to = $enum($input['link_to'] ?? null, ['none', 'media_file'], 'none', 'link_to');
    if (isset($link_to['error'])) return $link_to;

    $first = null;
    if (isset($input['first_image']) && $input['first_image'] !== null) {
        if (!is_array($input['first_image'])) {
            return ['error' => '"first_image" must be null or an object like {"size": "large", "alignment": "alignnone"}.'];
        }
        $first = [];
        if (isset($input['first_image']['size'])) {
            if (!in_array($input['first_image']['size'], veyra_media_scheme_sizes(), true)) {
                return ['error' => '"first_image.size" must be one of: ' . implode(', ', veyra_media_scheme_sizes()) . '.'];
            }
            $first['size'] = $input['first_image']['size'];
        }
        if (isset($input['first_image']['alignment'])) {
            if (!in_array($input['first_image']['alignment'], veyra_media_scheme_alignments(), true)) {
                return ['error' => '"first_image.alignment" must be one of: ' . implode(', ', veyra_media_scheme_alignments()) . '.'];
            }
            $first['alignment'] = $input['first_image']['alignment'];
        }
        if (empty($first)) $first = null;
    }

    return ['scheme' => [
        'scheme_version' => 1,
        'markup'         => $markup['ok'],
        'image_size'     => $sizes['ok'],
        'size_pick'      => $size_pick['ok'],
        'alignment'      => $aligns['ok'],
        'alignment_pick' => $align_pick['ok'],
        'link_to'        => $link_to['ok'],
        'first_image'    => $first,
    ]];
}

function veyra_media_scheme_to_json(array $scheme) {
    return wp_json_encode($scheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * The site's saved scheme. If none is stored yet (or the stored value is
 * corrupt), a random one is generated AND saved, so the site's scheme is
 * stable from its first use onward.
 */
function veyra_media_scheme_get_saved() {
    $raw = get_option(VEYRA_MEDIA_SCHEME_OPTION, '');
    if (is_string($raw) && $raw !== '') {
        $v = veyra_media_scheme_validate($raw);
        if (isset($v['scheme'])) {
            return $v['scheme'];
        }
    }
    $scheme = veyra_media_scheme_generate_random();
    veyra_media_scheme_save($scheme);
    return $scheme;
}

function veyra_media_scheme_save(array $scheme) {
    $json = wp_json_encode($scheme, JSON_UNESCAPED_SLASHES);
    // add_option first so we control autoload on a fresh site; update_option
    // returns false when the value is unchanged, which is fine.
    if (get_option(VEYRA_MEDIA_SCHEME_OPTION, null) === null) {
        add_option(VEYRA_MEDIA_SCHEME_OPTION, $json, '', 'no');
    } else {
        update_option(VEYRA_MEDIA_SCHEME_OPTION, $json, false);
    }
}

/** One-line plain-English summary of a scheme, shown under the text box. */
function veyra_media_scheme_describe(array $s) {
    $pct = function (array $map) {
        $total = array_sum($map);
        $parts = [];
        arsort($map);
        foreach ($map as $k => $w) {
            $parts[] = $k . ' ' . round($w * 100 / max(1, $total)) . '%';
        }
        return implode(' / ', $parts);
    };
    $out = 'Size: ' . $pct($s['image_size']) . ' (' . str_replace('_', ' ', $s['size_pick']) . ')'
         . ' · Align: ' . $pct($s['alignment']) . ' (' . str_replace('_', ' ', $s['alignment_pick']) . ')'
         . ' · Markup: ' . $s['markup']
         . ' · Link: ' . $s['link_to'];
    if (!empty($s['first_image'])) {
        $out .= ' · First image: ' . trim(($s['first_image']['size'] ?? '') . ' ' . ($s['first_image']['alignment'] ?? ''));
    }
    return $out;
}

/**
 * Per-post rendering context. Created once per post by the importer; rolls the
 * per_post choices up front and counts images so first_image can be applied.
 */
function veyra_media_scheme_make_post_context(array $scheme) {
    $ctx = new stdClass();
    $ctx->scheme      = $scheme;
    $ctx->image_index = 0;
    $ctx->post_size   = veyra_media_scheme_weighted_pick($scheme['image_size']);
    $ctx->post_align  = veyra_media_scheme_weighted_pick($scheme['alignment']);
    return $ctx;
}

/**
 * Emit one <img> (optionally wrapped in a link) per the scheme context.
 * $asset shape: ['id' => int|null, 'url' => string, 'alt' => string]
 */
function veyra_media_scheme_format_image($asset, $ctx) {
    $s = $ctx->scheme;
    $is_first = ($ctx->image_index === 0);
    $ctx->image_index++;

    $size  = $s['size_pick'] === 'per_image' ? veyra_media_scheme_weighted_pick($s['image_size']) : $ctx->post_size;
    $align = $s['alignment_pick'] === 'per_image' ? veyra_media_scheme_weighted_pick($s['alignment']) : $ctx->post_align;
    if ($is_first && !empty($s['first_image'])) {
        if (!empty($s['first_image']['size']))      $size  = $s['first_image']['size'];
        if (!empty($s['first_image']['alignment'])) $align = $s['first_image']['alignment'];
    }

    $id  = isset($asset['id']) ? (int) $asset['id'] : 0;
    $url = isset($asset['url']) ? $asset['url'] : '';
    $alt = isset($asset['alt']) ? $asset['alt'] : '';
    $html = '';

    if ($id && $s['markup'] === 'wp_native' && function_exists('wp_get_attachment_image')) {
        // Same classes the media-library popup inserts: {align} size-{size} wp-image-{ID}
        $html = wp_get_attachment_image($id, $size, false, [
            'class' => $align . ' size-' . $size . ' wp-image-' . $id,
        ]);
    }
    if ($html === '' && $id && function_exists('wp_get_attachment_image_src')) {
        // "plain": hand-rolled <img> pointing at the chosen size's file
        $src = wp_get_attachment_image_src($id, $size);
        if ($src) {
            $html = '<img src="' . esc_url($src[0]) . '" alt="' . esc_attr($alt) . '" width="' . (int) $src[1]
                  . '" height="' . (int) $src[2] . '" class="' . esc_attr($align) . '" />';
        }
    }
    if ($html === '') {
        // No attachment record: raw full-size URL with the alignment class
        $html = '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" class="' . esc_attr($align) . '" />';
    }

    if ($s['link_to'] === 'media_file') {
        $full = $id ? wp_get_attachment_url($id) : $url;
        if ($full) {
            $html = '<a href="' . esc_url($full) . '">' . $html . '</a>';
        }
    }
    return $html;
}

// ---------------------------------------------------------------------------
// AJAX: generate a random scheme (not saved) / save a scheme
// ---------------------------------------------------------------------------
add_action('wp_ajax_veyra_media_scheme_generate', 'veyra_media_scheme_ajax_generate');
function veyra_media_scheme_ajax_generate() {
    check_ajax_referer('veyra_media_scheme', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permission denied.'], 403);
    }
    $scheme = veyra_media_scheme_generate_random();
    wp_send_json_success([
        'json'    => veyra_media_scheme_to_json($scheme),
        'summary' => veyra_media_scheme_describe($scheme),
    ]);
}

add_action('wp_ajax_veyra_media_scheme_save', 'veyra_media_scheme_ajax_save');
function veyra_media_scheme_ajax_save() {
    check_ajax_referer('veyra_media_scheme', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Only administrators can change the site scheme.'], 403);
    }
    $v = veyra_media_scheme_validate(wp_unslash($_POST['scheme'] ?? ''));
    if (isset($v['error'])) {
        wp_send_json_error(['message' => $v['error']]);
    }
    veyra_media_scheme_save($v['scheme']);
    wp_send_json_success([
        'json'    => veyra_media_scheme_to_json($v['scheme']),
        'summary' => veyra_media_scheme_describe($v['scheme']),
    ]);
}
