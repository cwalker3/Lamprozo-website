<?php
/**
 * Firefly Analytics — revenue / conversion reporting layer.
 *
 * Aggregations over the commerce tables (orders, campaigns, features,
 * bookings, submissions, referrals) for the dashboard's Revenue section.
 *
 * Note on scope: ffc_orders has no template column — commerce is
 * install-wide, so revenue figures are NOT template-scoped (they're the
 * same regardless of the dashboard's site selector). Conversion rate
 * divides install-wide orders by the *selected template's* visitors,
 * which is the closest meaningful denominator the dashboard shows.
 *
 * Reuses firefly_analytics_resolve_window() + firefly_analytics_delta()
 * from analytics-report.php. Order timestamps (createdAt, TIMESTAMP/UTC)
 * are compared against the window's GMT bounds.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Statuses that count as realized revenue / a conversion. */
function firefly_analytics_completed_statuses() {
    return array( 'completed', 'paid', 'active', 'succeeded' );
}

/** SQL `IN (...)` fragment of quoted completed statuses. */
function firefly_analytics_completed_in() {
    $q = array_map( function ( $s ) { return "'" . esc_sql( $s ) . "'"; }, firefly_analytics_completed_statuses() );
    return '(' . implode( ',', $q ) . ')';
}

// ============================================================================
// Revenue KPIs
// ============================================================================

function firefly_analytics_revenue_kpis( $win, $tpl ) {
    $cur  = firefly_analytics_revenue_block( $win['start'], $win['end'], $tpl );
    $prev = $win['compare'] ? firefly_analytics_revenue_block( $win['prev_start'], $win['prev_end'], $tpl ) : null;

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

function firefly_analytics_revenue_block( $start, $end, $tpl ) {
    global $wpdb;
    $orders = $wpdb->prefix . 'ffc_orders';
    $analytics = $wpdb->prefix . 'ffc_analytics';
    $in = firefly_analytics_completed_in();

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COALESCE(SUM(totalPrice - COALESCE(refundAmount,0)), 0) AS revenue,
            COUNT(*) AS orders
         FROM $orders
         WHERE status IN {$in} AND createdAt >= %s AND createdAt < %s",
        $start, $end
    ), ARRAY_A );

    $revenue = (float) ( $row['revenue'] ?? 0 );
    $count   = (int) ( $row['orders'] ?? 0 );
    $aov     = $count > 0 ? round( $revenue / $count, 2 ) : 0;

    $visitors = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT session_hash) FROM $analytics
         WHERE template = %s AND created_at >= %s AND created_at < %s",
        $tpl, $start, $end
    ) );
    $conv = $visitors > 0 ? round( ( $count / $visitors ) * 100, 2 ) : 0;

    return array(
        'revenue'    => round( $revenue, 2 ),
        'orders'     => $count,
        'aov'        => $aov,
        'conversion' => $conv,
    );
}

// ============================================================================
// Revenue timeseries
// ============================================================================

function firefly_analytics_revenue_timeseries( $win ) {
    global $wpdb;
    $orders = $wpdb->prefix . 'ffc_orders';
    $in = firefly_analytics_completed_in();
    $g  = $win['granularity'];
    $fmt = $g === 'hour' ? '%Y-%m-%d %H:00' : ( $g === 'month' ? '%Y-%m' : '%Y-%m-%d' );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE_FORMAT(createdAt, %s) AS bucket,
                ROUND(SUM(totalPrice - COALESCE(refundAmount,0)), 2) AS value
         FROM $orders
         WHERE status IN {$in} AND createdAt >= %s AND createdAt < %s
         GROUP BY bucket ORDER BY bucket ASC",
        $fmt, $win['start'], $win['end']
    ), ARRAY_A );

    $have = array();
    foreach ( $rows as $r ) { $have[ $r['bucket'] ] = (float) $r['value']; }

    // Densify (reuse the same stepping as the traffic series).
    $out = array();
    $start_ts = strtotime( $win['start'] . ' UTC' );
    $end_ts   = strtotime( $win['end'] . ' UTC' );
    if ( $g === 'month' ) {
        $c = strtotime( gmdate( 'Y-m-01 00:00:00', $start_ts ) . ' UTC' );
        while ( $c <= $end_ts ) {
            $k = gmdate( 'Y-m', $c );
            $out[] = array( 't' => $k, 'value' => $have[ $k ] ?? 0 );
            $c = strtotime( gmdate( 'Y-m-01 00:00:00', $c ) . ' +1 month UTC' );
        }
    } else {
        $step = $g === 'hour' ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
        $c = $g === 'hour'
            ? strtotime( gmdate( 'Y-m-d H:00:00', $start_ts ) . ' UTC' )
            : strtotime( gmdate( 'Y-m-d 00:00:00', $start_ts ) . ' UTC' );
        $guard = 0;
        while ( $c <= $end_ts && $guard++ < 5000 ) {
            $k = $g === 'hour' ? gmdate( 'Y-m-d H:00', $c ) : gmdate( 'Y-m-d', $c );
            $out[] = array( 't' => $k, 'value' => $have[ $k ] ?? 0 );
            $c += $step;
        }
    }

    return new WP_REST_Response( array( 'granularity' => $g, 'metric' => 'revenue', 'series' => $out ) );
}

// ============================================================================
// Top products
// ============================================================================

function firefly_analytics_revenue_products( $win ) {
    global $wpdb;
    $orders = $wpdb->prefix . 'ffc_orders';
    $features = $wpdb->prefix . 'ffc_features';
    $in = firefly_analytics_completed_in();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(f.featureName, CONCAT('Feature #', o.featureId)) AS label,
                ROUND(SUM(o.totalPrice - COALESCE(o.refundAmount,0)), 2) AS revenue,
                COUNT(*) AS orders
         FROM $orders o
         LEFT JOIN $features f ON f.id = o.featureId
         WHERE o.status IN {$in} AND o.createdAt >= %s AND o.createdAt < %s
         GROUP BY o.featureId
         ORDER BY revenue DESC
         LIMIT 50",
        $win['start'], $win['end']
    ), ARRAY_A );

    foreach ( $rows as &$r ) { $r['revenue'] = (float) $r['revenue']; $r['orders'] = (int) $r['orders']; }
    unset( $r );
    return new WP_REST_Response( $rows );
}

// ============================================================================
// Campaign attribution
// ============================================================================

function firefly_analytics_revenue_campaigns( $win ) {
    global $wpdb;
    $orders = $wpdb->prefix . 'ffc_orders';
    $camps = $wpdb->prefix . 'ffc_campaigns';
    $in = firefly_analytics_completed_in();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(c.name, o.campaignToken) AS label,
                o.campaignToken AS token,
                ROUND(SUM(o.totalPrice - COALESCE(o.refundAmount,0)), 2) AS revenue,
                COUNT(*) AS orders
         FROM $orders o
         LEFT JOIN $camps c ON c.token = o.campaignToken
         WHERE o.status IN {$in} AND o.campaignToken <> '' AND o.campaignToken IS NOT NULL
           AND o.createdAt >= %s AND o.createdAt < %s
         GROUP BY o.campaignToken
         ORDER BY revenue DESC
         LIMIT 50",
        $win['start'], $win['end']
    ), ARRAY_A );

    foreach ( $rows as &$r ) {
        $r['revenue'] = (float) $r['revenue'];
        $r['orders']  = (int) $r['orders'];
        $r['aov']     = $r['orders'] > 0 ? round( $r['revenue'] / $r['orders'], 2 ) : 0;
    }
    unset( $r );
    return new WP_REST_Response( $rows );
}

// ============================================================================
// Bookings / submissions / referrals (lead funnels)
// ============================================================================

function firefly_analytics_revenue_bookings( $win ) {
    global $wpdb;
    $b = $wpdb->prefix . 'firefly_collective_bookings';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$b'" ) !== $b ) {
        return new WP_REST_Response( array( 'total' => 0, 'confirmed' => 0, 'by_type' => array() ) );
    }
    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $b WHERE created_at >= %s AND created_at < %s", $win['start'], $win['end'] ) );
    $confirmed = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $b WHERE request_confirmed = 1 AND created_at >= %s AND created_at < %s", $win['start'], $win['end'] ) );
    $by_type = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(NULLIF(type_name,''),'Other') AS label, COUNT(*) AS views
         FROM $b WHERE created_at >= %s AND created_at < %s
         GROUP BY label ORDER BY views DESC LIMIT 20", $win['start'], $win['end'] ), ARRAY_A );
    return new WP_REST_Response( array(
        'total' => $total, 'confirmed' => $confirmed,
        'by_type' => firefly_analytics_int_rows( $by_type, array( 'views' ) ),
    ) );
}

function firefly_analytics_revenue_submissions( $win ) {
    global $wpdb;
    $s = $wpdb->prefix . 'ffc_submissions';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$s'" ) !== $s ) {
        return new WP_REST_Response( array( 'total' => 0, 'by_form' => array() ) );
    }
    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $s WHERE created_at >= %s AND created_at < %s", $win['start'], $win['end'] ) );
    $by_form = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(NULLIF(form_type,''),'Other') AS label, COUNT(*) AS views
         FROM $s WHERE created_at >= %s AND created_at < %s
         GROUP BY label ORDER BY views DESC LIMIT 20", $win['start'], $win['end'] ), ARRAY_A );
    return new WP_REST_Response( array(
        'total' => $total,
        'by_form' => firefly_analytics_int_rows( $by_form, array( 'views' ) ),
    ) );
}

function firefly_analytics_revenue_referrals( $win ) {
    global $wpdb;
    $r = $wpdb->prefix . 'referrals';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$r'" ) !== $r ) {
        return new WP_REST_Response( array( 'total' => 0, 'by_status' => array(), 'available' => false ) );
    }
    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $r WHERE created_at >= %s AND created_at < %s", $win['start'], $win['end'] ) );
    $by_status = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(NULLIF(status,''),'pending') AS label, COUNT(*) AS views
         FROM $r WHERE created_at >= %s AND created_at < %s
         GROUP BY label ORDER BY views DESC LIMIT 20", $win['start'], $win['end'] ), ARRAY_A );
    return new WP_REST_Response( array(
        'total' => $total, 'available' => true,
        'by_status' => firefly_analytics_int_rows( $by_status, array( 'views' ) ),
    ) );
}
