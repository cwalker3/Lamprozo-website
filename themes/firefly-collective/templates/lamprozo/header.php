<?php
/**
 * Lamprozo Template - Header
 */

if (!defined('ABSPATH')) {
    exit;
}

global $template_path_web;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(get_bloginfo('name') . ' - ' . ($args['page-title'] ?? '')); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

<header>
    <div id="header-left">
        <?php $logo_path = $template_path_web . '/images/logo.png'; ?>
        <?php if (file_exists(str_replace(site_url(), ABSPATH, $logo_path))): ?>
        <div id="logo">
            <a href="/">
                <img src="<?php echo esc_url($logo_path); ?>"
                     alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
            </a>
        </div>
        <?php endif; ?>
        <div id="site-name">
            <a href="/"><?php echo esc_html(get_bloginfo('name')); ?></a>
        </div>
    </div>
    <nav>
        <?php wp_nav_menu(array('theme_location' => 'website-menu', 'fallback_cb' => false)); ?>
    </nav>
</header>

<main>
    <div class="content <?php echo esc_attr($args['page-slug'] ?? ''); ?>">
