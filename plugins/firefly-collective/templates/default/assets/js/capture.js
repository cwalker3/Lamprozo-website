/**
 * Firefly Notes — admin page controller.
 *
 * Drives the firefly-ragsmith dictation primitive (Whisper transcription, no LLM)
 * and persists the running note via firefly-capture/v1/notes.
 */
(function () {
    'use strict';

    if (!window.FireflyCapture || !FireflyCapture.restUrl) return;

    const REST  = FireflyCapture.restUrl.replace(/\/$/, '');
    const NONCE = FireflyCapture.nonce;

    // Source tag attached to every Ragsmith conversation we create from here,
    // so the main Ragsmith web app can filter wp-notes conversations out of
    // its primary list.
    const SOURCE = 'wp-notes';

    // localStorage keys are namespaced per-origin (per WP host) so multiple
    // Firefly sites on the same browser don't collide.
    //
    // LS_LEGACY_KEY held the entire session list back when sessions lived in
    // the browser. We now keep it ONLY long enough to run the one-shot
    // migration to the server on page load, then delete it. New code never
    // writes here.
    //
    // LS_ACTIVE_KEY is the "remember which session I was last in" fallback
    // for when the URL has no ?session= param. With the server now owning
    // the session list, this stores a server post id (as a string).
    const LS_LEGACY_KEY = 'firefly-capture/sessions/v1';
    const LS_ACTIVE_KEY = 'firefly-capture/active-session/v1';

    // ---------- DOM ----------
    const $ = (id) => document.getElementById(id);
    const els = {};
    let dict = null;

    // ---------- Busy overlay ----------
    // Fullscreen blocker shown while a destructive op is talking to Ragsmith
    // (note/session delete). The user can't click anything until the round
    // trip completes, which is what we want for destructive flows where
    // double-clicking would issue duplicate deletes.
    const busy = (function () {
        let root = null;
        let labelEl = null;
        let depth = 0;

        function ensureRoot() {
            if (root) return;
            root = document.createElement('div');
            root.className = 'firefly-capture-busy-overlay';
            root.hidden = true;
            root.setAttribute('role', 'status');
            root.setAttribute('aria-live', 'polite');
            root.innerHTML = '<div class="firefly-capture-busy-spinner" aria-hidden="true"></div>' +
                             '<div class="firefly-capture-busy-label"></div>';
            document.body.appendChild(root);
            labelEl = root.querySelector('.firefly-capture-busy-label');
        }

        function show(label) {
            ensureRoot();
            depth += 1;
            labelEl.textContent = label || 'Working…';
            root.hidden = false;
        }
        function hide() {
            if (depth > 0) depth -= 1;
            if (depth === 0 && root) root.hidden = true;
        }
        // Convenience: wrap an async task in show/hide regardless of outcome.
        async function wrap(label, task) {
            show(label);
            try { return await task(); }
            finally { hide(); }
        }
        return { show: show, hide: hide, wrap: wrap };
    })();

    // ---------- Warm-up toast ----------
    // Bottom-right non-blocking progress toast used by the Warm up button.
    // Same shape as the Agent admin's toast helpers (openToast / updateToast
    // / succeedToast / failToast / closeToast). Each toast is a fresh DOM
    // node — the helpers below build, update, and tear it down.
    function openToast(title, status) {
        const el = document.createElement('div');
        el.className = 'firefly-capture-toast';
        el.innerHTML =
            '<div class="firefly-capture-toast-head">'
          +   '<span class="dashicons dashicons-update" aria-hidden="true"></span>'
          +   '<span class="firefly-capture-toast-title"></span>'
          + '</div>'
          + '<div class="firefly-capture-toast-status"></div>'
          + '<div class="firefly-capture-toast-bar"><div class="firefly-capture-toast-bar-fill"></div></div>';
        document.body.appendChild(el);
        el.querySelector('.firefly-capture-toast-title').textContent = title;
        el.querySelector('.firefly-capture-toast-status').textContent = status || '';
        return el;
    }
    function updateToast(el, status, loaded, total) {
        if (!el) return;
        const statusEl = el.querySelector('.firefly-capture-toast-status');
        const barEl    = el.querySelector('.firefly-capture-toast-bar-fill');
        if (statusEl) statusEl.textContent = status + (total > 0 ? ' (' + loaded + '/' + total + ')' : '');
        if (barEl) {
            const pct = total > 0 ? Math.min(100, Math.round((loaded / total) * 100)) : 0;
            barEl.style.width = pct + '%';
        }
    }
    function succeedToast(el, title, status) {
        if (!el) return;
        el.classList.add('is-success');
        el.querySelector('.dashicons').className = 'dashicons dashicons-yes-alt';
        el.querySelector('.firefly-capture-toast-title').textContent = title;
        el.querySelector('.firefly-capture-toast-status').textContent = status || '';
        const bar = el.querySelector('.firefly-capture-toast-bar-fill');
        if (bar) bar.style.width = '100%';
        setTimeout(() => closeToast(el), 3500);
    }
    function failToast(el, title, status) {
        if (!el) return;
        el.classList.add('is-error');
        el.querySelector('.dashicons').className = 'dashicons dashicons-warning';
        el.querySelector('.firefly-capture-toast-title').textContent = title;
        el.querySelector('.firefly-capture-toast-status').textContent = status || '';
        setTimeout(() => closeToast(el), 6000);
    }
    function closeToast(el) {
        if (!el || !el.parentNode) return;
        el.style.opacity = '0';
        el.style.transform = 'translateY(8px)';
        setTimeout(() => { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
    }

    // ---------- Warm up handler ----------
    // POSTs the existing firefly-capture/v1/agent/warm route — the same
    // endpoint the Agent admin's Warm up button uses. The route is an SSE
    // pass-through that hits Ragsmith's POST /agents/{name}/load, which
    // emits start | progress | <final> events as it loads KBs, the
    // embedding model, helper LLMs (doc chooser, vision), and the chat
    // model into VRAM. Browser parses with the same fetch().body.getReader()
    // pattern the Agent uses (EventSource doesn't support POST).
    let warmInFlight = false;
    async function onWarm() {
        if (warmInFlight) return;
        warmInFlight = true;
        const btn = els.warmBtn;
        if (btn) btn.classList.add('is-warming');
        const toast = openToast('Warming up agent…', 'Talking to Ragsmith…');

        try {
            const resp = await fetch(REST + '/agent/warm', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': NONCE,
                    'Accept': 'text/event-stream',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),  // server fills agent + model from settings
            });
            if (!resp.ok) {
                const txt = await resp.text().catch(() => '');
                throw new Error('Warm HTTP ' + resp.status + ': ' + txt.slice(0, 200));
            }
            if (!resp.body) throw new Error('No response body to stream.');

            const reader = resp.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let total = 0;

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                const blocks = buffer.split('\n\n');
                buffer = blocks.pop();
                for (const block of blocks) {
                    let dataLine = '';
                    for (const line of block.split('\n')) {
                        if (line.startsWith('data:')) dataLine += line.slice(5).trim() + '\n';
                    }
                    dataLine = dataLine.replace(/\n$/, '');
                    if (!dataLine) continue;
                    let data = null;
                    try { data = JSON.parse(dataLine); } catch (e) { data = dataLine; }
                    if (data && typeof data === 'object') {
                        if (typeof data.total === 'number' && data.loaded === undefined) {
                            total = data.total;
                            updateToast(toast, 'Starting…', 0, total);
                        } else if (typeof data.loaded === 'number') {
                            total = total || data.total || 0;
                            updateToast(toast, data.status || 'Loading…', data.loaded, total || data.loaded);
                        } else if (data.name !== undefined && data.settings !== undefined) {
                            updateToast(toast, 'Ready', 1, 1);
                        }
                    }
                }
            }
            succeedToast(toast, 'Agent warmed up', 'KBs loaded · model in VRAM · ready');
            // Refresh the warm-state pill so it picks up the new
            // last_activity timestamp immediately. fetchWarmStatus()
            // re-renders the pill from the server's truth, then the
            // 60s tick interval keeps "Xm ago" current.
            fetchWarmStatus().catch(() => {});
        } catch (e) {
            console.error('[FireflyCapture] warm failed:', e);
            failToast(toast, 'Warm-up failed', String(e && e.message ? e.message : e));
        } finally {
            warmInFlight = false;
            if (btn) btn.classList.remove('is-warming');
        }
    }

    // ---------- Warm-state pill ----------
    // Indicator next to the Warm up button. Hidden until we confirm the
    // agent is loaded via GET /firefly-capture/v1/agent/status, then
    // shows "Warm · Xm ago" sourced from the response's last_activity.
    // The "Xm ago" text ticks every 60s via a local interval so it
    // stays accurate without hitting the server. localStorage caches
    // the last known state so the pill renders instantly on page
    // reopen (no flicker before the fresh status fetch lands).
    const LS_WARM_KEY = 'firefly-capture/last-warm/v1';
    let warmPillState = null;   // { ready: bool, lastActivity: <ms>, agent: <name> }
    let warmTickHandle = null;

    function renderWarmPill() {
        if (!els.warmPill) return;
        if (!warmPillState || !warmPillState.ready || !warmPillState.lastActivity) {
            els.warmPill.hidden = true;
            return;
        }
        const ago = Date.now() - warmPillState.lastActivity;
        if (els.warmPillText) {
            els.warmPillText.textContent = 'Warm · ' + fmtAgo(ago);
        }
        els.warmPill.hidden = false;
    }
    function fmtAgo(ms) {
        if (ms < 0) return 'just now';
        const s = Math.floor(ms / 1000);
        if (s < 60)    return 'just now';
        const m = Math.floor(s / 60);
        if (m < 60)    return m + 'm ago';
        const h = Math.floor(m / 60);
        if (h < 24)    return h + 'h ago';
        return Math.floor(h / 24) + 'd ago';
    }
    function startWarmTick() {
        if (warmTickHandle) return;
        warmTickHandle = setInterval(renderWarmPill, 60000);
    }
    function saveWarmCache(state) {
        try {
            if (state && state.ready) {
                window.localStorage.setItem(LS_WARM_KEY, JSON.stringify(state));
            } else {
                window.localStorage.removeItem(LS_WARM_KEY);
            }
        } catch (e) { /* localStorage blocked; in-memory state still works */ }
    }
    function loadWarmCache() {
        try {
            const raw = window.localStorage.getItem(LS_WARM_KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (parsed && parsed.ready && typeof parsed.lastActivity === 'number') return parsed;
        } catch (e) {}
        return null;
    }
    async function fetchWarmStatus() {
        try {
            const resp = await fetch(REST + '/agent/status', {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': NONCE, 'Accept': 'application/json' },
            });
            if (!resp.ok) {
                // 400 means no agent configured; clear the pill quietly.
                warmPillState = null;
                saveWarmCache(null);
                renderWarmPill();
                return;
            }
            const data = await resp.json();
            const ready = !!(data && data.ready);
            const last  = (data && data.readiness && data.readiness.last_activity) || null;
            const lastMs = last ? Date.parse(last) : null;
            if (ready && lastMs) {
                warmPillState = { ready: true, lastActivity: lastMs, agent: data.agent || '' };
            } else {
                warmPillState = null;
            }
            saveWarmCache(warmPillState);
            renderWarmPill();
        } catch (e) {
            // Network errors are non-fatal — keep whatever state we had.
            console.warn('[FireflyCapture] warm status fetch failed:', e);
        }
    }

    // ---------- Modal ----------
    // Promise-returning replacement for window.alert / confirm / prompt.
    // One root element is appended to <body> on first open and reused — the
    // contents (title, body, input, buttons) are rebuilt per call.
    // Returns:
    //   confirm  → Promise<boolean>      (true on OK)
    //   prompt   → Promise<string|null>  (null on cancel/escape)
    //   alert    → Promise<void>         (resolves on OK/escape)
    const modal = (function () {
        let root = null;
        let activeResolver = null;
        let lastFocused = null;

        function ensureRoot() {
            if (root) return root;
            root = document.createElement('div');
            root.className = 'firefly-capture-modal-root';
            root.hidden = true;
            root.innerHTML = [
                '<div class="firefly-capture-modal-backdrop" data-fn-modal-dismiss="1"></div>',
                '<div class="firefly-capture-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="firefly-capture-modal-title">',
                    '<h2 class="firefly-capture-modal-title" id="firefly-capture-modal-title"></h2>',
                    '<div class="firefly-capture-modal-body"></div>',
                    '<div class="firefly-capture-modal-actions">',
                        '<button type="button" class="button firefly-capture-modal-cancel" data-fn-modal-cancel></button>',
                        '<button type="button" class="button button-primary firefly-capture-modal-ok" data-fn-modal-ok></button>',
                    '</div>',
                '</div>'
            ].join('');
            document.body.appendChild(root);

            root.addEventListener('click', function (e) {
                if (e.target.matches('[data-fn-modal-dismiss]')) resolveActive(null);
            });
            document.addEventListener('keydown', function (e) {
                if (root.hidden) return;
                if (e.key === 'Escape') {
                    e.preventDefault();
                    resolveActive(null);
                } else if (e.key === 'Enter' && !(e.target && e.target.tagName === 'TEXTAREA')) {
                    e.preventDefault();
                    submitActive();
                }
            });
            return root;
        }

        function resolveActive(value) {
            if (!activeResolver) return;
            const r = activeResolver;
            activeResolver = null;
            root.hidden = true;
            if (lastFocused && typeof lastFocused.focus === 'function') {
                try { lastFocused.focus(); } catch (e) {}
            }
            r(value);
        }

        function submitActive() {
            const okBtn = root.querySelector('.firefly-capture-modal-ok');
            if (okBtn) okBtn.click();
        }

        function open(opts) {
            ensureRoot();
            // If a previous modal is open, resolve it as cancelled so the new
            // one takes over — avoids stacking and dangling promises.
            if (activeResolver) resolveActive(null);

            lastFocused = document.activeElement;

            const titleEl  = root.querySelector('.firefly-capture-modal-title');
            const bodyEl   = root.querySelector('.firefly-capture-modal-body');
            const cancelBtn = root.querySelector('.firefly-capture-modal-cancel');
            const okBtn    = root.querySelector('.firefly-capture-modal-ok');
            const dialogEl = root.querySelector('.firefly-capture-modal-dialog');

            titleEl.textContent = opts.title || '';
            bodyEl.innerHTML = '';

            if (opts.message) {
                const p = document.createElement('p');
                p.className = 'firefly-capture-modal-message';
                p.textContent = opts.message;
                bodyEl.appendChild(p);
            }

            let input = null;
            if (opts.type === 'prompt') {
                input = document.createElement('input');
                input.type = 'text';
                input.className = 'firefly-capture-modal-input';
                input.value = opts.defaultValue != null ? String(opts.defaultValue) : '';
                if (opts.placeholder) input.placeholder = opts.placeholder;
                bodyEl.appendChild(input);
            }

            okBtn.textContent = opts.confirmLabel || (opts.type === 'alert' ? 'OK' : 'Confirm');
            okBtn.classList.toggle('button-danger', !!opts.danger);
            okBtn.classList.toggle('button-primary', !opts.danger);

            cancelBtn.textContent = opts.cancelLabel || 'Cancel';
            cancelBtn.style.display = (opts.type === 'alert') ? 'none' : '';

            dialogEl.classList.toggle('is-danger', !!opts.danger);

            const promise = new Promise(function (resolve) {
                activeResolver = resolve;
            });

            cancelBtn.onclick = function () { resolveActive(opts.type === 'prompt' ? null : false); };
            okBtn.onclick     = function () {
                if (opts.type === 'prompt') resolveActive(input.value);
                else if (opts.type === 'alert') resolveActive();
                else resolveActive(true);
            };

            root.hidden = false;
            // Defer focus so transition/visibility settles before steal.
            setTimeout(function () {
                if (input) { input.focus(); input.select(); }
                else okBtn.focus();
            }, 0);

            return promise;
        }

        return {
            confirm: function (opts) { return open(Object.assign({}, opts, { type: 'confirm' })); },
            prompt:  function (opts) { return open(Object.assign({}, opts, { type: 'prompt'  })); },
            alert:   function (opts) { return open(Object.assign({}, opts, { type: 'alert'   })); },
        };
    })();

    // ---------- State ----------
    const state = {
        notes: [],          // [{id, title, modified}]
        currentId: null,    // currently loaded note id
        savedAt: 0,
        savePending: false,
        saveTimer: null,
        listening: false,
        muted: false,
        editMode: false,

        // Dictation sessions — server-backed since the post_parent refactor.
        // Each entry is the wire shape of a firefly_note_session post:
        //   { id, label, rs_session_id, note_count, created, modified }
        // id is a WordPress post id (integer). The server is the source of
        // truth — we never write to this array except via REST round-trips.
        //
        // activeSessionId is the integer id of the currently-selected session.
        // Resolved on load by URL param first, then LS_ACTIVE_KEY, then the
        // most-recently-modified session, with auto-create as the last resort.
        sessions: [],
        activeSessionId: null,
        // Tracks the text content that was last successfully saved to Ragsmith
        // for the currently-loaded note. Compared to els.transcript.value to
        // decide whether the Save-to-AI button is "synced" or "dirty".
        // Cleared when switching notes; refreshed after each Save to AI POST/PUT.
        aiSavedContent: null,
        // True while the Save-to-AI round-trip is in flight, so we can block
        // double-clicks and reflect the busy state in the button.
        savingToAI: false,
    };

    // ---------- API ----------
    async function api(path, opts = {}) {
        const init = {
            method: opts.method || 'GET',
            headers: { 'X-WP-Nonce': NONCE, 'Accept': 'application/json' },
            credentials: 'same-origin',
        };
        if (opts.body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(opts.body);
        }
        const r = await fetch(REST + path, init);
        if (!r.ok) {
            let msg = 'HTTP ' + r.status;
            try { const j = await r.json(); if (j && j.message) msg = j.message; } catch (e) {}
            throw new Error(msg);
        }
        return r.json();
    }

    // ---------- Rendering ----------
    // Render in the site's configured timezone (falls back to user-agent locale).
    const TZ = (window.FireflyCapture && FireflyCapture.timezone) || undefined;

    function fmtDate(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        const today = new Date();
        const dDay     = d.toLocaleDateString('en-US',     { timeZone: TZ });
        const todayDay = today.toLocaleDateString('en-US', { timeZone: TZ });
        const sameDay = dDay === todayDay;
        return sameDay
            ? d.toLocaleTimeString('en-US', { timeZone: TZ, hour: 'numeric', minute: '2-digit' })
            : d.toLocaleDateString('en-US', { timeZone: TZ, month: 'short', day: 'numeric' });
    }

    function renderList() {
        const list = els.list;
        list.innerHTML = '';
        if (state.notes.length === 0) {
            const li = document.createElement('li');
            li.className = 'firefly-capture-empty';
            li.textContent = 'No notes yet.';
            list.appendChild(li);
            els.count.textContent = '';
            return;
        }
        els.count.textContent = state.notes.length;
        for (const n of state.notes) {
            const li = document.createElement('li');
            li.className = 'firefly-capture-item' + (n.id === state.currentId ? ' is-active' : '');
            li.dataset.id = n.id;
            li.innerHTML = `
                <span class="firefly-capture-item-title"></span>
                <span class="firefly-capture-item-date"></span>
            `;
            li.querySelector('.firefly-capture-item-title').textContent = n.title || 'Untitled';
            li.querySelector('.firefly-capture-item-date').textContent = fmtDate(n.modified);
            li.addEventListener('click', () => loadNote(n.id));
            list.appendChild(li);
        }
    }

    function renderCurrent(note) {
        if (!note) {
            // No note loaded — clear inputs but keep main visible so the
            // mic button is reachable (clicking it auto-creates a note).
            els.title.value = '';
            els.transcript.value = '';
            els.modified.textContent = '';
            els.saved.textContent = '';
            els.delete.disabled = true;
            els.edit.disabled = true;
            setEditMode(false);
            return;
        }
        els.title.value = note.title || '';
        els.transcript.value = note.content || '';
        els.modified.textContent = 'Last edited ' + fmtDate(note.modified);
        els.saved.textContent = '';
        els.delete.disabled = false;
        els.edit.disabled = false;
        // New notes default to read-only; user taps Edit to type.
        setEditMode(false);
        // On mobile, loading a note collapses the sidebar so the editor fills the viewport.
        setSidebarOpen(false);
    }

    function setEditMode(on) {
        state.editMode = !!on;
        els.title.readOnly = !on;
        els.transcript.readOnly = !on;
        els.edit.classList.toggle('is-on', on);
        els.edit.setAttribute('aria-pressed', on ? 'true' : 'false');
        els.edit.querySelector('.firefly-capture-edit-label').textContent = on ? 'Done' : 'Edit';
        if (on) {
            // Focus the transcript so the user can type immediately.
            // (Defer to next tick so the readonly attribute removal takes effect.)
            setTimeout(() => els.transcript.focus(), 0);
        }
    }

    function setSidebarOpen(open) {
        const wrap = document.querySelector('.firefly-capture');
        if (!wrap) return;
        wrap.classList.toggle('show-sidebar', !!open);
        if (els.toggleList) {
            els.toggleList.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function setStatus(text) {
        els.status.textContent = text;
    }

    // ---------- Notes CRUD ----------
    async function loadList(autoLoadFirst = false) {
        // Show the same "Loading…" placeholder that loadModeList() uses for
        // recordings + documents. Without it, switching from Recordings or
        // Documents back to Notes leaves the previous mode's items visible
        // until the /notes fetch lands — feels broken on slow connections.
        if (els.list) {
            els.list.innerHTML = '<li class="firefly-capture-empty">Loading&hellip;</li>';
        }
        // Scope notes to the active session via the server's ?session= filter.
        // Without an active session (rare — only between init and the first
        // resolveInitialActiveSession()), we ask for the unfiltered list as
        // a defensive fallback so the sidebar isn't empty.
        const qs = state.activeSessionId ? ('?session=' + encodeURIComponent(state.activeSessionId)) : '';
        const data = await api('/notes' + qs);
        state.notes = data.notes || [];
        renderList();
        if (autoLoadFirst) {
            if (state.notes.length > 0) {
                await loadNote(state.notes[0].id);
            } else {
                renderCurrent(null);
            }
        }
    }

    async function loadNote(id) {
        await flushPendingSave();
        const note = await api('/notes/' + id);
        state.currentId = note.id;
        // If the note already has a stored Ragsmith message ref, treat the
        // currently loaded content as the synced baseline. The textarea will
        // dirty itself the moment the user edits, flipping the button back
        // to "Save to AI". If the note has no ref, aiSavedContent stays null
        // so the button shows "Save to AI" as soon as there's anything to send.
        const hasRef = Array.isArray(note.messages) && note.messages.length > 0;
        state.aiSavedContent = hasRef ? (note.content || '') : null;
        renderCurrent(note);
        updateAiSaveButtonState();
        // Mark active in sidebar without re-fetching list.
        document.querySelectorAll('.firefly-capture-item').forEach((el) => {
            el.classList.toggle('is-active', Number(el.dataset.id) === note.id);
        });
        els.transcript.focus();
    }

    async function createNote() {
        await flushPendingSave();
        // The active session is required — the server rejects a create with
        // no session_id. resolveInitialActiveSession() guarantees one exists
        // by load time, so this should always be populated when the New Note
        // button is clickable.
        const sess = getActiveSession();
        if (!sess) {
            await modal.alert({
                title: 'No session selected',
                message: 'Pick or create a session in the header dropdown before adding a note.',
            });
            return;
        }
        const note = await api('/notes', { method: 'POST', body: { session_id: sess.id } });
        state.notes.unshift({ id: note.id, title: note.title, modified: note.modified });
        state.currentId = note.id;
        // Fresh note → no Ragsmith ref yet, so the Save-to-AI button is
        // primed (will be "Save to AI" the moment there's any content).
        state.aiSavedContent = null;
        renderList();
        renderCurrent(note);
        updateAiSaveButtonState();
        // Don't auto-focus/select the title — title is readonly by default
        // and the user reaches for the mic, not the keyboard, on a new note.
    }

    async function deleteNote() {
        if (!state.currentId) return;
        const ok = await modal.confirm({
            title: 'Delete this note?',
            message: 'The note and its dictation messages will be removed. This cannot be undone.',
            confirmLabel: 'Delete',
            danger: true,
        });
        if (!ok) return;
        const id = state.currentId;
        // Wrap the round-trip in the fullscreen overlay so the user can't
        // double-click delete (would issue a stale DELETE on a missing note)
        // and gets clear feedback that the Ragsmith hop is in progress.
        await busy.wrap('Deleting note…', async () => {
            await api('/notes/' + id, { method: 'DELETE' });
        });
        state.notes = state.notes.filter((n) => n.id !== id);
        state.currentId = null;
        renderList();
        if (state.notes.length > 0) {
            await loadNote(state.notes[0].id);
        } else {
            renderCurrent(null);
        }
    }

    // ---------- Save (debounced) ----------
    function scheduleSave() {
        clearTimeout(state.saveTimer);
        state.savePending = true;
        els.saved.textContent = 'Saving…';
        state.saveTimer = setTimeout(flushPendingSave, 1500);
    }

    async function flushPendingSave() {
        clearTimeout(state.saveTimer);
        if (!state.savePending || !state.currentId) {
            state.savePending = false;
            return;
        }
        const id = state.currentId;
        const payload = {
            title:   els.title.value,
            content: els.transcript.value,
        };
        try {
            const note = await api('/notes/' + id, { method: 'POST', body: payload });
            state.savePending = false;
            els.saved.textContent = 'Saved';
            els.modified.textContent = 'Last edited ' + fmtDate(note.modified);
            // Update list entry in place so sidebar stays current.
            const item = state.notes.find((n) => n.id === id);
            if (item) {
                item.title = note.title;
                item.modified = note.modified;
                renderList();
            }
        } catch (e) {
            els.saved.textContent = 'Save failed: ' + e.message;
        }
    }

    // ---------- Sessions ----------
    // Sessions are now first-class server posts (firefly_note_session CPT).
    // The browser holds no authoritative session data; we fetch the list
    // from /sessions on load and after every mutating call. The URL
    // (?session=N) is the canonical active-session selector with
    // localStorage as a fallback for "remember where I was last."
    //
    // Public functions in this block:
    //   migrateLegacyLocalStorageIfPresent() — one-shot import on init.
    //   loadSessionsFromServer()             — fill state.sessions, ensure at
    //                                          least one exists.
    //   resolveInitialActiveSession()        — URL → LS → first.
    //   setActiveSession(id)                 — switch, push URL, re-render.
    //   createSession()                      — POST /sessions, switch to it.
    //   renameSession(id)                    — PATCH /sessions/{id}.
    //   deleteSession(id)                    — DELETE /sessions/{id}
    //                                          (server cascades notes + rs).
    //   getActiveSession()                   — local lookup of state record.

    /**
     * If the previous-generation client wrote a session list to
     * localStorage, ship it to the server's /sessions/migrate endpoint
     * before doing anything else. The endpoint is idempotent — safe to
     * re-run if a prior attempt didn't reach the localStorage clear step.
     */
    async function migrateLegacyLocalStorageIfPresent() {
        let raw;
        try { raw = localStorage.getItem(LS_LEGACY_KEY); }
        catch (e) { return; }
        if (!raw) return;

        let parsed;
        try { parsed = JSON.parse(raw); }
        catch (e) { localStorage.removeItem(LS_LEGACY_KEY); return; }
        if (!Array.isArray(parsed) || parsed.length === 0) {
            localStorage.removeItem(LS_LEGACY_KEY);
            return;
        }

        // Send only the fields the migration endpoint cares about — the
        // legacy createdAt is recreated server-side from post_date so it's
        // not worth shipping.
        const payload = parsed
            .filter((s) => s && typeof s.id === 'string' && s.id !== '')
            .map((s) => ({
                id: s.id,
                label: typeof s.label === 'string' ? s.label : '',
                ragsmithSessionId: typeof s.ragsmithSessionId === 'string' ? s.ragsmithSessionId : '',
            }));
        if (payload.length === 0) {
            localStorage.removeItem(LS_LEGACY_KEY);
            return;
        }

        try {
            const res = await api('/sessions/migrate', {
                method: 'POST',
                body: { sessions: payload },
            });
            console.info('[FireflyCapture] migrated legacy sessions:', res && res.migrated);
            // Map the old active uuid (if any) onto the newly-created server
            // post id so the user doesn't lose their place on the first load
            // after upgrade.
            try {
                const legacyActive = localStorage.getItem(LS_ACTIVE_KEY);
                if (legacyActive && res && Array.isArray(res.migrated)) {
                    const match = res.migrated.find((m) => m.browser_id === legacyActive);
                    if (match) localStorage.setItem(LS_ACTIVE_KEY, String(match.session_id));
                    else localStorage.removeItem(LS_ACTIVE_KEY);
                }
            } catch (e) { /* ignore */ }
        } catch (e) {
            // Surface but don't block the page — the user can still operate
            // on server sessions, just without their legacy ones imported.
            console.error('[FireflyCapture] session migration failed:', e);
            return;
        }

        // Clear the legacy key. Re-run is safe (idempotent) but pointless.
        try { localStorage.removeItem(LS_LEGACY_KEY); } catch (e) { /* ignore */ }
    }

    /**
     * Fetch /sessions and place it on state.sessions. If the user has no
     * sessions yet, create one and re-fetch so the picker is never empty.
     */
    async function loadSessionsFromServer() {
        const res = await api('/sessions');
        state.sessions = (res && Array.isArray(res.sessions)) ? res.sessions : [];

        if (state.sessions.length === 0) {
            // Auto-create the first session so the dictation flow has somewhere
            // to land. The user can rename it any time.
            const created = await api('/sessions', { method: 'POST', body: { label: 'Default' } });
            state.sessions = [created];
        }
    }

    /**
     * Decide which session is "active" right now, in priority order:
     *   1. URL ?session=N (must be a session the user owns)
     *   2. localStorage LS_ACTIVE_KEY (same constraint)
     *   3. The most-recently-modified session (top of the list — the server
     *      sorts DESC by modified)
     *
     * Side effects: writes LS_ACTIVE_KEY + history.replaceState so the URL
     * always reflects the resolved choice afterwards.
     */
    function resolveInitialActiveSession() {
        const ids = new Set(state.sessions.map((s) => Number(s.id)));

        const fromUrl = Number(new URLSearchParams(window.location.search).get('session') || 0);
        let chosen = ids.has(fromUrl) ? fromUrl : 0;

        if (!chosen) {
            const fromLs = Number(localStorage.getItem(LS_ACTIVE_KEY) || 0);
            if (ids.has(fromLs)) chosen = fromLs;
        }
        if (!chosen && state.sessions.length > 0) {
            chosen = Number(state.sessions[0].id);
        }

        state.activeSessionId = chosen || null;
        if (chosen) {
            try { localStorage.setItem(LS_ACTIVE_KEY, String(chosen)); } catch (e) {}
            writeActiveSessionToUrl(chosen);
        }
    }

    /**
     * Update the URL's ?session= param without triggering navigation. Uses
     * replaceState so back-button stays useful (we'd push, but pushState
     * here causes each picker click to add a history entry, which is
     * annoying — replaceState gives bookmarkability without the spam).
     */
    function writeActiveSessionToUrl(id) {
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('session', String(id));
            window.history.replaceState(null, '', url.toString());
        } catch (e) { /* same-origin policy / older browser — ignore */ }
    }

    function getActiveSession() {
        return state.sessions.find((s) => Number(s.id) === Number(state.activeSessionId)) || null;
    }

    /**
     * Switch the active session. Updates state, the URL, localStorage, and
     * re-renders the picker. Then resets every mode's loaded-item state
     * (notes editor, recording detail, doc detail) so items from the OLD
     * session can't linger on tabs the user isn't currently viewing —
     * after a session delete the lingering refs would 404 on next
     * interaction anyway. Finally reloads the ACTIVE mode's sidebar:
     * notes via loadList(true) so the first note auto-opens,
     * recordings/documents via loadModeList() so the user lands on the
     * entry (record button / dropzone) with a fresh sidebar.
     */
    async function setActiveSession(id) {
        const numId = Number(id);
        if (!state.sessions.some((s) => Number(s.id) === numId)) return;
        state.activeSessionId = numId;
        try { localStorage.setItem(LS_ACTIVE_KEY, String(numId)); } catch (e) {}
        writeActiveSessionToUrl(numId);
        renderActiveSession();
        renderSessionList();

        // --- Reset every mode's loaded-item state ---
        // Cancel any in-flight autosave for the (possibly-deleted) note
        // so we don't fire a PATCH at /notes/<gone> after the swap.
        clearTimeout(state.saveTimer);
        state.saveTimer    = null;
        state.savePending  = false;
        // Notes mode: drop the current note refs + visually clear the
        // editor. loadList(true) below will repaint with the new
        // session's first note (or an empty state).
        state.currentId       = null;
        state.aiSavedContent  = null;
        try { renderCurrent(null); } catch (e) {}
        // Recordings + documents: their `showXEntry` helpers reset both
        // visual state AND the per-mode state (currentPostId etc).
        try { if (typeof showRecordingEntry === 'function') showRecordingEntry(); } catch (e) {}
        try { if (typeof showDocumentEntry  === 'function') showDocumentEntry();  } catch (e) {}

        // Reload the active mode's sidebar.
        if (state.activeMode === 'notes' || !state.activeMode) {
            await loadList(true).catch((e) => console.error('[FireflyCapture] loadList after switch failed:', e));
        } else {
            await loadModeList().catch((e) => console.error('[FireflyCapture] loadModeList after switch failed:', e));
        }
    }

    /**
     * Create a new session via the server, refresh the local list, switch
     * to it. The server auto-numbers the label as "Session N+1" so we
     * don't need to compute it client-side.
     */
    async function createSession() {
        const created = await api('/sessions', { method: 'POST', body: {} });
        await loadSessionsFromServer();
        await setActiveSession(created.id);
    }

    /**
     * Prompt for a new label, PATCH the server, refresh the local list.
     */
    async function renameSession(id) {
        const sess = state.sessions.find((s) => Number(s.id) === Number(id));
        if (!sess) return;
        const next = await modal.prompt({
            title: 'Rename session',
            message: 'Give this dictation session a label.',
            defaultValue: sess.label,
            confirmLabel: 'Save',
        });
        if (next == null) return;
        const trimmed = next.trim();
        if (!trimmed) return;
        try {
            await api('/sessions/' + Number(id), { method: 'PATCH', body: { label: trimmed } });
            await loadSessionsFromServer();
            renderActiveSession();
            renderSessionList();
        } catch (e) {
            console.error('[FireflyCapture] rename failed:', e);
            await modal.alert({ title: 'Rename failed', message: e && e.message ? e.message : String(e) });
        }
    }

    /**
     * Confirm + cascade-delete a session. The server tears down everything
     * (ragsmith conversation + child notes + the session post itself); the
     * client just refreshes its state after.
     */
    async function deleteSession(id) {
        const sess = state.sessions.find((s) => Number(s.id) === Number(id));
        if (!sess) return;

        const noteCount = sess.note_count || 0;
        const ok = await modal.confirm({
            title: 'Delete this session?',
            message: noteCount > 0
                ? 'This will delete ' + noteCount + ' note' + (noteCount === 1 ? '' : 's') +
                  ' in this session, plus the AI conversation. This cannot be undone.'
                : 'The AI conversation will be removed. This cannot be undone.',
            confirmLabel: 'Delete',
            danger: true,
        });
        if (!ok) return;

        await busy.wrap('Deleting session…', async () => {
            try {
                await api('/sessions/' + Number(id), { method: 'DELETE' });
            } catch (e) {
                console.error('[FireflyCapture] delete session failed:', e);
                throw e;
            }
        });

        // Reload the session list from server (will auto-create a Default
        // session if we just deleted the last one), then switch active to
        // the most-recent remaining session.
        await loadSessionsFromServer();
        const nextId = state.sessions.length ? state.sessions[0].id : null;
        if (nextId) await setActiveSession(nextId);
        else {
            state.activeSessionId = null;
            renderActiveSession();
            renderSessionList();
            await loadList(true).catch(() => {});
        }
    }

    function renderActiveSession() {
        const sess = getActiveSession();
        if (!sess) {
            els.sessionLabel.textContent = 'Session';
            return;
        }
        els.sessionLabel.textContent = sess.label;
    }

    function renderSessionList() {
        const ul = els.sessionList;
        ul.innerHTML = '';
        for (const s of state.sessions) {
            const li = document.createElement('li');
            li.className = 'firefly-capture-session-item' + (s.id === state.activeSessionId ? ' is-active' : '');
            li.dataset.id = s.id;
            const label = document.createElement('button');
            label.type = 'button';
            label.className = 'firefly-capture-session-pick';
            label.textContent = s.label;
            label.addEventListener('click', () => { setActiveSession(s.id); closeSessionPopover(); });
            const rename = document.createElement('button');
            rename.type = 'button';
            rename.className = 'firefly-capture-session-action';
            rename.title = 'Rename';
            rename.setAttribute('aria-label', 'Rename session');
            rename.innerHTML = '<span class="dashicons dashicons-edit" aria-hidden="true"></span>';
            rename.addEventListener('click', (e) => { e.stopPropagation(); renameSession(s.id); });
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'firefly-capture-session-action firefly-capture-session-delete';
            del.title = 'Delete';
            del.setAttribute('aria-label', 'Delete session');
            del.innerHTML = '<span class="dashicons dashicons-trash" aria-hidden="true"></span>';
            del.addEventListener('click', (e) => { e.stopPropagation(); deleteSession(s.id); });
            li.appendChild(label);
            li.appendChild(rename);
            li.appendChild(del);
            ul.appendChild(li);
        }
    }

    function openSessionPopover() {
        els.sessionPopover.hidden = false;
        els.sessionBtn.setAttribute('aria-expanded', 'true');
        renderSessionList();
        positionSessionPopover();
        // Re-position on scroll/resize while open — the popover is fixed-positioned
        // on mobile so the button moving (e.g. iOS Safari URL bar collapse) would
        // otherwise leave the popover stranded.
        window.addEventListener('scroll', positionSessionPopover, { passive: true, capture: true });
        window.addEventListener('resize', positionSessionPopover, { passive: true });
        // Close on outside click — re-bound each open so we don't leak listeners.
        document.addEventListener('mousedown', onDocClickForPopover, { capture: true });
    }
    function closeSessionPopover() {
        els.sessionPopover.hidden = true;
        els.sessionBtn.setAttribute('aria-expanded', 'false');
        window.removeEventListener('scroll', positionSessionPopover, { capture: true });
        window.removeEventListener('resize', positionSessionPopover);
        document.removeEventListener('mousedown', onDocClickForPopover, { capture: true });
        // Clear inline positioning so the desktop CSS rules apply again next open.
        els.sessionPopover.style.top = '';
        els.sessionPopover.style.left = '';
        els.sessionPopover.style.right = '';
    }
    function onDocClickForPopover(e) {
        if (els.sessionPopover.contains(e.target) || els.sessionBtn.contains(e.target)) return;
        closeSessionPopover();
    }
    // Position the popover under the button. On wide viewports the static CSS
    // (right-aligned to the picker container) is fine. On narrow viewports
    // the button sits near the right edge, so we switch to viewport-fixed
    // positioning, center the popover under the button, and clamp to the
    // visible area so neither edge clips.
    function positionSessionPopover() {
        const pop = els.sessionPopover;
        const btn = els.sessionBtn;
        if (!pop || !btn || pop.hidden) return;

        const MOBILE_BREAKPOINT = 782; // matches the CSS @media gate
        if (window.innerWidth > MOBILE_BREAKPOINT) {
            // Desktop: let CSS do it. Clear any inline overrides from a prior
            // narrow-viewport open.
            pop.style.top = '';
            pop.style.left = '';
            pop.style.right = '';
            return;
        }

        // Mobile: fixed under the button, centered, clamped to viewport with
        // an 8px margin on each side.
        const MARGIN = 8;
        const GAP = 6;
        const btnRect = btn.getBoundingClientRect();
        const popWidth = Math.min(pop.offsetWidth, window.innerWidth - 2 * MARGIN);
        const btnCenter = btnRect.left + btnRect.width / 2;
        let left = btnCenter - popWidth / 2;
        if (left < MARGIN) left = MARGIN;
        if (left + popWidth > window.innerWidth - MARGIN) {
            left = window.innerWidth - MARGIN - popWidth;
        }
        pop.style.top = (btnRect.bottom + GAP) + 'px';
        pop.style.left = left + 'px';
        pop.style.right = 'auto';
    }

    // ---------- Save to AI ----------
    // Persists the current note's textarea content to Ragsmith as exactly
    // one dictation message. First save POSTs to /dictation; subsequent
    // saves PUT to /sessions/{sid}/messages/{mid}/content so the same
    // message gets updated (and its facts get re-extracted to match the
    // new content). The note's _firefly_note_messages meta stores the
    // (session_id, message_id) pair that points at the live message.

    async function saveNoteToAI() {
        if (state.savingToAI) return;
        if (!state.currentId) return;

        const content = els.transcript.value.trim();
        if (!content) {
            await modal.alert({
                title: 'Nothing to save',
                message: 'Dictate or type something first, then tap Save to AI.',
            });
            return;
        }

        const sess = getActiveSession();
        if (!sess) {
            await modal.alert({
                title: 'No active session',
                message: 'Pick or create a session in the header dropdown first.',
            });
            return;
        }
        if (!window.FireflyRagsmith) {
            await modal.alert({
                title: 'AI bridge not available',
                message: 'The AI bridge plugin is not loaded.',
            });
            return;
        }

        state.savingToAI = true;
        updateAiSaveButtonState();
        setStatus('Saving to AI…');

        try {
            // Look up the prior message ref (if any) for this note. The notes
            // plugin stores at most one entry per note after the explicit-save
            // refactor — we use the first if present.
            const existing = await fetchNoteMessageRef(state.currentId);

            let res;
            if (existing && existing.session_id && existing.message_id) {
                // Edit path: PUT to update the existing message. reextract_facts
                // tells Ragsmith to drop old facts + re-run extraction against
                // the new content, so the conversation's session_memory tracks
                // the latest version of the note rather than a stale snapshot.
                res = await FireflyRagsmith.updateDictation(
                    existing.session_id,
                    existing.message_id,
                    content,
                    { reextract_facts: true }
                );
            } else {
                // First save for this note: POST /dictation. The session
                // post owns the Ragsmith conversation id, so we reuse it if
                // already bound (subsequent dictations land in the same
                // Ragsmith conversation as everything else in this session).
                // If unbound, Ragsmith mints a fresh conversation id and we
                // bind it to the session post via /sessions/{id}/ragsmith
                // so the next save here — or in any of this session's other
                // notes — reuses it.
                res = await FireflyRagsmith.saveDictation(content, {
                    session_id: sess.rs_session_id || undefined,
                    source: SOURCE,
                    extract_facts: true,
                });
                if (res && res.session_id && !sess.rs_session_id) {
                    sess.rs_session_id = res.session_id;
                    // Persist on the session post so other notes in this
                    // session pick it up. Best-effort: a failure here just
                    // means the next save makes a new Ragsmith conversation
                    // instead of reusing this one.
                    try {
                        await api('/sessions/' + sess.id + '/ragsmith', {
                            method: 'POST',
                            body: { rs_session_id: res.session_id },
                        });
                    } catch (e) {
                        console.error('[FireflyCapture] bind rs_session_id failed:', e);
                    }
                }
                if (res && res.message_id && res.session_id) {
                    // Best-effort record on the WP side so single-note delete
                    // can cascade to this message. Idempotent on the server.
                    try {
                        await api('/notes/' + state.currentId + '/messages', {
                            method: 'POST',
                            body: { session_id: res.session_id, message_id: res.message_id },
                        });
                    } catch (e) {
                        console.error('[FireflyCapture] append-message failed:', e);
                    }
                }
            }

            // Keep the button in its "Working…" state through fact extraction
            // — flipping to "Synced" the moment the POST returns misleads the
            // user when extraction is still running in the background.
            const sid = (res && res.session_id) || (existing && existing.session_id);
            if (res && res.extracting && sid) {
                await pollExtractionUntilDone(sid);
            }

            // Extraction is done (or there was nothing to extract). Snapshot
            // the content as the synced baseline so the button flips to
            // "Synced" once we drop the savingToAI flag in the finally block.
            state.aiSavedContent = content;
            setStatus('Saved to AI');
        } catch (e) {
            const msg = e && e.message ? e.message : String(e);
            setStatus('Save to AI failed: ' + msg);
            console.error('[FireflyCapture] saveNoteToAI failed:', e);
        } finally {
            state.savingToAI = false;
            updateAiSaveButtonState();
        }
    }

    // Reads the {session_id, message_id} pair stored as post_meta on the
    // current note. We hit GET /notes/{id} and the note response includes
    // the messages array (added below in the PHP route). Falls back to
    // null when not present.
    async function fetchNoteMessageRef(noteId) {
        try {
            const note = await api('/notes/' + noteId);
            if (note && Array.isArray(note.messages) && note.messages.length > 0) {
                return note.messages[0];
            }
        } catch (e) {
            console.error('[FireflyCapture] fetchNoteMessageRef failed:', e);
        }
        return null;
    }

    // Renders the Save-to-AI button state: disabled while a save is in
    // flight, "is-synced" when the textarea matches the last saved content,
    // otherwise the default "needs save" appearance. The label also flips
    // between "Save to AI" and "Synced" to make the state obvious.
    function updateAiSaveButtonState() {
        const btn = els.aiSave;
        if (!btn) return;
        const labelEl = btn.querySelector('.firefly-capture-ai-save-label');
        const current = els.transcript ? els.transcript.value.trim() : '';

        if (state.savingToAI) {
            // Single "Working…" label covers both the POST/PUT round-trip
            // and the subsequent fact-extraction poll. The status indicator
            // below the textarea distinguishes the two phases for the user.
            btn.disabled = true;
            btn.classList.remove('is-synced');
            if (labelEl) labelEl.textContent = 'Working…';
            return;
        }
        btn.disabled = !current;
        const synced = state.aiSavedContent !== null && current === state.aiSavedContent;
        btn.classList.toggle('is-synced', synced);
        if (labelEl) labelEl.textContent = synced ? 'Synced' : 'Save to AI';
    }

    // Poll Ragsmith for fact-extraction completion. Returns a Promise that
    // resolves once extraction is done (or the poll budget is exhausted, or
    // the status endpoint errors). Awaitable so the Save-to-AI flow can keep
    // the button in its "Working…" state through the full save+extract cycle
    // rather than flipping to "Synced" the moment the POST returns.
    function pollExtractionUntilDone(sessionId) {
        return new Promise((resolve) => {
            if (!sessionId) { resolve(); return; }
            setStatus('Extracting facts…');
            const MAX_ATTEMPTS = 30;        // ~60s at 2s spacing
            const POLL_INTERVAL_MS = 2000;
            let attempts = 0;
            async function step() {
                try {
                    const data = await FireflyRagsmith.getExtractionStatus(sessionId);
                    if (!data || !data.extracting) {
                        setStatus('Idle');
                        resolve();
                        return;
                    }
                } catch (e) {
                    // Status endpoint failed — extraction may still be running
                    // server-side, but we have no signal. Resolve so the UI
                    // doesn't stay locked in "Working…" forever.
                    setStatus('Idle');
                    resolve();
                    return;
                }
                attempts++;
                if (attempts >= MAX_ATTEMPTS) {
                    setStatus('Idle');
                    resolve();
                    return;
                }
                setTimeout(step, POLL_INTERVAL_MS);
            }
            step();
        });
    }

    // ---------- Dictation ----------
    function ensureDictation() {
        if (dict) return dict;
        if (!window.FireflyRagsmith || typeof window.FireflyRagsmith.createDictation !== 'function') {
            throw new Error('Firefly Ragsmith dictation not available.');
        }
        dict = window.FireflyRagsmith.createDictation({
            mountTo: '#firefly-capture-dictation-host',
            onTranscript: onTranscriptChunk,
            onStateChange: onDictationStateChange,
            onError: (err) => setStatus('Error: ' + (err && err.message || err)),
        });
        return dict;
    }

    function onTranscriptChunk(chunk /*, fullSoFar */) {
        if (!chunk) return;
        // Append to the textarea exactly the way a user would type it,
        // with a single space joiner if there's already text. Persisting to
        // Ragsmith is no longer automatic — the user controls it explicitly
        // via the Save-to-AI button so each note maps to one dictation
        // message (which can be re-saved/updated cleanly).
        const ta = els.transcript;
        const before = ta.value;
        const sep = before.length === 0 ? '' :
                    /\n\s*$/.test(before) ? '' :
                    /[ \t]$/.test(before) ? '' : ' ';
        ta.value = before + sep + chunk;
        // Keep cursor / scroll at the end so the user sees new text appear.
        ta.scrollTop = ta.scrollHeight;
        scheduleSave();
        updateAiSaveButtonState();
    }

    function onDictationStateChange(s) {
        // States: 'idle' | 'connecting' | 'listening' | 'speaking' | 'muted' | 'error'
        switch (s) {
            case 'idle':       state.listening = false; setMicVisual(false); setStatus('Idle'); break;
            case 'connecting': setStatus('Connecting…'); break;
            case 'listening':  state.listening = true;  setMicVisual(true);  setStatus(state.muted ? 'Muted' : 'Listening…'); break;
            case 'speaking':   setStatus(state.muted ? 'Muted' : 'Hearing you…'); break;
            case 'muted':      setStatus('Muted'); break;
            case 'error':      setStatus('Mic error'); break;
        }
    }

    function setMicVisual(on) {
        els.mic.classList.toggle('is-on', on);
        els.mic.setAttribute('aria-pressed', on ? 'true' : 'false');
        els.mic.setAttribute('aria-label', on ? 'Stop dictation' : 'Start dictation');
        els.mute.disabled = !on;
        if (!on) {
            // Reset mute visual when mic stops.
            state.muted = false;
            els.mute.classList.remove('is-muted');
            els.mute.setAttribute('aria-pressed', 'false');
            els.mute.querySelector('.firefly-capture-mute-label').textContent = 'Mute';
        }
    }

    async function micToggle() {
        if (!state.currentId) {
            // No note loaded — create one so dictation has somewhere to land.
            await createNote();
        }
        try {
            ensureDictation().toggle();
        } catch (e) {
            setStatus(e.message);
        }
    }

    function muteToggle() {
        if (!dict) return;
        dict.toggleMute();
        state.muted = !state.muted;
        els.mute.classList.toggle('is-muted', state.muted);
        els.mute.setAttribute('aria-pressed', state.muted ? 'true' : 'false');
        els.mute.querySelector('.firefly-capture-mute-label').textContent = state.muted ? 'Unmute' : 'Mute';
        if (state.listening) setStatus(state.muted ? 'Muted' : 'Listening…');
    }

    // ---------- Init ----------
    function bindRefs() {
        els.list           = $('firefly-capture-list');
        els.count          = $('firefly-capture-count');
        els.main           = $('firefly-capture-main');
        els.title          = $('firefly-capture-title');
        els.transcript     = $('firefly-capture-transcript');
        els.edit           = $('firefly-capture-edit');
        els.delete         = $('firefly-capture-delete');
        els.modified       = $('firefly-capture-meta-modified');
        els.saved          = $('firefly-capture-meta-saved');
        els.mic            = $('firefly-capture-mic');
        els.mute           = $('firefly-capture-mute');
        els.status         = $('firefly-capture-status');
        els.newBtn         = $('firefly-capture-new');
        els.toggleList     = $('firefly-capture-toggle-list');
        els.warmBtn        = $('firefly-capture-warm');
        els.warmPill       = $('firefly-capture-warm-pill');
        els.warmPillText   = $('firefly-capture-warm-pill-text');
        els.sessionBtn     = $('firefly-capture-session-btn');
        els.sessionLabel   = $('firefly-capture-session-label');
        els.sessionPopover = $('firefly-capture-session-popover');
        els.sessionList    = $('firefly-capture-session-list');
        els.sessionNew     = $('firefly-capture-session-new');
        els.aiSave         = $('firefly-capture-ai-save');
    }

    function notifyError(e) {
        return modal.alert({
            title: 'Something went wrong',
            message: e && e.message ? e.message : String(e),
        });
    }

    function bindEvents() {
        // Mode-aware "New" button: in Notes mode creates a new note (existing
        // flow); in Recordings/Documents it clears the loaded item and returns
        // to the entry state so the user can start a fresh capture without the
        // previous one's title/transcript bleeding through.
        els.newBtn.addEventListener('click', () => {
            // For non-notes modes, collapse the sidebar in the same way
            // openModeItem does — otherwise on mobile (or any layout where
            // the "All recordings" list overlays the pane) the entry template
            // renders behind the list and looks like nothing happened.
            if (state.activeMode === 'recordings') {
                try { setSidebarOpen(false); } catch (e) {}
                showRecordingEntry();
                return;
            }
            if (state.activeMode === 'documents') {
                try { setSidebarOpen(false); } catch (e) {}
                showDocumentEntry();
                return;
            }
            createNote().catch(notifyError);
        });
        els.delete.addEventListener('click', () => deleteNote().catch(notifyError));
        els.edit.addEventListener('click', () => setEditMode(!state.editMode));
        // Every keystroke that changes the textarea (typed or pasted) flips the
        // Save-to-AI button from synced to dirty so the user sees the change
        // needs another push. The title doesn't affect Ragsmith state, so it
        // only triggers the WP-side debounced save.
        els.title.addEventListener('input', scheduleSave);
        els.transcript.addEventListener('input', () => {
            scheduleSave();
            updateAiSaveButtonState();
        });
        if (els.aiSave) {
            els.aiSave.addEventListener('click', () => saveNoteToAI().catch(notifyError));
        }
        els.mic.addEventListener('click', micToggle);
        els.mute.addEventListener('click', muteToggle);
        if (els.sessionBtn) {
            els.sessionBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                els.sessionPopover.hidden ? openSessionPopover() : closeSessionPopover();
            });
        }
        if (els.sessionNew) {
            els.sessionNew.addEventListener('click', () => { createSession(); closeSessionPopover(); });
        }
        if (els.warmBtn) {
            els.warmBtn.addEventListener('click', onWarm);
        }
        if (els.toggleList) {
            els.toggleList.addEventListener('click', () => {
                const wrap = document.querySelector('.firefly-capture');
                setSidebarOpen(!wrap.classList.contains('show-sidebar'));
            });
        }
        // Best-effort flush on page leave so a fast-typed note isn't lost.
        window.addEventListener('beforeunload', () => {
            if (state.savePending && state.currentId) {
                navigator.sendBeacon && navigator.sendBeacon(
                    REST + '/notes/' + state.currentId,
                    new Blob([JSON.stringify({
                        title: els.title.value,
                        content: els.transcript.value,
                        _wpnonce: NONCE,
                    })], { type: 'application/json' })
                );
            }
        });
    }

    /**
     * One-shot localStorage key rename: the firefly template renamed the
     * Notes app to Capture, which also renamed the LS prefix. Copy any
     * `firefly-notes/*` value into the matching `firefly-capture/*` slot
     * on first load so a user's active session and the legacy session
     * blob survive the rename. Safe to re-run — no-op once the legacy
     * keys are gone.
     */
    function migrateLocalStorageKeyPrefix() {
        const pairs = [
            ['firefly-notes/sessions/v1',       'firefly-capture/sessions/v1'],
            ['firefly-notes/active-session/v1', 'firefly-capture/active-session/v1'],
        ];
        for (const [from, to] of pairs) {
            try {
                const v = localStorage.getItem(from);
                if (v === null) continue;
                if (localStorage.getItem(to) === null) localStorage.setItem(to, v);
                localStorage.removeItem(from);
            } catch (e) { /* private mode etc. */ }
        }
    }

    async function init() {
        bindRefs();
        if (!els.list) return;
        bindEvents();

        // Warm-state pill: paint from localStorage immediately (no flicker),
        // start the 60s "Xm ago" tick, then fire a single fresh status
        // fetch against /agent/status. The fetch overrides the cached
        // state when it lands. After this, the pill updates only on
        // warm-up success or the next page load.
        warmPillState = loadWarmCache();
        renderWarmPill();
        startWarmTick();
        fetchWarmStatus().catch(() => {});

        // While async work is in flight, give the user something to look at.
        els.list.innerHTML = '<li class="firefly-capture-empty">Loading&hellip;</li>';

        try {
            // Notes → Capture rename: rehome any legacy localStorage keys.
            migrateLocalStorageKeyPrefix();
            // One-shot localStorage → server import on first load after upgrade.
            await migrateLegacyLocalStorageIfPresent();
            await loadSessionsFromServer();
            resolveInitialActiveSession();
            renderActiveSession();
            renderSessionList();
            await loadList(true);
        } catch (e) {
            console.error('[FireflyCapture] init failed:', e);
            els.list.innerHTML = '<li class="firefly-capture-empty">Failed to load: ' + (e && e.message ? e.message : e) + '</li>';
        }

        // Browser back/forward through different ?session= values — keep the
        // app in sync. Falls through harmlessly if the URL param points at a
        // session we don't own anymore.
        window.addEventListener('popstate', () => {
            const fromUrl = Number(new URLSearchParams(window.location.search).get('session') || 0);
            if (fromUrl && fromUrl !== state.activeSessionId &&
                state.sessions.some((s) => Number(s.id) === fromUrl)) {
                setActiveSession(fromUrl).catch(() => {});
            }
        });
    }

    // ==========================================================================
    // Capture: mode switching + Recordings + Documents.
    //
    // The Notes-only logic above remains the implementation of Notes mode.
    // The block below adds two new modes (Recordings, Documents) and the
    // tab-strip / sidebar-list switching that lets one page host all three.
    // Each mode shares the active session (and therefore the bound Ragsmith
    // conversation) so dictation / recordings / docs all land in one place.
    // ==========================================================================

    state.activeMode = 'notes'; // 'notes' | 'recordings' | 'documents'
    state.modeItems  = { recordings: [], documents: [] };
    state.rec = {
        // Capture-time state. Web Audio API mixes mic + (optional) tab/window
        // audio into a single MediaStream so MediaRecorder gets one track:
        //   micStream  → micSource → micGainNode ─┐
        //   displayStream → sysSource ────────────┤→ mixDestination → recorder
        //   mixDestination → analyser (level meter)
        // Mute toggles micGainNode.gain (mic only); display audio keeps flowing.
        recorder: null, stream: null, displayStream: null, combinedStream: null,
        audioCtx: null, micGain: null, mixDestination: null, analyser: null,
        chunks: [], startedAt: 0, timerId: null, meterRaf: 0,
        paused: false, pausedAccum: 0, pausedAt: 0, muted: false,

        // Post-stop / processing state
        recordedBlob: null, recordedUrl: null, durationSec: 0,
        currentPostId: null,         // firefly_recording WP post id
        ragsmithAudioPath: null,     // Ragsmith server-side file path
        sseAbort: null,              // AbortController for the SSE pipeline
        finalTranscript: '',
        finalSummary: '',
        speakers: {},                // {SPEAKER_00: "Alice", ...}
        result: null,                // final 'complete' event payload
    };
    state.doc = { uploadInFlight: false, currentPostId: null, currentRsSessionId: null };

    // Stage-label dictionary copied from Ragsmith for consistency.
    const SSE_STAGE_LABELS = {
        converting: 'Converting audio…',
        transcribing: 'Transcribing audio…',
        diarizing: 'Identifying speakers…',
        aligning: 'Aligning speakers with transcript…',
        building_transcript: 'Building transcript…',
        summarizing: 'Generating summary…',
        chunking: 'Preparing for knowledge base…',
        ingesting: 'Adding to knowledge base…',
    };

    const modeEls = {};

    function bindModeRefs() {
        modeEls.tabs           = document.querySelectorAll('.firefly-capture-modetab');
        modeEls.panes          = document.querySelectorAll('.firefly-capture-pane');
        modeEls.wrap           = document.querySelector('.wrap.firefly-capture');
        modeEls.modeLabelEls   = document.querySelectorAll('[data-mode-label-notes]');

        // Entry-point on the recording pane (toggles + Record button)
        modeEls.recTitle       = document.getElementById('firefly-capture-rec-title');
        modeEls.recShareAudio  = document.getElementById('firefly-capture-rec-share-audio');
        modeEls.recRoomAudio   = document.getElementById('firefly-capture-rec-room-audio');
        modeEls.recStartMuted  = document.getElementById('firefly-capture-rec-start-muted');
        modeEls.recBtn         = document.getElementById('firefly-capture-rec-btn');

        // 1. Recording overlay
        modeEls.recOverlay     = document.getElementById('ffrec-overlay');
        modeEls.recDot         = document.getElementById('ffrec-dot');
        modeEls.recTimer       = document.getElementById('ffrec-timer');
        modeEls.recMeterBars   = document.querySelectorAll('#ffrec-meter .ffrec-level-bar');
        modeEls.recMuteBtn     = document.getElementById('ffrec-mute-btn');
        modeEls.recMuteLabel   = document.getElementById('ffrec-mute-label');
        modeEls.recPauseBtn    = document.getElementById('ffrec-pause-btn');
        modeEls.recPauseLabel  = document.getElementById('ffrec-pause-label');
        modeEls.recStopBtn     = document.getElementById('ffrec-stop-btn');

        // 2. Complete overlay
        modeEls.recCompleteOverlay  = document.getElementById('ffrec-complete-overlay');
        modeEls.recCompleteDuration = document.getElementById('ffrec-complete-duration');
        modeEls.recPreviewAudio     = document.getElementById('ffrec-preview-audio');
        modeEls.recPreviewBtn       = document.getElementById('ffrec-preview-btn');
        modeEls.recDiscardBtn       = document.getElementById('ffrec-discard-btn');
        modeEls.recProcessBtn       = document.getElementById('ffrec-process-btn');

        // 3. Pre-processing dialog (chips)
        modeEls.recPreprocessDialog = document.getElementById('ffrec-preprocess-dialog');
        modeEls.recPreprocessKb     = document.getElementById('ffrec-preprocess-kb');
        modeEls.recChipTranscript   = document.getElementById('ffrec-chip-transcript');
        modeEls.recChipSummary      = document.getElementById('ffrec-chip-summary');
        modeEls.recChipDiarization  = document.getElementById('ffrec-chip-diarization');
        modeEls.recPreprocessCancel = document.getElementById('ffrec-preprocess-cancel');
        modeEls.recPreprocessStart  = document.getElementById('ffrec-preprocess-start');

        // 4. Processing overlay (SSE progress)
        modeEls.recProcessingOverlay = document.getElementById('ffrec-processing-overlay');
        modeEls.recProgressBar       = document.getElementById('ffrec-progress-bar');
        modeEls.recProgressText      = document.getElementById('ffrec-progress-text');
        modeEls.recStopProcessing    = document.getElementById('ffrec-stop-processing');
        modeEls.recStopProcessingBtn = document.getElementById('ffrec-stop-processing-btn');
        modeEls.recErrorActions      = document.getElementById('ffrec-error-actions');
        modeEls.recRetryBtn          = document.getElementById('ffrec-retry-btn');
        modeEls.recDismissBtn        = document.getElementById('ffrec-dismiss-btn');

        // 5. Completion / speaker dialog
        modeEls.recKbDialog       = document.getElementById('ffrec-kb-dialog');
        modeEls.recKbStats        = document.querySelector('.ffrec-kb-stats');
        modeEls.recSpeakersSection= document.getElementById('ffrec-speakers-section');
        modeEls.recSpeakersList   = document.getElementById('ffrec-speakers-list');
        modeEls.recKbCancel       = document.getElementById('ffrec-kb-cancel');
        modeEls.recKbConfirm      = document.getElementById('ffrec-kb-confirm');
        modeEls.recKbRefs         = document.getElementById('ffrec-kb-refs');
        modeEls.recKbRefTrans     = document.getElementById('ffrec-kb-ref-transcript');
        modeEls.recKbRefSummary   = document.getElementById('ffrec-kb-ref-summary');
        modeEls.recKbPrevTrans    = document.getElementById('ffrec-kb-preview-transcript');
        modeEls.recKbPrevSummary  = document.getElementById('ffrec-kb-preview-summary');

        // Document mode
        modeEls.docTitle        = document.getElementById('firefly-capture-doc-title');
        modeEls.docDelete       = document.getElementById('firefly-capture-doc-delete');
        modeEls.docStatus       = document.getElementById('firefly-capture-doc-status');
        modeEls.docEntry        = document.getElementById('firefly-capture-doc-entry');
        modeEls.docDetail       = document.getElementById('firefly-capture-doc-detail');
        modeEls.docDropzone     = document.getElementById('firefly-capture-doc-dropzone');
        modeEls.docFile         = document.getElementById('firefly-capture-doc-file');
        modeEls.docText         = document.getElementById('firefly-capture-doc-text');
        modeEls.docFactsSection = document.getElementById('firefly-capture-doc-facts-section');
        modeEls.docFacts        = document.getElementById('firefly-capture-doc-facts');
        modeEls.docFactsCount   = document.getElementById('firefly-capture-doc-facts-count');
        modeEls.docKb           = document.getElementById('firefly-capture-doc-kb');
        modeEls.docDownload     = document.getElementById('firefly-capture-doc-download');
        modeEls.docProcessingOverlay = document.getElementById('ffdoc-processing-overlay');
        modeEls.docPhaseTitle   = document.getElementById('ffdoc-phase-title');
        modeEls.docPhaseDesc    = document.getElementById('ffdoc-phase-desc');

        // Sidebar label that swaps "Your notes / recordings / documents"
        modeEls.sidebarLabel   = document.querySelector('.firefly-capture-sidebar-label');

        // Loaded-recording detail panel (shown when a past recording is opened)
        modeEls.recEntry           = document.getElementById('firefly-capture-rec-entry');
        modeEls.recDetail          = document.getElementById('firefly-capture-rec-detail');
        modeEls.recDetailStatus    = document.getElementById('firefly-capture-rec-detail-status');
        modeEls.recDetailAudio     = document.getElementById('firefly-capture-rec-detail-audio');
        modeEls.recDetailTranscript= document.getElementById('firefly-capture-rec-detail-transcript');
        modeEls.recDetailSummary   = document.getElementById('firefly-capture-rec-detail-summary');
        modeEls.recBackBtn         = document.getElementById('firefly-capture-rec-back');
        modeEls.recReprocessBtn    = document.getElementById('firefly-capture-rec-reprocess');
    }

    /**
     * Update visible pane + tab aria + dynamic labels + sidebar list to the
     * requested mode. Re-fetches the mode-appropriate item list from the
     * server so the sidebar always reflects the active mode + session.
     */
    async function setActiveMode(mode) {
        if (mode === state.activeMode) return;
        if (mode !== 'notes' && mode !== 'recordings' && mode !== 'documents') return;
        state.activeMode = mode;
        if (modeEls.wrap) modeEls.wrap.setAttribute('data-mode', mode);
        modeEls.tabs.forEach((tab) => {
            const on = tab.getAttribute('data-mode') === mode;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        modeEls.panes.forEach((pane) => {
            pane.hidden = pane.getAttribute('data-mode') !== mode;
        });
        // Swap "New note" → "New recording" → "New document" etc.
        modeEls.modeLabelEls.forEach((el) => {
            const next = el.getAttribute('data-mode-label-' + mode);
            if (next) el.textContent = next;
        });
        // Refresh the sidebar list to the active mode's items.
        await loadModeList();
    }

    /**
     * Mode-aware sidebar list. For notes we defer to the existing loadList()
     * which already handles the notes index. For recordings/documents we
     * fetch the matching REST endpoint and render a compact row per item.
     */
    async function loadModeList() {
        if (state.activeMode === 'notes') {
            return loadList(false);
        }
        if (!els.list) return;
        const sessionId = state.activeSessionId;
        if (!sessionId) {
            els.list.innerHTML = '<li class="firefly-capture-empty">Pick a session first.</li>';
            return;
        }
        els.list.innerHTML = '<li class="firefly-capture-empty">Loading&hellip;</li>';
        try {
            const path = '/' + state.activeMode + '?session=' + encodeURIComponent(sessionId);
            const res  = await api(path);
            const items = (res && res.items) || [];
            state.modeItems[state.activeMode] = items;
            renderModeList(items);
        } catch (e) {
            console.error('[FireflyCapture] loadModeList failed:', e);
            els.list.innerHTML = '<li class="firefly-capture-empty">Failed to load.</li>';
        }
    }

    function renderModeList(items) {
        if (!els.list) return;
        if (!items.length) {
            els.list.innerHTML = '<li class="firefly-capture-empty">No ' + state.activeMode + ' yet in this session.</li>';
            if (els.count) els.count.textContent = '';
            return;
        }
        const parts = items.map((it) => {
            const title  = (it.title || 'Untitled').replace(/</g, '&lt;');
            const status = it.status ? ' · ' + it.status : '';
            return '<li class="firefly-capture-item" data-id="' + it.id + '">'
                +    '<a href="#" class="firefly-capture-item-link">'
                +      '<span class="firefly-capture-item-title">' + title + '</span>'
                +      '<span class="firefly-capture-item-meta">' + (it.modified || '') + status + '</span>'
                +    '</a>'
                +    '<button type="button" class="firefly-capture-item-delete" data-id="' + it.id + '" aria-label="Delete" title="Delete">×</button>'
                +  '</li>';
        });
        els.list.innerHTML = parts.join('');
        if (els.count) els.count.textContent = String(items.length);

        // Wire delete buttons + item clicks (open detail) for the new modes.
        els.list.querySelectorAll('.firefly-capture-item-delete').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const id = Number(btn.getAttribute('data-id'));
                if (!id) return;
                deleteModeItem(id);
            });
        });
        els.list.querySelectorAll('.firefly-capture-item-link').forEach((link) => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const li = link.closest('.firefly-capture-item');
                const id = li && Number(li.getAttribute('data-id'));
                if (!id) return;
                openModeItem(id);
            });
        });
    }

    /**
     * Open an existing recording/document into the mode pane. For recordings
     * this shows the detail panel (transcript + summary + Reprocess). For
     * documents it'd be the same idea (not yet wired — pending user test
     * confirmation that the recording flow is correct).
     */
    async function openModeItem(id) {
        // Both modes auto-collapse the sidebar on mobile so the detail view
        // fills the viewport — same UX the Notes flow already uses.
        try { setSidebarOpen(false); } catch (e) {}
        if (state.activeMode === 'recordings') return openRecording(id);
        if (state.activeMode === 'documents')  return openDocument(id);
    }

    async function openDocument(id) {
        try {
            const d = await api('/documents/' + id);
            if (!d) return;
            showDocumentLoaded(d);
            els.list && els.list.querySelectorAll('.firefly-capture-item').forEach((li) => {
                li.classList.toggle('is-active', Number(li.getAttribute('data-id')) === id);
            });
        } catch (e) {
            console.error('[FireflyCapture] openDocument failed:', e);
            alert('Could not load document: ' + (e && e.message ? e.message : e));
        }
    }

    async function openRecording(id) {
        try {
            const r = await api('/recordings/' + id);
            if (!r) return;
            state.rec.currentPostId       = r.id;
            state.rec.ragsmithAudioPath   = r.audio_url || '';
            state.rec.finalTranscript     = r.transcript || '';
            state.rec.finalSummary        = r.summary    || '';

            if (modeEls.recTitle)             modeEls.recTitle.value = r.title || '';
            // Snapshot the loaded title so the blur-save handler (bindModeEvents)
            // can tell whether the user actually edited it. Non-null also doubles
            // as the "we're in detail view" gate — entry-mode reset clears it.
            state.rec.lastSavedTitle = r.title || '';
            if (modeEls.recDetailStatus)      modeEls.recDetailStatus.textContent = r.status || 'ready';
            if (modeEls.recDetailTranscript)  modeEls.recDetailTranscript.textContent = r.transcript || '(no transcript)';
            if (modeEls.recDetailSummary)     modeEls.recDetailSummary.textContent    = r.summary    || '(no summary)';
            if (modeEls.recDetailAudio) {
                // Prefer the WP-hosted MP3 (browser-playable URL inside
                // /wp-content/uploads/). The audio_url meta is the
                // Ragsmith server-relative path which the browser can't
                // hit directly — only used for re-processing.
                if (r.mp3_url) {
                    modeEls.recDetailAudio.src = r.mp3_url;
                } else if (r.audio_url) {
                    modeEls.recDetailAudio.removeAttribute('src');
                }
            }
            // Highlight the active item.
            els.list && els.list.querySelectorAll('.firefly-capture-item').forEach((li) => {
                li.classList.toggle('is-active', Number(li.getAttribute('data-id')) === id);
            });
            // Toggle entry → detail
            if (modeEls.recEntry)  modeEls.recEntry.hidden  = true;
            if (modeEls.recDetail) modeEls.recDetail.hidden = false;
        } catch (e) {
            console.error('[FireflyCapture] openRecording failed:', e);
            alert('Could not load recording: ' + (e && e.message ? e.message : e));
        }
    }

    /**
     * Reset the Recordings pane to the "new recording" template: no loaded
     * recording, empty title input (placeholder visible), toggles reset,
     * entry visible, detail hidden. Called from the global New button when
     * in Recordings mode and after a Discard from the complete overlay.
     */
    function showRecordingEntry() {
        state.rec.currentPostId     = null;
        state.rec.ragsmithAudioPath = null;
        state.rec.finalTranscript   = '';
        state.rec.finalSummary      = '';
        if (modeEls.recTitle) modeEls.recTitle.value = '';
        // Clearing lastSavedTitle disarms the blur-save handler — entry
        // mode is for typing the title of the NEXT recording, not for
        // editing a loaded one. Without this, a blur in entry mode with
        // a stale state.rec.currentPostId would PATCH the wrong post.
        state.rec.lastSavedTitle = null;
        if (modeEls.recShareAudio) modeEls.recShareAudio.checked = false;
        if (modeEls.recRoomAudio)  modeEls.recRoomAudio.checked  = false;
        if (modeEls.recStartMuted) modeEls.recStartMuted.checked = false;
        if (modeEls.recEntry)  modeEls.recEntry.hidden  = false;
        if (modeEls.recDetail) modeEls.recDetail.hidden = true;
        if (modeEls.recDetailAudio) {
            try { modeEls.recDetailAudio.pause(); } catch (e) {}
            modeEls.recDetailAudio.removeAttribute('src');
        }
        els.list && els.list.querySelectorAll('.firefly-capture-item').forEach((li) => li.classList.remove('is-active'));
    }

    /**
     * Reset the Documents pane to the empty dropzone state. Hides the
     * loaded detail view + the Delete button (no doc loaded = nothing to
     * delete) and clears any previous text/facts.
     */
    function showDocumentEntry() {
        state.doc.currentPostId   = null;
        state.doc.currentRsSessionId = null;
        if (modeEls.docTitle)        modeEls.docTitle.textContent = 'Drop a document to ingest';
        if (modeEls.docStatus)       modeEls.docStatus.textContent = 'Idle';
        if (modeEls.docEntry)        modeEls.docEntry.hidden  = false;
        if (modeEls.docDetail)       modeEls.docDetail.hidden = true;
        if (modeEls.docText)         modeEls.docText.textContent  = '';
        if (modeEls.docFacts)        modeEls.docFacts.innerHTML   = '';
        if (modeEls.docFactsCount)   modeEls.docFactsCount.textContent = '';
        if (modeEls.docFactsSection) modeEls.docFactsSection.hidden = true;
        if (modeEls.docKb)           modeEls.docKb.textContent    = '';
        if (modeEls.docFile)         modeEls.docFile.value = '';
        if (modeEls.docDelete)       modeEls.docDelete.hidden = true;
        if (modeEls.docDownload) {
            modeEls.docDownload.removeAttribute('href');
            modeEls.docDownload.removeAttribute('download');
        }
        els.list && els.list.querySelectorAll('.firefly-capture-item').forEach((li) => li.classList.remove('is-active'));
    }

    /**
     * Switch the Documents pane to the "loaded" state for an existing doc.
     * Populates the download link, the extracted text, the facts list and
     * the meta toolbar. Shows the Delete button (we now have something
     * worth deleting).
     */
    function showDocumentLoaded(doc) {
        if (!doc) return;
        state.doc.currentPostId      = doc.id;
        state.doc.currentRsSessionId = doc.rs_session_id || null;
        if (modeEls.docTitle)        modeEls.docTitle.textContent = doc.title || doc.filename || 'Document';
        if (modeEls.docStatus)       modeEls.docStatus.textContent = doc.status === 'extracting' ? 'Extracting facts…' : (doc.status || 'Ready');
        if (modeEls.docEntry)        modeEls.docEntry.hidden  = true;
        if (modeEls.docDetail)       modeEls.docDetail.hidden = false;
        if (modeEls.docText)         modeEls.docText.textContent = doc.text || '(no text extracted)';
        if (modeEls.docKb)           modeEls.docKb.textContent = doc.ephemeral_kb_path
            ? ('KB: ' + doc.ephemeral_kb_path + ' · chunks: ' + (doc.chunk_count || 0))
            : '';
        // Facts list — hidden when empty so the layout doesn't show an
        // orphan heading. extract_facts_only returns each fact as
        // { key, value, confidence } — same shape Ragsmith renders in
        // its chip body. We mirror that visual: key bolded on the left,
        // value following.
        const facts = Array.isArray(doc.facts) ? doc.facts : [];
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
        if (modeEls.docFacts) {
            modeEls.docFacts.innerHTML = facts.map((f) => {
                if (typeof f === 'string') return '<li>' + esc(f) + '</li>';
                if (f && typeof f === 'object') {
                    const k = f.key || '';
                    const v = f.value || f.content || f.text || '';
                    if (k && v) {
                        return '<li><strong>' + esc(k) + ':</strong> ' + esc(v) + '</li>';
                    }
                    return '<li>' + esc(v || JSON.stringify(f)) + '</li>';
                }
                return '';
            }).join('');
        }
        if (modeEls.docFactsSection) modeEls.docFactsSection.hidden = facts.length === 0;
        if (modeEls.docFactsCount) {
            const n = Number(doc.facts_count || facts.length || 0);
            modeEls.docFactsCount.textContent = n > 0 ? (n + (n === 1 ? ' fact extracted' : ' facts extracted')) : '';
        }
        // Download link — points at the WP attachment we saved at upload
        // time. If for some reason it's missing (older docs, save failed),
        // hide the button so the user doesn't click a dead link.
        if (modeEls.docDownload) {
            if (doc.file_url) {
                modeEls.docDownload.href = doc.file_url;
                if (doc.filename) modeEls.docDownload.setAttribute('download', doc.filename);
                modeEls.docDownload.hidden = false;
            } else {
                modeEls.docDownload.hidden = true;
            }
        }
        if (modeEls.docDelete) modeEls.docDelete.hidden = false;
    }

    /**
     * Delete a recording or document on the current mode.
     *
     * Uses the same Notes UX: a modal.confirm danger prompt, then a
     * fullscreen busy.wrap spinner while the cascade runs server-side
     * (drop the Ragsmith-side file/message + trash the WP post). The
     * fullscreen spinner is important because the round-trip can take
     * a couple seconds when Ragsmith is also removing the audio file.
     */
    async function deleteModeItem(id) {
        if (state.activeMode === 'notes') return; // notes have their own delete
        const labelMap = { recordings: 'recording', documents: 'document' };
        const label = labelMap[state.activeMode] || 'item';
        const proceed = await modal.confirm({
            title:        'Delete ' + label + '?',
            message:      'This also removes it from the AI conversation. This cannot be undone.',
            confirmLabel: 'Delete',
            danger:       true,
        });
        if (!proceed) return;

        try {
            await busy.wrap('Deleting…', () => api('/' + state.activeMode + '/' + id, { method: 'DELETE' }));
            // If the deleted item was the one currently loaded in the detail
            // panel, swap back to the entry point. We also reset when the
            // deletion was triggered from the toolbar (where the loaded doc
            // is, by definition, the current one) — and as a defensive
            // fallback any time there's no currentPostId in the active mode.
            if (state.activeMode === 'recordings' && state.rec.currentPostId === id) {
                showRecordingEntry();
            }
            if (state.activeMode === 'documents' && (state.doc.currentPostId === id || !state.doc.currentPostId)) {
                showDocumentEntry();
            }
            await loadModeList();
        } catch (e) {
            console.error('[FireflyCapture] delete failed:', e);
            await modal.alert({
                title:   'Delete failed',
                message: e && e.message ? e.message : String(e),
            });
        }
    }

    // ── IndexedDB recording recovery ────────────────────────────────────────
    // Ports the persistence layer from ragsmith's audio-recorder.js so an
    // interrupted upload (502 from the SSH tunnel going down, browser
    // crash, accidental tab close) can be recovered on next page load.
    //
    // During recording, MediaRecorder fires `dataavailable` on a 30s
    // timeslice (matching ragsmith's CHUNK_INTERVAL_MS). Each fired chunk
    // is appended to the in-memory `state.rec.chunks` array AND persisted
    // to the `chunks` object store keyed by `[recordingId, chunkIndex]`.
    // On successful upload (HTTP 2xx), we delete the per-recording rows.
    // On any upload failure we leave them in place — on the next Capture
    // page load, `recFindIncomplete()` finds them and the recovery banner
    // offers Resume or Discard.
    const REC_DB_NAME = 'firefly_capture_recordings';
    const REC_DB_VERSION = 1;
    const REC_STORE = 'chunks';
    const REC_CHUNK_INTERVAL_MS = 30000; // 30s — matches ragsmith
    let _recDb = null;

    function recOpenDB() {
        return new Promise((resolve, reject) => {
            if (_recDb) { resolve(_recDb); return; }
            const req = indexedDB.open(REC_DB_NAME, REC_DB_VERSION);
            req.onupgradeneeded = (e) => {
                const store = e.target.result.createObjectStore(REC_STORE, { keyPath: ['recordingId', 'chunkIndex'] });
                store.createIndex('byRecording', 'recordingId');
            };
            req.onsuccess = (e) => { _recDb = e.target.result; resolve(_recDb); };
            req.onerror  = (e) => reject(e.target.error);
        });
    }

    async function recSaveChunk(recordingId, chunkIndex, blob, totalDuration, status) {
        const db = await recOpenDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(REC_STORE, 'readwrite');
            tx.objectStore(REC_STORE).put({
                recordingId, chunkIndex, blob,
                timestamp: Date.now(),
                totalDuration, status,
            });
            tx.oncomplete = () => resolve();
            tx.onerror    = () => reject(tx.error);
        });
    }

    async function recGetChunks(recordingId) {
        const db = await recOpenDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(REC_STORE, 'readonly');
            const idx = tx.objectStore(REC_STORE).index('byRecording');
            const req = idx.getAll(recordingId);
            req.onsuccess = () => resolve(req.result.sort((a, b) => a.chunkIndex - b.chunkIndex));
            req.onerror   = () => reject(req.error);
        });
    }

    async function recClearChunks(recordingId) {
        const db = await recOpenDB();
        const chunks = await recGetChunks(recordingId);
        if (!chunks.length) return;
        return new Promise((resolve, reject) => {
            const tx = db.transaction(REC_STORE, 'readwrite');
            const store = tx.objectStore(REC_STORE);
            for (const c of chunks) store.delete([c.recordingId, c.chunkIndex]);
            tx.oncomplete = () => resolve();
            tx.onerror    = () => reject(tx.error);
        });
    }

    /**
     * Scan all chunks; if the latest chunk for any recording isn't yet
     * marked 'complete', return that recording. Only the first incomplete
     * recording is surfaced — concurrent unfinished recordings are an
     * edge case we don't bother UI'ing in v1.
     */
    async function recFindIncomplete() {
        const db = await recOpenDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(REC_STORE, 'readonly');
            const req = tx.objectStore(REC_STORE).getAll();
            req.onsuccess = () => {
                const all = req.result;
                if (!all.length) { resolve(null); return; }
                const byRec = {};
                for (const c of all) (byRec[c.recordingId] ||= []).push(c);
                for (const [id, chunks] of Object.entries(byRec)) {
                    const sorted = chunks.sort((a, b) => a.chunkIndex - b.chunkIndex);
                    const latest = sorted[sorted.length - 1];
                    if (latest.status !== 'complete') {
                        resolve({ recordingId: id, chunks: sorted, duration: latest.totalDuration || 0 });
                        return;
                    }
                }
                resolve(null);
            };
            req.onerror = () => reject(req.error);
        });
    }

    // ── Recovery banner: surfaces an unfinished recording on page load ──
    function renderRecoveryBanner(info) {
        const banner = document.getElementById('ffrec-recovery-banner');
        if (!banner) return;
        const durEl = banner.querySelector('.ffrec-recovery-duration');
        if (durEl) durEl.textContent = formatHMS(Math.round(info.duration || 0));
        banner.style.display = '';
        banner.dataset.recordingId = info.recordingId;
    }

    function hideRecoveryBanner() {
        const banner = document.getElementById('ffrec-recovery-banner');
        if (banner) banner.style.display = 'none';
    }

    async function maybeShowRecoveryBanner() {
        try {
            const incomplete = await recFindIncomplete();
            if (incomplete) renderRecoveryBanner(incomplete);
        } catch (e) {
            // Non-fatal — Safari private mode etc. Let recording continue.
            console.warn('[FireflyCapture] recovery check failed:', e);
        }
    }

    /**
     * Resume button — stitch IDB chunks into a single Blob, retry the
     * upload via the same /audio/upload bridge endpoint, and on success
     * jump into the Recording Complete overlay so the user can hit
     * Process exactly as if the original upload had succeeded.
     */
    async function recoveryResume() {
        const banner = document.getElementById('ffrec-recovery-banner');
        const resumeBtn = document.getElementById('ffrec-recovery-resume');
        if (!banner || !banner.dataset.recordingId) return;
        const recordingId = banner.dataset.recordingId;
        if (resumeBtn) { resumeBtn.disabled = true; resumeBtn.textContent = 'Resuming…'; }
        try {
            const chunks = await recGetChunks(recordingId);
            if (!chunks.length) { hideRecoveryBanner(); return; }
            const blob = new Blob(chunks.map(c => c.blob), { type: 'audio/webm;codecs=opus' });
            const lastDuration = chunks[chunks.length - 1].totalDuration || 0;

            // Re-prime the recording state so the Recording Complete
            // overlay's Preview + Process buttons behave normally.
            state.rec.recordedBlob = blob;
            if (state.rec.recordedUrl) URL.revokeObjectURL(state.rec.recordedUrl);
            state.rec.recordedUrl = URL.createObjectURL(blob);
            state.rec.durationSec = Math.round(lastDuration);
            state.rec.recordingId = recordingId;

            const fd = new FormData();
            const titleInput = modeEls.recTitle ? modeEls.recTitle.value.trim() : '';
            fd.append('file', blob, (titleInput || 'recovered-recording') + '.webm');
            if (titleInput) fd.append('title', titleInput);

            const upRes = await fetch(ragsmithRest() + '/audio/upload', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-WP-Nonce': NONCE, Accept: 'application/json' },
                body: fd,
            });
            if (!upRes.ok) throw new Error('HTTP ' + upRes.status);
            const upJson = await upRes.json();
            state.rec.ragsmithAudioPath = upJson && (upJson.file_path || upJson.path);

            // Upload survived — chunks no longer needed.
            await recClearChunks(recordingId);
            hideRecoveryBanner();

            // Surface the Recording Complete overlay so the user can Process.
            if (modeEls.recCompleteDuration) modeEls.recCompleteDuration.textContent = formatHMS(state.rec.durationSec);
            if (modeEls.recPreviewAudio) {
                modeEls.recPreviewAudio.src = state.rec.recordedUrl;
                modeEls.recPreviewAudio.style.display = 'none';
            }
            openOverlay(modeEls.recCompleteOverlay);
        } catch (e) {
            console.error('[FireflyCapture] resume failed:', e);
            alert('Resume failed: ' + (e && e.message ? e.message : e) +
                  '\n\nYour recording is still safe in browser storage. Try again in a moment.');
            if (resumeBtn) { resumeBtn.disabled = false; resumeBtn.textContent = 'Resume upload'; }
        }
    }

    async function recoveryDiscard() {
        const banner = document.getElementById('ffrec-recovery-banner');
        if (!banner || !banner.dataset.recordingId) return;
        if (!confirm('Discard the unfinished recording? This cannot be undone.')) return;
        try {
            await recClearChunks(banner.dataset.recordingId);
        } catch (e) {
            console.warn('[FireflyCapture] discard cleanup failed:', e);
        }
        hideRecoveryBanner();
    }

    function bindModeEvents() {
        modeEls.tabs.forEach((tab) => {
            tab.addEventListener('click', () => { setActiveMode(tab.getAttribute('data-mode')); });
        });

        // ---- Recording entry point (in-pane Record button) ----
        if (modeEls.recBtn) {
            modeEls.recBtn.addEventListener('click', () => startRecording());
        }

        // ---- Loaded-recording detail buttons ----
        if (modeEls.recBackBtn)      modeEls.recBackBtn.addEventListener('click', () => showRecordingEntry());
        if (modeEls.recReprocessBtn) modeEls.recReprocessBtn.addEventListener('click', () => openPreprocessDialog());

        // ---- Inline title rename on blur (loaded-recording detail view) ----
        // The title input is shared between "new recording" (entry mode) and
        // "view loaded recording" (detail mode) — same DOM element, different
        // semantics. We only PATCH on blur when:
        //   1. state.rec.currentPostId is set (a recording is loaded), AND
        //   2. state.rec.lastSavedTitle was snapshotted in openRecording (so
        //      we know what the saved value looked like before the user
        //      touched it — entry mode clears this to null), AND
        //   3. The trimmed value differs from the snapshot (no-op blurs
        //      shouldn't burn a REST round-trip).
        // On success we also rewrite the sidebar item's visible title and
        // update state.modeItems.recordings so subsequent re-renders use the
        // new value without another /recordings list fetch.
        if (modeEls.recTitle) {
            modeEls.recTitle.addEventListener('blur', async () => {
                const id = state.rec.currentPostId;
                if (!id || state.rec.lastSavedTitle === null || state.rec.lastSavedTitle === undefined) return;
                const next = (modeEls.recTitle.value || '').trim();
                const prev = state.rec.lastSavedTitle;
                if (next === prev) return;
                if (next === '') {
                    // Don't allow a blank title to overwrite a real one —
                    // restore the snapshot and bail.
                    modeEls.recTitle.value = prev;
                    return;
                }
                try {
                    await api('/recordings/' + id, { method: 'POST', body: { title: next } });
                    state.rec.lastSavedTitle = next;
                    // Sidebar item — update the visible title without re-fetching.
                    const li = els.list && els.list.querySelector('[data-id="' + id + '"]');
                    if (li) {
                        const titleEl = li.querySelector('.firefly-capture-item-title');
                        if (titleEl) titleEl.textContent = next;
                    }
                    // Cached items list — keep state.modeItems in sync so any
                    // later renderModeList() call (mode-switch, refresh) uses
                    // the new title instead of stomping on it with the stale one.
                    const items = state.modeItems && state.modeItems.recordings;
                    if (Array.isArray(items)) {
                        for (const it of items) {
                            if (Number(it.id) === Number(id)) { it.title = next; break; }
                        }
                    }
                } catch (e) {
                    console.error('[FireflyCapture] rename failed:', e);
                    // Revert the input so the user isn't left thinking the
                    // change persisted.
                    modeEls.recTitle.value = prev;
                    alert('Rename failed: ' + (e && e.message ? e.message : e));
                }
            });
        }

        // ---- Recovery banner (shown when an earlier upload didn't complete) ----
        const resumeBtn  = document.getElementById('ffrec-recovery-resume');
        const discardBtn = document.getElementById('ffrec-recovery-discard');
        if (resumeBtn)  resumeBtn.addEventListener('click', recoveryResume);
        if (discardBtn) discardBtn.addEventListener('click', recoveryDiscard);

        // ---- Recording overlay controls (during capture) ----
        if (modeEls.recMuteBtn)  modeEls.recMuteBtn.addEventListener('click', toggleRecMute);
        if (modeEls.recPauseBtn) modeEls.recPauseBtn.addEventListener('click', toggleRecPause);
        if (modeEls.recStopBtn)  modeEls.recStopBtn.addEventListener('click', stopRecording);

        // ---- Complete overlay buttons ----
        if (modeEls.recPreviewBtn) modeEls.recPreviewBtn.addEventListener('click', previewRecording);
        if (modeEls.recDiscardBtn) modeEls.recDiscardBtn.addEventListener('click', discardRecording);
        if (modeEls.recProcessBtn) modeEls.recProcessBtn.addEventListener('click', openPreprocessDialog);

        // ---- Pre-processing chips (toggle .is-active) ----
        [modeEls.recChipTranscript, modeEls.recChipSummary, modeEls.recChipDiarization].forEach((chip) => {
            if (chip) chip.addEventListener('click', () => chip.classList.toggle('is-active'));
        });
        if (modeEls.recPreprocessCancel) modeEls.recPreprocessCancel.addEventListener('click', () => closeOverlay(modeEls.recPreprocessDialog));
        if (modeEls.recPreprocessStart)  modeEls.recPreprocessStart.addEventListener('click', startProcessing);

        // ---- Processing overlay buttons ----
        if (modeEls.recStopProcessingBtn) modeEls.recStopProcessingBtn.addEventListener('click', stopProcessing);
        if (modeEls.recRetryBtn)          modeEls.recRetryBtn.addEventListener('click', startProcessing);
        if (modeEls.recDismissBtn)        modeEls.recDismissBtn.addEventListener('click', () => closeOverlay(modeEls.recProcessingOverlay));

        // ---- Completion dialog ----
        if (modeEls.recKbCancel)  modeEls.recKbCancel.addEventListener('click', () => closeOverlay(modeEls.recKbDialog));
        if (modeEls.recKbConfirm) modeEls.recKbConfirm.addEventListener('click', confirmSpeakerNames);
        if (modeEls.recKbRefTrans) modeEls.recKbRefTrans.addEventListener('click', () => toggleKbPreview('transcript'));
        if (modeEls.recKbRefSummary) modeEls.recKbRefSummary.addEventListener('click', () => toggleKbPreview('summary'));

        // ---- Copy buttons (event-delegated so future panels Just Work) ----
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.firefly-capture-copy-btn');
            if (!btn) return;
            e.preventDefault();
            const targetId = btn.getAttribute('data-copy-target');
            if (!targetId) return;
            const target = document.getElementById(targetId);
            if (!target) return;
            const text = target.textContent || '';
            copyToClipboard(text, btn);
        });

        // ---- Document upload (dropzone + file picker) ----
        if (modeEls.docFile) {
            modeEls.docFile.addEventListener('change', (e) => {
                const f = e.target.files && e.target.files[0];
                if (f) uploadDocument(f);
            });
        }
        if (modeEls.docDropzone) {
            ['dragenter', 'dragover'].forEach((ev) => {
                modeEls.docDropzone.addEventListener(ev, (e) => {
                    e.preventDefault();
                    modeEls.docDropzone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach((ev) => {
                modeEls.docDropzone.addEventListener(ev, (e) => {
                    e.preventDefault();
                    modeEls.docDropzone.classList.remove('is-dragover');
                });
            });
            modeEls.docDropzone.addEventListener('drop', (e) => {
                const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                if (f) uploadDocument(f);
            });
        }
        // ---- Toolbar Delete button (only visible when a doc is loaded) ----
        if (modeEls.docDelete) {
            modeEls.docDelete.addEventListener('click', () => {
                if (state.doc.currentPostId) deleteModeItem(state.doc.currentPostId);
            });
        }
    }

    // ==========================================================================
    // Recording mode: overlay-driven UX cloned from Ragsmith's audio-recorder.
    //
    // Flow:
    //   1. startRecording()      → #ffrec-overlay         (REC + timer + meter + Mute/Pause/Stop)
    //   2. stopRecording()       → #ffrec-complete-overlay (Preview / Discard / Process)
    //   3. openPreprocessDialog()→ #ffrec-preprocess-dialog (chips: Transcript/Summary/Diarization/KB)
    //   4. startProcessing()     → #ffrec-processing-overlay (SSE progress)
    //   5. on SSE 'complete'     → #ffrec-kb-dialog          (stats + speaker rename)
    //
    // The recording is uploaded to /audio/upload immediately on Stop so it
    // shows in Ragsmith's recordings manager regardless of whether the user
    // hits Process or Discard. Discard removes the WP-side post but leaves
    // the Ragsmith-side record in place (matches the Ragsmith desktop UX).
    // ==========================================================================

    function openOverlay(el)  { if (el) el.classList.add('is-active'); }
    function closeOverlay(el) { if (el) el.classList.remove('is-active'); }

    async function startRecording() {
        if (!state.activeSessionId) { alert('Pick or create a session first.'); return; }
        if (state.rec.recorder) return; // already recording

        const useShareAudio = !!(modeEls.recShareAudio && modeEls.recShareAudio.checked);
        const useRoomAudio  = !!(modeEls.recRoomAudio  && modeEls.recRoomAudio.checked);
        const startMuted    = !!(modeEls.recStartMuted && modeEls.recStartMuted.checked);

        try {
            // When Room Audio is on we ask Windows/Chromium NOT to apply any
            // capture-side DSP. The standardized constraints
            // (echoCancellation/noiseSuppression/autoGainControl: false) are
            // the main lever, but Chromium also honors several legacy
            // `goog*` aliases — some Chromium builds still re-enable the
            // matching processors if these aren't explicitly disabled. We
            // belt-and-suspenders the whole list to minimize the chance
            // Windows flips the output device into "Communications" mode,
            // which is what produces the static the user hears on other
            // tabs while recording.
            // Belt-and-suspenders capture constraints. Two goals:
            //   1. Disable every capture-side DSP (standardized + legacy
            //      googXxx aliases some Chromium builds still re-enable).
            //   2. Encourage Android's audio HAL to open AudioRecord in a
            //      music/media config (48k stereo, ~50ms buffer) instead of
            //      the voice-communication config (often 8/16k mono, low-
            //      latency, with HAL-side DSP). The voice-comm config is
            //      what flips the *output* device into comms mode on
            //      Android — that's the static the user hears on other
            //      tabs while recording. All "ideal" hints because device
            //      mics may not actually support 48k stereo; we want the
            //      best available, not a hard reject.
            const lenientMicAudio = {
                echoCancellation:         false,
                noiseSuppression:         false,
                autoGainControl:          false,
                voiceIsolation:           false, // newer spec — Chromium >=120
                googEchoCancellation:     false,
                googAutoGainControl:      false,
                googNoiseSuppression:     false,
                googHighpassFilter:       false,
                googTypingNoiseDetection: false,
                sampleRate:               { ideal: 48000 },
                channelCount:             { ideal: 2 },
                latency:                  { ideal: 0.05 },
            };
            const micConstraints = {
                audio: useRoomAudio ? lenientMicAudio : true,
                video: false,
            };
            state.rec.stream = await navigator.mediaDevices.getUserMedia(micConstraints);

            if (useShareAudio && navigator.mediaDevices.getDisplayMedia) {
                try {
                    state.rec.displayStream = await navigator.mediaDevices.getDisplayMedia({
                        audio: true, video: true, systemAudio: 'include',
                    });
                    state.rec.displayStream.getVideoTracks().forEach((t) => t.stop());
                } catch (e) { state.rec.displayStream = null; }
            }

            // Build a Web Audio graph that mixes mic + (optional) tab audio
            // into ONE stream. MediaRecorder cannot record a multi-track
            // MediaStream — it'd silently drop the extra track and produce
            // a mic-only (or empty) blob. The fix is to route everything
            // through a single MediaStreamDestination.
            state.rec.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const micSource = state.rec.audioCtx.createMediaStreamSource(state.rec.stream);

            // Gain node on the mic path so Mute = silence the MIC ONLY.
            // Toggling track.enabled would also kill the display audio
            // share when we mix it in below — gain is the right knob.
            state.rec.micGain = state.rec.audioCtx.createGain();
            state.rec.micGain.gain.value = startMuted ? 0 : 1;
            micSource.connect(state.rec.micGain);

            state.rec.mixDestination = state.rec.audioCtx.createMediaStreamDestination();
            state.rec.micGain.connect(state.rec.mixDestination);

            if (state.rec.displayStream && state.rec.displayStream.getAudioTracks().length > 0) {
                const sysSource = state.rec.audioCtx.createMediaStreamSource(state.rec.displayStream);
                sysSource.connect(state.rec.mixDestination);
                // If the user stops the screen share mid-recording, just
                // downgrade silently to mic-only (matches Ragsmith UX).
                state.rec.displayStream.getAudioTracks()[0].onended = () => {
                    state.rec.displayStream = null;
                };
            }

            state.rec.combinedStream = state.rec.mixDestination.stream;

            // Analyser hangs off the mixed destination so the level meter
            // reflects everything the recorder is capturing (mic + system).
            state.rec.analyser = state.rec.audioCtx.createAnalyser();
            state.rec.analyser.fftSize = 128;
            const meterSource = state.rec.audioCtx.createMediaStreamSource(state.rec.combinedStream);
            meterSource.connect(state.rec.analyser);

            state.rec.muted = startMuted;
            if (startMuted && modeEls.recMuteLabel) modeEls.recMuteLabel.textContent = 'Unmute';

            const recorder = new MediaRecorder(state.rec.combinedStream, { mimeType: 'audio/webm;codecs=opus' });
            state.rec.chunks = [];

            // Unique id for this recording's IDB chunk rows. Survives across
            // a tab close / refresh / browser crash so the recovery banner
            // can pick it back up via recFindIncomplete() on next load.
            state.rec.recordingId = 'rec_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
            state.rec.chunkIndex = 0;

            recorder.ondataavailable = (e) => {
                if (!e.data || e.data.size === 0) return;
                state.rec.chunks.push(e.data);
                // Mirror to IDB so an upload failure or crash is recoverable.
                // Effective duration = wall-clock minus paused intervals,
                // matching the value we'd show in the recovery banner.
                const elapsedMs = Date.now() - state.rec.startedAt - state.rec.pausedAccum;
                const duration  = Math.max(0, Math.round(elapsedMs / 1000));
                recSaveChunk(state.rec.recordingId, state.rec.chunkIndex++, e.data, duration, 'in_progress')
                    .catch((err) => console.warn('[FireflyCapture] IDB chunk save failed:', err));
            };
            recorder.onstop = onRecordingStopped;
            // 30s timeslice matches ragsmith. The level meter is driven by
            // a separate AnalyserNode loop so this slower cadence doesn't
            // affect the visualized waveform.
            recorder.start(REC_CHUNK_INTERVAL_MS);

            state.rec.recorder = recorder;
            state.rec.startedAt = Date.now();
            state.rec.pausedAccum = 0;
            state.rec.paused = false;
            startRecTimer();
            startRecMeter();

            openOverlay(modeEls.recOverlay);
        } catch (e) {
            console.error('[FireflyCapture] startRecording failed:', e);
            alert('Could not start recording: ' + (e && e.message ? e.message : e));
        }
    }

    function toggleRecMute() {
        if (!state.rec.micGain) return;
        state.rec.muted = !state.rec.muted;
        // Drive the mic gain node so mute silences ONLY the mic, leaving
        // any tab/window audio share untouched in the mix.
        state.rec.micGain.gain.value = state.rec.muted ? 0 : 1;
        if (modeEls.recMuteLabel) modeEls.recMuteLabel.textContent = state.rec.muted ? 'Unmute' : 'Mute';
        if (modeEls.recMuteBtn)   modeEls.recMuteBtn.classList.toggle('muted', state.rec.muted);
    }

    function toggleRecPause() {
        if (!state.rec.recorder) return;
        if (state.rec.paused) {
            // Resume
            try { state.rec.recorder.resume(); } catch (e) {}
            state.rec.pausedAccum += Date.now() - state.rec.pausedAt;
            state.rec.paused = false;
            if (modeEls.recPauseLabel) modeEls.recPauseLabel.textContent = 'Pause';
            if (modeEls.recDot) modeEls.recDot.classList.remove('paused');
            // Resume timer
            startRecTimer();
            // Resume meter (was paused on raf cancel)
            if (state.rec.combinedStream) startRecMeter();
        } else {
            // Pause
            try { state.rec.recorder.pause(); } catch (e) {}
            state.rec.pausedAt = Date.now();
            state.rec.paused = true;
            if (modeEls.recPauseLabel) modeEls.recPauseLabel.textContent = 'Resume';
            if (modeEls.recDot) modeEls.recDot.classList.add('paused');
            stopRecTimer();
            stopRecMeter();
        }
    }

    function stopRecording() {
        if (!state.rec.recorder) return;
        try { state.rec.recorder.stop(); } catch (e) {}
        stopRecMeter();
        stopRecTimer();
        if (state.rec.stream)        state.rec.stream.getTracks().forEach((t) => t.stop());
        if (state.rec.displayStream) state.rec.displayStream.getTracks().forEach((t) => t.stop());
        if (state.rec.audioCtx) {
            try { state.rec.audioCtx.close(); } catch (e) {}
        }
        state.rec.stream = null;
        state.rec.displayStream = null;
        state.rec.combinedStream = null;
        state.rec.audioCtx = null;
        state.rec.micGain = null;
        state.rec.mixDestination = null;
        state.rec.analyser = null;
        closeOverlay(modeEls.recOverlay);
    }

    /**
     * onstop handler — fires once MediaRecorder flushes remaining chunks.
     * Builds the blob, uploads it to Ragsmith immediately so it appears in
     * the recordings manager regardless of whether the user processes or
     * discards, then surfaces the complete overlay.
     */
    async function onRecordingStopped() {
        const blob = new Blob(state.rec.chunks, { type: 'audio/webm' });
        state.rec.chunks = [];
        // Effective duration excludes paused intervals.
        const elapsed = Date.now() - state.rec.startedAt - state.rec.pausedAccum;
        state.rec.durationSec = Math.max(0, Math.round(elapsed / 1000));
        state.rec.recordedBlob = blob;
        if (state.rec.recordedUrl) URL.revokeObjectURL(state.rec.recordedUrl);
        state.rec.recordedUrl = URL.createObjectURL(blob);
        state.rec.recorder = null;

        // Show "Recording Complete" overlay first — upload runs in parallel
        // so the user can review/preview before Process.
        if (modeEls.recCompleteDuration) modeEls.recCompleteDuration.textContent = formatHMS(state.rec.durationSec);
        if (modeEls.recPreviewAudio) {
            modeEls.recPreviewAudio.src = state.rec.recordedUrl;
            modeEls.recPreviewAudio.style.display = 'none';
        }
        openOverlay(modeEls.recCompleteOverlay);

        // Background upload to /audio/upload so the file lands in Ragsmith
        // immediately. Stores the returned audio path for the Process step.
        try {
            const titleInput = modeEls.recTitle ? modeEls.recTitle.value.trim() : '';
            const fd = new FormData();
            fd.append('file', blob, (titleInput || 'capture') + '.webm');
            if (titleInput) fd.append('title', titleInput);

            const upRes = await fetch(ragsmithRest() + '/audio/upload', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-WP-Nonce': NONCE, Accept: 'application/json' },
                body: fd,
            });
            if (!upRes.ok) throw new Error('HTTP ' + upRes.status);
            const upJson = await upRes.json();
            state.rec.ragsmithAudioPath = upJson && (upJson.file_path || upJson.path);

            // Upload landed on the server — the IDB chunks are no longer
            // needed for recovery. Anything that fails after this point
            // (WP record creation, processing) is recoverable via the
            // ragsmithAudioPath alone.
            if (state.rec.recordingId) {
                recClearChunks(state.rec.recordingId)
                    .catch((err) => console.warn('[FireflyCapture] IDB cleanup failed:', err));
            }

            // Persist a WP-side record so the sidebar reflects it.
            const created = await api('/recordings', {
                method: 'POST',
                body: {
                    session_id: state.activeSessionId,
                    title:      titleInput || ('Recording — ' + new Date().toLocaleString()),
                    audio_url:  state.rec.ragsmithAudioPath || '',
                    duration:   state.rec.durationSec,
                    share_audio: !!(modeEls.recShareAudio && modeEls.recShareAudio.checked),
                    room_audio:  !!(modeEls.recRoomAudio  && modeEls.recRoomAudio.checked),
                    status:     'uploaded',
                },
            });
            state.rec.currentPostId = created && created.id;
            await loadModeList();
        } catch (e) {
            console.error('[FireflyCapture] upload failed:', e);
            alert('Upload failed: ' + (e && e.message ? e.message : e));
        }
    }

    function previewRecording() {
        if (!modeEls.recPreviewAudio) return;
        const audio = modeEls.recPreviewAudio;
        audio.style.display = 'block';
        try { audio.play(); } catch (e) {}
    }

    async function discardRecording() {
        if (state.rec.recordedUrl) URL.revokeObjectURL(state.rec.recordedUrl);
        state.rec.recordedUrl = null;
        state.rec.recordedBlob = null;
        // Clear any IDB-persisted chunks for this recording so the recovery
        // banner doesn't resurrect a recording the user explicitly threw away.
        if (state.rec.recordingId) {
            try { await recClearChunks(state.rec.recordingId); } catch (e) { /* non-fatal */ }
            state.rec.recordingId = null;
        }
        if (state.rec.currentPostId) {
            try { await api('/recordings/' + state.rec.currentPostId, { method: 'DELETE' }); } catch (e) {}
            state.rec.currentPostId = null;
            await loadModeList();
        }
        closeOverlay(modeEls.recCompleteOverlay);
    }

    function openPreprocessDialog() {
        // Pass the base title input through verbatim — preserve spaces and
        // capitalization. The user wants what they typed at the top to be
        // what shows in the dialog (and what Ragsmith stores as the KB).
        // Fallback only when the title is empty.
        const titleInput = modeEls.recTitle ? modeEls.recTitle.value.trim() : '';
        if (modeEls.recPreprocessKb) {
            modeEls.recPreprocessKb.value = titleInput || ('Recording ' + new Date().toLocaleString());
        }
        closeOverlay(modeEls.recCompleteOverlay);
        openOverlay(modeEls.recPreprocessDialog);
    }

    /**
     * Kick the SSE pipeline with the user's chip-selected flags. Parses
     * Ragsmith's `event: <type>` + `data: <json>` SSE frames properly so
     * progress, transcript, speakers and the final complete payload all
     * land where they should.
     */
    async function startProcessing() {
        const kbName = (modeEls.recPreprocessKb && modeEls.recPreprocessKb.value.trim()) || ('recording-' + Math.floor(Date.now() / 1000));
        const enableTranscript  = !!(modeEls.recChipTranscript  && modeEls.recChipTranscript.classList.contains('is-active'));
        const enableSummary     = !!(modeEls.recChipSummary     && modeEls.recChipSummary.classList.contains('is-active'));
        const enableDiarization = !!(modeEls.recChipDiarization && modeEls.recChipDiarization.classList.contains('is-active'));
        // KB chip removed — Capture-side recordings don't need their own KB
        // (the bound Ragsmith conversation already gets the transcript).
        const enableKb          = false;

        if (!state.rec.ragsmithAudioPath) { alert('Upload not finished yet — try again in a moment.'); return; }

        closeOverlay(modeEls.recPreprocessDialog);
        openOverlay(modeEls.recProcessingOverlay);
        if (modeEls.recProgressBar)  modeEls.recProgressBar.style.width = '0%';
        if (modeEls.recProgressText) { modeEls.recProgressText.textContent = 'Uploading…'; modeEls.recProgressText.classList.remove('error'); }
        if (modeEls.recStopProcessing) modeEls.recStopProcessing.style.display = 'block';
        if (modeEls.recErrorActions)   modeEls.recErrorActions.style.display = 'none';

        state.rec.sseAbort = new AbortController();
        state.rec.finalTranscript = '';
        state.rec.finalSummary    = '';
        state.rec.speakers        = {};
        state.rec.result          = null;

        try {
            const resp = await fetch(ragsmithRest() + '/audio/process', {
                method: 'POST', credentials: 'same-origin',
                signal: state.rec.sseAbort.signal,
                headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json', Accept: 'text/event-stream' },
                body: JSON.stringify({
                    audio_path:         state.rec.ragsmithAudioPath,
                    kb_name:            kbName,
                    enable_transcript:  enableTranscript,
                    enable_summary:     enableSummary,
                    enable_diarization: enableDiarization,
                    enable_kb:          enableKb,
                    // Always pre-export MP3 so the WP media library save
                    // hits a pre-converted file (no ffmpeg on the fly).
                    export_mp3:         true,
                }),
            });
            if (!resp.ok || !resp.body) throw new Error('HTTP ' + resp.status);

            const reader  = resp.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let currentEvent = null;

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop();
                for (const raw of lines) {
                    const line = raw.replace(/\r$/, '');
                    if (line.startsWith('event:')) {
                        currentEvent = line.slice(6).trim();
                    } else if (line.startsWith('data:') && currentEvent) {
                        try {
                            const data = JSON.parse(line.slice(5).trim());
                            handleSSEEvent(currentEvent, data);
                        } catch (e) { /* tolerate keepalives */ }
                        currentEvent = null;
                    } else if (line.startsWith(':')) {
                        // heartbeat
                    } else if (line === '') {
                        currentEvent = null;
                    }
                }
            }

            // ---------- Post-SSE finalization ----------
            // SSE complete delivers paths, not text. The transcript event
            // only sends a 500-char preview. Fetch both full files via the
            // existing transcript / summary proxies so the WP record holds
            // the full content and the user can see the whole conversation.
            const sourceFilename = state.rec.result && state.rec.result.source_filename;
            if (enableTranscript && sourceFilename) {
                try {
                    if (modeEls.recProgressText) modeEls.recProgressText.textContent = 'Fetching transcript…';
                    const txRes = await fetch(ragsmithRest() + '/audio/files/' + encodeURIComponent(sourceFilename) + '/transcript', {
                        credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE, Accept: 'application/json' },
                    });
                    if (txRes.ok) {
                        const txJson = await txRes.json();
                        // Ragsmith returns { filename, transcript } for this route.
                        const fullText = txJson && (txJson.transcript || txJson.text);
                        if (fullText) state.rec.finalTranscript = fullText;
                    }
                } catch (e) { console.warn('[FireflyCapture] transcript fetch failed:', e); }
            }
            if (enableSummary && !state.rec.finalSummary && sourceFilename) {
                try {
                    if (modeEls.recProgressText) modeEls.recProgressText.textContent = 'Fetching summary…';
                    const sumRes = await fetch(ragsmithRest() + '/audio/files/' + encodeURIComponent(sourceFilename) + '/summary', {
                        credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE, Accept: 'application/json' },
                    });
                    if (sumRes.ok) {
                        const sumJson = await sumRes.json();
                        if (sumJson && sumJson.summary) state.rec.finalSummary = sumJson.summary;
                    }
                } catch (e) { console.warn('[FireflyCapture] summary fetch failed:', e); }
            }

            // Save the pre-exported MP3 into the WP media library so we get
            // a long-lived, browser-playable URL that survives Ragsmith
            // cleanup. The base recording-pane <audio> element points at
            // the resulting WP URL.
            //
            // Ragsmith's WEBM→MP3 conversion is async; this endpoint can
            // return 502 (or 504/408/425) for several seconds after the
            // transcript/summary calls already returned. Retry with mild
            // exponential backoff so the MP3 actually lands in WP rather
            // than silently leaving the player disabled.
            let mp3Attach = null;
            let mp3Url    = null;
            if (sourceFilename) {
                const TRANSIENT_STATUSES = new Set([408, 425, 502, 504]);
                const DELAYS_MS = [2000, 3000, 5000, 8000]; // 5 attempts total (initial + 4 retries)
                const MAX_ATTEMPTS = DELAYS_MS.length + 1;

                for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
                    if (modeEls.recProgressText) {
                        modeEls.recProgressText.textContent = attempt === 1
                            ? 'Saving MP3…'
                            : 'Saving MP3… (retry ' + attempt + '/' + MAX_ATTEMPTS + ')';
                    }
                    let mp3Res = null;
                    let networkError = null;
                    try {
                        mp3Res = await fetch(ragsmithRest() + '/audio/save-to-media', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json', Accept: 'application/json' },
                            body: JSON.stringify({ filename: sourceFilename, format: 'mp3' }),
                        });
                    } catch (e) {
                        networkError = e;
                    }

                    if (mp3Res && mp3Res.ok) {
                        try {
                            const mp3Json = await mp3Res.json();
                            mp3Attach = mp3Json && mp3Json.attachment_id;
                            mp3Url    = mp3Json && mp3Json.url;
                        } catch (e) { console.warn('[FireflyCapture] MP3 save: ok response but JSON parse failed:', e); }
                        break; // success
                    }

                    // Decide whether to retry. Network error → retry.
                    // HTTP error → retry only on the known-transient statuses.
                    const status      = mp3Res ? mp3Res.status : 0;
                    const isTransient = networkError != null || TRANSIENT_STATUSES.has(status);
                    if (!isTransient || attempt === MAX_ATTEMPTS) {
                        console.warn('[FireflyCapture] MP3 save failed (attempt ' + attempt + '/' + MAX_ATTEMPTS + '):',
                                     networkError || ('HTTP ' + status));
                        break; // give up; downstream patch will omit mp3 fields
                    }
                    // Wait, then retry. DELAYS_MS[attempt-1] = delay AFTER this attempt.
                    await new Promise((r) => setTimeout(r, DELAYS_MS[attempt - 1]));
                }
            }

            // Persist transcript + summary + mp3 refs on the WP record.
            if (state.rec.currentPostId) {
                const patch = {
                    transcript: state.rec.finalTranscript,
                    summary:    state.rec.finalSummary,
                    kb_path:    (state.rec.result && state.rec.result.kb_name) || kbName,
                    status:     'ready',
                };
                if (mp3Attach) patch.mp3_attachment_id = mp3Attach;
                if (mp3Url)    patch.mp3_url           = mp3Url;
                await api('/recordings/' + state.rec.currentPostId, { method: 'POST', body: patch });
                await loadModeList();
            }
            closeOverlay(modeEls.recProcessingOverlay);
            openCompletionDialog(state.rec.result || {});
        } catch (e) {
            const aborted = e.name === 'AbortError';
            console[aborted ? 'log' : 'error']('[FireflyCapture] processing ' + (aborted ? 'stopped' : 'failed') + ':', e);
            if (modeEls.recProgressText) {
                modeEls.recProgressText.textContent = aborted ? 'Stopped — recording saved' : ('Failed: ' + (e.message || e));
                if (!aborted) modeEls.recProgressText.classList.add('error');
            }
            if (modeEls.recStopProcessing) modeEls.recStopProcessing.style.display = 'none';
            if (modeEls.recErrorActions)   modeEls.recErrorActions.style.display   = 'flex';
            if (state.rec.currentPostId && !aborted) {
                api('/recordings/' + state.rec.currentPostId, { method: 'POST', body: { status: 'failed' } }).catch(() => {});
            }
        } finally {
            state.rec.sseAbort = null;
        }
    }

    function stopProcessing() {
        if (state.rec.sseAbort) state.rec.sseAbort.abort();
    }

    /**
     * Single-source SSE dispatcher. Mirrors Ragsmith's event taxonomy:
     * progress, transcript, speakers, complete, error, done.
     */
    function handleSSEEvent(type, data) {
        switch (type) {
            case 'progress':
                if (modeEls.recProgressBar && typeof data.percent === 'number') {
                    modeEls.recProgressBar.style.width = data.percent + '%';
                }
                if (modeEls.recProgressText) {
                    modeEls.recProgressText.textContent = SSE_STAGE_LABELS[data.stage] || (data.stage || 'Working…');
                }
                break;
            case 'transcript':
                if (data.preview) state.rec.finalTranscript = data.preview;
                if (data.text)    state.rec.finalTranscript = data.text;
                break;
            case 'summary':
                if (data.text) state.rec.finalSummary = data.text;
                break;
            case 'speakers':
                if (data.speakers) state.rec.speakers = data.speakers;
                break;
            case 'complete':
                if (modeEls.recProgressBar)  modeEls.recProgressBar.style.width = '100%';
                if (modeEls.recProgressText) modeEls.recProgressText.textContent = 'Complete!';
                state.rec.result = data || {};
                // Pull transcript/summary out of the final payload if SSE
                // didn't surface them in their own events.
                if (!state.rec.finalTranscript && data.transcript)      state.rec.finalTranscript = data.transcript;
                if (!state.rec.finalTranscript && data.transcript_path) state.rec.finalTranscript = '(transcript saved to ' + data.transcript_path + ')';
                if (!state.rec.finalSummary    && data.summary)         state.rec.finalSummary    = data.summary;
                break;
            case 'error':
                throw new Error(data && data.message ? data.message : 'Processing error');
            case 'done':
                break;
        }
    }

    function openCompletionDialog(result) {
        if (!modeEls.recKbDialog) return;
        const speakers = state.rec.speakers || {};
        const speakerKeys = Object.keys(speakers);
        const parts = [];
        if (result.total_chunks) parts.push(result.total_chunks + ' chunk' + (result.total_chunks !== 1 ? 's' : ''));
        if (result.speakers)     parts.push(result.speakers    + ' speaker' + (result.speakers !== 1 ? 's' : ''));
        if (result.duration)     parts.push(result.duration);
        if (modeEls.recKbStats) modeEls.recKbStats.textContent = parts.join(', ') || 'Done.';

        if (modeEls.recSpeakersSection) modeEls.recSpeakersSection.style.display = speakerKeys.length ? 'block' : 'none';
        if (speakerKeys.length && modeEls.recSpeakersList) {
            modeEls.recSpeakersList.innerHTML = speakerKeys.map((k) =>
                '<div class="ffrec-speaker-row">'
                +   '<span class="ffrec-speaker-label">' + k + '</span>'
                +   '<input type="text" class="ffrec-speaker-input" data-speaker="' + k + '" value="' + (speakers[k] || '') + '" placeholder="Enter name…">'
                + '</div>'
            ).join('');
        }

        // Seed the inline preview panels with the just-fetched content;
        // both start collapsed. Toggle buttons only show when there's
        // something to show — hide the row entirely if both empty.
        const hasT = !!state.rec.finalTranscript;
        const hasS = !!state.rec.finalSummary;
        if (modeEls.recKbPrevTrans)   modeEls.recKbPrevTrans.textContent   = state.rec.finalTranscript || '';
        if (modeEls.recKbPrevSummary) modeEls.recKbPrevSummary.textContent = state.rec.finalSummary    || '';
        if (modeEls.recKbPrevTrans)   modeEls.recKbPrevTrans.hidden   = true;
        if (modeEls.recKbPrevSummary) modeEls.recKbPrevSummary.hidden = true;
        if (modeEls.recKbRefTrans)    modeEls.recKbRefTrans.style.display    = hasT ? '' : 'none';
        if (modeEls.recKbRefSummary)  modeEls.recKbRefSummary.style.display  = hasS ? '' : 'none';
        if (modeEls.recKbRefs)        modeEls.recKbRefs.style.display        = (hasT || hasS) ? '' : 'none';
        if (modeEls.recKbRefTrans)    modeEls.recKbRefTrans.setAttribute('aria-pressed', 'false');
        if (modeEls.recKbRefSummary)  modeEls.recKbRefSummary.setAttribute('aria-pressed', 'false');

        openOverlay(modeEls.recKbDialog);
    }

    /**
     * Toggle one of the inline transcript/summary previews inside the
     * speaker dialog. Mirrors Ragsmith's View Transcript / View Summary
     * affordance — single click reveals, click again to hide.
     */
    function toggleKbPreview(which) {
        const btn = which === 'transcript' ? modeEls.recKbRefTrans  : modeEls.recKbRefSummary;
        const pane = which === 'transcript' ? modeEls.recKbPrevTrans : modeEls.recKbPrevSummary;
        if (!btn || !pane) return;
        const open = pane.hidden;
        pane.hidden = !open;
        btn.setAttribute('aria-pressed', open ? 'true' : 'false');
    }

    /**
     * Clipboard helper used by the copy buttons on the detail panel.
     * Falls back to a hidden textarea + execCommand when navigator.clipboard
     * is unavailable (older browsers, http contexts).
     */
    async function copyToClipboard(text, btn) {
        if (!text) return;
        const icon = btn && btn.querySelector('.dashicons');
        let ok = false;
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                ok = true;
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                ok = document.execCommand('copy');
                document.body.removeChild(ta);
            }
        } catch (e) { console.warn('[FireflyCapture] copy failed:', e); }
        // Swap the clipboard icon to a checkmark briefly as the only feedback.
        if (icon && ok) {
            icon.classList.remove('dashicons-clipboard');
            icon.classList.add('dashicons-yes');
            btn.classList.add('is-copied');
            setTimeout(() => {
                icon.classList.remove('dashicons-yes');
                icon.classList.add('dashicons-clipboard');
                btn.classList.remove('is-copied');
            }, 1200);
        }
    }

    async function confirmSpeakerNames() {
        const names = {};
        if (modeEls.recSpeakersList) {
            modeEls.recSpeakersList.querySelectorAll('.ffrec-speaker-input').forEach((inp) => {
                const k = inp.getAttribute('data-speaker');
                if (k) names[k] = inp.value.trim();
            });
            state.rec.speakers = names;
        }

        // Apply the rename map to the transcript + summary text so the WP
        // record (and its .txt attachments) reflect the friendly names
        // instead of SPEAKER_00 / SPEAKER_01. Empty inputs leave the
        // label as-is, matching Ragsmith's behaviour.
        const map = {};
        Object.keys(names).forEach((k) => { if (names[k]) map[k] = names[k]; });
        const renamed = (Object.keys(map).length > 0);
        if (renamed) {
            state.rec.finalTranscript = applySpeakerRename(state.rec.finalTranscript, map);
            state.rec.finalSummary    = applySpeakerRename(state.rec.finalSummary,    map);
        }

        // Persist back to WP. The model handler re-writes the .txt
        // attachments under the same URL when transcript/summary change.
        if (state.rec.currentPostId && renamed) {
            try {
                await busy.wrap('Saving names…', () => api('/recordings/' + state.rec.currentPostId, {
                    method: 'POST',
                    body: { transcript: state.rec.finalTranscript, summary: state.rec.finalSummary },
                }));
                await loadModeList();
            } catch (e) {
                console.error('[FireflyCapture] speaker rename save failed:', e);
            }
        }

        closeOverlay(modeEls.recKbDialog);
        // Open the just-processed recording in the detail panel so the user
        // sees the renamed transcript + summary immediately.
        if (state.rec.currentPostId) {
            try { await openRecording(state.rec.currentPostId); } catch (e) {}
        }
    }

    /**
     * Replace SPEAKER_00 / SPEAKER_01 / ... tokens with the user-supplied
     * names. Uses word boundaries so it doesn't accidentally rewrite a
     * mention of SPEAKER_001 if that appeared elsewhere.
     */
    function applySpeakerRename(text, map) {
        if (!text) return text;
        let out = text;
        Object.keys(map).forEach((label) => {
            const safe = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const re   = new RegExp('\\b' + safe + '\\b', 'g');
            out = out.replace(re, map[label]);
        });
        return out;
    }

    // -- timer + level meter helpers -----------------------------------------
    function formatHMS(sec) {
        const hh = String(Math.floor(sec / 3600)).padStart(2, '0');
        const mm = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
        const ss = String(sec % 60).padStart(2, '0');
        return hh + ':' + mm + ':' + ss;
    }
    function startRecTimer() {
        const tick = () => {
            if (state.rec.paused) return;
            const elapsed = Math.floor((Date.now() - state.rec.startedAt - state.rec.pausedAccum) / 1000);
            if (modeEls.recTimer) modeEls.recTimer.textContent = formatHMS(elapsed);
        };
        tick();
        state.rec.timerId = setInterval(tick, 500);
    }
    function stopRecTimer() {
        if (state.rec.timerId) clearInterval(state.rec.timerId);
        state.rec.timerId = null;
    }

    /**
     * Start the raf loop that drives the equalizer bars. Reads from the
     * shared analyser created in startRecording (which taps the mixed
     * destination, so it reflects mic + tab audio together).
     */
    function startRecMeter() {
        try {
            if (!state.rec.analyser) return;
            const data = new Uint8Array(state.rec.analyser.frequencyBinCount);
            const bars = modeEls.recMeterBars;
            const tick = () => {
                state.rec.analyser.getByteFrequencyData(data);
                bars.forEach((bar, i) => {
                    const slice = data.length / bars.length;
                    let max = 0;
                    for (let j = Math.floor(i * slice); j < Math.floor((i + 1) * slice); j++) {
                        if (data[j] > max) max = data[j];
                    }
                    const h = 4 + Math.round((max / 255) * 56);
                    bar.style.height = h + 'px';
                });
                state.rec.meterRaf = requestAnimationFrame(tick);
            };
            tick();
        } catch (e) {
            console.warn('[FireflyCapture] level meter unavailable:', e);
        }
    }
    function stopRecMeter() {
        if (state.rec.meterRaf) cancelAnimationFrame(state.rec.meterRaf);
        state.rec.meterRaf = 0;
        // Do not close audioCtx here — stopRecording owns its lifetime.
        if (modeEls.recMeterBars) modeEls.recMeterBars.forEach((b) => { b.style.height = '4px'; });
    }

    // ==========================================================================
    // Document mode: multipart POST to firefly-ragsmith /document/extract.
    //
    // Ragsmith side runs mode=extract_facts_only — fact extraction is
    // SYNCHRONOUS and the response carries a `chip` payload the WP-side
    // persists. Ragsmith renders the upload as a single floating "chip"
    // system message in the conversation (no user/assistant bubbles),
    // and deletion via DELETE /sessions/{sid}/fact-ingest/{cid} removes
    // both the chip and exactly the facts this ingest produced.
    //
    // Because extraction is synchronous, the WP-side flow is one phase:
    //   1. openDocProcessing() — blur overlay with one status line
    //      ("Extracting facts…") that the user sees for the duration of
    //      the round trip (extraction can take 10–60s on CPU-only LLM).
    //   2. closeDocProcessing() + showDocumentLoaded(doc) — facts are
    //      already in the chip payload, no polling needed.
    //
    // Side effects per upload:
    //   - Binds the WP session to the Ragsmith conversation if Ragsmith
    //     minted a fresh one (rs_session_id was empty before this upload).
    //     Without binding, the chip lands in a conversation we lose track
    //     of and the user can't find it in local.ragsmith.net.
    //   - The proxy saves a WP media copy of the uploaded file so the
    //     loaded-state "Download" button has a long-lived URL even after
    //     Ragsmith side cleanup.
    // ==========================================================================

    function openDocProcessing() {
        if (!modeEls.docProcessingOverlay) return;
        modeEls.docProcessingOverlay.classList.add('is-active');
        if (modeEls.docPhaseTitle) modeEls.docPhaseTitle.textContent = 'Extracting facts…';
        if (modeEls.docPhaseDesc)  modeEls.docPhaseDesc.textContent  = 'Reading the document and pulling out structured facts. This usually takes 10–30 seconds on a small doc, longer for big PDFs.';
    }
    function closeDocProcessing() {
        if (modeEls.docProcessingOverlay) modeEls.docProcessingOverlay.classList.remove('is-active');
    }

    async function uploadDocument(file) {
        if (!state.activeSessionId) { alert('Pick or create a session first.'); return; }
        if (state.doc.uploadInFlight) return;
        state.doc.uploadInFlight = true;

        openDocProcessing();
        if (modeEls.docStatus) modeEls.docStatus.textContent = 'Extracting facts…';

        try {
            // If the session is already bound to a Ragsmith conversation,
            // pass that id so the chip lands in the same conversation as
            // the session's notes + recordings. Otherwise let Ragsmith
            // mint a fresh one and bind it after the response returns.
            const sess = state.sessions.find((s) => Number(s.id) === Number(state.activeSessionId));
            const rsSessionId = sess && sess.rs_session_id;

            const fd = new FormData();
            fd.append('file', file, file.name);
            if (rsSessionId) fd.append('session_id', rsSessionId);

            const resp = await fetch(ragsmithRest() + '/document/extract', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-WP-Nonce': NONCE, Accept: 'application/json' },
                body: fd,
            });
            if (!resp.ok) throw new Error('Extract HTTP ' + resp.status);
            const r = await resp.json();

            // Bind the WP session to the Ragsmith conversation if this is
            // the first time we're talking to it.
            const returnedSessionId = r.session_id || rsSessionId || '';
            if (sess && returnedSessionId && !sess.rs_session_id) {
                sess.rs_session_id = returnedSessionId;
                try {
                    await api('/sessions/' + sess.id + '/ragsmith', {
                        method: 'POST',
                        body: { rs_session_id: returnedSessionId },
                    });
                } catch (e) {
                    console.error('[FireflyCapture] bind rs_session_id (doc) failed:', e);
                }
            }

            // Pull the chip payload Ragsmith returned. extract_facts_only
            // guarantees `chip` is present + facts are already extracted —
            // no polling needed, we can persist everything immediately.
            const chip       = r.chip || {};
            const factsArr   = Array.isArray(chip.facts) ? chip.facts : [];
            const chipMsgId  = chip.chip_message_id ? String(chip.chip_message_id) : '';
            const factCount  = Number(chip.fact_count || factsArr.length || 0);

            const created = await api('/documents', {
                method: 'POST',
                body: {
                    session_id:         state.activeSessionId,
                    title:              file.name,
                    filename:           file.name,
                    mime:               file.type || '',
                    // ephemeral_kb_path / chunk_count are read_aloud-only;
                    // extract_facts_only doesn't produce them.
                    ephemeral_kb_path:  '',
                    chunk_count:        0,
                    // rs_message_id stores the CHIP message id — required
                    // for the cascade delete (DELETE .../fact-ingest/{cid}).
                    rs_message_id:      chipMsgId,
                    rs_session_id:      returnedSessionId,
                    source_marker:      chip.source_marker || '',
                    status:             'ready',
                    file_attachment_id: r.wp_attachment_id || 0,
                    file_url:           r.wp_attachment_url || '',
                    text:               r.text || '',
                    facts:              JSON.stringify(factsArr),
                    facts_count:        factCount,
                },
            });
            state.doc.currentPostId      = created && created.id;
            state.doc.currentRsSessionId = returnedSessionId;

            closeDocProcessing();
            showDocumentLoaded(created);
            await loadModeList();
        } catch (e) {
            console.error('[FireflyCapture] document upload failed:', e);
            closeDocProcessing();
            if (modeEls.docStatus) modeEls.docStatus.textContent = 'Failed: ' + (e && e.message ? e.message : e);
            alert('Document upload failed: ' + (e && e.message ? e.message : e));
        } finally {
            state.doc.uploadInFlight = false;
            if (modeEls.docFile) modeEls.docFile.value = '';
        }
    }

    /** REST root for firefly-ragsmith (sibling namespace of firefly-capture). */
    function ragsmithRest() {
        return REST.replace(/\/firefly-capture\/v1$/, '/firefly-ragsmith/v1');
    }

    // Wire mode UI after the existing Notes init runs — we share state and
    // DOM with the Notes-only code above and need it to have bound first.
    function initCaptureModes() {
        bindModeRefs();
        if (!modeEls.tabs || !modeEls.tabs.length) return;
        bindModeEvents();
        // Surface the recovery banner if an earlier recording was interrupted.
        maybeShowRecoveryBanner();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCaptureModes);
    } else {
        initCaptureModes();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
