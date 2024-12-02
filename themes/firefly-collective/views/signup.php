<h1><?php echo esc_html($pageTitle); ?></h1>

<?php echo apply_filters('the_content', $postContent); ?>

<div class="signup-form">
    <div id="error-txt"></div>
    <input type="text" placeholder="<?php esc_attr_e('First Name'); ?>" id="signup-form-fname">
    <input type="text" placeholder="<?php esc_attr_e('Last Name'); ?>" id="signup-form-lname">
    <input type="email" placeholder="<?php esc_attr_e('Email'); ?>" id="signup-form-email">
    <input type="tel" placeholder="<?php esc_attr_e('Optional Phone'); ?>" id="signup-form-phone">
    <div id="signup-btn"><button><?php esc_html_e('Join Now'); ?></button></div>
</div>
