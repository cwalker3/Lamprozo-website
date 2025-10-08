<?php

// Plugin: includes/apps/backend/models/cache.php

if (!defined('ABSPATH')) exit;

/**
 * Firefly Collective - Static Cache System
 * Template-scoped page caching for maximum performance
 *
 * Architecture: Captures fully-rendered HTML and saves to disk
 * Cache Location: wp-content/cache/firefly-collective/{template}/{domain}/
 * Performance: 10-100x faster page loads for public pages
 */

// ============================================================================
// CACHE SERVING (runs very early in WordPress)
// ============================================================================

/**
 * Serve cached file if it exists (PHP-based method)
 * Called very early in WordPress execution via 'init' hook
 */
add_action('init', 'firefly_collective_cache_serve', 1);

function firefly_collective_cache_serve() {
    // Only serve cache for non-logged-in, non-admin requests
    if (firefly_collective_cache_should_exclude()) {
        return;
    }

    // Get template
    $template = firefly_collective_get_active_template();
    if (!$template) {
        return;
    }

    // Get cache file path
    $cache_file = firefly_collective_cache_get_file_path($template);

    // Check if cache file exists
    if (!file_exists($cache_file)) {
        return; // No cache, continue WordPress execution
    }

    // Check cache age (optional expiration)
    $cache_max_age = apply_filters('firefly_collective_cache_max_age', 86400); // 24 hours default
    if ($cache_max_age > 0) {
        $cache_age = time() - filemtime($cache_file);
        if ($cache_age > $cache_max_age) {
            return; // Cache expired, regenerate
        }
    }

    // Serve cached file
    header('Content-Type: text/html; charset=UTF-8');
    header('X-FFC-Cache: HIT');
    header('X-FFC-Template: ' . $template);
    readfile($cache_file);
    exit; // Stop WordPress execution
}

// ============================================================================
// CACHE GENERATION (output buffering)
// ============================================================================

/**
 * Start output buffering early in WordPress execution
 */
add_action('template_redirect', 'firefly_collective_cache_start', 1);

function firefly_collective_cache_start() {
    // Don't cache if conditions exclude it
    if (firefly_collective_cache_should_exclude()) {
        return;
    }

    // Start output buffering with callback
    ob_start('firefly_collective_cache_save');
}

/**
 * Save captured HTML to cache file
 * Called automatically by ob_start() callback
 */
function firefly_collective_cache_save($html) {
    // Get current template
    $template = firefly_collective_get_active_template();
    if (!$template) {
        return $html; // No template, don't cache
    }

    // Don't cache empty responses
    if (empty(trim($html))) {
        return $html;
    }

    // Build cache file path
    $cache_path = firefly_collective_cache_get_file_path($template);

    // Create directory structure if needed
    $cache_dir = dirname($cache_path);
    if (!is_dir($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }

    // Save HTML to cache file
    file_put_contents($cache_path, $html);

    // Set permissions
    @chmod($cache_path, 0644);

    // Add HTML comment with cache info
    $html = firefly_collective_cache_add_signature($html, $template);

    // Add header for debugging
    if (!headers_sent()) {
        header('X-FFC-Cache: MISS');
        header('X-FFC-Template: ' . $template);
    }

    return $html; // Return to browser
}

// ============================================================================
// CACHE EXCLUSION LOGIC
// ============================================================================

/**
 * Determine if current request should be excluded from caching
 */
function firefly_collective_cache_should_exclude() {
    // Don't cache logged-in users (personalized content)
    if (is_user_logged_in()) {
        return true;
    }

    // Don't cache admin pages
    if (is_admin()) {
        return true;
    }

    // Don't cache if doing AJAX
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return true;
    }

    // Don't cache if doing CRON
    if (defined('DOING_CRON') && DOING_CRON) {
        return true;
    }

    // Don't cache 404 pages
    if (is_404()) {
        return true;
    }

    // Don't cache POST requests (forms, AJAX)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return true;
    }

    // Don't cache URLs with query strings (except whitelisted)
    if (!empty($_GET) && !firefly_collective_cache_is_query_whitelisted()) {
        return true;
    }

    // Check for WordPress auth cookies
    foreach ($_COOKIE as $key => $value) {
        if (strpos($key, 'wordpress_logged_in') === 0) {
            return true;
        }
        if (strpos($key, 'comment_author') === 0) {
            return true; // Don't cache for comment authors
        }
    }

    // Exclude specific URL patterns
    $excluded_paths = firefly_collective_cache_get_excluded_paths();
    $request_uri = $_SERVER['REQUEST_URI'];

    foreach ($excluded_paths as $path) {
        if (strpos($request_uri, $path) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Get list of paths that should never be cached
 */
function firefly_collective_cache_get_excluded_paths() {
    return apply_filters('firefly_collective_cache_excluded_paths', [
        '/dashboard',              // User dashboard (dynamic)
        '/order-history',          // E-commerce orders (user-specific)
        '/wp-admin',               // WordPress admin
        '/wp-json',                // REST API endpoints
        '/cart',                   // Shopping cart (future)
        '/checkout',               // Checkout process (future)
        '/xmlrpc.php',             // XML-RPC
        '/wp-cron.php',            // Cron
    ]);
}

/**
 * Check if query string is whitelisted (e.g., UTM parameters)
 */
function firefly_collective_cache_is_query_whitelisted() {
    $whitelisted_params = apply_filters('firefly_collective_cache_whitelisted_query_params', [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'fbclid',
        'gclid',
        'ref',
    ]);

    foreach (array_keys($_GET) as $param) {
        if (!in_array($param, $whitelisted_params)) {
            return false;
        }
    }

    return true;
}

// ============================================================================
// CACHE FILE PATH UTILITIES
// ============================================================================

/**
 * Build cache file path for current request
 */
function firefly_collective_cache_get_file_path($template) {
    $cache_base = WP_CONTENT_DIR . '/cache/firefly-collective';
    $host = $_SERVER['HTTP_HOST'];
    $request_uri = $_SERVER['REQUEST_URI'];

    // Remove query string
    $request_uri = strtok($request_uri, '?');

    // Remove trailing slash (unless it's the homepage)
    $request_uri = rtrim($request_uri, '/');
    if (empty($request_uri)) {
        $request_uri = '/';
    }

    // Sanitize path (prevent directory traversal)
    $request_uri = str_replace(['..', '\\'], ['', '/'], $request_uri);

    // Build path: cache/firefly-collective/{template}/{host}{uri}/index.html
    $cache_path = $cache_base . '/' . $template . '/' . $host . $request_uri;

    // Ensure path ends with /index.html
    if (substr($cache_path, -1) !== '/') {
        $cache_path .= '/';
    }
    $cache_path .= 'index.html';

    return $cache_path;
}

/**
 * Add HTML comment with cache metadata (optional)
 */
function firefly_collective_cache_add_signature($html, $template) {
    $timestamp = current_time('mysql');
    $signature = sprintf(
        "\n<!-- Cached by Firefly Collective | Template: %s | Generated: %s -->\n",
        esc_html($template),
        esc_html($timestamp)
    );

    // Insert before </body> if it exists
    if (stripos($html, '</body>') !== false) {
        $html = str_ireplace('</body>', $signature . '</body>', $html);
    } else {
        $html .= $signature;
    }

    return $html;
}

// ============================================================================
// CACHE INVALIDATION (auto-clear on content changes)
// ============================================================================

/**
 * Clear cache on post/page save
 */
add_action('save_post', 'firefly_collective_cache_invalidate_post', 10, 2);
add_action('delete_post', 'firefly_collective_cache_invalidate_post', 10, 2);

function firefly_collective_cache_invalidate_post($post_id, $post = null) {
    // Only clear cache for published posts/pages
    if (get_post_status($post_id) !== 'publish') {
        return;
    }

    // Ignore autosaves and revisions
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    $template = firefly_collective_get_active_template();
    if (!$template) {
        return;
    }

    // Clear the specific post/page cache
    $url = get_permalink($post_id);
    firefly_collective_cache_delete_url($template, $url);

    // Clear homepage (may show recent posts)
    firefly_collective_cache_delete_url($template, home_url());

    // If it's a blog post, also clear blog listing
    if (get_post_type($post_id) === 'post') {
        $blog_page_id = get_option('page_for_posts');
        if ($blog_page_id) {
            $blog_url = get_permalink($blog_page_id);
            firefly_collective_cache_delete_url($template, $blog_url);
        }
    }
}

/**
 * Clear all cache on template switch
 */
add_action('update_option_firefly_collective_active_template', 'firefly_collective_cache_clear_on_template_switch', 10, 2);

function firefly_collective_cache_clear_on_template_switch($old_value, $new_value) {
    // Clear old template's cache (new template has no cache yet)
    if ($old_value) {
        firefly_collective_cache_delete_template($old_value);
    }
}

/**
 * Clear all cache on theme/plugin update
 */
add_action('switch_theme', 'firefly_collective_cache_clear_all');
add_action('activated_plugin', 'firefly_collective_cache_clear_all');
add_action('deactivated_plugin', 'firefly_collective_cache_clear_all');

/**
 * Clear cache on menu changes (affects all pages)
 */
add_action('wp_update_nav_menu', 'firefly_collective_cache_clear_all');

/**
 * Clear cache on customizer save (colors, layout changes)
 */
add_action('customize_save_after', 'firefly_collective_cache_clear_all');

// ============================================================================
// CACHE DELETION UTILITIES
// ============================================================================

/**
 * Delete cache file for specific URL
 */
function firefly_collective_cache_delete_url($template, $url) {
    $parsed_url = parse_url($url);
    $host = isset($parsed_url['host']) ? $parsed_url['host'] : $_SERVER['HTTP_HOST'];
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';

    // Remove trailing slash (unless homepage)
    $path = rtrim($path, '/');
    if (empty($path)) {
        $path = '/';
    }

    $cache_base = WP_CONTENT_DIR . '/cache/firefly-collective';
    $cache_file = $cache_base . '/' . $template . '/' . $host . $path . '/index.html';

    if (file_exists($cache_file)) {
        @unlink($cache_file);

        // Also try to remove parent directory if empty
        $cache_dir = dirname($cache_file);
        @rmdir($cache_dir);
    }
}

/**
 * Delete all cache for a specific template
 */
function firefly_collective_cache_delete_template($template) {
    $cache_dir = WP_CONTENT_DIR . '/cache/firefly-collective/' . $template;

    if (is_dir($cache_dir)) {
        firefly_collective_cache_delete_directory($cache_dir);
    }
}

/**
 * Delete entire cache directory (all templates)
 */
function firefly_collective_cache_clear_all() {
    $cache_base = WP_CONTENT_DIR . '/cache/firefly-collective';

    if (!is_dir($cache_base)) {
        return;
    }

    // Get all template directories
    $templates = glob($cache_base . '/*', GLOB_ONLYDIR);

    foreach ($templates as $template_dir) {
        firefly_collective_cache_delete_directory($template_dir);
    }
}

/**
 * Recursively delete directory
 */
function firefly_collective_cache_delete_directory($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $files = array_diff(scandir($dir), ['.', '..', '.htaccess', '.gitkeep', 'CACHE_ARCHITECTURE.md']);

    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            firefly_collective_cache_delete_directory($path);
        } else {
            @unlink($path);
        }
    }

    // Don't delete root cache directory, just empty it
    if ($dir !== WP_CONTENT_DIR . '/cache/firefly-collective') {
        @rmdir($dir);
    }
}

// ============================================================================
// ADMIN BAR CONTROLS
// ============================================================================

/**
 * Add "Clear Cache" button to WordPress admin bar
 */
add_action('admin_bar_menu', 'firefly_collective_cache_admin_bar', 10);

function firefly_collective_cache_admin_bar($wp_admin_bar) {
    // Only show to administrators
    if (!current_user_can('manage_options')) {
        return;
    }

    // Main menu item
    $wp_admin_bar->add_node([
        'id'    => 'ffc-clear-cache',
        'title' => '<span class="ab-icon dashicons-update"></span> Clear Cache',
        'href'  => wp_nonce_url(admin_url('admin-post.php?action=ffc_clear_cache'), 'ffc_clear_cache'),
        'meta'  => [
            'title' => 'Clear Firefly Collective cache for current template',
        ],
    ]);

    // Sub-menu: Clear current template only
    $template = firefly_collective_get_active_template();
    $wp_admin_bar->add_node([
        'parent' => 'ffc-clear-cache',
        'id'     => 'ffc-clear-cache-current',
        'title'  => 'Clear "' . esc_html($template) . '" Template',
        'href'   => wp_nonce_url(admin_url('admin-post.php?action=ffc_clear_cache_current'), 'ffc_clear_cache_current'),
    ]);

    // Sub-menu: Clear all templates
    $wp_admin_bar->add_node([
        'parent' => 'ffc-clear-cache',
        'id'     => 'ffc-clear-cache-all',
        'title'  => 'Clear All Templates',
        'href'   => wp_nonce_url(admin_url('admin-post.php?action=ffc_clear_cache_all'), 'ffc_clear_cache_all'),
    ]);
}

/**
 * Reposition Clear Cache to be last item in admin bar
 * Runs after all items are added, removes and re-adds to move to end
 */
add_action('wp_before_admin_bar_render', 'firefly_collective_cache_reposition_admin_bar');

function firefly_collective_cache_reposition_admin_bar() {
    global $wp_admin_bar;

    // Only for administrators
    if (!current_user_can('manage_options')) {
        return;
    }

    // Get the cache menu node
    $cache_node = $wp_admin_bar->get_node('ffc-clear-cache');

    if ($cache_node) {
        // Remove it
        $wp_admin_bar->remove_node('ffc-clear-cache');

        // Re-add it (this makes it appear last)
        $wp_admin_bar->add_node($cache_node);
    }
}

/**
 * Handle cache clear requests
 */
add_action('admin_post_ffc_clear_cache', 'firefly_collective_cache_handle_clear');
add_action('admin_post_ffc_clear_cache_all', 'firefly_collective_cache_handle_clear_all');
add_action('admin_post_ffc_clear_cache_current', 'firefly_collective_cache_handle_clear_current');

function firefly_collective_cache_handle_clear() {
    check_admin_referer('ffc_clear_cache');

    $template = firefly_collective_get_active_template();
    firefly_collective_cache_delete_template($template);

    wp_redirect(add_query_arg('cache_cleared', '1', wp_get_referer()));
    exit;
}

function firefly_collective_cache_handle_clear_all() {
    check_admin_referer('ffc_clear_cache_all');

    firefly_collective_cache_clear_all();

    wp_redirect(add_query_arg('cache_cleared', 'all', wp_get_referer()));
    exit;
}

function firefly_collective_cache_handle_clear_current() {
    check_admin_referer('ffc_clear_cache_current');

    $template = firefly_collective_get_active_template();
    firefly_collective_cache_delete_template($template);

    wp_redirect(add_query_arg('cache_cleared', $template, wp_get_referer()));
    exit;
}

/**
 * Show admin notice after cache clear
 */
add_action('admin_notices', 'firefly_collective_cache_admin_notices');

function firefly_collective_cache_admin_notices() {
    if (isset($_GET['cache_cleared'])) {
        $message = 'Cache cleared successfully.';
        if ($_GET['cache_cleared'] === 'all') {
            $message = 'Cache cleared for all templates.';
        } elseif ($_GET['cache_cleared'] !== '1') {
            $message = sprintf('Cache cleared for template: %s', esc_html($_GET['cache_cleared']));
        }

        echo '<div class="notice notice-success is-dismissible"><p><strong>Firefly Collective Cache:</strong> ' . $message . '</p></div>';
    }
}
