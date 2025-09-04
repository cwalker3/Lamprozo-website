<?php echo apply_filters('the_content', $postContent); ?>

<div class="contact-form">
    <input type="text" placeholder="<?php esc_attr_e('Name'); ?>" id="contact-form-name">
    <input type="email" placeholder="<?php esc_attr_e('Email'); ?>" id="contact-form-email">
    <textarea id="contact-form-message" placeholder="<?php esc_attr_e('Message'); ?>"></textarea>
    <div id="send-message-btn"><button><?php esc_html_e('Send'); ?></button></div>
</div>
