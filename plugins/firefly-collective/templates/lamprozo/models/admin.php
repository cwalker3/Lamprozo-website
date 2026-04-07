<?php
/**
 * Lamprozo - Admin Page
 */

function lamprozo_add_admin_menu() {
    add_menu_page(
        'Lamprozo',           // Page title
        'Lamprozo',           // Menu title
        'manage_options',             // Capability
        'lamprozo-dashboard',         // Menu slug
        'lamprozo_render_admin_page', // Callback
        'dashicons-admin-generic'     // Icon
    );
}
add_action('admin_menu', 'lamprozo_add_admin_menu');

function lamprozo_render_admin_page() {
    $template_dir = plugin_dir_path(__FILE__) . '../';
    include $template_dir . 'views/admin-page.php';
}

function lamprozo_enqueue_admin_assets($hook) {
    if ($hook !== 'toplevel_page_lamprozo-dashboard') {
        return;
    }

    $plugin_url = plugin_dir_url(__FILE__) . '../assets/';

    wp_enqueue_style('lamprozo-admin-css', $plugin_url . 'css/admin.css');
    wp_enqueue_script('lamprozo-admin-js', $plugin_url . 'js/admin.js', array('jquery'), '1.0', true);

    wp_localize_script('lamprozo-admin-js', 'lamprozoData', array(
        'apiUrl' => rest_url('firefly/lamprozo/v1'),
        'nonce' => wp_create_nonce('wp_rest')
    ));
}
add_action('admin_enqueue_scripts', 'lamprozo_enqueue_admin_assets');
