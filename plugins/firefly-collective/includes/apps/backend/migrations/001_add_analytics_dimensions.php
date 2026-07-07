<?php
/**
 * Migration 001 — Add analytics dimension columns.
 *
 * Adds the rich-collection columns (visit/event identity, device/browser/
 * OS, country, UTM, screen width, engagement) to wpka_ffc_analytics.
 *
 * The actual ALTERs live in firefly_analytics_ensure_columns() (analytics.php)
 * so the runtime version-gated installer and this tracked migration share a
 * single idempotent implementation. This migration exists so the change is
 * visible + replayable in the Migrations admin; because the installer runs
 * the same ensure on init, shouldRun() usually reports it already satisfied.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Migration_001_AddAnalyticsDimensions extends FFC_BaseMigration {

    public function getName() {
        return '001_add_analytics_dimensions';
    }

    public function getDescription() {
        return 'Add visit/event identity, device, browser, OS, country, UTM, screen width, and engagement columns to ffc_analytics.';
    }

    /**
     * Run only when a representative new column is still missing.
     */
    public function shouldRun() {
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_analytics';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return false; // base table not created yet — installer handles it
        }
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'event_id'" );
        return empty( $col );
    }

    public function up() {
        if ( ! function_exists( 'firefly_analytics_ensure_columns' ) ) {
            return array(
                'success' => false,
                'message' => 'firefly_analytics_ensure_columns() unavailable',
            );
        }
        $added = firefly_analytics_ensure_columns();
        return array(
            'success'  => true,
            'message'  => 'Analytics dimension columns ensured.',
            'metadata' => array( 'columns_added' => $added ),
        );
    }
}
