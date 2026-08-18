<?php
/**
 * Firefly Projects - Menu Sync Handler
 *
 * Handles packaging and syncing WordPress menu structures to remote sites,
 * with slug-based object resolution for cross-environment compatibility.
 */

// Ensure no direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Package menu for sync
 *
 * @param int $menu_id The menu term ID
 * @return array|WP_Error Package data or error
 */
function firefly_projects_package_menu($menu_id) {
    $menu = wp_get_nav_menu_object($menu_id);

    if (!$menu) {
        return new WP_Error('menu_not_found', 'Menu not found.');
    }

    $items = wp_get_nav_menu_items($menu_id);

    // Get theme locations this menu is assigned to
    $locations = get_nav_menu_locations();
    $assigned_locations = array();
    foreach ($locations as $location => $assigned_menu_id) {
        if ($assigned_menu_id == $menu_id) {
            $assigned_locations[] = $location;
        }
    }

    // Get template meta if available
    $template = get_term_meta($menu_id, '_firefly_template', true);

    $package = array(
        'menu_data' => array(
            'name'        => $menu->name,
            'slug'        => $menu->slug,
            'description' => $menu->description,
            'locations'   => $assigned_locations,
            'template'    => $template ? $template : ''
        ),
        'items' => array()
    );

    // Build a map of item ID to menu_order for parent resolution
    $id_to_order = array();
    if ($items) {
        foreach ($items as $item) {
            $id_to_order[$item->ID] = $item->menu_order;
        }
    }

    if ($items) {
        foreach ($items as $item) {
            // Get the object slug for slug-based resolution on remote
            $object_slug = '';
            if ($item->type === 'post_type' && $item->object_id) {
                $object_slug = get_post_field('post_name', $item->object_id);
            } elseif ($item->type === 'taxonomy' && $item->object_id) {
                $term = get_term($item->object_id, $item->object);
                if ($term && !is_wp_error($term)) {
                    $object_slug = $term->slug;
                }
            }

            // Get parent's menu_order instead of ID (for cross-environment matching)
            $parent_order = 0;
            if ($item->menu_item_parent && isset($id_to_order[$item->menu_item_parent])) {
                $parent_order = $id_to_order[$item->menu_item_parent];
            }

            $package['items'][] = array(
                'title'        => $item->title,
                'url'          => $item->url,
                'object_type'  => $item->type,         // 'post_type', 'taxonomy', 'custom'
                'object'       => $item->object,       // 'page', 'post', 'category', 'custom', etc.
                'object_slug'  => $object_slug,
                'menu_order'   => $item->menu_order,
                'parent_order' => $parent_order,
                'classes'      => is_array($item->classes) ? $item->classes : array(),
                'target'       => $item->target,
                'xfn'          => $item->xfn,
                'description'  => $item->description,
                'attr_title'   => $item->attr_title
            );
        }
    }

    return $package;
}

/**
 * Perform menu sync to remote site
 *
 * @param int $menu_id The menu term ID
 * @param string $target_env Target environment: 'dev' or 'prod'
 * @return array Result array with success status and message
 */
function firefly_projects_perform_menu_sync($menu_id, $target_env = 'dev') {
    // Package the menu
    $package = firefly_projects_package_menu($menu_id);

    if (is_wp_error($package)) {
        return array(
            'success' => false,
            'message' => $package->get_error_message()
        );
    }

    // Get remote endpoint based on target environment
    $project_endpoint = ($target_env === 'prod') ? PROD_ENDPOINT : LIVE_DEV_ENDPOINT;

    // Extract base URL and build menu sync endpoint
    if (preg_match('/(https?:\/\/[^\/]+)/', $project_endpoint, $matches)) {
        $base_url = $matches[1];
        $menu_endpoint = $base_url . '/wp-json/firefly-plugin/v1/receive-menu';
    } else {
        return array(
            'success' => false,
            'message' => 'Could not determine remote endpoint URL.'
        );
    }

    $env_label = ($target_env === 'prod') ? 'Production' : 'Live Dev';

    // Send the request
    $response = wp_remote_post($menu_endpoint, array(
        'headers' => array(
            'Content-Type'     => 'application/json',
            'X-Firefly-Secret' => FIREFLY_SHARED_SECRET
        ),
        'body'    => json_encode($package),
        'timeout' => 60
    ));

    if (is_wp_error($response)) {
        return array(
            'success' => false,
            'message' => 'Connection error: ' . $response->get_error_message()
        );
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if ($http_code === 200 && isset($result['success']) && $result['success']) {
        // Save last sync timestamp per environment
        $sync_time = time();
        $option_key = 'firefly_menu_sync_' . $target_env . '_' . $menu_id;
        update_option($option_key, $sync_time);

        return array(
            'success' => true,
            'message' => 'Menu synced successfully to ' . $env_label . '.',
            'details' => array(
                'menu_name'   => $package['menu_data']['name'],
                'menu_slug'   => $package['menu_data']['slug'],
                'items_count' => count($package['items']),
                'synced_at'   => $sync_time,
                'target_env'  => $target_env
            )
        );
    } else {
        $error_message = isset($result['message']) ? $result['message'] : 'Unknown error occurred.';
        return array(
            'success' => false,
            'message' => 'Sync to ' . $env_label . ' failed: ' . $error_message
        );
    }
}

/**
 * Convert an absolute URL to a relative path if it's an internal link
 *
 * @param string $url The URL to convert
 * @return string Relative path or original URL if external
 */
function firefly_projects_make_url_relative($url) {
    // Skip empty URLs or anchors
    if (empty($url) || strpos($url, '#') === 0) {
        return $url;
    }

    // Parse the URL
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) {
        // Already relative or invalid
        return $url;
    }

    // Get current site's host
    $site_url = parse_url(home_url());
    $site_host = isset($site_url['host']) ? $site_url['host'] : '';

    // Check if this is an internal link (same domain or common WordPress dev domains)
    // We treat any .org/.io/.local/.test variant as internal since we're syncing between environments
    $url_host = $parsed['host'];

    // Extract base domain (remove subdomains like www, dev, local, etc.)
    $url_base = preg_replace('/^(www\.|dev\.|local\.|staging\.)/i', '', $url_host);
    $site_base = preg_replace('/^(www\.|dev\.|local\.|staging\.)/i', '', $site_host);

    // Remove TLD for comparison (fireflycollective.org vs fireflycollective.io)
    $url_name = preg_replace('/\.(org|io|com|net|local|test|dev)$/i', '', $url_base);
    $site_name = preg_replace('/\.(org|io|com|net|local|test|dev)$/i', '', $site_base);

    // If base names match, treat as internal link
    if (strcasecmp($url_name, $site_name) === 0) {
        // Return just the path (and query/fragment if present)
        $relative = isset($parsed['path']) ? $parsed['path'] : '/';
        if (isset($parsed['query'])) {
            $relative .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment'])) {
            $relative .= '#' . $parsed['fragment'];
        }
        return $relative;
    }

    // External link - keep as-is
    return $url;
}

/**
 * Handle incoming menu sync from local site (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request
 * @return array Result array with success status and message
 */
function firefly_projects_handle_incoming_menu($request) {
    $data = $request->get_json_params();

    if (!isset($data['menu_data']) || !isset($data['items'])) {
        return array(
            'success' => false,
            'message' => 'Invalid menu data received.'
        );
    }

    $menu_data = $data['menu_data'];
    $items = $data['items'];

    // Find or create the scoped menu.
    //
    // Menu names are shared across templates (e.g. "Main Menu"), but the theme
    // keeps them distinct with a per-template unique slug + a _firefly_template
    // termmeta (see firefly_create_scoped_menu). So resolve the existing menu by
    // that scoped slug first, then by name + template, and only create if we
    // truly have none. Create via wp_insert_term() with a unique slug rather than
    // wp_create_nav_menu(), which rejects duplicate display names and would fail
    // the moment a second template's "Main Menu" is synced.
    $menu_template = !empty($menu_data['template']) ? $menu_data['template'] : '';

    // Template guard: a menu only travels to environments that have its
    // template installed. Refuse rather than creating a menu no template
    // here can render or manage.
    if ('' !== $menu_template && function_exists('firefly_is_valid_template') && !firefly_is_valid_template($menu_template)) {
        return array(
            'success' => false,
            'message' => "Refused: template '{$menu_template}' is not installed on this environment. "
                       . "Initialize it there before syncing its menu."
        );
    }

    $menu = get_term_by('slug', $menu_data['slug'], 'nav_menu');

    if (!$menu && '' !== $menu_template) {
        // Heal a drifted slug: match by name + template scope.
        $candidates = get_terms(array(
            'taxonomy'   => 'nav_menu',
            'hide_empty' => false,
            'name'       => $menu_data['name'],
            'meta_query' => array(array(
                'key'   => '_firefly_template',
                'value' => $menu_template,
            )),
        ));
        if (!is_wp_error($candidates) && !empty($candidates)) {
            $menu = $candidates[0];
        }
    }

    if (!$menu) {
        // Create with a unique slug so the shared display name can repeat.
        $desired_slug = !empty($menu_data['slug']) ? $menu_data['slug'] : sanitize_title($menu_data['name']);
        $slug = wp_unique_term_slug(
            $desired_slug,
            (object) array('taxonomy' => 'nav_menu', 'parent' => 0)
        );
        $result = wp_insert_term($menu_data['name'], 'nav_menu', array('slug' => $slug));
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => 'Failed to create menu: ' . $result->get_error_message()
            );
        }
        $menu_id = (int) $result['term_id'];
    } else {
        $menu_id = $menu->term_id;

        // Clear existing menu items
        $existing_items = wp_get_nav_menu_items($menu_id);
        if ($existing_items) {
            foreach ($existing_items as $item) {
                wp_delete_post($item->ID, true);
            }
        }
    }

    // Set template meta if provided
    if (!empty($menu_data['template'])) {
        update_term_meta($menu_id, '_firefly_template', $menu_data['template']);
    }

    // Map menu_order to new item ID for parent resolution
    $order_to_id = array();
    $template_warnings = array();
    // $menu_template already resolved above.

    // First pass: create all menu items
    foreach ($items as $item) {
        $item_data = array(
            'menu-item-title'       => $item['title'],
            'menu-item-position'    => $item['menu_order'],
            'menu-item-classes'     => is_array($item['classes']) ? implode(' ', $item['classes']) : $item['classes'],
            'menu-item-target'      => isset($item['target']) ? $item['target'] : '',
            'menu-item-xfn'         => isset($item['xfn']) ? $item['xfn'] : '',
            'menu-item-description' => isset($item['description']) ? $item['description'] : '',
            'menu-item-attr-title'  => isset($item['attr_title']) ? $item['attr_title'] : '',
            'menu-item-status'      => 'publish'
        );

        // Resolve object by slug
        $resolved = false;

        if ($item['object_type'] === 'post_type' && !empty($item['object_slug'])) {
            // Try to find post by slug
            $post = get_page_by_path($item['object_slug'], OBJECT, $item['object']);
            if ($post) {
                // Check if page template matches menu template (warning only, not blocking)
                if ($menu_template) {
                    $page_template = get_post_meta($post->ID, '_firefly_template', true);
                    if ($page_template && $page_template !== $menu_template) {
                        $template_warnings[] = "Page '{$item['object_slug']}' belongs to template '{$page_template}' but menu is for template '{$menu_template}'";
                    }
                }

                $item_data['menu-item-type'] = 'post_type';
                $item_data['menu-item-object'] = $item['object'];
                $item_data['menu-item-object-id'] = $post->ID;
                // Don't set URL - WordPress will generate it automatically from the object
                $resolved = true;
            }
        } elseif ($item['object_type'] === 'taxonomy' && !empty($item['object_slug'])) {
            // Try to find term by slug
            $term = get_term_by('slug', $item['object_slug'], $item['object']);
            if ($term && !is_wp_error($term)) {
                $item_data['menu-item-type'] = 'taxonomy';
                $item_data['menu-item-object'] = $item['object'];
                $item_data['menu-item-object-id'] = $term->term_id;
                // Don't set URL - WordPress will generate it automatically from the object
                $resolved = true;
            }
        } elseif ($item['object_type'] === 'custom') {
            // Custom link - convert to relative path if internal
            $item_data['menu-item-type'] = 'custom';
            $item_data['menu-item-url'] = firefly_projects_make_url_relative($item['url']);
            $resolved = true;
        }

        // Fallback: if not resolved, create as custom link with relative URL
        if (!$resolved) {
            $item_data['menu-item-type'] = 'custom';
            $item_data['menu-item-url'] = firefly_projects_make_url_relative($item['url']);
        }

        // Create menu item
        $new_id = wp_update_nav_menu_item($menu_id, 0, $item_data);

        if (!is_wp_error($new_id)) {
            $order_to_id[$item['menu_order']] = $new_id;
        }
    }

    // Second pass: set parent relationships
    foreach ($items as $item) {
        if (!empty($item['parent_order']) && isset($order_to_id[$item['parent_order']]) && isset($order_to_id[$item['menu_order']])) {
            $child_id = $order_to_id[$item['menu_order']];
            $parent_id = $order_to_id[$item['parent_order']];
            update_post_meta($child_id, '_menu_item_menu_item_parent', $parent_id);
        }
    }

    // Assign menu to theme locations if specified
    if (!empty($menu_data['locations'])) {
        $current_locations = get_nav_menu_locations();
        foreach ($menu_data['locations'] as $location) {
            $current_locations[$location] = $menu_id;
        }
        set_theme_mod('nav_menu_locations', $current_locations);
    }

    $result = array(
        'success' => true,
        'message' => ($menu ? 'Menu updated' : 'Menu created') . ' successfully.',
        'details' => array(
            'menu_id'     => $menu_id,
            'items_count' => count($items),
            'locations'   => isset($menu_data['locations']) ? $menu_data['locations'] : array()
        )
    );

    // Add template warnings if any
    if (!empty($template_warnings)) {
        $result['warnings'] = $template_warnings;
    }

    return $result;
}

/**
 * Import a pulled menu into a local menu (full sync - replaces all items)
 *
 * @param int $local_menu_id The local menu term ID to sync into
 * @param array $data The menu data from remote (menu_data + items)
 * @return array Result array with success status and message
 */
function firefly_projects_import_pulled_menu($local_menu_id, $data) {
    if (!isset($data['menu_data']) || !isset($data['items'])) {
        return array(
            'success' => false,
            'message' => 'Invalid menu data received.'
        );
    }

    $menu_data = $data['menu_data'];
    $items = $data['items'];

    // Verify local menu exists
    $local_menu = wp_get_nav_menu_object($local_menu_id);
    if (!$local_menu) {
        return array(
            'success' => false,
            'message' => 'Local menu not found.'
        );
    }

    // Set template meta if provided
    $menu_template = !empty($menu_data['template']) ? $menu_data['template'] : '';
    if ($menu_template) {
        update_term_meta($local_menu_id, '_firefly_template', $menu_template);
    }

    // Delete ALL existing menu items (full sync)
    $existing_items = wp_get_nav_menu_items($local_menu_id);
    if ($existing_items) {
        foreach ($existing_items as $item) {
            wp_delete_post($item->ID, true);
        }
    }

    // Map menu_order to new item ID for parent resolution
    $order_to_id = array();

    // First pass: create all menu items
    foreach ($items as $item) {
        $item_data = array(
            'menu-item-title'       => $item['title'],
            'menu-item-position'    => $item['menu_order'],
            'menu-item-classes'     => is_array($item['classes']) ? implode(' ', $item['classes']) : $item['classes'],
            'menu-item-target'      => isset($item['target']) ? $item['target'] : '',
            'menu-item-xfn'         => isset($item['xfn']) ? $item['xfn'] : '',
            'menu-item-description' => isset($item['description']) ? $item['description'] : '',
            'menu-item-attr-title'  => isset($item['attr_title']) ? $item['attr_title'] : '',
            'menu-item-status'      => 'publish'
        );

        // Resolve object by slug
        $resolved = false;

        if ($item['object_type'] === 'post_type' && !empty($item['object_slug'])) {
            // Find the post by slug WITHIN the menu's template — the same slug
            // can exist across templates, and an unscoped get_page_by_path
            // would link menu items to whichever sibling template happens to
            // own the slug. Fall back to the unscoped lookup only when the
            // menu carries no template (legacy data).
            $post = null;
            if ($menu_template && function_exists('firefly_projects_find_scoped_page')) {
                $post = firefly_projects_find_scoped_page($item['object_slug'], $menu_template, array($item['object']));
            }
            if (!$post && !$menu_template) {
                $post = get_page_by_path($item['object_slug'], OBJECT, $item['object']);
            }
            if ($post) {
                $item_data['menu-item-type'] = 'post_type';
                $item_data['menu-item-object'] = $item['object'];
                $item_data['menu-item-object-id'] = $post->ID;
                $resolved = true;
            }
        } elseif ($item['object_type'] === 'taxonomy' && !empty($item['object_slug'])) {
            // Try to find term by slug
            $term = get_term_by('slug', $item['object_slug'], $item['object']);
            if ($term && !is_wp_error($term)) {
                $item_data['menu-item-type'] = 'taxonomy';
                $item_data['menu-item-object'] = $item['object'];
                $item_data['menu-item-object-id'] = $term->term_id;
                $resolved = true;
            }
        } elseif ($item['object_type'] === 'custom') {
            // Custom link - convert to relative path if internal
            $item_data['menu-item-type'] = 'custom';
            $item_data['menu-item-url'] = firefly_projects_make_url_relative($item['url']);
            $resolved = true;
        }

        // Fallback: if not resolved, create as custom link with relative URL
        if (!$resolved) {
            $item_data['menu-item-type'] = 'custom';
            $item_data['menu-item-url'] = firefly_projects_make_url_relative($item['url']);
        }

        // Create menu item
        $new_id = wp_update_nav_menu_item($local_menu_id, 0, $item_data);

        if (!is_wp_error($new_id)) {
            $order_to_id[$item['menu_order']] = $new_id;
        }
    }

    // Second pass: set parent relationships
    foreach ($items as $item) {
        if (!empty($item['parent_order']) && isset($order_to_id[$item['parent_order']]) && isset($order_to_id[$item['menu_order']])) {
            $child_id = $order_to_id[$item['menu_order']];
            $parent_id = $order_to_id[$item['parent_order']];
            update_post_meta($child_id, '_menu_item_menu_item_parent', $parent_id);
        }
    }

    // Assign menu to theme locations if specified in the pulled data — but
    // only when the menu belongs to the locally ACTIVE template (or carries
    // no template). Pulling an inactive template's menu must never hijack the
    // live site's nav locations; activation wires locations later.
    $active_template = function_exists('firefly_get_scoping_template') ? firefly_get_scoping_template() : '';
    $is_active_template = (!$menu_template || !$active_template || $menu_template === $active_template);
    if (!empty($menu_data['locations']) && $is_active_template) {
        $current_locations = get_nav_menu_locations();
        foreach ($menu_data['locations'] as $location) {
            $current_locations[$location] = $local_menu_id;
        }
        set_theme_mod('nav_menu_locations', $current_locations);
    }

    return array(
        'success'     => true,
        'message'     => 'Menu items replaced successfully.',
        'items_count' => count($items),
        'locations'   => isset($menu_data['locations']) ? $menu_data['locations'] : array()
    );
}
