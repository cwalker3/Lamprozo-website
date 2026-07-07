/**
 * Firefly Agent — admin page controller.
 *
 * Responsibilities:
 *   - Load + render the session sidebar (reused from Capture's REST surface)
 *   - On session pick: fetch Ragsmith history → render bubbles
 *   - On send: POST /firefly-capture/v1/chat → parse SSE from response body
 *     → live-render assistant bubble (text_chunk → typing, thinking_chunk +
 *     *_status events → collapsible disclosures, text_complete → finalize,
 *     done → release input, error → inline error card, tool_approval →
 *     approval card)
 *   - Cancel button while streaming → POST /chat/cancel
 *   - Mobile sidebar slide-over (matches Capture's UX)
 *
 * No dependency on Capture's JS — Agent runs standalone. We DO use
 * window.FireflyRagsmith for non-streaming calls when available, but
 * the chat path is fetch + manual SSE parsing because EventSource
 * doesn't support POST bodies.
 */
(function () {
    'use strict';

    if (!window.FireflyAgent) {
        console.warn('[FireflyAgent] localized data missing; aborting.');
        return;
    }

    const REST  = window.FireflyAgent.restUrl;  // .../firefly-capture/v1
    const NONCE = window.FireflyAgent.nonce;
    const DEFAULT_AGENT = window.FireflyAgent.defaultAgent || '';
    const DEFAULT_MODEL = window.FireflyAgent.defaultModel || '';
    const marked = window.marked && window.marked.parse ? window.marked : null;

    // Per-WP-user persisted toggles. The Memory chip controls whether
    // the `recall` tool is available to the LLM on this turn — when off,
    // we send disabled_tools:['recall'] which the PHP route UNIONS with
    // the agent's admin-config defaults. Default OFF so a fresh user
    // starts in the conservative state and opts in to memory lookups.
    const LS_MEMORY_KEY = 'firefly-agent/memory-enabled';

    // Flip to false to silence the per-event SSE console.debug calls
    // once we're done diagnosing the synthesis hang.
    const DEBUG_SSE = true;

    // If no SSE event arrives for this long, show "Still working… Xs" in
    // the meta line so the user knows the stream is alive (Ragsmith emits
    // `: keepalive\n\n` comment-pings every 10s during long synth runs;
    // those don't trigger SSE events but they keep the socket open).
    const INACTIVITY_HINT_MS = 5000;
    if (!marked) {
        console.warn('[FireflyAgent] marked.js not loaded; assistant responses will render as plain text.');
    }

    // ----- DOM refs -------------------------------------------------------
    const els = {};
    function $(id) { return document.getElementById(id); }

    // ----- State ----------------------------------------------------------
    const state = {
        sessions: [],            // [{id, label, rs_session_id, modified, ...}]
        activeSessionId: null,   // wp_post_id of the active firefly_note_session
        activeRsSessionId: null, // Ragsmith conversation id (if bound)
        isStreaming: false,      // POST /chat in flight
        streamAbort: null,       // AbortController for the in-flight stream
        currentAssistant: null,  // { msgEl, bodyEl, textBuffer, thinkingBuffer,
                                 //   stepsList, caretEl, thinkingEl, stepsEl,
                                 //   timeEl, modelEl }
        lastUserMessage: '',     // for the inline error retry button
        cursorAtBottom: true,    // true = auto-scroll on new chunks; flips off
                                 // if the user scrolls up to read history
        memoryEnabled: false,    // Memory chip toggle — when false, browser sends
                                 // disabled_tools:['recall'] on chat to exclude
                                 // the recall tool. Loaded from localStorage on
                                 // init; default off.
    };

    // ===================================================================
    //   Init
    // ===================================================================
    document.addEventListener('DOMContentLoaded', init);
    if (document.readyState !== 'loading') init();

    let _initialized = false;
    function init() {
        if (_initialized) return;
        _initialized = true;
        bindRefs();
        if (!els.transcript) return;  // not on the agent page
        bindEvents();
        // Warm-state pill: paint from localStorage immediately so a
        // returning user sees the cached state before the network round
        // trip, start the 60s "Xm ago" tick, then refresh from the
        // server. Subsequent updates happen on warm-up success only.
        warmPillState = loadWarmCache();
        renderWarmPill();
        startWarmTick();
        fetchWarmStatus().catch(() => {});
        loadSessions().catch((e) => console.error('[FireflyAgent] initial loadSessions failed:', e));
    }

    function bindRefs() {
        els.wrap        = $('firefly-agent');
        els.list        = $('firefly-agent-list');
        els.count       = $('firefly-agent-count');
        els.warmBtn     = $('firefly-agent-warm');
        els.warmPill    = $('firefly-agent-warm-pill');
        els.warmPillText = $('firefly-agent-warm-pill-text');
        els.newBtn      = $('firefly-agent-new');
        els.toggleList  = $('firefly-agent-toggle-list');
        els.title       = $('firefly-agent-title');
        els.meta        = $('firefly-agent-meta');
        els.transcript  = $('firefly-agent-transcript');
        els.empty       = $('firefly-agent-empty');
        els.composer    = $('firefly-agent-composer');
        els.input       = $('firefly-agent-input');
        els.sendBtn     = $('firefly-agent-send');
        els.cancelBtn   = $('firefly-agent-cancel');
        // Per-chat toggle chips (Memory, future: web search, planning, etc.)
        els.chipMemory  = $('firefly-agent-chip-memory');
        // Capture header refs (notes / recordings / documents surfaced
        // from the bound Capture session in a collapsible block above
        // the transcript — populated by loadCaptureItems on session pick).
        els.captureWrap   = $('firefly-agent-capture');
        els.captureBody   = $('firefly-agent-capture-body');
        els.captureCount  = $('firefly-agent-capture-count');

        // Templates
        els.tplUser       = $('firefly-agent-tpl-user');
        els.tplAsst       = $('firefly-agent-tpl-assistant');
        els.tplApproval   = $('firefly-agent-tpl-approval');
        els.tplError      = $('firefly-agent-tpl-error');
        els.tplCapNote    = $('firefly-agent-tpl-capture-note');
        els.tplCapRec     = $('firefly-agent-tpl-capture-recording');
        els.tplCapDoc     = $('firefly-agent-tpl-capture-document');

        // Pre-fill the meta badge with the configured defaults so the user
        // sees which agent + model they're talking to BEFORE the first
        // chat completes (history endpoint only returns these once the
        // session is bound to a Ragsmith conversation).
        renderMetaBadge({});
    }

    /**
     * Update the toolbar meta line with agent/model/elapsed metadata.
     * Falls back to defaults from window.FireflyAgent so fresh sessions
     * still show what they're about to talk to.
     */
    function renderMetaBadge(opts) {
        if (!els.meta) return;
        opts = opts || {};
        const bits = [];
        const model = opts.display_model || opts.model || DEFAULT_MODEL;
        const agent = opts.agent || DEFAULT_AGENT;
        if (model) bits.push(model);
        if (agent) bits.push(agent);
        if (opts.suffix) bits.push(opts.suffix);
        els.meta.textContent = bits.join(' · ');
    }

    function bindEvents() {
        if (els.warmBtn) els.warmBtn.addEventListener('click', onWarm);
        if (els.newBtn) els.newBtn.addEventListener('click', onNewChat);
        if (els.toggleList) els.toggleList.addEventListener('click', toggleSidebar);
        if (els.composer) els.composer.addEventListener('submit', onSubmit);
        if (els.input) {
            els.input.addEventListener('input', onInputChange);
            els.input.addEventListener('keydown', onInputKey);
        }
        if (els.cancelBtn) els.cancelBtn.addEventListener('click', onCancel);
        if (els.transcript) els.transcript.addEventListener('scroll', onTranscriptScroll);
        if (els.chipMemory) els.chipMemory.addEventListener('click', onToggleMemory);
        // Hydrate the Memory chip state from localStorage. Falls back to
        // false (the default-off design) when no key exists yet or
        // localStorage is blocked (private mode, etc.).
        try {
            state.memoryEnabled = window.localStorage.getItem(LS_MEMORY_KEY) === 'true';
        } catch (e) {
            state.memoryEnabled = false;
        }
        renderMemoryChip();
    }

    /**
     * Flip the Memory chip and persist the new state. The next chat
     * turn picks up state.memoryEnabled from streamChat()'s body
     * construction — no per-turn UI needed.
     */
    function onToggleMemory() {
        state.memoryEnabled = !state.memoryEnabled;
        try { window.localStorage.setItem(LS_MEMORY_KEY, state.memoryEnabled ? 'true' : 'false'); }
        catch (e) { /* localStorage blocked; toggle still works for this session */ }
        renderMemoryChip();
    }

    /**
     * Paint the Memory chip's aria-pressed + visible label state from
     * state.memoryEnabled. Called on init and after each toggle.
     */
    function renderMemoryChip() {
        if (!els.chipMemory) return;
        els.chipMemory.setAttribute('aria-pressed', state.memoryEnabled ? 'true' : 'false');
    }

    // ===================================================================
    //   Helpers
    // ===================================================================
    async function api(path, options) {
        options = options || {};
        const opts = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: Object.assign({
                'X-WP-Nonce': NONCE,
                'Accept': 'application/json',
            }, options.headers || {}),
        };
        if (options.body !== undefined) {
            opts.body = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);
            opts.headers['Content-Type'] = 'application/json';
        }
        const resp = await fetch(REST + path, opts);
        if (!resp.ok) {
            const text = await resp.text().catch(() => '');
            throw new Error(`HTTP ${resp.status} ${path}: ${text.slice(0, 200)}`);
        }
        return resp.json();
    }

    function fmtDate(iso) {
        if (!iso) return '';
        try {
            const d = new Date(iso);
            const now = new Date();
            const sameDay = d.toDateString() === now.toDateString();
            if (sameDay) return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            const sameYear = d.getFullYear() === now.getFullYear();
            if (sameYear) return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
            return d.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' });
        } catch (e) { return ''; }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    function renderMarkdown(text) {
        if (!text) return '';
        if (!marked) return escapeHtml(text);
        try {
            return marked.parse(text);
        } catch (e) {
            console.warn('[FireflyAgent] markdown parse failed; falling back to text', e);
            return escapeHtml(text);
        }
    }

    /**
     * Detect <think>…</think> blocks the way Ragsmith Web UI does
     * (web/js/ui.js:separateThinkingContent). Reasoning models like
     * Qwen 3.5 9B emit their reasoning wrapped in <think>…</think>
     * either:
     *   (a) inline in the streamed response (when the LLM provider
     *       doesn't extract `response.thinking` as a separate field), or
     *   (b) prepended to the persisted message on save
     *       (core/ragsmith.py:4077 wraps plan + inter-step reasoning).
     *
     * Returns { thinking, response, isComplete }:
     *   - thinking: text between <think>…</think> or null
     *   - response: text after </think> (or the whole text if no <think>)
     *   - isComplete: true once </think> has been seen
     */
    function separateThinkingContent(text) {
        if (!text) return { thinking: null, response: '', isComplete: true };
        const start = text.indexOf('<think>');
        if (start === -1) return { thinking: null, response: text, isComplete: true };
        const end = text.indexOf('</think>');
        if (end === -1) {
            // Mid-stream: <think> opened but not closed yet. Everything
            // after the opener is thinking; response stays empty.
            return { thinking: text.slice(start + 7), response: '', isComplete: false };
        }
        return {
            thinking: text.slice(start + 7, end).trim(),
            response: text.slice(end + 8).trim(),
            isComplete: true,
        };
    }

    /**
     * Progressive markdown render during streaming. Re-parses the full
     * textBuffer on every chunk and slams the result into innerHTML, but
     * coalesces to one render per animation frame so a fast token rate
     * (100+ chunks/sec on local Ollama) doesn't thrash the DOM.
     *
     * Partial markdown is fine: unclosed code fences render as open code
     * blocks until they close, unclosed tables render as pipe-prose until
     * the separator row arrives — same behavior as Ragsmith Web UI.
     */
    const _streamingRender = { pending: new WeakMap(), rafId: 0 };
    function renderMarkdownStreaming(bodyEl, text) {
        if (!bodyEl) return;
        _streamingRender.pending.set(bodyEl, text);
        if (_streamingRender.rafId) return;
        _streamingRender.rafId = requestAnimationFrame(() => {
            _streamingRender.rafId = 0;
            const a = state.currentAssistant;
            if (!a || !a.bodyEl) return;
            const latest = _streamingRender.pending.get(a.bodyEl);
            if (latest == null) return;
            _streamingRender.pending.delete(a.bodyEl);
            // .is-rendered drops `white-space: pre-wrap`. Without it, every
            // \n in the markdown SOURCE renders as a visible line break,
            // doubling vertical space. We're putting parsed HTML into
            // innerHTML so block elements own the structure — flip to
            // `white-space: normal` from the very first streaming paint
            // so the bubble looks IDENTICAL to its final state, no shrink
            // at text_complete.
            a.bodyEl.classList.add('is-rendered');
            a.bodyEl.innerHTML = renderMarkdown(latest);
        });
    }

    function setSidebarOpen(open) {
        if (!els.wrap) return;
        els.wrap.classList.toggle('show-sidebar', !!open);
    }

    function toggleSidebar() {
        if (!els.wrap) return;
        setSidebarOpen(!els.wrap.classList.contains('show-sidebar'));
    }

    function setStreaming(streaming) {
        state.isStreaming = streaming;
        if (els.sendBtn)   els.sendBtn.hidden   = streaming;
        if (els.cancelBtn) els.cancelBtn.hidden = !streaming;
        if (els.input)     els.input.disabled   = streaming;
    }

    // ===================================================================
    //   Session list (reuses Capture's /sessions endpoint)
    // ===================================================================
    async function loadSessions() {
        const data = await api('/sessions');
        // The capture endpoint returns { sessions: [...] }
        state.sessions = (data && data.sessions) || data || [];
        renderSessionList();
        // Auto-pick: URL ?agent_session=N takes priority, else most-recent.
        const urlSess = Number(new URLSearchParams(window.location.search).get('agent_session') || 0);
        const startId = state.sessions.find((s) => Number(s.id) === urlSess)
            ? urlSess
            : (state.sessions.length ? state.sessions[0].id : null);
        if (startId) await openSession(startId);
        else showEmptyState('Start a conversation', 'Click + New chat above to create your first session.');
    }

    // Initial render batch size + the increment when the user scrolls
    // near the bottom of the sidebar. Picked so that even on tall
    // 1440p monitors the first batch overfills the viewport (so the
    // user sees a normal list, not a half-empty one), but keeps the
    // initial DOM tree small enough for snappy mount even with 500+
    // sessions cached. Tuning lever — bump if power users complain.
    const SESSIONS_BATCH = 30;
    // IntersectionObserver instance for the bottom-sentinel. Held on
    // the module scope so successive renders can disconnect the prior
    // one cleanly (the sentinel <li> gets replaced on each batch).
    let sessionsObserver = null;

    function buildSessionLi(s) {
        const isActive = Number(s.id) === Number(state.activeSessionId);
        const title = escapeHtml(s.label || 'Untitled');
        const meta  = escapeHtml(s.modified ? fmtDate(s.modified) : '');
        return ''
            + `<li class="firefly-agent-item${isActive ? ' is-active' : ''}" data-id="${s.id}">`
            +   '<div class="firefly-agent-item-row">'
            +     `<a class="firefly-agent-item-link" href="#" data-id="${s.id}">`
            +       `<span class="firefly-agent-item-title">${title}</span>`
            +       `<span class="firefly-agent-item-meta">${meta}</span>`
            +     '</a>'
            +     `<button type="button" class="firefly-agent-item-delete" data-id="${s.id}" `
            +       `aria-label="Delete this chat" title="Delete this chat">`
            +       '<span class="dashicons dashicons-trash" aria-hidden="true"></span>'
            +     '</button>'
            +   '</div>'
            + '</li>';
    }

    /**
     * Wire row-click and delete-button handlers across a set of <li>s.
     * Idempotent — used by both the initial render and each appended batch.
     */
    function wireSessionRows(rows) {
        rows.forEach((li) => {
            const link = li.querySelector('.firefly-agent-item-link');
            const del  = li.querySelector('.firefly-agent-item-delete');
            if (link) link.addEventListener('click', (e) => {
                e.preventDefault();
                const id = Number(link.getAttribute('data-id'));
                if (id) openSession(id);
            });
            if (del) del.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const id = Number(del.getAttribute('data-id'));
                if (id) confirmAndDeleteSession(id);
            });
        });
    }

    /**
     * Append the next batch of session rows + manage the bottom
     * sentinel. The sentinel <li> auto-removes once everything is
     * rendered, otherwise stays at the end as the IntersectionObserver
     * target — once it scrolls into view the next batch loads, the
     * sentinel is moved back to the new end, and observation resumes.
     */
    function appendNextSessionsBatch() {
        if (!els.list) return;
        const total = state.sessions.length;
        const start = state.sessionsRenderedCount || 0;
        if (start >= total) return;
        const end = Math.min(start + SESSIONS_BATCH, total);

        // Remove the prior sentinel (if any) — we always re-add it at the
        // new end so the observer keeps pointing at the actual bottom.
        const oldSentinel = els.list.querySelector('.firefly-agent-list-sentinel');
        if (oldSentinel) oldSentinel.remove();

        const html = state.sessions.slice(start, end).map(buildSessionLi).join('');
        els.list.insertAdjacentHTML('beforeend', html);

        // Bind handlers for just the newly-inserted rows (avoids
        // re-binding earlier batches and triggering listener leaks).
        const newRows = Array.from(els.list.children).slice(-(end - start));
        wireSessionRows(newRows);

        state.sessionsRenderedCount = end;

        // More to render? Put a sentinel at the new end and observe it.
        if (end < total) {
            const sentinel = document.createElement('li');
            sentinel.className = 'firefly-agent-list-sentinel';
            sentinel.setAttribute('aria-hidden', 'true');
            sentinel.textContent = 'Loading more…';
            els.list.appendChild(sentinel);
            if (sessionsObserver) sessionsObserver.disconnect();
            sessionsObserver = new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        appendNextSessionsBatch();
                        break;
                    }
                }
            }, { root: els.list, rootMargin: '100px' });
            sessionsObserver.observe(sentinel);
        } else if (sessionsObserver) {
            // Fully rendered — release the observer.
            sessionsObserver.disconnect();
            sessionsObserver = null;
        }
    }

    function renderSessionList() {
        if (!els.list) return;
        // Clean slate for any in-flight observer + DOM from a prior render.
        if (sessionsObserver) { sessionsObserver.disconnect(); sessionsObserver = null; }
        state.sessionsRenderedCount = 0;
        if (!state.sessions.length) {
            els.list.innerHTML = '<li class="firefly-capture-empty">No chats yet.</li>';
            if (els.count) els.count.textContent = '';
            return;
        }
        if (els.count) els.count.textContent = String(state.sessions.length);
        els.list.innerHTML = '';
        appendNextSessionsBatch();
    }

    // ===================================================================
    //   Session lifecycle
    // ===================================================================
    async function onNewChat() {
        try {
            // Reuse Capture's session-create. Server auto-labels as
            // "Session N+1" when no label is supplied.
            const created = await api('/sessions', { method: 'POST', body: {} });
            await loadSessions();
            if (created && created.id) await openSession(created.id);
        } catch (e) {
            console.error('[FireflyAgent] new session failed:', e);
            alert('Could not create a new chat: ' + (e && e.message ? e.message : e));
        }
    }

    async function openSession(id) {
        const sess = state.sessions.find((s) => Number(s.id) === Number(id));
        if (!sess) return;
        // If a stream is in flight, cancel it before switching contexts.
        if (state.isStreaming) {
            try { await onCancel(); } catch (e) { /* swallow */ }
        }
        state.activeSessionId = Number(id);
        state.activeRsSessionId = sess.rs_session_id || sess.ragsmith_session_id || null;
        if (els.wrap) {
            const url = new URL(window.location.href);
            url.searchParams.set('agent_session', String(id));
            window.history.replaceState(null, '', url.toString());
        }
        if (els.title) els.title.textContent = sess.label || 'Untitled';
        // Reset to defaults — renderHistory() will overwrite with the
        // conversation's actual agent/model once it loads.
        renderMetaBadge({});
        renderSessionList(); // re-paint active highlight
        setSidebarOpen(false);

        // Clear transcript, render the empty state while we wait.
        clearTranscript();
        // Reset the Capture header for the new session so stale items
        // from the prior session don't briefly show before the new
        // /notes /recordings /documents fetches come back.
        clearCaptureHeader();
        showEmptyState('Loading…', '');

        try {
            const hist = await api('/sessions/' + Number(id) + '/history');
            renderHistory(hist);
        } catch (e) {
            console.error('[FireflyAgent] history load failed:', e);
            showEmptyState('Could not load history', String(e && e.message ? e.message : e));
        }

        // Load + render the Capture artifacts attached to this session
        // (notes / recordings / documents). Best-effort: the chat is fully
        // functional without this, so a failure here is a console.warn
        // not a user-facing error.
        loadCaptureItems(Number(id))
            .then((items) => renderCaptureHeader(items))
            .catch((e) => console.warn('[FireflyAgent] Capture items load failed:', e));

        // Focus the composer so the user can just start typing.
        if (els.input) els.input.focus();
    }

    function renderHistory(hist) {
        clearTranscript();
        const msgs = (hist && hist.messages) || [];
        if (!msgs.length) {
            showEmptyState('Start the conversation', 'Send a message below to begin.');
        } else {
            for (const m of msgs) {
                if (m.role === 'user') {
                    // Dictation messages are surfaced in the Capture
                    // header above the transcript — skip them here so
                    // the same content doesn't render twice. The Ragsmith
                    // /dictation endpoint stamps metadata.is_dictation
                    // when it persists the message; the bridge passes
                    // metadata through intact.
                    if (m.metadata && m.metadata.is_dictation === true) continue;
                    appendUserMessage(m.content || '', m.timestamp);
                } else if (m.role === 'assistant') {
                    appendStaticAssistantMessage(
                        m.content || '',
                        m.timestamp,
                        hist.display_model || hist.model || '',
                        m.id || null,
                        m.step_log || null
                    );
                } else if (m.role === 'system') {
                    // Skip system messages in the visible transcript (fact-ingest chips, etc.)
                    // — Capture handles those, Agent doesn't need to repaint them.
                }
            }
        }
        renderMetaBadge({
            display_model: hist && hist.display_model,
            model:         hist && hist.model,
            agent:         hist && hist.agent,
        });
        scrollToBottom(true);
    }

    function clearTranscript() {
        if (!els.transcript) return;
        els.transcript.innerHTML = '';
        // Re-inject the empty-state placeholder so subsequent showEmptyState
        // calls have something to fill into.
        const empty = document.createElement('div');
        empty.className = 'firefly-agent-empty';
        empty.id = 'firefly-agent-empty';
        empty.hidden = true;
        empty.innerHTML = ''
            + '<div class="firefly-agent-empty-icon" aria-hidden="true">'
            +   '<svg viewBox="0 0 24 24" width="48" height="48" fill="currentColor">'
            +     '<path d="M20 2H4c-1.1 0-2 .9-2 2v14l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>'
            +   '</svg>'
            + '</div>'
            + '<div class="firefly-agent-empty-title"></div>'
            + '<div class="firefly-agent-empty-hint"></div>';
        els.transcript.appendChild(empty);
        els.empty = empty;
    }

    function showEmptyState(title, hint) {
        if (!els.empty) return;
        els.empty.querySelector('.firefly-agent-empty-title').textContent = title || '';
        els.empty.querySelector('.firefly-agent-empty-hint').textContent = hint || '';
        els.empty.hidden = false;
    }
    function hideEmptyState() {
        if (els.empty) els.empty.hidden = true;
    }

    // ===================================================================
    //   Message rendering
    // ===================================================================
    function instantiate(tpl) {
        if (!tpl || !tpl.content) return null;
        const node = tpl.content.firstElementChild.cloneNode(true);
        const fields = {};
        node.querySelectorAll('[data-field]').forEach((el) => {
            fields[el.getAttribute('data-field')] = el;
        });
        return { node, fields };
    }

    function appendUserMessage(text, timestamp) {
        hideEmptyState();
        const inst = instantiate(els.tplUser);
        if (!inst) return null;
        inst.fields.time.textContent = fmtDate(timestamp || new Date().toISOString());
        inst.fields.body.textContent = text;
        els.transcript.appendChild(inst.node);
        // User-send is a discrete event, not a streaming append — pass
        // force=true so the smooth-scroll branch of scrollToBottom kicks
        // in. The user just hit Enter; they should see their bubble
        // glide into view, not snap.
        scrollToBottom(true);
        return inst;
    }

    // Static (already-final) assistant message from history. No streaming
    // state — just markdown-render the content immediately.
    function appendStaticAssistantMessage(text, timestamp, modelLabel, messageId, stepLog) {
        hideEmptyState();
        const inst = instantiate(els.tplAsst);
        if (!inst) return null;
        inst.fields.time.textContent = fmtDate(timestamp || new Date().toISOString());
        inst.fields.model.textContent = modelLabel || '';
        // Ragsmith persists the tool-loop's plan + inter-step reasoning
        // by prepending <think>…</think> to the saved message content
        // (core/ragsmith.py:4077). Split here so the body renders the
        // response only and the reasoning lives in the Thinking
        // disclosure — same shape Web UI uses for history reload via
        // ui.js:separateThinkingContent. Without this split the
        // reasoning bleeds into the visible bubble after refresh.
        const split = separateThinkingContent(text || '');
        inst.fields.body.innerHTML = renderMarkdown(split.response || '');
        inst.fields.body.classList.add('is-rendered');
        if (inst.fields.caret) inst.fields.caret.hidden = true;
        if (split.thinking) {
            const thinkingEl     = inst.fields.thinking;
            const thinkingBodyEl = inst.fields['thinking-body'];
            const thinkingCountEl = inst.fields['thinking-count'];
            if (thinkingEl && thinkingBodyEl) {
                thinkingEl.hidden = false;
                thinkingBodyEl.textContent = split.thinking;
                if (thinkingCountEl) {
                    thinkingCountEl.textContent = '(' + split.thinking.length + ' chars)';
                }
            }
        }
        // Steps from the persisted tool-call log. The shape Ragsmith
        // stores in messages.step_log is a flat array of one entry per
        // tool invocation (server-side _build_step_log), NOT the live
        // SSE event stream — each entry has: step, tool, tool_label,
        // args_summary, success, error, duration_ms, prompt_tokens,
        // completion_tokens, timestamp. We render one disclosure row
        // per entry so the Steps panel looks the same after a refresh
        // as it did during streaming.
        if (Array.isArray(stepLog) && stepLog.length > 0) {
            renderStaticStepsIntoBubble(inst, stepLog);
        }
        els.transcript.appendChild(inst.node);
        // Wire action bar for historical messages with known ids. New
        // (streamed) bubbles get wired post-stream from finalize, once
        // the extraction-status poll returns the latest message_id.
        if (messageId) wireAssistantActionBar(inst.node, messageId);
        return inst;
    }

    /**
     * Attach the per-message action bar (Redo + Delete) to an assistant
     * bubble. Idempotent — re-runs replace the click handlers if the
     * bubble is wired more than once (rs_message_id is the dedupe key).
     *
     * @param {HTMLElement} msgEl   the .firefly-agent-msg--assistant node
     * @param {number|string} messageId  Ragsmith rs_message_id
     */
    function wireAssistantActionBar(msgEl, messageId) {
        if (!msgEl || !messageId) return;
        msgEl.dataset.rsMessageId = String(messageId);
        const bar = msgEl.querySelector('[data-field="actions"]');
        if (!bar) return;
        bar.hidden = false;
        const redoBtn   = bar.querySelector('[data-action="redo"]');
        const deleteBtn = bar.querySelector('[data-action="delete"]');

        if (redoBtn) {
            redoBtn.onclick = (e) => {
                e.preventDefault();
                openRedoModal(msgEl, messageId);
            };
        }
        if (deleteBtn) {
            deleteBtn.onclick = async (e) => {
                e.preventDefault();
                await confirmAndDeleteMessage(msgEl, messageId);
            };
        }
        updateRedoButtonVisibility();
    }

    /**
     * Web UI parity: only the LAST assistant message in the transcript
     * shows its Redo button; older messages can still be deleted but
     * can't be edit-and-resubmitted (the chat history above would be out
     * of order). Called after every action-bar wire and after deletes.
     */
    function updateRedoButtonVisibility() {
        if (!els.transcript) return;
        const assistants = els.transcript.querySelectorAll('.firefly-agent-msg--assistant');
        let lastWithId = null;
        assistants.forEach((m) => {
            if (m.dataset && m.dataset.rsMessageId) lastWithId = m;
        });
        assistants.forEach((m) => {
            const redo = m.querySelector('.firefly-agent-msg-redo');
            if (!redo) return;
            redo.hidden = (m !== lastWithId);
        });
    }

    // Streaming assistant bubble — created at the start of a chat turn and
    // mutated in place as text_chunk / thinking_chunk / *_status events
    // flow in. Finalized by text_complete.
    function startStreamingAssistantBubble() {
        hideEmptyState();
        const inst = instantiate(els.tplAsst);
        if (!inst) return null;
        inst.fields.time.textContent = fmtDate(new Date().toISOString());
        inst.fields.model.textContent = '';
        inst.fields.body.textContent = '';
        if (inst.fields.caret) inst.fields.caret.hidden = false;
        els.transcript.appendChild(inst.node);

        state.currentAssistant = {
            msgEl:        inst.node,
            bodyEl:       inst.fields.body,
            caretEl:      inst.fields.caret,
            timeEl:       inst.fields.time,
            modelEl:      inst.fields.model,
            thinkingEl:   inst.fields.thinking,
            thinkingBodyEl: inst.fields['thinking-body'],
            thinkingCountEl: inst.fields['thinking-count'],
            stepsEl:      inst.fields.steps,
            stepsListEl:  inst.fields['steps-body'],
            stepsCountEl: inst.fields['steps-count'],
            // textBuffer holds the raw streamed body INCLUDING any inline
            // <think>…</think> the model emits. We extract the thinking
            // and the response portion at every render via
            // separateThinkingContent — only `responseText` ever lands in
            // the body. This mirrors Web UI's fullText flow.
            textBuffer:    '',
            // toolThinking = inter-step reasoning streamed via thinking_chunk
            //                events from the tool loop.
            // nativeThinking = the <think>…</think> block extracted out of
            //                  textBuffer at each render.
            // Both render into the same Thinking disclosure via
            // updateThinkingDisplay() (Web UI does the same).
            toolThinking:   '',
            nativeThinking: '',
            stepsCount: 0,
            approvalEl: null,
        };
        scrollToBottom();
        return state.currentAssistant;
    }

    /**
     * Merge the two thinking sources (inter-step from thinking_chunk
     * events + native <think>…</think> extracted from text_chunk) into
     * the bubble's Thinking disclosure. Hides the disclosure entirely
     * when both sources are empty.
     */
    function updateThinkingDisplay() {
        const a = state.currentAssistant;
        if (!a || !a.thinkingEl) return;
        const parts = [];
        if (a.toolThinking) parts.push(a.toolThinking);
        if (a.nativeThinking) parts.push(a.nativeThinking);
        const combined = parts.join('\n\n---\n\n');
        if (!combined) {
            a.thinkingEl.hidden = true;
            return;
        }
        a.thinkingEl.hidden = false;
        a.thinkingBodyEl.textContent = combined;
        a.thinkingCountEl.textContent = '(' + combined.length + ' chars)';
    }

    function finalizeAssistantBubble(meta) {
        const a = state.currentAssistant;
        if (!a) return;
        // Cancel any pending streaming render — we're about to do the
        // final, definitive paint and don't want a stale RAF clobbering
        // it a frame later.
        if (_streamingRender.rafId) {
            cancelAnimationFrame(_streamingRender.rafId);
            _streamingRender.rafId = 0;
            _streamingRender.pending.delete(a.bodyEl);
        }
        // Render markdown for the final response — strip any inline
        // <think>…</think> first so the body never shows reasoning.
        const finalSplit = separateThinkingContent(a.textBuffer);
        a.bodyEl.innerHTML = renderMarkdown(finalSplit.response || '');
        a.bodyEl.classList.add('is-rendered');
        if (a.caretEl) a.caretEl.hidden = true;
        if (meta) {
            if (meta.display_model || meta.model) {
                a.modelEl.textContent = meta.display_model || meta.model;
            }
            if (meta.response_time_ms) {
                const sec = (meta.response_time_ms / 1000).toFixed(2);
                a.modelEl.textContent = (a.modelEl.textContent ? a.modelEl.textContent + ' · ' : '') + sec + 's';
            }
        }
        state.currentAssistant = null;
        scrollToBottom();
    }

    function appendErrorMessage(text, retryable) {
        hideEmptyState();
        const inst = instantiate(els.tplError);
        if (!inst) return;
        inst.fields.text.textContent = text || 'Something went wrong.';
        const retryBtn = inst.node.querySelector('[data-action="retry"]');
        if (retryBtn) {
            if (!retryable) retryBtn.hidden = true;
            else retryBtn.addEventListener('click', () => {
                inst.node.remove();
                if (state.lastUserMessage) {
                    if (els.input) {
                        els.input.value = state.lastUserMessage;
                        onInputChange();
                    }
                    sendMessage(state.lastUserMessage);
                }
            });
        }
        els.transcript.appendChild(inst.node);
        scrollToBottom();
    }

    // ===================================================================
    //   Composer
    // ===================================================================
    function onInputChange() {
        const has = els.input.value.trim().length > 0;
        if (els.sendBtn) els.sendBtn.disabled = !has || state.isStreaming;
        // Auto-grow.
        els.input.style.height = 'auto';
        els.input.style.height = Math.min(els.input.scrollHeight, 200) + 'px';
    }

    function onInputKey(e) {
        // Enter sends; Shift+Enter = newline; Ctrl/Cmd+Enter also sends.
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (els.composer.requestSubmit) els.composer.requestSubmit();
            else onSubmit(e);
        }
    }

    function onSubmit(e) {
        if (e && e.preventDefault) e.preventDefault();
        if (state.isStreaming) return;
        const text = els.input.value.trim();
        if (!text) return;
        sendMessage(text);
    }

    async function sendMessage(text) {
        if (!state.activeSessionId) {
            // Create a session on the fly so the user can just type + send
            // from an empty UI.
            try {
                await onNewChat();
            } catch (e) {
                appendErrorMessage('Could not create a chat: ' + (e && e.message ? e.message : e), false);
                return;
            }
        }
        state.lastUserMessage = text;
        appendUserMessage(text);
        els.input.value = '';
        onInputChange();
        setStreaming(true);
        startStreamingAssistantBubble();

        try {
            await streamChat(text);
        } catch (err) {
            // Streaming failed before completion. Drop the half-built
            // assistant bubble and show an inline retry card.
            if (state.currentAssistant && state.currentAssistant.msgEl) {
                state.currentAssistant.msgEl.remove();
                state.currentAssistant = null;
            }
            const isAbort = err && (err.name === 'AbortError' || /aborted/i.test(String(err.message)));
            if (!isAbort) {
                console.error('[FireflyAgent] streamChat failed:', err);
                appendErrorMessage(String(err && err.message ? err.message : err), true);
            }
        } finally {
            setStreaming(false);
            state.streamAbort = null;
            onInputChange();
            els.input.focus();
        }
    }

    // ===================================================================
    //   SSE stream from POST /chat
    // ===================================================================
    async function streamChat(text) {
        state.streamAbort = new AbortController();
        // Mirror the Ragsmith Web UI's chat body shape exactly so the
        // server takes the same code path (synthesis pre-flight,
        // recall, classifier intent) instead of falling through to the
        // generic tool-use planner. All these fields have Pydantic
        // defaults on the server, but sending them explicitly guarantees
        // identical request parity vs the Web UI's sendChatMessage().
        const requestBody = {
            session_id: state.activeSessionId,
            message: text,
            memory_level: 2,            // 2 = Full (context + fact extraction)
            initial_prompt: false,
            web_search: false,
            web_search_depth: 'surface',
            web_search_images: false,
            debug: false,
            // `disable_planning` is resolved server-side from the agent's
            // admin config (planning_default_on). Browser doesn't send it.
            //
            // `disabled_tools` is a UNION of per-chat browser overrides
            // (this list) + the agent's admin-config defaults — the PHP
            // route merges them in firefly_agent_route_chat. Browser-side
            // toggles can only RESTRICT tools further, never enable what
            // the agent admin disabled.
        };
        // Memory chip OFF → suppress the `recall` tool for this turn.
        // ON → omit the field so only the agent's admin defaults apply
        // (which means recall is allowed because it isn't in the
        // firefly-business-expert tool_configs disabled set).
        if (!state.memoryEnabled) {
            requestBody.disabled_tools = ['recall'];
        }
        const resp = await fetch(REST + '/chat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': NONCE,
                'Accept': 'text/event-stream',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestBody),
            signal: state.streamAbort.signal,
        });
        if (!resp.ok) {
            const body = await resp.text().catch(() => '');
            throw new Error('Chat HTTP ' + resp.status + ': ' + body.slice(0, 200));
        }
        if (!resp.body) throw new Error('No response body to stream.');

        // Inactivity heartbeat: surfaces "Still working… (Xs)" in the meta
        // badge when no SSE event has arrived in the last 5s. Ragsmith
        // emits `: keepalive\n\n` comment-pings every 10s during long
        // synth runs, which keep the socket alive but are silently dropped
        // by our parser (they have no event name) — so without this the
        // user would stare at a blinking caret with no signal.
        const startTime = Date.now();
        let lastActivityAt = startTime;
        let heartbeatTimer = null;
        const tickHeartbeat = () => {
            const sinceLast = Math.floor((Date.now() - lastActivityAt) / 1000);
            const totalSec = Math.floor((Date.now() - startTime) / 1000);
            if (sinceLast >= Math.floor(INACTIVITY_HINT_MS / 1000)) {
                renderMetaBadge({ suffix: 'Still working… ' + totalSec + 's' });
            }
        };
        heartbeatTimer = setInterval(tickHeartbeat, 1000);
        const markActivity = () => { lastActivityAt = Date.now(); };

        // Track completion the same way the Web UI does: only a
        // text_complete (or any data with full_text) flips this to true.
        // If the reader closes without it, we surface an "ended
        // unexpectedly" error so the user isn't left staring at a caret.
        let completedSuccessfully = false;
        const markCompleted = () => { completedSuccessfully = true; };

        try {
            const reader = resp.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                // SSE blocks are separated by blank lines.
                const blocks = buffer.split('\n\n');
                buffer = blocks.pop(); // last (possibly partial) block stays in buffer
                for (const block of blocks) {
                    let evName = null, dataLine = '';
                    for (const line of block.split('\n')) {
                        if (line.startsWith('event:')) evName = line.slice(6).trim();
                        else if (line.startsWith('data:')) dataLine += line.slice(5).trim() + '\n';
                    }
                    dataLine = dataLine.replace(/\n$/, '');
                    if (!evName) continue;
                    markActivity();
                    let data = null;
                    if (dataLine !== '') {
                        try { data = JSON.parse(dataLine); }
                        catch (e) { /* not JSON; pass raw */ data = dataLine; }
                    }
                    if (DEBUG_SSE) console.debug('[FireflyAgent SSE]', evName, data);
                    handleSSE(evName, data, markCompleted);
                }
            }

            // Stream closed cleanly. If text_complete never fired, this is
            // the Web UI's "Response ended unexpectedly" / "No response
            // received from server" path — surface it so the user knows
            // something went wrong server-side instead of staring at a
            // forever-blinking caret.
            if (!completedSuccessfully) {
                const a = state.currentAssistant;
                const partial = (a && a.textBuffer) || '';
                throw new Error(partial
                    ? 'Response ended unexpectedly. Check server.log on Ragsmith.'
                    : 'No response received from server. Check server.log on Ragsmith.');
            }
        } finally {
            if (heartbeatTimer) clearInterval(heartbeatTimer);
        }
    }

    function handleSSE(event, data, markCompleted) {
        const a = state.currentAssistant;
        if (!a) return;

        switch (event) {
            case 'text_chunk': {
                const chunk = data && (data.chunk || data.text) || '';
                a.textBuffer += chunk;
                // Reasoning models (Qwen 3.5 etc.) often stream their
                // <think>…</think> reasoning INSIDE text_chunk events
                // when the LLM provider hasn't separated it out into
                // response.thinking. Split here on every render so the
                // body only ever shows the response portion; the
                // reasoning lands in the Thinking disclosure. Mirrors
                // web/js/chat.js's onChunk handler.
                const split = separateThinkingContent(a.textBuffer);
                a.nativeThinking = split.thinking || '';
                updateThinkingDisplay();
                // Progressive markdown render of ONLY the response part.
                // RAF-coalesced so a fast chunk rate doesn't thrash the
                // DOM. Headings, lists, tables, code fences all appear
                // styled as they arrive instead of waiting for
                // text_complete.
                renderMarkdownStreaming(a.bodyEl, split.response || '');
                if (a.caretEl) a.caretEl.hidden = false;
                maybeScroll();
                break;
            }
            case 'thinking_chunk': {
                const chunk = data && (data.chunk || data.text) || (typeof data === 'string' ? data : '');
                a.toolThinking += chunk;
                updateThinkingDisplay();
                maybeScroll();
                break;
            }
            case 'stream_retract': {
                // Tool loop just decided that the text streamed so far was
                // speculative inter-step reasoning and not part of the
                // final answer. Web UI wipes the accumulated buffer; we
                // mirror that so the synthesizer's verbatim output isn't
                // prepended with stale pre-flight tokens. toolThinking
                // (from explicit thinking_chunk events) is left intact —
                // only the response stream is being retracted.
                a.textBuffer = '';
                a.nativeThinking = '';
                a.bodyEl.textContent = '';
                updateThinkingDisplay();
                addStep(event, data);
                break;
            }
            case 'vision_status':
            case 'search_status':
            case 'tool_status':
            case 'tool_progress':
            case 'doc_status':
            case 'doc_ready': {
                addStep(event, data);
                break;
            }
            case 'tool_approval': {
                renderApprovalCard(data);
                break;
            }
            case 'text_complete': {
                // Stash the session_id from the response — first turn of a
                // fresh session has this set; we bind it back so future
                // turns reuse the same Ragsmith conversation.
                if (data && data.session_id && !state.activeRsSessionId) {
                    state.activeRsSessionId = data.session_id;
                    bindRsSessionIdToWp(data.session_id).catch((e) =>
                        console.warn('[FireflyAgent] bind rs_session_id failed:', e)
                    );
                }
                // Some completion shapes carry the final text under
                // `full_text` instead of streaming it as chunks (search
                // cancel path, e.g.). Backfill so the bubble has content
                // even if no text_chunk events arrived. The tool-loop
                // path also PREPENDS <think>plan + reasoning</think>
                // here (core/ragsmith.py:4077) — same shape the DB
                // persists — so we re-extract on completion to keep
                // the body clean of reasoning even when streaming
                // delivered everything in one shot.
                if (data && data.full_text && !a.textBuffer) {
                    a.textBuffer = String(data.full_text);
                }
                if (a.textBuffer) {
                    const finalSplit = separateThinkingContent(a.textBuffer);
                    a.nativeThinking = finalSplit.thinking || '';
                    updateThinkingDisplay();
                }
                // Tool-loop cancellation path includes message_id in the
                // completion payload (server.py:3486). Wire the action
                // bar immediately if it's there; otherwise we'll poll
                // extraction-status below.
                const inlineMsgId = data && data.message_id;
                const bubbleEl = a.msgEl;
                finalizeAssistantBubble(data || {});
                if (typeof markCompleted === 'function') markCompleted();
                // Repaint the meta badge with the real conversation-level
                // model/agent from the response (overwrites the defaults).
                renderMetaBadge({
                    display_model: data && (data.display_model || data.model),
                    agent:         data && data.agent,
                });
                // Web UI parity: get the assistant's message_id so the
                // bubble's Delete + Redo buttons can hit the DB-backed
                // delete endpoint. Inline path (cancelled tool-loop) →
                // wire now. Otherwise poll /extraction-status (~1s
                // cadence, 30s max) — the server writes the message row
                // asynchronously and the id isn't known until extraction
                // settles. Same shape Web UI's pollExtractionStatus uses.
                if (bubbleEl) {
                    if (inlineMsgId) {
                        wireAssistantActionBar(bubbleEl, inlineMsgId);
                    } else {
                        pollForLatestMessageId(bubbleEl).catch((e) =>
                            console.warn('[FireflyAgent] poll latest_message_id failed:', e)
                        );
                    }
                }
                break;
            }
            case 'done': {
                // Belt + suspenders — some code paths emit `done` without
                // a preceding text_complete (cancelled stream, error).
                // Treat as completion so the safety net doesn't fire.
                if (typeof markCompleted === 'function') markCompleted();
                break;
            }
            case 'error': {
                const msg = (data && (data.error || data.message)) || 'Streaming error';
                // If we already had partial text, keep it and append the
                // error as a separate card. Otherwise drop the empty bubble.
                if (a.textBuffer) {
                    finalizeAssistantBubble({});
                } else if (a.msgEl) {
                    a.msgEl.remove();
                    state.currentAssistant = null;
                }
                appendErrorMessage(msg, true);
                // An explicit server-side error terminates the stream —
                // mark completed so the safety net doesn't double-emit.
                if (typeof markCompleted === 'function') markCompleted();
                break;
            }
            default: {
                // Unknown SSE event — log but don't break the stream.
                console.debug('[FireflyAgent] unhandled SSE event:', event, data);
            }
        }
    }

    /**
     * Render persisted step_log entries into a static (history-loaded)
     * assistant bubble's Steps disclosure. Different from addStep (live)
     * because the stored shape is summarized per-tool-call, not raw SSE
     * events. Each entry → one <li> in the same format the live renderer
     * uses, so a refresh looks indistinguishable from the streamed view.
     */
    function renderStaticStepsIntoBubble(inst, stepLog) {
        if (!inst || !inst.fields) return;
        const stepsEl      = inst.fields.steps;
        const stepsListEl  = inst.fields['steps-body'];
        const stepsCountEl = inst.fields['steps-count'];
        if (!stepsEl || !stepsListEl) return;

        stepsEl.hidden = false;
        stepsListEl.innerHTML = '';
        let visibleCount = 0;
        for (const entry of stepLog) {
            if (!entry || typeof entry !== 'object') continue;
            const isPhase = entry.phase === true;
            const label   = entry.tool_label || entry.tool || 'tool';
            const args    = entry.args_summary || '';
            const ms      = (typeof entry.duration_ms === 'number') ? entry.duration_ms : null;
            const failed  = entry.success === false;
            const errMsg  = failed ? (entry.error || 'failed') : '';

            // Body composition. For phase entries (Analyzing request,
            // Deciding tool, Generating response, etc.) we just show the
            // label — they have no args + 0ms duration, so the noise
            // would be misleading. For tool-call entries we keep the
            // args + timing the live UI showed.
            let body;
            if (isPhase) {
                body = label + (failed ? ' · FAILED' + (errMsg ? ': ' + errMsg : '') : '');
            } else {
                const parts = [];
                if (args) parts.push(args);
                if (ms !== null && ms > 0) parts.push(ms + 'ms');
                if (failed) parts.push('FAILED' + (errMsg ? ': ' + errMsg : ''));
                body = label + (parts.length ? ' · ' + parts.join(' · ') : '');
            }

            // Kind label matches what the live SSE handler shows for the
            // corresponding event type — "search" for search_status,
            // "tool progress" for tool_progress, "doc" for doc_status,
            // and plain "tool" for tool-call entries. Persisted phase
            // entries carry a `kind` field set server-side; tool calls
            // fall through to "tool" for backwards compatibility with
            // the pre-phase-capture step_log shape.
            const kindRaw  = entry.kind || (isPhase ? 'tool_progress' : 'tool');
            const kindText = kindRaw.replace(/_/g, ' ').replace(/status$/, '').trim();

            const li = document.createElement('li');
            li.innerHTML =
                '<span class="firefly-agent-step-kind">' + escapeHtml(kindText) + '</span>' +
                escapeHtml(body);
            stepsListEl.appendChild(li);
            visibleCount++;
        }
        if (stepsCountEl) stepsCountEl.textContent = '(' + visibleCount + ')';
        if (visibleCount === 0) stepsEl.hidden = true;
    }

    function addStep(kind, data) {
        const a = state.currentAssistant;
        if (!a) return;
        a.stepsCount++;
        a.stepsEl.hidden = false;
        a.stepsCountEl.textContent = '(' + a.stepsCount + ')';
        const li = document.createElement('li');
        const kindLabel = (kind || '').replace(/_/g, ' ').replace(/status$/, '').trim();

        // Body assembly: when a tool name is present (note_synthesizer, recall,
        // web_search, etc.) lead with it, then status / phase / message. This
        // matches the Web UI's tool-event rendering and makes long pre-flight
        // tools visible ("note_synthesizer · executing · fetching") instead of
        // just "executing" with no clue which tool is busy.
        let body = '';
        if (data && typeof data === 'object') {
            const tool   = data.tool || '';
            const status = data.status || '';
            const phase  = data.phase || '';
            const message = data.message || data.detail || data.kb || '';
            if (tool) {
                const tail = [status, phase, message].filter(Boolean).join(' · ');
                body = tool + (tail ? ' · ' + tail : '');
            } else {
                body = message || status || phase || JSON.stringify(data);
            }
        } else if (typeof data === 'string') {
            body = data;
        }
        li.innerHTML = '<span class="firefly-agent-step-kind">' + escapeHtml(kindLabel) + '</span>' + escapeHtml(body);
        a.stepsListEl.appendChild(li);
        maybeScroll();
    }

    function renderApprovalCard(data) {
        const a = state.currentAssistant;
        if (!a || !els.tplApproval) return;
        const inst = instantiate(els.tplApproval);
        if (!inst) return;
        const approvalId = data && (data.approval_id || data.id) || '';
        const summary = data && (data.summary || data.description || JSON.stringify(data, null, 2)) || '';
        inst.fields.body.textContent = summary;
        const approveBtn = inst.node.querySelector('[data-action="approve"]');
        const rejectBtn  = inst.node.querySelector('[data-action="reject"]');
        const sendDecision = async (decision) => {
            try {
                inst.node.remove();
                await api('/chat/tool-approval', {
                    method: 'POST',
                    body: { approval_id: approvalId, decision: decision },
                });
            } catch (e) {
                console.error('[FireflyAgent] tool-approval send failed:', e);
                appendErrorMessage('Could not deliver tool approval: ' + (e && e.message ? e.message : e), false);
            }
        };
        if (approveBtn) approveBtn.addEventListener('click', () => sendDecision('approve'));
        if (rejectBtn)  rejectBtn.addEventListener('click',  () => sendDecision('reject'));
        // Append the card BEFORE the streaming body so it visually leads
        // the partial assistant response.
        a.msgEl.insertBefore(inst.node, a.bodyEl);
        a.approvalEl = inst.node;
        maybeScroll();
    }

    async function bindRsSessionIdToWp(rsId) {
        if (!state.activeSessionId || !rsId) return;
        try {
            await api('/sessions/' + state.activeSessionId + '/ragsmith', {
                method: 'POST',
                body: { rs_session_id: rsId },
            });
            // Sync local state so subsequent operations know we're bound.
            const sess = state.sessions.find((s) => Number(s.id) === Number(state.activeSessionId));
            if (sess) sess.rs_session_id = rsId;
        } catch (e) {
            console.warn('[FireflyAgent] bind rs_session_id failed:', e);
        }
    }

    async function onCancel() {
        if (state.streamAbort) {
            try { state.streamAbort.abort(); } catch (e) { /* swallow */ }
        }
        try { await api('/chat/cancel', { method: 'POST', body: {} }); }
        catch (e) { console.warn('[FireflyAgent] cancel failed:', e); }
    }

    // ===================================================================
    //   Warm up (pre-load KBs, embeddings, helper LLMs, chat model)
    // ===================================================================
    let warmInFlight = false;

    async function onWarm() {
        if (warmInFlight) return;
        warmInFlight = true;
        if (els.warmBtn) els.warmBtn.classList.add('is-warming');
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

            // Reuse the same SSE block parser shape as streamChat — agent/load
            // emits `event: start | progress | <final>` with JSON data lines.
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
                    let evName = null, dataLine = '';
                    for (const line of block.split('\n')) {
                        if (line.startsWith('event:')) evName = line.slice(6).trim();
                        else if (line.startsWith('data:')) dataLine += line.slice(5).trim() + '\n';
                    }
                    dataLine = dataLine.replace(/\n$/, '');
                    if (!dataLine) continue;
                    let data = null;
                    try { data = JSON.parse(dataLine); }
                    catch (e) { data = dataLine; }

                    // Ragsmith /agents/{name}/load emits:
                    //   { agent, total, is_light, model }       → start (no event name on some paths)
                    //   { loaded, total, status }               → progress
                    //   { name, settings, kbs, ... }            → final
                    if (data && typeof data === 'object') {
                        if (typeof data.total === 'number' && data.loaded === undefined) {
                            total = data.total;
                            updateToast(toast, 'Starting…', 0, total);
                        } else if (typeof data.loaded === 'number') {
                            total = total || data.total || 0;
                            updateToast(toast, data.status || 'Loading…', data.loaded, total || data.loaded);
                        } else if (data.name !== undefined && data.settings !== undefined) {
                            // Final event — agent fully loaded.
                            updateToast(toast, 'Ready', 1, 1);
                        }
                    }
                }
            }
            succeedToast(toast, 'Agent warmed up', 'KBs loaded · model in VRAM · ready to chat');
            // Refresh the warm-state pill so it picks up the new
            // last_activity timestamp from the server.
            fetchWarmStatus().catch(() => {});
        } catch (e) {
            console.error('[FireflyAgent] warm failed:', e);
            failToast(toast, 'Warm-up failed', String(e && e.message ? e.message : e));
        } finally {
            warmInFlight = false;
            if (els.warmBtn) els.warmBtn.classList.remove('is-warming');
        }
    }

    // ===================================================================
    //   Warm-state indicator pill — next to the Warm up button.
    //   Same shape as Capture's: localStorage cache for instant render,
    //   fresh GET /agent/status on page load + after warm-up success,
    //   60s tick to keep "Xm ago" current without re-hitting the server.
    // ===================================================================
    const LS_WARM_KEY = 'firefly-agent/last-warm/v1';
    let warmPillState = null;
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
    function saveWarmCache(s) {
        try {
            if (s && s.ready) window.localStorage.setItem(LS_WARM_KEY, JSON.stringify(s));
            else              window.localStorage.removeItem(LS_WARM_KEY);
        } catch (e) {}
    }
    function loadWarmCache() {
        try {
            const raw = window.localStorage.getItem(LS_WARM_KEY);
            if (!raw) return null;
            const p = JSON.parse(raw);
            if (p && p.ready && typeof p.lastActivity === 'number') return p;
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
                warmPillState = null;
                saveWarmCache(null);
                renderWarmPill();
                return;
            }
            const data = await resp.json();
            const ready  = !!(data && data.ready);
            const last   = (data && data.readiness && data.readiness.last_activity) || null;
            const lastMs = last ? Date.parse(last) : null;
            warmPillState = (ready && lastMs)
                ? { ready: true, lastActivity: lastMs, agent: data.agent || '' }
                : null;
            saveWarmCache(warmPillState);
            renderWarmPill();
        } catch (e) {
            console.warn('[FireflyAgent] warm status fetch failed:', e);
        }
    }

    // ----- Toast (small bottom-right notification used by warm-up) ------
    function openToast(title, status) {
        const el = document.createElement('div');
        el.className = 'firefly-agent-toast';
        el.innerHTML = ''
            + '<div class="firefly-agent-toast-head">'
            +   '<span class="dashicons dashicons-update" aria-hidden="true"></span>'
            +   '<span class="firefly-agent-toast-title"></span>'
            + '</div>'
            + '<div class="firefly-agent-toast-status"></div>'
            + '<div class="firefly-agent-toast-bar"><div class="firefly-agent-toast-bar-fill"></div></div>';
        document.body.appendChild(el);
        el.querySelector('.firefly-agent-toast-title').textContent = title;
        el.querySelector('.firefly-agent-toast-status').textContent = status || '';
        return el;
    }
    function updateToast(el, status, loaded, total) {
        if (!el) return;
        const statusEl = el.querySelector('.firefly-agent-toast-status');
        const barEl    = el.querySelector('.firefly-agent-toast-bar-fill');
        if (statusEl) {
            statusEl.textContent = status + (total > 0 ? ' (' + loaded + '/' + total + ')' : '');
        }
        if (barEl) {
            const pct = total > 0 ? Math.min(100, Math.round((loaded / total) * 100)) : 0;
            barEl.style.width = pct + '%';
        }
    }
    function succeedToast(el, title, status) {
        if (!el) return;
        el.classList.add('is-success');
        el.querySelector('.dashicons').className = 'dashicons dashicons-yes-alt';
        el.querySelector('.firefly-agent-toast-title').textContent = title;
        el.querySelector('.firefly-agent-toast-status').textContent = status || '';
        const bar = el.querySelector('.firefly-agent-toast-bar-fill');
        if (bar) bar.style.width = '100%';
        setTimeout(() => closeToast(el), 3500);
    }
    function failToast(el, title, status) {
        if (!el) return;
        el.classList.add('is-error');
        el.querySelector('.dashicons').className = 'dashicons dashicons-warning';
        el.querySelector('.firefly-agent-toast-title').textContent = title;
        el.querySelector('.firefly-agent-toast-status').textContent = status || '';
        setTimeout(() => closeToast(el), 6000);
    }
    function closeToast(el) {
        if (!el || !el.parentNode) return;
        el.style.opacity = '0';
        el.style.transform = 'translateY(8px)';
        setTimeout(() => { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
    }

    // ===================================================================
    //   Capture header — notes / recordings / documents from the bound
    //   Capture session, rendered above the transcript.
    // ===================================================================

    /**
     * Fetch the three Capture lists in parallel for a given WP session id.
     * Returns { notes, recordings, documents } — each is an array, empty
     * on per-endpoint failure (which we log but otherwise tolerate so a
     * single failing endpoint doesn't blank the whole header).
     */
    async function loadCaptureItems(wpSessionId) {
        if (!wpSessionId) return { notes: [], recordings: [], documents: [] };
        const get = (path, key) => api(path).then(
            (data) => (data && data[key]) || [],
            (err) => {
                console.warn('[FireflyAgent] Capture fetch failed for ' + path + ':', err);
                return [];
            }
        );
        const [notes, recordings, documents] = await Promise.all([
            get('/notes?session=' + wpSessionId, 'notes'),
            get('/recordings?session=' + wpSessionId, 'items'),
            get('/documents?session=' + wpSessionId, 'items'),
        ]);
        return { notes, recordings, documents };
    }

    /**
     * Paint the Capture header for the loaded items. The summary count
     * line reads "3 notes · 1 recording · 2 documents · 47 facts" with
     * facts summed across all documents. Sections only render if their
     * list is non-empty. The whole block stays hidden when all three
     * lists are empty so a chat-only session looks identical to today.
     */
    function renderCaptureHeader(items) {
        if (!els.captureWrap) return;
        const notes      = (items && items.notes)      || [];
        const recordings = (items && items.recordings) || [];
        const documents  = (items && items.documents)  || [];

        if (!notes.length && !recordings.length && !documents.length) {
            els.captureWrap.hidden = true;
            return;
        }

        // Render each section into its <ul>.
        renderCaptureSection('notes',      notes,      buildNoteRow);
        renderCaptureSection('recordings', recordings, buildRecordingRow);
        renderCaptureSection('documents',  documents,  buildDocumentRow);

        // Compose the summary count.
        const factsTotal = documents.reduce((acc, d) => {
            const n = parseInt(d && d.facts_count, 10);
            return acc + (isNaN(n) ? 0 : n);
        }, 0);
        const bits = [];
        if (notes.length)      bits.push(notes.length      + ' note'      + (notes.length === 1 ? '' : 's'));
        if (recordings.length) bits.push(recordings.length + ' recording' + (recordings.length === 1 ? '' : 's'));
        if (documents.length)  bits.push(documents.length  + ' document'  + (documents.length === 1 ? '' : 's'));
        if (factsTotal > 0)    bits.push(factsTotal        + ' fact'      + (factsTotal === 1 ? '' : 's'));
        if (els.captureCount) els.captureCount.textContent = '(' + bits.join(' · ') + ')';

        els.captureWrap.hidden = false;
    }

    /**
     * Reset header state on session switch. Empties the three lists,
     * collapses + hides the outer <details>, and clears the summary
     * count. Called from openSession() before the new fetch fires so
     * the user never sees stale items from the prior session.
     */
    function clearCaptureHeader() {
        if (!els.captureWrap) return;
        els.captureWrap.open = false;
        els.captureWrap.hidden = true;
        if (els.captureCount) els.captureCount.textContent = '';
        if (els.captureBody) {
            els.captureBody.querySelectorAll('[data-list]').forEach((ul) => { ul.innerHTML = ''; });
            els.captureBody.querySelectorAll('.firefly-agent-capture-section').forEach((s) => { s.hidden = true; });
        }
    }

    /**
     * Inject built <details> rows into the named section <ul> and
     * show/hide its parent section based on whether anything was added.
     */
    function renderCaptureSection(kind, list, builder) {
        if (!els.captureBody) return;
        const ul = els.captureBody.querySelector('[data-list="' + kind + '"]');
        if (!ul) return;
        const section = ul.closest('.firefly-agent-capture-section');
        ul.innerHTML = '';
        if (!list || !list.length) {
            if (section) section.hidden = true;
            return;
        }
        for (const item of list) {
            const li = document.createElement('li');
            const node = builder(item);
            if (node) {
                li.appendChild(node);
                ul.appendChild(li);
            }
        }
        if (section) section.hidden = false;
    }

    /**
     * Build a <details> node for a single note from the list payload.
     * The list endpoint returns only id/title/modified/session_id (no
     * content) for performance — content is fetched lazily via
     * GET /notes/{id} the first time the user expands the row.
     */
    function buildNoteRow(item) {
        const inst = instantiate(els.tplCapNote);
        if (!inst) return null;
        inst.fields.title.textContent = item.title || 'Untitled';
        inst.fields.meta.textContent  = fmtDate(item.modified || '');
        const bodyEl = inst.fields.body;
        let loaded = false;
        inst.node.addEventListener('toggle', () => {
            if (loaded || !inst.node.open) return;
            loaded = true;
            api('/notes/' + item.id).then(
                (data) => {
                    const content = (data && data.content) || '';
                    bodyEl.innerHTML = content
                        ? renderMarkdown(content)
                        : '<span class="firefly-agent-capture-loading">No content saved yet.</span>';
                },
                (err) => {
                    console.warn('[FireflyAgent] note content fetch failed:', err);
                    bodyEl.innerHTML = '<span class="firefly-agent-capture-loading">Could not load content.</span>';
                }
            );
        });
        return inst.node;
    }

    /**
     * Build a <details> node for a single recording. The list payload
     * already includes transcript + summary + audio_url, so no lazy
     * fetch is needed — we just populate the fields up front.
     */
    function buildRecordingRow(item) {
        const inst = instantiate(els.tplCapRec);
        if (!inst) return null;
        inst.fields.title.textContent = item.title || 'Recording';
        const metaBits = [];
        if (item.duration) metaBits.push(fmtDuration(item.duration));
        if (item.status && item.status !== 'ready') metaBits.push(item.status);
        if (item.modified) metaBits.push(fmtDate(item.modified));
        inst.fields.meta.textContent = metaBits.join(' · ');

        const audioUrl = item.mp3_url || item.audio_url || '';
        if (audioUrl && inst.fields.audio) {
            inst.fields.audio.src = audioUrl;
            inst.fields.audio.hidden = false;
        }
        const summary = item.summary || '';
        if (summary) {
            inst.fields['summary-wrap'].hidden = false;
            inst.fields.summary.innerHTML = renderMarkdown(summary);
        }
        const transcript = item.transcript || '';
        if (transcript) {
            inst.fields['transcript-wrap'].hidden = false;
            inst.fields.transcript.textContent = transcript;
        }
        return inst.node;
    }

    /**
     * Build a <details> node for a single document. The list payload
     * already includes filename + mime + status + facts_count +
     * chunk_count + extracted text + the facts array.
     */
    function buildDocumentRow(item) {
        const inst = instantiate(els.tplCapDoc);
        if (!inst) return null;
        inst.fields.title.textContent = item.filename || item.title || 'Document';
        const metaBits = [];
        const factsN = parseInt(item.facts_count, 10);
        if (!isNaN(factsN) && factsN > 0) metaBits.push(factsN + ' fact' + (factsN === 1 ? '' : 's'));
        const chunksN = parseInt(item.chunk_count, 10);
        if (!isNaN(chunksN) && chunksN > 0) metaBits.push(chunksN + ' chunk' + (chunksN === 1 ? '' : 's'));
        if (item.status && item.status !== 'ready') metaBits.push(item.status);
        if (item.mime) metaBits.push(item.mime);
        inst.fields.meta.textContent = metaBits.join(' · ');

        const text = item.text || '';
        if (text) {
            inst.fields['text-wrap'].hidden = false;
            inst.fields.text.textContent = text;
        }
        const facts = Array.isArray(item.facts) ? item.facts : [];
        if (facts.length) {
            inst.fields['facts-wrap'].hidden = false;
            inst.fields['facts-count'].textContent = '(' + facts.length + ')';
            const ul = inst.fields.facts;
            for (const f of facts) {
                const li = document.createElement('li');
                // Fact shape can be {fact_key, fact_value} or a string.
                if (typeof f === 'string') {
                    li.textContent = f;
                } else if (f && typeof f === 'object') {
                    const k = f.fact_key || f.key || '';
                    const v = f.fact_value || f.value || f.text || '';
                    li.textContent = k ? (k + ': ' + v) : v;
                }
                ul.appendChild(li);
            }
        }
        return inst.node;
    }

    /**
     * Format seconds → "Xm Ys" / "Ys" for recording duration metadata.
     */
    function fmtDuration(seconds) {
        const n = parseFloat(seconds);
        if (isNaN(n) || n <= 0) return '';
        const total = Math.round(n);
        const m = Math.floor(total / 60);
        const s = total % 60;
        if (m === 0) return s + 's';
        return m + 'm ' + s + 's';
    }

    // ===================================================================
    //   Busy overlay — fullscreen blocker for destructive ops talking to
    //   Ragsmith. The user can't click anything until the round trip
    //   completes, which prevents double-deletes from impatient clicks.
    //   Ported from Capture's busy module for visual consistency.
    // ===================================================================
    const busy = (function () {
        let root = null;
        let labelEl = null;
        let depth = 0;

        function ensureRoot() {
            if (root) return;
            root = document.createElement('div');
            root.className = 'firefly-agent-busy-overlay';
            root.hidden = true;
            root.setAttribute('role', 'status');
            root.setAttribute('aria-live', 'polite');
            root.innerHTML = '<div class="firefly-agent-busy-spinner" aria-hidden="true"></div>' +
                             '<div class="firefly-agent-busy-label"></div>';
            document.body.appendChild(root);
            labelEl = root.querySelector('.firefly-agent-busy-label');
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
        async function wrap(label, task) {
            show(label);
            try { return await task(); }
            finally { hide(); }
        }
        return { show: show, hide: hide, wrap: wrap };
    })();

    // ===================================================================
    //   Modal confirm — Promise-based wrapper used by destructive actions
    // ===================================================================
    /**
     * @param {object} opts
     *   - title:       string (required)
     *   - message:     string|HTMLElement (required)
     *   - confirmLabel: string (default: 'Confirm')
     *   - cancelLabel:  string (default: 'Cancel')
     *   - danger:      bool (default: false) — styles confirm button red
     *   - dialogClass: string (optional) — extra class to add to the dialog node
     *                                      (e.g. 'firefly-agent-redo-dialog' for
     *                                      wider modals with a textarea)
     *   - onMount:     function (optional) — called after the modal is in the
     *                                        DOM, before resolve. Use for
     *                                        custom focus/setup.
     * @returns Promise<boolean> resolving true on confirm, false on cancel
     */
    function confirmModal(opts) {
        return new Promise((resolve) => {
            const root = document.createElement('div');
            root.className = 'firefly-agent-modal-root';
            root.setAttribute('role', 'dialog');
            root.setAttribute('aria-modal', 'true');
            root.innerHTML = ''
                + '<div class="firefly-agent-modal-backdrop"></div>'
                + '<div class="firefly-agent-modal-dialog" role="document">'
                +   `<h2 class="firefly-agent-modal-title"></h2>`
                +   '<div class="firefly-agent-modal-body">'
                +     '<p class="firefly-agent-modal-message"></p>'
                +   '</div>'
                +   '<div class="firefly-agent-modal-actions">'
                +     '<button type="button" class="firefly-agent-modal-cancel"></button>'
                +     '<button type="button" class="firefly-agent-modal-ok"></button>'
                +   '</div>'
                + '</div>';

            const titleEl   = root.querySelector('.firefly-agent-modal-title');
            const msgEl     = root.querySelector('.firefly-agent-modal-message');
            const cancelBtn = root.querySelector('.firefly-agent-modal-cancel');
            const okBtn     = root.querySelector('.firefly-agent-modal-ok');
            const backdrop  = root.querySelector('.firefly-agent-modal-backdrop');
            const dialog    = root.querySelector('.firefly-agent-modal-dialog');

            titleEl.textContent = opts.title || '';
            if (typeof opts.message === 'string') {
                msgEl.textContent = opts.message;
            } else if (opts.message instanceof HTMLElement) {
                msgEl.replaceWith(opts.message);
            }
            cancelBtn.textContent = opts.cancelLabel || 'Cancel';
            okBtn.textContent     = opts.confirmLabel || 'Confirm';
            if (opts.danger) okBtn.classList.add('is-danger');
            if (opts.dialogClass) dialog.classList.add(opts.dialogClass);

            // Focus management: remember the trigger so we can restore on close.
            const previouslyFocused = document.activeElement;

            // Trap Tab inside the dialog while it's open.
            function onKeydown(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    finish(false);
                    return;
                }
                if (e.key === 'Tab') {
                    const focusables = [cancelBtn, okBtn].filter((b) => !b.disabled);
                    if (focusables.length === 0) return;
                    const first = focusables[0], last = focusables[focusables.length - 1];
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            }

            let finished = false;
            function finish(result) {
                if (finished) return;
                finished = true;
                document.removeEventListener('keydown', onKeydown, true);
                if (root.parentNode) root.parentNode.removeChild(root);
                if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                    try { previouslyFocused.focus(); } catch (e) {}
                }
                resolve(result);
            }

            cancelBtn.addEventListener('click', () => finish(false));
            okBtn.addEventListener('click', () => finish(true));
            backdrop.addEventListener('click', () => finish(false));
            // Clicks INSIDE the dialog shouldn't trigger the backdrop cancel.
            dialog.addEventListener('click', (e) => e.stopPropagation());
            document.addEventListener('keydown', onKeydown, true);

            document.body.appendChild(root);
            // If caller supplied an onMount hook (e.g. focus a textarea
            // they injected as `message`), give it the dialog first so
            // it can override our default okBtn focus below.
            if (typeof opts.onMount === 'function') {
                try { opts.onMount(dialog); }
                catch (e) { console.warn('[FireflyAgent] confirmModal onMount threw:', e); }
            } else {
                // Focus the destructive button by default so keyboard users land
                // on the action verb (still requires deliberate Enter to confirm,
                // since the button doesn't auto-submit). Cancel is one Shift+Tab away.
                okBtn.focus();
            }
        });
    }

    /**
     * Poll /sessions/{wp_session_id}/extraction-status until we get a
     * latest_message_id, then wire the bubble's action bar. Matches
     * Web UI's pollExtractionStatus shape (1s cadence, 30s max, then
     * accept whatever id we have).
     */
    async function pollForLatestMessageId(bubbleEl) {
        if (!bubbleEl || !state.activeSessionId) return;
        const wpId = state.activeSessionId;
        const startedAt = Date.now();
        const MAX_MS = 30000;
        const INTERVAL_MS = 1000;
        // Small initial delay — give the server a moment to write the row
        // before the first poll. Web UI does the same (500ms).
        await new Promise((r) => setTimeout(r, 500));
        while (Date.now() - startedAt < MAX_MS) {
            // Bail if the user switched away from this session mid-poll.
            if (state.activeSessionId !== wpId) return;
            try {
                const data = await api('/sessions/' + wpId + '/extraction-status');
                if (data && data.latest_message_id) {
                    wireAssistantActionBar(bubbleEl, data.latest_message_id);
                    if (!data.extracting) return;
                    // Even after we get the id, keep polling until
                    // extracting clears — this matches Web UI behavior,
                    // though for the WP Agent the only consumer of the
                    // extraction signal would be a fact-ingest chip
                    // (we don't render those yet).
                }
            } catch (e) {
                // Swallow — next tick will retry.
            }
            await new Promise((r) => setTimeout(r, INTERVAL_MS));
        }
    }

    /**
     * Delete a single assistant+user message pair. Used by the per-message
     * Delete button. Differs from confirmAndDeleteSession — that one
     * cascade-deletes the whole conversation; this one drops just the
     * exchange.
     */
    async function confirmAndDeleteMessage(bubbleEl, rsMessageId) {
        if (!bubbleEl || !rsMessageId || !state.activeSessionId) return;
        const ok = await confirmModal({
            title: 'Delete this response?',
            message:
                'This removes the assistant\'s response and your prompt above it '
                + 'from the conversation. The rest of the chat history stays intact. '
                + 'This cannot be undone.',
            confirmLabel: 'Delete',
            cancelLabel: 'Cancel',
            danger: true,
        });
        if (!ok) return;
        try {
            await api('/messages/' + state.activeSessionId + '/' + rsMessageId, {
                method: 'DELETE',
            });
        } catch (e) {
            console.error('[FireflyAgent] message delete failed:', e);
            failToast(
                openToast('Delete failed', 'Talking to server…'),
                'Delete failed',
                String(e && e.message ? e.message : e)
            );
            return;
        }
        // Remove the user message that immediately precedes this bubble
        // (the pair) — Ragsmith does the same on the server side, so the
        // DOM stays in sync with the DB.
        const prev = bubbleEl.previousElementSibling;
        if (prev && prev.classList.contains('firefly-agent-msg--user')) {
            prev.remove();
        }
        bubbleEl.remove();
        updateRedoButtonVisibility();
        // If the transcript is now empty, drop back to the empty state.
        const remaining = els.transcript
            ? els.transcript.querySelectorAll('.firefly-agent-msg').length
            : 0;
        if (!remaining) showEmptyState('Start the conversation', 'Send a message below to begin.');
    }

    /**
     * Open the Redo modal for a given assistant bubble. The textarea is
     * pre-filled with the user's previous prompt (pulled from the
     * preceding .firefly-agent-msg--user node). On Resubmit: delete the
     * pair on the server, drop them from the DOM, send the edited prompt
     * as a fresh chat turn.
     */
    function openRedoModal(bubbleEl, rsMessageId) {
        if (!bubbleEl) return;
        const userMsg = bubbleEl.previousElementSibling;
        const originalPrompt = (userMsg && userMsg.classList.contains('firefly-agent-msg--user'))
            ? (userMsg.querySelector('[data-field="body"]')?.textContent || '').trim()
            : '';

        // Build the textarea node up-front so we can hand it to confirmModal
        // as the body. The modal helper accepts an HTMLElement for `message`
        // and replaces its <p> placeholder with it.
        const wrap = document.createElement('div');
        const ta = document.createElement('textarea');
        ta.className = 'firefly-agent-redo-textarea';
        ta.value = originalPrompt;
        ta.spellcheck = true;
        ta.rows = 6;
        ta.setAttribute('aria-label', 'Edit your prompt');
        wrap.appendChild(ta);
        const hint = document.createElement('p');
        hint.className = 'firefly-agent-redo-hint';
        hint.textContent = 'Resubmitting will delete the response above and send your edited prompt as a new turn.';
        wrap.appendChild(hint);

        confirmModal({
            title:        'Edit and resubmit',
            message:      wrap,
            confirmLabel: 'Resubmit',
            cancelLabel:  'Cancel',
            danger:       false,
            dialogClass:  'firefly-agent-redo-dialog',
            onMount:      () => {
                // Focus the textarea + park the caret at end so the user
                // can start editing immediately.
                ta.focus();
                ta.setSelectionRange(ta.value.length, ta.value.length);
            },
        }).then(async (ok) => {
            if (!ok) return;
            const newPrompt = ta.value.trim();
            if (!newPrompt) return;
            // 1. Delete the pair on the server.
            if (state.activeSessionId && rsMessageId) {
                try {
                    await api('/messages/' + state.activeSessionId + '/' + rsMessageId, {
                        method: 'DELETE',
                    });
                } catch (e) {
                    console.error('[FireflyAgent] redo: delete pair failed:', e);
                    appendErrorMessage('Could not delete the previous response: ' + (e && e.message ? e.message : e), false);
                    return;
                }
            }
            // 2. Drop both bubbles from the DOM.
            const prev = bubbleEl.previousElementSibling;
            if (prev && prev.classList.contains('firefly-agent-msg--user')) prev.remove();
            bubbleEl.remove();
            updateRedoButtonVisibility();
            // 3. Send the edited prompt as a fresh chat turn.
            sendMessage(newPrompt);
        }).catch((e) => console.error('[FireflyAgent] redo modal failed:', e));
    }

    /**
     * Show the confirm modal for deleting a session and, if approved, hit
     * the cascade-delete REST route. Then resync the session list and
     * switch active (or land on the empty state if no sessions remain).
     */
    async function confirmAndDeleteSession(id) {
        const sess = state.sessions.find((s) => Number(s.id) === Number(id));
        if (!sess) return;

        // Build a descriptive warning. The Capture session bound to this
        // chat may have notes / recordings / documents attached — surface
        // that explicitly so the user knows what they're about to wipe.
        // Capture's session-delete handler cascades through every child
        // post (firefly_note, firefly_recording, firefly_document), every
        // WP media attachment (MP3s, transcripts, uploaded PDFs), the
        // Ragsmith conversation row, every message + fact in it, the
        // ephemeral KB, and Ragsmith-side audio files. None of that is
        // recoverable from trash.
        const warnEl = document.createElement('div');
        warnEl.innerHTML =
            '<p style="margin:0 0 8px;font-weight:600;">'
            +   'This permanently deletes the entire session.'
            + '</p>'
            + '<p style="margin:0 0 6px;">Everything tied to this chat goes away:</p>'
            + '<ul style="margin:0 0 8px 18px;padding:0;list-style:disc;line-height:1.5;">'
            +   '<li>Every chat message in this conversation</li>'
            +   '<li>All notes, recordings, and documents attached via Capture</li>'
            +   '<li>The audio files, transcripts, and uploaded source files</li>'
            +   '<li>All facts extracted from the above</li>'
            + '</ul>'
            + '<p style="margin:0;color:#b91c1c;font-weight:500;">'
            +   'This cannot be undone.'
            + '</p>';

        const ok = await confirmModal({
            title: 'Delete this chat and everything in it?',
            message: warnEl,
            confirmLabel: 'Delete everything',
            cancelLabel: 'Cancel',
            danger: true,
        });
        if (!ok) return;

        // If the user is currently streaming a response, abort first so a
        // mid-stream delete doesn't leave the parser in a weird state.
        if (state.isStreaming) {
            try { await onCancel(); } catch (e) { /* swallow */ }
        }

        // Wrap the round-trip in the fullscreen busy overlay so the user
        // can't fire a second delete while the first is in flight. The
        // session-delete cascade does a lot server-side (Ragsmith conv
        // drop, per-note message purges, media attachment cleanup) and
        // can take several seconds on a busy session.
        try {
            await busy.wrap('Deleting chat…', async () => {
                await api('/sessions/' + Number(id), { method: 'DELETE' });

                // Drop from local state, then resync from server (which
                // guarantees a Default session exists if we just deleted
                // the last one).
                state.sessions = state.sessions.filter((s) => Number(s.id) !== Number(id));
                const wasActive = Number(state.activeSessionId) === Number(id);
                if (wasActive) {
                    state.activeSessionId = null;
                    state.activeRsSessionId = null;
                    clearTranscript();
                    // Drop the Capture header too — if no sessions remain
                    // after resync, loadSessions() won't auto-open one
                    // (and so won't fire its own clearCaptureHeader),
                    // leaving the deleted session's items visible until
                    // the next refresh.
                    clearCaptureHeader();
                    if (els.title) els.title.textContent = 'New chat';
                    renderMetaBadge({});
                }
                try {
                    await loadSessions();
                } catch (e) {
                    console.error('[FireflyAgent] resync after delete failed:', e);
                }
            });
        } catch (e) {
            console.error('[FireflyAgent] session delete failed:', e);
            failToast(openToast('Delete failed', 'Talking to server…'), 'Delete failed', String(e && e.message ? e.message : e));
            return;
        }

        // Small success toast so the user sees the action completed.
        const toast = openToast('Chat deleted', 'Removed from WP and Ragsmith');
        succeedToast(toast, 'Chat deleted', 'Removed from WP and Ragsmith');
    }

    // ===================================================================
    //   Scroll behavior
    // ===================================================================
    function maybeScroll() {
        if (state.cursorAtBottom) scrollToBottom();
    }

    function scrollToBottom(force) {
        if (!els.transcript) return;
        if (force) state.cursorAtBottom = true;
        // Smooth on discrete events (user just sent a message, session
        // just loaded — force=true). Instant during streaming so the
        // browser doesn't queue dozens of overlapping smooth-scroll
        // animations as tokens arrive token-by-token — that mode wants
        // the scrollTop to track the new bottom every frame, not to
        // animate toward an already-stale target.
        if (force) {
            els.transcript.scrollTo({ top: els.transcript.scrollHeight, behavior: 'smooth' });
        } else {
            els.transcript.scrollTop = els.transcript.scrollHeight;
        }
    }

    function onTranscriptScroll() {
        if (!els.transcript) return;
        const distFromBottom = els.transcript.scrollHeight - els.transcript.scrollTop - els.transcript.clientHeight;
        // Within 60px counts as "at the bottom" — small drift tolerance
        // for browser rounding of scrollHeight on rapid appends.
        state.cursorAtBottom = distFromBottom < 60;
    }

})();
