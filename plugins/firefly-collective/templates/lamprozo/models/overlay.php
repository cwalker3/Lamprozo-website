<?php
/**
 * Overlay - Plugin Model
 *
 * Serves a public, bare-HTML Nuzlocke status overlay for use as an OBS Browser
 * Source. All values are DERIVED LIVE from the active challenge and its ongoing
 * attempt (see lamprozo_overlay_resolve) — nothing is entered by hand here.
 *
 * Public pages: /?lamprozo_overlay=1   (status overlay; append &vertical=true)
 *               /?lamprozo_layout=1    (full GBA stream layout: framed game +
 *                                       webcam holes, embedded Twitch chat, and
 *                                       the status bar under the game screen)
 * REST        : GET lamprozo/v1/overlay  (public - so OBS can read it)
 */
if (!defined('ABSPATH')) { exit; }

// ── Resolution ──────────────────────────────────────────────────────────────

/**
 * Resolve the live overlay values from the active challenge + ongoing attempt.
 * Active challenge   = first challenge with status 'active'.
 * Ongoing attempt    = first attempt with status 'ongoing' (else highest number).
 * Returns blanks when there's no active challenge.
 */
function lamprozo_overlay_resolve() {
    $blank = ['game' => '', 'ruleset' => '', 'attempt' => '', 'cap' => '', 'deaths' => '', 'badges' => '', 'badgeset' => 'none', 'theme' => 'emerald', 'party' => array_slice((array) get_option('lamprozo_party', []), 0, 6)];

    if (!function_exists('lamprozo_get_challenges_data')) {
        return $blank;
    }

    $challenges   = lamprozo_get_challenges_data();
    $active = $active_slug = null;
    foreach ($challenges as $slug => $challenge) {
        if (($challenge['status'] ?? '') === 'active') {
            $active      = $challenge;
            $active_slug = $slug;
            break;
        }
    }
    if (!$active) {
        return $blank;
    }

    $attempts = lamprozo_get_attempts($active_slug);
    $ongoing  = null;
    foreach ($attempts as $attempt) {
        if (($attempt['status'] ?? '') === 'ongoing') { $ongoing = $attempt; break; }
    }
    if (!$ongoing && !empty($attempts)) {
        // Fall back to the highest-numbered attempt.
        usort($attempts, fn($a, $b) => ($b['number'] ?? 0) - ($a['number'] ?? 0));
        $ongoing = $attempts[0];
    }

    return [
        'game'    => $active['title']   ?? '',
        'ruleset' => $active['ruleset'] ?? '',
        'attempt' => $ongoing ? (string) ($ongoing['number'] ?? '') : '',
        'cap'     => $ongoing['cap']    ?? '',
        'deaths'  => $ongoing ? (string) lamprozo_attempt_deaths($ongoing) : '0',
        'badges'  => $ongoing['badges'] ?? '',
        'badgeset'=> $active['badgeset'] ?? 'none',
        'theme'   => $active['theme']   ?? 'emerald',
        'party'   => array_slice((array) get_option('lamprozo_party', []), 0, 6),
    ];
}

// ── REST API ──────────────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    // Public read: OBS browser source is NOT logged in, so this must be open.
    // Live endpoint — mark uncacheable so a CDN/proxy never serves stale stats.
    register_rest_route('lamprozo/v1', '/overlay', [
        'methods'             => 'GET',
        'callback'            => function() {
            $response = rest_ensure_response(lamprozo_overlay_resolve());
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
            return $response;
        },
        'permission_callback' => '__return_true',
    ]);

    // Party push from the local game-sync bridge (POST Showdown text). Authed by a
    // shared key so it can be called from outside WP (the bridge isn't logged in).
    register_rest_route('lamprozo/v1', '/party', [
        'methods'             => 'POST',
        'callback'            => 'lamprozo_rest_set_party',
        'permission_callback' => function($request) {
            $expected = defined('LAMPROZO_PARTY_KEY') ? LAMPROZO_PARTY_KEY : 'firefly-cli-dev-key';
            $given    = (string) $request->get_header('x-lamprozo-key');
            return (is_string($given) && hash_equals($expected, $given)) || current_user_can('manage_options');
        },
    ]);
});

/**
 * Store the live party (for the overlay) and merge any new mons into the active
 * challenge's ongoing attempt box (you set alive/dead in the admin afterwards).
 * Accepts a raw Showdown-export body, or JSON {"showdown": "..."}.
 */
function lamprozo_rest_set_party($request) {
    if (!function_exists('lamprozo_parse_showdown')) {
        return new WP_Error('unavailable', 'Parser unavailable', ['status' => 500]);
    }
    $body = $request->get_body();
    $text = $body;
    $json = json_decode($body, true);
    if (is_array($json) && isset($json['showdown'])) {
        $text = $json['showdown'];
    }

    $mons = lamprozo_parse_showdown($text);
    update_option('lamprozo_party', $mons, false);

    // Merge into the active challenge's ongoing attempt box (add-only).
    $merged_into = null;
    $added = 0;
    if (function_exists('lamprozo_get_challenges_data')) {
        foreach (lamprozo_get_challenges_data() as $slug => $c) {
            if (($c['status'] ?? '') !== 'active') {
                continue;
            }
            $attempts = lamprozo_get_attempts($slug);
            foreach ($attempts as $i => $a) {
                if (($a['status'] ?? '') === 'ongoing') {
                    $before = $a['box'] ?? [];
                    $after  = lamprozo_merge_into_box($before, $mons);
                    if (count($after) !== count($before)) {
                        $attempts[$i]['box'] = $after;
                        lamprozo_save_attempts($slug, $attempts);
                        $merged_into = $slug;
                        $added = count($after) - count($before);
                    }
                    break;
                }
            }
            break;
        }
    }

    return rest_ensure_response([
        'success'     => true,
        'party'       => count($mons),
        'box_added'   => $added,
        'merged_into' => $merged_into,
    ]);
}

// ── Instant updates via Server-Sent Events ──────────────────────────────────────

/**
 * Bump a revision marker whenever overlay-relevant data changes, so an open SSE
 * stream can detect the change and push immediately. Hooked on option writes so
 * every path (admin save, new attempt, challenge create/update/delete) is caught.
 */
function lamprozo_overlay_bump_rev() {
    update_option('lamprozo_overlay_rev', (string) microtime(true), false);
}
function lamprozo_overlay_maybe_bump_rev($option) {
    if ($option === 'lamprozo_challenges' || $option === 'lamprozo_party' || strpos($option, 'lamprozo_attempts_') === 0) {
        lamprozo_overlay_bump_rev();
    }
}
add_action('updated_option', 'lamprozo_overlay_maybe_bump_rev');
add_action('added_option',   'lamprozo_overlay_maybe_bump_rev');

add_action('template_redirect', function() {
    if (!isset($_GET['lamprozo_overlay_sse'])) { return; }
    lamprozo_stream_overlay_sse();
    exit;
});

/**
 * Server-Sent Events stream of the resolved overlay state. Holds one connection
 * open, pushing a fresh payload the instant the revision marker changes. Recycles
 * every ~45s so the browser's EventSource reconnects cleanly.
 */
function lamprozo_stream_overlay_sse() {
    global $wpdb;
    @set_time_limit(0);
    ignore_user_abort(true);
    while (ob_get_level() > 0) { @ob_end_flush(); }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no'); // disable proxy buffering (nginx)

    $read_rev = function() use ($wpdb) {
        return (string) $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'lamprozo_overlay_rev')
        );
    };
    $send = function() {
        wp_cache_delete('alloptions', 'options'); // force fresh autoloaded option reads
        echo 'data: ' . wp_json_encode(lamprozo_overlay_resolve()) . "\n\n";
        @ob_flush(); @flush();
    };

    $last = $read_rev();
    $send();

    $start = time();
    while (!connection_aborted() && (time() - $start) < 45) {
        usleep(800000); // 0.8s
        $rev = $read_rev();
        if ($rev !== $last) { $last = $rev; $send(); }
        else { echo ": ping\n\n"; @ob_flush(); @flush(); } // heartbeat
    }
}

// ── Public overlay page (OBS Browser Source) ───────────────────────────────────

add_action('template_redirect', function() {
    if (!isset($_GET['lamprozo_overlay'])) { return; }
    lamprozo_render_overlay_page();
    exit;
});

function lamprozo_render_overlay_page() {
    $overlay   = lamprozo_overlay_resolve();
    $rest_url  = esc_url_raw(rest_url('lamprozo/v1/overlay'));
    $vertical  = (isset($_GET['vertical']) && $_GET['vertical'] === 'true');
    // Poll interval (ms); override with &interval=10 for 10s.
    $interval  = isset($_GET['interval']) ? max(1, (int) $_GET['interval']) * 1000 : 5000;

    header('Content-Type: text/html; charset=utf-8');
    nocache_headers(); // Live overlay — never let a CDN/proxy serve a stale copy.
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Nuzlocke Status Overlay</title>
  <style>
    :root {
      --bg: rgba(12, 16, 28, 0.88);
      --panel-bg: rgba(24, 30, 50, 0.92);
      --border: rgba(255, 255, 255, 0.18);
      --text: #f7f7fb;
      --muted: #b9bfd6;
      --accent: #7dd3fc;
      --danger: #fb7185;
      --gold: #facc15;
      --shadow: rgba(0, 0, 0, 0.35);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; width: 100vw; height: 100vh; background: transparent;
      font-family: "Inter", "Segoe UI", Arial, sans-serif; color: var(--text);
      overflow: hidden;
    }
    .overlay {
      display: inline-flex; align-items: center; gap: 12px; padding: 12px;
      background: var(--bg); border: 2px solid var(--border); border-radius: 18px;
      box-shadow: 0 10px 30px var(--shadow); backdrop-filter: blur(8px);
    }
    .game-card {
      min-width: 190px; padding: 12px 16px;
      background: linear-gradient(135deg, rgba(125, 211, 252, 0.18), rgba(250, 204, 21, 0.08));
      border: 1px solid var(--border); border-radius: 14px;
    }
    .game-title { font-size: 18px; font-weight: 800; letter-spacing: 0.02em; line-height: 1.1; white-space: nowrap; }
    .ruleset {
      margin-top: 5px; font-size: 12px; font-weight: 700; color: var(--muted);
      text-transform: uppercase; letter-spacing: 0.12em; white-space: nowrap;
    }
    .stat-row { display: flex; gap: 10px; align-items: stretch; }
    .stat {
      min-width: 112px; padding: 10px 12px; background: var(--panel-bg);
      border: 1px solid var(--border); border-radius: 14px; text-align: center;
    }
    .label {
      font-size: 11px; font-weight: 800; color: var(--muted);
      text-transform: uppercase; letter-spacing: 0.14em; line-height: 1;
    }
    .value { margin-top: 6px; font-size: 28px; font-weight: 900; line-height: 1; letter-spacing: -0.04em; }
    .attempt .value { color: var(--accent); }
    .deaths .value { color: var(--danger); }
    .badges .value { color: var(--gold); }
    .badge-pips { display: flex; gap: 4px; align-items: center; justify-content: center; flex-wrap: wrap; max-width: 130px; }
    .badge-pip  { width: 14px; height: 14px; border-radius: 50%; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.22); }
    .badge-img  { width: 20px; height: 20px; object-fit: contain; }
    .badge-img--off { filter: grayscale(1) brightness(0.5); opacity: 0.5; }
    .overlay.vertical { flex-direction: column; align-items: stretch; width: 260px; }
    .overlay.vertical .stat-row { display: grid; grid-template-columns: 1fr 1fr; }
    .overlay.vertical .game-card,
    .overlay.vertical .stat { min-width: 0; width: 100%; }
    .overlay.vertical .game-title,
    .overlay.vertical .ruleset { white-space: normal; }
  </style>
</head>
<body>
  <main class="overlay<?php echo $vertical ? ' vertical' : ''; ?>" id="overlay">
    <section class="game-card">
      <div class="game-title" id="game"><?php echo esc_html($overlay['game']); ?></div>
      <div class="ruleset" id="ruleset"><?php echo esc_html($overlay['ruleset']); ?></div>
    </section>
    <section class="stat-row">
      <div class="stat attempt">
        <div class="label">Attempt</div>
        <div class="value" id="attempt"><?php echo esc_html($overlay['attempt']); ?></div>
      </div>
      <div class="stat">
        <div class="label">Level Cap</div>
        <div class="value" id="cap"><?php echo esc_html($overlay['cap']); ?></div>
      </div>
      <div class="stat deaths">
        <div class="label">Deaths</div>
        <div class="value" id="deaths"><?php echo esc_html($overlay['deaths']); ?></div>
      </div>
      <div class="stat badges">
        <div class="label">Badges</div>
        <div class="value" id="badges"><?php echo esc_html($overlay['badges']); ?></div>
      </div>
    </section>
  </main>

  <script>
    // Live-updating overlay: polls the public REST endpoint so edits made in
    // WP Admin appear on stream within a few seconds, no OBS refresh needed.
    var REST_URL = <?php echo wp_json_encode($rest_url); ?>;
    var FIELDS   = ["game", "ruleset", "attempt", "cap", "deaths"];
    var BADGE_SETS  = <?php echo wp_json_encode(lamprozo_badge_sets()); ?>;
    var UPLOADS_URL = <?php echo wp_json_encode($uploads_url); ?>;
    var BADGE_CB = Date.now(); // per-load cache-bust so a stale 404 can't stick

    function earnedBadges(v) { var m = String(v == null ? "" : v).match(/\d+/); return m ? parseInt(m[0], 10) : 0; }
    function badgeSlug(name) { return String(name).toLowerCase().replace(/[^a-z0-9]+/g, "-"); }
    function renderBadges(data) {
      var el = document.getElementById("badges");
      if (!el) return;
      var set = BADGE_SETS[data.badgeset];
      if (!(set && set.length)) { el.textContent = (data.badges == null ? "" : data.badges); return; }
      var n = earnedBadges(data.badges);
      el.textContent = "";
      var wrap = document.createElement("span");
      wrap.className = "badge-pips";
      set.forEach(function(b, i) {
        var on = i < n;
        // Prefer an uploaded badge image; fall back to a colored pip if absent.
        var img = document.createElement("img");
        img.className = "badge-img" + (on ? " badge-img--on" : " badge-img--off");
        img.src = UPLOADS_URL + "/lamprozo/badges/" + data.badgeset + "/" + badgeSlug(b.name) + ".png?v=" + BADGE_CB;
        img.alt = b.name; img.title = b.name;
        img.onerror = function() {
          var pip = document.createElement("span");
          pip.className = "badge-pip" + (on ? " badge-pip--on" : "");
          if (on) { pip.style.background = b.color; pip.style.borderColor = b.color; }
          pip.title = b.name;
          img.replaceWith(pip);
        };
        wrap.appendChild(img);
      });
      el.appendChild(wrap);
    }

    function partySlug(s) { return String(s || "").toLowerCase().replace(/[^a-z0-9]+/g, "-"); }
    function renderParty(data) {
      var el = document.getElementById("party");
      if (!el) return;                       // only the full layout has a party panel
      var party = data.party || [];
      el.textContent = "";
      party.forEach(function(p) {
        var slug = partySlug(p.species);
        var img = document.createElement("img");
        img.className = "party-mon";
        img.src = UPLOADS_URL + "/lamprozo/party/" + slug + ".png?v=" + BADGE_CB;
        img.alt = p.nickname || p.species || "";
        img.title = (p.nickname || p.species || "") + (p.level ? "  L" + p.level : "");
        img.onerror = function() {           // uploaded sprite missing -> pokemondb sprite
          if (img.dataset.fb) { img.style.visibility = "hidden"; return; }
          img.dataset.fb = "1";
          img.src = "https://img.pokemondb.net/sprites/heartgold-soulsilver/normal/" + slug + ".png";
        };
        el.appendChild(img);
      });
    }

    function applyOverlay(data) {
      FIELDS.forEach(function(key) {
        var el = document.getElementById(key);
        if (el && data[key] !== undefined && el.textContent !== String(data[key])) {
          el.textContent = data[key];
        }
      });
      renderBadges(data);
      renderParty(data);
    }

    function poll() {
      fetch(REST_URL + (REST_URL.indexOf("?") < 0 ? "?" : "&") + "_=" + Date.now(), { cache: "no-store" })
        .then(function(r) { return r.json(); })
        .then(applyOverlay)
        .catch(function() { /* keep last good values */ });
    }

    setInterval(poll, <?php echo (int) $interval; ?>); // fallback / safety net

    // Instant updates: Server-Sent Events push the moment data is saved in admin.
    // (The poll above stays as a fallback if the stream drops.)
    var SSE_URL = <?php echo wp_json_encode($sse_url); ?>;
    if (window.EventSource) {
      var es = new EventSource(SSE_URL);
      es.onmessage = function(e) { try { applyOverlay(JSON.parse(e.data)); } catch (_) {} };
      // On error the browser auto-reconnects; the fallback poll covers any gap.
    }
  </script>
</body>
</html>
    <?php
}

// ── Full GBA stream layout (OBS Browser Source) ─────────────────────────────────

/**
 * Animated-background color presets. Each drives the canvas backdrop: `base` is
 * the solid fill, `hue`/`sat` generate the drifting orbs + sparkle particles.
 * A challenge's `theme` field picks one of these (see the admin Challenges page).
 */
function lamprozo_layout_themes() {
    return [
        'emerald'  => ['base' => '#071a0a', 'hue' => 145, 'sat' => 60],
        'platinum' => ['base' => '#0b0a16', 'hue' => 265, 'sat' => 42],
        'silver'   => ['base' => '#0e1013', 'hue' => 210, 'sat' => 12],
        'crystal'  => ['base' => '#06141a', 'hue' => 188, 'sat' => 60],
        'fire'     => ['base' => '#1a0707', 'hue' => 8,   'sat' => 70],
        'water'    => ['base' => '#06101f', 'hue' => 215, 'sat' => 65],
        'gold'     => ['base' => '#1a1405', 'hue' => 45,  'sat' => 65],
        'ruby'     => ['base' => '#1a060a', 'hue' => 350, 'sat' => 65],
        'sapphire' => ['base' => '#060a1a', 'hue' => 225, 'sat' => 65],
    ];
}

/**
 * Gym-badge sets per game region. Each badge has a name + color; the overlay
 * lights them up in order according to the attempt's earned badge count. A
 * challenge's `badgeset` field selects one ('none' falls back to plain text).
 */
function lamprozo_badge_sets() {
    return [
        // Hoenn (Emerald / Emerald Kaizo), in gym order.
        'hoenn' => [
            ['name' => 'Stone',   'color' => '#9aa3ad'],
            ['name' => 'Knuckle', 'color' => '#d9534f'],
            ['name' => 'Dynamo',  'color' => '#f1c40f'],
            ['name' => 'Heat',    'color' => '#e67e22'],
            ['name' => 'Balance', 'color' => '#c8a24a'],
            ['name' => 'Feather', 'color' => '#48c9b0'],
            ['name' => 'Mind',    'color' => '#af7ac5'],
            ['name' => 'Rain',    'color' => '#2e86de'],
        ],
    ];
}

add_action('template_redirect', function() {
    if (!isset($_GET['lamprozo_layout'])) { return; }
    lamprozo_render_layout_page();
    exit;
});

// ── Party probe (diagnostic) ────────────────────────────────────────────────────
// Open /?lamprozo_party_probe=1 in a browser ON THE STREAMING PC to dump whatever
// the local sync server returns, so the party JSON shape can be mapped. Override
// the source with &src=. Temporary helper; safe to remove once the party panel works.
add_action('template_redirect', function() {
    if (!isset($_GET['lamprozo_party_probe'])) { return; }
    $src = isset($_GET['src']) ? esc_url_raw($_GET['src']) : 'http://localhost:31124/update';
    header('Content-Type: text/html; charset=utf-8');
    nocache_headers();
    ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Party probe</title>
<style>body{font-family:Consolas,monospace;background:#0e0e10;color:#efeff1;padding:1.2rem;line-height:1.4}
h3{margin:0 0 .6rem;font-weight:600}.src{color:#7dd3fc}pre{white-space:pre-wrap;word-break:break-word;background:#18181b;border:1px solid #2a2a2e;border-radius:8px;padding:1rem}.err{color:#fb7185}</style>
</head><body>
<h3>GET <span class="src" id="u"></span></h3>
<pre id="out">loading…</pre>
<script>
var SRC = <?php echo wp_json_encode($src); ?>;
document.getElementById('u').textContent = SRC;
fetch(SRC, { cache: 'no-store' })
  .then(function(r){ return r.text().then(function(t){ return { status: r.status, body: t }; }); })
  .then(function(res){
    var out = document.getElementById('out');
    var pretty;
    try { pretty = JSON.stringify(JSON.parse(res.body), null, 2); } catch (e) { pretty = res.body; }
    out.textContent = 'HTTP ' + res.status + '\n\n' + pretty;
  })
  .catch(function(e){
    var o = document.getElementById('out'); o.className = 'err';
    o.textContent = 'FETCH FAILED: ' + e + '\n\nOpen F12 → Console for the exact reason (CORS / mixed-content / connection refused).';
  });
</script>
</body></html>
    <?php
    exit;
});

function lamprozo_render_layout_page() {
    $overlay  = lamprozo_overlay_resolve();
    $rest_url = esc_url_raw(rest_url('lamprozo/v1/overlay'));
    $sse_url  = esc_url_raw(home_url('/?lamprozo_overlay_sse=1'));
    $uploads_url = esc_url_raw(wp_upload_dir()['baseurl']);
    $interval = isset($_GET['interval']) ? max(1, (int) $_GET['interval']) * 1000 : 5000;

    // Chat is rendered in-page via an anonymous, read-only Twitch IRC-over-
    // WebSocket connection (see the chat client script) — no login, no parent-
    // domain restriction. Channel overridable via ?channel=.
    $channel = isset($_GET['channel']) ? sanitize_key($_GET['channel']) : 'lamprozo';

    // Background theme: ?bg= override, else the active challenge's theme, else emerald.
    $themes    = lamprozo_layout_themes();
    $theme_key = isset($_GET['bg']) ? sanitize_key($_GET['bg']) : ($overlay['theme'] ?? 'emerald');
    if (!isset($themes[$theme_key])) { $theme_key = 'emerald'; }

    header('Content-Type: text/html; charset=utf-8');
    nocache_headers();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Lamprozo — GBA Stream Layout</title>
  <style>
    :root {
      --panel-bg: rgba(24, 30, 50, 0.92);
      --card-bg: linear-gradient(135deg, rgba(125,211,252,0.18), rgba(250,204,21,0.08));
      --border: rgba(255, 255, 255, 0.18);
      --frame: rgba(255, 255, 255, 0.85);
      --text: #f7f7fb;
      --muted: #b9bfd6;
      --accent: #7dd3fc;
      --danger: #fb7185;
      --gold: #facc15;
      --shadow: rgba(0, 0, 0, 0.45);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; height: 100%; }
    body {
      background: transparent; overflow: hidden;
      font-family: "Inter", "Segoe UI", Arial, sans-serif; color: var(--text);
    }
    #bg { position: absolute; inset: 0; z-index: 0; }
    .stage { position: absolute; inset: 0; z-index: 1; }

    /* Framed, transparent regions — OBS sources sit BEHIND the browser source. */
    .region {
      position: absolute;
      border: 0.22vh solid var(--frame);
      border-radius: 1vh;
      box-shadow: 0 0 1.4vh var(--shadow), inset 0 0 1.2vh rgba(0,0,0,0.25);
    }
    .region__label {
      position: absolute; top: -0.2vh; left: 1vw; transform: translateY(-100%);
      font-size: 1.3vh; font-weight: 800; letter-spacing: 0.16em; text-transform: uppercase;
      color: var(--muted); text-shadow: 0 1px 3px rgba(0,0,0,0.6);
    }

    /* GBA screen is 3:2 (240x160). On a 16:9 canvas, width% = 0.84375 * height%,
       so 62.6% x 74.2% holds a true 3:2 box with no stretching. Centered in the
       left column; the status bar below matches its width. */
    .region--gba    { left: 3.45%; top: 7.8%; width: 62.6%; height: 74.2%; }
    .region--cam    { left: 70%;   top: 7.8%; width: 28%;   height: 29.9%; }
    .region--chat   { left: 70%; top: 46.5%; width: 28%; height: 45.5%; overflow: hidden; background: rgba(12,16,28,0.55); backdrop-filter: blur(6px); }

    .chat {
      width: 100%; height: 100%; padding: 0.9vh 0.7vw;
      display: flex; flex-direction: column; justify-content: flex-end; gap: 0.5vh;
      overflow: hidden;
    }
    .chat__line {
      font-size: 1.95vh; line-height: 1.34; word-wrap: break-word; overflow-wrap: anywhere;
      text-shadow: 0 1px 3px rgba(0,0,0,0.7);
    }
    .chat__user { font-weight: 800; margin-right: 0.35em; }
    .chat__msg  { color: var(--text); }
    .chat__line--action .chat__msg { font-style: italic; }

    /* Status bar under the GBA screen: top row (game + stats), then a badge row */
    .status {
      position: absolute; left: 3.45%; top: 84%; width: 62.6%; height: 14%;
      display: flex; flex-direction: column; gap: 0.7vh;
      background: rgba(12, 16, 28, 0.88);
      border: 0.22vh solid var(--border); border-radius: 1.2vh;
      box-shadow: 0 0.8vh 2vh var(--shadow); backdrop-filter: blur(8px);
      padding: 0.9vh 1vw;
    }
    .status__top { display: flex; align-items: stretch; gap: 1vw; flex: 1.3; min-height: 0; }
    .status__card {
      display: flex; flex-direction: column; justify-content: center;
      padding: 0 1.2vw; min-width: 16%;
      background: var(--card-bg); border: 0.18vh solid var(--border); border-radius: 0.9vh;
    }
    .status__game { font-size: 2.4vh; font-weight: 800; line-height: 1.05; white-space: nowrap; }
    .status__ruleset { margin-top: 0.4vh; font-size: 1.4vh; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.12em; white-space: nowrap; }
    .status__stats { display: flex; gap: 0.8vw; flex: 1; }
    .stat {
      flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
      background: var(--panel-bg); border: 0.18vh solid var(--border); border-radius: 0.9vh;
    }
    .stat__label { font-size: 1.3vh; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: 0.12em; }
    .stat__value { margin-top: 0.5vh; font-size: 3.2vh; font-weight: 900; line-height: 1; letter-spacing: -0.03em; }
    .stat--attempt .stat__value { color: var(--accent); }
    .stat--deaths  .stat__value { color: var(--danger); }
    /* Badge row */
    .status__badges {
      flex: 1.2; min-height: 0; display: flex; align-items: stretch; justify-content: center; gap: 0;
      background: var(--panel-bg); border: 0.18vh solid var(--border); border-radius: 0.9vh;
      padding: 0.5vh 1.2vw; overflow: hidden;
    }
    /* Two even columns: badges | party */
    .status__badges #badges,
    .status__badges #party { flex: 1; display: flex; align-items: center; justify-content: center; height: 100%; min-width: 0; gap: 0.6vw; }
    .status__divider { width: 2px; align-self: stretch; margin: 0.6vh 0; background: rgba(255,255,255,0.16); flex-shrink: 0; }
    .party-mon { height: 5.5vh; max-height: 100%; width: auto; object-fit: contain; image-rendering: pixelated; }
    .badge-pips { display: flex; gap: 0.8vw; align-items: center; justify-content: center; flex-wrap: nowrap; height: 100%; max-height: 100%; }
    .badge-pip  { width: 3vh; height: 3vh; max-height: 100%; border-radius: 50%; background: rgba(255,255,255,0.1); border: 0.2vh solid rgba(255,255,255,0.22); }
    .badge-pip--on { box-shadow: 0 0 0.6vh rgba(0,0,0,0.45); }
    .badge-img  { height: 5vh; max-height: 100%; width: auto; max-width: 7vh; object-fit: contain; }
    .badge-img--off { filter: grayscale(1) brightness(0.5); opacity: 0.5; }
    .badge-img--on  { filter: drop-shadow(0 0 0.5vh rgba(0,0,0,0.55)); }
  </style>
</head>
<body>
  <canvas id="bg"></canvas>
  <div class="stage">
    <div class="region region--gba"></div>

    <div class="region region--cam"></div>

    <div class="region region--chat">
      <div class="chat" id="chat"></div>
    </div>

    <div class="status">
      <div class="status__top">
        <div class="status__card">
          <div class="status__game" id="game"><?php echo esc_html($overlay['game']); ?></div>
          <div class="status__ruleset" id="ruleset"><?php echo esc_html($overlay['ruleset']); ?></div>
        </div>
        <div class="status__stats">
          <div class="stat stat--attempt"><span class="stat__label">Attempt</span><span class="stat__value" id="attempt"><?php echo esc_html($overlay['attempt']); ?></span></div>
          <div class="stat"><span class="stat__label">Level Cap</span><span class="stat__value" id="cap"><?php echo esc_html($overlay['cap']); ?></span></div>
          <div class="stat stat--deaths"><span class="stat__label">Deaths</span><span class="stat__value" id="deaths"><?php echo esc_html($overlay['deaths']); ?></span></div>
        </div>
      </div>
      <div class="status__badges">
        <span id="badges"></span>
        <span class="status__divider"></span>
        <span id="party"></span>
      </div>
    </div>
  </div>

  <script>
    // ── Background theme (per active game) ─────────────────────────────────────
    var THEMES       = <?php echo wp_json_encode($themes); ?>;
    var THEME_LOCKED = <?php echo isset($_GET['bg']) ? 'true' : 'false'; ?>;
    var themeName    = <?php echo wp_json_encode($theme_key); ?>;
    var THEME        = THEMES[themeName] || THEMES.emerald;

    // ── Animated canvas background (drifting orbs + sparkles, scanlines, vignette).
    // Transparent holes are punched for the game + webcam so OBS captures placed
    // BEHIND this browser source show through.
    var bgCanvas = document.getElementById('bg');
    var bgCtx    = bgCanvas.getContext('2d');
    function bgResize() { bgCanvas.width = window.innerWidth; bgCanvas.height = window.innerHeight; }
    bgResize(); window.addEventListener('resize', bgResize);

    var HOLES = [
      { x: 3.45, y: 7.8, w: 62.6, h: 74.2 },   // GBA screen
      { x: 70,   y: 7.8, w: 28,   h: 29.9 }    // webcam
    ];
    var ORBS = [
      { ox: 0.15, oy: 0.35, r: 0.30, hueOff:  0,  phase: 0.00 },
      { ox: 0.80, oy: 0.65, r: 0.26, hueOff: 10,  phase: 1.80 },
      { ox: 0.50, oy: 0.15, r: 0.20, hueOff: -10, phase: 3.50 },
      { ox: 0.30, oy: 0.80, r: 0.23, hueOff: 15,  phase: 2.20 }
    ];
    var PARTICLES = Array.from({ length: 90 }, function() {
      return {
        x: Math.random(), y: Math.random(),
        size: Math.random() * 2.5 + 0.4,
        speed: Math.random() * 0.00012 + 0.00003,
        drift: (Math.random() - 0.5) * 0.00008,
        opacity: Math.random() * 0.55 + 0.1,
        pulseRate: Math.random() * 0.0015 + 0.0005,
        pulsePhase: Math.random() * Math.PI * 2,
        hueOff: Math.random() * 50 - 15,
        lightness: 55 + Math.random() * 30
      };
    });
    function roundRectPath(c, x, y, w, h, r) {
      c.beginPath();
      c.moveTo(x + r, y);
      c.arcTo(x + w, y,     x + w, y + h, r);
      c.arcTo(x + w, y + h, x,     y + h, r);
      c.arcTo(x,     y + h, x,     y,     r);
      c.arcTo(x,     y,     x + w, y,     r);
      c.closePath();
    }
    function bgDraw(ts) {
      var W = bgCanvas.width, H = bgCanvas.height, t = ts * 0.001;
      var hue = THEME.hue, sat = THEME.sat;

      bgCtx.globalCompositeOperation = 'source-over';
      bgCtx.fillStyle = THEME.base;
      bgCtx.fillRect(0, 0, W, H);

      for (var i = 0; i < ORBS.length; i++) {
        var o = ORBS[i];
        var x = (o.ox + Math.sin(t * 0.07 + o.phase) * 0.08) * W;
        var y = (o.oy + Math.cos(t * 0.05 + o.phase) * 0.06) * H;
        var r = o.r * Math.max(W, H);
        var a = 0.26 + Math.sin(t * 0.09 + o.phase) * 0.05;
        var g = bgCtx.createRadialGradient(x, y, 0, x, y, r);
        g.addColorStop(0,   'hsla(' + (hue + o.hueOff) + ',' + sat + '%,30%,' + a + ')');
        g.addColorStop(0.5, 'hsla(' + (hue + o.hueOff) + ',' + Math.max(sat - 10, 8) + '%,20%,' + (a * 0.5) + ')');
        g.addColorStop(1,   'transparent');
        bgCtx.fillStyle = g; bgCtx.fillRect(0, 0, W, H);
      }

      for (var j = 0; j < PARTICLES.length; j++) {
        var p = PARTICLES[j];
        var pulse = 0.65 + Math.sin(t * p.pulseRate * 1000 + p.pulsePhase) * 0.35;
        var alpha = p.opacity * pulse;
        var px = p.x * W, py = p.y * H, glowR = p.size * 5;
        var ph = hue + p.hueOff, psat = Math.min(sat + 20, 90);
        var pg = bgCtx.createRadialGradient(px, py, 0, px, py, glowR);
        pg.addColorStop(0,   'hsla(' + ph + ',' + psat + '%,' + p.lightness + '%,' + alpha + ')');
        pg.addColorStop(0.4, 'hsla(' + ph + ',' + (psat - 10) + '%,' + (p.lightness - 10) + '%,' + (alpha * 0.4) + ')');
        pg.addColorStop(1,   'transparent');
        bgCtx.beginPath(); bgCtx.arc(px, py, glowR, 0, Math.PI * 2); bgCtx.fillStyle = pg; bgCtx.fill();
        bgCtx.beginPath(); bgCtx.arc(px, py, p.size * 0.5, 0, Math.PI * 2);
        bgCtx.fillStyle = 'hsla(' + ph + ',90%,75%,' + (alpha * 0.6) + ')'; bgCtx.fill();
        p.y -= p.speed; p.x += p.drift;
        if (p.y < -0.02) { p.y = 1.02; p.x = Math.random(); }
        if (p.x < -0.02) p.x = 1.02;
        if (p.x > 1.02) p.x = -0.02;
      }

      bgCtx.fillStyle = 'rgba(0,0,0,0.04)';
      for (var sy = 0; sy < H; sy += 4) bgCtx.fillRect(0, sy, W, 1);

      var vg = bgCtx.createRadialGradient(W / 2, H / 2, Math.min(W, H) * 0.25, W / 2, H / 2, Math.max(W, H) * 0.75);
      vg.addColorStop(0, 'transparent'); vg.addColorStop(1, 'rgba(0,0,0,0.5)');
      bgCtx.fillStyle = vg; bgCtx.fillRect(0, 0, W, H);

      bgCtx.globalCompositeOperation = 'destination-out';
      bgCtx.fillStyle = '#000';
      var rr = 0.01 * H;
      for (var k = 0; k < HOLES.length; k++) {
        var hl = HOLES[k];
        roundRectPath(bgCtx, hl.x / 100 * W, hl.y / 100 * H, hl.w / 100 * W, hl.h / 100 * H, rr);
        bgCtx.fill();
      }
      bgCtx.globalCompositeOperation = 'source-over';

      requestAnimationFrame(bgDraw);
    }
    requestAnimationFrame(bgDraw);

    // ── Live-updating status — polls the public REST endpoint ──────────────────
    var REST_URL = <?php echo wp_json_encode($rest_url); ?>;
    var FIELDS   = ["game", "ruleset", "attempt", "cap", "deaths"];
    var BADGE_SETS  = <?php echo wp_json_encode(lamprozo_badge_sets()); ?>;
    var UPLOADS_URL = <?php echo wp_json_encode($uploads_url); ?>;
    var BADGE_CB = Date.now(); // per-load cache-bust so a stale 404 can't stick

    function earnedBadges(v) { var m = String(v == null ? "" : v).match(/\d+/); return m ? parseInt(m[0], 10) : 0; }
    function badgeSlug(name) { return String(name).toLowerCase().replace(/[^a-z0-9]+/g, "-"); }
    function renderBadges(data) {
      var el = document.getElementById("badges");
      if (!el) return;
      var set = BADGE_SETS[data.badgeset];
      if (!(set && set.length)) { el.textContent = (data.badges == null ? "" : data.badges); return; }
      var n = earnedBadges(data.badges);
      el.textContent = "";
      var wrap = document.createElement("span");
      wrap.className = "badge-pips";
      set.forEach(function(b, i) {
        var on = i < n;
        // Prefer an uploaded badge image; fall back to a colored pip if absent.
        var img = document.createElement("img");
        img.className = "badge-img" + (on ? " badge-img--on" : " badge-img--off");
        img.src = UPLOADS_URL + "/lamprozo/badges/" + data.badgeset + "/" + badgeSlug(b.name) + ".png?v=" + BADGE_CB;
        img.alt = b.name; img.title = b.name;
        img.onerror = function() {
          var pip = document.createElement("span");
          pip.className = "badge-pip" + (on ? " badge-pip--on" : "");
          if (on) { pip.style.background = b.color; pip.style.borderColor = b.color; }
          pip.title = b.name;
          img.replaceWith(pip);
        };
        wrap.appendChild(img);
      });
      el.appendChild(wrap);
    }

    function partySlug(s) { return String(s || "").toLowerCase().replace(/[^a-z0-9]+/g, "-"); }
    function renderParty(data) {
      var el = document.getElementById("party");
      if (!el) return;                       // only the full layout has a party panel
      var party = data.party || [];
      el.textContent = "";
      party.forEach(function(p) {
        var slug = partySlug(p.species);
        var img = document.createElement("img");
        img.className = "party-mon";
        img.src = UPLOADS_URL + "/lamprozo/party/" + slug + ".png?v=" + BADGE_CB;
        img.alt = p.nickname || p.species || "";
        img.title = (p.nickname || p.species || "") + (p.level ? "  L" + p.level : "");
        img.onerror = function() {           // uploaded sprite missing -> pokemondb sprite
          if (img.dataset.fb) { img.style.visibility = "hidden"; return; }
          img.dataset.fb = "1";
          img.src = "https://img.pokemondb.net/sprites/heartgold-soulsilver/normal/" + slug + ".png";
        };
        el.appendChild(img);
      });
    }

    function applyOverlay(data) {
      FIELDS.forEach(function(key) {
        var el = document.getElementById(key);
        if (el && data[key] !== undefined && el.textContent !== String(data[key])) {
          el.textContent = data[key];
        }
      });
      renderBadges(data);
      renderParty(data);
      // Re-theme the background live when the active game changes (unless ?bg= locked it).
      if (!THEME_LOCKED && data.theme && THEMES[data.theme] && data.theme !== themeName) {
        themeName = data.theme;
        THEME = THEMES[themeName];
      }
    }
    function poll() {
      fetch(REST_URL + (REST_URL.indexOf("?") < 0 ? "?" : "&") + "_=" + Date.now(), { cache: "no-store" })
        .then(function(r) { return r.json(); })
        .then(applyOverlay)
        .catch(function() {});
    }
    setInterval(poll, <?php echo (int) $interval; ?>); // fallback / safety net

    // Instant updates: Server-Sent Events push the moment data is saved in admin.
    // (The poll above stays as a fallback if the stream drops.)
    var SSE_URL = <?php echo wp_json_encode($sse_url); ?>;
    if (window.EventSource) {
      var es = new EventSource(SSE_URL);
      es.onmessage = function(e) { try { applyOverlay(JSON.parse(e.data)); } catch (_) {} };
      // On error the browser auto-reconnects; the fallback poll covers any gap.
    }

    // ── Twitch chat: anonymous, read-only IRC over WebSocket ───────────────────
    var CHANNEL   = <?php echo wp_json_encode($channel); ?>;
    var chatEl    = document.getElementById("chat");
    var MAX_LINES = 60;
    var FALLBACK_COLORS = ["#FF4F4F","#7DD3FC","#FACC15","#34D399","#F472B6","#A78BFA","#FB923C","#22D3EE"];

    function chatEscape(s) { return s.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"); }
    function fallbackColor(name) {
      var h = 0; for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
      return FALLBACK_COLORS[h % FALLBACK_COLORS.length];
    }
    function parseTags(raw) {
      var t = {};
      raw.split(";").forEach(function(p) { var i = p.indexOf("="); if (i > 0) t[p.slice(0, i)] = p.slice(i + 1); });
      return t;
    }
    function addLine(name, color, msg, isAction) {
      var c = color || fallbackColor(name);
      var line = document.createElement("div");
      line.className = "chat__line" + (isAction ? " chat__line--action" : "");
      var u = document.createElement("span");
      u.className = "chat__user"; u.style.color = c; u.textContent = name + ":";
      var m = document.createElement("span");
      m.className = "chat__msg"; if (isAction) m.style.color = c;
      m.innerHTML = " " + chatEscape(msg);
      line.appendChild(u); line.appendChild(m);
      chatEl.appendChild(line);
      while (chatEl.children.length > MAX_LINES) chatEl.removeChild(chatEl.firstChild);
    }
    function connectChat() {
      var ws = new WebSocket("wss://irc-ws.chat.twitch.tv:443");
      ws.onopen = function() {
        ws.send("PASS SCHMOOPIIE");
        ws.send("NICK justinfan" + Math.floor(Math.random() * 99999));
        ws.send("CAP REQ :twitch.tv/tags twitch.tv/commands");
        ws.send("JOIN #" + CHANNEL);
      };
      ws.onmessage = function(e) {
        e.data.split("\r\n").forEach(function(line) {
          if (!line) return;
          if (line.indexOf("PING") === 0) { ws.send("PONG :tmi.twitch.tv"); return; }
          var m = line.match(/^(?:@(\S+) )?:(\w+)!\S+ PRIVMSG #\S+ :([\s\S]*)$/);
          if (!m) return;
          var tags = m[1] ? parseTags(m[1]) : {};
          var name = tags["display-name"] || m[2];
          var text = m[3], isAction = false;
          var am = text.match(/^\x01ACTION ([\s\S]*)\x01$/);
          if (am) { isAction = true; text = am[1]; }
          addLine(name, tags["color"] || "", text, isAction);
        });
      };
      ws.onclose = function() { setTimeout(connectChat, 3000); };
      ws.onerror = function() { try { ws.close(); } catch (_) {} };
    }
    if (CHANNEL) connectChat();
  </script>
</body>
</html>
    <?php
}

// ── Admin menu (submenu under the existing Lamprozo menu) ───────────────────────

add_action('admin_menu', function() {
    // Parent "lamprozo-challenges" is registered by the attempts model. Priority
    // 11 runs after it (default 10) so the parent exists. If the attempts model
    // is ever unloaded, fall back to a standalone top-level page.
    if (function_exists('ffc_challenges_dashboard')) {
        add_submenu_page(
            'lamprozo-challenges',
            'Overlay',
            'Overlay',
            'manage_options',
            'lamprozo-overlay',
            'ffc_overlay_dashboard'
        );
    } else {
        add_menu_page(
            'Overlay',
            'Overlay',
            'manage_options',
            'lamprozo-overlay',
            'ffc_overlay_dashboard',
            'dashicons-desktop'
        );
    }
}, 11);

function ffc_overlay_dashboard() {
    $view_path = dirname(__FILE__, 2) . '/views/overlay.php';
    if (file_exists($view_path)) {
        include $view_path;
    }
}
