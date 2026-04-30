<?php
    /**
     * theme/templates/default/views/contact.php
     *
     * Thin wrapper. The hero copy + contact form + behind-the-scenes
     * sections all live as Gutenberg blocks in
     * snippets/pages/contact.html so non-developers can edit them in
     * wp-admin. The form's frontend behavior is bound by
     * assets/js/contact.js via the firefly/contact-form block markup.
     *
     * Edit the page in wp-admin, OR edit the snippet file and run
     * `firefly import default` to redeploy.
     */

    if ( ! defined( 'ABSPATH' ) ) exit;
?>
<?php echo apply_filters( 'the_content', $postContent ); ?>
