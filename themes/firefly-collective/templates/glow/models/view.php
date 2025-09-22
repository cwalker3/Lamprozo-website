<?php

    // template/models/view.php

    // Parse Request URI Function
    function parse_request_uri() {
        $uri = $_SERVER['REQUEST_URI'];
        $parsed_url = parse_url($uri);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        return explode('/', trim($path, '/'));
    }

    // Get assets for specific views
    function get_view_assets($view, $theme_path, $plugin_path) {
        $assets = array();
        $assets['js'] = array();
        $assets['css'] = array();

        // Get active template for template-specific assets
        $active_template = firefly_collective_get_active_template();
        $template_path = $theme_path . '/templates/' . $active_template;

        switch($view) {
            case '':
                // Front page only
                break;
        }

        return $assets;
    }

    // Determine View Function
    function determine_view() {
        $aCmd = parse_request_uri();

        // Get template path 
        $active_template = firefly_collective_get_active_template();
        $template_path = $theme_path . '/templates/' . $active_template;

        // For single blog posts
        if ( is_single() ) return 'blog-post';
        if ( is_home() ) return 'blog';

        // Check if the first segment exists
        if (isset($aCmd[0])) {
            $view = sanitize_title($aCmd[0]);

            // Valid custom URLs
            $valid_views = array();
            
            if (in_array($view, $valid_views)) {
                // Check if the view file exists
                $view_path = get_template_directory() . $template_path . '/views/' . $view . '.php';
                if (file_exists($view_path)) {
                    return $view;
                }
            }
        }

        return null;
    }

    // Handle Custom Views
    function handle_custom_views() {
        $view = determine_view();
        if ($view) {
            // Set the status header to 200 OK
            status_header(200);
        }
    }
    add_action('template_redirect', 'handle_custom_views');