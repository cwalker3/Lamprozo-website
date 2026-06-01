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
    $blank = ['game' => '', 'ruleset' => '', 'attempt' => '', 'cap' => '', 'deaths' => '', 'badges' => ''];

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
});

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
    var FIELDS   = ["game", "ruleset", "attempt", "cap", "deaths", "badges"];

    function applyOverlay(data) {
      FIELDS.forEach(function(key) {
        var el = document.getElementById(key);
        if (el && data[key] !== undefined && el.textContent !== String(data[key])) {
          el.textContent = data[key];
        }
      });
    }

    function poll() {
      fetch(REST_URL, { cache: "no-store" })
        .then(function(r) { return r.json(); })
        .then(applyOverlay)
        .catch(function() { /* keep last good values */ });
    }

    setInterval(poll, <?php echo (int) $interval; ?>);
  </script>
</body>
</html>
    <?php
}

// ── Full GBA stream layout (OBS Browser Source) ─────────────────────────────────

add_action('template_redirect', function() {
    if (!isset($_GET['lamprozo_layout'])) { return; }
    lamprozo_render_layout_page();
    exit;
});

function lamprozo_render_layout_page() {
    $overlay  = lamprozo_overlay_resolve();
    $rest_url = esc_url_raw(rest_url('lamprozo/v1/overlay'));
    $interval = isset($_GET['interval']) ? max(1, (int) $_GET['interval']) * 1000 : 5000;

    // Twitch chat embed: channel overridable via ?channel=, parent must be the
    // host this page is served from (handles localhost / tunnel / production).
    $channel = isset($_GET['channel']) ? sanitize_key($_GET['channel']) : 'lamprozo';
    $host    = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'localhost';
    $chat_src = 'https://www.twitch.tv/embed/' . rawurlencode($channel) . '/chat?darkpopout&parent=' . rawurlencode($host);

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
    .stage { position: absolute; inset: 0; }

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

    .region--gba    { left: 2%;    top: 7.8%; width: 69.5%;  height: 74.2%; }
    .region--cam    { left: 74.1%; top: 7.8%; width: 23.75%; height: 29.9%; }
    .region--chat   { left: 74.1%; top: 46.5%; width: 23.75%; height: 45.5%; overflow: hidden; }

    .region--chat iframe { width: 100%; height: 100%; border: 0; display: block; }

    /* Status bar, directly under the GBA screen */
    .status {
      position: absolute; left: 2%; top: 84%; width: 69.5%; height: 8%;
      display: flex; align-items: stretch; gap: 1vw;
      background: rgba(12, 16, 28, 0.88);
      border: 0.22vh solid var(--border); border-radius: 1.2vh;
      box-shadow: 0 0.8vh 2vh var(--shadow); backdrop-filter: blur(8px);
      padding: 0.9vh 1vw;
    }
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
    .stat--badges  .stat__value { color: var(--gold); }
  </style>
</head>
<body>
  <div class="stage">
    <div class="region region--gba"></div>

    <div class="region region--cam"></div>

    <div class="region region--chat">
      <iframe src="<?php echo esc_url($chat_src); ?>" title="Twitch chat"></iframe>
    </div>

    <div class="status">
      <div class="status__card">
        <div class="status__game" id="game"><?php echo esc_html($overlay['game']); ?></div>
        <div class="status__ruleset" id="ruleset"><?php echo esc_html($overlay['ruleset']); ?></div>
      </div>
      <div class="status__stats">
        <div class="stat stat--attempt"><span class="stat__label">Attempt</span><span class="stat__value" id="attempt"><?php echo esc_html($overlay['attempt']); ?></span></div>
        <div class="stat"><span class="stat__label">Level Cap</span><span class="stat__value" id="cap"><?php echo esc_html($overlay['cap']); ?></span></div>
        <div class="stat stat--deaths"><span class="stat__label">Deaths</span><span class="stat__value" id="deaths"><?php echo esc_html($overlay['deaths']); ?></span></div>
        <div class="stat stat--badges"><span class="stat__label">Badges</span><span class="stat__value" id="badges"><?php echo esc_html($overlay['badges']); ?></span></div>
      </div>
    </div>
  </div>

  <script>
    // Live-updating status — polls the public REST endpoint so edits in WP Admin
    // appear on stream within a few seconds (no OBS refresh needed).
    var REST_URL = <?php echo wp_json_encode($rest_url); ?>;
    var FIELDS   = ["game", "ruleset", "attempt", "cap", "deaths", "badges"];

    function applyOverlay(data) {
      FIELDS.forEach(function(key) {
        var el = document.getElementById(key);
        if (el && data[key] !== undefined && el.textContent !== String(data[key])) {
          el.textContent = data[key];
        }
      });
    }
    function poll() {
      fetch(REST_URL, { cache: "no-store" })
        .then(function(r) { return r.json(); })
        .then(applyOverlay)
        .catch(function() {});
    }
    setInterval(poll, <?php echo (int) $interval; ?>);
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
