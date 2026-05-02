<?php
    /**
     * theme/templates/default/views/dashboard.php
     *
     * Authenticated dashboard. Static markup is the chrome (hero,
     * profile card, subscriptions panel, modals); the price calculator
     * (#features-container) is JS-built by dashboard.js. All static IDs
     * preserved so dashboard.js, profile updates, Stripe modals, and
     * subscriptions handlers keep working without changes.
     */

    if ( ! defined( 'ABSPATH' ) ) exit;

    global $current_user;
    global $template_path_web;
    global $is_campaign_mode;
?>

<?php
    /* Hero + any extra above-the-fold content lives in
       snippets/pages/dashboard.html as Gutenberg blocks. Edit the page
       in wp-admin (or edit the snippet file and run
       `firefly import default`) to change the chrome. The view below
       handles the dynamic, per-user pieces (profile form, subscriptions,
       price calculator) that don't belong in editable content. */
    if ( ! $is_campaign_mode ) {
        echo apply_filters( 'the_content', $postContent );
    }
?>

<?php if ( $current_user->exists() ) : ?>
<section class="dashboard-section">
    <div class="container">
        <div class="dashboard-grid">

            <!-- Profile card -->
            <article class="dash-card profile-card" id="profile-container">
                <header class="dash-card-head">
                    <div class="overline">Profile</div>
                    <h2>Account details</h2>
                </header>

                <form id="profile-form" class="form-stack">
                    <div class="form-row">
                        <span class="form-label">Username</span>
                        <span class="form-static"><?php echo esc_html( $current_user->user_login ); ?></span>
                    </div>

                    <label class="form-row">
                        <span class="form-label">First name</span>
                        <input type="text" id="profile-first-name" class="form-input" value="<?php echo esc_attr( $current_user->first_name ); ?>" autocomplete="given-name">
                    </label>

                    <label class="form-row">
                        <span class="form-label">Last name</span>
                        <input type="text" id="profile-last-name" class="form-input" value="<?php echo esc_attr( $current_user->last_name ); ?>" autocomplete="family-name">
                    </label>

                    <label class="form-row">
                        <span class="form-label">Email</span>
                        <input type="email" id="profile-email" class="form-input" value="<?php echo esc_attr( $current_user->user_email ); ?>" autocomplete="email">
                    </label>

                    <div id="profile-message" class="form-message" aria-live="polite"></div>

                    <div class="btn-row">
                        <button type="button" id="update-profile-btn" class="btn btn-primary">Update profile</button>
                        <button type="button" id="reset-password-btn" class="btn btn-ghost">Send password reset</button>
                    </div>
                </form>
            </article>

            <!-- Subscriptions card -->
            <article class="dash-card subs-card" id="subscriptions-management" style="display: none;">
                <header class="dash-card-head">
                    <div class="overline">Billing</div>
                    <h2>Manage subscriptions</h2>
                </header>

                <div id="subscriptions-loading" class="dash-loading">
                    <div class="dash-spinner" aria-hidden="true"></div>
                    <p>Loading your subscriptions…</p>
                    <img src="<?php echo esc_url( $template_path_web ); ?>/images/loading.gif" alt="" id="subs-loader" hidden>
                </div>

                <div id="subscriptions-container" class="subscriptions-container" style="display: none;">
                    <!-- Populated by dashboard.js -->
                </div>

                <div id="no-subscriptions" class="empty-state" style="display: none;">
                    <p>You don't have any active subscriptions.</p>
                </div>
            </article>

        </div>
    </div>
</section>

<!-- Update Payment Method Modal -->
<div id="update-payment-modal" class="dash-modal update-payment-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="update-payment-title">
    <div class="dash-modal-content update-payment-content">
        <h3 id="update-payment-title">Update payment method</h3>
        <div id="update-payment-element"><!-- Stripe Elements mount --></div>
        <div id="update-payment-error" class="form-message" style="display: none;"></div>
        <div class="btn-row update-payment-actions">
            <button type="button" class="btn btn-ghost" id="cancel-update-payment">Cancel</button>
            <button type="button" class="btn btn-primary" id="update-payment-submit">Update</button>
        </div>
        <img id="update-modal-loader" src="<?php echo esc_url( $template_path_web ); ?>/images/loading-dark.gif" alt="" hidden>
    </div>
</div>

<!-- Cancel Subscription Modal -->
<div id="cancel-subscription-modal" class="dash-modal update-payment-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="cancel-sub-title">
    <div id="cancel-subscription-content" class="dash-modal-content">
        <h3 id="cancel-sub-title">Cancel subscription</h3>
        <p>Are you sure you want to cancel this subscription? This action cannot be undone.</p>
        <div class="btn-row cancel-actions">
            <button type="button" class="btn btn-ghost" id="close-cancel-modal">Close</button>
            <button type="button" class="btn btn-danger" id="confirm-cancel-subscription">Confirm cancel</button>
        </div>
        <img id="cancel-modal-loader" src="<?php echo esc_url( $template_path_web ); ?>/images/loading.gif" alt="" hidden>
    </div>
</div>
<?php endif; ?>

<?php if ( $is_campaign_mode ) : ?>
<section class="dashboard-section">
    <div class="container">
        <div class="campaign-banner" id="campaign-head">
            <div class="overline">Campaign mode</div>
            <p>This form has been pre-configured for a special offer.</p>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Price Calculator -->
<section class="dashboard-section price-calculator-section" id="price-calculator">
    <div class="container">
        <header class="section-head">
            <div class="overline">Build your plan</div>
            <h2>Pricing — <span class="serif">configure as you go.</span></h2>
            <p class="lead">Pick the features you need. The invoice on the right updates as you select.</p>
        </header>

        <div class="calculator-grid">
            <div id="features-container" class="features-container">
                <!-- dashboard.js renders feature-type cards here -->
            </div>

            <aside class="invoice-card" id="invoice-container" aria-labelledby="invoice-heading">
                <header class="dash-card-head">
                    <div class="overline">Your invoice</div>
                    <h2 id="invoice-heading">Running total</h2>
                </header>

                <div id="invoice-details" class="invoice-details">
                    <!-- Itemized selections — dashboard.js populates -->
                </div>

                <div id="invoice-total" class="invoice-total">
                    <!-- Total — dashboard.js populates -->
                </div>

                <button type="button" id="pay-now" class="btn btn-primary btn-pay">Pay now</button>
            </aside>
        </div>
    </div>
</section>
