<?php
/**
 * Lamprozo - REST API
 */

function lamprozo_register_rest_routes() {
    register_rest_route('firefly/lamprozo/v1', '/info', array(
        'methods' => 'GET',
        'callback' => 'lamprozo_get_info',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
}
add_action('rest_api_init', 'lamprozo_register_rest_routes');

function lamprozo_get_info() {
    return array(
        'template' => 'lamprozo',
        'version' => '1.0.0',
        'status' => 'active',
        'message' => 'Template system is working!'
    );
}
