<?php
    
    // plugin/functions.php

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    require_once('const.php');

    // Load core backend models (always loaded regardless of template)
    $core_models = array('cache', 'db', 'init', 'util');
    foreach($core_models as $model) {
        require_once(plugin_dir_path(__FILE__) . "apps/backend/models/$model.php");
    }

    // Load template-specific models
    // Get active template from theme (requires theme's template.php to be loaded)
    if (function_exists('firefly_collective_get_active_template')) {
        $active_template = firefly_collective_get_active_template();
    } else {
        // Fallback to default if theme function not available yet
        $active_template = 'default';
    }

    $template_models_dir = plugin_dir_path(__FILE__) . "../templates/{$active_template}/models/";

    // Define the models we expect for each template
    if ($active_template === 'glow') {
        $template_models = array('rest');
    } else {
        // Default template includes all non-core models
        $template_models = array('rest', 'bookings', 'orders', 'pricing', 'campaign', 'payment');
    }

    // Load template-specific models
    foreach($template_models as $model) {
        $model_path = $template_models_dir . "$model.php";
        if (file_exists($model_path)) {
            require_once($model_path);
        }
    }