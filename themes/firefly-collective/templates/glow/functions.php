<?php

    // template/functions.php

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    global $template_path;

    // Load models - customize MUST be loaded first for template system
    $models = array(  
                'init',         'view',         
                'pages',        'nav',
                'customize',    'rest');
                
    foreach($models as $model) {
        $model_path = $template_path . "/models/$model.php";
        if (file_exists($model_path)) {
            require_once $model_path;
        }
    }