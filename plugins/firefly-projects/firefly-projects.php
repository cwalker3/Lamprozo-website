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
 * Display admin notice if FIREFLY_DEV is not set or configuration is incomplete
 */
function firefly_projects_admin_notices() {
    // Only show to admins
    if (!current_user_can('manage_options')) {
        return;
    }

    // Show notice if FIREFLY_DEV is not defined (user won't see the menu)
    if (!defined('FIREFLY_DEV') || !FIREFLY_DEV) {
        $screen = get_current_screen();
        // Show on plugins page or dashboard
        if ($screen && in_array($screen->id, array('plugins', 'dashboard'))) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>Firefly Projects:</strong> The Projects admin menu is hidden. To access it on your dev environment, add <code>define('FIREFLY_DEV', true);</code> to your wp-config.php file.</p>
                <p><em>Note: On production/live environments, keep this plugin activated without FIREFLY_DEV to receive synced files via REST API.</em></p>
            </div>
            <?php
        }
        return;
    }

    // If we're on the Projects page, check for configuration issues
    $screen = get_current_screen();
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
                    <li><code>define('LIVE_DEV_ENDPOINT', 'https://your-remote-site.com/wp-json/firefly-plugin/v1/update_project');</code></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php
        }
    }
}
add_action('admin_notices', 'firefly_projects_admin_notices');
