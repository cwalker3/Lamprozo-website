<?php

    // Handle Signup Submission
    function handle_signup_submission(WP_REST_Request $request) {
        $params = $request->get_params();

        // Sanitize and Validate Inputs
        $fname = sanitize_text_field($params['fname'] ?? '');
        $lname = sanitize_text_field($params['lname'] ?? '');
        $email = sanitize_email($params['email'] ?? '');
        $phone = sanitize_text_field($params['phone'] ?? '');

        if (empty($fname) || empty($lname) || empty($email) || !is_email($email)) {
            return new WP_Error('invalid_input', __('Please provide valid first name, last name, and email.', 'firefly-collective'), array('status' => 400));
        }

        // Check if user already exists
        if (email_exists($email)) {
            return new WP_Error('user_exists', __('An account with this email already exists.', 'firefly-collective'), array('status' => 400));
        }

        // Generate a strong random password
        $password = wp_generate_password();

        // Create the user
        $userdata = array(
            'user_login' => sanitize_user($email, true),
            'user_email' => $email,
            'first_name' => $fname,
            'last_name'  => $lname,
            'user_pass'  => $password,
            'role'       => 'subscriber',
        );

        $user_id = wp_insert_user($userdata);

        if (is_wp_error($user_id)) {
            return new WP_Error('user_creation_failed', $user_id->get_error_message(), array('status' => 500));
        }

        // Update user meta
        if (!empty($phone)) {
            update_user_meta($user_id, 'phone', $phone);
        }

        // User Email
        // ----------------------------------------------------------------------------------------
        $site_name = get_bloginfo('name');
        $subject = "Thank you for signing up with {$site_name}";
        $html = "
            <html>
            <head>
                <title>Welcome to {$site_name}</title>
            </head>
            <body>
                <p>Thank you for signing up with {$site_name}.</p>
                <p>
                    <strong>Name:</strong> {$fname} {$lname}<br>
                    <strong>Email:</strong> {$email}<br>
                    <strong>Phone:</strong> {$phone}
                </p>

                <p>from: <a href='mailto:donotreply@fireflycollective.org'>donotreply@fireflycollective.org</a></p>
            </body>
            </html>
            ";
        send_html_mail($email, $subject, $html);

        // Admin Email
        // ----------------------------------------------------------------------------------------
        $subject = "{$fname} {$lname} has signed up on the website!";
        $html = "
            <html>
            <head>
                <title>Website Signup</title>
            </head>
            <body>
                <p>{$fname} {$lname} has signed up with the website.</p>
                <p>
                    <strong>Name:</strong> {$fname} {$lname}<br>
                    <strong>Email:</strong> {$email}<br>
                    <strong>Phone:</strong> {$phone}
                </p>

                <p>from: <a href='mailto:donotreply@fireflycollective.org'>donotreply@fireflycollective.org</a></p>
            </body>
            </html>
            ";
        send_html_mail(NULL, $subject, $html, true);
        // ----------------------------------------------------------------------------------------

        return rest_ensure_response(array('message' => __('Signup successful!', 'firefly-collective')));
    }