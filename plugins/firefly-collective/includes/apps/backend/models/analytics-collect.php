<?php
/**
 * Firefly Analytics — collection layer.
 *
 * Enrichment helpers (UA parsing, referrer→channel classification) plus
 * the public ingest handlers (page hit, engagement update, admin
 * activity). All cookieless: identity is a daily-rotating server hash
 * (visitor) + a client sessionStorage UUID (visit) + a per-pageview UUID
 * (event). The IP is used for geo + hashing only and is never stored.
 *
 * Required by analytics.php; the REST routes are registered there.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// Enrichment — User-Agent parsing
// ============================================================================

/**
 * Dependency-free User-Agent parser. Returns coarse, dashboard-grade
 * dimensions — not forensic detail. Order matters: more specific tokens
 * are tested before the generic ones they embed (e.g. Edg before Chrome,
 * Chrome before Safari).
 *
 * @param string $ua
 * @return array{device_type:string,browser:string,os:string}
 */
function firefly_analytics_parse_ua( $ua ) {
    $ua = (string) $ua;
    $result = array(
        'device_type' => 'desktop',
        'browser'     => 'Unknown',
        'os'          => 'Unknown',
    );
    if ( $ua === '' ) {
        return $result;
    }

    // ---- Device type -------------------------------------------------------
    $is_tablet = preg_match( '/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua );
    $is_mobile = preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|BB10|Opera Mini|IEMobile/i', $ua );
    if ( $is_tablet ) {
        $result['device_type'] = 'tablet';
    } elseif ( $is_mobile ) {
        $result['device_type'] = 'mobile';
    }

    // ---- OS ----------------------------------------------------------------
    if ( preg_match( '/Windows NT/i', $ua ) ) {
        $result['os'] = 'Windows';
    } elseif ( preg_match( '/iPhone|iPad|iPod/i', $ua ) ) {
        $result['os'] = 'iOS';
    } elseif ( preg_match( '/Android/i', $ua ) ) {
        $result['os'] = 'Android';
    } elseif ( preg_match( '/Mac OS X|Macintosh/i', $ua ) ) {
        $result['os'] = 'macOS';
    } elseif ( preg_match( '/CrOS/i', $ua ) ) {
        $result['os'] = 'ChromeOS';
    } elseif ( preg_match( '/Linux/i', $ua ) ) {
        $result['os'] = 'Linux';
    }

    // ---- Browser (specific before generic) ---------------------------------
    if ( preg_match( '/Edg(?:e|A|iOS)?\//i', $ua ) ) {
        $result['browser'] = 'Edge';
    } elseif ( preg_match( '/OPR\/|Opera/i', $ua ) ) {
        $result['browser'] = 'Opera';
    } elseif ( preg_match( '/SamsungBrowser/i', $ua ) ) {
        $result['browser'] = 'Samsung Internet';
    } elseif ( preg_match( '/Brave/i', $ua ) ) {
        $result['browser'] = 'Brave';
    } elseif ( preg_match( '/(?:CriOS|Chrome)\//i', $ua ) ) {
        $result['browser'] = 'Chrome';
    } elseif ( preg_match( '/(?:FxiOS|Firefox)\//i', $ua ) ) {
        $result['browser'] = 'Firefox';
    } elseif ( preg_match( '/Version\/.*Safari/i', $ua ) || preg_match( '/Safari\//i', $ua ) ) {
        $result['browser'] = 'Safari';
    } elseif ( preg_match( '/MSIE|Trident/i', $ua ) ) {
        $result['browser'] = 'Internet Explorer';
    }

    return $result;
}

/**
 * Shared bot heuristic — keep in one place so the hit + engagement +
 * admin-activity paths stay consistent.
 */
function firefly_analytics_is_bot( $ua ) {
    return (bool) preg_match(
        '/bot|crawl|spider|slurp|facebookexternalhit|linkedinbot|embedly|quora link preview|pinterest|bitlybot|preview|monitor|headless|lighthouse|gtmetrix|pingdom/i',
        (string) $ua
    );
}

// ============================================================================
// Enrichment — referrer → acquisition channel
// ============================================================================

/**
 * Classify a request into an acquisition channel from its referrer +
 * utm_medium. Computed at both write (optional) and read time; kept here
 * so the report layer and any future stored-channel share one ruleset.
 *
 * Returns one of: direct | organic | social | email | paid | referral
 *
 * @param string $referrer Full referrer URL (may be empty).
 * @param string $utm_medium Lowercased utm_medium, if any.
 * @return string
 */
function firefly_analytics_classify_referrer( $referrer, $utm_medium = '' ) {
    $utm_medium = strtolower( trim( (string) $utm_medium ) );
    if ( $utm_medium !== '' ) {
        if ( in_array( $utm_medium, array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid-search', 'cpm', 'display', 'banner' ), true ) ) {
            return 'paid';
        }
        if ( in_array( $utm_medium, array( 'email', 'newsletter', 'e-mail' ), true ) ) {
            return 'email';
        }
        if ( in_array( $utm_medium, array( 'social', 'social-network', 'social-media', 'sm', 'social_media' ), true ) ) {
            return 'social';
        }
        if ( in_array( $utm_medium, array( 'organic', 'search' ), true ) ) {
            return 'organic';
        }
        if ( $utm_medium === 'referral' ) {
            return 'referral';
        }
    }

    $referrer = trim( (string) $referrer );
    if ( $referrer === '' ) {
        return 'direct';
    }

    $host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
    if ( $host === '' ) {
        return 'direct';
    }
    // Strip a leading www. for matching.
    $host = preg_replace( '/^www\./', '', $host );

    // Same-host referrals are internal navigation — treat as direct so they
    // don't pollute the acquisition report with our own domain.
    $self = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    $self = preg_replace( '/^www\./', '', (string) $self );
    if ( $self !== '' && $host === $self ) {
        return 'direct';
    }

    $organic = array( 'google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'yandex.', 'baidu.com', 'ecosia.org', 'startpage.com', 'search.brave.com' );
    foreach ( $organic as $needle ) {
        if ( strpos( $host, $needle ) !== false ) {
            return 'organic';
        }
    }

    $social = array( 'facebook.com', 'fb.com', 'm.facebook.com', 'l.facebook.com', 'instagram.com', 'twitter.com', 't.co', 'x.com', 'linkedin.com', 'lnkd.in', 'reddit.com', 'youtube.com', 'youtu.be', 'pinterest.', 'tiktok.com', 'threads.net', 'bsky.app', 'mastodon.', 'tumblr.com', 'whatsapp.com', 'telegram.', 't.me' );
    foreach ( $social as $needle ) {
        if ( strpos( $host, $needle ) !== false ) {
            return 'social';
        }
    }

    $email = array( 'mail.google.com', 'outlook.', 'mail.yahoo.com', 'mail.proton', 'icloud.com' );
    foreach ( $email as $needle ) {
        if ( strpos( $host, $needle ) !== false ) {
            return 'email';
        }
    }

    return 'referral';
}

// ============================================================================
// Identity helpers
// ============================================================================

/**
 * Validate + normalize a client-supplied UUID (visit / event id). Returns
 * '' when the value isn't a plausible UUID so we never store junk.
 */
function firefly_analytics_clean_uuid( $value ) {
    $value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
    return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ? $value : '';
}

// ============================================================================
// Ingest — page hit
// ============================================================================

/**
 * POST /firefly-collective/v1/hit  — record one pageview (cookieless).
 *
 * Beacon body keys: p,t,r,i,y,tp (legacy) + vs (visit id), ev (event id),
 * e (is-entry 0/1), sw (screen width), us/um/uc (utm source/medium/campaign).
 */
function firefly_analytics_record_hit( $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_analytics';

    $data = json_decode( $request->get_body(), true );
    if ( ! $data || empty( $data['p'] ) ) {
        return new WP_REST_Response( null, 204 );
    }

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ( firefly_analytics_is_bot( $ua ) ) {
        return new WP_REST_Response( null, 204 );
    }

    $page_path  = sanitize_text_field( substr( $data['p'], 0, 255 ) );
    $page_title = isset( $data['t'] ) ? sanitize_text_field( substr( $data['t'], 0, 255 ) ) : null;
    $post_id    = isset( $data['i'] ) && is_numeric( $data['i'] ) ? absint( $data['i'] ) : null;
    $post_type  = isset( $data['y'] ) ? sanitize_text_field( substr( $data['y'], 0, 20 ) ) : null;
    $template   = isset( $data['tp'] ) ? sanitize_text_field( substr( $data['tp'], 0, 50 ) ) : null;
    $referrer   = isset( $data['r'] ) ? esc_url_raw( substr( $data['r'], 0, 500 ) ) : null;

    $visit_id   = firefly_analytics_clean_uuid( $data['vs'] ?? '' );
    $event_id   = firefly_analytics_clean_uuid( $data['ev'] ?? '' );
    $is_entry   = ! empty( $data['e'] ) ? 1 : 0;
    $screen_w   = isset( $data['sw'] ) && is_numeric( $data['sw'] ) ? min( 20000, absint( $data['sw'] ) ) : null;

    $utm_source   = isset( $data['us'] ) ? sanitize_text_field( substr( $data['us'], 0, 100 ) ) : null;
    $utm_medium   = isset( $data['um'] ) ? sanitize_text_field( substr( $data['um'], 0, 100 ) ) : null;
    $utm_campaign = isset( $data['uc'] ) ? sanitize_text_field( substr( $data['uc'], 0, 120 ) ) : null;
    $utm_source   = ( $utm_source === '' ) ? null : $utm_source;
    $utm_medium   = ( $utm_medium === '' ) ? null : $utm_medium;
    $utm_campaign = ( $utm_campaign === '' ) ? null : $utm_campaign;

    // Server-side enrichment.
    $agent       = firefly_analytics_parse_ua( $ua );
    $ip          = function_exists( 'firefly_analytics_client_ip' ) ? firefly_analytics_client_ip() : ( $_SERVER['REMOTE_ADDR'] ?? '' );
    $country     = function_exists( 'firefly_analytics_geo_country' ) ? firefly_analytics_geo_country( $ip ) : null;
    $session_hash = firefly_analytics_get_session_hash();

    // Rate limit: 1 hit per path per visitor per hour (refresh suppression).
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name
         WHERE session_hash = %s AND page_path = %s
         AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        $session_hash,
        $page_path
    ) );
    if ( $existing > 0 ) {
        return new WP_REST_Response( null, 204 );
    }

    $wpdb->insert(
        $table_name,
        array(
            'page_path'    => $page_path,
            'page_title'   => $page_title,
            'post_id'      => $post_id,
            'post_type'    => $post_type ?: null,
            'template'     => $template,
            'referrer'     => $referrer,
            'session_hash' => $session_hash,
            'visit_id'     => $visit_id ?: null,
            'event_id'     => $event_id ?: null,
            'is_entry'     => $is_entry,
            'device_type'  => $agent['device_type'],
            'browser'      => $agent['browser'],
            'os'           => $agent['os'],
            'country'      => $country,
            'utm_source'   => $utm_source,
            'utm_medium'   => $utm_medium,
            'utm_campaign' => $utm_campaign,
            'screen_w'     => $screen_w,
            'created_at'   => current_time( 'mysql', 1 ),
        ),
        array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
    );

    return new WP_REST_Response( null, 204 );
}

// ============================================================================
// Ingest — engagement (time-on-page + scroll depth)
// ============================================================================

/**
 * POST /firefly-collective/v1/engagement — update one pageview row with
 * the visit's dwell time + max scroll depth. Keyed by event_id; uses
 * GREATEST so repeated unload beacons keep the high-water mark.
 *
 * Body: { ev: <event_id>, d: <seconds>, sd: <scroll % 0-100> }
 */
function firefly_analytics_record_engagement( $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_analytics';

    $data = json_decode( $request->get_body(), true );
    $event_id = firefly_analytics_clean_uuid( $data['ev'] ?? '' );
    if ( $event_id === '' ) {
        return new WP_REST_Response( null, 204 );
    }

    $duration = isset( $data['d'] ) && is_numeric( $data['d'] ) ? min( 86400, absint( $data['d'] ) ) : 0;
    $scroll   = isset( $data['sd'] ) && is_numeric( $data['sd'] ) ? max( 0, min( 100, absint( $data['sd'] ) ) ) : 0;

    $wpdb->query( $wpdb->prepare(
        "UPDATE $table_name
         SET duration_s = GREATEST( COALESCE( duration_s, 0 ), %d ),
             max_scroll = GREATEST( COALESCE( max_scroll, 0 ), %d )
         WHERE event_id = %s
         ORDER BY id DESC
         LIMIT 1",
        $duration,
        $scroll,
        $event_id
    ) );

    return new WP_REST_Response( null, 204 );
}
