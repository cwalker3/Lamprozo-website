<h1><?php echo esc_html($pageTitle); ?></h1>
<?php echo apply_filters('the_content', $postContent); ?>

<div class="signup-form">
    <div id="error-txt"></div>
    <div class="signup-method">
        <label>
            <input type="radio" name="signup-method" value="direct" checked>
            <?php esc_html_e('Direct Signup'); ?>
        </label>
        <label>
            <input type="radio" name="signup-method" value="third">
            <?php esc_html_e('Sign in with Third Party'); ?>
        </label>
    </div>
    <div id="direct-signup-fields">
        <input type="text" placeholder="<?php esc_attr_e('First Name'); ?>" id="signup-form-fname">
        <input type="text" placeholder="<?php esc_attr_e('Last Name'); ?>" id="signup-form-lname">
        <input type="email" placeholder="<?php esc_attr_e('Email'); ?>" id="signup-form-email">
        <input type="tel" placeholder="<?php esc_attr_e('Optional Phone'); ?>" id="signup-form-phone">
        <label>
            <input type="checkbox" id="enable-username-password">
            <?php esc_html_e('Create a username and password'); ?>
        </label>
        <div id="username-password-fields" style="display:none;">
            <input type="text" placeholder="<?php esc_attr_e('Username'); ?>" id="signup-form-username">
            <div class="password-field">
                <input type="password" placeholder="<?php esc_attr_e('Password'); ?>" id="signup-form-password">
                <span id="toggle-password" class="toggle-password">&#128065;</span>
            </div>
        </div>
        <div id="signup-btn"><button id="join-now-btn"><?php esc_html_e('Join Now'); ?></button></div>
    </div>
    <div id="third-party-signup-fields" style="display:none;">
        <div class="third-party-providers">
            <label><input type="radio" name="third-party-provider" value="google" checked> Google</label>
            <label class="party-disabled"><input type="radio" name="third-party-provider" value="facebook"> Facebook</label>
            <label class="party-disabled"><input type="radio" name="third-party-provider" value="twitter"> Twitter (X)</label>
            <label class="party-disabled"><input type="radio" name="third-party-provider" value="linkedin"> LinkedIn</label>
            <label class="party-disabled"><input type="radio" name="third-party-provider" value="apple"> Apple</label>
            <label class="party-disabled"><input type="radio" name="third-party-provider" value="microsoft"> Microsoft</label>
        </div>
        <div id="google-signin-btn">
            <button id="google-signin"><?php esc_html_e('Sign in with Google'); ?></button>
        </div>
    </div>
</div>
