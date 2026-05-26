/**
 * Firefly — Default-collapsed sidebar panels with session-only persistence.
 *
 * Goal:
 *   - On a fresh browser session, every firefly-owned PluginDocumentSettingPanel
 *     starts collapsed regardless of what WordPress persisted to localStorage.
 *   - Open/close state survives navigating between posts within the same session
 *     (we let core/edit-post's normal preference mechanism handle that).
 *   - Closing the tab / browser = next session starts fresh collapsed again.
 *
 * Mechanism:
 *   - sessionStorage flag 'fireflyPanelsSessionInit' indicates "we've already
 *     initialized panels this session".
 *   - On the FIRST script run per session, wait for the editor to be ready,
 *     then dispatch core/edit-post.toggleEditorPanelClosed on every open
 *     firefly panel. Set the flag so subsequent loads (within the session)
 *     are no-ops and the user's choices stick.
 *
 * The panel names below mirror the {plugin-id}/{panel-name} convention used
 * by PluginDocumentSettingPanel. New firefly panels should be added here.
 */
(function () {
    'use strict';

    if (!window.wp || !wp.domReady || !wp.data) return;

    const SESSION_FLAG = 'fireflyPanelsSessionInit';
    if (sessionStorage.getItem(SESSION_FLAG)) {
        return; // already initialized this session — let Gutenberg handle state
    }

    // All PluginDocumentSettingPanel ids owned by Firefly. Format is
    // `{registerPlugin id}/{panel name}` — verified against each panel's
    // source file. Add new ones here as they're built.
    const PANEL_IDS = [
        'firefly-link-tracking/firefly-link-tracking-panel',
        'firefly-page-sync/firefly-page-sync',
        'firefly-page-pull/firefly-page-pull',
        'firefly-sync-log/firefly-sync-log',
        'firefly-geo-post-settings/geo-post-settings',
        'firefly-seo-post-panel/firefly-seo',
        'firefly-template-tools/firefly-template-assignment',
        'firefly-template-tools/firefly-snippet-export',
    ];

    /**
     * Resolve the store key that exposes the editor-panel read/write APIs.
     * WordPress has been migrating these from 'core/edit-post' to 'core/editor':
     *   - 6.5  deprecated on edit-post, available on editor
     *   - 7.0  removed from edit-post entirely (this site)
     * Probe in order of preference; return null when no store exposes them.
     */
    function resolvePanelStore() {
        const candidates = [ 'core/editor', 'core/edit-post' ];
        for (let i = 0; i < candidates.length; i++) {
            const store = wp.data.select(candidates[i]);
            if (store && typeof store.isEditorPanelOpened === 'function') {
                return candidates[i];
            }
        }
        return null;
    }

    wp.domReady(function () {
        const { select, dispatch, subscribe } = wp.data;

        // Wait until core/editor reports a post id — that's the proxy for
        // "Gutenberg has finished initial setup". Then collapse our panels.
        const unsub = subscribe(function () {
            const editor = select('core/editor');
            if (!editor || !editor.getCurrentPostId()) return;

            const storeKey = resolvePanelStore();
            if (!storeKey) {
                // API not available on any known store — bail without crashing.
                // Set the session flag so we don't retry on every store update.
                sessionStorage.setItem(SESSION_FLAG, '1');
                unsub();
                return;
            }

            const sel = select(storeKey);
            const dis = dispatch(storeKey);

            PANEL_IDS.forEach(function (id) {
                try {
                    if (sel.isEditorPanelOpened(id) && typeof dis.toggleEditorPanelClosed === 'function') {
                        dis.toggleEditorPanelClosed(id);
                    }
                } catch (e) {
                    // Per-panel failure is non-fatal; keep going.
                }
            });

            sessionStorage.setItem(SESSION_FLAG, '1');
            unsub();
        });
    });
})();
