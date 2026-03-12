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
    firefly_analytics_create_admin_activity_table();
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
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

    if (!$table_exists) {
        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            page_path VARCHAR(255) NOT NULL,
            page_title VARCHAR(255) DEFAULT NULL,
            post_id BIGINT UNSIGNED DEFAULT NULL,
            post_type VARCHAR(20) DEFAULT NULL,
            template VARCHAR(50) DEFAULT NULL,
            referrer VARCHAR(500) DEFAULT NULL,
            session_hash CHAR(32) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_created_at (created_at),
            INDEX idx_page_path (page_path),
            INDEX idx_post_type (post_type),
            INDEX idx_template (template),
            INDEX idx_session_date (session_hash, created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    } else {
        // Add template column if it doesn't exist (migration)
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'template'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN template VARCHAR(50) DEFAULT NULL AFTER post_type");
            $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_template (template)");
        }
    }
}

/**
 * Create admin activity table
 */
function firefly_analytics_create_admin_activity_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_admin_activity';
    $charset_collate = $wpdb->get_charset_collate();

    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

    if (!$table_exists) {
        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            activity_type VARCHAR(30) NOT NULL,
            username VARCHAR(100) DEFAULT NULL,
            session_hash CHAR(32) DEFAULT NULL,
            template VARCHAR(50) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_activity_type (activity_type),
            INDEX idx_created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
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
    $admin_activity_table = $wpdb->prefix . 'ffc_admin_activity';

    $wpdb->query(
        "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    );
    $wpdb->query(
        "DELETE FROM $admin_activity_table WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
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

    register_rest_route('firefly-collective/v1', '/analytics/reset', array(
        'methods' => 'POST',
        'callback' => 'firefly_analytics_reset_data',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));

    register_rest_route('firefly-collective/v1', '/analytics/track-local', array(
        'methods' => 'POST',
        'callback' => 'firefly_analytics_set_track_local',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));

    register_rest_route('firefly-collective/v1', '/analytics/export', array(
        'methods' => 'GET',
        'callback' => 'firefly_analytics_export_data',
        'permission_callback' => '__return_true' // Public endpoint - analytics data is not sensitive
    ));

    register_rest_route('firefly-collective/v1', '/analytics/import', array(
        'methods' => 'POST',
        'callback' => 'firefly_analytics_import_data',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));

    register_rest_route('firefly-collective/v1', '/analytics/pull', array(
        'methods' => 'POST',
        'callback' => 'firefly_analytics_pull_data',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));

    // Admin activity tracking (public, like /hit)
    register_rest_route('firefly-collective/v1', '/admin-activity', array(
        'methods' => 'POST',
        'callback' => 'firefly_analytics_record_admin_activity',
        'permission_callback' => '__return_true'
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
    $template = isset($data['tp']) ? sanitize_text_field(substr($data['tp'], 0, 50)) : null;
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

    // Insert hit (use GMT time to match MySQL CURDATE())
    $wpdb->insert(
        $table_name,
        array(
            'page_path' => $page_path,
            'page_title' => $page_title,
            'post_id' => $post_id,
            'post_type' => $post_type ?: null,
            'template' => $template,
            'referrer' => $referrer,
            'session_hash' => $session_hash,
            'created_at' => current_time('mysql', 1)  // 1 = GMT
        ),
        array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
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

    // Get active template (filter by template)
    $template = function_exists('firefly_collective_get_active_template')
        ? firefly_collective_get_active_template()
        : 'firefly';

    switch ($type) {
        case 'overview':
            return firefly_analytics_get_overview($table_name, $days, $template);
        case 'pages':
            return firefly_analytics_get_top_pages($table_name, $days, $template);
        case 'posts':
            return firefly_analytics_get_top_posts($table_name, $days, $template);
        case 'referrers':
            return firefly_analytics_get_referrers($table_name, $days, $template);
        case 'chart':
            return firefly_analytics_get_chart_data($table_name, $days, $template);
        case 'admin':
            return firefly_analytics_get_admin_activity($days, $template);
        default:
            return new WP_REST_Response(array('error' => 'Invalid type'), 400);
    }
}

/**
 * Get overview stats
 */
function firefly_analytics_get_overview($table_name, $days, $template) {
    global $wpdb;

    // Today (use UTC_DATE since we store GMT timestamps)
    $today_views = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE DATE(created_at) = UTC_DATE() AND template = %s",
        $template
    ));
    $today_unique = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT session_hash) FROM $table_name WHERE DATE(created_at) = UTC_DATE() AND template = %s",
        $template
    ));

    // Last 7 days
    $week_views = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND template = %s",
        $template
    ));
    $week_unique = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT session_hash) FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND template = %s",
        $template
    ));

    // Last 30 days
    $month_views = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND template = %s",
        $template
    ));
    $month_unique = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT session_hash) FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND template = %s",
        $template
    ));

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
function firefly_analytics_get_top_pages($table_name, $days, $template) {
    global $wpdb;

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT page_path, page_title, post_type,
                COUNT(*) as views,
                COUNT(DISTINCT session_hash) as unique_visits
         FROM $table_name
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         AND template = %s
         AND (post_type = 'page' OR post_type = '' OR post_type IS NULL)
         GROUP BY page_path
         ORDER BY views DESC
         LIMIT 50",
        $days,
        $template
    ), ARRAY_A);

    return new WP_REST_Response($results);
}

/**
 * Get top blog posts
 */
function firefly_analytics_get_top_posts($table_name, $days, $template) {
    global $wpdb;

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT page_path, page_title, post_id,
                COUNT(*) as views,
                COUNT(DISTINCT session_hash) as unique_visits
         FROM $table_name
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         AND template = %s
         AND post_type = 'post'
         GROUP BY page_path
         ORDER BY views DESC
         LIMIT 50",
        $days,
        $template
    ), ARRAY_A);

    return new WP_REST_Response($results);
}

/**
 * Get referrers
 */
function firefly_analytics_get_referrers($table_name, $days, $template) {
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
         AND template = %s
         GROUP BY domain
         ORDER BY visits DESC
         LIMIT 30",
        $days,
        $template
    ), ARRAY_A);

    return new WP_REST_Response($results);
}

/**
 * Get chart data (daily views for last N days)
 */
function firefly_analytics_get_chart_data($table_name, $days, $template) {
    global $wpdb;

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(created_at) as date,
                COUNT(*) as views,
                COUNT(DISTINCT session_hash) as unique_visits
         FROM $table_name
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         AND template = %s
         GROUP BY DATE(created_at)
         ORDER BY date ASC",
        $days,
        $template
    ), ARRAY_A);

    return new WP_REST_Response($results);
}

/**
 * Reset all analytics data (truncate table)
 */
function firefly_analytics_reset_data($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_analytics';
    $tracked_links_table = $wpdb->prefix . 'ffc_tracked_links';
    $link_clicks_table = $wpdb->prefix . 'ffc_link_clicks';

    $errors = array();
    $success_count = 0;

    // Truncate analytics table
    $result1 = $wpdb->query("TRUNCATE TABLE $table_name");
    if ($result1 !== false) {
        $success_count++;
    } else {
        $errors[] = 'analytics table';
    }

    // Clear link click data but keep the tracked link registrations
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$link_clicks_table'");
    if ($table_exists) {
        $result3 = $wpdb->query("DELETE FROM $link_clicks_table");
        if ($result3 !== false) {
            $success_count++;
        } else {
            $errors[] = 'link clicks table';
        }
    }

    // Clear admin activity data
    $admin_activity_table = $wpdb->prefix . 'ffc_admin_activity';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$admin_activity_table'");
    if ($table_exists) {
        $result4 = $wpdb->query("TRUNCATE TABLE $admin_activity_table");
        if ($result4 !== false) {
            $success_count++;
        } else {
            $errors[] = 'admin activity table';
        }
    }

    if (empty($errors)) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Analytics data reset successfully (' . $success_count . ' tables cleared)'
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to reset: ' . implode(', ', $errors)
        ), 500);
    }
}

/**
 * Set track on local setting
 */
function firefly_analytics_set_track_local($request) {
    $body = $request->get_body();
    $data = json_decode($body, true);

    $enabled = isset($data['enabled']) ? (bool) $data['enabled'] : false;

    update_option('firefly_analytics_track_local', $enabled);

    return new WP_REST_Response(array(
        'success' => true,
        'enabled' => $enabled
    ), 200);
}

/**
 * Export all analytics data as JSON (with rate limiting)
 */
function firefly_analytics_export_data($request) {
    // Skip rate limiting and cache for authenticated users (internal pulls)
    $is_internal = current_user_can('manage_options') || $request->get_param('bypass_cache') === 'true';

    if (!$is_internal) {
        // Rate limiting: 10 requests per hour per IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rate_limit_key = 'analytics_export_' . md5($ip);
        $requests = get_transient($rate_limit_key) ?: 0;

        if ($requests >= 10) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Rate limit exceeded. Please try again later.'
            ), 429);
        }

        // Increment request counter
        set_transient($rate_limit_key, $requests + 1, HOUR_IN_SECONDS);

        // Check cache first (cache for 5 minutes)
        $cache_key = 'analytics_export_cache';
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            return new WP_REST_Response($cached_data, 200);
        }
    }

    // Fetch fresh data
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_analytics';

    $results = $wpdb->get_results(
        "SELECT * FROM $table_name ORDER BY created_at DESC",
        ARRAY_A
    );

    // Also export tracked links and link clicks
    $tracked_links_table = $wpdb->prefix . 'ffc_tracked_links';
    $link_clicks_table = $wpdb->prefix . 'ffc_link_clicks';
    $posts_table = $wpdb->prefix . 'posts';

    // Export tracked links with post_name (slug) for cross-environment matching
    $tracked_links = $wpdb->get_results(
        "SELECT tl.*, p.post_name as post_slug FROM $tracked_links_table tl
         INNER JOIN $posts_table p ON tl.post_id = p.ID
         WHERE p.post_status IN ('publish', 'private')
         AND p.post_type IN ('post', 'page')
         ORDER BY tl.created_at DESC",
        ARRAY_A
    );

    $link_clicks = $wpdb->get_results(
        "SELECT * FROM $link_clicks_table ORDER BY clicked_at DESC",
        ARRAY_A
    );

    // Export admin activity data
    $admin_activity_table = $wpdb->prefix . 'ffc_admin_activity';
    $admin_activity = array();
    if ($wpdb->get_var("SHOW TABLES LIKE '$admin_activity_table'") === $admin_activity_table) {
        $admin_activity = $wpdb->get_results(
            "SELECT * FROM $admin_activity_table ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    $response_data = array(
        'success' => true,
        'count' => count($results),
        'data' => $results,
        'tracked_links' => $tracked_links,
        'link_clicks' => $link_clicks,
        'admin_activity' => $admin_activity
    );

    // Cache the response for 5 minutes (only for public requests)
    if (!$is_internal) {
        set_transient($cache_key, $response_data, 5 * MINUTE_IN_SECONDS);
    }

    return new WP_REST_Response($response_data, 200);
}

/**
 * Import analytics data from JSON (local only)
 */
function firefly_analytics_import_data($request) {
    // Only allow on local
    if (!defined('FIREFLY_DEV') || !FIREFLY_DEV) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Import only allowed on local environment'
        ), 403);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_analytics';

    $body = $request->get_body();
    $payload = json_decode($body, true);

    if (!isset($payload['data']) || !is_array($payload['data'])) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid data format'
        ), 400);
    }

    $data = $payload['data'];

    // Truncate existing data
    $wpdb->query("TRUNCATE TABLE $table_name");

    // Insert imported data
    $inserted = 0;
    foreach ($data as $row) {
        $result = $wpdb->insert(
            $table_name,
            array(
                'page_path' => $row['page_path'],
                'page_title' => $row['page_title'] ?? null,
                'post_id' => $row['post_id'] ?? null,
                'post_type' => $row['post_type'] ?? null,
                'template' => $row['template'] ?? null,
                'referrer' => $row['referrer'] ?? null,
                'session_hash' => $row['session_hash'] ?? null,
                'created_at' => $row['created_at']
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result) {
            $inserted++;
        }
    }

    return new WP_REST_Response(array(
        'success' => true,
        'message' => "Imported $inserted records",
        'imported' => $inserted
    ), 200);
}

/**
 * Pull analytics data from remote source (local only, server-side)
 */
function firefly_analytics_pull_data($request) {
    // Only allow on local
    if (!defined('FIREFLY_DEV') || !FIREFLY_DEV) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Pull only allowed on local environment'
        ), 403);
    }

    $body = $request->get_body();
    $payload = json_decode($body, true);

    if (!isset($payload['source'])) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Source URL required'
        ), 400);
    }

    $source_url = esc_url_raw($payload['source']);
    $export_url = trailingslashit($source_url) . 'wp-json/firefly-collective/v1/analytics/export?bypass_cache=true';

    // Fetch data from remote server-side
    $response = wp_remote_get($export_url, array(
        'timeout' => 30,
        'sslverify' => false // For dev environments
    ));

    if (is_wp_error($response)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to fetch from remote: ' . $response->get_error_message()
        ), 500);
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if ($response_code !== 200) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Remote returned status ' . $response_code . ': ' . substr($response_body, 0, 100)
        ), 500);
    }

    $data = json_decode($response_body, true);

    if (!$data || !isset($data['success']) || !$data['success']) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid response from remote: ' . substr($response_body, 0, 200)
        ), 500);
    }

    // Import the data
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_analytics';
    $tracked_links_table = $wpdb->prefix . 'ffc_tracked_links';
    $link_clicks_table = $wpdb->prefix . 'ffc_link_clicks';

    // Clear existing analytics and click data, but keep tracked link registrations
    $wpdb->query("TRUNCATE TABLE $table_name");
    $wpdb->query("DELETE FROM $link_clicks_table");

    // Insert imported analytics data
    $inserted = 0;
    foreach ($data['data'] as $row) {
        $result = $wpdb->insert(
            $table_name,
            array(
                'page_path' => $row['page_path'],
                'page_title' => $row['page_title'] ?? null,
                'post_id' => $row['post_id'] ?? null,
                'post_type' => $row['post_type'] ?? null,
                'template' => $row['template'] ?? null,
                'referrer' => $row['referrer'] ?? null,
                'session_hash' => $row['session_hash'] ?? null,
                'created_at' => $row['created_at']
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result) {
            $inserted++;
        }
    }

    // Match remote tracked links to local ones by post slug + link URL
    $links_matched = 0;
    $old_id_to_new_id = array(); // Map remote tracked_link ID to local tracked_link ID

    if (!empty($data['tracked_links'])) {
        foreach ($data['tracked_links'] as $link) {
            $old_id = $link['id'];
            $post_slug = $link['post_slug'] ?? null;

            if (!$post_slug) {
                continue;
            }

            // Find the local post by slug
            $local_post = $wpdb->get_row($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status IN ('publish', 'private') LIMIT 1",
                $post_slug
            ));

            if (!$local_post) {
                continue;
            }

            // Regenerate link_hash with local post_id
            $local_link_hash = md5($link['link_url'] . $local_post->ID);

            // Find the existing local tracked link
            $local_link = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $tracked_links_table WHERE post_id = %d AND link_hash = %s",
                $local_post->ID,
                $local_link_hash
            ));

            if ($local_link) {
                $old_id_to_new_id[$old_id] = $local_link->id;
                $links_matched++;
            }
        }
    }

    // Insert link clicks using mapped link_id (FK to tracked_links.id)
    $clicks_inserted = 0;

    if (!empty($data['link_clicks'])) {
        foreach ($data['link_clicks'] as $click) {
            // Get the new local link_id using our mapping
            $old_link_id = $click['link_id'];
            if (!isset($old_id_to_new_id[$old_link_id])) {
                continue;
            }

            $new_link_id = $old_id_to_new_id[$old_link_id];

            $result = $wpdb->insert(
                $link_clicks_table,
                array(
                    'link_id' => $new_link_id,
                    'session_hash' => $click['session_hash'],
                    'referrer' => $click['referrer'] ?? null,
                    'clicked_at' => $click['clicked_at']
                ),
                array('%d', '%s', '%s', '%s')
            );

            if ($result) {
                $clicks_inserted++;
            }
        }
    }

    // Import admin activity data
    $admin_activity_table = $wpdb->prefix . 'ffc_admin_activity';
    $admin_imported = 0;

    if (!empty($data['admin_activity'])) {
        // Clear existing admin activity
        $wpdb->query("TRUNCATE TABLE $admin_activity_table");

        foreach ($data['admin_activity'] as $row) {
            $result = $wpdb->insert(
                $admin_activity_table,
                array(
                    'activity_type' => $row['activity_type'],
                    'username' => $row['username'] ?? null,
                    'session_hash' => $row['session_hash'] ?? null,
                    'template' => $row['template'] ?? null,
                    'created_at' => $row['created_at']
                ),
                array('%s', '%s', '%s', '%s', '%s')
            );

            if ($result) {
                $admin_imported++;
            }
        }
    }

    return new WP_REST_Response(array(
        'success' => true,
        'message' => "Pulled and imported $inserted analytics records, matched $links_matched tracked links, $clicks_inserted link clicks, and $admin_imported admin activity records from remote",
        'imported' => array(
            'analytics' => $inserted,
            'tracked_links_matched' => $links_matched,
            'link_clicks' => $clicks_inserted,
            'admin_activity' => $admin_imported
        )
    ), 200);
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

/**
 * Record admin activity (login page views)
 */
function firefly_analytics_record_admin_activity($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_admin_activity';

    $body = $request->get_body();
    $data = json_decode($body, true);

    if (!$data || empty($data['type'])) {
        return new WP_REST_Response(null, 204);
    }

    $activity_type = sanitize_text_field($data['type']);
    if (!in_array($activity_type, array('login_page_view'), true)) {
        return new WP_REST_Response(null, 204);
    }

    // Skip bots
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|linkedinbot/i', $ua)) {
        return new WP_REST_Response(null, 204);
    }

    $session_hash = firefly_analytics_get_session_hash();
    $template = isset($data['tp']) ? sanitize_text_field($data['tp']) : null;

    // Rate limit: 1 per session per hour
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name
         WHERE session_hash = %s
         AND activity_type = %s
         AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        $session_hash,
        $activity_type
    ));

    if ($existing > 0) {
        return new WP_REST_Response(null, 204);
    }

    $wpdb->insert(
        $table_name,
        array(
            'activity_type' => $activity_type,
            'session_hash' => $session_hash,
            'template' => $template,
            'created_at' => current_time('mysql', 1)
        ),
        array('%s', '%s', '%s', '%s')
    );

    return new WP_REST_Response(null, 204);
}

/**
 * Record successful login
 */
function firefly_analytics_record_login($user_login, $user) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_admin_activity';

    $template = function_exists('firefly_collective_get_active_template')
        ? firefly_collective_get_active_template()
        : 'firefly';

    $wpdb->insert(
        $table_name,
        array(
            'activity_type' => 'login_success',
            'username' => sanitize_text_field($user_login),
            'session_hash' => firefly_analytics_get_session_hash(),
            'template' => $template,
            'created_at' => current_time('mysql', 1)
        ),
        array('%s', '%s', '%s', '%s', '%s')
    );
}
add_action('wp_login', 'firefly_analytics_record_login', 10, 2);

/**
 * Get admin activity data for dashboard
 */
function firefly_analytics_get_admin_activity($days, $template) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_admin_activity';

    // Login page views summary
    $views = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name
         WHERE activity_type = 'login_page_view'
         AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ));

    $unique_views = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT session_hash) FROM $table_name
         WHERE activity_type = 'login_page_view'
         AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ));

    // Successful logins
    $logins = $wpdb->get_results($wpdb->prepare(
        "SELECT username, created_at FROM $table_name
         WHERE activity_type = 'login_success'
         AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         ORDER BY created_at DESC
         LIMIT 100",
        $days
    ), ARRAY_A);

    return new WP_REST_Response(array(
        'login_views' => (int) $views,
        'login_unique' => (int) $unique_views,
        'logins' => $logins
    ));
}
