<?php
    /**
     * firefly/contact-form — server render.
     *
     * Owns the form's markup; behavior (validation + submit) is bound by
     * `assets/js/contact.js` via the static IDs below. Editor edit() in
     * index.js mirrors this output so the canvas matches the frontend.
     */
    if ( ! defined( 'ABSPATH' ) ) exit;

    $name_ph    = isset( $attributes['namePlaceholder'] )    ? $attributes['namePlaceholder']    : 'Your name';
    $email_ph   = isset( $attributes['emailPlaceholder'] )   ? $attributes['emailPlaceholder']   : 'Email';
    $message_ph = isset( $attributes['messagePlaceholder'] ) ? $attributes['messagePlaceholder'] : 'What are you working on?';
    $submit     = isset( $attributes['submitLabel'] )        ? $attributes['submitLabel']        : 'Send';

    $wrap = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => 'contact-form' ) )
        : 'class="contact-form"';
?>
<div <?php echo $wrap; ?>>
    <input type="text"  id="contact-form-name"    placeholder="<?php echo esc_attr( $name_ph ); ?>">
    <input type="email" id="contact-form-email"   placeholder="<?php echo esc_attr( $email_ph ); ?>">
    <textarea           id="contact-form-message" placeholder="<?php echo esc_attr( $message_ph ); ?>"></textarea>
    <div id="send-message-btn"><button type="button"><?php echo esc_html( $submit ); ?></button></div>
</div>
