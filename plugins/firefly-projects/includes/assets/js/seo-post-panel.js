/**
 * Firefly Projects — SEO Post Settings Panel
 *
 * Adds a Gutenberg sidebar panel with the 7-field SEO baseline:
 *   - SEO Title (overrides <title>)
 *   - Meta Description (overrides <meta name="description">)
 *   - Canonical URL
 *   - Robots: noindex + nofollow toggles
 *   - OG Image (attachment picker)
 *   - OG Title + OG Description (override Twitter as well — they mirror OG)
 *
 * Above the fields, a live Google-style SERP preview shows how the page will
 * appear in search results as the user types. Length warnings turn the
 * character counters amber at the soft limit, red past the hard limit.
 *
 * All values persist as registered post_meta (see seo-post.php). Override
 * resolution happens server-side in theme/models/seo-{meta,schema}.php.
 */
(function (wp) {
    'use strict';

    const { registerPlugin }                = wp.plugins;
    const { PluginDocumentSettingPanel }    = wp.editor;
    const { TextControl, TextareaControl, ToggleControl, Button } = wp.components;
    const { useSelect, useDispatch }        = wp.data;
    const { __ }                            = wp.i18n;
    const el                                = wp.element.createElement;
    const Fragment                          = wp.element.Fragment;

    const cfg = window.fireflySeoPanel || {};

    /* ----------------------------------------------------------------------
     * Char-length helpers + classes for the counter chip
     * ------------------------------------------------------------------- */
    const titleLimits = { soft: 50, hard: 60 };
    const descLimits  = { soft: 120, hard: 155 };

    function counterClass(len, limits) {
        if (len > limits.hard) return 'is-error';
        if (len > limits.soft) return 'is-warning';
        return '';
    }

    /** Truncate with ellipsis at a max length (preserves word boundary if possible). */
    function truncate(str, max) {
        if (!str || str.length <= max) return str || '';
        const cut = str.slice(0, max - 1);
        const lastSpace = cut.lastIndexOf(' ');
        return (lastSpace > max - 20 ? cut.slice(0, lastSpace) : cut) + '…';
    }

    /** Normalize a URL for the SERP "url crumb" — drop protocol, show host › path. */
    function normalizeUrlCrumb(url) {
        if (!url) return '';
        try {
            const u = new URL(url);
            const path = u.pathname.replace(/\/$/, '') || '';
            return u.host + (path ? ' › ' + path.replace(/^\//, '').split('/').join(' › ') : '');
        } catch (e) {
            return url;
        }
    }

    /* ----------------------------------------------------------------------
     * Live SERP preview component
     * ------------------------------------------------------------------- */
    function SerpPreview({ title, description, url }) {
        const displayTitle = truncate(title || '', titleLimits.hard);
        const displayDesc  = truncate(description || '', descLimits.hard);
        return el('div', { className: 'seo-serp-preview' },
            el('div', { className: 'seo-serp-url' }, normalizeUrlCrumb(url)),
            el('div', { className: 'seo-serp-title' }, displayTitle || __( '(no title)', 'firefly-projects' )),
            el('div', { className: 'seo-serp-description' }, displayDesc || __( '(no description)', 'firefly-projects' ))
        );
    }

    /* ----------------------------------------------------------------------
     * OG image picker — opens wp.media() and stores the attachment id
     * ------------------------------------------------------------------- */
    function OgImagePicker({ attachmentId, onChange }) {
        const [previewUrl, setPreviewUrl] = wp.element.useState(null);

        // Resolve the current attachment's URL whenever the id changes.
        wp.element.useEffect(() => {
            if (!attachmentId) { setPreviewUrl(null); return; }
            wp.apiFetch({ path: '/wp/v2/media/' + attachmentId })
                .then((att) => {
                    const src = (att && att.media_details && att.media_details.sizes && (att.media_details.sizes.medium || att.media_details.sizes.full));
                    setPreviewUrl(src ? src.source_url : (att && att.source_url) || null);
                })
                .catch(() => setPreviewUrl(null));
        }, [attachmentId]);

        const openPicker = () => {
            const frame = wp.media({
                title:    __( 'Select OG Image', 'firefly-projects' ),
                button:   { text: __( 'Use this image', 'firefly-projects' ) },
                library:  { type: 'image' },
                multiple: false,
            });
            frame.on('select', () => {
                const att = frame.state().get('selection').first().toJSON();
                onChange(att.id);
            });
            frame.open();
        };

        return el('div', { className: 'seo-og-image-row' },
            previewUrl
                ? el('img', { className: 'seo-og-image-preview', src: previewUrl, alt: '' })
                : el('div', { className: 'seo-og-image-preview is-empty' }, __( 'No image set', 'firefly-projects' )),
            el('div', { className: 'seo-og-image-actions' },
                el(Button, { variant: 'secondary', onClick: openPicker }, attachmentId ? __( 'Replace', 'firefly-projects' ) : __( 'Choose image', 'firefly-projects' )),
                attachmentId ? el(Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange(0) }, __( 'Clear', 'firefly-projects' )) : null
            )
        );
    }

    /* ----------------------------------------------------------------------
     * Main panel
     * ------------------------------------------------------------------- */
    const SeoPostPanel = () => {
        const { postTitle, excerpt, meta, postPermalink, featuredId } = useSelect((select) => {
            const editor = select('core/editor');
            const m = editor.getEditedPostAttribute('meta') || {};
            return {
                postTitle:    editor.getEditedPostAttribute('title') || '',
                excerpt:      editor.getEditedPostAttribute('excerpt') || '',
                meta:         m,
                postPermalink: editor.getCurrentPost() && editor.getCurrentPost().link ? editor.getCurrentPost().link : (cfg.postPermalink || ''),
                featuredId:   editor.getEditedPostAttribute('featured_media') || 0,
            };
        }, []);

        const { editPost } = useDispatch('core/editor');
        const updateMeta = (key, value) => editPost({ meta: { [key]: value } });

        // Defaults / placeholders the user sees when overrides are empty.
        const defaultTitle       = postTitle ? (cfg.siteName + ' - ' + postTitle) : cfg.siteName;
        const defaultDescription = truncate((meta._geo_summary || excerpt || ''), descLimits.hard);
        const defaultOgImageHint = featuredId ? __( 'Defaults to the featured image.', 'firefly-projects' ) : __( 'Defaults to the site\'s default-og.webp.', 'firefly-projects' );

        // The values that drive the SERP preview — overrides win, else defaults.
        const previewTitle = meta._seo_title || defaultTitle;
        const previewDesc  = meta._seo_description || defaultDescription;

        const titleLen = (meta._seo_title || '').length;
        const descLen  = (meta._seo_description || '').length;

        return el(PluginDocumentSettingPanel, {
            name:      'firefly-seo',
            title:     __( 'SEO', 'firefly-projects' ),
            className: 'firefly-seo-panel'
        },
            // SERP preview
            el('div', { className: 'seo-section' },
                el(SerpPreview, {
                    title:       previewTitle,
                    description: previewDesc,
                    url:         postPermalink,
                })
            ),

            // SEO Title
            el('div', { className: 'seo-section' },
                el('div', { className: 'seo-field-label' },
                    el('span', null, __( 'SEO Title', 'firefly-projects' )),
                    el('span', { className: 'seo-counter ' + counterClass(titleLen, titleLimits) }, titleLen + ' / ' + titleLimits.hard)
                ),
                el(TextControl, {
                    value:       meta._seo_title || '',
                    placeholder: defaultTitle,
                    onChange:    (val) => updateMeta('_seo_title', val),
                }),
                el('p', { className: 'seo-panel-description' }, __( 'Overrides the page\'s <title> tag. Keep under 60 characters for full display in search results.', 'firefly-projects' ))
            ),

            // Meta description
            el('div', { className: 'seo-section' },
                el('div', { className: 'seo-field-label' },
                    el('span', null, __( 'Meta Description', 'firefly-projects' )),
                    el('span', { className: 'seo-counter ' + counterClass(descLen, descLimits) }, descLen + ' / ' + descLimits.hard)
                ),
                el(TextareaControl, {
                    rows:        3,
                    value:       meta._seo_description || '',
                    placeholder: defaultDescription,
                    onChange:    (val) => updateMeta('_seo_description', val),
                }),
                el('p', { className: 'seo-panel-description' }, __( 'Appears under the title in search results. Falls back to GEO summary, then post excerpt.', 'firefly-projects' ))
            ),

            // Canonical URL
            el('div', { className: 'seo-section' },
                el('div', { className: 'seo-field-label' },
                    el('span', null, __( 'Canonical URL', 'firefly-projects' ))
                ),
                el(TextControl, {
                    type:        'url',
                    value:       meta._seo_canonical || '',
                    placeholder: postPermalink,
                    onChange:    (val) => updateMeta('_seo_canonical', val),
                }),
                el('p', { className: 'seo-panel-description' }, __( 'Override only when this page duplicates content from another URL.', 'firefly-projects' ))
            ),

            // Robots toggles
            el('div', { className: 'seo-section' },
                el('div', { className: 'seo-field-label' },
                    el('span', null, __( 'Search engine indexing', 'firefly-projects' ))
                ),
                el(ToggleControl, {
                    label:    __( 'Allow search engines to index this page', 'firefly-projects' ),
                    checked:  ! meta._seo_robots_noindex,
                    onChange: (checked) => updateMeta('_seo_robots_noindex', ! checked),
                }),
                el(ToggleControl, {
                    label:    __( 'Allow search engines to follow links on this page', 'firefly-projects' ),
                    checked:  ! meta._seo_robots_nofollow,
                    onChange: (checked) => updateMeta('_seo_robots_nofollow', ! checked),
                }),
                el('p', { className: 'seo-panel-description' }, __( 'Dev environments always get noindex,nofollow regardless of these toggles.', 'firefly-projects' ))
            ),

            // Social sharing — OG image, OG title, OG description
            el('div', { className: 'seo-section seo-section-social' },
                el('div', { className: 'seo-field-label' },
                    el('span', null, __( 'Social sharing', 'firefly-projects' ))
                ),

                el('div', { className: 'seo-subfield' },
                    el('label', { className: 'seo-subfield-label' }, __( 'OG Image', 'firefly-projects' )),
                    el(OgImagePicker, {
                        attachmentId: meta._seo_og_image_id || 0,
                        onChange:     (id) => updateMeta('_seo_og_image_id', id),
                    }),
                    el('p', { className: 'seo-panel-description' }, defaultOgImageHint + ' ' + __( 'Recommended: 1200×630px (1.91:1).', 'firefly-projects' ))
                ),

                el('div', { className: 'seo-subfield' },
                    el('label', { className: 'seo-subfield-label' }, __( 'OG Title', 'firefly-projects' )),
                    el(TextControl, {
                        value:       meta._seo_og_title || '',
                        placeholder: previewTitle,
                        onChange:    (val) => updateMeta('_seo_og_title', val),
                    })
                ),

                el('div', { className: 'seo-subfield' },
                    el('label', { className: 'seo-subfield-label' }, __( 'OG Description', 'firefly-projects' )),
                    el(TextareaControl, {
                        rows:        2,
                        value:       meta._seo_og_description || '',
                        placeholder: previewDesc,
                        onChange:    (val) => updateMeta('_seo_og_description', val),
                    })
                ),

                el('p', { className: 'seo-panel-description' }, __( 'Twitter card values mirror these OG fields.', 'firefly-projects' ))
            )
        );
    };

    registerPlugin('firefly-seo-post-panel', {
        render: SeoPostPanel,
        icon:   'search',
    });

})(window.wp);
