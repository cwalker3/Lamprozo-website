<?php

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