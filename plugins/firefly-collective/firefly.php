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

    // Bookings
    register_activation_hook(__FILE__, function() use ($default_models_dir) {
        if (file_exists($default_models_dir . 'bookings.php')) {
            require_once $default_models_dir . 'bookings.php';
            if (function_exists('firefly_collective_create_tables')) {
                firefly_collective_create_tables();
            }
        }
    });
    register_deactivation_hook(__FILE__, function() use ($default_models_dir) {
        if (file_exists($default_models_dir . 'bookings.php')) {
            require_once $default_models_dir . 'bookings.php';
            if (function_exists('firefly_collective_drop_all_bookings_tables')) {
                firefly_collective_drop_all_bookings_tables();
            }
        }
    });

    // Pricing system
    register_activation_hook(__FILE__, function() use ($default_models_dir) {
        if (file_exists($default_models_dir . 'pricing.php')) {
            require_once $default_models_dir . 'pricing.php';
            if (function_exists('firefly_collective_pricing_init')) {
                firefly_collective_pricing_init();
            }
        }
    });
    register_deactivation_hook(__FILE__, function() use ($default_models_dir) {
        if (file_exists($default_models_dir . 'pricing.php')) {
            require_once $default_models_dir . 'pricing.php';
            if (function_exists('drop_ffc_pricing_tables')) {
                drop_ffc_pricing_tables();
            }
        }
    });

    // Campaigns
    register_activation_hook(__FILE__, function() use ($default_models_dir) {
        if (file_exists($default_models_dir . 'campaign.php')) {
            require_once $default_models_dir . 'campaign.php';
            if (function_exists('firefly_collective_init_campaigns')) {
                firefly_collective_init_campaigns();
            }
        }
    });
