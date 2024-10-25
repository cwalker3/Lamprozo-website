<h1><?php echo esc_html($pageTitle); ?></h1>

<?php echo apply_filters('the_content', $postContent); ?>

<div class="contact-form">
    <input type="text" placeholder="<?php esc_attr_e('Name', 'your-theme-textdomain'); ?>" id="contact-form-name">
    <input type="email" placeholder="<?php esc_attr_e('Email', 'your-theme-textdomain'); ?>" id="contact-form-email">
    <textarea id="contact-form-message" placeholder="<?php esc_attr_e('Message', 'your-theme-textdomain'); ?>"></textarea>
    <div id="send-message-btn"><button><?php esc_html_e('Send', 'your-theme-textdomain'); ?></button></div>
</div>
