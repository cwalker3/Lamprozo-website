<?php
/**
 * Firefly Analytics — traffic reporting layer.
 *
 * Aggregation queries behind the GET /analytics?type=… dispatcher. Every
 * query is template-scoped and bounded by a resolved time window; KPI-style
 * endpoints also compute the equal-length previous window so the dashboard
 * can render deltas. All stored timestamps are GMT, so windows are built
 * with gmdate() and compared as literal datetimes.
 *
 * Visit identity = COALESCE(visit_id, session_hash): rows predating the
 * visit-tracking beacon fall back to the daily visitor hash so they still
 * count as a (single-page) visit instead of vanishing from visit metrics.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// Window resolution
// ============================================================================

/**
 * Resolve the reporting window from request params into GMT datetime
 * bounds plus the matching previous period and a bucket granularity.
 *
 * Params: range (today|24h|7d|30d|90d|12mo|custom), from, to (Y-m-d for
 * custom), compare (0/1).
 *
 * @return array{start:string,end:string,prev_start:string,prev_end:string,
 *               granularity:string,span_days:float,range:string,compare:bool}
 */
function firefly_analytics_resolve_window( $request ) {
    $range = sanitize_text_field( (string) $request->get_param( 'range' ) ) ?: '30d';
    $now   = time();

    switch ( $range ) {
        case 'today':
            $start_ts = strtotime( gmdate( 'Y-m-d 00:00:00', $now ) . ' UTC' );
            $end_ts   = $now;
            break;
        case '24h':
            $start_ts = $now - DAY_IN_SECONDS;
            $end_ts   = $now;
            break;
        case '7d':
            $start_ts = $now - 7 * DAY_IN_SECONDS;
            $end_ts   = $now;
            break;
        case '90d':
            $start_ts = $now - 90 * DAY_IN_SECONDS;
            $end_ts   = $now;
            break;
        case '12mo':
            $start_ts = $now - 365 * DAY_IN_SECONDS;
            $end_ts   = $now;
            break;
        case 'custom':
            $from = sanitize_text_field( (string) $request->get_param( 'from' ) );
            $to   = sanitize_text_field( (string) $request->get_param( 'to' ) );
            $start_ts = $from ? strtotime( $from . ' 00:00:00 UTC' ) : ( $now - 30 * DAY_IN_SECONDS );
            $end_ts   = $to   ? strtotime( $to . ' 23:59:59 UTC' )   : $now;
            if ( ! $start_ts ) { $start_ts = $now - 30 * DAY_IN_SECONDS; }
            if ( ! $end_ts || $end_ts < $start_ts ) { $end_ts = $now; }
            break;
        case '30d':
        default:
            $start_ts = $now - 30 * DAY_IN_SECONDS;
            $end_ts   = $now;
            $range    = '30d';
            break;
    }

    $span      = max( 1, $end_ts - $start_ts );
    $span_days = $span / DAY_IN_SECONDS;

    if ( $span <= 2 * DAY_IN_SECONDS ) {
        $granularity = 'hour';
    } elseif ( $span_days <= 92 ) {
        $granularity = 'day';
    } else {
        $granularity = 'month';
    }

    return array(
        'start'       => gmdate( 'Y-m-d H:i:s', $start_ts ),
        'end'         => gmdate( 'Y-m-d H:i:s', $end_ts ),
        'prev_start'  => gmdate( 'Y-m-d H:i:s', $start_ts - $span ),
        'prev_end'    => gmdate( 'Y-m-d H:i:s', $start_ts ),
        'granularity' => $granularity,
        'span_days'   => $span_days,
        'range'       => $range,
        'compare'     => (bool) absint( $request->get_param( 'compare' ) ),
    );
}

/** Percent change old→new, null when no prior baseline. */
function firefly_analytics_delta( $current, $previous ) {
    $current  = (float) $current;
    $previous = (float) $previous;
    if ( $previous <= 0 ) {
        return null;
    }
    return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
}

// ============================================================================
// KPIs
// ============================================================================

/**
 * Core traffic KPIs for a window (with previous-period values when compare).
 * visitors, pageviews, visits, views/visit, bounce %, avg visit duration (s).
 */
function firefly_analytics_report_kpis( $table, $win, $tpl ) {
    $cur  = firefly_analytics_kpi_block( $table, $win['start'], $win['end'], $tpl );
    $prev = $win['compare'] ? firefly_analytics_kpi_block( $table, $win['prev_start'], $win['prev_end'], $tpl ) : null;

    $out = array();
    foreach ( $cur as $k => $v ) {
        $out[ $k ] = array(
            'value' => $v,
            'delta' => $prev ? firefly_analytics_delta( $v, $prev[ $k ] ) : null,
            'prev'  => $prev ? $prev[ $k ] : null,
        );
    }
    return new WP_REST_Response( $out );
}

/** Raw KPI numbers for one [start,end) window. */
function firefly_analytics_kpi_block( $table, $start, $end, $tpl ) {
    global $wpdb;

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COUNT(*) AS pageviews,
            COUNT(DISTINCT session_hash) AS visitors,
            COUNT(DISTINCT COALESCE(visit_id, session_hash)) AS visits
         FROM $table
         WHERE template = %s AND created_at >= %s AND created_at < %s",
        $tpl, $start, $end
    ), ARRAY_A );

    $pageviews = (int) ( $row['pageviews'] ?? 0 );
    $visitors  = (int) ( $row['visitors'] ?? 0 );
    $visits    = (int) ( $row['visits'] ?? 0 );

    // Per-visit rollup: pageview count + total dwell, for bounce + duration.
    $visit_stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            AVG(pv) AS avg_pv,
            SUM(CASE WHEN pv = 1 THEN 1 ELSE 0 END) AS bounces,
            COUNT(*) AS v,
            AVG(dur) AS avg_dur
         FROM (
            SELECT COALESCE(visit_id, session_hash) AS vk,
                   COUNT(*) AS pv,
                   COALESCE(SUM(duration_s),0) AS dur
            FROM $table
            WHERE template = %s AND created_at >= %s AND created_at < %s
            GROUP BY vk
         ) t",
        $tpl, $start, $end
    ), ARRAY_A );

    $v          = (int) ( $visit_stats['v'] ?? 0 );
    $bounces    = (int) ( $visit_stats['bounces'] ?? 0 );
    $bounce     = $v > 0 ? round( ( $bounces / $v ) * 100, 1 ) : 0;
    $vpv        = $v > 0 ? round( $pageviews / $v, 2 ) : 0;
    $avg_dur    = (int) round( (float) ( $visit_stats['avg_dur'] ?? 0 ) );

    return array(
        'visitors'        => $visitors,
        'pageviews'       => $pageviews,
        'visits'          => $visits,
        'views_per_visit' => $vpv,
        'bounce_rate'     => $bounce,
        'avg_duration'    => $avg_dur,
    );
}

// ============================================================================
// Timeseries
// ============================================================================

/**
 * Bucketed timeseries for a metric over the window. Returns aligned series
 * for current (and previous period when compare) so the chart can overlay
 * a ghost line. metric: visitors|pageviews|visits.
 */
function firefly_analytics_report_timeseries( $table, $win, $tpl, $metric ) {
    $series = firefly_analytics_series_block( $table, $win['start'], $win['end'], $tpl, $win['granularity'], $metric );

    $payload = array(
        'granularity' => $win['granularity'],
        'metric'      => $metric,
        'series'      => $series,
    );

    if ( $win['compare'] ) {
        $payload['previous'] = firefly_analytics_series_block(
            $table, $win['prev_start'], $win['prev_end'], $tpl, $win['granularity'], $metric
        );
    }

    return new WP_REST_Response( $payload );
}

/** One bucketed series [{t, value}] for a window + granularity. */
function firefly_analytics_series_block( $table, $start, $end, $tpl, $granularity, $metric ) {
    global $wpdb;

    switch ( $metric ) {
        case 'pageviews':
            $agg = 'COUNT(*)';
            break;
        case 'visits':
            $agg = 'COUNT(DISTINCT COALESCE(visit_id, session_hash))';
            break;
        case 'visitors':
        default:
            $agg = 'COUNT(DISTINCT session_hash)';
            break;
    }

    if ( $granularity === 'hour' ) {
        $fmt = '%Y-%m-%d %H:00';
    } elseif ( $granularity === 'month' ) {
        $fmt = '%Y-%m';
    } else {
        $fmt = '%Y-%m-%d';
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE_FORMAT(created_at, %s) AS bucket, {$agg} AS value
         FROM $table
         WHERE template = %s AND created_at >= %s AND created_at < %s
         GROUP BY bucket
         ORDER BY bucket ASC",
        $fmt, $tpl, $start, $end
    ), ARRAY_A );

    // Densify: fill empty buckets with 0 so the chart x-axis is continuous.
    $have = array();
    foreach ( $rows as $r ) {
        $have[ $r['bucket'] ] = (int) $r['value'];
    }

    $out      = array();
    $start_ts = strtotime( $start . ' UTC' );
    $end_ts   = strtotime( $end . ' UTC' );
    $step     = $granularity === 'hour' ? HOUR_IN_SECONDS : ( $granularity === 'month' ? null : DAY_IN_SECONDS );

    if ( $step === null ) {
        // Month stepping (calendar-aware).
        $cursor = strtotime( gmdate( 'Y-m-01 00:00:00', $start_ts ) . ' UTC' );
        while ( $cursor <= $end_ts ) {
            $key   = gmdate( 'Y-m', $cursor );
            $out[] = array( 't' => $key, 'value' => $have[ $key ] ?? 0 );
            $cursor = strtotime( gmdate( 'Y-m-01 00:00:00', $cursor ) . ' +1 month UTC' );
        }
    } else {
        $cursor = $start_ts;
        if ( $granularity === 'hour' ) {
            $cursor = strtotime( gmdate( 'Y-m-d H:00:00', $start_ts ) . ' UTC' );
        } else {
            $cursor = strtotime( gmdate( 'Y-m-d 00:00:00', $start_ts ) . ' UTC' );
        }
        $guard = 0;
        while ( $cursor <= $end_ts && $guard++ < 5000 ) {
            $key   = $granularity === 'hour' ? gmdate( 'Y-m-d H:00', $cursor ) : gmdate( 'Y-m-d', $cursor );
            $out[] = array( 't' => $key, 'value' => $have[ $key ] ?? 0 );
            $cursor += $step;
        }
    }

    return $out;
}

// ============================================================================
// Sources / acquisition
// ============================================================================

/**
 * Acquisition breakdown. dim: channels | referrers | utm_source |
 * utm_medium | utm_campaign.
 */
function firefly_analytics_report_sources( $table, $win, $tpl, $dim ) {
    global $wpdb;

    if ( $dim === 'channels' ) {
        // Channel classification depends on referrer host + utm_medium, which
        // is awkward in pure SQL — pull grouped referrer/medium tallies and
        // fold them into channels in PHP via the shared classifier.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT referrer, utm_medium, COUNT(*) AS views,
                    COUNT(DISTINCT session_hash) AS visitors
             FROM $table
             WHERE template = %s AND created_at >= %s AND created_at < %s
             GROUP BY referrer, utm_medium",
            $tpl, $win['start'], $win['end']
        ), ARRAY_A );

        $channels = array();
        foreach ( $rows as $r ) {
            $ch = firefly_analytics_classify_referrer( (string) $r['referrer'], (string) $r['utm_medium'] );
            if ( ! isset( $channels[ $ch ] ) ) {
                $channels[ $ch ] = array( 'label' => ucfirst( $ch ), 'views' => 0, 'visitors' => 0 );
            }
            $channels[ $ch ]['views']    += (int) $r['views'];
            $channels[ $ch ]['visitors'] += (int) $r['visitors'];
        }
        usort( $channels, function ( $a, $b ) { return $b['views'] <=> $a['views']; } );
        return new WP_REST_Response( array_values( $channels ) );
    }

    if ( $dim === 'referrers' ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                CASE WHEN referrer = '' OR referrer IS NULL THEN 'Direct'
                     ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(referrer,'https://',''),'http://',''),'/',1),'?',1)
                END AS label,
                COUNT(*) AS views,
                COUNT(DISTINCT session_hash) AS visitors
             FROM $table
             WHERE template = %s AND created_at >= %s AND created_at < %s
             GROUP BY label
             ORDER BY views DESC
             LIMIT 50",
            $tpl, $win['start'], $win['end']
        ), ARRAY_A );
        return new WP_REST_Response( firefly_analytics_int_rows( $rows, array( 'views', 'visitors' ) ) );
    }

    // utm_source | utm_medium | utm_campaign
    $col = in_array( $dim, array( 'utm_source', 'utm_medium', 'utm_campaign' ), true ) ? $dim : 'utm_source';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(NULLIF($col,''), '(none)') AS label,
                COUNT(*) AS views,
                COUNT(DISTINCT session_hash) AS visitors
         FROM $table
         WHERE template = %s AND created_at >= %s AND created_at < %s
         GROUP BY label
         ORDER BY views DESC
         LIMIT 50",
        $tpl, $win['start'], $win['end']
    ), ARRAY_A );
    return new WP_REST_Response( firefly_analytics_int_rows( $rows, array( 'views', 'visitors' ) ) );
}

// ============================================================================
// Pages
// ============================================================================

/**
 * Page breakdown. dim: top | entry | exit.
 */
function firefly_analytics_report_pages( $table, $win, $tpl, $dim ) {
    global $wpdb;

    if ( $dim === 'entry' ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT page_path AS label, page_title,
                    COUNT(*) AS views,
                    COUNT(DISTINCT session_hash) AS visitors
             FROM $table
             WHERE template = %s AND created_at >= %s AND created_at < %s AND is_entry = 1
             GROUP BY page_path
             ORDER BY views DESC
             LIMIT 50",
            $tpl, $win['start'], $win['end']
        ), ARRAY_A );
        return new WP_REST_Response( firefly_analytics_int_rows( $rows, array( 'views', 'visitors' ) ) );
    }

    if ( $dim === 'exit' ) {
        // Exit page = the last pageview (max id) of each visit.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.page_path AS label, a.page_title,
                    COUNT(*) AS views,
                    COUNT(DISTINCT a.session_hash) AS visitors
             FROM $table a
             INNER JOIN (
                SELECT MAX(id) AS last_id
                FROM $table
                WHERE template = %s AND created_at >= %s AND created_at < %s
                GROUP BY COALESCE(visit_id, session_hash)
             ) e ON e.last_id = a.id
             GROUP BY a.page_path
             ORDER BY views DESC
             LIMIT 50",
            $tpl, $win['start'], $win['end']
        ), ARRAY_A );
        return new WP_REST_Response( firefly_analytics_int_rows( $rows, array( 'views', 'visitors' ) ) );
    }

    // top (pages, excluding blog posts)
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT page_path AS label, page_title,
                COUNT(*) AS views,
                COUNT(DISTINCT session_hash) AS visitors
         FROM $table
         WHERE template = %s AND created_at >= %s AND created_at < %s
         GROUP BY page_path
         ORDER BY views DESC
         LIMIT 50",
        $tpl, $win['start'], $win['end']
    ), ARRAY_A );
    return new WP_REST_Response( firefly_analytics_int_rows( $rows, array( 'views', 'visitors' ) ) );
}

/** Top blog posts (post_type = post). */
function firefly_analytics_report_posts( $table, $win, $tpl ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT page_path AS label, page_title, post_id,
                COUNT(*) AS views,
                COUNT(DISTINCT session_hash) AS visitors
         FROM $table
         WHERE template = %s AND created_at >= %s AND created_at < %s AND post_type = 'post'
         GROUP BY page_path
         ORDER BY views DESC
         LIMIT 50",
        $tpl, $win['start'], $win['end']
    ), ARRAY_A );
    return new WP_REST_Response( firefly_analytics_int_rows( $rows, array( 'views', 'visitors' ) ) );
}

// ============================================================================
// Devices / tech
// ============================================================================

/**
 * Tech breakdown. dim: device | browser | os | screen.
 */
function firefly_analytics_report_devices( $table, $win, $tpl, $dim ) {
    global $wpdb;

    if ( $dim === 'screen' ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                CASE
                    WHEN screen_w IS NULL THEN 'Unknown'
                    WHEN screen_w < 576 THEN 'Mobile (<576)'
                    WHEN screen_w < 768 THEN 'Large mobile (576-767)'
                    WHEN screen_w < 992 THEN 'Tablet (768-991)'
                    WHEN screen_w < 1280 THEN 'Laptop (992-1279)'
                    WHEN screen_w < 1920 THEN 'Desktop (1280-1919)'
                    ELSE 'Wide (1920+)'
                END AS label,
                COUNT(*) AS views,
                COUNT(DISTINCT session_hash) AS visitors
             FROM $table
             WHERE template = %s AND created_at >= %s AND created_at < %s
             GROUP BY label
             ORDER BY views DESC",
            $tpl, $win['start'], $win['end']
        ), ARRAY_A );
        return new WP_REST_Response( firefly_analytics_int_rows( $rows, array( 'views', 'visitors' ) ) );
    }

    $col = $dim === 'browser' ? 'browser' : ( $dim === 'os' ? 'os' : 'device_type' );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(NULLIF($col,''),'Unknown') AS label,
                COUNT(*) AS views,
                COUNT(DISTINCT session_hash) AS visitors
         FROM $table
         WHERE template = %s AND created_at >= %s AND created_at < %s
         GROUP BY label
         ORDER BY views DESC",
        $tpl, $win['start'], $win['end']
    ), ARRAY_A );

    // Title-case device_type for display (desktop → Desktop).
    if ( $col === 'device_type' ) {
        foreach ( $rows as &$r ) { $r['label'] = ucfirst( $r['label'] ); }
        unset( $r );
    }
    return new WP_REST_Response( firefly_analytics_int_rows( $rows, array( 'views', 'visitors' ) ) );
}

// ============================================================================
// Countries
// ============================================================================

function firefly_analytics_report_countries( $table, $win, $tpl ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(NULLIF(country,''),'??') AS code,
                COUNT(*) AS views,
                COUNT(DISTINCT session_hash) AS visitors
         FROM $table
         WHERE template = %s AND created_at >= %s AND created_at < %s
         GROUP BY code
         ORDER BY views DESC
         LIMIT 100",
        $tpl, $win['start'], $win['end']
    ), ARRAY_A );
    foreach ( $rows as &$r ) {
        $r['views']    = (int) $r['views'];
        $r['visitors'] = (int) $r['visitors'];
        $r['label']    = $r['code'] === '??' ? 'Unknown' : firefly_analytics_country_name( $r['code'] );
    }
    unset( $r );
    return new WP_REST_Response( $rows );
}

// ============================================================================
// Engagement
// ============================================================================

/**
 * Engagement: scroll-depth distribution, avg dwell per top page, top CTAs
 * (from the link-tracking tables).
 */
function firefly_analytics_report_engagement( $table, $win, $tpl ) {
    global $wpdb;

    $scroll = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            CASE
                WHEN max_scroll IS NULL THEN 'No data'
                WHEN max_scroll < 25 THEN '0-25%'
                WHEN max_scroll < 50 THEN '25-50%'
                WHEN max_scroll < 75 THEN '50-75%'
                WHEN max_scroll < 100 THEN '75-99%'
                ELSE '100%'
            END AS label,
            COUNT(*) AS views
         FROM $table
         WHERE template = %s AND created_at >= %s AND created_at < %s
         GROUP BY label",
        $tpl, $win['start'], $win['end']
    ), ARRAY_A );

    $dwell = $wpdb->get_results( $wpdb->prepare(
        "SELECT page_path AS label, page_title,
                ROUND(AVG(duration_s)) AS avg_seconds,
                COUNT(*) AS samples
         FROM $table
         WHERE template = %s AND created_at >= %s AND created_at < %s AND duration_s IS NOT NULL
         GROUP BY page_path
         HAVING samples >= 1
         ORDER BY avg_seconds DESC
         LIMIT 25",
        $tpl, $win['start'], $win['end']
    ), ARRAY_A );

    // Top CTAs from the link-tracking tables (template-scoped via tracked_links).
    $links_table  = $wpdb->prefix . 'ffc_tracked_links';
    $clicks_table = $wpdb->prefix . 'ffc_link_clicks';
    $ctas = array();
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$clicks_table'" ) === $clicks_table ) {
        $ctas = $wpdb->get_results( $wpdb->prepare(
            "SELECT tl.link_url AS label, tl.link_text,
                    COUNT(lc.id) AS clicks,
                    COUNT(DISTINCT lc.session_hash) AS unique_clicks
             FROM $links_table tl
             LEFT JOIN $clicks_table lc
                ON lc.link_id = tl.id AND lc.clicked_at >= %s AND lc.clicked_at < %s
             WHERE tl.template = %s
             GROUP BY tl.id
             HAVING clicks > 0
             ORDER BY clicks DESC
             LIMIT 25",
            $win['start'], $win['end'], $tpl
        ), ARRAY_A );
        $ctas = firefly_analytics_int_rows( $ctas, array( 'clicks', 'unique_clicks' ) );
    }

    return new WP_REST_Response( array(
        'scroll' => firefly_analytics_int_rows( $scroll, array( 'views' ) ),
        'dwell'  => firefly_analytics_int_rows( $dwell, array( 'avg_seconds', 'samples' ) ),
        'ctas'   => $ctas,
    ) );
}

// ============================================================================
// Realtime
// ============================================================================

/**
 * Last-5-minute snapshot: live visitor count, recent pageview feed, and a
 * 30-minute mini sparkline (per-minute pageviews).
 */
function firefly_analytics_report_realtime( $table, $tpl ) {
    global $wpdb;
    $now    = time();
    $five   = gmdate( 'Y-m-d H:i:s', $now - 5 * MINUTE_IN_SECONDS );
    $thirty = gmdate( 'Y-m-d H:i:s', $now - 30 * MINUTE_IN_SECONDS );

    $online = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT session_hash) FROM $table
         WHERE template = %s AND created_at >= %s",
        $tpl, $five
    ) );

    $feed = $wpdb->get_results( $wpdb->prepare(
        "SELECT page_path, page_title, country, device_type, created_at
         FROM $table
         WHERE template = %s AND created_at >= %s
         ORDER BY id DESC
         LIMIT 20",
        $tpl, $thirty
    ), ARRAY_A );

    $spark = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE_FORMAT(created_at,'%%Y-%%m-%%d %%H:%%i') AS bucket, COUNT(*) AS value
         FROM $table
         WHERE template = %s AND created_at >= %s
         GROUP BY bucket ORDER BY bucket ASC",
        $tpl, $thirty
    ), ARRAY_A );

    // Densify per-minute for the last 30 minutes.
    $have = array();
    foreach ( $spark as $s ) { $have[ $s['bucket'] ] = (int) $s['value']; }
    $mini = array();
    for ( $i = 30; $i >= 0; $i-- ) {
        $key   = gmdate( 'Y-m-d H:i', $now - $i * MINUTE_IN_SECONDS );
        $mini[] = array( 't' => $key, 'value' => $have[ $key ] ?? 0 );
    }

    return new WP_REST_Response( array(
        'online' => $online,
        'feed'   => $feed,
        'spark'  => $mini,
    ) );
}

// ============================================================================
// Helpers
// ============================================================================

/** Cast the named columns of each row to int (DB returns strings). */
function firefly_analytics_int_rows( $rows, $cols ) {
    foreach ( $rows as &$r ) {
        foreach ( $cols as $c ) {
            if ( isset( $r[ $c ] ) ) {
                $r[ $c ] = (int) $r[ $c ];
            }
        }
    }
    unset( $r );
    return $rows;
}

/** Minimal ISO-3166 alpha-2 → English name map for the country list. */
function firefly_analytics_country_name( $code ) {
    static $names = null;
    if ( $names === null ) {
        $names = array(
            'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia',
            'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands',
            'IE' => 'Ireland', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland',
            'PL' => 'Poland', 'PT' => 'Portugal', 'CH' => 'Switzerland', 'AT' => 'Austria', 'BE' => 'Belgium',
            'BR' => 'Brazil', 'MX' => 'Mexico', 'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia',
            'IN' => 'India', 'JP' => 'Japan', 'CN' => 'China', 'KR' => 'South Korea', 'SG' => 'Singapore',
            'HK' => 'Hong Kong', 'TW' => 'Taiwan', 'TH' => 'Thailand', 'ID' => 'Indonesia', 'PH' => 'Philippines',
            'MY' => 'Malaysia', 'VN' => 'Vietnam', 'NZ' => 'New Zealand', 'ZA' => 'South Africa', 'NG' => 'Nigeria',
            'EG' => 'Egypt', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'IL' => 'Israel', 'TR' => 'Turkey',
            'RU' => 'Russia', 'UA' => 'Ukraine', 'GR' => 'Greece', 'CZ' => 'Czechia', 'RO' => 'Romania',
            'HU' => 'Hungary', 'PK' => 'Pakistan', 'BD' => 'Bangladesh',
        );
    }
    return $names[ $code ] ?? $code;
}
