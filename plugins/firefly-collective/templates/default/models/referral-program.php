<?php
    
    // plugin/models/referral-program.php

    function firefly_collective_add_referral_program_link() {
        add_menu_page(
            'Referral Program',
            'Referral Program',
            'manage_options',
            'referral-program',
            'firefly_collective_referral_program_dashboard',
            'dashicons-megaphone'
        );
    }
    add_action( 'admin_menu', 'firefly_collective_add_referral_program_link' );

    function create_referrals_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'referrals';
        $charset_collate = $wpdb->get_charset_collate();

        // Includes the 'description' column
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            contact_name varchar(255) NOT NULL,
            company_name varchar(255) NOT NULL,
            phone varchar(50) NOT NULL,
            email varchar(100) NOT NULL,
            description text NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    add_action('after_switch_theme', 'create_referrals_table');

    /**
     * Grab all rows from the referrals table.
     *
     * @return array
     */
    function get_referrals() {
        global $wpdb;
        $table = $wpdb->prefix . 'referrals';
        $rows  = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
        return $rows ?: [];
    }

    /**
     * Enqueue your styles, scripts, and localize the referral data.
     */
    function init_referral_program_backend_data( $hook ) {
        if ( ! in_array( $hook, [ 'toplevel_page_referral-program', 'user-edit.php', 'profile.php' ], true ) ) {
            return;
        }

        $plugin_root_url = dirname( plugin_dir_url( __FILE__ ) ) . '/';
        $unique_id       = uniqid();
        $theme_path      = get_template_directory();
        $theme_path_uri  = get_template_directory_uri();

        wp_enqueue_style( 'custom-properties-css', $theme_path . '/assets/css/custom-properties.css', [], $unique_id );
        wp_enqueue_style( 'referral-program-css',    $plugin_root_url . 'assets/css/referral-program.css', [], $unique_id );
        wp_enqueue_script( 'referral-program-js',    $plugin_root_url . 'assets/js/referral-program.js', [], $unique_id, true );

        $nonce     = wp_create_nonce( 'wp_rest' );
        $api_url   = get_rest_url( null, 'custom-api/v1/' );

        global $referrals;
        $referrals = get_referrals();

        wp_localize_script(
            'referral-program-js',
            'referralProgramData',
            [
                'nonce'     => $nonce,
                'api_url'   => $api_url,
                'referrals' => $referrals,
            ]
        );
    }
    add_action( 'admin_enqueue_scripts', 'init_referral_program_backend_data' );

    /**
     * Load the admin view.
     */
    function firefly_collective_referral_program_dashboard() {
        $plugin_root = dirname( plugin_dir_path( __FILE__ ) );
        $view_path   = $plugin_root . '/views/referral-program.php';

        if ( file_exists( $view_path ) ) {
            require_once $view_path;
        } else {
            wp_die( 'The referral program view file could not be found.', 'File Not Found', [ 'response' => 404 ] );
        }
    }


    /**
     * REST callback: delete a referral.
     */
    function cancel_referral_submission( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_json_params();
        $id     = intval( $params['submissionId'] ?? 0 );

        if ( ! $id ) {
            return new WP_Error( 'invalid_id', 'Invalid referral ID', [ 'status' => 400 ] );
        }

        $table = $wpdb->prefix . 'referrals';

        // Fetch the row BEFORE deleting so we can email about it
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Referral not found', [ 'status' => 404 ] );
        }

        $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        if ( false === $deleted ) {
            return new WP_Error( 'db_error', 'Could not delete referral', [ 'status' => 500 ] );
        }

        // ——— Send emails ———
        $admin_email = get_option( 'admin_email' );
        $user_obj    = get_user_by( 'ID', intval( $row['user_id'] ) );
        $user_email  = $user_obj ? $user_obj->user_email : '';
        $company     = sanitize_text_field( $row['company_name'] );
        $submitted   = esc_html( $row['created_at'] );
        $date        = current_time( 'F j, Y g:i a' );

        // Email to referring user
        if ( $user_email ) {
            $subject = "Your referral #{$id} has been cancelled";
            $message = "Hello,\n\n"
                    . "Your referral submission for “{$company}” (ID: {$id}, submitted {$submitted}) "
                    . "was cancelled by the admin on {$date}.\n\n"
                    . "If you have any questions, please reply to this email.";
            wp_mail( $user_email, $subject, $message );
        }

        // Email to site admin
        if ( $admin_email ) {
            $subject = "Referral #{$id} cancelled";
            $message = "Referral submission ID {$id} for company “{$company}” (user ID {$row['user_id']}) "
                    . "was cancelled on {$date}.";
            wp_mail( $admin_email, $subject, $message );
        }
        // ——————————————————

        return rest_ensure_response( [ 'success' => true, 'id' => $id ] );
    }

    /**
     * REST callback: mark a referral as paid.
     */
    function mark_referral_as_paid( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_json_params();
        $id     = intval( $params['submissionId'] ?? 0 );

        if ( ! $id ) {
            return new WP_Error( 'invalid_id', 'Invalid referral ID', [ 'status' => 400 ] );
        }

        $table = $wpdb->prefix . 'referrals';

        $updated = $wpdb->update(
            $table,
            [ 'status' => 'paid' ],
            [ 'id'     => $id ],
            [ '%s'     ],
            [ '%d'     ]
        );
        if ( false === $updated ) {
            return new WP_Error( 'db_error', 'Could not update referral', [ 'status' => 500 ] );
        }

        // Fetch updated row for the response & email
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        // ——— Send emails ———
        $admin_email = get_option( 'admin_email' );
        $user_obj    = get_user_by( 'ID', intval( $row['user_id'] ) );
        $user_email  = $user_obj ? $user_obj->user_email : '';
        $company     = sanitize_text_field( $row['company_name'] );
        $submitted   = esc_html( $row['created_at'] );
        $date        = current_time( 'F j, Y g:i a' );

        // Email to referring user
        if ( $user_email ) {
            $subject = "Your referral #{$id} has been marked as paid";
            $message = "Hello,\n\n"
                    . "Your referral submission for “{$company}” (ID: {$id}, submitted {$submitted}) "
                    . "was marked as PAID on {$date}.\n\n"
                    . "Thank you for your referral!";
            wp_mail( $user_email, $subject, $message );
        }

        // Email to site admin
        if ( $admin_email ) {
            $subject = "Referral #{$id} marked as paid";
            $message = "Referral submission ID {$id} for company “{$company}” (user ID {$row['user_id']}) "
                    . "was marked as PAID on {$date}.";
            wp_mail( $admin_email, $subject, $message );
        }
        // ——————————————————

        return rest_ensure_response( [ 'success' => true, 'referral' => $row ] );
    }

    // ─── Add “Referral Stage” dropdown to user profile ──────────────────────────

    /**
     * Show the dropdown on the user profile / edit user screens.
     */
    function firefly_collective_referral_stage_field( $user ) {
        $stage = get_user_meta( $user->ID, 'referral-program-stage', true );
        if ( ! $stage || '0' === $stage ) {
            $stage = '0';
        }
        ?>
        <table class="form-table">
            <tr>
                <th><label for="referral-program-stage"><?php _e( 'Referral Stage', 'firefly_collective' ); ?></label></th>
                <td>
                    <select name="referral-program-stage" id="referral-program-stage">
                        <option value="0" <?php selected( $stage, '0' ); ?>><?php _e( 'None', 'firefly_collective' ); ?></option>
                        <option value="1" <?php selected( $stage, '1' ); ?>><?php _e( 'Initialized', 'firefly_collective' ); ?></option>
                        <option value="signed" <?php selected( $stage, 'signed' ); ?>><?php _e( 'Signed', 'firefly_collective' ); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }
    add_action( 'show_user_profile', 'firefly_collective_referral_stage_field' );
    add_action( 'edit_user_profile', 'firefly_collective_referral_stage_field' );

    /**
     * Save the dropdown value when the profile is updated.
     */
    function firefly_collective_save_referral_stage( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }
        if ( isset( $_POST['referral-program-stage'] ) ) {
            $stage = sanitize_text_field( $_POST['referral-program-stage'] );
            if ( '0' === $stage ) {
                delete_user_meta( $user_id, 'referral-program-stage' );
            } else {
                update_user_meta( $user_id, 'referral-program-stage', $stage );
            }
        }
    }
    add_action( 'personal_options_update', 'firefly_collective_save_referral_stage' );
    add_action( 'edit_user_profile_update', 'firefly_collective_save_referral_stage' );

    function delete_referrals_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'referrals';
        $sql = "DROP TABLE IF EXISTS {$table_name}";
        $wpdb->query($sql);
    }

    // =========================================================================
    // REST routes — registered inline so this model ships its own surface;
    // no edits needed to the framework rest.php to enable the admin actions.
    // =========================================================================
    add_action( 'rest_api_init', function () {
        $admin_only = function () { return current_user_can( 'manage_options' ); };

        register_rest_route( 'custom-api/v1', '/cancel-referral-submission', array(
            'methods'             => 'POST',
            'callback'            => 'cancel_referral_submission',
            'permission_callback' => $admin_only,
        ) );

        register_rest_route( 'custom-api/v1', '/mark-referral-as-paid', array(
            'methods'             => 'POST',
            'callback'            => 'mark_referral_as_paid',
            'permission_callback' => $admin_only,
        ) );
    } );
