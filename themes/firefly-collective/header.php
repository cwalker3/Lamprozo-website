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
        <div id="nav-bar"<? if($args['is-user-logged-in']) {?> class="user-nav"<? } ?>></div>
    </header>  

    <div>
        <img id="hamburger"<? if($args['is-user-logged-in']) {?> class="user-nav"<? } ?> src="<?php echo esc_url($args['theme-path'] . '/images/hamburger.webp'); ?>" alt="<?php esc_attr_e('Menu', 'your-theme-textdomain'); ?>">
    </div>
    <div>
        <img id="close-nav-btn"<? if($args['is-user-logged-in']) {?> class="user-nav"<? } ?> src="<?php echo esc_url($args['theme-path'] . '/images/close-nav.webp'); ?>" alt="<?php esc_attr_e('Close Menu', 'your-theme-textdomain'); ?>">
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
        <div class="content <?php echo esc_attr($args['page-slug']); ?>">
            <?php if ($args['is-single']) { ?>
                <div id="back-to-blogs"><a href="<?php echo esc_url(home_url('/blog')); ?>"><?php esc_html_e('Back to blogs', 'your-theme-textdomain'); ?></a></div>
            <?php } ?>
