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
}
add_action('rest_api_init', 'firefly_plugin_register_rest_endpoints');
