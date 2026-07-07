<?php
/**
 * Firefly Analytics — GeoIP country resolution.
 *
 * Thin, defensive wrapper around the bundled MaxMind GeoLite2-Country
 * database. Country-level only (privacy-friendly — never city/coords).
 *
 * Provisioning: drop a `GeoLite2-Country.mmdb` (free, from a MaxMind
 * account) into includes/apps/backend/data/. When the file is absent or
 * the reader errors, every lookup returns null and the dashboard renders
 * an "Unknown" bucket — geo is simply "off until provisioned". A geo
 * failure must NEVER break hit collection, so the whole path is wrapped.
 *
 * The vendored reader (assets/vendor/maxmind-db/, Apache-2.0) is the
 * official MaxMind pure-PHP implementation — no composer, no C extension
 * required (uses bcmath, present on this stack).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Absolute path to the GeoLite2-Country database, or '' if not present.
 */
function firefly_analytics_geo_db_path() {
    // models/ -> backend/data/GeoLite2-Country.mmdb
    $path = dirname( __DIR__ ) . '/data/GeoLite2-Country.mmdb';
    return file_exists( $path ) ? $path : '';
}

/**
 * Lazily build (and cache for the request) a MaxMind reader instance.
 * Returns null when the DB file is missing or the reader can't load.
 */
function firefly_analytics_geo_reader() {
    static $reader = false; // false = not yet attempted; null = unavailable

    if ( $reader !== false ) {
        return $reader;
    }

    $reader = null;

    $db = firefly_analytics_geo_db_path();
    if ( $db === '' ) {
        return null; // no database provisioned — geo off
    }

    if ( ! class_exists( '\\MaxMind\\Db\\Reader' ) ) {
        $base = dirname( __DIR__ ) . '/assets/vendor/maxmind-db/MaxMind/Db';
        // Require in dependency order — no composer autoloader is vendored.
        $files = array(
            $base . '/Reader/Util.php',
            $base . '/Reader/InvalidDatabaseException.php',
            $base . '/Reader/Metadata.php',
            $base . '/Reader/Decoder.php',
            $base . '/Reader.php',
        );
        foreach ( $files as $f ) {
            if ( is_readable( $f ) ) {
                require_once $f;
            }
        }
        if ( ! class_exists( '\\MaxMind\\Db\\Reader' ) ) {
            return null; // vendored lib missing/incomplete
        }
    }

    try {
        $reader = new \MaxMind\Db\Reader( $db );
    } catch ( \Throwable $e ) {
        $reader = null;
    }

    return $reader;
}

/**
 * Resolve an IP address to a 2-letter ISO country code (uppercase), or
 * null when unknown / unavailable / private. Always safe to call.
 *
 * @param string $ip
 * @return string|null
 */
function firefly_analytics_geo_country( $ip ) {
    $ip = is_string( $ip ) ? trim( $ip ) : '';
    if ( $ip === '' ) {
        return null;
    }

    // Skip obviously non-routable addresses — they'll never resolve and
    // we don't want to spend a tree lookup on them.
    if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
        // Still allow public IPs that failed only the private/reserved flags
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return null;
        }
        // It's a valid IP but private/reserved (localhost, LAN) — no country.
        return null;
    }

    $reader = firefly_analytics_geo_reader();
    if ( $reader === null ) {
        return null;
    }

    try {
        $record = $reader->get( $ip );
    } catch ( \Throwable $e ) {
        return null;
    }

    if ( is_array( $record ) && isset( $record['country']['iso_code'] ) ) {
        $code = strtoupper( substr( (string) $record['country']['iso_code'], 0, 2 ) );
        return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : null;
    }

    return null;
}

/**
 * Best-effort client IP extraction. Prefers the proxy-forwarded address
 * when present (tunnels/CDNs in this stack), falling back to REMOTE_ADDR.
 * The IP is used only for geo + hashing and is never stored.
 */
function firefly_analytics_client_ip() {
    $candidates = array();
    if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
        $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        // May be a comma list "client, proxy1, proxy2" — take the first.
        $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
        $candidates[] = trim( $parts[0] );
    }
    if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $candidates[] = $_SERVER['REMOTE_ADDR'];
    }
    foreach ( $candidates as $ip ) {
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $ip;
        }
    }
    return '';
}
