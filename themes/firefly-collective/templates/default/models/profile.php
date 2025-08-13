<?php

    // New: Update profile callback
    function handle_profile_update(WP_REST_Request $request) {
        $user_id = decrypt_with_auth_key($_COOKIE['auth_id']);
        if ( ! $user_id ) {
            return new WP_Error('not_logged_in', 'User not logged in', array('status' => 401));
        }
        $first_name = sanitize_text_field($request->get_param('first_name'));
        $last_name  = sanitize_text_field($request->get_param('last_name'));
        $email      = sanitize_email($request->get_param('email'));
        if ( ! is_email( $email ) ) {
            return new WP_Error('invalid_email', 'Invalid email', array('status' => 400));
        }
        $user_data = array(
            'ID'         => $user_id,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'user_email' => $email,
        );
        $result = wp_update_user( $user_data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'success' => true, 'message' => 'Profile updated' ) );
    }

    // New: Password reset callback
    function handle_password_reset(WP_REST_Request $request) {
        $user_id = decrypt_with_auth_key($_COOKIE['auth_id']);
        if ( ! $user_id ) {
            return new WP_Error('not_logged_in', 'User not logged in', array('status' => 401));
        }
        $user = get_user_by('id', $user_id);
        if ( ! $user ) {
            return new WP_Error('user_not_found', 'User not found', array('status' => 404));
        }
        // Use WordPress function to send reset email
        $result = retrieve_password($user->user_login);
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'success' => true, 'message' => 'Password reset email sent' ) );
    }
