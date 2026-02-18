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
define('FIREFLY_PROJECTS_VERSION', '1.0.21');
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
require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/geo-post.php';

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
                <p><strong>Firefly Projects:</strong> Running in <strong>LIVE DEV</strong> mode. This environment receives syncs from Local Dev.</p>
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

    // Production environment - show notice about receiving syncs
    if ($screen && in_array($screen->id, array('plugins', 'dashboard'))) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong>Firefly Projects:</strong> Running in <strong>Production</strong> mode. This site can receive syncs and host the dev environment bootstrap.</p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'firefly_projects_admin_notices');

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

/**
 * Enqueue GEO Settings panel and FAQ block assets
 * Loads on ALL environments (Local Dev, Live Dev, Production) for blog posts
 */
function firefly_projects_enqueue_geo_assets() {
    // Determine current post type
    global $post;
    $current_post_type = '';
    if ($post) {
        $current_post_type = $post->post_type;
    } elseif (isset($_GET['post'])) {
        $current_post_type = get_post_type($_GET['post']);
    } elseif (isset($_GET['post_type'])) {
        $current_post_type = $_GET['post_type'];
    }

    // Only load for posts
    if ($current_post_type !== 'post') {
        return;
    }

    $geo_js_file = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/js/geo-post-panel.js';
    $faq_js_file = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/js/faq-block.js';
    $geo_css_file = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/css/geo-post.css';

    $geo_version = file_exists($geo_js_file) ? filemtime($geo_js_file) : FIREFLY_PROJECTS_VERSION;

    // GEO Settings sidebar panel
    wp_enqueue_script(
        'firefly-geo-post-panel',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/js/geo-post-panel.js',
        array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'),
        $geo_version,
        true
    );

    // FAQ block
    wp_enqueue_script(
        'firefly-faq-block',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/js/faq-block.js',
        array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data'),
        file_exists($faq_js_file) ? filemtime($faq_js_file) : FIREFLY_PROJECTS_VERSION,
        true
    );

    // GEO styles
    wp_enqueue_style(
        'firefly-geo-post',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/css/geo-post.css',
        array(),
        file_exists($geo_css_file) ? filemtime($geo_css_file) : FIREFLY_PROJECTS_VERSION
    );
}
add_action('enqueue_block_editor_assets', 'firefly_projects_enqueue_gutenberg_assets');
add_action('enqueue_block_editor_assets', 'firefly_projects_enqueue_geo_assets');

/**
 * Register post meta for REST API access
 * Allows Gutenberg to read last sync timestamps per environment
 */
function firefly_projects_register_meta() {
    $post_types = array('page', 'post');

    // Register GEO meta fields for posts
    firefly_projects_register_geo_meta();

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
 * ASSET CLEANUP - Delete page assets when page is deleted
 * ============================================================================
 */

/**
 * Clean up uploads/pages/{slug}/ folder when a page is deleted
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

    // Get the assets directory for this page
    $upload_dir = wp_upload_dir();
    $assets_dir = $upload_dir['basedir'] . '/pages/' . $page_slug;

    // Check if directory exists
    if (!is_dir($assets_dir)) {
        return;
    }

    // Recursively delete the directory
    firefly_projects_delete_directory($assets_dir);
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
        <div class="firefly-toolbar-buttons">
            <button type="button" class="button button-primary" id="firefly-sync-all-pages">
                <?php printf(__('Sync All Pages (%d)', 'firefly-projects'), $page_count); ?>
            </button>
            <button type="button" class="button" id="firefly-pull-pages">
                <?php _e('Pull from Remote', 'firefly-projects'); ?>
            </button>
        </div>
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
