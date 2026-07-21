<?php
/**
 * Template Sync — Tools page markup shell.
 * All dynamic content (env targets, template lists, plan, progress) is
 * rendered by includes/assets/js/template-sync.js from the
 * fireflyTemplateSync config.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap fts-wrap">
    <h1>Template Sync</h1>
    <p class="fts-intro">
        Push or pull an <strong>entire template</strong> — files, pages, posts, menu, media, and settings —
        between environments. A template travels as one unit and never touches its siblings.
    </p>

    <div class="fts-panel" id="fts-setup">
        <div class="fts-row">
            <div class="fts-field">
                <label>Direction</label>
                <div class="fts-toggle-group" id="fts-direction">
                    <button type="button" class="button fts-toggle" data-value="push">Push</button>
                    <button type="button" class="button fts-toggle active" data-value="pull">Pull</button>
                </div>
            </div>
            <div class="fts-field">
                <label for="fts-env"><span id="fts-env-label">From</span></label>
                <select id="fts-env"></select>
            </div>
            <div class="fts-field fts-field--grow">
                <label for="fts-template">Template</label>
                <select id="fts-template"></select>
            </div>
            <div class="fts-field">
                <label>&nbsp;</label>
                <button type="button" class="button" id="fts-refresh">Refresh</button>
            </div>
        </div>

        <div class="fts-row fts-options">
            <label class="fts-check">
                <input type="checkbox" id="fts-include-media" checked>
                Include media <span class="fts-hint">(attachments tagged with this template)</span>
            </label>
            <label class="fts-check">
                <input type="checkbox" id="fts-include-shared">
                Include shared/untagged media <span class="fts-hint">(blogs, campaigns, legacy uploads — check for a fresh-machine pull)</span>
            </label>
            <label class="fts-check">
                <input type="checkbox" id="fts-mirror">
                Mirror <span class="fts-hint">(delete target-side extras this template owns — you'll see a preview first)</span>
            </label>
            <label class="fts-check">
                <input type="checkbox" id="fts-activate">
                Activate on target after sync <span class="fts-hint">(switches the target's live template!)</span>
            </label>
        </div>

        <div id="fts-summary" class="fts-summary"></div>

        <div class="fts-row fts-actions">
            <button type="button" class="button button-primary button-hero" id="fts-run" disabled>Loading…</button>
            <span id="fts-run-warning" class="fts-run-warning"></span>
        </div>
    </div>

    <div class="fts-panel fts-progress-panel" id="fts-progress" style="display:none;">
        <h2 id="fts-progress-title">Sync in progress…</h2>
        <ol class="fts-steps" id="fts-steps"></ol>
        <div class="fts-log-wrap">
            <h3>Log</h3>
            <div class="fts-log" id="fts-log"></div>
        </div>
        <div class="fts-row fts-actions" id="fts-done-actions" style="display:none;">
            <button type="button" class="button button-primary" id="fts-again">Back to setup</button>
        </div>
    </div>
</div>
