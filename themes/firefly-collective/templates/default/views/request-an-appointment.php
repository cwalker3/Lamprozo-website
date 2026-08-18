<?php

    /**
     * theme/templates/default/views/request-an-appointment.php
     *
     * The booking widget. Three steps in one card: month grid -> time chips ->
     * details form -> booked. Everything is driven by assets/js/booking.js,
     * which fetches availability from custom-api/v1/booking-slots and POSTs to
     * custom-api/v1/book-appointment (plugin model: bookings.php).
     *
     * Replaces the v1 request flow, where an admin hand-opened each slot and a
     * visitor's request claimed the row. Availability is now rules-based:
     * weekly hours generate slots, minus manual blocks, minus confirmed
     * appointments, minus the owner's external calendar busy times.
     *
     * THE IDS BELOW ARE THE CONTRACT with booking.js — restyle freely, rename
     * classes freely, but do not change an id without changing the script.
     *
     * Page copy (heading, lead) lives in
     * snippets/pages/request-an-appointment.html as Gutenberg blocks and is
     * echoed by $postContent below. Edit copy there, then
     * `firefly import default`.
     */

    if ( ! defined( 'ABSPATH' ) ) exit;

?>

<section class="ffc-booking">
    <div class="container ffc-booking__grid">

        <div class="ffc-booking__pitch">
            <?php echo apply_filters( 'the_content', $postContent ); ?>
        </div>

        <div class="ffc-booking__panel">
            <div class="ffc-booking__card" id="ffc-booking-widget">

                <h2 class="ffc-booking__title" id="ffc-booking-type-title"><?php esc_html_e( 'Book a call' ); ?></h2>
                <p class="ffc-booking__note" id="ffc-booking-type-note"><?php esc_html_e( 'Pick a day, then a time.' ); ?></p>

                <?php /* NOTE for future fields: templates that run a wpautop
                         cleaner strip EMPTY <p> elements on load, which would
                         eat a placeholder this widget fills later. Keep those
                         as div or span. */ ?>
                <div id="ffc-booking-error" role="alert" aria-live="polite"></div>

                <?php /* Steps 1 + 2: month grid and time chips. */ ?>
                <div id="ffc-booking-cal">
                    <div class="ffc-booking__monthbar">
                        <button type="button" id="ffc-cal-prev" class="ffc-booking__nav" aria-label="<?php esc_attr_e( 'Previous month' ); ?>">&larr;</button>
                        <span id="ffc-cal-title" class="ffc-booking__month"></span>
                        <button type="button" id="ffc-cal-next" class="ffc-booking__nav" aria-label="<?php esc_attr_e( 'Next month' ); ?>">&rarr;</button>
                    </div>
                    <div class="ffc-booking__dow" aria-hidden="true">
                        <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
                    </div>
                    <div id="ffc-cal-days" class="ffc-booking__days"></div>

                    <?php /* The wrapper animates OPEN rather than toggling
                             [hidden], so the card grows into the times instead
                             of jumping. That needs two elements: the outer is a
                             grid whose single row animates 0fr -> 1fr, the
                             inner clips it while it does. A height transition
                             cannot be used because the number of chips — and
                             therefore the final height — is not known until
                             they render. */ ?>
                    <div id="ffc-booking-slots-wrap" class="ffc-booking__slotswrap">
                        <div class="ffc-booking__slotswrap-inner">
                            <div class="ffc-booking__slots-day" id="ffc-slots-day"></div>
                            <div id="ffc-booking-slots" class="ffc-booking__slots"></div>
                        </div>
                    </div>
                    <div class="ffc-booking__tz" id="ffc-booking-tz"></div>
                </div>

                <?php /* Step 3: details, revealed once a time is chosen. */ ?>
                <div id="ffc-booking-details" hidden>
                    <p class="ffc-booking__chosen">
                        <span id="ffc-chosen-label"></span>
                        <button type="button" id="ffc-chosen-change" class="ffc-booking__change"><?php esc_html_e( 'change' ); ?></button>
                    </p>

                    <div class="ffc-field">
                        <label class="ffc-field__label" for="ffc-booking-name"><?php esc_html_e( 'Name' ); ?></label>
                        <input class="ffc-field__input" id="ffc-booking-name" type="text" autocomplete="name" required aria-required="true">
                    </div>
                    <div class="ffc-field">
                        <label class="ffc-field__label" for="ffc-booking-email"><?php esc_html_e( 'Email' ); ?></label>
                        <input class="ffc-field__input" id="ffc-booking-email" type="email" autocomplete="email" required aria-required="true">
                    </div>
                    <div class="ffc-field">
                        <label class="ffc-field__label" for="ffc-booking-phone"><?php esc_html_e( 'Phone' ); ?><span class="ffc-field__opt"><?php esc_html_e( 'optional' ); ?></span></label>
                        <input class="ffc-field__input" id="ffc-booking-phone" type="tel" autocomplete="tel">
                    </div>
                    <div class="ffc-field">
                        <label class="ffc-field__label" for="ffc-booking-notes"><?php esc_html_e( 'Anything we should know?' ); ?><span class="ffc-field__opt"><?php esc_html_e( 'optional' ); ?></span></label>
                        <textarea class="ffc-field__input ffc-field__input--area" id="ffc-booking-notes" rows="3"
                                  placeholder="<?php esc_attr_e( 'A sentence about what you want to cover.' ); ?>"></textarea>
                    </div>

                    <div id="ffc-booking-submit" class="ffc-booking__submit">
                        <input type="button" class="ffc-btn ffc-btn--accent" value="<?php esc_attr_e( 'Confirm booking' ); ?>">
                    </div>
                </div>

                <?php /* Success. booking.js fills the when and wires the .ics. */ ?>
                <div id="ffc-booking-success" class="ffc-booking__success" hidden>
                    <div class="ffc-booking__tick" aria-hidden="true"><span>&#10003;</span></div>
                    <h3 class="ffc-booking__success-title"><?php esc_html_e( 'You are booked' ); ?></h3>
                    <div class="ffc-booking__success-when" id="ffc-success-when"></div>
                    <p class="ffc-booking__success-note"><?php esc_html_e( 'A calendar invite is on its way to your email. Most mail apps add it automatically.' ); ?></p>
                    <a id="ffc-success-ics" class="ffc-btn ffc-btn--ghost" download="appointment.ics"><?php esc_html_e( 'Download the invite (.ics)' ); ?></a>
                </div>

            </div>
        </div>

    </div>
</section>
