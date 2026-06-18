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
.ov-bset-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.ov-bset-tag { font-size:0.7em; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#fff; background:#2271b1; border-radius:10px; padding:1px 8px; }
.ov-bset-tag--builtin { background:#888; }
.ov-bset-hint { font-size:0.82em; color:#888; margin:0 0 10px; }
.ov-bset-edit { max-width:540px; margin-bottom:16px; }
.ov-brow { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
.ov-brow__num { width:18px; text-align:right; color:#aaa; font-size:0.78em; font-weight:700; flex-shrink:0; }
.ov-brow__ord { display:flex; gap:2px; flex-shrink:0; }
.ov-brow__ord .button { min-width:24px; padding:0 5px; }
.ov-brow__name { flex:1; padding:5px 8px; border:1px solid #ccc; border-radius:4px; }
.ov-brow__color { width:40px; height:30px; padding:0; border:1px solid #ccc; border-radius:4px; background:none; cursor:pointer; flex-shrink:0; }
.ov-bset-actions { display:flex; gap:8px; margin-top:8px; }
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
// Full set list (built-in + custom) for the manager + image uploader. Image
// uploads target a set's own folder, so only sets that own their images
// (image_set === key) are uploadable here — a built-in that aliases another
// set's art (run-and-bun -> hoenn) is managed via its source set.
$ov_all_sets = function_exists('lamprozo_badge_sets_payload') ? lamprozo_badge_sets_payload() : [];
$ov_uploads  = esc_url_raw(wp_upload_dir()['baseurl']);
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

    <h3>Badge sets <span style="font-weight:400;color:#888;font-size:0.8em">(make a set, then upload a transparent .png per badge)</span></h3>
    <div class="ov-bset-bar">
        <label for="ov-badge-set" style="font-weight:600">Set:</label>
        <select id="ov-badge-set"></select>
        <span id="ov-bset-tag"></span>
        <button class="button button-small" id="ov-bset-new">+ New set</button>
        <button class="button button-small button-link-delete" id="ov-bset-del" style="display:none">Delete set</button>
    </div>
    <div id="ov-bset-editor"></div>
    <div class="ov-badge-grid" id="ov-badge-grid"></div>
</div>
</div>

<script>
function ovCopy(id) {
    const el = document.getElementById(id);
    el.select();
    navigator.clipboard.writeText(el.value);
}

(function () {
    const NONCE    = '<?php echo wp_create_nonce('wp_rest'); ?>';
    const ASSET_EP = '<?php echo esc_url_raw(rest_url('lamprozo/v1/overlay-asset')); ?>';
    const SETS_EP  = '<?php echo esc_url_raw(rest_url('lamprozo/v1/badge-sets')); ?>';
    const UPLOADS  = <?php echo wp_json_encode($ov_uploads); ?>;
    // [{ key, badges:[{name,color}], image_set, protected }]; custom sets carry _unsaved while being created.
    let sets       = <?php echo wp_json_encode(array_values($ov_all_sets)); ?>;
    let current    = '';

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
    function slug(s) { return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
    function hex(c) { return /^#[0-9a-f]{6}$/i.test(c) ? c : '#888888'; }
    function setByKey(k) { return sets.find(s => s.key === k); }
    function imageOwners() { return sets.filter(s => s.image_set === s.key); } // uploadable sets

    // ── shared file upload (sfx + badge png) ─────────────────────────────────
    function upload(form, btn, onDone) {
        btn.classList.add('ov-busy');
        fetch(ASSET_EP, { method: 'POST', headers: { 'X-WP-Nonce': NONCE }, body: form })
            .then(r => r.json().then(d => ({ ok: r.ok, d })))
            .then(({ ok, d }) => {
                btn.classList.remove('ov-busy');
                if (ok && d.success) { onDone(d.url + '?v=' + Date.now()); }
                else { alert('Upload failed: ' + (d && d.message ? d.message : 'unknown error')); }
            })
            .catch(() => { btn.classList.remove('ov-busy'); alert('Upload failed.'); });
    }

    // ── alert sounds ─────────────────────────────────────────────────────────
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

    // ── badge image upload ───────────────────────────────────────────────────
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

    // ── badge-set manager ────────────────────────────────────────────────────
    const sel    = document.getElementById('ov-badge-set');
    const tagEl  = document.getElementById('ov-bset-tag');
    const editor = document.getElementById('ov-bset-editor');
    const grid   = document.getElementById('ov-badge-grid');
    const delBtn = document.getElementById('ov-bset-del');

    function rebuildDropdown() {
        const owners = imageOwners();
        if (!owners.some(s => s.key === current)) current = owners.length ? owners[0].key : '';
        sel.innerHTML = owners.map(s => `<option value="${esc(s.key)}"${s.key === current ? ' selected' : ''}>${esc(s.key)}${s.protected ? '' : ' ★'}</option>`).join('');
    }
    function renderTag() {
        const s = setByKey(current);
        tagEl.innerHTML = s ? (s.protected
            ? '<span class="ov-bset-tag ov-bset-tag--builtin">built-in</span>'
            : '<span class="ov-bset-tag">custom</span>') : '';
        delBtn.style.display = (s && !s.protected) ? '' : 'none';
    }
    function renderEditor() {
        const s = setByKey(current);
        if (!s) { editor.innerHTML = ''; return; }
        if (s.protected) {
            editor.innerHTML = '<p class="ov-bset-hint">Built-in set — badge names are fixed. Upload a .png for each badge below.</p>';
            return;
        }
        const rows = s.badges.map((b, i) => `
            <div class="ov-brow">
                <span class="ov-brow__num">${i + 1}</span>
                <span class="ov-brow__ord">
                    <button class="button button-small" ${i === 0 ? 'disabled' : ''} onclick="ovBadgeMove(${i},-1)" title="Move up">&uarr;</button>
                    <button class="button button-small" ${i === s.badges.length - 1 ? 'disabled' : ''} onclick="ovBadgeMove(${i},1)" title="Move down">&darr;</button>
                </span>
                <input class="ov-brow__name" type="text" value="${esc(b.name)}" placeholder="Badge name" oninput="ovBadgeEdit(${i},'name',this.value)">
                <input class="ov-brow__color" type="color" value="${hex(b.color)}" onchange="ovBadgeEdit(${i},'color',this.value)" title="Fallback color (shown until a .png is uploaded)">
                <button class="button button-small button-link-delete" onclick="ovBadgeRemove(${i})" title="Remove badge">&times;</button>
            </div>`).join('');
        editor.innerHTML = `<div class="ov-bset-edit">
            <p class="ov-bset-hint">Set the gym order, names &amp; fallback colors — changes save automatically. Then upload a .png per badge below (filenames follow the badge names).</p>
            ${rows || '<p class="ov-bset-hint">No badges yet — add one below.</p>'}
            <div class="ov-bset-actions">
                <button class="button button-small" onclick="ovBadgeAdd()">+ Add badge</button>
                <span id="ov-bset-status" class="ov-bset-hint" style="margin:0;align-self:center"></span>
            </div>
        </div>`;
    }
    function renderGrid() {
        const s = setByKey(current);
        if (!grid) return;
        if (!s || s._unsaved) { grid.innerHTML = s && s._unsaved ? '<p class="ov-bset-hint">Name a badge above to create the set — image upload appears once it saves.</p>' : ''; return; }
        const cb = Date.now();
        grid.innerHTML = s.badges.map(b => {
            const bs  = slug(b.name);
            const url = UPLOADS + '/lamprozo/badges/' + s.key + '/' + bs + '.png?v=' + cb;
            return `<div class="ov-badge">
                <img class="ov-badge__img" id="badge-img-${bs}" src="${url}" alt=""
                     onerror="this.style.display='none';var p=document.getElementById('badge-ph-${bs}');if(p)p.style.display='flex'">
                <div class="ov-badge__ph" id="badge-ph-${bs}" style="display:none;background:${hex(b.color)};color:#fff">🎖</div>
                <div class="ov-badge__name">${esc(b.name)}</div>
                <input class="ov-asset__file" type="file" accept="image/png,.png" id="badge-file-${bs}">
                <button class="button button-small" onclick="ovUploadBadge('${esc(s.key)}','${bs}', this)">Upload</button>
            </div>`;
        }).join('');
    }
    function renderAll() { rebuildDropdown(); renderTag(); renderEditor(); renderGrid(); }

    // Edits mutate the in-memory custom set and auto-save (debounced), matching
    // the rest of the admin (attempts/fights/challenges all save on change). The
    // editor inputs are never re-rendered on a plain save, so typing isn't lost.
    let saveTimer = null;
    function setStatus(msg) { const el = document.getElementById('ov-bset-status'); if (el) el.textContent = msg; }
    function scheduleSave() {
        const s = setByKey(current); if (!s) return;
        s._dirty = true;
        setStatus('Saving…');
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveNow, 700);
    }
    function saveNow() {
        clearTimeout(saveTimer);
        const s = setByKey(current); if (!s) return;
        const badges = s.badges.map(b => ({ name: (b.name || '').trim(), color: b.color })).filter(b => b.name !== '');
        if (!badges.length) { setStatus('Add a badge name to save'); return; }
        fetch(SETS_EP, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
            body: JSON.stringify({ key: s.key, badges })
        }).then(r => r.json().then(d => ({ ok: r.ok, d })))
          .then(({ ok, d }) => {
              if (!ok || !d.success) { setStatus(''); alert('Save failed: ' + (d && d.message ? d.message : 'unknown error')); return; }
              s._unsaved = false; s._dirty = false;
              rebuildDropdown(); renderTag(); renderGrid(); // editor untouched so focus/caret stay put
              setStatus('All changes saved ✓');
          }).catch(() => { setStatus(''); alert('Save failed.'); });
    }

    window.ovBadgeEdit = (i, key, val) => { const s = setByKey(current); if (s && s.badges[i]) { s.badges[i][key] = val; scheduleSave(); } };
    window.ovBadgeAdd = () => { const s = setByKey(current); if (s) { s.badges.push({ name: '', color: '#cccccc' }); renderEditor(); } };
    window.ovBadgeRemove = (i) => { const s = setByKey(current); if (s) { s.badges.splice(i, 1); renderEditor(); scheduleSave(); } };
    window.ovBadgeMove = (i, d) => {
        const s = setByKey(current); if (!s) return;
        const j = i + d; if (j < 0 || j >= s.badges.length) return;
        [s.badges[i], s.badges[j]] = [s.badges[j], s.badges[i]];
        renderEditor(); scheduleSave();
    };

    document.getElementById('ov-bset-new').addEventListener('click', () => {
        const name = (prompt('Name the badge set (e.g. Kanto):') || '').trim();
        if (!name) return;
        const key = slug(name);
        if (!key) { alert('Please use letters or numbers.'); return; }
        if (setByKey(key)) { alert('A set called "' + key + '" already exists.'); current = key; renderAll(); return; }
        sets.push({ key, badges: [{ name: '', color: '#cccccc' }], image_set: key, protected: false, _unsaved: true });
        current = key;
        renderAll();
    });

    delBtn.addEventListener('click', () => {
        const s = setByKey(current); if (!s || s.protected) return;
        if (s._unsaved) { sets = sets.filter(x => x.key !== s.key); current = ''; renderAll(); return; }
        if (!confirm('Delete the "' + s.key + '" badge set and its uploaded images? This can\'t be undone.')) return;
        fetch(SETS_EP + '/' + encodeURIComponent(s.key), { method: 'DELETE', headers: { 'X-WP-Nonce': NONCE } })
            .then(r => r.json().then(d => ({ ok: r.ok, d })))
            .then(({ ok, d }) => {
                if (!ok || !d.success) { alert('Delete failed: ' + (d && d.message ? d.message : 'unknown error')); return; }
                sets = d.sets; current = ''; renderAll();
            }).catch(() => alert('Delete failed.'));
    });

    sel.addEventListener('change', () => {
        const prev = setByKey(current);
        if (prev && prev._dirty) saveNow(); // flush before leaving the set
        current = sel.value; renderTag(); renderEditor(); renderGrid();
    });
    window.addEventListener('beforeunload', (e) => { if (sets.some(s => s._dirty)) { e.preventDefault(); e.returnValue = ''; } });
    renderAll();
})();
</script>
