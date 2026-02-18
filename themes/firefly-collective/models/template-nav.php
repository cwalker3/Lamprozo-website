<?php
/**
 * Template-Scoped Navigation
 *
 * Creates and manages navigation menus per template.
 * Each template has its own menu that includes only its scoped pages.
 */

// =============================================================================
// MENU CREATION
// =============================================================================

/**
 * Create navigation menu for a template from its schema.
 */
function firefly_create_template_navigation($template) {
    $schema = firefly_get_template_schema($template);
    $menu_config = isset($schema['menu']) ? $schema['menu'] : array('name' => 'Main Menu');
    $menu_base_name = isset($menu_config['name']) ? $menu_config['name'] : 'Main Menu';

    // Create unique menu name per template
    $menu_name = "{$menu_base_name} ({$template})";
    $menu_obj = wp_get_nav_menu_object($menu_name);

    if (!$menu_obj) {
        $menu_id = wp_create_nav_menu($menu_name);

        if (is_wp_error($menu_id)) {
            return 0;
        }

        // Set template meta on menu term
        update_term_meta($menu_id, FIREFLY_TEMPLATE_META_KEY, $template);

        // Get pages from schema
        $pages = firefly_get_schema_pages($template);
        $menu_position = 1;

        // Add pages to menu in schema order
        foreach ($pages as $base_slug => $data) {
            // Skip pages not meant for menu
            if (isset($data['in_menu']) && !$data['in_menu']) {
                continue;
            }

            // Skip specific pages that shouldn't be in menu
            $excluded = array('template');
            if (in_array($base_slug, $excluded)) {
                continue;
            }

            $page = firefly_get_scoped_page($base_slug, $template);
            if ($page) {
                wp_update_nav_menu_item($menu_id, 0, array(
                    'menu-item-title'     => $data['title'],
                    'menu-item-object'    => 'page',
                    'menu-item-object-id' => $page->ID,
                    'menu-item-type'      => 'post_type',
                    'menu-item-status'    => 'publish',
                    'menu-item-position'  => isset($data['menu_order']) ? $data['menu_order'] : $menu_position
                ));
                $menu_position++;
            }
        }

        // Add custom links from schema
        $custom_links = isset($menu_config['custom_links']) ? $menu_config['custom_links'] : array();
        foreach ($custom_links as $link) {
            wp_update_nav_menu_item($menu_id, 0, array(
                'menu-item-title'    => $link['title'],
                'menu-item-url'      => $link['url'],
                'menu-item-status'   => 'publish',
                'menu-item-type'     => 'custom',
                'menu-item-position' => $menu_position
            ));
            $menu_position++;
        }
    } else {
        $menu_id = $menu_obj->term_id;

        // Ensure template meta is set even for existing menus
        $existing_template = get_term_meta($menu_id, FIREFLY_TEMPLATE_META_KEY, true);
        if (empty($existing_template)) {
            update_term_meta($menu_id, FIREFLY_TEMPLATE_META_KEY, $template);
        }
    }

    // Store menu ID for this template
    update_option("firefly_menu_{$template}", $menu_id);

    return $menu_id;
}

/**
 * Get the menu ID for the current or specified template.
 */
function firefly_get_template_menu($template = null) {
    if (!$template) {
        $template = firefly_get_scoping_template();
    }
    return get_option("firefly_menu_{$template}", 0);
}

/**
 * Delete the menu for a template.
 */
function firefly_delete_template_menu($template) {
    $menu_id = get_option("firefly_menu_{$template}");

    if ($menu_id) {
        wp_delete_nav_menu($menu_id);
        delete_option("firefly_menu_{$template}");
        return true;
    }

    return false;
}

// =============================================================================
// CUSTOMIZER MENU OVERRIDE
// =============================================================================

/**
 * Dynamically override menu location in customizer iframe to use the temp template's menu.
 * Without this, the iframe always shows the active template's menu.
 */
add_filter('theme_mod_nav_menu_locations', 'firefly_override_menu_in_customizer');

function firefly_override_menu_in_customizer($locations) {
    if (!function_exists('in_customizer_iframe') || !in_customizer_iframe()) {
        return $locations;
    }

    $temp_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
    $menu_id = get_option("firefly_menu_{$temp_template}", 0);

    if ($menu_id) {
        $locations['website-menu'] = $menu_id;
    }

    return $locations;
}

// =============================================================================
// MENU ASSIGNMENT
// =============================================================================

/**
 * Assign a template's menu to the theme location.
 */
function firefly_assign_template_menu($template) {
    $menu_id = firefly_get_template_menu($template);

    if ($menu_id) {
        $locations = get_theme_mod('nav_menu_locations', array());
        $locations['website-menu'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);
        return true;
    }

    return false;
}

// =============================================================================
// MENU SYNC
// =============================================================================

/**
 * Rebuild menu for a template (useful after page changes).
 */
function firefly_rebuild_template_menu($template) {
    // Delete existing menu
    firefly_delete_template_menu($template);

    // Create new menu from schema
    return firefly_create_template_navigation($template);
}

/**
 * Add a page to a template's menu.
 */
function firefly_add_page_to_template_menu($page_id, $template = null) {
    if (!$template) {
        $template = get_post_meta($page_id, FIREFLY_TEMPLATE_META_KEY, true);
    }

    if (!$template) {
        return false;
    }

    $menu_id = firefly_get_template_menu($template);
    if (!$menu_id) {
        return false;
    }

    $page = get_post($page_id);
    if (!$page) {
        return false;
    }

    // Check if already in menu
    $menu_items = wp_get_nav_menu_items($menu_id);
    foreach ($menu_items as $item) {
        if ($item->object_id == $page_id) {
            return true; // Already exists
        }
    }

    // Add to menu
    wp_update_nav_menu_item($menu_id, 0, array(
        'menu-item-title'     => $page->post_title,
        'menu-item-object'    => 'page',
        'menu-item-object-id' => $page_id,
        'menu-item-type'      => 'post_type',
        'menu-item-status'    => 'publish'
    ));

    return true;
}

/**
 * Remove a page from a template's menu.
 */
function firefly_remove_page_from_template_menu($page_id, $template = null) {
    if (!$template) {
        $template = get_post_meta($page_id, FIREFLY_TEMPLATE_META_KEY, true);
    }

    if (!$template) {
        return false;
    }

    $menu_id = firefly_get_template_menu($template);
    if (!$menu_id) {
        return false;
    }

    $menu_items = wp_get_nav_menu_items($menu_id);
    foreach ($menu_items as $item) {
        if ($item->object_id == $page_id) {
            wp_delete_post($item->ID, true);
            return true;
        }
    }

    return false;
}
