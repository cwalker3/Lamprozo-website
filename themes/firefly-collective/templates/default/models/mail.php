<?php

/**
 * Outgoing HTML mail for the template.
 *
 * NOTHING HERE IS HARDCODED TO A DOMAIN. This is base-system code that ships to
 * every forked site, so the sender identity and the admin recipient are derived
 * from the site itself. Both were previously literal fireflycollective.org
 * addresses, which meant a fork mailed the wrong company and sent From an
 * address it does not own — a reliable way to land in spam, since the sending
 * server has no authority over that domain and SPF/DKIM cannot pass.
 *
 * Each piece has a filter for sites that need to differ from their own
 * admin_email.
 */

/** Address the site's own notifications go to. */
function firefly_mail_admin_recipient() {
    return apply_filters( 'firefly_mail_admin_recipient', get_option( 'admin_email' ) );
}

/** Address outgoing mail is sent FROM. Must be a domain this site can sign. */
function firefly_mail_from_address() {
    $from = get_option( 'admin_email' );
    if ( ! $from || ! is_email( $from ) ) {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        $host = $host ? preg_replace( '/^www\./', '', $host ) : 'localhost';
        $from = 'no-reply@' . $host;
    }
    return apply_filters( 'firefly_mail_from_address', $from );
}

/** Display name on outgoing mail. */
function firefly_mail_from_name() {
    return apply_filters( 'firefly_mail_from_name', get_bloginfo( 'name' ) );
}

/**
 * Send an HTML email.
 *
 * @param string      $to       Recipient. Ignored when $admin is true.
 * @param string      $subject  Subject line.
 * @param string      $html     HTML body.
 * @param bool        $admin    Route to the site's own inbox instead of $to.
 * @param string|null $reply_to Address a reply should reach. Notification mail
 *                              about a visitor is useless if hitting Reply
 *                              lands on the site's own no-reply address — pass
 *                              the visitor's address and Reply just works.
 */
function send_html_mail( $to, $subject, $html, $admin = false, $reply_to = null ) {

    if ( $admin ) {
        $to = firefly_mail_admin_recipient();
    }

    $from  = firefly_mail_from_address();
    $reply = ( $reply_to && is_email( $reply_to ) ) ? $reply_to : $from;

    $headers = array(
        'From: ' . firefly_mail_from_name() . ' <' . $from . '>',
        'Reply-To: ' . $reply,
        'Content-Type: text/html; charset=UTF-8',
    );

    wp_mail( $to, $subject, $html, $headers );
}
