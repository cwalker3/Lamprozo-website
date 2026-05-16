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

// Per-note meta:
//   _firefly_note_session_id — the browser-local session uuid the note was
//   created under. Lets us bulk-delete notes when their owning session is
//   deleted from the picker.
//   _firefly_note_messages — JSON-serialized list of {session_id, message_id}
//   pairs identifying each Ragsmith dictation message this note produced.
//   Iterated on note delete so the Ragsmith conversation history stays in
//   sync with the WP side.
const FIREFLY_NOTES_META_SESSION  = '_firefly_note_session_id';
const FIREFLY_NOTES_META_MESSAGES = '_firefly_note_messages';

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
 * Default template gates on manage_options; flip to is_user_logged_in()
 * (or any custom cap) to open it up.
 */
function firefly_notes_can_access() {
    return current_user_can( 'manage_options' );
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

    // Record a single Ragsmith dictation message id against a note so the
    // delete cascade can remove it from Ragsmith later. Idempotent — duplicate
    // message ids are dropped on append.
    register_rest_route( FIREFLY_NOTES_REST_NS, '/notes/(?P<id>\d+)/messages', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_notes_route_append_message',
    ) );

    // Session-scoped operations: count + bulk delete notes carrying a given
    // browser-local session_id meta. Used by the session picker's delete flow.
    register_rest_route( FIREFLY_NOTES_REST_NS, '/sessions/(?P<sid>[A-Za-z0-9_\-]+)/notes', array(
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_notes_route_delete_session_notes',
        ),
    ) );
    register_rest_route( FIREFLY_NOTES_REST_NS, '/sessions/(?P<sid>[A-Za-z0-9_\-]+)/notes/count', array(
        'methods'             => WP_REST_Server::READABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_notes_route_count_session_notes',
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
    // Surface the Ragsmith message refs so the client can tell whether this
    // note has been pushed to AI (and, if so, which message to PUT against
    // on subsequent saves). The meta stores a JSON-encoded list — invalid
    // or missing data falls back to an empty array.
    $raw_messages = get_post_meta( $post->ID, FIREFLY_NOTES_META_MESSAGES, true );
    $messages = array();
    if ( is_string( $raw_messages ) && $raw_messages !== '' ) {
        $decoded = json_decode( $raw_messages, true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $row ) {
                if ( isset( $row['session_id'], $row['message_id'] ) ) {
                    $messages[] = array(
                        'session_id' => (string) $row['session_id'],
                        'message_id' => (string) $row['message_id'],
                    );
                }
            }
        }
    }
    return array(
        'id'       => (int) $post->ID,
        'title'    => $post->post_title !== '' ? $post->post_title : 'Untitled',
        'content'  => (string) $post->post_content,
        'modified' => mysql2date( 'c', $post->post_modified ),
        'created'  => mysql2date( 'c', $post->post_date ),
        'messages' => $messages,
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
    $body  = $req->get_json_params() ?: array();
    // Default to America/Los_Angeles so the timestamp in the title reads as
    // PST/PDT regardless of how WordPress's site-wide timezone is set (the
    // host server often defaults to UTC). Falls back to wp_date()'s default
    // if the timezone class isn't available for some reason.
    try {
        $tz    = new DateTimeZone( 'America/Los_Angeles' );
        $stamp = wp_date( 'M j, Y g:i A', null, $tz );
    } catch ( Exception $e ) {
        $stamp = wp_date( 'M j, Y g:i A' );
    }
    $title = sprintf( 'Untitled — %s', $stamp );
    $id = wp_insert_post( array(
        'post_type'    => FIREFLY_NOTES_POST_TYPE,
        'post_status'  => 'publish',
        'post_author'  => get_current_user_id(),
        'post_title'   => $title,
        'post_content' => '',
    ), true );
    if ( is_wp_error( $id ) ) return $id;

    // Stamp the creating browser session_id so delete-session can find this
    // note later. We accept any non-empty string — the client owns the uuid
    // shape; we don't need to validate it here.
    if ( ! empty( $body['session_id'] ) && is_string( $body['session_id'] ) ) {
        update_post_meta( $id, FIREFLY_NOTES_META_SESSION, sanitize_text_field( $body['session_id'] ) );
    }
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
    // Tear down the matching Ragsmith messages before trashing the post so
    // the conversation history stays in sync. Best-effort — a Ragsmith hop
    // failure shouldn't strand the WP note in a half-deleted state.
    firefly_notes_purge_ragsmith_messages( $post->ID );
    wp_trash_post( $post->ID );
    return rest_ensure_response( array( 'deleted' => true, 'id' => (int) $post->ID ) );
}

/**
 * POST /notes/{id}/messages — append a Ragsmith message reference.
 * Body: { session_id, message_id }. Both required. Idempotent: appending
 * an existing pair is a no-op so retries don't bloat the meta.
 */
function firefly_notes_route_append_message( WP_REST_Request $req ) {
    $post = firefly_notes_owned_post( $req['id'] );
    if ( is_wp_error( $post ) ) return $post;

    $body       = $req->get_json_params() ?: array();
    $session_id = isset( $body['session_id'] ) ? sanitize_text_field( (string) $body['session_id'] ) : '';
    $message_id = isset( $body['message_id'] ) ? (string) $body['message_id'] : '';

    if ( $session_id === '' || $message_id === '' ) {
        return new WP_Error( 'firefly_notes_bad_request', 'session_id and message_id are required.', array( 'status' => 400 ) );
    }

    $existing = get_post_meta( $post->ID, FIREFLY_NOTES_META_MESSAGES, true );
    $list     = is_string( $existing ) && $existing !== '' ? json_decode( $existing, true ) : array();
    if ( ! is_array( $list ) ) $list = array();

    foreach ( $list as $row ) {
        if ( isset( $row['session_id'], $row['message_id'] )
             && $row['session_id'] === $session_id
             && (string) $row['message_id'] === $message_id ) {
            return rest_ensure_response( array( 'ok' => true, 'duplicate' => true ) );
        }
    }
    $list[] = array( 'session_id' => $session_id, 'message_id' => $message_id );
    update_post_meta( $post->ID, FIREFLY_NOTES_META_MESSAGES, wp_json_encode( $list ) );
    return rest_ensure_response( array( 'ok' => true, 'count' => count( $list ) ) );
}

/**
 * GET /sessions/{sid}/notes/count — how many notes carry this local session_id.
 * The notes UI calls this to show "this will delete N notes" in the confirm.
 */
function firefly_notes_route_count_session_notes( WP_REST_Request $req ) {
    $sid = sanitize_text_field( (string) $req['sid'] );
    $ids = firefly_notes_ids_for_local_session( $sid );
    return rest_ensure_response( array( 'count' => count( $ids ) ) );
}

/**
 * DELETE /sessions/{sid}/notes — bulk-trash every note tagged with this
 * browser-local session_id. Each note's Ragsmith messages are torn down
 * via purge_ragsmith_messages before the post is trashed.
 */
function firefly_notes_route_delete_session_notes( WP_REST_Request $req ) {
    $sid = sanitize_text_field( (string) $req['sid'] );
    $ids = firefly_notes_ids_for_local_session( $sid );
    $deleted = array();
    foreach ( $ids as $id ) {
        // Ownership re-check per row — keeps multi-author setups honest.
        $post = firefly_notes_owned_post( $id );
        if ( is_wp_error( $post ) ) continue;
        firefly_notes_purge_ragsmith_messages( $post->ID );
        wp_trash_post( $post->ID );
        $deleted[] = (int) $post->ID;
    }
    return rest_ensure_response( array( 'deleted' => $deleted, 'count' => count( $deleted ) ) );
}

/**
 * Return note IDs whose _firefly_note_session_id meta matches $sid,
 * scoped to the current user (admins see everything).
 */
function firefly_notes_ids_for_local_session( $sid ) {
    if ( $sid === '' ) return array();
    $args = array(
        'post_type'      => FIREFLY_NOTES_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 500,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_key'       => FIREFLY_NOTES_META_SESSION,
        'meta_value'     => $sid,
    );
    if ( ! current_user_can( 'manage_options' ) ) {
        $args['author'] = get_current_user_id();
    }
    $q = new WP_Query( $args );
    return array_map( 'intval', $q->posts );
}

/**
 * Delete the Ragsmith messages this note produced, then clear the meta.
 * Skips silently when the firefly-ragsmith plugin isn't installed so the
 * notes plugin remains usable standalone.
 */
function firefly_notes_purge_ragsmith_messages( $post_id ) {
    if ( ! function_exists( 'firefly_ragsmith' ) ) return;
    $raw = get_post_meta( $post_id, FIREFLY_NOTES_META_MESSAGES, true );
    if ( ! is_string( $raw ) || $raw === '' ) return;
    $list = json_decode( $raw, true );
    if ( ! is_array( $list ) ) return;

    $client = firefly_ragsmith();
    if ( ! is_object( $client ) || ! method_exists( $client, 'delete_session_message' ) ) return;

    foreach ( $list as $row ) {
        if ( empty( $row['session_id'] ) || empty( $row['message_id'] ) ) continue;
        // Errors here are non-fatal — the conversation may already be gone
        // (e.g. session deletion ran before this call). Swallow + continue.
        $client->delete_session_message( $row['session_id'], $row['message_id'] );
    }
    delete_post_meta( $post_id, FIREFLY_NOTES_META_MESSAGES );
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
