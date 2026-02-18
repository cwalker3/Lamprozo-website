<?php
/**
 * Debug Logging Utilities
 * Provides REST endpoints and helper functions for accessing debug logs
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register debug REST routes
 */
function firefly_debug_register_rest_routes() {
    // Endpoint to retrieve debug.log file contents
    register_rest_route('firefly-collective/v1', '/debug-log', array(
        'methods' => 'GET',
        'callback' => 'firefly_get_debug_log',
        'permission_callback' => '__return_true'
    ));

    // Endpoint to clear debug.log file
    register_rest_route('firefly-collective/v1', '/debug-log/clear', array(
        'methods' => 'POST',
        'callback' => 'firefly_clear_debug_log',
        'permission_callback' => '__return_true'
    ));
}
add_action('rest_api_init', 'firefly_debug_register_rest_routes');

/**
 * Get debug.log file contents
 * Supports optional 'lines' parameter to return only last N lines (tail functionality)
 */
function firefly_get_debug_log($request) {
    $lines = $request->get_param('lines');
    $lines = $lines ? absint($lines) : 0; // 0 means return all lines

    // Get the debug.log file path
    $log_file = WP_CONTENT_DIR . '/debug.log';

    // Check if file exists
    if (!file_exists($log_file)) {
        return new WP_REST_Response(array(
            'error' => 'Debug log file not found',
            'path' => $log_file
        ), 404);
    }

    // Check if file is readable
    if (!is_readable($log_file)) {
        return new WP_REST_Response(array(
            'error' => 'Debug log file is not readable',
            'path' => $log_file
        ), 403);
    }

    // Read the file
    if ($lines > 0) {
        // Return only last N lines (tail functionality)
        $file_lines = file($log_file, FILE_IGNORE_NEW_LINES);
        if ($file_lines === false) {
            return new WP_REST_Response(array(
                'error' => 'Failed to read debug log file'
            ), 500);
        }

        $total_lines = count($file_lines);
        $start = max(0, $total_lines - $lines);
        $content = implode("\n", array_slice($file_lines, $start));
    } else {
        // Return entire file
        $content = file_get_contents($log_file);
        if ($content === false) {
            return new WP_REST_Response(array(
                'error' => 'Failed to read debug log file'
            ), 500);
        }
    }

    // Return as plain text
    $response = new WP_REST_Response($content, 200);
    $response->header('Content-Type', 'text/plain; charset=utf-8');

    return $response;
}

/**
 * Clear debug.log file contents
 */
function firefly_clear_debug_log($request) {
    // Get the debug.log file path
    $log_file = WP_CONTENT_DIR . '/debug.log';

    // Check if file exists
    if (!file_exists($log_file)) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Debug log file does not exist (already clear)'
        ), 200);
    }

    // Check if file is writable
    if (!is_writable($log_file)) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Debug log file is not writable',
            'path' => $log_file
        ), 403);
    }

    // Clear the file by opening in write mode and immediately closing
    $result = file_put_contents($log_file, '');

    if ($result === false) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Failed to clear debug log file'
        ), 500);
    }

    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Debug log file cleared successfully'
    ), 200);
}

/**
 * Helper function to fetch remote debug.log file and log it locally
 * Usage: fetch_remote_debug_log(); // defaults to Live Dev
 * Usage: fetch_remote_debug_log(LIVE_DEV_URL, 100); // last 100 lines from Live Dev
 * Usage: fetch_remote_debug_log(PROD_URL); // from Production
 *
 * @param string $source Source URL (defaults to LIVE_DEV_URL constant)
 * @param int $lines Optional number of lines to fetch (0 = all lines)
 */
function fetch_remote_debug_log($source = null, $lines = 0) {
    // Only work on local
    if (!defined('FIREFLY_DEV') || !FIREFLY_DEV) {
        error_log('[Fetch Debug Log] Not on local environment, skipping');
        return;
    }

    // Default to LIVE_DEV_URL if not specified
    if ($source === null) {
        $source = defined('LIVE_DEV_URL') ? LIVE_DEV_URL : 'https://dev.fireflycreative.io';
    }

    // Build debug log URL
    $debug_log_url = trailingslashit($source) . 'wp-json/firefly-collective/v1/debug-log';

    if ($lines > 0) {
        $debug_log_url = add_query_arg('lines', $lines, $debug_log_url);
    }

    error_log('[Fetch Debug Log] Fetching from: ' . $debug_log_url);

    // Fetch debug log from remote
    $response = wp_remote_get($debug_log_url, array(
        'timeout' => 15,
        'sslverify' => false
    ));

    if (is_wp_error($response)) {
        error_log('[Fetch Debug Log] ERROR: ' . $response->get_error_message());
        return;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if ($response_code !== 200) {
        error_log('[Fetch Debug Log] ERROR: Response code ' . $response_code);
        error_log('[Fetch Debug Log] Response: ' . substr($response_body, 0, 500));
        return;
    }

    // Log the debug.log contents to local debug.log
    $source_name = parse_url($source, PHP_URL_HOST);
    error_log('[Fetch Debug Log] ========== DEBUG.LOG FROM ' . strtoupper($source_name) . ' ==========');
    error_log($response_body);
    error_log('[Fetch Debug Log] ========== END DEBUG.LOG FROM ' . strtoupper($source_name) . ' ==========');
}
