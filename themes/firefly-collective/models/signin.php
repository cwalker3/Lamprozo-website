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
        $host = GOOGLE_API_DOMAIN; // must exactly match the domain in DevTools
        $cookie_name  = 'google_auth_state';
        $cookie_value = urlencode($state);
        $max_age      = 300; // 5 minutes

        // Build cookie header using lowercase attribute names.
        $cookie_parts = [
            "{$cookie_name}={$cookie_value}",
            "path=/",
            "max-age={$max_age}",
            "httponly",
            "samesite=Lax"
        ];
        if (is_ssl()) {
            $cookie_parts[] = "secure";
        }
        if (!empty($host)) {
            $cookie_parts[] = "domain={$host}";
        }
        $cookie_header = implode("; ", $cookie_parts);
        
        // Build the authorization URL.
        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => $scope,
            'state'         => $state,
            'access_type'   => 'online'
        ];
        $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        
        // Send a 302 redirect along with the cookie header.
        $response = new WP_REST_Response('', 302, ['Location' => $auth_url]);
        $response->header('Set-Cookie', $cookie_header, false);
        $response->header('Content-Type', 'text/html');
        
        error_log("google_auth_init: Set cookie header: $cookie_header on host: $host");
        
        return $response;
    }

    /**
     * google_auth_callback: Handles the OAuth callback from Google.
     */
    function google_auth_callback(WP_REST_Request $request) {
        // Retrieve 'code' and 'state' from the request.
        $code  = sanitize_text_field($request->get_param('code') ?? '');
        $state = sanitize_text_field($request->get_param('state') ?? '');
        
        error_log("google_auth_callback: Received state: $state");
        
        // Check if the temporary 'google_auth_state' cookie exists.
        if (empty($_COOKIE['google_auth_state'])) {
            error_log("google_auth_callback: Cookie 'google_auth_state' is missing.");
            $response = new WP_REST_Response('Invalid state: cookie missing', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        error_log("google_auth_callback: Cookie 'google_auth_state': " . $_COOKIE['google_auth_state']);
        
        // Verify the state matches the cookie value.
        if ($_COOKIE['google_auth_state'] !== $state) {
            error_log("google_auth_callback: State mismatch: cookie (" . $_COOKIE['google_auth_state'] . ") vs received ($state)");
            $response = new WP_REST_Response('Invalid state', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        
        // --- Delete the temporary google_auth_state cookie ---
        // Must match the same attributes as when it was set.
        $host = GOOGLE_API_DOMAIN;
        setcookie('google_auth_state', 'deleted', [
            'expires'  => time() - 3600, // expired in the past
            'path'     => '/',
            'domain'   => $host,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        // If no authorization code is provided, return an error.
        if (empty($code)) {
            $response = new WP_REST_Response('Missing code parameter', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        
        // Set up OAuth parameters.
        $client_id     = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';
        $client_secret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '';
        $redirect_uri  = defined('GOOGLE_REDIRECT_URI') ? GOOGLE_REDIRECT_URI : home_url('/wp-json/custom-api/v1/google-auth-callback');
        
        // Exchange the code for an access token.
        $token_response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code'
            ]
        ]);
        if (is_wp_error($token_response)) {
            $response = new WP_REST_Response('Error requesting access token', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        $token_body = wp_remote_retrieve_body($token_response);
        $token_json = json_decode($token_body, true);
        if (!isset($token_json['access_token'])) {
            $response = new WP_REST_Response('No access token returned', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        $access_token = sanitize_text_field($token_json['access_token']);
        
        // Retrieve the user's profile from Google.
        $profile_response = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', [
            'headers' => ['Authorization' => 'Bearer ' . $access_token]
        ]);
        if (is_wp_error($profile_response)) {
            $response = new WP_REST_Response('Error requesting user profile', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        $profile_body = wp_remote_retrieve_body($profile_response);
        $profile_json = json_decode($profile_body, true);
        
        // Extract user information.
        $email = sanitize_email($profile_json['email'] ?? '');
        $fname = sanitize_text_field($profile_json['given_name'] ?? '');
        $lname = sanitize_text_field($profile_json['family_name'] ?? '');
        
        if (!$email) {
            $response = new WP_REST_Response('No email found in Google profile', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        
        // Initialize variables for HTML message and auth_id.
        $html = '';
        $auth_id = '';
        
        // Check if the user already exists.
        if ($existing_user = get_user_by('email', $email)) {
            update_user_meta($existing_user->ID, 'third_party', 'google');
            $auth_id = $existing_user->ID;
        } else {
            // Create a new user.
            $username = sanitize_user($email, true);
            $password = wp_generate_password();
            $userdata = [
                'user_login' => $username,
                'user_email' => $email,
                'first_name' => $fname,
                'last_name'  => $lname,
                'user_pass'  => $password,
                'role'       => 'subscriber',
            ];
            $user_id = wp_insert_user($userdata);
            if (is_wp_error($user_id)) {
                $response = new WP_REST_Response('Could not create user', 500);
                $response->header('Content-Type', 'text/html');
                return $response;
            }
            update_user_meta($user_id, 'third_party', 'google');
            $auth_id = $user_id;
        }
        
        $encrypted_user_id = encrypt_with_auth_key($auth_id);
        
        // Build a JavaScript snippet to send the encrypted user ID to the parent window.
        // The parent page should then set the cookie on its own domain.
        $html = '<script>
                    var encryptedUserId = "' . esc_js($encrypted_user_id) . '";
                    window.opener.postMessage(
                      { type: "googleSignupSuccess", message: "Sign-in successful!", auth_id: encryptedUserId },
                      "*"
                    );
                    setTimeout(function(){ window.close(); }, 500);
                 </script>';
        
        // Return the HTML message.
        $response = new WP_REST_Response($html, 200);
        $response->header('Content-Type', 'text/html');
        return $response;
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