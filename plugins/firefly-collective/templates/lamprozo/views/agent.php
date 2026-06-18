<?php
/**
 * Agent — admin page chrome.
 *
 * Two-pane layout mirroring Capture: sidebar (session list) on the
 * left, chat pane on the right. Mobile collapses sidebar into a
 * slide-over via the same setSidebarOpen() pattern Capture uses.
 *
 * Behavior lives in agent.js. This file just stamps the DOM.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap firefly-agent" id="firefly-agent" data-mode="agent">

    <div class="firefly-agent-header firefly-capture-header">
        <h1 class="wp-heading-inline">Agent</h1>

        <button type="button"
                class="page-title-action firefly-capture-icon-btn firefly-agent-warm-btn"
                id="firefly-agent-warm"
                aria-label="Warm up agent" title="Pre-load KBs, embeddings, helper LLMs, and the chat model so the first message responds fast">
            <span class="dashicons dashicons-superhero" aria-hidden="true"></span>
            <span class="firefly-capture-btn-label">Warm up</span>
        </button>

        <!-- Warm-state indicator pill. Same UX as Capture's — hidden by
             default, shown when GET /agent/status returns `ready: true`,
             ticks once a minute to update the relative time. -->
        <span class="firefly-agent-warm-pill" id="firefly-agent-warm-pill" hidden role="status" aria-live="polite">
            <span class="firefly-agent-warm-dot" aria-hidden="true"></span>
            <span class="firefly-agent-warm-pill-text" id="firefly-agent-warm-pill-text"></span>
        </span>

        <button type="button"
                class="page-title-action firefly-capture-icon-btn firefly-agent-new-btn"
                id="firefly-agent-new"
                aria-label="New chat" title="New chat">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            <span class="firefly-capture-btn-label">New chat</span>
        </button>

        <button type="button"
                class="page-title-action firefly-capture-icon-btn firefly-agent-toggle-list"
                id="firefly-agent-toggle-list"
                aria-expanded="false" aria-label="All chats" title="All chats">
            <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
            <span class="firefly-capture-btn-label">All chats</span>
        </button>
    </div>
    <hr class="wp-header-end">

    <div class="firefly-agent-layout firefly-capture-layout">

        <!-- Sidebar: session list (reused from Capture's firefly_note_session CPT). -->
        <aside class="firefly-agent-sidebar firefly-capture-sidebar">
            <div class="firefly-agent-sidebar-head firefly-capture-sidebar-head">
                <span class="firefly-agent-sidebar-label">Your chats</span>
                <span class="firefly-agent-count" id="firefly-agent-count"></span>
            </div>
            <ul class="firefly-agent-list firefly-capture-list" id="firefly-agent-list" aria-label="Agent chat list">
                <li class="firefly-capture-empty">Loading&hellip;</li>
            </ul>
        </aside>

        <!-- Main chat pane. -->
        <section class="firefly-agent-main firefly-capture-main" id="firefly-agent-main">

            <div class="firefly-agent-toolbar">
                <span class="firefly-agent-title" id="firefly-agent-title">New chat</span>
                <span class="firefly-agent-meta" id="firefly-agent-meta" aria-live="polite"></span>
            </div>

            <!-- Capture artifacts header. Loaded from the three existing
                 firefly-capture/v1 endpoints (/notes, /recordings,
                 /documents) on session pick and rendered into a single
                 collapsible <details> block above the transcript.
                 Surfaces what the user has fed this session via Capture
                 so the chat stays focused on actual back-and-forth.
                 Hidden entirely until at least one item exists. -->
            <details class="firefly-agent-disclosure firefly-agent-capture" id="firefly-agent-capture" hidden>
                <summary>
                    <span class="firefly-agent-disclosure-icon">
                        <span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
                    </span>
                    <span class="firefly-agent-disclosure-label">Capture</span>
                    <span class="firefly-agent-disclosure-count" id="firefly-agent-capture-count"></span>
                </summary>
                <div class="firefly-agent-capture-body" id="firefly-agent-capture-body">
                    <!-- Notes section -->
                    <section class="firefly-agent-capture-section firefly-agent-capture-notes" hidden>
                        <h4 class="firefly-agent-capture-section-title">Notes</h4>
                        <ul class="firefly-agent-capture-list" data-list="notes"></ul>
                    </section>
                    <!-- Recordings section -->
                    <section class="firefly-agent-capture-section firefly-agent-capture-recordings" hidden>
                        <h4 class="firefly-agent-capture-section-title">Recordings</h4>
                        <ul class="firefly-agent-capture-list" data-list="recordings"></ul>
                    </section>
                    <!-- Documents section -->
                    <section class="firefly-agent-capture-section firefly-agent-capture-documents" hidden>
                        <h4 class="firefly-agent-capture-section-title">Documents</h4>
                        <ul class="firefly-agent-capture-list" data-list="documents"></ul>
                    </section>
                </div>
            </details>

            <!-- Conversation transcript. Filled by JS; auto-scrolls to bottom
                 on new messages unless the user has scrolled up. -->
            <div class="firefly-agent-transcript" id="firefly-agent-transcript" role="log" aria-live="polite" aria-atomic="false">
                <div class="firefly-agent-empty" id="firefly-agent-empty">
                    <div class="firefly-agent-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="48" height="48" fill="currentColor">
                            <path d="M20 2H4c-1.1 0-2 .9-2 2v14l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                        </svg>
                    </div>
                    <div class="firefly-agent-empty-title">Start a conversation</div>
                    <div class="firefly-agent-empty-hint">Pick a chat from the sidebar to continue it, or type below to start a new one.</div>
                </div>
            </div>

            <!-- Per-chat toggle chips that sit just above the composer.
                 Browser-side overrides that the PHP route unions with the
                 agent's admin-config defaults — these can only further
                 RESTRICT what the LLM may use, never enable what the
                 agent admin disabled. Each chip has an aria-pressed state
                 that JS flips on click and persists to localStorage. -->
            <div class="firefly-agent-chips" id="firefly-agent-chips" role="toolbar" aria-label="Per-chat toggles">
                <button type="button"
                        class="firefly-agent-chip"
                        id="firefly-agent-chip-memory"
                        data-chip="memory"
                        aria-pressed="false"
                        title="When on, the assistant can search your dictation notes and past conversation facts (uses the recall tool). When off, recall is excluded from this turn even if the agent admin has it enabled.">
                    <svg class="firefly-agent-chip-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9.5 2A2.5 2.5 0 0 0 7 4.5v0A2.5 2.5 0 0 0 4.5 7v0A2.5 2.5 0 0 0 5 11.95V12a2.5 2.5 0 0 0 2.5 2.5h0A2.5 2.5 0 0 0 10 17v0a2.5 2.5 0 0 0 2.5 2.5h0a2.5 2.5 0 0 0 2.5-2.5"/>
                        <path d="M14.5 2A2.5 2.5 0 0 1 17 4.5v0A2.5 2.5 0 0 1 19.5 7v0A2.5 2.5 0 0 1 19 11.95V12a2.5 2.5 0 0 1-2.5 2.5h0A2.5 2.5 0 0 1 14 17v0a2.5 2.5 0 0 1-2.5 2.5h0A2.5 2.5 0 0 1 9 17"/>
                    </svg>
                    <span class="firefly-agent-chip-label">Memory</span>
                </button>
            </div>

            <!-- Input bar pinned to the bottom of the pane. -->
            <form class="firefly-agent-composer" id="firefly-agent-composer" autocomplete="off">
                <div class="firefly-agent-input-wrap">
                    <textarea
                        class="firefly-agent-input"
                        id="firefly-agent-input"
                        placeholder="Type a message…"
                        rows="1"
                        spellcheck="true"
                        aria-label="Message"
                    ></textarea>
                </div>
                <button
                    type="submit"
                    class="firefly-agent-send"
                    id="firefly-agent-send"
                    aria-label="Send"
                    title="Send"
                    disabled
                >
                    <span class="dashicons dashicons-arrow-up-alt" aria-hidden="true"></span>
                </button>
                <button
                    type="button"
                    class="firefly-agent-cancel"
                    id="firefly-agent-cancel"
                    aria-label="Stop"
                    title="Stop"
                    hidden
                >
                    <span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>
                </button>
            </form>

        </section>

    </div>

    <!-- ============= Templates rendered into the transcript by JS ============= -->

    <!-- User bubble -->
    <template id="firefly-agent-tpl-user">
        <div class="firefly-agent-msg firefly-agent-msg--user">
            <div class="firefly-agent-msg-head">
                <span class="firefly-agent-msg-role">You</span>
                <span class="firefly-agent-msg-time" data-field="time"></span>
            </div>
            <div class="firefly-agent-msg-body" data-field="body"></div>
        </div>
    </template>

    <!-- Assistant bubble. Includes collapsible Thinking + Steps panels above
         the body that the JS populates from SSE events during streaming. -->
    <template id="firefly-agent-tpl-assistant">
        <div class="firefly-agent-msg firefly-agent-msg--assistant">
            <div class="firefly-agent-msg-head">
                <span class="firefly-agent-msg-role">Assistant</span>
                <span class="firefly-agent-msg-model" data-field="model"></span>
                <span class="firefly-agent-msg-time" data-field="time"></span>
            </div>

            <!-- Collapsed-by-default disclosure showing extended-thinking
                 tokens. JS shows the panel only if thinking_chunk events
                 arrived during the turn. -->
            <details class="firefly-agent-disclosure firefly-agent-thinking" data-field="thinking" hidden>
                <summary>
                    <span class="firefly-agent-disclosure-icon">
                        <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
                    </span>
                    <span class="firefly-agent-disclosure-label">Thinking</span>
                    <span class="firefly-agent-disclosure-count" data-field="thinking-count"></span>
                </summary>
                <div class="firefly-agent-disclosure-body" data-field="thinking-body"></div>
            </details>

            <!-- Collapsed-by-default disclosure showing all *_status events
                 (KB searches, tool calls, doc / vision processing, etc.). -->
            <details class="firefly-agent-disclosure firefly-agent-steps" data-field="steps" hidden>
                <summary>
                    <span class="firefly-agent-disclosure-icon">
                        <span class="dashicons dashicons-editor-ul" aria-hidden="true"></span>
                    </span>
                    <span class="firefly-agent-disclosure-label">Steps</span>
                    <span class="firefly-agent-disclosure-count" data-field="steps-count"></span>
                </summary>
                <ol class="firefly-agent-steps-list" data-field="steps-body"></ol>
            </details>

            <!-- The assistant's prose. Markdown is rendered into innerHTML
                 by marked.js after text_complete; during streaming, raw
                 tokens are appended and a typing caret is shown. -->
            <div class="firefly-agent-msg-body" data-field="body"></div>
            <span class="firefly-agent-typing-caret" data-field="caret" hidden>▍</span>

            <!-- Per-message action bar (Redo + Delete), mirrors Ragsmith
                 Web UI's footer on assistant messages. Hidden until the
                 message has a Ragsmith message_id (loaded from history or
                 fetched post-stream via /extraction-status). Redo button
                 also gets hidden on every message except the *last*
                 assistant (Web UI parity). -->
            <div class="firefly-agent-msg-actions" data-field="actions" hidden>
                <button type="button" class="firefly-agent-msg-action firefly-agent-msg-redo"
                        data-action="redo" title="Edit and resubmit this prompt"
                        aria-label="Edit and resubmit this prompt" hidden>
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                        <path d="M3 3v5h5"/>
                        <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                        <path d="M21 21v-5h-5"/>
                    </svg>
                </button>
                <button type="button" class="firefly-agent-msg-action firefly-agent-msg-delete"
                        data-action="delete" title="Delete this response"
                        aria-label="Delete this response">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                        <line x1="10" y1="11" x2="10" y2="17"/>
                        <line x1="14" y1="11" x2="14" y2="17"/>
                    </svg>
                </button>
            </div>
        </div>
    </template>

    <!-- Tool approval card — replaces the assistant body when a tool_approval
         event fires. User picks Approve / Reject; JS sends to /chat/tool-approval
         and unmounts the card. -->
    <template id="firefly-agent-tpl-approval">
        <div class="firefly-agent-approval">
            <div class="firefly-agent-approval-head">
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <span class="firefly-agent-approval-title">Tool approval requested</span>
            </div>
            <div class="firefly-agent-approval-body" data-field="body"></div>
            <div class="firefly-agent-approval-actions">
                <button type="button" class="button firefly-agent-approval-reject" data-action="reject">Reject</button>
                <button type="button" class="button button-primary firefly-agent-approval-approve" data-action="approve">Approve</button>
            </div>
        </div>
    </template>

    <!-- ===== Capture item templates — used by renderCaptureHeader =====
         Each is a <details> row. The body fills on disclosure open
         (notes do a lazy GET /notes/{id} the first time, recordings +
         documents have all their content in the list payload already). -->

    <template id="firefly-agent-tpl-capture-note">
        <details class="firefly-agent-capture-item firefly-agent-capture-item--note" data-kind="note">
            <summary>
                <span class="firefly-agent-capture-item-title" data-field="title"></span>
                <span class="firefly-agent-capture-item-meta" data-field="meta"></span>
            </summary>
            <div class="firefly-agent-capture-item-body" data-field="body">
                <span class="firefly-agent-capture-loading">Loading…</span>
            </div>
        </details>
    </template>

    <template id="firefly-agent-tpl-capture-recording">
        <details class="firefly-agent-capture-item firefly-agent-capture-item--recording" data-kind="recording">
            <summary>
                <span class="firefly-agent-capture-item-title" data-field="title"></span>
                <span class="firefly-agent-capture-item-meta" data-field="meta"></span>
            </summary>
            <div class="firefly-agent-capture-item-body">
                <audio class="firefly-agent-capture-audio" data-field="audio" controls preload="none" hidden></audio>
                <div class="firefly-agent-capture-subsection" data-field="summary-wrap" hidden>
                    <h5 class="firefly-agent-capture-subtitle">Summary</h5>
                    <div class="firefly-agent-capture-subbody" data-field="summary"></div>
                </div>
                <div class="firefly-agent-capture-subsection" data-field="transcript-wrap" hidden>
                    <h5 class="firefly-agent-capture-subtitle">Transcript</h5>
                    <div class="firefly-agent-capture-subbody firefly-agent-capture-subbody--pre" data-field="transcript"></div>
                </div>
            </div>
        </details>
    </template>

    <template id="firefly-agent-tpl-capture-document">
        <details class="firefly-agent-capture-item firefly-agent-capture-item--document" data-kind="document">
            <summary>
                <span class="firefly-agent-capture-item-title" data-field="title"></span>
                <span class="firefly-agent-capture-item-meta" data-field="meta"></span>
            </summary>
            <div class="firefly-agent-capture-item-body">
                <!-- Facts first — these are the structured signal the
                     agent actually uses for recall + context. Extracted
                     text is the raw source below as reference. -->
                <div class="firefly-agent-capture-subsection" data-field="facts-wrap" hidden>
                    <h5 class="firefly-agent-capture-subtitle">Facts <span class="firefly-agent-capture-subcount" data-field="facts-count"></span></h5>
                    <ul class="firefly-agent-capture-facts" data-field="facts"></ul>
                </div>
                <div class="firefly-agent-capture-subsection" data-field="text-wrap" hidden>
                    <h5 class="firefly-agent-capture-subtitle">Extracted text</h5>
                    <div class="firefly-agent-capture-subbody firefly-agent-capture-subbody--pre" data-field="text"></div>
                </div>
            </div>
        </details>
    </template>

    <!-- Inline error card with a Retry button. JS swaps it in on `error`
         SSE events; Retry replays the last user message. -->
    <template id="firefly-agent-tpl-error">
        <div class="firefly-agent-msg firefly-agent-msg--error">
            <div class="firefly-agent-error">
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <span class="firefly-agent-error-text" data-field="text">Something went wrong.</span>
                <button type="button" class="button firefly-agent-retry" data-action="retry">Retry</button>
            </div>
        </div>
    </template>

</div>
