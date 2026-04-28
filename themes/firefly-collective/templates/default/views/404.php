<?php
    /**
     * theme/templates/default/views/404.php
     *
     * System catch-all — no Gutenberg snippet (this URL never exists
     * as a wp-page). Markup is hand-rendered in the design system
     * idiom so the page reads as part of the template instead of
     * raw browser default.
     */

    if ( ! defined( 'ABSPATH' ) ) exit;
?>
<section class="hero">
    <div class="container">
        <div class="hero-copy reveal">
            <div class="overline"><?php esc_html_e( 'Error 404' ); ?></div>
            <h1 class="hero-headline">
                <?php esc_html_e( 'This route' ); ?>
                <span class="headline-accent"><?php esc_html_e( "doesn't exist" ); ?></span>
                <?php esc_html_e( 'yet.' ); ?>
            </h1>
            <p class="lead">
                <?php esc_html_e( "The URL you tried isn't registered in this template's schema. Check the path, or jump back to the home page and follow a link from there." ); ?>
            </p>
            <div class="hero-cta wp-block-buttons is-layout-flex">
                <div class="wp-block-button btn-primary">
                    <a class="wp-block-button__link" href="<?php echo esc_url( home_url( '/' ) ); ?>">
                        <?php esc_html_e( 'Back to home' ); ?>
                    </a>
                </div>
                <div class="wp-block-button btn-ghost">
                    <a class="wp-block-button__link" href="<?php echo esc_url( home_url( '/blog/' ) ); ?>">
                        <?php esc_html_e( 'Read the blog' ); ?>
                    </a>
                </div>
            </div>
            <div class="hero-meta">
                <span><span class="dot"></span><?php esc_html_e( 'static catch-all · no schema entry' ); ?></span>
                <span><?php esc_html_e( 'views/404.php · no snippet' ); ?></span>
            </div>
        </div>
    </div>
</section>
