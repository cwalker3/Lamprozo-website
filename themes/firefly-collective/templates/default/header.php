<?php
    
    // theme/template/default/header.php

    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }

    global $template_path_web;

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( firefly_get_document_title( isset( $args['page-title'] ) ? $args['page-title'] : '' ) ); ?></title>
    <?php wp_head(); ?>
</head>

<body <?php
    $additional_classes = array();

    // Add page slug as class
    global $post;
    if (isset($post) && $post->post_name) {
        $additional_classes[] = 'page-' . $post->post_name;
    }

    body_class($additional_classes);
?>>
    <div id="backdrop"></div>

    <?php
        // Compute whether to add "user-nav".
        // We NEVER add it while previewing in the Customizer.
        $is_logged_in   = ! empty( $args['is-user-logged-in'] );
        $no_auth_cookie = empty( $_COOKIE['auth_id'] );

        // Get view
        $view = determine_view();

        // For web app
        $is_web_app = false;
        if ($view === 'app') $is_web_app = true;
        
        // Website or app nav
        $nav = 'website-menu';
        if ($is_web_app) $nav = 'app-menu';

        // Reliable Customizer preview (iframe) detection across navigations:
        $in_customizer  = (
            isset($_GET['customize_messenger_channel']) ||
            isset($_GET['customize_changeset_uuid']) ||
            ( isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe' )
        );

        $theme_path    = isset($args['theme-path']) ? $args['theme-path'] : get_stylesheet_directory_uri();

        // Determine which overlay system to use
        $use_front_overlay = is_front_page() && (bool) template_get_option('menu_overlay', $in_customizer);
        $use_inner_overlay = !is_front_page() && (bool) template_get_option('nav_overlay_menu', $in_customizer);
        $use_any_overlay = $use_front_overlay || $use_inner_overlay;

        $element_disabled_class = '';
        $navbar_attr = 'class="';
        if ( ($use_any_overlay || !is_front_page()) && !$is_web_app ) $navbar_attr .= ' element-disable';
        $add_user_nav   = $is_logged_in && $no_auth_cookie && ! $in_customizer;
        if ($add_user_nav) $navbar_attr .= ' user-nav';
        $navbar_attr .= '"';

        // Logo positioning classes - always add logo-left when any overlay is active
        $logo_classes = array();
        if ($add_user_nav) :
            $logo_classes[] = 'user-nav';
        endif;
        if ($use_any_overlay && !$is_web_app) :
            $logo_classes[] = 'logo-left';
        endif;
        if ( is_front_page() ) :
            $logo_classes[] = 'front-page';
        endif;
        if ($is_web_app === 'app') :
            $logo_classes[] = 'web-app';
        endif;
        $logo_class_attr = !empty($logo_classes) ? ' class="' . implode(' ', $logo_classes) . '"' : '';
    ?>

    <header role="banner" itemscope itemtype="https://schema.org/WPHeader">
        <div id="nav-bar"<?php echo $navbar_attr; ?>></div>

        <div id="logo-name"<?php echo $logo_class_attr; ?> itemscope itemtype="https://schema.org/Organization">
            <div id="site-logo">
                <a href="/" itemprop="url">
                    <img src="<?php echo esc_url( $template_path_web . '/images/logo.webp' ); ?>" alt="<?php echo esc_attr( get_bloginfo('name') ); ?>" itemprop="logo">
                </a>
            </div>
            <div id="site-name">
                <a href="/">
                    <span itemprop="name"><?php echo esc_html( get_bloginfo('name') ); ?></span>
                </a>
            </div>
        </div>
    </header>

    <!-- Consolidated Overlay Menu System -->
    <?php if ($use_any_overlay && !$is_web_app): ?>
        <div id="overlay-menu-container" class="<?php echo $use_front_overlay ? 'front-page' : 'inner-page'; ?><?php echo $add_user_nav ? ' user-nav' : ''; ?>">
            <nav id="overlay-nav" role="navigation" aria-label="<?php esc_attr_e('Main Navigation'); ?>" itemscope itemtype="https://schema.org/SiteNavigationElement">
                <?php
                    wp_nav_menu( array(
                        'theme_location'  => 'website-menu',
                        'container_class' => 'overlay-menu-wrapper',
                        'menu_class'      => 'overlay-menu',
                        'fallback_cb'     => false,
                    ) );
                ?>
            </nav>
        </div>
    <?php endif; ?>

    <div <?php if ($use_any_overlay && !$is_web_app):?>
            class="element-disable"
         <?php endif; ?>>
        <button id="hamburger" type="button" aria-label="<?php esc_attr_e('Menu'); ?>" aria-expanded="false"<?php echo $user_nav_attr; ?>>
            <span class="bar bar-1"></span>
            <span class="bar bar-2"></span>
            <span class="bar bar-3"></span>
        </button>
    </div>

    <!-- Profile Dropdown -->
    <?php
    // Determine if profile should be visible initially
    $auth_exists = is_user_logged_in() || ! empty( $_COOKIE['auth_id'] );

    // Always render the container for web app (so JS can control it), or if authenticated
    if ( $auth_exists || $is_web_app ) : ?>
    <div id="profile-dropdown-container" style="<?php echo !$auth_exists ? 'display: none;' : ''; ?>" class="<?php echo $is_web_app && $auth_exists ? 'authenticated' : ''; ?>">
        <button id="profile-button" aria-label="Profile menu" aria-expanded="false">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 12C14.21 12 16 10.21 16 8C16 5.79 14.21 4 12 4C9.79 4 8 5.79 8 8C8 10.21 9.79 12 12 12ZM12 14C9.33 14 4 15.34 4 18V20H20V18C20 15.34 14.67 14 12 14Z" fill="currentColor"/>
            </svg>
        </button>
        <div id="profile-dropdown" class="profile-dropdown-menu" aria-hidden="true">
            <a href="/dashboard" class="profile-dropdown-item">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3 13H11V3H3V13ZM3 21H11V15H3V21ZM13 21H21V11H13V21ZM13 3V9H21V3H13Z" fill="currentColor"/>
                </svg>
                Dashboard
            </a>
            <a href="/order-history" class="profile-dropdown-item">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M19 3H5C3.9 3 3 3.9 3 5V19C3 20.1 3.9 21 5 21H19C20.1 21 21 20.1 21 19V5C21 3.9 20.1 3 19 3ZM19 19H5V8H19V19ZM7 10V12H9V10H7ZM11 10V12H17V10H11ZM7 14V16H9V14H7ZM11 14V16H17V14H11Z" fill="currentColor"/>
                </svg>
                Order History
            </a>
            <a href="/logout" class="profile-dropdown-item">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M17 7L15.59 8.41L18.17 11H8V13H18.17L15.59 15.59L17 17L22 12L17 7ZM4 5H12V3H4C2.9 3 2 3.9 2 5V19C2 20.1 2.9 21 4 21H12V19H4V5Z" fill="currentColor"/>
                </svg>
                Logout
            </a>
        </div>
    </div>
    <?php endif; ?>

    <nav role="navigation" aria-label="<?php echo esc_attr( $is_web_app ? __('App Navigation') : __('Site Navigation') ); ?>" itemscope itemtype="https://schema.org/SiteNavigationElement">
        <?php
            wp_nav_menu( array(
                'theme_location'  => $nav,
                'container_class' => $nav,
                'fallback_cb'     => false,
            ) );
        ?>
    </nav>

    <main role="main" id="main-content">
        <div id="contact-sticky">
            <h3>Looking to Connect?</h3>
            <a href="/contact">Contact Us</a>
        </div>

        <div class="content <?php echo isset($args['page-slug']) ? esc_attr($args['page-slug']) : ''; ?>">
            <?php if ( ! empty( $args['is-single'] ) ) : ?>
                <div id="back-to-blogs">
                    <a href="<?php echo esc_url( home_url('/blog') ); ?>"><?php esc_html_e('Back to blogs'); ?></a>
                </div>
            <?php endif; ?>