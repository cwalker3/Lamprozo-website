<?php

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    // Load models
    $models = array(
                'init',     'rest',     
                'meta',     'contact',
                'signup',   'signin',   
                'blogs',    'bookings',
                'mail',     'view',
                'pages',    'util',
                'encrypt',  'profile',
                'payment');
    foreach($models as $model) {
        require_once(get_template_directory()."/models/$model.php");
    }