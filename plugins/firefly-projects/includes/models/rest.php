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

    // Projects: Add -dev suffix to plugins/themes
    register_rest_route(
        'firefly-plugin/v1',
        '/add-dev-suffix',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_add_dev_suffix',
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
            'permission_callback' => '__return_true' // Uses shared secret authentication
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
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'description'       => 'The local menu term ID to sync into'
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
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                    'description'       => 'The page slug to export'
                )
            )
        )
    );

    // Asset Processing: Process page assets for dev environment (local site only)
    register_rest_route(
        'firefly-plugin/v1',
        '/process-page-assets',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_projects_process_page_assets',
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
                )
            )
        )
    );
}
add_action('rest_api_init', 'firefly_plugin_register_rest_endpoints');

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
    $result = firefly_projects_perform_page_sync($post, $include_assets, $target_env);

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
    // Verify shared secret
    $provided_secret = $request->get_header('X-Firefly-Secret');
    
    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Shared secret not configured on remote.'
        ), 500);
    }

    if ($provided_secret !== FIREFLY_SHARED_SECRET) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid shared secret.'
        ), 403);
    }

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
    // Verify shared secret
    $provided_secret = $request->get_header('X-Firefly-Secret');

    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Shared secret not configured on remote.'
        ), 500);
    }

    if ($provided_secret !== FIREFLY_SHARED_SECRET) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid shared secret.'
        ), 403);
    }

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

    // Perform the bulk sync
    $result = firefly_projects_sync_all_pages_handler($sync_mode, $target_env);

    $env_label = ($target_env === 'prod') ? 'Production' : 'Live Dev';

    return new WP_REST_Response(array(
        'success' => true,
        'message' => sprintf(
            'Synced %d of %d pages to %s.',
            $result['synced'],
            $result['total'],
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

    $orphan_count = firefly_projects_get_orphan_count($target_env);

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
    // Verify shared secret
    $provided_secret = $request->get_header('X-Firefly-Secret');

    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Shared secret not configured on remote.'
        ), 500);
    }

    if ($provided_secret !== FIREFLY_SHARED_SECRET) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid shared secret.'
        ), 403);
    }

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
    // Verify shared secret
    $provided_secret = $request->get_header('X-Firefly-Secret');

    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Shared secret not configured on remote.'
        ), 500);
    }

    if ($provided_secret !== FIREFLY_SHARED_SECRET) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid shared secret.'
        ), 403);
    }

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

    // Build export URL
    if (preg_match('/(https?:\/\/[^\/]+)/', $endpoint, $matches)) {
        $base_url = $matches[1];
        $export_url = $base_url . '/wp-json/firefly-plugin/v1/export-page?post_slug=' . urlencode($post_slug);
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
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $result['message'],
            'post_id' => $result['post_id'],
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
 * Export page for pull request (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_export_page($request) {
    // Verify shared secret
    $provided_secret = $request->get_header('X-Firefly-Secret');

    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Shared secret not configured on remote.'
        ), 500);
    }

    if ($provided_secret !== FIREFLY_SHARED_SECRET) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid shared secret.'
        ), 403);
    }

    $post_slug = $request->get_param('post_slug');

    // Find the page by slug
    $post = get_page_by_path($post_slug, OBJECT, array('page', 'post'));

    if (!$post) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Page not found: ' . $post_slug
        ), 404);
    }

    // Load asset mapping functions
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/asset-mapping.php';

    // Detect assets in content
    $assets = firefly_projects_detect_all_assets($post->post_content);

    // Package asset data with base64 content
    $asset_data = array();
    foreach ($assets as $asset_url) {
        $local_path = firefly_projects_resolve_asset_source($asset_url);

        if ($local_path && file_exists($local_path)) {
            $asset_data[] = array(
                'url'      => $asset_url,
                'filename' => basename($local_path),
                'content'  => base64_encode(file_get_contents($local_path)),
                'size'     => filesize($local_path)
            );
        }
    }

    // Get post meta
    $meta = get_post_meta($post->ID);
    $meta_data = array();
    foreach ($meta as $key => $values) {
        // Skip internal meta and our mapping meta
        if (strpos($key, '_') === 0 && $key !== '_thumbnail_id') {
            continue;
        }
        $meta_data[$key] = $values[0];
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
        ),
        'meta_data'      => $meta_data,
        'assets'         => $asset_data,
        'asset_map'      => $asset_map,
        'featured_image' => $featured_image
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

    $page_slug = $post_data['post_name'];

    // Find or create local post
    $existing_post = get_page_by_path($page_slug, OBJECT, $post_data['post_type']);

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

    // Process assets - save to uploads-dev and create mappings
    $mappings = array();
    $assets_saved = 0;

    // Create dev directory
    $dev_dir = firefly_projects_get_dev_asset_filesystem_path($page_slug);
    if (!file_exists($dev_dir)) {
        wp_mkdir_p($dev_dir);
    }

    foreach ($assets as $asset) {
        $original_url = $asset['url'];
        $filename = sanitize_file_name($asset['filename']);
        $content = base64_decode($asset['content']);

        $dev_path = $dev_dir . '/' . $filename;
        $dev_url = '/wp-content/uploads-dev/pages/' . $page_slug . '/' . $filename;

        // Save file
        if (file_put_contents($dev_path, $content)) {
            $mappings[$original_url] = $dev_url;
            $assets_saved++;
        }
    }

    // Determine asset origin based on source environment
    $asset_origin = ($source_env === 'prod') ? 'production' : 'dev';

    // If pulling from production, URLs need rewriting to dev paths
    // If pulling from dev, URLs may already be dev paths (check remote_asset_map)
    if ($source_env === 'prod') {
        // Rewrite content URLs to dev paths
        $new_content = firefly_projects_rewrite_content_urls($post_data['post_content'], $mappings, 'to_dev');

        // Update post with rewritten content
        wp_update_post(array(
            'ID'           => $post_id,
            'post_content' => $new_content
        ));

        // Save asset map
        firefly_projects_save_asset_map($post_id, array(
            'asset_origin' => $asset_origin,
            'mappings'     => $mappings,
            'dev_created'  => array()
        ));
    } else {
        // From dev - content may already have dev URLs
        // Import the remote asset map if it exists
        if (!empty($remote_asset_map) && !empty($remote_asset_map['mappings'])) {
            firefly_projects_save_asset_map($post_id, $remote_asset_map);
        } else {
            // No existing map - these are dev-created assets
            $dev_created = array_values($mappings);
            firefly_projects_save_asset_map($post_id, array(
                'asset_origin' => 'dev',
                'mappings'     => array(),
                'dev_created'  => $dev_created
            ));
        }
    }

    // Process featured image if included
    $featured_image_set = false;
    if (!empty($data['featured_image'])) {
        $featured = $data['featured_image'];
        $filename = sanitize_file_name($featured['filename']);

        // Save to uploads-dev directory
        $featured_path = $dev_dir . '/' . $filename;
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
            'is_update'          => $existing_post ? true : false
        )
    );
}

/**
 * Process page assets for dev environment (create mappings, copy assets)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_process_page_assets($request) {
    $post_id = $request->get_param('post_id');

    // Load asset mapping functions
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/asset-mapping.php';

    // Process the page
    $result = firefly_projects_process_page_for_dev($post_id, 'production');

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

    // Build list-pages URL
    if (preg_match('/(https?:\/\/[^\/]+)/', $endpoint, $matches)) {
        $base_url = $matches[1];
        $list_url = $base_url . '/wp-json/firefly-plugin/v1/list-pages?include_drafts=true&post_type=' . urlencode($post_type);
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

    return new WP_REST_Response(array(
        'success'    => true,
        'pages'      => $data['pages'],
        'count'      => $data['count'],
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
    // Verify shared secret
    $provided_secret = $request->get_header('X-Firefly-Secret');

    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Shared secret not configured on remote.'
        ), 500);
    }

    if ($provided_secret !== FIREFLY_SHARED_SECRET) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid shared secret.'
        ), 403);
    }

    // Get all nav menus
    $menus = wp_get_nav_menus();
    $menu_list = array();

    foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        $menu_list[] = array(
            'id'          => $menu->term_id,
            'name'        => $menu->name,
            'slug'        => $menu->slug,
            'description' => $menu->description,
            'count'       => $menu->count,
            'items_count' => $items ? count($items) : 0
        );
    }

    return new WP_REST_Response(array(
        'success' => true,
        'menus'   => $menu_list,
        'count'   => count($menu_list)
    ), 200);
}

/**
 * Export menu for pull request (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request object
 * @return WP_REST_Response
 */
function firefly_projects_export_menu($request) {
    // Verify shared secret
    $provided_secret = $request->get_header('X-Firefly-Secret');

    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Shared secret not configured on remote.'
        ), 500);
    }

    if ($provided_secret !== FIREFLY_SHARED_SECRET) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid shared secret.'
        ), 403);
    }

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

    // Build list-menus URL
    if (preg_match('/(https?:\/\/[^\/]+)/', $endpoint, $matches)) {
        $base_url = $matches[1];
        $list_url = $base_url . '/wp-json/firefly-plugin/v1/list-menus';
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

    return new WP_REST_Response(array(
        'success'      => true,
        'menus'        => $data['menus'],
        'count'        => $data['count'],
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
    $local_menu_id = $request->get_param('local_menu_id');
    $source_env = $request->get_param('source_env');

    // Verify local menu exists
    $local_menu = wp_get_nav_menu_object($local_menu_id);
    if (!$local_menu) {
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

    // Load the menu sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/menu-sync.php';

    // Import the menu into the local menu (full sync - delete existing items first)
    $result = firefly_projects_import_pulled_menu($local_menu_id, $data);

    $env_label = ($source_env === 'prod') ? 'Production' : 'Live Dev';

    if ($result['success']) {
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
