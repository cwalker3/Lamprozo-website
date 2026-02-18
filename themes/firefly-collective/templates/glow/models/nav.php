<?php
/**
 * Glow Template - Navigation Model
 *
 * Uses the schema-driven navigation system for template-scoped menus.
 */

/**
 * Create navigation menu from schema on theme activation.
 */
function custom_theme_setup_navigation() {
    global $active_template;

    // Create template-scoped menu from schema
    $menu_id = firefly_create_template_navigation($active_template);

    // Assign menu to theme location
    if ($menu_id) {
        $locations = get_theme_mod('nav_menu_locations', array());
        $locations['website-menu'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);
    }
}
add_action('after_switch_theme', 'custom_theme_setup_navigation', 20);
