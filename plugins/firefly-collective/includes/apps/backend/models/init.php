<?php

    // plugin/models/init.php

    function enqueue_general_styles_and_scripts($hook) {
        $target_dir_url = plugin_dir_url( dirname( __FILE__, 1 ) ); // go up 1 directory from models
        $plugin_uri = '/' . ltrim( str_replace( home_url(), '', $target_dir_url ), '/' );
        $unique_id = uniqid();
        wp_enqueue_style('custom-properties-css', $plugin_uri . 'assets/css/custom-properties.css', array(), $unique_id);
    }
    add_action('admin_enqueue_scripts', 'enqueue_general_styles_and_scripts');
