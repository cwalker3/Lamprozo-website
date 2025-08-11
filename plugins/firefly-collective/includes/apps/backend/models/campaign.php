<?php

    // plugin/models/campaign.php

    // Dashboard view
    function firefly_collective_campaign_dashboard() {
        $plugin_root = dirname(plugin_dir_path(__FILE__));
        $view_path   = $plugin_root . '/views/campaign.php';
        if (file_exists($view_path)) {
            require_once $view_path;
        } else {
            wp_die('The campaign view file could not be found.', 'File Not Found', array('response' => 404));
        }
    }

    // Enqueue styles and scripts
    function enqueue_campaign_styles_and_scripts($hook) {
        if ($hook !== 'toplevel_page_campaign') {
            return;
        }

        $plugin_root_url = dirname(plugin_dir_url(__FILE__)) . '/';
        $unique_id       = uniqid();
        $nonce   = wp_create_nonce('wp_rest');
        $api_url = get_rest_url(null, 'custom-api/v1/');
        $theme_path = get_template_directory_uri();
        $auth_id = $_COOKIE['auth_id'];
        
        global $features_options_addons;
        // Get features for configuration
        $features_options_addons = get_features_options_addons();

        // Main JS
        wp_enqueue_script('main-js', $theme_path . '/assets/js/main.js', array(), $unique_id, true);
        wp_localize_script('main-js', 'myApi', array(
            'themePath' => $theme_path
        ));

        // Enqueue Vue
        wp_enqueue_script('vue-js', VUE_REMOTE_CORE, array(), null, true);

        // Enqueue CSS & JS
        wp_enqueue_style('campaign-css', $plugin_root_url . 'assets/css/campaign.css', array(), $unique_id);
        wp_enqueue_script('main-js', $theme_path . '/assets/js/main.js', array(), $unique_id, true);
        wp_enqueue_script('campaign-js', $plugin_root_url . 'assets/js/campaign.js', array(), $unique_id, true);

        // Localize into JS
        $api_url = get_rest_url(null, 'custom-api/v1/');
        wp_localize_script('campaign-js', 'campaignData', array(
            'nonce'              => $nonce,
            'api_url'            => $api_url
        ));
    }
    add_action('admin_enqueue_scripts', 'enqueue_campaign_styles_and_scripts');

    // Create mysql table for campaigns
    function firefly_collective_init_campaigns() {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_campaigns (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            token VARCHAR(255) NOT NULL UNIQUE,
            start_date DATETIME NOT NULL,
            end_date DATETIME NULL,
            unlimited BOOLEAN DEFAULT FALSE,
            features_config JSON,
            preselect_config JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_token (token),
            INDEX idx_dates (start_date, end_date)
        ) {$collate};";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
    add_action( 'plugins_loaded', 'firefly_collective_init_campaigns' );

    // Drop table
    function firefly_collective_terminate_campaigns() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffc_campaigns");
    }

    // Add QR code generation function
    function generate_campaign_qr_code($token) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        
        // Create campaigns directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $campaigns_dir = $upload_dir['basedir'] . '/campaigns';
        if (!file_exists($campaigns_dir)) {
            wp_mkdir_p($campaigns_dir);
        }
        
        // Include QR code library (we'll use PHP QR Code)
        if (!class_exists('QRcode')) {
            // Download from: https://sourceforge.net/projects/phpqrcode/
            // For now, use a simple alternative with error logging
            $dashboard_url = home_url('/dashboard/' . $token);
            
            // Try multiple QR code APIs as fallback
            $apis = [
                'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($dashboard_url),
                'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($dashboard_url),
                'https://qr-generator.qrcode.studio/qr/custom?data=' . urlencode($dashboard_url) . '&size=300'
            ];
            
            foreach ($apis as $api_url) {
                $response = wp_remote_get($api_url, array('timeout' => 10));
                
                if (!is_wp_error($response)) {
                    $qr_image = wp_remote_retrieve_body($response);
                    if (!empty($qr_image)) {
                        $qr_path = $campaigns_dir . '/' . $token . '.png';
                        $result = file_put_contents($qr_path, $qr_image);
                        
                        if ($result !== false) {
                            return $upload_dir['baseurl'] . '/campaigns/' . $token . '.png';
                        } else {
                            error_log('Failed to save QR code for token: ' . $token . ' - Check file permissions');
                        }
                        break;
                    }
                } else {
                    error_log('QR API request failed: ' . $response->get_error_message());
                }
            }
        }
        
        error_log('Failed to generate QR code for token: ' . $token);
        return false;
    }

    // Campaign Management Functions
    function firefly_collective_get_campaigns($request) {
        global $wpdb;
        
        try {
            $campaigns = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}ffc_campaigns ORDER BY created_at DESC",
                ARRAY_A
            );
            
            // Add QR code URLs and decode JSON fields, convert dates to local timezone
            foreach ($campaigns as &$campaign) {
                $upload_dir = wp_upload_dir();
                $qr_file = $upload_dir['basedir'] . '/campaigns/' . $campaign['token'] . '.png';
                if (file_exists($qr_file)) {
                    $campaign['qr_url'] = $upload_dir['baseurl'] . '/campaigns/' . $campaign['token'] . '.png';
                }
                
                // Convert UTC dates to local timezone for display
                $campaign['start_date'] = convert_utc_to_local($campaign['start_date']);
                if (!empty($campaign['end_date'])) {
                    $campaign['end_date'] = convert_utc_to_local($campaign['end_date']);
                }
                
                $campaign['features_config'] = json_decode($campaign['features_config'], true);
                $campaign['preselect_config'] = json_decode($campaign['preselect_config'], true);
                $campaign['dashboard_url'] = home_url('/dashboard/' . $campaign['token']);
            }
            
            return array('success' => true, 'campaigns' => $campaigns);
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    function firefly_collective_create_campaign($request) {
        global $wpdb;
        
        $name = sanitize_text_field($request->get_param('name'));
        $start_date_local = sanitize_text_field($request->get_param('start_date'));
        $end_date_local = $request->get_param('end_date') ? sanitize_text_field($request->get_param('end_date')) : null;
        $unlimited = $request->get_param('unlimited') ? 1 : 0;
        $features_config = $request->get_param('features_config');
        $preselect_config = $request->get_param('preselect_config');
        
        try {
            // Convert local datetime to UTC for database storage
            $start_date_utc = convert_local_to_utc($start_date_local);
            $end_date_utc = $end_date_local ? convert_local_to_utc($end_date_local) : null;
            
            // Insert campaign
            $result = $wpdb->insert(
                $wpdb->prefix . 'ffc_campaigns',
                array(
                    'name' => $name,
                    'token' => '', // Will update after getting ID
                    'start_date' => $start_date_utc,
                    'end_date' => $end_date_utc,
                    'unlimited' => $unlimited,
                    'features_config' => json_encode($features_config),
                    'preselect_config' => json_encode($preselect_config)
                ),
                array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );
            
            if ($result === false) {
                throw new Exception('Failed to create campaign');
            }
            
            $campaign_id = $wpdb->insert_id;
            
            // Generate encrypted token
            $token = encrypt_with_auth_key($campaign_id);
            $token = preg_replace('/[^a-zA-Z0-9]/', '', $token); // Clean token for URL use
            $token = substr($token, 0, 32); // Limit length
            
            // Update with token
            $wpdb->update(
                $wpdb->prefix . 'ffc_campaigns',
                array('token' => $token),
                array('id' => $campaign_id),
                array('%s'),
                array('%d')
            );
            
            // Generate QR code
            generate_campaign_qr_code($token);
            
            return array('success' => true, 'campaign_id' => $campaign_id, 'token' => $token);
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    function firefly_collective_update_campaign($request) {
        global $wpdb;
        
        $id = intval($request->get_param('id'));
        $name = sanitize_text_field($request->get_param('name'));
        $start_date_local = sanitize_text_field($request->get_param('start_date'));
        $end_date_local = $request->get_param('end_date') ? sanitize_text_field($request->get_param('end_date')) : null;
        $unlimited = $request->get_param('unlimited') ? 1 : 0;
        $features_config = $request->get_param('features_config');
        $preselect_config = $request->get_param('preselect_config');
        
        try {
            // Convert local datetime to UTC for database storage
            $start_date_utc = convert_local_to_utc($start_date_local);
            $end_date_utc = $end_date_local ? convert_local_to_utc($end_date_local) : null;
            
            $result = $wpdb->update(
                $wpdb->prefix . 'ffc_campaigns',
                array(
                    'name' => $name,
                    'start_date' => $start_date_utc,
                    'end_date' => $end_date_utc,
                    'unlimited' => $unlimited,
                    'features_config' => json_encode($features_config),
                    'preselect_config' => json_encode($preselect_config)
                ),
                array('id' => $id),
                array('%s', '%s', '%s', '%d', '%s', '%s'),
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('Failed to update campaign');
            }
            
            return array('success' => true);
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Convert local datetime to UTC for database storage
     */
    function convert_local_to_utc($local_datetime) {
        if (empty($local_datetime)) {
            return null;
        }
        
        // Get WordPress timezone
        $wp_timezone = wp_timezone();
        
        try {
            // Create DateTime object in WordPress timezone
            $local_dt = new DateTime($local_datetime, $wp_timezone);
            
            // Convert to UTC
            $local_dt->setTimezone(new DateTimeZone('UTC'));
            
            return $local_dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            error_log('Error converting datetime to UTC: ' . $e->getMessage());
            return $local_datetime; // Fallback to original value
        }
    }

    /**
     * Convert UTC datetime to local for display
     */
    function convert_utc_to_local($utc_datetime) {
        if (empty($utc_datetime)) {
            return null;
        }
        
        // Get WordPress timezone
        $wp_timezone = wp_timezone();
        
        try {
            // Create DateTime object in UTC
            $utc_dt = new DateTime($utc_datetime, new DateTimeZone('UTC'));
            
            // Convert to WordPress timezone
            $utc_dt->setTimezone($wp_timezone);
            
            return $utc_dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            error_log('Error converting datetime to local: ' . $e->getMessage());
            return $utc_datetime; // Fallback to original value
        }
    }

    function firefly_collective_delete_campaign($request) {
        global $wpdb;
        
        $id = intval($request->get_param('id'));
        
        try {
            // Get campaign token for QR code deletion
            $campaign = $wpdb->get_row($wpdb->prepare(
                "SELECT token FROM {$wpdb->prefix}ffc_campaigns WHERE id = %d",
                $id
            ));
            
            if ($campaign) {
                // Delete QR code
                $upload_dir = wp_upload_dir();
                $qr_file = $upload_dir['basedir'] . '/campaigns/' . $campaign->token . '.png';
                if (file_exists($qr_file)) {
                    unlink($qr_file);
                }
                
                // Delete campaign
                $result = $wpdb->delete(
                    $wpdb->prefix . 'ffc_campaigns',
                    array('id' => $id),
                    array('%d')
                );
                
                if ($result === false) {
                    throw new Exception('Failed to delete campaign');
                }
            }
            
            return array('success' => true);
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }