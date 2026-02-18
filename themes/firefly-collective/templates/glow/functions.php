<?php

    // template/functions.php

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    global $template_path;

    // Load models
    $models = array(
                'init',         'view',
                'pages',        'rest',
                'mail',         'blogs',
                'contact',      'util');
                
    foreach($models as $model) {
        $model_path = $template_path . "/models/$model.php";
        if (file_exists($model_path)) {
            require_once $model_path;
        }
    }
