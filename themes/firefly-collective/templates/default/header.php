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

        // Reliable Customizer preview (iframe) detection across navigations:
        $in_customizer  = (
            isset($_GET['customize_messenger_channel']) ||
            isset($_GET['customize_changeset_uuid']) ||
            ( isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe' )
        );

        $add_user_nav   = $is_logged_in && $no_auth_cookie && ! $in_customizer;

        $user_nav_attr = $add_user_nav ? ' class="user-nav"' : '';
        $theme_path    = isset($args['theme-path']) ? $args['theme-path'] : get_stylesheet_directory_uri();

        $use_menu_overlay = (bool) template_get_option('menu_overlay', $in_customizer);
    ?>

    <header <?php if ($use_menu_overlay):?>
                class="element-disable"
            <?php endif; ?>>
        <div id="nav-bar"<?php echo $user_nav_attr; ?>></div>

        <div id="logo-name"<?php echo $user_nav_attr; ?>>
            <div id="site-logo">
                <img src="<?php echo esc_url( $template_path_web . '/images/logo.webp' ); ?>" alt="<?php echo esc_attr( get_bloginfo('name') ); ?>">
            </div>
            <div id="site-name"><?php echo esc_html( get_bloginfo('name') ); ?></div>
        </div>
    </header>

    <div <?php if ($use_menu_overlay):?>
            class="element-disable"
         <?php endif; ?>>
        <img id="hamburger"<?php echo $user_nav_attr; ?>
             src="<?php echo esc_url( $template_path_web . '/images/hamburger.webp' ); ?>"
             alt="<?php esc_attr_e('Menu'); ?>">
    </div>

    <div <?php if ($use_menu_overlay):?>
            class="element-disable"
         <?php endif; ?>>
        <img id="close-nav-btn"<?php echo $user_nav_attr; ?>
             src="<?php echo esc_url( $template_path_web . '/images/close-nav.webp' ); ?>"
             alt="<?php esc_attr_e('Close Menu'); ?>">
    </div>

    <nav>
        <?php
            wp_nav_menu( array(
                'theme_location'  => 'website-menu',
                'container_class' => 'website-menu',
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
