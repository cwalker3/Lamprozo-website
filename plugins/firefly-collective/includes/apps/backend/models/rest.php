<?php

/**
 * Plugin REST API Endpoints
 *
 * Registers all plugin-level REST endpoints under the firefly-plugin namespace.
 * This is separate from template REST endpoints which use custom-api namespace.
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
    error_log('[Firefly Projects Debug] firefly_plugin_verify_rest_admin - Permission callback started');

    // Verify nonce for security
    $nonce = $request->get_header('X-WP-Nonce');
    error_log('[Firefly Projects Debug] firefly_plugin_verify_rest_admin - Nonce header: ' . substr($nonce, 0, 10) . '...');

    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        error_log('[Firefly Projects Debug] firefly_plugin_verify_rest_admin - Nonce verification FAILED');
        return false;
    }

    error_log('[Firefly Projects Debug] firefly_plugin_verify_rest_admin - Nonce verification PASSED');

    // Check admin capability
    $has_capability = current_user_can('manage_options');
    error_log('[Firefly Projects Debug] firefly_plugin_verify_rest_admin - User has manage_options: ' . ($has_capability ? 'YES' : 'NO'));

    if (!$has_capability) {
        error_log('[Firefly Projects Debug] firefly_plugin_verify_rest_admin - User lacks manage_options capability');
        return false;
    }

    error_log('[Firefly Projects Debug] firefly_plugin_verify_rest_admin - All checks PASSED, returning true');
    return true;
}

/**
 * Register all plugin REST API endpoints
 */
function firefly_plugin_register_rest_endpoints() {
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Starting REST endpoint registration');

    // Projects: Get file tree for file selection UI
    $result1 = register_rest_route(
        'firefly-plugin/v1',
        '/get-project-files',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_get_project_files',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Registered /get-project-files: ' . ($result1 ? 'SUCCESS' : 'FAILED'));

    // Projects: Update/sync project to live dev environment
    $result2 = register_rest_route(
        'firefly-plugin/v1',
        '/update-project',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_local_update_project',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Registered /update-project: ' . ($result2 ? 'SUCCESS' : 'FAILED'));

    $api_url = rest_url('firefly-plugin/v1/');
    // Projects: Get backup history
    $result3 = register_rest_route(
        'firefly-plugin/v1',
        '/get-backup-history',
        array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_get_backup_history',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Registered /get-backup-history: ' . ($result3 ? 'SUCCESS' : 'FAILED'));

    // Projects: Restore from backup
    $result4 = register_rest_route(
        'firefly-plugin/v1',
        '/restore-backup',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_restore_backup',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Registered /restore-backup: ' . ($result4 ? 'SUCCESS' : 'FAILED'));

    // Projects: Delete backup
    $result5 = register_rest_route(
        'firefly-plugin/v1',
        '/delete-backup',
        array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_delete_backup',
            'permission_callback' => 'firefly_plugin_verify_rest_admin'
        )
    );
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Registered /delete-backup: ' . ($result5 ? 'SUCCESS' : 'FAILED'));

    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Complete REST URL: ' . $api_url);
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Full get-project-files URL: ' . $api_url . 'get-project-files');
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Full update-project URL: ' . $api_url . 'update-project');
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Full get-backup-history URL: ' . $api_url . 'get-backup-history');
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Full restore-backup URL: ' . $api_url . 'restore-backup');
    error_log('[Firefly Projects Debug] firefly_plugin_register_rest_endpoints - Full delete-backup URL: ' . $api_url . 'delete-backup');
}
add_action('rest_api_init', 'firefly_plugin_register_rest_endpoints');
