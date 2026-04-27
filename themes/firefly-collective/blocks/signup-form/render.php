<?php
    /**
     * firefly/signup-form — server render.
     *
     * Owns the form's markup. Behavior (validation, OAuth, account
     * creation) is bound by `assets/js/signup.js` via the static IDs
     * preserved here. index.js edit() mirrors this output so the
     * editor canvas matches the frontend.
     */
    if ( ! defined( 'ABSPATH' ) ) exit;

    $a = wp_parse_args( isset( $attributes ) ? $attributes : array(), array(
        'directLabel'          => 'Direct Signup',
        'thirdPartyLabel'      => 'Sign in with Third Party',
        'fnamePlaceholder'     => 'First Name',
        'lnamePlaceholder'     => 'Last Name',
        'emailPlaceholder'     => 'Email',
        'phonePlaceholder'     => 'Optional Phone',
        'passwordToggleLabel'  => 'Create a username and password',
        'usernamePlaceholder'  => 'Username',
        'passwordPlaceholder'  => 'Password',
        'joinLabel'            => 'Join Now',
        'googleLabel'          => 'Sign in with Google',
    ) );

    $wrap = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => 'signup-form' ) )
        : 'class="signup-form"';
?>
<div <?php echo $wrap; ?>>
    <div id="error-txt"></div>

    <div class="signup-method">
        <label>
            <input type="radio" name="signup-method" value="direct" checked>
            <?php echo esc_html( $a['directLabel'] ); ?>
        </label>
        <label>
            <input type="radio" name="signup-method" value="third">
            <?php echo esc_html( $a['thirdPartyLabel'] ); ?>
        </label>
    </div>

    <div id="direct-signup-fields">
        <input type="text"  id="signup-form-fname" placeholder="<?php echo esc_attr( $a['fnamePlaceholder'] ); ?>">
        <input type="text"  id="signup-form-lname" placeholder="<?php echo esc_attr( $a['lnamePlaceholder'] ); ?>">
        <input type="email" id="signup-form-email" placeholder="<?php echo esc_attr( $a['emailPlaceholder'] ); ?>">
        <input type="tel"   id="signup-form-phone" placeholder="<?php echo esc_attr( $a['phonePlaceholder'] ); ?>">

        <label>
            <input type="checkbox" id="enable-username-password">
            <?php echo esc_html( $a['passwordToggleLabel'] ); ?>
        </label>

        <div id="username-password-fields" style="display:none;">
            <input type="text" id="signup-form-username" placeholder="<?php echo esc_attr( $a['usernamePlaceholder'] ); ?>">
            <div class="password-field">
                <input type="password" id="signup-form-password" placeholder="<?php echo esc_attr( $a['passwordPlaceholder'] ); ?>">
                <span id="toggle-password" class="toggle-password" aria-hidden="true">&#128065;</span>
            </div>
        </div>

        <div id="signup-btn">
            <button type="button" id="join-now-btn"><?php echo esc_html( $a['joinLabel'] ); ?></button>
        </div>
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
            <button type="button" id="google-signin"><?php echo esc_html( $a['googleLabel'] ); ?></button>
        </div>
    </div>
</div>
