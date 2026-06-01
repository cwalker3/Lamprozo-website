<?php
/**
 * Attempts - Plugin Model
 * Manages Nuzlocke attempt data stored in wp_options as JSON.
 * Option key: lamprozo_attempts_{challenge_slug}
 */
if (!defined('ABSPATH')) { exit; }

// ── YouTube helpers ────────────────────────────────────────────────────────

function lamprozo_yt_id($url) {
    preg_match('/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([a-zA-Z0-9_-]{11})/', $url, $m);
    return $m[1] ?? null;
}

function lamprozo_yt_title($url) {
    $yt_id = lamprozo_yt_id($url);
    if (!$yt_id) return null;

    $cache_key = 'lamprozo_yt_title_' . $yt_id;
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $response = wp_remote_get('https://www.youtube.com/oembed?url=' . urlencode($url) . '&format=json', ['timeout' => 5]);
    if (is_wp_error($response)) return null;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $title = $data['title'] ?? null;
    if ($title) set_transient($cache_key, $title, WEEK_IN_SECONDS);
    return $title;
}

function lamprozo_yt_duration($url) {
    $yt_id = lamprozo_yt_id($url);
    if (!$yt_id) return null;

    $api_key = defined('LAMPROZO_YOUTUBE_API_KEY') ? LAMPROZO_YOUTUBE_API_KEY : null;
    if (!$api_key) return null;

    $cache_key = 'lamprozo_yt_dur_' . $yt_id;
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $api_url = 'https://www.googleapis.com/youtube/v3/videos?part=contentDetails&id=' . $yt_id . '&key=' . $api_key;
    $response = wp_remote_get($api_url, ['timeout' => 5]);
    if (is_wp_error($response)) return null;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $iso = $data['items'][0]['contentDetails']['duration'] ?? null;
    if (!$iso) return null;

    // Parse ISO 8601 duration (PT1H23M45S)
    preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $parts);
    $h = (int)($parts[1] ?? 0);
    $m = (int)($parts[2] ?? 0);
    $s = (int)($parts[3] ?? 0);
    $duration = $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);

    set_transient($cache_key, $duration, WEEK_IN_SECONDS);
    return $duration;
}

add_action('rest_api_init', function() {
    register_rest_route('lamprozo/v1', '/yt-title', [
        'methods'             => 'GET',
        'callback'            => function($r) {
            $url   = $r->get_param('url');
            $title = $url ? lamprozo_yt_title($url) : null;
            return rest_ensure_response(['title' => $title]);
        },
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
    register_rest_route('lamprozo/v1', '/yt-meta', [
        'methods'             => 'GET',
        'callback'            => function($r) {
            $url      = $r->get_param('url');
            $title    = $url ? lamprozo_yt_title($url) : null;
            $duration = $url ? lamprozo_yt_duration($url) : null;
            return rest_ensure_response(['title' => $title, 'duration' => $duration]);
        },
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
});

// ── Admin menu ──────────────────────────────────────────────────────────────

add_action('admin_menu', function() {
    add_menu_page(
        'Lamprozo',
        'Lamprozo',
        'manage_options',
        'lamprozo-challenges',
        'ffc_challenges_dashboard',
        'dashicons-shield'
    );
    add_submenu_page(
        'lamprozo-challenges',
        'Challenges',
        'Challenges',
        'manage_options',
        'lamprozo-challenges',
        'ffc_challenges_dashboard'
    );
    add_submenu_page(
        'lamprozo-challenges',
        'Attempts',
        'Attempts',
        'manage_options',
        'lamprozo-attempts',
        'ffc_attempts_dashboard'
    );
});

function ffc_challenges_dashboard() {
    $view_path = dirname(__FILE__, 2) . '/views/challenges.php';
    if (file_exists($view_path)) include $view_path;
}

function ffc_attempts_dashboard() {
    $view_path = dirname(__FILE__, 2) . '/views/attempts.php';
    if (file_exists($view_path)) include $view_path;
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function lamprozo_get_attempts($challenge) {
    $key  = 'lamprozo_attempts_' . sanitize_key($challenge);
    $data = get_option($key, null);

    $seeded = false;
    if ($data === null) {
        // Seed from PHP data file on first load
        $data_file = get_template_directory() . '/templates/lamprozo/data/' . sanitize_key($challenge) . '.php';
        if (file_exists($data_file)) {
            require $data_file;   // provides $attempts
            $data   = $attempts;
            $seeded = true;
        } else {
            return [];
        }
    }

    // Migrate legacy HZLA `fragsheet` data into the universal `box` model.
    $dirty = false;
    foreach ($data as &$attempt) {
        if (!isset($attempt['box']) && !empty($attempt['fragsheet'])) {
            $attempt['box'] = lamprozo_box_from_fragsheet($attempt['fragsheet']);
            $dirty = true;
        }
    }
    unset($attempt);

    if ($seeded || $dirty) {
        update_option($key, $data);
    }

    return $data;
}

function lamprozo_save_attempts($challenge, $attempts) {
    update_option('lamprozo_attempts_' . sanitize_key($challenge), $attempts);
}

/**
 * Convert a legacy HZLA fragsheet (species-keyed object) into a flat box array.
 * Filters HZLA "shadow prevo" artifacts (mirrored + zero-stat stub placeholders
 * left behind on evolution) and merges their kill counts into the evolved form.
 * Ported from the public _attempt-card.php renderer so the box is clean at rest.
 */
function lamprozo_box_from_fragsheet($fragsheet) {
    if (!is_array($fragsheet) || empty($fragsheet)) {
        return [];
    }

    $shadow_prevos = [];
    foreach ($fragsheet as $species => $mon) {
        $frag    = (int) ($mon['fragCount']      ?? 0);
        $prevo   = (int) ($mon['prevoFragCount'] ?? 0);
        $batchId = $mon['setData']['My Box']['boxImportBatchId'] ?? '';
        if ($prevo > 0 && $prevo > $frag) {
            $shadow_prevos[$species] = true;
        } elseif ($frag === 0 && $prevo === 0 && $batchId === '') {
            $shadow_prevos[$species] = true;
        }
    }

    $box = [];
    foreach ($fragsheet as $species => $mon) {
        if ($mon['hide'] ?? false) {
            continue;
        }
        if (isset($shadow_prevos[$species])) {
            continue;
        }
        $entry = [
            'species'  => $species,
            'nickname' => $mon['nn'] ?? '',
            'alive'    => (bool) ($mon['alive'] ?? false),
            'kills'    => (int) ($mon['fragCount'] ?? 0) + (int) ($mon['prevoFragCount'] ?? 0),
        ];
        $met     = $mon['setData']['My Box']['met']     ?? '';
        $nature  = $mon['setData']['My Box']['nature']  ?? '';
        $ability = $mon['setData']['My Box']['ability'] ?? '';
        $ivs     = $mon['setData']['My Box']['ivs']     ?? [];
        if ($met)          { $entry['met']     = $met; }
        if ($nature)       { $entry['nature']  = $nature; }
        if ($ability)      { $entry['ability'] = $ability; }
        if (!empty($ivs))  { $entry['ivs']     = $ivs; }
        $box[] = $entry;
    }

    usort($box, fn($a, $b) => ($b['kills'] ?? 0) - ($a['kills'] ?? 0));
    return $box;
}

/**
 * Count of dead box members in an attempt. Falls back to deriving the box from a
 * legacy fragsheet if `box` hasn't been materialized yet.
 */
function lamprozo_attempt_deaths($attempt) {
    // Manual override wins (for games where the box isn't tracked).
    if (isset($attempt['deaths']) && $attempt['deaths'] !== '' && $attempt['deaths'] !== null) {
        return (int) $attempt['deaths'];
    }
    $box = $attempt['box'] ?? [];
    if (empty($box) && !empty($attempt['fragsheet'])) {
        $box = lamprozo_box_from_fragsheet($attempt['fragsheet']);
    }
    $dead = 0;
    foreach ($box as $mon) {
        if (($mon['alive'] ?? true) === false) {
            $dead++;
        }
    }
    return $dead;
}

/**
 * Parse a Pokémon Showdown export (the format the in-game sync server returns)
 * into a list of mons. PHP mirror of the admin Box editor's JS parser.
 */
function lamprozo_parse_showdown($text) {
    $mons = [];
    foreach (preg_split('/\n\s*\n/', (string) $text) as $block) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), 'strlen'));
        if (empty($lines)) {
            continue;
        }
        $first = $lines[0];
        $head  = trim(explode(' @ ', $first)[0]);                 // drop held item
        $head  = trim(preg_replace('/\s*\((?:M|F)\)\s*$/i', '', $head)); // drop gender
        $species = $head;
        $nickname = '';
        if (preg_match('/^(.*?)\s*\((.+)\)\s*$/', $head, $m)) {
            $nickname = trim($m[1]);
            $species  = trim($m[2]);
        }
        if ($species === '') {
            continue;
        }
        $mon = ['species' => $species, 'nickname' => $nickname];
        foreach (array_slice($lines, 1) as $l) {
            if (preg_match('/^Level:\s*(\d+)/i', $l, $mm))      { $mon['level']   = (int) $mm[1]; }
            elseif (preg_match('/^Ability:\s*(.+)$/i', $l, $mm)) { $mon['ability'] = trim($mm[1]); }
            elseif (preg_match('/^(.+?)\s+Nature$/i', $l, $mm))  { $mon['nature']  = trim($mm[1]); }
        }
        $mons[] = $mon;
    }
    return $mons;
}

/**
 * Merge parsed mons into an existing box (add-only, preserving alive/dead/kills
 * on entries already present). Match is by species + nickname, case-insensitive.
 */
function lamprozo_merge_into_box($box, $mons) {
    if (!is_array($box)) {
        $box = [];
    }
    foreach ($mons as $p) {
        $sp = strtolower($p['species'] ?? '');
        $nk = strtolower($p['nickname'] ?? '');
        if ($sp === '') {
            continue;
        }
        $exists = false;
        foreach ($box as $e) {
            if (strtolower($e['species'] ?? '') === $sp && strtolower($e['nickname'] ?? '') === $nk) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $box[] = ['species' => $p['species'], 'nickname' => $p['nickname'] ?? '', 'alive' => true, 'kills' => 0];
        }
    }
    return $box;
}

/**
 * Resolve a species name to its National Dex number via PokéAPI, cached forever
 * per species (so it's fetched at most once each). Returns null if unknown, so
 * sprite rendering can fall back to a name-based source.
 */
function lamprozo_dex_number($species) {
    $name = strtolower(trim((string) $species));
    $name = str_replace(['♀', '♂'], ['-f', '-m'], $name);
    $name = str_replace([' ', '.', "'", ':'], ['-', '', '', ''], $name);
    $name = preg_replace('/[^a-z0-9-]/', '', $name);
    $name = trim(preg_replace('/-+/', '-', $name), '-');
    if ($name === '') {
        return null;
    }

    $key    = 'lamprozo_dex_' . $name;
    $cached = get_transient($key);
    if ($cached !== false) {
        return $cached === 'none' ? null : (int) $cached;
    }

    $resp = wp_remote_get('https://pokeapi.co/api/v2/pokemon/' . rawurlencode($name), ['timeout' => 3]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        set_transient($key, 'none', HOUR_IN_SECONDS); // unknown for now; retry in an hour
        return null;
    }
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $id   = isset($data['id']) ? (int) $data['id'] : null;
    set_transient($key, $id === null ? 'none' : $id, $id === null ? HOUR_IN_SECONDS : YEAR_IN_SECONDS);
    return $id;
}

function lamprozo_default_challenges() {
    return [
        'sterling-silver' => [
            'slug'        => 'sterling-silver',
            'title'       => 'Sterling Silver',
            'game'        => 'Pokémon Sterling Silver',
            'type'        => 'ROM Hack',
            'gen'         => 'IV',
            'description' => 'A hardcore Nuzlocke of the Pokémon SoulSilver ROM hack Sterling Silver.',
            'ruleset'     => 'Hardcore Nuzlocke',
            'theme'       => 'silver',
            'status'      => 'active',
        ],
        'renegade-platinum' => [
            'slug'        => 'renegade-platinum',
            'title'       => 'Renegade Platinum',
            'game'        => 'Pokémon Renegade Platinum',
            'type'        => 'ROM Hack',
            'gen'         => 'IV',
            'description' => 'A hardcore Nuzlocke of the Pokémon Platinum ROM hack Renegade Platinum.',
            'ruleset'     => 'Hardcore Nuzlocke',
            'theme'       => 'platinum',
            'status'      => 'on_hold',
        ],
        'platinum-kaizo' => [
            'slug'        => 'platinum-kaizo',
            'title'       => 'Platinum Kaizo',
            'game'        => 'Pokémon Platinum Kaizo',
            'type'        => 'ROM Hack',
            'gen'         => 'IV',
            'description' => 'A hardcore Nuzlocke of the Pokémon Platinum ROM hack Platinum Kaizo.',
            'ruleset'     => 'Hardcore Nuzlocke',
            'theme'       => 'platinum',
            'status'      => 'on_hold',
        ],
    ];
}

function lamprozo_get_challenges_data() {
    $stored = get_option('lamprozo_challenges', null);
    if ($stored === null) {
        $stored = lamprozo_default_challenges();
        update_option('lamprozo_challenges', $stored);
        return $stored;
    }
    // Migrate: add status / ruleset fields if missing
    $defaults = lamprozo_default_challenges();
    $updated  = false;
    foreach ($stored as $slug => &$challenge) {
        if (!isset($challenge['status'])) {
            $challenge['status'] = $defaults[$slug]['status'] ?? 'on_hold';
            $updated = true;
        }
        if (!isset($challenge['ruleset'])) {
            $challenge['ruleset'] = $defaults[$slug]['ruleset'] ?? '';
            $updated = true;
        }
        if (!isset($challenge['theme'])) {
            $challenge['theme'] = $defaults[$slug]['theme'] ?? 'emerald';
            $updated = true;
        }
        if (!isset($challenge['badgeset'])) {
            $challenge['badgeset'] = $defaults[$slug]['badgeset'] ?? 'none';
            $updated = true;
        }
    }
    unset($challenge);
    if ($updated) update_option('lamprozo_challenges', $stored);
    return $stored;
}

function lamprozo_get_challenges() {
    $data = lamprozo_get_challenges_data();
    return array_map(fn($c) => $c['title'], $data);
}

// ── REST API ─────────────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    register_rest_route('lamprozo/v1', '/challenges', [
        'methods'             => 'GET',
        'callback'            => fn() => rest_ensure_response(array_values(lamprozo_get_challenges_data())),
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
    register_rest_route('lamprozo/v1', '/challenges', [
        'methods'             => 'POST',
        'callback'            => 'lamprozo_rest_create_challenge',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
    register_rest_route('lamprozo/v1', '/challenges/(?P<slug>[a-z0-9-]+)', [
        'methods'             => 'PUT',
        'callback'            => 'lamprozo_rest_update_challenge',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
    register_rest_route('lamprozo/v1', '/challenges/(?P<slug>[a-z0-9-]+)', [
        'methods'             => 'DELETE',
        'callback'            => 'lamprozo_rest_delete_challenge',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);

    register_rest_route('lamprozo/v1', '/attempts/(?P<challenge>[a-z0-9-]+)', [
        'methods'             => 'GET',
        'callback'            => 'lamprozo_rest_get_attempts',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
    register_rest_route('lamprozo/v1', '/attempts/(?P<challenge>[a-z0-9-]+)', [
        'methods'             => 'POST',
        'callback'            => 'lamprozo_rest_save_attempts',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
    register_rest_route('lamprozo/v1', '/attempts/(?P<challenge>[a-z0-9-]+)/new', [
        'methods'             => 'POST',
        'callback'            => 'lamprozo_rest_new_attempt',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
});

function lamprozo_rest_get_attempts($request) {
    $challenge = $request['challenge'];
    return rest_ensure_response(lamprozo_get_attempts($challenge));
}

function lamprozo_rest_save_attempts($request) {
    $challenge = $request['challenge'];
    $attempts  = $request->get_json_params();
    if (!is_array($attempts)) {
        return new WP_Error('invalid_data', 'Expected array of attempts', ['status' => 400]);
    }
    lamprozo_save_attempts($challenge, $attempts);
    return rest_ensure_response(['success' => true]);
}

function lamprozo_rest_create_challenge($request) {
    $body  = $request->get_json_params();
    $slug  = sanitize_title($body['slug'] ?? '');
    $title = sanitize_text_field($body['title'] ?? '');
    if (!$slug || !$title) {
        return new WP_Error('missing_fields', 'slug and title are required', ['status' => 400]);
    }
    $challenges = lamprozo_get_challenges_data();
    if (isset($challenges[$slug])) {
        return new WP_Error('exists', 'Challenge already exists', ['status' => 409]);
    }
    $challenges[$slug] = [
        'slug'        => $slug,
        'title'       => $title,
        'game'        => sanitize_text_field($body['game'] ?? $title),
        'type'        => sanitize_text_field($body['type'] ?? 'ROM Hack'),
        'gen'         => sanitize_text_field($body['gen'] ?? ''),
        'description' => sanitize_textarea_field($body['description'] ?? ''),
        'ruleset'     => sanitize_text_field($body['ruleset'] ?? ''),
        'theme'       => sanitize_key($body['theme'] ?? 'emerald'),
        'badgeset'    => sanitize_key($body['badgeset'] ?? 'none'),
    ];
    update_option('lamprozo_challenges', $challenges);

    // Create WordPress page
    $page_id = wp_insert_post([
        'post_title'  => $title,
        'post_name'   => $slug,
        'post_status' => 'publish',
        'post_type'   => 'page',
        'meta_input'  => ['_firefly_template' => 'lamprozo'],
    ]);

    return rest_ensure_response(['success' => true, 'slug' => $slug, 'page_id' => $page_id]);
}

function lamprozo_rest_update_challenge($request) {
    $slug = $request['slug'];
    $body = $request->get_json_params();
    $challenges = lamprozo_get_challenges_data();
    if (!isset($challenges[$slug])) {
        return new WP_Error('not_found', 'Challenge not found', ['status' => 404]);
    }
    foreach (['title', 'game', 'type', 'gen', 'description', 'status', 'ruleset', 'theme', 'badgeset'] as $field) {
        if (isset($body[$field])) {
            $challenges[$slug][$field] = sanitize_text_field($body[$field]);
        }
    }
    update_option('lamprozo_challenges', $challenges);
    return rest_ensure_response(['success' => true]);
}

function lamprozo_rest_delete_challenge($request) {
    $slug = $request['slug'];
    $protected = ['sterling-silver', 'renegade-platinum', 'platinum-kaizo'];
    if (in_array($slug, $protected)) {
        return new WP_Error('protected', 'Cannot delete built-in challenges', ['status' => 403]);
    }
    $challenges = lamprozo_get_challenges_data();
    if (!isset($challenges[$slug])) {
        return new WP_Error('not_found', 'Challenge not found', ['status' => 404]);
    }
    unset($challenges[$slug]);
    update_option('lamprozo_challenges', $challenges);
    return rest_ensure_response(['success' => true]);
}

function lamprozo_rest_new_attempt($request) {
    $challenge = $request['challenge'];
    $attempts  = lamprozo_get_attempts($challenge);

    // Mark current ongoing as wiped or completed
    $previous_status = in_array($request->get_param('previous_status'), ['failed', 'completed'])
        ? $request->get_param('previous_status')
        : 'failed';
    foreach ($attempts as &$attempt) {
        if ($attempt['status'] === 'ongoing') {
            $attempt['status'] = $previous_status;
        }
    }
    unset($attempt);

    // Get next number
    $next = empty($attempts) ? 1 : (max(array_column($attempts, 'number')) + 1);

    // Carry level cap / badges forward from the most recent attempt for convenience.
    $prev = $attempts[0] ?? [];

    array_unshift($attempts, [
        'number' => $next,
        'status' => 'ongoing',
        'cap'    => $prev['cap'] ?? '',
        'badges' => $prev['badges'] ?? '',
        'vods'   => [],
        'box'    => [],
    ]);

    lamprozo_save_attempts($challenge, $attempts);
    return rest_ensure_response(['success' => true, 'number' => $next]);
}
