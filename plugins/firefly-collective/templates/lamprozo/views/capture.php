<?php
/**
 * Capture — admin page view. Three modes share the same session container:
 *   - Notes (existing dictation flow)
 *   - Recordings (browser MediaRecorder + share/room/muted toggles)
 *   - Documents (drop-zone → Ragsmith /document/extract → KB + facts)
 *
 * JS in capture.js owns mode switching, recording capture, document upload,
 * and the per-mode sidebar list state. Each mode pane is a sibling section
 * inside the main column; only the active mode's pane is visible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap firefly-capture" data-mode="notes">
    <div class="firefly-capture-header">
        <h1 class="wp-heading-inline">Capture</h1>

        <!-- Warm up button. Pre-loads everything the next chat / dictation
             save will need (KBs into memory, embedding model, helper LLMs,
             chat model in VRAM) by hitting the existing
             firefly-capture/v1/agent/warm route — the same endpoint the
             Agent admin uses. Streams progress events to a bottom-right
             toast so the user can keep working while the warm-up runs. -->
        <button type="button"
                class="page-title-action firefly-capture-icon-btn firefly-capture-warm-btn"
                id="firefly-capture-warm"
                aria-label="Warm up agent"
                title="Pre-load KBs, embeddings, helper LLMs, and the chat model so the first message responds fast">
            <span class="dashicons dashicons-superhero" aria-hidden="true"></span>
            <span class="firefly-capture-btn-label">Warm up</span>
        </button>

        <!-- Warm-state indicator pill. Hidden by default; the JS shows it
             when GET /agent/status returns `ready: true`, with text like
             "Warm · 4m ago" sourced from the response's last_activity.
             Local 60s tick keeps the relative time fresh without polling. -->
        <span class="firefly-capture-warm-pill" id="firefly-capture-warm-pill" hidden role="status" aria-live="polite">
            <span class="firefly-capture-warm-dot" aria-hidden="true"></span>
            <span class="firefly-capture-warm-pill-text" id="firefly-capture-warm-pill-text"></span>
        </span>

        <!-- Session picker: the session is the shared container across all three
             modes. The bound Ragsmith conversation receives messages from
             every mode in the active session. -->
        <div class="firefly-capture-session-picker">
            <button
                type="button"
                class="page-title-action firefly-capture-icon-btn firefly-capture-session-btn"
                id="firefly-capture-session-btn"
                aria-haspopup="true"
                aria-expanded="false"
                aria-label="Active capture session"
                title="Active capture session"
            >
                <span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
                <span class="firefly-capture-session-label" id="firefly-capture-session-label">Session</span>
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
            <div class="firefly-capture-session-popover" id="firefly-capture-session-popover" role="menu" hidden>
                <div class="firefly-capture-session-popover-head">
                    <strong>Capture sessions</strong>
                    <button type="button" class="button button-small" id="firefly-capture-session-new" aria-label="Start a new capture session">+ New session</button>
                </div>
                <ul class="firefly-capture-session-list" id="firefly-capture-session-list" aria-label="Capture sessions list"></ul>
                <p class="firefly-capture-session-hint">Each session maps to one AI conversation. Notes, recordings and documents in this session all land there.</p>
            </div>
        </div>

        <button type="button" class="page-title-action firefly-capture-icon-btn firefly-capture-new-btn" id="firefly-capture-new" aria-label="New item" title="New">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            <span class="firefly-capture-btn-label" data-mode-label-notes="New note" data-mode-label-recordings="New recording" data-mode-label-documents="New document">New note</span>
        </button>
        <button type="button" class="page-title-action firefly-capture-icon-btn firefly-capture-toggle-list" id="firefly-capture-toggle-list" aria-expanded="false" aria-label="All items" title="All">
            <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
            <span class="firefly-capture-btn-label" data-mode-label-notes="All notes" data-mode-label-recordings="All recordings" data-mode-label-documents="All documents">All notes</span>
        </button>
    </div>
    <hr class="wp-header-end">

    <!-- Mode tabs. ARIA tablist; clicking a tab swaps the active pane and the
         sidebar list contents. The active tab is reflected on .firefly-capture
         via the data-mode attribute so CSS can colour the active mode. -->
    <div class="firefly-capture-modetabs" role="tablist" aria-label="Capture modes">
        <button type="button" role="tab" class="firefly-capture-modetab is-active" id="firefly-capture-tab-notes"      data-mode="notes"      aria-selected="true"  aria-controls="firefly-capture-pane-notes">
            <span class="dashicons dashicons-edit" aria-hidden="true"></span>Notes
        </button>
        <button type="button" role="tab" class="firefly-capture-modetab" id="firefly-capture-tab-recordings" data-mode="recordings" aria-selected="false" aria-controls="firefly-capture-pane-recordings">
            <span class="dashicons dashicons-microphone" aria-hidden="true"></span>Recordings
        </button>
        <button type="button" role="tab" class="firefly-capture-modetab" id="firefly-capture-tab-documents"  data-mode="documents"  aria-selected="false" aria-controls="firefly-capture-pane-documents">
            <span class="dashicons dashicons-media-document" aria-hidden="true"></span>Documents
        </button>
    </div>

    <div class="firefly-capture-layout">

        <!-- Sidebar: items of the active mode in this session, newest first. -->
        <aside class="firefly-capture-sidebar">
            <div class="firefly-capture-sidebar-head">
                <span class="firefly-capture-sidebar-label" data-mode-label-notes="Your notes" data-mode-label-recordings="Your recordings" data-mode-label-documents="Your documents">Your notes</span>
                <span class="firefly-capture-count" id="firefly-capture-count"></span>
            </div>
            <ul class="firefly-capture-list" id="firefly-capture-list" aria-label="Capture items list">
                <li class="firefly-capture-empty">Loading&hellip;</li>
            </ul>
        </aside>

        <!-- Main panel: one pane per mode. Only the active pane is visible. -->
        <section class="firefly-capture-main" id="firefly-capture-main">

            <!-- ===== Notes mode pane ===== -->
            <div class="firefly-capture-pane firefly-capture-pane-notes" id="firefly-capture-pane-notes" role="tabpanel" aria-labelledby="firefly-capture-tab-notes" data-mode="notes">
                <div class="firefly-capture-toolbar">
                    <input
                        type="text"
                        id="firefly-capture-title"
                        class="firefly-capture-title-input"
                        placeholder="Untitled"
                        aria-label="Note title"
                        spellcheck="false"
                        readonly
                    />
                    <button type="button" class="button firefly-capture-edit" id="firefly-capture-edit" aria-pressed="false" aria-label="Edit note">
                        <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                        <span class="firefly-capture-edit-label">Edit</span>
                    </button>
                    <button type="button" class="button button-primary firefly-capture-ai-save" id="firefly-capture-ai-save" aria-label="Save to AI" title="Save to AI">
                        <span class="firefly-capture-ai-sparkles" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" focusable="false">
                                <path d="M12 2.5l1.6 4.9 4.9 1.6-4.9 1.6L12 15.5l-1.6-4.9L5.5 9l4.9-1.6L12 2.5z"/>
                                <path d="M19 14.5l.9 2.6 2.6.9-2.6.9-.9 2.6-.9-2.6-2.6-.9 2.6-.9.9-2.6z"/>
                                <path d="M5.5 17l.6 1.7 1.7.6-1.7.6-.6 1.7-.6-1.7-1.7-.6 1.7-.6.6-1.7z"/>
                            </svg>
                        </span>
                        <span class="firefly-capture-ai-save-label">Save to AI</span>
                    </button>
                    <button type="button" class="button button-link-delete firefly-capture-delete-btn" id="firefly-capture-delete" aria-label="Delete note" title="Delete">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                        <span class="firefly-capture-delete-label">Delete</span>
                    </button>
                </div>

                <div class="firefly-capture-meta">
                    <span id="firefly-capture-meta-saved" class="firefly-capture-saved-status"></span>
                    <span id="firefly-capture-meta-modified"></span>
                </div>

                <textarea
                    class="firefly-capture-transcript"
                    id="firefly-capture-transcript"
                    aria-label="Note content"
                    placeholder="Tap the microphone to start dictating. Tap Edit to type or correct text."
                    spellcheck="true"
                    readonly
                ></textarea>

                <!-- Hidden host for the firefly-ragsmith dictation panel; we drive it via API. -->
                <div id="firefly-capture-dictation-host" hidden></div>

                <div class="firefly-capture-mic-bar">
                    <button
                        type="button"
                        class="firefly-capture-mic"
                        id="firefly-capture-mic"
                        aria-label="Start dictation"
                        aria-pressed="false"
                    >
                        <span class="firefly-capture-mic-ring"></span>
                        <span class="dashicons dashicons-microphone" aria-hidden="true"></span>
                    </button>
                    <button
                        type="button"
                        class="button firefly-capture-mute"
                        id="firefly-capture-mute"
                        aria-label="Mute microphone"
                        aria-pressed="false"
                        disabled
                    >
                        <span class="firefly-capture-mute-icon">
                            <span class="dashicons dashicons-microphone" aria-hidden="true"></span>
                        </span>
                        <span class="firefly-capture-mute-label">Mute</span>
                    </button>
                    <div class="firefly-capture-status" id="firefly-capture-status" aria-live="polite">Idle</div>
                </div>
            </div>

            <!-- ===== Recordings mode pane =====
                 Entry-point only: title input + the 3 capture toggles +
                 Record button. Starting a recording opens the fullscreen
                 #ffrec-overlay (mirrors Ragsmith's recording UX exactly:
                 REC dot + timer + 21-bar level meter + Mute/Pause/Stop).
                 Stop opens #ffrec-complete-overlay (Preview/Discard/Process).
                 Process opens #ffrec-preprocess-dialog (chips for Transcript /
                 Summary / Diarization / Add to KB). Start Processing opens
                 #ffrec-processing-overlay with the SSE progress bar.
                 Complete opens #ffrec-kb-dialog (stats + speaker rename). -->
            <div class="firefly-capture-pane firefly-capture-pane-recordings" id="firefly-capture-pane-recordings" role="tabpanel" aria-labelledby="firefly-capture-tab-recordings" data-mode="recordings" hidden>
                <div class="firefly-capture-rec-toolbar">
                    <input
                        type="text"
                        id="firefly-capture-rec-title"
                        class="firefly-capture-title-input"
                        placeholder="Untitled recording"
                        aria-label="Recording title"
                        spellcheck="false"
                    />
                </div>

                <div class="firefly-capture-rec-toggles" role="group" aria-label="Recording options">
                    <label class="firefly-capture-rec-toggle">
                        <input type="checkbox" id="firefly-capture-rec-share-audio">
                        <span class="firefly-capture-rec-toggle-label">Share audio</span>
                        <span class="firefly-capture-rec-toggle-hint">Capture tab / window audio (desktop only)</span>
                    </label>
                    <label class="firefly-capture-rec-toggle">
                        <input type="checkbox" id="firefly-capture-rec-room-audio">
                        <span class="firefly-capture-rec-toggle-label">Capture room audio</span>
                        <span class="firefly-capture-rec-toggle-hint">Disable echo cancellation + noise suppression for in-room playback</span>
                    </label>
                    <label class="firefly-capture-rec-toggle">
                        <input type="checkbox" id="firefly-capture-rec-start-muted">
                        <span class="firefly-capture-rec-toggle-label">Start muted</span>
                        <span class="firefly-capture-rec-toggle-hint">Begin with the mic muted; unmute when ready</span>
                    </label>
                </div>

                <div class="firefly-capture-rec-entry" id="firefly-capture-rec-entry">
                    <button
                        type="button"
                        class="firefly-capture-rec-btn"
                        id="firefly-capture-rec-btn"
                        aria-label="Start recording"
                    >
                        <span class="firefly-capture-rec-btn-dot"></span>
                        <span class="firefly-capture-rec-btn-label">Record</span>
                    </button>
                    <span class="firefly-capture-rec-entry-hint">Click/Tap to Start</span>
                </div>

                <!-- ===== Loaded-recording detail =====
                     Visible when the user clicks a past recording in the
                     sidebar. Shows transcript + summary + a Reprocess button
                     that re-opens the chip dialog against the same audio. -->
                <div class="firefly-capture-rec-detail" id="firefly-capture-rec-detail" hidden>
                    <div class="firefly-capture-rec-detail-head">
                        <span class="firefly-capture-rec-detail-status" id="firefly-capture-rec-detail-status">ready</span>
                        <div class="firefly-capture-rec-detail-actions">
                            <button type="button" class="button" id="firefly-capture-rec-back" title="Back to new recording">← Back</button>
                            <button type="button" class="button button-primary" id="firefly-capture-rec-reprocess" title="Reprocess this recording">Reprocess</button>
                        </div>
                    </div>
                    <audio id="firefly-capture-rec-detail-audio" controls preload="none" style="width:100%;margin:8px 0;"></audio>

                    <div class="firefly-capture-rec-output-head">
                        <h3 class="firefly-capture-rec-output-heading">Transcript</h3>
                        <button type="button" class="firefly-capture-copy-btn" data-copy-target="firefly-capture-rec-detail-transcript" aria-label="Copy transcript" title="Copy transcript">
                            <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                        </button>
                    </div>
                    <pre class="firefly-capture-rec-transcript" id="firefly-capture-rec-detail-transcript"></pre>

                    <div class="firefly-capture-rec-output-head">
                        <h3 class="firefly-capture-rec-output-heading">Summary</h3>
                        <button type="button" class="firefly-capture-copy-btn" data-copy-target="firefly-capture-rec-detail-summary" aria-label="Copy summary" title="Copy summary">
                            <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div class="firefly-capture-rec-summary" id="firefly-capture-rec-detail-summary"></div>
                </div>
            </div>

            <!-- ===== Documents mode pane =====
                 Drop a file into the zone (or click to pick) → multipart upload
                 through firefly-ragsmith /document/extract with mode=read_aloud
                 + extract_facts=true. The doc lands in the bound Ragsmith
                 conversation (no LLM round-trip), populates the ephemeral KB,
                 and triggers background fact extraction. -->
            <div class="firefly-capture-pane firefly-capture-pane-documents" id="firefly-capture-pane-documents" role="tabpanel" aria-labelledby="firefly-capture-tab-documents" data-mode="documents" hidden>
                <div class="firefly-capture-doc-toolbar">
                    <span class="firefly-capture-doc-title" id="firefly-capture-doc-title">Drop a document to ingest</span>
                    <button type="button" class="button button-link-delete firefly-capture-doc-delete firefly-capture-delete-btn" id="firefly-capture-doc-delete" aria-label="Delete document" title="Delete" hidden>
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                        <span class="firefly-capture-delete-label">Delete</span>
                    </button>
                </div>

                <!-- Empty/entry state: dropzone fills remaining viewport space. -->
                <div class="firefly-capture-doc-entry" id="firefly-capture-doc-entry">
                    <label
                        class="firefly-capture-doc-dropzone"
                        id="firefly-capture-doc-dropzone"
                        for="firefly-capture-doc-file"
                        aria-label="Drop document or click to pick a file"
                    >
                        <span class="dashicons dashicons-upload firefly-capture-doc-dropzone-icon" aria-hidden="true"></span>
                        <span class="firefly-capture-doc-dropzone-headline">Drop a document here</span>
                        <span class="firefly-capture-doc-dropzone-hint">or click to choose · PDF, DOCX, TXT, MD up to 50 MB</span>
                        <input
                            type="file"
                            id="firefly-capture-doc-file"
                            class="firefly-capture-doc-file"
                            accept=".pdf,.docx,.txt,.md,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,text/markdown"
                        />
                    </label>
                </div>

                <!-- Loaded state: download button + extracted text + facts. -->
                <div class="firefly-capture-doc-detail" id="firefly-capture-doc-detail" hidden>
                    <div class="firefly-capture-doc-detail-head">
                        <a class="button button-primary firefly-capture-doc-download" id="firefly-capture-doc-download" href="#" target="_blank" rel="noopener" download>
                            <span class="dashicons dashicons-download" aria-hidden="true"></span>
                            <span>Download</span>
                        </a>
                        <span class="firefly-capture-doc-facts-count" id="firefly-capture-doc-facts-count" aria-live="polite"></span>
                    </div>

                    <div class="firefly-capture-rec-output-head">
                        <h3 class="firefly-capture-rec-output-heading">Extracted text</h3>
                        <button type="button" class="firefly-capture-copy-btn" data-copy-target="firefly-capture-doc-text" aria-label="Copy extracted text" title="Copy extracted text">
                            <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                        </button>
                    </div>
                    <pre class="firefly-capture-doc-text" id="firefly-capture-doc-text" aria-label="Extracted document text"></pre>

                    <div class="firefly-capture-doc-facts-section" id="firefly-capture-doc-facts-section" hidden>
                        <h3 class="firefly-capture-rec-output-heading">Facts</h3>
                        <ul class="firefly-capture-doc-facts" id="firefly-capture-doc-facts" aria-label="Extracted facts"></ul>
                    </div>
                    <div class="firefly-capture-doc-kb" id="firefly-capture-doc-kb"></div>
                </div>
            </div>

        </section>

    </div>

    <!-- =================================================================
         Fullscreen recording overlays — clone of Ragsmith audio-recorder.
         All four overlays are display:none by default; JS toggles .is-active.
         ================================================================= -->

    <!-- 1. Recording overlay (during capture) -->
    <div id="ffrec-overlay" class="ffrec-overlay" role="dialog" aria-modal="true" aria-label="Recording in progress">
        <div class="ffrec-bar">
            <div class="ffrec-top-row">
                <span id="ffrec-dot" class="ffrec-dot"></span>
                <span class="ffrec-label">REC</span>
                <span id="ffrec-timer" class="ffrec-timer">00:00:00</span>
            </div>
            <div class="ffrec-level-meter" id="ffrec-meter" aria-hidden="true">
                <!-- 21 bars to match Ragsmith's visual rhythm -->
                <span class="ffrec-level-bar"></span><span class="ffrec-level-bar"></span>
                <span class="ffrec-level-bar"></span><span class="ffrec-level-bar"></span>
                <span class="ffrec-level-bar"></span><span class="ffrec-level-bar"></span>
                <span class="ffrec-level-bar"></span><span class="ffrec-level-bar"></span>
                <span class="ffrec-level-bar"></span><span class="ffrec-level-bar"></span>
                <span class="ffrec-level-bar"></span><span class="ffrec-level-bar"></span>
                <span class="ffrec-level-bar"></span><span class="ffrec-level-bar"></span>
                <span class="ffrec-level-bar"></span><span class="ffrec-level-bar"></span>
                <span class="ffrec-level-bar"></span><span class="ffrec-level-bar"></span>
                <span class="ffrec-level-bar"></span><span class="ffrec-level-bar"></span>
                <span class="ffrec-level-bar"></span>
            </div>
            <div class="ffrec-controls-row">
                <button id="ffrec-mute-btn" class="ffrec-control-btn" type="button" title="Mute mic">
                    <span class="dashicons dashicons-microphone" aria-hidden="true"></span>
                    <span id="ffrec-mute-label">Mute</span>
                </button>
                <button id="ffrec-pause-btn" class="ffrec-control-btn" type="button" title="Pause">
                    <span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>
                    <span id="ffrec-pause-label">Pause</span>
                </button>
                <button id="ffrec-stop-btn" class="ffrec-control-btn stop" type="button" title="Stop">
                    <!-- WP's dashicons set has play / pause / skip but no stop —
                         inline SVG of a filled square is the universal stop glyph. -->
                    <svg class="ffrec-stop-icon" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                        <rect x="3" y="3" width="10" height="10" rx="1" fill="currentColor"/>
                    </svg>
                    <span>Stop</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 2. Recording-complete overlay (post-stop, pre-process) -->
    <div id="ffrec-complete-overlay" class="ffrec-overlay" role="dialog" aria-modal="true" aria-label="Recording complete">
        <div class="ffrec-bar complete">
            <span class="ffrec-complete-label">Recording Complete</span>
            <span id="ffrec-complete-duration" class="ffrec-timer">00:00:00</span>
            <audio id="ffrec-preview-audio" controls preload="none" style="display:none;width:100%;margin-top:8px;"></audio>
            <div class="ffrec-complete-actions">
                <button id="ffrec-preview-btn" class="ffrec-control-btn" type="button">Preview</button>
                <button id="ffrec-discard-btn" class="ffrec-control-btn secondary" type="button">Discard</button>
                <button id="ffrec-process-btn" class="ffrec-control-btn primary" type="button">Process</button>
            </div>
        </div>
    </div>

    <!-- 3. Pre-processing dialog (chip toggles before SSE upload) -->
    <div id="ffrec-preprocess-dialog" class="ffrec-dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="ffrec-preprocess-title">
        <div class="ffrec-dialog">
            <h3 id="ffrec-preprocess-title" class="ffrec-dialog-title">Process Recording</h3>
            <div class="ffrec-dialog-section">
                <label class="ffrec-dialog-label" for="ffrec-preprocess-kb">Knowledge Base Name</label>
                <input type="text" id="ffrec-preprocess-kb" class="ffrec-dialog-input" placeholder="recording-2026-06-01">
            </div>
            <div class="ffrec-dialog-section">
                <span class="ffrec-dialog-label">Processing Options</span>
                <div class="ffrec-dialog-chips ffrec-dialog-chips-processing">
                    <button class="ffrec-chip is-active" id="ffrec-chip-transcript" type="button" title="Save the transcript">Transcript</button>
                    <button class="ffrec-chip is-active" id="ffrec-chip-summary"    type="button" title="Generate an LLM summary">Summary</button>
                    <button class="ffrec-chip"           id="ffrec-chip-diarization" type="button" title="Label speakers (requires HF token)">Diarization</button>
                </div>
            </div>
            <div class="ffrec-dialog-actions">
                <button id="ffrec-preprocess-cancel" class="ffrec-dialog-btn secondary" type="button">Cancel</button>
                <button id="ffrec-preprocess-start"  class="ffrec-dialog-btn primary"   type="button">Start Processing</button>
            </div>
        </div>
    </div>

    <!-- 4. Processing overlay (SSE progress) -->
    <div id="ffrec-processing-overlay" class="ffrec-overlay" role="dialog" aria-modal="true" aria-label="Processing recording">
        <div class="ffrec-bar processing">
            <div class="ffrec-progress-container">
                <div class="ffrec-progress-track">
                    <div id="ffrec-progress-bar" class="ffrec-progress-fill"></div>
                </div>
                <span id="ffrec-progress-text" class="ffrec-progress-text">Uploading…</span>
            </div>
            <div id="ffrec-stop-processing" style="display:none; text-align:center; margin-top:8px;">
                <button id="ffrec-stop-processing-btn" class="ffrec-control-btn secondary" type="button" style="font-size:0.85rem; padding:6px 16px;">Stop Processing</button>
            </div>
            <div id="ffrec-error-actions" class="ffrec-error-actions" style="display:none">
                <button id="ffrec-retry-btn"   class="ffrec-control-btn primary"   type="button">Retry</button>
                <button id="ffrec-dismiss-btn" class="ffrec-control-btn secondary" type="button">Dismiss</button>
            </div>
        </div>
    </div>

    <!-- 5. Completion / KB / speaker rename dialog
         Lets the user rename detected speakers while previewing the
         transcript and summary in collapsible panels (Ragsmith parity). -->
    <div id="ffrec-kb-dialog" class="ffrec-dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="ffrec-kb-title">
        <div class="ffrec-dialog ffrec-dialog--wide">
            <h3 id="ffrec-kb-title" class="ffrec-dialog-title">Processing Complete</h3>
            <p class="ffrec-kb-stats"></p>

            <!-- Toggle buttons for inline transcript / summary preview -->
            <div class="ffrec-kb-refs" id="ffrec-kb-refs">
                <button type="button" class="ffrec-kb-ref-btn" id="ffrec-kb-ref-transcript" aria-pressed="false">
                    <span class="dashicons dashicons-media-text" aria-hidden="true"></span>View Transcript
                </button>
                <button type="button" class="ffrec-kb-ref-btn" id="ffrec-kb-ref-summary" aria-pressed="false">
                    <span class="dashicons dashicons-list-view" aria-hidden="true"></span>View Summary
                </button>
            </div>

            <pre class="ffrec-kb-preview" id="ffrec-kb-preview-transcript" hidden></pre>
            <div class="ffrec-kb-preview" id="ffrec-kb-preview-summary" hidden></div>

            <div class="ffrec-dialog-section" id="ffrec-speakers-section" style="display:none;">
                <label class="ffrec-dialog-label">Speakers Detected</label>
                <div class="ffrec-speakers-list" id="ffrec-speakers-list"></div>
            </div>
            <div class="ffrec-dialog-actions">
                <button id="ffrec-kb-cancel"  class="ffrec-dialog-btn secondary" type="button">Skip</button>
                <button id="ffrec-kb-confirm" class="ffrec-dialog-btn primary"   type="button">Done</button>
            </div>
        </div>
    </div>

    <!-- Documents processing overlay. Reuses .ffrec-overlay backdrop +
         .ffrec-bar card so we get the same blur/centered-card feel as the
         recording overlays. Two phases the JS swaps between:
            phase=processing → "Processing document…" (docling + KB chunking)
            phase=extracting → "Extracting facts…" (Ragsmith background extract) -->
    <div id="ffdoc-processing-overlay" class="ffrec-overlay" role="dialog" aria-modal="true" aria-label="Processing document">
        <div class="ffrec-bar processing ffdoc-card">
            <div class="ffdoc-spinner" aria-hidden="true"></div>
            <div class="ffdoc-phase-title"   id="ffdoc-phase-title">Processing document…</div>
            <div class="ffdoc-phase-desc"    id="ffdoc-phase-desc">Docling extraction · chunking · embedding</div>
        </div>
    </div>
</div>
