<?php
    /**
     * theme/templates/default/views/order-history.php
     *
     * Thin wrapper. Hero + container live here in the design system;
     * the actual orders table comes from the firefly-collective plugin's
     * orders.php (Vue-mounted via plugin/assets/js/orders.js).
     */

    if ( ! defined( 'ABSPATH' ) ) exit;

    global $current_user;
?>

<?php
    /* Hero lives in snippets/pages/order-history.html as Gutenberg
       blocks — edit there for WYSIWYG. The orders table itself
       (Vue-mounted by the plugin's orders.js) stays here as PHP since
       it depends on REST data, not editable content. */
    echo apply_filters( 'the_content', $postContent );
?>

<section class="dashboard-section orders-section">
    <div class="container">
<?php
    require_once $backend_plugin_path . '/views/orders.php';
?>
    </div>
</section>
