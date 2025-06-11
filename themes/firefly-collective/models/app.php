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

    // App initialization - returns menu + front page
    function app_init($request) {
        $params = $request->get_params();
        
        // Get menu HTML
        ob_start();
        wp_nav_menu(array(
            'theme_location'  => 'app-menu',
            'container_class' => 'app-menu',
            'fallback_cb'     => false,
        ));
        $menu_html = ob_get_clean();
        
        // Get front page content
        ob_start();
        
        // Get front page
        $front_page_id = get_option( 'page_on_front' ); 
        $front_page_html = get_post_field( 'post_content', $front_page_id );

        return rest_ensure_response([
            'success'           => true,
            'menu_html'         => $menu_html,
            'front_page_html'   => $front_page_html,
            'nonce'             => wp_create_nonce('wp_rest'),
            'gapiDomain'        => 'https://' . GOOGLE_API_DOMAIN
        ]);
    }

    add_action('init', function() {
        // Check if this is a request for the service worker
        $request_uri = $_SERVER['REQUEST_URI'];
        $sw_path = '/wp-content/themes/firefly-collective/service-worker.js';
        
        // Only intercept exact service worker requests
        if (parse_url($request_uri, PHP_URL_PATH) === $sw_path) {
            // Set headers to prevent caching
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Content-Type: application/javascript');
            
            // Read and output the service worker file
            $file_path = get_template_directory() . '/service-worker.js';
            if (file_exists($file_path)) {
                readfile($file_path);
                exit;
            }
        }
    });