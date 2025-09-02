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
    <title><?php echo esc_html( get_bloginfo('name') . ' - ' . (isset($args['page-title']) ? $args['page-title'] : '') ); ?></title>
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
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

    <header>
        <div id="nav-bar"<?php echo $navbar_attr; ?>></div>

        <div id="logo-name"<?php echo $logo_class_attr; ?>>
            <div id="site-logo">
                <a href="/">
                    <img src="<?php echo esc_url( $template_path_web . '/images/logo.webp' ); ?>" alt="<?php echo esc_attr( get_bloginfo('name') ); ?>">
                </a>
            </div>
            <div id="site-name">
                <a href="/">
                    <?php echo esc_html( get_bloginfo('name') ); ?>
                </a>
            </div>
        </div>
    </header>

    <!-- Consolidated Overlay Menu System -->
    <?php if ($use_any_overlay && !$is_web_app): ?>
        <div id="overlay-menu-container" class="<?php echo $use_front_overlay ? 'front-page' : 'inner-page'; ?><?php echo $add_user_nav ? ' user-nav' : ''; ?>">
            <nav id="overlay-nav">
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
        <img id="hamburger"<?php echo $user_nav_attr; ?>
             src="<?php echo esc_url( $template_path_web . '/images/hamburger.webp' ); ?>"
             alt="<?php esc_attr_e('Menu'); ?>">
    </div>

    <div <?php if ($use_any_overlay && !$is_web_app):?>
            class="element-disable"
         <?php endif; ?>>
        <img id="close-nav-btn"<?php echo $user_nav_attr; ?>
             src="<?php echo esc_url( $template_path_web . '/images/close-nav.webp' ); ?>"
             alt="<?php esc_attr_e('Close Menu'); ?>">
    </div>

    <nav>
        <?php
            wp_nav_menu( array(
                'theme_location'  => $nav,
                'container_class' => $nav,
                'fallback_cb'     => false,
            ) );
        ?>
    </nav>

    <main>
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