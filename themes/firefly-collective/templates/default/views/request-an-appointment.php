<?php
    /**
     * theme/templates/default/views/request-an-appointment.php
     *
     * Hero (overline + headline + lead + meta) lives in
     * snippets/pages/request-an-appointment.html as Gutenberg blocks.
     * The booking form + calendar widget stay as PHP markup here
     * because their behavior is wired by calendar.js +
     * request-an-appointment.js to the static IDs below.
     *
     * Edit copy in wp-admin or in the snippet file; redeploy with
     * `firefly import default`.
     */

    if ( ! defined( 'ABSPATH' ) ) exit;
?>
<?php echo apply_filters( 'the_content', $postContent ); ?>

<section class="appt-section">
    <div class="container">
        <div class="appt-grid">
            <div class="book-an-appointment-form">
                <div id="error-txt"></div>
                <input type="text"  id="book-an-appointment-form-fname"   placeholder="<?php esc_attr_e( 'First name' ); ?>">
                <input type="text"  id="book-an-appointment-form-lname"   placeholder="<?php esc_attr_e( 'Last name' ); ?>">
                <input type="email" id="book-an-appointment-form-email"   placeholder="<?php esc_attr_e( 'Email' ); ?>">
                <input type="tel"   id="book-an-appointment-form-phone"   placeholder="<?php esc_attr_e( 'Optional phone' ); ?>">
                <textarea           id="book-an-appointment-form-message" placeholder="<?php esc_attr_e( 'What should we cover?' ); ?>"></textarea>
                <select             id="book-an-appointment-type">
                    <option value="General"><?php esc_html_e( 'General appointment' ); ?></option>
                </select>
                <div id="book-an-appointment-btn">
                    <button type="button"><?php esc_html_e( 'Request appointment' ); ?></button>
                </div>
            </div>

            <div class="appt-calendar-card">
                <div id="calendar-container"></div>
            </div>
        </div>
    </div>
</section>
