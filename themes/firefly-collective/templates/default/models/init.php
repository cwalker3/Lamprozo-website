<?php

    // template/models/init.php

    // Register Navigation Menus
    function register_website_menus() {
        register_nav_menu('website-menu', __('Main Website Menu', 'firefly-collective'));
        register_nav_menu('app-menu', __('App Menu', 'firefly-collective'));
    }
    add_action('init', 'register_website_menus');

    // Add Theme Support for Post Thumbnails
    add_theme_support('post-thumbnails');

    /**
     * Tag every page rendered through this template with the `firefly-page`
     * body class so the shared design system in _core_design.css applies.
     * The PWA SPA at /app is excluded since it ships its own chrome.
     *
     * See templates/default/DESIGN.md for the body-class contract.
     */
    function firefly_default_add_body_class( $classes ) {
        if ( ! in_array('firefly-page', $classes, true) ) {
            $classes[] = 'firefly-page';
        }
        return $classes;
    }
    add_filter('body_class', 'firefly_default_add_body_class', 5);

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
        $nav_handle = '';
        $template_handle = '';
        $main_js_handle = '';
        if (is_dir($js_dir)) {
            $js_files = glob($js_dir . '/_core_*.js');
            foreach ($js_files as $file) {
                $filename = basename($file);
                $handle = 'core-' . str_replace(['_core_', '.js'], '', $filename) . '-js';
                $url = $template_web_path . '/js/' . $filename;
                wp_enqueue_script($handle, $url, array(), $version, true);
                if ( preg_match('/^core-nav/', $handle) ) {
                    $nav_handle = $handle;
                }
                if (preg_match('/'.$template_name.'/', $handle)) {
                    $template_handle = $handle;
                }
                if (preg_match('/^core-main/', $handle)) {
                    $main_js_handle = $handle;
                }
            }
        }

        // Localize main.js with the nonce and API URL for security
        wp_localize_script($main_js_handle, 'myApi', array(
            'nonce'         => $nonce,
            'api_url'       => $api_url,
            'themePath'     => $theme_path,
            'maxBlogs'      => 15,
            'gapiDomain'    => WP_HOME
        ));

        // Nav js data (only if nav handle exists)
        if (!empty($nav_handle)) {
            wp_localize_script($nav_handle, 'navData', array(
                'auth_id' => isset($_COOKIE['auth_id']) ? $_COOKIE['auth_id'] : ''
            ));
        }
    }

    // Enqueue Styles and Scripts
    function enqueue_my_styles_and_scripts() {

        global $backend_plugin_path, $backend_plugin_path_web, $theme_path_web, $template_path, $template_web_path;
        global $nonce;

        // Get active template for template-scoped plugin paths
        $active_template = firefly_collective_get_active_template();
        $backend_plugin_path = ABSPATH . 'wp-content/plugins/firefly-collective/templates/' . $active_template;
        $backend_plugin_path_web = '/wp-content/plugins/firefly-collective/templates/' . $active_template;
        $theme_path = get_template_directory_uri();
        $theme_path_web = get_template_directory_uri();
        $active_template = firefly_collective_get_active_template();
        $template_path = $theme_path_web . '/templates/' . $active_template;
        $template_path_web = $template_path;
        $version = wp_get_theme()->get('Version');
        $unique_id = uniqid();
        $auth_id = isset($_COOKIE['auth_id']) ? $_COOKIE['auth_id'] : '';
        $api_url = esc_url_raw(rest_url('custom-api/v1/'));
        $current_view = determine_view();

        // Enqueue core template assets
        enqueue_core_assets($active_template, $theme_path, $unique_id);

        // Localize main.js with the nonce and API URL for security
        wp_localize_script('core-main-js', 'myApi', array(
            'nonce'         => $nonce,
            'api_url'       => $api_url,
            'themePath'     => $theme_path,
            'template_path' => $template_path_web,
            'maxBlogs'      => 15,
            'gapiDomain'    => WP_HOME
        ));

        // Get dynamic asset paths
        $assets = get_view_assets($current_view, $theme_path, $backend_plugin_path_web);

        // Enqueue CSS
        enqueue_assets($assets['css'], 'css', $unique_id);

        // Enqueue JS
        enqueue_assets($assets['js'], 'js', $unique_id);

        // Request an Appointment
        if (determine_view() === 'request-an-appointment') {
            wp_localize_script('calendar-js', 'calData', array(
                'isAdmin'        => 'false',
                'nonce'          => $nonce,
                'calendar'       => get_firefly_collective_calendar(),
                'booking_types'  => get_booking_types(),
                'admin_settings' => get_admin_settings()
            ));
        }

        // Dashboard — dashboard.js always expects `dashboardData` to be
        // defined, or it throws a ReferenceError on initializeDashboard.
        // The feature-configurator-specific fields (features list,
        // subscription status, campaign config) only get populated when
        // that plugin is loaded; otherwise we emit a minimal stub so the
        // profile-only path still works.
        if (determine_view() === 'dashboard') {
            global $theme_path, $is_campaign_mode;
            $theme_path = get_template_directory_uri();

            $user_id = decrypt_with_auth_key($auth_id);
            $is_campaign_mode = !empty($_COOKIE['campaign_token']);
            $third_party = get_user_meta( $user_id, 'third_party', true ) ?: null;

            $publishable_key = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : get_option('firefly_stripe_publishable_key', '');

            $features_options_addons = function_exists('get_features_options_addons')
                ? get_features_options_addons()
                : array();

            $subscription_status = null;
            if (function_exists('firefly_collective_check_subscription_status')) {
                $request = new WP_REST_Request();
                $request->set_param('user_id', $user_id);
                $subscription_status = firefly_collective_check_subscription_status($request);
            }

            // Check for campaign token (from URL or cookie)
            $campaign_config = null;
            $campaign_token  = null;

            $request_uri = $_SERVER['REQUEST_URI'];
            if (preg_match('/\/dashboard\/([a-zA-Z0-9]+)/', $request_uri, $matches)) {
                $campaign_token = $matches[1];
            } elseif (!empty($_COOKIE['campaign_token'])) {
                $campaign_token = $_COOKIE['campaign_token'];
            }

            if ($campaign_token) {
                global $wpdb;
                $campaign = $wpdb->get_row($wpdb->prepare(
                    "SELECT features_config, preselect_config FROM {$wpdb->prefix}ffc_campaigns WHERE token = %s",
                    $campaign_token
                ));

                if ($campaign) {
                    $campaign_config = array(
                        'features_config'  => json_decode($campaign->features_config, true),
                        'preselect_config' => json_decode($campaign->preselect_config, true)
                    );
                }

                wp_enqueue_script('auth-js', $template_path . '/assets/js/auth.js', array(), $unique_id, true);
            }

            wp_localize_script('dashboard-js', 'dashboardData', array(
                'nonce'                 => $nonce,
                'features'              => $features_options_addons,
                'theme_path'            => $theme_path,
                'template_path'         => $template_path,
                'stripeKey'             => $publishable_key,
                'subscription_status'   => $subscription_status,
                'campaign_config'       => $campaign_config,
                'third_party'           => $third_party
            ));
        }

        // Order history
        if (determine_view() === 'order-history') {
            global $currentUserIdAdmin;
            $currentUserIdAdmin = current_user_can('manage_options');

            $obj = new stdClass();

            // Localize into JS
            wp_localize_script('orders-js', 'ordersData', array(
                'data'               => $obj,
                'nonce'              => $nonce,
                'theme_path'         => $theme_path,
                'apiUrl'             => $api_url,
                'currentUserIsAdmin' => $currentUserIdAdmin,
                'currentUserId'      => get_current_user_id()
            ));
        }
    }
    add_action('wp_enqueue_scripts', 'enqueue_my_styles_and_scripts');

    function add_pwa_manifest() {
        global $template_path;

        if (determine_view() !== 'app') return;

        $manifest_url = $template_path . '/manifest.json';

        // Output the link tag
        echo '<link crossorigin="use-credentials" rel="manifest" href="' . esc_url( $manifest_url ) . '">';
    }
    // Hook into wp_head to print the manifest link in the <head> section
    add_action( 'wp_head', 'add_pwa_manifest' );

    function enqueue_assets( array $files, string $type, string $version ) {
        $suffix = $type === 'css' ? '-css' : '-js';

        foreach ( $files as $path ) {

            // remote?
            if ( false !== strpos( $file, '://' ) ) {
                $host  = parse_url( $file, PHP_URL_HOST );
                $parts = explode( '.', $host );
                $name  = $parts[ count( $parts ) - 2 ];
                $src   = $file;
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

    function disable_comments() {
        // This file is loaded for both frontend and admin requests.
        // `remove_menu_page()` relies on the global $menu being initialized;
        // calling it too early (e.g. on plain `init`) can produce core warnings.
        if ( ! is_admin() ) {
            return;
        }

        // Remove from admin menu (guard against core warning: $menu === null)
        global $menu;
        if ( isset($menu) && is_array($menu) ) {
            remove_menu_page('edit-comments.php');
        }
        
        // Remove from post and pages
        remove_post_type_support('post', 'comments');
        remove_post_type_support('page', 'comments');
        
        // Close comments site-wide
        function disable_all_comments($open, $post_id) {
            return false;
        }
        add_filter('comments_open', 'disable_all_comments', 10, 2);
    }
    // Ensure WordPress has initialized $menu/$submenu before we try removing pages.
    add_action('admin_menu', 'disable_comments', 999);

    // Remove 'jquery-migrate' as a dependency of WordPress' jQuery
    add_action('wp_default_scripts', function ($scripts) {
        if (isset($scripts->registered['jquery'])) {
            $scripts->registered['jquery']->deps = array_diff(
                $scripts->registered['jquery']->deps,
                ['jquery-migrate']
            );
        }
    });

    /**
	 * Remove default WordPress block library CSS
	 */
	function remove_wp_css() {
		wp_dequeue_style('wp-block-library-theme');
		wp_dequeue_style('wc-blocks-style'); // Remove WooCommerce block CSS if present
		wp_dequeue_style('classic-theme-styles'); // Remove classic theme styles
	}
	add_action('wp_enqueue_scripts', 'remove_wp_css', 100);

    // Add custom editor style css
    add_action('after_setup_theme', function () {
        add_theme_support('editor-styles');
        add_theme_support('align-wide');
    });

    // Enqueue editor styles with cache-busting based on file modification time.
    //
    // editor-style.css is a banner-focused stylesheet designed for content
    // pages in the default template (contact, signup, dashboard, etc.) that
    // lead with a wp:cover block. It adds aggressive padding/margin rules
    // to EVERY .wp-block which clash with custom landing-style pages.
    //
    // A page can opt out via EITHER:
    //   1. Post meta `_firefly_skip_banner_editor_css` = '1'
    //   2. Being the home page (post_name === 'home')
    //
    // Landing/marketing pages should opt out and provide their own editor
    // parity CSS (see templates/default/models/editor-preview.php for the
    // home-page pattern).
    add_action('enqueue_block_editor_assets', function () {
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                if ($post->post_name === 'home') return;
                if (get_post_meta($post_id, '_firefly_skip_banner_editor_css', true) === '1') return;
            }
        }

        $editor_style_path = get_template_directory() . '/editor-style.css';
        $version = file_exists($editor_style_path) ? filemtime($editor_style_path) : '1.0.0';

        wp_enqueue_style(
            'theme-editor-styles',
            get_template_directory_uri() . '/editor-style.css',
            array(),
            $version
        );
    });

    /**
     * Extend Cover Block with Height Presets
     * Adds a dropdown with Full/Half height options to the Dimensions panel
     * All styles are handled in editor-style.css
     */

    // Enqueue the block editor script
    function enqueue_cover_block_extension() {
        wp_enqueue_script(
            'cover-height-preset-extension',
            get_template_directory_uri() . '/assets/js/cover-height-extension.js',
            array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-element' ),
            '1.0.0',
            true
        );
    }
    add_action( 'enqueue_block_editor_assets', 'enqueue_cover_block_extension' );

    // Add custom attribute to Cover block via filter
    function add_cover_height_preset_attribute( $settings, $name ) {
        if ( 'core/cover' === $name ) {
            $settings['attributes']['heightPreset'] = array(
                'type'    => 'string',
                'default' => 'full'
            );
        }
        return $settings;
    }
    add_filter( 'blocks_register_block_type_args', 'add_cover_height_preset_attribute', 10, 2 );

    // Add custom CSS class based on height preset
    function add_cover_height_preset_class( $extra_props, $block_type, $attributes ) {
        if ( 'core/cover' === $block_type->name && ! empty( $attributes['heightPreset'] ) ) {
            if ( 'full' === $attributes['heightPreset'] ) {
                $extra_props['className'] = isset( $extra_props['className'] ) 
                    ? $extra_props['className'] . ' height-preset-full' 
                    : 'height-preset-full';
            } elseif ( 'half' === $attributes['heightPreset'] ) {
                $extra_props['className'] = isset( $extra_props['className'] ) 
                    ? $extra_props['className'] . ' height-preset-half' 
                    : 'height-preset-half';
            }
        }
        return $extra_props;
    }
    add_filter( 'blocks_get_save_content_extra_props', 'add_cover_height_preset_class', 10, 3 );

    function remove_constrained_layout_from_covers($block_content, $block) {
		if ($block['blockName'] === 'core/cover' && strpos($block_content, 'wp-block-column') !== false) {
			$block_content = str_replace('is-layout-constrained', 'is-layout-unconstrained', $block_content);
		}
		return $block_content;
	}
	add_filter('render_block', 'remove_constrained_layout_from_covers', 10, 2);

    // If any plugin enqueues it explicitly, dequeue as a fallback
    add_action('wp_print_scripts', function () {
        wp_dequeue_script('jquery-migrate');
    }, 100);

    add_action('admin_print_scripts', function () {
        wp_dequeue_script('jquery-migrate');
    }, 100);
