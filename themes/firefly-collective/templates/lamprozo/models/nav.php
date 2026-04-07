<?php
/**
 * Lamprozo Template - Navigation
 */

function lamprozo_setup_navigation() {
    global $active_template;

    $menu_id = firefly_create_template_navigation($active_template);

    if ($menu_id) {
        $locations = get_theme_mod('nav_menu_locations', array());
        $locations['website-menu'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);
    }
}
add_action('after_switch_theme', 'lamprozo_setup_navigation', 20);
