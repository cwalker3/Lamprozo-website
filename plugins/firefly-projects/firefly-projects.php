<?php
/**
 * Plugin Name: Firefly Projects
 * Plugin URI: https://fireflycollective.org
 * Description: Professional file synchronization and backup system for WordPress. Sync files to remote environments with selective file control, automatic backups, and restore functionality.
 * Version: 1.0.0
 * Author: Alex Strait
 * Author URI: https://fireflycreative.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: firefly-projects
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Ensure no direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('FIREFLY_PROJECTS_VERSION', '1.0.0');
define('FIREFLY_PROJECTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FIREFLY_PROJECTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FIREFLY_PROJECTS_PLUGIN_FILE', __FILE__);
define('LIVE_DEV_ENDPOINT', 'https://test1.fireflycollective.org/wp-json/firefly-plugin/v1/update_project');

// Load configuration constants
require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/constants.php';

// Load core functions
require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/functions.php';

// Load models
require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/rest.php';
require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/projects.php';

/**
 * Activation hook - Create necessary directories
 */
function firefly_projects_activate() {
    // Create necessary directories
    $upload_dir = wp_upload_dir();
    $backups_dir = trailingslashit($upload_dir['basedir']) . 'firefly_backups';
    $temp_dir = trailingslashit($upload_dir['basedir']) . 'firefly_collective_temp';

    if (!file_exists($backups_dir)) {
        wp_mkdir_p($backups_dir);
    }

    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
    }

    // Flush rewrite rules for REST API
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'firefly_projects_activate');

/**
 * Deactivation hook - Clean up
 */
function firefly_projects_deactivate() {
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'firefly_projects_deactivate');

/**
 * Display admin notice based on environment type
 */
function firefly_projects_admin_notices() {
    // Only show to admins
    if (!current_user_can('manage_options')) {
        return;
    }

    $screen = get_current_screen();

    // Live Dev environment notice
    if (firefly_projects_is_live_dev()) {
        if ($screen && in_array($screen->id, array('plugins', 'dashboard'))) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Firefly Projects:</strong> Running in <strong>LIVE DEV</strong> mode (headless). This environment receives syncs from Local Dev.</p>
                <p><em>Note: Only -dev plugins are shown. Production plugins are hidden to prevent confusion.</em></p>
            </div>
            <?php
        }
        return;
    }

    // Local Dev environment
    if (firefly_projects_is_local_dev()) {
        // If we're on the Projects page, check for configuration issues
        if ($screen && $screen->id === 'toplevel_page_firefly-projects') {
            if (!firefly_projects_is_configured()) {
                ?>
                <div class="notice notice-warning">
                    <p><strong>Firefly Projects:</strong> Configuration incomplete. Please add the following constants to your wp-config.php:</p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <?php if (empty(FIREFLY_SHARED_SECRET)): ?>
                        <li><code>define('FIREFLY_SHARED_SECRET', 'your-secret-key-here');</code></li>
                        <?php endif; ?>
                        <?php if (empty(LIVE_DEV_ENDPOINT)): ?>
                        <li><code>define('LIVE_DEV_ENDPOINT', 'https://dev.yoursite.com/wp-json/firefly-plugin/v1/update_project');</code></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php
            }
        }
        return;
    }

    // Production environment (no constants set)
    if ($screen && in_array($screen->id, array('plugins', 'dashboard'))) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong>Firefly Projects:</strong> Plugin is inactive (production mode). No sync functionality available.</p>
            <p><em>To use this plugin:</em></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><strong>Local Dev:</strong> Add <code>define('FIREFLY_DEV', true);</code> to wp-config.php</li>
                <li><strong>Live Dev:</strong> Add <code>define('FIREFLY_LIVE_DEV', true);</code> to wp-config.php</li>
            </ul>
        </div>
        <?php
    }
}
add_action('admin_notices', 'firefly_projects_admin_notices');

/**
 * Filter plugins list based on environment
 * Live Dev: Only show -dev plugins (hide production versions after initial migration)
 * Production: Only show non-dev plugins (hide -dev to prevent confusion)
 * Local Dev: Show all plugins
 */
function firefly_projects_filter_plugins($plugins) {
    // Local Dev: Show all plugins
    if (firefly_projects_is_local_dev()) {
        return $plugins;
    }

    $filtered = array();

    foreach ($plugins as $plugin_file => $plugin_data) {
        $plugin_folder = dirname($plugin_file);

        // Extract just the plugin folder name (first part of path)
        if (strpos($plugin_folder, '/') !== false) {
            $plugin_folder = substr($plugin_folder, 0, strpos($plugin_folder, '/'));
        }

        $is_dev_plugin = (substr($plugin_folder, -4) === '-dev');

        // Skip firefly-projects itself from filtering
        if ($plugin_folder === 'firefly-projects') {
            $filtered[$plugin_file] = $plugin_data;
            continue;
        }

        // Live Dev: Only show -dev plugins
        if (firefly_projects_is_live_dev()) {
            if ($is_dev_plugin) {
                $filtered[$plugin_file] = $plugin_data;
            }
        }
        // Production: Only show non-dev plugins
        else if (firefly_projects_is_production()) {
            if (!$is_dev_plugin) {
                $filtered[$plugin_file] = $plugin_data;
            }
        }
    }

    return $filtered;
}
add_filter('all_plugins', 'firefly_projects_filter_plugins');

/**
 * Add (DEVELOPMENT) label to plugin names with -dev suffix
 * Shows on both Local Dev and Live Dev environments
 */
function firefly_projects_add_dev_label($plugin_meta, $plugin_file, $plugin_data) {
    // Only on Local Dev and Live Dev, not Production
    if (!firefly_projects_is_local_dev() && !firefly_projects_is_live_dev()) {
        return $plugin_meta;
    }

    $plugin_folder = dirname($plugin_file);

    // Extract just the plugin folder name
    if (strpos($plugin_folder, '/') !== false) {
        $plugin_folder = substr($plugin_folder, 0, strpos($plugin_folder, '/'));
    }

    $is_dev_plugin = (substr($plugin_folder, -4) === '-dev');

    if ($is_dev_plugin) {
        // Add development label to the beginning of plugin meta
        array_unshift($plugin_meta, '<strong style="color: #d63638;">DEVELOPMENT VERSION</strong>');
    }

    return $plugin_meta;
}
add_filter('plugin_row_meta', 'firefly_projects_add_dev_label', 10, 3);

/**
 * Hide plugin admin notices on Local Dev and Live Dev environments
 * These notices are not relevant for development environments
 */
function firefly_projects_hide_dev_notices() {
    // Only hide on Local Dev and Live Dev, not Production
    if (!firefly_projects_is_local_dev() && !firefly_projects_is_live_dev()) {
        return;
    }

    ?>
    <style type="text/css">
        /* Hide MonsterInsights setup notices */
        .notice.monsterinsights-notice,
        .notice:has(.monsterinsights-setup-wizard-link),
        .notice:has(.monsterinsights-disclaimer-note) {
            display: none !important;
        }

        /* Hide Action Scheduler warnings */
        .notice:has(a[href*="action-scheduler"]) {
            display: none !important;
        }

        /* Hide Otter Blocks data tracking notices */
        .notice.themeisle-sdk-notice,
        #otter_blocks_dev_logger_flag-notification {
            display: none !important;
        }

        /* Hide other common plugin setup/upsell notices (but not plugin update rows) */
        .notice:has(a[class*="setup-wizard"]),
        .notice:has(a[href*="upgrade"]):not(.update-message),
        .notice:has(a[href*="pro-version"]) {
            display: none !important;
        }
    </style>
    <?php
}
add_action('admin_head', 'firefly_projects_hide_dev_notices');

/**
 * Map non-dev plugin updates to their -dev equivalents
 * This is the core mapping function used by both filters
 */
function firefly_projects_map_dev_updates($transient) {
    if (!is_object($transient)) {
        return $transient;
    }

    // Initialize response array if not set
    if (!isset($transient->response)) {
        $transient->response = array();
    }

    // Production: Just filter out -dev updates
    if (firefly_projects_is_production()) {
        foreach ($transient->response as $plugin_file => $update_data) {
            $plugin_folder = dirname($plugin_file);
            if (substr($plugin_folder, -4) === '-dev') {
                unset($transient->response[$plugin_file]);
            }
        }
        return $transient;
    }

    // Local Dev & Live Dev: Map non-dev updates to -dev paths
    if (!firefly_projects_is_local_dev() && !firefly_projects_is_live_dev()) {
        return $transient;
    }

    // Get all installed plugins (unfiltered)
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Temporarily remove our filter to get unfiltered plugin list
    remove_filter('all_plugins', 'firefly_projects_filter_plugins');
    $all_plugins = get_plugins();
    add_filter('all_plugins', 'firefly_projects_filter_plugins');

    $updates_to_add = array();

    foreach ($transient->response as $plugin_file => $update_data) {
        $plugin_folder = dirname($plugin_file);
        $plugin_basename = basename($plugin_file);

        // Skip if already a -dev plugin or firefly-projects
        if (substr($plugin_folder, -4) === '-dev' || $plugin_folder === 'firefly-projects') {
            continue;
        }

        // Create -dev version of the plugin path
        $dev_plugin_file = $plugin_folder . '-dev/' . $plugin_basename;

        // If -dev version exists, map the update to it
        if (isset($all_plugins[$dev_plugin_file])) {
            $dev_update_data = clone $update_data;
            $dev_update_data->plugin = $dev_plugin_file;
            $updates_to_add[$dev_plugin_file] = $dev_update_data;
        }

        // On Live Dev, remove the non-dev update (only -dev plugins visible)
        if (firefly_projects_is_live_dev()) {
            unset($transient->response[$plugin_file]);
        }
    }

    // Add the -dev updates
    foreach ($updates_to_add as $plugin_file => $update_data) {
        $transient->response[$plugin_file] = $update_data;
    }

    return $transient;
}

/**
 * Filter plugin update transient when it's retrieved (read)
 */
function firefly_projects_filter_plugin_updates($transient) {
    return firefly_projects_map_dev_updates($transient);
}
add_filter('site_transient_update_plugins', 'firefly_projects_filter_plugin_updates');

/**
 * Filter plugin update transient before it's saved
 * This ensures the -dev mappings are stored in the cache
 */
function firefly_projects_presave_plugin_updates($transient) {
    return firefly_projects_map_dev_updates($transient);
}
add_filter('pre_set_site_transient_update_plugins', 'firefly_projects_presave_plugin_updates');

/**
 * ============================================================================
 * DEV PLUGIN UPDATE HANDLING
 * ============================================================================
 *
 * When updating a -dev plugin, WordPress extracts to the original slug folder.
 * These hooks handle the shuffle:
 * 1. Pre-install: Enable maintenance, rename live folder to -temp
 * 2. WordPress extracts update to original folder name
 * 3. Post-install: Copy to -dev, delete extracted folder, restore -temp to original
 */

// Track which -dev plugin is being updated
global $firefly_dev_update_in_progress;
$firefly_dev_update_in_progress = null;

/**
 * Pre-install hook - prepare for -dev plugin update
 * Renames the live folder out of the way so WordPress can extract the update
 */
function firefly_projects_pre_dev_update($response, $hook_extra) {
    global $firefly_dev_update_in_progress;

    // Only on Local Dev or Live Dev (not Production)
    if (!firefly_projects_is_local_dev() && !firefly_projects_is_live_dev()) {
        return $response;
    }

    // Check if this is a plugin update
    if (!isset($hook_extra['plugin'])) {
        return $response;
    }

    $plugin_file = $hook_extra['plugin'];
    $plugin_folder = dirname($plugin_file);

    // Check if updating a -dev plugin
    if (substr($plugin_folder, -4) !== '-dev') {
        return $response;
    }

    // Get the base plugin name (without -dev)
    $base_folder = substr($plugin_folder, 0, -4);
    $plugins_dir = WP_PLUGIN_DIR;
    $live_folder_path = $plugins_dir . '/' . $base_folder;
    $temp_folder_path = $plugins_dir . '/' . $base_folder . '-temp';

    // Enable maintenance mode
    $maintenance_file = ABSPATH . '.maintenance';
    file_put_contents($maintenance_file, '<?php $upgrading = ' . time() . '; ?>');

    // Rename live folder if it exists
    if (is_dir($live_folder_path)) {
        rename($live_folder_path, $temp_folder_path);
    }

    // Track this update
    $firefly_dev_update_in_progress = array(
        'plugin_folder' => $plugin_folder,
        'base_folder' => $base_folder,
        'live_folder_path' => $live_folder_path,
        'temp_folder_path' => $temp_folder_path,
        'dev_folder_path' => $plugins_dir . '/' . $plugin_folder,
    );

    return $response;
}
add_filter('upgrader_pre_install', 'firefly_projects_pre_dev_update', 10, 2);

/**
 * Post-install hook - finalize -dev plugin update
 * Copies the extracted update to -dev folder and restores the live folder
 */
function firefly_projects_post_dev_update($response, $hook_extra) {
    global $firefly_dev_update_in_progress;

    // Only process if we have a tracked -dev update
    if (!$firefly_dev_update_in_progress) {
        return $response;
    }

    $info = $firefly_dev_update_in_progress;

    // The newly downloaded plugin is at the base folder path (WordPress extracted it there)
    $new_plugin_path = $info['live_folder_path'];
    $dev_folder_path = $info['dev_folder_path'];
    $temp_folder_path = $info['temp_folder_path'];

    // Copy new files to -dev folder
    if (is_dir($new_plugin_path)) {
        // Remove old -dev folder
        if (is_dir($dev_folder_path)) {
            firefly_projects_recursive_delete($dev_folder_path);
        }
        // Copy new to -dev
        firefly_projects_recursive_copy($new_plugin_path, $dev_folder_path);
        // Delete the newly extracted (non-dev) folder
        firefly_projects_recursive_delete($new_plugin_path);
    }

    // Restore the temp folder back to original name (the untouched live version)
    if (is_dir($temp_folder_path)) {
        rename($temp_folder_path, $info['live_folder_path']);
    }

    // Disable maintenance mode
    $maintenance_file = ABSPATH . '.maintenance';
    if (file_exists($maintenance_file)) {
        unlink($maintenance_file);
    }

    // Clear tracked update
    $firefly_dev_update_in_progress = null;

    return $response;
}
add_filter('upgrader_post_install', 'firefly_projects_post_dev_update', 10, 2);

/**
 * Recursively copy a directory
 */
function firefly_projects_recursive_copy($src, $dst) {
    $dir = opendir($src);
    if (!$dir) {
        return false;
    }
    @mkdir($dst, 0755, true);
    while (($file = readdir($dir)) !== false) {
        if ($file !== '.' && $file !== '..') {
            $src_path = $src . '/' . $file;
            $dst_path = $dst . '/' . $file;
            if (is_dir($src_path)) {
                firefly_projects_recursive_copy($src_path, $dst_path);
            } else {
                copy($src_path, $dst_path);
            }
        }
    }
    closedir($dir);
    return true;
}

/**
 * Recursively delete a directory
 */
function firefly_projects_recursive_delete($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    $objects = scandir($dir);
    foreach ($objects as $object) {
        if ($object !== '.' && $object !== '..') {
            $path = $dir . '/' . $object;
            if (is_dir($path)) {
                firefly_projects_recursive_delete($path);
            } else {
                unlink($path);
            }
        }
    }
    rmdir($dir);
    return true;
}

