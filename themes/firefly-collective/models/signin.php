<?php
    function check_username_exists(WP_REST_Request $request) {
        $username = sanitize_user($request->get_param('username') ?? '');
        if (empty($username)) {
            return new WP_Error('invalid_input', __('Username is required.', 'alex-strait'), array('status' => 400));
        }
        return rest_ensure_response(array('exists' => username_exists($username) ? true : false));
    }

    function check_email_exists(WP_REST_Request $request) {
        $email = sanitize_email($request->get_param('email') ?? '');
        if (empty($email)) {
            return new WP_Error('invalid_input', __('Email is required.', 'alex-strait'), array('status' => 400));
        }
        return rest_ensure_response(array('exists' => email_exists($email) ? true : false));
    }

    /**
     * google_auth_init: Initiates the Google OAuth flow.
     */
    function google_auth_init(WP_REST_Request $request) {
        $client_id    = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';
        $redirect_uri = defined('GOOGLE_REDIRECT_URI') ? GOOGLE_REDIRECT_URI : home_url('/wp-json/custom-api/v1/google-auth-callback');
        $scope        = 'email profile';
        $state        = wp_create_nonce('google_auth_state');

        // Use our defined GOOGLE_API_DOMAIN for cookie and URL.
        $host = GOOGLE_API_DOMAIN;
        $cookie_domain = $host;
        $cookie_name  = 'google_auth_state';
        $cookie_value = urlencode($state);
        $max_age      = 300; // 5 minutes
        $cookie_parts = [
            "{$cookie_name}={$cookie_value}",
            "Path=/",
            "Max-Age={$max_age}",
            "HttpOnly",
            "SameSite=Lax"
        ];
        if (is_ssl()) {
            $cookie_parts[] = "Secure";
        }
        if (!empty($cookie_domain)) {
            $cookie_parts[] = "Domain={$cookie_domain}";
        }
        $cookie_header = implode("; ", $cookie_parts);
        
        // Build the authorization URL.
        $params = array(
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => $scope,
            'state'         => $state,
            'access_type'   => 'online'
        );
        $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        
        $response = new WP_REST_Response('', 302, array('Location' => $auth_url));
        $response->header('Set-Cookie', $cookie_header, false);
        $response->header('Content-Type', 'text/html');
        
        error_log("google_auth_init: Set cookie header: $cookie_header on host: $host");
        
        return $response;
    }

    /**
     * google_auth_callback: Handles the OAuth callback from Google.
     */
    function google_auth_callback(WP_REST_Request $request) {
        $code  = sanitize_text_field($request->get_param('code') ?? '');
        $state = sanitize_text_field($request->get_param('state') ?? '');

        // Use our defined GOOGLE_API_DOMAIN.
        $host = GOOGLE_API_DOMAIN;
        $cookie_domain = $host;

        error_log("google_auth_callback: Received state: $state");

        if (empty($_COOKIE['google_auth_state'])) {
            error_log("google_auth_callback: Cookie 'google_auth_state' is missing.");
            return new WP_REST_Response('Invalid state: cookie missing', 400, array('Content-Type' => 'text/html'));
        }
        error_log("google_auth_callback: Cookie 'google_auth_state': " . $_COOKIE['google_auth_state']);
        if ($_COOKIE['google_auth_state'] !== $state) {
            error_log("google_auth_callback: State mismatch: cookie (" . $_COOKIE['google_auth_state'] . ") vs received ($state)");
            return new WP_REST_Response('Invalid state', 400, array('Content-Type' => 'text/html'));
        }
        // Clear the state cookie.
        $clear_cookie = "google_auth_state=deleted; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax" . (is_ssl() ? "; Secure" : "");
        if (!empty($cookie_domain)) {
            $clear_cookie .= "; Domain={$cookie_domain}";
        }
        $response_headers = array('Set-Cookie' => $clear_cookie, 'Content-Type' => 'text/html');

        if (empty($code)) {
            return new WP_REST_Response('Missing code parameter', 400, $response_headers);
        }

        $client_id     = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';
        $client_secret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '';
        $redirect_uri  = defined('GOOGLE_REDIRECT_URI') ? GOOGLE_REDIRECT_URI : home_url('/wp-json/custom-api/v1/google-auth-callback');

        $token_response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code'
            )
        ));
        if (is_wp_error($token_response)) {
            return new WP_REST_Response('Error requesting access token', 400, $response_headers);
        }
        $token_body = wp_remote_retrieve_body($token_response);
        $token_json = json_decode($token_body, true);
        if (!isset($token_json['access_token'])) {
            return new WP_REST_Response('No access token returned', 400, $response_headers);
        }
        $access_token = sanitize_text_field($token_json['access_token']);

        $profile_response = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', array(
            'headers' => array('Authorization' => 'Bearer ' . $access_token)
        ));
        if (is_wp_error($profile_response)) {
            return new WP_REST_Response('Error requesting user profile', 400, $response_headers);
        }
        $profile_body = wp_remote_retrieve_body($profile_response);
        $profile_json = json_decode($profile_body, true);

        $email = sanitize_email($profile_json['email'] ?? '');
        $fname = sanitize_text_field($profile_json['given_name'] ?? '');
        $lname = sanitize_text_field($profile_json['family_name'] ?? '');

        if (!$email) {
            return new WP_REST_Response('No email found in Google profile', 400, $response_headers);
        }

        // Check if the user already exists.
        $existing_user = get_user_by('email', $email);
        if ($existing_user) {
            update_user_meta($existing_user->ID, 'third_party', 'google');
            // Alert the user that they've already signed up, then close the window.
            $html = '<script>window.opener.postMessage({ type: "googleSignupSuccess", message: "You have already signed up with this account." }, "*");
                    // Then close the popup after a short delay to ensure the message is sent
                    setTimeout(function(){ window.close(); }, 500);</script>';
            return new WP_REST_Response($html, 200, $response_headers);
        } else {
            $username = sanitize_user($email, true);
            $password = wp_generate_password();
            $userdata = array(
                'user_login' => $username,
                'user_email' => $email,
                'first_name' => $fname,
                'last_name'  => $lname,
                'user_pass'  => $password,
                'role'       => 'subscriber',
            );
            $user_id = wp_insert_user($userdata);
            if (is_wp_error($user_id)) {
                return new WP_REST_Response('Could not create user', 500, $response_headers);
            }
            update_user_meta($user_id, 'third_party', 'google');
            wp_set_auth_cookie( $user_id, false );
            wp_set_current_user( $user_id );

            // For a new user, simply close the window.
            $html = '<script>
                        // Send a message to the opener (parent window)
                        window.opener.postMessage({ type: "googleSignupSuccess", message: "Thanks for signing up!" }, "*");
                        // Then close the popup after a short delay to ensure the message is sent
                        setTimeout(function(){ window.close(); }, 500);
                    </script>';

            return new WP_REST_Response($html, 200, $response_headers);
        }
    }

    add_filter('rest_pre_serve_request', function($served, $result, $request, $server) {
        $headers = $result->get_headers();
        if ( isset($headers['Content-Type']) && strpos($headers['Content-Type'], 'text/html') !== false ) {
            // Send headers
            header('Content-Type: text/html; charset=UTF-8');
            // Output the response body directly.
            echo $result->get_data();
            return true; // We've served the request.
        }
        return $served;
    }, 10, 4);
