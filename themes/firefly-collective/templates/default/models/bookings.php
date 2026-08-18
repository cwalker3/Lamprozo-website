<?php

    function get_firefly_collective_calendar($is_admin = false) {
        global $wpdb;

        // Define the table name with the WordPress table prefix
        $table_name = $wpdb->prefix . 'firefly_collective_bookings';

        // Get current date components
        $current_year  = intval( date( 'Y' ) );
        $current_month = intval( date( 'n' ) ); // Numeric representation of a month, without leading zeros (1-12)
        $current_month--;
        $current_day   = intval( date( 'j' ) ); // Day of the month without leading zeros (1-31)

        $admin_statement = '';
        if (!$is_admin) $admin_statement = "AND request_flag = '0'";
        
        // Define the SQL query with proper date comparison logic
        $query = "SELECT *
                FROM {$table_name}
                WHERE (day_number >= $current_day AND month_number >= $current_month AND year_number >= $current_year $admin_statement)
                ORDER BY year_number DESC, month_number DESC, day_number DESC, start_time ASC";

        // $query = "SELECT * FROM {$table_name}";
        // $query = "DELETE FROM wpka_firefly_collective_bookings";

        // Prepare the SQL statement with the current date components
        $prepared_query = $wpdb->prepare($query);

        // Execute the query and retrieve the results
        $results = $wpdb->get_results($prepared_query);

        // Check if any results were returned
        if ( ! empty($results) ) {
            return $results;
        }

        // Return null if no bookings are found
        return null;
    }

    function request_appointment(WP_REST_Request $request) {
        $calData = $request->get_params();

        global $wpdb;
        $table_name = $wpdb->prefix . 'firefly_collective_bookings';

        // Sanitize data
        $id            = intval($calData['id']);
        $first_name    = sanitize_text_field($calData['first_name']);
        $last_name     = sanitize_text_field($calData['last_name']);
        $email         = sanitize_email($calData['email']);
        $phone         = sanitize_text_field($calData['phone']);
        $type          = sanitize_text_field($calData['type']);
        $message       = sanitize_textarea_field($calData['msg']);
        $day_number    = intval($calData['day']);
        $month_number  = intval($calData['month']);
        $year_number   = intval($calData['year']);
        $start_time    = sanitize_text_field($calData['start-time']);
        $end_time      = sanitize_text_field($calData['end-time']);
        $request_flag  = sanitize_text_field($calData['request_flag']);

        // Define WHERE clause (Assuming you have an ID or unique identifier)
        $where = array('id' => $id); // Ensure 'id' exists in $calData

        // Update the existing record
        $updated = $wpdb->update(
            $table_name,
            array(
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'email'        => $email,
                'phone'        => $phone,
                'type_name'    => $type,
                'message'      => $message,
                'day_number'   => $day_number,
                'month_number' => $month_number,
                'year_number'  => $year_number,
                'start_time'   => $start_time,
                'end_time'     => $end_time,
                'request_flag' => $request_flag
            ),
            $where,
            array(
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s'
            ),
            array('%d')
        );

        if ($updated === false) {
            return new WP_Error(
                'db_update_error',
                __($wpdb->last_error),
                array('status' => 500)
            );
        }
        
        $start_time = format_time($start_time);
        $end_time = format_time($end_time);

        // User Email
        // ----------------------------------------------------------------------------------------
        $subject = "Your $type Appointment Has Been Requested";
        $html = "
            <html>
            <head>
                <title>Appointment Request</title>
            </head>
            <body>
                <p>{$type} appointment has been requested.</p>
                <p>
                    <strong>Name:</strong> {$first_name} {$last_name}<br>
                    <strong>Email:</strong> {$email}<br>
                    <strong>Phone:</strong> {$phone}<br>
                    <strong>Start Time:</strong> {$start_time}<br>
                    <strong>End Time:</strong> {$end_time}
                </p>

                <p style='color:#666;font-size:12px;'>Sent from the website.</p>
            </body>
            </html>
            ";
        send_html_mail($email, $subject, $html);

        // Admin Email
        // ----------------------------------------------------------------------------------------
        $message = nl2br($message);
        $subject = "$type Appointment Has Been Requested";
        $html = "
            <html>
            <head>
                <title>Appointment Request</title>
            </head>
            <body>
                <p>{$type} appointment has been requested.</p>
                <p>
                    <strong>Name:</strong> {$first_name} {$last_name}<br>
                    <strong>Email:</strong> {$email}<br>
                    <strong>Phone:</strong> {$phone}<br>
                    <strong>Start Time:</strong> {$start_time}<br>
                    <strong>End Time:</strong> {$end_time}<br><br>
                    <strong>Message:</strong><br>
                    {$message}
                </p>

                <p style='color:#666;font-size:12px;'>Sent from the website.</p>
            </body>
            </html>
            ";
        send_html_mail(NULL, $subject, $html, true);
        // ----------------------------------------------------------------------------------------

        return rest_ensure_response( array('success' => true, 
                                            'message' => 'Appointment request succeeded!') );
    }