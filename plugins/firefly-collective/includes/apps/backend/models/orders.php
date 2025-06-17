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

    function create_ffc_orders_table_if_not_exist() {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            orderID VARCHAR(36) DEFAULT NULL,
            payment_intent_id VARCHAR(255) NULL,
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
            
            subscription_id VARCHAR(255) NULL,
            subscription_status VARCHAR(50) NULL,
            subscription_renewal TINYINT(1) DEFAULT 0,
            subscription_period_start DATETIME NULL,
            subscription_period_end DATETIME NULL,
            subscription_current_period_start DATETIME NULL,
            subscription_current_period_end DATETIME NULL,
            subscription_cancelled_at DATETIME NULL,

            PRIMARY KEY(id),
            INDEX idx_order (orderID),
            INDEX idx_user (userId),
            INDEX idx_feature (featureId),
            INDEX idx_option (optionId),
            INDEX idx_status (status),
            INDEX idx_created (createdAt),
            INDEX idx_subscription_id (subscription_id)
        ) {$collate};";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }


    function firefly_collective_place_order($request) {
        global $wpdb;
        if ( ! is_user_logged_in() ) {
            return new WP_Error('not_logged_in', 'You must be logged in to place an order.', array('status' => 401));
        }
        $data = $request->get_json_params();
        
        $is_batch = isset($data['items']) && is_array($data['items']);
        $order_items = $is_batch ? $data['items'] : [$data];
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('not_logged_in', 'You must be logged in to place an order.', array('status' => 401));
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
            
            $price_data = calculate_server_price($feature_id, $option_id, $addon_ids, $price_option_index, $quantity);
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'ffc_orders',
                array(
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
                    'status'             => 'pending',
                    'createdAt'          => current_time('mysql')
                )
            );
            
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
        
        return array(
            'success'         => true,
            'orderID'         => $order_id,
            'records'         => $inserted_records,
            'totalOrderValue' => $total_order_value
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
            SELECT * FROM {$wpdb->prefix}ffc_orders
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
            "SELECT id, optionId, addonName FROM {$wpdb->prefix}ffc_addons",
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

    function firefly_collective_orders_email($order_id, $new_status = '', $order_data = null) {
        global $wpdb;
        
        if ($order_data !== null) {
            $order_items = $order_data;
        } else {
            $order_items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_orders WHERE orderID = %s ORDER BY id ASC",
                    $order_id
                ),
                ARRAY_A
            );
        }
        
        if (empty($order_items)) {
            return false;
        }
        
        $first_item = $order_items[0];
        
        $user = get_userdata($first_item['userId']);
        if (!$user) {
            return false;
        }
        
        $status = !empty($new_status) ? $new_status : $first_item['status'];
        $status_formatted = ucfirst($status);
        $status_color = '';
        
        switch ($status) {
            case 'completed':
            case 'paid':        // if you ever use “paid” as distinct
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
        
        // Compute “order total”:
        $order_total = 0;
        foreach ($order_items as $item) {
            $order_total += floatval($item['totalPrice']);
        }
        $formatted_order_total = '$' . number_format($order_total, 2);
        
        // Sum up all item‐level discounts (we’ll use this under “Order Total”) ──
        $total_discount = 0;
        foreach ($order_items as $item) {
            $item_discount = floatval($item['totalPriceDiscount']);
            if ($item_discount > 0) {
                $total_discount += $item_discount;
            }
        }
        $formatted_discount = '$' . number_format($total_discount, 2);
        
        $service_type = '';
        if (!empty($first_item['userData'])) {
            $user_data = json_decode($first_item['userData'], true);
            if (isset($user_data['dineInTakeOutDelivery'])) {
                $service_types = array('Dine In', 'Take Out', 'Delivery');
                $selected_type = intval($user_data['dineInTakeOutDelivery']);
                if (isset($service_types[$selected_type])) {
                    $service_type = $service_types[$selected_type];
                }
            }
        }
        
        // rRebuild $items_html so that prices/right‐align, item column is first and wide,
        // “Addons:” appears under that same first column, not in its own column. ──
        $items_html = '';
        foreach ($order_items as $item) {
            // Fetch feature + option as before
            $feature = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_features WHERE id = %d",
                    intval($item['featureId'])
                ),
                ARRAY_A
            );
            $option = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ffc_options WHERE id = %d",
                    intval($item['optionId'])
                ),
                ARRAY_A
            );
            if (!$feature || !$option) {
                continue;
            }
            
            // If “price options” exist
            $size_label = '';
            $base_price = 0;
            if (!empty($option['pricingType']) && $option['pricingType'] === 'price options') {
                $price_options = json_decode($option['priceOptions'], true);
                if (isset($price_options['types']) && isset($item['priceSelected'])) {
                    $sel = intval($item['priceSelected']);
                    if (isset($price_options['types'][$sel])) {
                        $size_label = $price_options['types'][$sel]['label'];
                        $base_price = floatval($price_options['types'][$sel]['price']);
                    }
                }
            }
            
            $formatted_item_price = '$' . number_format(floatval($item['totalPrice']), 2);
            
            // Decode any discount info JSON
            $info = array();
            if (!empty($item['priceDiscountsInfo'])) {
                $decoded = json_decode($item['priceDiscountsInfo'], true);
                if (is_array($decoded)) {
                    $info = $decoded;
                }
            }
            
            // 1) Print the “Item - Option (size)” + Quantity + Price row
            $items_html .= "
                <tr>
                    <td style='width:70%; vertical-align: top;'>
                        <strong>{$feature['featureName']} - {$option['optionName']}" .
                            (!empty($size_label) ? " ({$size_label})" : "") .
                        "</strong>
                    </td>
                    <td style='width:15%; text-align:center; vertical-align: top;'>
                        <strong>" . intval($item['quantity']) . "</strong>
                    </td>
                    <td style='width:15%; text-align:right; vertical-align: top;'>
                        <strong>{$formatted_item_price}</strong>
                    </td>
                </tr>
            ";
            
            // 2) Option‐level discount (if present):
            if (!empty($info['option']) && trim($info['option']) !== '') {
                $opt_disc = esc_html($info['option']);
                $items_html .= "
                    <tr>
                        <td colspan='3' style='font-size:0.85em; font-style:italic; color:#0066cc; padding-left:12px;'>
                            - {$opt_disc}
                        </td>
                    </tr>
                ";
            }
            
            // 3) “Addons:” under the same first column, not a separate column
            $decoded_addon_ids = array();
            if (!empty($item['addonIds'])) {
                $decoded_addon_ids = json_decode($item['addonIds'], true);
                if (!is_array($decoded_addon_ids)) {
                    $decoded_addon_ids = array();
                }
            }
            
            if (!empty($decoded_addon_ids)) {
                $addon_names = array();
                foreach ($decoded_addon_ids as $aid) {
                    $aid = intval($aid);
                    $addon_row = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}ffc_addons WHERE id = %d",
                            $aid
                        ),
                        ARRAY_A
                    );
                    if ($addon_row) {
                        $addon_names[] = $addon_row['addonName'];
                    }
                }
                $join_names = implode(', ', $addon_names);
                $items_html .= "
                    <tr>
                        <td colspan='3' style='padding-left:10px;'>
                            <em>Addons:</em> {$join_names}
                        </td>
                    </tr>
                ";
            } else {
                $items_html .= "
                    <tr>
                        <td colspan='3' style='padding-left:10px;'>
                            <em>Addons:</em> None
                        </td>
                    </tr>
                ";
            }
            
            // 4) Addon‐level discounts (if any)
            if (!empty($info['addons']) && is_array($info['addons'])) {
                $addon_discounts = array_filter($info['addons'], function($x) {
                    return (is_string($x) && trim($x) !== '');
                });
                if (!empty($addon_discounts)) {
                    $join_addon_disc = implode(', ', $addon_discounts);
                    $items_html .= "
                        <tr>
                            <td colspan='3' style='font-size:0.85em; font-style:italic; color:#0066cc; padding-left:12px;'>
                                - {$join_addon_disc}
                            </td>
                        </tr>
                    ";
                }
            }
            
            // 5) Spacer row between items
            $items_html .= "
                <tr class='item-spacer'><td colspan='3'>&nbsp;</td></tr>
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
        
        //
        // Build the dynamic “thank‐you” or “if you have questions” message ──
        //
        if ($status === 'completed' || $status === 'paid') {
            $closing_paragraph = "<p>Thank you for your order! If you have any questions, please contact us.</p>";
        } else {
            // for cancelled / refunded / deleted, no “thank you,” just “please contact us.”
            $closing_paragraph = "<p>If you have any questions, please contact us.</p>";
        }
        //
        // ── end CHANGED ──
        //
        
        // Update the <table> so that prices are right‐aligned, item column is wide,
        // and print the single “order‐level” discount under “Order Total.” ──
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
                        <strong>Customer:</strong> {$user->display_name}<br>
                        <strong>Email:</strong> {$user->user_email}
                    </div>
                    
                    <table class='invoice-table'>
                        <thead>
                            <tr>
                                <th style='width:70%;'>Item</th>
                                <th style='width:15%; text-align:center;'>Quantity</th>
                                <th style='width:15%; text-align:right;'>Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$items_html}
                        </tbody>
                        <tfoot>
                            <tr class='total-row'>
                                <td></td>
                                <td style='text-align:right;'>Order Total:</td>
                                <td style='text-align:right;'>{$formatted_order_total}</td>
                            </tr>"
                            . (
                                $total_discount > 0
                                ? "
                                <tr>
                                    <td colspan='2'></td>
                                    <td style='font-size:0.85em; font-style:italic; color:#0066cc; text-align:right;'>
                                        - {$formatted_discount} discount
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
                        Firefly Collective<br>
                        Email: <a href='mailto:donotreply@fireflycollective.org'>donotreply@fireflycollective.org</a>
                    </div>
                </div>
            </body>
            </html>
        ";
        $user_sent = send_html_mail($user->user_email, $user_subject, $user_html);
        
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
                        <strong>Name:</strong> {$user->display_name}<br>
                        <strong>Email:</strong> {$user->user_email}<br>
                        <strong>User ID:</strong> {$first_item['userId']}
                    </div>
                    
                    <table class='invoice-table'>
                        <thead>
                            <tr>
                                <th style='width:70%;'>Item</th>
                                <th style='width:15%; text-align:center;'>Quantity</th>
                                <th style='width:15%; text-align:right;'>Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$items_html}
                        </tbody>
                        <tfoot>
                            <tr class='total-row'>
                                <td></td>
                                <td style='text-align:right;'>Order Total:</td>
                                <td style='text-align:right;'>{$formatted_order_total}</td>
                            </tr>"
                            . (
                                $total_discount > 0
                                ? "
                                <tr>
                                    <td colspan='2'></td>
                                    <td style='font-size:0.85em; font-style:italic; color:#0066cc; text-align:right;'>
                                        - {$formatted_discount} discount
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
        $admin_sent = send_html_mail(NULL, $admin_subject, $admin_html, true);

        return ($user_sent && $admin_sent);
    }
