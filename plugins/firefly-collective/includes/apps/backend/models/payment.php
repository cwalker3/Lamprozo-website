<?php

    // plugin/models/payment.php

    // Initialize Stripe with your API keys
    function firefly_collective_stripe_init() {
        // Use constant if defined, fallback to option if not
        $secret_key = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : get_option('firefly_stripe_secret_key', '');
        
        // Initialize Stripe
        \Stripe\Stripe::setApiKey($secret_key);
        
        // Set API version
        \Stripe\Stripe::setApiVersion('2025-06-30.basil');
    }

    /**
     * Safely get a PI id from an Invoice (works with Basil + older shapes)
     */
    function ff_invoice_pi_id($invoice) {
        // Old field
        if (isset($invoice->payment_intent) && $invoice->payment_intent) {
            return $invoice->payment_intent;
        }

        // New payments array
        if (isset($invoice->payments, $invoice->payments->data) && !empty($invoice->payments->data)) {
            foreach ($invoice->payments->data as $p) {
                // Check if payment exists and has payment_intent
                if (isset($p->payment) && is_object($p->payment) && isset($p->payment->payment_intent)) {
                    return $p->payment->payment_intent;
                }
                // Also check if payment_intent is directly on the payment object
                if (isset($p->payment_intent)) {
                    return $p->payment_intent;
                }
            }
        }

        return null;
    }

    /**
     * Find the subscription id on an invoice, even if invoice.subscription is missing
     * Updated for Basil API 2025-06-30
     */
    function ff_invoice_subscription_id($invoice) {
        // Basil: parent wrapper
        if (! empty($invoice->parent)
            && ! empty($invoice->parent->subscription_details)
            && ! empty($invoice->parent->subscription_details->subscription)
        ) {
            return $invoice->parent->subscription_details->subscription;
        }

        // Fallback for legacy versions
        if (! empty($invoice->subscription)) {
            return $invoice->subscription;
        }

        return null;
    }

    /**
     * True if an invoice line is the recurring subscription line.
     */
    function ff_is_recurring_line($line) {
        // 1) Legacy: line.type === 'subscription'
        if (!empty($line->type) && $line->type === 'subscription') {
            reliable_log(
                "WEBHOOK_DEBUG: ff_is_recurring_line: matched legacy type 'subscription' for line {$line->id}",
                "WEBHOOK_DEBUG"
            );
            return true;
        }

        // 2) Basil: nested under parent.subscription_item_details
        if (!empty($line->parent->type) && $line->parent->type === 'subscription_item_details') {
            reliable_log(
                "WEBHOOK_DEBUG: ff_is_recurring_line: matched Basil parent type 'subscription_item_details' for line {$line->id}",
                "WEBHOOK_DEBUG"
            );
            return true;
        }

        // 3) Fallback: any price.recurring property
        if (!empty($line->price->recurring)) {
            reliable_log(
                "WEBHOOK_DEBUG: ff_is_recurring_line: matched fallback price.recurring for line {$line->id}",
                "WEBHOOK_DEBUG"
            );
            return true;
        }

        // 4) No match
        $type       = isset($line->type) ? $line->type : 'n/a';
        $parentType = isset($line->parent->type) ? $line->parent->type : 'n/a';
        reliable_log(
            "WEBHOOK_DEBUG: ff_is_recurring_line: no match (type={$type}, parent_type={$parentType}) for line {$line->id}",
            "WEBHOOK_DEBUG"
        );
        return false;
    }


    /**
     * Get current period start/end safely (Basil or legacy).
     */
    function ff_subscription_period($sub) {
        // Newer API keeps them on the subscription itself, but older code sometimes used the first item.
        $start = isset($sub->current_period_start) ? $sub->current_period_start : null;
        $end   = isset($sub->current_period_end)   ? $sub->current_period_end   : null;

        if ((!$start || !$end) && isset($sub->items, $sub->items->data[0])) {
            $item  = $sub->items->data[0];
            $start = $start ?: (isset($item->current_period_start) ? $item->current_period_start : null);
            $end   = $end   ?: (isset($item->current_period_end)   ? $item->current_period_end   : null);
        }

        return [
            'start' => $start,
            'end'   => $end,
        ];
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
            $interval = $item['interval'] ?: 'monthly';
            $items_by_interval[$interval][] = $item;
        }

        if (count($items_by_interval) > 1) {
            return new WP_Error('multiple_intervals', 'Multiple subscription intervals in one order not yet supported', ['status' => 400]);
        }

        $interval = array_key_first($items_by_interval);
        $subscription_items = $items_by_interval[$interval];

        // Description
        $item_descriptions = array_map(function($item) use ($wpdb) {
            return format_item_description($item, $wpdb);
        }, $subscription_items);
        $order_description = implode(' | ', $item_descriptions);

        // Create products/prices
        $stripe_items = [];
        foreach ($subscription_items as $item) {
            $item_desc = format_item_description($item, $wpdb);

            $product = \Stripe\Product::create([
                'name'     => $item_desc,
                'metadata' => [
                    'feature_id' => $item['featureId'],
                    'option_id'  => $item['optionId'],
                    'order_id'   => $order_id
                ]
            ]);

            $price = \Stripe\Price::create([
                'product'   => $product->id,
                'unit_amount' => round((float)$item['totalPrice'] * 100),
                'currency'  => 'usd',
                'recurring' => [
                    'interval'       => $interval,
                    'interval_count' => 1
                ]
            ]);

            $stripe_items[] = [
                'price'    => $price->id,
                'quantity' => 1
            ];
        }

        $metadata = [
            'order_id'              => $order_id,
            'wordpress_user_id'     => get_current_user_id(),
            'payment_type'          => 'subscription',
            'subscription_interval' => $interval
        ];

        // IMPORTANT: expand the new path
        $subscription = \Stripe\Subscription::create([
            'customer'          => $customer->id,
            'items'             => $stripe_items,
            'payment_behavior'  => 'default_incomplete',
            'payment_settings'  => ['save_default_payment_method' => 'on_subscription'],
            'expand'            => ['latest_invoice.payments.data.payment'],
            'metadata'          => $metadata,
            'description'       => $order_description
        ]);

        // Grab PI
        $piId = ff_invoice_pi_id($subscription->latest_invoice);
        if ($piId) {
            \Stripe\PaymentIntent::update($piId, ['description' => $order_description]);
        }

        // Periods
        $periods = ff_subscription_period($subscription);

        // Save subscription details to DB
        foreach ($subscription_items as $item) {
            $wpdb->update(
                $wpdb->prefix . 'ffc_orders',
                [
                    'payment_intent_id'               => $piId,
                    'subscription_id'                 => $subscription->id,
                    'subscription_status'             => 'active',
                    'subscription_renewal'            => 0,
                    'subscription_period_start'       => $periods['start'] ? date('Y-m-d H:i:s', $periods['start']) : null,
                    'subscription_current_period_end' => $periods['end']   ? date('Y-m-d H:i:s', $periods['end'])   : null,
                ],
                [
                    'orderID' => $order_id,
                    'id'      => $item['id']
                ],
                ['%s','%s','%s','%d','%s','%s'],
                ['%s','%d']
            );
        }

        $clientSecret = $piId ? \Stripe\PaymentIntent::retrieve($piId)->client_secret : null;

        return [
            'success'        => true,
            'clientSecret'   => $clientSecret,
            'type'           => 'subscription',
            'subscriptionId' => $subscription->id,
        ];
    }


    /**
     * Handle mixed payment (one-time + subscription) using Stripe Invoice Items
     */
    function firefly_collective_create_mixed_payment($customer, $order_id, $recurring_items, $one_time_items) {
        global $wpdb;

        try {
            // Descriptions
            $one_time_descriptions = array_map(function($item) use ($wpdb) {
                return format_item_description($item, $wpdb);
            }, $one_time_items);

            $recurring_descriptions = array_map(function($item) use ($wpdb) {
                return format_item_description($item, $wpdb);
            }, $recurring_items);

            $order_description = implode(' | ', $recurring_descriptions);

            // Totals
            $total_one_time   = array_sum(array_map(fn($i)=> (float)$i['totalPrice'], $one_time_items));
            $total_recurring  = array_sum(array_map(fn($i)=> (float)$i['totalPrice'], $recurring_items));
            $interval         = $recurring_items[0]['interval'] ?: 'monthly';

            $metadata = [
                'order_id'                     => $order_id,
                'wordpress_user_id'            => get_current_user_id(),
                'payment_type'                 => 'mixed',
                'has_one_time_items'           => 'true',
                'total_items'                  => count($one_time_items) + count($recurring_items),
                'subscription_interval'        => $interval,
                'subscription_amount_monthly'  => number_format($total_recurring, 2),
            ];

            // 1. One-time items as invoice items
            foreach ($one_time_items as $item) {
                $item_desc = format_item_description($item, $wpdb);
                \Stripe\InvoiceItem::create([
                    'customer'    => $customer->id,
                    'amount'      => round((float)$item['totalPrice'] * 100),
                    'currency'    => 'usd',
                    'description' => $item_desc,
                    'metadata'    => [
                        'order_id'           => $order_id,
                        'wordpress_user_id'  => get_current_user_id(),
                        'feature_id'         => $item['featureId'],
                        'option_id'          => $item['optionId'],
                        'type'               => 'one_time'
                    ]
                ]);
            }

            // 2. Create products/prices for recurring items
            $stripe_items = [];
            foreach ($recurring_items as $item) {
                $item_desc = format_item_description($item, $wpdb);

                $product = \Stripe\Product::create([
                    'name'     => sprintf('%s ($%.2f/%s)', $item_desc, (float)$item['totalPrice'], $item['interval'] ?: 'month'),
                    'metadata' => [
                        'feature_id'        => $item['featureId'],
                        'option_id'         => $item['optionId'],
                        'order_id'          => $order_id,
                        'recurring_amount'  => (float)$item['totalPrice']
                    ]
                ]);

                $price = \Stripe\Price::create([
                    'product'   => $product->id,
                    'unit_amount' => round((float)$item['totalPrice'] * 100),
                    'currency'  => 'usd',
                    'recurring' => [
                        'interval'       => $interval,
                        'interval_count' => 1
                    ]
                ]);

                $stripe_items[] = ['price' => $price->id, 'quantity' => 1];
            }

            // 3. Create subscription (new expand path)
            $subscription = \Stripe\Subscription::create([
                'customer'          => $customer->id,
                'items'             => $stripe_items,
                'payment_behavior'  => 'default_incomplete',
                'payment_settings'  => ['save_default_payment_method' => 'on_subscription'],
                'expand'            => ['latest_invoice.payments.data.payment'],
                'metadata'          => $metadata,
                'description'       => sprintf('%s - $%.2f/%s', $order_description, $total_recurring, $interval)
            ]);

            // 4. Update PI desc
            $piId = ff_invoice_pi_id($subscription->latest_invoice);
            if ($piId) {
                \Stripe\PaymentIntent::update($piId, [
                    'description'                   => $order_description,
                    'statement_descriptor_suffix'   => 'SUB+SETUP'
                ]);
            }

            // 5. Periods & DB updates
            $periods = ff_subscription_period($subscription);

            foreach ($recurring_items as $item) {
                $wpdb->update(
                    $wpdb->prefix . 'ffc_orders',
                    [
                        'payment_intent_id'               => $piId,
                        'subscription_id'                 => $subscription->id,
                        'subscription_status'             => 'active',
                        'subscription_renewal'            => 0,
                        'subscription_period_start'       => $periods['start'] ? date('Y-m-d H:i:s', $periods['start']) : null,
                        'subscription_current_period_end' => $periods['end']   ? date('Y-m-d H:i:s', $periods['end'])   : null,
                    ],
                    [
                        'orderID' => $order_id,
                        'id'      => $item['id']
                    ],
                    ['%s','%s','%s','%d','%s','%s'],
                    ['%s','%d']
                );
            }

            foreach ($one_time_items as $item) {
                $wpdb->update(
                    $wpdb->prefix . 'ffc_orders',
                    ['payment_intent_id' => $piId],
                    ['orderID' => $order_id, 'id' => $item['id']],
                    ['%s'],
                    ['%s','%d']
                );
            }

            return [
                'success'          => true,
                'clientSecret'     => $piId ? \Stripe\PaymentIntent::retrieve($piId)->client_secret : null,
                'type'             => 'mixed',
                'subscriptionId'   => $subscription->id,
                'totalFirstPayment'=> $total_one_time + $total_recurring,
                'recurringAmount'  => $total_recurring,
                'oneTimeAmount'    => $total_one_time
            ];

        } catch (Exception $e) {
            // Cleanup pending invoice items
            try {
                $invoice_items = \Stripe\InvoiceItem::all([
                    'customer' => $customer->id,
                    'pending'  => true,
                    'limit'    => 100
                ]);
                foreach ($invoice_items->data as $item) {
                    if (isset($item->metadata->order_id) && $item->metadata->order_id === $order_id) {
                        $item->delete();
                    }
                }
            } catch (Exception $cleanup_error) {
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
                    $pi = $event->data->object;
                    $order_id     = isset($pi->metadata->order_id) ? $pi->metadata->order_id : null;
                    $payment_type = isset($pi->metadata->payment_type) ? $pi->metadata->payment_type : null;

                    if ($order_id && $payment_type !== 'subscription_renewal') {
                        firefly_collective_update_order_payment_status($order_id, 'paid');
                    }
                    break;
                    
                case 'payment_intent.payment_failed':
                    $pi = $event->data->object;
                    $order_id = isset($pi->metadata->order_id) ? $pi->metadata->order_id : null;

                    if ($order_id) {
                        firefly_collective_update_order_payment_status($order_id, 'failed');
                    }
                    break;
                
                case 'payment_intent.processing':
                    $pi = $event->data->object;
                    $order_id = isset($pi->metadata->order_id) ? $pi->metadata->order_id : null;
                    if ($order_id) {
                        firefly_collective_update_order_payment_status($order_id, 'processing');
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
                    // Grab the raw invoice object from Stripe
                    $eventInvoice = $event->data->object;

                    // Determine subscription ID using the new Basil path
                    $subscriptionId = ff_invoice_subscription_id($eventInvoice);
                    if (! $subscriptionId) {
                        error_log("WEBHOOK: invoice.paid received but no subscription ID (Basil)"); 
                        break;
                    }

                    // Is this the creation invoice or a renewal?
                    if (isset($eventInvoice->billing_reason) 
                        && $eventInvoice->billing_reason === 'subscription_create'
                    ) {
                        // First subscription payment—update original row only
                        // (your existing subscription‑create logic)
                        firefly_collective_handle_subscription_created(\Stripe\Subscription::retrieve($subscriptionId));
                    } else {
                        // Renewal payment—insert a new order
                        firefly_collective_handle_subscription_invoice_paid($eventInvoice);
                    }
                    break;
                    
                case 'invoice.payment_failed':
                    $invoice = $event->data->object;

                    // Re-retrieve with shallow expand
                    $invoice = \Stripe\Invoice::retrieve($invoice->id, [
                        'expand' => ['payments.data.payment']
                    ]);

                    $subscriptionId = ff_invoice_subscription_id($invoice);
                    if ($subscriptionId) {
                        firefly_collective_handle_subscription_invoice_failed($invoice);
                    }
                    break;

                case 'setup_intent.succeeded':
                    $si = $event->data->object;
                    $wp_user_id = isset($si->metadata->wordpress_user_id) ? $si->metadata->wordpress_user_id : null;

                    if ($wp_user_id) {
                        try {
                            \Stripe\Customer::update($si->customer, [
                                'invoice_settings' => [
                                    'default_payment_method' => $si->payment_method
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

        if (!isset($subscription->metadata->order_id) || !$subscription->metadata->order_id) {
            return;
        }

        firefly_collective_stripe_init(); 

        $subscription = \Stripe\Subscription::retrieve($subscription->id, [
            'expand' => ['items.data']
        ]);

        $original = $wpdb->get_row( $wpdb->prepare(
            "SELECT id
            FROM {$wpdb->prefix}ffc_orders
            WHERE orderID = %s
            AND subscription_id = %s
            LIMIT 1",
            $subscription->metadata->order_id,
            $subscription->id
        ), ARRAY_A );

        if ( ! $original ) { return; }

        $p = ff_subscription_period($subscription);

        $wpdb->update(
            "{$wpdb->prefix}ffc_orders",
            [
                'subscription_status'             => $subscription->status,
                'subscription_current_period_end' => $p['end'] ? date('Y-m-d H:i:s', $p['end']) : null,
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

        firefly_collective_stripe_init();

        $subscription = \Stripe\Subscription::retrieve($subscription->id, [
            'expand' => ['items.data']
        ]);

        $p = ff_subscription_period($subscription);

        $wpdb->update(
            $wpdb->prefix . 'ffc_orders',
            [
                'subscription_status'             => $subscription->status,
                'subscription_current_period_end' => $p['end'] ? date('Y-m-d H:i:s', $p['end']) : null
            ],
            ['subscription_id' => $subscription->id],
            ['%s', '%s'],
            ['%s']
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

        // 1) Log entry
        reliable_log("WEBHOOK_DEBUG: enter handle_subscription_invoice_paid(); invoice ID={$invoice->id}", "WEBHOOK_DEBUG");

        // Ensure expanded invoice
        $invoice = \Stripe\Invoice::retrieve($invoice->id, [
            'expand' => ['payments.data.payment', 'lines.data.price.product']
        ]);
        reliable_log("WEBHOOK_DEBUG: re-retrieved invoice with expand; lines count=" . count($invoice->lines->data), "WEBHOOK_DEBUG");

        // 2) Skip prorations
        if (isset($invoice->billing_reason) && $invoice->billing_reason === 'subscription_update') {
            reliable_log("WEBHOOK_DEBUG: skipping proration invoice (billing_reason=subscription_update)", "WEBHOOK_DEBUG");
            return;
        }

        // 3) Find subscription ID
        $subscriptionId = ff_invoice_subscription_id($invoice);
        reliable_log("WEBHOOK_DEBUG: ff_invoice_subscription_id() => " . var_export($subscriptionId, true), "WEBHOOK_DEBUG");
        if (!$subscriptionId) {
            reliable_log("WEBHOOK_DEBUG: no subscription ID—aborting renewal handler", "WEBHOOK_DEBUG");
            return;
        }

        // 4) Load original order
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
        reliable_log("WEBHOOK_DEBUG: fetched original order row ID=" . ($original['id'] ?? 'null'), "WEBHOOK_DEBUG");
        if (!$original) {
            reliable_log("WEBHOOK_DEBUG: original order not found for subscription {$subscriptionId}", "WEBHOOK_DEBUG");
            return;
        }

        // 5) Check for duplicate renewal
        $piId = ff_invoice_pi_id($invoice);
        reliable_log("WEBHOOK_DEBUG: ff_invoice_pi_id() => " . var_export($piId, true), "WEBHOOK_DEBUG");
        $existing_renewal = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ffc_orders
            WHERE payment_intent_id = %s
            AND subscription_renewal = 1
            AND transaction_type = 'renewal'",
            $piId
        ));
        reliable_log("WEBHOOK_DEBUG: existing renewal count for PI {$piId} => {$existing_renewal}", "WEBHOOK_DEBUG");
        if ($existing_renewal > 0) {
            reliable_log("WEBHOOK_DEBUG: duplicate renewal detected—updating period end only", "WEBHOOK_DEBUG");
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $p = ff_subscription_period($subscription);
            if ($p['end']) {
                $wpdb->update(
                    "{$wpdb->prefix}ffc_orders",
                    ['subscription_current_period_end' => date('Y-m-d H:i:s', $p['end'])],
                    ['id' => $original['id']]
                );
                reliable_log("WEBHOOK_DEBUG: updated original row period_end to {$p['end']}", "WEBHOOK_DEBUG");
            }
            return;
        }

        // 6) Process one-time invoice items (if any)
        foreach ($invoice->lines->data as $line) {
            if (isset($line->type) && $line->type === 'invoiceitem') {
                reliable_log("WEBHOOK_DEBUG: inserting one-time invoice item ID={$line->id}", "WEBHOOK_DEBUG");
                // ... your existing insert code ...
            }
        }

        // 7) Process recurring line(s)
        foreach ($invoice->lines->data as $line) {
            if (!ff_is_recurring_line($line)) {
                reliable_log("WEBHOOK_DEBUG: skipping non-recurring line ID={$line->id}", "WEBHOOK_DEBUG");
                continue;
            }

            // Only handle actual cycles
            if ($invoice->billing_reason !== 'subscription_cycle') {
                reliable_log("WEBHOOK_DEBUG: billing_reason={$invoice->billing_reason}, not 'subscription_cycle'; skipping", "WEBHOOK_DEBUG");
                continue;
            }

            // Compute period and amount
            $periodStart = date('Y-m-d H:i:s', $line->period->start);
            $periodEnd   = date('Y-m-d H:i:s', $line->period->end);
            $amount      = $line->amount / 100;
            $quantity    = $line->quantity;
            reliable_log("WEBHOOK_DEBUG: recurring line detected—period {$periodStart} to {$periodEnd}, amount={$amount}", "WEBHOOK_DEBUG");

            // Create new renewal order
            $new_order_id = wp_generate_uuid4();
            reliable_log("WEBHOOK_DEBUG: creating renewal order new_order_id={$new_order_id}", "WEBHOOK_DEBUG");

            if ($piId) {
                $result = $wpdb->insert(
                    "{$wpdb->prefix}ffc_orders",
                    [
                        'orderID'                         => $new_order_id,
                        'payment_intent_id'               => $piId,
                        'userId'                          => $original['userId'],
                        'featureId'                       => $original['featureId'],
                        'optionId'                        => $original['optionId'],
                        'addonIds'                        => $original['addonIds'],
                        'priceSelected'                   => $original['priceSelected'],
                        'quantity'                        => $quantity,
                        'totalPrice'                      => $amount,
                        'status'                          => 'paid',
                        'createdAt'                       => current_time('mysql'),
                        'subscription_renewal'            => 1,
                        'subscription_period_start'       => $periodStart,
                        'subscription_current_period_end' => $periodEnd,
                    ]
                );

                reliable_log("WEBHOOK_DEBUG: \$wpdb->insert() returned " . var_export($result, true), "WEBHOOK_DEBUG");
            }
            if ($result !== false) {
                firefly_collective_orders_email($new_order_id, 'paid');
                reliable_log("WEBHOOK_DEBUG: renewal order email sent for {$new_order_id}", "WEBHOOK_DEBUG");
            } else {
                reliable_log("WEBHOOK_DEBUG: FAILED to insert renewal order for subscription {$subscriptionId}", "WEBHOOK_DEBUG");
            }

            // Finally, update the original subscription period end on its first row
            $wpdb->update(
                "{$wpdb->prefix}ffc_orders",
                [ 'subscription_current_period_end' => $periodEnd ],
                [ 'id' => $original['id'] ]
            );
            reliable_log("WEBHOOK_DEBUG: updated original row period_end field", "WEBHOOK_DEBUG");

            // break after handling one recurring line
            break;
        }

        reliable_log("WEBHOOK_DEBUG: exit handle_subscription_invoice_paid()", "WEBHOOK_DEBUG");
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
            $feature = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ffc_features WHERE id = %d",
                intval($current_sub['featureId'])
            ), ARRAY_A);
            
            // Create new product with correct name
            $product = \Stripe\Product::create([
                'name' => sprintf('%s - %s', $feature['featureName'], $new_option['optionName']),
                'metadata' => [
                    'feature_id' => intval($current_sub['featureId']),
                    'option_id' => $new_option_id,
                    'plan_change' => 'true'
                ]
            ]);

            $new_price = \Stripe\Price::create([
                'product' => $product->id,
                'unit_amount' => round((float)$new_option['staticPrice'] * 100),
                'currency' => 'usd',
                'recurring' => [
                    'interval' => $new_option['interval'] ?: 'month',
                    'interval_count' => 1
                ]
            ]);

            // Calculate proration using preview
            $immediate_charge = 0;

            reliable_log("Starting proration calculation for subscription: $subscription_id", "PRORATION");

            try {
                // Create preview with proper parameters for Basil API
                $preview_params = [
                    'customer' => $customer->id,
                    'subscription' => $subscription_id,
                    'subscription_details' => [
                        'items' => [
                            [
                                'id' => $stripe_sub->items->data[0]->id,
                                'price' => $new_price->id,
                            ],
                        ],
                        'proration_behavior' => 'always_invoice', // Changed to always_invoice
                    ],
                ];

                reliable_log("Creating invoice preview with params: " . json_encode($preview_params), "PRORATION");
                
                $proration_preview = $stripe->invoices->createPreview($preview_params);
                
                reliable_log("Preview created - Amount due: " . $proration_preview->amount_due, "PRORATION");
                
                // Get the proration amount directly from amount_due
                $immediate_charge = $proration_preview->amount_due / 100;
                
                reliable_log("Proration amount: $immediate_charge", "PRORATION");

            } catch (Exception $e) {
                reliable_log('Failed to preview invoice: ' . $e->getMessage(), "PRORATION_ERROR");
                
                // Manual proration fallback (keep existing fallback code)
                $current_time = time();
                $period_start = $stripe_sub->current_period_start ?? $current_time;
                $period_end = $stripe_sub->current_period_end ?? ($current_time + 2592000);

                $total_days = ($period_end - $period_start) / 86400;
                $days_remaining = max(0, ($period_end - $current_time) / 86400);

                if ($days_remaining > 0 && $total_days > 0) {
                    $old_price = (float)$current_sub['totalPrice'];
                    $new_price_amount = (float)$new_option['staticPrice'];

                    $credit = ($old_price / $total_days) * $days_remaining;
                    $charge = ($new_price_amount / $total_days) * $days_remaining;

                    $immediate_charge = round($charge - $credit, 2);
                }
            }

            // Create a SetupIntent for payment method collection (keeping original flow)
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
            
            reliable_log("Starting plan change completion for SetupIntent: $setup_intent_id", "PLAN_CHANGE");
            
            // Create a StripeClient instance
            $stripe = new \Stripe\StripeClient(\Stripe\Stripe::$apiKey);
            
            // Retrieve the SetupIntent to get metadata
            $setup_intent = $stripe->setupIntents->retrieve($setup_intent_id);
            
            if ($setup_intent->status !== 'succeeded') {
                reliable_log("SetupIntent not succeeded: " . $setup_intent->status, "PLAN_CHANGE_ERROR");
                return new WP_Error('payment_not_confirmed', 'Payment method not confirmed', array('status' => 400));
            }
            
            $subscription_id = $setup_intent->metadata->subscription_id;
            $new_option_id = intval($setup_intent->metadata->new_option_id);
            $immediate_charge = floatval($setup_intent->metadata->immediate_charge);
            $is_renewal = $setup_intent->metadata->is_renewal === 'true';
            $new_price_id = $setup_intent->metadata->new_price_id;
            
            reliable_log("Plan change params - Sub: $subscription_id, NewOption: $new_option_id, Charge: $immediate_charge, Renewal: " . ($is_renewal ? 'true' : 'false'), "PLAN_CHANGE");
            
            global $wpdb;
            
            // Get new option details
            $new_option = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ffc_options WHERE id = %d",
                $new_option_id
            ), ARRAY_A);
            
            // Retrieve the subscription before update
            $stripe_sub = $stripe->subscriptions->retrieve($subscription_id);
            reliable_log("Current subscription status before update: " . $stripe_sub->status, "PLAN_CHANGE");
            
            // Check if subscription is past_due and this is a renewal
            $was_past_due = ($stripe_sub->status === 'past_due');
            
            // For past_due renewals, we need to handle differently
            if ($was_past_due && $is_renewal) {
                // First, update the default payment method
                $stripe->customers->update($stripe_sub->customer, [
                    'invoice_settings' => ['default_payment_method' => $setup_intent->payment_method]
                ]);
                
                // Find and pay any open invoices
                $open_invoices = $stripe->invoices->all([
                    'subscription' => $subscription_id,
                    'status' => 'open',
                    'limit' => 10
                ]);
                
                $total_paid = 0;
                $paid_invoices = [];
                
                foreach ($open_invoices->data as $invoice) {
                    try {
                        reliable_log("Found open invoice: " . $invoice->id . " for amount: " . ($invoice->amount_due / 100), "PLAN_CHANGE");
                        
                        // Pay the invoice
                        $paid_invoice = $stripe->invoices->pay($invoice->id);
                        
                        if ($paid_invoice->status === 'paid') {
                            // Re-retrieve with proper expansion
                            $paid_invoice = $stripe->invoices->retrieve($invoice->id, [
                                'expand' => ['payment_intent', 'payments.data.payment']
                            ]);
                            
                            $total_paid += $paid_invoice->amount_paid / 100;
                            
                            // Use the helper function to get payment_intent_id
                            $payment_intent_id = ff_invoice_pi_id($paid_invoice);
                            
                            $paid_invoices[] = [
                                'invoice' => $paid_invoice,
                                'payment_intent_id' => $payment_intent_id
                            ];
                            
                            reliable_log("Successfully paid invoice: " . $invoice->id . " with payment_intent: " . ($payment_intent_id ?? 'null'), "PLAN_CHANGE");
                        }
                    } catch (Exception $e) {
                        reliable_log("Failed to pay invoice " . $invoice->id . ": " . $e->getMessage(), "PLAN_CHANGE_ERROR");
                    }
                }
                
                // Only update subscription if we're actually changing the plan
                if ($new_price_id && $stripe_sub->items->data[0]->price->id !== $new_price_id) {
                    $update_params = [
                        'items' => [
                            [
                                'id' => $stripe_sub->items->data[0]->id,
                                'price' => $new_price_id
                            ]
                        ],
                        'proration_behavior' => 'none',
                        'description' => sprintf('%s - $%.2f/%s', $new_option['optionName'], floatval($new_option['staticPrice']), $new_option['interval'])
                    ];
                    
                    $updated_sub = $stripe->subscriptions->update($subscription_id, $update_params);
                    reliable_log("Subscription plan updated. New status: " . $updated_sub->status, "PLAN_CHANGE");
                } else {
                    $updated_sub = $stripe->subscriptions->retrieve($subscription_id);
                    reliable_log("Subscription payment updated. Status: " . $updated_sub->status, "PLAN_CHANGE");
                }
                
                // Update the existing subscription record
                $periods = ff_subscription_period($updated_sub);
                
                $update_result = $wpdb->update(
                    $wpdb->prefix . 'ffc_orders',
                    array(
                        'optionId' => $new_option_id,
                        'totalPrice' => floatval($new_option['staticPrice']),
                        'subscription_status' => $updated_sub->status,
                        'subscription_current_period_end' => $periods['end'] ? date('Y-m-d H:i:s', $periods['end']) : null,
                        'updatedAt' => current_time('mysql')
                    ),
                    array(
                        'subscription_id' => $subscription_id,
                        'subscription_renewal' => 0
                    ),
                    array('%d', '%s', '%s', '%s', '%s'),
                    array('%s', '%d')
                );
                
                // Create a single transaction record for the renewal payment
                if ($total_paid > 0) {
                    $new_order_id = wp_generate_uuid4();
                    
                    $original_order = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ffc_orders 
                        WHERE subscription_id = %s 
                        ORDER BY createdAt ASC LIMIT 1",
                        $subscription_id
                    ), ARRAY_A);
                    
                    // Get the payment intent from the first paid invoice
                    $payment_intent_id = null;
                    if (!empty($paid_invoices)) {
                        $payment_intent_id = $paid_invoices[0]['payment_intent_id'];
                        reliable_log("Using payment_intent_id: " . ($payment_intent_id ?? 'null') . " for renewal record", "PLAN_CHANGE");
                    }
                    
                    $transaction_data = array(
                        'orderID' => $new_order_id,
                        'payment_intent_id' => $payment_intent_id,  // This should now have a value!
                        'userId' => $original_order['userId'],
                        'featureId' => $original_order['featureId'],
                        'optionId' => $new_option_id,
                        'addonIds' => $original_order['addonIds'],
                        'priceSelected' => $original_order['priceSelected'],
                        'quantity' => 1,
                        'totalPrice' => $total_paid,
                        'totalPriceDiscount' => 0,
                        'priceDiscountsInfo' => json_encode([
                            'description' => 'Past due subscription renewal'
                        ]),
                        'userData' => $original_order['userData'],
                        'status' => 'paid',
                        'transaction_type' => 'renewal',
                        'subscription_renewal' => 1,
                        'createdAt' => current_time('mysql')
                    );
                    
                    $wpdb->insert($wpdb->prefix . 'ffc_orders', $transaction_data);
                    reliable_log("Created renewal transaction record: $new_order_id with payment_intent: " . ($payment_intent_id ?? 'null'), "PLAN_CHANGE");
                    
                    // Send confirmation email
                    firefly_collective_orders_email($new_order_id, 'renewed');
                }
            } else {
                // Not a past_due renewal - use the original logic with always_invoice
                $update_params = [
                    'items' => [
                        [
                            'id' => $stripe_sub->items->data[0]->id,
                            'price' => $new_price_id
                        ]
                    ],
                    'proration_behavior' => 'always_invoice',
                    'payment_behavior' => 'allow_incomplete',
                    'default_payment_method' => $setup_intent->payment_method,
                    'description' => sprintf('%s - $%.2f/%s', $new_option['optionName'], floatval($new_option['staticPrice']), $new_option['interval'])
                ];
                
                reliable_log("Updating subscription with params: " . json_encode($update_params), "PLAN_CHANGE");
                
                $updated_sub = $stripe->subscriptions->update($subscription_id, $update_params);
                
                reliable_log("Subscription updated successfully. New status: " . $updated_sub->status, "PLAN_CHANGE");
                
                // Get the latest invoice
                $latest_invoice = $updated_sub->latest_invoice;
                if (is_string($latest_invoice)) {
                    $latest_invoice = $stripe->invoices->retrieve($latest_invoice, [
                        'expand' => ['lines.data', 'payments.data.payment']
                    ]);
                }
                
                // Get actual charged amount
                $actual_charge = 0;
                if ($latest_invoice && $latest_invoice->status === 'paid') {
                    $actual_charge = $latest_invoice->amount_paid / 100;
                }
                
                // Update the existing subscription record
                $periods = ff_subscription_period($updated_sub);
                
                $update_result = $wpdb->update(
                    $wpdb->prefix . 'ffc_orders',
                    array(
                        'optionId' => $new_option_id,
                        'totalPrice' => floatval($new_option['staticPrice']),
                        'subscription_status' => $updated_sub->status,
                        'subscription_current_period_end' => $periods['end'] ? date('Y-m-d H:i:s', $periods['end']) : null,
                        'updatedAt' => current_time('mysql')
                    ),
                    array(
                        'subscription_id' => $subscription_id,
                        'subscription_renewal' => 0
                    ),
                    array('%d', '%s', '%s', '%s', '%s'),
                    array('%s', '%d')
                );
                
                // Create transaction record only if there was a charge
                if ($actual_charge > 0) {
                    $new_order_id = wp_generate_uuid4();
                    
                    $original_order = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ffc_orders 
                        WHERE subscription_id = %s 
                        ORDER BY createdAt ASC LIMIT 1",
                        $subscription_id
                    ), ARRAY_A);
                    
                    $description = $actual_charge > 0 ? 'Plan upgrade proration' : 'Plan change';
                    
                    $transaction_data = array(
                        'orderID' => $new_order_id,
                        'payment_intent_id' => ($latest_invoice && $latest_invoice->payment_intent) ? $latest_invoice->payment_intent : null,
                        'userId' => $original_order['userId'],
                        'featureId' => $original_order['featureId'],
                        'optionId' => $new_option_id,
                        'addonIds' => $original_order['addonIds'],
                        'priceSelected' => $original_order['priceSelected'],
                        'quantity' => 1,
                        'totalPrice' => $actual_charge,
                        'totalPriceDiscount' => 0,
                        'priceDiscountsInfo' => json_encode(['description' => $description]),
                        'userData' => $original_order['userData'],
                        'status' => 'paid',
                        'transaction_type' => 'plan_change',
                        'subscription_renewal' => 1,
                        'createdAt' => current_time('mysql')
                    );
                    
                    $wpdb->insert($wpdb->prefix . 'ffc_orders', $transaction_data);
                    reliable_log("Created plan change transaction record: $new_order_id", "PLAN_CHANGE");
                    
                    // Send confirmation email
                    firefly_collective_orders_email($new_order_id, 'plan_change');
                }
            }
            
            reliable_log("Plan change completed successfully", "PLAN_CHANGE");
            
            return array(
                'success' => true,
                'message' => $is_renewal ? 'Subscription renewed successfully!' : 'Plan changed successfully!',
                'newPlan' => $new_option['optionName'],
                'newPrice' => floatval($new_option['staticPrice']),
                'transactionCreated' => true
            );
            
        } catch (Exception $e) {
            reliable_log('Complete plan change error: ' . $e->getMessage(), "PLAN_CHANGE_ERROR");
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
     * Get subscriptions.
     * - Admins see EVERYONE'S subscriptions.
     * - Regular users see only their own active/trialing/past_due subs.
     */
    function firefly_collective_get_subscriptions( $request ) {
        global $wpdb;

        $user_id  = get_current_user_id();
        $is_admin = current_user_can('manage_options');

        if ( ! $user_id ) {
            return new WP_Error('not_logged_in', 'You must be logged in to view subscriptions.', ['status' => 401]);
        }

        // Base pieces of the query
        $select = "
            SELECT DISTINCT
                o.subscription_id,
                o.subscription_status,
                o.subscription_current_period_end,
                o.userId,
                o.featureId,
                o.optionId,
                MIN(o.createdAt) AS started_at,
                o.totalPrice AS total_amount,
                f.featureName AS features,
                opt.optionName AS options,
                opt.interval AS intervals
        ";

        $from = "
            FROM {$wpdb->prefix}ffc_orders o
            JOIN {$wpdb->prefix}ffc_features f ON o.featureId = f.id
            JOIN {$wpdb->prefix}ffc_options  opt ON o.optionId = opt.id
        ";

        $group_by = "
            GROUP BY
                o.subscription_id,
                o.subscription_status,
                o.subscription_current_period_end,
                o.userId,
                o.featureId,
                o.optionId,
                o.totalPrice,
                f.featureName,
                opt.optionName,
                opt.interval
        ";

        // Common WHERE parts
        $where_base = "o.subscription_id IS NOT NULL AND o.subscription_renewal = 0";

        if ( $is_admin ) {
            // Admin: show everything, any status
            $sql = "
                $select
                $from
                WHERE $where_base
                $group_by
            ";
            $subscriptions = $wpdb->get_results( $sql, ARRAY_A );

            // Don't bother pulling a Stripe payment method for every user here (performance).
            foreach ( $subscriptions as &$sub ) {
                $sub['payment_method'] = null;
            }
        } else {
            // Regular user: only their subscriptions and only active-ish ones
            $sql = $wpdb->prepare("
                $select
                $from
                WHERE $where_base
                AND o.userId = %d
                AND o.subscription_status IN ('active','trialing','past_due')
                $group_by
            ", $user_id );

            $subscriptions = $wpdb->get_results( $sql, ARRAY_A );

            // Enrich with the user's default payment method (optional, as before)
            firefly_collective_stripe_init();
            $stripe_customer_id = get_user_meta( $user_id, 'stripe_customer_id', true );

            if ( $stripe_customer_id ) {
                try {
                    $customer = \Stripe\Customer::retrieve($stripe_customer_id, [
                        'expand' => ['invoice_settings.default_payment_method']
                    ]);

                    $payment_method = null;
                    if ( $customer->invoice_settings->default_payment_method ) {
                        $pm = \Stripe\PaymentMethod::retrieve($customer->invoice_settings->default_payment_method);
                        $payment_method = [
                            'type'      => $pm->type,
                            'last4'     => $pm->card->last4 ?? null,
                            'brand'     => $pm->card->brand ?? null,
                            'exp_month' => $pm->card->exp_month ?? null,
                            'exp_year'  => $pm->card->exp_year ?? null,
                        ];
                    }

                    foreach ( $subscriptions as &$sub ) {
                        $sub['payment_method'] = $payment_method;
                    }
                } catch ( Exception $e ) {
                    error_log('Error retrieving Stripe customer: ' . $e->getMessage());
                }
            }
        }

        return [
            'success'        => true,
            'subscriptions'  => $subscriptions,
        ];
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
                'subscription_status'      => 'cancelled',
                'subscription_cancelled_at'=> current_time('mysql')
            );

            if ($should_refund) {
                $update_data['status'] = 'refunded';
            }

            $format = $should_refund ? array('%s','%s','%s') : array('%s','%s');

            $wpdb->update(
                $wpdb->prefix . 'ffc_orders',
                $update_data,
                array('subscription_id' => $subscription_id),
                $format,
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