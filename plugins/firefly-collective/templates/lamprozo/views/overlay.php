<?php
if (!defined('ABSPATH')) exit;

// Resolve once for the server-rendered "source" line; the preview iframe and the
// live values refresh themselves from the public REST endpoint.
$resolved = function_exists('lamprozo_overlay_resolve') ? lamprozo_overlay_resolve() : [];

// Figure out which challenge/attempt is feeding the overlay, for the status line.
$source_challenge = '';
$source_attempt   = '';
if (function_exists('lamprozo_get_challenges_data')) {
    foreach (lamprozo_get_challenges_data() as $slug => $c) {
        if (($c['status'] ?? '') === 'active') {
            $source_challenge = $c['title'] ?? $slug;
            $attempts = lamprozo_get_attempts($slug);
            foreach ($attempts as $a) {
                if (($a['status'] ?? '') === 'ongoing') { $source_attempt = $a['number'] ?? ''; break; }
            }
            break;
        }
    }
}
?>
<div class="wrap">
<h1>Stream Overlay</h1>
<p style="color:#666;max-width:680px">
    This overlay is <strong>fully automatic</strong> — it shows the live status of your
    <strong>active</strong> challenge and its <strong>ongoing</strong> attempt. Edit those in
    <a href="<?php echo esc_url(admin_url('admin.php?page=lamprozo-challenges')); ?>">Challenges</a>
    (game, ruleset) and
    <a href="<?php echo esc_url(admin_url('admin.php?page=lamprozo-attempts')); ?>">Attempts</a>
    (attempt #, level cap, badges, deaths) and the overlay updates on stream within a few seconds.
</p>

<style>
.ov-layout { display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start; }
.ov-panel { background:#fff; border:1px solid #ddd; border-radius:6px; padding:20px; }
.ov-preview { flex:1 1 520px; max-width:680px; }
.ov-urls { flex:1 1 320px; max-width:420px; }
.ov-source { display:inline-block; background:#f0f6fc; border:1px solid #c3d9ed; color:#0a4b78; border-radius:4px; padding:6px 12px; font-size:0.9em; margin-bottom:14px; }
.ov-source--none { background:#fcf0f0; border-color:#edc3c3; color:#8a1f1f; }
.ov-frame-wrap { background:#0e0e10; border-radius:8px; padding:18px; overflow:auto; }
.ov-frame { width:100%; height:200px; border:0; display:block; }
.ov-url-block { margin-bottom:16px; }
.ov-url-block label { display:block; font-weight:600; font-size:0.85em; margin-bottom:4px; }
.ov-url-row { display:flex; gap:6px; }
.ov-url-row input { flex:1; padding:6px 8px; border:1px solid #ccc; border-radius:4px; font-family:monospace; font-size:0.82em; background:#f6f7f7; }
.ov-hint { font-size:0.82em; color:#888; margin:14px 0 0; line-height:1.5; }
</style>

<div class="ov-layout">
    <div class="ov-panel ov-preview">
        <h2 style="margin-top:0">Live preview</h2>
        <?php if ($source_challenge): ?>
        <span class="ov-source">
            Live: <strong><?php echo esc_html($source_challenge); ?></strong>
            <?php if ($source_attempt !== ''): ?>— attempt #<?php echo esc_html($source_attempt); ?><?php endif; ?>
        </span>
        <?php else: ?>
        <span class="ov-source ov-source--none">
            No active challenge — set one to “Active” in Challenges and the overlay will populate.
        </span>
        <?php endif; ?>
        <div class="ov-frame-wrap">
            <iframe class="ov-frame" src="<?php echo esc_url(home_url('/?lamprozo_overlay=1')); ?>" title="Overlay preview"></iframe>
        </div>
        <p class="ov-hint">This is the exact page OBS renders, refreshing every few seconds.</p>
    </div>

    <div class="ov-panel ov-urls">
        <h2 style="margin-top:0">OBS Browser Source</h2>
        <div class="ov-url-block">
            <label>Horizontal (recommended ~900&times;160)</label>
            <div class="ov-url-row">
                <input type="text" id="ov-url-h" readonly value="<?php echo esc_attr(home_url('/?lamprozo_overlay=1')); ?>">
                <button class="button" onclick="ovCopy('ov-url-h')">Copy</button>
            </div>
        </div>
        <div class="ov-url-block">
            <label>Vertical sidebar (~260&times;320)</label>
            <div class="ov-url-row">
                <input type="text" id="ov-url-v" readonly value="<?php echo esc_attr(home_url('/?lamprozo_overlay=1&vertical=true')); ?>">
                <button class="button" onclick="ovCopy('ov-url-v')">Copy</button>
            </div>
        </div>
        <div class="ov-url-block">
            <label>Full GBA layout (whole 16:9 scene)</label>
            <div class="ov-url-row">
                <input type="text" id="ov-url-layout" readonly value="<?php echo esc_attr(home_url('/?lamprozo_layout=1')); ?>">
                <button class="button" onclick="ovCopy('ov-url-layout')">Copy</button>
            </div>
        </div>
        <p class="ov-hint">
            <strong>Full layout</strong> is a single browser source sized to your whole canvas. It
            draws the framed game + webcam holes, an embedded Twitch chat, and the status bar — so
            place it on <em>top</em> and put your game capture, webcam, and a background source
            <em>behind</em> it (they show through the transparent frames). Chat channel defaults to
            <code>lamprozo</code>; override with <code>&amp;channel=yourname</code>. Open it from the
            same domain as this site so Twitch chat loads.
        </p>
        <p class="ov-hint">
            Add a <strong>Browser Source</strong> in OBS and paste one of these URLs. The
            background is transparent and it auto-refreshes, so leave it running while you
            update your challenge and attempts.
        </p>
        <p class="ov-hint">
            <a href="<?php echo esc_url(home_url('/?lamprozo_overlay=1')); ?>" target="_blank">Open overlay in a new tab &rarr;</a>
        </p>
    </div>
</div>
</div>

<script>
function ovCopy(id) {
    const el = document.getElementById(id);
    el.select();
    navigator.clipboard.writeText(el.value);
}
</script>
