<?php

    // plugin/models/payment.php

    // Initialize Stripe with your API keys
    function firefly_collective_stripe_init() {
        // Use constant if defined, fallback to option if not
        $secret_key = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : get_option('firefly_stripe_secret_key', '');
        
        // Initialize Stripe
        \Stripe\Stripe::setApiKey($secret_key);
        
        // Set API version
        \Stripe\Stripe::setApiVersion('2025-07-30.basil');
    }

    /**
     * Safely get a PI id from an Invoice (works with Basil + older shapes)
     */
    function ff_invoice_pi_id($invoice) {
        // 1) Legacy field
        if (! empty($invoice->payment_intent)) {
            return $invoice->payment_intent;
        }

        // 2) Basil: inspect InvoicePayment objects under invoice.payments
        if (! empty($invoice->payments->data)) {
            foreach ($invoice->payments->data as $invPay) {
                // If 'payment' is expanded to a full PaymentIntent object
                if (isset($invPay->payment) && is_object($invPay->payment) && ! empty($invPay->payment->id)) {
                    return $invPay->payment->id;
                }
                // If 'payment' remains the InvoicePayment sub‑object with a payment_intent string
                if (isset($invPay->payment->payment_intent)) {
                    return $invPay->payment->payment_intent;
                }
                // Fallback: direct payment_intent on the InvoicePayment
                if (isset($invPay->payment_intent)) {
                    return $invPay->payment_intent;
                }
            }
        }

        // 3) Final fallback: expanded charge.payment_intent
        if (isset($invoice->charge) && ! empty($invoice->charge->payment_intent)) {
            return $invoice->charge->payment_intent;
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
     * Calculate discounted prices for an order item (replicates frontend logic)
     * Returns array with discounted base price and addon prices
     */
    function calculate_discounted_prices($item, $wpdb) {
        $quantity = max(1, intval($item['quantity']));
        
        // Get option and addons data
        $option = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ffc_options WHERE id = %d",
            intval($item['optionId'])
        ), ARRAY_A);
        
        if (!$option) return ['base_per_unit' => 0, 'addons' => []];
        
        // Get base price from price options or static price
        $base_price = 0;
        if ($option['priceOptions']) {
            try {
                $price_options = json_decode($option['priceOptions'], true);
                if (isset($price_options['types']) && isset($item['priceSelected'])) {
                    $price_index = intval($item['priceSelected']);
                    if (isset($price_options['types'][$price_index])) {
                        $base_price = floatval($price_options['types'][$price_index]['price']);
                    }
                }
            } catch (Exception $e) {
                $base_price = floatval($option['staticPrice']);
            }
        } else {
            $base_price = floatval($option['staticPrice']);
        }
        
        // Calculate addon prices with group discounts
        $addon_prices = [];
        $addons_by_group = [];
        $addon_ids = json_decode($item['addonIds'], true) ?: [];
        
        // Group addons and calculate base addon prices
        foreach ($addon_ids as $addon_id) {
            $addon = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ffc_addons WHERE id = %d",
                intval($addon_id)
            ), ARRAY_A);
            
            if (!$addon) continue;
            
            $addon_price = floatval($addon['staticPriceMod']);
            
            if ($addon['enableGrouping'] && $addon['groupName']) {
                if (!isset($addons_by_group[$addon['groupName']])) {
                    $addons_by_group[$addon['groupName']] = [
                        'addons' => [],
                        'thresholds' => []
                    ];
                    
                    // Parse group threshold discounts
                    if ($addon['groupThresholdDiscounts']) {
                        try {
                            $threshold_data = json_decode($addon['groupThresholdDiscounts'], true);
                            if (isset($threshold_data['types'])) {
                                $addons_by_group[$addon['groupName']]['thresholds'] = $threshold_data['types'];
                            }
                        } catch (Exception $e) {
                            // Ignore parse errors
                        }
                    }
                }
                $addons_by_group[$addon['groupName']]['addons'][] = [
                    'id' => $addon_id,
                    'price' => $addon_price
                ];
            }
            
            $addon_prices[$addon_id] = $addon_price; // Start with base price
        }
        
        // Apply group discounts
        foreach ($addons_by_group as $group_name => $group_data) {
            $group_count = count($group_data['addons']);
            $thresholds = $group_data['thresholds'];
            
            // Find applicable discount
            $applicable_discount = 0;
            foreach ($thresholds as $threshold) {
                if ($group_count >= intval($threshold['itemCount'])) {
                    $applicable_discount = max($applicable_discount, floatval($threshold['discount']));
                }
            }
            
            // Apply discount to group addons
            if ($applicable_discount > 0) {
                foreach ($group_data['addons'] as $addon_data) {
                    $original_price = $addon_data['price'];
                    $discounted_price = $original_price * (1 - $applicable_discount / 100);
                    $addon_prices[$addon_data['id']] = $discounted_price;
                }
            }
        }
        
        // Calculate total addon cost per unit
        $total_addon_cost_per_unit = array_sum($addon_prices);
        
        // Apply quantity discount to base price
        $discounted_base_total = $base_price * $quantity;
        if ($option['thresholdDiscounts']) {
            try {
                $threshold_data = json_decode($option['thresholdDiscounts'], true);
                $thresholds = $threshold_data ?: [];
                
                // Find highest applicable discount
                $best_discount = 0;
                foreach ($thresholds as $threshold) {
                    if ($quantity >= intval($threshold['itemCount'])) {
                        $best_discount = max($best_discount, floatval($threshold['discount']));
                    }
                }
                
                if ($best_discount > 0) {
                    $discounted_base_total = ($base_price * $quantity) * (1 - $best_discount / 100);
                }
            } catch (Exception $e) {
                // Ignore parse errors
            }
        }
        
        $base_per_unit = $discounted_base_total / $quantity;
        
        return [
            'base_per_unit' => $base_per_unit,
            'addons' => $addon_prices
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
            
            $first_item = $order_items[0];
            $user_id = intval($first_item['userId']);
            
            // Handle anonymous vs authenticated users for Stripe customer
            if ($user_id === 0) {
                // Anonymous user - use data from order
                $anon_data = [
                    'firstName' => $first_item['anonUserFirstName'] ?? '',
                    'lastName' => $first_item['anonUserLastName'] ?? '',
                    'email' => $first_item['anonUserEmail'] ?? '',
                    'phone' => $first_item['anonUserPhone'] ?? ''
                ];
                
                if (empty($anon_data['email'])) {
                    return new WP_Error('missing_email', 'Email required for anonymous orders', array('status' => 400));
                }
                
                $customer = firefly_collective_get_or_create_stripe_customer(0, $anon_data);
            } else {
                // Authenticated user
                $customer = firefly_collective_get_or_create_stripe_customer($user_id);
            }
            
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
                // Mixed payment
                return firefly_collective_create_mixed_payment($customer, $order_id, $recurring_items, $one_time_items);
            } elseif ($has_recurring) {
                // Subscription only - works for both authenticated and anonymous users
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
     * Create a one-time invoice and charge using Stripe Invoicing API
     */
    function firefly_collective_create_one_time_payment($customer, $order_id, $items) {
        global $wpdb;
        firefly_collective_stripe_init();

        // 1. Create an InvoiceItem for each one-time order row, splitting base + addons
        foreach ($items as $item) {
            $quantity = max(1, intval($item['quantity'])); // Ensure minimum quantity of 1
            
            // Calculate discounted prices
            $discounted_prices = calculate_discounted_prices($item, $wpdb);
            
            // Build a description like: "FeatureName – OptionName"
            $baseDesc = format_item_description([
                'featureName' => $item['featureName'],
                'optionName'  => $item['optionName'],
                'addonIds'    => json_encode([]),
            ], $wpdb);
            
            $baseCents = round($discounted_prices['base_per_unit'] * 100);
            
            \Stripe\InvoiceItem::create([
                'customer'              => $customer->id,
                'unit_amount_decimal'   => $baseCents,
                'quantity'              => $quantity,
                'currency'              => 'usd',
                'description'           => $baseDesc,
                'metadata'              => [
                    'order_id' => $order_id,
                    'item_id'  => $item['id'],
                    'type'     => 'base',
                ],
            ]);

            // -- one line per addon --
            $addonIds = json_decode($item['addonIds'], true);
            if (!empty($addonIds)) {
                foreach ($addonIds as $aid) {
                    $addonName = $wpdb->get_var($wpdb->prepare(
                        "SELECT addonName FROM {$wpdb->prefix}ffc_addons WHERE id = %d",
                        intval($aid)
                    ));
                    $addonCents = round($discounted_prices['addons'][$aid] * 100);
                    
                    \Stripe\InvoiceItem::create([
                        'customer'              => $customer->id,
                        'unit_amount_decimal'   => $addonCents,
                        'quantity'              => $quantity,
                        'currency'              => 'usd',
                        'description'           => $addonName,
                        'metadata'              => [
                            'order_id' => $order_id,
                            'item_id'  => $item['id'],
                            'type'     => 'addon',
                            'addon_id' => $aid,
                        ],
                    ]);
                }
            }
        }

        // 2. Create a draft invoice, including all pending InvoiceItems
        $invoice = \Stripe\Invoice::create([
            'customer'                       => $customer->id,
            'collection_method'              => 'charge_automatically',
            'auto_advance'                   => true,
            'pending_invoice_items_behavior' => 'include',
            'metadata'                       => [ 'order_id' => $order_id ],
        ]);

        // 3. Finalize the invoice (this will open it and, if amount > $0, create a PI)
        $invoice = $invoice->finalizeInvoice([
            'expand' => ['payments.data.payment'],
        ]);

        // 4. Grab the PaymentIntent ID (if any)
        $piId = ff_invoice_pi_id($invoice);

        // 5. Update *each* order-row to stamp in invoice_id + payment_intent_id
        foreach ($items as $item) {
            $wpdb->update(
                "{$wpdb->prefix}ffc_orders",
                [
                    'invoice_id'        => $invoice->id,
                    'payment_intent_id' => $piId,
                ],
                [
                    'orderID' => $order_id,
                    'id'      => $item['id'],
                ],
                ['%s','%s'],
                ['%s','%d']
            );
        }

        // 6. Return the client secret to the front-end (if we have a PI)
        $clientSecret = $piId
            ? \Stripe\PaymentIntent::retrieve($piId)->client_secret
            : null;

        return [
            'success'      => true,
            'invoiceId'    => $invoice->id,
            'clientSecret' => $clientSecret,
        ];
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
                    'subscriptionPrice'               => floatval($item['totalPrice']), // Add this line
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
            // 1. Create InvoiceItems for each one-time item, splitting base + addons
            foreach ($one_time_items as $item) {
                $quantity = max(1, intval($item['quantity'])); // Ensure minimum quantity of 1
                
                // Calculate discounted prices
                $discounted_prices = calculate_discounted_prices($item, $wpdb);
                
                // Build base description: "FeatureName – OptionName"
                $baseDesc = format_item_description([
                    'featureName' => $item['featureName'],
                    'optionName'  => $item['optionName'],
                    'addonIds'    => json_encode([]),
                ], $wpdb);

                $baseCents = round($discounted_prices['base_per_unit'] * 100);

                \Stripe\InvoiceItem::create([
                    'customer'              => $customer->id,
                    'unit_amount_decimal'   => $baseCents,
                    'quantity'              => $quantity,
                    'currency'              => 'usd',
                    'description'           => $baseDesc,
                    'metadata'              => [
                        'order_id' => $order_id,
                        'item_id'  => $item['id'],
                        'type'     => 'base',
                    ],
                ]);

                // Create one line per addon
                $addonIds = json_decode($item['addonIds'], true);
                if (!empty($addonIds)) {
                    foreach ($addonIds as $aid) {
                        $addonName = $wpdb->get_var($wpdb->prepare(
                            "SELECT addonName FROM {$wpdb->prefix}ffc_addons WHERE id = %d",
                            intval($aid)
                        ));
                        $addonCents = round($discounted_prices['addons'][$aid] * 100);

                        \Stripe\InvoiceItem::create([
                            'customer'              => $customer->id,
                            'unit_amount_decimal'   => $addonCents,
                            'quantity'              => $quantity,
                            'currency'              => 'usd',
                            'description'           => $addonName,
                            'metadata'     => [
                                'order_id' => $order_id,
                                'item_id'  => $item['id'],
                                'type'     => 'addon',
                                'addon_id' => $aid,
                            ],
                        ]);
                    }
                }
            }

            // 2. Create products/prices for recurring items
            $stripe_items = [];
            $interval     = $recurring_items[0]['interval'] ?: 'monthly';
            foreach ($recurring_items as $item) {
                $itemDesc = format_item_description($item, $wpdb);

                $product = \Stripe\Product::create([
                    'name'     => sprintf('%s ($%.2f/%s)', $itemDesc, (float)$item['totalPrice'], $interval),
                    'metadata' => [
                        'feature_id'       => $item['featureId'],
                        'option_id'        => $item['optionId'],
                        'order_id'         => $order_id,
                        'recurring_amount' => (float)$item['totalPrice'],
                    ],
                ]);

                $price = \Stripe\Price::create([
                    'product'      => $product->id,
                    'unit_amount'  => round((float)$item['totalPrice'] * 100),
                    'currency'     => 'usd',
                    'recurring'    => [
                        'interval'       => $interval,
                        'interval_count' => 1,
                    ],
                ]);

                $stripe_items[] = ['price' => $price->id, 'quantity' => 1];
            }

            // 3. Create the subscription, expanding the first invoice’s payment
            $subscription = \Stripe\Subscription::create([
                'customer'         => $customer->id,
                'items'            => $stripe_items,
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
                'expand'           => ['latest_invoice.payments.data.payment'],
                'metadata'         => [
                    'order_id'                  => $order_id,
                    'payment_type'              => 'mixed',
                    'subscription_interval'     => $interval,
                    'has_one_time_items'        => 'true',
                    'total_one_time_amount'     => number_format(array_sum(array_map(fn($i)=>(float)$i['totalPrice'], $one_time_items)),2),
                    'subscription_amount_monthly'=> number_format(array_sum(array_map(fn($i)=>(float)$i['totalPrice'], $recurring_items)),2),
                ],
            ]);

            // 4. Retrieve and update the PaymentIntent description
            $piId = ff_invoice_pi_id($subscription->latest_invoice);
            if ($piId) {
                \Stripe\PaymentIntent::update($piId, [
                    'description'                 => 'Mixed order: ' . implode(' | ', array_map(fn($i)=>$i['featureName'].'–'.$i['optionName'], $recurring_items)),
                    'statement_descriptor_suffix' => 'MIXED+SETUP',
                ]);
            }

            // 5. Persist subscription and payment intent into DB
            $periods = ff_subscription_period($subscription);
            foreach ($recurring_items as $item) {
                $wpdb->update(
                    "{$wpdb->prefix}ffc_orders",
                    [
                        'payment_intent_id'               => $piId,
                        'subscription_id'                 => $subscription->id,
                        'subscription_status'             => 'active',
                        'subscription_renewal'            => 0,
                        'subscription_period_start'       => $periods['start'] ? date('Y-m-d H:i:s', $periods['start']) : null,
                        'subscription_current_period_end' => $periods['end']   ? date('Y-m-d H:i:s', $periods['end'])   : null,
                        'subscriptionPrice'               => (float)$item['totalPrice'],
                    ],
                    ['orderID' => $order_id, 'id' => $item['id']],
                    ['%s','%d','%s','%d','%s','%s','%f'],
                    ['%s','%d']
                );
            }
            foreach ($one_time_items as $item) {
                $wpdb->update(
                    "{$wpdb->prefix}ffc_orders",
                    ['payment_intent_id' => $piId],
                    ['orderID' => $order_id, 'id' => $item['id']],
                    ['%s'],
                    ['%s','%d']
                );
            }

            // 6. Return client secret for front-end confirmation
            return [
                'success'        => true,
                'clientSecret'   => $piId ? \Stripe\PaymentIntent::retrieve($piId)->client_secret : null,
                'subscriptionId' => $subscription->id,
                'type'           => 'mixed',
            ];

        } catch (Exception $e) {
            // Clean up pending InvoiceItems on error
            try {
                $pending = \Stripe\InvoiceItem::all(['customer' => $customer->id, 'pending' => true, 'limit' => 100]);
                foreach ($pending->data as $ii) {
                    if ($ii->metadata->order_id === $order_id) {
                        $ii->delete();
                    }
                }
            } catch (Exception $cleanup) {
                error_log("Cleanup failed: " . $cleanup->getMessage());
            }
            throw new Exception("Failed to create mixed payment: " . $e->getMessage());
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
                    $order_id = isset($pi->metadata->order_id) ? $pi->metadata->order_id : null;
                    $payment_type = isset($pi->metadata->payment_type) ? $pi->metadata->payment_type : null;

                    if ($order_id && $payment_type !== 'subscription_renewal') {
                        firefly_collective_update_order_payment_status($order_id, 'paid');
                        
                        // Create user account if requested
                        firefly_collective_create_user_after_payment($order_id);
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
                    $eventInvoice = $event->data->object;
                    $subscriptionId = ff_invoice_subscription_id($eventInvoice);

                    if (
                        isset($eventInvoice->billing_reason)
                        && $eventInvoice->billing_reason === 'subscription_create'
                    ) {
                        // Update subscription data
                        firefly_collective_handle_subscription_created(
                            \Stripe\Subscription::retrieve($subscriptionId)
                        );

                        // Get order ID from subscription metadata
                        $subscription = \Stripe\Subscription::retrieve($subscriptionId);
                        $order_id = $subscription->metadata->order_id ?? null;

                        if ($order_id) {
                            firefly_collective_orders_email($order_id, 'paid');
                            
                            // Create user account for subscription orders
                            firefly_collective_create_user_after_payment($order_id);
                        }
                    }
                    // Handle renewal payments...
                    else {
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
     * Handle successful subscription renewal payment.
     * Dedupes by invoice_id, then creates exactly one renewal order per invoice.
     */
    function firefly_collective_handle_subscription_invoice_paid($invoice) {
        global $wpdb;

        // 0) Dedupe: bail if we've already processed this Stripe invoice
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ffc_orders WHERE invoice_id = %s",
            $invoice->id
        ));
        if ($exists) {
            reliable_log("SKIPPING duplicate invoice {$invoice->id}", "WEBHOOK_DEBUG");
            return;
        }

        reliable_log("WEBHOOK_DEBUG: enter handle_subscription_invoice_paid(); invoice ID={$invoice->id}", "WEBHOOK_DEBUG");

        // 1) Expand needed nested objects
        $invoice = \Stripe\Invoice::retrieve($invoice->id, [
            'expand' => [
                'lines.data.price.product',
                'payments.data.payment',
                'charge.payment_intent',
            ]
        ]);
        reliable_log("WEBHOOK_DEBUG: re-retrieved invoice with expand; lines count=" . count($invoice->lines->data), "WEBHOOK_DEBUG");

        // 2) Skip prorations on plan changes
        if (isset($invoice->billing_reason) && $invoice->billing_reason === 'subscription_update') {
            reliable_log("WEBHOOK_DEBUG: skipping proration invoice (billing_reason=subscription_update)", "WEBHOOK_DEBUG");
            return;
        }

        // 3) Determine subscription ID
        $subscriptionId = ff_invoice_subscription_id($invoice);
        reliable_log("WEBHOOK_DEBUG: ff_invoice_subscription_id() => " . var_export($subscriptionId, true), "WEBHOOK_DEBUG");
        if (! $subscriptionId) {
            reliable_log("WEBHOOK_DEBUG: no subscription ID—aborting renewal handler", "WEBHOOK_DEBUG");
            return;
        }

        // 4) Load the very first/original order row for this subscription
        $original = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ffc_orders
            WHERE subscription_id = %s
            ORDER BY createdAt ASC
            LIMIT 1",
            $subscriptionId
        ), ARRAY_A);
        reliable_log("WEBHOOK_DEBUG: fetched original order row ID=" . ($original['id'] ?? 'null'), "WEBHOOK_DEBUG");
        if (! $original) {
            reliable_log("WEBHOOK_DEBUG: original order not found for subscription {$subscriptionId}", "WEBHOOK_DEBUG");
            return;
        }

        // 5) Find the payment_intent on this invoice
        $piId = ff_invoice_pi_id($invoice);
        reliable_log("WEBHOOK_DEBUG: ff_invoice_pi_id() => " . var_export($piId, true), "WEBHOOK_DEBUG");

        // 6) Only one renewal per PI
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ffc_orders
            WHERE payment_intent_id = %s
            AND subscription_renewal = 1
            AND transaction_type  = 'renewal'",
            $piId
        ));
        reliable_log("WEBHOOK_DEBUG: existing renewal count for PI {$piId} => {$existing}", "WEBHOOK_DEBUG");
        if ($existing > 0) {
            reliable_log("WEBHOOK_DEBUG: duplicate renewal detected—updating only period_end", "WEBHOOK_DEBUG");
            $sub = \Stripe\Subscription::retrieve($subscriptionId);
            $p   = ff_subscription_period($sub);
            if ($p['end']) {
                $wpdb->update(
                    "{$wpdb->prefix}ffc_orders",
                    ['subscription_current_period_end' => date('Y-m-d H:i:s', $p['end'])],
                    ['id'                            => $original['id']]
                );
                reliable_log("WEBHOOK_DEBUG: updated original row period_end to {$p['end']}", "WEBHOOK_DEBUG");
            }
            return;
        }

        // 7) Insert the single renewal order row
        foreach ($invoice->lines->data as $line) {
            if (! ff_is_recurring_line($line)) {
                continue;
            }
            if ($invoice->billing_reason !== 'subscription_cycle') {
                reliable_log("WEBHOOK_DEBUG: billing_reason={$invoice->billing_reason}, skipping", "WEBHOOK_DEBUG");
                continue;
            }

            $periodStart = date('Y-m-d H:i:s', $line->period->start);
            $periodEnd   = date('Y-m-d H:i:s', $line->period->end);
            $amount      = $line->amount / 100;
            $quantity    = $line->quantity;

            $new_order_id = wp_generate_uuid4();
            reliable_log("WEBHOOK_DEBUG: creating renewal order new_order_id={$new_order_id}", "WEBHOOK_DEBUG");

            $result = $wpdb->insert(
                "{$wpdb->prefix}ffc_orders",
                [
                    'orderID'                         => $new_order_id,
                    'invoice_id'                      => $invoice->id,
                    'payment_intent_id'               => $piId,
                    'userId'                          => $original['userId'],
                    'featureId'                       => $original['featureId'],
                    'optionId'                        => $original['optionId'],
                    'addonIds'                        => $original['addonIds'],
                    'priceSelected'                   => $original['priceSelected'],
                    'quantity'                        => $quantity,
                    'totalPrice'                      => $amount,
                    'subscriptionPrice'               => $amount,
                    'totalPriceDiscount'              => $original['totalPriceDiscount'],
                    'priceDiscountsInfo'              => $original['priceDiscountsInfo'],
                    'userData'                        => $original['userData'],
                    'status'                          => 'paid',
                    'transaction_type'                => 'renewal',
                    'createdAt'                       => current_time('mysql'),
                    'subscription_renewal'            => 1,
                    'subscription_period_start'       => $periodStart,
                    'subscription_current_period_end' => $periodEnd,
                    'subscription_id'                 => $subscriptionId,
                    'subscription_status'             => $invoice->status,
                ]
            );

            reliable_log("WEBHOOK_DEBUG: \$wpdb->insert() returned " . var_export($result, true), "WEBHOOK_DEBUG");
            if ($result !== false) {
                firefly_collective_orders_email($new_order_id, 'paid');
                reliable_log("WEBHOOK_DEBUG: renewal order email sent for {$new_order_id}", "WEBHOOK_DEBUG");
            }

            // update original’s period_end
            $wpdb->update(
                "{$wpdb->prefix}ffc_orders",
                ['subscription_current_period_end' => $periodEnd],
                ['id'                            => $original['id']]
            );
            reliable_log("WEBHOOK_DEBUG: updated original row period_end field", "WEBHOOK_DEBUG");

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
                return new WP_Error('payment_not_confirmed', 'Payment method not confirmed', ['status' => 400]);
            }
            
            $subscription_id   = $setup_intent->metadata->subscription_id;
            $new_option_id     = intval($setup_intent->metadata->new_option_id);
            $immediate_charge  = floatval($setup_intent->metadata->immediate_charge);
            $is_renewal        = ($setup_intent->metadata->is_renewal === 'true');
            $new_price_id      = $setup_intent->metadata->new_price_id;
            
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
            
            // Past‑due renewals branch
            if ($was_past_due && $is_renewal) {
                // Update default payment method on customer
                $stripe->customers->update($stripe_sub->customer, [
                    'invoice_settings' => ['default_payment_method' => $setup_intent->payment_method]
                ]);
                
                // Find and pay any open invoices
                $open_invoices = $stripe->invoices->all([
                    'subscription' => $subscription_id,
                    'status'       => 'open',
                    'limit'        => 10
                ]);
                
                $total_paid    = 0;
                $paid_invoices = [];
                
                foreach ($open_invoices->data as $invoice) {
                    try {
                        reliable_log("Found open invoice: {$invoice->id} for amount: " . ($invoice->amount_due / 100), "PLAN_CHANGE");
                        
                        $paid_invoice = $stripe->invoices->pay($invoice->id);
                        if ($paid_invoice->status === 'paid') {
                            $paid_invoice = $stripe->invoices->retrieve($invoice->id, [
                                'expand' => ['payment_intent', 'payments.data.payment']
                            ]);
                            $total_paid += $paid_invoice->amount_paid / 100;
                            $payment_intent_id = ff_invoice_pi_id($paid_invoice);
                            $paid_invoices[] = [
                                'invoice'            => $paid_invoice,
                                'payment_intent_id'  => $payment_intent_id
                            ];
                            reliable_log("Successfully paid invoice: {$invoice->id} with payment_intent: " . ($payment_intent_id ?? 'null'), "PLAN_CHANGE");
                        }
                    } catch (Exception $e) {
                        reliable_log("Failed to pay invoice {$invoice->id}: " . $e->getMessage(), "PLAN_CHANGE_ERROR");
                    }
                }
                
                // Only update subscription if changing plan
                if ($new_price_id && $stripe_sub->items->data[0]->price->id !== $new_price_id) {
                    $update_params = [
                        'items'              => [[
                            'id'    => $stripe_sub->items->data[0]->id,
                            'price' => $new_price_id,
                        ]],
                        'proration_behavior' => 'none',
                        'description'        => sprintf(
                            '%s - $%.2f/%s',
                            $new_option['optionName'],
                            floatval($new_option['staticPrice']),
                            $new_option['interval']
                        )
                    ];
                    $updated_sub = $stripe->subscriptions->update($subscription_id, $update_params);
                    reliable_log("Subscription plan updated. New status: " . $updated_sub->status, "PLAN_CHANGE");
                } else {
                    $updated_sub = $stripe->subscriptions->retrieve($subscription_id);
                    reliable_log("Subscription payment updated. Status: " . $updated_sub->status, "PLAN_CHANGE");
                }
                
                // Update original subscription record
                $periods = ff_subscription_period($updated_sub);
                $wpdb->update(
                    "{$wpdb->prefix}ffc_orders",
                    [
                        'optionId'                         => $new_option_id,
                        'subscriptionPrice'                => floatval($new_option['staticPrice']),
                        'subscription_status'              => $updated_sub->status,
                        'subscription_current_period_end'  => $periods['end']
                                                            ? date('Y-m-d H:i:s', $periods['end'])
                                                            : null,
                        'updatedAt'                        => current_time('mysql'),
                    ],
                    [
                        'subscription_id'       => $subscription_id,
                        'subscription_renewal'  => 0,
                    ],
                    ['%d','%s','%s','%s','%s'],
                    ['%s','%d']
                );
                
                // Create renewal transaction record
                if ($total_paid > 0 && !empty($paid_invoices[0])) {
                    $new_order_id    = wp_generate_uuid4();
                    $original_order  = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ffc_orders
                        WHERE subscription_id = %s
                        ORDER BY createdAt ASC LIMIT 1",
                        $subscription_id
                    ), ARRAY_A);
                    
                    $transaction_data = [
                        'invoice_id'                      => $paid_invoices[0]['invoice']->id,
                        'orderID'                         => $new_order_id,
                        'payment_intent_id'               => $paid_invoices[0]['payment_intent_id'],
                        'userId'                          => $original_order['userId'],
                        'featureId'                       => $original_order['featureId'],
                        'optionId'                        => $new_option_id,
                        'addonIds'                        => $original_order['addonIds'],
                        'priceSelected'                   => $original_order['priceSelected'],
                        'quantity'                        => 1,
                        'totalPrice'                      => $total_paid,
                        'totalPriceDiscount'              => 0,
                        'priceDiscountsInfo'              => json_encode([
                                                            'description' => 'Past due subscription renewal'
                                                            ]),
                        'userData'                        => $original_order['userData'],
                        'status'                          => 'paid',
                        'transaction_type'                => 'renewal',
                        'subscription_renewal'            => 1,
                        'subscription_period_start'       => $periods['start']
                                                            ? date('Y-m-d H:i:s', $periods['start'])
                                                            : null,
                        'subscription_current_period_end' => $periods['end']
                                                            ? date('Y-m-d H:i:s', $periods['end'])
                                                            : null,
                        'createdAt'                       => current_time('mysql'),
                    ];
                    
                    $wpdb->insert("{$wpdb->prefix}ffc_orders", $transaction_data);
                    reliable_log(
                        "PLAN_CHANGE: Created renewal transaction record: $new_order_id with invoice_id={$paid_invoices[0]['invoice']->id}",
                        "PLAN_CHANGE"
                    );
                    firefly_collective_orders_email($new_order_id, 'renewed');
                }
                
            } else {
                // Not past_due / proration branch
                $update_params = [
                    'items'                     => [[
                        'id'    => $stripe_sub->items->data[0]->id,
                        'price' => $new_price_id,
                    ]],
                    'proration_behavior'        => 'always_invoice',
                    'payment_behavior'          => 'allow_incomplete',
                    'default_payment_method'    => $setup_intent->payment_method,
                    'description'               => sprintf(
                        '%s - $%.2f/%s',
                        $new_option['optionName'],
                        floatval($new_option['staticPrice']),
                        $new_option['interval']
                    ),
                ];
                reliable_log("Updating subscription with params: " . json_encode($update_params), "PLAN_CHANGE");
                
                $updated_sub = $stripe->subscriptions->update($subscription_id, $update_params);
                reliable_log("Subscription updated successfully. New status: " . $updated_sub->status, "PLAN_CHANGE");
                
                // Fetch latest invoice and amount
                $latest_invoice = is_string($updated_sub->latest_invoice)
                    ? $stripe->invoices->retrieve($updated_sub->latest_invoice, [
                        'expand' => ['lines.data', 'payments.data.payment']
                    ])
                    : $updated_sub->latest_invoice;
                
                $actual_charge = ($latest_invoice && $latest_invoice->status === 'paid')
                    ? $latest_invoice->amount_paid / 100
                    : 0;
                
                // Update subscription record
                $periods = ff_subscription_period($updated_sub);
                $wpdb->update(
                    "{$wpdb->prefix}ffc_orders",
                    [
                        'optionId'                        => $new_option_id,
                        'subscriptionPrice'               => floatval($new_option['staticPrice']),
                        'subscription_status'             => $updated_sub->status,
                        'subscription_current_period_end' => $periods['end']
                                                            ? date('Y-m-d H:i:s', $periods['end'])
                                                            : null,
                        'updatedAt'                       => current_time('mysql'),
                    ],
                    [
                        'subscription_id'      => $subscription_id,
                        'subscription_renewal' => 0,
                    ],
                    ['%d','%s','%s','%s','%s'],
                    ['%s','%d']
                );
                
                // Create proration transaction record
                if ($actual_charge > 0) {
                    $new_order_id   = wp_generate_uuid4();
                    $original_order = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ffc_orders
                        WHERE subscription_id = %s
                        ORDER BY createdAt ASC LIMIT 1",
                        $subscription_id
                    ), ARRAY_A);
                    
                    $transaction_data = [
                        'invoice_id'          => $latest_invoice->id,
                        'orderID'             => $new_order_id,
                        'payment_intent_id'   => ff_invoice_pi_id($latest_invoice),
                        'userId'              => $original_order['userId'],
                        'featureId'           => $original_order['featureId'],
                        'optionId'            => $new_option_id,
                        'addonIds'            => $original_order['addonIds'],
                        'priceSelected'       => $original_order['priceSelected'],
                        'quantity'            => 1,
                        'totalPrice'          => $actual_charge,
                        'totalPriceDiscount'  => 0,
                        'priceDiscountsInfo'  => json_encode(['description' => 'Plan change proration']),
                        'userData'            => $original_order['userData'],
                        'status'              => 'paid',
                        'transaction_type'    => 'plan_change',
                        'subscription_renewal'=> 1,
                        'createdAt'           => current_time('mysql'),
                    ];
                    
                    $wpdb->insert("{$wpdb->prefix}ffc_orders", $transaction_data);
                    reliable_log("PLAN_CHANGE: Created plan change transaction record: $new_order_id with invoice_id={$latest_invoice->id}", "PLAN_CHANGE");
                    firefly_collective_orders_email($new_order_id, 'plan_change');
                }
            }
            
            reliable_log("Plan change completed successfully", "PLAN_CHANGE");
            return [
                'success'             => true,
                'message'             => $is_renewal
                                        ? 'Subscription renewed successfully!'
                                        : 'Plan changed successfully!',
                'newPlan'             => $new_option['optionName'],
                'newPrice'            => floatval($new_option['staticPrice']),
                'transactionCreated'  => true,
            ];
            
        } catch (Exception $e) {
            reliable_log('Complete plan change error: ' . $e->getMessage(), "PLAN_CHANGE_ERROR");
            return new WP_Error('stripe_error', $e->getMessage(), ['status' => 500]);
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
    function firefly_collective_get_or_create_stripe_customer($user_id, $anon_data = null) {
        firefly_collective_stripe_init();
        
        // Handle anonymous users
        if ($user_id === 0 && $anon_data) {
            // For anonymous users, create a new Stripe customer each time
            $customer_name = trim($anon_data['firstName'] . ' ' . $anon_data['lastName']) ?: 'Anonymous Customer';
            
            $customer = \Stripe\Customer::create([
                'name' => $customer_name,
                'email' => $anon_data['email'],
                'phone' => $anon_data['phone'] ?: null,
                'metadata' => [
                    'anonymous_order' => 'true',
                    'first_name' => $anon_data['firstName'],
                    'last_name' => $anon_data['lastName']
                ]
            ]);
            
            return $customer;
        }
        
        // Handle authenticated users
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
                COALESCE(o.subscriptionPrice, o.totalPrice) AS total_amount,
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
                o.subscriptionPrice,
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
        
        // Get original order details
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ffc_orders 
            WHERE subscription_id = %s 
            AND subscription_renewal = 0 
            AND transaction_type = 'initial'
            ORDER BY createdAt ASC 
            LIMIT 1",
            $subscription_id
        ));
        
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found', array('status' => 404));
        }
        
        firefly_collective_stripe_init();

        try {
            // Get subscription details with expand to ensure we have all data
            $subscription = \Stripe\Subscription::retrieve($subscription_id, [
                'expand' => ['latest_invoice', 'items.data', 'test_clock']
            ]);
            
            // Use your existing helper function to get periods safely
            $periods = ff_subscription_period($subscription);
            $period_start = $periods['start'];
            $period_end = $periods['end'];
            
            // Initialize refund amount
            $refund_amount_cents = 0;
            
            reliable_log("Refund calculation:", "REFUND_DEBUG");
            reliable_log("- Subscription ID: " . $subscription_id, "REFUND_DEBUG");
            reliable_log("- Subscription status: " . $subscription->status, "REFUND_DEBUG");
            reliable_log("- Period start: " . ($period_start ? date('Y-m-d H:i:s', $period_start) : 'null'), "REFUND_DEBUG");
            reliable_log("- Period end: " . ($period_end ? date('Y-m-d H:i:s', $period_end) : 'null'), "REFUND_DEBUG");
            
            // Check if subscription has a test clock
            $has_test_clock = !empty($subscription->test_clock);
            $current_time = time();
            
            if ($has_test_clock) {
                try {
                    // Retrieve the test clock to get the simulated time
                    $test_clock = \Stripe\TestHelpers\TestClock::retrieve($subscription->test_clock);
                    $simulated_time = $test_clock->frozen_time;
                    
                    reliable_log("- Test clock detected", "REFUND_DEBUG");
                    reliable_log("- Test clock frozen time: " . date('Y-m-d H:i:s', $simulated_time), "REFUND_DEBUG");
                    reliable_log("- System time: " . date('Y-m-d H:i:s', $current_time), "REFUND_DEBUG");
                    
                    // Use the simulated time for calculations
                    $current_time = $simulated_time;
                } catch (Exception $e) {
                    reliable_log("- Could not retrieve test clock: " . $e->getMessage(), "REFUND_DEBUG");
                }
            }
            
            reliable_log("- Time used for calculation: " . date('Y-m-d H:i:s', $current_time), "REFUND_DEBUG");
            
            // Check for 3-day grace period first
            $subscription_created = strtotime($order->createdAt);
            $days_since_creation = ($current_time - $subscription_created) / 86400;

            if ($days_since_creation <= 3) {
                // Within 3-day grace period - full refund
                $unused_fraction = 1.0;
                reliable_log(sprintf("- Within 3-day grace period (%.1f days old), full refund", $days_since_creation), "REFUND_DEBUG");
            } else if ($period_start && $period_end && $period_end > $period_start) {
                if ($current_time < $period_start) {
                    // Subscription hasn't started yet - full refund
                    $unused_fraction = 1.0;
                    reliable_log("- Subscription not started yet, full refund", "REFUND_DEBUG");
                } else if ($current_time >= $period_end) {
                    // Full period elapsed - no refund
                    $unused_fraction = 0.0;
                    reliable_log("- Full period elapsed, no refund", "REFUND_DEBUG");
                } else {
                    // Calculate based on time progression (4+ days old)
                    $total_period_seconds = $period_end - $period_start;
                    $elapsed_seconds = $current_time - $period_start;
                    $unused_seconds = $period_end - $current_time;
                    $unused_fraction = $unused_seconds / $total_period_seconds;
                    
                    reliable_log("- Total period: " . round($total_period_seconds / 86400, 1) . " days", "REFUND_DEBUG");
                    reliable_log("- Elapsed time: " . round($elapsed_seconds / 86400, 1) . " days", "REFUND_DEBUG");
                    reliable_log("- Unused time: " . round($unused_seconds / 86400, 1) . " days", "REFUND_DEBUG");
                }
            } else {
                // No valid periods, but still honor grace period
                $unused_fraction = $days_since_creation <= 3 ? 1.0 : 0.0;
                reliable_log("- No valid periods found for refund calculation", "REFUND_DEBUG");
            }

            // Calculate refund amount
            $subscription_price = floatval($order->subscriptionPrice ?: $order->totalPrice);
            $refund_amount_cents = round($subscription_price * $unused_fraction * 100);

            reliable_log(sprintf("- Subscription price: $%.2f", $subscription_price), "REFUND_DEBUG");
            reliable_log(sprintf("- Unused fraction: %.3f", $unused_fraction), "REFUND_DEBUG");
            reliable_log(sprintf("- Calculated refund: $%.2f", ($refund_amount_cents / 100)), "REFUND_DEBUG");

            // Cancel the subscription
            try {
                $canceled = $subscription->cancel();
                reliable_log("- Subscription cancelled successfully", "REFUND_DEBUG");
            } catch (Exception $e) {
                reliable_log("- Error cancelling subscription: " . $e->getMessage(), "REFUND_DEBUG");
                throw $e;
            }
            
            // For subscriptions with plan changes, we need to calculate actual consumption
            $has_plan_changes = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ffc_orders 
                WHERE subscription_id = %s 
                AND transaction_type = 'plan_change'
                AND status = 'paid'",
                $subscription_id
            ));
            
            if ($has_plan_changes > 0) {
                reliable_log("Plan changes detected - calculating precise consumption", "REFUND_DEBUG");
                
                // Get all transactions for this subscription in the current period
                $all_transactions = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_orders 
                    WHERE subscription_id = %s 
                    AND status IN ('paid', 'refunded')
                    ORDER BY createdAt ASC",
                    $subscription_id
                ), ARRAY_A);
                
                // Calculate total paid in current period
                $total_paid_cents = 0;
                $plan_changes = [];
                $initial_transaction = null;
                
                foreach ($all_transactions as $trans) {
                    if ($trans['transaction_type'] === 'initial' && $trans['subscription_renewal'] == 0) {
                        $initial_transaction = $trans;
                        $total_paid_cents += $trans['totalPrice'] * 100;
                        $plan_changes[] = [
                            'timestamp' => strtotime($trans['createdAt']),
                            'price_per_month' => floatval($trans['subscriptionPrice'] ?: $trans['totalPrice']),
                            'option_id' => $trans['optionId']
                        ];
                    } else if ($trans['transaction_type'] === 'plan_change' && $trans['status'] === 'paid') {
                        $total_paid_cents += $trans['totalPrice'] * 100;
                        // Get the new price from the updated subscription
                        $new_price = $wpdb->get_var($wpdb->prepare(
                            "SELECT subscriptionPrice FROM {$wpdb->prefix}ffc_orders 
                            WHERE subscription_id = %s 
                            AND optionId = %d
                            AND subscription_renewal = 0
                            ORDER BY updatedAt DESC LIMIT 1",
                            $subscription_id,
                            $trans['optionId']
                        ));
                        $plan_changes[] = [
                            'timestamp' => strtotime($trans['createdAt']),
                            'price_per_month' => floatval($new_price),
                            'option_id' => $trans['optionId']
                        ];
                    }
                }
                
                reliable_log("Total paid in period: $" . ($total_paid_cents / 100), "REFUND_DEBUG");
                
                // Calculate consumed value based on time spent on each plan
                $consumed_value_cents = 0;
                $period_days = ($period_end - $period_start) / 86400;
                
                for ($i = 0; $i < count($plan_changes); $i++) {
                    $plan_start = $plan_changes[$i]['timestamp'];
                    $plan_end = ($i + 1 < count($plan_changes)) ? $plan_changes[$i + 1]['timestamp'] : $current_time;
                    
                    // Don't count beyond the cancellation time
                    $plan_end = min($plan_end, $current_time);
                    
                    $days_on_plan = max(0, ($plan_end - $plan_start) / 86400);
                    $daily_rate = $plan_changes[$i]['price_per_month'] / $period_days;
                    $plan_cost = $daily_rate * $days_on_plan;
                    
                    $consumed_value_cents += $plan_cost * 100;
                    
                    reliable_log(sprintf(
                        "Plan %d: %s to %s (%.1f days) at $%.2f/month = $%.2f",
                        $i + 1,
                        date('Y-m-d', $plan_start),
                        date('Y-m-d', $plan_end),
                        $days_on_plan,
                        $plan_changes[$i]['price_per_month'],
                        $plan_cost
                    ), "REFUND_DEBUG");
                }
                
                reliable_log("Total consumed value: $" . ($consumed_value_cents / 100), "REFUND_DEBUG");
                
                // Override the refund amount with the precise calculation
                $refund_amount_cents = max(0, $total_paid_cents - $consumed_value_cents);
                reliable_log("Calculated precise refund: $" . ($refund_amount_cents / 100), "REFUND_DEBUG");
                
                // Get transactions to process refunds
                $cycle_transactions = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_orders 
                    WHERE subscription_id = %s 
                    AND status = 'paid'
                    AND transaction_type IN ('initial', 'plan_change')
                    ORDER BY createdAt DESC",
                    $subscription_id
                ));
                
            } else {
                // Original logic for subscriptions without plan changes
                $renewal_transactions = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_orders 
                    WHERE subscription_id = %s 
                    AND subscription_renewal = 1 
                    AND status = 'paid'
                    ORDER BY createdAt DESC",
                    $subscription_id
                ));
                
                if (!empty($renewal_transactions)) {
                    $cycle_transactions = $renewal_transactions;
                    reliable_log("Using renewal period transactions", "REFUND_DEBUG");
                } else {
                    $period_start_db = $order->subscription_period_start;
                    $period_end_db = $order->subscription_current_period_end;
                    
                    $cycle_transactions = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ffc_orders 
                        WHERE userId = %d AND featureId = %d AND status = 'paid'
                        AND (
                            (subscription_id = %s AND subscription_renewal = 0) OR
                            (createdAt >= %s AND createdAt <= %s)
                        )
                        ORDER BY createdAt DESC",
                        $order->userId,
                        $order->featureId,
                        $order->subscription_id,
                        $period_start_db,
                        $period_end_db
                    ));
                    reliable_log("Using original period transactions", "REFUND_DEBUG");
                }
            }
            
            // Process refunds if we have credit to give
            $total_refunded = 0;
            if ($refund_amount_cents > 0) {
                foreach ($cycle_transactions as $transaction) {
                    if ($total_refunded >= $refund_amount_cents) break;
                    
                    $payment_intent_id = $transaction->payment_intent_id;
                    
                    // Get payment intent from invoice if needed
                    if (!$payment_intent_id && !empty($transaction->invoice_id)) {
                        try {
                            $invoice = \Stripe\Invoice::retrieve($transaction->invoice_id, [
                                'expand' => ['payments.data.payment']
                            ]);
                            $payment_intent_id = ff_invoice_pi_id($invoice);
                        } catch (Exception $e) {
                            continue;
                        }
                    }
                    
                    if ($payment_intent_id) {
                        try {
                            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
                            $remaining_credit = $refund_amount_cents - $total_refunded;
                            $refund_this_transaction = min($payment_intent->amount, $remaining_credit);
                            
                            if ($refund_this_transaction >= 50) { // Minimum 50 cents
                                reliable_log("Creating refund: PI=$payment_intent_id, amount=$refund_this_transaction cents", "REFUND_DEBUG");
                                
                                try {
                                    $refund = \Stripe\Refund::create([
                                        'payment_intent' => $payment_intent_id,
                                        'amount' => $refund_this_transaction,
                                        'reason' => 'requested_by_customer'
                                    ]);
                                    
                                    // Check if refund was successful
                                    if ($refund->status === 'succeeded') {
                                        $total_refunded += $refund_this_transaction;
                                        reliable_log("✅ Stripe refund succeeded: {$refund->id} for {$refund_this_transaction} cents from PI: $payment_intent_id", "REFUND_DEBUG");
                                        
                                        // Update with refund amount
                                        $wpdb->update(
                                            $wpdb->prefix . 'ffc_orders',
                                            array(
                                                'status' => 'refunded',
                                                'refundAmount' => $refund_this_transaction / 100 // Convert cents to dollars
                                            ),
                                            array('id' => $transaction->id),
                                            array('%s', '%f'),
                                            array('%d')
                                        );
                                    } else {
                                        reliable_log("❌ Stripe refund failed with status: {$refund->status} for PI: $payment_intent_id", "REFUND_DEBUG");
                                    }
                                    
                                } catch (Exception $e) {
                                    reliable_log("❌ Stripe refund error for PI $payment_intent_id: " . $e->getMessage(), "REFUND_DEBUG");
                                }
                            }
                        } catch (Exception $e) {
                            reliable_log("Refund failed for PI $payment_intent_id: " . $e->getMessage(), "REFUND_DEBUG");
                        }
                    }
                }
            }
            
            // Update subscription as cancelled
            $wpdb->update(
                $wpdb->prefix . 'ffc_orders',
                array(
                    'subscription_status' => 'cancelled',
                    'subscription_cancelled_at' => current_time('mysql')
                ),
                array('subscription_id' => $subscription_id),
                array('%s','%s'),
                array('%s')
            );
            
            $refund_percentage = $total_refunded > 0 ? ($total_refunded / ($order->totalPrice * 100)) * 100 : 0;
            $refund_amount_dollars = $total_refunded / 100;
            
            if ($refund_percentage >= 99) {
                $message = "Subscription cancelled successfully and fully refunded ($" . number_format($refund_amount_dollars, 2) . ")";
            } else if ($refund_percentage > 0) {
                $message = "Subscription cancelled successfully and partially refunded ($" . number_format($refund_amount_dollars, 2) . " - " . round($refund_percentage, 1) . "%)";
            } else {
                $message = "Subscription cancelled successfully (no refund - billing period completed)";
            }

            return array(
                'success' => true,
                'message' => $message,
                'refunded' => $total_refunded > 0,
                'refund_percentage' => round($refund_percentage, 1),
                'refund_amount' => $refund_amount_dollars
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

    function firefly_collective_create_user_after_payment($order_id) {
        global $wpdb;
        
        // Look for ANY order item in this order that has account creation data
        $order_items = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, f.recurring FROM {$wpdb->prefix}ffc_orders o
            JOIN {$wpdb->prefix}ffc_features f ON o.featureId = f.id
            WHERE o.orderID = %s AND o.userId = 0
            ORDER BY f.recurring DESC",
            $order_id
        ), ARRAY_A);
        
        if (!$order_items) {
            error_log("DEBUG: No anonymous order items found for order: {$order_id}");
            return;
        }
        
        // Find the first recurring item with account creation data
        $account_item = null;
        foreach ($order_items as $item) {
            
            if ($item['recurring'] == 1) {
                $user_data = json_decode($item['userData'], true);
                
                if ($user_data && isset($user_data['createAccount']) && $user_data['createAccount']) {
                    $account_item = $item;
                    break;
                }
            }
        }
        
        if (!$account_item) {
            error_log("DEBUG: No recurring items with account creation data found for order: {$order_id}");
            return;
        }
        
        $user_data = json_decode($account_item['userData'], true);
        
        // Validate required fields
        if (!$user_data['username'] || !$user_data['password']) {
            error_log('ERROR: Cannot create user - missing username or password');
            return;
        }
        
        // Check if user already exists
        if (username_exists($user_data['username'])) {
            error_log('ERROR: Username already exists: ' . $user_data['username']);
            return;
        }
        
        if (email_exists($account_item['anonUserEmail'])) {
            error_log('ERROR: Email already exists: ' . $account_item['anonUserEmail']);
            return;
        }
        
        // Create WordPress user
        $user_id = wp_create_user(
            $user_data['username'],
            $user_data['password'],
            $account_item['anonUserEmail']
        );
        
        if (is_wp_error($user_id)) {
            error_log('ERROR: Failed to create user: ' . $user_id->get_error_message());
            return;
        }
        
        // Update user meta
        wp_update_user([
            'ID' => $user_id,
            'first_name' => $account_item['anonUserFirstName'],
            'last_name' => $account_item['anonUserLastName']
        ]);
        
        // Link ALL orders with this email to the new user
        $updated_rows = $wpdb->update(
            $wpdb->prefix . 'ffc_orders',
            ['userId' => $user_id],
            [
                'userId' => 0,
                'anonUserEmail' => $account_item['anonUserEmail']
            ],
            ['%d'],
            ['%d', '%s']
        );
        
        // Update Stripe subscription metadata with the new user ID
        if (!empty($account_item['subscription_id'])) {
            try {
                firefly_collective_stripe_init();
                
                \Stripe\Subscription::update($account_item['subscription_id'], [
                    'metadata' => [
                        'wordpress_user_id' => $user_id,
                        'order_id' => $order_id,
                        'username' => $user_data['username']
                    ]
                ]);
                
                error_log("SUCCESS: Updated Stripe subscription {$account_item['subscription_id']} metadata with user ID {$user_id}");
            } catch (Exception $e) {
                error_log("ERROR: Failed to update Stripe subscription metadata: " . $e->getMessage());
            }
        }
        
        error_log("SUCCESS: Created user ID {$user_id} and linked {$updated_rows} orders for order {$order_id}");
    }

    function firefly_collective_link_anonymous_orders($email, $user_id) {
        global $wpdb;
        
        // Update all anonymous orders with this email to the new user ID
        $wpdb->update(
            $wpdb->prefix . 'ffc_orders',
            ['userId' => $user_id],
            [
                'userId' => 0,
                'anonUserEmail' => $email
            ],
            ['%d'],
            ['%d', '%s']
        );
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