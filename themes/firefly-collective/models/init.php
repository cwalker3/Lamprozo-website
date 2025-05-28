<?php
    // Register Navigation Menu
    function register_website_menu() {
        register_nav_menu('website-menu', __('Main Website Menu', 'firefly-collective'));
    }
    add_action('init', 'register_website_menu');

    // Add Theme Support for Post Thumbnails
    add_theme_support('post-thumbnails');

    // Enqueue Styles and Scripts
    function enqueue_my_styles_and_scripts() {
        $theme_path = get_template_directory_uri();
        $version = wp_get_theme()->get('Version');
        $unique_id = uniqid();
        $auth_id = $_COOKIE['auth_id'];

        // Enqueue Stylesheets
        wp_enqueue_style('custom-properties-css', $theme_path . '/assets/css/custom-properties.css', array(), $unique_id);
        wp_enqueue_style('main-css', $theme_path . '/assets/css/main.css', array(), $unique_id);
        wp_enqueue_style('nav-css', $theme_path . '/assets/css/nav.css', array(), $unique_id);
        wp_enqueue_style('animations-css', $theme_path . '/assets/css/animations.css', array(), $unique_id);
        wp_enqueue_style('gutenberg-css', $theme_path . '/assets/css/gutenberg.css', array(), $unique_id);

        // Enqueue Scripts
        wp_enqueue_script('nav-js', $theme_path . '/assets/js/nav.js', array(), $unique_id, true);
        wp_localize_script('nav-js', 'navData', array(
            'auth_id'   => $auth_id
        ));
        
        wp_enqueue_script('main-js', $theme_path . '/assets/js/main.js', array(), $unique_id, true);

        $nonce = wp_create_nonce('wp_rest');

        // Localize main.js with the nonce and API URL for security
        wp_localize_script('main-js', 'myApi', array(
            'nonce'   => $nonce,
            'api_url' => esc_url_raw(rest_url('custom-api/v1/')), // Base API URL
            'themePath' => $theme_path,
            'maxBlogs' => 15,
            'gapiDomain' => 'https://' . GOOGLE_API_DOMAIN
        ));

        // Request an Appointment
        if (determine_view() === 'request-an-appointment') {
            wp_enqueue_style('calendar-css', $theme_path . '/assets/css/calendar.css', array(), $unique_id);
            wp_enqueue_script('cal-js', $theme_path . '/assets/js/calendar.js', array(), $unique_id, true);
            wp_localize_script('cal-js', 'calData', array(
                'isAdmin'        => 'false',
                'nonce'          => $nonce,
                'calendar'       => get_firefly_collective_calendar(),
                'booking_types'  => get_booking_types(),
                'admin_settings' => get_admin_settings()
            ));
        }

        // Signup
        if (determine_view() === 'signup') {
            wp_enqueue_script('auth-js', $theme_path . '/assets/js/auth.js', array(), $unique_id, true);
        }

        // Dashboard
        if (determine_view() === 'dashboard') {
            global $features_options_addons;
            $features_options_addons = get_features_options_addons();
            $theme_path = get_template_directory_uri();
            
            wp_enqueue_style('auth-css', $theme_path . '/assets/css/auth.css', array(), $unique_id);
            wp_enqueue_style('dashboard-css', $theme_path . '/assets/css/dashboard.css', array(), $unique_id);
            
            // Add Stripe.js
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
            wp_enqueue_script('dashboard-js', $theme_path . '/assets/js/dashboard.js', array(), $unique_id, true);
            
            // Get Stripe configuration
            $publishable_key = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : get_option('firefly_stripe_publishable_key', '');

            wp_localize_script('dashboard-js', 'dashboardData', array(
                'nonce'          => $nonce,
                'features'       => $features_options_addons,
                'theme_path'     => $theme_path,
                'stripeKey'      => $publishable_key
            ));
        }
    }
    add_action('wp_enqueue_scripts', 'enqueue_my_styles_and_scripts');

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
        wp_enqueue_style('auth-css', $theme_path . '/assets/css/auth.css', array(), $unique_id);
        wp_enqueue_script('auth', $theme_path . '/assets/js/auth.js', array(), $unique_id, true);
        wp_localize_script('auth', 'myApi', array(
            'nonce'   => $nonce,
            'gapiDomain' => 'https://' . GOOGLE_API_DOMAIN
        ));
    });
    