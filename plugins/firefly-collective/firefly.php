<?php

    /*
    Plugin Name: Firefly Collective
    Description: Core website features.
    Author: Alex Strait
    */

    // Stripe
    if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
        error_log( 'Composer autoload not found in Firefly Collective plugin.' );
        return;
    }

    require_once __DIR__ . '/vendor/autoload.php';
    \Stripe\Stripe::setApiKey( STRIPE_SECRET_KEY );

    require_once plugin_dir_path(__FILE__) . 'includes/firefly-functions.php';

    $default_models_dir = plugin_dir_path(__FILE__) . 'templates/default/models/';

    // Load all default template models for activation/deactivation hooks
    $ffc_load_default_models = function() use ($default_models_dir) {
        // The main plugin body already loads the ACTIVE template's models by
        // the time the activation hook fires. Forked templates (firefly,
        // firefly-v2) declare the same function names as default's models,
        // so loading default's set on top is a fatal redeclare — and it's
        // unnecessary: the loaded fork provides the same hook functions.
        // Only load default's models when no template set is loaded at all.
        if (function_exists('firefly_collective_create_tables')) return;
        $templates_config_path = plugin_dir_path(__FILE__) . 'templates.json';
        if (!file_exists($templates_config_path)) return;
        $config = json_decode(file_get_contents($templates_config_path), true);
        foreach (($config['default'] ?? []) as $model) {
            $path = $default_models_dir . "$model.php";
            if (file_exists($path)) {
                require_once $path;
            }
        }
    };

    register_activation_hook(__FILE__, function() use ($ffc_load_default_models) {
        $ffc_load_default_models();
        if (function_exists('firefly_collective_create_tables')) firefly_collective_create_tables();
        if (function_exists('firefly_collective_pricing_init')) firefly_collective_pricing_init();
        if (function_exists('firefly_collective_init_campaigns')) firefly_collective_init_campaigns();
    });

    register_deactivation_hook(__FILE__, function() use ($ffc_load_default_models) {
        $ffc_load_default_models();
        if (function_exists('firefly_collective_drop_all_bookings_tables')) firefly_collective_drop_all_bookings_tables();
        if (function_exists('drop_ffc_pricing_tables')) drop_ffc_pricing_tables();
    });
