<?php

function check_username_exists(WP_REST_Request $request) {
    $username = sanitize_user($request->get_param('username') ?? '');
    if (empty($username)) {
        return new WP_Error('invalid_input', __('Username is required.', 'alex-strait'), array('status' => 400));
    }
    return rest_ensure_response(array('exists' => username_exists($username) ? true : false));
}

function check_email_exists(WP_REST_Request $request) {
    $email = sanitize_email($request->get_param('email') ?? '');
    if (empty($email)) {
        return new WP_Error('invalid_input', __('Email is required.', 'alex-strait'), array('status' => 400));
    }
    return rest_ensure_response(array('exists' => email_exists($email) ? true : false));
}