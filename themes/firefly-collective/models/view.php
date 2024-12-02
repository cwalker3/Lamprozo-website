<?php

    // Parse Request URI Function
    function parse_request_uri() {
        $uri = $_SERVER['REQUEST_URI'];
        $parsed_url = parse_url($uri);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        return explode('/', trim($path, '/'));
    }

    // Determine View Function
    function determine_view() {
        $aCmd = parse_request_uri();

        // For single blog posts
        if ( is_single() ) return 'blog-post';
        if ( is_home() ) return 'blog';

        // Check if the first segment exists
        if (isset($aCmd[0])) {
            $view = sanitize_title($aCmd[0]);

            // Valid custom URLs
            $valid_views = array('contact',
                                'signup',
                                'request-an-appointment');

            if (in_array($view, $valid_views)) {
                // Check if the view file exists
                $view_path = get_template_directory() . '/views/' . $view . '.php';
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