<?php
/**
 * Email Verification Codes — framework primitive.
 *
 * Generic 6-digit code workflow used by signup, login, password reset, and
 * any other flow that needs out-of-band email confirmation. Codes are
 * one-shot, expire in 15 minutes, are attempt-counted (max 3), and the
 * email-side request rate is capped per address.
 *
 * Storage: a single table `{prefix}ffc_verification_codes` carries every
 * in-flight code regardless of purpose; callers pass a `type` string so
 * one flow's codes never validate against another's.
 *
 * Branded delivery: the email body is built from a tokenized HTML
 * template. Callers pass content strings (title, greeting, intro,
 * instruction, expiry notice, security notice); the footer branding is
 * filterable via the `firefly_verification_email_branding` hook so each
 * site can replace the default site-name footer without rewriting the
 * template.
 *
 * Promoted from swrr's per-template `2fa.php` with all volunteer-specific
 * code stripped (pending-volunteer table + signup-form schema). The pure
 * code primitive lives here; consumers wire their own pending-application
 * tables if they need to stash form data alongside an in-flight code.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// SCHEMA INSTALLATION
// =============================================================================

if ( ! defined( 'FIREFLY_EMAIL_VERIFICATION_DB_VERSION' ) ) {
    define( 'FIREFLY_EMAIL_VERIFICATION_DB_VERSION', '1' );
}

/**
 * Create the verification-codes table on first load (idempotent via dbDelta).
 * Tied to a version option so subsequent boots short-circuit.
 */
function firefly_email_verification_install_table() {
    $installed = get_option( 'firefly_email_verification_db_version' );
    if ( $installed === FIREFLY_EMAIL_VERIFICATION_DB_VERSION ) {
        return;
    }

    global $wpdb;
    $table_name      = $wpdb->prefix . 'ffc_verification_codes';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(255) NOT NULL,
        code VARCHAR(10) NOT NULL,
        type VARCHAR(32) NOT NULL,
        data LONGTEXT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        verified_at DATETIME NULL,
        attempts INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY email_type (email, type),
        KEY expires_at (expires_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'firefly_email_verification_db_version', FIREFLY_EMAIL_VERIFICATION_DB_VERSION );
}
add_action( 'init', 'firefly_email_verification_install_table' );

// =============================================================================
// CODE LIFECYCLE
// =============================================================================

/**
 * Generate a secure 6-digit verification code.
 */
function firefly_generate_verification_code() {
    return sprintf( '%06d', wp_rand( 100000, 999999 ) );
}

/**
 * Create a verification code record.
 *
 * @param string $email User's email address.
 * @param string $type  Purpose tag (signup/login/password-reset/etc.). Up to 32 chars.
 * @param array  $data  Optional payload to stash alongside the code (e.g. pending form data).
 * @return array        ['success' => bool, 'code_id' => int, 'code' => string] or ['success' => false, 'message' => string].
 */
function firefly_create_verification_code( $email, $type = 'signup', $data = array() ) {
    global $wpdb;

    $code       = firefly_generate_verification_code();
    $table_name = $wpdb->prefix . 'ffc_verification_codes';
    $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( 15 * 60 ) );
    $data_json  = ! empty( $data ) ? wp_json_encode( $data ) : null;

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'email'      => sanitize_email( $email ),
            'code'       => $code,
            'type'       => sanitize_text_field( $type ),
            'data'       => $data_json,
            'expires_at' => $expires_at,
            'created_at' => current_time( 'mysql', 1 ),
            'attempts'   => 0,
        ),
        array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
    );

    if ( $inserted ) {
        return array(
            'success' => true,
            'code_id' => $wpdb->insert_id,
            'code'    => $code,
        );
    }

    return array(
        'success' => false,
        'message' => 'Failed to create verification code',
    );
}

/**
 * Verify a submitted code against the most recent unverified entry for
 * email + type. On success, marks the row verified and returns any stashed data.
 *
 * Caller is responsible for calling firefly_increment_verification_attempts()
 * when verification fails — the attempt counter is a defense against guessing,
 * not part of the success path.
 */
function firefly_verify_code( $email, $code, $type = 'signup' ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ffc_verification_codes';
    $email      = sanitize_email( $email );
    $code       = sanitize_text_field( $code );

    $record = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table_name
        WHERE email = %s
        AND code = %s
        AND type = %s
        AND verified_at IS NULL
        AND expires_at > %s
        ORDER BY created_at DESC
        LIMIT 1",
        $email,
        $code,
        $type,
        current_time( 'mysql', 1 )
    ) );

    if ( ! $record ) {
        return array(
            'success' => false,
            'message' => 'Invalid or expired verification code',
        );
    }

    if ( $record->attempts >= 3 ) {
        return array(
            'success' => false,
            'message' => 'Too many verification attempts. Please request a new code.',
        );
    }

    $wpdb->update(
        $table_name,
        array( 'verified_at' => current_time( 'mysql', 1 ) ),
        array( 'id' => $record->id ),
        array( '%s' ),
        array( '%d' )
    );

    $data = ! empty( $record->data ) ? json_decode( $record->data, true ) : array();

    return array(
        'success' => true,
        'data'    => $data,
        'code_id' => $record->id,
    );
}

/**
 * Increment the attempt counter for the most recent unverified code for
 * email + type. Call on every failed verify_code() so the 3-strike kill
 * switch can fire.
 */
function firefly_increment_verification_attempts( $email, $type = 'signup' ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ffc_verification_codes';

    $wpdb->query( $wpdb->prepare(
        "UPDATE $table_name
        SET attempts = attempts + 1
        WHERE email = %s
        AND type = %s
        AND verified_at IS NULL
        AND expires_at > %s
        ORDER BY created_at DESC
        LIMIT 1",
        sanitize_email( $email ),
        $type,
        current_time( 'mysql', 1 )
    ) );
}

/**
 * Delete expired unverified codes. Hourly cron.
 */
function firefly_cleanup_expired_verification_codes() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ffc_verification_codes';

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM $table_name
        WHERE expires_at < %s
        AND verified_at IS NULL",
        current_time( 'mysql', 1 )
    ) );
}

if ( ! wp_next_scheduled( 'firefly_cleanup_verification_codes' ) ) {
    wp_schedule_event( time(), 'hourly', 'firefly_cleanup_verification_codes' );
}
add_action( 'firefly_cleanup_verification_codes', 'firefly_cleanup_expired_verification_codes' );

// =============================================================================
// RATE LIMITING
// =============================================================================

/**
 * Returns true if the caller has exceeded the per-email request rate for
 * this verification type within the trailing hour.
 *
 * Defaults: 3/hr for signup-like types, 5/hr for login. Override the
 * thresholds with the `firefly_verification_rate_limit` filter.
 */
function firefly_check_verification_rate_limit( $email, $type = 'signup' ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ffc_verification_codes';
    $email      = sanitize_email( $email );

    $max_attempts = ( $type === 'login' ) ? 5 : 3;
    $max_attempts = (int) apply_filters( 'firefly_verification_rate_limit', $max_attempts, $type, $email );

    $time_window = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

    $count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name
        WHERE email = %s
        AND type = %s
        AND created_at > %s",
        $email,
        $type,
        $time_window
    ) );

    return intval( $count ) >= $max_attempts;
}

/**
 * Minutes until the rate-limit window resets for this email + type.
 * Returns 0 when no codes were issued in the window.
 */
function firefly_get_verification_rate_limit_reset_time( $email, $type = 'signup' ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ffc_verification_codes';

    $oldest = $wpdb->get_var( $wpdb->prepare(
        "SELECT created_at FROM $table_name
        WHERE email = %s
        AND type = %s
        AND created_at > %s
        ORDER BY created_at ASC
        LIMIT 1",
        sanitize_email( $email ),
        $type,
        gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
    ) );

    if ( $oldest ) {
        $oldest_time       = strtotime( $oldest );
        $reset_time        = $oldest_time + HOUR_IN_SECONDS;
        $minutes_remaining = ceil( ( $reset_time - time() ) / 60 );
        return max( 1, $minutes_remaining );
    }

    return 0;
}

// =============================================================================
// EMAIL DELIVERY
// =============================================================================

/**
 * Send a verification code by email.
 *
 * @param string $email Recipient address.
 * @param string $code  6-digit code (typically the value returned from firefly_create_verification_code()).
 * @param array  $args  {
 *     Optional content overrides.
 *     @type string $subject     Email Subject:. Default "Your verification code".
 *     @type string $title       Header block H1. Default = subject.
 *     @type string $greeting    Lead-in line. Default "Hello,".
 *     @type string $intro       Body paragraph. Default empty.
 *     @type string $instruction Lead-in to the code block. Default "Use the code below to continue:".
 *     @type string $expiry      Expiry-notice text. Default "This code will expire in 15 minutes.".
 *     @type string $security    Security-notice text. Default a generic "ignore this email" reminder.
 * }
 * @return bool wp_mail() success.
 */
function firefly_send_verification_email( $email, $code, $args = array() ) {
    $defaults = array(
        'subject'     => __( 'Your verification code', 'firefly-collective' ),
        'title'       => '',
        'greeting'    => __( 'Hello,', 'firefly-collective' ),
        'intro'       => '',
        'instruction' => __( 'Use the code below to continue:', 'firefly-collective' ),
        'expiry'      => __( 'This code will expire in 15 minutes.', 'firefly-collective' ),
        'security'    => __( "If you didn't request this code, please ignore this email.", 'firefly-collective' ),
    );
    $args = wp_parse_args( $args, $defaults );
    if ( $args['title'] === '' ) {
        $args['title'] = $args['subject'];
    }

    $html = firefly_get_verification_email_html(
        $args['title'],
        $args['greeting'],
        $args['intro'],
        $args['instruction'],
        $code,
        $args['expiry'],
        $args['security']
    );

    add_filter( 'wp_mail_content_type', 'firefly_verification_email_html_content_type' );
    $sent = wp_mail( $email, $args['subject'], $html );
    remove_filter( 'wp_mail_content_type', 'firefly_verification_email_html_content_type' );

    return $sent;
}

/**
 * One-shot HTML content-type filter for verification emails so wp_mail
 * sends them as HTML without affecting any other outgoing mail.
 */
function firefly_verification_email_html_content_type() {
    return 'text/html';
}

/**
 * Build the verification-code email body. Callers pass content strings;
 * this function handles the markup, styling, and branding.
 */
function firefly_get_verification_email_html( $title, $greeting, $intro, $instruction, $code, $expiry, $security ) {
    $branding = apply_filters(
        'firefly_verification_email_branding',
        array(
            'name'    => get_bloginfo( 'name' ),
            'tagline' => get_bloginfo( 'description' ),
        )
    );

    $branding_name    = isset( $branding['name'] )    ? $branding['name']    : get_bloginfo( 'name' );
    $branding_tagline = isset( $branding['tagline'] ) ? $branding['tagline'] : '';

    $title       = esc_html( $title );
    $greeting    = esc_html( $greeting );
    $intro       = esc_html( $intro );
    $instruction = esc_html( $instruction );
    $code        = esc_html( $code );
    $expiry      = esc_html( $expiry );
    $security    = esc_html( $security );
    $bname       = esc_html( $branding_name );
    $btag        = esc_html( $branding_tagline );

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$title</title>
    <style>
        body { margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif; background-color:#f5f5f5; line-height:1.6; }
        .email-wrapper { max-width:600px; margin:0 auto; background-color:#ffffff; }
        .email-header { background:linear-gradient(135deg,#0066cc 0%,#0052a3 100%); padding:40px 30px; text-align:center; }
        .email-header h1 { margin:0; color:#ffffff; font-size:28px; font-weight:600; }
        .email-body { padding:40px 30px; }
        .email-body p { margin:0 0 20px 0; color:#333333; font-size:16px; }
        .greeting { font-size:18px; font-weight:600; color:#1f2937; margin-bottom:20px; }
        .code-container { background:linear-gradient(135deg,#f0f9ff 0%,#e0f2fe 100%); border:2px solid #0066cc; border-radius:12px; padding:30px; text-align:center; margin:30px 0; }
        .code-label { font-size:14px; color:#6b7280; text-transform:uppercase; letter-spacing:1px; margin-bottom:15px; font-weight:600; }
        .verification-code { font-size:42px; font-weight:700; color:#0066cc; letter-spacing:8px; font-family:'Courier New',Courier,monospace; margin:10px 0; }
        .expiry-notice { background-color:#fff3cd; border-left:4px solid #ffc107; padding:15px 20px; margin:25px 0; border-radius:4px; }
        .expiry-notice p { margin:0; color:#856404; font-size:14px; }
        .security-notice { background-color:#f0f0f0; padding:20px; border-radius:8px; margin-top:30px; }
        .security-notice p { margin:0; font-size:13px; color:#666666; }
        .email-footer { background-color:#f9fafb; padding:30px; text-align:center; border-top:1px solid #e5e7eb; }
        .email-footer p { margin:5px 0; font-size:13px; color:#6b7280; }
        .branding { font-weight:600; color:#0066cc; }
        @media only screen and (max-width:600px) {
            .email-header { padding:30px 20px; }
            .email-header h1 { font-size:24px; }
            .email-body { padding:30px 20px; }
            .code-container { padding:20px 15px; }
            .verification-code { font-size:28px; letter-spacing:4px; }
        }
        @media only screen and (max-width:400px) {
            .verification-code { font-size:24px; letter-spacing:3px; }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-header">
            <h1>$title</h1>
        </div>
        <div class="email-body">
            <p class="greeting">$greeting</p>
            <p>$intro</p>
            <p>$instruction</p>
            <div class="code-container">
                <div class="code-label">Your Verification Code</div>
                <div class="verification-code">$code</div>
            </div>
            <div class="expiry-notice">
                <p><strong>⏰ Important:</strong> $expiry</p>
            </div>
            <div class="security-notice">
                <p><strong>🔒 Security Notice:</strong> $security</p>
            </div>
        </div>
        <div class="email-footer">
            <p class="branding">$bname</p>
            <p>$btag</p>
        </div>
    </div>
</body>
</html>
HTML;
}
