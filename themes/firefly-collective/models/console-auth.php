<?php
/**
 * theme/models/console-auth.php
 *
 * Sends logged-out users to the Firefly panel console for their own site.
 *
 * Sites are console-only for login: nginx 403s wp-login.php (GET and POST) and
 * xmlrpc.php, and the only way into wp-admin is the console's one-click magic
 * login (/?ffc_magic=<token>), which sets the auth cookie directly. Logout was
 * the leftover — it dead-ended on the deprecated /ffc-login/ form, which posts to
 * wp-login.php and therefore can never succeed.
 *
 * This is a FRAMEWORK model: it lives outside templates/ so every template
 * inherits one implementation. Nothing here is domain-specific — the console
 * host is derived from whatever site it runs on.
 *
 * /ffc-login/ and CUSTOM_LOGIN_SLUG are intentionally left in place; other flows
 * (campaign tokens, signin views) still reference them. This only changes where
 * a logged-out user is *sent*.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Second-level TLDs where the registrable domain is three labels, not two, so
 * "shop.example.co.uk" isn't mistaken for an apex. Not exhaustive — anything
 * unusual should use the firefly_console_host filter or the console_host mod.
 */
function firefly_console_multipart_tlds() {
    return apply_filters( 'firefly_console_multipart_tlds', array(
        'co.uk', 'org.uk', 'me.uk', 'com.au', 'net.au', 'org.au',
        'co.nz', 'co.za', 'com.br', 'co.jp', 'com.mx',
    ) );
}

/**
 * Derive the console hostname for a site host.
 *
 *   lamprozo.com                 -> console.lamprozo.com
 *   dev.fireflycreative.io       -> console.fireflycreative.io   (dev. stripped first)
 *   matt1.fireflycollective.org  -> console-matt1.fireflycollective.org
 *
 * That last case is FLAT on purpose: the wildcard certificate only covers
 * single-label subdomains, so console.matt1.<parent> would break TLS.
 *
 * Returns '' for hosts that can't have one (localhost, bare IPs), which lets
 * callers fall back to their previous behaviour on local installs.
 *
 * @param string $host Optional host to derive from. Defaults to this site's.
 * @return string Console host, or '' when none applies.
 */
function firefly_console_host( $host = '' ) {
    if ( '' === $host ) {
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
    }

    $host = strtolower( trim( (string) $host ) );

    // Drop a port if one came along (localhost:8082).
    if ( false !== strpos( $host, ':' ) ) {
        $host = strtok( $host, ':' );
    }

    $console = '';

    // Single-label hosts and raw IPs (local installs) derive nothing; the
    // override and filter below can still supply one.
    if ( '' !== $host && false !== strpos( $host, '.' ) && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
        // A dev (or www) site shares its parent's console.
        $bare = preg_replace( '/^(dev|www)\./', '', $host );

        $labels    = explode( '.', $bare );
        $last_two  = implode( '.', array_slice( $labels, -2 ) );
        $apex_size = in_array( $last_two, firefly_console_multipart_tlds(), true ) ? 3 : 2;

        if ( count( $labels ) <= $apex_size ) {
            $console = 'console.' . $bare;                   // plain site
        } else {
            $label   = array_shift( $labels );               // nested / demo site
            $console = 'console-' . $label . '.' . implode( '.', $labels );
        }
    }

    // An explicit console_host theme mod wins over the derivation.
    $override = trim( (string) get_theme_mod( 'console_host', '' ) );
    if ( '' !== $override ) {
        $console = $override;
    }

    /**
     * Final say on the console host. Applied even when nothing was derived, so
     * a filter can supply one for a host shape this doesn't cover.
     */
    return (string) apply_filters( 'firefly_console_host', $console, $host );
}

/**
 * Full URL to this site's console. '' when the host has no console.
 *
 * The console bounces unauthenticated visitors to its own login, so the bare
 * root is the right target.
 *
 * @param string $path Path to append. Defaults to '/'.
 * @return string
 */
function firefly_console_url( $path = '/' ) {
    $host = firefly_console_host();
    $url  = '' === $host ? '' : 'https://' . $host . '/' . ltrim( (string) $path, '/' );

    return (string) apply_filters( 'firefly_console_url', $url, $host, $path );
}

/**
 * Path the console shows a signed-in user: the "choose where to go" splash with
 * cards for Production admin, Development admin, and Site Operations.
 * (panel/app/routes/client.py — GET /dashboard)
 */
if ( ! defined( 'FIREFLY_CONSOLE_SPLASH_PATH' ) ) {
    define( 'FIREFLY_CONSOLE_SPLASH_PATH', '/dashboard' );
}

/**
 * Send logout to the console SPLASH, not to the console root.
 *
 * Two things this gets right:
 *  - wp-login.php?loggedout=true (WordPress's default) is 403'd by nginx, so
 *    logout used to dead-end.
 *  - The user stays signed in to the console. Logging out of WordPress should
 *    hand them back to the splash so they can pick another environment; it is
 *    not a request to end their console session.
 */
add_filter( 'logout_redirect', 'firefly_console_logout_redirect', 10, 3 );
function firefly_console_logout_redirect( $redirect_to, $requested_redirect_to, $user ) {
    $splash = firefly_console_url( FIREFLY_CONSOLE_SPLASH_PATH );

    return '' !== $splash ? $splash : $redirect_to;
}

/**
 * REQUIRED, and easy to miss: logout goes through wp_safe_redirect(), which
 * silently rewrites any off-host URL back to this site's home. The console is a
 * different host, so without this the redirect above looks like it does nothing.
 * Only the computed console host is added.
 */
add_filter( 'allowed_redirect_hosts', 'firefly_console_allowed_hosts' );
function firefly_console_allowed_hosts( $hosts ) {
    $console = firefly_console_host();
    if ( '' !== $console && ! in_array( $console, (array) $hosts, true ) ) {
        $hosts[] = $console;
    }

    return $hosts;
}
