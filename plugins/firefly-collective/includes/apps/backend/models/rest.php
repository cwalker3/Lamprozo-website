<?php

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

    register_rest_route('custom-api/v1', '/place-order', array(
    'methods'             => 'POST',
    'callback'            => 'firefly_collective_place_order',
    'permission_callback' => 'verify_rest_nonce'
));
}
add_action('rest_api_init', 'register_plugin_custom_api_endpoints');