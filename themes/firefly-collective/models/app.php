<?php

    // theme/models/app.php

    function app_get_menu( $request ) {
        $params = $request->get_params();
        $message = isset( $params['message'] ) ? $params['message'] : '';
        
        // Capture the menu HTML output by using output buffering
        ob_start();
        wp_nav_menu(array(
            'theme_location'  => 'website-menu',
            'container_class' => 'website-menu',
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