<?php

    // Handle Contact Form Submission
    function handle_contact_form_submission(WP_REST_Request $request) {
        $params = $request->get_params();

        $name    = sanitize_text_field($params['name'] ?? '');
        $email   = sanitize_email($params['email'] ?? '');
        $message = sanitize_textarea_field($params['message'] ?? '');

        if (empty($name) || empty($email) || empty($message) || !is_email($email)) {
            return new WP_Error('invalid_input', __('Please provide valid name, email, and message.', 'firefly-collective'), array('status' => 400));
        }

        // Save to database
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'ffc_submissions',
            array(
                'form_type' => 'contact',
                'name'      => $name,
                'email'     => $email,
                'message'   => $message,
            ),
            array('%s', '%s', '%s', '%s')
        );

        // Admin Email
        // ----------------------------------------------------------------------------------------
        $message = nl2br($message);
        $subject = "{$name} has sent you a message";
        $html = "
            <html>
            <head>
                <title>Website contact</title>
            </head>
            <body>
                <p>{$message}</p>

                <p>from: <a href='mailto:donotreply@fireflycollective.org'>donotreply@fireflycollective.org</a></p>
            </body>
            </html>
            ";
        send_html_mail(NULL, $subject, $html, true);
        // ----------------------------------------------------------------------------------------

        return rest_ensure_response(array('message' => __('Message sent successfully.', 'firefly-collective')));
    }

    // Handle Newsletter Signup (footer form) — saves to submissions table, no provider integration
    function handle_newsletter_signup(WP_REST_Request $request) {
        $email = sanitize_email($request->get_param('email') ?? '');

        if (empty($email) || !is_email($email)) {
            return new WP_Error('invalid_email', __('Please provide a valid email address.', 'firefly-collective'), array('status' => 400));
        }

        global $wpdb;

        // Prevent duplicate newsletter signups
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ffc_submissions WHERE form_type = 'newsletter' AND email = %s LIMIT 1",
            $email
        ));

        if ($existing) {
            return rest_ensure_response(array('message' => __('You\'re already subscribed.', 'firefly-collective')));
        }

        $wpdb->insert(
            $wpdb->prefix . 'ffc_submissions',
            array(
                'form_type' => 'newsletter',
                'name'      => '',
                'email'     => $email,
                'message'   => 'Newsletter signup',
            ),
            array('%s', '%s', '%s', '%s')
        );

        // Notify admin
        $subject = "New newsletter signup: {$email}";
        $html = "
            <html>
            <head><title>New newsletter signup</title></head>
            <body>
                <p>A new email was added to the newsletter list:</p>
                <p><strong>{$email}</strong></p>
            </body>
            </html>
            ";
        send_html_mail(NULL, $subject, $html, true);

        return rest_ensure_response(array('message' => __('Thanks for subscribing!', 'firefly-collective')));
    }