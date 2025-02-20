<h1><?php echo esc_html($pageTitle); ?></h1>

<?php echo apply_filters('the_content', $postContent); ?>

<div class="signup-form">
    <div id="error-txt"></div>
    <input type="text" placeholder="<?php esc_attr_e('First Name'); ?>" id="signup-form-fname">
    <input type="text" placeholder="<?php esc_attr_e('Last Name'); ?>" id="signup-form-lname">
    <input type="email" placeholder="<?php esc_attr_e('Email'); ?>" id="signup-form-email">
    <input type="tel" placeholder="<?php esc_attr_e('Optional Phone'); ?>" id="signup-form-phone">
    
    <label>
        <input type="checkbox" id="enable-username-password">
        <?php esc_html_e('Create a username and password', 'alex-strait'); ?>
    </label>
    <div id="username-password-fields" style="display:none;">
        <input type="text" placeholder="<?php esc_attr_e('Username', 'alex-strait'); ?>" id="signup-form-username">
        <div class="password-field">
            <input type="password" placeholder="<?php esc_attr_e('Password', 'alex-strait'); ?>" id="signup-form-password">
            <span id="toggle-password" class="toggle-password">&#128065;</span>
        </div>
    </div>
    
    <div id="signup-btn"><button id="join-now-btn"><?php esc_html_e('Join Now'); ?></button></div>
</div>
