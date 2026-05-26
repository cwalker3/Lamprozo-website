<div id="attempts-app" style="max-width:1000px;padding:1.5rem 0">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem">
        <h1 style="margin:0">Attempts</h1>
        <div style="display:flex;gap:0.75rem;align-items:center">
            <select id="challenge-select" style="padding:0.4rem 0.75rem;border-radius:4px;border:1px solid #ccc">
                <?php foreach (lamprozo_get_challenges() as $slug => $name): ?>
                <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                <?php endforeach; ?>
            </select>
            <button id="btn-new-attempt" class="button button-primary">+ New Attempt</button>
            <button id="btn-save" class="button button-primary">Save Changes</button>
        </div>
    </div>

    <div id="attempts-notice" style="display:none;margin-bottom:1rem"></div>

    <div id="attempts-list"></div>
</div>

<style>
.attempt-card { background:#fff; border:1px solid #ddd; border-radius:6px; margin-bottom:12px; overflow:hidden; }
.attempt-card__header { display:flex; align-items:center; gap:12px; padding:10px 14px; background:#f9f9f9; border-bottom:1px solid #eee; cursor:pointer; user-select:none; }
.attempt-card__header:hover { background:#f0f0f0; }
.attempt-card__number { font-weight:700; min-width:110px; }
.attempt-card__split { color:#555; font-size:0.9em; flex:1; }
.attempt-card__toggle { margin-left:auto; color:#888; font-size:0.85em; }
.attempt-card__body { padding:14px; display:none; }
.attempt-card__body.open { display:block; }
.attempt-card__row { display:flex; gap:12px; margin-bottom:10px; flex-wrap:wrap; }
.attempt-card__row label { font-size:0.85em; font-weight:600; color:#555; display:block; margin-bottom:3px; }
.attempt-field { flex:1; min-width:160px; }
.attempt-field input, .attempt-field select, .attempt-field textarea { width:100%; padding:5px 8px; border:1px solid #ccc; border-radius:4px; }
.attempt-field textarea { resize:vertical; min-height:60px; }
.vod-list { display:flex; flex-direction:column; gap:14px; margin-bottom:6px; }
.vod-item { display:flex; flex-direction:column; gap:4px; padding:6px; border:1px solid #eee; border-radius:4px; background:#fafafa; }
.vod-row { display:flex; gap:6px; align-items:center; }
.vod-row input { padding:4px 7px; border:1px solid #ccc; border-radius:4px; }
.vod-label-input { width:100px; }
.vod-url-input { flex:1; }
.vod-dur-input { width:90px; }
.vod-summary-input { width:100%; padding:5px 7px; border:1px solid #ccc; border-radius:4px; font-family:inherit; font-size:0.9em; resize:vertical; min-height:50px; }
</style>

<script>
(function() {
    const nonce   = '<?php echo wp_create_nonce('wp_rest'); ?>';
    const apiBase = '<?php echo esc_url(rest_url('lamprozo/v1/attempts/')); ?>';
    let attempts  = [];
    let challenge = document.getElementById('challenge-select').value;
    let openCards = new Set();

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
        const split  = attempt.split || '';
        const notes  = attempt.notes || '';
        const vods   = attempt.vods || [];

        const vodRows = vods.map((v, vi) => `
            <div class="vod-item">
                <div class="vod-row">
                    <input class="vod-url-input" type="text" placeholder="https://youtu.be/..." value="${esc(v.url||'')}"
                        onchange="updateVod(${i},${vi},'url',this.value)"
                        onblur="fetchYtMeta(${i},${vi},this.value)">
                    <input class="vod-label-input" type="text" placeholder="Title (auto-filled from YouTube)" value="${esc(v.label||'')}"
                        id="vod-label-${i}-${vi}"
                        onchange="updateVod(${i},${vi},'label',this.value)">
                    <input class="vod-dur-input" type="text" placeholder="Duration (e.g. 1:23:45)"
                        id="vod-dur-${i}-${vi}"
                        value="${esc(v.duration||'')}"
                        onchange="updateVod(${i},${vi},'duration',this.value)">
                    <button class="button button-small" onclick="removeVod(${i},${vi})">✕</button>
                </div>
                <textarea class="vod-summary-input" placeholder="What happened in this VOD?"
                    onchange="updateVod(${i},${vi},'summary',this.value)">${esc(v.summary||'')}</textarea>
            </div>`).join('');

        return `<div class="attempt-card">
            <div class="attempt-card__header" onclick="toggleCard(${i})">
                <span class="attempt-card__number">Attempt #${attempt.number}</span>
                <span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:0.78em;font-weight:700;background:${statusColor(attempt.status)}20;color:${statusColor(attempt.status)}">${statusLabel(attempt.status)}</span>
                <span class="attempt-card__split">${split ? '📍 ' + esc(split) : ''}</span>
                <span class="attempt-card__toggle">${isOpen ? '▲ collapse' : '▼ expand'}</span>
            </div>
            <div class="attempt-card__body ${isOpen ? 'open' : ''}">
                <div class="attempt-card__row">
                    <div class="attempt-field" style="min-width:140px;max-width:180px">
                        <label>Status</label>
                        <select onchange="updateField(${i},'status',this.value)">
                            <option value="ongoing"   ${attempt.status==='ongoing'   ? 'selected':''}>Ongoing</option>
                            <option value="failed"    ${attempt.status==='failed'    ? 'selected':''}>Wiped</option>
                            <option value="completed" ${attempt.status==='completed' ? 'selected':''}>Completed</option>
                        </select>
                    </div>
                    <div class="attempt-field">
                        <label>Split reached</label>
                        <input type="text" placeholder="e.g. Gym 3, Elite Four..." value="${esc(split)}"
                            onchange="updateField(${i},'split',this.value)">
                    </div>
                </div>
                <div class="attempt-card__row">
                    <div class="attempt-field" style="flex:2">
                        <label>Notes</label>
                        <textarea placeholder="What happened this run?" onchange="updateField(${i},'notes',this.value)">${esc(notes)}</textarea>
                    </div>
                </div>
                <div>
                    <label style="font-size:0.85em;font-weight:600;color:#555;display:block;margin-bottom:6px">VODs</label>
                    <div class="vod-list">${vodRows}</div>
                    <button class="button button-small" onclick="addVod(${i})">+ Add VOD</button>
                </div>
                <div style="margin-top:12px;padding-top:10px;border-top:1px solid #eee">
                    <label style="font-size:0.85em;font-weight:600;color:#555;display:block;margin-bottom:6px">Fragsheet <span style="font-weight:400;color:#888">(HZLA calc JSON)</span></label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="file" accept=".json" onchange="loadFragsheetFile(${i},this)" style="flex:1">
                        ${attempt.fragsheet
                            ? `<span style="color:#00a32a;font-size:0.85em">✓ ${Object.keys(attempt.fragsheet).length} Pokémon loaded</span>
                               <button class="button button-small button-link-delete" onclick="clearFragsheet(${i})">Remove</button>`
                            : '<span style="color:#888;font-size:0.85em">No fragsheet</span>'}
                    </div>
                </div>
                <div style="margin-top:12px;padding-top:10px;border-top:1px solid #eee">
                    <button class="button button-small button-link-delete" onclick="deleteAttempt(${i})">Delete attempt</button>
                </div>
            </div>
        </div>`;
    }

    function render() {
        document.getElementById('attempts-list').innerHTML = attempts.length
            ? attempts.map((a, i) => renderCard(a, i)).join('')
            : '<p style="color:#888">No attempts yet.</p>';
    }

    window.fetchYtMeta = (i, vi, url) => {
        if (!url) return;
        fetch('<?php echo esc_url(rest_url('lamprozo/v1/yt-meta')); ?>?url=' + encodeURIComponent(url), { headers: headers() })
            .then(r => r.json())
            .then(data => {
                if (data.title && !attempts[i].vods[vi].label) {
                    attempts[i].vods[vi].label = data.title;
                    const labelEl = document.getElementById(`vod-label-${i}-${vi}`);
                    if (labelEl) labelEl.value = data.title;
                }
                if (data.duration && !attempts[i].vods[vi].duration) {
                    attempts[i].vods[vi].duration = data.duration;
                    const durEl = document.getElementById(`vod-dur-${i}-${vi}`);
                    if (durEl) durEl.value = data.duration;
                }
            });
    };

    window.clearFragsheet = (i) => { attempts[i].fragsheet = null; render(); };
    window.loadFragsheetFile = (i, input) => {
        const file = input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (e) => {
            try {
                attempts[i].fragsheet = JSON.parse(e.target.result);
                render();
            } catch(err) {
                alert('Invalid JSON file: ' + err.message);
            }
        };
        reader.readAsText(file);
    };
    window.toggleCard    = (i) => { openCards.has(i) ? openCards.delete(i) : openCards.add(i); render(); };
    window.updateField   = (i, key, val) => { attempts[i][key] = val; };
    window.updateVod     = (i, vi, key, val) => { attempts[i].vods[vi][key] = val; };
    window.addVod        = (i) => { attempts[i].vods = attempts[i].vods || []; attempts[i].vods.push({label:'VOD',url:'',summary:''}); render(); };
    window.removeVod     = (i, vi) => { attempts[i].vods.splice(vi, 1); render(); };
    window.deleteAttempt = (i) => { if (confirm('Delete attempt #' + attempts[i].number + '?')) { attempts.splice(i, 1); render(); } };

    document.getElementById('challenge-select').addEventListener('change', function() {
        challenge = this.value; openCards.clear(); load();
    });

    document.getElementById('btn-save').addEventListener('click', () => {
        fetch(apiBase + challenge, { method: 'POST', headers: headers(), body: JSON.stringify(attempts) })
            .then(r => r.json())
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

    document.getElementById('btn-new-attempt').addEventListener('click', () => {
        const hasOngoing = attempts.some(a => a.status === 'ongoing');
        let previous_status = 'failed';
        if (hasOngoing) {
            const choice = confirm('Did you complete the current attempt?\n\nOK = Completed\nCancel = Wiped');
            if (choice === null) return;
            previous_status = choice ? 'completed' : 'failed';
        }
        fetch(apiBase + challenge + '/new', { method: 'POST', headers: headers(), body: JSON.stringify({ previous_status }) })
            .then(r => r.json())
            .then(data => { notice('Started attempt #' + data.number + '!'); openCards.clear(); load(); })
            .catch(() => notice('Failed to create attempt.', 'error'));
    });

    function load() {
        fetch(apiBase + challenge, { headers: headers() })
            .then(r => r.json())
            .then(data => { attempts = data; render(); });
    }

    load();
})();
</script>
