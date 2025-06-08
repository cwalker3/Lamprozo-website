<?php

    // theme/models/app.php

    function app_setup_nav() {
        $app_menu_name = 'App Menu';
        $menu_obj  = wp_get_nav_menu_object($app_menu_name);
        if (! $menu_obj) {
            $menu_id  = wp_create_nav_menu($app_menu_name);
            $new_menu = true;
        } else {
            $menu_id  = $menu_obj->term_id;
            $new_menu = false;
        }

        $locations = get_theme_mod('nav_menu_locations', array());
        $locations['app-menu'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);

        $custom_links = array(
            array('title' => 'Dashboard',         'url' => '/app.html'),
            array('title' => 'Log Out',           'url' => '#'),
            array('title' => 'Log In',            'url' => '#'),
        );
        foreach ($custom_links as $link) {
            wp_update_nav_menu_item($menu_id, 0, array(
                'menu-item-title'  => $link['title'],
                'menu-item-url'    => $link['url'],
                'menu-item-status' => 'publish',
                'menu-item-type'   => 'custom',
            ));
        }
    }
    add_action('after_switch_theme', 'app_setup_nav');

    function app_get_menu( $request ) {
        $params = $request->get_params();
        $message = isset( $params['message'] ) ? $params['message'] : '';
        
        // Capture the menu HTML output by using output buffering
        ob_start();
        wp_nav_menu(array(
            'theme_location'  => 'app-menu',
            'container_class' => 'app-menu',
            'fallback_cb'     => false,
        ));
        $menu_html = ob_get_clean();
        
        // Get the outer nav element too (optional - if you want the complete nav)
        $full_nav_html = '<nav style="display: grid;">' . $menu_html . '</nav>';
        
        // Return both the message and the menu HTML
        return rest_ensure_response([
            'success' => true,
            'message' => $message,
            'menu_html' => $menu_html,
            'full_nav_html' => $full_nav_html
        ]);
    }