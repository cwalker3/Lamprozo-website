<?php
/**
 * Firefly Projects REST API Endpoints
 *
 * Registers all REST endpoints under the firefly-plugin namespace.
 */

// Ensure no direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * CORS headers for cross-origin REST API requests.
 *
 * The receive-side endpoints are gated by either the shared secret
 * (firefly_projects_verify_shared_secret) or capability + nonce, so
 * direct exploitation of CORS isn't the threat. But Allow-Credentials:
 * true with a reflected Origin is the textbook permissive posture, so
 * we now check the Origin against firefly_projects_allowed_origins()
 * before sending any Allow-Origin header. Browser CORS will then block
 * non-allowlisted origins from reading responses or sending credentials.
 */
add_action('rest_api_init', function() {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function($value) {
        firefly_projects_send_cors_headers();
        return $value;
    });
}, 15);

/**
 * Handle preflight OPTIONS requests for CORS — same allowlist gate.
 */
add_action('init', function() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        if (firefly_projects_send_cors_headers()) {
            header('Access-Control-Max-Age: 86400');
        }
        exit(0);
    }
});

/**
 * Emit Access-Control-Allow-* headers iff the request's Origin is on
 * the allowlist. Returns true if headers were emitted, false otherwise
 * (so callers know whether the preflight should signal "allowed").
 */
function firefly_projects_send_cors_headers() {
    $origin = get_http_origin();
    if (!$origin) return false;

    $allowed = firefly_projects_allowed_origins();
    if (!in_array($origin, $allowed, true)) {
        return false;
    }

    header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: X-Firefly-Secret, Content-Type, X-WP-Nonce');
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    return true;
}

/**
 * Verify REST request permissions for admin-level endpoints
 *
 * @param WP_REST_Request $request The REST request object
 * @return bool True if request is authorized, false otherwise
 */
function firefly_plugin_verify_rest_admin($request) {
    // Verify nonce for security
    $nonce = $request->get_header('X-WP-Nonce');

    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        return false;
    }

    // Check admin capability
    $has_capability = current_user_can('manage_options');

    if (!$has_capability) {
        return false;
    }

    return true;
}

/**
 * Register all plugin REST API endpoints
 */
function firefly_plugin_register_rest_endpoints() {
    // Projects: Get file tree for file selection UI
    register_rest_route(
        'firefly-plugin/v1',
        '/get-project-files',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_get_project_files',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );

    // Projects: Update/sync project to live dev environment (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/update-project',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_local_update_project',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );

    // Projects: Handle incoming project update (remote site only)
    // This endpoint is called by the local site to update the remote
    register_rest_route(
        'firefly-plugin/v1',
        '/update_project',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_handle_project_update',
            'permission_callback' => '__return_true' // Uses shared secret authentication
        )
    );

    // Projects: Get backup history
    register_rest_route(
        'firefly-plugin/v1',
        '/get-backup-history',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_get_backup_history',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );

    // Projects: Restore from backup
    register_rest_route(
        'firefly-plugin/v1',
        '/restore-backup',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_restore_backup',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );

    // Projects: Delete backup
    register_rest_route(
        'firefly-plugin/v1',
        '/delete-backup',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_delete_backup',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );

    // Projects: Sync firefly-projects plugin itself
    register_rest_route(
        'firefly-plugin/v1',
        '/sync-self',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_sync_self',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );

    // Git Mode: read git repo status + user toggle
    register_rest_route(
        'firefly-plugin/v1',
        '/git-status',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_git_status_endpoint',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
        )
    );

    // Git Mode: persist the user's on/off preference
    register_rest_route(
        'firefly-plugin/v1',
        '/git-mode',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_git_mode_toggle_endpoint',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'enabled' => array(
                    'required' => true,
                    'type'     => 'boolean',
                ),
            ),
        )
    );

    // Page Sync: Sync page content to remote site (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/sync-page',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_sync_page',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'post_id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'description'       => 'The post/page ID to sync'
                ),
                'include_assets' => array(
                    'required'          => false,
                    'type'              => 'boolean',
                    'default'           => true,
                    'description'       => 'Whether to include detected media assets'
                ),
                'target_env' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'dev',
                    'enum'              => array('dev', 'prod'),
                    'description'       => 'Target environment: dev (Live Dev) or prod (Production)'
                )
            )
        )
    );

    // Page Sync: Handle incoming page sync (remote site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/receive-page',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_receive_page',
            'permission_callback' => '__return_true' // Uses shared secret authentication
        )
    );

    // Menu Sync: Sync menu to remote site (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/sync-menu',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_sync_menu',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'menu_id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'description'       => 'The menu term ID to sync'
                ),
                'target_env' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'dev',
                    'enum'              => array('dev', 'prod'),
                    'description'       => 'Target environment: dev (Live Dev) or prod (Production)'
                )
            )
        )
    );

    // Menu Sync: Handle incoming menu sync (remote site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/receive-menu',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_receive_menu',
            'permission_callback' => '__return_true' // Uses shared secret authentication
        )
    );

    // Menu Sync: Get sync timestamps for a menu
    register_rest_route(
        'firefly-plugin/v1',
        '/menu-sync-status',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_get_menu_sync_status',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'menu_id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'description'       => 'The menu term ID'
                )
            )
        )
    );

    // Menu Pull: List available menus (remote site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/list-menus',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_list_menus',
            'permission_callback' => '__return_true', // Uses shared secret authentication
            'args'                => array(
                'template' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Template scope; defaults to this site\'s active template. Pass "all" to list every template\'s menus.'
                )
            )
        )
    );

    // Menu Pull: Export menu for pull request (remote site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/export-menu',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_export_menu',
            'permission_callback' => '__return_true', // Uses shared secret authentication
            'args'                => array(
                'menu_id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'description'       => 'The menu term ID to export'
                )
            )
        )
    );

    // Menu Pull: Fetch available menus from remote (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/fetch-remote-menus',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_fetch_remote_menus',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'source_env' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'dev',
                    'enum'              => array('dev', 'prod'),
                    'description'       => 'Source environment: dev (Live Dev) or prod (Production)'
                ),
                'template' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Template scope; defaults to the local active template. Pass "all" to list every template\'s menus.'
                )
            )
        )
    );

    // Menu Pull: Pull menu from remote (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/pull-menu',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_pull_menu',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'remote_menu_id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'description'       => 'The remote menu term ID to pull'
                ),
                'local_menu_id' => array(
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                    'description'       => 'The local menu term ID to sync into. Omit (or 0) to resolve/create the template-scoped menu automatically.'
                ),
                'source_env' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'dev',
                    'enum'              => array('dev', 'prod'),
                    'description'       => 'Source environment: dev (Live Dev) or prod (Production)'
                )
            )
        )
    );

    // Pages List Sync: Sync all pages to remote (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/sync-all-pages',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_sync_all_pages',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'sync_mode' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'safe',
                    'enum'              => array('safe', 'mirror'),
                    'description'       => 'Sync mode: safe (keep remote extras) or mirror (delete remote extras)'
                ),
                'target_env' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'dev',
                    'enum'              => array('dev', 'prod'),
                    'description'       => 'Target environment: dev (Live Dev) or prod (Production)'
                ),
                'post_type' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'page',
                    'enum'              => array('page', 'post'),
                    'description'       => 'Post type to sync: page or post'
                )
            )
        )
    );

    // Pages List Sync: Get orphan count for mirror mode preview (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/pages-orphan-count',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_get_pages_orphan_count',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'target_env' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'dev',
                    'enum'              => array('dev', 'prod'),
                    'description'       => 'Target environment to check'
                ),
                'post_type' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'page',
                    'enum'              => array('page', 'post'),
                    'description'       => 'Post type to check orphans for'
                )
            )
        )
    );

    // Pages List Sync: List all published pages/posts (remote site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/list-pages',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_list_pages',
            'permission_callback' => '__return_true', // Uses shared secret authentication
            'args'                => array(
                'post_type' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'page',
                    'enum'              => array('page', 'post'),
                    'description'       => 'Post type to list: page or post'
                ),
                'include_drafts' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'false',
                    'description'       => 'Include draft posts'
                ),
                'template' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => 'Filter to posts/pages belonging to this firefly template (matches _firefly_template meta). Empty = this site\'s active template; "all" = unfiltered.'
                )
            )
        )
    );

    // Pages List Sync: Delete pages by slug (remote site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/delete-pages',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_delete_pages',
            'permission_callback' => '__return_true' // Uses shared secret authentication
        )
    );

    // Page Pull: Pull page from remote to local (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/pull-page',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_pull_page',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'post_slug' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                    'description'       => 'The page slug to pull'
                ),
                'source_env' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'dev',
                    'enum'              => array('dev', 'prod'),
                    'description'       => 'Source environment: dev (Live Dev) or prod (Production)'
                ),
                'firefly_page_id' => array(
                    'required'    => false,
                    'type'        => 'string',
                    'description' => 'Stable cross-env id "{template}:{slug}" (preferred; scopes the remote lookup)'
                ),
                'template' => array(
                    'required'    => false,
                    'type'        => 'string',
                    'description' => 'Template to scope the pull to (must be installed locally)'
                )
            )
        )
    );

    // Page Pull: Export page for pull request (remote site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/export-page',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_export_page',
            'permission_callback' => '__return_true', // Uses shared secret authentication
            'args'                => array(
                'post_slug' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                    'description'       => 'The page slug to export (optional if firefly_page_id given)'
                ),
                'firefly_page_id' => array(
                    'required'    => false,
                    'type'        => 'string',
                    'description' => 'Stable cross-env id "{template}:{slug}" (preferred)'
                ),
                'template' => array(
                    'required'    => false,
                    'type'        => 'string',
                    'description' => 'Template to scope the slug lookup to'
                )
            )
        )
    );

    // Asset Processing: Process page assets for local environment (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/process-page-assets',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_process_page_assets_callback',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'post_id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'description'       => 'The post ID to process'
                )
            )
        )
    );

    // Page Pull: Fetch available pages from remote (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/fetch-remote-pages',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_fetch_remote_pages',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'source_env' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'dev',
                    'enum'              => array('dev', 'prod'),
                    'description'       => 'Source environment: dev (Live Dev) or prod (Production)'
                ),
                'post_type' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'page',
                    'enum'              => array('page', 'post'),
                    'description'       => 'Post type to fetch: page or post'
                ),
                'template' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'description'       => 'Scope the remote list to this firefly template. Empty = the local active template; "all" = no scoping.'
                )
            )
        )
    );

    // =========================================================================
    // TEMPLATE SYNC — whole-template push/pull (handlers in template-sync.php)
    // =========================================================================

    // Initiating side (admin auth): these run where the operator is and call
    // the remote peer with the shared secret.
    $ts_admin_routes = array(
        'push-files'      => 'firefly_projects_ts_push_files',
        'pull-files'      => 'firefly_projects_ts_pull_files',
        'remote-templates' => 'firefly_projects_ts_remote_templates',
        'remote-manifest' => 'firefly_projects_ts_remote_manifest',
        'media-diff'      => 'firefly_projects_ts_media_diff',
        'push-media-item' => 'firefly_projects_ts_push_media_item',
        'pull-media-item' => 'firefly_projects_ts_pull_media_item',
        'mirror-media'    => 'firefly_projects_ts_mirror_media',
        'mirror-content'  => 'firefly_projects_ts_mirror_content',
        'push-settings'   => 'firefly_projects_ts_push_settings',
        'pull-settings'   => 'firefly_projects_ts_pull_settings',
        'remote-activate' => 'firefly_projects_ts_remote_activate',
        'activate-local'  => 'firefly_projects_ts_activate_local',
        'clear-cache'     => 'firefly_projects_ts_clear_cache',
    );
    foreach ($ts_admin_routes as $ts_path => $ts_callback) {
        register_rest_route('firefly-plugin/v1', '/template-sync/' . $ts_path, array(
            'methods'             => ($ts_path === 'remote-templates' || $ts_path === 'remote-manifest') ? 'GET' : 'POST',
            'callback'            => $ts_callback,
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
        ));
    }

    // Receiving side (shared secret verified inside each handler): callable
    // by remote peers; version-tolerant with unknown params.
    $ts_secret_routes = array(
        'receive-files'      => array('POST', 'firefly_projects_ts_receive_files'),
        'export-files'       => array('GET',  'firefly_projects_ts_export_files'),
        'list-templates'     => array('GET',  'firefly_projects_ts_list_templates'),
        'list-media'         => array('GET',  'firefly_projects_ts_list_media'),
        'receive-media-item' => array('POST', 'firefly_projects_ts_receive_media_item'),
        'export-media-item'  => array('GET',  'firefly_projects_ts_export_media_item'),
        'delete-media'       => array('POST', 'firefly_projects_ts_delete_media'),
        'export-settings'    => array('GET',  'firefly_projects_ts_export_settings'),
        'receive-settings'   => array('POST', 'firefly_projects_ts_receive_settings'),
        'receive-activate'   => array('POST', 'firefly_projects_ts_receive_activate'),
    );
    foreach ($ts_secret_routes as $ts_path => $ts_def) {
        register_rest_route('firefly-plugin/v1', '/template-sync/' . $ts_path, array(
            'methods'             => $ts_def[0],
            'callback'            => $ts_def[1],
            'permission_callback' => '__return_true', // Shared-secret auth inside handler
        ));
    }

    // Manifest serves both the local planner (admin nonce) and remote peers
    // (shared secret) — dual auth inside the handler.
    register_rest_route('firefly-plugin/v1', '/template-sync/manifest', array(
        'methods'             => 'GET',
        'callback'            => 'firefly_projects_ts_manifest',
        'permission_callback' => '__return_true', // Dual auth inside handler
    ));

    // Bootstrap: Check if wp-dev exists on production (local calls this)
    register_rest_route(
        'firefly-plugin/v1',
        '/check-dev-exists',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_check_dev_exists',
            'permission_callback' => '__return_true' // Uses shared secret authentication
        )
    );

    // Bootstrap: Receive WP bundle and create wp-dev (production endpoint)
    register_rest_route(
        'firefly-plugin/v1',
        '/bootstrap-dev',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_bootstrap_dev',
            'permission_callback' => '__return_true' // Uses shared secret authentication
        )
    );

    // Bootstrap: Generate WP bundle for sending (local endpoint)
    register_rest_route(
        'firefly-plugin/v1',
        '/generate-wp-bundle',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_generate_wp_bundle',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );

    // Template Schema Sync: Sync template schema to remote (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/sync-template-schema',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_sync_template_schema',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'template' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'The template name to sync (e.g., firefly, default, glow)'
                ),
                'target_env' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'dev',
                    'enum'              => array('dev', 'prod'),
                    'description'       => 'Target environment: dev (Live Dev) or prod (Production)'
                )
            )
        )
    );

    // Template Schema Sync: Handle incoming schema sync (remote site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/receive-template-schema',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_receive_template_schema',
            'permission_callback' => '__return_true' // Uses shared secret authentication
        )
    );

    // Template Schema Sync: List available templates (both local and remote)
    register_rest_route(
        'firefly-plugin/v1',
        '/list-template-schemas',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_list_schemas_endpoint',
            'permission_callback' => '__return_true' // Uses shared secret for remote, admin for local
        )
    );

    // Sync Log: Read the activity log for a specific post (admin only).
    register_rest_route(
        'firefly-plugin/v1',
        '/sync-log',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_sync_log_endpoint',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'post_id' => array( 'required' => true,  'type' => 'integer' ),
                'limit'   => array( 'required' => false, 'type' => 'integer', 'default' => 20 ),
            ),
        )
    );

    // Post Activity (remote-side): expose this remote's view of one page so a
    // local can merge it into the unified activity timeline. Identified by the
    // stable cross-env _firefly_page_id meta. Shared-secret auth.
    register_rest_route(
        'firefly-plugin/v1',
        '/post-activity',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_post_activity_endpoint',
            'permission_callback' => '__return_true', // verified via shared secret inside
            'args'                => array(
                'firefly_page_id' => array( 'required' => true,  'type' => 'string' ),
                'limit'           => array( 'required' => false, 'type' => 'integer', 'default' => 20 ),
            ),
        )
    );

    // Remote Activity (local-side proxy): admin asks for the remote's view of
    // a local post; we resolve _firefly_page_id and call /post-activity on the
    // appropriate remote with the shared secret.
    register_rest_route(
        'firefly-plugin/v1',
        '/remote-activity',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_projects_remote_activity_endpoint',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
            'args'                => array(
                'post_id' => array( 'required' => true,  'type' => 'integer' ),
                'env'     => array( 'required' => true,  'type' => 'string' ),
                'limit'   => array( 'required' => false, 'type' => 'integer', 'default' => 20 ),
            ),
        )
    );

    // Restore Local Revision: admin-triggered restore of a local post to one
    // of its own WP revisions. Writes a 'restore' log row + lets save_post
    // run so the snippet file on disk re-derives from the restored content.
    register_rest_route(
        'firefly-plugin/v1',
        '/restore-local-revision',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_restore_local_revision_endpoint',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
        )
    );

    // Rollback Push (local proxy): admin-triggered rollback of a previously-
    // executed push. Reads pre_push_revision_id from the log row, calls the
    // remote /restore-to-revision endpoint with shared secret.
    register_rest_route(
        'firefly-plugin/v1',
        '/rollback-push',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_rollback_push_endpoint',
            'permission_callback' => 'firefly_plugin_verify_rest_admin',
        )
    );

    // Restore To Revision (remote-side): receiver for rollback-push. Restores
    // this remote's post to a named revision_id. Shared-secret auth.
    register_rest_route(
        'firefly-plugin/v1',
        '/restore-to-revision',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_restore_to_revision_endpoint',
            'permission_callback' => '__return_true', // verified via shared secret inside
        )
    );
}
add_action('rest_api_init', 'firefly_plugin_register_rest_endpoints');

/**
 * REST: GET /sync-log?post_id=X[&limit=N]
 * Returns the most recent sync log entries for a post, shaped for the UI.
 */
function firefly_projects_sync_log_endpoint( WP_REST_Request $request ) {
    $post_id = (int) $request->get_param( 'post_id' );
    $limit   = (int) ( $request->get_param( 'limit' ) ?: 20 );
    if ( $post_id <= 0 ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'post_id required.' ), 400 );
    }
    if ( ! function_exists( 'firefly_projects_get_sync_log' ) ) {
        return new WP_REST_Response( array( 'success' => true, 'entries' => array() ), 200 );
    }
    $entries = firefly_projects_get_sync_log( $post_id, $limit );
    return new WP_REST_Response( array( 'success' => true, 'entries' => $entries ), 200 );
}

/**
 * Look up a post on this site by its stable cross-env _firefly_page_id.
 * Shared by every endpoint that takes firefly_page_id as input.
 */
function firefly_projects_find_post_by_firefly_page_id( $firefly_page_id ) {
    if ( ! $firefly_page_id ) return null;
    $found = get_posts( array(
        'post_type'            => array( 'page', 'post' ),
        'post_status'          => 'any',
        'numberposts'          => 1,
        'meta_key'             => '_firefly_page_id',
        'meta_value'           => $firefly_page_id,
        'firefly_skip_scoping' => true,
    ) );
    return $found ? $found[0] : null;
}

/**
 * Parse a stable firefly_page_id ("{template}:{slug}") into its parts.
 *
 * @return array{template:string, slug:string}
 */
function firefly_projects_parse_page_id( $firefly_page_id ) {
    if ( $firefly_page_id && strpos( $firefly_page_id, ':' ) !== false ) {
        list( $template, $slug ) = explode( ':', $firefly_page_id, 2 );
        return array( 'template' => $template, 'slug' => $slug );
    }
    return array( 'template' => '', 'slug' => '' );
}

/**
 * Find a page/post by slug scoped to a template, so the same slug can exist
 * across templates (the way scoped content is meant to work). When the
 * _firefly_page_id meta isn't stored yet, this resolves by slug + template
 * meta. Falls back to a plain slug lookup when no template is given.
 *
 * @return WP_Post|null
 */
function firefly_projects_find_scoped_page( $slug, $template = '', $post_types = array( 'page', 'post' ) ) {
    if ( empty( $slug ) ) return null;
    $args = array(
        'name'                 => $slug,
        'post_type'            => $post_types,
        'post_status'          => 'any',
        'numberposts'          => 1,
        'firefly_skip_scoping' => true,
    );
    if ( ! empty( $template ) ) {
        $args['meta_query'] = array( array(
            'key'   => '_firefly_template',
            'value' => $template,
        ) );
    }
    $found = get_posts( $args );
    return $found ? $found[0] : null;
}

/**
 * REST: GET /post-activity?firefly_page_id=X[&limit=N] — REMOTE-SIDE.
 * Returns this remote's sync log + WP revisions + current state for one page,
 * identified by the cross-env stable id. Shared-secret auth.
 */
function firefly_projects_post_activity_endpoint( WP_REST_Request $request ) {
    $auth_failure = firefly_projects_verify_shared_secret( $request );
    if ( $auth_failure !== null ) return $auth_failure;

    $firefly_page_id = (string) $request->get_param( 'firefly_page_id' );
    $limit           = (int) ( $request->get_param( 'limit' ) ?: 20 );

    if ( $firefly_page_id === '' ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'firefly_page_id required.' ), 400 );
    }

    $post = firefly_projects_find_post_by_firefly_page_id( $firefly_page_id );
    if ( ! $post ) {
        // Not an error — local may not have synced this post yet. Empty result.
        return new WP_REST_Response( array(
            'success'    => true,
            'post_id'    => null,
            'current'    => null,
            'sync_log'   => array(),
            'revisions'  => array(),
        ), 200 );
    }

    $sync_log  = function_exists( 'firefly_projects_get_sync_log' )
        ? firefly_projects_get_sync_log( $post->ID, $limit )
        : array();
    $revisions = function_exists( 'firefly_projects_get_post_revisions_shaped' )
        ? firefly_projects_get_post_revisions_shaped( $post->ID, $limit )
        : array();

    $latest_rev_id = ! empty( $revisions ) ? (int) $revisions[0]['revision_id'] : null;

    $modified_gmt = $post->post_modified_gmt;
    $ts_gmt       = $modified_gmt ? strtotime( $modified_gmt . ' UTC' ) : null;

    return new WP_REST_Response( array(
        'success'   => true,
        'post_id'   => (int) $post->ID,
        'current'   => array(
            'post_modified_gmt'   => $modified_gmt,
            'post_modified_human' => $ts_gmt ? human_time_diff( $ts_gmt, time() ) . ' ago' : '',
            'latest_revision_id'  => $latest_rev_id,
        ),
        'sync_log'  => $sync_log,
        'revisions' => $revisions,
    ), 200 );
}

/**
 * REST: GET /remote-activity?post_id=X&env=dev|prod[&limit=N] — LOCAL-SIDE.
 * Proxies the remote's /post-activity for the local post's _firefly_page_id.
 * Graceful degradation when the post hasn't been synced or the remote is down.
 */
function firefly_projects_remote_activity_endpoint( WP_REST_Request $request ) {
    $post_id = (int) $request->get_param( 'post_id' );
    $env     = (string) $request->get_param( 'env' );
    $limit   = (int) ( $request->get_param( 'limit' ) ?: 20 );

    if ( $post_id <= 0 || ! in_array( $env, array( 'dev', 'prod' ), true ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'post_id and env (dev|prod) required.' ), 400 );
    }

    $firefly_page_id = get_post_meta( $post_id, '_firefly_page_id', true );
    if ( ! $firefly_page_id ) {
        return new WP_REST_Response( array(
            'success'  => true,
            'entries'  => array(),
            'warning'  => 'Post is not yet registered for cross-environment sync.',
            'env'      => $env,
        ), 200 );
    }

    $endpoint = ( $env === 'prod' )
        ? ( defined( 'PROD_ENDPOINT' ) ? PROD_ENDPOINT : '' )
        : ( defined( 'LIVE_DEV_ENDPOINT' ) ? LIVE_DEV_ENDPOINT : '' );

    if ( ! $endpoint ) {
        return new WP_REST_Response( array(
            'success' => true,
            'entries' => array(),
            'warning' => 'No ' . ( $env === 'prod' ? 'Production' : 'Live Dev' ) . ' endpoint configured.',
            'env'     => $env,
        ), 200 );
    }

    // Extract base URL and build the activity endpoint URL.
    $base = '';
    if ( preg_match( '/(https?:\/\/[^\/]+)/', $endpoint, $m ) ) {
        $base = $m[1];
    }
    if ( ! $base ) {
        return new WP_REST_Response( array(
            'success' => true,
            'entries' => array(),
            'warning' => 'Could not derive base URL for ' . $env . '.',
            'env'     => $env,
        ), 200 );
    }

    $url = $base . '/wp-json/firefly-plugin/v1/post-activity?firefly_page_id=' . rawurlencode( $firefly_page_id ) . '&limit=' . $limit;
    $response = wp_remote_get( $url, array(
        'headers' => array( 'X-Firefly-Secret' => FIREFLY_SHARED_SECRET ),
        'timeout' => 15,
    ) );

    if ( is_wp_error( $response ) ) {
        return new WP_REST_Response( array(
            'success' => true,
            'entries' => array(),
            'warning' => 'Could not reach ' . ( $env === 'prod' ? 'Production' : 'Live Dev' ) . ': ' . $response->get_error_message(),
            'env'     => $env,
        ), 200 );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( $code !== 200 || ! is_array( $data ) || empty( $data['success'] ) ) {
        $msg = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
        return new WP_REST_Response( array(
            'success' => true,
            'entries' => array(),
            'warning' => 'Remote returned an error (' . $msg . ').',
            'env'     => $env,
        ), 200 );
    }

    // Pass through with an env_label for the UI.
    $data['env']       = $env;
    $data['env_label'] = ( $env === 'prod' ) ? 'Production' : 'Live Dev';
    return new WP_REST_Response( $data, 200 );
}

/**
 * REST: POST /restore-local-revision  body: { post_id, revision_id }
 * Admin-triggered restore of a local post to one of its own WP revisions.
 * save_post runs (NOT suppressed) so the snippet file on disk updates.
 */
function firefly_projects_restore_local_revision_endpoint( WP_REST_Request $request ) {
    $body        = $request->get_json_params() ?: array();
    $post_id     = isset( $body['post_id'] ) ? (int) $body['post_id'] : 0;
    $revision_id = isset( $body['revision_id'] ) ? (int) $body['revision_id'] : 0;

    if ( $post_id <= 0 || $revision_id <= 0 ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'post_id and revision_id required.' ), 400 );
    }

    // Confirm the revision belongs to this post — defends against operators
    // sliding in an arbitrary revision_id from another post.
    $revision = wp_get_post_revision( $revision_id );
    if ( ! $revision || (int) $revision->post_parent !== $post_id ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Revision not found for this post.' ), 404 );
    }

    $restored_id = wp_restore_post_revision( $revision_id );
    if ( is_wp_error( $restored_id ) || ! $restored_id ) {
        $msg = is_wp_error( $restored_id ) ? $restored_id->get_error_message() : 'Restore failed.';
        return new WP_REST_Response( array( 'success' => false, 'message' => $msg ), 500 );
    }

    // Log the restore action.
    if ( function_exists( 'firefly_projects_log_sync' ) ) {
        $post = get_post( $post_id );
        firefly_projects_log_sync( array(
            'post_id'     => $post_id,
            'post_type'   => $post ? $post->post_type : 'page',
            'direction'   => 'restore',
            'env'         => 'local',
            'user_id'     => get_current_user_id() ?: null,
            'status'      => 'success',
            'revision_id' => $revision_id,
            'files_count' => 0,
            'summary'     => array(
                'action'              => 'restore_local',
                'source_revision_id'  => $revision_id,
            ),
        ) );
    }

    return new WP_REST_Response( array(
        'success'        => true,
        'message'        => 'Local restored to revision #' . $revision_id . '.',
        'post_id'        => $post_id,
        'revision_id'    => $revision_id,
    ), 200 );
}

/**
 * REST: POST /rollback-push  body: { log_id } — LOCAL-SIDE PROXY.
 * Reads pre_push_revision_id from the log row's summary, hits the remote's
 * /restore-to-revision, then writes a 'rollback' log row locally.
 */
function firefly_projects_rollback_push_endpoint( WP_REST_Request $request ) {
    global $wpdb;
    $body   = $request->get_json_params() ?: array();
    $log_id = isset( $body['log_id'] ) ? (int) $body['log_id'] : 0;
    if ( $log_id <= 0 ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'log_id required.' ), 400 );
    }

    $table = function_exists( 'firefly_projects_sync_log_table' ) ? firefly_projects_sync_log_table() : ( $wpdb->prefix . 'firefly_sync_log' );
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ), ARRAY_A );
    if ( ! $row ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Log row not found.' ), 404 );
    }
    if ( $row['direction'] !== 'push' || $row['status'] !== 'success' ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Can only roll back a successful push.' ), 400 );
    }

    $summary = $row['summary'] ? json_decode( $row['summary'], true ) : array();
    $pre_id  = isset( $summary['pre_push_revision_id'] ) ? (int) $summary['pre_push_revision_id'] : 0;
    if ( $pre_id <= 0 ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'No pre-push revision recorded (this push created the post on the remote, or predates the rollback feature).' ), 400 );
    }

    $post_id = (int) $row['post_id'];
    $firefly_page_id = get_post_meta( $post_id, '_firefly_page_id', true );
    if ( ! $firefly_page_id ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Post has no _firefly_page_id meta.' ), 400 );
    }

    $env      = $row['env'];
    $endpoint = ( $env === 'prod' )
        ? ( defined( 'PROD_ENDPOINT' ) ? PROD_ENDPOINT : '' )
        : ( defined( 'LIVE_DEV_ENDPOINT' ) ? LIVE_DEV_ENDPOINT : '' );
    if ( ! $endpoint ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'No endpoint configured for ' . $env . '.' ), 500 );
    }
    $base = '';
    if ( preg_match( '/(https?:\/\/[^\/]+)/', $endpoint, $m ) ) $base = $m[1];
    if ( ! $base ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Could not derive remote base URL.' ), 500 );
    }
    $url = $base . '/wp-json/firefly-plugin/v1/restore-to-revision';

    $response = wp_remote_post( $url, array(
        'headers' => array(
            'Content-Type'      => 'application/json',
            'X-Firefly-Secret'  => FIREFLY_SHARED_SECRET,
        ),
        'body'    => wp_json_encode( array(
            'firefly_page_id' => $firefly_page_id,
            'revision_id'     => $pre_id,
        ) ),
        'timeout' => 30,
    ) );

    if ( is_wp_error( $response ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Remote unreachable: ' . $response->get_error_message() ), 500 );
    }
    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || ! is_array( $data ) || empty( $data['success'] ) ) {
        $msg = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
        // Log the failure too — admins want to see failed rollback attempts.
        if ( function_exists( 'firefly_projects_log_sync' ) ) {
            firefly_projects_log_sync( array(
                'post_id'     => $post_id,
                'post_type'   => $row['post_type'],
                'direction'   => 'rollback',
                'env'         => $env,
                'user_id'     => get_current_user_id() ?: null,
                'status'      => 'failure',
                'revision_id' => null,
                'files_count' => 0,
                'summary'     => array(
                    'rolled_back_log_id'        => $log_id,
                    'target_revision_id'        => $pre_id,
                    'error_message'             => $msg,
                ),
            ) );
        }
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Remote rejected the rollback: ' . $msg ), $code === 200 ? 500 : $code );
    }

    // Success — log it.
    if ( function_exists( 'firefly_projects_log_sync' ) ) {
        firefly_projects_log_sync( array(
            'post_id'     => $post_id,
            'post_type'   => $row['post_type'],
            'direction'   => 'rollback',
            'env'         => $env,
            'user_id'     => get_current_user_id() ?: null,
            'status'      => 'success',
            'revision_id' => null,
            'files_count' => 0,
            'summary'     => array(
                'rolled_back_log_id'        => $log_id,
                'target_revision_id'        => $pre_id,
                'remote_response'           => isset( $data['message'] ) ? $data['message'] : null,
            ),
        ) );
    }

    return new WP_REST_Response( array(
        'success'  => true,
        'message'  => 'Remote ' . ( $env === 'prod' ? 'Production' : 'Live Dev' ) . ' rolled back to revision #' . $pre_id . '.',
    ), 200 );
}

/**
 * REST: POST /restore-to-revision  body: { firefly_page_id, revision_id } — REMOTE-SIDE.
 * Receiver for the rollback flow. Restores this remote's post to the given
 * revision and logs the rollback locally. Suppresses snippet-write hook
 * because we don't have the corresponding snippet content for this revision.
 */
function firefly_projects_restore_to_revision_endpoint( WP_REST_Request $request ) {
    $auth_failure = firefly_projects_verify_shared_secret( $request );
    if ( $auth_failure !== null ) return $auth_failure;

    $body            = $request->get_json_params() ?: array();
    $firefly_page_id = isset( $body['firefly_page_id'] ) ? (string) $body['firefly_page_id'] : '';
    $revision_id     = isset( $body['revision_id'] ) ? (int) $body['revision_id'] : 0;

    if ( $firefly_page_id === '' || $revision_id <= 0 ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'firefly_page_id and revision_id required.' ), 400 );
    }

    $post = firefly_projects_find_post_by_firefly_page_id( $firefly_page_id );
    if ( ! $post ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Post not found on this site.' ), 404 );
    }

    $revision = wp_get_post_revision( $revision_id );
    if ( ! $revision || (int) $revision->post_parent !== (int) $post->ID ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Revision not found or does not belong to this post (it may have been pruned).' ), 404 );
    }

    // Suppress the snippet-write hook for this restore — we don't have the
    // sender's snippet content for this revision, so re-deriving from
    // post_content is fine on the remote (the snippet auto-export will run
    // naturally from save_post since we DON'T define the flag inbound here…)
    //
    // Actually: we DO want the remote's snippet to update to match the
    // restored content. The remote's save_post → firefly_save_snippet will
    // re-derive correctly from post_content. So intentionally NO suppression.

    $restored_id = wp_restore_post_revision( $revision_id );
    if ( is_wp_error( $restored_id ) || ! $restored_id ) {
        $msg = is_wp_error( $restored_id ) ? $restored_id->get_error_message() : 'Restore failed on this site.';
        return new WP_REST_Response( array( 'success' => false, 'message' => $msg ), 500 );
    }

    if ( function_exists( 'firefly_projects_log_sync' ) ) {
        firefly_projects_log_sync( array(
            'post_id'     => (int) $post->ID,
            'post_type'   => $post->post_type,
            'direction'   => 'rollback_applied',
            'env'         => 'local', // 'local' from this remote's POV — it's a local change on this site
            'user_id'     => null, // initiated by a remote local-dev, no local user
            'status'      => 'success',
            'revision_id' => $revision_id,
            'files_count' => 0,
            'summary'     => array(
                'action'              => 'rollback_applied',
                'restored_to_revision'=> $revision_id,
                'firefly_page_id'     => $firefly_page_id,
            ),
        ) );
    }

    return new WP_REST_Response( array(
        'success'     => true,
        'message'     => 'Restored to revision #' . $revision_id . '.',
        'post_id'     => (int) $post->ID,
        'revision_id' => $revision_id,
    ), 200 );
}

/**
 * Sync page content to remote site
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_sync_page($request) {
    $post_id = $request->get_param('post_id');
    $include_assets = $request->get_param('include_assets');
    $target_env = $request->get_param('target_env');
    // "Sync template files" toggle (snippet + schema entry). Defaults ON when
    // the caller doesn't send it, so older clients keep the full-sync behavior.
    $stf = $request->get_param('sync_template_files');
    $sync_template_files = ( null === $stf ) ? true : filter_var( $stf, FILTER_VALIDATE_BOOLEAN );

    // Get the post
    $post = get_post($post_id);

    if (!$post) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Post not found.'
        ), 404);
    }

    // Verify it's a page or post
    if (!in_array($post->post_type, array('page', 'post'))) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Only pages and posts can be synced.'
        ), 400);
    }

    // Check configuration based on target environment
    if ($target_env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Production endpoint not configured. Please set PROD_ENDPOINT in wp-config.php.'
            ), 400);
        }
    } else {
        if (!firefly_projects_is_configured()) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Plugin not configured. Please set FIREFLY_SHARED_SECRET and LIVE_DEV_ENDPOINT in wp-config.php.'
            ), 400);
        }
    }

    // Load the page sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/page-sync.php';

    // Perform the sync
    $result = firefly_projects_perform_page_sync($post, $include_assets, $target_env, $sync_template_files);

    if ($result['success']) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $result['message'],
            'details' => isset($result['details']) ? $result['details'] : null
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $result['message']
        ), 500);
    }
}

/**
 * Receive page content from local site (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_receive_page($request) {
    // Receive-side auth: shared-secret header. Helper handles missing
    // config, missing/invalid header, and any future hardening.
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    // Load the page sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/page-sync.php';

    // Process the incoming page data
    $result = firefly_projects_handle_incoming_page($request);

    if ($result['success']) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $result['message']
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $result['message']
        ), 500);
    }
}

/**
 * Sync menu to remote site
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_sync_menu($request) {
    $menu_id = $request->get_param('menu_id');
    $target_env = $request->get_param('target_env');

    // Verify menu exists
    $menu = wp_get_nav_menu_object($menu_id);

    if (!$menu) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Menu not found.'
        ), 404);
    }

    // Check configuration based on target environment
    if ($target_env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Production endpoint not configured. Please set PROD_ENDPOINT in wp-config.php.'
            ), 400);
        }
    } else {
        if (!firefly_projects_is_configured()) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Plugin not configured. Please set FIREFLY_SHARED_SECRET and LIVE_DEV_ENDPOINT in wp-config.php.'
            ), 400);
        }
    }

    // Load the menu sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/menu-sync.php';

    // Perform the sync
    $result = firefly_projects_perform_menu_sync($menu_id, $target_env);

    if ($result['success']) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $result['message'],
            'details' => isset($result['details']) ? $result['details'] : null
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $result['message']
        ), 500);
    }
}

/**
 * Receive menu from local site (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_receive_menu($request) {
    // Receive-side auth: shared-secret header. Helper handles missing
    // config, missing/invalid header, and any future hardening.
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    // Load the menu sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/menu-sync.php';

    // Process the incoming menu data
    $result = firefly_projects_handle_incoming_menu($request);

    if ($result['success']) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $result['message'],
            'details' => isset($result['details']) ? $result['details'] : null
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $result['message']
        ), 500);
    }
}

/**
 * Get menu sync status (timestamps for both environments)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_get_menu_sync_status($request) {
    $menu_id = $request->get_param('menu_id');

    // Verify menu exists
    $menu = wp_get_nav_menu_object($menu_id);

    if (!$menu) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Menu not found.'
        ), 404);
    }

    $last_sync_dev = get_option('firefly_menu_sync_dev_' . $menu_id, 0);
    $last_sync_prod = get_option('firefly_menu_sync_prod_' . $menu_id, 0);

    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'menu_id'       => $menu_id,
            'menu_name'     => $menu->name,
            'last_sync_dev' => (int) $last_sync_dev,
            'last_sync_prod' => (int) $last_sync_prod
        )
    ), 200);
}

/**
 * Sync all pages to remote site (bulk operation)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_sync_all_pages($request) {
    $sync_mode = $request->get_param('sync_mode');
    $target_env = $request->get_param('target_env');
    $post_type = $request->get_param('post_type') ?: 'page';
    // "Sync template files" toggle — defaults ON when absent (older clients).
    $stf = $request->get_param('sync_template_files');
    $sync_template_files = ( null === $stf ) ? true : filter_var( $stf, FILTER_VALIDATE_BOOLEAN );

    // Check configuration based on target environment
    if ($target_env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Production endpoint not configured. Please set PROD_ENDPOINT in wp-config.php.'
            ), 400);
        }
    } else {
        if (!firefly_projects_is_configured()) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Plugin not configured. Please set FIREFLY_SHARED_SECRET and LIVE_DEV_ENDPOINT in wp-config.php.'
            ), 400);
        }
    }

    // Load the pages list sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/pages-list-sync.php';

    $delete_only = $request->get_param('delete_only');
    $env_label = ($target_env === 'prod') ? 'Production' : 'Live Dev';
    $type_label = ($post_type === 'post') ? 'posts' : 'pages';

    // Delete-only mode: just handle mirror orphan deletion (syncs done individually by frontend)
    if ($delete_only) {
        $local_page_ids = array();
        $active_template = firefly_get_scoping_template();
        $posts = get_posts(array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'meta_key'       => '_firefly_template',
            'meta_value'     => $active_template,
        ));
        foreach ($posts as $p) {
            $fpid = get_post_meta($p->ID, '_firefly_page_id', true);
            if (empty($fpid)) {
                $tmpl = get_post_meta($p->ID, '_firefly_template', true);
                if (!empty($tmpl)) {
                    $fpid = $tmpl . ':' . $p->post_name;
                }
            }
            if ($fpid) {
                $local_page_ids[] = $fpid;
            }
        }
        $delete_result = firefly_projects_delete_remote_orphans($local_page_ids, $target_env, $post_type);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => sprintf('Deleted %d orphan %s on %s.', $delete_result['deleted'], $type_label, $env_label),
            'details' => array(
                'total'         => 0,
                'synced'        => 0,
                'failed'        => 0,
                'deleted'       => $delete_result['deleted'],
                'errors'        => array(),
                'deleted_pages' => isset($delete_result['deleted_pages']) ? $delete_result['deleted_pages'] : array(),
                'target_env'    => $target_env,
                'sync_mode'     => 'mirror'
            )
        ), 200);
    }

    // Perform the bulk sync
    $result = firefly_projects_sync_all_pages_handler($sync_mode, $target_env, $post_type, $sync_template_files);

    return new WP_REST_Response(array(
        'success' => true,
        'message' => sprintf(
            'Synced %d of %d %s to %s.',
            $result['synced'],
            $result['total'],
            $type_label,
            $env_label
        ),
        'details' => array(
            'total'         => $result['total'],
            'synced'        => $result['synced'],
            'failed'        => $result['failed'],
            'deleted'       => $result['deleted'],
            'errors'        => $result['errors'],
            'deleted_pages' => isset($result['deleted_pages']) ? $result['deleted_pages'] : array(),
            'target_env'    => $target_env,
            'sync_mode'     => $sync_mode
        )
    ), 200);
}

/**
 * Get orphan page count for mirror mode preview
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_get_pages_orphan_count($request) {
    $target_env = $request->get_param('target_env');
    $post_type = $request->get_param('post_type') ?: 'page';

    // Check configuration
    if ($target_env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'orphan_count' => 0,
                'message' => 'Production endpoint not configured.'
            ), 200);
        }
    } else {
        if (!firefly_projects_is_configured()) {
            return new WP_REST_Response(array(
                'success' => false,
                'orphan_count' => 0,
                'message' => 'Plugin not configured.'
            ), 200);
        }
    }

    // Load the pages list sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/pages-list-sync.php';

    $orphan_count = firefly_projects_get_orphan_count($target_env, $post_type);

    return new WP_REST_Response(array(
        'success'      => true,
        'orphan_count' => $orphan_count,
        'target_env'   => $target_env
    ), 200);
}

/**
 * List all published pages (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_list_pages($request) {
    // Receive-side auth: shared-secret header. Helper handles missing
    // config, missing/invalid header, and any future hardening.
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    // Load the pages list sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/pages-list-sync.php';

    $result = firefly_projects_list_pages_handler($request);

    return new WP_REST_Response($result, 200);
}

/**
 * Delete pages by slug (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_delete_pages($request) {
    // Receive-side auth: shared-secret header. Helper handles missing
    // config, missing/invalid header, and any future hardening.
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    // Load the pages list sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/pages-list-sync.php';

    $result = firefly_projects_delete_pages_handler($request);

    return new WP_REST_Response($result, 200);
}

/**
 * Pull page from remote environment to local
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_pull_page($request) {
    $post_slug = $request->get_param('post_slug');
    $source_env = $request->get_param('source_env');
    $firefly_page_id = $request->get_param('firefly_page_id');
    $template = $request->get_param('template');

    // Template guard: a template travels between environments only where it
    // exists on both sides. If this site doesn't have the named template
    // (no {template}-schema.json), refuse up front rather than importing
    // content into a system that can't render or manage it.
    $check_template = $template;
    if (!$check_template && $firefly_page_id && strpos($firefly_page_id, ':') !== false) {
        $check_template = substr($firefly_page_id, 0, strpos($firefly_page_id, ':'));
    }
    if ($check_template && function_exists('firefly_is_valid_template') && !firefly_is_valid_template($check_template)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => "Template '{$check_template}' is not installed on this site. "
                       . "Initialize it first (firefly templates init {$check_template}) before pulling its content."
        ), 400);
    }

    // Determine endpoint based on source environment
    if ($source_env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Production endpoint not configured.'
            ), 400);
        }
        $endpoint = PROD_ENDPOINT;
    } else {
        if (!defined('LIVE_DEV_ENDPOINT') || empty(LIVE_DEV_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Live Dev endpoint not configured.'
            ), 400);
        }
        $endpoint = LIVE_DEV_ENDPOINT;
    }

    // Build export URL. Prefer the stable firefly_page_id so the remote can
    // resolve the right page when a slug is shared across templates.
    if (preg_match('/(https?:\/\/[^\/]+)/', $endpoint, $matches)) {
        $base_url = $matches[1];
        $query = array();
        if (!empty($firefly_page_id)) { $query['firefly_page_id'] = $firefly_page_id; }
        if (!empty($post_slug))       { $query['post_slug']       = $post_slug; }
        if (!empty($template))        { $query['template']        = $template; }
        $export_url = $base_url . '/wp-json/firefly-plugin/v1/export-page?' . http_build_query($query);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Could not determine remote URL.'
        ), 400);
    }

    // Fetch page data from remote
    $response = wp_remote_get($export_url, array(
        'headers' => array(
            'X-Firefly-Secret' => FIREFLY_SHARED_SECRET
        ),
        'timeout' => 60
    ));

    if (is_wp_error($response)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to connect to remote: ' . $response->get_error_message()
        ), 500);
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($http_code !== 200 || !isset($data['success']) || !$data['success']) {
        $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error from remote.';
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Remote error: ' . $error_msg
        ), $http_code);
    }

    // Load asset mapping functions
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/asset-mapping.php';

    // Process the pulled page data
    $result = firefly_projects_import_pulled_page($data, $source_env);

    if ($result['success']) {
        // Activity log row — pull, success.
        if ( function_exists( 'firefly_projects_log_sync' ) && ! empty( $result['post_id'] ) ) {
            $pulled = get_post( (int) $result['post_id'] );
            firefly_projects_log_sync(array(
                'post_id'     => (int) $result['post_id'],
                'post_type'   => $pulled ? $pulled->post_type : 'page',
                'direction'   => 'pull',
                'env'         => ( $source_env === 'prod' ) ? 'prod' : 'dev',
                'user_id'     => get_current_user_id() ?: null,
                'status'      => 'success',
                'revision_id' => null,
                'files_count' => 0,
                'summary'     => array(
                    'source_host' => parse_url( $endpoint, PHP_URL_HOST ),
                    'slug'        => $post_slug,
                ),
            ));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => $result['message'],
            'post_id' => $result['post_id'],
            'details' => isset($result['details']) ? $result['details'] : null
        ), 200);
    } else {
        // Activity log row — pull, failure. We may not know the local post_id
        // yet (it could be a not-yet-existing page), so look it up by slug.
        if ( function_exists( 'firefly_projects_log_sync' ) ) {
            $local = get_page_by_path( $post_slug, OBJECT, array( 'page', 'post' ) );
            if ( $local ) {
                firefly_projects_log_sync(array(
                    'post_id'     => (int) $local->ID,
                    'post_type'   => $local->post_type,
                    'direction'   => 'pull',
                    'env'         => ( $source_env === 'prod' ) ? 'prod' : 'dev',
                    'user_id'     => get_current_user_id() ?: null,
                    'status'      => 'failure',
                    'revision_id' => null,
                    'files_count' => 0,
                    'summary'     => array(
                        'source_host'   => parse_url( $endpoint, PHP_URL_HOST ),
                        'slug'          => $post_slug,
                        'error_message' => $result['message'],
                    ),
                ));
            }
        }
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $result['message']
        ), 500);
    }
}

/**
 * Export page for pull request (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_export_page($request) {
    // Receive-side auth: shared-secret header. Helper handles missing
    // config, missing/invalid header, and any future hardening.
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $post_slug       = $request->get_param('post_slug');
    $firefly_page_id = $request->get_param('firefly_page_id');
    $template        = $request->get_param('template');

    // Resolve the page in a template-aware way so the same slug can exist
    // across templates. Prefer the stable firefly_page_id ("{template}:{slug}").
    $post = null;
    if ($firefly_page_id) {
        $post = firefly_projects_find_post_by_firefly_page_id($firefly_page_id);
        if (!$post) {
            $parsed = firefly_projects_parse_page_id($firefly_page_id);
            if ($parsed['slug']) {
                if (empty($template))  { $template  = $parsed['template']; }
                if (empty($post_slug)) { $post_slug = $parsed['slug']; }
                $post = firefly_projects_find_scoped_page($parsed['slug'], $parsed['template']);
            }
        }
    }
    if (!$post && $post_slug) {
        $post = firefly_projects_find_scoped_page($post_slug, $template);
    }
    if (!$post && $post_slug) {
        // Legacy last-resort: unscoped slug lookup.
        $post = get_page_by_path($post_slug, OBJECT, array('page', 'post'));
    }

    if (!$post) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Page not found: ' . ($firefly_page_id ? $firefly_page_id : $post_slug)
        ), 404);
    }

    // Load asset mapping functions
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/asset-mapping.php';

    // Detect assets in content. Pass true so already-materialized
    // /uploads/pages/<slug>/ inline assets (from a prior sync) are packaged too —
    // otherwise the pull drops every inline image and per-page icon, leaving 404s
    // on the receiver while media-library originals + featured images survive.
    $assets = firefly_projects_detect_all_assets($post->post_content, true);

    // Package asset data with base64 content
    $asset_data = array();
    $packed_filenames = array();
    foreach ($assets as $asset_url) {
        $local_path = firefly_projects_resolve_asset_source($asset_url);

        if ($local_path && file_exists($local_path)) {
            $asset_data[] = array(
                'url'      => $asset_url,
                'filename' => basename($local_path),
                'content'  => base64_encode(file_get_contents($local_path)),
                'size'     => filesize($local_path)
            );
            $packed_filenames[basename($local_path)] = true;
        }
    }

    // Also enumerate the page's whole /uploads/pages/<slug>/ directory. The
    // content scan above only finds assets referenced inline; files that live
    // in the page dir without an inline reference (per-page icons stored in
    // meta, downloads linked from elsewhere) would otherwise be silently
    // dropped and need manual FTP — the exact gap this closes.
    //
    // BUT page-asset dirs are keyed by slug ONLY, and the scoping system
    // deliberately allows two templates to share a slug — so on a multi-template
    // install (e.g. dev) another template's page can share this /pages/<slug>/
    // dir. Enumerating the whole dir would then ship the OTHER template's files
    // under this pull, breaking tenant isolation. Guard: if the slug is owned by
    // more than one template, skip the whole-dir scan and ship only the
    // content-referenced assets (which are unambiguously this page's).
    $this_template = get_post_meta($post->ID, '_firefly_template', true);
    $slug_shared_across_templates = false;
    $same_slug_ids = get_posts(array(
        'post_type'            => array('page', 'post'),
        'post_status'          => 'publish',
        'name'                 => $post->post_name,
        'numberposts'          => -1,
        'fields'               => 'ids',
        'firefly_skip_scoping' => true,
    ));
    foreach ($same_slug_ids as $sid) {
        if ((int) $sid === (int) $post->ID) continue;
        if (get_post_meta($sid, '_firefly_template', true) !== $this_template) {
            $slug_shared_across_templates = true;
            break;
        }
    }

    $page_dir = firefly_projects_get_page_asset_filesystem_path($post->post_name);
    $page_dir_skipped = array();
    if (is_dir($page_dir) && !$slug_shared_across_templates) {
        foreach (scandir($page_dir) as $entry) {
            if ($entry === '.' || $entry === '..' || isset($packed_filenames[$entry])) continue;
            $path = $page_dir . '/' . $entry;
            if (!is_file($path)) continue;
            $asset_data[] = array(
                'url'      => '/wp-content/uploads/pages/' . $post->post_name . '/' . $entry,
                'filename' => $entry,
                'content'  => base64_encode(file_get_contents($path)),
                'size'     => filesize($path)
            );
            $packed_filenames[$entry] = true;
        }
    } elseif (is_dir($page_dir) && $slug_shared_across_templates) {
        // Record what we deliberately did NOT ship so the operator knows any
        // non-inline page-dir files for this slug need attention (the shared
        // dir can't be attributed to one template safely).
        foreach (scandir($page_dir) as $entry) {
            if ($entry === '.' || $entry === '..' || isset($packed_filenames[$entry])) continue;
            if (is_file($page_dir . '/' . $entry)) $page_dir_skipped[] = $entry;
        }
    }

    // Get post meta. Whitelist must stay in sync with the package /
    // perform_page_sync whitelists above — same set of meta keys travels
    // in both directions (push and pull).
    $meta = get_post_meta($post->ID);
    $meta_data = array();
    $allowed_underscore_keys = array(
        '_thumbnail_id',
        '_geo_summary', '_geo_key_facts', '_geo_article_type', '_geo_faq',
        '_firefly_template', '_firefly_page_id',
        '_firefly_mobile_thumbnail_id', '_firefly_mobile_thumbnail_breakpoint',
        // SEO meta (per-page overrides — see seo-post.php for the registration)
        '_seo_title', '_seo_description', '_seo_canonical',
        '_seo_robots_noindex', '_seo_robots_nofollow',
        // NOTE: _seo_og_image_id is intentionally NOT in this whitelist.
        // The id is environment-specific; once pull-side OG file shipping
        // lands the receiver will re-resolve the id locally (mirrors the
        // sync direction).
        '_seo_og_title', '_seo_og_description',
    );
    foreach ($meta as $key => $values) {
        // Skip internal meta (except whitelisted keys)
        if (strpos($key, '_') === 0 && !in_array($key, $allowed_underscore_keys)) {
            continue;
        }
        $meta_data[$key] = $values[0];
    }

    // Ensure a stable cross-environment id travels with the page so the
    // importer can match it template-scoped even when the meta wasn't stored.
    if (empty($meta_data['_firefly_page_id'])) {
        $tmpl = isset($meta_data['_firefly_template']) ? $meta_data['_firefly_template'] : '';
        if ($tmpl) {
            $meta_data['_firefly_page_id'] = $tmpl . ':' . $post->post_name;
        }
    }

    // Get existing asset map if any
    $asset_map = firefly_projects_get_asset_map($post->ID);

    // Get featured image data if exists
    $featured_image = null;
    $thumbnail_id = get_post_thumbnail_id($post->ID);
    if ($thumbnail_id) {
        $thumbnail_path = get_attached_file($thumbnail_id);
        if ($thumbnail_path && file_exists($thumbnail_path)) {
            $featured_image = array(
                'filename' => basename($thumbnail_path),
                'content'  => base64_encode(file_get_contents($thumbnail_path)),
                'mime_type' => get_post_mime_type($thumbnail_id),
                'alt_text' => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
                'title'    => get_the_title($thumbnail_id)
            );
        }
    }

    // Mobile featured image (same file-shipping shape as push). The
    // _firefly_mobile_thumbnail_id meta in the whitelist above carries a
    // REMOTE attachment id — the importer re-resolves it locally from this
    // file, exactly like the push receive side does.
    $mobile_featured_image = null;
    $mobile_featured_image_clear = false;
    $mobile_id = (int) get_post_meta($post->ID, '_firefly_mobile_thumbnail_id', true);
    if ($mobile_id) {
        $mobile_path = get_attached_file($mobile_id);
        if ($mobile_path && file_exists($mobile_path)) {
            $mobile_featured_image = array(
                'filename'  => basename($mobile_path),
                'content'   => base64_encode(file_get_contents($mobile_path)),
                'mime_type' => get_post_mime_type($mobile_id),
                'alt_text'  => get_post_meta($mobile_id, '_wp_attachment_image_alt', true),
                'title'     => get_the_title($mobile_id)
            );
        }
    } else {
        $mobile_featured_image_clear = true;
    }

    // Per-page OG image override (_seo_og_image_id). This is the pull-side OG
    // file shipping the meta-whitelist note above anticipates: the id itself
    // never travels, the file does, and the importer re-resolves a local id.
    $og_image = null;
    $og_image_clear = false;
    $og_id = (int) get_post_meta($post->ID, '_seo_og_image_id', true);
    if ($og_id) {
        $og_path = get_attached_file($og_id);
        if ($og_path && file_exists($og_path)) {
            $og_image = array(
                'filename'  => basename($og_path),
                'content'   => base64_encode(file_get_contents($og_path)),
                'mime_type' => get_post_mime_type($og_id),
                'alt_text'  => get_post_meta($og_id, '_wp_attachment_image_alt', true),
                'title'     => get_the_title($og_id)
            );
        }
    } else {
        $og_image_clear = true;
    }

    // Theme-side files (snippet + schema entry) so a pulled page carries its
    // template files just like a push with "Sync template files" on.
    $associated_files = function_exists('firefly_projects_collect_associated_files')
        ? firefly_projects_collect_associated_files($post)
        : array();

    // Tracked links travel too when the link-tracking model is present.
    $tracked_links = array();
    if (function_exists('firefly_link_tracking_get_post_links_for_sync')) {
        $tracked_links = firefly_link_tracking_get_post_links_for_sync($post->ID);
    }

    // Check if this page has special WordPress roles (home page, posts page)
    $page_role = null;
    if ($post->post_type === 'page') {
        $show_on_front = get_option('show_on_front');
        if ($show_on_front === 'page') {
            $page_on_front = get_option('page_on_front');
            $page_for_posts = get_option('page_for_posts');

            if ($page_on_front && $page_on_front == $post->ID) {
                $page_role = 'front_page';
            } elseif ($page_for_posts && $page_for_posts == $post->ID) {
                $page_role = 'posts_page';
            }
        }
    }

    return new WP_REST_Response(array(
        'success' => true,
        'post_data' => array(
            'post_title'   => $post->post_title,
            'post_name'    => $post->post_name,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_type'    => $post->post_type,
            'post_status'  => $post->post_status,
            'menu_order'   => $post->menu_order,
            'post_parent'  => $post->post_parent,
            // The environment being pulled FROM is the source of truth for
            // dates — date-based post permalinks must survive the pull.
            'post_date'     => $post->post_date,
            'post_date_gmt' => $post->post_date_gmt,
        ),
        'meta_data'      => $meta_data,
        'assets'         => $asset_data,
        // Non-inline page-dir files withheld because the slug is shared across
        // templates (see the scandir guard above). Empty in the normal case.
        'page_dir_skipped' => $page_dir_skipped,
        'asset_map'      => $asset_map,
        'featured_image' => $featured_image,
        'mobile_featured_image'       => $mobile_featured_image,
        'mobile_featured_image_clear' => $mobile_featured_image_clear,
        'og_image'                    => $og_image,
        'og_image_clear'              => $og_image_clear,
        'associated_files'            => $associated_files,
        'tracked_links'               => $tracked_links,
        'page_role'      => $page_role
    ), 200);
}

/**
 * Import a pulled page and process assets
 *
 * @param array $data The page data from remote
 * @param string $source_env The source environment
 * @return array Result array
 */
function firefly_projects_import_pulled_page($data, $source_env) {
    $post_data = $data['post_data'];
    $meta_data = isset($data['meta_data']) ? $data['meta_data'] : array();
    $assets = isset($data['assets']) ? $data['assets'] : array();
    $remote_asset_map = isset($data['asset_map']) ? $data['asset_map'] : array();
    $associated_files = isset($data['associated_files']) ? $data['associated_files'] : array();
    $tracked_links = isset($data['tracked_links']) ? $data['tracked_links'] : array();

    $page_slug = $post_data['post_name'];

    // Match the local target template-scoped, so a slug shared across templates
    // lands on the right page (or creates a new scoped one) instead of
    // clobbering whichever template happens to own that slug.
    $incoming_template = isset($meta_data['_firefly_template']) ? $meta_data['_firefly_template'] : '';
    $firefly_page_id   = isset($meta_data['_firefly_page_id']) ? $meta_data['_firefly_page_id'] : '';

    // Template guard (authoritative — pull_page pre-checks, but the template
    // may only be discoverable here from the incoming meta). Content whose
    // template isn't installed locally must not land.
    if ($incoming_template && function_exists('firefly_is_valid_template') && !firefly_is_valid_template($incoming_template)) {
        return array(
            'success' => false,
            'message' => "Refused: template '{$incoming_template}' is not installed on this site. "
                       . "Initialize it first (firefly templates init {$incoming_template}) before pulling its content."
        );
    }

    // When the export carries the authoritative snippet + schema entry,
    // suppress the local save_post → snippet auto-export hook so it doesn't
    // regenerate the snippet from post_content and clobber the remote's file.
    // (Old remotes without associated_files keep the hook, so the snippet
    // still refreshes from content.)
    if (!empty($associated_files) && !defined('FIREFLY_PROJECTS_SYNCING_INBOUND')) {
        define('FIREFLY_PROJECTS_SYNCING_INBOUND', true);
    }

    // TRUE SYNC: store post_content byte-for-byte. Without a logged-in user
    // with unfiltered_html, KSES save filters mangle block markup (double
    // hyphens in block-attribute JSON get unicode-escaped, inline CSS props
    // get dropped). The content comes from a trusted admin-authored
    // environment; strip KSES for this request, mirroring the push receive
    // side. kses_init_filters() is re-registered on shutdown.
    kses_remove_filters();
    add_action('shutdown', 'kses_init_filters');

    // Ensure a stable id exists so future syncs match deterministically.
    if (empty($firefly_page_id) && $incoming_template) {
        $firefly_page_id = $incoming_template . ':' . $page_slug;
        $meta_data['_firefly_page_id'] = $firefly_page_id;
    }

    $existing_post = null;
    if ($firefly_page_id) {
        $existing_post = firefly_projects_find_post_by_firefly_page_id($firefly_page_id);
        if (!$existing_post && $incoming_template) {
            $existing_post = firefly_projects_find_scoped_page($page_slug, $incoming_template, array($post_data['post_type']));
        }
    }
    if (!$existing_post) {
        // Slug fallback only when the template matches (or the local page has no
        // template). A different template's same-slug page must NOT be reused.
        $fallback = get_page_by_path($page_slug, OBJECT, $post_data['post_type']);
        if ($fallback) {
            $fallback_template = get_post_meta($fallback->ID, '_firefly_template', true);
            if (empty($fallback_template) || $fallback_template === $incoming_template) {
                $existing_post = $fallback;
            }
        }
    }

    // Initially save content as-is (we'll rewrite after processing assets)
    $wp_post_data = array(
        'post_title'   => $post_data['post_title'],
        'post_name'    => $post_data['post_name'],
        'post_content' => $post_data['post_content'],
        'post_excerpt' => isset($post_data['post_excerpt']) ? $post_data['post_excerpt'] : '',
        'post_type'    => $post_data['post_type'],
        'post_status'  => $post_data['post_status'],
        'menu_order'   => isset($post_data['menu_order']) ? $post_data['menu_order'] : 0,
    );

    // wp_insert_post/wp_update_post internally call wp_unslash — must slash
    // first or block-attribute JSON escapes (\u003c, \u0022) lose their
    // backslashes and render as literal "u003c" on the live site.
    $wp_post_data = wp_slash($wp_post_data);

    // The pulled-from environment is the source of truth for dates, so
    // date-based post permalinks (/2026/05/11/slug/) survive the pull. Old
    // remotes that don't ship post_date leave the local date untouched.
    if (!empty($post_data['post_date'])) {
        $wp_post_data['post_date'] = wp_slash($post_data['post_date']);
        $wp_post_data['edit_date'] = true;
    }
    if (!empty($post_data['post_date_gmt'])) {
        $wp_post_data['post_date_gmt'] = wp_slash($post_data['post_date_gmt']);
    }

    if ($existing_post) {
        $wp_post_data['ID'] = $existing_post->ID;
        $post_id = wp_update_post($wp_post_data, true);
    } else {
        $post_id = wp_insert_post($wp_post_data, true);
    }

    if (is_wp_error($post_id)) {
        return array(
            'success' => false,
            'message' => 'Failed to save page: ' . $post_id->get_error_message()
        );
    }

    // Save meta data
    foreach ($meta_data as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }

    // Ensure template assignment for imported pages (covers updates where
    // auto-assignment doesn't fire and exports that omit _firefly_template)
    if (!get_post_meta($post_id, '_firefly_template', true)) {
        $active_template = function_exists('firefly_collective_get_active_template')
            ? firefly_collective_get_active_template()
            : get_option('firefly_collective_active_template', 'default');
        update_post_meta($post_id, '_firefly_template', $active_template);
    }

    // Preserve the intended slug across templates. wp_insert_post may have
    // deduplicated it (e.g. "template" -> "template-2") because another template
    // already owns that slug — the theme's wp_unique_post_slug filter can't see
    // _firefly_template on a brand-new insert (post ID is 0 during slug
    // generation). Now that the template meta is set, re-apply the desired slug;
    // the filter sees the template and keeps it scoped per template.
    $desired_slug = isset($post_data['post_name']) ? $post_data['post_name'] : '';
    if ($desired_slug && get_post_field('post_name', $post_id) !== $desired_slug) {
        wp_update_post(array('ID' => $post_id, 'post_name' => $desired_slug));
    }

    // Apply theme-side files (snippet + schema entry) shipped by the remote —
    // the same manifest push uses, so a pulled page carries its template
    // files without FTP. Runs after meta so the writer sees _firefly_template.
    $associated_report = array('files_written' => array(), 'warnings' => array());
    $applied_post = get_post($post_id);
    if ($applied_post && !empty($associated_files) && function_exists('firefly_projects_apply_associated_files')) {
        $associated_report = firefly_projects_apply_associated_files($associated_files, $applied_post);
    }

    // Sync tracked links if provided
    if (!empty($tracked_links) && function_exists('firefly_link_tracking_sync_incoming_links')) {
        firefly_link_tracking_sync_incoming_links($post_id, $tracked_links);
    }

    // Process assets - save to uploads/pages and create mappings
    $mappings = array();
    $assets_saved = 0;

    // Create page assets directory
    $assets_dir = firefly_projects_get_page_asset_filesystem_path($page_slug);
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
    }

    foreach ($assets as $asset) {
        $original_url = $asset['url'];
        $filename = sanitize_file_name($asset['filename']);
        $content = base64_decode($asset['content']);

        $local_path = $assets_dir . '/' . $filename;
        $local_url = '/wp-content/uploads/pages/' . $page_slug . '/' . $filename;

        // Save file
        if (file_put_contents($local_path, $content)) {
            $mappings[$original_url] = $local_url;
            $assets_saved++;
        }
    }

    // Determine asset origin based on source environment
    $asset_origin = ($source_env === 'prod') ? 'production' : 'dev';

    // If pulling from production, URLs need rewriting to local paths
    // If pulling from dev, URLs may already be local paths (check remote_asset_map)
    if ($source_env === 'prod') {
        // Rewrite content URLs to local paths
        $new_content = firefly_projects_rewrite_content_urls($post_data['post_content'], $mappings, 'to_dev');

        // Update post with rewritten content (wp_slash to preserve block-attr backslashes)
        wp_update_post(wp_slash(array(
            'ID'           => $post_id,
            'post_content' => $new_content
        )));

        // Save asset map
        firefly_projects_save_asset_map($post_id, array(
            'asset_origin' => $asset_origin,
            'mappings'     => $mappings,
            'local_created'  => array()
        ));
    } else {
        // From dev - content may already have local URLs
        // Import the remote asset map if it exists
        if (!empty($remote_asset_map) && !empty($remote_asset_map['mappings'])) {
            firefly_projects_save_asset_map($post_id, $remote_asset_map);
        } else {
            // No existing map - these are locally-created assets
            $local_created = array_values($mappings);
            firefly_projects_save_asset_map($post_id, array(
                'asset_origin' => 'local',
                'mappings'     => array(),
                'local_created'  => $local_created
            ));
        }
    }

    // Process featured image if included
    $featured_image_set = false;
    if (!empty($data['featured_image'])) {
        $featured = $data['featured_image'];
        $filename = sanitize_file_name($featured['filename']);

        // Capture the prior featured attachment so it can be purged after the
        // new one is wired up — otherwise every re-pull accumulates an orphan
        // attachment (mirrors the push receive side).
        $previous_thumb_id = (int) get_post_thumbnail_id($post_id);

        // Save to page assets directory
        $featured_path = $assets_dir . '/' . $filename;
        $featured_content = base64_decode($featured['content']);

        if (file_put_contents($featured_path, $featured_content)) {
            // Create attachment post
            $attachment = array(
                'post_mime_type' => $featured['mime_type'],
                'post_title'     => !empty($featured['title']) ? $featured['title'] : pathinfo($filename, PATHINFO_FILENAME),
                'post_content'   => '',
                'post_status'    => 'inherit'
            );

            // Insert attachment
            $attach_id = wp_insert_attachment($attachment, $featured_path, $post_id);

            if (!is_wp_error($attach_id)) {
                // Generate attachment metadata
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attach_data = wp_generate_attachment_metadata($attach_id, $featured_path);
                wp_update_attachment_metadata($attach_id, $attach_data);

                // Set alt text if provided
                if (!empty($featured['alt_text'])) {
                    update_post_meta($attach_id, '_wp_attachment_image_alt', $featured['alt_text']);
                }

                // Set as featured image
                set_post_thumbnail($post_id, $attach_id);
                $featured_image_set = true;

                // Purge the previous featured attachment (path-aware: the new
                // file typically lands on the same path the old one owned).
                if (function_exists('firefly_projects_safely_replace_previous_attachment')) {
                    firefly_projects_safely_replace_previous_attachment($previous_thumb_id, $attach_id);
                }
            }
        }
    }

    // Mobile featured image (_firefly_mobile_thumbnail_id). The whitelisted
    // meta carried the REMOTE attachment id — re-resolve it locally from the
    // shipped file, mirroring the push receive side. Without this the meta
    // points at whatever attachment happens to own that id here.
    $featured = !empty($data['featured_image']) ? $data['featured_image'] : null;
    $mobile_featured = !empty($data['mobile_featured_image']) ? $data['mobile_featured_image'] : null;
    $mobile_attach_id = null;
    if ($mobile_featured && !empty($mobile_featured['filename'])) {
        $mobile_filename = sanitize_file_name($mobile_featured['filename']);
        // Don't clobber the desktop featured file if they share a name.
        $mobile_destination_name = $mobile_filename;
        if ($featured && isset($featured['filename']) && sanitize_file_name($featured['filename']) === $mobile_filename) {
            $mobile_destination_name = 'mobile-' . $mobile_filename;
        }
        $mobile_path = $assets_dir . '/' . $mobile_destination_name;
        $previous_mobile_id = (int) get_post_meta($post_id, '_firefly_mobile_thumbnail_id', true);

        if (file_put_contents($mobile_path, base64_decode($mobile_featured['content']))) {
            $mobile_attachment = array(
                'post_mime_type' => $mobile_featured['mime_type'],
                'post_title'     => !empty($mobile_featured['title']) ? $mobile_featured['title'] : pathinfo($mobile_filename, PATHINFO_FILENAME),
                'post_content'   => '',
                'post_status'    => 'inherit'
            );
            $mobile_attach_id = wp_insert_attachment($mobile_attachment, $mobile_path, $post_id);
            if (!is_wp_error($mobile_attach_id)) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                wp_update_attachment_metadata($mobile_attach_id, wp_generate_attachment_metadata($mobile_attach_id, $mobile_path));
                if (!empty($mobile_featured['alt_text'])) {
                    update_post_meta($mobile_attach_id, '_wp_attachment_image_alt', $mobile_featured['alt_text']);
                }
                update_post_meta($post_id, '_firefly_mobile_thumbnail_id', (int) $mobile_attach_id);
                if (function_exists('firefly_projects_safely_replace_previous_attachment')) {
                    firefly_projects_safely_replace_previous_attachment($previous_mobile_id, $mobile_attach_id);
                }
            } else {
                $mobile_attach_id = null;
            }
        }
    } elseif (!empty($data['mobile_featured_image_clear'])) {
        delete_post_meta($post_id, '_firefly_mobile_thumbnail_id');
    } elseif (isset($meta_data['_firefly_mobile_thumbnail_id'])) {
        // Meta arrived (old remote / file missing) but no file to back it —
        // the remote id is meaningless locally, drop it rather than point at
        // an unrelated local attachment.
        delete_post_meta($post_id, '_firefly_mobile_thumbnail_id');
    }

    // Per-page OG image override (_seo_og_image_id): re-resolve locally,
    // reusing the featured/mobile attachment when the same file fills both
    // slots (mirrors push receive de-dup).
    $og_image = !empty($data['og_image']) ? $data['og_image'] : null;
    if ($og_image && !empty($og_image['filename'])) {
        $og_filename = sanitize_file_name($og_image['filename']);
        $og_attach_id_use = null;

        if (isset($attach_id) && !is_wp_error($attach_id) && $featured && isset($featured['filename']) && sanitize_file_name($featured['filename']) === $og_filename) {
            $og_attach_id_use = (int) $attach_id;
        } elseif ($mobile_attach_id && $mobile_featured && isset($mobile_featured['filename']) && sanitize_file_name($mobile_featured['filename']) === $og_filename) {
            $og_attach_id_use = (int) $mobile_attach_id;
        } else {
            $og_destination_name = $og_filename;
            $collides_with_featured = $featured && isset($featured['filename']) && sanitize_file_name($featured['filename']) === $og_filename;
            $collides_with_mobile   = $mobile_featured && isset($mobile_featured['filename']) && sanitize_file_name($mobile_featured['filename']) === $og_filename;
            if ($collides_with_featured || $collides_with_mobile) {
                $og_destination_name = 'og-' . $og_filename;
            }
            $og_path = $assets_dir . '/' . $og_destination_name;
            $previous_og_id = (int) get_post_meta($post_id, '_seo_og_image_id', true);

            if (file_put_contents($og_path, base64_decode($og_image['content']))) {
                $og_attachment = array(
                    'post_mime_type' => $og_image['mime_type'],
                    'post_title'     => !empty($og_image['title']) ? $og_image['title'] : pathinfo($og_filename, PATHINFO_FILENAME),
                    'post_content'   => '',
                    'post_status'    => 'inherit'
                );
                $og_new_id = wp_insert_attachment($og_attachment, $og_path, $post_id);
                if (!is_wp_error($og_new_id)) {
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    wp_update_attachment_metadata($og_new_id, wp_generate_attachment_metadata($og_new_id, $og_path));
                    if (!empty($og_image['alt_text'])) {
                        update_post_meta($og_new_id, '_wp_attachment_image_alt', $og_image['alt_text']);
                    }
                    $og_attach_id_use = (int) $og_new_id;
                    if (function_exists('firefly_projects_safely_replace_previous_attachment')) {
                        firefly_projects_safely_replace_previous_attachment($previous_og_id, $og_new_id);
                    }
                }
            }
        }

        if ($og_attach_id_use) {
            update_post_meta($post_id, '_seo_og_image_id', $og_attach_id_use);
        }
    } elseif (!empty($data['og_image_clear'])) {
        delete_post_meta($post_id, '_seo_og_image_id');
    }

    // Set page role if specified (front page or posts page). The theme resolves
    // these PER TEMPLATE via firefly_front_page_{template}/firefly_posts_page_{template}
    // (used for front-end front-page detection and the Customizer preview), so we
    // must set those too — not just the global core options. The global option is
    // only touched for the active template, so pulling another template's front
    // page can't hijack the live site's front page.
    $page_role = isset($data['page_role']) ? $data['page_role'] : null;
    if ($page_role && $post_data['post_type'] === 'page') {
        $role_template = get_post_meta($post_id, '_firefly_template', true);
        $active_template = function_exists('firefly_collective_get_active_template')
            ? firefly_collective_get_active_template()
            : get_option('firefly_collective_active_template', 'default');
        $is_active = (!$role_template || $role_template === $active_template);

        if ($page_role === 'front_page') {
            if ($role_template) {
                update_option("firefly_front_page_{$role_template}", $post_id);
            }
            if ($is_active) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $post_id);
            }
        } elseif ($page_role === 'posts_page') {
            if ($role_template) {
                update_option("firefly_posts_page_{$role_template}", $post_id);
            }
            if ($is_active) {
                update_option('page_for_posts', $post_id);
            }
        }
    }

    // Save pull timestamp
    $pull_time = time();
    if ($source_env === 'prod') {
        update_post_meta($post_id, '_firefly_last_pull_prod', $pull_time);
    } else {
        update_post_meta($post_id, '_firefly_last_pull_dev', $pull_time);
    }

    $env_label = ($source_env === 'prod') ? 'Production' : 'Live Dev';

    // Use appropriate label for message
    $type_label = ($post_data['post_type'] === 'post') ? 'Post' : 'Page';

    return array(
        'success' => true,
        'message' => $type_label . ' pulled successfully from ' . $env_label,
        'post_id' => $post_id,
        'details' => array(
            'page_title'         => $post_data['post_title'],
            'page_slug'          => $page_slug,
            'assets_pulled'      => $assets_saved,
            'featured_image_set' => $featured_image_set,
            'source_env'         => $source_env,
            'is_update'          => $existing_post ? true : false,
            'page_role'          => $page_role,
            'associated_files'   => $associated_report['files_written'],
            'associated_files_warnings' => $associated_report['warnings']
        )
    );
}

/**
 * Process page assets for local environment (create mappings, copy assets)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_process_page_assets_callback($request) {
    $post_id = $request->get_param('post_id');

    // Load asset mapping functions
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/asset-mapping.php';

    // Process the page
    $result = firefly_projects_process_page_assets($post_id, 'production');

    if ($result['success']) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $result['message'],
            'details' => array(
                'assets_processed' => $result['assets_processed'],
                'assets_failed'    => isset($result['assets_failed']) ? $result['assets_failed'] : 0
            )
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $result['message']
        ), 500);
    }
}

/**
 * Fetch available pages from remote environment (for pull operations)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_fetch_remote_pages($request) {
    $source_env = $request->get_param('source_env');
    $post_type = $request->get_param('post_type');
    $template = $request->get_param('template');
    $template = is_string($template) ? trim($template) : '';

    // The system is always template-scoped: when the caller doesn't name a
    // template, scope to this site's active one instead of listing everything.
    // `template=all` is the explicit escape hatch.
    if ($template === '' && function_exists('firefly_get_scoping_template')) {
        $template = firefly_get_scoping_template();
    }

    // Determine endpoint based on source environment
    if ($source_env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Production endpoint not configured.'
            ), 400);
        }
        $endpoint = PROD_ENDPOINT;
    } else {
        if (!defined('LIVE_DEV_ENDPOINT') || empty(LIVE_DEV_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Live Dev endpoint not configured.'
            ), 400);
        }
        $endpoint = LIVE_DEV_ENDPOINT;
    }

    // Build list-pages URL. Pass `template` so the remote scopes its
    // response to the local site's active template — otherwise same-slug
    // posts from sibling templates leak into the pull modal.
    if (preg_match('/(https?:\/\/[^\/]+)/', $endpoint, $matches)) {
        $base_url = $matches[1];
        $list_url = $base_url . '/wp-json/firefly-plugin/v1/list-pages?include_drafts=true&post_type=' . urlencode($post_type);
        if ( $template !== '' ) {
            $list_url .= '&template=' . urlencode( $template );
        }
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Could not determine remote URL.'
        ), 400);
    }

    // Fetch pages from remote
    $response = wp_remote_get($list_url, array(
        'headers' => array(
            'X-Firefly-Secret' => FIREFLY_SHARED_SECRET
        ),
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to connect to remote: ' . $response->get_error_message()
        ), 500);
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($http_code !== 200 || !isset($data['success']) || !$data['success']) {
        $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error from remote.';
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Remote error: ' . $error_msg
        ), $http_code);
    }

    $env_label = ($source_env === 'prod') ? 'Production' : 'Live Dev';

    // Belt-and-braces: an OLD remote plugin ignores the template param and
    // returns every template's content. Its list still carries per-page
    // firefly_page_id ("{template}:{slug}"), so filter locally. Pages with no
    // id can't be attributed to a template and are dropped — safe-by-default
    // beats leaking sibling templates. New remotes echo back 'template' and
    // have already scoped server-side.
    $pages = $data['pages'];
    if ($template !== '' && $template !== 'all' && !isset($data['template'])) {
        $prefix = $template . ':';
        $pages = array_values(array_filter($pages, function ($p) use ($prefix) {
            return !empty($p['firefly_page_id']) && strpos($p['firefly_page_id'], $prefix) === 0;
        }));
    }

    return new WP_REST_Response(array(
        'success'    => true,
        'pages'      => $pages,
        'count'      => count($pages),
        'template'   => $template,
        'source_env' => $source_env,
        'source_label' => $env_label
    ), 200);
}

/**
 * List available menus (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_list_menus($request) {
    // Receive-side auth: shared-secret header. Helper handles missing
    // config, missing/invalid header, and any future hardening.
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    // Template scope: when the caller passes a template, only that template's
    // menus are listed. No template → default to THIS site's active template
    // (the system is always template-scoped). `template=all` lists everything.
    $scope_template = $request->get_param('template');
    $scope_template = is_string($scope_template) ? trim($scope_template) : '';
    if ($scope_template === '' && function_exists('firefly_get_scoping_template')) {
        $scope_template = firefly_get_scoping_template();
    }

    // Get all nav menus
    $menus = wp_get_nav_menus();
    $menu_list = array();

    foreach ($menus as $menu) {
        $template = get_term_meta($menu->term_id, '_firefly_template', true);
        $template = $template ? $template : '';
        if ($scope_template !== '' && $scope_template !== 'all' && $template !== $scope_template) {
            continue;
        }
        $items = wp_get_nav_menu_items($menu->term_id);
        $menu_list[] = array(
            'id'          => $menu->term_id,
            'name'        => $menu->name,
            'slug'        => $menu->slug,
            'description' => $menu->description,
            'count'       => $menu->count,
            'items_count' => $items ? count($items) : 0,
            'template'    => $template
        );
    }

    return new WP_REST_Response(array(
        'success'  => true,
        'menus'    => $menu_list,
        'count'    => count($menu_list),
        'template' => $scope_template
    ), 200);
}

/**
 * Export menu for pull request (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_export_menu($request) {
    // Receive-side auth: shared-secret header. Helper handles missing
    // config, missing/invalid header, and any future hardening.
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $menu_id = $request->get_param('menu_id');

    // Load the menu sync handler for packaging
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/menu-sync.php';

    // Package the menu
    $package = firefly_projects_package_menu($menu_id);

    if (is_wp_error($package)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $package->get_error_message()
        ), 404);
    }

    return new WP_REST_Response(array(
        'success'   => true,
        'menu_data' => $package['menu_data'],
        'items'     => $package['items']
    ), 200);
}

/**
 * Fetch available menus from remote environment (local endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_fetch_remote_menus($request) {
    $source_env = $request->get_param('source_env');
    $template = $request->get_param('template');
    $template = is_string($template) ? trim($template) : '';
    if ($template === '' && function_exists('firefly_get_scoping_template')) {
        $template = firefly_get_scoping_template();
    }

    // Determine endpoint based on source environment
    if ($source_env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Production endpoint not configured.'
            ), 400);
        }
        $endpoint = PROD_ENDPOINT;
    } else {
        if (!defined('LIVE_DEV_ENDPOINT') || empty(LIVE_DEV_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Live Dev endpoint not configured.'
            ), 400);
        }
        $endpoint = LIVE_DEV_ENDPOINT;
    }

    // Build list-menus URL (template param scopes new remotes server-side)
    if (preg_match('/(https?:\/\/[^\/]+)/', $endpoint, $matches)) {
        $base_url = $matches[1];
        $list_url = $base_url . '/wp-json/firefly-plugin/v1/list-menus';
        if ($template !== '') {
            $list_url .= '?template=' . urlencode($template);
        }
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Could not determine remote URL.'
        ), 400);
    }

    // Fetch menus from remote
    $response = wp_remote_get($list_url, array(
        'headers' => array(
            'X-Firefly-Secret' => FIREFLY_SHARED_SECRET
        ),
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to connect to remote: ' . $response->get_error_message()
        ), 500);
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($http_code !== 200 || !isset($data['success']) || !$data['success']) {
        $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error from remote.';
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Remote error: ' . $error_msg
        ), $http_code);
    }

    $env_label = ($source_env === 'prod') ? 'Production' : 'Live Dev';

    // Belt-and-braces for OLD remote plugins that ignore the template param:
    // every menu row already carries its 'template' termmeta, so filter
    // locally. Menus with no template tag can't be attributed and are
    // dropped from a scoped listing.
    $menus = $data['menus'];
    if ($template !== '' && $template !== 'all' && !isset($data['template'])) {
        $menus = array_values(array_filter($menus, function ($m) use ($template) {
            return isset($m['template']) && $m['template'] === $template;
        }));
    }

    return new WP_REST_Response(array(
        'success'      => true,
        'menus'        => $menus,
        'count'        => count($menus),
        'template'     => $template,
        'source_env'   => $source_env,
        'source_label' => $env_label
    ), 200);
}

/**
 * Pull menu from remote environment (local endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_pull_menu($request) {
    $remote_menu_id = $request->get_param('remote_menu_id');
    $local_menu_id = (int) $request->get_param('local_menu_id');
    $source_env = $request->get_param('source_env');

    // local_menu_id is OPTIONAL: the local target is resolved (or created)
    // template-scoped from the pulled menu's template after we fetch it —
    // so pulling a menu into a fresh environment works without first
    // hand-creating an empty menu. When an explicit id IS given, verify it.
    $local_menu = $local_menu_id ? wp_get_nav_menu_object($local_menu_id) : null;
    if ($local_menu_id && !$local_menu) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Local menu not found.'
        ), 404);
    }

    // Determine endpoint based on source environment
    if ($source_env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Production endpoint not configured.'
            ), 400);
        }
        $endpoint = PROD_ENDPOINT;
    } else {
        if (!defined('LIVE_DEV_ENDPOINT') || empty(LIVE_DEV_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Live Dev endpoint not configured.'
            ), 400);
        }
        $endpoint = LIVE_DEV_ENDPOINT;
    }

    // Build export-menu URL
    if (preg_match('/(https?:\/\/[^\/]+)/', $endpoint, $matches)) {
        $base_url = $matches[1];
        $export_url = $base_url . '/wp-json/firefly-plugin/v1/export-menu?menu_id=' . $remote_menu_id;
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Could not determine remote URL.'
        ), 400);
    }

    // Fetch menu data from remote
    $response = wp_remote_get($export_url, array(
        'headers' => array(
            'X-Firefly-Secret' => FIREFLY_SHARED_SECRET
        ),
        'timeout' => 60
    ));

    if (is_wp_error($response)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to connect to remote: ' . $response->get_error_message()
        ), 500);
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($http_code !== 200 || !isset($data['success']) || !$data['success']) {
        $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error from remote.';
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Remote error: ' . $error_msg
        ), $http_code);
    }

    $menu_template = isset($data['menu_data']['template']) ? $data['menu_data']['template'] : '';
    $menu_name = isset($data['menu_data']['name']) ? $data['menu_data']['name'] : '';

    // Template guard: refuse to import a menu whose template isn't installed
    // here — same invariant as page pull.
    if ($menu_template && function_exists('firefly_is_valid_template') && !firefly_is_valid_template($menu_template)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => "Template '{$menu_template}' is not installed on this site. "
                       . "Initialize it first (firefly templates init {$menu_template}) before pulling its menu."
        ), 400);
    }

    // Resolve the local target menu template-scoped.
    if ($local_menu) {
        // Explicit target: refuse if it belongs to a DIFFERENT template than
        // the menu being pulled — that's exactly the cross-template collision
        // the scoping system exists to prevent.
        $local_template = get_term_meta($local_menu->term_id, '_firefly_template', true);
        if ($menu_template && $local_template && $local_template !== $menu_template) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => "Local menu '{$local_menu->name}' belongs to template '{$local_template}' "
                           . "but the pulled menu belongs to '{$menu_template}'. Pick the matching menu or pull without one."
            ), 400);
        }
    } elseif ($menu_template) {
        // No explicit target: find this template's menu (canonical option →
        // tagged name match → legacy name), or create a fresh scoped one.
        if (function_exists('firefly_find_template_menu_id')) {
            $found_id = firefly_find_template_menu_id($menu_template, $menu_name);
            if ($found_id) {
                $local_menu_id = $found_id;
                $local_menu = wp_get_nav_menu_object($local_menu_id);
            }
        }
        if (!$local_menu && function_exists('firefly_create_scoped_menu') && $menu_name !== '') {
            $created = firefly_create_scoped_menu($menu_name, $menu_template);
            if (!is_wp_error($created)) {
                $local_menu_id = (int) $created;
                $local_menu = wp_get_nav_menu_object($local_menu_id);
            }
        }
    }

    if (!$local_menu) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Could not resolve or create a local menu for the pull'
                       . ($menu_template ? " (template '{$menu_template}')" : '') . '.'
        ), 500);
    }

    // Load the menu sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/menu-sync.php';

    // Import the menu into the local menu (full sync - delete existing items first)
    $result = firefly_projects_import_pulled_menu($local_menu_id, $data);

    $env_label = ($source_env === 'prod') ? 'Production' : 'Live Dev';

    if ($result['success']) {
        // Heal the theme's canonical per-template menu pointer so the front
        // end picks up exactly this menu for the template.
        if ($menu_template) {
            update_option("firefly_menu_{$menu_template}", (int) $local_menu_id);
        }

        // Save pull timestamp
        $pull_time = time();
        $option_key = 'firefly_menu_pull_' . $source_env . '_' . $local_menu_id;
        update_option($option_key, $pull_time);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Menu pulled successfully from ' . $env_label . '.',
            'details' => array(
                'local_menu_id'   => $local_menu_id,
                'local_menu_name' => $local_menu->name,
                'remote_menu_name' => $data['menu_data']['name'],
                'template'        => $menu_template,
                'items_pulled'    => $result['items_count'],
                'source_env'      => $source_env,
                'pulled_at'       => $pull_time
            )
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Pull from ' . $env_label . ' failed: ' . $result['message']
        ), 500);
    }
}

/**
 * ============================================================================
 * BOOTSTRAP DEV ENVIRONMENT
 * ============================================================================
 */

/**
 * Verify shared secret for bootstrap endpoints. Used as a
 * permission_callback so it returns WP_Error/true rather than the
 * WP_REST_Response shape the body-style helper uses, but applies the
 * same security hardening (constant-time compare, deploy-window gate,
 * rotation grace period).
 *
 * @param WP_REST_Request $request
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function firefly_projects_verify_bootstrap_secret($request) {
    // Item 8: deploy window — closed window blocks bootstrap too.
    if (defined('FIREFLY_DEPLOY_OPEN_UNTIL') && !empty(FIREFLY_DEPLOY_OPEN_UNTIL)) {
        $open_until = strtotime((string) FIREFLY_DEPLOY_OPEN_UNTIL);
        if ($open_until === false || $open_until < time()) {
            return new WP_Error('firefly_deploy_window_closed', 'Deployment window closed.', array('status' => 403));
        }
    }

    $secret = (string) $request->get_header('X-Firefly-Secret');

    if ($secret === '') {
        return new WP_Error('missing_secret', 'Missing authentication header', array('status' => 401));
    }

    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_Error('not_configured', 'Shared secret not configured on this site', array('status' => 500));
    }

    // Item 1: constant-time compare. Item 5: previous secret accepted during rotation.
    if (hash_equals((string) FIREFLY_SHARED_SECRET, $secret)) {
        return true;
    }
    if (defined('FIREFLY_SHARED_SECRET_PREVIOUS')
        && !empty(FIREFLY_SHARED_SECRET_PREVIOUS)
        && hash_equals((string) FIREFLY_SHARED_SECRET_PREVIOUS, $secret)) {
        return true;
    }

    return new WP_Error('invalid_secret', 'Invalid authentication', array('status' => 403));
}

/**
 * Check if wp-dev directory exists (called by local on production)
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function firefly_projects_check_dev_exists($request) {
    // Verify shared secret
    $auth = firefly_projects_verify_bootstrap_secret($request);
    if (is_wp_error($auth)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $auth->get_error_message()
        ), $auth->get_error_data()['status']);
    }

    // Check for wp-dev directory inside ABSPATH (same level as wp-content)
    $wp_dev_path = ABSPATH . 'wp-dev';

    $exists = is_dir($wp_dev_path);

    return new WP_REST_Response(array(
        'success' => true,
        'exists' => $exists,
        'path' => $exists ? $wp_dev_path : null,
        'suggested_path' => $wp_dev_path,
        'abspath' => ABSPATH
    ), 200);
}

/**
 * Bootstrap dev environment - receive WP bundle and create wp-dev
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function firefly_projects_bootstrap_dev($request) {
    // Verify shared secret
    $auth = firefly_projects_verify_bootstrap_secret($request);
    if (is_wp_error($auth)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $auth->get_error_message()
        ), $auth->get_error_data()['status']);
    }

    // Get parameters
    $wp_config_content = $request->get_param('wp_config');
    $target_folder = $request->get_param('target_folder') ?: 'wp-dev';
    $wp_bundle_base64 = $request->get_param('wp_bundle');
    $plugin_bundle_base64 = $request->get_param('plugin_bundle');

    if (empty($wp_config_content)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'wp_config content is required'
        ), 400);
    }

    // Determine target path - put wp-dev inside the document root (same level as wp-content)
    $target_path = ABSPATH . sanitize_file_name($target_folder);

    // Check if already exists
    if (is_dir($target_path)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Target directory already exists: ' . $target_folder
        ), 400);
    }

    // Create target directory
    if (!wp_mkdir_p($target_path)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to create directory: ' . $target_path
        ), 500);
    }

    $extracted_files = 0;

    // Handle WP bundle if provided (base64 encoded)
    if (!empty($wp_bundle_base64)) {
        $wp_zip_content = base64_decode($wp_bundle_base64);
        $temp_zip_path = $target_path . '/temp-wp.zip';

        if (file_put_contents($temp_zip_path, $wp_zip_content)) {
            $zip = new ZipArchive();
            if ($zip->open($temp_zip_path) === true) {
                $zip->extractTo($target_path);
                $extracted_files = $zip->numFiles;
                $zip->close();
            }
            @unlink($temp_zip_path);
        }
    }

    // Write wp-config.php
    $wp_config_path = $target_path . '/wp-config.php';
    if (file_put_contents($wp_config_path, $wp_config_content) === false) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to write wp-config.php'
        ), 500);
    }

    // Create wp-content directories if they don't exist
    wp_mkdir_p($target_path . '/wp-content/plugins');
    wp_mkdir_p($target_path . '/wp-content/themes');
    wp_mkdir_p($target_path . '/wp-content/uploads');

    // Handle firefly-projects plugin bundle if provided
    if (!empty($plugin_bundle_base64)) {
        $plugin_zip_content = base64_decode($plugin_bundle_base64);
        $temp_plugin_path = $target_path . '/temp-plugin.zip';

        if (file_put_contents($temp_plugin_path, $plugin_zip_content)) {
            $plugin_zip = new ZipArchive();
            if ($plugin_zip->open($temp_plugin_path) === true) {
                $plugin_zip->extractTo($target_path . '/wp-content/plugins');
                $plugin_zip->close();
            }
            @unlink($temp_plugin_path);
        }
    }

    // Set proper permissions for web server access
    // Directories need 755 (rwxr-xr-x), files need 644 (rw-r--r--)
    firefly_projects_fix_permissions_recursive($target_path);

    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Dev environment created successfully',
        'path' => $target_path,
        'files_extracted' => $extracted_files
    ), 200);
}

/**
 * Generate WP bundle for bootstrap (called on local)
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function firefly_projects_generate_wp_bundle($request) {
    // Get parameters
    $db_name = sanitize_text_field($request->get_param('db_name'));
    $db_user = sanitize_text_field($request->get_param('db_user'));
    $db_password = $request->get_param('db_password'); // Don't sanitize password
    $db_host = sanitize_text_field($request->get_param('db_host')) ?: 'localhost';
    $table_prefix = sanitize_text_field($request->get_param('table_prefix')) ?: 'wp_';
    $dev_subdomain = sanitize_text_field($request->get_param('dev_subdomain'));

    if (empty($db_name) || empty($db_user) || empty($db_password)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Database name, user, and password are required'
        ), 400);
    }

    // Generate security salts
    $salts = firefly_projects_generate_salts();

    // Get shared secret from local config
    $shared_secret = defined('FIREFLY_SHARED_SECRET') ? FIREFLY_SHARED_SECRET : '';

    // Generate wp-config.php content
    $wp_config = firefly_projects_generate_wp_config(
        $db_name,
        $db_user,
        $db_password,
        $db_host,
        $table_prefix,
        $salts,
        $shared_secret,
        $dev_subdomain
    );

    // Create temp directory for ZIP
    $upload_dir = wp_upload_dir();
    $temp_dir = trailingslashit($upload_dir['basedir']) . 'firefly_collective_temp';
    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
    }

    // Create WordPress core ZIP
    $wp_zip_path = $temp_dir . '/wp-core-' . time() . '.zip';
    $wp_zip = new ZipArchive();

    if ($wp_zip->open($wp_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to create ZIP file'
        ), 500);
    }

    // Add WordPress core files
    $wp_core_files = array(
        'wp-admin',
        'wp-includes',
        'index.php',
        'wp-activate.php',
        'wp-blog-header.php',
        'wp-comments-post.php',
        'wp-cron.php',
        'wp-links-opml.php',
        'wp-load.php',
        'wp-login.php',
        'wp-mail.php',
        'wp-settings.php',
        'wp-signup.php',
        'wp-trackback.php',
        'xmlrpc.php'
    );

    foreach ($wp_core_files as $file) {
        $path = ABSPATH . $file;
        if (is_dir($path)) {
            firefly_projects_add_dir_to_zip($wp_zip, $path, $file);
        } elseif (is_file($path)) {
            $wp_zip->addFile($path, $file);
        }
    }

    // Add wp-content structure (empty directories)
    $wp_zip->addEmptyDir('wp-content');
    $wp_zip->addEmptyDir('wp-content/plugins');
    $wp_zip->addEmptyDir('wp-content/themes');
    $wp_zip->addEmptyDir('wp-content/uploads');

    $wp_zip->close();

    // Create firefly-projects plugin ZIP
    $plugin_zip_path = $temp_dir . '/firefly-projects-' . time() . '.zip';
    $plugin_zip = new ZipArchive();

    if ($plugin_zip->open($plugin_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $plugin_dir = FIREFLY_PROJECTS_PLUGIN_DIR;
        firefly_projects_add_dir_to_zip($plugin_zip, $plugin_dir, 'firefly-projects');
        $plugin_zip->close();
    }

    // Read ZIPs as base64 for transport
    $wp_bundle_base64 = base64_encode(file_get_contents($wp_zip_path));
    $plugin_bundle_base64 = base64_encode(file_get_contents($plugin_zip_path));

    // Clean up temp files
    @unlink($wp_zip_path);
    @unlink($plugin_zip_path);

    return new WP_REST_Response(array(
        'success' => true,
        'wp_config' => $wp_config,
        'wp_bundle' => $wp_bundle_base64,
        'plugin_bundle' => $plugin_bundle_base64,
        'wp_bundle_size' => strlen($wp_bundle_base64),
        'plugin_bundle_size' => strlen($plugin_bundle_base64)
    ), 200);
}

/**
 * Generate WordPress salts
 *
 * @return array
 */
function firefly_projects_generate_salts() {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}|;:,.<>?';
    $salts = array();
    $keys = array('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT');

    foreach ($keys as $key) {
        $salt = '';
        for ($i = 0; $i < 64; $i++) {
            $salt .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $salts[$key] = $salt;
    }

    return $salts;
}

/**
 * Generate wp-config.php content
 *
 * @param string $db_name
 * @param string $db_user
 * @param string $db_password
 * @param string $db_host
 * @param string $table_prefix
 * @param array $salts
 * @param string $shared_secret
 * @param string $dev_subdomain
 * @return string
 */
function firefly_projects_generate_wp_config($db_name, $db_user, $db_password, $db_host, $table_prefix, $salts, $shared_secret, $dev_subdomain = '') {
    $config = "<?php
/**
 * WordPress Configuration for Dev Environment
 * Generated by Firefly Projects Bootstrap
 */

// ** MySQL settings ** //
define('DB_NAME', '{$db_name}');
define('DB_USER', '{$db_user}');
define('DB_PASSWORD', '{$db_password}');
define('DB_HOST', '{$db_host}');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// ** Authentication Unique Keys and Salts ** //
define('AUTH_KEY',         '{$salts['AUTH_KEY']}');
define('SECURE_AUTH_KEY',  '{$salts['SECURE_AUTH_KEY']}');
define('LOGGED_IN_KEY',    '{$salts['LOGGED_IN_KEY']}');
define('NONCE_KEY',        '{$salts['NONCE_KEY']}');
define('AUTH_SALT',        '{$salts['AUTH_SALT']}');
define('SECURE_AUTH_SALT', '{$salts['SECURE_AUTH_SALT']}');
define('LOGGED_IN_SALT',   '{$salts['LOGGED_IN_SALT']}');
define('NONCE_SALT',       '{$salts['NONCE_SALT']}');

// ** Table Prefix ** //
\$table_prefix = '{$table_prefix}';

// ** Firefly Projects Configuration ** //
define('FIREFLY_LIVE_DEV', true);
define('FIREFLY_SHARED_SECRET', '{$shared_secret}');

// ** Debugging ** //
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

";

    // Add site URL if subdomain provided
    if (!empty($dev_subdomain)) {
        $config .= "// ** Site URLs ** //
define('WP_HOME', 'https://{$dev_subdomain}');
define('WP_SITEURL', 'https://{$dev_subdomain}');

// ** Cookie/Auth Domain — must match the subdomain so auth cookies stick ** //
define('DOMAIN', '{$dev_subdomain}');
define('COOKIE_DOMAIN', '{$dev_subdomain}');

";
    }

    $config .= "// ** Absolute path to the WordPress directory ** //
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// ** Explicitly set wp-content directory to prevent using parent site's wp-content ** //
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('WP_CONTENT_URL', WP_HOME . '/wp-content');

// ** Sets up WordPress vars and included files ** //
require_once ABSPATH . 'wp-settings.php';
";

    return $config;
}

/**
 * Recursively add directory to ZIP
 *
 * @param ZipArchive $zip
 * @param string $dir
 * @param string $base
 */
function firefly_projects_add_dir_to_zip($zip, $dir, $base) {
    // Normalize directory path - remove trailing slash for consistent substr calculation
    $dir = rtrim($dir, '/\\');

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if (!$file->isDir()) {
            $file_path = $file->getRealPath();
            // +1 to skip the directory separator after the base dir
            $relative_path = $base . '/' . substr($file_path, strlen($dir) + 1);
            $zip->addFile($file_path, $relative_path);
        }
    }
}

/**
 * Recursively fix permissions for web server access
 * Sets directories to 755 and files to 644
 *
 * @param string $path The path to fix permissions for
 */
function firefly_projects_fix_permissions_recursive($path) {
    // Fix the root directory first
    @chmod($path, 0755);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @chmod($item->getRealPath(), 0755);
        } else {
            @chmod($item->getRealPath(), 0644);
        }
    }
}

/**
 * ============================================================================
 * TEMPLATE SCHEMA SYNC ENDPOINTS
 * ============================================================================
 */

/**
 * Sync template schema to remote site
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_sync_template_schema($request) {
    $template = $request->get_param('template');
    $target_env = $request->get_param('target_env');

    // Load the template schema sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/template-schema-sync.php';

    // Check if template schema system is available
    if (!firefly_projects_is_template_schema_available()) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Template schema system not available. Firefly theme may not be active.'
        ), 400);
    }

    // Check configuration based on target environment
    if ($target_env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Production endpoint not configured. Please set PROD_ENDPOINT in wp-config.php.'
            ), 400);
        }
    } else {
        if (!firefly_projects_is_configured()) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Plugin not configured. Please set FIREFLY_SHARED_SECRET and LIVE_DEV_ENDPOINT in wp-config.php.'
            ), 400);
        }
    }

    // Perform the sync
    $result = firefly_projects_perform_template_schema_sync($template, $target_env);

    if ($result['success']) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $result['message'],
            'details' => isset($result['details']) ? $result['details'] : null
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $result['message']
        ), 500);
    }
}

/**
 * Receive template schema from local site (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_receive_template_schema($request) {
    // Receive-side auth: shared-secret header. Helper handles missing
    // config, missing/invalid header, and any future hardening.
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    // Load the template schema sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/template-schema-sync.php';

    // Process the incoming schema
    $result = firefly_projects_handle_incoming_template_schema($request);

    if ($result['success']) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $result['message'],
            'details' => isset($result['details']) ? $result['details'] : null
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $result['message']
        ), 500);
    }
}

/**
 * List available template schemas (endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_list_schemas_endpoint($request) {
    // Dual-mode endpoint: header present = remote call gated by shared
    // secret; no header = local admin call gated by capability check.
    if ($request->get_header('X-Firefly-Secret')) {
        $auth_failure = firefly_projects_verify_shared_secret($request);
        if ($auth_failure !== null) return $auth_failure;
    } else {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Unauthorized.'
            ), 403);
        }
    }

    // Load the template schema sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/template-schema-sync.php';

    $result = firefly_projects_list_template_schemas();

    return new WP_REST_Response($result, 200);
}
