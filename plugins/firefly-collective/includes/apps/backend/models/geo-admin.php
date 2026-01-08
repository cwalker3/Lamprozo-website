<?php
/**
 * GEO Admin - WordPress Admin Interface for GEO Configuration
 * 
 * Provides a Vue.js-based admin interface for managing Generative Engine
 * Optimization (GEO) settings including organization info, location,
 * contact details, founders, services, and social links.
 */

// Ensure no direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register admin menu item
 */
function firefly_collective_add_geo_link() {
    add_menu_page(
        'GEO Settings',
        'GEO',
        'manage_options',
        'firefly-geo',
        'firefly_collective_geo_dashboard',
        'dashicons-visibility',
        31
    );
}
add_action('admin_menu', 'firefly_collective_add_geo_link');

/**
 * Enqueue styles and scripts for the GEO admin page
 */
function enqueue_geo_styles_and_scripts($hook) {
    if ($hook !== 'toplevel_page_firefly-geo') {
        return;
    }

    $plugin_url = plugin_dir_url(dirname(dirname(dirname(dirname(__FILE__)))));
    $unique_id = uniqid();

    // Enqueue CSS
    wp_enqueue_style(
        'geo-css',
        $plugin_url . 'includes/apps/backend/assets/css/geo.css',
        array(),
        $unique_id
    );

    // Enqueue Vue.js from CDN
    wp_enqueue_script(
        'vue-js',
        'https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js',
        array(),
        '3',
        true
    );

    // Enqueue GEO JavaScript
    wp_enqueue_script(
        'geo-js',
        $plugin_url . 'includes/apps/backend/assets/js/geo.js',
        array('vue-js'),
        $unique_id,
        true
    );

    // Pass data to JavaScript
    $has_dev = defined('LIVE_DEV_ENDPOINT') && !empty(LIVE_DEV_ENDPOINT);
    $has_prod = defined('PROD_ENDPOINT') && !empty(PROD_ENDPOINT);
    
    wp_localize_script('geo-js', 'geoData', array(
        'nonce' => wp_create_nonce('wp_rest'),
        'apiUrl' => rest_url('firefly-collective/v1/'),
        'adminUrl' => admin_url('admin.php?page=firefly-geo'),
        'hasDevEndpoint' => $has_dev,
        'hasProdEndpoint' => $has_prod,
        'devSite' => $has_dev ? parse_url(LIVE_DEV_ENDPOINT, PHP_URL_HOST) : '',
        'prodSite' => $has_prod ? parse_url(PROD_ENDPOINT, PHP_URL_HOST) : ''
    ));
}
add_action('admin_enqueue_scripts', 'enqueue_geo_styles_and_scripts');

/**
 * Display the GEO dashboard page
 */
function firefly_collective_geo_dashboard() {
    $view_path = plugin_dir_path(dirname(__FILE__)) . 'views/geo.php';
    
    if (file_exists($view_path)) {
        require_once $view_path;
    } else {
        wp_die('The GEO view file could not be found.', 'File Not Found', array('response' => 404));
    }
}

/**
 * Create GEO config database table
 */
function firefly_collective_create_geo_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_geo_config';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        config_key VARCHAR(50) NOT NULL,
        config_value LONGTEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY config_key (config_key)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Initialize GEO table on plugin activation and migrate existing config
 */
function firefly_collective_init_geo() {
    firefly_collective_create_geo_table();
    firefly_collective_migrate_geo_config();
}
add_action('admin_init', 'firefly_collective_init_geo');

/**
 * Migrate existing geo-config.json to database (one-time)
 */
function firefly_collective_migrate_geo_config() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_geo_config';
    
    // Check if migration already done
    $existing = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    if ($existing > 0) {
        return;
    }
    
    // Check for existing JSON config
    $json_path = plugin_dir_path(dirname(__FILE__)) . 'geo-config.json';
    if (!file_exists($json_path)) {
        // No existing config, use defaults
        $config = firefly_geo_get_default_config();
    } else {
        $json_content = file_get_contents($json_path);
        $config = json_decode($json_content, true);
        if (!$config) {
            $config = firefly_geo_get_default_config();
        }
    }
    
    // Insert each section into database
    $sections = array('organization', 'location', 'contact', 'founders', 'services', 'social', 'industry');
    
    foreach ($sections as $section) {
        if (isset($config[$section])) {
            $wpdb->replace(
                $table_name,
                array(
                    'config_key' => $section,
                    'config_value' => json_encode($config[$section])
                ),
                array('%s', '%s')
            );
        }
    }
}

/**
 * Get GEO config from database
 */
function firefly_collective_get_geo_config() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_geo_config';
    
    $results = $wpdb->get_results(
        "SELECT config_key, config_value FROM $table_name",
        ARRAY_A
    );
    
    if (empty($results)) {
        return firefly_geo_get_default_config();
    }
    
    $config = array();
    foreach ($results as $row) {
        $config[$row['config_key']] = json_decode($row['config_value'], true);
    }
    
    // Merge with defaults to ensure all keys exist
    $defaults = firefly_geo_get_default_config();
    foreach ($defaults as $key => $value) {
        if (!isset($config[$key])) {
            $config[$key] = $value;
        }
    }
    
    return $config;
}

/**
 * Save GEO config to database
 */
function firefly_collective_save_geo_config($config) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_geo_config';
    
    $sections = array('organization', 'location', 'contact', 'founders', 'services', 'social', 'industry');
    
    foreach ($sections as $section) {
        if (isset($config[$section])) {
            $wpdb->replace(
                $table_name,
                array(
                    'config_key' => $section,
                    'config_value' => json_encode($config[$section])
                ),
                array('%s', '%s')
            );
        }
    }
    
    // Clear the static cache in geo-schema.php
    firefly_geo_clear_config_cache();
    
    return true;
}

/**
 * Register REST API endpoints
 */
add_action('rest_api_init', function() {
    // GET config
    register_rest_route('firefly-collective/v1', '/geo/config', array(
        'methods' => 'GET',
        'callback' => 'firefly_collective_rest_get_geo_config',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
    
    // POST config (save)
    register_rest_route('firefly-collective/v1', '/geo/config', array(
        'methods' => 'POST',
        'callback' => 'firefly_collective_rest_save_geo_config',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
    
    // POST reset (restore defaults)
    register_rest_route('firefly-collective/v1', '/geo/reset', array(
        'methods' => 'POST',
        'callback' => 'firefly_collective_rest_reset_geo_config',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
    
    // POST preview (generate JSON-LD preview)
    register_rest_route('firefly-collective/v1', '/geo/preview', array(
        'methods' => 'POST',
        'callback' => 'firefly_collective_rest_geo_preview',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
    
    // POST sync (push config to remote)
    register_rest_route('firefly-collective/v1', '/geo/sync', array(
        'methods' => 'POST',
        'callback' => 'firefly_collective_rest_geo_sync',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
    
    // POST receive (receive config from remote - uses shared secret)
    register_rest_route('firefly-collective/v1', '/geo/receive', array(
        'methods' => 'POST',
        'callback' => 'firefly_collective_rest_geo_receive',
        'permission_callback' => '__return_true'  // Uses shared secret instead
    ));
});

/**
 * REST: Get GEO config
 */
function firefly_collective_rest_get_geo_config(WP_REST_Request $request) {
    $config = firefly_collective_get_geo_config();
    
    return new WP_REST_Response(array(
        'success' => true,
        'config' => $config
    ), 200);
}

/**
 * REST: Save GEO config
 */
function firefly_collective_rest_save_geo_config(WP_REST_Request $request) {
    $config = $request->get_json_params();
    
    if (empty($config)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'No configuration data provided'
        ), 400);
    }
    
    // Sanitize inputs
    $config = firefly_collective_sanitize_geo_config($config);
    
    // Save to database
    $result = firefly_collective_save_geo_config($config);
    
    if ($result) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Configuration saved successfully'
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to save configuration'
        ), 500);
    }
}

/**
 * REST: Reset GEO config to defaults
 */
function firefly_collective_rest_reset_geo_config(WP_REST_Request $request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_geo_config';
    
    // Clear existing config
    $wpdb->query("TRUNCATE TABLE $table_name");
    
    // Insert defaults
    $defaults = firefly_geo_get_default_config();
    firefly_collective_save_geo_config($defaults);
    
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Configuration reset to defaults',
        'config' => $defaults
    ), 200);
}

/**
 * REST: Generate JSON-LD preview
 */
function firefly_collective_rest_geo_preview(WP_REST_Request $request) {
    $config = $request->get_json_params();
    
    if (empty($config)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'No configuration data provided'
        ), 400);
    }
    
    // Generate the JSON-LD schema
    $schema = firefly_geo_generate_preview_schema($config);
    
    return new WP_REST_Response(array(
        'success' => true,
        'preview' => json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ), 200);
}

/**
 * Sanitize GEO config data
 */
function firefly_collective_sanitize_geo_config($config) {
    $sanitized = array();
    
    // Organization
    if (isset($config['organization'])) {
        $sanitized['organization'] = array(
            'name' => sanitize_text_field($config['organization']['name'] ?? ''),
            'legalName' => sanitize_text_field($config['organization']['legalName'] ?? ''),
            'url' => esc_url_raw($config['organization']['url'] ?? ''),
            'foundingDate' => sanitize_text_field($config['organization']['foundingDate'] ?? ''),
            'description' => sanitize_textarea_field($config['organization']['description'] ?? ''),
            'disambiguatingDescription' => sanitize_textarea_field($config['organization']['disambiguatingDescription'] ?? ''),
            'logo' => esc_url_raw($config['organization']['logo'] ?? ''),
            'image' => esc_url_raw($config['organization']['image'] ?? '')
        );
    }
    
    // Location
    if (isset($config['location'])) {
        $sanitized['location'] = array(
            'city' => sanitize_text_field($config['location']['city'] ?? ''),
            'state' => sanitize_text_field($config['location']['state'] ?? ''),
            'stateCode' => sanitize_text_field($config['location']['stateCode'] ?? ''),
            'country' => sanitize_text_field($config['location']['country'] ?? ''),
            'countryCode' => sanitize_text_field($config['location']['countryCode'] ?? '')
        );
    }
    
    // Contact
    if (isset($config['contact'])) {
        $sanitized['contact'] = array(
            'email' => sanitize_email($config['contact']['email'] ?? ''),
            'phone' => sanitize_text_field($config['contact']['phone'] ?? '')
        );
    }
    
    // Founders (array)
    if (isset($config['founders']) && is_array($config['founders'])) {
        $sanitized['founders'] = array();
        foreach ($config['founders'] as $founder) {
            $sanitized['founders'][] = array(
                'name' => sanitize_text_field($founder['name'] ?? ''),
                'jobTitle' => sanitize_text_field($founder['jobTitle'] ?? ''),
                'description' => sanitize_textarea_field($founder['description'] ?? '')
            );
        }
    }
    
    // Services (array)
    if (isset($config['services']) && is_array($config['services'])) {
        $sanitized['services'] = array();
        foreach ($config['services'] as $service) {
            $sanitized['services'][] = array(
                'name' => sanitize_text_field($service['name'] ?? ''),
                'description' => sanitize_textarea_field($service['description'] ?? '')
            );
        }
    }
    
    // Social
    if (isset($config['social'])) {
        $sanitized['social'] = array(
            'linkedin' => esc_url_raw($config['social']['linkedin'] ?? ''),
            'facebook' => esc_url_raw($config['social']['facebook'] ?? ''),
            'twitter' => esc_url_raw($config['social']['twitter'] ?? ''),
            'github' => esc_url_raw($config['social']['github'] ?? ''),
            'instagram' => esc_url_raw($config['social']['instagram'] ?? '')
        );
    }
    
    // Industry
    if (isset($config['industry'])) {
        $sanitized['industry'] = array(
            'naics' => sanitize_text_field($config['industry']['naics'] ?? ''),
            'isicV4' => sanitize_text_field($config['industry']['isicV4'] ?? '')
        );
    }
    
    return $sanitized;
}

/**
 * Generate preview schema from config (simplified version for preview)
 */
function firefly_geo_generate_preview_schema($config) {
    $org = $config['organization'] ?? array();
    $loc = $config['location'] ?? array();
    $contact = $config['contact'] ?? array();
    $founders = $config['founders'] ?? array();
    $services = $config['services'] ?? array();
    $social = $config['social'] ?? array();
    $industry = $config['industry'] ?? array();
    
    $schema = array(
        '@context' => 'https://schema.org',
        '@graph' => array()
    );
    
    // Organization schema
    $organization = array(
        '@type' => 'ProfessionalService',
        '@id' => ($org['url'] ?? '') . '/#organization',
        'name' => $org['name'] ?? '',
        'legalName' => $org['legalName'] ?? '',
        'url' => $org['url'] ?? '',
        'description' => $org['description'] ?? '',
        'disambiguatingDescription' => $org['disambiguatingDescription'] ?? '',
        'foundingDate' => $org['foundingDate'] ?? ''
    );
    
    // Add industry codes if present
    if (!empty($industry['naics'])) {
        $organization['naics'] = $industry['naics'];
    }
    if (!empty($industry['isicV4'])) {
        $organization['isicV4'] = $industry['isicV4'];
    }
    
    // Add logo if present
    if (!empty($org['logo'])) {
        $organization['logo'] = array(
            '@type' => 'ImageObject',
            'url' => $org['logo']
        );
    }
    
    // Add address if location present
    if (!empty($loc['city'])) {
        $organization['address'] = array(
            '@type' => 'PostalAddress',
            'addressLocality' => $loc['city'] ?? '',
            'addressRegion' => $loc['stateCode'] ?? '',
            'addressCountry' => $loc['countryCode'] ?? ''
        );
    }
    
    // Add contact info
    if (!empty($contact['email'])) {
        $organization['email'] = $contact['email'];
    }
    if (!empty($contact['phone'])) {
        $organization['telephone'] = $contact['phone'];
    }
    
    // Add founders
    if (!empty($founders)) {
        $organization['founder'] = array();
        foreach ($founders as $index => $founder) {
            $organization['founder'][] = array(
                '@type' => 'Person',
                '@id' => ($org['url'] ?? '') . '/about/#founder-' . ($index + 1),
                'name' => $founder['name'] ?? '',
                'jobTitle' => $founder['jobTitle'] ?? ''
            );
        }
    }
    
    // Add services
    if (!empty($services)) {
        $organization['hasOfferCatalog'] = array(
            '@type' => 'OfferCatalog',
            'name' => 'Services',
            'itemListElement' => array()
        );
        foreach ($services as $service) {
            $organization['hasOfferCatalog']['itemListElement'][] = array(
                '@type' => 'Offer',
                'itemOffered' => array(
                    '@type' => 'Service',
                    'name' => $service['name'] ?? '',
                    'description' => $service['description'] ?? ''
                )
            );
        }
    }
    
    // Add social links (sameAs)
    $sameAs = array();
    foreach ($social as $platform => $url) {
        if (!empty($url)) {
            $sameAs[] = $url;
        }
    }
    if (!empty($sameAs)) {
        $organization['sameAs'] = $sameAs;
    }
    
    $schema['@graph'][] = $organization;
    
    // Website schema
    $schema['@graph'][] = array(
        '@type' => 'WebSite',
        '@id' => ($org['url'] ?? '') . '/#website',
        'url' => $org['url'] ?? '',
        'name' => $org['name'] ?? '',
        'description' => $org['description'] ?? '',
        'publisher' => array(
            '@id' => ($org['url'] ?? '') . '/#organization'
        )
    );
    
    return $schema;
}


/**
 * REST: Sync GEO config to remote environment
 */
function firefly_collective_rest_geo_sync(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $target_env = isset($params['target_env']) ? $params['target_env'] : 'dev';
    
    // Check endpoint configuration
    if ($target_env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Production endpoint not configured. Set PROD_ENDPOINT in wp-config.php.'
            ), 400);
        }
        $project_endpoint = PROD_ENDPOINT;
    } else {
        if (!defined('LIVE_DEV_ENDPOINT') || empty(LIVE_DEV_ENDPOINT)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Live Dev endpoint not configured. Set LIVE_DEV_ENDPOINT in wp-config.php.'
            ), 400);
        }
        $project_endpoint = LIVE_DEV_ENDPOINT;
    }
    
    // Check shared secret
    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Shared secret not configured. Set FIREFLY_SHARED_SECRET in wp-config.php.'
        ), 400);
    }
    
    // Get current config
    $config = firefly_collective_get_geo_config();
    
    // Build the GEO receive endpoint URL
    if (preg_match('/(https?:\/\/[^\/]+)/', $project_endpoint, $matches)) {
        $base_url = $matches[1];
        $geo_endpoint = $base_url . '/wp-json/firefly-collective/v1/geo/receive';
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid endpoint URL format.'
        ), 400);
    }
    
    // Prepare payload
    $payload = array(
        'secret' => FIREFLY_SHARED_SECRET,
        'config' => $config
    );
    
    // Send to remote
    $response = wp_remote_post($geo_endpoint, array(
        'timeout' => 30,
        'headers' => array(
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode($payload)
    ));
    
    if (is_wp_error($response)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to connect: ' . $response->get_error_message()
        ), 500);
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($response_code !== 200) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $response_body['message'] ?? 'Remote server returned error ' . $response_code
        ), $response_code);
    }
    
    $env_name = ($target_env === 'prod') ? 'Production' : 'Live Dev';
    
    return new WP_REST_Response(array(
        'success' => true,
        'message' => "GEO config synced to {$env_name} successfully!"
    ), 200);
}

/**
 * REST: Receive GEO config from remote (called by remote sync)
 */
function firefly_collective_rest_geo_receive(WP_REST_Request $request) {
    $params = $request->get_json_params();
    
    // Verify shared secret
    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Shared secret not configured on receiving server.'
        ), 500);
    }
    
    $received_secret = isset($params['secret']) ? $params['secret'] : '';
    if ($received_secret !== FIREFLY_SHARED_SECRET) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid shared secret.'
        ), 403);
    }
    
    // Get config from payload
    $config = isset($params['config']) ? $params['config'] : null;
    if (empty($config)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'No configuration data provided.'
        ), 400);
    }
    
    // Ensure table exists
    firefly_collective_create_geo_table();
    
    // Sanitize and save
    $config = firefly_collective_sanitize_geo_config($config);
    $result = firefly_collective_save_geo_config($config);
    
    if ($result) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'GEO config received and saved successfully.'
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to save configuration.'
        ), 500);
    }
}
