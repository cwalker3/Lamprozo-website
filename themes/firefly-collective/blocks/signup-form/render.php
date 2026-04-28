<?php
    /**
     * firefly/signup-form — server render.
     *
     * Owns the form's markup. Behavior (validation, OAuth, account
     * creation) is bound by `assets/js/signup.js` via the static IDs
     * preserved here. index.js edit() mirrors this output so the
     * editor canvas matches the frontend.
     *
     * IMPORTANT: every <input> is wrapped in a <div class="field"> so
     * wpautop (run by `the_content` on the snippet's block output)
     * sees block-level siblings between newlines and doesn't sprinkle
     * <br>/<p> tags through the form. Without these wrappers, direct
     * mode rendered ~100px taller than third-party mode and the
     * container reflowed on toggle.
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

    <div class="signup-method"><label><input type="radio" name="signup-method" value="direct" checked><span><?php echo esc_html( $a['directLabel'] ); ?></span></label><label><input type="radio" name="signup-method" value="third"><span><?php echo esc_html( $a['thirdPartyLabel'] ); ?></span></label></div>

    <div id="direct-signup-fields">
        <div class="field"><input type="text"  id="signup-form-fname" placeholder="<?php echo esc_attr( $a['fnamePlaceholder'] ); ?>"></div>
        <div class="field"><input type="text"  id="signup-form-lname" placeholder="<?php echo esc_attr( $a['lnamePlaceholder'] ); ?>"></div>
        <div class="field"><input type="email" id="signup-form-email" placeholder="<?php echo esc_attr( $a['emailPlaceholder'] ); ?>"></div>
        <div class="field"><input type="tel"   id="signup-form-phone" placeholder="<?php echo esc_attr( $a['phonePlaceholder'] ); ?>"></div>
        <div class="field"><label class="password-toggle"><input type="checkbox" id="enable-username-password"><span><?php echo esc_html( $a['passwordToggleLabel'] ); ?></span></label></div>
        <div id="username-password-fields" style="display:none;">
            <div class="field"><input type="text" id="signup-form-username" placeholder="<?php echo esc_attr( $a['usernamePlaceholder'] ); ?>"></div>
            <div class="field password-field"><input type="password" id="signup-form-password" placeholder="<?php echo esc_attr( $a['passwordPlaceholder'] ); ?>"><span id="toggle-password" class="toggle-password" aria-hidden="true">&#128065;</span></div>
        </div>
        <div id="signup-btn"><button type="button" id="join-now-btn"><?php echo esc_html( $a['joinLabel'] ); ?></button></div>
    </div>

    <div id="third-party-signup-fields" style="display:none;">
        <div class="third-party-providers"><label><input type="radio" name="third-party-provider" value="google" checked><span>Google</span></label><label class="party-disabled"><input type="radio" name="third-party-provider" value="facebook"><span>Facebook</span></label><label class="party-disabled"><input type="radio" name="third-party-provider" value="twitter"><span>Twitter (X)</span></label><label class="party-disabled"><input type="radio" name="third-party-provider" value="linkedin"><span>LinkedIn</span></label><label class="party-disabled"><input type="radio" name="third-party-provider" value="apple"><span>Apple</span></label><label class="party-disabled"><input type="radio" name="third-party-provider" value="microsoft"><span>Microsoft</span></label></div>
        <div id="google-signin-btn"><button type="button" id="google-signin"><?php echo esc_html( $a['googleLabel'] ); ?></button></div>
    </div>
</div>
