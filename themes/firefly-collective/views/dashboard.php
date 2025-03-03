<?php
    $current_user = decrypt_current_user($_COOKIE['auth_id']);
?>
<h1><?php echo esc_html($pageTitle); ?></h1>
<p><?php echo apply_filters('the_content', $postContent); ?></p>

<?php if ( $current_user->exists() ) : ?>
    <div id="welcome-msg">
        Hi, <?php echo esc_html( $current_user->first_name ); ?>
    </div>
    <div id="profile-container">
        <form id="profile-form">
            <div>
                <div>Username: <b><?php echo esc_attr($current_user->user_login); ?></b></div>
            </div>
            <div>
                <div>First Name:</div>
                <div><input type="text" id="profile-first-name" value="<?php echo esc_attr($current_user->first_name); ?>"></div>
            </div>
            <div>
                <div>Last Name:</div>
                <div><input type="text" id="profile-last-name" value="<?php echo esc_attr($current_user->last_name); ?>"></div>
            </div>
            <div>
                <div>Email:</div>
                <div><input type="email" id="profile-email" value="<?php echo esc_attr($current_user->user_email); ?>"></div>
            </div>

            <div id="profile-message"></div>

            <div>
                <button type="button" id="update-profile-btn">Update Profile</button>
                <button type="button" id="reset-password-btn">Send Password Reset</button>
            </div>
        </form>
    </div>
<?php endif; ?>
