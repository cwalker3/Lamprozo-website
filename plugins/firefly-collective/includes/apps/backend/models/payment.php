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
    /**
     * Modified create payment intent function to support customers and subscriptions
     */
    function firefly_collective_create_payment_intent($request) {
        try {
            firefly_collective_stripe_init();
            
            $params = $request->get_json_params();
            $order_id = isset($params['orderID']) ? sanitize_text_field($params['orderID']) : '';
            
            if (empty($order_id)) {
                return new WP_Error('missing_order_id', 'Order ID is required', array('status' => 400));
            }
            
            // Get order details
            global $wpdb;
            $table_name = $wpdb->prefix . 'ffc_orders';
            
            // Get all items in this order
            $order_items = $wpdb->get_results($wpdb->prepare(
                "SELECT o.*, f.featureName, f.recurring, opt.optionName, opt.interval 
                FROM $table_name o
                JOIN {$wpdb->prefix}ffc_features f ON o.featureId = f.id
                JOIN {$wpdb->prefix}ffc_options opt ON o.optionId = opt.id
                WHERE o.orderID = %s",
                $order_id
            ), ARRAY_A);
            
            if (!$order_items || empty($order_items)) {
                return new WP_Error('invalid_order', 'Order not found', array('status' => 400));
            }
            
            $user_id = get_current_user_id();
            
            // Get or create Stripe customer
            $customer = firefly_collective_get_or_create_stripe_customer($user_id);
            
            // Check if any items are recurring
            $has_recurring = false;
            $has_one_time = false;
            $recurring_items = [];
            $one_time_items = [];
            
            foreach ($order_items as $item) {
                if ($item['recurring'] == 1) {
                    $has_recurring = true;
                    $recurring_items[] = $item;
                } else {
                    $has_one_time = true;
                    $one_time_items[] = $item;
                }
            }
            
            // If we have both recurring and one-time items, we need to handle them separately
            if ($has_recurring && $has_one_time) {
                return firefly_collective_create_mixed_payment($customer, $order_id, $recurring_items, $one_time_items);
            } elseif ($has_recurring) {
                return firefly_collective_create_subscription($customer, $order_id, $recurring_items);
            } else {
                // One-time payment only
                return firefly_collective_create_one_time_payment($customer, $order_id, $one_time_items);
            }
            
        } catch (Exception $e) {
            return new WP_Error('stripe_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * Create a one-time payment
     */
    function firefly_collective_create_one_time_payment($customer, $order_id, $items) {
        // Calculate total
        $total = 0;
        foreach ($items as $item) {
            $total += floatval($item['totalPrice']);
        }
        
        $amount = round($total * 100); // Convert to cents
        
        // Create payment intent with customer
        $payment_intent = \Stripe\PaymentIntent::create([
            'amount' => $amount,
            'currency' => 'usd',
            'customer' => $customer->id,
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
            'metadata' => [
                'order_id' => $order_id,
                'wordpress_user_id' => get_current_user_id(),
                'payment_type' => 'one_time'
            ],
            'description' => 'Order #' . $order_id
        ]);
        
        // Save payment intent ID
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ffc_orders',
            array('payment_intent_id' => $payment_intent->id),
            array('orderID' => $order_id),
            array('%s'),
            array('%s')
        );
        
        return array(
            'success' => true,
            'clientSecret' => $payment_intent->client_secret,
            'type' => 'one_time'
        );
    }

    /**
     * Create a subscription for recurring items
     */
    function firefly_collective_create_subscription($customer, $order_id, $items) {
        global $wpdb;
        
        // Group items by interval
        $items_by_interval = [];
        foreach ($items as $item) {
            $interval = $item['interval'] ?: 'monthly'; // Default to monthly
            if (!isset($items_by_interval[$interval])) {
                $items_by_interval[$interval] = [];
            }
            $items_by_interval[$interval][] = $item;
        }
        
        // For now, we'll handle single interval subscriptions
        // If you have multiple intervals, you'd need multiple subscriptions
        if (count($items_by_interval) > 1) {
            return new WP_Error('multiple_intervals', 'Multiple subscription intervals in one order not yet supported', array('status' => 400));
        }
        
        $interval = array_keys($items_by_interval)[0];
        $subscription_items = array_values($items_by_interval)[0];
        
        // Create subscription items
        $stripe_items = [];
        foreach ($subscription_items as $item) {
            // Create or retrieve a product and price
            $product = \Stripe\Product::create([
                'name' => $item['featureName'] . ' - ' . $item['optionName'],
                'metadata' => [
                    'feature_id' => $item['featureId'],
                    'option_id' => $item['optionId'],
                    'order_id' => $order_id
                ]
            ]);
            
            // Create a price for this product
            $price = \Stripe\Price::create([
                'product' => $product->id,
                'unit_amount' => round(floatval($item['totalPrice']) * 100),
                'currency' => 'usd',
                'recurring' => [
                    'interval' => $interval,
                    'interval_count' => 1
                ]
            ]);
            
            $stripe_items[] = [
                'price' => $price->id,
                'quantity' => 1
            ];
        }
        
        // Create subscription
        $subscription = \Stripe\Subscription::create([
            'customer' => $customer->id,
            'items' => $stripe_items,
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'expand' => ['latest_invoice.payment_intent'],
            'metadata' => [
                'order_id' => $order_id,
                'wordpress_user_id' => get_current_user_id()
            ]
        ]);
        
        // Save subscription details to orders
        $wpdb->update(
            $wpdb->prefix . 'ffc_orders',
            array(
                'payment_intent_id'               => $subscription->latest_invoice->payment_intent->id,
                'subscription_id'                 => $subscription->id,
                'subscription_status'             => 'active',
                // Next renewal = end of current period
                'subscription_renewal'            => date('Y-m-d H:i:s', $subscription->current_period_end),
                // Billing period = same as current_period_start/end
                'subscription_period_start'       => date('Y-m-d H:i:s', $subscription->current_period_start),
                'subscription_period_end'         => date('Y-m-d H:i:s', $subscription->current_period_end),
                // Mirror fields for clarity (optional if you want both sets)
                'subscription_current_period_start' => date('Y-m-d H:i:s', $subscription->current_period_start),
                'subscription_current_period_end'   => date('Y-m-d H:i:s', $subscription->current_period_end),
                // subscription_cancelled_at omitted → remains NULL
            ),
            array(
                'orderID' => $order_id
            ),
            // Formats for each field in the first array, in matching order:
            array(
                '%s', // payment_intent_id
                '%s', // subscription_id
                '%s', // subscription_status
                '%s', // subscription_renewal
                '%s', // subscription_period_start
                '%s', // subscription_period_end
                '%s', // subscription_current_period_start
                '%s'  // subscription_current_period_end
            ),
            // Format for the WHERE clause
            array(
                '%s'  // orderID
            )
        );

        return array(
            'success'        => true,
            'clientSecret'   => $subscription->latest_invoice->payment_intent->client_secret,
            'type'           => 'subscription',
            'subscriptionId' => $subscription->id,
        );
    }

    /**
     * Handle mixed payment (one-time + subscription)
     */
    function firefly_collective_create_mixed_payment($customer, $order_id, $recurring_items, $one_time_items) {
        // This is complex - you'd typically:
        // 1. Create the subscription with setup intent
        // 2. Create a separate payment intent for one-time items
        // 3. Use Stripe Checkout or handle them sequentially
        
        // For now, return an error suggesting to split the order
        return new WP_Error(
            'mixed_payment_types', 
            'Please place recurring and one-time items in separate orders for now.', 
            array('status' => 400)
        );
    }

    /**
     * Enhanced webhook handler to support subscription events
     */
    function firefly_collective_handle_stripe_webhook($request) {
        try {
            firefly_collective_stripe_init();
            
            $webhook_secret = defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : get_option('firefly_stripe_webhook_secret', '');
            
            $payload = @file_get_contents('php://input');
            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
            
            $event = null;
            try {
                $event = \Stripe\Webhook::constructEvent(
                    $payload, $sig_header, $webhook_secret
                );
            } catch(\UnexpectedValueException $e) {
                return new WP_REST_Response('Invalid payload', 400);
            } catch(\Stripe\Exception\SignatureVerificationException $e) {
                return new WP_REST_Response('Invalid signature', 400);
            }
            
            // Handle different event types
            switch ($event->type) {
                // One-time payment events
                case 'payment_intent.succeeded':
                    $payment_intent = $event->data->object;
                    $order_id = $payment_intent->metadata->order_id;
                    
                    if ($order_id && $payment_intent->metadata->payment_type !== 'subscription_renewal') {
                        firefly_collective_update_order_payment_status($order_id, 'paid');
                    }
                    break;
                    
                case 'payment_intent.payment_failed':
                    $payment_intent = $event->data->object;
                    $order_id = $payment_intent->metadata->order_id;
                    
                    if ($order_id) {
                        firefly_collective_update_order_payment_status($order_id, 'failed');
                    }
                    break;
                    
                // Subscription events
                case 'customer.subscription.created':
                    $subscription = $event->data->object;
                    firefly_collective_handle_subscription_created($subscription);
                    break;
                    
                case 'customer.subscription.updated':
                    $subscription = $event->data->object;
                    firefly_collective_handle_subscription_updated($subscription);
                    break;
                    
                case 'customer.subscription.deleted':
                    $subscription = $event->data->object;
                    firefly_collective_handle_subscription_cancelled($subscription);
                    break;
                    
                case 'invoice.paid':
                    // This handles recurring subscription payments
                    $invoice = $event->data->object;
                    if ($invoice->subscription) {
                        firefly_collective_handle_subscription_invoice_paid($invoice);
                    }
                    break;
                    
                case 'invoice.payment_failed':
                    $invoice = $event->data->object;
                    if ($invoice->subscription) {
                        firefly_collective_handle_subscription_invoice_failed($invoice);
                    }
                    break;

                case 'setup_intent.succeeded':
                    $setup_intent = $event->data->object;
                    if ($setup_intent->metadata->wordpress_user_id) {
                        // Update the customer's default payment method
                        try {
                            \Stripe\Customer::update($setup_intent->customer, [
                                'invoice_settings' => [
                                    'default_payment_method' => $setup_intent->payment_method
                                ]
                            ]);
                        } catch (Exception $e) {
                            error_log('Error updating customer payment method: ' . $e->getMessage());
                        }
                    }
                    break;
            }
            
            return new WP_REST_Response('Webhook received', 200);
        } catch (Exception $e) {
            return new WP_REST_Response('Error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Handle subscription creation
     */
    function firefly_collective_handle_subscription_created($subscription) {
        global $wpdb;
        
        // Update the original order
        if (isset($subscription->metadata->order_id)) {
            $wpdb->update(
                $wpdb->prefix . 'ffc_orders',
                array(
                    'subscription_id' => $subscription->id,
                    'subscription_status' => $subscription->status,
                    'subscription_current_period_start' => date('Y-m-d H:i:s', $subscription->current_period_start),
                    'subscription_current_period_end' => date('Y-m-d H:i:s', $subscription->current_period_end)
                ),
                array('orderID' => $subscription->metadata->order_id),
                array('%s', '%s', '%s', '%s'),
                array('%s')
            );
        }
    }

    /**
     * Handle subscription updates
     */
    function firefly_collective_handle_subscription_updated($subscription) {
        global $wpdb;
        
        // Update subscription status in all related orders
        $wpdb->update(
            $wpdb->prefix . 'ffc_orders',
            array(
                'subscription_status' => $subscription->status,
                'subscription_current_period_start' => date('Y-m-d H:i:s', $subscription->current_period_start),
                'subscription_current_period_end' => date('Y-m-d H:i:s', $subscription->current_period_end)
            ),
            array('subscription_id' => $subscription->id),
            array('%s', '%s', '%s'),
            array('%s')
        );
    }

    /**
     * Handle subscription cancellation
     */
    function firefly_collective_handle_subscription_cancelled($subscription) {
        global $wpdb;
        
        // Update subscription status
        $wpdb->update(
            $wpdb->prefix . 'ffc_orders',
            array(
                'subscription_status' => 'cancelled',
                'subscription_cancelled_at' => current_time('mysql')
            ),
            array('subscription_id' => $subscription->id),
            array('%s', '%s'),
            array('%s')
        );
        
        // Send cancellation email
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT orderID FROM {$wpdb->prefix}ffc_orders WHERE subscription_id = %s",
            $subscription->id
        ), ARRAY_A);
        
        foreach ($orders as $order) {
            firefly_collective_orders_email($order['orderID'], 'subscription_cancelled');
        }
    }

    /**
     * Handle successful subscription renewal payment
     */
    function firefly_collective_handle_subscription_invoice_paid($invoice) {
        global $wpdb;
        
        // Get the original order data to clone for the renewal
        $original_orders = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ffc_orders WHERE subscription_id = %s",
            $invoice->subscription
        ), ARRAY_A);
        
        if (empty($original_orders)) {
            return;
        }
        
        // Create a new order for this renewal
        $renewal_order_id = wp_generate_uuid4();
        
        foreach ($original_orders as $order) {
            $wpdb->insert(
                $wpdb->prefix . 'ffc_orders',
                array(
                    'orderID' => $renewal_order_id,
                    'payment_intent_id' => $invoice->payment_intent,
                    'subscription_id' => $invoice->subscription,
                    'userId' => $order['userId'],
                    'featureId' => $order['featureId'],
                    'optionId' => $order['optionId'],
                    'addonIds' => $order['addonIds'],
                    'priceSelected' => $order['priceSelected'],
                    'quantity' => $order['quantity'],
                    'totalPrice' => $order['totalPrice'],
                    'totalPriceDiscount' => $order['totalPriceDiscount'],
                    'priceDiscountsInfo' => $order['priceDiscountsInfo'],
                    'userData' => $order['userData'],
                    'status' => 'paid',
                    'subscription_renewal' => 1,
                    'subscription_period_start' => date('Y-m-d H:i:s', $invoice->period_start),
                    'subscription_period_end' => date('Y-m-d H:i:s', $invoice->period_end),
                    'createdAt' => current_time('mysql')
                )
            );
        }
        
        // Send renewal confirmation email
        firefly_collective_orders_email($renewal_order_id, 'subscription_renewed');
    }

    /**
     * Handle failed subscription payment
     */
    function firefly_collective_handle_subscription_invoice_failed($invoice) {
        global $wpdb;
        
        // Get customer email to notify
        $customer_id = $invoice->customer;
        $stripe_customer = \Stripe\Customer::retrieve($customer_id);
        
        // Get WordPress user
        $user_query = new WP_User_Query(array(
            'meta_key' => 'stripe_customer_id',
            'meta_value' => $customer_id,
            'number' => 1
        ));
        
        $users = $user_query->get_results();
        if (!empty($users)) {
            $user = $users[0];
            
            // Send payment failed email
            $subject = 'Subscription Payment Failed';
            $message = sprintf(
                'Hello %s,\n\n' .
                'We were unable to process your subscription payment. ' .
                'Please update your payment method to avoid service interruption.\n\n' .
                'You can update your payment information by logging into your account.\n\n' .
                'Thank you,\n' .
                'Firefly Collective',
                $user->display_name
            );
            
            wp_mail($user->user_email, $subject, $message);
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

    /**
     * Get or create a Stripe customer for a WordPress user
     */
    function firefly_collective_get_or_create_stripe_customer($user_id) {
        firefly_collective_stripe_init();
        
        $user = get_userdata($user_id);
        if (!$user) {
            throw new Exception('User not found');
        }
        
        // Check if user already has a Stripe customer ID
        $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true);
        
        if ($stripe_customer_id) {
            try {
                // Verify the customer still exists in Stripe
                $customer = \Stripe\Customer::retrieve($stripe_customer_id);
                if ($customer && !$customer->deleted) {
                    // Update customer info if needed
                    \Stripe\Customer::update($stripe_customer_id, [
                        'name' => $user->first_name . ' ' . $user->last_name,
                        'email' => $user->user_email,
                    ]);
                    return $customer;
                }
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Customer doesn't exist, we'll create a new one
            }
        }
        
        // Create new Stripe customer
        $customer = \Stripe\Customer::create([
            'name' => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
            'email' => $user->user_email,
            'metadata' => [
                'wordpress_user_id' => $user_id,
                'wordpress_username' => $user->user_login
            ]
        ]);
        
        // Save the Stripe customer ID to user meta
        update_user_meta($user_id, 'stripe_customer_id', $customer->id);
        
        return $customer;
    }

    /**
     * Get user's active subscriptions
     */
    function firefly_collective_get_subscriptions($request) {
        global $wpdb;
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('not_logged_in', 'You must be logged in to view subscriptions.', array('status' => 401));
        }

        // Get active subscriptions from database
        $subscriptions = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT 
            o.subscription_id, 
            o.subscription_status,
            o.subscription_current_period_end,
            o.userId,  -- ADD THIS LINE
            MIN(o.createdAt) as started_at,
            SUM(o.totalPrice) as total_amount,
            GROUP_CONCAT(DISTINCT f.featureName) as features,
            GROUP_CONCAT(DISTINCT opt.optionName) as options,
            GROUP_CONCAT(DISTINCT opt.interval) as intervals
        FROM {$wpdb->prefix}ffc_orders o
        JOIN {$wpdb->prefix}ffc_features f ON o.featureId = f.id
        JOIN {$wpdb->prefix}ffc_options opt ON o.optionId = opt.id
        WHERE o.subscription_id IS NOT NULL 
        AND o.subscription_status IN ('active', 'trialing', 'past_due')
        GROUP BY o.subscription_id, o.subscription_status, o.subscription_current_period_end, o.userId",
        $user_id
    ), ARRAY_A);
        
        // Get payment method info from Stripe
        firefly_collective_stripe_init();
        $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true);
        
        if ($stripe_customer_id) {
            try {
                $customer = \Stripe\Customer::retrieve($stripe_customer_id, [
                    'expand' => ['default_source', 'invoice_settings.default_payment_method']
                ]);
                
                // Get default payment method details
                $payment_method = null;
                if ($customer->invoice_settings->default_payment_method) {
                    $pm = \Stripe\PaymentMethod::retrieve($customer->invoice_settings->default_payment_method);
                    $payment_method = [
                        'type' => $pm->type,
                        'last4' => $pm->card->last4 ?? null,
                        'brand' => $pm->card->brand ?? null,
                        'exp_month' => $pm->card->exp_month ?? null,
                        'exp_year' => $pm->card->exp_year ?? null
                    ];
                }
                
                // Add payment method to response
                foreach ($subscriptions as &$sub) {
                    $sub['payment_method'] = $payment_method;
                }
            } catch (Exception $e) {
                // Log error but continue
                error_log('Error retrieving Stripe customer: ' . $e->getMessage());
            }
        }
        
        return array(
            'success' => true,
            'subscriptions' => $subscriptions
        );
    }

    /**
     * Cancel a subscription
     */
    function firefly_collective_cancel_subscription($request) {

        $params = $request->get_json_params();
        $subscription_id = isset($params['subscriptionId']) ? sanitize_text_field($params['subscriptionId']) : '';
        
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required', array('status' => 400));
        }
        
        global $wpdb;
        
        // Cancel in Stripe
        firefly_collective_stripe_init();
        
        try {
            $subscription = \Stripe\Subscription::retrieve($subscription_id);
            $subscription->cancel();
            
            // Update database
            $wpdb->update(
                $wpdb->prefix . 'ffc_orders',
                array(
                    'subscription_status' => 'cancelled',
                    'subscription_cancelled_at' => current_time('mysql')
                ),
                array('subscription_id' => $subscription_id),
                array('%s', '%s'),
                array('%s')
            );
            
            return array(
                'success' => true,
                'message' => 'Subscription cancelled successfully'
            );
        } catch (Exception $e) {
            return new WP_Error('stripe_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * Update payment method for subscriptions
     */
    function firefly_collective_update_payment_method($request) {

        $raw       = sanitize_text_field( $_COOKIE['auth_id'] );
        $decrypted = decrypt_with_auth_key( $raw );
        $user_id   = intval( $decrypted );

        firefly_collective_stripe_init();
        
        try {
            // Get or create customer
            $customer = firefly_collective_get_or_create_stripe_customer($user_id);
            
            // Create a setup intent for updating payment method
            $setup_intent = \Stripe\SetupIntent::create([
                'customer' => $customer->id,
                'payment_method_types' => ['card'],
                'usage' => 'off_session',
                'metadata' => [
                    'wordpress_user_id' => $user_id
                ]
            ]);
            
            return array(
                'success' => true,
                'clientSecret' => $setup_intent->client_secret
            );
        } catch (Exception $e) {
            return new WP_Error('stripe_error', $e->getMessage(), array('status' => 500));
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