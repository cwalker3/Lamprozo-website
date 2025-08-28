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
            'permission_callback' => 'safe_verify_request',
        ));
        
        register_rest_route('custom-api/v1', '/check-email', array(
            'methods'             => 'GET',
            'callback'            => 'check_email_exists',
            'permission_callback' => 'safe_verify_request',
        ));
        
        register_rest_route('custom-api/v1', '/update-profile', array(
            'methods'             => 'POST',
            'callback'            => 'handle_profile_update',
            'permission_callback' => 'verify_rest_request',
        ));
        
        register_rest_route('custom-api/v1', '/reset-password', array(
            'methods'             => 'POST',
            'callback'            => 'handle_password_reset',
            'permission_callback' => 'verify_rest_request',
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

        register_rest_route('custom-api/v1', '/app-get-view', array(
            'methods'             => 'POST',
            'callback'            => 'app_get_view',
            'permission_callback' => '__return_true'
        ));

        register_rest_route('custom-api/v1', '/app-login', array(
            'methods'             => 'POST',
            'callback'            => 'app_login',
            'permission_callback' => '__return_true'
        ));

        register_rest_route('custom-api/v1', '/app-logout', array(
            'methods'             => 'GET',
            'callback'            => 'app_logout',
            'permission_callback' => 'verify_rest_request'
        ));

        register_rest_route('custom-api/v1', '/change-template-temp', array(
            'methods'             => 'POST',
            'callback'            => 'handle_change_template_temp',
            'permission_callback' => 'safe_verify_request'
        ));

        register_rest_route('custom-api/v1', '/change-landing-style-preview', array(
            'methods'             => 'POST',
            'callback'            => 'handle_change_landing_style_preview',
            'permission_callback' => 'safe_verify_request'
        ));

        register_rest_route('custom-api/v1', '/get-landing-style-preview', array(
            'methods'             => 'GET',
            'callback'            => 'handle_get_landing_style_preview',
            'permission_callback' => 'safe_verify_request'
        ));

        register_rest_route('custom-api/v1', '/edit-landing-in-gutenberg', array(
            'methods'             => 'POST',
            'callback'            => 'handle_edit_landing_in_gutenberg',
            'permission_callback' => 'safe_verify_request'
        ));

        register_rest_route('custom-api/v1', '/change-template-option-preview', array(
			'methods'             => 'POST',
			'callback'            => 'handle_change_option_preview',
			'permission_callback' => 'safe_verify_request'
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

    function verify_rest_request( WP_REST_Request $request ) {
        // 1. Use WordPress's logged-in cookie authentication
        if ( ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
            $cookie_value = sanitize_text_field( $_COOKIE[ LOGGED_IN_COOKIE ] );
            $user_id      = wp_validate_auth_cookie( $cookie_value, 'logged_in' );

            if ( $user_id ) {
                wp_set_current_user( $user_id );
                // Reset auth cookies for consistency
                wp_set_auth_cookie( $user_id, true, is_ssl() );

                // 2. Grant admin users immediately
                if ( user_can( $user_id, 'manage_options' ) ) {
                    return true;
                }
            }
        }

        // 3. Check custom auth_id cookie
        if ( ! empty( $_COOKIE['auth_id'] ) ) {
            $raw       = sanitize_text_field( $_COOKIE['auth_id'] );
            $decrypted = decrypt_with_auth_key( $raw );
            $uid       = intval( $decrypted );
            $user      = get_user_by( 'id', $uid );

            if ( $uid && $user ) {
                wp_set_current_user( $uid );
                wp_set_auth_cookie( $uid, true, is_ssl() );
                return true;
            }
        }

        // 4. Check campaign token for anonymous access
        if ( ! empty( $_COOKIE['campaign_token'] ) ) {
            $token = sanitize_text_field( $_COOKIE['campaign_token'] );
            
            // Validate campaign token using same logic as signin model
            global $wpdb;
            $today = current_time('Y-m-d');
            
            $campaign = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ffc_campaigns
                WHERE token = %s
                AND DATE(start_date) <= %s
                AND (unlimited = 1 OR end_date IS NULL OR DATE(end_date) >= %s)",
                $token, $today, $today
            ) );
            
            if ( $campaign ) {
                return true; // Allow anonymous access for valid campaigns
            }
        }

        return false;
    }

    /**
     * Safe authentication that doesn't reset cookies
     */
    function safe_verify_request( WP_REST_Request $request ) {
        // 1. Check if user is already authenticated via WordPress cookies
        if ( is_user_logged_in() && current_user_can( 'customize' ) ) {
            return true;
        }
        
        // 2. Use WordPress's logged-in cookie authentication WITHOUT resetting cookies
        if ( ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
            $cookie_value = sanitize_text_field( $_COOKIE[ LOGGED_IN_COOKIE ] );
            $user_id      = wp_validate_auth_cookie( $cookie_value, 'logged_in' );

            if ( $user_id ) {
                // Set current user but DON'T reset auth cookies
                wp_set_current_user( $user_id );
                
                // Grant admin users immediately
                if ( user_can( $user_id, 'customize' ) ) {
                    return true;
                }
            }
        }

        // 3. Check custom auth_id cookie WITHOUT resetting cookies
        if ( ! empty( $_COOKIE['auth_id'] ) ) {
            $raw       = sanitize_text_field( $_COOKIE['auth_id'] );
            $decrypted = decrypt_with_auth_key( $raw );
            $uid       = intval( $decrypted );
            $user      = get_user_by( 'id', $uid );

            if ( $uid && $user ) {
                // Set current user but DON'T reset auth cookies
                wp_set_current_user( $uid );
                return true;
            }
        }

        // 4. Check campaign token for anonymous access
        if ( ! empty( $_COOKIE['campaign_token'] ) ) {
            $token = sanitize_text_field( $_COOKIE['campaign_token'] );
            
            // Validate campaign token using same logic as signin model
            global $wpdb;
            $today = current_time('Y-m-d');
            
            $campaign = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ffc_campaigns
                WHERE token = %s
                AND DATE(start_date) <= %s
                AND (unlimited = 1 OR end_date IS NULL OR DATE(end_date) >= %s)",
                $token, $today, $today
            ) );
            
            if ( $campaign ) {
                return true; // Allow anonymous access for valid campaigns
            }
        }

        return false;
    }