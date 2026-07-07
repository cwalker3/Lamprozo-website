<?php
/**
 * Notes — Per-user dictation notes. Uses firefly-ragsmith's dictation
 * primitive (Whisper transcription, no LLM round-trip).
 *
 * Surface:
 *   - firefly_note CPT (private, per-author)            — notes
 *   - firefly_note_session CPT (private, per-author)    — sessions that own notes
 *   - wp-admin top-level "Notes" menu (admin-only by default)
 *   - REST namespace firefly-capture/v1
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

const FIREFLY_CAPTURE_MENU_SLUG              = 'firefly-capture';
const FIREFLY_CAPTURE_POST_TYPE              = 'firefly_note';
const FIREFLY_CAPTURE_SESSION_POST_TYPE      = 'firefly_note_session';
const FIREFLY_CAPTURE_RECORDING_POST_TYPE    = 'firefly_recording';
const FIREFLY_CAPTURE_DOCUMENT_POST_TYPE     = 'firefly_document';
const FIREFLY_CAPTURE_REST_NS                = 'firefly-capture/v1';

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
const FIREFLY_CAPTURE_META_MESSAGES       = '_firefly_note_messages';
const FIREFLY_CAPTURE_META_LEGACY_SESSION = '_firefly_note_session_id';

// Per-session meta:
//   _firefly_note_session_rs_id — the Ragsmith conversation_id this session
//   maps to. NULL until the first Save-to-AI lands a message; then sticky.
//   Reused by every subsequent save in the session so all of a session's
//   dictation messages live in one Ragsmith conversation.
const FIREFLY_CAPTURE_META_SESSION_RS_ID = '_firefly_note_session_rs_id';

// Marks the auto-created "Default" session per user, used by the orphan-
// adoption fallback. Find-or-create keys off this meta instead of the title
// so a user-renamed default still gets reused.
const FIREFLY_CAPTURE_META_DEFAULT_SESSION = '_firefly_note_default_session';

// Per-recording meta. Recordings represent audio captured in-browser and
// uploaded to Ragsmith's /import/audio pipeline (transcribe + diarize +
// summary + KB). The same meta keys are also written to the session's
// bound Ragsmith conversation as a message, so deletes can cascade.
const FIREFLY_CAPTURE_META_RECORDING_AUDIO_URL    = '_recording_audio_url';
const FIREFLY_CAPTURE_META_RECORDING_DURATION     = '_recording_duration';
const FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT   = '_recording_transcript';
const FIREFLY_CAPTURE_META_RECORDING_SUMMARY      = '_recording_summary';
const FIREFLY_CAPTURE_META_RECORDING_KB_PATH      = '_recording_kb_path';
const FIREFLY_CAPTURE_META_RECORDING_RS_MSG_ID    = '_recording_rs_message_id';
const FIREFLY_CAPTURE_META_RECORDING_SHARE_AUDIO  = '_recording_share_audio';
const FIREFLY_CAPTURE_META_RECORDING_ROOM_AUDIO   = '_recording_room_audio';
const FIREFLY_CAPTURE_META_RECORDING_STATUS       = '_recording_status'; // processing|ready|failed
// MP3 saved to WP media library so the browser has a playable URL that
// survives Ragsmith-side cleanup. attachment_id is the WP attachment post id,
// url is the cached public URL (re-resolvable via wp_get_attachment_url).
const FIREFLY_CAPTURE_META_RECORDING_MP3_ATTACH   = '_recording_mp3_attachment_id';
const FIREFLY_CAPTURE_META_RECORDING_MP3_URL      = '_recording_mp3_url';
// Transcript + summary mirrored to WP as .txt attachments — gives the
// recording a portable download URL inside /wp-content/uploads/ that the
// user can share or archive even if the Ragsmith files get purged.
const FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT_ATTACH = '_recording_transcript_attachment_id';
const FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT_URL    = '_recording_transcript_url';
const FIREFLY_CAPTURE_META_RECORDING_SUMMARY_ATTACH    = '_recording_summary_attachment_id';
const FIREFLY_CAPTURE_META_RECORDING_SUMMARY_URL       = '_recording_summary_url';

// Per-document meta. Documents are uploaded to Ragsmith /document/extract
// in read_aloud mode with extract_facts=true — the doc lands in the
// session's bound conversation, populates the ephemeral KB, and triggers
// background fact extraction. No LLM round-trip ("just floats + facts").
const FIREFLY_CAPTURE_META_DOC_FILENAME           = '_document_filename';
const FIREFLY_CAPTURE_META_DOC_MIME               = '_document_mime';
const FIREFLY_CAPTURE_META_DOC_EPHEMERAL_KB_PATH  = '_document_ephemeral_kb_path';
// rs_message_id is the Ragsmith CHIP message id (returned as
// `chip.chip_message_id` from /document/extract under mode=extract_facts_only).
// We keep the same meta key as before so existing storage carries over —
// it just refers to a chip-typed system message instead of a user/assistant pair.
const FIREFLY_CAPTURE_META_DOC_RS_MSG_ID          = '_document_rs_message_id';
const FIREFLY_CAPTURE_META_DOC_RS_SESSION_ID      = '_document_rs_session_id';
// Unique marker the Ragsmith server assigned to this ingest. Required to
// delete the chip + its facts via DELETE /sessions/{sid}/fact-ingest/{cid}.
// Stored alongside chip_message_id so the WP-side delete cascade can call
// the right Ragsmith endpoint without needing a second lookup round-trip.
const FIREFLY_CAPTURE_META_DOC_SOURCE_MARKER      = '_document_source_marker';
const FIREFLY_CAPTURE_META_DOC_CHUNK_COUNT        = '_document_chunk_count';
const FIREFLY_CAPTURE_META_DOC_FACTS_COUNT        = '_document_facts_count';
const FIREFLY_CAPTURE_META_DOC_STATUS             = '_document_status'; // ready|failed (no longer "extracting" — sync mode)
// WP media library copy of the uploaded file. Saved by the document proxy
// at upload time so the user can re-download it from the Capture UI long
// after Ragsmith side cleanup has run. Cascade-deleted with the doc post.
const FIREFLY_CAPTURE_META_DOC_FILE_ATTACH        = '_document_file_attachment_id';
const FIREFLY_CAPTURE_META_DOC_FILE_URL           = '_document_file_url';
// Extracted plain text + summary of facts, mirrored from Ragsmith into
// post_meta so the loaded-state UI can render them without re-fetching.
const FIREFLY_CAPTURE_META_DOC_TEXT               = '_document_text';
const FIREFLY_CAPTURE_META_DOC_FACTS              = '_document_facts'; // JSON array

/**
 * Per-file mtime cache busting. Whenever an asset's content changes on
 * disk, its mtime updates and browsers refetch on the next request.
 * Unchanged files stay cached — no manual version bumps required.
 */
function firefly_capture_asset_version( $abs_path ) {
    return file_exists( $abs_path ) ? (string) filemtime( $abs_path ) : '0';
}

/**
 * Single source of truth for who can use the Notes feature.
 * Default template gates on manage_options; flip to is_user_logged_in()
 * (or any custom cap) to open it up.
 */
function firefly_capture_can_access() {
    return current_user_can( 'manage_options' );
}

// ---------- Custom post types ----------

add_action( 'init', function () {
    // Note CPT. Now also declares hierarchical=true so post_parent links a
    // note to its owning session post. The Notes admin UI is the only place
    // that surfaces these, so all show_* / public flags stay off.
    register_post_type( FIREFLY_CAPTURE_POST_TYPE, array(
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
    // — the Capture UI manages the full lifecycle through the session picker.
    register_post_type( FIREFLY_CAPTURE_SESSION_POST_TYPE, array(
        'label'           => 'Capture Sessions',
        'public'          => false,
        'show_ui'         => false,
        'show_in_menu'    => false,
        'show_in_rest'    => false,
        'supports'        => array( 'title', 'author' ),
        'capability_type' => 'post',
        'map_meta_cap'    => true,
    ) );

    // Recording CPT — sibling of firefly_note under a session. Each
    // recording is one audio capture (share-audio / room-audio / start-
    // muted toggles) uploaded to Ragsmith /import/audio. The transcript,
    // summary, KB path and Ragsmith message id land in postmeta.
    register_post_type( FIREFLY_CAPTURE_RECORDING_POST_TYPE, array(
        'label'           => 'Capture Recordings',
        'public'          => false,
        'show_ui'         => false,
        'show_in_menu'    => false,
        'show_in_rest'    => false,
        'hierarchical'    => true,
        'supports'        => array( 'title', 'author', 'page-attributes' ),
        'capability_type' => 'post',
        'map_meta_cap'    => true,
    ) );

    // Document CPT — sibling of firefly_note under a session. Each
    // document is one file uploaded to Ragsmith /document/extract in
    // read_aloud mode with extract_facts=true. No LLM round-trip happens
    // for captures — the doc just floats in the conversation and feeds
    // fact extraction.
    register_post_type( FIREFLY_CAPTURE_DOCUMENT_POST_TYPE, array(
        'label'           => 'Capture Documents',
        'public'          => false,
        'show_ui'         => false,
        'show_in_menu'    => false,
        'show_in_rest'    => false,
        'hierarchical'    => true,
        'supports'        => array( 'title', 'author', 'page-attributes' ),
        'capability_type' => 'post',
        'map_meta_cap'    => true,
    ) );
} );

// ---------- Admin menu ----------

add_action( 'admin_menu', function () {
    if ( ! firefly_capture_can_access() ) return;

    add_menu_page(
        'Capture',
        'Capture',
        'manage_options',
        FIREFLY_CAPTURE_MENU_SLUG,
        'firefly_capture_render_page',
        'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iI2E3YWFhZCI+PHBhdGggZD0iTTEyIDIuNWwxLjYgNC45IDQuOSAxLjYtNC45IDEuNkwxMiAxNS41bC0xLjYtNC45TDUuNSA5bDQuOS0xLjZMMTIgMi41eiIvPjxwYXRoIGQ9Ik0xOSAxNC41bC45IDIuNiAyLjYuOS0yLjYuOS0uOSAyLjYtLjktMi42LTIuNi0uOSAyLjYtLjkuOS0yLjZ6Ii8+PHBhdGggZD0iTTUuNSAxN2wuNiAxLjcgMS43LjYtMS43LjYtLjYgMS43LS42LTEuNy0xLjctLjYgMS43LS42LjYtMS43eiIvPjwvc3ZnPg==',
        26
    );
}, 99 );

function firefly_capture_render_page() {
    if ( ! firefly_capture_can_access() ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'firefly-collective' ) );
    }
    $view = plugin_dir_path( __FILE__ ) . '../views/capture.php';
    if ( file_exists( $view ) ) {
        include $view;
    }
}

// ---------- Asset enqueue (only on this page) ----------

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'toplevel_page_' . FIREFLY_CAPTURE_MENU_SLUG ) return;
    if ( ! firefly_capture_can_access() ) return;

    if ( function_exists( 'firefly_ragsmith_enqueue_dictation' ) ) {
        firefly_ragsmith_enqueue_dictation();
    }

    // Template-agnostic asset URL: this same file lives under
    // templates/firefly/ AND templates/firefly-v2/ (and may be copied
    // into future templates), so derive the template directory name
    // from __FILE__ instead of hardcoding "firefly".
    $template_dir   = dirname( __FILE__, 2 ); // plugins/firefly-collective/templates/<template>/
    $template_name  = basename( $template_dir );
    $asset_base     = plugins_url( "templates/{$template_name}/assets", WP_PLUGIN_DIR . '/firefly-collective/firefly.php' );

    wp_enqueue_style(
        'firefly-capture',
        $asset_base . '/css/capture.css',
        array(),
        firefly_capture_asset_version( $template_dir . '/assets/css/capture.css' )
    );
    wp_enqueue_script(
        'firefly-capture',
        $asset_base . '/js/capture.js',
        array(),
        firefly_capture_asset_version( $template_dir . '/assets/js/capture.js' ),
        true
    );
    wp_add_inline_script(
        'firefly-capture',
        sprintf(
            'window.FireflyCapture = window.FireflyCapture || {}; window.FireflyCapture.restUrl = %s; window.FireflyCapture.nonce = %s;',
            wp_json_encode( esc_url_raw( rest_url( FIREFLY_CAPTURE_REST_NS ) ) ),
            wp_json_encode( wp_create_nonce( 'wp_rest' ) )
        ),
        'before'
    );
} );

// ---------- REST routes ----------

add_action( 'rest_api_init', function () {
    $perm = function () { return firefly_capture_can_access(); };

    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/notes', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_list',
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_create',
        ),
    ) );

    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/notes/(?P<id>\d+)', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_get',
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_delete',
        ),
    ) );

    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/notes/(?P<id>\d+)', array(
        'methods'             => WP_REST_Server::EDITABLE,  // POST/PUT/PATCH
        'permission_callback' => $perm,
        'callback'            => 'firefly_capture_route_update',
    ) );

    // Record a single Ragsmith dictation message id against a note so the
    // delete cascade can remove it from Ragsmith later. Idempotent — duplicate
    // message ids are dropped on append.
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/notes/(?P<id>\d+)/messages', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_capture_route_append_message',
    ) );

    // Session-scoped operations: count + bulk delete notes carrying a given
    // browser-local session_id meta. Used by the session picker's delete flow.
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/sessions/(?P<sid>[A-Za-z0-9_\-]+)/notes', array(
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_delete_session_notes',
        ),
    ) );
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/sessions/(?P<sid>[A-Za-z0-9_\-]+)/notes/count', array(
        'methods'             => WP_REST_Server::READABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_capture_route_count_session_notes',
    ) );

    // ---------- Session CRUD (server-side first-class sessions) ----------
    // GET    /sessions      — list current user's sessions (with note counts)
    // POST   /sessions      — create  body: { label? }
    // GET    /sessions/{id} — fetch one
    // PATCH  /sessions/{id} — rename  body: { label }
    // DELETE /sessions/{id} — cascade-delete session + child notes + Ragsmith
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/sessions', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_list_sessions',
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_create_session',
        ),
    ) );
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/sessions/(?P<id>\d+)', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_get_session',
        ),
        array(
            'methods'             => WP_REST_Server::EDITABLE,  // POST/PUT/PATCH
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_update_session',
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_delete_session',
        ),
    ) );

    // POST /sessions/{id}/ragsmith — record the Ragsmith conversation_id
    // that this session is bound to. Called by the client right after the
    // first Save-to-AI lands and Ragsmith returns a session id. Subsequent
    // calls with the same id are no-ops so retries are safe.
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/sessions/(?P<id>\d+)/ragsmith', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_capture_route_set_session_rs_id',
    ) );

    // POST /sessions/migrate — one-shot import of the browser's legacy
    // localStorage session list. Body: { sessions: [{ id, label,
    // ragsmithSessionId }] } where `id` is the browser uuid. For each
    // entry: create a server session post (or look up the existing one if
    // already migrated), bind its rs_session_id, then re-parent every
    // legacy-meta-tagged note onto the new session post. Idempotent —
    // safe to call repeatedly from a client that lost track of whether
    // it ran.
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/sessions/migrate', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_capture_route_migrate_sessions',
    ) );

    // ---------- Recordings (Capture mode #2) ----------
    // POST  /recordings              — body: { session_id, share_audio?, room_audio?, started_muted?, title?, audio_url?, duration?, rs_message_id?, status? }
    // GET   /recordings?session=N    — list recordings under a session
    // GET   /recordings/{id}         — one recording
    // PATCH /recordings/{id}         — update transcript/summary/status/etc. as the Ragsmith pipeline progresses
    // DELETE /recordings/{id}        — cascade-delete: drop the Ragsmith message + trash the post
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/recordings', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_list_recordings',
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_create_recording',
        ),
    ) );
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/recordings/(?P<id>\d+)', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_get_recording',
        ),
        array(
            'methods'             => WP_REST_Server::EDITABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_update_recording',
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_delete_recording',
        ),
    ) );

    // ---------- Documents (Capture mode #3) ----------
    // POST  /documents               — body: { session_id, filename, mime?, rs_message_id?, ephemeral_kb_path?, chunk_count?, facts_count?, status? }
    // GET   /documents?session=N
    // GET   /documents/{id}
    // PATCH /documents/{id}          — update facts_count / status when extraction completes
    // DELETE /documents/{id}
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/documents', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_list_documents',
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_create_document',
        ),
    ) );
    register_rest_route( FIREFLY_CAPTURE_REST_NS, '/documents/(?P<id>\d+)', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_get_document',
        ),
        array(
            'methods'             => WP_REST_Server::EDITABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_update_document',
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $perm,
            'callback'            => 'firefly_capture_route_delete_document',
        ),
    ) );
} );

/**
 * Owner check used by every per-note route. Returns the post or a WP_Error.
 */
function firefly_capture_owned_post( $id ) {
    $post = get_post( (int) $id );
    if ( ! $post || $post->post_type !== FIREFLY_CAPTURE_POST_TYPE ) {
        return new WP_Error( 'firefly_capture_not_found', 'Note not found.', array( 'status' => 404 ) );
    }
    if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'firefly_capture_forbidden', 'Not your note.', array( 'status' => 403 ) );
    }
    return $post;
}

/**
 * Sister to firefly_capture_owned_post for the session CPT. Used by every
 * per-session route to verify the requester owns the session (or is admin).
 */
function firefly_capture_owned_session( $id ) {
    $post = get_post( (int) $id );
    if ( ! $post || $post->post_type !== FIREFLY_CAPTURE_SESSION_POST_TYPE ) {
        return new WP_Error( 'firefly_capture_session_not_found', 'Session not found.', array( 'status' => 404 ) );
    }
    if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'firefly_capture_session_forbidden', 'Not your session.', array( 'status' => 403 ) );
    }
    return $post;
}

/**
 * Shape a session post for the wire. Includes a live note_count so the
 * picker UI can show "delete will remove N notes" without a second roundtrip.
 */
function firefly_capture_format_session( WP_Post $session ) {
    $note_count = (int) ( new WP_Query( array(
        'post_type'      => FIREFLY_CAPTURE_POST_TYPE,
        'post_parent'    => $session->ID,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => false,
    ) ) )->found_posts;

    return array(
        'id'              => (int) $session->ID,
        'label'           => $session->post_title !== '' ? $session->post_title : 'Untitled session',
        'rs_session_id'   => (string) get_post_meta( $session->ID, FIREFLY_CAPTURE_META_SESSION_RS_ID, true ),
        'note_count'      => $note_count,
        'created'         => mysql2date( 'c', $session->post_date ),
        'modified'        => mysql2date( 'c', $session->post_modified ),
    );
}

function firefly_capture_format( WP_Post $post ) {
    // Surface the Ragsmith message refs so the client can tell whether this
    // note has been pushed to AI (and, if so, which message to PUT against
    // on subsequent saves). The meta stores a JSON-encoded list — invalid
    // or missing data falls back to an empty array.
    $raw_messages = get_post_meta( $post->ID, FIREFLY_CAPTURE_META_MESSAGES, true );
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
function firefly_capture_route_list( WP_REST_Request $req ) {
    $args = array(
        'post_type'      => FIREFLY_CAPTURE_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 200,
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    );
    // Match the admin-permissive pattern used by every other Capture list
    // endpoint (firefly_capture_route_list_sessions, firefly_capture_list_children).
    // Administrators see everyone's notes — Capture is shared workspace for
    // the admin team — while non-admin roles stay scoped to their own.
    if ( ! current_user_can( 'manage_options' ) ) {
        $args['author'] = get_current_user_id();
    }
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
 * have that recorded as FIREFLY_CAPTURE_META_LEGACY_SESSION so the migration
 * endpoint can re-parent them later. This path will be removed once every
 * install has migrated.
 */
function firefly_capture_route_create( WP_REST_Request $req ) {
    $body = $req->get_json_params() ?: array();

    // Resolve parent session. Numeric → new flow (post_parent). Anything
    // non-numeric → legacy flow (uuid stored as meta).
    $parent_session_id = 0;
    $legacy_session_id = '';
    if ( isset( $body['session_id'] ) ) {
        if ( is_numeric( $body['session_id'] ) && (int) $body['session_id'] > 0 ) {
            $parent_session_id = (int) $body['session_id'];
            // Verify the user owns the parent session before parenting onto it.
            $owned = firefly_capture_owned_session( $parent_session_id );
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
        'post_type'    => FIREFLY_CAPTURE_POST_TYPE,
        'post_status'  => 'publish',
        'post_author'  => get_current_user_id(),
        'post_parent'  => $parent_session_id,
        'post_title'   => $title,
        'post_content' => '',
    ), true );
    if ( is_wp_error( $id ) ) return $id;

    if ( $legacy_session_id !== '' ) {
        update_post_meta( $id, FIREFLY_CAPTURE_META_LEGACY_SESSION, $legacy_session_id );
    }
    return rest_ensure_response( firefly_capture_format( get_post( $id ) ) );
}

function firefly_capture_route_get( WP_REST_Request $req ) {
    $post = firefly_capture_owned_post( $req['id'] );
    if ( is_wp_error( $post ) ) return $post;
    return rest_ensure_response( firefly_capture_format( $post ) );
}

function firefly_capture_route_delete( WP_REST_Request $req ) {
    $post = firefly_capture_owned_post( $req['id'] );
    if ( is_wp_error( $post ) ) return $post;
    // Tear down the matching Ragsmith messages before trashing the post so
    // the conversation history stays in sync. Best-effort — a Ragsmith hop
    // failure shouldn't strand the WP note in a half-deleted state.
    firefly_capture_purge_ragsmith_messages( $post->ID );
    wp_trash_post( $post->ID );
    return rest_ensure_response( array( 'deleted' => true, 'id' => (int) $post->ID ) );
}

/**
 * POST /notes/{id}/messages — append a Ragsmith message reference.
 * Body: { session_id, message_id }. Both required. Idempotent: appending
 * an existing pair is a no-op so retries don't bloat the meta.
 */
function firefly_capture_route_append_message( WP_REST_Request $req ) {
    $post = firefly_capture_owned_post( $req['id'] );
    if ( is_wp_error( $post ) ) return $post;

    $body       = $req->get_json_params() ?: array();
    $session_id = isset( $body['session_id'] ) ? sanitize_text_field( (string) $body['session_id'] ) : '';
    $message_id = isset( $body['message_id'] ) ? (string) $body['message_id'] : '';

    if ( $session_id === '' || $message_id === '' ) {
        return new WP_Error( 'firefly_capture_bad_request', 'session_id and message_id are required.', array( 'status' => 400 ) );
    }

    $existing = get_post_meta( $post->ID, FIREFLY_CAPTURE_META_MESSAGES, true );
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
    update_post_meta( $post->ID, FIREFLY_CAPTURE_META_MESSAGES, wp_json_encode( $list ) );
    return rest_ensure_response( array( 'ok' => true, 'count' => count( $list ) ) );
}

/**
 * GET /sessions/{sid}/notes/count — how many notes carry this local session_id.
 * The notes UI calls this to show "this will delete N notes" in the confirm.
 */
function firefly_capture_route_count_session_notes( WP_REST_Request $req ) {
    $sid = sanitize_text_field( (string) $req['sid'] );
    $ids = firefly_capture_ids_for_local_session( $sid );
    return rest_ensure_response( array( 'count' => count( $ids ) ) );
}

/**
 * DELETE /sessions/{sid}/notes — bulk-trash every note tagged with this
 * browser-local session_id. Each note's Ragsmith messages are torn down
 * via purge_ragsmith_messages before the post is trashed.
 */
function firefly_capture_route_delete_session_notes( WP_REST_Request $req ) {
    $sid = sanitize_text_field( (string) $req['sid'] );
    $ids = firefly_capture_ids_for_local_session( $sid );
    $deleted = array();
    foreach ( $ids as $id ) {
        // Ownership re-check per row — keeps multi-author setups honest.
        $post = firefly_capture_owned_post( $id );
        if ( is_wp_error( $post ) ) continue;
        firefly_capture_purge_ragsmith_messages( $post->ID );
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
function firefly_capture_ids_for_local_session( $sid ) {
    if ( $sid === '' ) return array();
    $args = array(
        'post_type'      => FIREFLY_CAPTURE_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 500,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_key'       => FIREFLY_CAPTURE_META_LEGACY_SESSION,
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
function firefly_capture_purge_ragsmith_messages( $post_id ) {
    if ( ! function_exists( 'firefly_ragsmith' ) ) return;
    $raw = get_post_meta( $post_id, FIREFLY_CAPTURE_META_MESSAGES, true );
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
    delete_post_meta( $post_id, FIREFLY_CAPTURE_META_MESSAGES );
}

/**
 * Partial update: accepts {title?, content?}. Only the keys present are written.
 * Title is sanitized; content is stored as-is (plain text dictation transcript).
 */
function firefly_capture_route_update( WP_REST_Request $req ) {
    $post = firefly_capture_owned_post( $req['id'] );
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
    return rest_ensure_response( firefly_capture_format( get_post( $post->ID ) ) );
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
 * The Default session is keyed by FIREFLY_CAPTURE_META_DEFAULT_SESSION (not the
 * title) so the user can rename it without breaking idempotency.
 */
function firefly_capture_adopt_orphan_notes_into_default() {
    $user_id = get_current_user_id();
    if ( ! $user_id ) return;

    $orphan_q = new WP_Query( array(
        'post_type'      => FIREFLY_CAPTURE_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'author'         => $user_id,
        'post_parent'    => 0,
        'posts_per_page' => 500,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );
    if ( empty( $orphan_q->posts ) ) return;

    $default_id = firefly_capture_find_or_create_default_session( $user_id );
    if ( ! $default_id ) return;

    foreach ( $orphan_q->posts as $note_id ) {
        wp_update_post( array( 'ID' => $note_id, 'post_parent' => $default_id ) );
        delete_post_meta( $note_id, FIREFLY_CAPTURE_META_LEGACY_SESSION );
    }
}

/**
 * Find the user's auto-created Default session or create one. Resolution
 * order:
 *   1. Session already flagged with FIREFLY_CAPTURE_META_DEFAULT_SESSION
 *      (the meta-keyed lookup is renamed-safe).
 *   2. Any session owned by the user with the literal title "Default" — this
 *      lets the legacy /sessions/migrate flow's auto-created session be
 *      reused so we don't end up with two "Default" rows for the same user.
 *      Found sessions get back-tagged with the default meta for cheap lookup
 *      next time.
 *   3. Create a new one and tag it.
 */
function firefly_capture_find_or_create_default_session( $user_id ) {
    $by_meta = new WP_Query( array(
        'post_type'      => FIREFLY_CAPTURE_SESSION_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'author'         => $user_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_key'       => FIREFLY_CAPTURE_META_DEFAULT_SESSION,
        'meta_value'     => '1',
    ) );
    if ( $by_meta->posts ) return (int) $by_meta->posts[0];

    $by_title = new WP_Query( array(
        'post_type'      => FIREFLY_CAPTURE_SESSION_POST_TYPE,
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
        update_post_meta( $id, FIREFLY_CAPTURE_META_DEFAULT_SESSION, '1' );
        return $id;
    }

    $id = wp_insert_post( array(
        'post_type'   => FIREFLY_CAPTURE_SESSION_POST_TYPE,
        'post_status' => 'publish',
        'post_author' => $user_id,
        'post_title'  => 'Default',
    ), true );
    if ( is_wp_error( $id ) ) return 0;
    update_post_meta( $id, FIREFLY_CAPTURE_META_DEFAULT_SESSION, '1' );
    return (int) $id;
}

/**
 * GET /sessions — list the current user's sessions, newest-modified first,
 * with a live child-note count for the picker UI. Admin sees everything.
 */
function firefly_capture_route_list_sessions( WP_REST_Request $req ) {
    firefly_capture_adopt_orphan_notes_into_default();

    $args = array(
        'post_type'      => FIREFLY_CAPTURE_SESSION_POST_TYPE,
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
        $sessions[] = firefly_capture_format_session( $session );
    }
    return rest_ensure_response( array( 'sessions' => $sessions ) );
}

/**
 * POST /sessions — create a new session. Body accepts `label` (optional);
 * falls back to "Session N" using the user's current session count + 1 so
 * a freshly-created session gets a unique-feeling default name without a
 * separate "label is required" trip.
 */
function firefly_capture_route_create_session( WP_REST_Request $req ) {
    $body  = $req->get_json_params() ?: array();
    $label = isset( $body['label'] ) ? sanitize_text_field( (string) $body['label'] ) : '';
    if ( $label === '' ) {
        // Count existing sessions for this user to suggest "Session N+1".
        $count_q = new WP_Query( array(
            'post_type'      => FIREFLY_CAPTURE_SESSION_POST_TYPE,
            'post_status'    => array( 'publish', 'draft' ),
            'author'         => get_current_user_id(),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ) );
        $label = sprintf( 'Session %d', (int) $count_q->found_posts + 1 );
    }

    $id = wp_insert_post( array(
        'post_type'   => FIREFLY_CAPTURE_SESSION_POST_TYPE,
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
        'post_title'  => $label,
    ), true );
    if ( is_wp_error( $id ) ) return $id;

    return rest_ensure_response( firefly_capture_format_session( get_post( $id ) ) );
}

/**
 * GET /sessions/{id} — single session fetch, used by the URL-driven session
 * picker on page load to validate that `?session=N` is a session the
 * current user owns before activating it.
 */
function firefly_capture_route_get_session( WP_REST_Request $req ) {
    $session = firefly_capture_owned_session( $req['id'] );
    if ( is_wp_error( $session ) ) return $session;
    return rest_ensure_response( firefly_capture_format_session( $session ) );
}

/**
 * PATCH /sessions/{id} — rename. Body: { label }. Empty label is rejected
 * to keep the picker from rendering a blank entry.
 */
function firefly_capture_route_update_session( WP_REST_Request $req ) {
    $session = firefly_capture_owned_session( $req['id'] );
    if ( is_wp_error( $session ) ) return $session;

    $body = $req->get_json_params() ?: array();
    if ( ! array_key_exists( 'label', $body ) ) {
        return new WP_Error( 'firefly_capture_bad_request', 'label is required.', array( 'status' => 400 ) );
    }
    $label = sanitize_text_field( (string) $body['label'] );
    if ( $label === '' ) {
        return new WP_Error( 'firefly_capture_bad_request', 'label cannot be empty.', array( 'status' => 400 ) );
    }

    wp_update_post( array(
        'ID'         => $session->ID,
        'post_title' => $label,
    ) );
    clean_post_cache( $session->ID );
    return rest_ensure_response( firefly_capture_format_session( get_post( $session->ID ) ) );
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
function firefly_capture_route_delete_session( WP_REST_Request $req ) {
    $session = firefly_capture_owned_session( $req['id'] );
    if ( is_wp_error( $session ) ) return $session;

    // 1. Ragsmith conversation. Deletes the conversation row, every
    //    message in it (including fact-ingest chips), and the per-
    //    conversation ephemeral KB at data/kb/_conv/{rs_id}/. Does NOT
    //    delete standalone audio files (those live in data/audio/ and
    //    are scoped per-recording — handled per-child below).
    $rs_id = (string) get_post_meta( $session->ID, FIREFLY_CAPTURE_META_SESSION_RS_ID, true );
    if ( $rs_id !== '' && function_exists( 'firefly_ragsmith' ) ) {
        $client = firefly_ragsmith();
        if ( is_object( $client ) && method_exists( $client, 'delete_session' ) ) {
            try { $client->delete_session( $rs_id ); }
            catch ( Exception $e ) { error_log( '[firefly-capture] ragsmith delete_session failed: ' . $e->getMessage() ); }
        }
    }

    // 2. Notes. Same as before — purge any per-note message refs (mostly
    //    redundant now that delete_session above dropped the whole
    //    conversation, but cheap and resilient if Ragsmith was offline).
    $note_ids       = firefly_capture_ids_for_session( $session->ID );
    $notes_deleted  = array();
    foreach ( $note_ids as $note_id ) {
        firefly_capture_purge_ragsmith_messages( $note_id );
        wp_trash_post( $note_id );
        $notes_deleted[] = (int) $note_id;
    }

    // 3. Recordings + documents under this session — these were
    //    previously left as orphans pointing at a dead session, with
    //    their WP media attachments AND (for recordings) Ragsmith audio
    //    files still on disk. Walk each one, clean up its side
    //    artifacts, then trash the post.
    $children_deleted = firefly_capture_cleanup_session_children( $session->ID );

    // 4. Session itself.
    wp_delete_post( $session->ID, true );

    return rest_ensure_response( array(
        'deleted'             => true,
        'id'                  => (int) $session->ID,
        'notes_deleted'       => $notes_deleted,
        'recordings_deleted'  => $children_deleted['recordings'],
        'documents_deleted'   => $children_deleted['documents'],
    ) );
}

/**
 * Sweep recordings + documents under a session, deleting their WP
 * media attachments + any Ragsmith-side artifacts that aren't already
 * cleaned up by the conversation delete (audio files specifically),
 * then trash each post.
 *
 * Returns { recordings: [...ids], documents: [...ids] } for the response.
 */
function firefly_capture_cleanup_session_children( $session_id ) {
    $result = array( 'recordings' => array(), 'documents' => array() );

    $q = new WP_Query( array(
        'post_type'      => array(
            FIREFLY_CAPTURE_RECORDING_POST_TYPE,
            FIREFLY_CAPTURE_DOCUMENT_POST_TYPE,
        ),
        'post_parent'    => (int) $session_id,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 500,
        'no_found_rows'  => true,
    ) );

    foreach ( $q->posts as $child ) {
        if ( $child->post_type === FIREFLY_CAPTURE_RECORDING_POST_TYPE ) {
            // MP3 + transcript + summary WP attachments.
            foreach ( array(
                FIREFLY_CAPTURE_META_RECORDING_MP3_ATTACH,
                FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT_ATTACH,
                FIREFLY_CAPTURE_META_RECORDING_SUMMARY_ATTACH,
            ) as $key ) {
                $aid = (int) get_post_meta( $child->ID, $key, true );
                if ( $aid > 0 ) {
                    wp_delete_attachment( $aid, true );
                }
            }
            // Ragsmith-side audio file (lives in data/audio/, NOT tied
            // to the conversation row, so delete_session() above missed it).
            $audio_url = (string) get_post_meta( $child->ID, FIREFLY_CAPTURE_META_RECORDING_AUDIO_URL, true );
            if ( $audio_url !== '' && function_exists( 'firefly_ragsmith' ) ) {
                $filename = basename( $audio_url );
                if ( $filename !== '' ) {
                    try { firefly_ragsmith()->delete_audio_file( $filename ); }
                    catch ( Exception $e ) { error_log( '[firefly-capture] ragsmith audio delete failed: ' . $e->getMessage() ); }
                }
            }
            wp_trash_post( $child->ID );
            $result['recordings'][] = (int) $child->ID;
        } elseif ( $child->post_type === FIREFLY_CAPTURE_DOCUMENT_POST_TYPE ) {
            // WP media library copy of the uploaded file. The chip + its
            // facts are already gone via delete_session() above, so no
            // need to call delete_fact_ingest_chip here.
            $aid = (int) get_post_meta( $child->ID, FIREFLY_CAPTURE_META_DOC_FILE_ATTACH, true );
            if ( $aid > 0 ) {
                wp_delete_attachment( $aid, true );
            }
            wp_trash_post( $child->ID );
            $result['documents'][] = (int) $child->ID;
        }
    }

    return $result;
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
 *   1. Looks up an already-migrated session post by FIREFLY_CAPTURE_META_LEGACY_SESSION
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
function firefly_capture_route_migrate_sessions( WP_REST_Request $req ) {
    $body  = $req->get_json_params() ?: array();
    $input = isset( $body['sessions'] ) && is_array( $body['sessions'] ) ? $body['sessions'] : array();

    $results = array();
    foreach ( $input as $entry ) {
        if ( ! is_array( $entry ) || empty( $entry['id'] ) ) continue;
        $browser_id = sanitize_text_field( (string) $entry['id'] );
        $label      = isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '';
        $rs_id      = isset( $entry['ragsmithSessionId'] ) ? sanitize_text_field( (string) $entry['ragsmithSessionId'] ) : '';

        // 1. Look up an existing session post for this browser uuid.
        $session_id = firefly_capture_find_session_by_legacy_id( $browser_id );

        // 2. Or create a new session post.
        if ( ! $session_id ) {
            $label = $label !== '' ? $label : 'Imported session';
            $new_id = wp_insert_post( array(
                'post_type'   => FIREFLY_CAPTURE_SESSION_POST_TYPE,
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
                'post_title'  => $label,
            ), true );
            if ( is_wp_error( $new_id ) ) continue;
            $session_id = (int) $new_id;
            // 3. Tag with browser uuid so a future re-run finds this row.
            update_post_meta( $session_id, FIREFLY_CAPTURE_META_LEGACY_SESSION, $browser_id );
        }

        // 4. Bind ragsmith id when present + not already set.
        if ( $rs_id !== '' ) {
            $current_rs = (string) get_post_meta( $session_id, FIREFLY_CAPTURE_META_SESSION_RS_ID, true );
            if ( $current_rs === '' ) {
                update_post_meta( $session_id, FIREFLY_CAPTURE_META_SESSION_RS_ID, $rs_id );
            }
        }

        // 5. Re-parent notes tagged with the browser uuid → this session post.
        $orphan_note_ids = firefly_capture_ids_for_local_session( $browser_id );
        $reparented = 0;
        foreach ( $orphan_note_ids as $note_id ) {
            // Skip notes the user doesn't own (firefly_capture_ids_for_local_session
            // already scopes by author for non-admins, but admins might see
            // others' notes — we still want to migrate those when admin runs it,
            // so no extra owner gate here).
            wp_update_post( array( 'ID' => $note_id, 'post_parent' => $session_id ) );
            delete_post_meta( $note_id, FIREFLY_CAPTURE_META_LEGACY_SESSION );
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
 * Find a session post id whose FIREFLY_CAPTURE_META_LEGACY_SESSION meta
 * matches $browser_id, scoped to the current user. Used by the migration
 * endpoint to make re-runs idempotent.
 */
function firefly_capture_find_session_by_legacy_id( $browser_id ) {
    if ( $browser_id === '' ) return 0;
    $args = array(
        'post_type'      => FIREFLY_CAPTURE_SESSION_POST_TYPE,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_key'       => FIREFLY_CAPTURE_META_LEGACY_SESSION,
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
function firefly_capture_route_set_session_rs_id( WP_REST_Request $req ) {
    $session = firefly_capture_owned_session( $req['id'] );
    if ( is_wp_error( $session ) ) return $session;

    $body  = $req->get_json_params() ?: array();
    $rs_id = isset( $body['rs_session_id'] ) ? sanitize_text_field( (string) $body['rs_session_id'] ) : '';

    if ( $rs_id === '' ) {
        delete_post_meta( $session->ID, FIREFLY_CAPTURE_META_SESSION_RS_ID );
    } else {
        update_post_meta( $session->ID, FIREFLY_CAPTURE_META_SESSION_RS_ID, $rs_id );
    }
    return rest_ensure_response( firefly_capture_format_session( get_post( $session->ID ) ) );
}

/**
 * Return the IDs of every note whose post_parent is this session, scoped
 * to the current user (admins see all). Used by the cascade-delete + the
 * notes list filter.
 */
function firefly_capture_ids_for_session( $session_id ) {
    $args = array(
        'post_type'      => FIREFLY_CAPTURE_POST_TYPE,
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

// ---------- Recordings + Documents: shared helpers and per-mode handlers ----------
//
// Both new CPTs follow the same lifecycle: the browser uploads media to
// Ragsmith via the firefly-ragsmith bridge, then POSTs a metadata-only
// record to our REST endpoint to create the WP-side post. Subsequent
// PATCHes update transcript/summary/facts/status as the Ragsmith pipeline
// finishes asynchronously. Delete cascades to the bound Ragsmith message.

/**
 * Ownership guard for a recording or document post — same shape as
 * firefly_capture_owned_post but parameterized by post_type.
 */
function firefly_capture_owned_child( $id, $post_type, $label ) {
    $post = get_post( (int) $id );
    if ( ! $post || $post->post_type !== $post_type ) {
        return new WP_Error( 'firefly_capture_not_found', "$label not found.", array( 'status' => 404 ) );
    }
    if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'firefly_capture_forbidden', "Not your $label.", array( 'status' => 403 ) );
    }
    return $post;
}

/**
 * Build a recording's REST payload — ID, title, parent session, all
 * recording meta values, timestamps.
 */
function firefly_capture_format_recording( WP_Post $post ) {
    $meta_keys = array(
        'audio_url'                => FIREFLY_CAPTURE_META_RECORDING_AUDIO_URL,
        'duration'                 => FIREFLY_CAPTURE_META_RECORDING_DURATION,
        'transcript'               => FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT,
        'summary'                  => FIREFLY_CAPTURE_META_RECORDING_SUMMARY,
        'kb_path'                  => FIREFLY_CAPTURE_META_RECORDING_KB_PATH,
        'rs_message_id'            => FIREFLY_CAPTURE_META_RECORDING_RS_MSG_ID,
        'share_audio'              => FIREFLY_CAPTURE_META_RECORDING_SHARE_AUDIO,
        'room_audio'               => FIREFLY_CAPTURE_META_RECORDING_ROOM_AUDIO,
        'status'                   => FIREFLY_CAPTURE_META_RECORDING_STATUS,
        'mp3_attachment_id'        => FIREFLY_CAPTURE_META_RECORDING_MP3_ATTACH,
        'mp3_url'                  => FIREFLY_CAPTURE_META_RECORDING_MP3_URL,
        'transcript_attachment_id' => FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT_ATTACH,
        'transcript_url'           => FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT_URL,
        'summary_attachment_id'    => FIREFLY_CAPTURE_META_RECORDING_SUMMARY_ATTACH,
        'summary_url'              => FIREFLY_CAPTURE_META_RECORDING_SUMMARY_URL,
    );
    $payload = array(
        'id'         => (int) $post->ID,
        'session_id' => (int) $post->post_parent,
        'title'      => $post->post_title,
        'created'    => mysql_to_rfc3339( $post->post_date_gmt ),
        'modified'   => mysql_to_rfc3339( $post->post_modified_gmt ),
    );
    foreach ( $meta_keys as $key => $meta_key ) {
        $payload[ $key ] = get_post_meta( $post->ID, $meta_key, true );
    }
    return $payload;
}

/**
 * Same for documents.
 */
function firefly_capture_format_document( WP_Post $post ) {
    $meta_keys = array(
        'filename'           => FIREFLY_CAPTURE_META_DOC_FILENAME,
        'mime'               => FIREFLY_CAPTURE_META_DOC_MIME,
        'ephemeral_kb_path'  => FIREFLY_CAPTURE_META_DOC_EPHEMERAL_KB_PATH,
        'rs_message_id'      => FIREFLY_CAPTURE_META_DOC_RS_MSG_ID,
        'rs_session_id'      => FIREFLY_CAPTURE_META_DOC_RS_SESSION_ID,
        'source_marker'      => FIREFLY_CAPTURE_META_DOC_SOURCE_MARKER,
        'chunk_count'        => FIREFLY_CAPTURE_META_DOC_CHUNK_COUNT,
        'facts_count'        => FIREFLY_CAPTURE_META_DOC_FACTS_COUNT,
        'status'             => FIREFLY_CAPTURE_META_DOC_STATUS,
        'file_attachment_id' => FIREFLY_CAPTURE_META_DOC_FILE_ATTACH,
        'file_url'           => FIREFLY_CAPTURE_META_DOC_FILE_URL,
        'text'               => FIREFLY_CAPTURE_META_DOC_TEXT,
    );
    $payload = array(
        'id'         => (int) $post->ID,
        'session_id' => (int) $post->post_parent,
        'title'      => $post->post_title,
        'created'    => mysql_to_rfc3339( $post->post_date_gmt ),
        'modified'   => mysql_to_rfc3339( $post->post_modified_gmt ),
    );
    foreach ( $meta_keys as $key => $meta_key ) {
        $payload[ $key ] = get_post_meta( $post->ID, $meta_key, true );
    }
    // Facts stored as a JSON blob — decode for the client.
    $facts_raw = get_post_meta( $post->ID, FIREFLY_CAPTURE_META_DOC_FACTS, true );
    $facts     = $facts_raw ? json_decode( $facts_raw, true ) : array();
    $payload['facts'] = is_array( $facts ) ? $facts : array();
    return $payload;
}

/**
 * Generic listing: items of a given post_type filtered by `?session=N`.
 */
function firefly_capture_list_children( $post_type, $session_id, $formatter ) {
    if ( $session_id <= 0 ) {
        return rest_ensure_response( array( 'items' => array() ) );
    }
    $owned = firefly_capture_owned_session( $session_id );
    if ( is_wp_error( $owned ) ) return $owned;

    $args = array(
        'post_type'      => $post_type,
        'post_parent'    => $session_id,
        'post_status'    => array( 'publish', 'draft' ),
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'posts_per_page' => 500,
        'no_found_rows'  => true,
    );
    if ( ! current_user_can( 'manage_options' ) ) {
        $args['author'] = get_current_user_id();
    }
    $q = new WP_Query( $args );
    $items = array();
    foreach ( $q->posts as $p ) {
        $items[] = call_user_func( $formatter, $p );
    }
    return rest_ensure_response( array( 'items' => $items ) );
}

/**
 * Generic create: insert a child post under a session with title, meta,
 * and a session-ownership check. Meta keys are translated from incoming
 * body keys via the $field_map (body key → meta key).
 */
function firefly_capture_create_child( WP_REST_Request $req, $post_type, $field_map, $label, $formatter ) {
    $body = $req->get_json_params() ?: array();
    $parent_session_id = isset( $body['session_id'] ) ? (int) $body['session_id'] : 0;
    if ( $parent_session_id <= 0 ) {
        return new WP_Error( 'firefly_capture_bad_request', 'session_id is required.', array( 'status' => 400 ) );
    }
    $owned = firefly_capture_owned_session( $parent_session_id );
    if ( is_wp_error( $owned ) ) return $owned;

    $title = isset( $body['title'] ) && $body['title'] !== ''
        ? sanitize_text_field( $body['title'] )
        : sprintf( '%s — %s', $label, wp_date( 'M j, Y g:i A' ) );

    $id = wp_insert_post( array(
        'post_type'   => $post_type,
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
        'post_parent' => $parent_session_id,
        'post_title'  => $title,
    ), true );
    if ( is_wp_error( $id ) ) return $id;

    foreach ( $field_map as $body_key => $meta_key ) {
        if ( array_key_exists( $body_key, $body ) ) {
            update_post_meta( $id, $meta_key, $body[ $body_key ] );
        }
    }
    firefly_capture_touch_session( $parent_session_id );
    return rest_ensure_response( call_user_func( $formatter, get_post( $id ) ) );
}

/**
 * Bump a session's post_modified to "now" so it floats to the top of the
 * default-DESC session list whenever any of its child notes/recordings/
 * documents change. Without this bump, the Capture default-session
 * resolver (URL → localStorage → first-in-list) could pick a session that
 * was last edited days before the child the user actually wants to see.
 *
 * Direct $wpdb update on purpose — wp_update_post() would re-fire save_post
 * hooks (and could clobber the post_modified bump with its own value via
 * inferred defaults). The session's content hasn't actually changed, only
 * its "freshness" indicator, so post-update side effects aren't wanted.
 */
function firefly_capture_touch_session( $session_id ) {
    $session_id = (int) $session_id;
    if ( $session_id <= 0 ) return;
    global $wpdb;
    $now_local = current_time( 'mysql' );
    $now_gmt   = current_time( 'mysql', 1 );
    $wpdb->update(
        $wpdb->posts,
        array(
            'post_modified'     => $now_local,
            'post_modified_gmt' => $now_gmt,
        ),
        array(
            'ID'        => $session_id,
            'post_type' => FIREFLY_CAPTURE_SESSION_POST_TYPE,
        ),
        array( '%s', '%s' ),
        array( '%d', '%s' )
    );
    clean_post_cache( $session_id );
}

/**
 * Generic patch: update title and/or any meta key on an existing child
 * post. Used by the SSE-driven pipeline progress updates on the JS side.
 */
function firefly_capture_update_child( WP_REST_Request $req, $post_type, $field_map, $label, $formatter ) {
    $post = firefly_capture_owned_child( $req['id'], $post_type, $label );
    if ( is_wp_error( $post ) ) return $post;
    $body = $req->get_json_params() ?: array();

    if ( isset( $body['title'] ) ) {
        wp_update_post( array( 'ID' => $post->ID, 'post_title' => sanitize_text_field( (string) $body['title'] ) ) );
    }
    foreach ( $field_map as $body_key => $meta_key ) {
        if ( array_key_exists( $body_key, $body ) ) {
            update_post_meta( $post->ID, $meta_key, $body[ $body_key ] );
        }
    }
    firefly_capture_touch_session( $post->post_parent );
    return rest_ensure_response( call_user_func( $formatter, get_post( $post->ID ) ) );
}

/**
 * Generic cascade-delete: drop the Ragsmith message tied to this child,
 * then trash the WP post.
 */
function firefly_capture_delete_child( WP_REST_Request $req, $post_type, $rs_msg_meta_key, $label ) {
    $post = firefly_capture_owned_child( $req['id'], $post_type, $label );
    if ( is_wp_error( $post ) ) return $post;

    // Best-effort detach from the Ragsmith conversation. Failure here
    // should not block the local trash — Ragsmith eventual consistency
    // tolerates orphan messages and the user just wants the WP-side
    // record gone.
    $rs_msg_id = (string) get_post_meta( $post->ID, $rs_msg_meta_key, true );
    if ( $rs_msg_id !== '' && function_exists( 'firefly_ragsmith' ) ) {
        $rs_session_id = (string) get_post_meta( $post->post_parent, FIREFLY_CAPTURE_META_SESSION_RS_ID, true );
        if ( $rs_session_id !== '' ) {
            try {
                firefly_ragsmith()->delete_session_message( $rs_session_id, $rs_msg_id );
            } catch ( Exception $e ) {
                // Logged via WP debug if WP_DEBUG_LOG is on; intentional swallow.
                error_log( '[firefly-capture] ragsmith detach failed: ' . $e->getMessage() );
            }
        }
    }

    $parent_id = (int) $post->post_parent;
    wp_trash_post( $post->ID );
    firefly_capture_touch_session( $parent_id );
    return rest_ensure_response( array( 'deleted' => (int) $post->ID ) );
}

// ---------- Recordings routes ----------

function firefly_capture_recording_field_map() {
    return array(
        'audio_url'                => FIREFLY_CAPTURE_META_RECORDING_AUDIO_URL,
        'duration'                 => FIREFLY_CAPTURE_META_RECORDING_DURATION,
        'transcript'               => FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT,
        'summary'                  => FIREFLY_CAPTURE_META_RECORDING_SUMMARY,
        'kb_path'                  => FIREFLY_CAPTURE_META_RECORDING_KB_PATH,
        'rs_message_id'            => FIREFLY_CAPTURE_META_RECORDING_RS_MSG_ID,
        'share_audio'              => FIREFLY_CAPTURE_META_RECORDING_SHARE_AUDIO,
        'room_audio'               => FIREFLY_CAPTURE_META_RECORDING_ROOM_AUDIO,
        'status'                   => FIREFLY_CAPTURE_META_RECORDING_STATUS,
        'mp3_attachment_id'        => FIREFLY_CAPTURE_META_RECORDING_MP3_ATTACH,
        'mp3_url'                  => FIREFLY_CAPTURE_META_RECORDING_MP3_URL,
        'transcript_attachment_id' => FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT_ATTACH,
        'transcript_url'           => FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT_URL,
        'summary_attachment_id'    => FIREFLY_CAPTURE_META_RECORDING_SUMMARY_ATTACH,
        'summary_url'              => FIREFLY_CAPTURE_META_RECORDING_SUMMARY_URL,
    );
}

function firefly_capture_route_list_recordings( WP_REST_Request $req ) {
    return firefly_capture_list_children(
        FIREFLY_CAPTURE_RECORDING_POST_TYPE,
        (int) $req->get_param( 'session' ),
        'firefly_capture_format_recording'
    );
}
function firefly_capture_route_create_recording( WP_REST_Request $req ) {
    return firefly_capture_create_child( $req, FIREFLY_CAPTURE_RECORDING_POST_TYPE, firefly_capture_recording_field_map(), 'Recording', 'firefly_capture_format_recording' );
}
function firefly_capture_route_get_recording( WP_REST_Request $req ) {
    $post = firefly_capture_owned_child( $req['id'], FIREFLY_CAPTURE_RECORDING_POST_TYPE, 'Recording' );
    if ( is_wp_error( $post ) ) return $post;
    return rest_ensure_response( firefly_capture_format_recording( $post ) );
}
/**
 * Recording update: after the generic field-map write, mirror transcript +
 * summary text to .txt files in the WP media library so the recording has
 * portable download URLs even if Ragsmith's copies get purged.
 *
 * Replaces any previously-saved attachment for the same recording when the
 * text content changes (e.g. after a speaker rename rewrites SPEAKER_XX
 * tokens), so the user always sees the latest version under one URL.
 */
function firefly_capture_route_update_recording( WP_REST_Request $req ) {
    $resp = firefly_capture_update_child( $req, FIREFLY_CAPTURE_RECORDING_POST_TYPE, firefly_capture_recording_field_map(), 'Recording', 'firefly_capture_format_recording' );
    if ( is_wp_error( $resp ) ) return $resp;

    $body = $req->get_json_params() ?: array();
    $post_id = (int) $req['id'];

    if ( isset( $body['transcript'] ) && $body['transcript'] !== '' ) {
        firefly_capture_save_text_attachment(
            $post_id,
            'transcript',
            (string) $body['transcript'],
            FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT_ATTACH,
            FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT_URL
        );
    }
    if ( isset( $body['summary'] ) && $body['summary'] !== '' ) {
        firefly_capture_save_text_attachment(
            $post_id,
            'summary',
            (string) $body['summary'],
            FIREFLY_CAPTURE_META_RECORDING_SUMMARY_ATTACH,
            FIREFLY_CAPTURE_META_RECORDING_SUMMARY_URL
        );
    }

    // Re-emit the post payload so the JS sees the freshly-stamped URLs.
    return rest_ensure_response( firefly_capture_format_recording( get_post( $post_id ) ) );
}

/**
 * Write a text blob to the WP media library and stamp the resulting
 * attachment id + URL onto the parent recording. Replaces any prior
 * attachment for the same kind so the URL stays stable.
 */
function firefly_capture_save_text_attachment( $post_id, $kind, $text, $attach_meta_key, $url_meta_key ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $post = get_post( $post_id );
    if ( ! $post ) return;
    $slug = sanitize_title( $post->post_title ) ?: ( 'recording-' . $post_id );
    $name = sanitize_file_name( $slug . '-' . $kind . '.txt' );

    $upload = wp_upload_bits( $name, null, $text );
    if ( ! empty( $upload['error'] ) ) {
        error_log( '[firefly-capture] text upload failed: ' . $upload['error'] );
        return;
    }

    // Replace prior attachment (and its underlying file) so we don't
    // accumulate stale .txt copies of every speaker rename pass.
    $previous = (int) get_post_meta( $post_id, $attach_meta_key, true );
    if ( $previous > 0 ) {
        wp_delete_attachment( $previous, true );
    }

    $attach_id = wp_insert_attachment( array(
        'post_mime_type' => 'text/plain',
        'post_title'     => $slug . ' — ' . $kind,
        'post_status'    => 'inherit',
        'post_content'   => '',
        'post_parent'    => $post_id,
    ), $upload['file'], $post_id );
    if ( is_wp_error( $attach_id ) ) {
        error_log( '[firefly-capture] text attach insert failed: ' . $attach_id->get_error_message() );
        return;
    }
    wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );

    update_post_meta( $post_id, $attach_meta_key, (int) $attach_id );
    update_post_meta( $post_id, $url_meta_key,    (string) wp_get_attachment_url( $attach_id ) );
}
/**
 * Recording delete cascade: drops 3 things in order, all best-effort —
 *   1. The WP media-library MP3 attachment (so the file in /uploads/
 *      doesn't orphan when its parent recording post is gone)
 *   2. The Ragsmith-side audio file (so local.ragsmith.net's recordings
 *      manager reflects the deletion)
 *   3. The Ragsmith conversation message + the WP post (handled by the
 *      generic firefly_capture_delete_child)
 *
 * Any individual step's failure is logged but doesn't block the next —
 * the user just wants the recording gone, and partial cleanup is
 * recoverable later from either side.
 */
function firefly_capture_route_delete_recording( WP_REST_Request $req ) {
    $post = firefly_capture_owned_child( $req['id'], FIREFLY_CAPTURE_RECORDING_POST_TYPE, 'Recording' );
    if ( is_wp_error( $post ) ) return $post;

    // 1. WP-side attachments (audio MP3 + transcript .txt + summary .txt).
    //    Force-delete each so the file under /uploads is gone too.
    foreach ( array(
        FIREFLY_CAPTURE_META_RECORDING_MP3_ATTACH,
        FIREFLY_CAPTURE_META_RECORDING_TRANSCRIPT_ATTACH,
        FIREFLY_CAPTURE_META_RECORDING_SUMMARY_ATTACH,
    ) as $attach_meta_key ) {
        $aid = (int) get_post_meta( $post->ID, $attach_meta_key, true );
        if ( $aid > 0 ) {
            wp_delete_attachment( $aid, true );
        }
    }

    // 2. Ragsmith-side audio file.
    $audio_url = (string) get_post_meta( $post->ID, FIREFLY_CAPTURE_META_RECORDING_AUDIO_URL, true );
    if ( $audio_url !== '' && function_exists( 'firefly_ragsmith' ) ) {
        $filename = basename( $audio_url );
        if ( $filename !== '' ) {
            try {
                firefly_ragsmith()->delete_audio_file( $filename );
            } catch ( Exception $e ) {
                error_log( '[firefly-capture] ragsmith audio delete failed: ' . $e->getMessage() );
            }
        }
    }

    // 3. Conversation message detach + WP trash.
    return firefly_capture_delete_child( $req, FIREFLY_CAPTURE_RECORDING_POST_TYPE, FIREFLY_CAPTURE_META_RECORDING_RS_MSG_ID, 'Recording' );
}

// ---------- Documents routes ----------

function firefly_capture_document_field_map() {
    return array(
        'filename'           => FIREFLY_CAPTURE_META_DOC_FILENAME,
        'mime'               => FIREFLY_CAPTURE_META_DOC_MIME,
        'ephemeral_kb_path'  => FIREFLY_CAPTURE_META_DOC_EPHEMERAL_KB_PATH,
        'rs_message_id'      => FIREFLY_CAPTURE_META_DOC_RS_MSG_ID,
        'rs_session_id'      => FIREFLY_CAPTURE_META_DOC_RS_SESSION_ID,
        'source_marker'      => FIREFLY_CAPTURE_META_DOC_SOURCE_MARKER,
        'chunk_count'        => FIREFLY_CAPTURE_META_DOC_CHUNK_COUNT,
        'facts_count'        => FIREFLY_CAPTURE_META_DOC_FACTS_COUNT,
        'status'             => FIREFLY_CAPTURE_META_DOC_STATUS,
        'file_attachment_id' => FIREFLY_CAPTURE_META_DOC_FILE_ATTACH,
        'file_url'           => FIREFLY_CAPTURE_META_DOC_FILE_URL,
        'text'               => FIREFLY_CAPTURE_META_DOC_TEXT,
        // facts is a JSON array; client sends it as a JSON string so the
        // generic update_child can store it as a single meta value.
        'facts'              => FIREFLY_CAPTURE_META_DOC_FACTS,
    );
}

function firefly_capture_route_list_documents( WP_REST_Request $req ) {
    return firefly_capture_list_children(
        FIREFLY_CAPTURE_DOCUMENT_POST_TYPE,
        (int) $req->get_param( 'session' ),
        'firefly_capture_format_document'
    );
}
function firefly_capture_route_create_document( WP_REST_Request $req ) {
    return firefly_capture_create_child( $req, FIREFLY_CAPTURE_DOCUMENT_POST_TYPE, firefly_capture_document_field_map(), 'Document', 'firefly_capture_format_document' );
}
function firefly_capture_route_get_document( WP_REST_Request $req ) {
    $post = firefly_capture_owned_child( $req['id'], FIREFLY_CAPTURE_DOCUMENT_POST_TYPE, 'Document' );
    if ( is_wp_error( $post ) ) return $post;
    return rest_ensure_response( firefly_capture_format_document( $post ) );
}
function firefly_capture_route_update_document( WP_REST_Request $req ) {
    return firefly_capture_update_child( $req, FIREFLY_CAPTURE_DOCUMENT_POST_TYPE, firefly_capture_document_field_map(), 'Document', 'firefly_capture_format_document' );
}
function firefly_capture_route_delete_document( WP_REST_Request $req ) {
    $post = firefly_capture_owned_child( $req['id'], FIREFLY_CAPTURE_DOCUMENT_POST_TYPE, 'Document' );
    if ( is_wp_error( $post ) ) return $post;

    // 1. WP-side media attachment (the user-facing download copy).
    //    force=true removes the file under /uploads too.
    $aid = (int) get_post_meta( $post->ID, FIREFLY_CAPTURE_META_DOC_FILE_ATTACH, true );
    if ( $aid > 0 ) {
        wp_delete_attachment( $aid, true );
    }

    // 2. Ragsmith-side fact-ingest chip + the facts it produced. The
    //    document was ingested under mode=extract_facts_only — Ragsmith
    //    persisted both a chip-typed system message and a set of
    //    session_memory rows tagged with our source_marker. The chip
    //    delete endpoint handles both in one call. Best-effort: if
    //    Ragsmith is down we still want the WP-side post gone.
    $rs_session_id = (string) get_post_meta( $post->ID, FIREFLY_CAPTURE_META_DOC_RS_SESSION_ID, true );
    if ( $rs_session_id === '' ) {
        // Older docs (pre-chip flow) stored only the parent session's
        // rs_session_id on the session post — fall back to that so
        // delete still cascades for legacy entries.
        $rs_session_id = (string) get_post_meta( $post->post_parent, FIREFLY_CAPTURE_META_SESSION_RS_ID, true );
    }
    $chip_id = (string) get_post_meta( $post->ID, FIREFLY_CAPTURE_META_DOC_RS_MSG_ID, true );
    if ( $rs_session_id !== '' && $chip_id !== '' && function_exists( 'firefly_ragsmith' ) ) {
        try {
            firefly_ragsmith()->delete_fact_ingest_chip( $rs_session_id, $chip_id );
            // Clear the meta so the generic delete helper below doesn't
            // also try to delete the (now-gone) chip via the per-message
            // endpoint and log a spurious 404.
            delete_post_meta( $post->ID, FIREFLY_CAPTURE_META_DOC_RS_MSG_ID );
        } catch ( Exception $e ) {
            error_log( '[firefly-capture] ragsmith chip delete failed: ' . $e->getMessage() );
        }
    }

    // 3. WP post trash. With rs_message_id cleared above the generic
    //    helper's message-delete pass is a no-op.
    return firefly_capture_delete_child( $req, FIREFLY_CAPTURE_DOCUMENT_POST_TYPE, FIREFLY_CAPTURE_META_DOC_RS_MSG_ID, 'Document' );
}
