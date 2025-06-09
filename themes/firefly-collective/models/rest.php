<?php

    // theme/models/rest.php

    function register_custom_api_endpoints() {
        register_rest_route('custom-api/v1', '/submit-contact', array(
            'methods'             => 'POST',
            'callback'            => 'handle_contact_form_submission',
            'permission_callback' => 'verify_rest_nonce',
        ));
        
        register_rest_route('custom-api/v1', '/submit-signup', array(
            'methods'             => 'POST',
            'callback'            => 'handle_signup_submission',
            'permission_callback' => 'verify_rest_nonce',
        ));
        
        register_rest_route('custom-api/v1', '/get-more-blogs', array(
            'methods'             => 'GET',
            'callback'            => 'handle_get_more_blogs',
            'permission_callback' => 'verify_rest_nonce',
        ));
        
        register_rest_route('custom-api/v1', '/filter-blogs', array(
            'methods'             => 'GET',
            'callback'            => 'handle_filter_blogs',
            'permission_callback' => 'verify_rest_nonce',
        ));
        
        register_rest_route('custom-api/v1', '/request-appointment', array(
            'methods'             => 'POST',
            'callback'            => 'request_appointment',
            'permission_callback' => 'verify_rest_nonce',
        ));
        
        register_rest_route('custom-api/v1', '/check-username', array(
            'methods'             => 'GET',
            'callback'            => 'check_username_exists',
            'permission_callback' => 'verify_rest_nonce',
        ));
        
        register_rest_route('custom-api/v1', '/check-email', array(
            'methods'             => 'GET',
            'callback'            => 'check_email_exists',
            'permission_callback' => 'verify_rest_nonce',
        ));
        
        register_rest_route('custom-api/v1', '/update-profile', array(
            'methods'             => 'POST',
            'callback'            => 'handle_profile_update',
            'permission_callback' => 'verify_rest_nonce',
        ));
        
        register_rest_route('custom-api/v1', '/reset-password', array(
            'methods'             => 'POST',
            'callback'            => 'handle_password_reset',
            'permission_callback' => 'verify_rest_nonce',
        ));
        
        register_rest_route('custom-api/v1', '/google-auth-init', array(
            'methods'             => 'GET',
            'callback'            => 'google_auth_init',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route('custom-api/v1', '/google-auth-callback', array(
            'methods'             => 'GET',
            'callback'            => 'google_auth_callback',
            'permission_callback' => '__return_true',
        ));

        register_rest_route('custom-api/v1', '/app-init', array(
            'methods'             => 'POST',
            'callback'            => 'app_init',
            'permission_callback' => '__return_true',
        ));
    }
    add_action('rest_api_init', 'register_custom_api_endpoints');

    function verify_rest_nonce($request) {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('invalid_nonce', 'Invalid nonce', array('status' => 403));
        }
        return true;
    }
