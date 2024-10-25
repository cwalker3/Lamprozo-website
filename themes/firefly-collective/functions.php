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
    $version = wp_get_theme()->get('Version'); // Use theme version for cache busting

    // Enqueue Stylesheets
    wp_enqueue_style('nav-css', $theme_path . '/assets/css/nav.css', array(), $version);
    wp_enqueue_style('main-css', $theme_path . '/assets/css/main.css', array(), $version);
    wp_enqueue_style('animations-css', $theme_path . '/assets/css/animations.css', array(), $version);
    wp_enqueue_style('gutenberg-css', $theme_path . '/assets/css/gutenberg.css', array(), $version);

    // Enqueue Scripts
    wp_enqueue_script('nav-js', $theme_path . '/assets/js/nav.js', array(), $version, true);
    wp_enqueue_script('main-js', $theme_path . '/assets/js/main.js', array(), $version, true);

    // Localize main.js with the nonce and API URL for security
    wp_localize_script('main-js', 'myApi', array(
        'nonce'   => wp_create_nonce('wp_rest'), // Generate the nonce
        'api_url' => esc_url_raw(rest_url('custom-api/v1/')), // Base API URL
        'themePath' => $theme_path,
    ));
}
add_action('wp_enqueue_scripts', 'enqueue_my_styles_and_scripts');

// Register Custom REST API Endpoints
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
}
add_action('rest_api_init', 'register_custom_api_endpoints');

// Verify Nonce for REST API
function verify_rest_nonce($request) {
    $nonce = $request->get_header('X-WP-Nonce');
    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        return new WP_Error('invalid_nonce', 'Invalid nonce', array('status' => 403));
    }
    return true;
}

// Handle Contact Form Submission
function handle_contact_form_submission(WP_REST_Request $request) {
    $params = $request->get_params();

    $name    = sanitize_text_field($params['name'] ?? '');
    $email   = sanitize_email($params['email'] ?? '');
    $message = sanitize_textarea_field($params['message'] ?? '');

    if (empty($name) || empty($email) || empty($message) || !is_email($email)) {
        return new WP_Error('invalid_input', __('Please provide valid name, email, and message.', 'firefly-collective'), array('status' => 400));
    }

    $adminEmail = get_option('admin_email');
    $subject    = __('Website Contact Form Submitted from: ', 'firefly-collective') . $name;
    $fullMessage = $message . "\n\nFrom: " . $email;
    $headers    = array('Reply-To: ' . $email);

    // Sanitize headers to prevent email injection
    $headers = array_map('sanitize_text_field', $headers);

    $sent = wp_mail(sanitize_email($adminEmail), sanitize_text_field($subject), wp_strip_all_tags($fullMessage), $headers);

    // if (!$sent) {
    //     return new WP_Error('email_failed', __('Failed to send email.', 'firefly-collective'), array('status' => 500));
    // }

    return rest_ensure_response(array('message' => __('Message sent successfully.', 'firefly-collective')));
}

// Handle Signup Submission
function handle_signup_submission(WP_REST_Request $request) {
    $params = $request->get_params();

    // Sanitize and Validate Inputs
    $fname = sanitize_text_field($params['fname'] ?? '');
    $lname = sanitize_text_field($params['lname'] ?? '');
    $email = sanitize_email($params['email'] ?? '');
    $phone = sanitize_text_field($params['phone'] ?? '');

    if (empty($fname) || empty($lname) || empty($email) || !is_email($email)) {
        return new WP_Error('invalid_input', __('Please provide valid first name, last name, and email.', 'firefly-collective'), array('status' => 400));
    }

    // Check if user already exists
    if (email_exists($email)) {
        return new WP_Error('user_exists', __('An account with this email already exists.', 'firefly-collective'), array('status' => 400));
    }

    // Generate a strong random password
    $password = wp_generate_password();

    // Create the user
    $userdata = array(
        'user_login' => sanitize_user($email, true),
        'user_email' => $email,
        'first_name' => $fname,
        'last_name'  => $lname,
        'user_pass'  => $password,
        'role'       => 'subscriber',
    );

    $user_id = wp_insert_user($userdata);

    if (is_wp_error($user_id)) {
        return new WP_Error('user_creation_failed', $user_id->get_error_message(), array('status' => 500));
    }

    // Update user meta
    if (!empty($phone)) {
        update_user_meta($user_id, 'phone', $phone);
    }

    // Send welcome email to the user
    $message = __("Welcome to our community!\n\n", 'firefly-collective');
    $message .= __("Name: ", 'firefly-collective') . "$fname $lname\n";
    $message .= __("Email: ", 'firefly-collective') . "$email\n";
    if (!empty($phone)) {
        $message .= __("Phone: ", 'firefly-collective') . "$phone\n\n";
    }
    $message .= __("Your account has been created successfully.", 'firefly-collective');

    wp_mail($email, __('Welcome to Our Community', 'firefly-collective'), wp_strip_all_tags($message));

    // Notify admin of new signup
    $adminEmail = get_option('admin_email');
    $adminMessage = __("New website signup:\n\n", 'firefly-collective');
    $adminMessage .= __("Name: ", 'firefly-collective') . "$fname $lname\n";
    $adminMessage .= __("Email: ", 'firefly-collective') . "$email\n";
    if (!empty($phone)) {
        $adminMessage .= __("Phone: ", 'firefly-collective') . "$phone\n";
    }

    wp_mail($adminEmail, __('New Website Signup', 'firefly-collective'), wp_strip_all_tags($adminMessage));

    return rest_ensure_response(array('message' => __('Signup successful!', 'firefly-collective')));
}

// Handle Get More Blogs
function handle_get_more_blogs(WP_REST_Request $request) {
    $params = $request->get_params();

    $page = isset($params['page']) ? intval($params['page']) : 1;

    $posts = get_posts(array(
        'post_type'      => 'post',
        'posts_per_page' => 15,
        'paged'          => $page,
    ));

    $response = array();

    foreach ($posts as $post) {
        setup_postdata($post);
        $response[] = array(
            'title'          => get_the_title($post->ID),
            'excerpt'        => get_the_excerpt($post->ID),
            'permalink'      => get_permalink($post->ID),
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
        );
    }
    wp_reset_postdata();

    return rest_ensure_response($response);
}

// Handle Filter Blogs
function handle_filter_blogs(WP_REST_Request $request) {
    $params = $request->get_params();

    $page = isset($params['page']) ? intval($params['page']) : 1;

    $args = array(
        'post_type'      => 'post',
        'posts_per_page' => 15,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );

    // Apply filters if present
    if (!empty($params['category_id'])) {
        $args['cat'] = intval($params['category_id']);
    }

    if (!empty($params['tag_id'])) {
        $args['tag_id'] = intval($params['tag_id']);
    }

    if (!empty($params['month'])) {
        $args['monthnum'] = intval($params['month']);
    }

    if (!empty($params['year'])) {
        $args['year'] = intval($params['year']);
    }

    if (!empty($params['keywords'])) {
        $args['s'] = sanitize_text_field($params['keywords']);
    }

    $posts = get_posts($args);

    $response = array();

    foreach ($posts as $post) {
        setup_postdata($post);
        $response[] = array(
            'title'          => get_the_title($post->ID),
            'excerpt'        => get_the_excerpt($post->ID),
            'permalink'      => get_permalink($post->ID),
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
        );
    }
    wp_reset_postdata();

    return rest_ensure_response($response);
}

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

    // Check if the first segment exists
    if (isset($aCmd[0])) {
        $view = sanitize_title($aCmd[0]);

        // Valid custom URLs
        $valid_views = array('contact', 'blog', 'signup');

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

function custom_theme_setup_pages() {
    $pages = array(
        'home' => array(
            'title'   => 'Home',
            'content' => 'This is the homepage.',
        ),
        'blog' => array(
            'title'   => 'Blog',
            'content' => 'This is the blog.',
        ),
        'contact' => array(
            'title'   => 'Contact',
            'content' => 'This is the contact page.',
        ),
        'signup' => array(
            'title'   => 'Signup',
            'content' => 'This is the signup page.',
        ),
    );

    $page_ids = array();

    foreach ($pages as $slug => $page_data) {
        // Check if the page already exists
        $page = get_page_by_path($slug);

        if (!$page) {
            // Page doesn't exist, so create it
            $page_id = wp_insert_post(array(
                'post_title'   => $page_data['title'],
                'post_content' => $page_data['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $slug,
            ));
            $page_ids[$slug] = $page_id;
        } else {
            // Page exists, store its ID
            $page_ids[$slug] = $page->ID;
        }
    }

    // Set 'Home' as the static front page
    if (isset($page_ids['home'])) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $page_ids['home']);
    }

    // Set 'Blog' as the posts page
    if (isset($page_ids['blog'])) {
        update_option('page_for_posts', $page_ids['blog']);
    }

    // Create 'Main Website Menu' and assign it to the 'website-menu' location
    $menu_name = 'Main Website Menu';
    $menu_exists = wp_get_nav_menu_object($menu_name);

    if (!$menu_exists) {
        // Create the menu
        $menu_id = wp_create_nav_menu($menu_name);

        // Assign the menu to the 'website-menu' theme location
        $locations = get_theme_mod('nav_menu_locations');
        $locations['website-menu'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);

        // Add pages to the menu
        foreach ($page_ids as $slug => $page_id) {
            wp_update_nav_menu_item($menu_id, 0, array(
                'menu-item-title'     => $pages[$slug]['title'],
                'menu-item-object'    => 'page',
                'menu-item-object-id' => $page_id,
                'menu-item-type'      => 'post_type',
                'menu-item-status'    => 'publish',
            ));
        }
    }
}
add_action('after_switch_theme', 'custom_theme_setup_pages');

function slugToTitle($slug) {
    // Replace hyphens with spaces
    $string = str_replace('-', ' ', $slug);

    // Capitalize the first letter of each word
    $title = ucwords($string);

    return $title;
}
