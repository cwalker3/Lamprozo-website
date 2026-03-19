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
    $templates_config_path = plugin_dir_path(__FILE__) . "../templates.json";
    $templates_config = json_decode(file_get_contents($templates_config_path), true);
    $default_template = $templates_config['default_template'] ?? 'default';

    // Get active template from WordPress option, falling back to config default
    $active_template = get_option('firefly_collective_active_template', $default_template);

    // Validate template exists in config, fallback to config default if not
    $templates_dir = plugin_dir_path(__FILE__) . "../templates/";
    if (!isset($templates_config[$active_template]) || !is_dir($templates_dir . $active_template)) {
        $active_template = $default_template;
    }

    $template_models_dir = plugin_dir_path(__FILE__) . "../templates/{$active_template}/models/";
    $template_models = $templates_config[$active_template] ?? array('rest');

    // Load template-specific models
    foreach($template_models as $model) {
        $model_path = $template_models_dir . "$model.php";
        if (file_exists($model_path)) {
            require_once($model_path);
        }
    }