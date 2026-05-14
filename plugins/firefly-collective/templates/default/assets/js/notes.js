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

    // ---------- DOM ----------
    const $ = (id) => document.getElementById(id);
    const els = {};
    let dict = null;

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
        renderCurrent(note);
        // Mark active in sidebar without re-fetching list.
        document.querySelectorAll('.firefly-notes-item').forEach((el) => {
            el.classList.toggle('is-active', Number(el.dataset.id) === note.id);
        });
        els.transcript.focus();
    }

    async function createNote() {
        await flushPendingSave();
        const note = await api('/notes', { method: 'POST', body: {} });
        state.notes.unshift({ id: note.id, title: note.title, modified: note.modified });
        state.currentId = note.id;
        renderList();
        renderCurrent(note);
        // Don't auto-focus/select the title — title is readonly by default
        // and the user reaches for the mic, not the keyboard, on a new note.
    }

    async function deleteNote() {
        if (!state.currentId) return;
        if (!confirm('Delete this note? This cannot be undone from here.')) return;
        const id = state.currentId;
        await api('/notes/' + id, { method: 'DELETE' });
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
        // with a single space joiner if there's already text.
        const ta = els.transcript;
        const before = ta.value;
        const sep = before.length === 0 ? '' :
                    /\n\s*$/.test(before) ? '' :
                    /[ \t]$/.test(before) ? '' : ' ';
        ta.value = before + sep + chunk;
        // Keep cursor / scroll at the end so the user sees new text appear.
        ta.scrollTop = ta.scrollHeight;
        scheduleSave();
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
        els.list       = $('firefly-notes-list');
        els.count      = $('firefly-notes-count');
        els.main       = $('firefly-notes-main');
        els.title      = $('firefly-notes-title');
        els.transcript = $('firefly-notes-transcript');
        els.edit       = $('firefly-notes-edit');
        els.delete     = $('firefly-notes-delete');
        els.modified   = $('firefly-notes-meta-modified');
        els.saved      = $('firefly-notes-meta-saved');
        els.mic        = $('firefly-notes-mic');
        els.mute       = $('firefly-notes-mute');
        els.status     = $('firefly-notes-status');
        els.newBtn     = $('firefly-notes-new');
        els.toggleList = $('firefly-notes-toggle-list');
    }

    function bindEvents() {
        els.newBtn.addEventListener('click', () => createNote().catch((e) => alert(e.message)));
        els.delete.addEventListener('click', () => deleteNote().catch((e) => alert(e.message)));
        els.edit.addEventListener('click', () => setEditMode(!state.editMode));
        els.title.addEventListener('input', scheduleSave);
        els.transcript.addEventListener('input', scheduleSave);
        els.mic.addEventListener('click', micToggle);
        els.mute.addEventListener('click', muteToggle);
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
