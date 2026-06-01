<?php
if (!defined('ABSPATH')) exit;
$theme_keys = function_exists('lamprozo_layout_themes') ? array_keys(lamprozo_layout_themes()) : ['emerald'];
$badge_keys = array_merge(['none'], function_exists('lamprozo_badge_sets') ? array_keys(lamprozo_badge_sets()) : []);
?>
<div class="wrap">
<h1>Challenges</h1>
<style>
.challenge-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:16px; margin:20px 0; }
.challenge-card { background:#fff; border:1px solid #ddd; border-radius:6px; padding:16px; }
.challenge-card h3 { margin:0 0 4px; }
.challenge-card .meta { font-size:0.85em; color:#666; margin:0 0 10px; }
.challenge-card .desc { font-size:0.9em; color:#444; margin:0 0 12px; }
.challenge-card .actions { display:flex; gap:8px; flex-wrap:wrap; }
.badge-active  { background:#e6f4ea; color:#1e7e34; border-radius:20px; padding:2px 10px; font-size:0.78em; font-weight:700; }
.badge-on-hold { background:#fff3cd; color:#856404; border-radius:20px; padding:2px 10px; font-size:0.78em; font-weight:700; }
.add-form { background:#fff; border:1px solid #ddd; border-radius:6px; padding:20px; max-width:600px; margin:20px 0; }
.add-form h2 { margin-top:0; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
.form-row label, .form-full label { display:block; font-weight:600; font-size:0.85em; margin-bottom:4px; }
.form-row input, .form-full input, .form-full textarea { width:100%; box-sizing:border-box; }
.form-full { margin-bottom:12px; }
.form-full textarea { height:70px; }
.attempts-embed { display:none; margin-top:12px; border-top:1px solid #e0e0e0; padding-top:10px; }
.attempts-embed iframe { width:100%; border:0; display:block; background:#f6f7f7; border-radius:4px; }
</style>

<div id="challenge-list" class="challenge-grid"></div>

<div class="add-form">
    <h2>Add New Challenge</h2>
    <div class="form-row">
        <div>
            <label>Title *</label>
            <input type="text" id="new-title" placeholder="e.g. Crystal Legacy" oninput="generateSlug()">
        </div>
        <div>
            <label>Slug *</label>
            <input type="text" id="new-slug" placeholder="e.g. crystal-legacy">
        </div>
    </div>
    <div class="form-row">
        <div>
            <label>Game</label>
            <input type="text" id="new-game" placeholder="e.g. Pokémon Crystal Legacy">
        </div>
        <div>
            <label>Type</label>
            <input type="text" id="new-type" placeholder="ROM Hack" value="ROM Hack">
        </div>
    </div>
    <div class="form-row">
        <div>
            <label>Generation</label>
            <input type="text" id="new-gen" placeholder="e.g. II">
        </div>
        <div>
            <label>Ruleset</label>
            <input type="text" id="new-ruleset" placeholder="e.g. Hardcore Nuzlocke">
        </div>
    </div>
    <div class="form-row">
        <div>
            <label>Background theme</label>
            <select id="new-theme">
                <?php foreach ($theme_keys as $tk): ?>
                <option value="<?php echo esc_attr($tk); ?>"><?php echo esc_html(ucfirst($tk)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Badge set</label>
            <select id="new-badgeset">
                <?php foreach ($badge_keys as $bk): ?>
                <option value="<?php echo esc_attr($bk); ?>"><?php echo esc_html(ucfirst($bk)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-full">
        <label>Description</label>
        <textarea id="new-desc" placeholder="A short description of this challenge..."></textarea>
    </div>
    <button class="button button-primary" onclick="createChallenge()">Add Challenge</button>
    <span id="add-status" style="margin-left:10px;font-size:0.9em"></span>
</div>
</div>

<script>
const apiBase = '<?php echo esc_js(rest_url('lamprozo/v1')); ?>';
const nonce   = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };
const themeOptions = <?php echo wp_json_encode($theme_keys); ?>;
const badgeOptions = <?php echo wp_json_encode($badge_keys); ?>;

function themeSelect(slug, current) {
    const opts = themeOptions.map(t =>
        `<option value="${t}"${t === (current||'emerald') ? ' selected' : ''}>${t.charAt(0).toUpperCase() + t.slice(1)}</option>`
    ).join('');
    return `<select onchange="updateTheme('${esc(slug)}', this.value)" style="flex:1;padding:3px 6px;border:1px solid #ccc;border-radius:4px">${opts}</select>`;
}

function badgeSelect(slug, current) {
    const opts = badgeOptions.map(b =>
        `<option value="${b}"${b === (current||'none') ? ' selected' : ''}>${b.charAt(0).toUpperCase() + b.slice(1)}</option>`
    ).join('');
    return `<select onchange="updateBadgeset('${esc(slug)}', this.value)" style="flex:1;padding:3px 6px;border:1px solid #ccc;border-radius:4px">${opts}</select>`;
}

function generateSlug() {
    const title = document.getElementById('new-title').value;
    document.getElementById('new-slug').value = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function renderChallenges(challenges) {
    const grid = document.getElementById('challenge-list');
    if (!challenges.length) { grid.innerHTML = '<p style="color:#888">No challenges yet.</p>'; return; }
    grid.innerHTML = challenges.map(c => {
        const isOnHold   = c.status === 'on_hold';
        const isComplete = c.status === 'completed';
        const badge = isComplete
            ? `<span class="badge-active" style="background:#ede7f6;color:#6a0dad">Completed</span>`
            : isOnHold
            ? `<span class="badge-on-hold">On Hold</span>`
            : `<span class="badge-active">Active</span>`;
        return `
        <div class="challenge-card">
            <h3>${esc(c.title)} ${badge}</h3>
            <p class="meta">${esc(c.type)} &middot; Gen ${esc(c.gen)} &middot; <code>${esc(c.slug)}</code></p>
            <p class="desc">${esc(c.description)}</p>
            <p class="meta" style="display:flex;align-items:center;gap:6px">
                <label style="font-weight:600;white-space:nowrap">Ruleset:</label>
                <input type="text" value="${esc(c.ruleset||'')}" placeholder="e.g. Hardcore Nuzlocke"
                    style="flex:1;padding:3px 6px;border:1px solid #ccc;border-radius:4px"
                    onchange="updateRuleset('${esc(c.slug)}', this.value)">
            </p>
            <p class="meta" style="display:flex;align-items:center;gap:6px">
                <label style="font-weight:600;white-space:nowrap">BG theme:</label>
                ${themeSelect(c.slug, c.theme)}
            </p>
            <p class="meta" style="display:flex;align-items:center;gap:6px">
                <label style="font-weight:600;white-space:nowrap">Badge set:</label>
                ${badgeSelect(c.slug, c.badgeset)}
            </p>
            <div class="actions">
                <a href="/${esc(c.slug)}" target="_blank" class="button button-small">View Page</a>
                <button class="button button-small" onclick="toggleAttempts('${esc(c.slug)}', this)">▸ Attempts</button>
                ${!isComplete ? `<button class="button button-small" onclick="toggleStatus('${esc(c.slug)}','${isOnHold ? 'active' : 'on_hold'}')">${isOnHold ? 'Set Active' : 'Put On Hold'}</button>` : ''}
                ${!isComplete ? `<button class="button button-small" style="background:#6a0dad;color:#fff;border-color:#6a0dad" onclick="toggleStatus('${esc(c.slug)}','completed')">Mark Completed</button>` : `<button class="button button-small" onclick="toggleStatus('${esc(c.slug)}','active')">Reopen</button>`}
                ${!['sterling-silver','renegade-platinum','platinum-kaizo'].includes(c.slug)
                    ? `<button class="button button-small button-link-delete" onclick="deleteChallenge('${esc(c.slug)}','${esc(c.title)}')">Delete</button>`
                    : ''}
            </div>
            <div class="attempts-embed" id="att-${esc(c.slug)}"></div>
        </div>`;
    }).join('');
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function load() {
    fetch(apiBase + '/challenges', { headers })
        .then(r => r.json()).then(renderChallenges);
}

async function createChallenge() {
    const slug  = document.getElementById('new-slug').value.trim();
    const title = document.getElementById('new-title').value.trim();
    const status = document.getElementById('add-status');
    if (!slug || !title) { status.textContent = 'Title and slug are required.'; return; }

    status.textContent = 'Creating...';
    const res = await fetch(apiBase + '/challenges', {
        method: 'POST', headers,
        body: JSON.stringify({
            slug,
            title,
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
        status.textContent = `Created! Visit /${slug}`;
        ['new-title','new-slug','new-game','new-type','new-gen','new-ruleset','new-desc'].forEach(id => {
            const el = document.getElementById(id);
            el.value = id === 'new-type' ? 'ROM Hack' : '';
        });
        load();
    } else {
        status.textContent = data.message || 'Error creating challenge.';
    }
}

async function updateRuleset(slug, ruleset) {
    const res = await fetch(apiBase + '/challenges/' + slug, {
        method: 'PUT', headers,
        body: JSON.stringify({ ruleset })
    });
    const data = await res.json();
    if (!data.success) alert(data.message || 'Error saving ruleset.');
}

async function updateTheme(slug, theme) {
    const res = await fetch(apiBase + '/challenges/' + slug, {
        method: 'PUT', headers,
        body: JSON.stringify({ theme })
    });
    const data = await res.json();
    if (!data.success) alert(data.message || 'Error saving theme.');
}

async function updateBadgeset(slug, badgeset) {
    const res = await fetch(apiBase + '/challenges/' + slug, {
        method: 'PUT', headers,
        body: JSON.stringify({ badgeset })
    });
    const data = await res.json();
    if (!data.success) alert(data.message || 'Error saving badge set.');
}

// Expand a challenge card to show its attempts editor inline (embedded iframe).
function toggleAttempts(slug, btn) {
    const card = btn.closest('.challenge-card');
    const box  = document.getElementById('att-' + slug);
    if (!box) return;
    if (box.dataset.open === '1') {
        box.dataset.open = '0';
        box.style.display = 'none';
        box.innerHTML = '';
        if (card) card.style.gridColumn = '';
        btn.textContent = '▸ Attempts';
        return;
    }
    box.dataset.open = '1';
    box.style.display = 'block';
    if (card) card.style.gridColumn = '1 / -1';   // span full width so the editor has room
    btn.textContent = '▾ Attempts';
    const f = document.createElement('iframe');
    f.src = '/?lamprozo_attempts_embed=1&challenge=' + encodeURIComponent(slug);
    f.setAttribute('data-challenge', slug);
    f.style.height = '320px';
    box.appendChild(f);
}

// Auto-size each embedded attempts iframe to its content.
window.addEventListener('message', function (e) {
    if (!e.data || e.data.type !== 'lamprozo-attempts-height') return;
    document.querySelectorAll('.attempts-embed iframe').forEach(function (f) {
        if (f.getAttribute('data-challenge') === e.data.challenge) {
            f.style.height = Math.max(200, e.data.height) + 'px';
        }
    });
});

async function toggleStatus(slug, newStatus) {
    const res = await fetch(apiBase + '/challenges/' + slug, {
        method: 'PUT', headers,
        body: JSON.stringify({ status: newStatus })
    });
    const data = await res.json();
    if (data.success) load();
    else alert(data.message || 'Error updating challenge.');
}

async function deleteChallenge(slug, title) {
    if (!confirm(`Delete "${title}"? This cannot be undone.`)) return;
    const res = await fetch(apiBase + '/challenges/' + slug, { method: 'DELETE', headers });
    const data = await res.json();
    if (data.success) load();
    else alert(data.message || 'Error deleting challenge.');
}

load();
</script>
