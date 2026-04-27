<?php
    /**
     * theme/templates/default/models/editor-preview.php
     *
     * Makes the Gutenberg editor canvas render at frontend fidelity for
     * every page in this template:
     *
     *   1) Injects design tokens + _core_design.css into the editor iframe
     *      via block_editor_settings_all, so any page being edited inherits
     *      the same palette, typography, and component CSS as the frontend.
     *   2) Injects home.css ON TOP of the design CSS when editing the home
     *      page, for the page-specific hero / triple-panel / CLI demo CSS.
     *      Other pages with their own page-specific stylesheets follow the
     *      same `{slug}.css` convention and get injected automatically.
     *   3) Stamps `firefly-page` (always) + `page-{slug}` (per page) on the
     *      editor canvas iframe body so design-system selectors cascade
     *      inside the editor exactly as on the frontend.
     *
     * Runs only for pages that belong to this template. Pages from other
     * templates (or attached to no template) get the default Gutenberg
     * editor with no injection.
     */

    if ( ! defined( 'ABSPATH' ) ) exit;

    /**
     * Resolve the post being edited, falling back to the ?post= query
     * parameter that wp-admin uses for both the classic and block editors.
     */
    function firefly_editor_resolve_post( $post = null ) {
        if ( $post ) return $post;
        $pid = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
        if ( ! $pid ) return null;
        return get_post( $pid );
    }

    /**
     * True iff the post belongs to the active template (or has no template
     * meta and we're rendering with this template as the default).
     */
    function firefly_editor_post_in_template( $post ) {
        if ( ! $post ) return false;
        $assigned = get_post_meta( $post->ID, '_firefly_template', true );
        $active   = function_exists( 'firefly_collective_get_active_template' )
            ? firefly_collective_get_active_template()
            : 'default';
        // No assignment → assume active template.
        return $assigned === '' || $assigned === $active;
    }

    /**
     * Read a CSS file from the active template's assets, returning '' if
     * the file isn't present. Suppresses notices on empty/missing files.
     */
    function firefly_editor_read_template_css( $filename ) {
        $template_name = function_exists( 'firefly_collective_get_active_template' )
            ? firefly_collective_get_active_template()
            : 'default';
        $css_path = get_template_directory() . '/templates/' . $template_name . '/assets/css/' . $filename;
        if ( ! file_exists( $css_path ) ) return '';
        $contents = @file_get_contents( $css_path );
        return is_string( $contents ) ? $contents : '';
    }

    /**
     * Inject design CSS (always) + page-specific CSS (when editing that
     * page) into the editor iframe. block_editor_settings_all delivers a
     * `styles` array that Gutenberg applies inside the canvas iframe,
     * not the outer admin frame.
     */
    add_filter( 'block_editor_settings_all', function ( $settings, $context ) {
        $post = firefly_editor_resolve_post( isset( $context->post ) ? $context->post : null );
        if ( ! firefly_editor_post_in_template( $post ) ) return $settings;

        if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
            $settings['styles'] = array();
        }

        // Fonts via @import. Gutenberg honors @import in injected styles.
        $settings['styles'][] = array(
            'css' => "@import url('https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500;600&family=Instrument+Serif:ital@0;1&display=swap');",
        );

        // Tokens (so var(--ff-*) resolves inside the editor iframe).
        $tokens_css = firefly_editor_read_template_css( '_core_custom-properties.css' );
        if ( $tokens_css !== '' ) {
            $settings['styles'][] = array( 'css' => $tokens_css );
        }

        // The shared design system. Always applies inside the editor for
        // any page in this template, mirroring how it loads on the frontend.
        $design_css = firefly_editor_read_template_css( '_core_design.css' );
        if ( $design_css !== '' ) {
            $settings['styles'][] = array( 'css' => $design_css );
        }

        // Page-specific CSS, by convention `assets/css/{slug}.css`. If a
        // page has unique layout (the home triple-panel hero, a custom
        // pricing layout, etc.), drop those rules in `{slug}.css` scoped
        // under `body.firefly-page.page-{slug}` and they'll show up here.
        $slug = $post->post_name ?: '';
        if ( $slug !== '' ) {
            $page_css = firefly_editor_read_template_css( $slug . '.css' );
            if ( $page_css !== '' ) {
                $settings['styles'][] = array( 'css' => $page_css );
            }
        }

        // Editor-canvas-specific overrides. Keep this MINIMAL — layout +
        // component CSS lives in _core_design.css (loaded in both contexts)
        // using high-specificity selectors. This block only handles things
        // that shouldn't leak to the frontend:
        //   - canvas dark bg + outer breathing room
        //   - kill editor-style.css's aggressive .wp-block padding/margin
        //   - force reveal elements visible (no motion-helpers.js in editor)
        //   - editor chrome (post title, block selection color)
        $settings['styles'][] = array(
            'css' => "
                /* Editor canvas: dark bg + outer breathing room */
                .editor-styles-wrapper.firefly-page,
                body.editor-styles-wrapper.firefly-page {
                    background: var(--ff-bg, #0a0a0b) !important;
                    color: var(--ff-fg, #fafaf7);
                    padding: clamp(1rem, 2vw, 2rem) !important;
                    box-sizing: border-box !important;
                }

                /* Kill the 5vh first-child margin from editor-style.css.
                   Non-first-children keep WP's default flow-layout sibling
                   margin so editor spacing matches frontend. */
                .editor-styles-wrapper.firefly-page .wp-block:not(.wp-cover):first-child {
                    margin-block: 0 !important;
                }

                /* Kill editor-style.css's horizontal padding + margins on
                   text-type wp-blocks. These are leaf text elements that
                   shouldn't inherit the 2.5vw / 5vw side gutters — they
                   should flush-align with their container's padding.
                   Tagname selectors keep specificity at (0,3,1) which
                   doesn't conflict with custom block paddings. */
                .editor-styles-wrapper.firefly-page h1.wp-block,
                .editor-styles-wrapper.firefly-page h2.wp-block,
                .editor-styles-wrapper.firefly-page h3.wp-block,
                .editor-styles-wrapper.firefly-page h4.wp-block,
                .editor-styles-wrapper.firefly-page h5.wp-block,
                .editor-styles-wrapper.firefly-page h6.wp-block,
                .editor-styles-wrapper.firefly-page p.wp-block,
                .editor-styles-wrapper.firefly-page blockquote.wp-block {
                    padding-left: 0 !important;
                    padding-right: 0 !important;
                    margin-left: 0 !important;
                    margin-right: 0 !important;
                }

                /* Kill padding-inline: 2.5vw on layout wrappers that
                   should be flush with their container. (Visually-distinct
                   cards like .tier, .pillar, .contrib-card keep their own
                   intentional padding from _core_design.css.) */
                .editor-styles-wrapper.firefly-page .hero-inner,
                .editor-styles-wrapper.firefly-page .hero-copy,
                .editor-styles-wrapper.firefly-page .cli-inner,
                .editor-styles-wrapper.firefly-page .cli-copy,
                .editor-styles-wrapper.firefly-page .substrate-inner,
                .editor-styles-wrapper.firefly-page .section-head,
                .editor-styles-wrapper.firefly-page .grid,
                .editor-styles-wrapper.firefly-page .pillar-grid,
                .editor-styles-wrapper.firefly-page .tmpl-grid,
                .editor-styles-wrapper.firefly-page .price-copy,
                .editor-styles-wrapper.firefly-page .price-list,
                .editor-styles-wrapper.firefly-page .trust-grid {
                    padding-inline: 0 !important;
                }

                /* Class-named paragraphs that may carry padding from editor-style.css. */
                .editor-styles-wrapper.firefly-page p.metric,
                .editor-styles-wrapper.firefly-page p.footnote,
                .editor-styles-wrapper.firefly-page p.lead {
                    padding-left: 0 !important;
                    padding-right: 0 !important;
                }

                /* Force reveal elements visible (motion-helpers.js doesn't run in editor). */
                .editor-styles-wrapper.firefly-page .reveal,
                .editor-styles-wrapper.firefly-page .reveal-stagger,
                .editor-styles-wrapper.firefly-page .reveal-stagger > *,
                .editor-styles-wrapper .reveal,
                .editor-styles-wrapper .reveal-stagger > * {
                    opacity: 1 !important;
                    transform: none !important;
                }

                /* Editor chrome (post title + block selection color). */
                .editor-styles-wrapper.firefly-page .wp-block-post-title,
                .editor-styles-wrapper.firefly-page .editor-post-title {
                    color: var(--ff-fg, #fafaf7);
                    max-width: var(--ff-container, 1240px);
                    margin-inline: auto;
                    padding: 3rem clamp(1.25rem, 5vw, 3.5rem) 0;
                    font-family: var(--ff-font-sans, 'Geist'), sans-serif;
                }
                .editor-styles-wrapper.firefly-page .block-editor-block-list__block.is-selected {
                    outline-color: var(--ff-amber, #f5b544) !important;
                }
            ",
        );

        return $settings;
    }, 20, 2 );

    /**
     * Stamp `firefly-page` + `page-{slug}` on the editor iframe body so
     * design-system selectors cascade inside the editor.
     */
    add_action( 'enqueue_block_editor_assets', function () {
        $post = firefly_editor_resolve_post();
        if ( ! firefly_editor_post_in_template( $post ) ) return;

        $slug = $post && $post->post_name ? $post->post_name : '';

        $handle = 'firefly-editor-canvas-class';
        wp_register_script( $handle, '', array( 'wp-dom-ready', 'wp-data' ), null, true );
        wp_enqueue_script( $handle );

        $slug_class = $slug !== '' ? 'page-' . sanitize_html_class( $slug ) : '';

        $inline = sprintf(
            <<<'JS'
                ( function ( wp ) {
                    if ( ! wp || ! wp.domReady ) return;
                    var slugClass = %s;
                    wp.domReady( function () {
                        var tries = 0;
                        function apply () {
                            tries++;
                            // Iframed editor (modern Gutenberg)
                            var iframe = document.querySelector( 'iframe[name="editor-canvas"]' );
                            if ( iframe && iframe.contentDocument && iframe.contentDocument.body ) {
                                iframe.contentDocument.body.classList.add( 'firefly-page' );
                                if ( slugClass ) iframe.contentDocument.body.classList.add( slugClass );
                            }
                            // Non-iframed fallback
                            document.querySelectorAll( '.editor-styles-wrapper' ).forEach( function ( el ) {
                                el.classList.add( 'firefly-page' );
                                if ( slugClass ) el.classList.add( slugClass );
                            } );
                            if ( tries < 10 ) setTimeout( apply, 400 );
                        }
                        apply();
                    } );
                } )( window.wp );
JS,
            wp_json_encode( $slug_class )
        );
        wp_add_inline_script( $handle, $inline );
    } );
