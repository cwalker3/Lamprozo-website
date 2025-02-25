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
                                'request-an-appointment',
                                'login');

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

    // Only remove the default admin redirect when the request is for /login.
    add_action( 'init', 'conditionally_remove_default_redirect' );
    function conditionally_remove_default_redirect() {
        $current_path = untrailingslashit( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
        if ( $current_path === '/login' ) {
            remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
        }
    }

    // Only trigger our custom auth_redirect_scheme filter on /login.
    add_filter( 'auth_redirect_scheme', 'conditional_stop_redirect', 9999 );
    function conditional_stop_redirect( $scheme ) {
        $current_path = untrailingslashit( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
        // If not /login, do nothing.
        if ( $current_path !== '/login' ) {
            return $scheme;
        }
        // If the user is validated, let the scheme remain.
        if ( wp_validate_auth_cookie( '', $scheme ) ) {
            return $scheme;
        }
        // Otherwise, force a 404 for /login.
        global $wp_query;
        $wp_query->set_404();
        get_template_part( '404' );
        exit();
    }