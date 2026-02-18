<?php
    
    // plugin/functions.php

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    require_once('const.php');

    // Load core backend models (always loaded regardless of template)
    $core_models = array('cache', 'db', 'init', 'util', 'geo-schema', 'geo-admin', 'analytics', 'debug', 'link-tracking');
    foreach($core_models as $model) {
        require_once(plugin_dir_path(__FILE__) . "apps/backend/models/$model.php");
    }

    // Load template-specific models
    // Get active template directly from WordPress option
    $active_template = get_option('firefly_collective_active_template', 'firefly');

    // Validate template exists, fallback to 'firefly' if not
    $templates_dir = plugin_dir_path(__FILE__) . "../templates/";
    if (!is_dir($templates_dir . $active_template)) {
        $active_template = 'firefly';
    }

    $template_models_dir = plugin_dir_path(__FILE__) . "../templates/{$active_template}/models/";

    // Load template models from config file (or fallback to hardcoded defaults)
    $templates_config_path = plugin_dir_path(__FILE__) . "../templates.json";

    if (file_exists($templates_config_path)) {
        $templates_config = json_decode(file_get_contents($templates_config_path), true);
        $template_models = $templates_config[$active_template] ?? array('rest');
    } else {
        // Fallback to hardcoded defaults if config doesn't exist
        switch ($active_template) {
            case 'default':
                $template_models = array('rest', 'bookings', 'orders', 'pricing', 'campaign', 'payment');
                break;

            case 'firefly':
                $template_models = array('rest', 'bookings', 'orders', 'pricing', 'campaign', 'payment', 'referral-program');
                break;

            case 'glow':
                $template_models = array('rest');
                break;

            default:
                $template_models = array('rest');
                break;
        }
    }

    // Load template-specific models
    foreach($template_models as $model) {
        $model_path = $template_models_dir . "$model.php";
        if (file_exists($model_path)) {
            require_once($model_path);
        }
    }