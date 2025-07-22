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

    /**
     * Format item description consistently
     */
    function format_item_description($item, $wpdb) {
        $item_desc = $item['featureName'] . " - " . $item['optionName'];
        
        // Add addons if present
        if (!empty($item['addonIds'])) {
            $addon_ids = json_decode($item['addonIds'], true);
            if (!empty($addon_ids)) {
                $addon_names = [];
                foreach ($addon_ids as $aid) {
                    $addon_row = $wpdb->get_row($wpdb->prepare(
                        "SELECT addonName FROM {$wpdb->prefix}ffc_addons WHERE id = %d",
                        intval($aid)
                    ), ARRAY_A);
                    if ($addon_row) {
                        $addon_names[] = $addon_row['addonName'];
                    }
                }
                if (!empty($addon_names)) {
                    $item_desc .= " with " . implode(", ", $addon_names);
                }
            }
        }
        
        return $item_desc;
    }

    // Create a payment intent (for orders and subscriptions)
    function firefly_collective_create_payment_intent($request) {
        try {
            firefly_collective_stripe_init();
            
            $params = $request->get_json_params();
            $order_id = isset($params['orderID']) ? sanitize_text_field($params['orderID']) : '';
            $item_id = isset($params['itemId']) ? intval($params['itemId']) : null;
            
            if (empty($order_id)) {
                return new WP_Error('missing_order_id', 'Order ID is required', array('status' => 400));
            }
            
            // Get order details
            global $wpdb;
            $table_name = $wpdb->prefix . 'ffc_orders';
            
            // Get all items in this order - INCLUDE THE ID FIELD
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
            
            // Handle different payment scenarios
            if ($has_recurring && $has_one_time) {
                // Mixed payment - use the new implementation
                return firefly_collective_create_mixed_payment($customer, $order_id, $recurring_items, $one_time_items);
            } elseif ($has_recurring) {
                // Subscription only
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
     * Create a one-time payment using Stripe Payment Intent
     */
    function firefly_collective_create_one_time_payment($customer, $order_id, $items) {
        global $wpdb;

        try {
            // Calculate total amount and build description
            $total_amount = 0;
            $item_descriptions = [];

            foreach ($items as $item) {
                $item_desc = format_item_description($item, $wpdb);
                $item_descriptions[] = $item_desc;
                $total_amount += floatval($item['totalPrice']);
            }

            // Create description
            $order_description = implode(" | ", $item_descriptions);
            
            // Build metadata
            $metadata = [
                'order_id'          => $order_id,
                'wordpress_user_id' => get_current_user_id(),
                'payment_type'      => 'one_time',
                'total_items'       => count($items),
                'features'          => implode(', ', array_unique(array_map(function($i){return $i['featureName'];}, $items))),
            ];

            // Create a Payment Intent directly
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount'                    => round($total_amount * 100), // Convert to cents
                'currency'                  => 'usd',
                'customer'                  => $customer->id,
                'description'               => $order_description,
                'metadata'                  => $metadata,
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);

            // Update orders table with the Payment Intent ID
            $wpdb->update(
                $wpdb->prefix . 'ffc_orders',
                ['payment_intent_id' => $payment_intent->id],
                ['orderID'          => $order_id],
                ['%s'],
                ['%s']
            );

            return [
                'success'      => true,
                'clientSecret' => $payment_intent->client_secret,
                'type'         => 'one_time',
            ];

        } catch (Exception $e) {
            throw new Exception('Failed to create one-time payment: ' . $e->getMessage());
        }
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
        
        // Build simplified description
        $item_descriptions = array_map(function($item) use ($wpdb) {
            return format_item_description($item, $wpdb);
        }, $subscription_items);
        $order_description = implode(" | ", $item_descriptions);
        
        // Create subscription items
        $stripe_items = [];
        foreach ($subscription_items as $item) {
            $item_desc = format_item_description($item, $wpdb);
            
            // Create or retrieve a product and price
            $product = \Stripe\Product::create([
                'name' => $item_desc,
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
        
        // Build metadata
        $metadata = [
            'order_id' => $order_id,
            'wordpress_user_id' => get_current_user_id(),
            'payment_type' => 'subscription',
            'subscription_interval' => $interval
        ];
        
        // Create subscription with expanded invoice
        $subscription = \Stripe\Subscription::create([
            'customer' => $customer->id,
            'items' => $stripe_items,
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'expand' => ['latest_invoice.payment_intent'],
            'metadata' => $metadata,
            'description' => $order_description
        ]);
        
        // Update the Payment Intent description
        if ($subscription && $subscription->latest_invoice && $subscription->latest_invoice->payment_intent) {
            \Stripe\PaymentIntent::update(
                $subscription->latest_invoice->payment_intent->id,
                [
                    'description' => $order_description
                ]
            );
        }
        
        // Save subscription details to orders (only the subscription item row)
        foreach ( $subscription_items as $item ) {
            $wpdb->update(
                $wpdb->prefix . 'ffc_orders',
                array(
                    'payment_intent_id'               => $subscription->latest_invoice->payment_intent->id,
                    'subscription_id'                 => $subscription->id,
                    'subscription_status'             => 'active',
                    'subscription_renewal'            => 0,
                    'subscription_period_start'       => date('Y-m-d H:i:s', $subscription->current_period_start),
                    'subscription_current_period_end' => date('Y-m-d H:i:s', $subscription->current_period_end),
                ),
                array(
                    'orderID' => $order_id,
                    'id'      => $item['id']
                ),
                array('%s','%s','%s','%d','%s','%s'),
                array('%s','%d')
            );
        }

        return array(
            'success'        => true,
            'clientSecret'   => $subscription->latest_invoice->payment_intent->client_secret,
            'type'           => 'subscription',
            'subscriptionId' => $subscription->id,
        );
    }

    /**
     * Handle mixed payment (one-time + subscription) using Stripe Invoice Items
     */
    function firefly_collective_create_mixed_payment($customer, $order_id, $recurring_items, $one_time_items) {
        global $wpdb;
        
        try {
            // Build simplified descriptions
            $one_time_descriptions = array_map(function($item) use ($wpdb) {
                return format_item_description($item, $wpdb);
            }, $one_time_items);
            
            $recurring_descriptions = array_map(function($item) use ($wpdb) {
                return format_item_description($item, $wpdb);
            }, $recurring_items);
            
            // Only describe the subscription line
            $order_description = implode(" | ", $recurring_descriptions);
            
            // Calculate totals for reporting
            $total_one_time = 0;
            foreach ($one_time_items as $item) {
                $total_one_time += floatval($item['totalPrice']);
            }
            
            $total_recurring = 0;
            foreach ($recurring_items as $item) {
                $total_recurring += floatval($item['totalPrice']);
            }
            
            // Build metadata with clear subscription amount
            $metadata = [
                'order_id' => $order_id,
                'wordpress_user_id' => get_current_user_id(),
                'payment_type' => 'mixed',
                'has_one_time_items' => 'true',
                'total_items' => count($one_time_items) + count($recurring_items),
                'subscription_interval' => isset($recurring_items[0]['interval']) ? $recurring_items[0]['interval'] : 'monthly',
                'subscription_amount_monthly' => number_format($total_recurring, 2), // Clear monthly amount
            ];
            
            // Step 1: Add one-time items as invoice items to the customer
            foreach ($one_time_items as $item) {
                $item_desc = format_item_description($item, $wpdb);
                
                \Stripe\InvoiceItem::create([
                    'customer' => $customer->id,
                    'amount' => round(floatval($item['totalPrice']) * 100), // Convert to cents
                    'currency' => 'usd',
                    'description' => $item_desc,
                    'metadata' => [
                        'order_id' => $order_id,
                        'wordpress_user_id' => get_current_user_id(),
                        'feature_id' => $item['featureId'],
                        'option_id' => $item['optionId'],
                        'type' => 'one_time'
                    ]
                ]);
            }
            
            // Step 2: Create subscription items for recurring items
            $stripe_items = [];
            $interval = 'monthly'; // Default interval
            
            foreach ($recurring_items as $item) {
                $item_desc = format_item_description($item, $wpdb);
                
                // Create or retrieve a product with clear recurring amount in name
                $product = \Stripe\Product::create([
                    'name' => sprintf('%s ($%.2f/%s)', $item_desc, floatval($item['totalPrice']), $item['interval'] ?: 'month'),
                    'metadata' => [
                        'feature_id' => $item['featureId'],
                        'option_id' => $item['optionId'],
                        'order_id' => $order_id,
                        'recurring_amount' => floatval($item['totalPrice'])
                    ]
                ]);
                
                // Determine interval (default to monthly if not specified)
                $interval = $item['interval'] ?: 'monthly';
                
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
            
            // Step 3: Create subscription (which will include the invoice items in the first invoice)
            $subscription = \Stripe\Subscription::create([
                'customer' => $customer->id,
                'items' => $stripe_items,
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
                'expand' => ['latest_invoice.payment_intent'],
                'metadata' => $metadata,
                'description' => sprintf('%s - $%.2f/%s', $order_description, $total_recurring, $interval)
            ]);
            
            // Step 4: Update the Payment Intent with a clear description
            if ($subscription && $subscription->latest_invoice && $subscription->latest_invoice->payment_intent) {
                \Stripe\PaymentIntent::update(
                    $subscription->latest_invoice->payment_intent->id,
                    [
                        'description' => $order_description,
                        'statement_descriptor_suffix' => 'SUB+SETUP' // Shows on bank statement
                    ]
                );
            }
            
            // Step 5: Update database records
            // Update recurring items with subscription info
            foreach ($recurring_items as $item) {
                $wpdb->update(
                    $wpdb->prefix . 'ffc_orders',
                    array(
                        'payment_intent_id' => $subscription->latest_invoice->payment_intent->id,
                        'subscription_id' => $subscription->id,
                        'subscription_status' => 'active',
                        'subscription_renewal' => 0,  // Set to 0 for initial subscription
                        'subscription_period_start' => date('Y-m-d H:i:s', $subscription->current_period_start),
                        'subscription_current_period_end' => date('Y-m-d H:i:s', $subscription->current_period_end),
                    ),
                    array(
                        'orderID' => $order_id,
                        'id' => $item['id']
                    ),
                    array('%s', '%s', '%s', '%d', '%s', '%s'),
                    array('%s', '%d')
                );
            }
            
            // Update one-time items with payment intent info
            foreach ($one_time_items as $item) {
                $wpdb->update(
                    $wpdb->prefix . 'ffc_orders',
                    array(
                        'payment_intent_id' => $subscription->latest_invoice->payment_intent->id,
                    ),
                    array(
                        'orderID' => $order_id,
                        'id' => $item['id']
                    ),
                    array('%s'),
                    array('%s', '%d')
                );
            }
            
            return array(
                'success' => true,
                'clientSecret' => $subscription->latest_invoice->payment_intent->client_secret,
                'type' => 'mixed',
                'subscriptionId' => $subscription->id,
                'totalFirstPayment' => $total_one_time + $total_recurring,
                'recurringAmount' => $total_recurring,
                'oneTimeAmount' => $total_one_time
            );
            
        } catch (Exception $e) {
            // Clean up invoice items if an error occurs
            try {
                $invoice_items = \Stripe\InvoiceItem::all([
                    'customer' => $customer->id,
                    'pending' => true,
                    'limit' => 100
                ]);
                
                foreach ($invoice_items->data as $item) {
                    if (isset($item->metadata->order_id) && $item->metadata->order_id === $order_id) {
                        $item->delete();
                    }
                }
            } catch (Exception $cleanup_error) {
                // Log cleanup error but don't throw
                error_log('Failed to cleanup invoice items: ' . $cleanup_error->getMessage());
            }
            
            throw new Exception('Failed to create mixed payment: ' . $e->getMessage());
        }
    }

    /**
     * Webhook handler to support subscription events
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
                
                case 'payment_intent.processing':
                    // The ACH debit is pending settlement
                    $pi = $event->data->object;
                    firefly_collective_update_order_payment_status(
                        $pi->metadata->order_id,
                        'processing'
                    );
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
                        // Check if this is a plan change invoice by looking for proration items
                        $has_proration = false;
                        foreach ($invoice->lines->data as $line) {
                            if ($line->proration) {
                                $has_proration = true;
                                break;
                            }
                        }
                        
                        // If it's a plan change invoice, skip creating new orders
                        if ($has_proration) {
                            error_log('Plan change invoice detected, skipping order creation');
                            break;
                        }
                        
                        // Check if this is the first invoice with mixed payment
                        $subscription = \Stripe\Subscription::retrieve($invoice->subscription);
                        
                        if ($invoice->billing_reason === 'subscription_create') {
                            // First invoice - check if it has one-time items
                            if (isset($subscription->metadata->has_one_time_items) && 
                                $subscription->metadata->has_one_time_items === 'true') {
                                
                                // Update one-time and subscription items to 'paid' status
                                global $wpdb;
                                $order_id = $subscription->metadata->order_id;
                                
                                if ($order_id) {
                                    // Update all items in this order to 'paid'
                                    $wpdb->update(
                                        $wpdb->prefix . 'ffc_orders',
                                        array('status' => 'paid'),
                                        array('orderID' => $order_id),
                                        array('%s'),
                                        array('%s')
                                    );
                                    
                                    // Send confirmation email for the complete order
                                    firefly_collective_orders_email($order_id, 'paid');
                                }
                            } else {
                                // Regular subscription creation without one-time items
                                global $wpdb;
                                $order_id = $subscription->metadata->order_id;
                                
                                if ($order_id) {
                                    $wpdb->update(
                                        $wpdb->prefix . 'ffc_orders',
                                        array('status' => 'paid'),
                                        array('orderID' => $order_id),
                                        array('%s'),
                                        array('%s')
                                    );
                                    
                                    firefly_collective_orders_email($order_id, 'paid');
                                }
                            }
                        } else {
                            // Recurring payment - create new order
                            firefly_collective_handle_subscription_invoice_paid($invoice);
                        }
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
    function firefly_collective_handle_subscription_created( $subscription ) {
        global $wpdb;

        if ( empty( $subscription->metadata->order_id ) ) {
            return;
        }

        // 1. Find the 1 row we already stamped with subscription_id in create_subscription()
        $original = $wpdb->get_row( $wpdb->prepare(
            "SELECT id
            FROM {$wpdb->prefix}ffc_orders
            WHERE orderID = %s
                AND subscription_id = %s
            LIMIT 1",
            $subscription->metadata->order_id,
            $subscription->id
        ), ARRAY_A );

        if ( ! $original ) {
            return; // nothing to do
        }

        // 2. Update only that single row’s status & period_end
        $wpdb->update(
            "{$wpdb->prefix}ffc_orders",
            [
                'subscription_status'             => $subscription->status,
                'subscription_current_period_end' => date( 'Y-m-d H:i:s', $subscription->current_period_end ),
            ],
            [ 'id' => $original['id'] ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
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
     * Inserts any one-time invoice items, then creates a renewal order
     * and updates the original subscription's end date.
     */
    function firefly_collective_handle_subscription_invoice_paid( $invoice ) {
        global $wpdb;
        
        // Fix: Use object notation instead of array notation
        $subscriptionId = $invoice->subscription;
        
        // Add debug logging
        error_log("Processing subscription invoice for subscription: " . $subscriptionId);
        
        if (!$subscriptionId) {
            error_log("No subscription ID found in invoice");
            return;
        }

        // 1. Find the very first subscription-setup order
        $original = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ffc_orders
                WHERE subscription_id = %s
                ORDER BY createdAt ASC
                LIMIT 1",
                $subscriptionId
            ),
            ARRAY_A
        );
        
        if ( ! $original ) {
            error_log( "No subscription order found for {$subscriptionId}" );
            return;
        }

        // 2. Insert any one-time invoice items as standalone orders
        foreach ( $invoice->lines->data as $line ) {
            // Stripe sometimes labels non-subscription lines as 'invoiceitem'
            if ( isset($line->type) && $line->type === 'invoiceitem' ) {
                $amount   = $line->amount / 100;
                $quantity = ! empty( $line->quantity ) ? $line->quantity : 1;

                $wpdb->insert(
                    "{$wpdb->prefix}ffc_orders",
                    [
                        'orderID'                          => $original['orderID'],
                        'payment_intent_id'                => $invoice->payment_intent,
                        'userId'                           => $original['userId'],
                        'featureId'                        => $original['featureId'],
                        'optionId'                         => $original['optionId'],
                        'addonIds'                         => $original['addonIds'],
                        'priceSelected'                    => $original['priceSelected'],
                        'quantity'                         => $quantity,
                        'totalPrice'                       => $amount,
                        'status'                           => 'paid',
                        'createdAt'                        => current_time( 'mysql' ),
                        'updatedAt'                        => current_time( 'mysql' ),
                        'subscription_id'                  => null,
                        'subscription_status'              => null,
                        'subscription_period_start'        => null,
                        'subscription_current_period_end'  => null,
                        'subscription_cancelled_at'        => null,
                    ]
                );
            }
        }

        // 3. Handle the recurring subscription line
        foreach ( $invoice->lines->data as $line ) {
            if ( $line->type !== 'subscription' ) {
                continue;
            }

            $periodStart = date( 'Y-m-d H:i:s', $line->period->start );
            $periodEnd   = date( 'Y-m-d H:i:s', $line->period->end );
            $amount      = $line->amount / 100;
            $quantity    = $line->quantity;

            // Skip the first invoice (subscription creation)
            if ($invoice->billing_reason === 'subscription_create') {
                error_log("Skipping renewal order creation for initial subscription invoice");
                
                // Just update the original order's period end
                $wpdb->update(
                    "{$wpdb->prefix}ffc_orders",
                    [ 'subscription_current_period_end' => $periodEnd ],
                    [ 'id' => $original['id'] ]
                );
                continue;
            }

            // 3a. Insert a renewal order for actual renewals
            error_log("Creating renewal order for subscription {$subscriptionId}");
            
            // Generate new order ID for renewal
            $new_order_id = wp_generate_uuid4();

            $result = $wpdb->insert(
                "{$wpdb->prefix}ffc_orders",
                [
                    'orderID'                          => $new_order_id, 
                    'payment_intent_id'                => $invoice->payment_intent,
                    'userId'                           => $original['userId'],
                    'featureId'                        => $original['featureId'],
                    'optionId'                         => $original['optionId'],
                    'addonIds'                         => $original['addonIds'],
                    'priceSelected'                    => $original['priceSelected'],
                    'quantity'                         => $quantity,
                    'totalPrice'                       => $amount,
                    'status'                           => 'paid',
                    'createdAt'                        => current_time( 'mysql' ),
                    'updatedAt'                        => current_time( 'mysql' ),
                    'subscription_id'                  => null, // Renewal orders don't need subscription info
                    'subscription_status'              => null,
                    'subscription_renewal'             => 1, // Mark as renewal
                    'subscription_period_start'        => $periodStart,
                    'subscription_current_period_end'  => $periodEnd,
                ]
            );
            
            if ($result === false) {
                error_log("Failed to insert renewal order: " . $wpdb->last_error);
            } else {
                firefly_collective_orders_email($new_order_id, 'paid');
            }

            // 3b. Update the original subscription row's period end
            $wpdb->update(
                "{$wpdb->prefix}ffc_orders",
                [ 'subscription_current_period_end' => $periodEnd ],
                [ 'id' => $original['id'] ]
            );
        }
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

    /**
     * Handle change plan for subscriptions
     */
    function firefly_collective_change_subscription_plan($request) {
        try {
            firefly_collective_stripe_init();

            $params          = $request->get_json_params();
            $subscription_id = sanitize_text_field($params['subscriptionId'] ?? '');
            $new_option_id   = intval($params['newOptionId'] ?? 0);
            $is_renewal      = !empty($params['isRenewal']);

            global $wpdb;

            // Get current subscription details
            $current_sub = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT o.*, opt.optionName, opt.staticPrice, opt.interval, o.subscription_current_period_end
                    FROM {$wpdb->prefix}ffc_orders o
                    JOIN {$wpdb->prefix}ffc_options opt ON o.optionId = opt.id
                    WHERE o.subscription_id = %s
                    ORDER BY o.createdAt ASC
                    LIMIT 1",
                    $subscription_id
                ),
                ARRAY_A
            );

            if (!$current_sub) {
                return new WP_Error('subscription_not_found', 'Subscription not found', array('status' => 404));
            }

            // Get new option details
            $new_option = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_options WHERE id = %d",
                    $new_option_id
                ),
                ARRAY_A
            );

            if (!$new_option) {
                return new WP_Error('option_not_found', 'New plan not found', array('status' => 404));
            }

            $user_id  = get_current_user_id();
            $customer = firefly_collective_get_or_create_stripe_customer($user_id);

            // Stripe client
            $stripe     = new \Stripe\StripeClient(\Stripe\Stripe::$apiKey);
            $stripe_sub = $stripe->subscriptions->retrieve($subscription_id);

            // Create a new Price for the plan
            $new_price = $stripe->prices->create([
                'product'     => $stripe_sub->items->data[0]->price->product,
                'unit_amount' => round((float)$new_option['staticPrice'] * 100),
                'currency'    => 'usd',
                'recurring'   => [
                    'interval' => $new_option['interval'] ?: 'month',
                ],
            ]);

            // Calculate proration using preview
            $immediate_charge = 0;

            try {
                // Create preview with proper parameters
                $preview_params = [
                    'customer'     => $customer->id,
                    'subscription' => $subscription_id,
                    'subscription_details' => [
                        'items' => [
                            [
                                'id'    => $stripe_sub->items->data[0]->id,
                                'price' => $new_price->id,
                            ],
                        ],
                        'proration_behavior' => 'create_prorations',
                    ],
                ];

                $proration_preview = $stripe->invoices->createPreview($preview_params);
                
                // The amount_due is the actual amount that will be charged
                $immediate_charge = $proration_preview->amount_due / 100;
                
                // Log for debugging
                error_log('Proration preview amount_due: ' . $proration_preview->amount_due);
                error_log('Proration preview total: ' . $proration_preview->total);
                error_log('Immediate charge calculated: ' . $immediate_charge);

            } catch (Exception $e) {
                // Fallback: manual proration
                error_log('Failed to preview invoice: ' . $e->getMessage());

                // Calculate days remaining in period
                $current_time   = time();
                $period_end     = strtotime($current_sub['subscription_current_period_end']);
                $period_start   = strtotime($stripe_sub->current_period_start);
                $total_days     = ($period_end - $period_start) / 86400;
                $days_remaining = max(0, ($period_end - $current_time) / 86400);

                if ($days_remaining > 0 && $total_days > 0) {
                    // Calculate the prorated amounts
                    $old_price = (float)$current_sub['staticPrice'];
                    $new_price_amount = (float)$new_option['staticPrice'];
                    
                    // Credit for unused time on old plan
                    $credit = ($old_price / $total_days) * $days_remaining;
                    
                    // Charge for remaining time on new plan
                    $charge = ($new_price_amount / $total_days) * $days_remaining;
                    
                    // Net charge
                    $immediate_charge = round($charge - $credit, 2);
                    
                    error_log("Manual proration: credit=$credit, charge=$charge, net=$immediate_charge");
                }
            }

            // Create a SetupIntent for payment method collection
            $setup_intent = $stripe->setupIntents->create([
                'customer'             => $customer->id,
                'payment_method_types' => ['card'],
                'usage'                => 'off_session',
                'metadata'             => [
                    'subscription_id'   => $subscription_id,
                    'new_option_id'     => $new_option_id,
                    'old_option_id'     => $current_sub['optionId'],
                    'is_renewal'        => $is_renewal ? 'true' : 'false',
                    'wordpress_user_id' => $user_id,
                    'immediate_charge'  => (string)$immediate_charge,
                    'new_price_id'      => $new_price->id,
                ],
            ]);

            return [
                'success'         => true,
                'clientSecret'    => $setup_intent->client_secret,
                'currentPlan'     => $current_sub['optionName'],
                'currentPrice'    => (float)$current_sub['staticPrice'],
                'newPlan'         => $new_option['optionName'],
                'newPrice'        => (float)$new_option['staticPrice'],
                'immediateCharge' => $immediate_charge,
                'isUpgrade'       => (float)$new_option['staticPrice'] > (float)$current_sub['staticPrice'],
                'setupIntentId'   => $setup_intent->id,
            ];

        } catch (Exception $e) {
            error_log('Plan change error: ' . $e->getMessage());
            return new WP_Error('stripe_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Complete the plan change after payment method confirmation
     */
    function firefly_collective_complete_plan_change($request) {
        try {
            firefly_collective_stripe_init();
            
            $params = $request->get_json_params();
            $setup_intent_id = sanitize_text_field($params['setupIntentId']);
            
            // Create a StripeClient instance
            $stripe = new \Stripe\StripeClient(\Stripe\Stripe::$apiKey);
            
            // Retrieve the SetupIntent to get metadata
            $setup_intent = $stripe->setupIntents->retrieve($setup_intent_id);
            
            if ($setup_intent->status !== 'succeeded') {
                return new WP_Error('payment_not_confirmed', 'Payment method not confirmed', array('status' => 400));
            }
            
            $subscription_id = $setup_intent->metadata->subscription_id;
            $new_option_id = intval($setup_intent->metadata->new_option_id);
            $immediate_charge = floatval($setup_intent->metadata->immediate_charge);
            $is_renewal = $setup_intent->metadata->is_renewal === 'true';
            $new_price_id = $setup_intent->metadata->new_price_id;
            
            global $wpdb;
            
            // Get new option details
            $new_option = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ffc_options WHERE id = %d",
                $new_option_id
            ), ARRAY_A);
            
            // Retrieve the subscription
            $stripe_sub = $stripe->subscriptions->retrieve($subscription_id);
            
            // Update the subscription with the new price
            $update_params = [
                'items' => [
                    [
                        'id' => $stripe_sub->items->data[0]->id,
                        'price' => $new_price_id
                    ]
                ],
                'proration_behavior' => 'always_invoice',
                'payment_behavior' => 'default_incomplete',
                'default_payment_method' => $setup_intent->payment_method,
                'metadata' => [
                    'plan_change_in_progress' => 'true' // Mark this as a plan change
                ]
            ];
            
            $updated_sub = $stripe->subscriptions->update($subscription_id, $update_params);
            
            // Update the existing subscription record in database
            $wpdb->update(
                $wpdb->prefix . 'ffc_orders',
                array(
                    'optionId' => $new_option_id,
                    'totalPrice' => floatval($new_option['staticPrice']),
                    'subscription_status' => $updated_sub->status,
                    'subscription_current_period_end' => date('Y-m-d H:i:s', $updated_sub->current_period_end),
                    'updatedAt' => current_time('mysql')
                ),
                array(
                    'subscription_id' => $subscription_id,
                    'subscription_renewal' => 0
                ),
                array('%d', '%s', '%s', '%s', '%s'),
                array('%s', '%d')
            );
            
            // Try to find and pay the prorated invoice
            $payment_intent_id = null;
            $actual_charge = $immediate_charge; // Default to calculated charge
            
            try {
                // Wait a moment for Stripe to create the invoice
                sleep(1);
                
                // Look for the proration invoice
                $invoices = $stripe->invoices->all([
                    'subscription' => $subscription_id,
                    'limit' => 5,
                    'created' => [
                        'gte' => time() - 10 // Last 10 seconds
                    ]
                ]);
                
                foreach ($invoices->data as $invoice) {
                    // Check if this invoice has proration items
                    $has_proration = false;
                    foreach ($invoice->lines->data as $line) {
                        if ($line->proration) {
                            $has_proration = true;
                            break;
                        }
                    }
                    
                    if ($has_proration && ($invoice->status === 'open' || $invoice->status === 'draft')) {
                        error_log('Found proration invoice ' . $invoice->id . ' with amount ' . $invoice->amount_due);
                        
                        // Use the actual invoice amount
                        $actual_charge = $invoice->amount_due / 100;
                        
                        // Finalize if draft
                        if ($invoice->status === 'draft') {
                            $invoice = $stripe->invoices->finalizeInvoice($invoice->id);
                        }
                        
                        // Pay the invoice
                        $paid_invoice = $stripe->invoices->pay($invoice->id);
                        $payment_intent_id = $paid_invoice->payment_intent;
                        
                        error_log('Paid proration invoice ' . $invoice->id . ' for $' . $actual_charge);
                        break;
                    }
                }
            } catch (\Stripe\Exception\ApiErrorException $e) {
                error_log('Error processing proration invoice: ' . $e->getMessage());
            }
            
            // Create a transaction record for the plan change
            if ($actual_charge != 0) { // Only create if there's a charge or credit
                $new_order_id = wp_generate_uuid4();
                
                $original_order = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_orders 
                    WHERE subscription_id = %s 
                    ORDER BY createdAt ASC LIMIT 1",
                    $subscription_id
                ), ARRAY_A);
                
                $transaction_data = array(
                    'orderID' => $new_order_id,
                    'payment_intent_id' => $payment_intent_id ?: null,
                    'userId' => $original_order['userId'],
                    'featureId' => $original_order['featureId'],
                    'optionId' => $new_option_id,
                    'addonIds' => $original_order['addonIds'],
                    'priceSelected' => $original_order['priceSelected'],
                    'quantity' => 1,
                    'totalPrice' => abs($actual_charge), // Store absolute value
                    'totalPriceDiscount' => 0,
                    'priceDiscountsInfo' => json_encode([
                        'description' => $actual_charge > 0 ? 'Plan upgrade proration' : 'Plan downgrade credit'
                    ]),
                    'userData' => $original_order['userData'],
                    'status' => $payment_intent_id ? 'paid' : 'completed',
                    'transaction_type' => 'plan_change',
                    'subscription_renewal' => 1,
                    'createdAt' => current_time('mysql')
                );
                
                $wpdb->insert($wpdb->prefix . 'ffc_orders', $transaction_data);
                
                // Send confirmation email
                firefly_collective_orders_email($new_order_id, 'plan_change');
            }
            
            // Remove the plan change marker
            $stripe->subscriptions->update($subscription_id, [
                'metadata' => [
                    'plan_change_in_progress' => ''
                ]
            ]);
            
            return array(
                'success' => true,
                'message' => $is_renewal ? 'Subscription renewed successfully!' : 'Plan changed successfully!',
                'newPlan' => $new_option['optionName'],
                'newPrice' => floatval($new_option['staticPrice']),
                'immediateCharge' => $actual_charge,
                'transactionCreated' => $actual_charge != 0
            );
            
        } catch (Exception $e) {
            error_log('Complete plan change error: ' . $e->getMessage());
            return new WP_Error('stripe_error', $e->getMessage(), array('status' => 500));
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
        
        // Check if this order has a subscription_id (meaning it's already handled by webhook)
        global $wpdb;
        $has_subscription = $wpdb->get_var($wpdb->prepare(
            "SELECT subscription_id FROM {$wpdb->prefix}ffc_orders 
            WHERE orderID = %s AND subscription_id IS NOT NULL 
            LIMIT 1",
            $order_id
        ));
        
        $updated = firefly_collective_update_order_payment_status($order_id, $status);
        
        if ($updated) {
            // Only send email if it's NOT a subscription order (webhooks handle those)
            if (!$has_subscription) {
                firefly_collective_orders_email($order_id, $status);
            }
            
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
        // Verify user is logged in
        if (!is_user_logged_in()) {
            return new WP_Error(
                'not_logged_in', 
                'You must be logged in to refund an order.', 
                ['status' => 401]
            );
        }

        // Get orderID and itemId from the request body
        $params = $request->get_json_params();
        $order_id = isset($params['orderID']) ? sanitize_text_field($params['orderID']) : '';
        $item_id = isset($params['itemId']) ? intval($params['itemId']) : null; // Add this line to get itemId

        if (empty($order_id)) {
            return new WP_Error(
                'missing_order_id', 
                'Order ID is required.', 
                ['status' => 400]
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ffc_orders';

        // Get all items in this order
        $order_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE orderID = %s",
            $order_id
        ), ARRAY_A);

        if (empty($order_items)) {
            return new WP_Error(
                'order_not_found', 
                'Order not found.', 
                ['status' => 404]
            );
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            // Verify the order belongs to the current user
            if (!empty($order_items)) {
                $order_user_id = intval($order_items[0]['userId']);
                if ($order_user_id !== get_current_user_id()) {
                    return new WP_Error(
                        'unauthorized', 
                        'You can only refund your own orders.', 
                        ['status' => 403]
                    );
                }
            }
        }

        // Calculate what needs to be refunded
        $items_to_refund = [];
        $total_to_refund = 0;
        $payment_intent_id = null;
        $refunded_item_ids = []; // Track refunded item IDs

        foreach ($order_items as $item) {
            // Skip already refunded items
            if ($item['status'] === 'refunded') {
                continue;
            }
            
            // If item_id is specified, only refund that specific item
            if ($item_id !== null && intval($item['id']) !== $item_id) {
                continue; // Skip items that don't match the specified item_id
            }
            
            // Collect items that need refunding
            $items_to_refund[] = $item;
            $total_to_refund += floatval($item['totalPrice']);
            $refunded_item_ids[] = intval($item['id']); // Track which items are being refunded
            
            // Get payment intent ID from any non-refunded item
            if (!$payment_intent_id && !empty($item['payment_intent_id'])) {
                $payment_intent_id = $item['payment_intent_id'];
            }
        }

        // Check if there's anything to refund
        if (empty($items_to_refund)) {
            return new WP_Error(
                'already_refunded', 
                'This item has already been fully refunded.', 
                ['status' => 400]
            );
        }

        if (!$payment_intent_id) {
            return new WP_Error(
                'no_payment_intent', 
                'No payment intent found for this order.', 
                ['status' => 404]
            );
        }

        // Initialize Stripe
        firefly_collective_stripe_init();

        try {
            // Create a partial refund for the remaining amount
            $refund = \Stripe\Refund::create([
                'payment_intent' => sanitize_text_field($payment_intent_id),
                'amount' => round($total_to_refund * 100), // Convert to cents
                'reason' => 'requested_by_customer',
                'metadata' => [
                    'order_id' => $order_id,
                    'refunded_items' => count($items_to_refund),
                    'partial_refund' => count($items_to_refund) < count($order_items) ? 'true' : 'false',
                    'item_id' => $item_id // Include the specific item ID if set
                ]
            ]);

            if ($refund->status !== 'succeeded') {
                return new WP_Error(
                    'refund_failed', 
                    'Stripe refund status: ' . $refund->status, 
                    ['status' => 500]
                );
            }

            // Update only the specified items to 'refunded' status
            $updated = 0;
            foreach ($items_to_refund as $item) {
                $result = $wpdb->update(
                    $table,
                    ['status' => 'refunded'],
                    ['id' => $item['id']], // Use the specific item ID
                    ['%s'],
                    ['%d']
                );
                if ($result !== false) {
                    $updated += $result;
                }
            }

            if ($updated === 0) {
                return new WP_Error(
                    'db_error', 
                    'Failed to update order status in database.', 
                    ['status' => 500]
                );
            }

            // Send confirmation email
            firefly_collective_orders_email($order_id, 'refunded');

            return [
                'success' => true,
                'message' => count($items_to_refund) < count($order_items) 
                    ? 'Item refunded successfully.' 
                    : 'Order refunded successfully.',
                'refund_id' => $refund->id,
                'amount_refunded' => $total_to_refund,
                'items_refunded' => count($items_to_refund),
                'refunded_item_ids' => $refunded_item_ids // Return the IDs of refunded items
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
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
                
                // Check if customer exists and is not deleted using isset
                if ($customer && (!isset($customer->deleted) || !$customer->deleted)) {
                    // Update customer info if needed
                    \Stripe\Customer::update($stripe_customer_id, [
                        'name' => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
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

        // Get active subscriptions from database with current option details
        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT 
                o.subscription_id, 
                o.subscription_status,
                o.subscription_current_period_end,
                o.userId,
                o.featureId,
                o.optionId,
                MIN(o.createdAt) as started_at,
                o.totalPrice as total_amount,
                f.featureName as features,
                opt.optionName as options,
                opt.interval as intervals
            FROM {$wpdb->prefix}ffc_orders o
            JOIN {$wpdb->prefix}ffc_features f ON o.featureId = f.id
            JOIN {$wpdb->prefix}ffc_options opt ON o.optionId = opt.id
            WHERE o.userId = %d
            AND o.subscription_id IS NOT NULL 
            AND o.subscription_status IN ('active', 'trialing', 'past_due')
            AND o.subscription_renewal = 0
            GROUP BY o.subscription_id, o.subscription_status, o.subscription_current_period_end, o.userId, o.featureId, o.optionId, o.totalPrice, f.featureName, opt.optionName, opt.interval",
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
     * Cancel a subscription (with refund if <= 3 days old)
     */
    function firefly_collective_cancel_subscription($request) {

        $params = $request->get_json_params();
        $subscription_id = isset($params['subscriptionId']) ? sanitize_text_field($params['subscriptionId']) : '';
        
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required', array('status' => 400));
        }
        
        global $wpdb;
        
        // Get order details to check if refund eligible
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ffc_orders WHERE subscription_id = %s",
            $subscription_id
        ));
        
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found', array('status' => 404));
        }
        
        // Check if order is 3 days old or less
        $order_age_days = (time() - strtotime($order->createdAt)) / (60 * 60 * 24);
        $should_refund = $order_age_days <= 3;
        
        // Cancel in Stripe
        firefly_collective_stripe_init();
        
        try {
            $subscription = \Stripe\Subscription::retrieve($subscription_id);
            $subscription->cancel();
            
            // Process refund if eligible
            if ($should_refund && !empty($order->payment_intent_id)) {
                $refund = \Stripe\Refund::create([
                    'payment_intent' => $order->payment_intent_id,
                    'amount' => $order->totalPrice * 100 // Convert to cents
                ]);
            }
            
            // Update database
            $update_data = array(
                'subscription_status' => 'cancelled',
                'subscription_cancelled_at' => current_time('mysql')
            );
            
            if ($should_refund) {
                $update_data['status'] = 'refunded';
            }
            
            $wpdb->update(
                $wpdb->prefix . 'ffc_orders',
                $update_data,
                array('subscription_id' => $subscription_id),
                array('%s', '%s', '%s'),
                array('%s')
            );
            
            $message = $should_refund 
                ? 'Subscription cancelled and refunded successfully' 
                : 'Subscription cancelled successfully';
            
            return array(
                'success' => true,
                'message' => $message,
                'refunded' => $should_refund
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