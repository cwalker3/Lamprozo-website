<?php
/**
 * Notes — Per-user dictation notes. Uses firefly-ragsmith's dictation
 * primitive (Whisper transcription, no LLM round-trip).
 *
 * Surface:
 *   - firefly_note CPT (private, per-author)            — notes
 *   - firefly_note_session CPT (private, per-author)    — sessions that own notes
 *   - wp-admin top-level "Notes" menu (admin-only by default)
 *   - REST namespace firefly-notes/v1
 *
 * Data shape:
 *   session post  (firefly_note_session) — post_title is the session label
 *     ├─ note post (firefly_note), post_parent = session id
 *     ├─ note post
 *     └─ note post
 *
 *   Active-session selection is browser-side (URL ?session=N first, then a
 *   localStorage fallback). Server is authoritative for the session list,
 *   the per-session Ragsmith conversation id, and parent-child linkage.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const FIREFLY_NOTES_MENU_SLUG          = 'firefly-notes';
const FIREFLY_NOTES_POST_TYPE          = 'firefly_note';
const FIREFLY_NOTES_SESSION_POST_TYPE  = 'firefly_note_session';
const FIREFLY_NOTES_REST_NS            = 'firefly-notes/v1';

// Per-note meta:
//   _firefly_note_messages — JSON-serialized list of {session_id, message_id}
//   pairs identifying each Ragsmith dictation message this note produced.
//   Iterated on note delete so the Ragsmith conversation history stays in
//   sync with the WP side.
//
//   _firefly_note_session_id (LEGACY) — the browser-local session uuid the
//   note was created under, before sessions became server-side first-class
//   objects. Read by the one-shot migration endpoint to re-parent legacy
//   notes onto their newly-created session post, then cleared. Kept on the
//   note temporarily so partial migrations stay recoverable; safe to delete
//   once every install has run the migration.
const FIREFLY_NOTES_META_MESSAGES       = '_firefly_note_messages';
const FIREFLY_NOTES_META_LEGACY_SESSION = '_firefly_note_session_id';

// Per-session meta:
//   _firefly_note_session_rs_id — the Ragsmith conversation_id this session
//   maps to. NULL until the first Save-to-AI lands a message; then sticky.
//   Reused by every subsequent save in the session so all of a session's
//   dictation messages live in one Ragsmith conversation.
const FIREFLY_NOTES_META_SESSION_RS_ID = '_firefly_note_session_rs_id';

// Marks the auto-created "Default" session per user, used by the orphan-
// adoption fallback. Find-or-create keys off this meta instead of the title
// so a user-renamed default still gets reused.
const FIREFLY_NOTES_META_DEFAULT_SESSION = '_firefly_note_default_session';

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

// ---------- Custom post types ----------

add_action( 'init', function () {
    // Note CPT. Now also declares hierarchical=true so post_parent links a
    // note to its owning session post. The Notes admin UI is the only place
    // that surfaces these, so all show_* / public flags stay off.
    register_post_type( FIREFLY_NOTES_POST_TYPE, array(
        'label'           => 'Notes',
        'public'          => false,
        'show_ui'         => false,
        'show_in_menu'    => false,
        'show_in_rest'    => false,
        'hierarchical'    => true,
        'supports'        => array( 'title', 'editor', 'author', 'page-attributes' ),
        'capability_type' => 'post',
        'map_meta_cap'    => true,
    ) );

    // Session CPT. A session is a named container that holds notes (via
    // post_parent on the note) and maps 1:1 to a Ragsmith conversation
    // (via the _firefly_note_session_rs_id meta). Kept hidden in wp-admin
    // — the Notes UI manages the full lifecycle through the session picker.
    register_post_type( FIREFLY_NOTES_SESSION_POST_TYPE, array(
        'label'           => 'Note Sessions',
        'public'          => false,
        'show_ui'         => false,
        'show_in_menu'    => false,
        'show_in_rest'    => false,
        'supports'        => array( 'title', 'author' ),
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

    // ---------- Session CRUD (server-side first-class sessions) ----------
    // GET    /sessions      — list current user's sessions (with note counts)
    // POST   /sessions      — create  body: { label? }
    // GET    /sessions/{id} — fetch one
    // PATCH  /sessions/{id} — rename  body: { label }
    // DELETE /sessions/{id} — cascade-delete session + child notes + Ragsmith
    register_rest_route( FIREFLY_NOTES_REST_NS, '/sessions', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_notes_route_list_sessions',
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_notes_route_create_session',
        ),
    ) );
    register_rest_route( FIREFLY_NOTES_REST_NS, '/sessions/(?P<id>\d+)', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_notes_route_get_session',
        ),
        array(
            'methods'             => WP_REST_Server::EDITABLE,  // POST/PUT/PATCH
            'permission_callback' => $perm,
            'callback'            => 'firefly_notes_route_update_session',
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_notes_route_delete_session',
        ),
    ) );

    // POST /sessions/{id}/ragsmith — record the Ragsmith conversation_id
    // that this session is bound to. Called by the client right after the
    // first Save-to-AI lands and Ragsmith returns a session id. Subsequent
    // calls with the same id are no-ops so retries are safe.
    register_rest_route( FIREFLY_NOTES_REST_NS, '/sessions/(?P<id>\d+)/ragsmith', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_notes_route_set_session_rs_id',
    ) );

    // POST /sessions/migrate — one-shot import of the browser's legacy
    // localStorage session list. Body: { sessions: [{ id, label,
    // ragsmithSessionId }] } where `id` is the browser uuid. For each
    // entry: create a server session post (or look up the existing one if
    // already migrated), bind its rs_session_id, then re-parent every
    // legacy-meta-tagged note onto the new session post. Idempotent —
    // safe to call repeatedly from a client that lost track of whether
    // it ran.
    register_rest_route( FIREFLY_NOTES_REST_NS, '/sessions/migrate', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_notes_route_migrate_sessions',
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

/**
 * Sister to firefly_notes_owned_post for the session CPT. Used by every
 * per-session route to verify the requester owns the session (or is admin).
 */
function firefly_notes_owned_session( $id ) {
    $post = get_post( (int) $id );
    if ( ! $post || $post->post_type !== FIREFLY_NOTES_SESSION_POST_TYPE ) {
        return new WP_Error( 'firefly_notes_session_not_found', 'Session not found.', array( 'status' => 404 ) );
    }
    if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'firefly_notes_session_forbidden', 'Not your session.', array( 'status' => 403 ) );
    }
    return $post;
}

/**
 * Shape a session post for the wire. Includes a live note_count so the
 * picker UI can show "delete will remove N notes" without a second roundtrip.
 */
function firefly_notes_format_session( WP_Post $session ) {
    $note_count = (int) ( new WP_Query( array(
        'post_type'      => FIREFLY_NOTES_POST_TYPE,
        'post_parent'    => $session->ID,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => false,
    ) ) )->found_posts;

    return array(
        'id'              => (int) $session->ID,
        'label'           => $session->post_title !== '' ? $session->post_title : 'Untitled session',
        'rs_session_id'   => (string) get_post_meta( $session->ID, FIREFLY_NOTES_META_SESSION_RS_ID, true ),
        'note_count'      => $note_count,
        'created'         => mysql2date( 'c', $session->post_date ),
        'modified'        => mysql2date( 'c', $session->post_modified ),
    );
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

/**
 * GET /notes — list current user's notes.
 *
 * Filterable by ?session=N where N is a session post id. With the parameter
 * present, only notes whose post_parent equals N are returned (the picker's
 * primary use case). Without it, every note belonging to the current user
 * is returned (admin "all notes" view).
 *
 * Returns a session_id alongside each note so a stale "all notes" client
 * can re-group locally without refetching individual records.
 */
function firefly_notes_route_list( WP_REST_Request $req ) {
    $args = array(
        'post_type'      => FIREFLY_NOTES_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'author'         => get_current_user_id(),
        'posts_per_page' => 200,
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    );
    $session_id = (int) $req->get_param( 'session' );
    if ( $session_id > 0 ) {
        $args['post_parent'] = $session_id;
    }
    $q = new WP_Query( $args );

    $items = array();
    foreach ( $q->posts as $post ) {
        $items[] = array(
            'id'         => (int) $post->ID,
            'title'      => $post->post_title !== '' ? $post->post_title : 'Untitled',
            'modified'   => mysql2date( 'c', $post->post_modified ),
            'session_id' => (int) $post->post_parent,
        );
    }
    return rest_ensure_response( array( 'notes' => $items ) );
}

/**
 * POST /notes — create a note. Body accepts:
 *   - session_id (int, required for the new flow) — the parent session post.
 *     Author of session must match request user. Without this, the create
 *     is rejected so we never end up with orphan notes.
 *
 * Legacy: callers that still send a browser-uuid string session_id will
 * have that recorded as FIREFLY_NOTES_META_LEGACY_SESSION so the migration
 * endpoint can re-parent them later. This path will be removed once every
 * install has migrated.
 */
function firefly_notes_route_create( WP_REST_Request $req ) {
    $body = $req->get_json_params() ?: array();

    // Resolve parent session. Numeric → new flow (post_parent). Anything
    // non-numeric → legacy flow (uuid stored as meta).
    $parent_session_id = 0;
    $legacy_session_id = '';
    if ( isset( $body['session_id'] ) ) {
        if ( is_numeric( $body['session_id'] ) && (int) $body['session_id'] > 0 ) {
            $parent_session_id = (int) $body['session_id'];
            // Verify the user owns the parent session before parenting onto it.
            $owned = firefly_notes_owned_session( $parent_session_id );
            if ( is_wp_error( $owned ) ) {
                return $owned;
            }
        } elseif ( is_string( $body['session_id'] ) && $body['session_id'] !== '' ) {
            $legacy_session_id = sanitize_text_field( $body['session_id'] );
        }
    }

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
        'post_parent'  => $parent_session_id,
        'post_title'   => $title,
        'post_content' => '',
    ), true );
    if ( is_wp_error( $id ) ) return $id;

    if ( $legacy_session_id !== '' ) {
        update_post_meta( $id, FIREFLY_NOTES_META_LEGACY_SESSION, $legacy_session_id );
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
 * Return note IDs whose legacy _firefly_note_session_id meta matches $sid,
 * scoped to the current user. Used only by the migration endpoint to find
 * pre-server-sessions notes tagged with a given browser uuid so they can be
 * re-parented onto a freshly-created session post.
 *
 * After migration completes the meta is cleared on each note, so this query
 * goes empty and the helper becomes inert. Kept here for the lifetime of
 * the legacy support window.
 */
function firefly_notes_ids_for_local_session( $sid ) {
    if ( $sid === '' ) return array();
    $args = array(
        'post_type'      => FIREFLY_NOTES_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 500,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_key'       => FIREFLY_NOTES_META_LEGACY_SESSION,
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

// ---------- Session route handlers ----------
//
// All of the session routes share the same author-scoping model as the
// note routes: a non-admin user sees only sessions they created. The
// server is the source of truth for the session list, labels, and per-
// session Ragsmith conversation id; the browser caches "which session
// is active" but no longer owns the data.

/**
 * Backwards-compat fallback: notes created before sessions-as-CPT shipped
 * have post_parent=0 (no session). On the user's first /sessions hit after
 * upgrade we sweep those orphans into an auto-created "Default" session so
 * nothing is stranded under the new ?session=N filter.
 *
 * Cheap on the common path: one fields=ids query returns 0 hits and we exit.
 * The Default session is keyed by FIREFLY_NOTES_META_DEFAULT_SESSION (not the
 * title) so the user can rename it without breaking idempotency.
 */
function firefly_notes_adopt_orphan_notes_into_default() {
    $user_id = get_current_user_id();
    if ( ! $user_id ) return;

    $orphan_q = new WP_Query( array(
        'post_type'      => FIREFLY_NOTES_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'author'         => $user_id,
        'post_parent'    => 0,
        'posts_per_page' => 500,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );
    if ( empty( $orphan_q->posts ) ) return;

    $default_id = firefly_notes_find_or_create_default_session( $user_id );
    if ( ! $default_id ) return;

    foreach ( $orphan_q->posts as $note_id ) {
        wp_update_post( array( 'ID' => $note_id, 'post_parent' => $default_id ) );
        delete_post_meta( $note_id, FIREFLY_NOTES_META_LEGACY_SESSION );
    }
}

/**
 * Find the user's auto-created Default session or create one. Resolution
 * order:
 *   1. Session already flagged with FIREFLY_NOTES_META_DEFAULT_SESSION
 *      (the meta-keyed lookup is renamed-safe).
 *   2. Any session owned by the user with the literal title "Default" — this
 *      lets the legacy /sessions/migrate flow's auto-created session be
 *      reused so we don't end up with two "Default" rows for the same user.
 *      Found sessions get back-tagged with the default meta for cheap lookup
 *      next time.
 *   3. Create a new one and tag it.
 */
function firefly_notes_find_or_create_default_session( $user_id ) {
    $by_meta = new WP_Query( array(
        'post_type'      => FIREFLY_NOTES_SESSION_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'author'         => $user_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_key'       => FIREFLY_NOTES_META_DEFAULT_SESSION,
        'meta_value'     => '1',
    ) );
    if ( $by_meta->posts ) return (int) $by_meta->posts[0];

    $by_title = new WP_Query( array(
        'post_type'      => FIREFLY_NOTES_SESSION_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'author'         => $user_id,
        'title'          => 'Default',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ) );
    if ( $by_title->posts ) {
        $id = (int) $by_title->posts[0];
        update_post_meta( $id, FIREFLY_NOTES_META_DEFAULT_SESSION, '1' );
        return $id;
    }

    $id = wp_insert_post( array(
        'post_type'   => FIREFLY_NOTES_SESSION_POST_TYPE,
        'post_status' => 'publish',
        'post_author' => $user_id,
        'post_title'  => 'Default',
    ), true );
    if ( is_wp_error( $id ) ) return 0;
    update_post_meta( $id, FIREFLY_NOTES_META_DEFAULT_SESSION, '1' );
    return (int) $id;
}

/**
 * GET /sessions — list the current user's sessions, newest-modified first,
 * with a live child-note count for the picker UI. Admin sees everything.
 */
function firefly_notes_route_list_sessions( WP_REST_Request $req ) {
    firefly_notes_adopt_orphan_notes_into_default();

    $args = array(
        'post_type'      => FIREFLY_NOTES_SESSION_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 500,
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    );
    if ( ! current_user_can( 'manage_options' ) ) {
        $args['author'] = get_current_user_id();
    }
    $q = new WP_Query( $args );

    $sessions = array();
    foreach ( $q->posts as $session ) {
        $sessions[] = firefly_notes_format_session( $session );
    }
    return rest_ensure_response( array( 'sessions' => $sessions ) );
}

/**
 * POST /sessions — create a new session. Body accepts `label` (optional);
 * falls back to "Session N" using the user's current session count + 1 so
 * a freshly-created session gets a unique-feeling default name without a
 * separate "label is required" trip.
 */
function firefly_notes_route_create_session( WP_REST_Request $req ) {
    $body  = $req->get_json_params() ?: array();
    $label = isset( $body['label'] ) ? sanitize_text_field( (string) $body['label'] ) : '';
    if ( $label === '' ) {
        // Count existing sessions for this user to suggest "Session N+1".
        $count_q = new WP_Query( array(
            'post_type'      => FIREFLY_NOTES_SESSION_POST_TYPE,
            'post_status'    => array( 'publish', 'draft' ),
            'author'         => get_current_user_id(),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ) );
        $label = sprintf( 'Session %d', (int) $count_q->found_posts + 1 );
    }

    $id = wp_insert_post( array(
        'post_type'   => FIREFLY_NOTES_SESSION_POST_TYPE,
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
        'post_title'  => $label,
    ), true );
    if ( is_wp_error( $id ) ) return $id;

    return rest_ensure_response( firefly_notes_format_session( get_post( $id ) ) );
}

/**
 * GET /sessions/{id} — single session fetch, used by the URL-driven session
 * picker on page load to validate that `?session=N` is a session the
 * current user owns before activating it.
 */
function firefly_notes_route_get_session( WP_REST_Request $req ) {
    $session = firefly_notes_owned_session( $req['id'] );
    if ( is_wp_error( $session ) ) return $session;
    return rest_ensure_response( firefly_notes_format_session( $session ) );
}

/**
 * PATCH /sessions/{id} — rename. Body: { label }. Empty label is rejected
 * to keep the picker from rendering a blank entry.
 */
function firefly_notes_route_update_session( WP_REST_Request $req ) {
    $session = firefly_notes_owned_session( $req['id'] );
    if ( is_wp_error( $session ) ) return $session;

    $body = $req->get_json_params() ?: array();
    if ( ! array_key_exists( 'label', $body ) ) {
        return new WP_Error( 'firefly_notes_bad_request', 'label is required.', array( 'status' => 400 ) );
    }
    $label = sanitize_text_field( (string) $body['label'] );
    if ( $label === '' ) {
        return new WP_Error( 'firefly_notes_bad_request', 'label cannot be empty.', array( 'status' => 400 ) );
    }

    wp_update_post( array(
        'ID'         => $session->ID,
        'post_title' => $label,
    ) );
    clean_post_cache( $session->ID );
    return rest_ensure_response( firefly_notes_format_session( get_post( $session->ID ) ) );
}

/**
 * DELETE /sessions/{id} — cascade-delete a session.
 *
 * Order matters:
 *   1. Drop the Ragsmith conversation (cascades all its messages + facts).
 *      Doing this first means the per-note message purge below becomes a
 *      no-op against an already-gone conversation, which is fine — the
 *      client method swallows that quietly.
 *   2. Trash every child note (post_parent = session id). Each one's
 *      DELETE handler still walks its message refs in case 1 didn't reach
 *      Ragsmith for some reason, so the safety net is preserved.
 *   3. Force-delete the session post itself (bypass trash — sessions are
 *      runtime state, not user content that should be recoverable).
 */
function firefly_notes_route_delete_session( WP_REST_Request $req ) {
    $session = firefly_notes_owned_session( $req['id'] );
    if ( is_wp_error( $session ) ) return $session;

    // 1. Ragsmith conversation.
    $rs_id = (string) get_post_meta( $session->ID, FIREFLY_NOTES_META_SESSION_RS_ID, true );
    if ( $rs_id !== '' && function_exists( 'firefly_ragsmith' ) ) {
        $client = firefly_ragsmith();
        if ( is_object( $client ) && method_exists( $client, 'delete_session' ) ) {
            // Best effort — proceed even if Ragsmith doesn't have this id.
            $client->delete_session( $rs_id );
        }
    }

    // 2. Child notes.
    $child_ids = firefly_notes_ids_for_session( $session->ID );
    $deleted = array();
    foreach ( $child_ids as $note_id ) {
        // Per-note purge handles any message refs still on the note.
        firefly_notes_purge_ragsmith_messages( $note_id );
        wp_trash_post( $note_id );
        $deleted[] = (int) $note_id;
    }

    // 3. Session itself.
    wp_delete_post( $session->ID, true );

    return rest_ensure_response( array(
        'deleted' => true,
        'id'      => (int) $session->ID,
        'notes_deleted' => $deleted,
    ) );
}

/**
 * POST /sessions/migrate — one-shot localStorage → server import.
 *
 * Body shape:
 *   { sessions: [
 *       { id: "<browser-uuid>", label: "<label>", ragsmithSessionId: "<rs-id-or-null>" },
 *       ...
 *   ] }
 *
 * For each entry the handler:
 *   1. Looks up an already-migrated session post by FIREFLY_NOTES_META_LEGACY_SESSION
 *      = browser uuid. If one exists, reuses it (preserves prior runs).
 *   2. Otherwise creates a session post owned by the requester with the
 *      same label.
 *   3. Stores the browser uuid on the session post itself (as legacy meta)
 *      so re-runs find the same record.
 *   4. Binds rs_session_id if provided.
 *   5. Walks every note tagged with that browser uuid via legacy meta,
 *      re-parents it to the new session post, and clears the legacy meta.
 *
 * Returns: { migrated: [ {browser_id, session_id, note_count} ] }
 */
function firefly_notes_route_migrate_sessions( WP_REST_Request $req ) {
    $body  = $req->get_json_params() ?: array();
    $input = isset( $body['sessions'] ) && is_array( $body['sessions'] ) ? $body['sessions'] : array();

    $results = array();
    foreach ( $input as $entry ) {
        if ( ! is_array( $entry ) || empty( $entry['id'] ) ) continue;
        $browser_id = sanitize_text_field( (string) $entry['id'] );
        $label      = isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '';
        $rs_id      = isset( $entry['ragsmithSessionId'] ) ? sanitize_text_field( (string) $entry['ragsmithSessionId'] ) : '';

        // 1. Look up an existing session post for this browser uuid.
        $session_id = firefly_notes_find_session_by_legacy_id( $browser_id );

        // 2. Or create a new session post.
        if ( ! $session_id ) {
            $label = $label !== '' ? $label : 'Imported session';
            $new_id = wp_insert_post( array(
                'post_type'   => FIREFLY_NOTES_SESSION_POST_TYPE,
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
                'post_title'  => $label,
            ), true );
            if ( is_wp_error( $new_id ) ) continue;
            $session_id = (int) $new_id;
            // 3. Tag with browser uuid so a future re-run finds this row.
            update_post_meta( $session_id, FIREFLY_NOTES_META_LEGACY_SESSION, $browser_id );
        }

        // 4. Bind ragsmith id when present + not already set.
        if ( $rs_id !== '' ) {
            $current_rs = (string) get_post_meta( $session_id, FIREFLY_NOTES_META_SESSION_RS_ID, true );
            if ( $current_rs === '' ) {
                update_post_meta( $session_id, FIREFLY_NOTES_META_SESSION_RS_ID, $rs_id );
            }
        }

        // 5. Re-parent notes tagged with the browser uuid → this session post.
        $orphan_note_ids = firefly_notes_ids_for_local_session( $browser_id );
        $reparented = 0;
        foreach ( $orphan_note_ids as $note_id ) {
            // Skip notes the user doesn't own (firefly_notes_ids_for_local_session
            // already scopes by author for non-admins, but admins might see
            // others' notes — we still want to migrate those when admin runs it,
            // so no extra owner gate here).
            wp_update_post( array( 'ID' => $note_id, 'post_parent' => $session_id ) );
            delete_post_meta( $note_id, FIREFLY_NOTES_META_LEGACY_SESSION );
            $reparented++;
        }

        $results[] = array(
            'browser_id'      => $browser_id,
            'session_id'      => $session_id,
            'notes_migrated'  => $reparented,
        );
    }

    return rest_ensure_response( array( 'migrated' => $results ) );
}

/**
 * Find a session post id whose FIREFLY_NOTES_META_LEGACY_SESSION meta
 * matches $browser_id, scoped to the current user. Used by the migration
 * endpoint to make re-runs idempotent.
 */
function firefly_notes_find_session_by_legacy_id( $browser_id ) {
    if ( $browser_id === '' ) return 0;
    $args = array(
        'post_type'      => FIREFLY_NOTES_SESSION_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_key'       => FIREFLY_NOTES_META_LEGACY_SESSION,
        'meta_value'     => $browser_id,
    );
    if ( ! current_user_can( 'manage_options' ) ) {
        $args['author'] = get_current_user_id();
    }
    $q = new WP_Query( $args );
    return $q->posts ? (int) $q->posts[0] : 0;
}

/**
 * POST /sessions/{id}/ragsmith — bind a Ragsmith conversation_id to a
 * session. Body: { rs_session_id }. Idempotent — sending the same id back
 * is a no-op so the client can call this every save without consequence.
 * Sending a different id replaces the value (lets the client recover from
 * a Ragsmith-side conversation reset). Empty id clears the binding.
 */
function firefly_notes_route_set_session_rs_id( WP_REST_Request $req ) {
    $session = firefly_notes_owned_session( $req['id'] );
    if ( is_wp_error( $session ) ) return $session;

    $body  = $req->get_json_params() ?: array();
    $rs_id = isset( $body['rs_session_id'] ) ? sanitize_text_field( (string) $body['rs_session_id'] ) : '';

    if ( $rs_id === '' ) {
        delete_post_meta( $session->ID, FIREFLY_NOTES_META_SESSION_RS_ID );
    } else {
        update_post_meta( $session->ID, FIREFLY_NOTES_META_SESSION_RS_ID, $rs_id );
    }
    return rest_ensure_response( firefly_notes_format_session( get_post( $session->ID ) ) );
}

/**
 * Return the IDs of every note whose post_parent is this session, scoped
 * to the current user (admins see all). Used by the cascade-delete + the
 * notes list filter.
 */
function firefly_notes_ids_for_session( $session_id ) {
    $args = array(
        'post_type'      => FIREFLY_NOTES_POST_TYPE,
        'post_parent'    => (int) $session_id,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 500,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    );
    if ( ! current_user_can( 'manage_options' ) ) {
        $args['author'] = get_current_user_id();
    }
    $q = new WP_Query( $args );
    return array_map( 'intval', $q->posts );
}
