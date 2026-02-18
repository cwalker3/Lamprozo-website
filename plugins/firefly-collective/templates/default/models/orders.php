<?php

    // plugin/models/orders.php

    function firefly_collective_orders_dashboard() {
        // Get the current template directory
        $template_name = FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE;
        // Go up from models/ -> default/ -> templates/ -> plugin_root/
        $plugin_base = dirname(dirname(dirname(dirname(__FILE__)))) . '/';

        // Construct the path to the view file in the template directory
        $view_path = $plugin_base . 'templates/default/views/orders.php';

        if (file_exists($view_path)) {
            require_once $view_path;
        } else {
            wp_die('The orders view file could not be found at: ' . $view_path, 'File Not Found', array('response' => 404));
        }
    }

    function firefly_collective_subscriptions_dashboard() {
        // Subscriptions view stays in core backend directory (not template-specific)
        // Go up from models/ -> default/ -> templates/ -> plugin_root/
        $plugin_base = dirname(dirname(dirname(dirname(__FILE__)))) . '/';
        $view_path = $plugin_base . 'includes/apps/backend/views/subscriptions.php';

        if (file_exists($view_path)) {
            require_once $view_path;
        } else {
            wp_die('The subscriptions view file could not be found at: ' . $view_path, 'File Not Found', array('response' => 404));
        }
    }

    function enqueue_orders_styles_and_scripts($hook) {
        if ($hook !== 'toplevel_page_orders' && $hook !== 'toplevel_page_subscriptions') {
            return;
        }

        $plugin_path = plugin_dir_url(dirname(dirname(__FILE__)));
        $template_name = FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE;
        $unique_id       = uniqid();
        $hookName = '';
        $nonce   = wp_create_nonce('wp_rest');
        $api_url = get_rest_url(null, 'custom-api/v1/');
        $theme_path = get_template_directory_uri();
        $active_template = firefly_collective_get_active_template();
        $template_path = $theme_path . '/templates/' . $active_template;
        $auth_id = $_COOKIE['auth_id'];

        // Admin only
        global $currentUserIdAdmin;
        $currentUserIdAdmin = current_user_can('manage_options');

        // Main JS
        wp_enqueue_script('main-js', $template_path . '/assets/js/_core_main.js', array(), $unique_id, true);
        wp_localize_script('main-js', 'myApi', array(
            'themePath' => $theme_path
        ));

        // Enqueue Vue
        wp_enqueue_script('vue-js', VUE_REMOTE_CORE, array(), null, true);

        switch ($hook) {
            // Orders admin
            case "toplevel_page_orders":

                // Enqueue CSS & JS
                wp_enqueue_style('orders-css', $plugin_path . $template_name . '/assets/css/orders.css', array(), $unique_id);
                wp_enqueue_script('orders-js', $plugin_path . $template_name . '/assets/js/orders.js', array(), $unique_id, true);

                $obj = new stdClass();

                // Localize into JS
                $nonce   = wp_create_nonce('wp_rest');
                $api_url = get_rest_url(null, 'custom-api/v1/');
                
                // Get online payments setting
                $online_payments_enabled = get_option('firefly_online_payments_enabled', '1');

                wp_localize_script('orders-js', 'ordersData', array(
                    'data'   => $obj,
                    'nonce'  => $nonce,
                    'apiUrl' => $api_url,
                    'auth_id'=> $auth_id,
                    'currentUserIsAdmin' => $currentUserIdAdmin,
                    'currentUserId'      => get_current_user_id(),
                    'isPWA' => 0,
                    'onlinePaymentsEnabled' => $online_payments_enabled
                ));
                break;

        // Subscriptions admin
        case "toplevel_page_subscriptions":

            // Enqueue CSS & JS
            wp_enqueue_style('subscriptions-css', $plugin_path . $template_name . '/assets/css/subscriptions.css', array(), $unique_id);
            wp_enqueue_script('main-js', $theme_path . '/assets/js/main.js', array(), $unique_id, true);
            wp_enqueue_script('subscriptions-js', $plugin_path . $template_name . '/assets/js/subscriptions.js', array(), $unique_id, true);

            // Get Stripe configuration
            $publishable_key = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : get_option('firefly_stripe_publishable_key', '');

            // Enqueue Stripe
            wp_enqueue_script('stripe-js', STRIPE_REMOTE_JS, array(), null, true);

            // Localize into JS
            $nonce   = wp_create_nonce('wp_rest');
            $api_url = get_rest_url(null, 'custom-api/v1/');
            wp_localize_script('subscriptions-js', 'subscriptionsData', array(
                'nonce'              => $nonce,
                'apiUrl'             => $api_url,
                'auth_id'            => $auth_id,
                'currentUserIsAdmin' => $currentUserIdAdmin,
                'currentUserId'      => get_current_user_id(),
                'isPWA'              => 0,
                'stripeKey'          => $publishable_key
            ));
            break;
        }
    }
    add_action('admin_enqueue_scripts', 'enqueue_orders_styles_and_scripts');

    function firefly_collective_add_orders_link() {
        add_menu_page(
            'Orders',
            'Orders',
            'manage_options',
            'orders',
            'firefly_collective_orders_dashboard',
            'dashicons-cart'
        );
    }
    add_action('admin_menu', 'firefly_collective_add_orders_link');

    function firefly_collective_add_subscriptions_link() {
        add_menu_page(
            'Subscriptions',
            'Subscriptions',
            'manage_options',
            'subscriptions',
            'firefly_collective_subscriptions_dashboard',
            'dashicons-tickets-alt'
        );
    }
    add_action('admin_menu', 'firefly_collective_add_subscriptions_link');

    /**
     * Create or update the ffc_orders table to include invoice_id and its index.
     */
    function create_ffc_orders_table_if_not_exist() {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_orders (
            id                                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            orderID                             VARCHAR(36)        DEFAULT NULL,
            invoice_id                          VARCHAR(255)       NULL,
            payment_intent_id                   VARCHAR(255)       NULL,
            userId                              INT UNSIGNED       NOT NULL,
            featureId                           INT UNSIGNED       NOT NULL,
            optionId                            INT UNSIGNED       NOT NULL,
            addonIds                            JSON               DEFAULT NULL,
            priceSelected                       INT                DEFAULT NULL,
            quantity                            INT                DEFAULT NULL,
            totalPrice                          DECIMAL(10,2)      DEFAULT NULL,
            subscriptionPrice                   DECIMAL(10,2)      DEFAULT NULL,
            totalPriceDiscount                  DECIMAL(10,2)      DEFAULT NULL,
            priceDiscountsInfo                  JSON               DEFAULT NULL,
            refundAmount                        DECIMAL(10,2)      DEFAULT 0.00,
            userData                            JSON               NOT NULL,
            campaignToken                       VARCHAR(50)        DEFAULT NULL,
            anonUserFirstName                   VARCHAR(255)       DEFAULT NULL,
            anonUserLastName                    VARCHAR(255)       DEFAULT NULL,
            anonUserEmail                       VARCHAR(255)       DEFAULT NULL,
            anonUserPhone                       VARCHAR(255)       DEFAULT NULL,
            status                              VARCHAR(50)        NOT NULL DEFAULT 'pending',
            transaction_type                    VARCHAR(50)        DEFAULT 'initial',
            createdAt                           TIMESTAMP          DEFAULT CURRENT_TIMESTAMP,
            updatedAt                           TIMESTAMP          DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            subscription_id                     VARCHAR(255)       NULL,
            subscription_status                 VARCHAR(50)        NULL,
            subscription_renewal                TINYINT(1)         DEFAULT 0,
            subscription_period_start           DATETIME           NULL,
            subscription_current_period_end     DATETIME           NULL,
            subscription_cancelled_at           DATETIME           NULL,
            
            PRIMARY KEY  (id),
            KEY          idx_order              (orderID),
            KEY          idx_user               (userId),
            KEY          idx_feature            (featureId),
            KEY          idx_option             (optionId),
            KEY          idx_status             (status),
            KEY          idx_created            (createdAt),
            KEY          idx_subscription_id    (subscription_id)
        ) {$collate};";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
    add_action( 'plugins_loaded', 'create_ffc_orders_table_if_not_exist' );

    function firefly_collective_place_order($request) {
        global $wpdb;
        
        $data = $request->get_json_params();
        
        $is_batch = isset($data['items']) && is_array($data['items']);
        $order_items = $is_batch ? $data['items'] : [$data];
        
        // Determine if this is an authenticated user or anonymous campaign order
        $user_id = 0;
        $anon_data = [];
        
        if (!empty($_COOKIE['auth_id'])) {
            // Authenticated user
            if (!is_user_logged_in()) {
                return new WP_Error('not_logged_in', 'You must be logged in to place an order.', array('status' => 401));
            }
            $user_id = get_current_user_id();
        } elseif (!empty($_COOKIE['campaign_token']) && !empty($data['anonUser'])) {
            // Anonymous campaign order
            $anon_data = [
                'firstName' => sanitize_text_field($data['anonUser']['firstName'] ?? ''),
                'lastName'  => sanitize_text_field($data['anonUser']['lastName'] ?? ''),
                'email'     => sanitize_email($data['anonUser']['email'] ?? ''),
                'phone'     => sanitize_text_field($data['anonUser']['phone'] ?? '')
            ];
            
            // Add account creation data if present
            if (isset($data['anonUser']['createAccount'])) {
                $anon_data['createAccount'] = $data['anonUser']['createAccount'];
                $anon_data['signupMethod'] = $data['anonUser']['signupMethod'] ?? 'username';
                $anon_data['username'] = sanitize_text_field($data['anonUser']['username'] ?? '');
                $anon_data['password'] = $data['anonUser']['password'] ?? ''; // Don't sanitize password
            }
            
            // Validate required email
            if (empty($anon_data['email']) || !is_email($anon_data['email'])) {
                return new WP_Error('invalid_email', 'Valid email address is required.', array('status' => 400));
            }
            
            $user_id = 0; // Anonymous user
        } else {
            return new WP_Error('unauthorized', 'Authentication required.', array('status' => 401));
        }
        
        $order_id = isset($data['orderID']) ? sanitize_text_field($data['orderID']) : wp_generate_uuid4();
        
        $inserted_records = [];
        $total_order_value = 0;
        
        foreach ($order_items as $item) {
            $feature_id = intval($item['featureId']);
            $option_id = intval($item['optionId']);
            $addon_ids = isset($item['addonIds']) ? $item['addonIds'] : [];
            $user_data = isset($item['userData']) ? $item['userData'] : [];
            $price_option_index = isset($item['priceOptionIndex']) ? intval($item['priceOptionIndex']) : 0;
            $quantity = isset($item['quantity']) ? intval($item['quantity']) : 1;
            
            // Add account creation data for recurring features
            if ($user_id === 0 && !empty($anon_data) && isset($anon_data['createAccount']) && $anon_data['createAccount']) {
                
                // Get feature info to check if it's recurring
                $feature_info = $wpdb->get_row($wpdb->prepare(
                    "SELECT recurring FROM {$wpdb->prefix}ffc_features WHERE id = %d",
                    $feature_id
                ));
                
                // If this is a recurring feature, store the account creation data
                if ($feature_info && $feature_info->recurring == 1) {
                    $user_data['createAccount'] = true;
                    $user_data['signupMethod'] = $anon_data['signupMethod'];
                    $user_data['username'] = $anon_data['username'];
                    $user_data['password'] = $anon_data['password'];
                } else {
                    error_log("DEBUG: Feature {$feature_id} is not recurring, skipping account data");
                }
            }
            
            $price_data = calculate_server_price($feature_id, $option_id, $addon_ids, $price_option_index, $quantity);
            
            // Check if online payments are enabled
            $online_payments_enabled = get_option('firefly_online_payments_enabled', '1');

            // If online payments are disabled, mark orders as completed by default
            $initial_status = ($online_payments_enabled === '1') ? 'pending' : 'completed';

            $insert_data = array(
                'orderID'            => $order_id,
                'userId'             => $user_id,
                'featureId'          => $feature_id,
                'optionId'           => $option_id,
                'addonIds'           => json_encode($addon_ids),
                'priceSelected'      => $price_option_index,
                'quantity'           => $quantity,
                'totalPrice'         => $price_data['totalPrice'],
                'totalPriceDiscount' => $price_data['totalPriceDiscount'],
                'priceDiscountsInfo' => json_encode($price_data['priceDiscountsInfo']),
                'userData'           => json_encode($user_data),
                'status'             => $initial_status,
                'createdAt'          => current_time('mysql')
            );
            
            // Add anonymous user contact data if present
            if ($user_id === 0 && !empty($anon_data)) {
                $insert_data['anonUserFirstName'] = $anon_data['firstName'];
                $insert_data['anonUserLastName'] = $anon_data['lastName'];
                $insert_data['anonUserEmail'] = $anon_data['email'];
                $insert_data['anonUserPhone'] = $anon_data['phone'];
            }

            // Add campaign token if exists
            if ($_COOKIE['campaign_token']) $insert_data['campaignToken'] = $_COOKIE['campaign_token'];
            
            $result = $wpdb->insert($wpdb->prefix . 'ffc_orders', $insert_data);
            
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to save order item: ' . $wpdb->last_error, array('status' => 500));
            }
            
            $inserted_records[] = [
                'recordId'        => $wpdb->insert_id,
                'featureId'       => $feature_id,
                'calculatedPrice' => $price_data['totalPrice'],
                'discountAmount'  => $price_data['totalPriceDiscount'],
                'discountInfo'    => $price_data['priceDiscountsInfo']
            ];
            
            $total_order_value += $price_data['totalPrice'];
        }

        // If online payments are disabled, send order email immediately
        if ($online_payments_enabled !== '1') {
            firefly_collective_orders_email($order_id, 'completed');
        }

        return array(
            'success'         => true,
            'orderID'         => $order_id,
            'records'         => $inserted_records,
            'totalOrderValue' => $total_order_value,
            'paymentDisabled' => ($online_payments_enabled !== '1')
        );
    }

    function firefly_collective_get_orders($request) {
        global $wpdb;
        if ( ! is_user_logged_in() ) {
            return new WP_Error('not_logged_in', 'You must be logged in to view orders.', array('status' => 401));
        }
        
        $page = $request->get_param('page') ? intval($request->get_param('page')) : 1;
        $per_page = $request->get_param('per_page') ? intval($request->get_param('per_page')) : 10;
        $status = $request->get_param('status');
        $date_from = $request->get_param('date_from');
        $date_to = $request->get_param('date_to');
        $search = $request->get_param('search');
        $order_id = $request->get_param('order_id');
        $sort_field = $request->get_param('sort_field') ? sanitize_text_field($request->get_param('sort_field')) : 'createdAt';
        $sort_direction = $request->get_param('sort_direction') ? sanitize_text_field($request->get_param('sort_direction')) : 'desc';
        
        $where_clauses = [];
        $query_params = [];
        
        if ( ! current_user_can('manage_options') ) {
            $where_clauses[] = 'userId = %d';
            $query_params[] = get_current_user_id();
        }
        
        if ($status) {
            $where_clauses[] = 'status = %s';
            $query_params[] = $status;
        }
        
        if ($date_from) {
            $where_clauses[] = 'createdAt >= %s';
            $query_params[] = $date_from . ' 00:00:00';
        }
        
        if ($date_to) {
            $where_clauses[] = 'createdAt <= %s';
            $query_params[] = $date_to . ' 23:59:59';
        }
        
        if ($order_id) {
            $where_clauses[] = 'orderID LIKE %s';
            $query_params[] = '%' . $wpdb->esc_like($order_id) . '%';
        }
        
        if ($search) {
            $user_query = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} 
                WHERE display_name LIKE %s OR user_login LIKE %s OR user_email LIKE %s",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
            
            $matching_user_ids = $wpdb->get_col($user_query);
            
            $search_clauses = array('orderID LIKE %s', 'userData LIKE %s');
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
            
            if (!empty($matching_user_ids)) {
                $user_ids_string = implode(',', array_map('intval', $matching_user_ids));
                $search_clauses[] = "userId IN ($user_ids_string)";
            }
            
            $where_clauses[] = '(' . implode(' OR ', $search_clauses) . ')';
        }
        
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        $allowed_sort_fields = array(
            'orderID', 'userId', 'featureId', 'optionId', 
            'totalPrice', 'quantity', 'status', 'createdAt'
        );
        
        if (!in_array($sort_field, $allowed_sort_fields)) {
            $sort_field = 'createdAt';
        }
        
        $sort_direction = strtoupper($sort_direction) === 'ASC' ? 'ASC' : 'DESC';
        
        $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}ffc_orders $where_sql";
        
        if (!empty($query_params)) {
            $count_query = $wpdb->prepare($count_query, $query_params);
        }
        
        $total_items = $wpdb->get_var($count_query);
        
        $offset = ($page - 1) * $per_page;
        
        $orders_query = "
            SELECT *, refundAmount FROM {$wpdb->prefix}ffc_orders
            $where_sql
            ORDER BY $sort_field $sort_direction
            LIMIT %d OFFSET %d
        ";
        
        $query_params[] = $per_page;
        $query_params[] = $offset;
        
        $orders = $wpdb->get_results(
            $wpdb->prepare($orders_query, $query_params),
            ARRAY_A
        );
        
        return array(
            'success'     => true,
            'orders'      => $orders,
            'total'       => intval($total_items),
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        );
    }

    function firefly_collective_refund_order($request) {
        global $wpdb;
        if ( ! is_user_logged_in() ) {
            return array(
                'success' => false,
                'message' => 'You must be logged in to refund an order.'
            );
        }
        
        $order_id = sanitize_text_field($request->get_param('orderID'));
        
        if (empty($order_id)) {
            return array(
                'success' => false,
                'message' => 'Order ID is required'
            );
        }
        
        $order_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ffc_orders WHERE orderID = %s",
                $order_id
            ),
            ARRAY_A
        );
        
        if (empty($order_data)) {
            return array(
                'success' => false,
                'message' => 'Order not found'
            );
        }
        
        $first_item = $order_data[0];
        $order_user_id = intval($first_item['userId']);
        $current_user_id = get_current_user_id();
        
        if (!current_user_can('manage_options') && $order_user_id !== $current_user_id) {
            return array(
                'success' => false,
                'message' => 'You do not have permission to refund this order'
            );
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ffc_orders',
            array('status' => 'refunded'),
            array('orderID' => $order_id),
            array('%s'),
            array('%s')
        );
        
        if ($result === false) {
            return array(
                'success' => false,
                'message' => 'Failed to refund order: ' . $wpdb->last_error
            );
        }
        
        firefly_collective_orders_email($order_id, 'refunded');
        
        return array(
            'success' => true,
            'message' => 'Order refunded successfully',
            'refunded' => $result
        );
    }

    /**
     * Delete an order by ID
     */
    function firefly_collective_delete_order($request) {
        global $wpdb;
        
        $order_id = sanitize_text_field($request->get_param('orderID'));
        
        if (empty($order_id)) {
            return [
                'success' => false,
                'message' => 'Order ID is required'
            ];
        }
        
        // Fetch order data before deletion
        $order_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ffc_orders WHERE orderID = %s",
                $order_id
            ),
            ARRAY_A
        );
        
        // Store the order data before deletion
        $order_data_copy = $order_data;
        
        $result = $wpdb->delete(
            $wpdb->prefix . 'ffc_orders',
            ['orderID' => $order_id],
            ['%s']
        );
        
        if ($result === false) {
            return [
                'success' => false,
                'message' => 'Failed to delete order: ' . $wpdb->last_error
            ];
        }
        
        // Send email with the stored order data
        firefly_collective_orders_email($order_id, 'deleted', $order_data_copy);

        return [
            'success' => true,
            'message' => 'Order deleted successfully',
            'deleted' => $result // Number of rows affected
        ];
    }

    /**
     * Delete multiple orders (bulk delete)
     */
    function firefly_collective_delete_orders_bulk($request) {
        global $wpdb;
        
        $order_ids = $request->get_param('orderIDs');
        
        if (empty($order_ids) || !is_array($order_ids)) {
            return [
                'success' => false,
                'message' => 'Order IDs are required'
            ];
        }
        
        // Sanitize order IDs
        $order_ids = array_map('sanitize_text_field', $order_ids);
        
        // Fetch all order data before deletion
        $placeholders = implode(',', array_fill(0, count($order_ids), '%s'));
        $order_data_by_id = [];
        
        $orders_query = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ffc_orders WHERE orderID IN ($placeholders)",
            $order_ids
        );
        
        $all_order_data = $wpdb->get_results($orders_query, ARRAY_A);
        
        // Group order data by orderID
        foreach ($all_order_data as $order_item) {
            $order_id = $order_item['orderID'];
            if (!isset($order_data_by_id[$order_id])) {
                $order_data_by_id[$order_id] = [];
            }
            $order_data_by_id[$order_id][] = $order_item;
        }
        
        // Delete the orders
        $delete_query = $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ffc_orders WHERE orderID IN ($placeholders)",
            $order_ids
        );
        
        $result = $wpdb->query($delete_query);
        
        if ($result === false) {
            return [
                'success' => false,
                'message' => 'Failed to delete orders: ' . $wpdb->last_error
            ];
        }
        
        // Send emails for each deleted order
        foreach ($order_data_by_id as $order_id => $order_data) {
            firefly_collective_orders_email($order_id, 'deleted', $order_data);
        }
        
        return [
            'success' => true,
            'message' => 'Orders deleted successfully',
            'deleted' => $result // Number of rows affected
        ];
    }

    function firefly_collective_update_order_status($request) {
        global $wpdb;
        if ( ! is_user_logged_in() ) {
            return array(
                'success' => false,
                'message' => 'You must be logged in to update order status.'
            );
        }
        
        $order_id = sanitize_text_field($request->get_param('orderID'));
        $status   = sanitize_text_field($request->get_param('status'));
        
        if (empty($order_id)) {
            return array(
                'success' => false,
                'message' => 'Order ID is required'
            );
        }
        
        if (empty($status)) {
            return array(
                'success' => false,
                'message' => 'Status is required'
            );
        }
        
        $allowed_statuses = array('pending', 'completed', 'cancelled', 'refunded');
        if (!in_array($status, $allowed_statuses)) {
            return array(
                'success' => false,
                'message' => 'Invalid status value'
            );
        }
        
        $order_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ffc_orders WHERE orderID = %s",
                $order_id
            ),
            ARRAY_A
        );
        
        if (!$order_data) {
            return array(
                'success' => false,
                'message' => 'Order not found'
            );
        }
        
        $order_user_id = intval($order_data['userId']);
        $current_user_id = get_current_user_id();
        if (!current_user_can('manage_options') && $order_user_id !== $current_user_id) {
            return array(
                'success' => false,
                'message' => 'You do not have permission to update this order status'
            );
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ffc_orders',
            array('status' => $status),
            array('orderID' => $order_id),
            array('%s'),
            array('%s')
        );
        
        if ($result === false) {
            return array(
                'success' => false,
                'message' => 'Failed to update order status: ' . $wpdb->last_error
            );
        }
        
        firefly_collective_orders_email($order_id, $status);
        
        return array(
            'success' => true,
            'message' => 'Order status updated successfully',
            'updated' => $result
        );
    }

    /**
     * Check if a user has an active subscription based on database data
     */
    function firefly_collective_check_subscription_status($request) {
        global $wpdb;
        
        // Get user ID from request or current user
        $user_id = $request->get_param('user_id');
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return new WP_Error('not_logged_in', 'User not found', array('status' => 401));
        }
        
        // Check permissions - users can only check their own status unless admin
        if (!current_user_can('manage_options') && $user_id != get_current_user_id()) {
            return new WP_Error('unauthorized', 'You can only check your own subscription status', array('status' => 403));
        }
        
        // Query for active subscriptions
        $active_subscription = $wpdb->get_row($wpdb->prepare("
            SELECT 
                o.*,
                f.featureName,
                opt.optionName,
                opt.interval
            FROM {$wpdb->prefix}ffc_orders o
            JOIN {$wpdb->prefix}ffc_features f ON o.featureId = f.id
            JOIN {$wpdb->prefix}ffc_options opt ON o.optionId = opt.id
            WHERE o.userId = %d
                AND o.subscription_id IS NOT NULL
                AND o.subscription_status IN ('active', 'trialing', 'past_due')
                AND o.subscription_current_period_end > NOW()
                AND o.subscription_cancelled_at IS NULL
                AND f.recurring = 1
            ORDER BY o.subscription_current_period_end DESC
            LIMIT 1
        ", $user_id), ARRAY_A);
        
        if ($active_subscription) {
            // Calculate days remaining
            $end_date = new DateTime($active_subscription['subscription_current_period_end']);
            $now = new DateTime();
            $days_remaining = $now->diff($end_date)->days;
            
            return array(
                'success' => true,
                'has_active_subscription' => true,
                'status' => 'paid',
                'subscription_details' => array(
                    'subscription_id' => $active_subscription['subscription_id'],
                    'status' => $active_subscription['subscription_status'],
                    'feature' => $active_subscription['featureName'],
                    'plan' => $active_subscription['optionName'],
                    'option_id' => intval($active_subscription['optionId']),
                    'interval' => $active_subscription['interval'],
                    'current_period_end' => $active_subscription['subscription_current_period_end'],
                    'days_remaining' => $days_remaining,
                    'amount' => floatval($active_subscription['totalPrice'])
                )
            );
        }
        
        // Check if user had a subscription that expired or was cancelled
        $past_subscription = $wpdb->get_row($wpdb->prepare("
            SELECT 
                subscription_cancelled_at,
                subscription_current_period_end,
                subscription_status
            FROM {$wpdb->prefix}ffc_orders
            WHERE userId = %d
                AND subscription_id IS NOT NULL
            ORDER BY createdAt DESC
            LIMIT 1
        ", $user_id), ARRAY_A);
        
        $message = 'No active subscription found';
        if ($past_subscription) {
            if ($past_subscription['subscription_cancelled_at']) {
                $message = 'Subscription was cancelled on ' . date('F j, Y', strtotime($past_subscription['subscription_cancelled_at']));
            } elseif ($past_subscription['subscription_current_period_end'] < current_time('mysql')) {
                $message = 'Subscription expired on ' . date('F j, Y', strtotime($past_subscription['subscription_current_period_end']));
            }
        }
        
        return array(
            'success' => true,
            'has_active_subscription' => false,
            'status' => 'not_paid',
            'message' => $message
        );
    }

    /**
     * Helper function to check subscription status for internal use
     * Returns boolean true if user has active subscription, false otherwise
     */
    function firefly_check_subscription_status($user_id) {
        global $wpdb;
        
        if (!$user_id) {
            return false;
        }
        
        $active_subscription = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}ffc_orders o
            JOIN {$wpdb->prefix}ffc_features f ON o.featureId = f.id
            WHERE o.userId = %d
                AND o.subscription_id IS NOT NULL
                AND o.subscription_status IN ('active', 'trialing', 'past_due')
                AND o.subscription_current_period_end > NOW()
                AND o.subscription_cancelled_at IS NULL
                AND f.recurring = 1
        ", $user_id));
        
        return $active_subscription > 0;
    }

    function firefly_collective_get_features() {
        global $wpdb;
        if ( ! is_user_logged_in() ) {
            return new WP_Error('not_logged_in', 'You must be logged in to view features.', array('status' => 401));
        }
        
        $features = $wpdb->get_results(
            "SELECT id, featureName FROM {$wpdb->prefix}ffc_features",
            ARRAY_A
        );
        
        return array(
            'success'  => true,
            'features' => $features
        );
    }

    function firefly_collective_get_options() {
        global $wpdb;
        if ( ! is_user_logged_in() ) {
            return new WP_Error('not_logged_in', 'You must be logged in to view options.', array('status' => 401));
        }
        
        $options = $wpdb->get_results(
            "SELECT id, featureId, optionName FROM {$wpdb->prefix}ffc_options",
            ARRAY_A
        );
        
        return array(
            'success' => true,
            'options' => $options
        );
    }

    function firefly_collective_get_addons() {
        global $wpdb;
        if ( ! is_user_logged_in() ) {
            return new WP_Error('not_logged_in', 'You must be logged in to view addons.', array('status' => 401));
        }
        
        $addons = $wpdb->get_results(
            "SELECT id, optionId, addonName, staticPriceMod, groupName FROM {$wpdb->prefix}ffc_addons",
            ARRAY_A
        );
        
        return array(
            'success' => true,
            'addons'  => $addons
        );
    }

    function firefly_collective_get_users() {
        global $wpdb;
        if ( ! is_user_logged_in() ) {
            return new WP_Error('not_logged_in', 'You must be logged in to view users.', array('status' => 401));
        }
        
        $users = array();
        $user_query = new WP_User_Query(array(
            'fields' => array('ID', 'display_name', 'user_email')
        ));
        
        foreach ($user_query->get_results() as $user) {
            $users[$user->ID] = $user->display_name . ' (' . $user->user_email . ')';
        }
        
        return array(
            'success' => true,
            'users'   => $users
        );
    }

    // Calculate itemized pricing
    function get_itemized_pricing_breakdown($item, $wpdb) {
        $lines = array();
        
        // Get feature and option info
        $feature = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}ffc_features WHERE id = %d", intval($item['featureId'])),
            ARRAY_A
        );
        $option = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}ffc_options WHERE id = %d", intval($item['optionId'])),
            ARRAY_A
        );
        
        if (!$feature || !$option) {
            return array('lines' => array());
        }
        
        // Parse discount info
        $discount_info = array();
        if (!empty($item['priceDiscountsInfo'])) {
            $discount_info = json_decode($item['priceDiscountsInfo'], true) ?: array();
        }
        
        // Calculate base option price (work backwards from total)
        $base_price = floatval($item['totalPrice']) + floatval($item['totalPriceDiscount'] ?: 0);
        
        // Subtract addon costs
        $addon_ids = json_decode($item['addonIds'] ?: '[]', true) ?: array();
        foreach ($addon_ids as $addon_id) {
            $addon = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$wpdb->prefix}ffc_addons WHERE id = %d", intval($addon_id)),
                ARRAY_A
            );
            if ($addon) {
                $base_price -= floatval($addon['staticPriceMod'] ?: 0) * intval($item['quantity']);
            }
        }
        
        // Add base option line
        $lines[] = array(
            'name' => $feature['featureName'] . ' - ' . $option['optionName'],
            'quantity' => intval($item['quantity']),
            'unit_price' => $base_price / intval($item['quantity']),
            'total_price' => $base_price,
            'is_base' => true,
            'is_addon' => false,
            'is_discount' => false
        );
        
        // Add option discount if present
        if (!empty($discount_info['option'])) {
            $lines[] = array(
                'name' => $discount_info['option'],
                'quantity' => 1,
                'unit_price' => 0,
                'total_price' => 0,
                'is_base' => false,
                'is_addon' => false,
                'is_discount' => true
            );
        }
        
        // Add individual addons
        foreach ($addon_ids as $index => $addon_id) {
            $addon = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$wpdb->prefix}ffc_addons WHERE id = %d", intval($addon_id)),
                ARRAY_A
            );
            if ($addon) {
                $addon_price = floatval($addon['staticPriceMod'] ?: 0) * intval($item['quantity']);
                $lines[] = array(
                    'name' => $addon['addonName'],
                    'quantity' => intval($item['quantity']),
                    'unit_price' => floatval($addon['staticPriceMod'] ?: 0),
                    'total_price' => $addon_price,
                    'is_base' => false,
                    'is_addon' => true,
                    'is_discount' => false
                );
                
                // Add addon discount if present
                if (!empty($discount_info['addons'][$index])) {
                    $lines[] = array(
                        'name' => $discount_info['addons'][$index],
                        'quantity' => 1,
                        'unit_price' => 0,
                        'total_price' => 0,
                        'is_base' => false,
                        'is_addon' => false,
                        'is_discount' => true
                    );
                }
            }
        }
        
        return array('lines' => $lines);
    }

    /**
     * Toggle online payments on/off
     */
    function firefly_collective_toggle_online_payments($request) {
        // Check if user has admin permissions
        if (!current_user_can('manage_options')) {
            return new WP_Error('unauthorized', 'You do not have permission to change this setting', array('status' => 403));
        }

        $params = $request->get_json_params();
        $enabled = isset($params['enabled']) ? $params['enabled'] : false;

        // Convert to string '1' or '0'
        $enabled_value = $enabled ? '1' : '0';

        // Update the option in the database
        $result = update_option('firefly_online_payments_enabled', $enabled_value);

        if ($result || get_option('firefly_online_payments_enabled') === $enabled_value) {
            return array(
                'success' => true,
                'message' => $enabled ? 'Online payments enabled' : 'Online payments disabled',
                'enabled' => $enabled_value
            );
        } else {
            return new WP_Error('update_failed', 'Failed to update online payments setting', array('status' => 500));
        }
    }

    /**
     * Get online payments status
     */
    function firefly_collective_get_online_payments_status($request) {
        // Check if user has admin permissions
        if (!current_user_can('manage_options')) {
            return new WP_Error('unauthorized', 'You do not have permission to view this setting', array('status' => 403));
        }

        $enabled = get_option('firefly_online_payments_enabled', '1');

        return array(
            'success' => true,
            'enabled' => $enabled === '1'
        );
    }

    function firefly_collective_orders_email($order_id, $new_status = '', $order_data = null) {
        global $wpdb;
        
        if ($order_data !== null) {
            $order_items = $order_data;
        } else {
            // Get order items with joined feature and option data
            $order_items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT o.*, f.featureName, opt.optionName, opt.priceOptions 
                    FROM {$wpdb->prefix}ffc_orders o
                    LEFT JOIN {$wpdb->prefix}ffc_features f ON o.featureId = f.id
                    LEFT JOIN {$wpdb->prefix}ffc_options opt ON o.optionId = opt.id
                    WHERE o.orderID = %s 
                    ORDER BY o.id ASC",
                    $order_id
                ),
                ARRAY_A
            );
        }
        
        if (empty($order_items)) {
            return false;
        }
        
        $first_item = $order_items[0];
        
        // Handle anonymous vs authenticated users
        $user_email = '';
        $user_name = '';

        if (intval($first_item['userId']) === 0) {
            // Anonymous order
            $user_email = $first_item['anonUserEmail'];
            $user_name = trim($first_item['anonUserFirstName'] . ' ' . $first_item['anonUserLastName']) ?: 'Customer';
            
            if (empty($user_email)) {
                return false; // Can't send email without address
            }
        } else {
            // Authenticated user
            $user = get_userdata($first_item['userId']);
            if (!$user) {
                return false;
            }
            $user_email = $user->user_email;
            $user_name = $user->display_name;
        }
        
        $status = !empty($new_status) ? $new_status : $first_item['status'];
        $status_formatted = ucfirst($status);
        $status_color = '';
        
        switch ($status) {
            case 'completed':
            case 'paid':
                $status_color = '#28a745';
                break;
            case 'pending':
                $status_color = '#ffc107';
                break;
            case 'cancelled':
                $status_color = '#dc3545';
                break;
            case 'refunded':
                $status_color = '#6c757d';
                break;
            default:
                $status_color = '#6c757d';
        }
        
        $order_date = date('F j, Y, g:i a', strtotime($first_item['createdAt']));
        $invoice_number = str_replace('-', '', substr($first_item['orderID'], 0, 8));
        
        // Compute order total from stored values
        $order_total = 0;
        $total_discount = 0;
        foreach ($order_items as $item) {
            $order_total += floatval($item['totalPrice']);
            $total_discount += floatval($item['totalPriceDiscount']);
        }
        $formatted_order_total = '$' . number_format($order_total, 2);
        $formatted_discount = '$' . number_format($total_discount, 2);
        
        $service_type = '';
        if (!empty($first_item['userData'])) {
            $user_data = json_decode($first_item['userData'], true);
            if (is_array($user_data) && isset($user_data['dineInTakeOutDelivery'])) {
                $service_types = array('Dine In', 'Take Out', 'Delivery');
                $selected_type = intval($user_data['dineInTakeOutDelivery']);
                if (isset($service_types[$selected_type])) {
                    $service_type = $service_types[$selected_type];
                }
            }
        }
        
        // Build items HTML from stored data
        $items_html = '';
        foreach ($order_items as $item) {
            // Ensure required fields exist
            if (!isset($item['quantity']) || !isset($item['featureName']) || !isset($item['optionName'])) {
                continue;
            }
            
            $quantity = intval($item['quantity']);
            
            // Get the selected price option details (with robust fallbacks)
            $price_options    = null;
            $size_label       = '';
            $base_unit_price  = null; // start as null so we can detect "not set"

            // Try priceOptions first
            if (!empty($item['priceOptions'])) {
                $price_options = json_decode($item['priceOptions'], true);
                if ($price_options && isset($price_options['types']) && is_array($price_options['types'])) {
                    $price_selected_index = intval($item['priceSelected']);
                    if (isset($price_options['types'][$price_selected_index]) && is_array($price_options['types'][$price_selected_index])) {
                        $size_label       = isset($price_options['types'][$price_selected_index]['label']) ? $price_options['types'][$price_selected_index]['label'] : '';
                        $base_unit_price  = isset($price_options['types'][$price_selected_index]['price']) ? floatval($price_options['types'][$price_selected_index]['price']) : null;
                    }
                }
            }

            // Fallback #1: use staticPrice from options if present
            if ($base_unit_price === null || $base_unit_price == 0) {
                if (isset($item['staticPrice']) && is_numeric($item['staticPrice'])) {
                    $base_unit_price = floatval($item['staticPrice']);
                }
            }

            // Fallback #2: derive base price from stored totals by removing addons & reversing discounts
            if ($base_unit_price === null || $base_unit_price == 0) {
                // Use existing helper to compute a clean base-per-unit
                $breakdown = get_itemized_pricing_breakdown($item, $wpdb);
                if (!empty($breakdown['lines'])) {
                    foreach ($breakdown['lines'] as $line) {
                        if (!empty($line['is_base'])) {
                            $base_unit_price = floatval($line['unit_price']);
                            break;
                        }
                    }
                }

                // Last-resort guard
                if ($base_unit_price === null) {
                    $base_unit_price = 0;
                }
            }
            
            // Base item row
            $base_name = $item['featureName'] . ' - ' . $item['optionName'] . ($size_label ? ' (' . $size_label . ')' : '');
            $items_html .= "
                <tr class='ffc-base-item'>
                    <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; font-weight: bold;'>
                        " . esc_html($base_name) . "
                    </td>
                    <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; text-align: center; font-weight: bold;'>
                        {$quantity}
                    </td>
                    <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; text-align: right; font-weight: bold;'>
                        $" . number_format($base_unit_price, 2) . "
                    </td>
                    <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; text-align: right; font-weight: bold;'>
                        $" . number_format($base_unit_price * $quantity, 2) . "
                    </td>
                </tr>
            ";
            
            // Show option-level discount right after the base item
            $discount_info = null;
            if (!empty($item['priceDiscountsInfo'])) {
                $discount_info = json_decode($item['priceDiscountsInfo'], true);
                if (is_array($discount_info) && isset($discount_info['option'])) {
                    $items_html .= "
                        <tr class='ffc-discount-item'>
                            <td colspan='4' style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; padding-left: 30px; font-size: 0.85em; font-style: italic; color: #0066cc; text-align: left;'>
                                " . esc_html($discount_info['option']) . "
                            </td>
                        </tr>
                    ";
                }
            }
            
            // Add-ons from stored JSON
            if (!empty($item['addonIds'])) {
                $addon_ids = json_decode($item['addonIds'], true);
                if (is_array($addon_ids)) {
                    foreach ($addon_ids as $addon_id_str) {
                        $addon_id = intval($addon_id_str);
                        $addon_quantity = $quantity; // Use base item quantity for addons
                        
                        // Get addon details
                        $addon = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT addonName, staticPriceMod FROM {$wpdb->prefix}ffc_addons WHERE id = %d",
                                $addon_id
                            ),
                            ARRAY_A
                        );
                        
                        if ($addon) {
                            $addon_unit_price = floatval($addon['staticPriceMod']);
                            $addon_total_price = $addon_unit_price * $addon_quantity;
                            
                            $items_html .= "
                                <tr class='ffc-addon-item'>
                                    <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; padding-left: 20px; font-size: 0.95em;'>
                                        + " . esc_html($addon['addonName']) . "
                                    </td>
                                    <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; text-align: center; font-size: 0.95em;'>
                                        {$addon_quantity}
                                    </td>
                                    <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; text-align: right; font-size: 0.95em;'>
                                        $" . number_format($addon_unit_price, 2) . "
                                    </td>
                                    <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; text-align: right; font-size: 0.95em;'>
                                        $" . number_format($addon_total_price, 2) . "
                                    </td>
                                </tr>
                            ";
                        }
                    }
                }
            }
            
            // Show group/addon discounts after addons
            if ($discount_info && isset($discount_info['addons']) && is_array($discount_info['addons'])) {
                foreach ($discount_info['addons'] as $addon_discount) {
                    $items_html .= "
                        <tr class='ffc-discount-item'>
                            <td colspan='4' style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; padding-left: 30px; font-size: 0.85em; font-style: italic; color: #0066cc; text-align: left;'>
                                " . esc_html($addon_discount) . "
                            </td>
                        </tr>
                    ";
                }
            }
            
            // Show final item discount amount
            $item_discount = floatval($item['totalPriceDiscount']);
            if ($item_discount > 0) {
                $items_html .= "
                    <tr class='ffc-discount-item'>
                        <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; padding-left: 30px; font-size: 0.85em; font-style: italic; color: #0066cc;'>
                            Item Discount
                        </td>
                        <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; text-align: center; font-size: 0.85em; color: #0066cc;'>
                            1
                        </td>
                        <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; text-align: right; font-size: 0.85em; color: #0066cc;'>
                            
                        </td>
                        <td style='padding: 8px 10px; border-bottom: 1px solid #dee2e6; text-align: right; font-size: 0.85em; color: #0066cc;'>
                            -$" . number_format($item_discount, 2) . "
                        </td>
                    </tr>
                ";
            }
            
            // Add separator between items
            $items_html .= "
                <tr class='item-spacer'>
                    <td colspan='4' style='border: none; padding: 10px 0;'></td>
                </tr>
            ";
        }
        
        $email_css = "
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; background-color: #fff; }
            .invoice-header { background-color: #f8f9fa; padding: 20px; border-bottom: 2px solid #dee2e6; }
            .invoice-body { padding: 20px; }
            .invoice-footer { background-color: #f8f9fa; padding: 20px; border-top: 2px solid #dee2e6; margin-top: 20px; }
            .company-name { font-size: 24px; font-weight: bold; color: #007bff; }
            .status-badge { display: inline-block; padding: 8px 15px; border-radius: 4px; color: white; font-weight: bold; }
            .invoice-details { margin: 20px 0; }
            .invoice-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .invoice-table th { background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; text-align: left; padding: 10px; }
            .invoice-table td { padding: 8px 10px; border-bottom: 1px solid #dee2e6; }
            .invoice-table .total-row { font-weight: bold; border-top: 2px solid #dee2e6; }
            .item-spacer { border: none; }
            .contact-info { font-size: 14px; margin-top: 20px; }
            .customer-details { margin: 20px 0; }
        ";
        
        // Build the dynamic "thank‐you" or "if you have questions" message 
        if ($status === 'completed' || $status === 'paid') {
            $closing_paragraph = "<p>Thank you for your order! If you have any questions, please contact us.</p>";
        } else {
            $closing_paragraph = "<p>If you have any questions, please contact us.</p>";
        }
        
        $user_subject = "Your Order #{$invoice_number} is now {$status_formatted}";
        $user_html = "
            <html>
            <head>
                <title>Order {$status_formatted} - Invoice #{$invoice_number}</title>
                <style>{$email_css}</style>
            </head>
            <body>
                <div class='invoice-header'>
                    <div class='company-name'>Firefly Creative</div>
                    <div>Order Invoice #{$invoice_number}</div>
                    <div class='status-badge' style='background-color: {$status_color};'>
                        Status: {$status_formatted}
                    </div>
                </div>
                
                <div class='invoice-body'>
                    <div class='invoice-details'>
                        <strong>Order Date:</strong> {$order_date}<br>
                        <strong>Order ID:</strong> {$first_item['orderID']}<br>
                        <strong>Service Type:</strong> {$service_type}
                    </div>
                    
                    <div class='customer-details'>
                        <strong>Customer:</strong> {$user_name}<br>
                        <strong>Email:</strong> {$user_email}
                    </div>
                    
                    <table class='invoice-table'>
                        <thead>
                            <tr>
                                <th style='width:50%;'>Item</th>
                                <th style='width:15%; text-align:center;'>Quantity</th>
                                <th style='width:15%; text-align:right;'>Unit Price</th>
                                <th style='width:20%; text-align:right;'>Total Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$items_html}
                        </tbody>
                        <tfoot>
                            <tr class='total-row'>
                                <td colspan='3' style='text-align:right;'>Order Total:</td>
                                <td style='text-align:right;'>{$formatted_order_total}</td>
                            </tr>"
                            . (
                                $total_discount > 0
                                ? "
                                <tr>
                                    <td colspan='3'></td>
                                    <td style='font-size:0.85em; font-style:italic; color:#0066cc; text-align:right;'>
                                        Total Savings: -{$formatted_discount}
                                    </td>
                                </tr>
                                "
                                : ""
                            ) . "
                        </tfoot>
                    </table>
                    
                    {$closing_paragraph}
                </div>
                
                <div class='invoice-footer'>
                    <div class='contact-info'>
                        Firefly Creative<br>
                        Email: <a href='mailto:donotreply@fireflycreative.io'>donotreply@fireflycreative.io</a>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Set headers for HTML mail
        $headers = array(
            'From: Firefly Creative <donotreply@fireflycreative.io>',
            'Reply-To: donotreply@fireflycreative.io',
            'Content-Type: text/html; charset=UTF-8',
        );

        // Send user email
        $user_sent = wp_mail(
            $user_email,
            $user_subject,
            $user_html,
            $headers
        );
        
        $admin_subject = "Order #{$invoice_number} from {$user_name} is now {$status_formatted}";
        $admin_html = "
            <html>
            <head>
                <title>Order {$status_formatted} - Invoice #{$invoice_number}</title>
                <style>{$email_css}</style>
            </head>
            <body>
                <div class='invoice-header'>
                    <div class='company-name'>Firefly Creative</div>
                    <div>Order Invoice #{$invoice_number}</div>
                    <div class='status-badge' style='background-color: {$status_color};'>
                        Status: {$status_formatted}
                    </div>
                </div>
                
                <div class='invoice-body'>
                    <div class='invoice-details'>
                        <strong>Order Date:</strong> {$order_date}<br>
                        <strong>Order ID:</strong> {$first_item['orderID']}<br>
                        <strong>Service Type:</strong> {$service_type}
                    </div>
                    
                    <div class='customer-details'>
                        <strong>Customer Information:</strong><br>
                        <strong>Name:</strong> {$user_name}<br>
                        <strong>Email:</strong> {$user_email}<br>
                        <strong>User ID:</strong> {$first_item['userId']}
                    </div>
                    
                    <table class='invoice-table'>
                        <thead>
                            <tr>
                                <th style='width:50%;'>Item</th>
                                <th style='width:15%; text-align:center;'>Quantity</th>
                                <th style='width:15%; text-align:right;'>Unit Price</th>
                                <th style='width:20%; text-align:right;'>Total Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$items_html}
                        </tbody>
                        <tfoot>
                            <tr class='total-row'>
                                <td colspan='3' style='text-align:right;'>Order Total:</td>
                                <td style='text-align:right;'>{$formatted_order_total}</td>
                            </tr>"
                            . (
                                $total_discount > 0
                                ? "
                                <tr>
                                    <td colspan='3'></td>
                                    <td style='font-size:0.85em; font-style:italic; color:#0066cc; text-align:right;'>
                                        Total Savings: -{$formatted_discount}
                                    </td>
                                </tr>
                                "
                                : ""
                            ) . "
                        </tfoot>
                    </table>
                    
                    <p>
                        <strong>Order Status Change:</strong>
                        Order is now <span style='color: {$status_color}; font-weight: bold;'>{$status_formatted}</span>
                    </p>
                    
                    {$closing_paragraph}
                </div>
                
                <div class='invoice-footer'>
                    <div class='contact-info'>
                        This is an automated notification from the order management system.
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Send admin notification
        $admin_email = get_option('admin_email');
        $admin_sent = wp_mail(
            $admin_email, 
            $admin_subject,
            $admin_html,
            $headers
        );

        return ($user_sent && $admin_sent);
    }
