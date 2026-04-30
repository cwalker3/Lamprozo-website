<?php
    /**
     * theme/templates/default/views/signup.php
     *
     * Thin wrapper. The hero copy + signup form (firefly/signup-form
     * block) + behind-the-scenes sections all live as Gutenberg blocks
     * in snippets/pages/signup.html. Frontend behavior is bound by
     * assets/js/signup.js via the static IDs the block renders.
     *
     * Edit the page in wp-admin, or edit the snippet file and run
     * `firefly import default` to redeploy.
     */

    if ( ! defined( 'ABSPATH' ) ) exit;
?>
<?php echo apply_filters( 'the_content', $postContent ); ?>
