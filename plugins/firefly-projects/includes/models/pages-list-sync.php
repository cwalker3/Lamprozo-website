<?php
/**
 * Firefly Projects - Pages List Sync Handler
 *
 * Handles bulk page sync operations from the Pages admin list,
 * including safe sync and mirror sync modes.
 */

// Ensure no direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync all published pages to remote site
 *
 * @param string $sync_mode 'safe' or 'mirror'
 * @param string $target_env 'dev' or 'prod'
 * @return array Results array with counts and errors
 */
function firefly_projects_sync_all_pages_handler($sync_mode, $target_env) {
    // Load the page sync handler
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/page-sync.php';

    // Get all published pages for the active template
    $active_template = firefly_get_scoping_template();
    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'meta_key'       => '_firefly_template',
        'meta_value'     => $active_template,
    ));

    $results = array(
        'total'   => count($pages),
        'synced'  => 0,
        'failed'  => 0,
        'deleted' => 0,
        'errors'  => array()
    );

    $local_page_ids = array();

    // Sync each page
    foreach ($pages as $page) {
        $fpid = get_post_meta($page->ID, '_firefly_page_id', true);
        if (empty($fpid)) {
            // Compute from template + slug if missing
            $tmpl = get_post_meta($page->ID, '_firefly_template', true);
            if (!empty($tmpl)) {
                $fpid = $tmpl . ':' . $page->post_name;
            }
        }
        if ($fpid) {
            $local_page_ids[] = $fpid;
        }
        $result = firefly_projects_perform_page_sync($page, true, $target_env);

        if ($result['success']) {
            $results['synced']++;
        } else {
            $results['failed']++;
            $results['errors'][] = array(
                'title'   => $page->post_title,
                'slug'    => $page->post_name,
                'message' => $result['message']
            );
        }
    }

    // Mirror mode: delete remote pages not on local
    if ($sync_mode === 'mirror') {
        $delete_result = firefly_projects_delete_remote_orphans($local_page_ids, $target_env);
        $results['deleted'] = $delete_result['deleted'];
        if (!empty($delete_result['deleted_pages'])) {
            $results['deleted_pages'] = $delete_result['deleted_pages'];
        }
    }

    return $results;
}

/**
 * Delete remote pages that don't exist locally
 *
 * @param array $local_slugs Array of local page slugs
 * @param string $target_env Target environment
 * @return array Result with deleted count and page list
 */
function firefly_projects_delete_remote_orphans($local_page_ids, $target_env) {
    $result = array(
        'deleted'       => 0,
        'deleted_pages' => array()
    );

    // Get remote endpoint based on target environment
    $endpoint = ($target_env === 'prod') ? PROD_ENDPOINT : LIVE_DEV_ENDPOINT;

    // Extract base URL
    if (!preg_match('/(https?:\/\/[^\/]+)/', $endpoint, $matches)) {
        return $result;
    }
    $base_url = $matches[1];

    // Fetch remote pages list
    $response = wp_remote_get($base_url . '/wp-json/firefly-plugin/v1/list-pages', array(
        'headers' => array('X-Firefly-Secret' => FIREFLY_SHARED_SECRET),
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        return $result;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        return $result;
    }

    $remote_data = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($remote_data['pages']) || !is_array($remote_data['pages'])) {
        return $result;
    }

    // Find orphan pages by _firefly_page_id (remote pages not in local)
    // Only consider remote pages belonging to the active template
    $active_template = firefly_get_scoping_template();
    $remote_page_ids = array();
    foreach ( $remote_data['pages'] as $page ) {
        if ( ! empty( $page['firefly_page_id'] ) && strpos( $page['firefly_page_id'], $active_template . ':' ) === 0 ) {
            $remote_page_ids[] = $page['firefly_page_id'];
        }
    }
    $orphan_page_ids = array_diff( $remote_page_ids, $local_page_ids );

    if (empty($orphan_page_ids)) {
        return $result;
    }

    // Get titles for the pages we're deleting
    $orphan_pages = array();
    foreach ($remote_data['pages'] as $page) {
        if (isset($page['firefly_page_id']) && in_array($page['firefly_page_id'], $orphan_page_ids)) {
            $orphan_pages[] = array(
                'slug'  => $page['slug'],
                'title' => $page['title'],
                'firefly_page_id' => $page['firefly_page_id']
            );
        }
    }

    // Delete orphan pages on remote by _firefly_page_id
    $delete_response = wp_remote_post($base_url . '/wp-json/firefly-plugin/v1/delete-pages', array(
        'headers' => array(
            'Content-Type'     => 'application/json',
            'X-Firefly-Secret' => FIREFLY_SHARED_SECRET
        ),
        'body'    => json_encode(array('firefly_page_ids' => array_values($orphan_page_ids))),
        'timeout' => 60
    ));

    if (is_wp_error($delete_response)) {
        return $result;
    }

    $delete_data = json_decode(wp_remote_retrieve_body($delete_response), true);

    if (isset($delete_data['deleted'])) {
        $result['deleted'] = $delete_data['deleted'];
        $result['deleted_pages'] = $orphan_pages;
    }

    return $result;
}

/**
 * Get remote orphan count (for mirror mode preview)
 *
 * @param string $target_env Target environment
 * @return int Number of pages that would be deleted
 */
function firefly_projects_get_orphan_count($target_env) {
    // Get local page IDs (firefly_page_id meta) for active template
    $active_template = firefly_get_scoping_template();
    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'fields'         => 'ids',
        'meta_key'       => '_firefly_template',
        'meta_value'     => $active_template,
    ));

    $local_page_ids = array();
    foreach ($pages as $page_id) {
        $fpid = get_post_meta($page_id, '_firefly_page_id', true);
        if (empty($fpid)) {
            $tmpl = get_post_meta($page_id, '_firefly_template', true);
            if (!empty($tmpl)) {
                $fpid = $tmpl . ':' . get_post_field('post_name', $page_id);
            }
        }
        if ($fpid) {
            $local_page_ids[] = $fpid;
        }
    }

    // Get remote endpoint
    $endpoint = ($target_env === 'prod') ? PROD_ENDPOINT : LIVE_DEV_ENDPOINT;

    if (!preg_match('/(https?:\/\/[^\/]+)/', $endpoint, $matches)) {
        return 0;
    }
    $base_url = $matches[1];

    // Fetch remote pages
    $response = wp_remote_get($base_url . '/wp-json/firefly-plugin/v1/list-pages', array(
        'headers' => array('X-Firefly-Secret' => FIREFLY_SHARED_SECRET),
        'timeout' => 30
    ));

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return 0;
    }

    $remote_data = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($remote_data['pages'])) {
        return 0;
    }

    $active_template = firefly_get_scoping_template();
    $remote_page_ids = array();
    foreach ( $remote_data['pages'] as $page ) {
        if ( ! empty( $page['firefly_page_id'] ) && strpos( $page['firefly_page_id'], $active_template . ':' ) === 0 ) {
            $remote_page_ids[] = $page['firefly_page_id'];
        }
    }
    $orphan_page_ids = array_diff( $remote_page_ids, $local_page_ids );

    return count($orphan_page_ids);
}

/**
 * List all pages/posts for pull/sync operations (remote endpoint handler)
 * Includes published and draft content with extended metadata
 *
 * @param WP_REST_Request $request
 * @return array
 */
function firefly_projects_list_pages_handler($request) {
    // Check if we should include drafts (for pull operations)
    $include_drafts = $request->get_param('include_drafts') === 'true' || $request->get_param('include_drafts') === '1';

    // Get post type (default to 'page' for backwards compatibility)
    $post_type = $request->get_param('post_type');
    if (!in_array($post_type, array('page', 'post'))) {
        $post_type = 'page';
    }

    $post_statuses = array('publish');
    if ($include_drafts) {
        $post_statuses[] = 'draft';
        $post_statuses[] = 'pending';
    }

    $pages = get_posts(array(
        'post_type'            => $post_type,
        'post_status'          => $post_statuses,
        'numberposts'          => -1,
        'orderby'              => 'title',
        'order'                => 'ASC',
        'firefly_skip_scoping' => true,
    ));

    $list = array();
    foreach ($pages as $page) {
        // Get excerpt or generate one from content
        $excerpt = $page->post_excerpt;
        if (empty($excerpt)) {
            $excerpt = wp_trim_words(strip_tags($page->post_content), 20, '...');
        }

        // Check if page has featured image
        $has_thumbnail = has_post_thumbnail($page->ID);
        $thumbnail_url = $has_thumbnail ? get_the_post_thumbnail_url($page->ID, 'thumbnail') : '';

        $firefly_page_id = get_post_meta($page->ID, '_firefly_page_id', true);

        $list[] = array(
            'id'              => $page->ID,
            'slug'            => $page->post_name,
            'title'           => $page->post_title,
            'status'          => $page->post_status,
            'excerpt'         => $excerpt,
            'modified'        => $page->post_modified,
            'modified_gmt'    => $page->post_modified_gmt,
            'has_thumbnail'   => $has_thumbnail,
            'thumbnail_url'   => $thumbnail_url,
            'parent'          => $page->post_parent,
            'menu_order'      => $page->menu_order,
            'firefly_page_id' => $firefly_page_id
        );
    }

    return array(
        'success'   => true,
        'pages'     => $list,
        'count'     => count($list),
        'post_type' => $post_type
    );
}

/**
 * Delete pages by slug (remote endpoint handler)
 *
 * @param WP_REST_Request $request
 * @return array
 */
function firefly_projects_delete_pages_handler($request) {
    $firefly_page_ids = $request->get_param('firefly_page_ids');
    $slugs = $request->get_param('slugs');

    $deleted = 0;
    $deleted_pages = array();

    // New mode: delete by _firefly_page_id meta
    if (is_array($firefly_page_ids) && !empty($firefly_page_ids)) {
        foreach ($firefly_page_ids as $fpid) {
            $posts = get_posts(array(
                'post_type'            => 'page',
                'post_status'          => 'any',
                'numberposts'          => 1,
                'meta_key'             => '_firefly_page_id',
                'meta_value'           => $fpid,
                'firefly_skip_scoping' => true,
            ));
            if (!empty($posts)) {
                $page = $posts[0];
                $title = $page->post_title;
                if (wp_delete_post($page->ID, true)) {
                    $deleted++;
                    $deleted_pages[] = array(
                        'slug'             => $page->post_name,
                        'title'            => $title,
                        'firefly_page_id'  => $fpid
                    );
                }
            }
        }

        return array(
            'success'       => true,
            'deleted'       => $deleted,
            'deleted_pages' => $deleted_pages
        );
    }

    // Legacy fallback: delete by slug
    if (!is_array($slugs) || empty($slugs)) {
        return array(
            'success' => false,
            'message' => 'No firefly_page_ids or slugs provided.',
            'deleted' => 0
        );
    }

    foreach ($slugs as $slug) {
        $page = get_page_by_path($slug);
        if ($page) {
            $title = $page->post_title;
            $result = wp_delete_post($page->ID, true);
            if ($result) {
                $deleted++;
                $deleted_pages[] = array(
                    'slug'  => $slug,
                    'title' => $title
                );
            }
        }
    }

    return array(
        'success'       => true,
        'deleted'       => $deleted,
        'deleted_pages' => $deleted_pages
    );
}
