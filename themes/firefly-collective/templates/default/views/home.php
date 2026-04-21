<?php
    /**
     * theme/templates/default/views/home.php
     *
     * Firefly landing page wrapper. The entire landing is authored as Gutenberg
     * content in snippets/pages/home.html so non-developers can edit it from
     * wp-admin. This view exists only to:
     *   1) add the body class so home.css scopes apply
     *   2) render $postContent inside the .home-page container
     *
     * Edit the page content in wp-admin OR edit snippets/pages/home.html and
     * run `firefly import default` to redeploy.
     */

    if ( ! defined( 'ABSPATH' ) ) exit;

    add_filter( 'body_class', function ( $classes ) {
        $classes[] = 'page-home';
        return $classes;
    } );
?>

<div class="home-page" id="home-page">
    <?php echo $postContent; ?>
</div>
