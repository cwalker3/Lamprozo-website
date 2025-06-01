<?php

    // plugin/models/payment.php

    // Initialize Stripe with your API keys
    function firefly_collective_stripe_init() {
        // Use constant if defined, fallback to option if not
        $secret_key = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : get_option('firefly_stripe_secret_key', '');
        
        // Initialize Stripe
        \Stripe\Stripe::setApiKey($secret_key);
        
        // Set API version
        \Stripe\Stripe::setApiVersion('2023-10-16');
    }

    // Create a payment intent
    function firefly_collective_create_payment_intent($request) {
        try {
            firefly_collective_stripe_init();
            
            // Parse the request body
            $params = $request->get_json_params();
            
            // Get order ID from request
            $order_id = isset($params['orderID']) ? sanitize_text_field($params['orderID']) : '';
            
            if (empty($order_id)) {
                return new WP_Error('missing_order_id', 'Order ID is required', array('status' => 400));
            }
            
            // Get the order total from database
            global $wpdb;
            $table_name = $wpdb->prefix . 'ffc_orders';
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(totalPrice) FROM $table_name WHERE orderID = %s",
                $order_id
            ));
            
            if (!$total) {
                return new WP_Error('invalid_order', 'Order not found or has no total', array('status' => 400));
            }
            
            // Convert to cents for Stripe
            $amount = round($total * 100);
            
            // Create the payment intent
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount' => $amount,
                'currency' => 'usd', // Change to your currency
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
                'metadata' => [
                    'order_id' => $order_id,
                    'wordpress_user_id' => get_current_user_id()
                ],
                'description' => 'Order #' . $order_id
            ]);
            
            // Save the payment intent ID to the database
            $wpdb->update(
                $table_name,
                array('payment_intent_id' => $payment_intent->id),
                array('orderID' => $order_id),
                array('%s'),
                array('%s')
            );
            
            // Return client secret to the frontend
            return array(
                'success' => true,
                'clientSecret' => $payment_intent->client_secret
            );
        } catch (Exception $e) {
            return new WP_Error('stripe_error', $e->getMessage(), array('status' => 500));
        }
    }

    // Handle Stripe webhooks
    function firefly_collective_handle_stripe_webhook($request) {
        try {
            firefly_collective_stripe_init();
        
            // Get the webhook secret from wp-config or options
            $webhook_secret = defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : get_option('firefly_stripe_webhook_secret', '');
            
            // Get the event
            $payload = @file_get_contents('php://input');
            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
            
            // Verify the event
            $event = null;
            try {
                $event = \Stripe\Webhook::constructEvent(
                    $payload, $sig_header, $webhook_secret
                );
            } catch(\UnexpectedValueException $e) {
                // Invalid payload
                return new WP_REST_Response('Invalid payload', 400);
            } catch(\Stripe\Exception\SignatureVerificationException $e) {
                // Invalid signature
                return new WP_REST_Response('Invalid signature', 400);
            }
            
            // Handle specific event types
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $payment_intent = $event->data->object;
                    $order_id = $payment_intent->metadata->order_id;
                    
                    // Update order status to 'paid'
                    if ($order_id) {
                        firefly_collective_update_order_payment_status($order_id, 'paid');
                    }
                    break;
                    
                case 'payment_intent.payment_failed':
                    $payment_intent = $event->data->object;
                    $order_id = $payment_intent->metadata->order_id;
                    
                    // Update order status to 'failed'
                    if ($order_id) {
                        firefly_collective_update_order_payment_status($order_id, 'failed');
                    }
                    break;
            }
            
            return new WP_REST_Response('Webhook received', 200);
        } catch (Exception $e) {
            return new WP_REST_Response('Error: ' . $e->getMessage(), 500);
        }
    }

    // Update order payment status
    function firefly_collective_update_order_payment_status($order_id, $status) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_orders';
        
        return $wpdb->update(
            $table_name,
            array('status' => $status),
            array('orderID' => $order_id),
            array('%s'),
            array('%s')
        );
    }

    // Update payment status endpoint
    function firefly_collective_update_payment_status($request) {
        $params = $request->get_json_params();
        
        $order_id = isset($params['orderID']) ? sanitize_text_field($params['orderID']) : '';
        $status = isset($params['status']) ? sanitize_text_field($params['status']) : '';
        
        if (empty($order_id) || empty($status)) {
            return new WP_Error('missing_params', 'Order ID and status are required', array('status' => 400));
        }
        
        $updated = firefly_collective_update_order_payment_status($order_id, $status);
        
        if ($updated) {
            firefly_collective_orders_email($order_id, $status);
            return array(
                'success' => true,
                'message' => 'Order status updated'
            );
        } else {
            return new WP_Error('update_failed', 'Failed to update order status', array('status' => 500));
        }
    }

    /**
     * Issue a refund for a given orderID.
     */
    function firefly_collective_refund_payment($request) {
        // 1. Verify user is logged in
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'not_logged_in', 
                'You must be logged in to refund an order.', 
                ['status' => 401]
            );
        }

        // 2. Get orderID from the request body
        $params   = $request->get_json_params();
        $order_id = isset( $params['orderID'] ) ? sanitize_text_field( $params['orderID'] ) : '';

        if ( empty( $order_id ) ) {
            return new WP_Error(
                'missing_order_id', 
                'Order ID is required.', 
                ['status' => 400]
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ffc_orders';

        // 3. Fetch the stored payment_intent_id (and ensure the order exists)
        $pi_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT payment_intent_id FROM $table WHERE orderID = %s",
            $order_id
        ) );

        if ( ! $pi_id ) {
            return new WP_Error(
                'no_payment_intent', 
                'No payment intent found for this order or order not found.', 
                ['status' => 404]
            );
        }

        // 4. Initialize Stripe
        firefly_collective_stripe_init();

        try {
            // 5. Create a full refund via Stripe
            $refund = \Stripe\Refund::create([
                'payment_intent' => sanitize_text_field( $pi_id ),
            ]);

            if ( $refund->status !== 'succeeded' ) {
                // If Stripe didn’t return `succeeded`, treat as failure
                return new WP_Error(
                    'refund_failed', 
                    'Stripe refund status: ' . $refund->status, 
                    ['status' => 500]
                );
            }

            // 6. Update our DB: set status = 'refunded'
            $updated = $wpdb->update(
                $table,
                ['status' => 'refunded'],
                ['orderID' => $order_id],
                ['%s'],
                ['%s']
            );

            if ( $updated === false ) {
                return new WP_Error(
                    'db_error', 
                    'Failed to update order status: ' . $wpdb->last_error, 
                    ['status' => 500]
                );
            }

            // 7. Optionally, send confirmation emails (reuse your existing function)
            firefly_collective_orders_email( $order_id, 'refunded' );

            return [
                'success' => true,
                'message' => 'Order refunded successfully.',
                'refund_id' => $refund->id,
            ];

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            // Catch any Stripe API error (e.g. insufficient funds, invalid PI ID, etc.)
            return new WP_Error(
                'stripe_refund_error',
                'Stripe error: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    // Add Stripe settings page to admin
    function firefly_collective_stripe_settings_init() {
        add_settings_section(
            'firefly_stripe_settings_section',
            'Stripe Settings',
            'firefly_collective_stripe_settings_section_callback',
            'general'
        );
        
        // Test mode
        add_settings_field(
            'firefly_stripe_test_mode',
            'Test Mode',
            'firefly_collective_stripe_test_mode_callback',
            'general',
            'firefly_stripe_settings_section'
        );
        register_setting('general', 'firefly_stripe_test_mode');
        
        // Test publishable key
        add_settings_field(
            'firefly_stripe_test_publishable_key',
            'Test Publishable Key',
            'firefly_collective_stripe_test_publishable_key_callback',
            'general',
            'firefly_stripe_settings_section'
        );
        register_setting('general', 'firefly_stripe_test_publishable_key');
        
        // Test secret key
        add_settings_field(
            'firefly_stripe_test_secret_key',
            'Test Secret Key',
            'firefly_collective_stripe_test_secret_key_callback',
            'general',
            'firefly_stripe_settings_section'
        );
        register_setting('general', 'firefly_stripe_test_secret_key');
        
        // Test webhook secret
        add_settings_field(
            'firefly_stripe_test_webhook_secret',
            'Test Webhook Secret',
            'firefly_collective_stripe_test_webhook_secret_callback',
            'general',
            'firefly_stripe_settings_section'
        );
        register_setting('general', 'firefly_stripe_test_webhook_secret');
        
        // Live publishable key
        add_settings_field(
            'firefly_stripe_live_publishable_key',
            'Live Publishable Key',
            'firefly_collective_stripe_live_publishable_key_callback',
            'general',
            'firefly_stripe_settings_section'
        );
        register_setting('general', 'firefly_stripe_live_publishable_key');
        
        // Live secret key
        add_settings_field(
            'firefly_stripe_live_secret_key',
            'Live Secret Key',
            'firefly_collective_stripe_live_secret_key_callback',
            'general',
            'firefly_stripe_settings_section'
        );
        register_setting('general', 'firefly_stripe_live_secret_key');
        
        // Live webhook secret
        add_settings_field(
            'firefly_stripe_live_webhook_secret',
            'Live Webhook Secret',
            'firefly_collective_stripe_live_webhook_secret_callback',
            'general',
            'firefly_stripe_settings_section'
        );
        register_setting('general', 'firefly_stripe_live_webhook_secret');
    }
    add_action('admin_init', 'firefly_collective_stripe_settings_init');

    // Settings section callback
    function firefly_collective_stripe_settings_section_callback() {
        echo '<p>Configure your Stripe API keys for payment processing.</p>';
    }

    // Test mode callback
    function firefly_collective_stripe_test_mode_callback() {
        $test_mode = get_option('firefly_stripe_test_mode', true);
        echo '<input type="checkbox" name="firefly_stripe_test_mode" value="1" ' . checked(1, $test_mode, false) . '/>';
    }

    // Test publishable key callback
    function firefly_collective_stripe_test_publishable_key_callback() {
        $test_publishable_key = get_option('firefly_stripe_test_publishable_key', '');
        echo '<input type="text" name="firefly_stripe_test_publishable_key" value="' . esc_attr($test_publishable_key) . '" class="regular-text" />';
    }

    // Test secret key callback
    function firefly_collective_stripe_test_secret_key_callback() {
        $test_secret_key = get_option('firefly_stripe_test_secret_key', '');
        echo '<input type="password" name="firefly_stripe_test_secret_key" value="' . esc_attr($test_secret_key) . '" class="regular-text" />';
    }

    // Test webhook secret callback
    function firefly_collective_stripe_test_webhook_secret_callback() {
        $test_webhook_secret = get_option('firefly_stripe_test_webhook_secret', '');
        echo '<input type="password" name="firefly_stripe_test_webhook_secret" value="' . esc_attr($test_webhook_secret) . '" class="regular-text" />';
    }

    // Live publishable key callback
    function firefly_collective_stripe_live_publishable_key_callback() {
        $live_publishable_key = get_option('firefly_stripe_live_publishable_key', '');
        echo '<input type="text" name="firefly_stripe_live_publishable_key" value="' . esc_attr($live_publishable_key) . '" class="regular-text" />';
    }

    // Live secret key callback
    function firefly_collective_stripe_live_secret_key_callback() {
        $live_secret_key = get_option('firefly_stripe_live_secret_key', '');
        echo '<input type="password" name="firefly_stripe_live_secret_key" value="' . esc_attr($live_secret_key) . '" class="regular-text" />';
    }

    // Live webhook secret callback
    function firefly_collective_stripe_live_webhook_secret_callback() {
        $live_webhook_secret = get_option('firefly_stripe_live_webhook_secret', '');
        echo '<input type="password" name="firefly_stripe_live_webhook_secret" value="' . esc_attr($live_webhook_secret) . '" class="regular-text" />';
    }