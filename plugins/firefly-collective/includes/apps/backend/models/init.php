<?php

    function enqueue_general_styles_and_scripts($hook) {
        $theme_path = get_template_directory_uri();
        $unique_id = uniqid();
        wp_enqueue_style('custom-properties-css', $theme_path . '/assets/css/custom-properties.css', array(), $unique_id);
    }
    add_action('admin_enqueue_scripts', 'enqueue_general_styles_and_scripts');