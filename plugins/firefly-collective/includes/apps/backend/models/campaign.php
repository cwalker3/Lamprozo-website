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

        // Main JS
        wp_enqueue_script('main-js', $theme_path . '/assets/js/main.js', array(), $unique_id, true);
        wp_localize_script('main-js', 'myApi', array(
            'themePath' => $theme_path
        ));

        // Enqueue CSS & JS
        wp_enqueue_style('campaign-css', $plugin_root_url . 'assets/css/campaign.css', array(), $unique_id);
        wp_enqueue_script('main-js', $theme_path . '/assets/js/main.js', array(), $unique_id, true);
        wp_enqueue_script('campaign-js', $plugin_root_url . 'assets/js/campaign.js', array(), $unique_id, true);

        // Enqueue Vue
        wp_enqueue_script('vue-js', VUE_REMOTE_CORE, array(), null, true);

        // Localize into JS
        $api_url = get_rest_url(null, 'custom-api/v1/');
        wp_localize_script('campaign-js', 'campaignData', array(
            'nonce'              => $nonce,
            'apiUrl'             => $api_url
        ));
    }
    add_action('admin_enqueue_scripts', 'enqueue_campaign_styles_and_scripts');

    // Create mysql table for campaigns
    function create_ffc_campaign_table_if_not_exist() {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_campaigns (
            id                                  INT UNSIGNED NOT NULL AUTO_INCREMENT
        ) {$collate};";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
    add_action( 'plugins_loaded', 'create_ffc_campaign_table_if_not_exist' );