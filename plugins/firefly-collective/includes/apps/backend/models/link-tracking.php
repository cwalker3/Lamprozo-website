<?php
/**
 * Firefly Link Click Tracking
 *
 * Tracks clicks on specific links within blog posts and pages.
 * Links must be explicitly enabled for tracking via Gutenberg UI.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize link tracking system
 */
function firefly_link_tracking_init() {
    firefly_link_tracking_create_tables();
}
add_action('admin_init', 'firefly_link_tracking_init');

/**
 * Add tracking attributes to links in post content
 */
function firefly_link_tracking_add_attributes($content) {
    // Only process on single post/page views
    if (!is_singular()) {
        return $content;
    }

    global $post, $wpdb;

    if (!$post) {
        return $content;
    }

    // Get all active tracked links for this post
    $links_table = $wpdb->prefix . 'ffc_tracked_links';
    $tracked_links = $wpdb->get_results($wpdb->prepare(
        "SELECT link_url, link_hash FROM $links_table WHERE post_id = %d AND is_active = 1",
        $post->ID
    ), OBJECT_K);

    // If no tracked links, return original content
    if (empty($tracked_links)) {
        return $content;
    }

    // Parse HTML
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    // Find all links
    $links = $dom->getElementsByTagName('a');

    foreach ($links as $link) {
        $href = $link->getAttribute('href');

        // Check if this link is being tracked
        if (isset($tracked_links[$href])) {
            $link->setAttribute('data-track-clicks', 'true');
            $link->setAttribute('data-link-hash', $tracked_links[$href]->link_hash);
        }
    }

    // Save the modified HTML
    $content = $dom->saveHTML();

    // Remove XML encoding tag
    $content = str_replace('<?xml encoding="UTF-8">', '', $content);

    return $content;
}
add_filter('the_content', 'firefly_link_tracking_add_attributes', 999);

/**
 * Enqueue Gutenberg link tracking assets
 */
function firefly_link_tracking_enqueue_editor_assets() {
    $asset_file = plugin_dir_path(__FILE__) . '../assets/js/gutenberg-link-tracking.js';

    wp_enqueue_script(
        'firefly-gutenberg-link-tracking',
        plugins_url('../assets/js/gutenberg-link-tracking.js', __FILE__),
        array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-rich-text', 'wp-block-editor', 'wp-api-fetch', 'wp-hooks', 'wp-i18n', 'wp-compose'),
        filemtime($asset_file),
        true
    );

    // Add inline styles for the tracking button
    wp_add_inline_style('wp-edit-blocks', '
        .firefly-track-link-button.is-pressed {
            background-color: #2271b1;
            color: white;
        }
        .firefly-tracked-links-list ul {
            margin-top: 10px;
        }
    ');
}
add_action('enqueue_block_editor_assets', 'firefly_link_tracking_enqueue_editor_assets');

/**
 * Enqueue frontend link tracking script
 */
function firefly_link_tracking_enqueue_frontend_assets() {
    if (is_admin()) return;

    // Get the post ID for tracking
    global $post;
    if (!$post) return;

    wp_add_inline_script('jquery', "
        (function() {
            document.addEventListener('click', function(e) {
                const link = e.target.closest('a[data-track-clicks=\"true\"]');
                if (!link) return;

                const linkHash = link.getAttribute('data-link-hash');
                const postId = " . absint($post->ID) . ";
                const referrer = document.referrer || '';

                if (!linkHash) return;

                // Use sendBeacon for reliable tracking (works even if user navigates away)
                const endpoint = '" . rest_url('firefly-collective/v1/link-click') . "';
                const data = JSON.stringify({
                    h: linkHash,
                    p: postId,
                    r: referrer
                });

                navigator.sendBeacon(endpoint, new Blob([data], { type: 'application/json' }));
            });
        })();
    ");
}
add_action('wp_enqueue_scripts', 'firefly_link_tracking_enqueue_frontend_assets');

/**
 * Create link tracking tables
 */
function firefly_link_tracking_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Table for tracked links metadata
    $links_table = $wpdb->prefix . 'ffc_tracked_links';
    $links_exists = $wpdb->get_var("SHOW TABLES LIKE '$links_table'") === $links_table;

    if (!$links_exists) {
        $sql = "CREATE TABLE $links_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            link_url VARCHAR(2048) NOT NULL,
            link_text VARCHAR(500) DEFAULT NULL,
            link_hash CHAR(32) NOT NULL,
            template VARCHAR(50) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_link (post_id, link_hash),
            INDEX idx_post_id (post_id),
            INDEX idx_template (template),
            INDEX idx_active (is_active)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Table for link click events
    $clicks_table = $wpdb->prefix . 'ffc_link_clicks';
    $clicks_exists = $wpdb->get_var("SHOW TABLES LIKE '$clicks_table'") === $clicks_table;

    if (!$clicks_exists) {
        $sql = "CREATE TABLE $clicks_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            link_id BIGINT UNSIGNED NOT NULL,
            session_hash CHAR(32) DEFAULT NULL,
            referrer VARCHAR(500) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_link_id (link_id),
            INDEX idx_clicked_at (clicked_at),
            INDEX idx_session_date (session_hash, clicked_at),
            FOREIGN KEY (link_id) REFERENCES {$links_table}(id) ON DELETE CASCADE
        ) $charset_collate;";

        dbDelta($sql);
    }
}

/**
 * Register REST API endpoints
 */
function firefly_link_tracking_register_rest_routes() {
    // Record a link click
    register_rest_route('firefly-collective/v1', '/link-click', array(
        'methods' => 'POST',
        'callback' => 'firefly_link_tracking_record_click',
        'permission_callback' => '__return_true'
    ));

    // Enable/disable tracking for a link (Gutenberg)
    register_rest_route('firefly-collective/v1', '/track-link', array(
        'methods' => 'POST',
        'callback' => 'firefly_link_tracking_toggle',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        }
    ));

    // Get tracked links for analytics dashboard
    register_rest_route('firefly-collective/v1', '/tracked-links', array(
        'methods' => 'GET',
        'callback' => 'firefly_link_tracking_get_data',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));

    // Get tracked links for a specific post (Gutenberg editor)
    register_rest_route('firefly-collective/v1', '/post-tracked-links/(?P<post_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'firefly_link_tracking_get_post_links',
        'permission_callback' => function($request) {
            return current_user_can('edit_post', $request['post_id']);
        }
    ));

    // Delete most recent click for a link
    register_rest_route('firefly-collective/v1', '/delete-click/(?P<link_id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'firefly_link_tracking_delete_recent_click',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
}
add_action('rest_api_init', 'firefly_link_tracking_register_rest_routes');

/**
 * Record a link click
 */
function firefly_link_tracking_record_click($request) {
    global $wpdb;

    // sendBeacon sends data as Blob, need to read raw input
    $body = $request->get_json_params();
    if (empty($body)) {
        // Try reading from raw input stream (for sendBeacon)
        $raw_body = file_get_contents('php://input');
        $body = json_decode($raw_body, true);
    }

    $link_hash = sanitize_text_field($body['h'] ?? '');
    $post_id = absint($body['p'] ?? 0);
    $referrer = sanitize_text_field($body['r'] ?? '');

    if (!$link_hash || !$post_id) {
        return new WP_REST_Response(['error' => 'Missing required fields'], 400);
    }

    // Check if link exists and is active
    $links_table = $wpdb->prefix . 'ffc_tracked_links';

    $link = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $links_table WHERE post_id = %d AND link_hash = %s AND is_active = 1",
        $post_id,
        $link_hash
    ));

    if (!$link) {
        return new WP_REST_Response(['error' => 'Link not tracked or inactive'], 404);
    }

    // Generate session hash (same method as analytics)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $date = gmdate('Y-m-d');
    $session_hash = md5($ip . $ua . $date);

    // Rate limiting: 1 click per link per session per hour
    $clicks_table = $wpdb->prefix . 'ffc_link_clicks';

    $recent_click = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $clicks_table
         WHERE link_id = %d
         AND session_hash = %s
         AND clicked_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
         LIMIT 1",
        $link->id,
        $session_hash
    ));

    if ($recent_click) {
        return new WP_REST_Response(['ok' => true, 'duplicate' => true], 200);
    }

    // Record the click

    $result = $wpdb->insert(
        $clicks_table,
        array(
            'link_id' => $link->id,
            'session_hash' => $session_hash,
            'referrer' => $referrer,
            'user_agent' => substr($ua, 0, 500)
        ),
        array('%d', '%s', '%s', '%s')
    );

    if ($result === false) {
        return new WP_REST_Response(['error' => 'Failed to record click'], 500);
    }

    return new WP_REST_Response(['ok' => true], 200);
}

/**
 * Delete the most recent click for a link
 */
function firefly_link_tracking_delete_recent_click($request) {
    global $wpdb;

    $link_id = absint($request['link_id']);

    if (!$link_id) {
        return new WP_REST_Response(['error' => 'Missing link_id'], 400);
    }

    $clicks_table = $wpdb->prefix . 'ffc_link_clicks';

    // Find the most recent click for this link
    $recent_click = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $clicks_table WHERE link_id = %d ORDER BY clicked_at DESC LIMIT 1",
        $link_id
    ));

    if (!$recent_click) {
        return new WP_REST_Response(['error' => 'No clicks found for this link'], 404);
    }

    // Delete the click
    $result = $wpdb->delete(
        $clicks_table,
        array('id' => $recent_click->id),
        array('%d')
    );

    if ($result === false) {
        return new WP_REST_Response(['error' => 'Failed to delete click'], 500);
    }

    // Get updated click counts
    $total_clicks = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $clicks_table WHERE link_id = %d",
        $link_id
    ));

    $unique_clicks = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT session_hash) FROM $clicks_table WHERE link_id = %d",
        $link_id
    ));

    return new WP_REST_Response([
        'success' => true,
        'total_clicks' => (int)$total_clicks,
        'unique_clicks' => (int)$unique_clicks
    ], 200);
}

/**
 * Toggle link tracking (enable/disable from Gutenberg)
 */
function firefly_link_tracking_toggle($request) {
    global $wpdb;

    $body = $request->get_json_params();

    $post_id = absint($body['post_id'] ?? 0);
    $link_url = esc_url_raw($body['link_url'] ?? '');
    $link_text = sanitize_text_field($body['link_text'] ?? '');
    $is_active = !empty($body['is_active']);

    if (!$post_id || !$link_url) {
        return new WP_REST_Response(['error' => 'Missing required fields'], 400);
    }

    // Generate link hash (URL + post_id ensures uniqueness per post)
    $link_hash = md5($link_url . $post_id);

    // Get template for this post
    $template = get_post_meta($post_id, '_firefly_template', true) ?: 'firefly';

    $links_table = $wpdb->prefix . 'ffc_tracked_links';

    // Check if link already exists
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, is_active FROM $links_table WHERE post_id = %d AND link_hash = %s",
        $post_id,
        $link_hash
    ));

    if ($existing) {
        // Update existing link
        $wpdb->update(
            $links_table,
            array(
                'link_text' => $link_text,
                'is_active' => $is_active ? 1 : 0
            ),
            array('id' => $existing->id),
            array('%s', '%d'),
            array('%d')
        );

        return new WP_REST_Response([
            'ok' => true,
            'link_id' => $existing->id,
            'link_hash' => $link_hash,
            'action' => 'updated'
        ], 200);
    } else {
        // Create new tracked link
        $wpdb->insert(
            $links_table,
            array(
                'post_id' => $post_id,
                'link_url' => $link_url,
                'link_text' => $link_text,
                'link_hash' => $link_hash,
                'template' => $template,
                'is_active' => $is_active ? 1 : 0
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d')
        );

        return new WP_REST_Response([
            'ok' => true,
            'link_id' => $wpdb->insert_id,
            'link_hash' => $link_hash,
            'action' => 'created'
        ], 200);
    }
}

/**
 * Get tracked links data for analytics dashboard
 */
function firefly_link_tracking_get_data($request) {
    global $wpdb;

    $days = absint($request->get_param('days') ?: 30);
    $template = sanitize_text_field($request->get_param('template') ?: '');

    $links_table = $wpdb->prefix . 'ffc_tracked_links';
    $clicks_table = $wpdb->prefix . 'ffc_link_clicks';

    // Build WHERE clause for template filter
    $where_template = '';
    if ($template) {
        $where_template = $wpdb->prepare(' AND l.template = %s', $template);
    }

    // Get links with click counts
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT
            l.id,
            l.post_id,
            l.link_url,
            l.link_text,
            l.template,
            l.is_active,
            p.post_title,
            p.post_type,
            COUNT(c.id) as total_clicks,
            COUNT(DISTINCT c.session_hash) as unique_clicks,
            MAX(c.clicked_at) as last_click
         FROM $links_table l
         LEFT JOIN {$wpdb->posts} p ON l.post_id = p.ID
         LEFT JOIN $clicks_table c ON l.id = c.link_id
            AND c.clicked_at > DATE_SUB(NOW(), INTERVAL %d DAY)
         WHERE l.is_active = 1 $where_template
         GROUP BY l.id
         ORDER BY total_clicks DESC, l.created_at DESC
         LIMIT 100",
        $days
    ));

    return new WP_REST_Response($results, 200);
}

/**
 * Get tracked links for a specific post (used in Gutenberg editor)
 */
function firefly_link_tracking_get_post_links($request) {
    global $wpdb;

    $post_id = absint($request['post_id']);

    $links_table = $wpdb->prefix . 'ffc_tracked_links';

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT id, link_url, link_hash, is_active
         FROM $links_table
         WHERE post_id = %d",
        $post_id
    ), OBJECT_K); // Use link_hash as array key

    // Convert to hash-indexed array for easy lookup in Gutenberg
    $indexed = array();
    foreach ($results as $link) {
        $indexed[$link->link_hash] = array(
            'id' => $link->id,
            'url' => $link->link_url,
            'is_active' => (bool)$link->is_active
        );
    }

    return new WP_REST_Response($indexed, 200);
}

/**
 * Get tracked links for sync (used when pushing to Live Dev/Production)
 */
function firefly_link_tracking_get_post_links_for_sync($post_id) {
    global $wpdb;

    $links_table = $wpdb->prefix . 'ffc_tracked_links';

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT link_url, link_text, link_hash, template, is_active
         FROM $links_table
         WHERE post_id = %d",
        $post_id
    ), ARRAY_A);

    return $results;
}

/**
 * Sync incoming tracked links from remote site
 */
function firefly_link_tracking_sync_incoming_links($post_id, $tracked_links) {
    global $wpdb;

    if (empty($tracked_links) || !is_array($tracked_links)) {
        return;
    }

    $links_table = $wpdb->prefix . 'ffc_tracked_links';

    // Get template for this post
    $template = get_post_meta($post_id, '_firefly_template', true) ?: 'firefly';

    foreach ($tracked_links as $link) {
        // Regenerate hash with the remote post_id (since post IDs differ across environments)
        $link_hash = md5($link['link_url'] . $post_id);

        // Check if link already exists (by URL and post_id, not by hash from source)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $links_table WHERE post_id = %d AND link_url = %s",
            $post_id,
            $link['link_url']
        ));

        if ($existing) {
            // Update existing link
            $update_result = $wpdb->update(
                $links_table,
                array(
                    'link_url' => $link['link_url'],
                    'link_text' => $link['link_text'],
                    'link_hash' => $link_hash,
                    'template' => isset($link['template']) ? $link['template'] : $template,
                    'is_active' => isset($link['is_active']) ? $link['is_active'] : 1
                ),
                array('id' => $existing->id),
                array('%s', '%s', '%s', '%s', '%d'),
                array('%d')
            );
        } else {
            // Create new tracked link
            $insert_result = $wpdb->insert(
                $links_table,
                array(
                    'post_id' => $post_id,
                    'link_url' => $link['link_url'],
                    'link_text' => $link['link_text'],
                    'link_hash' => $link_hash,
                    'template' => isset($link['template']) ? $link['template'] : $template,
                    'is_active' => isset($link['is_active']) ? $link['is_active'] : 1
                ),
                array('%d', '%s', '%s', '%s', '%s', '%d')
            );
        }
    }
}
