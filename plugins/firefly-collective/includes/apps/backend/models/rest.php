<?php

    // theme/models/rest.php

    function register_plugin_custom_api_endpoints() {
        register_rest_route('custom-api/v1', '/save-calendar', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_save_calendar',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/handle-appointment', array(
            'methods'             => 'POST',
            'callback'            => 'handle_apt_confirm',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/save-bookings-admin-data', array(
            'methods'             => 'POST',
            'callback'            => 'save_bookings_admin_data',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/update-project', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_local_update_project',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('firefly-collective/v1', '/update_project', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_handle_project_update',
            'permission_callback' => '__return_true'
        ));

        register_rest_route('custom-api/v1', '/save-pricing', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_save_pricing',
            'permission_callback' => 'verify_rest_nonce'
        ));

        // Orders API endpoints
        register_rest_route('custom-api/v1', '/place-order', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_place_order',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/get-orders', array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_get_orders',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/delete-order', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_delete_order',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/update-order-status', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_update_order_status',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/delete-orders-bulk', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_delete_orders_bulk',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/update-orders-status-bulk', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_update_orders_status_bulk',
            'permission_callback' => 'verify_rest_nonce'
        ));

        // Lookup data endpoints
        register_rest_route('custom-api/v1', '/get-features', array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_get_features',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/get-options', array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_get_options',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/get-addons', array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_get_addons',
            'permission_callback' => 'verify_rest_nonce'
        ));

        register_rest_route('custom-api/v1', '/get-users', array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_get_users',
            'permission_callback' => 'verify_rest_nonce'
        ));

        // Payment
        register_rest_route('custom-api/v1', '/create-payment-intent', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_create_payment_intent',
            'permission_callback' => 'verify_rest_nonce'
        ));
        
        register_rest_route('custom-api/v1', '/stripe-webhook', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_handle_stripe_webhook',
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('custom-api/v1', '/update-payment-status', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_update_payment_status',
            'permission_callback' => 'verify_rest_nonce'
        ));
    }
    add_action('rest_api_init', 'register_plugin_custom_api_endpoints');