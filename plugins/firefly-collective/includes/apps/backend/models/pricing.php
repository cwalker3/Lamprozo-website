<?php

    function firefly_collective_add_pricing_link() {
        add_menu_page(
            'Pricing',
            'Pricing',
            'manage_options',
            'pricing',
            'firefly_collective_pricing_dashboard',
            'dashicons-money-alt'
        );
    }
    add_action('admin_menu', 'firefly_collective_add_pricing_link');

    function enqueue_pricing_styles_and_scripts($hook) {
        if ($hook !== 'toplevel_page_pricing') {
            return;
        }
        
        $plugin_root_url = dirname(plugin_dir_url(__FILE__)) . '/';
        $unique_id = uniqid();
        
        // Enqueue CSS and JS.
        wp_enqueue_style('pricing-css', $plugin_root_url . 'assets/css/pricing.css', array(), $unique_id);
        wp_enqueue_script('pricing-js', $plugin_root_url . 'assets/js/pricing.js', array(), $unique_id, true);

        // Read pricing.json from the plugin root.
        $plugin_root_path = dirname(plugin_dir_path(__FILE__));
        $pricing_json_path = $plugin_root_path . '/pricing.json';
        $pricing_data = array();
        if (file_exists($pricing_json_path)) {
            $content = file_get_contents($pricing_json_path);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $pricing_data = $decoded;
            }
        }
        // Localize the pricingData variable.
        wp_localize_script('pricing-js', 'pricingData', array(
            'data' => $pricing_data,
            'nonce'  => $nonce,
            'apiUrl' => $api_url
        ));

        // Localize additional settings if needed.
        $nonce = wp_create_nonce('wp_rest');
        $api_url = get_rest_url(null, 'custom-api/v1/');
        wp_localize_script('pricing-js', 'pricingDataSettings', array(
            'nonce'  => $nonce,
            'apiUrl' => $api_url
        ));
    }
    add_action('admin_enqueue_scripts', 'enqueue_pricing_styles_and_scripts');

    function firefly_collective_pricing_dashboard() {
        $plugin_root = dirname(plugin_dir_path(__FILE__));
        $view_path = $plugin_root . '/views/pricing.php';

        if (file_exists($view_path)) {
            require_once $view_path;
        } else {
            wp_die('The pricing view file could not be found.', 'File Not Found', array('response' => 404));
        }
    }

    function firefly_collective_save_pricing( $request ) {
        $data = $request->get_json_params();
        $plugin_root_path = dirname(plugin_dir_path(__FILE__));
        $pricing_json_path = $plugin_root_path . '/pricing.json';
        $result = file_put_contents($pricing_json_path, json_encode($data, JSON_PRETTY_PRINT));
        if($result === false) {
             return new WP_Error('save_failed', 'Failed to save pricing data', array('status' => 500));
        }
        return array('success' => true, 'message' => 'Pricing data saved successfully.');
    }
    
