<?php

    // template/functions.php

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    global $template_path;

    // Load models
    // NOTE: 'meta' (OG/Twitter) + 'schema' (JSON-LD) used to live in
    // templates/default/models/. Both were promoted to the framework in
    // themes/firefly-collective/models/seo-{meta,schema}.php so every
    // template inherits SEO output. Don't re-list them here.
    $models = array(
                'init',         'rest',
                'user',
                'contact',      'signup',
                'signin',       'blogs',
                'bookings',     'mail',
                'view',         'pages',
                'util',
                'encrypt',      'profile',
                'app',          'editor-preview',
                'social-share', 'redirects');
                
    foreach($models as $model) {
        $model_path = $template_path . "/models/$model.php";
        if (file_exists($model_path)) {
            require_once $model_path;
        }
    }