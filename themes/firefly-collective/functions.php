<?php

    // theme/functions.php

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    // Load template model
    require_once get_template_directory() . '/template.php';

    // Get active template
    global $active_template, $template_path, $template_path_web;
    $active_template    = firefly_collective_get_active_template();
    $template_path      = get_template_directory() . '/templates/' . $active_template;
    $template_path_web  = get_template_directory_uri() . '/templates/' . $active_template;

    // Load template functions
    require_once $template_path . '/functions.php';