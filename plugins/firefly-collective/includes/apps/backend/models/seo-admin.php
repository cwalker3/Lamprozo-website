<?php
/**
 * SEO Admin — site-wide SEO defaults storage + sync.
 *
 * Parallel to geo-admin.php:
 *   - Stores config in wp_ffc_seo_config (key/value rows, one per top-level section)
 *   - Migrates from includes/apps/backend/seo-config.json on first run
 *   - Exposes REST endpoints under firefly-collective/v1/seo/* :
 *       GET  /seo/config       — read current config
 *       POST /seo/config       — save config
 *       POST /seo/reset        — restore defaults
 *       POST /seo/sync         — push this site's config to remote env
 *       POST /seo/receive      — accept config from a local-dev push
 *
 * No separate admin menu page — the existing GEO menu (now labeled
 * "SEO/GEO") hosts the SEO form section alongside GEO sections.
 *
 * The actual <meta> emission for these config values lives in the theme's
 * seo-meta.php + seo-schema.php; this file is storage + transport only.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Default config — used when no DB rows exist or the JSON file is missing.
 */
function firefly_seo_get_default_config() {
    return array(
        'defaults' => array(
            'og_image_url'    => '',
            'title_separator' => ' - ',
        ),
        'twitter' => array(
            'site_handle'    => '',
            'creator_handle' => '',
            'card_type'      => 'summary_large_image',
        ),
        'verification' => array(
            'google'    => '',
            'bing'      => '',
            'yandex'    => '',
            'pinterest' => '',
            'baidu'     => '',
        ),
        'robots' => array(
            'default_index'  => true,
            'default_follow' => true,
        ),
    );
}

/**
 * Create the SEO config table.
 */
function firefly_collective_create_seo_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_seo_config';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        config_key VARCHAR(50) NOT NULL,
        config_value LONGTEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY config_key (config_key)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * One-shot init + migration. Runs on admin_init to be safe; cheap when
 * already initialized (single COUNT(*) query).
 */
function firefly_collective_init_seo() {
    firefly_collective_create_seo_table();
    firefly_collective_migrate_seo_config();
}
add_action( 'admin_init', 'firefly_collective_init_seo' );

/**
 * Seed the DB from seo-config.json on first run. Idempotent.
 */
function firefly_collective_migrate_seo_config() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_seo_config';

    $existing = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
    if ( $existing > 0 ) {
        return;
    }

    $json_path = plugin_dir_path( dirname( __FILE__ ) ) . 'seo-config.json';
    if ( file_exists( $json_path ) ) {
        $json_content = file_get_contents( $json_path );
        $config = json_decode( $json_content, true );
        if ( ! $config ) {
            $config = firefly_seo_get_default_config();
        }
    } else {
        $config = firefly_seo_get_default_config();
    }

    $sections = array( 'defaults', 'twitter', 'verification', 'robots' );
    foreach ( $sections as $section ) {
        if ( isset( $config[ $section ] ) ) {
            $wpdb->replace(
                $table_name,
                array(
                    'config_key'   => $section,
                    'config_value' => wp_json_encode( $config[ $section ] ),
                ),
                array( '%s', '%s' )
            );
        }
    }
}

/**
 * Read the merged SEO config (DB + defaults fallback).
 *
 * Returns the full nested structure. Used by both REST handlers and the
 * theme's wp_head SEO functions (default OG image, twitter:site, etc.).
 */
function firefly_collective_get_seo_config() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_seo_config';

    // Suppress errors so frontend rendering doesn't fail if the table hasn't
    // been created yet (e.g. plugin activated but admin_init hasn't fired).
    $results = $wpdb->get_results(
        "SELECT config_key, config_value FROM $table_name",
        ARRAY_A
    );

    if ( empty( $results ) ) {
        return firefly_seo_get_default_config();
    }

    $config = array();
    foreach ( $results as $row ) {
        $config[ $row['config_key'] ] = json_decode( $row['config_value'], true );
    }

    // Merge with defaults so every consumer can rely on the full shape.
    $defaults = firefly_seo_get_default_config();
    foreach ( $defaults as $key => $value ) {
        if ( ! isset( $config[ $key ] ) ) {
            $config[ $key ] = $value;
        } elseif ( is_array( $value ) ) {
            // Per-section merge so partial saves don't lose default sub-keys.
            $config[ $key ] = array_merge( $value, $config[ $key ] );
        }
    }

    return $config;
}

/**
 * Persist config to DB. Accepts a partial or full nested array; missing
 * sections are left untouched (no destructive overwrite).
 */
function firefly_collective_save_seo_config( $config ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_seo_config';

    $sections = array( 'defaults', 'twitter', 'verification', 'robots' );
    $all_ok   = true;
    foreach ( $sections as $section ) {
        if ( isset( $config[ $section ] ) ) {
            $result = $wpdb->replace(
                $table_name,
                array(
                    'config_key'   => $section,
                    'config_value' => wp_json_encode( $config[ $section ] ),
                ),
                array( '%s', '%s' )
            );
            if ( $result === false ) {
                $all_ok = false;
            }
        }
    }
    return $all_ok;
}

/**
 * Sanitize an incoming config payload before writing. Strict per-field.
 */
function firefly_collective_sanitize_seo_config( $config ) {
    if ( ! is_array( $config ) ) {
        return array();
    }
    $clean = array();

    if ( isset( $config['defaults'] ) && is_array( $config['defaults'] ) ) {
        $clean['defaults'] = array(
            'og_image_url'    => isset( $config['defaults']['og_image_url'] )    ? esc_url_raw( $config['defaults']['og_image_url'] )       : '',
            'title_separator' => isset( $config['defaults']['title_separator'] ) ? sanitize_text_field( $config['defaults']['title_separator'] ) : ' - ',
        );
    }
    if ( isset( $config['twitter'] ) && is_array( $config['twitter'] ) ) {
        $clean['twitter'] = array(
            'site_handle'    => isset( $config['twitter']['site_handle'] )    ? sanitize_text_field( $config['twitter']['site_handle'] )    : '',
            'creator_handle' => isset( $config['twitter']['creator_handle'] ) ? sanitize_text_field( $config['twitter']['creator_handle'] ) : '',
            'card_type'      => isset( $config['twitter']['card_type'] )      ? sanitize_text_field( $config['twitter']['card_type'] )      : 'summary_large_image',
        );
    }
    if ( isset( $config['verification'] ) && is_array( $config['verification'] ) ) {
        $clean['verification'] = array();
        foreach ( array( 'google', 'bing', 'yandex', 'pinterest', 'baidu' ) as $key ) {
            $clean['verification'][ $key ] = isset( $config['verification'][ $key ] ) ? sanitize_text_field( $config['verification'][ $key ] ) : '';
        }
    }
    if ( isset( $config['robots'] ) && is_array( $config['robots'] ) ) {
        $clean['robots'] = array(
            'default_index'  => ! empty( $config['robots']['default_index'] ),
            'default_follow' => ! empty( $config['robots']['default_follow'] ),
        );
    }
    return $clean;
}

/* ---------------------------------------------------------------------------
 * REST endpoints — mirror /geo/* exactly
 * ------------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {
    register_rest_route( 'firefly-collective/v1', '/seo/config', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_rest_get_seo_config',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_rest_save_seo_config',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ),
    ) );

    register_rest_route( 'firefly-collective/v1', '/seo/reset', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_collective_rest_reset_seo_config',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
    ) );

    register_rest_route( 'firefly-collective/v1', '/seo/sync', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_collective_rest_seo_sync',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
    ) );

    // Public endpoint — auth via shared secret in the request body, not
    // via WP user. Same pattern as /geo/receive.
    register_rest_route( 'firefly-collective/v1', '/seo/receive', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_collective_rest_seo_receive',
        'permission_callback' => '__return_true',
    ) );
} );

function firefly_collective_rest_get_seo_config( WP_REST_Request $request ) {
    return new WP_REST_Response( array(
        'success' => true,
        'config'  => firefly_collective_get_seo_config(),
    ), 200 );
}

function firefly_collective_rest_save_seo_config( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    $config = isset( $params['config'] ) ? $params['config'] : array();
    $clean  = firefly_collective_sanitize_seo_config( $config );

    if ( empty( $clean ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'No valid config sections provided.',
        ), 400 );
    }

    $result = firefly_collective_save_seo_config( $clean );
    if ( ! $result ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Failed to save SEO config.',
        ), 500 );
    }

    return new WP_REST_Response( array(
        'success' => true,
        'message' => 'SEO config saved.',
        'config'  => firefly_collective_get_seo_config(),
    ), 200 );
}

function firefly_collective_rest_reset_seo_config( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_seo_config';
    $wpdb->query( "TRUNCATE TABLE $table_name" );
    firefly_collective_migrate_seo_config();
    return new WP_REST_Response( array(
        'success' => true,
        'message' => 'SEO config reset to defaults.',
        'config'  => firefly_collective_get_seo_config(),
    ), 200 );
}

/**
 * Push this site's SEO config to the remote env's /seo/receive.
 * Mirrors firefly_collective_rest_geo_sync byte-for-byte.
 */
function firefly_collective_rest_seo_sync( WP_REST_Request $request ) {
    $params     = $request->get_json_params();
    $target_env = isset( $params['target_env'] ) ? $params['target_env'] : 'dev';

    if ( $target_env === 'prod' ) {
        if ( ! defined( 'PROD_ENDPOINT' ) || empty( PROD_ENDPOINT ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Production endpoint not configured. Set PROD_ENDPOINT in wp-config.php.' ), 400 );
        }
        $project_endpoint = PROD_ENDPOINT;
    } else {
        if ( ! defined( 'LIVE_DEV_ENDPOINT' ) || empty( LIVE_DEV_ENDPOINT ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Live Dev endpoint not configured. Set LIVE_DEV_ENDPOINT in wp-config.php.' ), 400 );
        }
        $project_endpoint = LIVE_DEV_ENDPOINT;
    }

    if ( ! defined( 'FIREFLY_SHARED_SECRET' ) || empty( FIREFLY_SHARED_SECRET ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Shared secret not configured. Set FIREFLY_SHARED_SECRET in wp-config.php.' ), 400 );
    }

    $config = firefly_collective_get_seo_config();

    if ( preg_match( '/(https?:\/\/[^\/]+)/', $project_endpoint, $matches ) ) {
        $base_url     = $matches[1];
        $seo_endpoint = $base_url . '/wp-json/firefly-collective/v1/seo/receive';
    } else {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid endpoint URL format.' ), 400 );
    }

    $payload = array(
        'secret' => FIREFLY_SHARED_SECRET,
        'config' => $config,
    );

    $response = wp_remote_post( $seo_endpoint, array(
        'timeout' => 30,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( $payload ),
    ) );

    if ( is_wp_error( $response ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Failed to connect: ' . $response->get_error_message() ), 500 );
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $response_code !== 200 ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => isset( $response_body['message'] ) ? $response_body['message'] : ( 'Remote server returned error ' . $response_code ),
        ), $response_code );
    }

    $env_name = ( $target_env === 'prod' ) ? 'Production' : 'Live Dev';
    return new WP_REST_Response( array(
        'success' => true,
        'message' => "SEO config synced to {$env_name} successfully!",
    ), 200 );
}

/**
 * Receive an incoming SEO config from a local-dev push.
 */
function firefly_collective_rest_seo_receive( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    if ( ! defined( 'FIREFLY_SHARED_SECRET' ) || empty( FIREFLY_SHARED_SECRET ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Shared secret not configured on receiving server.' ), 500 );
    }

    $received_secret = isset( $params['secret'] ) ? $params['secret'] : '';
    if ( $received_secret !== FIREFLY_SHARED_SECRET ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid shared secret.' ), 403 );
    }

    $config = isset( $params['config'] ) ? $params['config'] : null;
    if ( empty( $config ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'No configuration data provided.' ), 400 );
    }

    firefly_collective_create_seo_table();
    $config = firefly_collective_sanitize_seo_config( $config );
    $result = firefly_collective_save_seo_config( $config );

    if ( $result ) {
        return new WP_REST_Response( array( 'success' => true, 'message' => 'SEO config received and saved successfully.' ), 200 );
    }
    return new WP_REST_Response( array( 'success' => false, 'message' => 'Failed to save configuration.' ), 500 );
}
