<?php

    // theme/models/app.php

    // App initialization - returns menu + front page
    function app_init($request) {
        $params = $request->get_params();
        $theme_path = get_template_directory_uri();
        $api_url = esc_url_raw(rest_url('custom-api/v1/'));
        $http_host = $_SERVER['HTTP_HOST'];

        // Get menu HTML
        ob_start();
        wp_nav_menu(array(
            'theme_location'  => 'app-menu',
            'container_class' => 'app-menu',
            'fallback_cb'     => false,
        ));
        $menu_html = ob_get_clean();

        // Get app page title and html
        $app_page_title = '';
        $app_page_html = '';
        $page = get_page_by_path( 'app' );
        $app_page_title = get_the_title( $page->ID );
        ob_start();
        echo apply_filters( 'the_content', $page->post_content );
        $app_page_html = ob_get_clean();

        return rest_ensure_response([
            'success'           => true,
            'menu_html'         => $menu_html,
            'app_page_html'     => $app_page_html,
            'app_page_title'    => $app_page_title,
            'nonce'             => wp_create_nonce('wp_rest'),
            'gapiDomain'        => 'https://' . GOOGLE_API_DOMAIN,
            'theme_path'        => $theme_path,
            'api_url'           => $api_url,
            'auth_id'           => $_COOKIE['auth_id'],
            'http_host'         => $http_host
        ]);
    }

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
            array('title' => 'Order History',     'url' => '#'),
            array('title' => 'Dashboard',         'url' => '#'),
            array('title' => 'Back to Website',   'url' => '#'),
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

    function app_get_view($request) {
        global $theme_path_web;
        $params = $request->get_params();
        $view = $params['view'];
        $theme_path = get_template_directory();
        $theme_path_web = get_template_directory_uri();
        $plugin_path = ABSPATH . 'wp-content/plugins/firefly-collective/includes/apps/backend';
        $nonce = wp_create_nonce('wp_rest');
        $api_url = esc_url_raw(rest_url('custom-api/v1/'));

        // Set up the context that your view expects
        global $current_user;
        $decrypted = decrypt_with_auth_key( sanitize_text_field( $_COOKIE['auth_id'] ) );
        $uid = intval( $decrypted );
        if ( $uid && get_user_by('id', $uid) ) {
            wp_set_current_user($uid);
            wp_set_auth_cookie($uid, true, is_ssl());
        }
        wp_get_current_user(); // Ensure current user is populated

        switch ($view) {
            case 'dashboard':
                $features_options_addons = get_features_options_addons();
                
                // Get the dashboard page from WordPress
                $dashboard_page = get_page_by_path('dashboard');
                
                if ($dashboard_page) {
                    // Set up variables that the view expects
                    $pageTitle = get_the_title($dashboard_page->ID);
                    $postContent = apply_filters('the_content', $dashboard_page->post_content);
                    $postID = $dashboard_page->ID;
                } else {
                    // Fallback if no dashboard page exists
                    $pageTitle = 'Dashboard';
                    $postContent = '';
                    $postID = 0;
                }
                
                // Additional variables your view might need
                $themePath = $theme_path_web;
                $pageSlug = 'dashboard';
                $unique = wp_unique_id('unique-');
                
                // Start output buffering
                ob_start();
                
                // Include the view (now all expected variables are defined)
                include $theme_path . '/views/dashboard.php';
                
                // Get the captured content
                $response_html = ob_get_clean();

                // Get Stripe configuration
                $publishable_key = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : get_option('firefly_stripe_publishable_key', '');
                
                // Get subscription status
                $request = new WP_REST_Request();
                $request->set_param('user_id', $uid);
                
                $subscription_status = firefly_collective_check_subscription_status($request);

                return rest_ensure_response([
                    'success'               => true,
                    'response_html'         => $response_html,
                    'nonce'                 => $nonce,
                    'features'              => $features_options_addons,
                    'theme_path'            => $theme_path_web,
                    'stripeKey'             => $publishable_key,
                    'subscription_status'   => $subscription_status
                ]);
            break;

            case 'order-history':
                global $currentUserIdAdmin;
                $currentUserIdAdmin = current_user_can('manage_options');

                // Get orders view
                ob_start();
                include $plugin_path . '/views/orders.php';
                $response_html = ob_get_clean();

                $obj = new stdClass();

                return rest_ensure_response([
                    'success'           => true,
                    'response_html'     => $response_html,
                    'data'              => $obj,
                    'apiUrl'            => $api_url,
                    'nonce'             => $nonce
                ]);
                break;
            
            case 'signup':

                // Get the dashboard page from WordPress
                $signup_page = get_page_by_path('signup');
                
                if ($signup_page) {
                    // Set up variables that the view expects
                    $pageTitle = get_the_title($signup_page->ID);
                    $postContent = apply_filters('the_content', $signup_page->post_content);
                    $postID = $signup_page->ID;
                } else {
                    // Fallback if no dashboard page exists
                    $pageTitle = 'Signup';
                    $postContent = '';
                    $postID = 0;
                }

                // Get orders view
                ob_start();
                include $theme_path . '/views/signup.php';
                $response_html = ob_get_clean();

                $obj = new stdClass();

                return rest_ensure_response([
                    'success'           => true,
                    'response_html'     => $response_html,
                    'apiUrl'            => $api_url,
                    'nonce'             => $nonce
                ]);
                break;
        }
    }

    function app_login($request) {
        $params = $request->get_params();
        
        // Validate required fields
        if (empty($params['username']) || empty($params['password'])) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'Username and password are required'
            ]);
        }
        
        // Authenticate user
        $user = wp_authenticate($params['username'], $params['password']);
        
        if (is_wp_error($user)) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'Invalid credentials'
            ]);
        }
        
        // Set auth cookies
        $user_id = $user->ID;
        $encrypted_user_id = encrypt_with_auth_key($user_id);
        setcookie('auth_id', $encrypted_user_id, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());
        
        return rest_ensure_response([
            'success' => true,
            'auth_id' => $encrypted_user_id
        ]);
    }

    function app_logout($request) {
        wp_logout();

        if ( isset( $_COOKIE['auth_id'] ) ) {
            setcookie( 'auth_id', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
            unset( $_COOKIE['auth_id'] );
        }

        return rest_ensure_response([
            'logout' => true
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