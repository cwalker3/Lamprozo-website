<?php
if (!defined('ABSPATH')) exit;
$theme_keys = function_exists('lamprozo_layout_themes') ? array_keys(lamprozo_layout_themes()) : ['emerald'];
$badge_keys = array_merge(['none'], function_exists('lamprozo_badge_sets') ? array_keys(lamprozo_badge_sets()) : []);
$badge_counts = [];
if (function_exists('lamprozo_badge_sets')) {
    foreach (lamprozo_badge_sets() as $k => $v) { $badge_counts[$k] = count($v); }
}
?>
<div class="wrap">
<h1>Challenges</h1>
<style>
.badge-active  { background:#e6f4ea; color:#1e7e34; border-radius:20px; padding:2px 10px; font-size:0.72em; font-weight:700; vertical-align:middle; }
.badge-on-hold { background:#fff3cd; color:#856404; border-radius:20px; padding:2px 10px; font-size:0.72em; font-weight:700; vertical-align:middle; }

.add-form { background:#fff; border:1px solid #ddd; border-radius:6px; padding:20px; max-width:760px; margin:0 0 16px; }
.add-form h2 { margin-top:0; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
.form-row label, .form-full label { display:block; font-weight:600; font-size:0.85em; margin-bottom:4px; }
.form-row input, .form-row select, .form-full input, .form-full textarea { width:100%; box-sizing:border-box; }
.form-full { margin-bottom:12px; }
.form-full textarea { height:70px; }

/* Master–detail */
.cd-layout { display:flex; gap:16px; align-items:flex-start; margin-top:16px; }
.cd-list { flex:0 0 260px; display:flex; flex-direction:column; gap:6px; }
.cd-item { display:flex; align-items:center; gap:8px; width:100%; text-align:left; padding:8px 10px; border:1px solid #ddd; border-radius:6px; background:#fff; cursor:pointer; font-size:13px; }
.cd-item:hover { background:#f0f6fc; }
.cd-item--active { border-color:#2271b1; background:#f0f6fc; box-shadow:inset 3px 0 0 #2271b1; font-weight:600; }
.cd-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
.cd-item__title { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

.cd-detail { flex:1; min-width:0; background:#fff; border:1px solid #ddd; border-radius:6px; padding:16px; }
.cd-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.cd-head h2 { margin:0; font-size:1.3em; }
.cd-head .actions { display:flex; gap:6px; flex-wrap:wrap; }
.cd-meta { font-size:0.85em; color:#666; margin:6px 0; }
.cd-desc { font-size:0.92em; color:#444; margin:6px 0 12px; }
.cd-fields { display:flex; gap:16px; flex-wrap:wrap; margin:12px 0; }
.cd-fields label { font-size:0.78em; font-weight:600; color:#555; text-transform:uppercase; letter-spacing:0.04em; display:flex; flex-direction:column; gap:4px; }
.cd-fields input, .cd-fields select { padding:5px 8px; border:1px solid #ccc; border-radius:4px; min-width:170px; font-weight:400; text-transform:none; }
.cd-caps { margin:12px 0; }
.cd-caps__label { font-size:0.78em; font-weight:700; color:#555; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:6px; }
.cd-caps__label span { font-weight:400; text-transform:none; color:#888; letter-spacing:0; }
.cd-caps__row { display:flex; gap:6px; flex-wrap:wrap; }
.cap-cell { display:flex; flex-direction:column; align-items:center; gap:2px; font-size:0.7em; color:#888; font-weight:700; }
.cap-cell input { width:46px; padding:4px; border:1px solid #ccc; border-radius:4px; text-align:center; font-size:13px; color:#1d2327; }
.cd-subhead { margin:18px 0 8px; padding-top:14px; border-top:1px solid #eee; font-size:0.78em; text-transform:uppercase; letter-spacing:0.08em; color:#666; font-weight:700; }
#cd-attempts iframe { width:100%; border:0; display:block; background:#f6f7f7; border-radius:4px; }
</style>

<p><button class="button button-primary" onclick="toggleAddForm()" id="add-toggle">+ New Challenge</button></p>

<div class="add-form" id="add-form" style="display:none">
    <h2>Add New Challenge</h2>
    <div class="form-row">
        <div><label>Title *</label><input type="text" id="new-title" placeholder="e.g. Crystal Legacy" oninput="generateSlug()"></div>
        <div><label>Slug *</label><input type="text" id="new-slug" placeholder="e.g. crystal-legacy"></div>
    </div>
    <div class="form-row">
        <div><label>Game</label><input type="text" id="new-game" placeholder="e.g. Pokémon Crystal Legacy"></div>
        <div><label>Type</label><input type="text" id="new-type" placeholder="ROM Hack" value="ROM Hack"></div>
    </div>
    <div class="form-row">
        <div><label>Generation</label><input type="text" id="new-gen" placeholder="e.g. II"></div>
        <div><label>Ruleset</label><input type="text" id="new-ruleset" placeholder="e.g. Hardcore Nuzlocke"></div>
    </div>
    <div class="form-row">
        <div><label>Background theme</label>
            <select id="new-theme">
                <?php foreach ($theme_keys as $tk): ?><option value="<?php echo esc_attr($tk); ?>"><?php echo esc_html(ucfirst($tk)); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div><label>Badge set</label>
            <select id="new-badgeset">
                <?php foreach ($badge_keys as $bk): ?><option value="<?php echo esc_attr($bk); ?>"><?php echo esc_html(ucfirst($bk)); ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-full"><label>Description</label><textarea id="new-desc" placeholder="A short description of this challenge..."></textarea></div>
    <button class="button button-primary" onclick="createChallenge()">Add Challenge</button>
    <span id="add-status" style="margin-left:10px;font-size:0.9em"></span>
</div>

<div class="cd-layout">
    <div class="cd-list" id="challenge-list-items"></div>
    <div class="cd-detail" id="challenge-detail"></div>
</div>
</div>

<script>
const apiBase = '<?php echo esc_js(rest_url('lamprozo/v1')); ?>';
const nonce   = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };
const themeOptions = <?php echo wp_json_encode($theme_keys); ?>;
const badgeOptions = <?php echo wp_json_encode($badge_keys); ?>;
const badgeCounts  = <?php echo wp_json_encode($badge_counts); ?>;
const PROTECTED = ['sterling-silver','renegade-platinum','platinum-kaizo'];

let challengesData = [];
let selected = null;
let mountedSlug = null;   // which challenge's attempts iframe is currently mounted

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function statusDot(s) { return s==='active' ? '#1e7e34' : s==='completed' ? '#6a0dad' : '#856404'; }
function statusBadge(s) {
    if (s==='completed') return `<span class="badge-active" style="background:#ede7f6;color:#6a0dad">Completed</span>`;
    if (s==='on_hold')   return `<span class="badge-on-hold">On Hold</span>`;
    return `<span class="badge-active">Active</span>`;
}

function themeSelect(slug, current) {
    const opts = themeOptions.map(t => `<option value="${t}"${t===(current||'emerald')?' selected':''}>${t.charAt(0).toUpperCase()+t.slice(1)}</option>`).join('');
    return `<select onchange="updateField('${esc(slug)}','theme',this.value)">${opts}</select>`;
}
function badgeSelect(slug, current) {
    const opts = badgeOptions.map(b => `<option value="${b}"${b===(current||'none')?' selected':''}>${b.charAt(0).toUpperCase()+b.slice(1)}</option>`).join('');
    return `<select onchange="updateField('${esc(slug)}','badgeset',this.value)">${opts}</select>`;
}

function toggleAddForm() {
    const f = document.getElementById('add-form');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

function generateSlug() {
    const title = document.getElementById('new-title').value;
    document.getElementById('new-slug').value = title.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
}

function renderList() {
    const el = document.getElementById('challenge-list-items');
    if (!challengesData.length) { el.innerHTML = '<p style="color:#888;padding:6px">No challenges yet.</p>'; return; }
    el.innerHTML = challengesData.map(c =>
        `<button class="cd-item${c.slug===selected?' cd-item--active':''}" onclick="selectChallenge('${esc(c.slug)}')">
            <span class="cd-dot" style="background:${statusDot(c.status)}"></span>
            <span class="cd-item__title">${esc(c.title)}</span>
        </button>`
    ).join('');
}

function renderInfo() {
    const c = challengesData.find(x => x.slug === selected);
    const el = document.getElementById('cd-info');
    if (!c || !el) return;
    const isComplete = c.status==='completed', isOnHold = c.status==='on_hold';
    const n = badgeCounts[c.badgeset] || 8;
    const caps = c.caps || [];
    let capCells = '';
    for (let i = 0; i <= n; i++) {
        capCells += `<label class="cap-cell"><span>${i}</span><input type="text" value="${esc(caps[i]||'')}" onchange="updateCaps('${esc(c.slug)}',${i},this.value)"></label>`;
    }
    el.innerHTML = `
        <div class="cd-head">
            <h2>${esc(c.title)} ${statusBadge(c.status)}</h2>
            <div class="actions">
                <a href="/${esc(c.slug)}" target="_blank" class="button button-small">View Page</a>
                ${!isComplete ? `<button class="button button-small" onclick="toggleStatus('${esc(c.slug)}','${isOnHold?'active':'on_hold'}')">${isOnHold?'Set Active':'Put On Hold'}</button>` : ''}
                ${!isComplete ? `<button class="button button-small" style="background:#6a0dad;color:#fff;border-color:#6a0dad" onclick="toggleStatus('${esc(c.slug)}','completed')">Mark Completed</button>` : `<button class="button button-small" onclick="toggleStatus('${esc(c.slug)}','active')">Reopen</button>`}
                ${!PROTECTED.includes(c.slug) ? `<button class="button button-small button-link-delete" onclick="deleteChallenge('${esc(c.slug)}','${esc(c.title)}')">Delete</button>` : ''}
            </div>
        </div>
        <p class="cd-meta">${esc(c.game||'')} &middot; ${esc(c.type)} &middot; Gen ${esc(c.gen)} &middot; <code>${esc(c.slug)}</code></p>
        ${c.description ? `<p class="cd-desc">${esc(c.description)}</p>` : ''}
        <div class="cd-fields">
            <label>Ruleset<input type="text" value="${esc(c.ruleset||'')}" placeholder="Hardcore Nuzlocke" onchange="updateField('${esc(c.slug)}','ruleset',this.value)"></label>
            <label>Background theme${themeSelect(c.slug, c.theme)}</label>
            <label>Badge set${badgeSelect(c.slug, c.badgeset)}</label>
        </div>
        <div class="cd-caps">
            <div class="cd-caps__label">Level cap by badges <span>(badges &rarr; cap; auto-applies to the ongoing attempt)</span></div>
            <div class="cd-caps__row">${capCells}</div>
        </div>`;
}

function renderDetail() {
    const detail = document.getElementById('challenge-detail');
    if (!selected) { detail.innerHTML = '<p style="color:#888;padding:12px">Select a challenge from the list.</p>'; mountedSlug = null; return; }
    if (mountedSlug !== selected) {
        detail.innerHTML = `<div id="cd-info"></div>
            <div class="cd-subhead">Attempts</div>
            <div id="cd-attempts"></div>`;
        const f = document.createElement('iframe');
        f.src = '/?lamprozo_attempts_embed=1&challenge=' + encodeURIComponent(selected);
        f.setAttribute('data-challenge', selected);
        f.style.height = '320px';
        document.getElementById('cd-attempts').appendChild(f);
        mountedSlug = selected;
    }
    renderInfo();
}

function selectChallenge(slug) { selected = slug; renderList(); renderDetail(); }

function load() {
    fetch(apiBase + '/challenges', { headers })
        .then(r => r.json())
        .then(data => {
            challengesData = data;
            if (!challengesData.some(c => c.slug === selected)) {
                const active = challengesData.find(c => c.status === 'active');
                selected = (active || challengesData[0])?.slug || null;
            }
            renderList();
            renderDetail();
        });
}

// Save a single challenge field (ruleset/theme/badgeset) without re-rendering.
async function updateField(slug, field, value) {
    const c = challengesData.find(x => x.slug === slug); if (c) c[field] = value;
    const res = await fetch(apiBase + '/challenges/' + slug, { method:'PUT', headers, body: JSON.stringify({ [field]: value }) });
    const data = await res.json();
    if (!data.success) alert(data.message || ('Error saving ' + field + '.'));
}

// Save the per-badge level caps array for a challenge.
function updateCaps(slug, idx, val) {
    const c = challengesData.find(x => x.slug === slug); if (!c) return;
    c.caps = c.caps || [];
    c.caps[idx] = val;
    fetch(apiBase + '/challenges/' + slug, { method:'PUT', headers, body: JSON.stringify({ caps: c.caps }) })
        .then(r => r.json()).then(d => { if (!d.success) alert(d.message || 'Error saving caps.'); });
}

async function toggleStatus(slug, newStatus) {
    const res = await fetch(apiBase + '/challenges/' + slug, { method:'PUT', headers, body: JSON.stringify({ status: newStatus }) });
    const data = await res.json();
    if (data.success) load(); else alert(data.message || 'Error updating challenge.');
}

async function deleteChallenge(slug, title) {
    if (!confirm(`Delete "${title}"? This cannot be undone.`)) return;
    const res = await fetch(apiBase + '/challenges/' + slug, { method:'DELETE', headers });
    const data = await res.json();
    if (data.success) { if (selected === slug) selected = null; load(); }
    else alert(data.message || 'Error deleting challenge.');
}

async function createChallenge() {
    const slug  = document.getElementById('new-slug').value.trim();
    const title = document.getElementById('new-title').value.trim();
    const status = document.getElementById('add-status');
    if (!slug || !title) { status.textContent = 'Title and slug are required.'; return; }
    status.textContent = 'Creating...';
    const res = await fetch(apiBase + '/challenges', {
        method:'POST', headers,
        body: JSON.stringify({
            slug, title,
            game:        document.getElementById('new-game').value.trim() || title,
            type:        document.getElementById('new-type').value.trim() || 'ROM Hack',
            gen:         document.getElementById('new-gen').value.trim(),
            ruleset:     document.getElementById('new-ruleset').value.trim(),
            theme:       document.getElementById('new-theme').value,
            badgeset:    document.getElementById('new-badgeset').value,
            description: document.getElementById('new-desc').value.trim(),
        })
    });
    const data = await res.json();
    if (data.success) {
        status.textContent = '';
        ['new-title','new-slug','new-game','new-type','new-gen','new-ruleset','new-desc'].forEach(id => {
            const el = document.getElementById(id); el.value = id === 'new-type' ? 'ROM Hack' : '';
        });
        document.getElementById('add-form').style.display = 'none';
        selected = slug;
        load();
    } else {
        status.textContent = data.message || 'Error creating challenge.';
    }
}

// Auto-size the embedded attempts iframe to its content.
window.addEventListener('message', function (e) {
    if (!e.data || e.data.type !== 'lamprozo-attempts-height') return;
    const f = document.querySelector('#cd-attempts iframe[data-challenge="' + e.data.challenge + '"]');
    if (f) f.style.height = Math.max(200, e.data.height) + 'px';
});

load();
</script>
