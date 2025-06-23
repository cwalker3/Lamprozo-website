<?php

    // plugin/models/db.php

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