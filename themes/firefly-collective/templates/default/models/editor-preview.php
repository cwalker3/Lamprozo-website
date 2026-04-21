<?php
    /**
     * theme/templates/default/models/editor-preview.php
     *
     * Makes the Gutenberg editor canvas render the landing page at frontend
     * fidelity when editing the "home" page:
     *   1) Injects home.css into the editor iframe via block_editor_settings_all.
     *   2) Injects Google Fonts + grain overlay into the iframe.
     *   3) Adds `home-page` + `page-home` classes to the editor iframe body so
     *      our existing home.css selectors cascade inside the editor exactly
     *      as they do on the frontend.
     *
     * Only runs when editing the `home` page to avoid polluting other pages.
     */

    if ( ! defined( 'ABSPATH' ) ) exit;

    /**
     * True iff we're editing the home page in the block editor.
     *
     * @param object|null $post
     */
    function firefly_editor_is_home_context( $post = null ) {
        if ( ! $post ) {
            $pid = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
            if ( ! $pid ) return false;
            $post = get_post( $pid );
        }
        return $post && $post->post_name === 'home';
    }

    /**
     * Inject home.css + fonts into the editor iframe.
     * block_editor_settings_all delivers a `styles` array that Gutenberg
     * applies inside the editor canvas iframe (not the outer admin frame).
     */
    add_filter( 'block_editor_settings_all', function ( $settings, $context ) {
        $post = isset( $context->post ) ? $context->post : null;
        if ( ! firefly_editor_is_home_context( $post ) ) return $settings;

        $template_name = firefly_collective_get_active_template();
        $css_path      = get_template_directory() . '/templates/' . $template_name . '/assets/css/home.css';

        if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
            $settings['styles'] = array();
        }

        // Fonts via @import (Gutenberg honors @import in injected styles).
        $settings['styles'][] = array(
            'css' => "@import url('https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500;600&family=Instrument+Serif:ital@0;1&display=swap');",
        );

        // The landing CSS itself.
        if ( file_exists( $css_path ) ) {
            $settings['styles'][] = array(
                'css' => file_get_contents( $css_path ),
            );
        }

        // Editor-canvas-specific CSS. Keep this MINIMAL — padding / grid /
        // layout all live in home.css (loaded in both contexts) using
        // high-specificity selectors. This block only handles things that
        // shouldn't leak to the frontend:
        //   - canvas dark bg + editor gutter
        //   - kill editor-style.css's .wp-block padding/margin overrides
        //   - force reveal elements visible (no motion-helpers.js in editor)
        $settings['styles'][] = array(
            'css' => "
                /* Editor canvas: dark bg + outer breathing room */
                .editor-styles-wrapper.page-home,
                body.editor-styles-wrapper.page-home {
                    background: #0a0a0b !important;
                    color: #fafaf7;
                    padding: clamp(1rem, 2vw, 2rem) !important;
                    box-sizing: border-box !important;
                }
                .editor-styles-wrapper.page-home .home-page {
                    padding: 0 !important;
                    border-radius: 6px;
                    overflow: hidden;
                }

                /* Neutralize editor-style.css rules that target ALL wp-blocks.
                   We do NOT nuke all .wp-block padding-inline because that
                   would outrank home.css card/container padding rules.
                   Instead, we only kill the specific editor-style.css rules
                   that affect first-child margin and h1/p side margins. */

                /* Kill the 5vh first-child margin from editor-style.css.
                   NON-first-children keep WP's default flow-layout sibling
                   margin (1.5em) so editor spacing matches frontend. */
                .editor-styles-wrapper.page-home .wp-block:not(.wp-cover):first-child {
                    margin-block: 0 !important;
                }

                /* Kill editor-style.css's horizontal padding + margins on ALL
                   text-type wp-blocks (headings, paragraphs, quotes). These
                   are leaf text elements that shouldn't inherit the 2.5vw /
                   5vw side gutters from editor-style.css — they should
                   flush-align with their container's padding.
                   Using tagname selectors (h1.wp-block, p.wp-block) keeps
                   specificity at (0,3,1) which doesn't conflict with our
                   custom block paddings (.tier, .pillar, .contrib-card). */
                .editor-styles-wrapper.page-home h1.wp-block,
                .editor-styles-wrapper.page-home h2.wp-block,
                .editor-styles-wrapper.page-home h3.wp-block,
                .editor-styles-wrapper.page-home h4.wp-block,
                .editor-styles-wrapper.page-home h5.wp-block,
                .editor-styles-wrapper.page-home h6.wp-block,
                .editor-styles-wrapper.page-home p.wp-block,
                .editor-styles-wrapper.page-home blockquote.wp-block {
                    padding-left: 0 !important;
                    padding-right: 0 !important;
                    margin-left: 0 !important;
                    margin-right: 0 !important;
                }

                /* Kill editor-style.css's padding-inline: 2.5vw on layout
                   wrappers that should be flush with their container.
                   (Do NOT include .triple, .triple-output, .contrib-card,
                   .pillar, .quote-card, .price-cta, .tier, .cli-box here —
                   those are visually-distinct cards/containers with their
                   own intentional padding from home.css.) */
                .editor-styles-wrapper.page-home .hero-inner,
                .editor-styles-wrapper.page-home .hero-copy,
                .editor-styles-wrapper.page-home .cli-inner,
                .editor-styles-wrapper.page-home .cli-copy,
                .editor-styles-wrapper.page-home .substrate-inner,
                .editor-styles-wrapper.page-home .section-head,
                .editor-styles-wrapper.page-home .grid,
                .editor-styles-wrapper.page-home .pillar-grid,
                .editor-styles-wrapper.page-home .tmpl-grid,
                .editor-styles-wrapper.page-home .price-copy,
                .editor-styles-wrapper.page-home .price-list,
                .editor-styles-wrapper.page-home .trust-grid {
                    padding-inline: 0 !important;
                }

                /* Special-case class-named paragraphs that are text but may
                   carry extra padding from editor-style.css. */
                .editor-styles-wrapper.page-home p.metric,
                .editor-styles-wrapper.page-home p.footnote,
                .editor-styles-wrapper.page-home p.lead {
                    padding-left: 0 !important;
                    padding-right: 0 !important;
                }

                /* Force reveal elements visible (no motion-helpers.js in editor) */
                .editor-styles-wrapper.page-home .reveal,
                .editor-styles-wrapper.page-home .reveal-stagger,
                .editor-styles-wrapper.page-home .reveal-stagger > *,
                .editor-styles-wrapper .reveal,
                .editor-styles-wrapper .reveal-stagger > * {
                    opacity: 1 !important;
                    transform: none !important;
                }

                /* Editor chrome (post title + block selection color) */
                .editor-styles-wrapper.page-home .wp-block-post-title,
                .editor-styles-wrapper.page-home .editor-post-title {
                    color: #fafaf7;
                    max-width: 1240px;
                    margin-inline: auto;
                    padding: 3rem clamp(1.25rem, 5vw, 3.5rem) 0;
                    font-family: 'Geist', sans-serif;
                }
                .editor-styles-wrapper.page-home .block-editor-block-list__block.is-selected {
                    outline-color: #f5b544 !important;
                }
            ",
        );

        return $settings;
    }, 20, 2 );

    /**
     * Enqueue a tiny editor JS that adds `home-page` + `page-home` classes
     * to the editor iframe body, so our home.css selectors cascade.
     */
    add_action( 'enqueue_block_editor_assets', function () {
        if ( ! firefly_editor_is_home_context() ) return;

        $handle = 'firefly-editor-home-preview';
        wp_register_script( $handle, '', array( 'wp-dom-ready', 'wp-data' ), null, true );
        wp_enqueue_script( $handle );

        $inline = <<<'JS'
            ( function ( wp ) {
                if ( ! wp || ! wp.domReady ) return;
                wp.domReady( function () {
                    var tries = 0;
                    function apply () {
                        tries++;
                        // Iframed editor (modern Gutenberg)
                        var iframe = document.querySelector( 'iframe[name="editor-canvas"]' );
                        if ( iframe && iframe.contentDocument && iframe.contentDocument.body ) {
                            iframe.contentDocument.body.classList.add( 'page-home', 'home-page' );
                        }
                        // Non-iframed fallback
                        document.querySelectorAll( '.editor-styles-wrapper' ).forEach( function ( el ) {
                            el.classList.add( 'page-home', 'home-page' );
                        } );
                        if ( tries < 10 ) setTimeout( apply, 400 );
                    }
                    apply();
                } );
            } )( window.wp );
JS;
        wp_add_inline_script( $handle, $inline );
    } );
