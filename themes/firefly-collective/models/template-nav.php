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
 * Create a nav_menu scoped to a template, allowing the same display name to be
 * reused across templates.
 *
 * WordPress treats nav_menu names as globally unique, but wp_insert_term permits
 * a duplicate name as long as a unique slug is supplied. We give each template's
 * menu a per-template slug ("{name}-{template}") so two templates can both have,
 * e.g., a menu named "Main Menu" -- the same way scoped pages share a slug. The
 * new menu is tagged with the template via term meta.
 *
 * @param string $name     Menu display name.
 * @param string $template Template slug to scope the menu to.
 * @return int|WP_Error    New menu term ID, or WP_Error on failure.
 */
function firefly_create_scoped_menu($name, $template) {
    $name = trim($name);
    if ('' === $name) {
        return new WP_Error('menu_name_empty', __('Please enter a valid menu name.', 'firefly-collective'));
    }

    // Per-template unique slug keeps the display name free to repeat.
    $slug = wp_unique_term_slug(
        sanitize_title($name . '-' . $template),
        (object) array('taxonomy' => 'nav_menu', 'parent' => 0)
    );

    $result = wp_insert_term($name, 'nav_menu', array('slug' => $slug));
    if (is_wp_error($result)) {
        return $result;
    }

    update_term_meta($result['term_id'], FIREFLY_TEMPLATE_META_KEY, $template);

    return (int) $result['term_id'];
}

/**
 * Resolve the existing menu term ID for a template, if any.
 *
 * Looks up (in order): the stored firefly_menu_{template} option, a nav_menu
 * tagged with this template whose name matches the base name, and finally a
 * legacy "{base_name} ({template})" name match (for menus created before names
 * were shared across templates).
 *
 * @param string $template  Template slug.
 * @param string $base_name Expected menu name (no template suffix).
 * @return int Menu term ID, or 0 if none found.
 */
function firefly_find_template_menu_id($template, $base_name = '') {
    // 1. Canonical stored pointer.
    $menu_id = (int) get_option("firefly_menu_{$template}");
    if ($menu_id && term_exists($menu_id, 'nav_menu')) {
        return $menu_id;
    }

    $menus = get_terms(array('taxonomy' => 'nav_menu', 'hide_empty' => false));
    if (!is_array($menus)) {
        $menus = array();
    }

    // 2. nav_menu tagged with this template whose name matches the base name.
    if ('' !== $base_name) {
        foreach ($menus as $menu) {
            if ($menu->name === $base_name
                && get_term_meta($menu->term_id, FIREFLY_TEMPLATE_META_KEY, true) === $template) {
                // Heal the canonical pointer: it was stale or missing (e.g. a
                // pull/import replaced the menu term without updating it).
                update_option("firefly_menu_{$template}", (int) $menu->term_id);
                return (int) $menu->term_id;
            }
        }
    }

    // 3. Legacy suffixed name ("Main Menu (template)").
    if ('' !== $base_name) {
        $legacy = get_term_by('name', "{$base_name} ({$template})", 'nav_menu');
        if ($legacy && !is_wp_error($legacy)) {
            update_option("firefly_menu_{$template}", (int) $legacy->term_id);
            return (int) $legacy->term_id;
        }
    }

    return 0;
}

/**
 * Create navigation menu for a template from its schema.
 */
function firefly_create_template_navigation($template) {
    $schema = firefly_get_template_schema($template);
    $menu_config = isset($schema['menu']) ? $schema['menu'] : array('name' => 'Main Menu');
    $menu_base_name = isset($menu_config['name']) ? $menu_config['name'] : 'Main Menu';

    // Resolve this template's existing menu so the display name can be shared
    // across templates (e.g. every template's "Main Menu") instead of suffixed.
    $menu_id = firefly_find_template_menu_id($template, $menu_base_name);

    if ($menu_id) {
        // Clear existing menu items so we can rebuild from schema
        $existing_items = wp_get_nav_menu_items($menu_id);
        if ($existing_items) {
            foreach ($existing_items as $item) {
                wp_delete_post($item->ID, true);
            }
        }

        // Normalize a legacy suffixed name to the shared base name, keeping the
        // existing (unique) slug. wp_update_term only enforces slug uniqueness.
        $term = get_term($menu_id, 'nav_menu');
        if ($term && !is_wp_error($term) && $term->name !== $menu_base_name) {
            wp_update_term($menu_id, 'nav_menu', array(
                'name' => $menu_base_name,
                'slug' => $term->slug,
            ));
        }
    } else {
        $menu_id = firefly_create_scoped_menu($menu_base_name, $template);

        if (is_wp_error($menu_id)) {
            return 0;
        }
    }

    // Ensure template meta on menu term
    update_term_meta($menu_id, FIREFLY_TEMPLATE_META_KEY, $template);

    // Populate menu items from schema
    if (isset($menu_config['items']) && is_array($menu_config['items'])) {
        firefly_process_menu_items($menu_config['items'], $menu_id, $template, 0);
    } else {
        // Legacy flat format: build menu from page in_menu/menu_order fields
        $pages = firefly_get_schema_pages($template);
        $menu_position = 1;

        foreach ($pages as $base_slug => $data) {
            if (isset($data['in_menu']) && !$data['in_menu']) {
                continue;
            }

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

        // Add custom links from schema (legacy format)
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
    }

    // Store menu ID for this template
    update_option("firefly_menu_{$template}", $menu_id);

    return $menu_id;
}

/**
 * Recursively create menu items from a hierarchical items array.
 * Used by firefly_create_template_navigation() when schema has menu.items.
 */
function firefly_process_menu_items($items, $menu_id, $template, $parent_id = 0) {
    foreach ($items as $item) {
        $args = array(
            'menu-item-title'     => $item['title'],
            'menu-item-status'    => 'publish',
            'menu-item-position'  => isset($item['menu_order']) ? $item['menu_order'] : 0,
            'menu-item-parent-id' => $parent_id,
        );

        $type = isset($item['type']) ? $item['type'] : 'custom';

        if ($type === 'post_type' && !empty($item['page_slug'])) {
            // Page reference — look up the scoped page
            $page = firefly_get_scoped_page($item['page_slug'], $template);
            if ($page) {
                $args['menu-item-type']      = 'post_type';
                $args['menu-item-object']    = 'page';
                $args['menu-item-object-id'] = $page->ID;
            } else {
                // Page not found — fall back to custom link to slug
                $args['menu-item-type'] = 'custom';
                $args['menu-item-url']  = '/' . $item['page_slug'];
            }
        } else {
            // Custom link
            $args['menu-item-type'] = 'custom';
            $args['menu-item-url']  = isset($item['url']) ? $item['url'] : '#';
        }

        $new_item_id = wp_update_nav_menu_item($menu_id, 0, $args);

        // Recurse into children
        if (!is_wp_error($new_item_id) && !empty($item['children'])) {
            firefly_process_menu_items($item['children'], $menu_id, $template, $new_item_id);
        }
    }
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

    // Resolve robustly rather than trusting the raw option: a pull/import can
    // replace the menu term (new ID) without updating firefly_menu_{template},
    // leaving the option pointing at a deleted term and the overlay empty.
    $base_name = 'Main Menu';
    if (function_exists('firefly_get_template_schema')) {
        $schema = firefly_get_template_schema($temp_template);
        if (!empty($schema['menu']['name'])) {
            $base_name = $schema['menu']['name'];
        }
    }

    $menu_id = firefly_find_template_menu_id($temp_template, $base_name);

    if ($menu_id) {
        $locations['website-menu'] = $menu_id;
    }

    return $locations;
}

/**
 * Self-heal the website-menu location for the ACTIVE template.
 *
 * The stored nav_menu_locations theme mod can drift out from under the active
 * template: a switch that ran with a stale firefly_menu_{template} pointer, a
 * pull/import that replaced the menu term with a new ID, or a menu recreated
 * after the location was saved. Any of those left the header rendering no
 * menu (or another template's menu) until someone reassigned it by hand.
 *
 * Runs on every read of nav_menu_locations (outside the customizer iframe,
 * which the override above handles): if the assigned menu doesn't exist or
 * belongs to a different template, re-resolve the active template's menu by
 * term meta and return the corrected mapping. Read-only heal — the stored
 * theme mod is left alone; find_template_menu_id() already heals the
 * firefly_menu_{template} option as a side effect. Untagged (legacy) menus
 * are left assigned as-is.
 */
add_filter('theme_mod_nav_menu_locations', 'firefly_heal_active_template_menu_location', 20);

function firefly_heal_active_template_menu_location($locations) {
    if (!is_array($locations)) {
        return $locations;
    }

    if (function_exists('in_customizer_iframe') && in_customizer_iframe()) {
        return $locations;
    }

    $template = firefly_get_scoping_template();
    $current  = isset($locations['website-menu']) ? (int) $locations['website-menu'] : 0;

    if ($current && term_exists($current, 'nav_menu')) {
        $owner = get_term_meta($current, FIREFLY_TEMPLATE_META_KEY, true);
        if ('' === $owner || $owner === $template) {
            return $locations;
        }
    }

    $base_name = 'Main Menu';
    if (function_exists('firefly_get_template_schema')) {
        $schema = firefly_get_template_schema($template);
        if (!empty($schema['menu']['name'])) {
            $base_name = $schema['menu']['name'];
        }
    }

    $menu_id = firefly_find_template_menu_id($template, $base_name);
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
