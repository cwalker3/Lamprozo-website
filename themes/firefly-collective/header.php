<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?=get_bloginfo('name')?> - <?=$args['page-title']?></title>
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
    <div id="backdrop"></div>

    <header>
        <div id="nav-bar"<? if($args['is-user-logged-in'] && !$_COOKIE['auth_id']) {?> class="user-nav"<? } ?>></div>
        <div id="logo-name" <? if ($args['is-user-logged-in'] && !$_COOKIE['auth_id']) {?> class="user-nav"<?}?>>
            <div id="site-logo"><img src="<?php echo esc_url($args['theme-path'] . '/images/logo.webp'); ?>"></div>
            <div id="site-name"><?=get_bloginfo('name')?></div>
        </div>
    </header>  

    <div>
        <img id="hamburger"<? if($args['is-user-logged-in'] && !$_COOKIE['auth_id']) {?> class="user-nav"<? } ?> src="<?php echo esc_url($args['theme-path'] . '/images/hamburger.webp'); ?>" alt="<?php esc_attr_e('Menu'); ?>">
    </div>
    <div>
        <img id="close-nav-btn"<? if($args['is-user-logged-in'] && !$_COOKIE['auth_id']) {?> class="user-nav"<? } ?> src="<?php echo esc_url($args['theme-path'] . '/images/close-nav.webp'); ?>" alt="<?php esc_attr_e('Close Menu'); ?>">
    </div>
    <nav>
        <?php
        wp_nav_menu(array(
            'theme_location'  => 'website-menu',
            'container_class' => 'website-menu',
            'fallback_cb'     => false,
        ));
        ?>
    </nav>

    <main>
        <div id="contact-sticky">
            <h3>Looking to Connect?</h3>
            <a href="/contact">Contact Us</a>
        </div>
        <div class="content <?php echo esc_attr($args['page-slug']); ?>">
            <?php if ($args['is-single']) { ?>
                <div id="back-to-blogs"><a href="<?php echo esc_url(home_url('/blog')); ?>"><?php esc_html_e('Back to blogs'); ?></a></div>
            <?php } ?>
