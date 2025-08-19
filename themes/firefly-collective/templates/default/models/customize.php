<?php

    // template/models/customize.php

    // Customizer from template
    function enqueue_template_customize_js() {
        global $template_path_web;
        global $main_nonce;

        wp_enqueue_script(
            'my-customize-controls',
            $template_path_web . '/assets/js/customize.js',
            array( 'customize-controls' ),
            false,
            true
        );

        wp_localize_script('my-customize-controls', 'customizeData', array(
            'nonce' => $main_nonce,
        ));
    }
    add_action( 'customize_controls_enqueue_scripts', 'enqueue_template_customize_js' );