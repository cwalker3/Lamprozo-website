<?php

    // plugin/models/db.php

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