<?php

    // plugin/models/template.php

    // Define template system constants
    define('FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE', 'default');
    define('FIREFLY_COLLECTIVE_TEMPLATE_OPTION', 'firefly_collective_active_template');
    define('FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION', 'firefly_collective_active_template_temp');
    define('FIREFLY_COLLECTIVE_TEMPLATES_DIR', get_template_directory() . '/templates');

    // Customizer admin only
    function init_customizer($hook) {
        $target_dir_url = plugin_dir_url( dirname( __FILE__, 1 ) );
        $plugin_uri = '/' . ltrim( str_replace( home_url(), '', $target_dir_url ), '/' );
        $unique_id = uniqid();
        if (is_customize_preview()) {
            wp_enqueue_script('customize-js', $plugin_uri . '/assets/js/customize.js', array(), $unique_id, true);
        }
    }
    add_action('admin_enqueue_scripts', 'init_customizer');

    /**
     * Check if a template exists (plugin version)
     */
    function firefly_collective_plugin_template_exists($template_name) {
        $template_name = sanitize_file_name($template_name);
        $template_path = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . $template_name;
        
        // Check if template directory exists and has required files
        return is_dir($template_path) && 
               file_exists($template_path . '/header.php') && 
               file_exists($template_path . '/footer.php');
    }

    function handle_change_template_temp( WP_REST_Request $request ) {
        $template_name = sanitize_text_field( $request->get_param( 'template' ) );
        
        if ( empty( $template_name ) ) {
            return new WP_Error( 'missing_template', 'Template name is required', array( 'status' => 400 ) );
        }
        
        // Validate template exists using plugin function
        if ( ! firefly_collective_plugin_template_exists( $template_name ) ) {
            return new WP_Error( 'invalid_template', 'Template does not exist', array( 'status' => 400 ) );
        }
        
        // Update temp option
        update_option( FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, $template_name );
        
        return rest_ensure_response( array(
            'success' => true,
            'template' => $template_name
        ) );
    }