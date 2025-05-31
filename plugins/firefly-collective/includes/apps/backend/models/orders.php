<?php

    // plugin/models/orders.php

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

    function firefly_collective_orders_dashboard() {
        $plugin_root = dirname(plugin_dir_path(__FILE__));
        $view_path   = $plugin_root . '/views/orders.php';
        if (file_exists($view_path)) {
            require_once $view_path;
        } else {
            wp_die('The pricing view file could not be found.', 'File Not Found', array('response' => 404));
        }
    }

    /**
     * Creates the _ffc_orders table for storing order data
     */
    function create_ffc_orders_table_if_not_exist() {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            orderID VARCHAR(36) DEFAULT NULL,
            userId INT UNSIGNED NOT NULL,
            featureId INT UNSIGNED NOT NULL,
            optionId INT UNSIGNED NOT NULL,
            addonIds JSON DEFAULT NULL,
            priceSelected INT DEFAULT NULL,
            quantity INT DEFAULT NULL,
            totalPrice DECIMAL(10,2) DEFAULT NULL,
            totalPriceDiscount DECIMAL(10,2) DEFAULT NULL,
            priceDiscountsInfo JSON DEFAULT NULL,
            userData JSON NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            INDEX idx_order (orderID),
            INDEX idx_user (userId),
            INDEX idx_feature (featureId),
            INDEX idx_option (optionId),
            INDEX idx_status (status),
            INDEX idx_created (createdAt)
        ) {$collate};";
        
        require_once(ABSPATH.'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    function firefly_collective_place_order($request) {
        global $wpdb;
        $data = $request->get_json_params();
        
        // Check if we're receiving a single item or multiple items
        $is_batch = isset($data['items']) && is_array($data['items']);
        $order_items = $is_batch ? $data['items'] : [$data];
        
        // Get current user ID
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('not_logged_in', 'You must be logged in to place an order.', array('status' => 401));
        }
        
        // Generate order ID if not provided
        $order_id = isset($data['orderID']) ? sanitize_text_field($data['orderID']) : wp_generate_uuid4();
        
        $inserted_records = [];
        $total_order_value = 0;
        
        // Process each order item
        foreach ($order_items as $item) {
            // Extract item data
            $feature_id = intval($item['featureId']);
            $option_id = intval($item['optionId']);
            $addon_ids = isset($item['addonIds']) ? $item['addonIds'] : [];
            $user_data = isset($item['userData']) ? $item['userData'] : [];
            $price_option_index = isset($item['priceOptionIndex']) ? intval($item['priceOptionIndex']) : 0;
            $quantity = isset($item['quantity']) ? intval($item['quantity']) : 1;
            
            // Calculate price and get discount information
            $price_data = calculate_server_price($feature_id, $option_id, $addon_ids, $price_option_index, $quantity);
            
            // Insert order item into database
            $result = $wpdb->insert(
                $wpdb->prefix . 'ffc_orders',
                array(
                    'orderID' => $order_id,
                    'userId' => $user_id,
                    'featureId' => $feature_id,
                    'optionId' => $option_id,
                    'addonIds' => json_encode($addon_ids),
                    'priceSelected' => $price_option_index,
                    'quantity' => $quantity,
                    'totalPrice' => $price_data['totalPrice'],
                    'totalPriceDiscount' => $price_data['totalPriceDiscount'],
                    'priceDiscountsInfo' => json_encode($price_data['priceDiscountsInfo']),
                    'userData' => json_encode($user_data),
                    'status' => 'pending',
                    'createdAt' => current_time('mysql')
                )
            );
            
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to save order item: ' . $wpdb->last_error, array('status' => 500));
            }
            
            $inserted_records[] = [
                'recordId' => $wpdb->insert_id,
                'featureId' => $feature_id,
                'calculatedPrice' => $price_data['totalPrice'],
                'discountAmount' => $price_data['totalPriceDiscount'],
                'discountInfo' => $price_data['priceDiscountsInfo']
            ];
            
            $total_order_value += $price_data['totalPrice'];
        }
        
        // Return success with orderID and all record IDs
        return array(
            'success' => true,
            'orderID' => $order_id,
            'records' => $inserted_records,
            'totalOrderValue' => $total_order_value
        );
    }

    /**
     * Get all orders with optional filtering and pagination
     */
    function firefly_collective_get_orders($request) {
        global $wpdb;
        
        $page = $request->get_param('page') ? intval($request->get_param('page')) : 1;
        $per_page = $request->get_param('per_page') ? intval($request->get_param('per_page')) : 10;
        $status = $request->get_param('status');
        $date_from = $request->get_param('date_from');
        $date_to = $request->get_param('date_to');
        $search = $request->get_param('search');
        $order_id = $request->get_param('order_id');
        $sort_field = $request->get_param('sort_field') ? sanitize_text_field($request->get_param('sort_field')) : 'createdAt';
        $sort_direction = $request->get_param('sort_direction') ? sanitize_text_field($request->get_param('sort_direction')) : 'desc';
        
        // Build query
        $where_clauses = [];
        $query_params = [];
        
        // Filter by status
        if ($status) {
            $where_clauses[] = 'status = %s';
            $query_params[] = $status;
        }
        
        // Filter by date range
        if ($date_from) {
            $where_clauses[] = 'createdAt >= %s';
            $query_params[] = $date_from . ' 00:00:00';
        }
        
        if ($date_to) {
            $where_clauses[] = 'createdAt <= %s';
            $query_params[] = $date_to . ' 23:59:59';
        }
        
        // Filter by order ID
        if ($order_id) {
            $where_clauses[] = 'orderID LIKE %s';
            $query_params[] = '%' . $wpdb->esc_like($order_id) . '%';
        }
        
        // Filter by search term (in orderID, user data, or user ID)
        if ($search) {
            // Get user IDs that match the search term
            $user_query = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} 
                WHERE display_name LIKE %s OR user_login LIKE %s OR user_email LIKE %s",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
            
            $matching_user_ids = $wpdb->get_col($user_query);
            
            $search_clauses = ['orderID LIKE %s', 'userData LIKE %s'];
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
            
            if (!empty($matching_user_ids)) {
                $user_ids_string = implode(',', array_map('intval', $matching_user_ids));
                $search_clauses[] = "userId IN ($user_ids_string)";
            }
            
            $where_clauses[] = '(' . implode(' OR ', $search_clauses) . ')';
        }
        
        // Build WHERE clause
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // Validate sort field against allowed fields
        $allowed_sort_fields = [
            'orderID', 'userId', 'featureId', 'optionId', 
            'totalPrice', 'quantity', 'status', 'createdAt'
        ];
        
        if (!in_array($sort_field, $allowed_sort_fields)) {
            $sort_field = 'createdAt';
        }
        
        // Validate sort direction
        $sort_direction = strtoupper($sort_direction) === 'ASC' ? 'ASC' : 'DESC';
        
        // Count total results for pagination
        $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}ffc_orders $where_sql";
        
        // If there are query parameters, prepare the count query
        if (!empty($query_params)) {
            $count_query = $wpdb->prepare($count_query, $query_params);
        }
        
        $total_items = $wpdb->get_var($count_query);
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Main query
        $orders_query = "
            SELECT * FROM {$wpdb->prefix}ffc_orders
            $where_sql
            ORDER BY $sort_field $sort_direction
            LIMIT %d OFFSET %d
        ";
        
        // Add pagination parameters
        $query_params[] = $per_page;
        $query_params[] = $offset;
        
        $orders = $wpdb->get_results(
            $wpdb->prepare($orders_query, $query_params),
            ARRAY_A
        );
        
        return [
            'success' => true,
            'orders' => $orders,
            'total' => intval($total_items),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ];
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
     * Update an order's status
     */
    function firefly_collective_update_order_status($request) {
        global $wpdb;
        
        $order_id = sanitize_text_field($request->get_param('orderID'));
        $status = sanitize_text_field($request->get_param('status'));
        
        if (empty($order_id)) {
            return [
                'success' => false,
                'message' => 'Order ID is required'
            ];
        }
        
        if (empty($status)) {
            return [
                'success' => false,
                'message' => 'Status is required'
            ];
        }
        
        // Validate status
        $allowed_statuses = ['pending', 'completed', 'cancelled'];
        if (!in_array($status, $allowed_statuses)) {
            return [
                'success' => false,
                'message' => 'Invalid status value'
            ];
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ffc_orders',
            ['status' => $status],
            ['orderID' => $order_id],
            ['%s'],
            ['%s']
        );
        
        if ($result === false) {
            return [
                'success' => false,
                'message' => 'Failed to update order status: ' . $wpdb->last_error
            ];
        }
        
        firefly_collective_orders_email($order_id, $status);

        return [
            'success' => true,
            'message' => 'Order status updated successfully',
            'updated' => $result // Number of rows affected
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

    /**
     * Update multiple orders' status (bulk update)
     */
    function firefly_collective_update_orders_status_bulk($request) {
        global $wpdb;
        
        $order_ids = $request->get_param('orderIDs');
        $status = sanitize_text_field($request->get_param('status'));
        
        if (empty($order_ids) || !is_array($order_ids)) {
            return [
                'success' => false,
                'message' => 'Order IDs are required'
            ];
        }
        
        if (empty($status)) {
            return [
                'success' => false,
                'message' => 'Status is required'
            ];
        }
        
        // Validate status
        $allowed_statuses = ['pending', 'completed', 'cancelled'];
        if (!in_array($status, $allowed_statuses)) {
            return [
                'success' => false,
                'message' => 'Invalid status value'
            ];
        }
        
        // Sanitize order IDs
        $order_ids = array_map('sanitize_text_field', $order_ids);
        
        // Build placeholders for the query
        $placeholders = implode(',', array_fill(0, count($order_ids), '%s'));
        
        // Create query parameters array with status as first parameter
        $query_params = array_merge([$status], $order_ids);
        
        $query = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}ffc_orders SET status = %s WHERE orderID IN ($placeholders)",
            $query_params
        );
        
        $result = $wpdb->query($query);
        
        if ($result === false) {
            return [
                'success' => false,
                'message' => 'Failed to update orders: ' . $wpdb->last_error
            ];
        }
        
        // Send emails to all updated orders
        foreach ($order_ids as $order_id) {
            firefly_collective_orders_email($order_id, $status);
        }
        
        return [
            'success' => true,
            'message' => 'Orders updated successfully',
            'updated' => $result // Number of rows affected
        ];
    }

    /**
     * Get features for lookups
     */
    function firefly_collective_get_features() {
        global $wpdb;
        
        $features = $wpdb->get_results(
            "SELECT id, featureName FROM {$wpdb->prefix}ffc_features",
            ARRAY_A
        );
        
        return [
            'success' => true,
            'features' => $features
        ];
    }

    /**
     * Get options for lookups
     */
    function firefly_collective_get_options() {
        global $wpdb;
        
        $options = $wpdb->get_results(
            "SELECT id, featureId, optionName FROM {$wpdb->prefix}ffc_options",
            ARRAY_A
        );
        
        return [
            'success' => true,
            'options' => $options
        ];
    }

    /**
     * Get addons for lookups
     */
    function firefly_collective_get_addons() {
        global $wpdb;
        
        $addons = $wpdb->get_results(
            "SELECT id, optionId, addonName FROM {$wpdb->prefix}ffc_addons",
            ARRAY_A
        );
        
        return [
            'success' => true,
            'addons' => $addons
        ];
    }

    /**
     * Get users for lookups
     */
    function firefly_collective_get_users() {
        global $wpdb;
        
        $users = [];
        $user_query = new WP_User_Query([
            'fields' => ['ID', 'display_name', 'user_email']
        ]);
        
        foreach ($user_query->get_results() as $user) {
            $users[$user->ID] = $user->display_name . ' (' . $user->user_email . ')';
        }
        
        return [
            'success' => true,
            'users' => $users
        ];
    }

    /**
     * Send order status update notifications to admin and user with detailed invoice for all items in order
     */
    function firefly_collective_orders_email($order_id, $new_status = '', $order_data = null) {
        global $wpdb;
        
        // If order data is provided (e.g., for deleted orders), use it
        // Otherwise, fetch from database
        if ($order_data !== null) {
            $order_items = $order_data;
        } else {
            // Get all order items with this order ID
            $order_items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_orders WHERE orderID = %s ORDER BY id ASC",
                    $order_id
                ),
                ARRAY_A
            );
        }
        
        if (empty($order_items)) {
            return false; // Order not found
        }
        
        // Get the first item to extract common order information
        $first_item = $order_items[0];
        
        // Get user data
        $user = get_userdata($first_item['userId']);
        if (!$user) {
            return false; // User not found
        }
        
        // Status messaging
        $status = !empty($new_status) ? $new_status : $first_item['status'];
        $status_formatted = ucfirst($status);
        $status_color = '';
        
        switch ($status) {
            case 'completed':
                $status_color = '#28a745'; // Green
                break;
            case 'pending':
                $status_color = '#ffc107'; // Yellow
                break;
            case 'cancelled':
                $status_color = '#dc3545'; // Red
                break;
            case 'deleted':
                $status_color = '#6c757d'; // Gray
                break;
            default:
                $status_color = '#6c757d'; // Gray
        }
        
        // Format dates
        $order_date = date('F j, Y, g:i a', strtotime($first_item['createdAt']));
        $invoice_number = str_replace('-', '', substr($first_item['orderID'], 0, 8));
        
        // Calculate order total
        $order_total = 0;
        foreach ($order_items as $item) {
            $order_total += floatval($item['totalPrice']);
        }
        $formatted_order_total = '$' . number_format($order_total, 2);
        
        // Order service type (dine in, take out, delivery)
        $service_type = '';
        if (!empty($first_item['userData'])) {
            $user_data = json_decode($first_item['userData'], true);
            if (isset($user_data['dineInTakeOutDelivery'])) {
                $service_types = ['Dine In', 'Take Out', 'Delivery'];
                $selected_type = intval($user_data['dineInTakeOutDelivery']);
                if (isset($service_types[$selected_type])) {
                    $service_type = $service_types[$selected_type];
                }
            }
        }
        
        // Build HTML for all order items
        $items_html = '';
        
        foreach ($order_items as $item_index => $item) {
            // Get feature details
            $feature = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_features WHERE id = %d",
                    $item['featureId']
                ),
                ARRAY_A
            );
            
            // Get option details
            $option = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_options WHERE id = %d",
                    $item['optionId']
                ),
                ARRAY_A
            );
            
            // Skip if feature or option not found
            if (!$feature || !$option) {
                continue;
            }
            
            // Get pricing information for the selected option
            $size_label = '';
            $base_price = 0;
            
            if (!empty($option['pricingType']) && $option['pricingType'] == 'price options') {
                $price_options = json_decode($option['priceOptions'], true);
                if (isset($price_options['types']) && isset($item['priceSelected'])) {
                    $selected_index = intval($item['priceSelected']);
                    if (isset($price_options['types'][$selected_index])) {
                        $size_label = $price_options['types'][$selected_index]['label'];
                        $base_price = floatval($price_options['types'][$selected_index]['price']);
                    }
                }
            }
            
            // Format item price
            $formatted_item_price = '$' . number_format($item['totalPrice'], 2);
            $formatted_base_price = '$' . number_format($base_price, 2);
            
            // Item header row with feature name and option
            $items_html .= "
                <tr class='item-header'>
                    <td><strong>{$feature['featureName']} - {$option['optionName']} ({$size_label})</strong></td>
                    <td><strong>{$item['quantity']}</strong></td>
                    <td><strong>{$formatted_base_price}</strong></td>
                </tr>
            ";
            
            // Decode addons and get their details
            if (!empty($item['addonIds'])) {
                $addon_ids = json_decode($item['addonIds'], true);
                $grouped_addons = [];
                
                if (is_array($addon_ids)) {
                    foreach ($addon_ids as $addon_id) {
                        $addon = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT * FROM {$wpdb->prefix}ffc_addons WHERE id = %d",
                                $addon_id
                            ),
                            ARRAY_A
                        );
                        
                        if ($addon) {
                            $group = !empty($addon['groupName']) ? $addon['groupName'] : 'Standard Addons';
                            if (!isset($grouped_addons[$group])) {
                                $grouped_addons[$group] = [];
                            }
                            $grouped_addons[$group][] = $addon;
                        }
                    }
                    
                    // Generate addon HTML with grouping
                    foreach ($grouped_addons as $group_name => $group_addons) {
                        $items_html .= "<tr><td colspan='3'>&nbsp;&nbsp;&nbsp;<em>{$group_name}:</em></td></tr>";
                        
                        foreach ($group_addons as $addon) {
                            $addon_price = floatval($addon['staticPriceMod']);
                            $addon_price_formatted = '$' . number_format($addon_price, 2);
                            $items_html .= "
                                <tr>
                                    <td>&nbsp;&nbsp;&nbsp;- {$addon['addonName']}</td>
                                    <td>1</td>
                                    <td>{$addon_price_formatted}</td>
                                </tr>
                            ";
                        }
                    }
                }
            }
            
            // Item subtotal
            $items_html .= "
                <tr class='item-subtotal'>
                    <td></td>
                    <td>Item Total:</td>
                    <td>{$formatted_item_price}</td>
                </tr>
                <tr class='item-spacer'><td colspan='3'>&nbsp;</td></tr>
            ";
        }
        
        // Common CSS for both emails
        $email_css = "
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; }
            .invoice-header { background-color: #f8f9fa; padding: 20px; border-bottom: 2px solid #dee2e6; }
            .invoice-body { padding: 20px; }
            .invoice-footer { background-color: #f8f9fa; padding: 20px; border-top: 2px solid #dee2e6; margin-top: 20px; }
            .company-name { font-size: 24px; font-weight: bold; color: #007bff; }
            .status-badge { display: inline-block; padding: 8px 15px; border-radius: 4px; color: white; font-weight: bold; }
            .invoice-details { margin: 20px 0; }
            .invoice-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .invoice-table th { background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; text-align: left; padding: 10px; }
            .invoice-table td { padding: 10px; border-bottom: 1px solid #dee2e6; }
            .invoice-table .total-row { font-weight: bold; border-top: 2px solid #dee2e6; }
            .item-header { background-color: #f8f9fa; }
            .item-subtotal { border-bottom: 1px solid #dee2e6; }
            .item-spacer { border: none; }
            .contact-info { font-size: 14px; }
            .customer-details { margin: 20px 0; }
            .invoice-table th:nth-child(1) { width: 60%; }
            .invoice-table th:nth-child(2) { width: 15%; }
            .invoice-table th:nth-child(3) { width: 25%; }
        ";
        
        // User Email
        // ----------------------------------------------------------------------------------------
        $user_subject = "Your Order #{$invoice_number} is now {$status_formatted}";
        $user_html = "
            <html>
            <head>
                <title>Order {$status_formatted} - Invoice #{$invoice_number}</title>
                <style>{$email_css}</style>
            </head>
            <body>
                <div class='invoice-header'>
                    <div class='company-name'>Firefly Collective</div>
                    <div>Order Invoice #{$invoice_number}</div>
                    <div class='status-badge' style='background-color: {$status_color};'>Status: {$status_formatted}</div>
                </div>
                
                <div class='invoice-body'>
                    <div class='invoice-details'>
                        <strong>Order Date:</strong> {$order_date}<br>
                        <strong>Order ID:</strong> {$first_item['orderID']}<br>
                        <strong>Service Type:</strong> {$service_type}
                    </div>
                    
                    <div class='customer-details'>
                        <strong>Customer:</strong> {$user->display_name}<br>
                        <strong>Email:</strong> {$user->user_email}
                    </div>
                    
                    <table class='invoice-table'>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$items_html}
                            <tr class='total-row'>
                                <td></td>
                                <td>Order Total:</td>
                                <td>{$formatted_order_total}</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <p>Thank you for your order! If you have any questions, please contact us.</p>
                </div>
                
                <div class='invoice-footer'>
                    <div class='contact-info'>
                        Firefly Collective<br>
                        Email: <a href='mailto:donotreply@fireflycollective.org'>donotreply@fireflycollective.org</a>
                    </div>
                </div>
            </body>
            </html>
        ";
        $user_sent = send_html_mail($user->user_email, $user_subject, $user_html);
        
        // Admin Email
        // ----------------------------------------------------------------------------------------
        $admin_subject = "Order #{$invoice_number} from {$user->display_name} is now {$status_formatted}";
        $admin_html = "
            <html>
            <head>
                <title>Order {$status_formatted} - Invoice #{$invoice_number}</title>
                <style>{$email_css}</style>
            </head>
            <body>
                <div class='invoice-header'>
                    <div class='company-name'>Firefly Collective</div>
                    <div>Order Invoice #{$invoice_number}</div>
                    <div class='status-badge' style='background-color: {$status_color};'>Status: {$status_formatted}</div>
                </div>
                
                <div class='invoice-body'>
                    <div class='invoice-details'>
                        <strong>Order Date:</strong> {$order_date}<br>
                        <strong>Order ID:</strong> {$first_item['orderID']}<br>
                        <strong>Service Type:</strong> {$service_type}
                    </div>
                    
                    <div class='customer-details'>
                        <strong>Customer Information:</strong><br>
                        <strong>Name:</strong> {$user->display_name}<br>
                        <strong>Email:</strong> {$user->user_email}<br>
                        <strong>User ID:</strong> {$first_item['userId']}
                    </div>
                    
                    <table class='invoice-table'>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$items_html}
                            <tr class='total-row'>
                                <td></td>
                                <td>Order Total:</td>
                                <td>{$formatted_order_total}</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <p><strong>Order Status Change:</strong> Order is now <span style='color: {$status_color}; font-weight: bold;'>{$status_formatted}</span></p>
                </div>
                
                <div class='invoice-footer'>
                    <div class='contact-info'>
                        This is an automated notification from the order management system.
                    </div>
                </div>
            </body>
            </html>
        ";
        $admin_sent = send_html_mail(NULL, $admin_subject, $admin_html, true);

        return ($user_sent && $admin_sent);
    }