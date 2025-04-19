<?php

    function change_login_logo() {
        echo '<style type="text/css">
        .login h1 a { 
            background-image: url(' . get_template_directory_uri() . '/images/logo.webp) !important;
            background-size: contain;
            width: 200px;
            height: 100px;
        }
        </style>';
    }
    add_action('login_head', 'change_login_logo');

    function custom_login_logo_url() {
        return home_url();
    }
    add_filter('login_headerurl', 'custom_login_logo_url');

    function custom_login_logo_url_title() {
        return get_bloginfo('name');
    }
    add_filter('login_headertitle', 'custom_login_logo_url_title');

    function enqueue_custom_login_style() {
    wp_enqueue_style(
        'custom-login',
        get_template_directory_uri() . '/assets/css/login.css',
        array(),
        wp_get_theme()->get('Version')
    );
    }
    add_action('login_enqueue_scripts', 'enqueue_custom_login_style');

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

        $host = GOOGLE_API_DOMAIN;
        $cookie_name  = 'google_auth_state';
        $cookie_value = urlencode($state);
        $max_age      = 300;

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
        
        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => $scope,
            'state'         => $state,
            'access_type'   => 'online'
        ];
        $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        
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
        $code  = sanitize_text_field($request->get_param('code') ?? '');
        $state = sanitize_text_field($request->get_param('state') ?? '');
        
        error_log("google_auth_callback: Received state: $state");
        
        if (empty($_COOKIE['google_auth_state'])) {
            error_log("google_auth_callback: Cookie 'google_auth_state' is missing.");
            $response = new WP_REST_Response('Invalid state: cookie missing', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        error_log("google_auth_callback: Cookie 'google_auth_state': " . $_COOKIE['google_auth_state']);
        
        if ($_COOKIE['google_auth_state'] !== $state) {
            error_log("google_auth_callback: State mismatch: cookie (" . $_COOKIE['google_auth_state'] . ") vs received ($state)");
            $response = new WP_REST_Response('Invalid state', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        
        $host = GOOGLE_API_DOMAIN;
        setcookie('google_auth_state', 'deleted', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => $host,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        if (empty($code)) {
            $response = new WP_REST_Response('Missing code parameter', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        
        $client_id     = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';
        $client_secret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '';
        $redirect_uri  = defined('GOOGLE_REDIRECT_URI') ? GOOGLE_REDIRECT_URI : home_url('/wp-json/custom-api/v1/google-auth-callback');
        
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
        
        $email = sanitize_email($profile_json['email'] ?? '');
        $fname = sanitize_text_field($profile_json['given_name'] ?? '');
        $lname = sanitize_text_field($profile_json['family_name'] ?? '');
        
        if (!$email) {
            $response = new WP_REST_Response('No email found in Google profile', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        
        $html = '';
        $auth_id = '';
        
        if ($existing_user = get_user_by('email', $email)) {
            update_user_meta($existing_user->ID, 'third_party', 'google');
            $auth_id = $existing_user->ID;
            $user_id = $existing_user->ID;
        } else {
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
        
        // Encrypt the user ID
        $encrypted_user_id = encrypt_with_auth_key($auth_id);
        
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());

        // Set auth_id cookie if the user is a subscriber
        $current_user = get_user_by('id', $auth_id);
        if ($current_user && in_array('subscriber', (array)$current_user->roles)) {
            setcookie('auth_id', $encrypted_user_id, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }
        
        $html = '<script>
                    var encryptedUserId = "' . esc_js($encrypted_user_id) . '";
                    window.opener.postMessage(
                        { type: "googleSignupSuccess", message: "Sign-in successful!", auth_id: encryptedUserId },
                        "*"
                    );
                    window.opener.location.href = "/dashboard";
                    setTimeout(function(){ window.close(); }, 500);
                </script>';
        
        $response = new WP_REST_Response($html, 200);
        $response->header('Content-Type', 'text/html');
        return $response;
    }

    // Hook into regular login to set the auth_id cookie for subscribers.
    function set_auth_cookie_on_wp_login($user_login, $user) {
        if (in_array('subscriber', (array)$user->roles)) {
            $encrypted_user_id = encrypt_with_auth_key($user->ID);
            setcookie('auth_id', $encrypted_user_id, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }
    }
    add_action('wp_login', 'set_auth_cookie_on_wp_login', 10, 2);

    // Redirect subscribers to /dashboard after login.
    function custom_login_redirect($redirect_to, $request, $user) {
        if (isset($user->roles) && is_array($user->roles) && in_array('subscriber', $user->roles)) {
            return home_url('/dashboard');
        }
        return $redirect_to;
    }
    add_filter('login_redirect', 'custom_login_redirect', 10, 3);

    add_filter('rest_pre_serve_request', function($served, $result, $request, $server) {
        $headers = $result->get_headers();
        if ( isset($headers['Content-Type']) && strpos($headers['Content-Type'], 'text/html') !== false ) {
            header('Content-Type: text/html; charset=UTF-8');
            echo $result->get_data();
            return true;
        }
        return $served;
    }, 10, 4);

    // Redirect on login pages.
    add_action('login_init', function() {
        if (isset($_COOKIE['auth_id'])) {
            wp_safe_redirect(home_url('/dashboard'));
            exit;
        }
    });

    function custom_google_signin_button() {
        ?>
        <div class="google-signin-container">
            <button id="google-signin" type="button" class="google-signin-button">
                Sign in with Google
            </button>
        </div>
        <?php
    }
    add_action('login_form', 'custom_google_signin_button');    

    // Redirect on admin pages.
    add_action('admin_init', function() {
        // Avoid interfering with AJAX requests.
        if (isset($_COOKIE['auth_id']) && !(defined('DOING_AJAX') && DOING_AJAX)) {
            wp_safe_redirect(home_url('/dashboard'));
            exit;
        }
    });

    function custom_logout_redirect() {
        // Only intercept the exact /logout URI
        if ( untrailingslashit( $_SERVER['REQUEST_URI'] ) === '/logout' ) {
            // 1. Log the user out of WP (clears WP’s own cookies/auth)
            wp_logout();
    
            // 2. Remove custom auth_id cookie
            if ( isset( $_COOKIE['auth_id'] ) ) {
                setcookie( 'auth_id', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
                unset( $_COOKIE['auth_id'] );
            }
    
            // 3. Redirect to WP login page
            wp_redirect( wp_login_url() );
            exit;
        }
    }
    add_action( 'init', 'custom_logout_redirect' );    

    add_filter('determine_current_user', function( $user_id ) {
        if ( empty( $_COOKIE['auth_id'] ) ) {
            return $user_id;
        }
        $decrypted = decrypt_with_auth_key( sanitize_text_field( $_COOKIE['auth_id'] ) );
        $uid = intval( $decrypted );
        if ( $uid && get_user_by('id', $uid) ) {
            wp_set_current_user($uid);
            wp_set_auth_cookie($uid, true, is_ssl());
            return $uid; // tells WP, “this is the logged‑in user”
        }
        return $user_id;
    }, 20 );

    add_filter('show_admin_bar', function( $show ) {
        // if this user was logged in only via auth_id
        if ( ! empty( $_COOKIE['auth_id'] ) ) {
            return false; 
        }
        return $show;
    });