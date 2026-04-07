<?php if (!defined('ABSPATH')) exit; ?>
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
            <div class="actions">
                <a href="/${esc(c.slug)}" target="_blank" class="button button-small">View Page</a>
                <a href="/wp-admin/admin.php?page=lamprozo-attempts&challenge=${esc(c.slug)}" class="button button-small">Attempts</a>
                ${!isComplete ? `<button class="button button-small" onclick="toggleStatus('${esc(c.slug)}','${isOnHold ? 'active' : 'on_hold'}')">${isOnHold ? 'Set Active' : 'Put On Hold'}</button>` : ''}
                ${!isComplete ? `<button class="button button-small" style="background:#6a0dad;color:#fff;border-color:#6a0dad" onclick="toggleStatus('${esc(c.slug)}','completed')">Mark Completed</button>` : `<button class="button button-small" onclick="toggleStatus('${esc(c.slug)}','active')">Reopen</button>`}
                ${!['sterling-silver','renegade-platinum','platinum-kaizo'].includes(c.slug)
                    ? `<button class="button button-small button-link-delete" onclick="deleteChallenge('${esc(c.slug)}','${esc(c.title)}')">Delete</button>`
                    : ''}
            </div>
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
            description: document.getElementById('new-desc').value.trim(),
        })
    });
    const data = await res.json();
    if (data.success) {
        status.textContent = `Created! Visit /${slug}`;
        ['new-title','new-slug','new-game','new-type','new-gen','new-desc'].forEach(id => {
            const el = document.getElementById(id);
            el.value = id === 'new-type' ? 'ROM Hack' : '';
        });
        load();
    } else {
        status.textContent = data.message || 'Error creating challenge.';
    }
}

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
