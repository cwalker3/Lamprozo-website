<?php


// Open Graph/Twitter Meta Tags
function set_open_graph_meta_data() {
    $theme_path = get_template_directory_uri();
    if (is_singular()) {
        global $post;
        setup_postdata($post);
        $title = get_the_title();
        $description = get_the_excerpt();
        $image = get_the_post_thumbnail_url($post->ID, 'full');
        $url = get_permalink();
        $type = 'article';
    } else {
        $title = get_bloginfo('name');
        $description = get_bloginfo('description');

        $url = home_url();
        $type = 'website';
    }

    $image = $image ? $image : $theme_path.'/images/default-og.webp';
    $description = $description ? $description : 'Firecly Collective website development framework';

    // Output meta tags
    echo '<!-- Open Graph meta tags -->' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($description) . '" />' . "\n";
    if ($image) {
        echo '<meta property="og:image" content="' . esc_url($image) . '" />' . "\n";
    }
    echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
    echo '<meta property="og:type" content="' . esc_attr($type) . '" />' . "\n";

    echo '<!-- Twitter Card meta tags -->' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr($description) . '" />' . "\n";
    if ($image) {
        echo '<meta name="twitter:image" content="' . esc_url($image) . '" />' . "\n";
    }
}
add_action('wp_head', 'set_open_graph_meta_data');

// Enqueue Styles and Scripts
function enqueue_my_styles_and_scripts() {
    $theme_path = get_template_directory_uri();
    $version = wp_get_theme()->get('Version');
    $unique_id = uniqid();

    // Enqueue Stylesheets
    wp_enqueue_style('custom-properties-css', $theme_path . '/assets/css/custom-properties.css', array(), $unique_id);
    wp_enqueue_style('main-css', $theme_path . '/assets/css/main.css', array(), $unique_id);
    wp_enqueue_style('nav-css', $theme_path . '/assets/css/nav.css', array(), $unique_id);
    wp_enqueue_style('animations-css', $theme_path . '/assets/css/animations.css', array(), $unique_id);
    wp_enqueue_style('gutenberg-css', $theme_path . '/assets/css/gutenberg.css', array(), $unique_id);

    // Enqueue Scripts
    wp_enqueue_script('nav-js', $theme_path . '/assets/js/nav.js', array(), $unique_id, true);
    wp_enqueue_script('main-js', $theme_path . '/assets/js/main.js', array(), $unique_id, true);

    $nonce = wp_create_nonce('wp_rest');

    // Localize main.js with the nonce and API URL for security
    wp_localize_script('main-js', 'myApi', array(
        'nonce'   => $nonce,
        'api_url' => esc_url_raw(rest_url('custom-api/v1/')), // Base API URL
        'themePath' => $theme_path,
        'maxBlogs' => 15
    ));

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

    register_rest_route('custom-api/v1', '/request-appointment', array(
        'methods'             => 'POST',
        'callback'            => 'request_appointment',
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

    // Admin Email
    // ----------------------------------------------------------------------------------------
    $message = nl2br($message);
    $subject = "{$name} has sent you a message";
    $html = "
        <html>
        <head>
            <title>Website contact</title>
        </head>
        <body>
            <p>{$message}</p>

            <p>from: <a href='mailto:donotreply@fireflycollective.org'>donotreply@fireflycollective.org</a></p>
        </body>
        </html>
        ";
    send_html_mail(NULL, $subject, $html, true);
    // ----------------------------------------------------------------------------------------

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

    // User Email
    // ----------------------------------------------------------------------------------------
    $site_name = get_bloginfo('name');
    $subject = "Thank you for signing up with {$site_name}";
    $html = "
        <html>
        <head>
            <title>Welcome to {$site_name}</title>
        </head>
        <body>
            <p>Thank you for signing up with {$site_name}.</p>
            <p>
                <strong>Name:</strong> {$fname} {$lname}<br>
                <strong>Email:</strong> {$email}<br>
                <strong>Phone:</strong> {$phone}
            </p>

            <p>from: <a href='mailto:donotreply@fireflycollective.org'>donotreply@fireflycollective.org</a></p>
        </body>
        </html>
        ";
    send_html_mail($email, $subject, $html);

    // Admin Email
    // ----------------------------------------------------------------------------------------
    $subject = "{$fname} {$lname} has signed up on the website!";
    $html = "
        <html>
        <head>
            <title>Website Signup</title>
        </head>
        <body>
            <p>{$fname} {$lname} has signed up with the website.</p>
            <p>
                <strong>Name:</strong> {$fname} {$lname}<br>
                <strong>Email:</strong> {$email}<br>
                <strong>Phone:</strong> {$phone}
            </p>

            <p>from: <a href='mailto:donotreply@fireflycollective.org'>donotreply@fireflycollective.org</a></p>
        </body>
        </html>
        ";
    send_html_mail(NULL, $subject, $html, true);
    // ----------------------------------------------------------------------------------------

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
            'author'         => get_the_author('display_name', $post->post_author),
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
        );
    }
    wp_reset_postdata();

    return rest_ensure_response($response);
}

function get_firefly_collective_calendar($is_admin = false) {
    global $wpdb;

    // Define the table name with the WordPress table prefix
    $table_name = $wpdb->prefix . 'firefly_collective_bookings';

    // Get current date components
    $current_year  = intval( date( 'Y' ) );
    $current_month = intval( date( 'n' ) ); // Numeric representation of a month, without leading zeros (1-12)
    $current_month--;
    $current_day   = intval( date( 'j' ) ); // Day of the month without leading zeros (1-31)

    $admin_statement = '';
    if (!$is_admin) $admin_statement = "AND request_flag = '0'";
    
    // Define the SQL query with proper date comparison logic
    $query = "SELECT *
              FROM {$table_name}
              WHERE (day_number >= $current_day AND month_number >= $current_month AND year_number >= $current_year $admin_statement)
              ORDER BY year_number DESC, month_number DESC, day_number DESC, start_time ASC";

    // $query = "SELECT * FROM {$table_name}";
    // $query = "DELETE FROM wpka_firefly_collective_bookings";

    // Prepare the SQL statement with the current date components
    $prepared_query = $wpdb->prepare($query);

    // Execute the query and retrieve the results
    $results = $wpdb->get_results($prepared_query);

    // Check if any results were returned
    if ( ! empty($results) ) {
        return $results;
    }

    // Return null if no bookings are found
    return null;
}

function request_appointment(WP_REST_Request $request) {
    $calData = $request->get_params();

    global $wpdb;
    $table_name = $wpdb->prefix . 'firefly_collective_bookings';

    // Sanitize data
    $id            = intval($calData['id']);
    $first_name    = sanitize_text_field($calData['first_name']);
    $last_name     = sanitize_text_field($calData['last_name']);
    $email         = sanitize_email($calData['email']);
    $phone         = sanitize_text_field($calData['phone']);
    $type          = sanitize_text_field($calData['type']);
    $message       = sanitize_textarea_field($calData['msg']);
    $day_number    = intval($calData['day']);
    $month_number  = intval($calData['month']);
    $year_number   = intval($calData['year']);
    $start_time    = sanitize_text_field($calData['start-time']);
    $end_time      = sanitize_text_field($calData['end-time']);
    $request_flag  = sanitize_text_field($calData['request_flag']);

    // Define WHERE clause (Assuming you have an ID or unique identifier)
    $where = array('id' => $id); // Ensure 'id' exists in $calData

    // Update the existing record
    $updated = $wpdb->update(
        $table_name,
        array(
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'email'        => $email,
            'phone'        => $phone,
            'type_name'    => $type,
            'message'      => $message,
            'day_number'   => $day_number,
            'month_number' => $month_number,
            'year_number'  => $year_number,
            'start_time'   => $start_time,
            'end_time'     => $end_time,
            'request_flag' => $request_flag
        ),
        $where,
        array(
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%d',
            '%d',
            '%d',
            '%s',
            '%s',
            '%s'
        ),
        array('%d')
    );

    if ($updated === false) {
        return new WP_Error(
            'db_update_error',
            __($wpdb->last_error),
            array('status' => 500)
        );
    }
    
    $start_time = format_time($start_time);
    $end_time = format_time($end_time);

    // User Email
    // ----------------------------------------------------------------------------------------
    $subject = "Your $type Appointment Has Been Requested";
    $html = "
        <html>
        <head>
            <title>Appointment Request</title>
        </head>
        <body>
            <p>{$type} appointment has been requested.</p>
            <p>
                <strong>Name:</strong> {$first_name} {$last_name}<br>
                <strong>Email:</strong> {$email}<br>
                <strong>Phone:</strong> {$phone}<br>
                <strong>Start Time:</strong> {$start_time}<br>
                <strong>End Time:</strong> {$end_time}
            </p>

            <p>from: <a href='mailto:donotreply@fireflycollective.org'>donotreply@fireflycollective.org</a></p>
        </body>
        </html>
        ";
    send_html_mail($email, $subject, $html);

    // Admin Email
    // ----------------------------------------------------------------------------------------
    $message = nl2br($message);
    $subject = "$type Appointment Has Been Requested";
    $html = "
        <html>
        <head>
            <title>Appointment Request</title>
        </head>
        <body>
            <p>{$type} appointment has been requested.</p>
            <p>
                <strong>Name:</strong> {$first_name} {$last_name}<br>
                <strong>Email:</strong> {$email}<br>
                <strong>Phone:</strong> {$phone}<br>
                <strong>Start Time:</strong> {$start_time}<br>
                <strong>End Time:</strong> {$end_time}<br><br>
                <strong>Message:</strong><br>
                {$message}
            </p>

            <p>from: <a href='mailto:donotreply@fireflycollective.org'>donotreply@fireflycollective.org</a></p>
        </body>
        </html>
        ";
    send_html_mail(NULL, $subject, $html, true);
    // ----------------------------------------------------------------------------------------

    return rest_ensure_response( array('success' => true, 
                                        'message' => 'Appointment request succeeded!') );
}

function send_html_mail($to, $subject, $html, $admin = false) {

    if ($admin) $to = 'info@fireflycollective.org';

    $headers = array(
        'From: Firefly Collective <donotreply@fireflycollective.org>',
        'Reply-To: donotreply@fireflycollective.org',
        'Content-Type: text/html; charset=UTF-8',
    );

    // Send the email
    wp_mail($to, $subject, $html, $headers);
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

    // For single blog posts
    if ( is_single() ) return 'blog-post';
    if ( is_home() ) return 'blog';

    // Check if the first segment exists
    if (isset($aCmd[0])) {
        $view = sanitize_title($aCmd[0]);

        // Valid custom URLs
        $valid_views = array('contact',
                             'signup',
                            'request-an-appointment');

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

function dequeue_jquery_migrate( $scripts ) {
    if ( ! is_admin() ) {
        // Deregister jQuery
        wp_deregister_script('jquery');
        // Optionally, remove jQuery Migrate
        wp_deregister_script('jquery-migrate');
    }
}
add_action( 'wp_enqueue_scripts', 'dequeue_jquery_migrate' );

function format_time($time_str) {
    // Create a DateTime object from the input time string
    $time = DateTime::createFromFormat('H:i', $time_str);
    
    // Check if the time was parsed successfully
    if (!$time) {
        return false; // Handle invalid input as needed
    }
    
    // Format the time into 12-hour format with am/pm
    return $time->format('g:i a');
}

function slugToTitle($slug) {
    // Replace hyphens with spaces
    $string = str_replace('-', ' ', $slug);

    // Capitalize the first letter of each word
    $title = ucwords($string);

    return $title;
}


function get_blog_poster_name( $author_id = null ) {
    if ( is_null( $author_id ) ) {
        $author_id = get_the_author_meta( 'ID' );
    }

    $display_name = get_the_author_meta( 'display_name', $author_id );

    if ( empty( $display_name ) ) {
        $display_name = __( 'Anonymous', 'your-text-domain' );
    }

    return esc_html( apply_filters( 'get_blog_poster_display_name', $display_name, $author_id ) );
}
