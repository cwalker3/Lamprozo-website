/**
 * Firefly Template Tools — Gutenberg sidebar panels.
 *
 * Replaces two classic add_meta_box() registrations that were rendering in
 * the Document sidebar with extra reorder chevrons. Native
 * PluginDocumentSettingPanel registrations give the same single-chevron UX
 * as the other firefly panels (Remote Sync / Recent syncs / SEO / GEO).
 *
 *   1. Template Assignment — SelectControl bound to the _firefly_template
 *      post_meta. Visible for both pages and posts.
 *   2. Snippet Export — Button + status, only on pages. Calls the existing
 *      admin-ajax handler `firefly_export_snippet`.
 *
 * Both panels are registered under the same plugin id ('firefly-template-tools')
 * so they appear adjacent in the sidebar.
 */
(function (wp) {
    'use strict';

    const { registerPlugin }             = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editor;
    const { SelectControl, Button, Notice, Spinner } = wp.components;
    const { useSelect, useDispatch }     = wp.data;
    const { useState }                   = wp.element;
    const { __ }                         = wp.i18n;
    const el                             = wp.element.createElement;

    const cfg = window.fireflyTemplateTools || {};

    /* ----------------------------------------------------------------------
     * Template Assignment panel
     * ------------------------------------------------------------------- */
    const TemplateAssignmentPanel = () => {
        const { current, postType } = useSelect((select) => {
            const editor = select('core/editor');
            const meta   = editor.getEditedPostAttribute('meta') || {};
            return {
                current:  meta._firefly_template || (cfg.defaultTemplate || ''),
                postType: editor.getCurrentPostType(),
            };
        }, []);

        const { editPost } = useDispatch('core/editor');
        const setTemplate  = (value) => editPost({ meta: { _firefly_template: value } });

        if (!Array.isArray(cfg.templates) || cfg.templates.length === 0) {
            return null;
        }

        const options = cfg.templates.map((t) => ({ value: t, label: t.charAt(0).toUpperCase() + t.slice(1) }));

        return el(PluginDocumentSettingPanel, {
            name:      'firefly-template-assignment',
            title:     __( 'Template Assignment', 'firefly-collective' ),
            className: 'firefly-template-tools-panel'
        },
            el(SelectControl, {
                value:    current,
                options:  options,
                onChange: setTemplate,
            }),
            el('p', { className: 'firefly-tools-description' },
                __( 'Assign this content to a specific template. Content is only visible when that template is active.', 'firefly-collective' ))
        );
    };

    /* ----------------------------------------------------------------------
     * Snippet Export panel — only for pages
     * ------------------------------------------------------------------- */
    const SnippetExportPanel = () => {
        const { postId, postType } = useSelect((select) => {
            const editor = select('core/editor');
            return {
                postId:   editor.getCurrentPostId(),
                postType: editor.getCurrentPostType(),
            };
        }, []);

        const [exporting, setExporting] = useState(false);
        const [result, setResult]       = useState(null); // { type: 'success'|'error', text: string }

        if (postType !== 'page') {
            return null;
        }

        const doExport = () => {
            if (exporting || !postId) return;
            setExporting(true);
            setResult(null);

            const body = new URLSearchParams();
            body.append('action', 'firefly_export_snippet');
            body.append('page_id', String(postId));
            body.append('nonce', cfg.exportNonce || '');

            fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
                .then((r) => r.json())
                .then((j) => {
                    if (j && j.success) {
                        setResult({ type: 'success', text: (j.data && j.data.message) || __( 'Exported.', 'firefly-collective' ) });
                    } else {
                        setResult({ type: 'error', text: (j && j.data && j.data.message) || __( 'Export failed.', 'firefly-collective' ) });
                    }
                })
                .catch(() => setResult({ type: 'error', text: __( 'Export failed. Try again.', 'firefly-collective' ) }))
                .finally(() => setExporting(false));
        };

        const snippetInfo = cfg.snippetInfo || null; // pre-localized read-only info about the snippet path

        return el(PluginDocumentSettingPanel, {
            name:      'firefly-snippet-export',
            title:     __( 'Snippet Export', 'firefly-collective' ),
            className: 'firefly-template-tools-panel'
        },
            snippetInfo && snippetInfo.path
                ? el('div', { className: 'firefly-tools-snippet-meta' },
                    el('div', null,
                        el('strong', null, __( 'Snippet:', 'firefly-collective' )),
                        el('div', null,
                            el('code', { className: 'firefly-tools-snippet-path' }, snippetInfo.path))),
                    snippetInfo.modified
                        ? el('div', null,
                            el('strong', null, __( 'Last Modified:', 'firefly-collective' )),
                            el('div', null, snippetInfo.modified))
                        : el('p', { className: 'firefly-tools-description' },
                            el('em', null, __( 'Snippet file does not exist yet.', 'firefly-collective' )))
                )
                : el('p', { className: 'firefly-tools-description' },
                    snippetInfo && snippetInfo.warning ? snippetInfo.warning : __( 'No snippet path resolved for this page.', 'firefly-collective' )),

            el(Button, {
                variant:   'secondary',
                onClick:   doExport,
                disabled:  exporting,
                style:     { marginTop: 8 },
            }, exporting
                ? el('span', null, el(Spinner), ' ', __( 'Exporting…', 'firefly-collective' ))
                : __( 'Export to Snippet', 'firefly-collective' )),

            result
                ? el(Notice, { status: result.type, isDismissible: false, className: 'firefly-tools-result' }, result.text)
                : null
        );
    };

    /* ----------------------------------------------------------------------
     * Plugin registration — combined into one plugin id so the panels group
     * naturally in the sidebar.
     * ------------------------------------------------------------------- */
    registerPlugin('firefly-template-tools', {
        render: function () {
            return el(wp.element.Fragment, null,
                el(TemplateAssignmentPanel),
                el(SnippetExportPanel)
            );
        },
        icon: 'admin-settings',
    });

})(window.wp);
