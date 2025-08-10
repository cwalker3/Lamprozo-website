<?php

    // theme/functions.php

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    // Load models - customize MUST be loaded first for template system
    $models = array(
                'init',         'rest',
                'user',         'meta',
                'template',     'contact',
                'signup',       'signin',
                'blogs',        'bookings',
                'mail',         'view',
                'pages',        'util',
                'encrypt',      'profile',
                'app');
                
    foreach($models as $model) {
        $model_path = get_template_directory() . "/models/$model.php";
        if (file_exists($model_path)) {
            require_once $model_path;
        }
    }