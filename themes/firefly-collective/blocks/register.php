<?php
    /**
     * theme/blocks/register.php
     *
     * Auto-registers every subdirectory of /blocks that contains a block.json.
     * Each block is defined by:
     *   block.json   — metadata, attributes, editor script, render callback
     *   index.js     — editor UI (registerBlockType + edit function)
     *   render.php   — server-side render output (matches frontend HTML)
     *
     * Server-side rendering means blocks save with `save: () => null` and the
     * HTML comes from render.php, guaranteeing editor and frontend markup are
     * identical and our home.css works in both contexts.
     */

    if ( ! defined( 'ABSPATH' ) ) exit;

    /**
     * Register a custom "Firefly" block category so our blocks group together
     * in the inserter.
     */
    add_filter( 'block_categories_all', function ( $categories ) {
        return array_merge(
            array(
                array(
                    'slug'  => 'firefly',
                    'title' => __( 'Firefly', 'firefly-collective' ),
                    'icon'  => null,
                ),
            ),
            $categories
        );
    }, 10, 1 );

    /**
     * Walk /blocks and register any subdirectory containing a block.json.
     */
    add_action( 'init', function () {
        $blocks_dir = get_template_directory() . '/blocks';
        if ( ! is_dir( $blocks_dir ) ) return;

        $entries = glob( $blocks_dir . '/*', GLOB_ONLYDIR );
        if ( ! $entries ) return;

        foreach ( $entries as $dir ) {
            if ( file_exists( $dir . '/block.json' ) ) {
                register_block_type( $dir );
            }
        }
    }, 20 );

    /**
     * Inject shared HTML into the editor for blocks whose edit() needs to
     * mirror render.php exactly (triple-panel, cli-terminal). This avoids
     * duplicating the markup across PHP and JS.
     */
    add_action( 'enqueue_block_editor_assets', function () {
        $blocks_dir = get_template_directory() . '/blocks';

        $html = array();

        $triple = $blocks_dir . '/triple-panel/_html.php';
        if ( file_exists( $triple ) ) {
            require_once $triple;
            if ( function_exists( 'firefly_triple_panel_html' ) ) {
                $html['triplePanel'] = firefly_triple_panel_html();
            }
        }

        $cli = $blocks_dir . '/cli-terminal/_html.php';
        if ( file_exists( $cli ) ) {
            require_once $cli;
            if ( function_exists( 'firefly_cli_terminal_html' ) ) {
                $html['cliTerminal'] = firefly_cli_terminal_html();
            }
        }

        if ( empty( $html ) ) return;

        // Piggyback on wp-blocks which is loaded in every block editor.
        wp_add_inline_script(
            'wp-blocks',
            'window.fireflyBlockHtml = ' . wp_json_encode( $html ) . ';',
            'before'
        );
    } );
