<div id="media-app" style="max-width:900px;padding:1.5rem 0">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem">
        <h1 style="margin:0">Media</h1>
        <div style="display:flex;gap:0.75rem">
            <button class="button <?php echo !isset($_GET['tab']) || $_GET['tab']==='videos' ? 'button-primary' : ''; ?>"
                onclick="switchTab('videos')">Videos</button>
            <button class="button <?php echo isset($_GET['tab']) && $_GET['tab']==='clips' ? 'button-primary' : ''; ?>"
                onclick="switchTab('clips')">Clips</button>
        </div>
    </div>

    <div id="media-notice" style="display:none;margin-bottom:1rem"></div>

    <div style="display:flex;gap:0.75rem;margin-bottom:1rem">
        <button id="btn-add" class="button">+ Add Item</button>
        <button id="btn-save" class="button button-primary">Save Changes</button>
    </div>

    <table class="widefat striped" id="media-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>URL</th>
                <th>Description</th>
                <th style="width:80px">Actions</th>
            </tr>
        </thead>
        <tbody id="media-tbody">
            <tr><td colspan="4">Loading...</td></tr>
        </tbody>
    </table>
</div>

<script>
(function() {
    const nonce   = '<?php echo wp_create_nonce('wp_rest'); ?>';
    const apiBase = '<?php echo esc_url(rest_url('lamprozo/v1/')); ?>';
    let tab   = 'videos';
    let items = [];

    function headers() {
        return { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };
    }

    function notice(msg, type = 'success') {
        const el = document.getElementById('media-notice');
        el.innerHTML = `<div class="notice notice-${type} inline"><p>${msg}</p></div>`;
        el.style.display = 'block';
        setTimeout(() => el.style.display = 'none', 3000);
    }

    function esc(str) {
        return String(str||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function renderRow(item, i) {
        return `<tr>
            <td><input type="text" value="${esc(item.title)}" style="width:100%;padding:4px 6px"
                onchange="update(${i},'title',this.value)"></td>
            <td><input type="text" value="${esc(item.url)}" placeholder="https://..." style="width:100%;padding:4px 6px"
                onchange="update(${i},'url',this.value)"></td>
            <td><input type="text" value="${esc(item.description||'')}" style="width:100%;padding:4px 6px"
                onchange="update(${i},'description',this.value)"></td>
            <td><button class="button button-small button-link-delete" onclick="remove(${i})">Delete</button></td>
        </tr>`;
    }

    function render() {
        document.getElementById('media-tbody').innerHTML = items.length
            ? items.map((item, i) => renderRow(item, i)).join('')
            : `<tr><td colspan="4" style="color:#888">No ${tab} yet. Click "+ Add Item" to add one.</td></tr>`;
    }

    window.update  = (i, key, val) => { items[i][key] = val; };
    window.remove  = (i) => { items.splice(i, 1); render(); };

    window.switchTab = function(newTab) {
        tab = newTab;
        load();
        document.querySelectorAll('#media-app .button').forEach(b => b.classList.remove('button-primary'));
        event.target.classList.add('button-primary');
    };

    document.getElementById('btn-add').addEventListener('click', () => {
        items.unshift({ title: '', url: '', description: '' });
        render();
    });

    document.getElementById('btn-save').addEventListener('click', () => {
        fetch(apiBase + tab, { method: 'POST', headers: headers(), body: JSON.stringify(items) })
            .then(r => r.json())
            .then(() => notice('Saved!'))
            .catch(() => notice('Save failed.', 'error'));
    });

    function load() {
        fetch(apiBase + tab, { headers: headers() })
            .then(r => r.json())
            .then(data => { items = data; render(); });
    }

    load();
})();
</script>
