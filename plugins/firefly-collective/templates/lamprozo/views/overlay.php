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
.ov-assets { margin-top:24px; max-width:100%; }
.ov-assets h3 { margin:18px 0 10px; }
.ov-sfx-grid { display:flex; gap:16px; flex-wrap:wrap; }
.ov-sfx { flex:1 1 200px; min-width:200px; border:1px solid #e2e2e2; border-radius:6px; padding:12px; background:#fafafa; }
.ov-sfx__label { font-weight:600; margin-bottom:6px; }
.ov-sfx audio { width:100%; height:34px; margin-bottom:6px; }
.ov-asset__none { font-size:0.82em; color:#b8b8b8; font-style:italic; margin-bottom:6px; }
.ov-asset__file { display:block; font-size:0.82em; margin-bottom:8px; }
.ov-set-row { display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.ov-badge-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
.ov-badge { border:1px solid #e2e2e2; border-radius:6px; padding:10px; background:#fafafa; text-align:center; }
.ov-badge__img { width:56px; height:56px; object-fit:contain; image-rendering:-webkit-optimize-contrast; }
.ov-badge__ph { width:56px; height:56px; margin:0 auto; border:1px dashed #ccc; border-radius:6px; display:flex; align-items:center; justify-content:center; color:#bbb; font-size:1.6em; }
.ov-badge__name { font-weight:600; font-size:0.85em; margin:6px 0; }
.ov-busy { opacity:0.5; pointer-events:none; }
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
            draws the animated background, the framed game + webcam holes, an embedded Twitch chat,
            and the status bar — so place it on <em>top</em> and put your game capture and webcam
            <em>behind</em> it (they show through the transparent frames). The background
            <strong>auto-themes per game</strong> (set each challenge's <em>BG theme</em> on the
            Challenges page); override with <code>&amp;bg=emerald</code>. Chat channel defaults to
            <code>lamprozo</code> (override <code>&amp;channel=</code>); open from the same domain as
            this site so chat connects.
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

<?php
// Only list "real" image sets in the uploader — sets that alias another's images
// (e.g. run-and-bun -> hoenn) are edited via their source set.
$ov_badge_sets = function_exists('lamprozo_badge_sets') ? lamprozo_badge_sets() : [];
if (function_exists('lamprozo_badge_image_set')) {
    $ov_badge_sets = array_filter(
        $ov_badge_sets,
        fn($k) => lamprozo_badge_image_set($k) === $k,
        ARRAY_FILTER_USE_KEY
    );
}
$ov_uploads = esc_url_raw(wp_upload_dir()['baseurl']);
?>
<div class="ov-panel ov-assets">
    <h2 style="margin-top:0">Overlay assets</h2>
    <p class="ov-hint" style="margin-top:0">Upload the alert sounds and gym-badge images the overlay uses. They save straight into your uploads folder, so the overlay (and the attempt cards) pick them up right away — just refresh the OBS source.</p>

    <h3>Alert sounds <span style="font-weight:400;color:#888;font-size:0.8em">(.mp3, keep them short)</span></h3>
    <div class="ov-sfx-grid">
        <?php foreach (['badge' => 'Badge earned', 'death' => 'Pokémon died', 'wipe' => 'Run wiped'] as $key => $label): ?>
        <div class="ov-sfx" id="sfx-card-<?php echo esc_attr($key); ?>">
            <div class="ov-sfx__label"><?php echo esc_html($label); ?></div>
            <audio controls preload="metadata" id="sfx-audio-<?php echo esc_attr($key); ?>"
                   src="<?php echo esc_url($ov_uploads . '/lamprozo/sfx/' . $key . '.mp3'); ?>?v=<?php echo time(); ?>"
                   onerror="ovSfxMissing('<?php echo esc_js($key); ?>')"></audio>
            <div class="ov-asset__none" id="sfx-none-<?php echo esc_attr($key); ?>" style="display:none">No file uploaded yet.</div>
            <input class="ov-asset__file" type="file" accept="audio/mpeg,.mp3" id="sfx-file-<?php echo esc_attr($key); ?>">
            <button class="button button-small" onclick="ovUploadSfx('<?php echo esc_js($key); ?>', this)">Upload</button>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($ov_badge_sets)): ?>
    <h3>Gym badges <span style="font-weight:400;color:#888;font-size:0.8em">(.png, transparent background)</span></h3>
    <div class="ov-set-row">
        <label for="ov-badge-set" style="font-weight:600">Badge set:</label>
        <select id="ov-badge-set">
            <?php foreach (array_keys($ov_badge_sets) as $set): ?>
            <option value="<?php echo esc_attr($set); ?>"><?php echo esc_html(ucfirst($set)); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="ov-badge-grid" id="ov-badge-grid"></div>
    <?php endif; ?>
</div>
</div>

<script>
function ovCopy(id) {
    const el = document.getElementById(id);
    el.select();
    navigator.clipboard.writeText(el.value);
}

(function () {
    const NONCE       = '<?php echo wp_create_nonce('wp_rest'); ?>';
    const ENDPOINT    = '<?php echo esc_url_raw(rest_url('lamprozo/v1/overlay-asset')); ?>';
    const UPLOADS     = <?php echo wp_json_encode($ov_uploads); ?>;
    const BADGE_SETS  = <?php echo wp_json_encode($ov_badge_sets ?: new stdClass()); ?>;

    function slug(s) { return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-'); }

    function upload(form, btn, onDone) {
        btn.classList.add('ov-busy');
        fetch(ENDPOINT, { method: 'POST', headers: { 'X-WP-Nonce': NONCE }, body: form })
            .then(r => r.json().then(d => ({ ok: r.ok, d })))
            .then(({ ok, d }) => {
                btn.classList.remove('ov-busy');
                if (ok && d.success) { onDone(d.url + '?v=' + Date.now()); }
                else { alert('Upload failed: ' + (d && d.message ? d.message : 'unknown error')); }
            })
            .catch(() => { btn.classList.remove('ov-busy'); alert('Upload failed.'); });
    }

    window.ovSfxMissing = (key) => {
        const a = document.getElementById('sfx-audio-' + key);
        const n = document.getElementById('sfx-none-' + key);
        if (a) a.style.display = 'none';
        if (n) n.style.display = 'block';
    };

    window.ovUploadSfx = (key, btn) => {
        const input = document.getElementById('sfx-file-' + key);
        if (!input.files[0]) { alert('Choose an .mp3 file first.'); return; }
        const form = new FormData();
        form.append('kind', 'sfx');
        form.append('name', key);
        form.append('file', input.files[0]);
        upload(form, btn, (url) => {
            const a = document.getElementById('sfx-audio-' + key);
            const n = document.getElementById('sfx-none-' + key);
            if (n) n.style.display = 'none';
            if (a) { a.src = url; a.style.display = ''; a.load(); }
            input.value = '';
        });
    };

    window.ovUploadBadge = (set, badgeSlug, btn) => {
        const input = document.getElementById('badge-file-' + badgeSlug);
        if (!input.files[0]) { alert('Choose a .png file first.'); return; }
        const form = new FormData();
        form.append('kind', 'badge');
        form.append('set', set);
        form.append('badge', badgeSlug);
        form.append('file', input.files[0]);
        upload(form, btn, (url) => {
            const img = document.getElementById('badge-img-' + badgeSlug);
            const ph  = document.getElementById('badge-ph-' + badgeSlug);
            if (img) { img.src = url; img.style.display = ''; }
            if (ph)  ph.style.display = 'none';
            input.value = '';
        });
    };

    function renderBadgeGrid() {
        const grid = document.getElementById('ov-badge-grid');
        if (!grid) return;
        const set = document.getElementById('ov-badge-set').value;
        const cb  = Date.now();
        grid.innerHTML = (BADGE_SETS[set] || []).map(b => {
            const s   = slug(b.name);
            const url = UPLOADS + '/lamprozo/badges/' + set + '/' + s + '.png?v=' + cb;
            return `<div class="ov-badge">
                <img class="ov-badge__img" id="badge-img-${s}" src="${url}" alt=""
                     onerror="this.style.display='none';var p=document.getElementById('badge-ph-${s}');if(p)p.style.display='flex'">
                <div class="ov-badge__ph" id="badge-ph-${s}" style="display:none">🎖</div>
                <div class="ov-badge__name">${b.name}</div>
                <input class="ov-asset__file" type="file" accept="image/png,.png" id="badge-file-${s}">
                <button class="button button-small" onclick="ovUploadBadge('${set}','${s}', this)">Upload</button>
            </div>`;
        }).join('');
    }

    const setSel = document.getElementById('ov-badge-set');
    if (setSel) { setSel.addEventListener('change', renderBadgeGrid); renderBadgeGrid(); }
})();
</script>
