<?php
/**
 * Notes — admin page view. JS in notes.js owns the dynamic state.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap firefly-notes">
    <div class="firefly-notes-header">
        <h1 class="wp-heading-inline">Notes</h1>

        <!-- Session picker: shows the active dictation session (mirrors a Ragsmith
             conversation). Clicking opens a popover with CRUD over local sessions. -->
        <div class="firefly-notes-session-picker">
            <button
                type="button"
                class="page-title-action firefly-notes-icon-btn firefly-notes-session-btn"
                id="firefly-notes-session-btn"
                aria-haspopup="true"
                aria-expanded="false"
                aria-label="Active dictation session"
                title="Active dictation session"
            >
                <span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
                <span class="firefly-notes-session-label" id="firefly-notes-session-label">Session</span>
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
            <div class="firefly-notes-session-popover" id="firefly-notes-session-popover" role="menu" hidden>
                <div class="firefly-notes-session-popover-head">
                    <strong>Dictation sessions</strong>
                    <button type="button" class="button button-small" id="firefly-notes-session-new" aria-label="Start a new dictation session">+ New session</button>
                </div>
                <ul class="firefly-notes-session-list" id="firefly-notes-session-list" aria-label="Dictation sessions list"></ul>
                <p class="firefly-notes-session-hint">Each session groups dictations into one Ragsmith conversation. Starting a new session begins a fresh conversation.</p>
            </div>
        </div>

        <button type="button" class="page-title-action firefly-notes-icon-btn" id="firefly-notes-new" aria-label="New note" title="New note">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            <span class="firefly-notes-btn-label">New note</span>
        </button>
        <button type="button" class="page-title-action firefly-notes-icon-btn firefly-notes-toggle-list" id="firefly-notes-toggle-list" aria-expanded="false" aria-label="All notes" title="All notes">
            <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
            <span class="firefly-notes-btn-label">All notes</span>
        </button>
    </div>
    <hr class="wp-header-end">

    <div class="firefly-notes-layout">

        <!-- Sidebar: notes list -->
        <aside class="firefly-notes-sidebar">
            <div class="firefly-notes-sidebar-head">
                <span>Your notes</span>
                <span class="firefly-notes-count" id="firefly-notes-count"></span>
            </div>
            <ul class="firefly-notes-list" id="firefly-notes-list" aria-label="Notes list">
                <li class="firefly-notes-empty">Loading&hellip;</li>
            </ul>
        </aside>

        <!-- Main panel -->
        <section class="firefly-notes-main" id="firefly-notes-main">
            <div class="firefly-notes-toolbar">
                <input
                    type="text"
                    id="firefly-notes-title"
                    class="firefly-notes-title-input"
                    placeholder="Untitled"
                    aria-label="Note title"
                    spellcheck="false"
                    readonly
                />
                <button type="button" class="button firefly-notes-edit" id="firefly-notes-edit" aria-pressed="false" aria-label="Edit note">
                    <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                    <span class="firefly-notes-edit-label">Edit</span>
                </button>
                <!-- Save to AI: explicit user-triggered persist to the Ragsmith
                     conversation (and fact extraction). Replaces the prior
                     auto-save-on-mic-stop flow so each note maps cleanly to
                     a single dictation message that the user controls. -->
                <button type="button" class="button button-primary firefly-notes-ai-save" id="firefly-notes-ai-save" aria-label="Save to AI" title="Save to AI">
                    <span class="firefly-notes-ai-sparkles" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" focusable="false">
                            <path d="M12 2.5l1.6 4.9 4.9 1.6-4.9 1.6L12 15.5l-1.6-4.9L5.5 9l4.9-1.6L12 2.5z"/>
                            <path d="M19 14.5l.9 2.6 2.6.9-2.6.9-.9 2.6-.9-2.6-2.6-.9 2.6-.9.9-2.6z"/>
                            <path d="M5.5 17l.6 1.7 1.7.6-1.7.6-.6 1.7-.6-1.7-1.7-.6 1.7-.6.6-1.7z"/>
                        </svg>
                    </span>
                    <span class="firefly-notes-ai-save-label">Save to AI</span>
                </button>
                <button type="button" class="button button-link-delete firefly-notes-delete-btn" id="firefly-notes-delete" aria-label="Delete note">Delete</button>
            </div>

            <div class="firefly-notes-meta">
                <span id="firefly-notes-meta-saved" class="firefly-notes-saved-status"></span>
                <span id="firefly-notes-meta-modified"></span>
            </div>

            <textarea
                class="firefly-notes-transcript"
                id="firefly-notes-transcript"
                aria-label="Note content"
                placeholder="Tap the microphone to start dictating. Tap Edit to type or correct text."
                spellcheck="true"
                readonly
            ></textarea>

            <!-- Hidden host for the firefly-ragsmith dictation panel; we drive it via API. -->
            <div id="firefly-notes-dictation-host" hidden></div>

            <div class="firefly-notes-mic-bar">
                <button
                    type="button"
                    class="firefly-notes-mic"
                    id="firefly-notes-mic"
                    aria-label="Start dictation"
                    aria-pressed="false"
                >
                    <span class="firefly-notes-mic-ring"></span>
                    <span class="dashicons dashicons-microphone" aria-hidden="true"></span>
                </button>
                <button
                    type="button"
                    class="button firefly-notes-mute"
                    id="firefly-notes-mute"
                    aria-label="Mute microphone"
                    aria-pressed="false"
                    disabled
                >
                    <span class="firefly-notes-mute-icon">
                        <span class="dashicons dashicons-microphone" aria-hidden="true"></span>
                    </span>
                    <span class="firefly-notes-mute-label">Mute</span>
                </button>
                <div class="firefly-notes-status" id="firefly-notes-status" aria-live="polite">Idle</div>
            </div>
        </section>

    </div>
</div>
