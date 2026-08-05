<?php
/**
 * Redirects - Theme Model
 *
 * Permanent redirects for URLs this site used to serve.
 *
 * WordPress already forwards an old slug to a new one via its `_wp_old_slug`
 * record — but ONLY when the rename happens inside wp-admin. Firefly slugs are
 * declared in the template schema and deployed by the CLI, which writes
 * post_name directly, so no old-slug record is ever created and the previous
 * URL simply 404s. This is that safety net, and it matters most right before a
 * launch or a restructure, when old URLs are already indexed and linked.
 *
 * 301 rather than 302: the old URL is gone for good, which is what tells search
 * engines and existing bookmarks to move across instead of continuing to ask.
 *
 * Ships EMPTY. Add entries as pages are renamed or retired.
 */
if (!defined('ABSPATH')) { exit; }

/**
 * Old path (no surrounding slashes) => new path.
 *
 * Point each old URL at the closest surviving equivalent rather than dumping
 * everything on the home page — someone arriving from a search result wants
 * the thing they searched for.
 *
 *   'newsroom'   => '/blog/',
 *   'contact-us' => '/contact/',
 */
function firefly_template_legacy_redirects() {
    return apply_filters( 'firefly_template_legacy_redirects', array() );
}

function firefly_template_do_legacy_redirects() {
    // Never interfere with the admin, cron, or the REST API.
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

    $map = firefly_template_legacy_redirects();
    if ( empty( $map ) ) return;

    $uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    $path = wp_parse_url( $uri, PHP_URL_PATH );
    if ( ! $path ) return;

    $key = trim( $path, '/' );
    if ( ! isset( $map[ $key ] ) ) return;

    // Carry the query string through, so a campaign link like
    // /old-page?utm_source=… does not lose its tracking on the way over.
    $target = home_url( $map[ $key ] );
    $qs     = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '';
    if ( $qs ) {
        $target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $qs;
    }

    wp_safe_redirect( $target, 301 );
    exit;
}
// parse_request runs before WordPress decides the request is a 404, and late
// enough that is_admin()/REST_REQUEST are already resolved.
add_action( 'parse_request', 'firefly_template_do_legacy_redirects' );
