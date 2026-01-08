<?php
/**
 * Firefly Analytics - Lightweight privacy-friendly analytics
 * 
 * Tracks page views and blog post visits without cookies or PII.
 * Production only, 90-day retention.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize analytics system
 */
function firefly_analytics_init() {
    firefly_analytics_create_table();
    firefly_analytics_schedule_cleanup();
}
add_action('admin_init', 'firefly_analytics_init');

/**
 * Create analytics table
 */
function firefly_analytics_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_analytics';
    $charset_collate = $wpdb->get_charset_collate();

    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
        return;
    }

    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        page_path VARCHAR(255) NOT NULL,
        page_title VARCHAR(255) DEFAULT NULL,
        post_id BIGINT UNSIGNED DEFAULT NULL,
        post_type VARCHAR(20) DEFAULT NULL,
        referrer VARCHAR(500) DEFAULT NULL,
        session_hash CHAR(32) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_created_at (created_at),
        INDEX idx_page_path (page_path),
        INDEX idx_post_type (post_type),
        INDEX idx_session_date (session_hash, created_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Schedule daily cleanup cron
 */
function firefly_analytics_schedule_cleanup() {
    if (!wp_next_scheduled('firefly_analytics_cleanup_cron')) {
        wp_schedule_event(time(), 'daily', 'firefly_analytics_cleanup_cron');
    }
}
add_action('firefly_analytics_cleanup_cron', 'firefly_analytics_cleanup_old_data');

/**
 * Delete data older than 90 days
 */
function firefly_analytics_cleanup_old_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_analytics';
    
    $wpdb->query(
        "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    );
}

/**
 * Register REST API endpoint for tracking hits
 */
function firefly_analytics_register_rest_routes() {
    register_rest_route('firefly-collective/v1', '/hit', array(
        'methods' => 'POST',
        'callback' => 'firefly_analytics_record_hit',
        'permission_callback' => '__return_true'
    ));

    register_rest_route('firefly-collective/v1', '/analytics', array(
        'methods' => 'GET',
        'callback' => 'firefly_analytics_get_data',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
}
add_action('rest_api_init', 'firefly_analytics_register_rest_routes');

/**
 * Generate session hash from IP + UA + date (no PII stored)
 */
function firefly_analytics_get_session_hash() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $date = date('Y-m-d');
    
    return md5($ip . $ua . $date . wp_salt('auth'));
}

/**
 * Record a page hit
 */
function firefly_analytics_record_hit($request) {
    // Only track on production
    if (defined('FIREFLY_DEV') && FIREFLY_DEV) {
        return new WP_REST_Response(null, 204);
    }
    if (defined('FIREFLY_LIVE_DEV') && FIREFLY_LIVE_DEV) {
        return new WP_REST_Response(null, 204);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_analytics';

    // Parse JSON body
    $body = $request->get_body();
    $data = json_decode($body, true);

    if (!$data || empty($data['p'])) {
        return new WP_REST_Response(null, 204);
    }

    $page_path = sanitize_text_field(substr($data['p'], 0, 255));
    $page_title = isset($data['t']) ? sanitize_text_field(substr($data['t'], 0, 255)) : null;
    $post_id = isset($data['i']) && is_numeric($data['i']) ? absint($data['i']) : null;
    $post_type = isset($data['y']) ? sanitize_text_field(substr($data['y'], 0, 20)) : null;
    $referrer = isset($data['r']) ? sanitize_text_field(substr($data['r'], 0, 500)) : null;
    $session_hash = firefly_analytics_get_session_hash();

    // Skip bots
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|linkedinbot/i', $ua)) {
        return new WP_REST_Response(null, 204);
    }

    // Rate limit: 1 hit per path per session per hour
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name 
         WHERE session_hash = %s 
         AND page_path = %s 
         AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        $session_hash,
        $page_path
    ));

    if ($existing > 0) {
        return new WP_REST_Response(null, 204);
    }

    // Insert hit
    $wpdb->insert(
        $table_name,
        array(
            'page_path' => $page_path,
            'page_title' => $page_title,
            'post_id' => $post_id,
            'post_type' => $post_type ?: null,
            'referrer' => $referrer,
            'session_hash' => $session_hash,
            'created_at' => current_time('mysql')
        ),
        array('%s', '%s', '%d', '%s', '%s', '%s', '%s')
    );

    return new WP_REST_Response(null, 204);
}

/**
 * Get analytics data for dashboard
 */
function firefly_analytics_get_data($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_analytics';

    $days = absint($request->get_param('days') ?: 30);
    $type = sanitize_text_field($request->get_param('type') ?: 'overview');

    switch ($type) {
        case 'overview':
            return firefly_analytics_get_overview($table_name, $days);
        case 'pages':
            return firefly_analytics_get_top_pages($table_name, $days);
        case 'posts':
            return firefly_analytics_get_top_posts($table_name, $days);
        case 'referrers':
            return firefly_analytics_get_referrers($table_name, $days);
        case 'chart':
            return firefly_analytics_get_chart_data($table_name, $days);
        default:
            return new WP_REST_Response(array('error' => 'Invalid type'), 400);
    }
}

/**
 * Get overview stats
 */
function firefly_analytics_get_overview($table_name, $days) {
    global $wpdb;

    // Today
    $today_views = $wpdb->get_var(
        "SELECT COUNT(*) FROM $table_name WHERE DATE(created_at) = CURDATE()"
    );
    $today_unique = $wpdb->get_var(
        "SELECT COUNT(DISTINCT session_hash) FROM $table_name WHERE DATE(created_at) = CURDATE()"
    );

    // Last 7 days
    $week_views = $wpdb->get_var(
        "SELECT COUNT(*) FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $week_unique = $wpdb->get_var(
        "SELECT COUNT(DISTINCT session_hash) FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );

    // Last 30 days
    $month_views = $wpdb->get_var(
        "SELECT COUNT(*) FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $month_unique = $wpdb->get_var(
        "SELECT COUNT(DISTINCT session_hash) FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );

    return new WP_REST_Response(array(
        'today' => array(
            'views' => (int) $today_views,
            'unique' => (int) $today_unique
        ),
        'week' => array(
            'views' => (int) $week_views,
            'unique' => (int) $week_unique
        ),
        'month' => array(
            'views' => (int) $month_views,
            'unique' => (int) $month_unique
        )
    ));
}

/**
 * Get top pages
 */
function firefly_analytics_get_top_pages($table_name, $days) {
    global $wpdb;

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT page_path, page_title, post_type,
                COUNT(*) as views,
                COUNT(DISTINCT session_hash) as unique_visits
         FROM $table_name
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         AND (post_type = 'page' OR post_type = '' OR post_type IS NULL)
         GROUP BY page_path
         ORDER BY views DESC
         LIMIT 50",
        $days
    ), ARRAY_A);

    return new WP_REST_Response($results);
}

/**
 * Get top blog posts
 */
function firefly_analytics_get_top_posts($table_name, $days) {
    global $wpdb;

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT page_path, page_title, post_id,
                COUNT(*) as views,
                COUNT(DISTINCT session_hash) as unique_visits
         FROM $table_name
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         AND post_type = 'post'
         GROUP BY page_path
         ORDER BY views DESC
         LIMIT 50",
        $days
    ), ARRAY_A);

    return new WP_REST_Response($results);
}

/**
 * Get referrers
 */
function firefly_analytics_get_referrers($table_name, $days) {
    global $wpdb;

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            CASE 
                WHEN referrer = '' OR referrer IS NULL THEN 'Direct'
                ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(referrer, 'https://', ''), 'http://', ''), '/', 1), '?', 1)
            END as domain,
            COUNT(*) as visits
         FROM $table_name
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         GROUP BY domain
         ORDER BY visits DESC
         LIMIT 30",
        $days
    ), ARRAY_A);

    return new WP_REST_Response($results);
}

/**
 * Get chart data (daily views for last N days)
 */
function firefly_analytics_get_chart_data($table_name, $days) {
    global $wpdb;

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(created_at) as date,
                COUNT(*) as views,
                COUNT(DISTINCT session_hash) as unique_visits
         FROM $table_name
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         GROUP BY DATE(created_at)
         ORDER BY date ASC",
        $days
    ), ARRAY_A);

    return new WP_REST_Response($results);
}

/**
 * Add admin menu
 */
function firefly_analytics_admin_menu() {
    add_menu_page(
        'Analytics',
        'Analytics',
        'manage_options',
        'firefly-analytics',
        'firefly_analytics_dashboard',
        'dashicons-chart-bar',
        32
    );
}
add_action('admin_menu', 'firefly_analytics_admin_menu');

/**
 * Render dashboard page
 */
function firefly_analytics_dashboard() {
    $view_path = plugin_dir_path(dirname(__FILE__)) . 'views/analytics.php';
    if (file_exists($view_path)) {
        require_once $view_path;
    } else {
        echo '<div class="wrap"><h1>Analytics</h1><p>View file not found.</p></div>';
    }
}
