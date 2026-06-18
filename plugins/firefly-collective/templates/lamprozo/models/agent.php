<?php
/**
 * Agent — Firefly Capture Conversational UI
 *
 * Sibling admin page to Capture. Where Capture is the dictation +
 * recording + document INGEST surface, Agent is the OUTBOUND chat
 * surface: a clean WP-native UI that talks to the Ragsmith
 * conversation bound to each Capture session.
 *
 * Session model: one-to-one with Capture.
 *   firefly_note_session post           ←──── shared
 *     ├─ _firefly_note_session_rs_id    ←──── shared (Ragsmith conversation id)
 *     └─ … (notes, recordings, documents created by Capture)
 *
 * Picking a session in Agent loads the bound conversation's history
 * from Ragsmith (GET /sessions/{rs_id}) and lets the user continue it.
 * If the session has no rs_session_id yet, the first message creates
 * the Ragsmith conversation and we bind it back via the existing
 * /firefly-capture/v1/sessions/{wp_id}/ragsmith route.
 *
 * REST surface: all new routes register under FIREFLY_CAPTURE_REST_NS
 * ("firefly-capture/v1") to keep the Capture + Agent feature pair as
 * a single REST namespace.
 *
 *   POST   /chat                              → SSE-stream a chat turn
 *   POST   /chat/cancel                       → cancel in-flight chat
 *   POST   /chat/tool-approval                → HITL response
 *   GET    /sessions/{id}/history             → load Ragsmith history
 *   DELETE /messages/{wp_id}/{rs_message_id}  → drop a message pair
 *
 * This file is template-name agnostic: it uses basename(dirname(__FILE__, 2))
 * to derive the template directory name, so the same file works in
 * firefly/, firefly-v2/, default/, and any future templates.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------- Constants ----------

const FIREFLY_AGENT_MENU_SLUG = 'firefly-agent';

// ---------- Permission helper ----------

if ( ! function_exists( 'firefly_agent_can_access' ) ) {
    function firefly_agent_can_access() {
        // Same capability as Capture so a user who can see one can see the other.
        return current_user_can( 'manage_options' );
    }
}

// ---------- Admin menu ----------

add_action( 'admin_menu', function () {
    if ( ! firefly_agent_can_access() ) return;

    add_menu_page(
        'Agent',
        'Agent',
        'manage_options',
        FIREFLY_AGENT_MENU_SLUG,
        'firefly_agent_render_page',
        // Chat-bubble SVG in WP admin gray. Matches the visual weight
        // of Capture's sparkles icon on the same nav.
        'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iI2E3YWFhZCI+PHBhdGggZD0iTTIwIDJINGMtMS4xIDAtMiAuOS0yIDJ2MTRsNC00aDE0YzEuMSAwIDItLjkgMi0yVjRjMC0xLjEtLjktMi0yLTJ6Ii8+PC9zdmc+',
        // Position 26.1 places Agent immediately below Capture (26)
        // in the WP admin nav. Decimal positions are honored by WP
        // for stable ordering against future menu items.
        26.1
    );
}, 99 );

function firefly_agent_render_page() {
    if ( ! firefly_agent_can_access() ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'firefly-collective' ) );
    }
    $view = plugin_dir_path( __FILE__ ) . '../views/agent.php';
    if ( file_exists( $view ) ) {
        include $view;
    }
}

// ---------- Asset enqueue (only on this page) ----------

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'toplevel_page_' . FIREFLY_AGENT_MENU_SLUG ) return;
    if ( ! firefly_agent_can_access() ) return;

    // The firefly-ragsmith bridge enqueues `window.FireflyRagsmith` (REST
    // root, nonce, rsFetch, etc.) that our JS uses for non-streaming
    // calls (listAgents, listSessions). Capture does the same thing.
    if ( function_exists( 'firefly_ragsmith_enqueue_dictation' ) ) {
        firefly_ragsmith_enqueue_dictation();
    }

    // Template-agnostic asset URL (mirrors capture.php).
    $template_dir  = dirname( __FILE__, 2 );
    $template_name = basename( $template_dir );
    $asset_base    = plugins_url( "templates/{$template_name}/assets", WP_PLUGIN_DIR . '/firefly-collective/firefly.php' );

    // Vendored marked.js for client-side markdown rendering of assistant
    // messages. Loaded before agent.js so window.marked is in scope.
    wp_enqueue_script(
        'firefly-agent-marked',
        $asset_base . '/vendor/marked.min.js',
        array(),
        firefly_agent_asset_version( $template_dir . '/assets/vendor/marked.min.js' ),
        true
    );

    wp_enqueue_style(
        'firefly-agent',
        $asset_base . '/css/agent.css',
        array(),
        firefly_agent_asset_version( $template_dir . '/assets/css/agent.css' )
    );
    wp_enqueue_script(
        'firefly-agent',
        $asset_base . '/js/agent.js',
        array( 'firefly-agent-marked' ),
        firefly_agent_asset_version( $template_dir . '/assets/js/agent.js' ),
        true
    );
    // The REST root we surface to the browser is the SAME firefly-capture/v1
    // root Capture uses — Agent + Capture are one app. Routes are scoped
    // by path, not namespace. The nonce lets the browser hit our routes
    // authenticated under the current WP user session.
    //
    // We also surface default_agent + default_model so the toolbar can
    // show the resolved agent/model badge BEFORE the first chat turn
    // finishes (the history endpoint only returns these once the session
    // is bound to a Ragsmith conversation).
    $default_agent = function_exists( 'firefly_ragsmith_setting' )
        ? (string) firefly_ragsmith_setting( 'default_agent', '' )
        : '';
    $default_model = function_exists( 'firefly_ragsmith_setting' )
        ? (string) firefly_ragsmith_setting( 'default_model', '' )
        : '';
    wp_add_inline_script(
        'firefly-agent',
        sprintf(
            'window.FireflyAgent = window.FireflyAgent || {}; '
                . 'window.FireflyAgent.restUrl = %s; '
                . 'window.FireflyAgent.nonce = %s; '
                . 'window.FireflyAgent.defaultAgent = %s; '
                . 'window.FireflyAgent.defaultModel = %s;',
            wp_json_encode( esc_url_raw( rest_url( 'firefly-capture/v1' ) ) ),
            wp_json_encode( wp_create_nonce( 'wp_rest' ) ),
            wp_json_encode( $default_agent ),
            wp_json_encode( $default_model )
        ),
        'before'
    );
} );

if ( ! function_exists( 'firefly_agent_asset_version' ) ) {
    /**
     * Per-file mtime cache busting so the browser always pulls the
     * latest CSS/JS after a deploy without us having to bump a string.
     */
    function firefly_agent_asset_version( $abs_path ) {
        return file_exists( $abs_path ) ? (string) filemtime( $abs_path ) : '0';
    }
}

// ---------- REST routes ----------

add_action( 'rest_api_init', function () {
    $perm = function () { return firefly_agent_can_access(); };
    $ns   = 'firefly-capture/v1';

    // POST /chat — open an SSE stream that forwards /chat/agent. The
    // browser POSTs { session_id, message, agent?, model? }; we
    // resolve session_id → rs_session_id from the Capture post,
    // inject default agent/model, hand off to the existing SSE proxy.
    register_rest_route( $ns, '/chat', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_agent_route_chat',
    ) );

    // POST /chat/cancel — abort the in-flight chat on Ragsmith side.
    register_rest_route( $ns, '/chat/cancel', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_agent_route_chat_cancel',
    ) );

    // POST /chat/tool-approval — HITL response when the agent emitted
    // a tool_approval SSE event. Body: { approval_id, decision }.
    register_rest_route( $ns, '/chat/tool-approval', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_agent_route_tool_approval',
    ) );

    // GET /sessions/{id}/history — load the Ragsmith conversation
    // history for a WP session. Returns { messages, agent, model,
    // display_model, provider }. Empty when the session has no
    // rs_session_id yet (fresh, no chat has happened).
    register_rest_route( $ns, '/sessions/(?P<id>\d+)/history', array(
        'methods'             => WP_REST_Server::READABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_agent_route_history',
    ) );

    // DELETE /messages/{wp_session_id}/{rs_message_id} — drop a
    // message pair from the bound Ragsmith conversation. We require
    // the WP session id in the path so we can verify ownership before
    // forwarding the delete to Ragsmith.
    register_rest_route( $ns, '/messages/(?P<wp_session_id>\d+)/(?P<rs_message_id>\d+)', array(
        'methods'             => WP_REST_Server::DELETABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_agent_route_message_delete',
    ) );

    // GET /sessions/{wp_session_id}/extraction-status — Web UI parity.
    // After a chat turn streams to text_complete the server is still
    // writing the assistant message to the DB asynchronously; the
    // message_id isn't known until that finishes. The browser polls
    // this endpoint (~1s cadence, 30s max) and gets back
    // { extracting, latest_message_id } — the latter is the rs_message_id
    // we attach to the assistant bubble so its Delete + Redo buttons
    // can hit the DB-backed message-delete endpoint.
    register_rest_route( $ns, '/sessions/(?P<id>\d+)/extraction-status', array(
        'methods'             => WP_REST_Server::READABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_agent_route_extraction_status',
    ) );

    // POST /agent/warm — pre-loads everything the chat will need so the
    // first message doesn't pay the cold-start tax. Streams SSE progress
    // from Ragsmith's /agents/{name}/load — KBs, embedding model, helper
    // LLMs (document chooser, vision), the chat model itself, etc. The
    // browser parses the same SSE shape it uses for /chat.
    register_rest_route( $ns, '/agent/warm', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_agent_route_warm',
    ) );

    // GET /agent/status — cheap readiness check that does NOT trigger a
    // load. Returns Ragsmith's /agents/{name}/status response verbatim so
    // the Capture + Agent UIs can render their "Warm · Xm ago" indicator
    // pills. Agent name defaults to firefly-ragsmith's default_agent
    // setting when not passed as ?agent=… on the query string. Reused
    // by both the Capture and Agent admins since both have Warm up
    // buttons that need the same status data.
    register_rest_route( $ns, '/agent/status', array(
        'methods'             => WP_REST_Server::READABLE,
        'permission_callback' => $perm,
        'callback'            => 'firefly_agent_route_status',
    ) );
} );

// ---------- Helpers ----------

/**
 * Resolve an agent's "default tool state" from its Ragsmith admin
 * settings, mirroring what the Ragsmith Web UI does client-side when
 * it builds its tool-chip strip (web/js/main.js:1255-1350).
 *
 * The WP Agent UI deliberately has NO tool toggles — the chat is
 * meant to be a faithful headless rendering of the agent's admin
 * config. So we resolve those defaults server-side here and inject
 * them into the chat body before forwarding.
 *
 * Returns:
 *   array(
 *     'disabled_tools'   => list<string>   // tools with default_on === false
 *                                          // + chain_<id> entries for chains with default_on === false
 *     'disable_planning' => bool           // true unless use_planning && planning_default_on !== false
 *   )
 *   or null on fetch failure (caller should treat as "no overrides").
 *
 * Cached for 5 min per agent via transient so chat turns don't pay
 * a GET /agents/{name} round-trip every time. Bump by changing
 * FIREFLY_AGENT_DEFAULTS_CACHE_VER below if the resolution logic
 * changes shape.
 */
const FIREFLY_AGENT_DEFAULTS_CACHE_VER = 1;
function firefly_agent_resolve_agent_defaults( $agent_name ) {
    $agent_name = (string) $agent_name;
    if ( $agent_name === '' ) return null;
    $cache_key = 'firefly_agent_defaults_v' . FIREFLY_AGENT_DEFAULTS_CACHE_VER . '_' . md5( $agent_name );
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) return $cached;

    if ( ! function_exists( 'firefly_ragsmith' ) ) return null;
    $client = firefly_ragsmith();
    if ( ! is_object( $client ) || ! method_exists( $client, 'get_agent' ) ) return null;
    $resp = $client->get_agent( $agent_name );
    if ( is_wp_error( $resp ) || ! is_array( $resp ) ) return null;

    $settings     = isset( $resp['settings'] ) && is_array( $resp['settings'] ) ? $resp['settings'] : array();
    $tool_configs = isset( $settings['tool_configs'] ) && is_array( $settings['tool_configs'] ) ? $settings['tool_configs'] : array();
    $tool_chains  = isset( $settings['tool_chains'] )  && is_array( $settings['tool_chains'] )  ? $settings['tool_chains']  : array();

    $disabled = array();
    foreach ( $tool_configs as $tool_name => $cfg ) {
        // Strict false — the Web UI logic is `default_on !== false`, so
        // missing keys + truthy values both leave the tool ENABLED. Only
        // an explicit `false` turns the tool off by default.
        if ( is_array( $cfg ) && array_key_exists( 'default_on', $cfg ) && $cfg['default_on'] === false ) {
            $disabled[] = (string) $tool_name;
        }
    }
    foreach ( $tool_chains as $chain ) {
        if ( ! is_array( $chain ) ) continue;
        if ( empty( $chain['enabled'] ) ) continue;
        $id = isset( $chain['id'] ) ? (string) $chain['id'] : '';
        if ( $id === '' ) continue;
        if ( array_key_exists( 'default_on', $chain ) && $chain['default_on'] === false ) {
            // Mirrors `chain_<id>` naming the Web UI uses in disabledTools.
            $disabled[] = 'chain_' . str_replace( '-', '_', $id );
        }
    }

    // Planning: agent must opt-in via use_planning AND not have explicitly
    // disabled the default-on (`planning_default_on !== false`).
    $use_planning        = ! empty( $settings['use_planning'] );
    $planning_default_on = ( ! array_key_exists( 'planning_default_on', $settings ) ) || $settings['planning_default_on'] !== false;
    $disable_planning    = ! ( $use_planning && $planning_default_on );

    $result = array(
        'disabled_tools'   => array_values( array_unique( $disabled ) ),
        'disable_planning' => (bool) $disable_planning,
    );
    set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
    return $result;
}

/**
 * Resolve a WP firefly_note_session post id to the Ragsmith
 * conversation id stored on it. Returns null if the post doesn't
 * exist, isn't owned by the current user, or has no rs_session_id
 * yet (fresh session).
 */
function firefly_agent_resolve_rs_session_id( $wp_session_id ) {
    $wp_session_id = (int) $wp_session_id;
    if ( $wp_session_id <= 0 ) return null;
    // Reuse Capture's ownership check so a user can't talk to a
    // session they don't own. firefly_capture_owned_session() is
    // defined in capture.php and returns the WP_Post on success
    // or a WP_Error on failure.
    if ( ! function_exists( 'firefly_capture_owned_session' ) ) return null;
    $owned = firefly_capture_owned_session( $wp_session_id );
    if ( is_wp_error( $owned ) ) return null;
    $rs_id = (string) get_post_meta( $wp_session_id, FIREFLY_CAPTURE_META_SESSION_RS_ID, true );
    return $rs_id !== '' ? $rs_id : null;
}

/**
 * Bind a fresh Ragsmith conversation id back onto the WP session
 * post after the first chat turn creates it. Mirrors what
 * /firefly-capture/v1/sessions/{id}/ragsmith does, but inline so
 * the chat handler doesn't have to do a second HTTP round-trip.
 */
function firefly_agent_bind_rs_session_id( $wp_session_id, $rs_session_id ) {
    if ( $rs_session_id === '' ) return;
    update_post_meta( (int) $wp_session_id, FIREFLY_CAPTURE_META_SESSION_RS_ID, sanitize_text_field( $rs_session_id ) );
}

// ---------- Route handlers ----------

/**
 * POST /firefly-capture/v1/chat
 *
 * Body shape (JSON):
 *   {
 *     "session_id":      <wp_post_id>,    // required, firefly_note_session post id
 *     "message":         "<text>",        // required, user's input
 *     "agent":           "<name>",        // optional, falls back to firefly-ragsmith default_agent
 *     "model":           "<tier|tag>",    // optional, falls back to default_model
 *     "temperature":     <float>,         // optional override
 *     "use_kb":          <bool>,          // optional override
 *     "kb_top":          <int>,           // optional override
 *     "kb_threshold":    <float>          // optional override
 *   }
 *
 * Responds with text/event-stream forwarded from Ragsmith /chat/agent.
 * Event taxonomy the browser should handle:
 *   text_chunk         → token of the assistant's response
 *   text_complete      → final response + metadata
 *   done               → stream finished
 *   error              → terminal failure
 *   thinking_chunk     → extended-thinking tokens (Claude opus etc.)
 *   vision_status / search_status / tool_status / doc_status
 *   tool_progress / tool_approval / doc_ready / stream_retract
 */
function firefly_agent_route_chat( WP_REST_Request $req ) {
    $body = $req->get_json_params() ?: array();

    $wp_session_id = isset( $body['session_id'] ) ? (int) $body['session_id'] : 0;
    $message       = isset( $body['message'] )    ? (string) $body['message'] : '';
    if ( $wp_session_id <= 0 || $message === '' ) {
        return new WP_Error( 'firefly_agent_bad_request', 'session_id and message are required.', array( 'status' => 400 ) );
    }

    // Ownership check + resolve to Ragsmith conversation id.
    if ( ! function_exists( 'firefly_capture_owned_session' ) ) {
        return new WP_Error( 'firefly_agent_no_capture', 'Capture is not available.', array( 'status' => 500 ) );
    }
    $owned = firefly_capture_owned_session( $wp_session_id );
    if ( is_wp_error( $owned ) ) return $owned;
    $rs_session_id = firefly_agent_resolve_rs_session_id( $wp_session_id );

    // Assemble the Ragsmith body. Default model/agent injection mirrors
    // the same fallback the dictation + audio + document routes use,
    // so a fresh-out-of-the-box install never hits a naked LLM call.
    $payload = array(
        'message' => $message,
    );
    if ( $rs_session_id ) {
        $payload['session_id'] = $rs_session_id;
    }
    // Whitelist of body fields the browser may pass through to Ragsmith.
    // Mirrors the Web UI's sendChatMessage() body shape so chat_with_agent
    // takes the same code path (notes_intent → synthesis pre-flight, etc.).
    // Any field NOT in this list is dropped.
    $passthrough = array(
        'agent', 'model', 'temperature', 'fidelity', 'memory_level',
        'use_kb', 'kb_top', 'kb_threshold',
        'initial_prompt', 'web_search', 'web_search_depth', 'web_search_images',
        'attachments', 'disabled_tools', 'disable_planning', 'container_token',
        'debug',
    );
    foreach ( $passthrough as $k ) {
        if ( array_key_exists( $k, $body ) && $body[ $k ] !== null && $body[ $k ] !== '' ) {
            $payload[ $k ] = $body[ $k ];
        }
    }
    if ( empty( $payload['agent'] ) && function_exists( 'firefly_ragsmith_setting' ) ) {
        $default_agent = (string) firefly_ragsmith_setting( 'default_agent', '' );
        if ( $default_agent !== '' ) $payload['agent'] = $default_agent;
    }
    if ( empty( $payload['model'] ) && function_exists( 'firefly_ragsmith_setting' ) ) {
        $default_model = (string) firefly_ragsmith_setting( 'default_model', '' );
        if ( $default_model !== '' ) $payload['model'] = $default_model;
    }

    // Resolve agent-level default tool toggles + planning state from the
    // agent's admin config. The WP Agent UI exposes no toggles — the
    // chat is a faithful headless rendering of whatever the Ragsmith
    // admin has set for this agent. If the browser later grows its
    // own per-chat overrides, those win (we only fill in what isn't set).
    $agent_for_defaults = isset( $payload['agent'] ) ? (string) $payload['agent'] : '';
    if ( $agent_for_defaults !== '' ) {
        $defaults = firefly_agent_resolve_agent_defaults( $agent_for_defaults );
        if ( is_array( $defaults ) ) {
            // UNION the browser-supplied disabled_tools with the agent's
            // admin-config defaults. Browser-side per-chat toggles (e.g.
            // the Memory chip that disables `recall`) can only ADD to the
            // disabled set, never remove from it — the agent admin still
            // owns which tools are off by default. Empty browser list is
            // treated the same as not sending one.
            $browser_disabled = array();
            if ( isset( $payload['disabled_tools'] ) && is_array( $payload['disabled_tools'] ) ) {
                foreach ( $payload['disabled_tools'] as $t ) {
                    $t = (string) $t;
                    if ( $t !== '' ) $browser_disabled[] = $t;
                }
            }
            $agent_disabled = is_array( $defaults['disabled_tools'] ) ? $defaults['disabled_tools'] : array();
            $merged = array_values( array_unique( array_merge( $agent_disabled, $browser_disabled ) ) );
            if ( ! empty( $merged ) ) {
                $payload['disabled_tools'] = $merged;
            }
            if ( ! array_key_exists( 'disable_planning', $payload ) && ! empty( $defaults['disable_planning'] ) ) {
                $payload['disable_planning'] = true;
            }
        }
    }

    // For a fresh session (no rs_session_id yet) we tag the source so
    // the new Ragsmith conversation is identifiable as a Capture/Agent
    // origin in the Ragsmith UI's session filter.
    if ( ! $rs_session_id ) {
        $payload['source'] = 'wp-agent';
    }

    // Tip the wp_session_id to the JS-side via a header so the browser
    // can correlate the SSE response back to the WP session that
    // initiated it (text_complete carries the Ragsmith session_id; the
    // browser binds it back via /sessions/{wp_id}/ragsmith on first
    // response, identical to Capture's saveNoteToAI flow).
    @header( 'X-Firefly-Agent-Wp-Session: ' . (int) $wp_session_id );

    // Hand off to the existing SSE proxy. This call NEVER RETURNS —
    // the proxy writes SSE headers, streams the upstream response,
    // and calls exit(). Anything after this line is unreachable.
    if ( ! class_exists( 'Firefly_Ragsmith_SSE_Proxy' ) ) {
        return new WP_Error( 'firefly_agent_no_proxy', 'SSE proxy not available.', array( 'status' => 500 ) );
    }
    Firefly_Ragsmith_SSE_Proxy::stream_chat( $payload );
    // Unreachable.
}

/**
 * POST /firefly-capture/v1/chat/cancel — abort the in-flight chat.
 */
function firefly_agent_route_chat_cancel( WP_REST_Request $req ) {
    if ( ! function_exists( 'firefly_ragsmith' ) ) {
        return new WP_Error( 'firefly_agent_no_bridge', 'Ragsmith bridge not loaded.', array( 'status' => 500 ) );
    }
    $client = firefly_ragsmith();
    if ( ! is_object( $client ) || ! method_exists( $client, 'cancel_chat' ) ) {
        return new WP_Error( 'firefly_agent_no_cancel', 'cancel_chat not implemented on client.', array( 'status' => 500 ) );
    }
    return rest_ensure_response( $client->cancel_chat() );
}

/**
 * POST /firefly-capture/v1/chat/tool-approval
 *
 * Body: { approval_id, decision }  — forwards verbatim.
 */
function firefly_agent_route_tool_approval( WP_REST_Request $req ) {
    $body = $req->get_json_params() ?: array();
    $approval_id = isset( $body['approval_id'] ) ? (string) $body['approval_id'] : '';
    $decision    = isset( $body['decision'] )    ? (string) $body['decision']    : '';
    if ( $approval_id === '' || $decision === '' ) {
        return new WP_Error( 'firefly_agent_bad_request', 'approval_id and decision are required.', array( 'status' => 400 ) );
    }
    if ( ! function_exists( 'firefly_ragsmith' ) ) {
        return new WP_Error( 'firefly_agent_no_bridge', 'Ragsmith bridge not loaded.', array( 'status' => 500 ) );
    }
    $client = firefly_ragsmith();
    if ( ! is_object( $client ) || ! method_exists( $client, 'tool_approval' ) ) {
        return new WP_Error( 'firefly_agent_no_tool_approval', 'tool_approval not implemented on client.', array( 'status' => 500 ) );
    }
    return rest_ensure_response( $client->tool_approval( $approval_id, $decision ) );
}

/**
 * GET /firefly-capture/v1/sessions/{id}/history
 *
 * Returns the conversation history for a Capture session. Shape:
 *   {
 *     "rs_session_id":   "<id-or-empty>",
 *     "bound":           <bool>,         // false = no chat has happened yet
 *     "messages":        [...],          // Ragsmith message objects (role, content, timestamp, metadata)
 *     "agent":           "<name>",       // conversation-level agent (from Ragsmith conv row)
 *     "model":           "<id>",
 *     "display_model":   "<friendly>",
 *     "provider":        "<anthropic|openai|local|...>"
 *   }
 */
function firefly_agent_route_history( WP_REST_Request $req ) {
    $wp_session_id = (int) $req['id'];
    if ( ! function_exists( 'firefly_capture_owned_session' ) ) {
        return new WP_Error( 'firefly_agent_no_capture', 'Capture is not available.', array( 'status' => 500 ) );
    }
    $owned = firefly_capture_owned_session( $wp_session_id );
    if ( is_wp_error( $owned ) ) return $owned;

    $rs_session_id = firefly_agent_resolve_rs_session_id( $wp_session_id );
    if ( ! $rs_session_id ) {
        // No Ragsmith conversation bound yet — return an empty shell so
        // the UI can render "fresh session" state cleanly without an
        // error path.
        return rest_ensure_response( array(
            'rs_session_id' => '',
            'bound'         => false,
            'messages'      => array(),
            'agent'         => '',
            'model'         => '',
            'display_model' => '',
            'provider'      => '',
        ) );
    }

    if ( ! function_exists( 'firefly_ragsmith' ) ) {
        return new WP_Error( 'firefly_agent_no_bridge', 'Ragsmith bridge not loaded.', array( 'status' => 500 ) );
    }
    $client = firefly_ragsmith();
    if ( ! is_object( $client ) || ! method_exists( $client, 'get_session' ) ) {
        return new WP_Error( 'firefly_agent_no_get_session', 'get_session not implemented on client.', array( 'status' => 500 ) );
    }
    $resp = $client->get_session( $rs_session_id );
    if ( is_wp_error( $resp ) ) return $resp;

    // Normalize to a stable response shape regardless of what the
    // Ragsmith client returns (it could be an object, array, etc.).
    $messages      = isset( $resp['messages'] )      ? $resp['messages']      : array();
    $agent         = isset( $resp['agent'] )         ? (string) $resp['agent'] : '';
    $model         = isset( $resp['model'] )         ? (string) $resp['model'] : '';
    $display_model = isset( $resp['display_model'] ) ? (string) $resp['display_model'] : '';
    $provider      = isset( $resp['provider'] )      ? (string) $resp['provider']      : '';

    return rest_ensure_response( array(
        'rs_session_id' => $rs_session_id,
        'bound'         => true,
        'messages'      => $messages,
        'agent'         => $agent,
        'model'         => $model,
        'display_model' => $display_model,
        'provider'      => $provider,
    ) );
}

/**
 * POST /firefly-capture/v1/agent/warm
 *
 * Body (JSON, all optional):
 *   { "agent": "<name>", "model": "<tier|tag>", "force": <bool> }
 *
 * Pre-loads everything the chat path will need: KBs into memory, the
 * embedding model, helper LLMs (document chooser, vision model), and
 * the requested chat model warmed in VRAM. Streams SSE progress so the
 * UI can render a real loading indicator instead of a black-box spinner.
 *
 * If `agent` is omitted, falls back to firefly-ragsmith's default_agent
 * setting. Same for `model`. This call NEVER RETURNS — the SSE proxy
 * writes headers + streams + calls exit().
 */
function firefly_agent_route_warm( WP_REST_Request $req ) {
    $body  = $req->get_json_params() ?: array();
    $agent = isset( $body['agent'] ) ? (string) $body['agent'] : '';
    $model = isset( $body['model'] ) ? (string) $body['model'] : '';
    $force = isset( $body['force'] ) ? (bool)   $body['force'] : false;

    if ( $agent === '' && function_exists( 'firefly_ragsmith_setting' ) ) {
        $agent = (string) firefly_ragsmith_setting( 'default_agent', '' );
    }
    if ( $model === '' && function_exists( 'firefly_ragsmith_setting' ) ) {
        $model = (string) firefly_ragsmith_setting( 'default_model', '' );
    }
    if ( $agent === '' ) {
        return new WP_Error( 'firefly_agent_no_agent', 'No agent specified and no default configured.', array( 'status' => 400 ) );
    }

    if ( ! class_exists( 'Firefly_Ragsmith_SSE_Proxy' ) ) {
        return new WP_Error( 'firefly_agent_no_proxy', 'SSE proxy not available.', array( 'status' => 500 ) );
    }
    // method_exists guard: stream_agent_load() landed in firefly-ragsmith
    // after the proxy class itself, so older installs may have the class
    // but not this method. Return a clean error instead of fataling.
    if ( ! method_exists( 'Firefly_Ragsmith_SSE_Proxy', 'stream_agent_load' ) ) {
        return new WP_Error(
            'firefly_agent_no_stream_agent_load',
            'Warm-up not available — firefly-ragsmith plugin is out of date (needs stream_agent_load).',
            array( 'status' => 501 )
        );
    }
    // Streams; never returns.
    Firefly_Ragsmith_SSE_Proxy::stream_agent_load( $agent, $model !== '' ? $model : null, $force );
}

/**
 * GET /firefly-capture/v1/agent/status?agent=<name>
 *
 * Lightweight proxy for Ragsmith's GET /agents/{name}/status. Returns
 * `{ready, cached, readiness: {last_activity, llm_model_loaded, ...}}`.
 * Used by both the Capture and Agent admins to render their warm-state
 * indicator pills. Does NOT trigger a load — just reads cached state.
 *
 * If `agent` is omitted, falls back to firefly-ragsmith's default_agent
 * setting. Returns a 400 if neither is set, so the browser can render
 * an empty "no agent configured" pill.
 */
function firefly_agent_route_status( WP_REST_Request $req ) {
    $agent = (string) $req->get_param( 'agent' );
    if ( $agent === '' && function_exists( 'firefly_ragsmith_setting' ) ) {
        $agent = (string) firefly_ragsmith_setting( 'default_agent', '' );
    }
    if ( $agent === '' ) {
        return new WP_Error( 'firefly_agent_no_agent', 'No agent specified and no default configured.', array( 'status' => 400 ) );
    }
    if ( ! function_exists( 'firefly_ragsmith' ) ) {
        return new WP_Error( 'firefly_agent_no_bridge', 'Ragsmith bridge not loaded.', array( 'status' => 500 ) );
    }
    $client = firefly_ragsmith();
    if ( ! is_object( $client ) || ! method_exists( $client, 'get_agent_status' ) ) {
        return new WP_Error( 'firefly_agent_no_status', 'get_agent_status not implemented on client.', array( 'status' => 500 ) );
    }
    $resp = $client->get_agent_status( $agent );
    if ( is_wp_error( $resp ) ) return $resp;
    return rest_ensure_response( $resp );
}

/**
 * DELETE /firefly-capture/v1/messages/{wp_session_id}/{rs_message_id}
 *
 * Drop a message pair from the bound Ragsmith conversation. The WP
 * session id in the path is verified against the current user before
 * the delete is forwarded — prevents users from deleting messages
 * out of sessions they don't own.
 */
function firefly_agent_route_message_delete( WP_REST_Request $req ) {
    $wp_session_id  = (int) $req['wp_session_id'];
    $rs_message_id  = (int) $req['rs_message_id'];
    if ( $wp_session_id <= 0 || $rs_message_id <= 0 ) {
        return new WP_Error( 'firefly_agent_bad_request', 'wp_session_id and rs_message_id are required.', array( 'status' => 400 ) );
    }

    if ( ! function_exists( 'firefly_capture_owned_session' ) ) {
        return new WP_Error( 'firefly_agent_no_capture', 'Capture is not available.', array( 'status' => 500 ) );
    }
    $owned = firefly_capture_owned_session( $wp_session_id );
    if ( is_wp_error( $owned ) ) return $owned;

    $rs_session_id = firefly_agent_resolve_rs_session_id( $wp_session_id );
    if ( ! $rs_session_id ) {
        return new WP_Error( 'firefly_agent_unbound_session', 'Session has no Ragsmith conversation bound.', array( 'status' => 400 ) );
    }

    if ( ! function_exists( 'firefly_ragsmith' ) ) {
        return new WP_Error( 'firefly_agent_no_bridge', 'Ragsmith bridge not loaded.', array( 'status' => 500 ) );
    }
    $client = firefly_ragsmith();
    if ( ! is_object( $client ) || ! method_exists( $client, 'delete_session_message' ) ) {
        return new WP_Error( 'firefly_agent_no_delete_message', 'delete_session_message not implemented on client.', array( 'status' => 500 ) );
    }
    $resp = $client->delete_session_message( $rs_session_id, (string) $rs_message_id );
    if ( is_wp_error( $resp ) ) return $resp;
    return rest_ensure_response( $resp );
}

/**
 * GET /firefly-capture/v1/sessions/{wp_session_id}/extraction-status
 *
 * Returns the post-stream extraction state for the bound Ragsmith
 * conversation. Shape: { extracting: bool, latest_message_id: int|null }.
 * For unbound (fresh) sessions: returns { extracting: false,
 * latest_message_id: null } so the browser's poll loop terminates cleanly.
 */
function firefly_agent_route_extraction_status( WP_REST_Request $req ) {
    $wp_session_id = (int) $req['id'];
    if ( ! function_exists( 'firefly_capture_owned_session' ) ) {
        return new WP_Error( 'firefly_agent_no_capture', 'Capture is not available.', array( 'status' => 500 ) );
    }
    $owned = firefly_capture_owned_session( $wp_session_id );
    if ( is_wp_error( $owned ) ) return $owned;

    $rs_session_id = firefly_agent_resolve_rs_session_id( $wp_session_id );
    if ( ! $rs_session_id ) {
        return rest_ensure_response( array(
            'extracting'        => false,
            'latest_message_id' => null,
        ) );
    }

    if ( ! function_exists( 'firefly_ragsmith' ) ) {
        return new WP_Error( 'firefly_agent_no_bridge', 'Ragsmith bridge not loaded.', array( 'status' => 500 ) );
    }
    $client = firefly_ragsmith();
    if ( ! is_object( $client ) || ! method_exists( $client, 'get_extraction_status' ) ) {
        return new WP_Error( 'firefly_agent_no_extraction_status', 'get_extraction_status not implemented on client.', array( 'status' => 500 ) );
    }
    $resp = $client->get_extraction_status( $rs_session_id );
    if ( is_wp_error( $resp ) ) return $resp;
    return rest_ensure_response( array(
        'extracting'        => isset( $resp['extracting'] ) ? (bool) $resp['extracting'] : false,
        'latest_message_id' => isset( $resp['latest_message_id'] ) ? $resp['latest_message_id'] : null,
    ) );
}
