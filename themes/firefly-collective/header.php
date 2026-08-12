<?php
    
    // theme/header.php - Template Control File

    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }

    // Ensure customize model is loaded
    if (!function_exists('firefly_collective_get_active_template')) {
        require_once get_template_directory() . '/template.php';
    }

    // Ensure $args is available and populated with proper data
    if (!isset($args) || !is_array($args)) {
        $args = array();
    }

    // Make sure args contains the data templates expect
    if (!isset($args['page-title'])) {
        $args['page-title'] = '';
    }
    if (!isset($args['theme-path'])) {
        $args['theme-path'] = get_stylesheet_directory_uri();
    }
    if (!isset($args['page-slug'])) {
        $args['page-slug'] = '';
    }
    if (!isset($args['is-single'])) {
        $args['is-single'] = is_single();
    }
    if (!isset($args['is-user-logged-in'])) {
        $args['is-user-logged-in'] = is_user_logged_in();
    }

    // Try to load the header from the active template
    if (firefly_collective_load_template_file('header.php', $args)) {
        // Template loaded successfully
        return;
    }
    
    // If we get here, the template failed to load
    // Try to load the default template as fallback
    $default_header_path = firefly_collective_get_template_file_path('header.php', FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
    if ($default_header_path) {
        // Extract args for use in template
        if (!empty($args)) {
            extract($args, EXTR_SKIP);
        }
        include $default_header_path;
        return;
    }
    
    // Ultimate fallback: Basic header if even default template fails
    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( firefly_get_document_title( isset( $args['page-title'] ) ? $args['page-title'] : '' ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
    <div id="backdrop"></div>
    <header>
        <h1><?php echo esc_html(get_bloginfo('name')); ?></h1>
    </header>
    <main>
        <div class="content">
    <?php