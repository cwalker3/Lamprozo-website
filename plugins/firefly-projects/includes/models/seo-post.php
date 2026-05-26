<?php
/**
 * Firefly Projects — SEO post meta registration.
 *
 * Parallel to geo-post.php. Registers 8 per-page SEO meta keys with show_in_rest
 * so the Gutenberg sidebar can read/write them via wp.data.dispatch('core/editor').
 * The frontend resolution + wp_head output happens in the theme's seo-meta.php
 * and seo-schema.php — this file is just registration + sanitization.
 *
 * Override resolution is consumer-side; this file only validates inputs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register all SEO meta keys on both 'page' and 'post' post types.
 */
function firefly_projects_register_seo_meta() {
    $post_types = array( 'page', 'post' );

    $auth_can_edit = function () { return current_user_can( 'edit_posts' ); };

    // String overrides — single line / textarea inputs in the sidebar.
    $string_keys = array(
        '_seo_title',
        '_seo_description',
        '_seo_og_title',
        '_seo_og_description',
    );
    foreach ( $post_types as $post_type ) {
        foreach ( $string_keys as $key ) {
            register_post_meta( $post_type, $key, array(
                'show_in_rest'      => true,
                'single'            => true,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => $auth_can_edit,
            ) );
        }

        // Canonical URL — sanitize as URL.
        register_post_meta( $post_type, '_seo_canonical', array(
            'show_in_rest'      => true,
            'single'            => true,
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
            'auth_callback'     => $auth_can_edit,
        ) );

        // Robots toggles — booleans.
        foreach ( array( '_seo_robots_noindex', '_seo_robots_nofollow' ) as $bool_key ) {
            register_post_meta( $post_type, $bool_key, array(
                'show_in_rest'      => true,
                'single'            => true,
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => function ( $v ) { return (bool) $v; },
                'auth_callback'     => $auth_can_edit,
            ) );
        }

        // OG image override — attachment id (int).
        register_post_meta( $post_type, '_seo_og_image_id', array(
            'show_in_rest'      => true,
            'single'            => true,
            'type'              => 'integer',
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth_can_edit,
        ) );
    }
}
add_action( 'init', 'firefly_projects_register_seo_meta' );
