<?php

    // template/models/init.php

    // Register Navigation Menu
    function register_website_menu() {
        register_nav_menu('website-menu', __('Main Website Menu', 'firefly-collective'));
    }
    add_action('init', 'register_website_menu');

    // Add Theme Support for Post Thumbnails
    add_theme_support('post-thumbnails');

    /**
     * Enqueue core template assets (files starting with _core_)
     */
    function enqueue_core_assets($template_name, $theme_path, $version) {
        global $nonce;

        $template_dir = get_template_directory() . '/templates/' . $template_name . '/assets';
        $template_web_path = $theme_path . '/templates/' . $template_name . '/assets';
        $template_path = get_template_directory_uri() . '/templates/' . $template_name;
        
        // Scan and enqueue core CSS files
        $css_dir = $template_dir . '/css';
        if (is_dir($css_dir)) {
            $css_files = glob($css_dir . '/_core_*.css');
            foreach ($css_files as $file) {
                $filename = basename($file);
                $handle = 'core-' . str_replace(['_core_', '.css'], '', $filename) . '-css';
                $url = $template_web_path . '/css/' . $filename;
                wp_enqueue_style($handle, $url, array(), $version);
            }
        }
        
        // Scan and enqueue core JS files
        $js_dir = $template_dir . '/js';
        if (is_dir($js_dir)) {
            $js_files = glob($js_dir . '/_core_*.js');
            foreach ($js_files as $file) {
                $filename = basename($file);
                $handle = 'core-' . str_replace(['_core_', '.js'], '', $filename) . '-js';
                $url = $template_web_path . '/js/' . $filename;
                wp_enqueue_script($handle, $url, array(), $version, true);
            }
        }
    }

    // Enqueue Styles and Scripts
    function enqueue_my_styles_and_scripts() {

        global $backend_plugin_path, $backend_plugin_path_web;
        global $theme_path, $theme_path_web, $template_path, $template_web_path, $nonce;

        $backend_plugin_path = ABSPATH . 'wp-content/plugins/firefly-collective/includes/apps/backend';
        $backend_plugin_path_web = '/wp-content/plugins/firefly-collective/includes/apps/backend';
        $theme_path = get_template_directory_uri();
        $theme_path_web = $theme_path;
        $active_template = firefly_collective_get_active_template();
        $template_path = $theme_path_web . '/templates/' . $active_template;
        $template_path_web = $template_path;
        $version = wp_get_theme()->get('Version');
        $unique_id = uniqid();
        $api_url = esc_url_raw(rest_url('custom-api/v1/'));
        $current_view = determine_view();

        // Enqueue core template assets
        enqueue_core_assets($active_template, $theme_path, $unique_id);

        // Localize main.js with the nonce and API URL for security
        wp_localize_script('core-main-js', 'myApi', array(
            'nonce'         => $nonce,
            'api_url'       => $api_url,
            'themePath'     => $theme_path,
            'template_path' => $template_path_web
        ));

        // Get dynamic asset paths
        $assets = get_view_assets($current_view, $theme_path, $backend_plugin_path_web);

        // Enqueue CSS
        enqueue_assets($assets['css'], 'css', $unique_id);

        // Enqueue JS
        enqueue_assets($assets['js'], 'js', $unique_id);

    }
    add_action('wp_enqueue_scripts', 'enqueue_my_styles_and_scripts');

    function enqueue_assets( array $files, string $type, string $version ) {
        $suffix = $type === 'css' ? '-css' : '-js';

        foreach ( $files as $path ) {
            if ( ! $path ) {
                continue;
            }

            if ( false !== strpos( $path, '://' ) ) {
                $host  = parse_url( $path, PHP_URL_HOST );
                $parts = explode( '.', $host );
                $name  = $parts[ count( $parts ) - 2 ];
                $src   = $path;
            } else {
                $name = pathinfo( $path, PATHINFO_FILENAME );
                $src  = "{$path}";
            }

            $handle = $name . $suffix;

            if ( $type === 'css' ) {
                wp_enqueue_style( $handle, $src, array(), $version );
            } else {
                wp_enqueue_script( $handle, $src, array(), $version, true );
            }
        }
    }

    function remove_constrained_layout_from_covers($block_content, $block) {
		if ($block['blockName'] === 'core/cover' && strpos($block_content, 'wp-block-column') !== false) {
			$block_content = str_replace('is-layout-constrained', 'is-layout-unconstrained', $block_content);
		}
		return $block_content;
	}
	add_filter('render_block', 'remove_constrained_layout_from_covers', 10, 2);
