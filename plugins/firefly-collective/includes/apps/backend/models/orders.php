<?php

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
        
        // Build placeholders for the query
        $placeholders = implode(',', array_fill(0, count($order_ids), '%s'));
        
        $query = $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ffc_orders WHERE orderID IN ($placeholders)",
            $order_ids
        );
        
        $result = $wpdb->query($query);
        
        if ($result === false) {
            return [
                'success' => false,
                'message' => 'Failed to delete orders: ' . $wpdb->last_error
            ];
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