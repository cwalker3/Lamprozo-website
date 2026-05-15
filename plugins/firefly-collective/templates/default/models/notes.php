<?php
/**
 * Notes — Per-user dictation notes. Uses firefly-ragsmith's dictation
 * primitive (Whisper transcription, no LLM round-trip).
 *
 * Surface:
 *   - firefly_note CPT (private, per-author)
 *   - wp-admin top-level "Notes" menu (admin-only by default)
 *   - REST namespace firefly-notes/v1
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const FIREFLY_NOTES_MENU_SLUG  = 'firefly-notes';
const FIREFLY_NOTES_POST_TYPE  = 'firefly_note';
const FIREFLY_NOTES_REST_NS    = 'firefly-notes/v1';

/**
 * Per-file mtime cache busting. Whenever an asset's content changes on
 * disk, its mtime updates and browsers refetch on the next request.
 * Unchanged files stay cached — no manual version bumps required.
 */
function firefly_notes_asset_version( $abs_path ) {
    return file_exists( $abs_path ) ? (string) filemtime( $abs_path ) : '0';
}

/**
 * Single source of truth for who can use the Notes feature.
 *
 * Default template gates on manage_options. Other contexts can broaden
 * (or tighten) access without forking the model file by adding handlers
 * to the `firefly_notes_can_access` filter — return true to grant.
 *
 * Example: open to a custom role.
 *   add_filter( 'firefly_notes_can_access', function ( $can ) {
 *       return $can || current_user_can( 'editor' );
 *   } );
 */
function firefly_notes_can_access() {
    $can = current_user_can( 'manage_options' );
    return (bool) apply_filters( 'firefly_notes_can_access', $can );
}

// ---------- Custom post type ----------

add_action( 'init', function () {
    register_post_type( FIREFLY_NOTES_POST_TYPE, array(
        'label'           => 'Notes',
        'public'          => false,
        'show_ui'         => false,
        'show_in_menu'    => false,
        'show_in_rest'    => false,
        'supports'        => array( 'title', 'editor', 'author' ),
        'capability_type' => 'post',
        'map_meta_cap'    => true,
    ) );
} );

// ---------- Admin menu ----------

add_action( 'admin_menu', function () {
    if ( ! firefly_notes_can_access() ) return;

    add_menu_page(
        'Notes',
        'Notes',
        'manage_options',
        FIREFLY_NOTES_MENU_SLUG,
        'firefly_notes_render_page',
        'dashicons-microphone',
        26
    );
}, 99 );

function firefly_notes_render_page() {
    if ( ! firefly_notes_can_access() ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'firefly-collective' ) );
    }
    $view = plugin_dir_path( __FILE__ ) . '../views/notes.php';
    if ( file_exists( $view ) ) {
        include $view;
    }
}

// ---------- Asset enqueue (only on this page) ----------

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'toplevel_page_' . FIREFLY_NOTES_MENU_SLUG ) return;
    if ( ! firefly_notes_can_access() ) return;

    if ( function_exists( 'firefly_ragsmith_enqueue_dictation' ) ) {
        firefly_ragsmith_enqueue_dictation();
    }

    // plugins_url(path, file) is relative to the *file's* directory,
    // so anchor it at the plugin's main file (firefly-collective/firefly.php).
    $asset_base   = plugins_url( 'templates/default/assets', WP_PLUGIN_DIR . '/firefly-collective/firefly.php' );
    $template_dir = dirname( __FILE__, 2 ); // plugins/firefly-collective/templates/default/

    wp_enqueue_style(
        'firefly-notes',
        $asset_base . '/css/notes.css',
        array(),
        firefly_notes_asset_version( $template_dir . '/assets/css/notes.css' )
    );
    wp_enqueue_script(
        'firefly-notes',
        $asset_base . '/js/notes.js',
        array(),
        firefly_notes_asset_version( $template_dir . '/assets/js/notes.js' ),
        true
    );
    wp_add_inline_script(
        'firefly-notes',
        sprintf(
            'window.FireflyNotes = window.FireflyNotes || {}; window.FireflyNotes.restUrl = %s; window.FireflyNotes.nonce = %s;',
            wp_json_encode( esc_url_raw( rest_url( FIREFLY_NOTES_REST_NS ) ) ),
            wp_json_encode( wp_create_nonce( 'wp_rest' ) )
        ),
        'before'
    );
} );

// ---------- REST routes ----------

add_action( 'rest_api_init', function () {
    $perm = function () { return firefly_notes_can_access(); };

    register_rest_route( FIREFLY_NOTES_REST_NS, '/notes', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_notes_route_list',
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_notes_route_create',
        ),
    ) );

    register_rest_route( FIREFLY_NOTES_REST_NS, '/notes/(?P<id>\d+)', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_notes_route_get',
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_notes_route_delete',
        ),
    ) );

    register_rest_route( FIREFLY_NOTES_REST_NS, '/notes/(?P<id>\d+)', array(
        'methods'             => WP_REST_Server::EDITABLE,  // POST/PUT/PATCH
        'permission_callback' => $perm,
        'callback'            => 'firefly_notes_route_update',
    ) );
} );

/**
 * Owner check used by every per-note route. Returns the post or a WP_Error.
 */
function firefly_notes_owned_post( $id ) {
    $post = get_post( (int) $id );
    if ( ! $post || $post->post_type !== FIREFLY_NOTES_POST_TYPE ) {
        return new WP_Error( 'firefly_notes_not_found', 'Note not found.', array( 'status' => 404 ) );
    }
    if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'firefly_notes_forbidden', 'Not your note.', array( 'status' => 403 ) );
    }
    return $post;
}

function firefly_notes_format( WP_Post $post ) {
    return array(
        'id'       => (int) $post->ID,
        'title'    => $post->post_title !== '' ? $post->post_title : 'Untitled',
        'content'  => (string) $post->post_content,
        'modified' => mysql2date( 'c', $post->post_modified ),
        'created'  => mysql2date( 'c', $post->post_date ),
    );
}

function firefly_notes_route_list( WP_REST_Request $req ) {
    $q = new WP_Query( array(
        'post_type'      => FIREFLY_NOTES_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'author'         => get_current_user_id(),
        'posts_per_page' => 200,
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ) );

    $items = array();
    foreach ( $q->posts as $post ) {
        $items[] = array(
            'id'       => (int) $post->ID,
            'title'    => $post->post_title !== '' ? $post->post_title : 'Untitled',
            'modified' => mysql2date( 'c', $post->post_modified ),
        );
    }
    return rest_ensure_response( array( 'notes' => $items ) );
}

function firefly_notes_route_create( WP_REST_Request $req ) {
    $title = sprintf( 'Untitled — %s', wp_date( 'M j, Y g:i A' ) );
    $id = wp_insert_post( array(
        'post_type'    => FIREFLY_NOTES_POST_TYPE,
        'post_status'  => 'publish',
        'post_author'  => get_current_user_id(),
        'post_title'   => $title,
        'post_content' => '',
    ), true );
    if ( is_wp_error( $id ) ) return $id;
    return rest_ensure_response( firefly_notes_format( get_post( $id ) ) );
}

function firefly_notes_route_get( WP_REST_Request $req ) {
    $post = firefly_notes_owned_post( $req['id'] );
    if ( is_wp_error( $post ) ) return $post;
    return rest_ensure_response( firefly_notes_format( $post ) );
}

function firefly_notes_route_delete( WP_REST_Request $req ) {
    $post = firefly_notes_owned_post( $req['id'] );
    if ( is_wp_error( $post ) ) return $post;
    wp_trash_post( $post->ID );
    return rest_ensure_response( array( 'deleted' => true, 'id' => (int) $post->ID ) );
}

/**
 * Partial update: accepts {title?, content?}. Only the keys present are written.
 * Title is sanitized; content is stored as-is (plain text dictation transcript).
 */
function firefly_notes_route_update( WP_REST_Request $req ) {
    $post = firefly_notes_owned_post( $req['id'] );
    if ( is_wp_error( $post ) ) return $post;

    $body   = $req->get_json_params() ?: array();
    $update = array( 'ID' => $post->ID );

    if ( array_key_exists( 'title', $body ) ) {
        $title = sanitize_text_field( (string) $body['title'] );
        $update['post_title'] = $title !== '' ? $title : 'Untitled';
    }
    if ( array_key_exists( 'content', $body ) ) {
        // Plain-text dictation transcript; strip tags defensively but preserve newlines.
        $update['post_content'] = wp_check_invalid_utf8( wp_strip_all_tags( (string) $body['content'], false ) );
    }

    if ( count( $update ) > 1 ) {
        wp_update_post( $update );
        clean_post_cache( $post->ID );
    }
    return rest_ensure_response( firefly_notes_format( get_post( $post->ID ) ) );
}
