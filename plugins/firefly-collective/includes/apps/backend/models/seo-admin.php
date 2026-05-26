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
        'facebook' => array(
            'app_id' => '',
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

    $sections = array( 'defaults', 'twitter', 'facebook', 'verification', 'robots' );
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

    $sections = array( 'defaults', 'twitter', 'facebook', 'verification', 'robots' );
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
        // title_separator preserves whitespace (so " | " keeps its spaces).
        // strip_tags + UTF-8 check is enough hardening; trimming would
        // corrupt the user's intent for separators like " · " or " — ".
        $sep_raw = isset( $config['defaults']['title_separator'] ) ? (string) $config['defaults']['title_separator'] : ' - ';
        $sep_clean = wp_check_invalid_utf8( strip_tags( $sep_raw ) );
        // Cap length to prevent abuse — separators are tiny by nature.
        if ( strlen( $sep_clean ) > 12 ) {
            $sep_clean = substr( $sep_clean, 0, 12 );
        }
        $clean['defaults'] = array(
            'og_image_url'    => isset( $config['defaults']['og_image_url'] ) ? esc_url_raw( $config['defaults']['og_image_url'] ) : '',
            'title_separator' => $sep_clean,
        );
    }
    if ( isset( $config['twitter'] ) && is_array( $config['twitter'] ) ) {
        $clean['twitter'] = array(
            'site_handle'    => isset( $config['twitter']['site_handle'] )    ? sanitize_text_field( $config['twitter']['site_handle'] )    : '',
            'creator_handle' => isset( $config['twitter']['creator_handle'] ) ? sanitize_text_field( $config['twitter']['creator_handle'] ) : '',
            'card_type'      => isset( $config['twitter']['card_type'] )      ? sanitize_text_field( $config['twitter']['card_type'] )      : 'summary_large_image',
        );
    }
    if ( isset( $config['facebook'] ) && is_array( $config['facebook'] ) ) {
        // fb:app_id is a 16-digit numeric string from FB. Keep it as a
        // plain string so leading zeros (if any) survive — sanitize as text.
        $clean['facebook'] = array(
            'app_id' => isset( $config['facebook']['app_id'] ) ? sanitize_text_field( $config['facebook']['app_id'] ) : '',
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

    // Bulk per-page SEO sync: gather every page/post's _seo_* postmeta in
    // the active template and push the bundle to the remote in one shot.
    // Cheaper than running the full page-sync per post.
    register_rest_route( 'firefly-collective/v1', '/seo/sync-pages', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_collective_rest_seo_sync_pages',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
    ) );

    register_rest_route( 'firefly-collective/v1', '/seo/receive-pages', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_collective_rest_seo_receive_pages',
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
    // Accept both shapes:
    //   { config: { defaults: {...}, twitter: {...}, ... } }   ← wrapped
    //   { defaults: {...}, twitter: {...}, ... }               ← direct (matches GEO save)
    if ( isset( $params['config'] ) && is_array( $params['config'] ) ) {
        $config = $params['config'];
    } else {
        $config = is_array( $params ) ? $params : array();
    }
    $clean = firefly_collective_sanitize_seo_config( $config );

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

/* ---------------------------------------------------------------------------
 * Bulk per-page SEO sync — pushes every post's _seo_* meta in one payload.
 * Lighter than running full page-sync per post: no Gutenberg block content,
 * no media attachments, just the meta keys identified by _firefly_page_id.
 *
 * The SEO meta keys list mirrors what seo-post.php registers + what
 * page-sync.php whitelists. _seo_og_image_id is shipped as an integer; it
 * references local attachment ids and won't auto-translate cross-env (same
 * known limitation as full page-sync).
 * ------------------------------------------------------------------------ */

/**
 * The exact set of postmeta keys that constitute "per-page SEO".
 * Single source of truth used by both the gather (sync-pages) and
 * apply (receive-pages) sides.
 */
function firefly_collective_seo_meta_keys() {
    return array(
        '_seo_title',
        '_seo_description',
        '_seo_canonical',
        '_seo_robots_noindex',
        '_seo_robots_nofollow',
        '_seo_og_image_id',
        '_seo_og_title',
        '_seo_og_description',
    );
}

/**
 * REST: POST /seo/sync-pages — local-side bulk gather + push.
 *
 * Iterates pages + posts in the active template's scope and collects each
 * one's _seo_* meta into a single payload keyed by _firefly_page_id, then
 * POSTs to the remote's /seo/receive-pages with shared-secret auth.
 */
function firefly_collective_rest_seo_sync_pages( WP_REST_Request $request ) {
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

    // Resolve active template — the same one the rest of the sync pipeline uses.
    $template = function_exists( 'firefly_get_scoping_template' ) ? firefly_get_scoping_template() : 'default';

    $posts = get_posts( array(
        'post_type'            => array( 'page', 'post' ),
        'post_status'          => 'publish',
        'numberposts'          => -1,
        'meta_key'             => '_firefly_template',
        'meta_value'           => $template,
        'firefly_skip_scoping' => true,
    ) );

    $meta_keys = firefly_collective_seo_meta_keys();
    $pages     = array();
    $skipped   = array();

    foreach ( $posts as $p ) {
        $fpid = get_post_meta( $p->ID, '_firefly_page_id', true );
        if ( ! $fpid ) {
            $skipped[] = $p->post_name . ' (no _firefly_page_id)';
            continue;
        }
        $meta = array();
        foreach ( $meta_keys as $key ) {
            $meta[ $key ] = get_post_meta( $p->ID, $key, true );
        }
        $pages[] = array(
            'firefly_page_id' => (string) $fpid,
            'post_type'       => $p->post_type,
            'slug'            => $p->post_name,
            'meta'            => $meta,
        );
    }

    if ( empty( $pages ) ) {
        return new WP_REST_Response( array(
            'success'   => false,
            'message'   => 'No pages with _firefly_page_id found in template "' . $template . '". Sync from the Pages list once so each page gets a stable cross-env id.',
            'skipped'   => $skipped,
        ), 400 );
    }

    if ( preg_match( '/(https?:\/\/[^\/]+)/', $project_endpoint, $matches ) ) {
        $base_url = $matches[1];
        $endpoint = $base_url . '/wp-json/firefly-collective/v1/seo/receive-pages';
    } else {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid endpoint URL format.' ), 400 );
    }

    $response = wp_remote_post( $endpoint, array(
        'timeout' => 60, // bulk payload — give it room
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'secret' => FIREFLY_SHARED_SECRET,
            'pages'  => $pages,
        ) ),
    ) );

    if ( is_wp_error( $response ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Failed to connect: ' . $response->get_error_message() ), 500 );
    }
    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $body['success'] ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => isset( $body['message'] ) ? $body['message'] : ( 'Remote returned HTTP ' . $code ),
        ), $code === 200 ? 500 : $code );
    }

    $env_name = ( $target_env === 'prod' ) ? 'Production' : 'Live Dev';
    return new WP_REST_Response( array(
        'success'        => true,
        'message'        => sprintf( 'Synced SEO meta for %d page(s) to %s.', count( $pages ), $env_name ),
        'pages_sent'     => count( $pages ),
        'pages_applied'  => isset( $body['applied'] ) ? (int) $body['applied'] : null,
        'skipped'        => $skipped,
        'remote_message' => isset( $body['message'] ) ? $body['message'] : null,
    ), 200 );
}

/**
 * REST: POST /seo/receive-pages — remote-side bulk apply.
 *
 * For every entry in `pages[]`, look up the local post via _firefly_page_id
 * and write each _seo_* meta key. Returns applied + skipped counts.
 */
function firefly_collective_rest_seo_receive_pages( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    if ( ! defined( 'FIREFLY_SHARED_SECRET' ) || empty( FIREFLY_SHARED_SECRET ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Shared secret not configured on receiving server.' ), 500 );
    }
    $received_secret = isset( $params['secret'] ) ? $params['secret'] : '';
    if ( $received_secret !== FIREFLY_SHARED_SECRET ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid shared secret.' ), 403 );
    }

    $pages = isset( $params['pages'] ) && is_array( $params['pages'] ) ? $params['pages'] : array();
    if ( empty( $pages ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'No pages in payload.' ), 400 );
    }

    $meta_keys = firefly_collective_seo_meta_keys();
    $applied   = 0;
    $not_found = array();

    foreach ( $pages as $entry ) {
        $fpid = isset( $entry['firefly_page_id'] ) ? (string) $entry['firefly_page_id'] : '';
        if ( $fpid === '' || ! isset( $entry['meta'] ) || ! is_array( $entry['meta'] ) ) {
            continue;
        }

        // Resolve by stable cross-env id.
        $found = get_posts( array(
            'post_type'            => array( 'page', 'post' ),
            'post_status'          => 'any',
            'numberposts'          => 1,
            'meta_key'             => '_firefly_page_id',
            'meta_value'           => $fpid,
            'firefly_skip_scoping' => true,
        ) );
        if ( empty( $found ) ) {
            $not_found[] = $fpid;
            continue;
        }
        $post_id = (int) $found[0]->ID;

        // Apply each known SEO meta key. Per-key sanitization mirrors
        // seo-post.php's register_post_meta sanitize_callback choices.
        foreach ( $meta_keys as $key ) {
            if ( ! array_key_exists( $key, $entry['meta'] ) ) continue;
            $val = $entry['meta'][ $key ];
            switch ( $key ) {
                case '_seo_canonical':
                    $val = esc_url_raw( (string) $val );
                    break;
                case '_seo_robots_noindex':
                case '_seo_robots_nofollow':
                    $val = (bool) $val;
                    break;
                case '_seo_og_image_id':
                    $val = (int) $val;
                    break;
                default:
                    $val = sanitize_text_field( (string) $val );
            }
            update_post_meta( $post_id, $key, $val );
        }
        $applied++;
    }

    return new WP_REST_Response( array(
        'success'   => true,
        'message'   => sprintf( 'Applied SEO meta to %d page(s); %d not found on this site.', $applied, count( $not_found ) ),
        'applied'   => $applied,
        'not_found' => $not_found,
    ), 200 );
}
