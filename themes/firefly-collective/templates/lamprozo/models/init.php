<?php
/**
 * Lamprozo Template - Initialization
 */

// Register navigation menus
function lamprozo_register_menus() {
    register_nav_menu('website-menu', __('Lamprozo Menu', 'firefly-collective'));
}
add_action('init', 'lamprozo_register_menus');

// Theme support
add_theme_support('post-thumbnails');

// Hide the admin bar on the front end
add_filter('show_admin_bar', '__return_false');

/**
 * Enqueue core template assets (files starting with _core_)
 */
function lamprozo_enqueue_core_assets($template_name, $theme_path, $version) {
    $template_dir = get_template_directory() . '/templates/' . $template_name . '/assets';
    $template_web = $theme_path . '/templates/' . $template_name . '/assets';

    // CSS
    $css_dir = $template_dir . '/css';
    if (is_dir($css_dir)) {
        $css_files = glob($css_dir . '/_core_*.css');
        foreach ($css_files as $file) {
            $filename = basename($file);
            $handle = 'core-' . str_replace(array('_core_', '.css'), '', $filename) . '-css';
            wp_enqueue_style($handle, $template_web . '/css/' . $filename, array(), $version);
        }
    }

    // JS
    $js_dir = $template_dir . '/js';
    if (is_dir($js_dir)) {
        $js_files = glob($js_dir . '/_core_*.js');
        foreach ($js_files as $file) {
            $filename = basename($file);
            $handle = 'core-' . str_replace(array('_core_', '.js'), '', $filename) . '-js';
            wp_enqueue_script($handle, $template_web . '/js/' . $filename, array(), $version, true);
        }
    }
}

/**
 * Main enqueue function
 */
function lamprozo_enqueue_scripts() {
    $active_template = firefly_collective_get_active_template();
    $theme_path = get_template_directory_uri();
    $version = uniqid();

    lamprozo_enqueue_core_assets($active_template, $theme_path, $version);

    // View-specific assets
    $view = determine_view();
    $assets = get_view_assets($view, $theme_path);
    if (!empty($assets['css'])) {
        foreach ($assets['css'] as $css) {
            wp_enqueue_style(basename($css, '.css'), $css, array(), $version);
        }
    }
    if (!empty($assets['js'])) {
        foreach ($assets['js'] as $js) {
            wp_enqueue_script(basename($js, '.js'), $js, array(), $version, true);
        }
    }
}
add_action('wp_enqueue_scripts', 'lamprozo_enqueue_scripts');
