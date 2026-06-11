<?php
// $preselect / $embed may be injected by the embed endpoint; otherwise derive.
$preselect = isset($preselect) ? $preselect : (isset($_GET['challenge']) ? sanitize_title($_GET['challenge']) : '');
$embed     = isset($embed) ? (bool) $embed : false;

// Per-challenge fight lists, so the attempt's "current fight" stepper can resolve
// the level cap + badge count client-side.
$ffc_all_fights = [];
if (function_exists('lamprozo_get_challenges_data')) {
    foreach (lamprozo_get_challenges_data() as $cslug => $cdata) {
        $ffc_all_fights[$cslug] = $cdata['fights'] ?? [];
    }
}
?>
<div id="attempts-app" style="max-width:1000px;padding:<?php echo $embed ? '0' : '1.5rem 0'; ?>">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;gap:0.75rem">
        <?php if (!$embed): ?><h1 style="margin:0">Attempts</h1><?php endif; ?>
        <div style="display:flex;gap:0.75rem;align-items:center;<?php echo $embed ? 'margin-left:auto' : ''; ?>">
            <select id="challenge-select" style="<?php echo $embed ? 'display:none' : 'padding:0.4rem 0.75rem;border-radius:4px;border:1px solid #ccc'; ?>">
                <?php foreach (lamprozo_get_challenges() as $slug => $name): ?>
                <option value="<?php echo esc_attr($slug); ?>"<?php selected($preselect, $slug); ?>><?php echo esc_html($name); ?></option>
                <?php endforeach; ?>
            </select>
            <button id="btn-new-attempt" class="button button-primary">+ New Attempt</button>
            <button id="btn-save" class="button button-primary">Save Changes</button>
        </div>
    </div>

    <div id="attempts-notice" style="display:none;margin-bottom:1rem"></div>

    <div id="vods-section"></div>

    <div id="attempts-list"></div>
</div>

<style>
.attempt-card { background:#fff; border:1px solid #ddd; border-radius:6px; margin-bottom:12px; overflow:hidden; }
.attempt-card__header { display:flex; align-items:center; gap:12px; padding:10px 14px; background:#f9f9f9; border-bottom:1px solid #eee; cursor:pointer; user-select:none; }
.attempt-card__header:hover { background:#f0f0f0; }
.attempt-card__number { font-weight:700; min-width:110px; }
.attempt-card__split { color:#555; font-size:0.9em; flex:1; }
.attempt-card__summary { color:#555; font-size:0.85em; white-space:nowrap; }
.attempt-card__notes-preview { color:#888; font-size:0.85em; font-style:italic; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.attempt-card__toggle { margin-left:auto; color:#888; font-size:0.85em; flex-shrink:0; }
.attempt-card__body { padding:14px; display:none; }
.attempt-card__body.open { display:block; }
.attempt-card__row { display:flex; gap:12px; margin-bottom:10px; flex-wrap:wrap; }
.attempt-card__row label { font-size:0.85em; font-weight:600; color:#555; display:block; margin-bottom:3px; }
.attempt-field { flex:1; min-width:160px; }
.attempt-field input, .attempt-field select, .attempt-field textarea { width:100%; padding:5px 8px; border:1px solid #ccc; border-radius:4px; }
.attempt-field textarea { resize:vertical; min-height:60px; }
.vod-list { display:flex; flex-direction:column; gap:8px; margin-bottom:0; max-height:330px; overflow-y:auto; padding:2px; }
.vod-item { display:flex; flex-direction:column; gap:4px; padding:6px; border:1px solid #eee; border-radius:4px; background:#fafafa; }
.vod-row { display:flex; gap:6px; align-items:center; }
.vod-row input { padding:4px 7px; border:1px solid #ccc; border-radius:4px; }
.vod-label-input { width:100px; }
.vod-url-input { flex:1; }
.vod-dur-input { width:90px; }
.vod-summary-input { width:100%; padding:5px 7px; border:1px solid #ccc; border-radius:4px; font-family:inherit; font-size:0.9em; resize:vertical; min-height:34px; }
.vods-section { background:#fff; border:1px solid #ddd; border-radius:6px; margin-bottom:18px; padding:10px 14px; }
.vods-section__title { font-size:1em; font-weight:700; cursor:pointer; user-select:none; margin:0; list-style-position:inside; }
.vods-section__title span { font-weight:400; color:#888; }
.vods-section[open] .vods-section__title { margin-bottom:8px; }
.vods-section__bar { margin-bottom:8px; }
.vod-attempts { display:flex; align-items:center; flex-wrap:wrap; gap:6px; padding:2px 2px 0; font-size:0.82em; color:#555; }
.vod-attempts__label { font-weight:600; }
.vod-chip { display:inline-flex; align-items:center; gap:2px; padding:1px 4px 1px 8px; border:1px solid #9146ff; border-radius:12px; background:#f3ecff; color:#333; }
.vod-chip button { border:none; background:none; cursor:pointer; color:#9146ff; font-size:1.05em; line-height:1; padding:0 2px; }
.vod-chip button:hover { color:#d63638; }
.vod-add-att { font-size:0.82em; padding:2px 4px; border:1px solid #ccc; border-radius:4px; background:#fff; }
.box-grid { display:flex; flex-direction:column; gap:6px; margin-bottom:8px; }
.box-mon { display:flex; align-items:center; gap:6px; padding:4px 6px; border:1px solid #eee; border-radius:4px; background:#fafafa; }
.box-mon--dead { opacity:0.65; background:#f7eaea; }
.box-mon__sprite { width:40px; height:40px; image-rendering:pixelated; object-fit:contain; flex-shrink:0; }
.box-mon__species { width:130px; }
.box-mon__nick { width:120px; }
.box-mon__kills { width:64px; }
.box-mon input { padding:4px 7px; border:1px solid #ccc; border-radius:4px; }
.box-import { margin-top:8px; }
.box-import summary { cursor:pointer; font-size:0.85em; color:#2271b1; }
.box-import__text { width:100%; min-height:90px; margin:6px 0; font-family:monospace; font-size:0.82em; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box; }
</style>

<script>
(function() {
    const nonce    = '<?php echo wp_create_nonce('wp_rest'); ?>';
    const apiBase  = '<?php echo esc_url(rest_url('lamprozo/v1/attempts/')); ?>';
    const vodsBase = '<?php echo esc_url(rest_url('lamprozo/v1/vods/')); ?>';
    let attempts  = [];
    let vods      = [];
    let challenge = document.getElementById('challenge-select').value;
    let openCards = new Set();
    const CHALLENGE_FIGHTS = <?php echo wp_json_encode($ffc_all_fights ?: new stdClass()); ?>;
    function fightsFor()   { return (CHALLENGE_FIGHTS && CHALLENGE_FIGHTS[challenge]) || []; }
    function gymTotal(fs)  { return fs.filter(f => f.badge).length; }
    function gymsAt(fs, step) { let g = 0; for (let k = 0; k < Math.min(step, fs.length); k++) if (fs[k].badge) g++; return g; }
    function inferStep(fs, badgeNum) { let g = 0; for (let k = 0; k < fs.length; k++) if (fs[k].badge) { if (++g === badgeNum) return k + 1; } return badgeNum > 0 ? fs.length : 0; }
    function fightLabel(fs, step) {
        if (!fs.length) return 'No fights defined';
        if (step >= fs.length) return '✓ All fights done';
        const f = fs[step];
        return (f.name || ('Fight ' + (step + 1))) + (f.cap ? ' — cap ' + f.cap : '');
    }

    function headers() { return { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }; }

    function notice(msg, type = 'success') {
        const el = document.getElementById('attempts-notice');
        el.innerHTML = `<div class="notice notice-${type} inline"><p>${msg}</p></div>`;
        el.style.display = 'block';
        setTimeout(() => el.style.display = 'none', 3000);
    }

    function esc(str) {
        return String(str||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function statusLabel(s) { return s === 'failed' ? 'Wiped' : s === 'completed' ? 'Completed' : 'Ongoing'; }
    function statusColor(s) { return s === 'ongoing' ? '#00a32a' : s === 'failed' ? '#d63638' : '#9146ff'; }

    function renderCard(attempt, i) {
        const isOpen = openCards.has(i) || attempt.status === 'ongoing';
        const notes  = attempt.notes || '';
        const cap    = attempt.cap || '';
        const badges = attempt.badges || '';
        const badgeNum = parseInt((String(badges).match(/\d+/) || ['0'])[0], 10);
        const fights = fightsFor();
        const step   = (attempt.step != null) ? attempt.step : inferStep(fights, badgeNum);
        const deaths = attempt.deaths ?? '';
        const box    = attempt.box || [];
        const deadCount = box.filter(m => m.alive === false).length;
        const deathsShown  = (deaths !== '' && deaths != null) ? deaths : deadCount;
        const notesPreview = notes.length > 80 ? notes.slice(0, 80) + '…' : notes;

        const boxRows = box.map((m, bi) => `
            <div class="box-mon box-mon--${m.alive === false ? 'dead' : 'alive'}">
                <img class="box-mon__sprite" src="${spriteUrl(m.species)}" alt="" onerror="this.style.visibility='hidden'">
                <input class="box-mon__species" type="text" placeholder="Species" value="${esc(m.species||'')}"
                    onchange="updateBoxMon(${i},${bi},'species',this.value)">
                <input class="box-mon__nick" type="text" placeholder="Nickname" value="${esc(m.nickname||'')}"
                    onchange="updateBoxMon(${i},${bi},'nickname',this.value)">
                <input class="box-mon__kills" type="number" min="0" title="Kills" value="${m.kills||0}"
                    onchange="updateBoxMon(${i},${bi},'kills',this.value)">
                <button class="button button-small" onclick="toggleBoxAlive(${i},${bi})">${m.alive === false ? '💀 Dead' : 'Alive'}</button>
                <button class="button button-small button-link-delete" onclick="removeBoxMon(${i},${bi})">✕</button>
            </div>`).join('');

        return `<div class="attempt-card">
            <div class="attempt-card__header" onclick="toggleCard(${i})">
                <span class="attempt-card__number">Attempt #${attempt.number}</span>
                <span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:0.78em;font-weight:700;background:${statusColor(attempt.status)}20;color:${statusColor(attempt.status)}">${statusLabel(attempt.status)}</span>
                <span class="attempt-card__summary">🎖 ${badgeNum}</span>
                ${notes ? `<span class="attempt-card__notes-preview" title="${esc(notes)}">${esc(notesPreview)}</span>` : '<span style="flex:1"></span>'}
                <span class="attempt-card__toggle">${isOpen ? '▲ collapse' : '▼ expand'}</span>
            </div>
            <div class="attempt-card__body ${isOpen ? 'open' : ''}">
                <div class="attempt-card__row">
                    <div class="attempt-field" style="min-width:130px;max-width:160px">
                        <label>Status</label>
                        <select onchange="updateField(${i},'status',this.value)">
                            <option value="ongoing"   ${attempt.status==='ongoing'   ? 'selected':''}>Ongoing</option>
                            <option value="failed"    ${attempt.status==='failed'    ? 'selected':''}>Wiped</option>
                            <option value="completed" ${attempt.status==='completed' ? 'selected':''}>Completed</option>
                        </select>
                    </div>
                    <div class="attempt-field" style="min-width:100px;max-width:130px">
                        <label>Level cap</label>
                        <input type="text" id="cap-input-${i}" placeholder="e.g. 16" value="${esc(cap)}"
                            onchange="saveMeta(${i},'cap',this.value)">
                    </div>
                    ${fights.length ? `
                    <div class="attempt-field" style="flex:1 1 300px;min-width:240px">
                        <label>Current fight <span style="font-weight:400;color:#888">· 🎖 ${gymsAt(fights, step)}/${gymTotal(fights)}</span></label>
                        <div style="display:flex;gap:5px;align-items:center">
                            <button type="button" class="button button-small" style="min-width:28px;padding:0 8px" onclick="bumpStep(${i},-1)" ${step<=0?'disabled':''}>&minus;</button>
                            <span style="flex:1;min-width:0;padding:5px 8px;border:1px solid #e0e0e0;border-radius:4px;background:#fafafa;font-size:0.86em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(fightLabel(fights, step))}</span>
                            <button type="button" class="button button-small" style="min-width:28px;padding:0 8px" onclick="bumpStep(${i},1)" ${step>=fights.length?'disabled':''}>+</button>
                        </div>
                    </div>` : `
                    <div class="attempt-field" style="flex:0 0 auto;min-width:0">
                        <label>Badges</label>
                        <div style="display:flex;gap:4px;align-items:center">
                            <button type="button" class="button button-small" style="min-width:28px;padding:0 8px" onclick="bumpBadges(${i},-1)">&minus;</button>
                            <input type="number" min="0" id="badges-input-${i}" value="${badgeNum}" style="width:46px;flex:0 0 46px;text-align:center"
                                onchange="saveMeta(${i},'badges',this.value)">
                            <button type="button" class="button button-small" style="min-width:28px;padding:0 8px" onclick="bumpBadges(${i},1)">+</button>
                        </div>
                    </div>`}
                    <div class="attempt-field" style="min-width:90px;max-width:120px">
                        <label>Deaths</label>
                        <input type="text" placeholder="auto: ${deadCount}" value="${esc(deaths)}"
                            onchange="saveMeta(${i},'deaths',this.value)">
                    </div>
                </div>
                <div class="attempt-card__row">
                    <div class="attempt-field" style="flex:2">
                        <label>Notes</label>
                        <textarea placeholder="What happened this run?" onchange="updateField(${i},'notes',this.value)">${esc(notes)}</textarea>
                    </div>
                </div>
                <div style="padding-top:4px">
                    <label style="font-size:0.85em;font-weight:600;color:#555;display:block;margin-bottom:6px">
                        Box <span style="font-weight:400;color:#888">(${box.length} Pokémon, ${deadCount} dead)</span>
                    </label>
                    <div class="box-grid">${boxRows}</div>
                    <button class="button button-small" onclick="addBoxMon(${i})">+ Add Pokémon</button>
                    <div class="box-import">
                        <details>
                            <summary>Import…</summary>
                            <textarea id="showdown-${i}" class="box-import__text" placeholder="Paste a Pokémon Showdown team export here, then click Import. Re-importing merges — it adds new mons and keeps any you've marked dead."></textarea>
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                <button class="button button-small" onclick="importShowdown(${i})">Import Showdown</button>
                                <span style="color:#ccc">|</span>
                                <label style="font-size:0.85em;color:#555">HZLA JSON: <input type="file" accept=".json" onchange="loadFragsheetFile(${i},this)"></label>
                            </div>
                        </details>
                    </div>
                </div>
                <div style="margin-top:12px;padding-top:10px;border-top:1px solid #eee">
                    <button class="button button-small button-link-delete" onclick="deleteAttempt(${i})">Delete attempt</button>
                </div>
            </div>
        </div>`;
    }

    function render() {
        renderVods();
        document.getElementById('attempts-list').innerHTML = attempts.length
            ? attempts.map((a, i) => renderCard(a, i)).join('')
            : '<p style="color:#888">No attempts yet.</p>';
    }

    // Challenge-level VOD list. Each VOD links to one or more attempt NUMBERS, shown as
    // removable chips with a compact dropdown to attach more (a wipe-spanning VOD covers
    // two attempts). Numbers run newest-first so recent attempts are easy to reach.
    function renderVods() {
        const numbersDesc = attempts.map(a => a.number).sort((x, y) => y - x);
        const rows = vods.map((v, vi) => {
            const linked = (v.attempts || []).slice().sort((x, y) => y - x);
            const chips  = linked.map(n =>
                `<span class="vod-chip">#${n}<button title="Unlink attempt #${n}" onclick="removeVodAttempt(${vi},${n})">✕</button></span>`
            ).join('');
            const addable = numbersDesc.filter(n => !linked.includes(n));
            const addSel = addable.length
                ? `<select class="vod-add-att" onchange="addVodAttempt(${vi},this.value)"><option value="">+ attempt…</option>${addable.map(n => `<option value="${n}">#${n}</option>`).join('')}</select>`
                : '';
            return `<div class="vod-item">
                <div class="vod-row">
                    <input class="vod-url-input" type="text" placeholder="https://youtu.be/..." value="${esc(v.url||'')}"
                        onchange="updateVod(${vi},'url',this.value)" onblur="fetchYtMeta(${vi},this.value)">
                    <input class="vod-label-input" type="text" placeholder="Title (auto-filled from YouTube)" value="${esc(v.label||'')}"
                        id="vod-label-${vi}" onchange="updateVod(${vi},'label',this.value)">
                    <input class="vod-dur-input" type="text" placeholder="Duration (e.g. 1:23:45)"
                        id="vod-dur-${vi}" value="${esc(v.duration||'')}" onchange="updateVod(${vi},'duration',this.value)">
                    <button class="button button-small" onclick="removeVod(${vi})">✕</button>
                </div>
                <textarea class="vod-summary-input" placeholder="What happened in this VOD?"
                    onchange="updateVod(${vi},'summary',this.value)">${esc(v.summary||'')}</textarea>
                <div class="vod-attempts"><span class="vod-attempts__label">Covers:</span>${chips || '<em style="color:#bbb">unlinked</em>'}${addSel}</div>
            </div>`;
        }).join('');
        document.getElementById('vods-section').innerHTML = `<details class="vods-section"${vods.length ? '' : ' open'}>
            <summary class="vods-section__title">VODs <span>(${vods.length})</span></summary>
            <div class="vods-section__bar"><button class="button button-small" onclick="addVod()">+ Add VOD</button></div>
            <div class="vod-list">${rows || '<p style="color:#888;margin:0">No VODs yet.</p>'}</div>
        </details>`;
    }

    window.fetchYtMeta = (vi, url) => {
        if (!url || !vods[vi]) return;
        fetch('<?php echo esc_url(rest_url('lamprozo/v1/yt-meta')); ?>?url=' + encodeURIComponent(url), { headers: headers() })
            .then(r => r.json())
            .then(data => {
                if (data.title && !vods[vi].label) {
                    vods[vi].label = data.title;
                    const labelEl = document.getElementById(`vod-label-${vi}`);
                    if (labelEl) labelEl.value = data.title;
                }
                if (data.duration && !vods[vi].duration) {
                    vods[vi].duration = data.duration;
                    const durEl = document.getElementById(`vod-dur-${vi}`);
                    if (durEl) durEl.value = data.duration;
                }
            });
    };

    // ── Box helpers ──────────────────────────────────────────────────────────
    function spriteSlug(s) { return String(s||'').toLowerCase().replace(/[^a-z0-9]+/g, '-'); }
    function spriteUrl(s)  { return 'https://img.pokemondb.net/sprites/heartgold-soulsilver/normal/' + spriteSlug(s) + '.png'; }

    // Parse a Pokémon Showdown team export into [{species, nickname, alive, nature, ability}].
    function parseShowdown(text) {
        const mons = [];
        String(text || '').split(/\n\s*\n/).forEach(block => {
            const lines = block.split('\n').map(l => l.trim()).filter(Boolean);
            if (!lines.length) return;
            let head = lines[0].split(' @ ')[0].trim();   // drop held item
            // Strip trailing gender marker "(M)" / "(F)".
            head = head.replace(/\s*\((?:M|F)\)\s*$/i, '').trim();
            let species = head, nickname = '';
            const m = head.match(/^(.*?)\s*\((.+)\)\s*$/);
            if (m) { nickname = m[1].trim(); species = m[2].trim(); }
            if (!species) return;
            const entry = { species, nickname, alive: true };
            lines.slice(1).forEach(l => {
                const am = l.match(/^Ability:\s*(.+)$/i);
                if (am) entry.ability = am[1].trim();
                const nm = l.match(/^(.+?)\s+Nature$/i);
                if (nm) entry.nature = nm[1].trim();
            });
            mons.push(entry);
        });
        return mons;
    }

    // JS port of lamprozo_box_from_fragsheet(): HZLA fragsheet object -> box array.
    function boxFromFragsheet(fragsheet) {
        if (!fragsheet || typeof fragsheet !== 'object') return [];
        const shadow = {};
        for (const [species, mon] of Object.entries(fragsheet)) {
            const frag    = parseInt(mon.fragCount, 10) || 0;
            const prevo   = parseInt(mon.prevoFragCount, 10) || 0;
            const batchId = (mon.setData && mon.setData['My Box'] && mon.setData['My Box'].boxImportBatchId) || '';
            if (prevo > 0 && prevo > frag) shadow[species] = true;
            else if (frag === 0 && prevo === 0 && batchId === '') shadow[species] = true;
        }
        const box = [];
        for (const [species, mon] of Object.entries(fragsheet)) {
            if (mon.hide) continue;
            if (shadow[species]) continue;
            const myBox = (mon.setData && mon.setData['My Box']) || {};
            const entry = {
                species,
                nickname: mon.nn || '',
                alive: mon.alive === true,
                kills: (parseInt(mon.fragCount, 10) || 0) + (parseInt(mon.prevoFragCount, 10) || 0),
            };
            if (myBox.met)     entry.met     = myBox.met;
            if (myBox.nature)  entry.nature  = myBox.nature;
            if (myBox.ability) entry.ability = myBox.ability;
            if (myBox.ivs)     entry.ivs     = myBox.ivs;
            box.push(entry);
        }
        box.sort((a, b) => (b.kills || 0) - (a.kills || 0));
        return box;
    }

    window.importShowdown = (i) => {
        const text = document.getElementById('showdown-' + i).value;
        const parsed = parseShowdown(text);
        if (!parsed.length) { notice('No Pokémon found in that paste.', 'warning'); return; }
        attempts[i].box = attempts[i].box || [];
        let added = 0;
        parsed.forEach(p => {
            const dup = attempts[i].box.find(e =>
                (e.species||'').toLowerCase() === p.species.toLowerCase() &&
                (e.nickname||'').toLowerCase() === (p.nickname||'').toLowerCase());
            if (dup) {
                if (!dup.nature && p.nature)   dup.nature = p.nature;
                if (!dup.ability && p.ability) dup.ability = p.ability;
            } else {
                attempts[i].box.push(p);
                added++;
            }
        });
        notice(`Imported ${added} new Pokémon (${parsed.length - added} already in box).`);
        render();
    };

    window.loadFragsheetFile = (i, input) => {
        const file = input.files[0];
        if (!file) return;
        if ((attempts[i].box || []).length && !confirm('Replace the current box with this HZLA import?')) {
            input.value = ''; return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            try {
                attempts[i].box = boxFromFragsheet(JSON.parse(e.target.result));
                delete attempts[i].fragsheet;
                render();
            } catch(err) {
                alert('Invalid JSON file: ' + err.message);
            }
        };
        reader.readAsText(file);
    };

    window.addBoxMon    = (i) => { attempts[i].box = attempts[i].box || []; attempts[i].box.push({species:'',nickname:'',alive:true,kills:0}); render(); };
    window.removeBoxMon = (i, bi) => { attempts[i].box.splice(bi, 1); render(); };
    window.toggleBoxAlive = (i, bi) => { attempts[i].box[bi].alive = attempts[i].box[bi].alive === false; render(); };
    window.updateBoxMon = (i, bi, key, val) => {
        if (key === 'kills') val = parseInt(val, 10) || 0;
        attempts[i].box[bi][key] = val;
        if (key === 'species') render(); // refresh sprite
    };

    window.toggleCard    = (i) => { openCards.has(i) ? openCards.delete(i) : openCards.add(i); render(); };
    window.updateField   = (i, key, val) => { attempts[i][key] = val; };
    // Persist a single meta field (cap/badges/deaths) immediately, without
    // touching the box — so it survives the party sync and needs no Save click.
    window.bumpBadges = (i, delta) => {
        const cur = parseInt((String(attempts[i].badges || '').match(/\d+/) || ['0'])[0], 10);
        const next = Math.max(0, cur + delta);
        const el = document.getElementById('badges-input-' + i);
        if (el) el.value = next;
        saveMeta(i, 'badges', String(next));
    };

    // Advance/retreat the attempt's fight pointer; the cap + badge count follow it.
    window.bumpStep = (i, delta) => {
        const fights = fightsFor();
        const cur = (attempts[i].step != null)
            ? attempts[i].step
            : inferStep(fights, parseInt((String(attempts[i].badges || '').match(/\d+/) || ['0'])[0], 10));
        const next = Math.max(0, Math.min(fights.length, cur + delta));
        if (next === cur) return;
        attempts[i].step = next;
        fetch(apiBase + challenge + '/meta', {
            method: 'POST', headers: headers(),
            body: JSON.stringify({ number: attempts[i].number, step: next })
        }).then(r => r.json()).then(d => {
            if (d && d.success) {
                if (d.cap    !== undefined) attempts[i].cap    = d.cap;
                if (d.badges !== undefined) attempts[i].badges = d.badges;
                if (d.step   !== undefined) attempts[i].step   = d.step;
            }
            render();
        }).catch(() => {});
    };

    window.saveMeta = (i, key, val) => {
        attempts[i][key] = val;
        fetch(apiBase + challenge + '/meta', {
            method: 'POST', headers: headers(),
            body: JSON.stringify({ number: attempts[i].number, [key]: val })
        }).then(r => r.json()).then(d => {
            // Badge count may auto-derive a new level cap server-side; reflect it.
            if (key === 'badges' && d && d.cap !== undefined && d.cap !== attempts[i].cap) {
                attempts[i].cap = d.cap;
                const capEl = document.getElementById('cap-input-' + i);
                if (capEl) capEl.value = d.cap;
            }
        }).catch(() => {});
    };
    function newVodId() { return 'v' + Date.now().toString(16) + Math.floor(Math.random()*0xffff).toString(16); }
    window.updateVod        = (vi, key, val) => { vods[vi][key] = val; };
    window.addVodAttempt    = (vi, val) => {
        const n = parseInt(val, 10);
        if (!n) return;
        const set = new Set(vods[vi].attempts || []);
        set.add(n);
        vods[vi].attempts = [...set].sort((a, b) => a - b);
        renderVods();
    };
    window.removeVodAttempt = (vi, n) => {
        vods[vi].attempts = (vods[vi].attempts || []).filter(x => x !== n);
        renderVods();
    };
    window.addVod = () => {
        const ongoing = attempts.find(a => a.status === 'ongoing');
        // Prepend so the new VOD lands at the top — no scrolling to find it.
        vods.unshift({ id: newVodId(), url:'', label:'VOD', duration:'', summary:'', attempts: ongoing ? [ongoing.number] : [] });
        renderVods();
        const list = document.querySelector('.vod-list');
        if (list) list.scrollTop = 0;
    };
    window.removeVod = (vi) => { vods.splice(vi, 1); renderVods(); };
    window.deleteAttempt = (i) => { if (confirm('Delete attempt #' + attempts[i].number + '?')) { attempts.splice(i, 1); render(); } };

    document.getElementById('challenge-select').addEventListener('change', function() {
        challenge = this.value; openCards.clear(); load();
    });

    document.getElementById('btn-save').addEventListener('click', () => {
        Promise.all([
            fetch(apiBase + challenge,  { method: 'POST', headers: headers(), body: JSON.stringify(attempts) }).then(r => r.json()),
            fetch(vodsBase + challenge, { method: 'POST', headers: headers(), body: JSON.stringify(vods) }).then(r => r.json()),
        ])
            .then(() => {
                notice('Saved!');
                fetch('<?php echo esc_js(rest_url('firefly/v1/clear-cache')); ?>', {
                    method: 'POST',
                    headers: { ...headers(), 'X-Firefly-CLI-Key': 'firefly-cli-dev-key' },
                    body: JSON.stringify({ template: 'lamprozo' })
                });
            })
            .catch(() => notice('Save failed.', 'error'));
    });

    window.doNewAttempt = (previous_status) => {
        fetch(apiBase + challenge + '/new', { method: 'POST', headers: headers(), body: JSON.stringify({ previous_status }) })
            .then(r => r.json())
            .then(data => { notice('Started attempt #' + data.number + '!'); openCards.clear(); load(); })
            .catch(() => notice('Failed to create attempt.', 'error'));
    };
    window.cancelNewAttempt = () => { const el = document.getElementById('attempts-notice'); el.style.display = 'none'; el.innerHTML = ''; };

    document.getElementById('btn-new-attempt').addEventListener('click', () => {
        // No ongoing attempt -> just start one.
        if (!attempts.some(a => a.status === 'ongoing')) { window.doNewAttempt('failed'); return; }
        // Otherwise ask how the current run ended, with clear inline choices.
        const el = document.getElementById('attempts-notice');
        el.style.display = 'block';
        el.innerHTML = `<div class="notice notice-warning inline" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0">
            <strong>Start a new attempt — how did the current run end?</strong>
            <button class="button button-small" onclick="doNewAttempt('failed')">💀 Wiped</button>
            <button class="button button-small" onclick="doNewAttempt('completed')">✓ Completed</button>
            <button class="button button-small" onclick="cancelNewAttempt()">Cancel</button>
        </div>`;
    });

    function load() {
        Promise.all([
            fetch(apiBase + challenge, { headers: headers() }).then(r => r.json()),
            fetch(vodsBase + challenge, { headers: headers() }).then(r => r.json()),
        ]).then(([a, v]) => {
            attempts = Array.isArray(a) ? a : [];
            vods     = Array.isArray(v) ? v : [];
            render();
        });
    }

    load();
})();
</script>
<?php if ($embed): ?>
<script>
// Report content height to the parent (Challenges page) so the iframe auto-sizes.
(function () {
    function reportHeight() {
        try {
            parent.postMessage({
                type: 'lamprozo-attempts-height',
                challenge: <?php echo wp_json_encode($preselect); ?>,
                height: document.body.scrollHeight + 24
            }, '*');
        } catch (e) {}
    }
    window.addEventListener('load', reportHeight);
    if (window.MutationObserver) {
        new MutationObserver(reportHeight).observe(document.body, { subtree: true, childList: true, attributes: true, characterData: true });
    }
    setInterval(reportHeight, 800);
})();
</script>
<?php endif; ?>
