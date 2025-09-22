<?php

    // theme/views/dashboard.php

    global $current_user;
    global $template_path_web;
    global $is_campaign_mode;

?>

<?php if (!$is_campaign_mode) : ?>
    <p><?php echo apply_filters('the_content', $postContent); ?></p>
<?php endif; ?>
    
<?php if ($current_user->exists()) : ?>
    <!-- Only show profile stuff if NOT in campaign mode -->
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

    <!-- Subscription Management -->
    <div id="subscriptions-management" style="display: none;">
        <h2>Manage Subscriptions</h2>
        
        <div id="subscriptions-loading" class="loading-spinner">
            <img src="<?=$template_path_web?>/images/loading.gif" alt="Loading..." id="subs-loader">
            <p>Loading your subscriptions...</p>
        </div>
        
        <div id="subscriptions-container" class="subscriptions-container" style="display: none;">
            <!-- Subscriptions will be dynamically loaded here -->
        </div>
        
        <div id="no-subscriptions" style="display: none;">
            <p>You don't have any active subscriptions.</p>
        </div>
    </div>

    <!-- Update Payment Method Modal -->
    <div id="update-payment-modal" class="update-payment-modal" style="display: none;">
        <div class="update-payment-content">
            <h3>Update Payment Method</h3>
            <div id="update-payment-element">
                <!-- Stripe Elements will mount here -->
            </div>
            <div id="update-payment-error" style="color: red; margin-top: 10px; display: none;"></div>
            <div class="update-payment-actions">
                <button class="button" id="cancel-update-payment">Cancel</button>
                <button class="button button-primary" id="update-payment-submit">Update</button>
            </div>
            <img id="update-modal-loader" src="<?=$template_path_web?>/images/loading-dark.gif">
        </div>
    </div>

    <!-- Cancel Subscription Modal -->
    <div id="cancel-subscription-modal" class="update-payment-modal" style="display: none;">
        <div id="cancel-subscription-content">
            <h3>Cancel Subscription</h3>

            <p>Are you sure you want to cancel this subscription? This action cannot be undone.</p>
            <div class="cancel-actions">
                <button class="button" id="close-cancel-modal">Close</button>
                <button class="button button-danger" id="confirm-cancel-subscription">Confirm Cancel</button>
            </div>
        </div>
        <img id="cancel-modal-loader" src="<?=$template_path_web?>/images/loading.gif" style="display: none;">
    </div>
<?php endif; ?>

<?php if ($is_campaign_mode) : ?>
    <!-- Campaign Mode Notice -->
    <div id="campaign-head">
        <p><strong>Campaign Mode:</strong> This form has been pre-configured for a special offer.</p>
    </div>
<?php endif; ?>

<!-- Price Calculator Interface -->
<div id="price-calculator">
    <h2>Billing - Price Calculator</h2>
    <div id="features-container">
        <!-- JavaScript will dynamically build feature type blocks with instance UIs -->
    </div>
</div>

<!-- Invoice Summary -->
<div id="invoice-container">
    <h2>Your Invoice</h2>
    <div id="invoice-details">
        <!-- Itemized selections will be displayed here -->
    </div>
    <div id="invoice-total">
        <!-- Total price will be calculated and displayed here -->
    </div>
    <button id="pay-now">Pay Now</button>
</div>