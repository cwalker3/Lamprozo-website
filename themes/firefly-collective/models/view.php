<?php

    // theme/models/view.php

    // Parse Request URI Function
    function parse_request_uri() {
        $uri = $_SERVER['REQUEST_URI'];
        $parsed_url = parse_url($uri);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        return explode('/', trim($path, '/'));
    }

    // Get assets for specific views
    function get_view_assets($view) {
        $assets = array();
        $assets['js'] = array();
        $assets['css'] = array();
        $assets['location_type'] = 'theme';

        switch($view) {
            case 'blog':
                $assets['js'][]     = 'assets/js/blog.js';
                break;
            
            case 'app':
                $assets['css'][]    = 'assets/css/app.css';
                $assets['js'][]     = 'assets/js/app.js';
                break;

            case 'contact':
                $assets['js'][]     = 'assets/js/contact.js';
                break;

            case 'signup':
                $assets['js'][]     = 'assets/js/auth.js';
                $assets['js'][]     = 'assets/js/signup.js';
                break;

            case 'request-an-appointment':
                $assets['css'][]    = 'assets/css/calendar.css';
                $assets['js'][]     = 'assets/js/calendar.js';
                $assets['js'][]     = 'assets/js/request-an-appointment.js';
                break;

            case 'dashboard':
                $assets['css'][]    = 'assets/css/auth.css';
                $assets['css'][]    = 'assets/css/dashboard.css';
                $assets['js'][]     = 'assets/js/dashboard.js';
                $assets['js'][]     = 'https://js.stripe.com/v3/';
                break;
            
            case 'order-history':
                $assets['location_type'] = 'plugin';
                $assets['css'][]    = 'assets/css/orders.css';
                $assets['js'][]     = 'assets/js/orders.js';
                $assets['js'][]     = VUE_REMOTE_CORE;
                break;
        }

        return $assets;
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
            $valid_views = array(
                                'app',
                                'contact',
                                'signup',
                                'request-an-appointment',
                                'dashboard',
                                'order-history');
            
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