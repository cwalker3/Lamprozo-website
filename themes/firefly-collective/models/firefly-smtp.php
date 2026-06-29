<?php
/**
 * Firefly Framework - SMTP Mailer (shared across all templates/sites)
 *
 * Routes wp_mail() through an authenticated SMTP relay (port 587) when SMTP
 * credentials are defined in wp-config.php. Firefly servers have no local MTA
 * and outbound port 25 is blocked, so a relay is the only way mail can leave.
 *
 * Each site enables it by defining these in wp-config.php (per-environment,
 * never committed or synced):
 *
 *   define( 'FIREFLY_SMTP_HOST', 'smtp.gmail.com' );
 *   define( 'FIREFLY_SMTP_PORT', 587 );                  // 587 = STARTTLS
 *   define( 'FIREFLY_SMTP_USER', 'you@yourdomain.com' ); // auth user
 *   define( 'FIREFLY_SMTP_PASS', 'app-password' );       // app password, no spaces
 *   define( 'FIREFLY_SMTP_FROM', 'you@yourdomain.com' ); // optional; defaults to USER
 *   define( 'FIREFLY_SMTP_FROM_NAME', 'Your Site' );     // optional; defaults to site name
 *   define( 'FIREFLY_SMTP_SECURE', 'tls' );              // optional; tls (587) or ssl (465)
 *
 * For Gmail/Workspace the From address must be the authenticated account (or an
 * alias of it), so leave FIREFLY_SMTP_FROM equal to FIREFLY_SMTP_USER. With no
 * constants defined, wp_mail() uses WordPress' default mailer.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Point PHPMailer at the configured SMTP relay.
 */
function firefly_configure_smtp( $phpmailer ) {
    if ( ! defined( 'FIREFLY_SMTP_HOST' ) || ! FIREFLY_SMTP_HOST ) {
        return; // No relay configured for this environment.
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = FIREFLY_SMTP_HOST;
    $phpmailer->Port       = defined( 'FIREFLY_SMTP_PORT' ) ? (int) FIREFLY_SMTP_PORT : 587;
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = defined( 'FIREFLY_SMTP_USER' ) ? FIREFLY_SMTP_USER : '';
    $phpmailer->Password   = defined( 'FIREFLY_SMTP_PASS' ) ? FIREFLY_SMTP_PASS : '';
    $phpmailer->SMTPSecure = defined( 'FIREFLY_SMTP_SECURE' ) ? FIREFLY_SMTP_SECURE : 'tls';

    // A real, authenticated From keeps the relay from rejecting the message.
    $from      = defined( 'FIREFLY_SMTP_FROM' ) && FIREFLY_SMTP_FROM ? FIREFLY_SMTP_FROM : $phpmailer->Username;
    $from_name = defined( 'FIREFLY_SMTP_FROM_NAME' ) ? FIREFLY_SMTP_FROM_NAME : get_bloginfo( 'name' );
    if ( $from ) {
        $phpmailer->setFrom( $from, $from_name, false );
    }
}
add_action( 'phpmailer_init', 'firefly_configure_smtp' );
