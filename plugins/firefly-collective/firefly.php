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

    register_activation_hook(__FILE__, 'firefly_collective_create_tables');

    define('LIVE_DEV_ENDPOINT', 'https://fireflycollective.org/wp-json/firefly-collective/v1/update_project');

    // Bookings
    register_deactivation_hook(__FILE__, 'firefly_collective_drop_all_bookings_tables');

    // Pricing system
    register_activation_hook(__FILE__, 'firefly_collective_pricing_init');
    register_deactivation_hook(__FILE__, 'drop_ffc_pricing_tables');

    // Campaigns
    register_activation_hook(__FILE__, 'firefly_collective_init_campaigns');
    // register_deactivation_hook(__FILE__, 'firefly_collective_terminate_campaigns');