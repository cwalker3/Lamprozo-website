<?php

    // plugin/models/submissions.php

    if (!defined('ABSPATH')) { exit; }

    // Dashboard view
    function firefly_collective_submissions_dashboard() {
        $plugin_base = dirname(dirname(dirname(dirname(__FILE__)))) . '/';
        $view_path = $plugin_base . 'templates/default/views/submissions.php';

        if (file_exists($view_path)) {
            require_once $view_path;
        } else {
            wp_die('The submissions view file could not be found at: ' . $view_path, 'File Not Found', array('response' => 404));
        }
    }

    // Enqueue styles and scripts
    function enqueue_submissions_styles_and_scripts($hook) {
        if ($hook !== 'toplevel_page_submissions') {
            return;
        }

        $plugin_path = plugin_dir_url(dirname(dirname(__FILE__)));
        $template_name = FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE;
        $unique_id = uniqid();
        $nonce = wp_create_nonce('wp_rest');
        $api_url = get_rest_url(null, 'custom-api/v1/');

        // Enqueue Vue
        wp_enqueue_script('vue-js', VUE_REMOTE_CORE, array(), null, true);

        // Enqueue CSS & JS
        wp_enqueue_style('submissions-css', $plugin_path . $template_name . '/assets/css/submissions.css', array(), $unique_id);
        wp_enqueue_script('submissions-js', $plugin_path . $template_name . '/assets/js/submissions.js', array(), $unique_id, true);

        // Localize data
        wp_localize_script('submissions-js', 'submissionsData', array(
            'nonce'   => $nonce,
            'api_url' => $api_url
        ));
    }
    add_action('admin_enqueue_scripts', 'enqueue_submissions_styles_and_scripts');

    // Menu registration
    function firefly_collective_add_submissions_link() {
        add_menu_page(
            'Submissions',
            'Submissions',
            'manage_options',
            'submissions',
            'firefly_collective_submissions_dashboard',
            'dashicons-email-alt'
        );
    }
    add_action('admin_menu', 'firefly_collective_add_submissions_link');

    // Create table
    function firefly_collective_init_submissions() {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_submissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_type VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            company VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            plan VARCHAR(100) DEFAULT NULL,
            message TEXT NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'new',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_form_type (form_type),
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) {$collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    firefly_collective_init_submissions();

    // Drop table (available for plugin teardown scripts)
    function firefly_collective_terminate_submissions() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffc_submissions");
    }

    // =========================================================================
    // REST Routes
    // =========================================================================

    add_action('rest_api_init', function() {
        $admin_permission = function() { return current_user_can('manage_options'); };

        register_rest_route('custom-api/v1', '/get-submissions', array(
            'methods'             => 'GET',
            'callback'            => 'firefly_collective_get_submissions',
            'permission_callback' => $admin_permission
        ));

        register_rest_route('custom-api/v1', '/update-submission-status', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_update_submission_status',
            'permission_callback' => $admin_permission
        ));

        register_rest_route('custom-api/v1', '/delete-submission', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_delete_submission',
            'permission_callback' => $admin_permission
        ));

        register_rest_route('custom-api/v1', '/bulk-delete-submissions', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_bulk_delete_submissions',
            'permission_callback' => $admin_permission
        ));

        register_rest_route('custom-api/v1', '/bulk-update-submissions-status', array(
            'methods'             => 'POST',
            'callback'            => 'firefly_collective_bulk_update_submissions_status',
            'permission_callback' => $admin_permission
        ));
    });

    // =========================================================================
    // CRUD Handlers
    // =========================================================================

    function firefly_collective_get_submissions($request) {
        global $wpdb;

        $page           = intval($request->get_param('page') ?: 1);
        $per_page       = intval($request->get_param('per_page') ?: 20);
        $form_type      = sanitize_text_field($request->get_param('form_type') ?: '');
        $status         = sanitize_text_field($request->get_param('status') ?: '');
        $search         = sanitize_text_field($request->get_param('search') ?: '');
        $date_from      = sanitize_text_field($request->get_param('date_from') ?: '');
        $date_to        = sanitize_text_field($request->get_param('date_to') ?: '');
        $sort_field     = sanitize_text_field($request->get_param('sort_field') ?: 'created_at');
        $sort_direction = sanitize_text_field($request->get_param('sort_direction') ?: 'desc');

        $allowed_sort = array('id', 'form_type', 'name', 'email', 'status', 'created_at');
        if (!in_array($sort_field, $allowed_sort)) {
            $sort_field = 'created_at';
        }
        $sort_direction = strtoupper($sort_direction) === 'ASC' ? 'ASC' : 'DESC';

        $where = array();
        $params = array();

        if ($form_type) {
            $where[] = 'form_type = %s';
            $params[] = $form_type;
        }
        if ($status) {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        if ($search) {
            $where[] = '(name LIKE %s OR email LIKE %s OR company LIKE %s OR message LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($date_from) {
            $where[] = 'created_at >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $where[] = 'created_at <= %s';
            $params[] = $date_to . ' 23:59:59';
        }

        $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}ffc_submissions {$where_clause}";
        if ($params) {
            $total = $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total = $wpdb->get_var($count_sql);
        }

        $offset = ($page - 1) * $per_page;
        $query_sql = "SELECT * FROM {$wpdb->prefix}ffc_submissions {$where_clause} ORDER BY {$sort_field} {$sort_direction} LIMIT %d OFFSET %d";
        $query_params = array_merge($params, array($per_page, $offset));
        $submissions = $wpdb->get_results($wpdb->prepare($query_sql, $query_params), ARRAY_A);

        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}ffc_submissions GROUP BY status",
            ARRAY_A
        );
        $status_counts = array();
        foreach ($counts as $row) {
            $status_counts[$row['status']] = intval($row['count']);
        }

        return array(
            'success'       => true,
            'submissions'   => $submissions,
            'total'         => intval($total),
            'page'          => $page,
            'per_page'      => $per_page,
            'status_counts' => $status_counts
        );
    }

    function firefly_collective_update_submission_status($request) {
        global $wpdb;

        $id     = intval($request->get_param('id'));
        $status = sanitize_text_field($request->get_param('status'));

        $valid = array('new', 'read', 'replied', 'archived');
        if (!in_array($status, $valid)) {
            return new WP_Error('invalid_status', 'Invalid status value', array('status' => 400));
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'ffc_submissions',
            array('status' => $status),
            array('id' => $id),
            array('%s'),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update submission', array('status' => 500));
        }

        return array('success' => true);
    }

    function firefly_collective_delete_submission($request) {
        global $wpdb;

        $id = intval($request->get_param('id'));

        $result = $wpdb->delete(
            $wpdb->prefix . 'ffc_submissions',
            array('id' => $id),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete submission', array('status' => 500));
        }

        return array('success' => true);
    }

    function firefly_collective_bulk_delete_submissions($request) {
        global $wpdb;

        $ids = $request->get_param('ids');
        if (!is_array($ids) || empty($ids)) {
            return new WP_Error('invalid_ids', 'No IDs provided', array('status' => 400));
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}ffc_submissions WHERE id IN ({$placeholders})",
                $ids
            )
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete submissions', array('status' => 500));
        }

        return array('success' => true, 'deleted' => $result);
    }

    function firefly_collective_bulk_update_submissions_status($request) {
        global $wpdb;

        $ids    = $request->get_param('ids');
        $status = sanitize_text_field($request->get_param('status'));

        $valid = array('new', 'read', 'replied', 'archived');
        if (!in_array($status, $valid)) {
            return new WP_Error('invalid_status', 'Invalid status value', array('status' => 400));
        }

        if (!is_array($ids) || empty($ids)) {
            return new WP_Error('invalid_ids', 'No IDs provided', array('status' => 400));
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}ffc_submissions SET status = %s WHERE id IN ({$placeholders})",
                array_merge(array($status), $ids)
            )
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update submissions', array('status' => 500));
        }

        return array('success' => true, 'updated' => $result);
    }
