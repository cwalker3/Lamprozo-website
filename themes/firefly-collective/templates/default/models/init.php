<?php

    // theme/models/init.php

    // Register Navigation Menu
    function register_website_menu() {
        register_nav_menu('website-menu', __('Main Website Menu', 'firefly-collective'));
    }
    add_action('init', 'register_website_menu');

    // Add Theme Support for Post Thumbnails
    add_theme_support('post-thumbnails');

    /**
     * Enqueue core template assets (files starting with _core_)
     */
    function enqueue_core_assets($template_name, $theme_path, $version) {
        $template_dir = get_template_directory() . '/templates/' . $template_name . '/assets';
        $template_web_path = $theme_path . '/templates/' . $template_name . '/assets';
        $template_path = get_template_directory_uri() . '/templates/' . $template_name;
        
        // Scan and enqueue core CSS files
        $css_dir = $template_dir . '/css';
        if (is_dir($css_dir)) {
            $css_files = glob($css_dir . '/_core_*.css');
            foreach ($css_files as $file) {
                $filename = basename($file);
                $handle = 'core-' . str_replace(['_core_', '.css'], '', $filename) . '-css';
                $url = $template_web_path . '/css/' . $filename;
                wp_enqueue_style($handle, $url, array(), $version);
            }
        }
        
        // Scan and enqueue core JS files
        $js_dir = $template_dir . '/js';
        $nav_handle = '';
        $template_handle = '';
        $main_js_handle = '';
        if (is_dir($js_dir)) {
            $js_files = glob($js_dir . '/_core_*.js');
            foreach ($js_files as $file) {
                $filename = basename($file);
                $handle = 'core-' . str_replace(['_core_', '.js'], '', $filename) . '-js';
                $url = $template_web_path . '/js/' . $filename;
                wp_enqueue_script($handle, $url, array(), $version, true);
                if ( preg_match('/^core-nav/', $handle) ) {
                    $nav_handle = $handle;
                }
                if (preg_match('/'.$template_name.'/', $handle)) {
                    $template_handle = $handle;
                }
                if (preg_match('/^core-main/', $handle)) {
                    $main_js_handle = $handle;
                }
            }
        }

        // Localize main.js with the nonce and API URL for security
        wp_localize_script($main_js_handle, 'myApi', array(
            'nonce'         => $nonce,
            'api_url'       => $api_url,
            'themePath'     => $theme_path,
            'maxBlogs'      => 15,
            'gapiDomain'    => 'https://' . GOOGLE_API_DOMAIN
        ));

        // Nav js data (only if nav handle exists)
        if (!empty($nav_handle)) {
            wp_localize_script($nav_handle, 'navData', array(
                'auth_id' => isset($_COOKIE['auth_id']) ? $_COOKIE['auth_id'] : ''
            ));
        }
    }

    // Enqueue Styles and Scripts
    function enqueue_my_styles_and_scripts() {

        global $backend_plugin_path, $backend_plugin_path_web, $theme_path_web, $template_path, $template_web_path;

        $backend_plugin_path = ABSPATH . 'wp-content/plugins/firefly-collective/includes/apps/backend';
        $backend_plugin_path_web = '/wp-content/plugins/firefly-collective/includes/apps/backend';
        $theme_path = get_template_directory_uri();
        $theme_path_web = get_template_directory_uri();
        $active_template = firefly_collective_get_active_template();
        $template_path = $theme_path_web . '/templates/' . $active_template;
        $template_path_web = $template_path;
        $version = wp_get_theme()->get('Version');
        $unique_id = uniqid();
        $auth_id = isset($_COOKIE['auth_id']) ? $_COOKIE['auth_id'] : '';
        $api_url = esc_url_raw(rest_url('custom-api/v1/'));
        $current_view = determine_view();

        // Enqueue core template assets
        enqueue_core_assets($active_template, $theme_path, $unique_id);
        
        $nonce = wp_create_nonce('wp_rest');

        // Localize main.js with the nonce and API URL for security
        wp_localize_script('core-main-js', 'myApi', array(
            'nonce'         => $nonce,
            'api_url'       => $api_url,
            'themePath'     => $theme_path,
            'template_path' => $template_path_web,
            'maxBlogs'      => 15,
            'gapiDomain'    => 'https://' . GOOGLE_API_DOMAIN
        ));

        // Get dynamic asset paths
        $assets = get_view_assets($current_view, $theme_path, $backend_plugin_path_web);

        // Enqueue CSS
        enqueue_assets($assets['css'], 'css', $unique_id);

        // Enqueue JS
        enqueue_assets($assets['js'], 'js', $unique_id);

        // Request an Appointment
        if (determine_view() === 'request-an-appointment') {
            wp_localize_script('calendar-js', 'calData', array(
                'isAdmin'        => 'false',
                'nonce'          => $nonce,
                'calendar'       => get_firefly_collective_calendar(),
                'booking_types'  => get_booking_types(),
                'admin_settings' => get_admin_settings()
            ));
        }

        // Dashboard
        if (determine_view() === 'dashboard') {
            $features_options_addons = get_features_options_addons();
            global $theme_path, $is_campaign_mode;
            $theme_path = get_template_directory_uri();

            $is_campaign_mode = !empty($_COOKIE['campaign_token']);
            
            // Get Stripe configuration
            $publishable_key = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : get_option('firefly_stripe_publishable_key', '');

            // Get subscription status
            $request = new WP_REST_Request();
            $request->set_param('user_id', $user_id);
            
            $subscription_status = firefly_collective_check_subscription_status($request);
            
            // Check for campaign token (from URL or cookie)
            $campaign_config = null;
            $campaign_token = null;

            // First check URL path for token
            $request_uri = $_SERVER['REQUEST_URI'];
            if (preg_match('/\/dashboard\/([a-zA-Z0-9]+)/', $request_uri, $matches)) {
                $campaign_token = $matches[1];
            } elseif (!empty($_COOKIE['campaign_token'])) {
                $campaign_token = $_COOKIE['campaign_token'];
            }

            if ($campaign_token) {
                global $wpdb;
                $campaign = $wpdb->get_row($wpdb->prepare(
                    "SELECT features_config, preselect_config FROM {$wpdb->prefix}ffc_campaigns WHERE token = %s",
                    $campaign_token
                ));
                
                if ($campaign) {
                    $campaign_config = array(
                        'features_config' => json_decode($campaign->features_config, true),
                        'preselect_config' => json_decode($campaign->preselect_config, true)
                    );
                }
            }

            wp_localize_script('dashboard-js', 'dashboardData', array(
                'nonce'                 => $nonce,
                'features'              => $features_options_addons,
                'theme_path'            => $theme_path,
                'template_path'         => $template_path,
                'stripeKey'             => $publishable_key,
                'subscription_status'   => $subscription_status,
                'campaign_config'       => $campaign_config
            ));
        }

        // Order history
        if (determine_view() === 'order-history') {
            global $currentUserIdAdmin;
            $currentUserIdAdmin = current_user_can('manage_options');

            $obj = new stdClass();

            // Localize into JS
            wp_localize_script('orders-js', 'ordersData', array(
                'data'               => $obj,
                'nonce'              => $nonce,
                'theme_path'         => $theme_path,
                'apiUrl'             => $api_url,
                'currentUserIsAdmin' => $currentUserIdAdmin,
                'currentUserId'      => get_current_user_id()
            ));
        }
    }
    add_action('wp_enqueue_scripts', 'enqueue_my_styles_and_scripts');

    function add_pwa_manifest() {
        global $template_path;

        if (determine_view() !== 'app') return;

        $manifest_url = $template_path . '/manifest.json';

        // Output the link tag
        echo '<link crossorigin="use-credentials" rel="manifest" href="' . esc_url( $manifest_url ) . '">';
    }
    // Hook into wp_head to print the manifest link in the <head> section
    add_action( 'wp_head', 'add_pwa_manifest' );

    function enqueue_assets( array $files, string $type, string $version ) {
        $suffix = $type === 'css' ? '-css' : '-js';

        foreach ( $files as $path ) {

            // remote?
            if ( false !== strpos( $file, '://' ) ) {
                $host  = parse_url( $file, PHP_URL_HOST );
                $parts = explode( '.', $host );
                $name  = $parts[ count( $parts ) - 2 ];
                $src   = $file;
            } else {
                $name = pathinfo( $path, PATHINFO_FILENAME );
                $src  = "{$path}";
            }

            $handle = $name . $suffix;

            if ( $type === 'css' ) {
                wp_enqueue_style( $handle, $src, array(), $version );
            } else {
                wp_enqueue_script( $handle, $src, array(), $version, true );
            }
        }
    }

    function disable_comments() {
        // Remove from admin menu
        remove_menu_page('edit-comments.php');
        
        // Remove from post and pages
        remove_post_type_support('post', 'comments');
        remove_post_type_support('page', 'comments');
        
        // Close comments site-wide
        function disable_all_comments($open, $post_id) {
            return false;
        }
        add_filter('comments_open', 'disable_all_comments', 10, 2);
    }
    add_action('init', 'disable_comments');

    add_action('login_init', function() {
        $theme_path = get_template_directory_uri();
        $active_template = firefly_collective_get_active_template();
        $nonce = wp_create_nonce('wp_rest');
        
        $template_path =  get_template_directory_uri() . '/templates/' . $active_template;
        
        // CSS: handle, src, deps(array), ver, media
        wp_enqueue_style(
            'auth-css',
            $template_path . '/assets/css/auth.css',
            array(),
            $nonce,
            'all'
        );
        wp_enqueue_script('template-main-js', $template_path . '/assets/js/_core_main.js', array(), $nonce, true);
        wp_enqueue_script('auth-js', $template_path . '/assets/js/auth.js', array(), $nonce, true);
        wp_localize_script('auth-js', 'myApi', array(
            'nonce'     => $nonce,
            'gapiDomain'=> 'https://' . GOOGLE_API_DOMAIN
        ));
    });

    // Remove 'jquery-migrate' as a dependency of WordPress' jQuery
    add_action('wp_default_scripts', function ($scripts) {
        if (isset($scripts->registered['jquery'])) {
            $scripts->registered['jquery']->deps = array_diff(
                $scripts->registered['jquery']->deps,
                ['jquery-migrate']
            );
        }
    });

    // If any plugin enqueues it explicitly, dequeue as a fallback
    add_action('wp_print_scripts', function () {
        wp_dequeue_script('jquery-migrate');
    }, 100);

    add_action('admin_print_scripts', function () {
        wp_dequeue_script('jquery-migrate');
    }, 100);