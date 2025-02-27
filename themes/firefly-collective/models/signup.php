<?php

    /**
     * Handle Signup Submission.
     */
    function handle_signup_submission(WP_REST_Request $request) {
        $params = $request->get_params();

        $fname = sanitize_text_field($params['fname'] ?? '');
        $lname = sanitize_text_field($params['lname'] ?? '');
        $email = sanitize_email($params['email'] ?? '');
        $phone = sanitize_text_field($params['phone'] ?? '');

        if (empty($fname) || empty($lname) || empty($email) || !is_email($email)) {
            return new WP_Error('invalid_input', __('Please provide valid first name, last name, and email.', 'firefly-collective'), array('status' => 400));
        }

        if (email_exists($email)) {
            return new WP_Error('user_exists', __('An account with this email already exists.', 'firefly-collective'), array('status' => 400));
        }

        $username = sanitize_user($params['username'] ?? '');
        $password_input = sanitize_text_field($params['password'] ?? '');

        if (!empty($username) || !empty($password_input)) {
            if (empty($username) || empty($password_input)) {
                return new WP_Error('invalid_input', __('Please provide both username and password.', 'firefly-collective'), array('status' => 400));
            }
            if (username_exists($username)) {
                return new WP_Error('user_exists', __('Username already exists.', 'firefly-collective'), array('status' => 400));
            }
            $password = $password_input;
        } else {
            $password = wp_generate_password();
            $username = sanitize_user($email, true);
        }

        $userdata = array(
            'user_login' => $username,
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

        if (!empty($phone)) {
            update_user_meta($user_id, 'phone', $phone);
        }

        // Send welcome email to the new user.
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

        // Send notification email to admin.
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

        // Encrypt the user ID using our encryption function.
        $encrypted_user_id = encrypt_with_auth_key($user_id);

        // Set the auth_id cookie using PHP's setcookie().
        // Adjust the domain parameter if needed (omit or set to your domain).
        $cookie_options = [
            'expires'  => time() + 3600,  // 1 hour
            'path'     => '/',
            // 'domain'   => 'yourdomain.com', // Uncomment and set for production if required.
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        setcookie('auth_id', $encrypted_user_id, $cookie_options);

        return rest_ensure_response(array('message' => __('Signup successful!', 'firefly-collective')));
    }
