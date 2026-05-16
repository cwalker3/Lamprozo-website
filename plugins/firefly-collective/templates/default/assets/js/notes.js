/**
 * Firefly Notes — admin page controller.
 *
 * Drives the firefly-ragsmith dictation primitive (Whisper transcription, no LLM)
 * and persists the running note via firefly-notes/v1/notes.
 */
(function () {
    'use strict';

    if (!window.FireflyNotes || !FireflyNotes.restUrl) return;

    const REST  = FireflyNotes.restUrl.replace(/\/$/, '');
    const NONCE = FireflyNotes.nonce;

    // Source tag attached to every Ragsmith conversation we create from here,
    // so the main Ragsmith web app can filter wp-notes conversations out of
    // its primary list.
    const SOURCE = 'wp-notes';

    // localStorage keys are namespaced per-origin (per WP host) so multiple
    // Firefly sites on the same browser don't collide.
    const LS_SESSIONS_KEY = 'firefly-notes/sessions/v1';
    const LS_ACTIVE_KEY   = 'firefly-notes/active-session/v1';

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
            root.className = 'firefly-notes-busy-overlay';
            root.hidden = true;
            root.setAttribute('role', 'status');
            root.setAttribute('aria-live', 'polite');
            root.innerHTML = '<div class="firefly-notes-busy-spinner" aria-hidden="true"></div>' +
                             '<div class="firefly-notes-busy-label"></div>';
            document.body.appendChild(root);
            labelEl = root.querySelector('.firefly-notes-busy-label');
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
            root.className = 'firefly-notes-modal-root';
            root.hidden = true;
            root.innerHTML = [
                '<div class="firefly-notes-modal-backdrop" data-fn-modal-dismiss="1"></div>',
                '<div class="firefly-notes-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="firefly-notes-modal-title">',
                    '<h2 class="firefly-notes-modal-title" id="firefly-notes-modal-title"></h2>',
                    '<div class="firefly-notes-modal-body"></div>',
                    '<div class="firefly-notes-modal-actions">',
                        '<button type="button" class="button firefly-notes-modal-cancel" data-fn-modal-cancel></button>',
                        '<button type="button" class="button button-primary firefly-notes-modal-ok" data-fn-modal-ok></button>',
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
            const okBtn = root.querySelector('.firefly-notes-modal-ok');
            if (okBtn) okBtn.click();
        }

        function open(opts) {
            ensureRoot();
            // If a previous modal is open, resolve it as cancelled so the new
            // one takes over — avoids stacking and dangling promises.
            if (activeResolver) resolveActive(null);

            lastFocused = document.activeElement;

            const titleEl  = root.querySelector('.firefly-notes-modal-title');
            const bodyEl   = root.querySelector('.firefly-notes-modal-body');
            const cancelBtn = root.querySelector('.firefly-notes-modal-cancel');
            const okBtn    = root.querySelector('.firefly-notes-modal-ok');
            const dialogEl = root.querySelector('.firefly-notes-modal-dialog');

            titleEl.textContent = opts.title || '';
            bodyEl.innerHTML = '';

            if (opts.message) {
                const p = document.createElement('p');
                p.className = 'firefly-notes-modal-message';
                p.textContent = opts.message;
                bodyEl.appendChild(p);
            }

            let input = null;
            if (opts.type === 'prompt') {
                input = document.createElement('input');
                input.type = 'text';
                input.className = 'firefly-notes-modal-input';
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

        // Dictation sessions (browser-local, mirrors Ragsmith conversations).
        // sessions: [{ id, label, ragsmithSessionId, createdAt }]
        // activeSessionId: id of the currently-active session row in `sessions`
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
    const TZ = (window.FireflyNotes && FireflyNotes.timezone) || undefined;

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
            li.className = 'firefly-notes-empty';
            li.textContent = 'No notes yet.';
            list.appendChild(li);
            els.count.textContent = '';
            return;
        }
        els.count.textContent = state.notes.length;
        for (const n of state.notes) {
            const li = document.createElement('li');
            li.className = 'firefly-notes-item' + (n.id === state.currentId ? ' is-active' : '');
            li.dataset.id = n.id;
            li.innerHTML = `
                <span class="firefly-notes-item-title"></span>
                <span class="firefly-notes-item-date"></span>
            `;
            li.querySelector('.firefly-notes-item-title').textContent = n.title || 'Untitled';
            li.querySelector('.firefly-notes-item-date').textContent = fmtDate(n.modified);
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
        els.edit.querySelector('.firefly-notes-edit-label').textContent = on ? 'Done' : 'Edit';
        if (on) {
            // Focus the transcript so the user can type immediately.
            // (Defer to next tick so the readonly attribute removal takes effect.)
            setTimeout(() => els.transcript.focus(), 0);
        }
    }

    function setSidebarOpen(open) {
        const wrap = document.querySelector('.firefly-notes');
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
        const data = await api('/notes');
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
        document.querySelectorAll('.firefly-notes-item').forEach((el) => {
            el.classList.toggle('is-active', Number(el.dataset.id) === note.id);
        });
        els.transcript.focus();
    }

    async function createNote() {
        await flushPendingSave();
        // Tag the new note with the active session_id so the bulk-delete on
        // session removal can find it. The server stores this in post_meta.
        const sess = getActiveSession();
        const body = sess ? { session_id: sess.id } : {};
        const note = await api('/notes', { method: 'POST', body });
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
            message: 'The note and its dictation messages in Ragsmith will be removed. This cannot be undone.',
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
    // A "session" here is a browser-local handle to a Ragsmith conversation.
    // We store an array in localStorage; each entry binds a friendly label
    // to a Ragsmith session_id (null until the first dictation creates one
    // server-side). The user can switch between sessions, rename, or delete.

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        // RFC4122-ish fallback for older browsers.
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function loadSessionsFromStorage() {
        try {
            const raw = localStorage.getItem(LS_SESSIONS_KEY);
            const list = raw ? JSON.parse(raw) : null;
            state.sessions = Array.isArray(list) ? list : [];
            state.activeSessionId = localStorage.getItem(LS_ACTIVE_KEY) || null;
        } catch (e) {
            state.sessions = [];
            state.activeSessionId = null;
        }
        // Ensure at least one session exists so the user always has somewhere to dictate.
        if (state.sessions.length === 0) {
            const first = makeSession('Default');
            state.sessions = [first];
            state.activeSessionId = first.id;
            persistSessions();
        } else if (!state.sessions.some((s) => s.id === state.activeSessionId)) {
            // Saved active id doesn't exist anymore (e.g. cleared elsewhere) — fall back to first.
            state.activeSessionId = state.sessions[0].id;
            localStorage.setItem(LS_ACTIVE_KEY, state.activeSessionId);
        }
    }

    function persistSessions() {
        try {
            localStorage.setItem(LS_SESSIONS_KEY, JSON.stringify(state.sessions));
            if (state.activeSessionId) {
                localStorage.setItem(LS_ACTIVE_KEY, state.activeSessionId);
            }
        } catch (e) { /* quota or disabled — ignore */ }
    }

    function makeSession(label) {
        return {
            id: uuid(),
            label: label || ('Session ' + (state.sessions.length + 1)),
            ragsmithSessionId: null,
            createdAt: new Date().toISOString(),
        };
    }

    function getActiveSession() {
        return state.sessions.find((s) => s.id === state.activeSessionId) || null;
    }

    function setActiveSession(id) {
        if (!state.sessions.some((s) => s.id === id)) return;
        state.activeSessionId = id;
        persistSessions();
        renderActiveSession();
        renderSessionList();
    }

    function createSession() {
        const sess = makeSession('Session ' + (state.sessions.length + 1));
        state.sessions.unshift(sess);
        state.activeSessionId = sess.id;
        persistSessions();
        renderActiveSession();
        renderSessionList();
    }

    async function renameSession(id) {
        const sess = state.sessions.find((s) => s.id === id);
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
        sess.label = trimmed;
        persistSessions();
        renderActiveSession();
        renderSessionList();
    }

    async function deleteSession(id) {
        const sess = state.sessions.find((s) => s.id === id);
        if (!sess) return;

        // Show how many WP notes will be removed so the user knows the blast
        // radius before confirming. Count is based on the session_id meta
        // we stamp at note creation time.
        const noteCount = await countNotesForSession(id);
        const ok = await modal.confirm({
            title: 'Delete this session?',
            message: noteCount > 0
                ? 'This will delete ' + noteCount + ' note' + (noteCount === 1 ? '' : 's') +
                  ' tagged with this session, plus the Ragsmith conversation. This cannot be undone.'
                : 'The Ragsmith conversation will be removed. This cannot be undone.',
            confirmLabel: 'Delete',
            danger: true,
        });
        if (!ok) return;

        // The full delete is multi-step (Ragsmith conversation + WP notes +
        // local state). Block the UI for the whole sequence so the user
        // doesn't try to switch sessions mid-tear-down.
        await busy.wrap('Deleting session…', async () => {
            // 1. Drop the Ragsmith conversation (cascades to all its messages).
            if (sess.ragsmithSessionId && window.FireflyRagsmith && typeof FireflyRagsmith.deleteSession === 'function') {
                try { await FireflyRagsmith.deleteSession(sess.ragsmithSessionId); }
                catch (e) { /* best-effort: still drop local state */ }
            }
            // 2. Bulk-trash WP notes tagged with this local session_id.
            try { await api('/sessions/' + encodeURIComponent(id) + '/notes', { method: 'DELETE' }); }
            catch (e) { console.error('[FireflyNotes] bulk-trash failed:', e); }
        });

        // 3. Drop the local session entry.
        state.sessions = state.sessions.filter((s) => s.id !== id);
        if (state.activeSessionId === id) {
            state.activeSessionId = state.sessions.length ? state.sessions[0].id : null;
        }
        if (state.sessions.length === 0) {
            const first = makeSession('Default');
            state.sessions = [first];
            state.activeSessionId = first.id;
        }
        persistSessions();
        renderActiveSession();
        renderSessionList();
        // Refresh the notes sidebar — anything tagged to that session is gone.
        await loadList(true).catch(() => {});
    }

    // Asks the server how many notes carry this session's local id as their
    // _firefly_note_session_id meta. Used purely to message the confirm dialog.
    async function countNotesForSession(localSessionId) {
        try {
            const res = await api('/sessions/' + encodeURIComponent(localSessionId) + '/notes/count');
            return (res && typeof res.count === 'number') ? res.count : 0;
        } catch (e) {
            return 0;
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
            li.className = 'firefly-notes-session-item' + (s.id === state.activeSessionId ? ' is-active' : '');
            li.dataset.id = s.id;
            const label = document.createElement('button');
            label.type = 'button';
            label.className = 'firefly-notes-session-pick';
            label.textContent = s.label;
            label.addEventListener('click', () => { setActiveSession(s.id); closeSessionPopover(); });
            const rename = document.createElement('button');
            rename.type = 'button';
            rename.className = 'firefly-notes-session-action';
            rename.title = 'Rename';
            rename.setAttribute('aria-label', 'Rename session');
            rename.innerHTML = '<span class="dashicons dashicons-edit" aria-hidden="true"></span>';
            rename.addEventListener('click', (e) => { e.stopPropagation(); renameSession(s.id); });
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'firefly-notes-session-action firefly-notes-session-delete';
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
                title: 'Ragsmith not available',
                message: 'The Firefly Ragsmith plugin is not loaded.',
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
                // First save: POST /dictation. Ragsmith creates or upserts the
                // conversation, inserts a new user message, and schedules
                // extraction. We record the returned id so subsequent saves
                // take the edit path.
                res = await FireflyRagsmith.saveDictation(content, {
                    session_id: sess.ragsmithSessionId || undefined,
                    source: SOURCE,
                    extract_facts: true,
                });
                if (res && res.session_id && !sess.ragsmithSessionId) {
                    sess.ragsmithSessionId = res.session_id;
                    persistSessions();
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
                        console.error('[FireflyNotes] append-message failed:', e);
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
            console.error('[FireflyNotes] saveNoteToAI failed:', e);
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
            console.error('[FireflyNotes] fetchNoteMessageRef failed:', e);
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
        const labelEl = btn.querySelector('.firefly-notes-ai-save-label');
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
            mountTo: '#firefly-notes-dictation-host',
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
            els.mute.querySelector('.firefly-notes-mute-label').textContent = 'Mute';
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
        els.mute.querySelector('.firefly-notes-mute-label').textContent = state.muted ? 'Unmute' : 'Mute';
        if (state.listening) setStatus(state.muted ? 'Muted' : 'Listening…');
    }

    // ---------- Init ----------
    function bindRefs() {
        els.list           = $('firefly-notes-list');
        els.count          = $('firefly-notes-count');
        els.main           = $('firefly-notes-main');
        els.title          = $('firefly-notes-title');
        els.transcript     = $('firefly-notes-transcript');
        els.edit           = $('firefly-notes-edit');
        els.delete         = $('firefly-notes-delete');
        els.modified       = $('firefly-notes-meta-modified');
        els.saved          = $('firefly-notes-meta-saved');
        els.mic            = $('firefly-notes-mic');
        els.mute           = $('firefly-notes-mute');
        els.status         = $('firefly-notes-status');
        els.newBtn         = $('firefly-notes-new');
        els.toggleList     = $('firefly-notes-toggle-list');
        els.sessionBtn     = $('firefly-notes-session-btn');
        els.sessionLabel   = $('firefly-notes-session-label');
        els.sessionPopover = $('firefly-notes-session-popover');
        els.sessionList    = $('firefly-notes-session-list');
        els.sessionNew     = $('firefly-notes-session-new');
        els.aiSave         = $('firefly-notes-ai-save');
    }

    function notifyError(e) {
        return modal.alert({
            title: 'Something went wrong',
            message: e && e.message ? e.message : String(e),
        });
    }

    function bindEvents() {
        els.newBtn.addEventListener('click', () => createNote().catch(notifyError));
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
        if (els.toggleList) {
            els.toggleList.addEventListener('click', () => {
                const wrap = document.querySelector('.firefly-notes');
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

    function init() {
        bindRefs();
        if (!els.list) return;
        loadSessionsFromStorage();
        renderActiveSession();
        bindEvents();
        loadList(true).catch((e) => {
            els.list.innerHTML = '<li class="firefly-notes-empty">Failed to load: ' + e.message + '</li>';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
