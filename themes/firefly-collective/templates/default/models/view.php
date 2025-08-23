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

            case 'blog':
                $assets['css'][]    = $template_path . '/assets/css/blog.css';
                $assets['js'][]     = $template_path . '/assets/js/blog.js';
                break;
            
            case 'app':
                $assets['css'][]    = $template_path . '/assets/css/app.css';
                $assets['css'][]    = $template_path . '/assets/css/auth.css';
                $assets['css'][]    = $template_path . '/assets/css/dashboard.css';
                $assets['css'][]    = $plugin_path   . '/assets/css/orders.css';
                $assets['js'][]     = $template_path . '/assets/js/signup.js';
                $assets['js'][]     = $template_path . '/assets/js/auth.js';
                $assets['js'][]     = $template_path . '/assets/js/app.js';
                $assets['js'][]     = $template_path . '/assets/js/dashboard.js';
                $assets['js'][]     = $plugin_path   . '/assets/js/orders.js';
                $assets['js'][]     = 'https://js.stripe.com/v3/';
                $assets['js'][]     = VUE_REMOTE_CORE;
                break;

            case 'contact':
                $assets['css'][]    = $template_path . '/assets/css/contact.css';
                $assets['js'][]     = $template_path . '/assets/js/contact.js';
                break;

            case 'signup':
                $assets['css'][]    = $template_path . '/assets/css/signup-appt.css';
                $assets['js'][]     = $template_path . '/assets/js/auth.js';
                $assets['js'][]     = $template_path . '/assets/js/signup.js';
                break;

            case 'request-an-appointment':
                $assets['css'][]    = $template_path . '/assets/css/signup-appt.css';
                $assets['css'][]    = $template_path . '/assets/css/calendar.css';
                $assets['js'][]     = $template_path . '/assets/js/calendar.js';
                $assets['js'][]     = $template_path . '/assets/js/request-an-appointment.js';
                break;

            case 'dashboard':
                $assets['css'][]    = $template_path . '/assets/css/auth.css';
                $assets['css'][]    = $template_path . '/assets/css/dashboard.css';
                $assets['js'][]     = $template_path . '/assets/js/dashboard.js';
                $assets['js'][]     = 'https://js.stripe.com/v3/';
                break;
            
            case 'order-history':
                $assets['css'][]    = $plugin_path . '/assets/css/orders.css';
                $assets['js'][]     = $plugin_path . '/assets/js/orders.js';
                $assets['js'][]     = VUE_REMOTE_CORE;
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
            $valid_views = array(
                                'app',
                                'contact',
                                'signup',
                                'request-an-appointment',
                                'dashboard',
                                'order-history');
            
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