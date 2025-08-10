<?php

    // theme/template/enqueue.php

    function enqueue_template_styles_and_scripts() {

        $nonce = wp_create_nonce('wp_rest');
        $api_url = esc_url_raw(rest_url('custom-api/v1/'));
        $theme_path_web = get_template_directory_uri();

        // Enqueue Stylesheets
        wp_enqueue_style( FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE.'-css', $theme_path_web . '/templates/'.
                          FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE.'/assets/css/' . 
                          FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE.'.css', array(), $unique_id );

        // Enqueue Scripts
        wp_enqueue_script(  FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE.'-js', $theme_path_web . '/templates/'.
                            FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE.'/assets/js/' . 
                            FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE . '.js', array(), $unique_id, true );
        wp_localize_script( FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE.'-js', 'templateData', array(
            'nonce'   => $nonce,
            'api_url' => $api_url,
        ) );
    }
    add_action('wp_enqueue_scripts', 'enqueue_template_styles_and_scripts');