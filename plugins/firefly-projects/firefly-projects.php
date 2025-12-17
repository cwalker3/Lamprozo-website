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
define('FIREFLY_PROJECTS_VERSION', '1.0.20');
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
require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/page-sync.php';

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

    // Create uploads-dev directory for dev environments
    $uploads_dev_dir = dirname($upload_dir['basedir']) . '/uploads-dev';
    if (!file_exists($uploads_dev_dir)) {
        wp_mkdir_p($uploads_dev_dir);
    }

    // Flush rewrite rules for REST API
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'firefly_projects_activate');

/**
 * Ensure uploads-dev directory exists on admin init (for dev environments)
 * This runs on every admin load to ensure the directory is always available
 */
function firefly_projects_ensure_uploads_dev() {
    // Only on Local Dev or Live Dev
    if (!firefly_projects_is_local_dev() && !firefly_projects_is_live_dev()) {
        return;
    }

    $upload_dir = wp_upload_dir();
    $uploads_dev_dir = dirname($upload_dir['basedir']) . '/uploads-dev';

    if (!file_exists($uploads_dev_dir)) {
        wp_mkdir_p($uploads_dev_dir);
    }
}
add_action('admin_init', 'firefly_projects_ensure_uploads_dev');

/**
 * Redirect WordPress uploads to uploads-dev on Local Dev and Live Dev
 * This makes all new media uploads go to uploads-dev instead of uploads
 */
function firefly_projects_upload_dir($uploads) {
    // Only on Local Dev or Live Dev
    if (!firefly_projects_is_local_dev() && !firefly_projects_is_live_dev()) {
        return $uploads;
    }

    // Replace 'uploads' with 'uploads-dev' in all paths and URLs
    $uploads['basedir'] = str_replace('/uploads', '/uploads-dev', $uploads['basedir']);
    $uploads['baseurl'] = str_replace('/uploads', '/uploads-dev', $uploads['baseurl']);
    $uploads['path'] = str_replace('/uploads', '/uploads-dev', $uploads['path']);
    $uploads['url'] = str_replace('/uploads', '/uploads-dev', $uploads['url']);

    // Ensure the directory exists
    if (!file_exists($uploads['path'])) {
        wp_mkdir_p($uploads['path']);
    }

    return $uploads;
}
add_filter('upload_dir', 'firefly_projects_upload_dir');

/**
 * Filter Media Library to only show files from the appropriate uploads folder
 * Local Dev & Live Dev: Only show uploads-dev
 * Production: Only show uploads (hide uploads-dev)
 */
function firefly_projects_filter_media_library($query) {
    // Only apply to media queries in admin
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    // Only apply to attachment queries
    if ($query->get('post_type') !== 'attachment') {
        return;
    }

    // Get the appropriate uploads folder based on environment
    if (firefly_projects_is_local_dev() || firefly_projects_is_live_dev()) {
        // Dev environments: only show uploads-dev files
        $meta_query = $query->get('meta_query') ?: array();
        $meta_query[] = array(
            'key'     => '_wp_attached_file',
            'compare' => 'EXISTS'
        );
        $query->set('meta_query', $meta_query);

        // Add a filter to further restrict by path
        add_filter('posts_where', 'firefly_projects_media_where_uploads_dev', 10, 2);
    } elseif (firefly_projects_is_production()) {
        // Production: only show regular uploads files (hide uploads-dev)
        add_filter('posts_where', 'firefly_projects_media_where_uploads_prod', 10, 2);
    }
}
add_action('pre_get_posts', 'firefly_projects_filter_media_library');

/**
 * Filter WHERE clause to only show uploads-dev files (for dev environments)
 */
function firefly_projects_media_where_uploads_dev($where, $query) {
    global $wpdb;

    if ($query->get('post_type') !== 'attachment') {
        return $where;
    }

    // Only show attachments where the file path contains 'uploads-dev'
    $where .= " AND {$wpdb->posts}.ID IN (
        SELECT post_id FROM {$wpdb->postmeta}
        WHERE meta_key = '_wp_attached_file'
        AND meta_value LIKE '%uploads-dev%'
    )";

    // Remove this filter so it doesn't affect other queries
    remove_filter('posts_where', 'firefly_projects_media_where_uploads_dev', 10);

    return $where;
}

/**
 * Filter WHERE clause to hide uploads-dev files (for production)
 */
function firefly_projects_media_where_uploads_prod($where, $query) {
    global $wpdb;

    if ($query->get('post_type') !== 'attachment') {
        return $where;
    }

    // Exclude attachments where the file path contains 'uploads-dev'
    $where .= " AND {$wpdb->posts}.ID NOT IN (
        SELECT post_id FROM {$wpdb->postmeta}
        WHERE meta_key = '_wp_attached_file'
        AND meta_value LIKE '%uploads-dev%'
    )";

    // Remove this filter so it doesn't affect other queries
    remove_filter('posts_where', 'firefly_projects_media_where_uploads_prod', 10);

    return $where;
}

/**
 * Filter AJAX media queries (for Media Library modal in Gutenberg/Classic editor)
 */
function firefly_projects_filter_ajax_media_query($query) {
    if (firefly_projects_is_local_dev() || firefly_projects_is_live_dev()) {
        add_filter('posts_where', 'firefly_projects_media_where_uploads_dev', 10, 2);
    } elseif (firefly_projects_is_production()) {
        add_filter('posts_where', 'firefly_projects_media_where_uploads_prod', 10, 2);
    }
    return $query;
}
add_filter('ajax_query_attachments_args', 'firefly_projects_filter_ajax_media_query');

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
 * Local Dev & Live Dev: Only show -dev plugins (hide production versions)
 * Production: Only show non-dev plugins (hide -dev to prevent confusion)
 */
function firefly_projects_filter_plugins($plugins) {
    $filtered = array();

    foreach ($plugins as $plugin_file => $plugin_data) {
        $plugin_folder = dirname($plugin_file);

        // Extract just the plugin folder name (first part of path)
        if (strpos($plugin_folder, '/') !== false) {
            $plugin_folder = substr($plugin_folder, 0, strpos($plugin_folder, '/'));
        }

        $is_dev_plugin = (substr($plugin_folder, -4) === '-dev');

        // Skip firefly-projects itself from filtering (always show)
        if ($plugin_folder === 'firefly-projects') {
            $filtered[$plugin_file] = $plugin_data;
            continue;
        }

        // Local Dev & Live Dev: Only show -dev plugins
        if (firefly_projects_is_local_dev() || firefly_projects_is_live_dev()) {
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
 * Filter themes list based on environment
 * Local Dev & Live Dev: Only show -dev themes (hide production versions)
 * Production: Only show non-dev themes (hide -dev to prevent confusion)
 */
function firefly_projects_filter_themes($themes) {
    $filtered = array();

    foreach ($themes as $theme_slug => $theme_data) {
        $is_dev_theme = (substr($theme_slug, -4) === '-dev');

        // Local Dev & Live Dev: Only show -dev themes
        if (firefly_projects_is_local_dev() || firefly_projects_is_live_dev()) {
            if ($is_dev_theme) {
                $filtered[$theme_slug] = $theme_data;
            }
        }
        // Production: Only show non-dev themes
        else if (firefly_projects_is_production()) {
            if (!$is_dev_theme) {
                $filtered[$theme_slug] = $theme_data;
            }
        }
    }

    return $filtered;
}
add_filter('wp_prepare_themes_for_js', 'firefly_projects_filter_themes');

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


/**
 * Enqueue Gutenberg editor scripts and styles for page sync
 * Only loads in the block editor for pages and posts
 */
function firefly_projects_enqueue_gutenberg_assets() {
    // Only load on dev environments where syncing is enabled
    if (!defined('FIREFLY_DEV') || !FIREFLY_DEV) {
        return;
    }

    // Only load if configuration is complete
    if (!firefly_projects_is_configured()) {
        return;
    }

    // Use file modification time for versioning on dev environments to prevent caching
    $js_file = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/js/gutenberg-sync-button.js';
    $css_file = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/css/gutenberg-sync.css';
    $js_version = (firefly_projects_is_local_dev() || firefly_projects_is_live_dev()) ? filemtime($js_file) : FIREFLY_PROJECTS_VERSION;
    $css_version = (firefly_projects_is_local_dev() || firefly_projects_is_live_dev()) ? filemtime($css_file) : FIREFLY_PROJECTS_VERSION;

    // Register and enqueue the sync button script
    wp_enqueue_script(
        'firefly-gutenberg-sync',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/js/gutenberg-sync-button.js',
        array(
            'wp-plugins',
            'wp-edit-post',
            'wp-element',
            'wp-components',
            'wp-data',
            'wp-i18n',
            'wp-api-fetch'
        ),
        $js_version,
        true
    );

    // Enqueue styles
    wp_enqueue_style(
        'firefly-gutenberg-sync',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/css/gutenberg-sync.css',
        array('wp-components'),
        $css_version
    );

    // Pass configuration to JavaScript
    wp_localize_script('firefly-gutenberg-sync', 'fireflyPageSync', array(
        'restUrl' => rest_url('firefly-plugin/v1/'),
        'nonce' => wp_create_nonce('wp_rest'),
        'isConfigured' => firefly_projects_is_configured(),
        'remoteSite' => defined('LIVE_DEV_ENDPOINT') ? parse_url(LIVE_DEV_ENDPOINT, PHP_URL_HOST) : '',
        'hasProdEndpoint' => defined('PROD_ENDPOINT') && !empty(PROD_ENDPOINT),
        'prodSite' => defined('PROD_ENDPOINT') && !empty(PROD_ENDPOINT) ? parse_url(PROD_ENDPOINT, PHP_URL_HOST) : '',
        'isLocalDev' => firefly_projects_is_local_dev()
    ));
}
add_action('enqueue_block_editor_assets', 'firefly_projects_enqueue_gutenberg_assets');

/**
 * Register post meta for REST API access
 * Allows Gutenberg to read last sync timestamps per environment
 */
function firefly_projects_register_meta() {
    $post_types = array('page', 'post');

    foreach ($post_types as $post_type) {
        // Last sync to Live Dev
        register_post_meta($post_type, '_firefly_last_sync_dev', array(
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'integer',
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));

        // Last sync to Production
        register_post_meta($post_type, '_firefly_last_sync_prod', array(
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'integer',
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));

        // Last pull from Live Dev
        register_post_meta($post_type, '_firefly_last_pull_dev', array(
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'integer',
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));

        // Last pull from Production
        register_post_meta($post_type, '_firefly_last_pull_prod', array(
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'integer',
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));

        // Asset map for URL mappings
        // Note: We don't expose this to REST API to avoid validation issues
        // It's managed entirely by PHP backend
        register_post_meta($post_type, '_firefly_asset_map', array(
            'show_in_rest'  => false,
            'single'        => true,
            'type'          => 'string', // Stored as JSON string
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
    }
}
add_action('init', 'firefly_projects_register_meta');

/**
 * ============================================================================
 * ASSET CLEANUP - Delete dev assets when page is deleted
 * ============================================================================
 */

/**
 * Clean up uploads-dev/pages/{slug}/ folder when a page is deleted
 *
 * @param int $post_id The post ID being deleted
 */
function firefly_projects_cleanup_page_assets($post_id) {
    $post = get_post($post_id);

    // Only for pages and posts
    if (!$post || !in_array($post->post_type, array('page', 'post'))) {
        return;
    }

    $page_slug = $post->post_name;
    if (empty($page_slug)) {
        return;
    }

    // Get the dev assets directory for this page
    $upload_dir = wp_upload_dir();
    $dev_assets_dir = dirname($upload_dir['basedir']) . '/uploads-dev/pages/' . $page_slug;

    // Check if directory exists
    if (!is_dir($dev_assets_dir)) {
        return;
    }

    // Recursively delete the directory
    firefly_projects_delete_directory($dev_assets_dir);
}
add_action('before_delete_post', 'firefly_projects_cleanup_page_assets');

/**
 * Recursively delete a directory and its contents
 *
 * @param string $dir Directory path to delete
 * @return bool Success
 */
function firefly_projects_delete_directory($dir) {
    if (!is_dir($dir)) {
        return false;
    }

    $files = array_diff(scandir($dir), array('.', '..'));

    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            firefly_projects_delete_directory($path);
        } else {
            unlink($path);
        }
    }

    return rmdir($dir);
}

/**
 * ============================================================================
 * MENU SYNC - Meta Box and Assets
 * ============================================================================
 */

/**
 * Add menu sync meta box to nav-menus.php
 */
function firefly_projects_add_menu_sync_metabox() {
    // Only on dev environments where syncing is enabled
    if (!defined('FIREFLY_DEV') || !FIREFLY_DEV) {
        return;
    }

    // Only if configuration is complete
    if (!firefly_projects_is_configured()) {
        return;
    }

    add_meta_box(
        'firefly-menu-sync',
        __('Remote Sync', 'firefly-projects'),
        'firefly_projects_menu_sync_metabox_content',
        'nav-menus',
        'side',
        'default'
    );
}
add_action('admin_head-nav-menus.php', 'firefly_projects_add_menu_sync_metabox');

/**
 * Render the menu sync meta box content
 * The actual UI is built by JavaScript for better interactivity
 */
function firefly_projects_menu_sync_metabox_content() {
    echo '<div id="firefly-menu-sync-loading">';
    echo '<p>' . esc_html__('Loading...', 'firefly-projects') . '</p>';
    echo '</div>';
}

/**
 * Enqueue menu sync assets on nav-menus.php
 */
function firefly_projects_enqueue_menu_sync_assets($hook) {
    // Only on nav-menus.php
    if ($hook !== 'nav-menus.php') {
        return;
    }

    // Only on dev environments
    if (!defined('FIREFLY_DEV') || !FIREFLY_DEV) {
        return;
    }

    // Only if configured
    if (!firefly_projects_is_configured()) {
        return;
    }

    // Get current menu ID if available
    $menu_id = 0;
    if (isset($_REQUEST['menu'])) {
        $menu_id = absint($_REQUEST['menu']);
    } elseif (isset($_REQUEST['action']) && $_REQUEST['action'] === 'edit') {
        $menu_id = absint($_REQUEST['menu']);
    }

    // Use file modification time for versioning on dev environments
    $js_file = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/js/menu-sync.js';
    $css_file = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/css/menu-sync.css';
    $js_version = (firefly_projects_is_local_dev() || firefly_projects_is_live_dev()) ? filemtime($js_file) : FIREFLY_PROJECTS_VERSION;
    $css_version = (firefly_projects_is_local_dev() || firefly_projects_is_live_dev()) ? filemtime($css_file) : FIREFLY_PROJECTS_VERSION;

    // Enqueue JavaScript
    wp_enqueue_script(
        'firefly-menu-sync',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/js/menu-sync.js',
        array('jquery'),
        $js_version,
        true
    );

    // Enqueue CSS
    wp_enqueue_style(
        'firefly-menu-sync',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/css/menu-sync.css',
        array(),
        $css_version
    );

    // Get sync/pull timestamps for current menu
    $last_sync_dev = $menu_id ? get_option('firefly_menu_sync_dev_' . $menu_id, 0) : 0;
    $last_sync_prod = $menu_id ? get_option('firefly_menu_sync_prod_' . $menu_id, 0) : 0;
    $last_pull_dev = $menu_id ? get_option('firefly_menu_pull_dev_' . $menu_id, 0) : 0;
    $last_pull_prod = $menu_id ? get_option('firefly_menu_pull_prod_' . $menu_id, 0) : 0;

    // Pass configuration to JavaScript
    wp_localize_script('firefly-menu-sync', 'fireflyMenuSync', array(
        'restUrl' => rest_url('firefly-plugin/v1/'),
        'nonce' => wp_create_nonce('wp_rest'),
        'hasProdEndpoint' => defined('PROD_ENDPOINT') && !empty(PROD_ENDPOINT),
        'lastSyncDev' => (int) $last_sync_dev,
        'lastSyncProd' => (int) $last_sync_prod,
        'lastPullDev' => (int) $last_pull_dev,
        'lastPullProd' => (int) $last_pull_prod,
        'menuId' => $menu_id
    ));
}
add_action('admin_enqueue_scripts', 'firefly_projects_enqueue_menu_sync_assets');

/**
 * ============================================================================
 * PAGES LIST SYNC - Toolbar, Row Actions, and Assets
 * ============================================================================
 */

/**
 * Add "Sync to Remote" row action to pages list
 */
function firefly_projects_add_page_sync_row_action($actions, $post) {
    // Only on dev environments where syncing is enabled
    if (!defined('FIREFLY_DEV') || !FIREFLY_DEV) {
        return $actions;
    }

    // Only for published pages
    if ($post->post_status !== 'publish') {
        return $actions;
    }

    // Only if configuration is complete
    if (!firefly_projects_is_configured()) {
        return $actions;
    }

    // Get sync timestamps
    $last_sync_dev = get_post_meta($post->ID, '_firefly_last_sync_dev', true);
    $last_sync_prod = get_post_meta($post->ID, '_firefly_last_sync_prod', true);

    $actions['firefly_sync'] = sprintf(
        '<a href="#" class="firefly-sync-page-link" data-post-id="%d" data-post-title="%s" data-last-sync-dev="%s" data-last-sync-prod="%s">%s</a>',
        $post->ID,
        esc_attr($post->post_title),
        esc_attr($last_sync_dev),
        esc_attr($last_sync_prod),
        __('Sync to Remote', 'firefly-projects')
    );

    return $actions;
}
add_filter('page_row_actions', 'firefly_projects_add_page_sync_row_action', 10, 2);

/**
 * Display sync toolbar on Pages list admin screen
 */
function firefly_projects_pages_sync_toolbar() {
    // Only on dev environments
    if (!defined('FIREFLY_DEV') || !FIREFLY_DEV) {
        return;
    }

    // Only if configured
    if (!firefly_projects_is_configured()) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'edit-page') {
        return;
    }

    $page_count = wp_count_posts('page')->publish;
    $has_prod = defined('PROD_ENDPOINT') && !empty(PROD_ENDPOINT);
    ?>
    <div id="firefly-pages-toolbar" class="firefly-pages-toolbar">
        <?php if ($has_prod): ?>
        <div class="firefly-env-toggle-container">
            <div class="firefly-env-toggle-row">
                <span class="firefly-env-toggle-label active">Live Dev</span>
                <button type="button" role="switch" aria-checked="false" class="firefly-env-toggle-switch" id="firefly-pages-env-toggle">
                    <span class="firefly-env-toggle-knob"></span>
                </button>
                <span class="firefly-env-toggle-label">Production</span>
            </div>
        </div>
        <?php endif; ?>
        <button type="button" class="button button-primary" id="firefly-sync-all-pages">
            <?php printf(__('Sync All Pages (%d)', 'firefly-projects'), $page_count); ?>
        </button>
    </div>
    <?php
}
add_action('admin_notices', 'firefly_projects_pages_sync_toolbar');

/**
 * Enqueue pages list sync assets
 */
function firefly_projects_enqueue_pages_list_sync_assets($hook) {
    // Only on edit.php for pages
    if ($hook !== 'edit.php') {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'page') {
        return;
    }

    // Only on dev environments
    if (!defined('FIREFLY_DEV') || !FIREFLY_DEV) {
        return;
    }

    // Only if configured
    if (!firefly_projects_is_configured()) {
        return;
    }

    // Use file modification time for versioning on dev environments
    $js_file = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/js/pages-list-sync.js';
    $css_file = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/css/pages-list-sync.css';
    $js_version = (firefly_projects_is_local_dev() || firefly_projects_is_live_dev()) ? filemtime($js_file) : FIREFLY_PROJECTS_VERSION;
    $css_version = (firefly_projects_is_local_dev() || firefly_projects_is_live_dev()) ? filemtime($css_file) : FIREFLY_PROJECTS_VERSION;

    // Enqueue JavaScript
    wp_enqueue_script(
        'firefly-pages-list-sync',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/js/pages-list-sync.js',
        array('jquery'),
        $js_version,
        true
    );

    // Enqueue CSS
    wp_enqueue_style(
        'firefly-pages-list-sync',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/css/pages-list-sync.css',
        array(),
        $css_version
    );

    // Enqueue dashicons for warning icon
    wp_enqueue_style('dashicons');

    // Get page count
    $page_count = wp_count_posts('page')->publish;

    // Pass configuration to JavaScript
    wp_localize_script('firefly-pages-list-sync', 'fireflyPagesSync', array(
        'restUrl'         => rest_url('firefly-plugin/v1/'),
        'nonce'           => wp_create_nonce('wp_rest'),
        'hasProdEndpoint' => defined('PROD_ENDPOINT') && !empty(PROD_ENDPOINT),
        'pageCount'       => (int) $page_count
    ));
}
add_action('admin_enqueue_scripts', 'firefly_projects_enqueue_pages_list_sync_assets');
