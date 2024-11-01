<?php

    require_once('const.php');

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    /**
     * Function to create the firefly_collective_bookings table upon plugin activation.
     */
    function firefly_collective_create_tables() {
        global $wpdb;

        // Create bookings table
        $table_name = $wpdb->prefix . 'firefly_collective_bookings';
        $sql = "CREATE TABLE $table_name 
                (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    first_name VARCHAR(100) NULL,
                    last_name VARCHAR(100) NULL,
                    email VARCHAR(100) NULL,
                    phone VARCHAR(20) NULL,
                    message TEXT NULL,
                    day_number TINYINT(2) NOT NULL,
                    month_number TINYINT(2) NOT NULL,
                    year_number SMALLINT(4) NOT NULL,
                    start_time VARCHAR(5) NOT NULL,
                    end_time VARCHAR(5) NOT NULL,
                    type_name VARCHAR(20) NULL,
                    request_flag TINYINT(1) NULL,
                    remove_flag VARCHAR(5) NULL,
                    request_confirmed TINYINT(1) NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id)
                )";
        create_table($sql);

        // Create booking types table
        $table_name = $wpdb->prefix . 'firefly_collective_bookings_types';
        $sql = "CREATE TABLE $table_name 
                (
                    type_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    type_title VARCHAR(100) NULL,
                    type_desrc VARCHAR(1000) NULL,
                    PRIMARY KEY (type_id)
                )";
        create_table($sql);

        // Create booking settings table and data
        $table_name = $wpdb->prefix . 'firefly_collective_bookings_settings';
        $sql = "CREATE TABLE $table_name 
                (
                    setting_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    setting_type VARCHAR(50) NOT NULL,
                    setting_value VARCHAR(50) NOT NULL,
                    PRIMARY KEY (setting_id)
                )";
        create_table($sql);

        $default_settings = array(
            "beginning_hour" => "10",
            "end_hour" => "16",
            "public_calendar" => "0",
            "authorization_token" => generateToken()
        );
        
        foreach ($default_settings as $settingType => $settingValue) {
            // Insert new record
            $inserted = $wpdb->insert(
                $table_name,
                array(
                    'setting_type'  => $settingType,
                    'setting_value' => $settingValue
                ),
                array(
                    '%s',
                    '%s'
                )
            );
        }
        
    }

    function create_table($sql) {
        global $wpdb;

        // Set the charset and collation
        $charset_collate = $wpdb->get_charset_collate();

        // Append the charset and collation to the SQL statement
        $sql .= " $charset_collate;";

        // Include the necessary file for dbDelta
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Execute the SQL statement
        dbDelta($sql);
    }

    /**
     * Function to drop the firefly_collective_bookings table upon plugin deactivation.
     */
    function firefly_collective_drop_all_tables() {
        global $wpdb;

        // Drop bookings table
        $table_name = $wpdb->prefix . 'firefly_collective_bookings';
        $sql = "DROP TABLE IF EXISTS $table_name;";
        $wpdb->query($sql);

        // Drop booking types table
        $table_name = $wpdb->prefix . 'firefly_collective_bookings_types';
        $sql = "DROP TABLE IF EXISTS $table_name;";
        $wpdb->query($sql);

        // Drop booking settings table
        $table_name = $wpdb->prefix . 'firefly_collective_bookings_settings';
        $sql = "DROP TABLE IF EXISTS $table_name;";
        $wpdb->query($sql);
    }

    /**
     * Enqueue Bookings Styles and Scripts
     */
    function enqueue_bookings_styles_and_scripts($hook) {
        // Check if we're on the 'My Bookings' admin page
        if ($hook !== 'toplevel_page_my-bookings') {
            return; // Exit if we're not on the correct page
        }

        $theme_path = get_template_directory_uri();
        $unique_id = uniqid(); // Adjust this for versioning or cache-busting

        // Enqueue Stylesheets
        wp_enqueue_style('custom-properties-css', $theme_path . '/assets/css/custom-properties.css', array(), $unique_id);
        wp_enqueue_style('calendar-css', $theme_path . '/assets/css/calendar.css', array(), $unique_id);
        wp_enqueue_style('bookings-css', PLUGIN_PATH_BACKEND . '/assets/css/bookings.css', array(), $unique_id);

        // Enqueue Scripts
        wp_enqueue_script('cal-js', $theme_path . '/assets/js/calendar.js', array(), $unique_id, true);
        wp_enqueue_script('bookings-js', PLUGIN_PATH_BACKEND . '/assets/js/bookings.js', array(), $unique_id, true);

        // Admin access
        $nonce = wp_create_nonce('wp_rest');
        $is_admin = current_user_can('manage_options') ? 'true' : 'false';
        $calendar = get_firefly_collective_calendar(true);

        wp_localize_script('cal-js', 'calData', array(
            'isAdmin'           => $is_admin,
            'nonce'             => $nonce,
            'adminUrl'          => admin_url('admin.php?page=my-bookings'),
            'calendar'          => $calendar,
            'admin_settings'    => get_admin_settings()
        ));

        wp_localize_script('bookings-js', 'bookingsData', array(
            'api_url'           => esc_url_raw(rest_url('custom-api/v1/')),
            'host'              => $_SERVER['HTTP_HOST'],
            'nonce'             => $nonce,
            'plugin_path'       => PLUGIN_PATH_BACKEND,
            'bookings_types'    => get_booking_types()
        ));
    }
    add_action('admin_enqueue_scripts', 'enqueue_bookings_styles_and_scripts');

    /**
     * Register Custom REST API Endpoints
     */
    function register_plugin_custom_api_endpoints() {
        register_rest_route('custom-api/v1', '/save-calendar', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_save_calendar',
            'permission_callback' => 'verify_rest_nonce',
        ));

        register_rest_route('custom-api/v1', '/handle-appointment', array(
            'methods'             => 'POST',
            'callback'            => 'handle_apt_confirm',
            'permission_callback' => 'verify_rest_nonce',
        ));

        register_rest_route('custom-api/v1', '/save-bookings-admin-data', array(
            'methods'             => 'POST',
            'callback'            => 'save_bookings_admin_data',
            'permission_callback' => 'verify_rest_nonce',
        ));
    }
    add_action('rest_api_init', 'register_plugin_custom_api_endpoints');

    function firefly_collective_save_calendar(WP_REST_Request $request) {
        // Retrieve JSON data from the request
        $apts = $request->get_json_params();

        // Insert or update data into the database
        global $wpdb;
        $table_name = $wpdb->prefix . 'firefly_collective_bookings';

        $recordsUpdated = array();
        $recordsDeleted = array();
        $recordsInserted = array();

        foreach ($apts as $data) {

            // Sanitize data
            $id            = isset($data['id']) ? intval($data['id']) : 0;
            $first_name    = sanitize_text_field($data['first_name']);
            $last_name     = sanitize_text_field($data['last_name']);
            $email         = sanitize_email($data['email']);
            $phone         = sanitize_text_field($data['phone']);
            $message       = sanitize_textarea_field($data['message']);
            $day_number    = intval($data['day_number']);
            $month_number  = intval($data['month_number']);
            $year_number   = intval($data['year_number']);
            $start_time    = sanitize_text_field($data['start_time']);
            $end_time      = sanitize_text_field($data['end_time']);
            $request_flag  = intval($data['request_flag']);
            $remove_flag   = sanitize_text_field($data['remove_flag']);

            // Check if the record exists
            $existing_record = null;
            if ($id > 0) {
                $existing_record = $wpdb->get_row(
                    $wpdb->prepare("SELECT id FROM $table_name WHERE id = %d", $id)
                );
            }

            if ($existing_record && $remove_flag !== "1") {
                // Update the existing record
                $updated = $wpdb->update(
                    $table_name,
                    array(
                        'first_name'    => $first_name,
                        'last_name'     => $last_name,
                        'email'         => $email,
                        'phone'         => $phone,
                        'message'       => $message,
                        'start_time'    => $start_time,
                        'end_time'      => $end_time,
                        'request_flag'  => $request_flag,
                        'remove_flag'   => $remove_flag
                    ),
                    array('id' => $existing_record->id),
                    array(
                        '%s', // first_name
                        '%s', // last_name
                        '%s', // email
                        '%s', // phone
                        '%s', // message
                        '%s', // start_time
                        '%s', // end_time
                        '%d', // request_flag
                        '%s'  // remove_flag
                    ),
                    array('%d') // id format
                );

                if ($updated === false) {
                    return new WP_Error(
                        'db_update_error',
                        __('Failed to update booking: ' . $wpdb->last_error),
                        array('status' => 500)
                    );
                }
                $recordsUpdated[] = $data;
            } elseif ($existing_record && $remove_flag === "1") {
                // Delete the existing record
                $deleted = $wpdb->delete(
                    $table_name,
                    array('id' => $existing_record->id),
                    array('%d') // id format
                );

                if ($deleted === false) {
                    return new WP_Error(
                        'db_delete_error',
                        __('Could not delete record: ' . $wpdb->last_error),
                        array('status' => 500)
                    );
                }
                $recordsDeleted[] = $data;
            } elseif (!$existing_record) {
                // Insert new record
                $inserted = $wpdb->insert(
                    $table_name,
                    array(
                        'first_name'    => $first_name,
                        'last_name'     => $last_name,
                        'email'         => $email,
                        'phone'         => $phone,
                        'message'       => $message,
                        'day_number'    => $day_number,
                        'month_number'  => $month_number,
                        'year_number'   => $year_number,
                        'start_time'    => $start_time,
                        'end_time'      => $end_time,
                        'request_flag'  => $request_flag,
                        'remove_flag'   => $remove_flag
                    ),
                    array(
                        '%s', // first_name
                        '%s', // last_name
                        '%s', // email
                        '%s', // phone
                        '%s', // message
                        '%d', // day_number
                        '%d', // month_number
                        '%d', // year_number
                        '%s', // start_time
                        '%s', // end_time
                        '%d', // request_flag
                        '%s'  // remove_flag
                    )
                );

                if ($inserted === false) {
                    return new WP_Error(
                        'db_insert_error',
                        __('Failed to save booking: ' . $wpdb->last_error),
                        array('status' => 500)
                    );
                }

                $insertData = $data;
                $insertData['id'] = $wpdb->insert_id;
                $recordsInserted[] = $insertData;
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Booking saved successfully.'),
            'records_inserted' => json_encode($recordsInserted),
            'records_deleted'  => json_encode($recordsDeleted)
        ));
    }

    function handle_apt_confirm(WP_REST_Request $request) {
        $apt = $request->get_json_params();

        global $wpdb;
        $table_name = $wpdb->prefix . 'firefly_collective_bookings';
        $is_admin = current_user_can('manage_options') ? true : false;

        if ($is_admin) {
            if ($apt['type'] === 'confirm') {
                $data = array('request_confirmed' => $apt['request_confirmed']);
                $format = array('%d');
            }
            if ($apt['type'] === 'cancel') {
                $data   = array('first_name'        => "",
                                'last_name'         => "",
                                'email'             => "",
                                'phone'             => "",
                                'message'           => "",
                                'request_flag'      => "0",
                                'request_confirmed' => 0);
                $format = array('%s',
                                '%s',
                                '%s',
                                '%s',
                                '%s',
                                '%s',
                                '%s');
            }

            $updated = $wpdb->update(
                $table_name,
                $data,
                array('id' => $apt['id']),
                $format,
                array('%d')
            );
        }

        if ($updated === false) {
            return new WP_Error(
                'db_update_error',
                __($wpdb->last_error),
                array('status' => 500)
            );
        }

        $first_name        = $apt['first_name'];
        $last_name         = $apt['last_name'];
        $email             = $apt['email'];
        $phone             = $apt['phone'];
        $message           = nl2br($apt['message']);
        $start_time        = format_time($apt['start_time']);
        $end_time          = format_time($apt['end_time']);
        $type_name         = $apt['type_name'];

        $type_past_tense = '';
        if ($apt['type'] === 'confirm') $type_past_tense = 'confirmed';
        if ($apt['type'] === 'cancel') $type_past_tense = 'cancelled';
        $type_past_tense_ucfirst = ucfirst($type_past_tense);

        // User Email
        // ----------------------------------------------------------------------------------------
        $subject = "Your {$type_name} Appointment Has Been {$type_past_tense_ucfirst}";
        $html = "
            <html>
            <head>
                <title>Appointment {$type_past_tense_ucfirst}</title>
            </head>
            <body>
                <p>{$type_name} appointment has been {$type_past_tense}.</p>
                <p>
                    <strong>Name:</strong> {$first_name} {$last_name}<br>
                    <strong>Email:</strong> {$email}<br>
                    <strong>Phone:</strong> {$phone}<br>
                    <strong>Start:</strong> {$start_time}<br>
                    <strong>End:</strong> {$end_time}
                </p>

                <p>from: <a href='mailto:donotreply@expressyourheart.net'>donotreply@expressyourheart.net</a></p>
            </body>
            </html>
            ";
        send_html_mail($email, $subject, $html);

        // Admin Email
        // ----------------------------------------------------------------------------------------
        $subject = "Your {$type_name} Appointment with {$first_name} {$last_name} Has Been {$type_past_tense_ucfirst}";
        $html = "
            <html>
            <head>
                <title>Appointment {$type_past_tense_ucfirst}</title>
            </head>
            <body>
                <p>{$type_name} appointment with {$first_name} {$last_name} has been {$type_past_tense}.</p>
                <p>
                    <strong>Name:</strong> {$first_name} {$last_name}<br>
                    <strong>Email:</strong> {$email}<br>
                    <strong>Phone:</strong> {$phone}<br>
                    <strong>Start:</strong> {$start_time}<br>
                    <strong>End:</strong> {$end_time}<br><br>

                    Message:<br>
                    {$message}
                </p>

                <p>from: <a href='mailto:donotreply@expressyourheart.net'>donotreply@expressyourheart.net</a></p>
            </body>
            </html>
            ";
        send_html_mail(NULL, $subject, $html, true);
        // ----------------------------------------------------------------------------------------

        return rest_ensure_response( array('success' => true, 
                                        'message' => 'Appointment Scheduled',
                                        'type'    =>  $apt['type']) );
    }

    function get_booking_types() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'firefly_collective_bookings_types';

        $query = "SELECT *
        FROM {$table_name}";

        $prepared_query = $wpdb->prepare($query);
        $results = $wpdb->get_results($prepared_query);

        if ( ! empty($results) ) {
            return $results;
        }
    }

    function get_admin_settings() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'firefly_collective_bookings_settings';

        // Prepare the SQL query. No need for $wpdb->prepare() as there are no placeholders.
        $query = "SELECT setting_type, setting_value FROM {$table_name}";

        // Execute the query and fetch results as associative arrays.
        $results = $wpdb->get_results($query, ARRAY_A);

        // Check if any settings were retrieved.
        if ( ! empty($results) ) {
            $settings = array();

            foreach ( $results as $row ) {
                $key = $row['setting_type'];
                $value = $row['setting_value'];

                // Process the value based on the setting type.
                switch ( $key ) {
                    case 'beginning_hour':
                    case 'end_hour':
                        // Convert to integer.
                        $settings[$key] = intval( $value );
                        break;

                    case 'public_calendar':
                        // Convert '1' to true and '0' to false.
                        $settings[$key] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
                        break;

                    // Add more cases here if you have additional settings that require specific processing.
                    
                    default:
                        // For any other settings, keep the value as is (string).
                        $settings[$key] = $value;
                }
            }

            // Optionally, you can cast the associative array to an object.
            return (object) $settings;

            // If you prefer to return an associative array instead of an object, use:
            // return $settings;
        }

        // Return null if no settings are found.
        return null;
    }

    function save_bookings_admin_data(WP_REST_Request $request) {
        $admin_data = $request->get_json_params();
        $settings = $admin_data['settings'];
        $types = $admin_data['types'];
        $addedTypes = array();
        $deletedTypes = array();

        global $wpdb;
        $table_name = $wpdb->prefix . 'firefly_collective_bookings_settings';
        foreach($settings as $setting=>$value) {
            $updated = $wpdb->update(
                $table_name,
                array('setting_value'=> $value),
                array('setting_type' => $setting),
                array('%s'),
                array('%s')
            );

            if ($updated === false) {
                return new WP_Error(
                    'db_update_error',
                    $wpdb->last_error,
                    array('status' => 500)
                );
            }
        }

        $table_name = $wpdb->prefix . 'firefly_collective_bookings_types';
        foreach($types as $typeId=>$data) {
            if (preg_match('/new-type/', $typeId)) {
                $timestamp = preg_replace("/new-type-([0-9]+)/", "$1", $typeId);
                $inserted = $wpdb->insert(
                    $table_name,
                    array('type_title' => $types[$typeId]['title']),
                    array('%s')
                );

                if ($inserted === false) {
                    return new WP_Error(
                        'db_insert_error',
                        __('Failed to save type: ' . $wpdb->last_error),
                        array('status' => 500)
                    );
                }

                $addedTypes[] = array('timestamp'=>$timestamp, 'id'=> $wpdb->insert_id);
            }

            if (preg_match('/^type/', $typeId)) {
                $isRemoval = $types[$typeId]['remove'];

                if ($isRemoval) {
                    $deleted = $wpdb->delete(
                        $table_name,
                        array('type_id' => $types[$typeId]['id']),
                        array('%d')
                    );

                    if ($deleted === false) {
                        return new WP_Error(
                            'db_delete_error',
                            __('Could not delete record: ' . $wpdb->last_error),
                            array('status' => 500)
                        );
                    }
                    $deletedTypes[] = $types[$typeId]['id'];
                }
                else {
                    $updated = $wpdb->update(
                        $table_name,
                        array('type_title' => $types[$typeId]['title']),
                        array('type_id' => intval($types[$typeId]['id'])),
                        array('%s'),
                        array('%d')
                    );

                    if ($updated === false) {
                        return new WP_Error(
                            'db_update_error',
                            __('Failed to update type: ' . $wpdb->last_error),
                            array('status' => 500)
                        );
                    }
                }
            }
        }

        return rest_ensure_response( array('success'       => true,
                                        'added_types'   => $addedTypes,
                                        'deleted_types' => $deletedTypes) );
    }

    function firefly_collective_add_bookings_link() {
        // Top-level menu
        add_menu_page(
            'My Bookings', // Title of the page
            'My Bookings', // Text to show on the menu link
            'manage_options', // Capability requirement to see the link
            'my-bookings', // The 'slug' - file to display when clicking the link
            'firefly_collective_bookings_dashboard', // Function to call when admin link is clicked
            'dashicons-calendar', // Icon for the menu
        );
    }
    add_action('admin_menu', 'firefly_collective_add_bookings_link');

    function firefly_collective_bookings_dashboard() {
        $view_path = plugin_dir_path(__FILE__) . 'apps/backend/views/my-bookings.php';
        if (file_exists($view_path)) {
            require_once $view_path;
        } else {
            // Optional: handle missing file scenario (e.g., log an error)
            wp_die('The app file could not be found.', 'File Not Found', array('response' => 404));
        } 
    }

    function generateToken($length = 21) {
        $bytes = random_bytes($length);
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $charsLength = strlen($chars);
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            $token .= $chars[$byte % $charsLength];
        }
        return $token;
    }

    function sanitizeRequestURI() {
        // Step 1: Retrieve the REQUEST_URI
        $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        // Step 2: Trim leading and trailing slashes
        $trimmedUri = trim($requestUri, '/');

        // Step 3: Remove all illegal URL characters
        $sanitizedUri = filter_var($trimmedUri, FILTER_SANITIZE_URL);

        // Step 4: Encode special characters to prevent XSS
        $safeUri = htmlspecialchars($sanitizedUri, ENT_QUOTES, 'UTF-8');

        return $safeUri;
    }