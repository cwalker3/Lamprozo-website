<?php

    // Define the custom login slug using theme mod with default value
    define('CUSTOM_LOGIN_SLUG', get_theme_mod('custom_login_slug', 'ffc-login'));

    /**
     * Register Theme Customizer settings for custom login URL
     */
    add_action('customize_register', 'firefly_customize_register_login_settings');
    function firefly_customize_register_login_settings($wp_customize) {
        // Add a new section for Security Settings
        $wp_customize->add_section('firefly_security_settings', array(
            'title'       => __('Security Settings', 'firefly-collective'),
            'priority'    => 160,
            'description' => __('Configure security-related settings for your site.', 'firefly-collective'),
        ));
        
        // Add setting for custom login slug
        $wp_customize->add_setting('custom_login_slug', array(
            'default'           => 'ffc-login',
            'sanitize_callback' => 'firefly_sanitize_login_slug',
            'transport'         => 'postMessage', // Requires JS to preview, but we'll just refresh
        ));
        
        // Add control for custom login slug
        $wp_customize->add_control('custom_login_slug', array(
            'label'       => __('Custom Login URL', 'firefly-collective'),
            'description' => __('Enter a custom slug for your login page. Default is "ffc-login". Only lowercase letters, numbers, and hyphens allowed.', 'firefly-collective'),
            'section'     => 'firefly_security_settings',
            'type'        => 'text',
            'input_attrs' => array(
                'placeholder' => 'ffc-login',
                'pattern'     => '[a-z0-9\-]+',
            ),
        ));
        
        // Add a notice about saving permalinks
        $wp_customize->add_control('login_slug_notice', array(
            'label'       => '',
            'description' => sprintf(
                '<strong>%s</strong> %s',
                __('Important:', 'firefly-collective'),
                __('After changing this setting, you may need to refresh permalinks by visiting Settings → Permalinks and clicking "Save Changes".', 'firefly-collective')
            ),
            'section'     => 'firefly_security_settings',
            'type'        => 'hidden',
            'priority'    => 11,
        ));
    }

    /**
     * Sanitize the login slug input
     */
    function firefly_sanitize_login_slug($input) {
        // Remove any whitespace
        $input = trim($input);
        
        // Convert to lowercase
        $input = strtolower($input);
        
        // Remove any characters that aren't lowercase letters, numbers, or hyphens
        $input = preg_replace('/[^a-z0-9\-]/', '', $input);
        
        // Remove multiple consecutive hyphens
        $input = preg_replace('/-+/', '-', $input);
        
        // Remove leading/trailing hyphens
        $input = trim($input, '-');
        
        // If empty after sanitization, return default
        if (empty($input)) {
            return 'ffc-login';
        }
        
        // Ensure it's not a WordPress reserved slug
        $reserved_slugs = array(
            'wp-admin', 'wp-login', 'admin', 'login', 'wp-content', 
            'wp-includes', 'wp-json', 'feed', 'rss', 'sitemap',
            'robots', 'xmlrpc', 'trackback'
        );
        
        if (in_array($input, $reserved_slugs)) {
            return 'ffc-login';
        }
        
        return $input;
    }

    /**
     * Store the old login slug before saving
     */
    add_action('customize_save', 'firefly_before_customize_save');
    function firefly_before_customize_save($wp_customize) {
        // Store the current (old) value before it gets updated
        $old_slug = get_theme_mod('custom_login_slug', 'ffc-login');
        set_transient('firefly_old_login_slug', $old_slug, 60);
    }

    /**
     * Check if login slug changed after saving
     */
    add_action('customize_save_after', 'firefly_after_customize_save');
    function firefly_after_customize_save($wp_customize) {
        // Get the old value we stored before saving
        $old_slug = get_transient('firefly_old_login_slug');
        
        // Get the new saved value
        $new_slug = get_theme_mod('custom_login_slug', 'ffc-login');
        
        // Only set notice if actually changed
        if ($old_slug && $old_slug !== $new_slug) {
            set_transient('firefly_login_slug_changed', true, 60);
        }
        
        // Clean up
        delete_transient('firefly_old_login_slug');
    }

    /**
     * Show admin notice after login slug change
     */
    add_action('admin_notices', 'firefly_login_slug_change_notice');
    function firefly_login_slug_change_notice() {
        if (get_transient('firefly_login_slug_changed')) {
            $login_url = home_url(CUSTOM_LOGIN_SLUG);
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><strong><?php _e('Login URL Changed!', 'firefly-collective'); ?></strong></p>
                <p><?php printf(__('Your new login URL is: <a href="%s">%s</a>', 'firefly-collective'), esc_url($login_url), esc_html($login_url)); ?></p>
                <p><?php _e('Please bookmark this URL as the old login URLs will now return 404 errors.', 'firefly-collective'); ?></p>
            </div>
            <?php
            delete_transient('firefly_login_slug_changed');
        }
    }

    function change_login_logo() {
        global $active_template;

        echo '<style type="text/css">
        .login h1 a { 
            background-image: url(' . get_template_directory_uri() . '/templates/'.$active_template.'/images/logo.webp) !important;
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
        $active_template = firefly_collective_get_active_template();
        wp_enqueue_style(
            'custom-login',
            get_template_directory_uri() . '/templates/' . $active_template . '/assets/css/login.css',
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
        
        // Force account selection if auth_id is not set
        if (empty($_COOKIE['auth_id'])) {
            $params['prompt'] = 'select_account';
        }
        
        $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        
        $response = new WP_REST_Response('', 302, ['Location' => $auth_url]);
        $response->header('Set-Cookie', $cookie_header, false);
        $response->header('Content-Type', 'text/html');
        
        return $response;
    }

    /**
     * google_auth_callback: Handles the OAuth callback from Google.
     */
    function google_auth_callback(WP_REST_Request $request) {
        $code  = sanitize_text_field($request->get_param('code') ?? '');
        $state = sanitize_text_field($request->get_param('state') ?? '');
        
        if (empty($_COOKIE['google_auth_state'])) {
            error_log("google_auth_callback: Cookie 'google_auth_state' is missing.");
            $response = new WP_REST_Response('Invalid state: cookie missing', 400);
            $response->header('Content-Type', 'text/html');
            return $response;
        }
        
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
            update_user_meta($user_id, 'custom_user', true);

            $auth_id = $user_id;
        }
        
        // Encrypt the user ID
        $encrypted_user_id = encrypt_with_auth_key($auth_id);
        
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());

        // Set auth_id cookie if the user is a subscriber
        $current_user = get_user_by('id', $auth_id);
        if ($current_user && in_array('subscriber', (array)$current_user->roles)) {
            set_custom_user($encrypted_user_id);
        }
        
        $html = '
                <div style="width: 100%; height: 100%; display: grid; place-content: center;">
                    <h2>Successfully authenticated!</h2>
                    <button onpointerup="window.close();">Close this window</button>
                </div>
                <script>
                    var encryptedUserId = "' . esc_js($encrypted_user_id) . '";
                    window.opener.postMessage(
                        { type: "googleSignupSuccess", message: "Sign-in successful!", auth_id: encryptedUserId },
                        "*"
                    );
                    setTimeout(function(){ window.close(); }, 500);
                </script>';
        
        $response = new WP_REST_Response($html, 200);
        $response->header('Content-Type', 'text/html');
        return $response;
    }

   add_action('template_redirect', function() {
        $path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
        
        // Handle campaign URLs: /dashboard/{token}
        if ( preg_match('#^dashboard/([A-Za-z0-9]+)$#', $path, $m) ) {
            global $wpdb;
            $token = $m[1];
            
            // Use consistent UTC time for comparison
            $now_utc = gmdate('Y-m-d H:i:s');
            
            // First, let's get the campaign without date restrictions to debug
            $campaign_debug = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, name, start_date, end_date, unlimited, token FROM {$wpdb->prefix}ffc_campaigns WHERE token = %s",
                $token
            ) );
            
            // Log for debugging (remove this after fixing)
            error_log("Campaign Debug - Token: $token");
            error_log("Campaign Debug - Current UTC: $now_utc");
            if ($campaign_debug) {
                error_log("Campaign Debug - Found campaign: " . print_r($campaign_debug, true));
            } else {
                error_log("Campaign Debug - No campaign found for token");
            }
            
            // Now check with date validation
            $campaign = $wpdb->get_row( $wpdb->prepare(
                "
                SELECT id, name, start_date, end_date, unlimited
                FROM {$wpdb->prefix}ffc_campaigns
                WHERE token = %s
                AND (
                    start_date <= %s OR start_date IS NULL
                )
                AND (
                    unlimited = 1
                    OR end_date IS NULL
                    OR end_date >= %s
                )
                ",
                $token, $now_utc, $now_utc
            ) );

            if ( $campaign ) {
                // Valid campaign - set cookie and redirect to dashboard
                setcookie('campaign_token', $token, [
                    'expires'  => time() + DAY_IN_SECONDS,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                $_COOKIE['campaign_token'] = $token;
                
                error_log("Campaign Debug - Valid campaign found, redirecting to dashboard");
                wp_redirect( home_url('/dashboard') );
                exit;
            } else {
                // Invalid or expired campaign token
                error_log("Campaign Debug - Campaign validation failed");
                
                // Clean up any existing campaign cookies
                cleanup_expired_campaign_cookies();
                
                // Redirect to login with error message
                wp_redirect( home_url(CUSTOM_LOGIN_SLUG . '/?campaign_error=invalid') );
                exit;
            }
        }
        
        // Clean up expired campaign cookies on dashboard access
        if ( determine_view() === 'dashboard' ) {
            cleanup_expired_campaign_cookies();
        }

        // Fallback to login if neither auth_id nor valid campaign_token
        if ( determine_view() === 'dashboard'
        && empty($_COOKIE['auth_id'])
        && empty($_COOKIE['campaign_token']) ) {
            wp_logout();
            if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
            wp_redirect( home_url(CUSTOM_LOGIN_SLUG) );
            exit;
        }
    }, 5 );

    /**
     * Clean up expired campaign cookies
     */
    function cleanup_expired_campaign_cookies() {
        if ( empty($_COOKIE['campaign_token']) ) {
            return;
        }
        
        global $wpdb;
        $token = sanitize_text_field($_COOKIE['campaign_token']);
        $now_utc = gmdate('Y-m-d H:i:s');
        
        // Check if the current campaign token is still valid
        $valid_campaign = $wpdb->get_var( $wpdb->prepare(
            "
            SELECT COUNT(*)
            FROM {$wpdb->prefix}ffc_campaigns
            WHERE token = %s
            AND (
                start_date <= %s OR start_date IS NULL
            )
            AND (
                unlimited = 1
                OR end_date IS NULL
                OR end_date >= %s
            )
            ",
            $token, $now_utc, $now_utc
        ) );
        
        // If campaign is expired or doesn't exist, remove the cookie
        if ( !$valid_campaign ) {
            setcookie('campaign_token', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE['campaign_token']);
        }
    }

    /**
     * Show campaign error messages on login page
     */
    add_action('login_message', 'show_campaign_error_message');
    function show_campaign_error_message($message) {
        if ( isset($_GET['campaign_error']) && $_GET['campaign_error'] === 'invalid' ) {
            $message = '<div id="login_error">The campaign link you used is invalid or has expired. Please log in with your account.</div>';
        }
        return $message;
    }

    // Hook into regular login to set the auth_id cookie for subscribers.
    function set_auth_cookie_on_wp_login($user_login, $user) {
        if (in_array('subscriber', (array)$user->roles)) {
            $encrypted_user_id = encrypt_with_auth_key($user->ID);
            set_custom_user($encrypted_user_id);
        }
    }
    add_action('wp_login', 'set_auth_cookie_on_wp_login', 10, 2);

    // Redirect subscribers to /dashboard after login.
    function custom_login_redirect($redirect_to, $request, $user) {
        // Only redirect subscribers to dashboard
        if (isset($user->roles) && is_array($user->roles)) {
            if (in_array('subscriber', $user->roles)) {
                return home_url('/dashboard');
            }
            // For admins and other roles, let them go to wp-admin
            if (!empty($redirect_to) && $redirect_to !== admin_url()) {
                return $redirect_to;
            }
            return admin_url();
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
        // Avoid interfering with AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        
        // Only redirect subscribers with auth_id cookie
        if (isset($_COOKIE['auth_id'])) {
            $current_user = wp_get_current_user();
            if ($current_user && $current_user->ID > 0) {
                // Only redirect subscribers, let other roles access wp-admin
                if (in_array('subscriber', (array)$current_user->roles)) {
                    wp_safe_redirect(home_url('/dashboard'));
                    exit;
                }
            }
        }
    });

    function custom_logout_redirect() {
        // Only intercept the exact /logout URI
        if ( untrailingslashit( $_SERVER['REQUEST_URI'] ) === '/logout' ) {
            // 1. Log the user out of WP (clears WP's own cookies/auth)
            wp_logout();

            // 2. Remove custom auth_id cookie
            if ( isset( $_COOKIE['auth_id'] ) ) {
                setcookie( 'auth_id', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
                unset( $_COOKIE['auth_id'] );
            }

            // 3. Redirect to custom login page
            wp_redirect( home_url(CUSTOM_LOGIN_SLUG) );
            exit;
        }
    }
    add_action( 'init', 'custom_logout_redirect' ); 

    /**
     * Initialize custom login URL handling
     */
    add_action('init', 'custom_login_url_init', 1);
    function custom_login_url_init() {
        // Only proceed if we're not in admin or doing AJAX
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        $request_uri = $_SERVER['REQUEST_URI'];
        $request_path = parse_url($request_uri, PHP_URL_PATH);
        $request = trim($request_path, '/');
        
        // Handle custom login URL (including with query strings)
        if ($request === CUSTOM_LOGIN_SLUG || 
            strpos($request, CUSTOM_LOGIN_SLUG . '/') === 0 || 
            (strpos($request_uri, '/' . CUSTOM_LOGIN_SLUG . '?') !== false)) {
            // Prevent redirect loops
            if (!defined('CUSTOM_LOGIN_LOADING')) {
                define('CUSTOM_LOGIN_LOADING', true);
                
                // Set global to indicate we're on the login page
                $GLOBALS['pagenow'] = 'wp-login.php';
                
                // Load wp-login.php
                require_once(ABSPATH . 'wp-login.php');
                exit;
            }
        }
        
        // For wp-login.php, allow it if:
        // 1. User is logged in
        // 2. It's a login/logout action
        // 3. There's a redirect_to parameter (post-login redirect)
        // 4. It's coming from our custom login page
        if (strpos($request, 'wp-login.php') !== false) {
            if (is_user_logged_in() || 
                isset($_GET['action']) || 
                isset($_POST['log']) || 
                isset($_GET['redirect_to']) || 
                isset($_POST['redirect_to']) ||
                isset($_GET['loggedout']) ||
                isset($_POST['wp-submit']) ||
                isset($_GET['reauth']) ||
                (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], CUSTOM_LOGIN_SLUG) !== false)) {
                return; // Allow access
            }
            // Otherwise, block it
            custom_return_404();
        }
        
        // Block other default login URLs for non-logged-in users
        $blocked_paths = [
            'wp-admin' => ['wp-admin', 'wp-admin/'],
            'admin' => ['admin']
        ];
        
        foreach ($blocked_paths as $type => $paths) {
            foreach ($paths as $blocked) {
                if ($request === $blocked || 
                    strpos($request, $blocked . '/') === 0 || 
                    strpos($request_uri, '/' . $blocked . '?') !== false) {
                    
                    // Allow wp-admin for logged-in users
                    if ($type === 'wp-admin' && is_user_logged_in()) {
                        return;
                    }
                    
                    // Block everything else
                    custom_return_404();
                }
            }
        }
    }

    /**
     * Return a proper 404 response
     */
    function custom_return_404() {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        
        // Use theme's 404 template if available
        if ($template = locate_template('404.php')) {
            load_template($template);
        } else {
            // Basic 404 response
            header('HTTP/1.0 404 Not Found');
            echo '<h1>404 Not Found</h1>';
            echo '<p>The page you are looking for does not exist.</p>';
        }
        exit;
    }

    /**
     * Filter login URL to use custom slug
     */
    add_filter('site_url', 'custom_login_url', 10, 4);
    function custom_login_url($url, $path, $scheme, $blog_id) {
        if (strpos($path, 'wp-login.php') !== false && !defined('CUSTOM_LOGIN_LOADING')) {
            $args = '';
            if (strpos($url, '?') !== false) {
                list($base, $args) = explode('?', $url, 2);
                $args = '?' . $args;
            }
            return home_url(CUSTOM_LOGIN_SLUG . '/' . $args, $scheme);
        }
        return $url;
    }

    /**
     * Filter login URL
     */
    add_filter('login_url', 'custom_filter_login_url', 10, 3);
    function custom_filter_login_url($login_url, $redirect, $force_reauth) {
        if (!defined('CUSTOM_LOGIN_LOADING')) {
            $login_url = home_url(CUSTOM_LOGIN_SLUG . '/');
            
            if (!empty($redirect)) {
                $login_url = add_query_arg('redirect_to', urlencode($redirect), $login_url);
            }
            
            if ($force_reauth) {
                $login_url = add_query_arg('reauth', '1', $login_url);
            }
        }
        return $login_url;
    }

    /**
     * Filter logout URL
     */
    add_filter('logout_url', 'custom_filter_logout_url', 10, 2);
    function custom_filter_logout_url($logout_url, $redirect) {
        if (!defined('CUSTOM_LOGIN_LOADING')) {
            $args = array('action' => 'logout');
            if (!empty($redirect)) {
                $args['redirect_to'] = urlencode($redirect);
            }
            
            $logout_url = add_query_arg($args, home_url(CUSTOM_LOGIN_SLUG . '/'));
            $logout_url = wp_nonce_url($logout_url, 'log-out');
        }
        return $logout_url;
    }

    /**
     * Filter password reset URL
     */
    add_filter('lostpassword_url', 'custom_filter_lostpassword_url', 10, 2);
    function custom_filter_lostpassword_url($lostpassword_url, $redirect) {
        if (!defined('CUSTOM_LOGIN_LOADING')) {
            $args = array('action' => 'lostpassword');
            if (!empty($redirect)) {
                $args['redirect_to'] = urlencode($redirect);
            }
            $lostpassword_url = add_query_arg($args, home_url(CUSTOM_LOGIN_SLUG . '/'));
        }
        return $lostpassword_url;
    }

    /**
     * Redirect wp-login.php form actions to custom URL
     */
    add_filter('login_form_action', 'custom_login_form_action', 10, 1);
    function custom_login_form_action($action) {
        if (!defined('CUSTOM_LOGIN_LOADING')) {
            return home_url(CUSTOM_LOGIN_SLUG . '/');
        }
        return $action;
    }

    /**
     * Update custom Google signin button script
     */
    add_action('login_footer', 'custom_login_google_script');
    function custom_login_google_script() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const googleButton = document.getElementById('google-signin');
            if (googleButton) {
                googleButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    const width = 500;
                    const height = 600;
                    const left = (screen.width - width) / 2;
                    const top = (screen.height - height) / 2;
                    
                    window.open(
                        '<?php echo esc_url(home_url('/wp-json/custom-api/v1/google-auth-init')); ?>',
                        'google-signin',
                        `width=${width},height=${height},left=${left},top=${top}`
                    );
                    
                    // Listen for success message
                    window.addEventListener('message', function(event) {
                        if (event.data && event.data.type === 'googleSignupSuccess') {
                            window.location.href = '<?php echo esc_url(home_url('/dashboard')); ?>';
                        }
                    });
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Disable XML-RPC for additional security
     */
    add_filter('xmlrpc_enabled', '__return_false');

    /**
     * Remove WordPress version from various places
     */
    remove_action('wp_head', 'wp_generator');
    add_filter('the_generator', '__return_empty_string');

    /**
     * Disable login hints in error messages
     */
    add_filter('login_errors', 'custom_login_errors');
    function custom_login_errors($error) {
        // Return generic error message
        return __('Login failed. Please check your credentials.', 'alex-strait');
    }

    /**
     * Prevent user enumeration via author archives
     */
    add_action('template_redirect', 'custom_prevent_user_enumeration');
    function custom_prevent_user_enumeration() {
        if (is_author()) {
            custom_return_404();
        }
    }

    /**
     * Block user enumeration via REST API
     */
    add_filter('rest_endpoints', 'custom_disable_user_endpoints');
    function custom_disable_user_endpoints($endpoints) {
        if (isset($endpoints['/wp/v2/users'])) {
            unset($endpoints['/wp/v2/users']);
        }
        if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
            unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
        }
        return $endpoints;
    }

    add_action('login_init', 'custom_unified_login_init', 5);
    function custom_unified_login_init() {
        // Add security headers
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: no-referrer-when-downgrade');
        
        // Check if user has auth_id cookie or is logged in
        if (!empty($_COOKIE['auth_id']) || is_user_logged_in()) {
            // Don't redirect if it's a logout action
            if (!isset($_REQUEST['action']) || $_REQUEST['action'] !== 'logout') {
                wp_safe_redirect(home_url('/dashboard'));
                exit;
            }
        }
    }

    /**
     * Clean up and secure the custom login implementation
     */
    add_action('init', 'custom_login_cleanup', 99);
    function custom_login_cleanup() {
        // Remove hints from login page that could help attackers
        add_filter('login_message', '__return_empty_string');
        
        // Disable login shake effect on error
        add_action('login_footer', function() {
            ?><script>
            if (document.getElementById('login_error')) {
                document.body.classList.remove('login-action-login');
                document.getElementById('login').classList.remove('shake');
            }
            </script><?php
        });
    }

    /**
     * Prevent WordPress from redirecting wp-admin to login
     * This runs before WordPress can process the request
     */
    add_action('parse_request', 'custom_block_wp_admin_early', 1);
    function custom_block_wp_admin_early($wp) {
        // Skip if in actual admin or doing AJAX
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        $request_uri = $_SERVER['REQUEST_URI'];
        $request_path = parse_url($request_uri, PHP_URL_PATH);
        $request = trim($request_path, '/');
        
        // Check if this is a wp-admin request
        if ($request === 'wp-admin' || 
            strpos($request, 'wp-admin/') === 0 || 
            strpos($request_uri, '/wp-admin?') !== false) {
            
            // If user is not logged in, return 404 immediately
            if (!is_user_logged_in()) {
                custom_return_404();
            }
        }
    }

    /**
     * Additional filter to prevent auth_redirect on wp-admin
     */
    add_filter('auth_redirect_scheme', 'custom_prevent_auth_redirect', 1);
    function custom_prevent_auth_redirect($scheme) {
        if (strpos($_SERVER['REQUEST_URI'], '/wp-admin') !== false) {
            // Check for WordPress auth cookies or your custom auth_id cookie
            if ( !isset($_COOKIE[LOGGED_IN_COOKIE]) ) {
                custom_return_404();
            }
        }
        return $scheme;
    }

    add_filter('determine_current_user', function( $user_id ) {
        if ( empty( $_COOKIE['auth_id'] ) ) {
            return $user_id;
        }
        if (defined('FIREFLY_DEV')) {
            add_filter('secure_auth_cookie',     '__return_true');
            add_filter('secure_logged_in_cookie','__return_true');
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