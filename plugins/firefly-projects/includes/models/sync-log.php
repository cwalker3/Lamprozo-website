<?php
/**
 * Firefly Projects - Sync Activity Log
 *
 * Time-series record of every page/post sync attempt (push + pull, success +
 * failure) so multi-user teams can see who did what when. Backs the
 * expandable per-row activity feed in the pages list and the "Recent syncs"
 * sub-section in the Gutenberg sidebar.
 *
 * Storage: custom table {wpdb_prefix}firefly_sync_log. Created on plugin
 * activation and migrated on version bumps via firefly_projects_maybe_migrate_db().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Option key tracking the migrated DB version. Bumped when schema changes.
 */
const FIREFLY_PROJECTS_DB_VERSION_KEY = 'firefly_projects_db_version';

/**
 * Retention cap: max entries kept per post. The 101st insert prunes the oldest.
 * Bounded growth without cron.
 */
const FIREFLY_PROJECTS_LOG_RETENTION_PER_POST = 100;

/**
 * Resolve the log table name with the active wpdb prefix.
 */
function firefly_projects_sync_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'firefly_sync_log';
}

/**
 * Run dbDelta to create / upgrade the log table. Called from the activation
 * hook AND from a version-gate so existing installs pick up the table on the
 * next admin page load after an upgrade.
 */
function firefly_projects_install_sync_log_table() {
    global $wpdb;
    $table = firefly_projects_sync_log_table();
    $charset_collate = $wpdb->get_charset_collate();

    // NOTE: dbDelta is picky — column definitions must be on their own lines,
    // two spaces between PRIMARY KEY and parens, etc. Don't reflow this.
    $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  post_type VARCHAR(20) NOT NULL,
  direction VARCHAR(8) NOT NULL,
  env VARCHAR(8) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  status VARCHAR(8) NOT NULL,
  revision_id BIGINT UNSIGNED NULL,
  files_count INT UNSIGNED NOT NULL DEFAULT 0,
  summary LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY post_id_created (post_id, created_at),
  KEY user_id (user_id)
) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( FIREFLY_PROJECTS_DB_VERSION_KEY, FIREFLY_PROJECTS_VERSION );
}

/**
 * Lazy migration: runs on every request once if the stored DB version differs
 * from the live plugin version. Cheap (one option read in steady state).
 */
function firefly_projects_maybe_migrate_db() {
    $stored = get_option( FIREFLY_PROJECTS_DB_VERSION_KEY, '0' );
    if ( $stored === FIREFLY_PROJECTS_VERSION ) {
        return;
    }
    firefly_projects_install_sync_log_table();
}
add_action( 'admin_init', 'firefly_projects_maybe_migrate_db' );

/**
 * Insert one row into the activity log + prune past the retention cap.
 *
 * $args:
 *   post_id      (int, required)
 *   post_type    (string, required)  'page' | 'post'
 *   direction    (string, required)  'push' | 'pull'
 *   env          (string, required)  'dev'  | 'prod'
 *   user_id      (int|null)          null = system/CLI
 *   status       (string, required)  'success' | 'failure'
 *   revision_id  (int|null)          null when irrelevant (e.g. pull, failure)
 *   files_count  (int)               default 0
 *   summary      (array|null)        JSON-encoded into the row
 *
 * Returns the inserted id, or false on failure. Failures are non-fatal: we
 * don't want a log-table problem to make the user think their sync failed.
 */
function firefly_projects_log_sync( $args ) {
    global $wpdb;
    $table = firefly_projects_sync_log_table();

    $row = array(
        'post_id'     => isset( $args['post_id'] ) ? (int) $args['post_id'] : 0,
        'post_type'   => isset( $args['post_type'] ) ? substr( (string) $args['post_type'], 0, 20 ) : '',
        'direction'   => isset( $args['direction'] ) ? substr( (string) $args['direction'], 0, 8 ) : '',
        'env'         => isset( $args['env'] ) ? substr( (string) $args['env'], 0, 8 ) : '',
        'user_id'     => isset( $args['user_id'] ) && $args['user_id'] ? (int) $args['user_id'] : null,
        'status'      => isset( $args['status'] ) ? substr( (string) $args['status'], 0, 8 ) : '',
        'revision_id' => isset( $args['revision_id'] ) && $args['revision_id'] ? (int) $args['revision_id'] : null,
        'files_count' => isset( $args['files_count'] ) ? (int) $args['files_count'] : 0,
        'summary'     => isset( $args['summary'] ) && $args['summary'] !== null ? wp_json_encode( $args['summary'] ) : null,
        'created_at'  => current_time( 'mysql', true ), // GMT
    );

    if ( $row['post_id'] <= 0 || $row['direction'] === '' || $row['env'] === '' || $row['status'] === '' ) {
        return false;
    }

    $formats = array( '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' );
    $ok = $wpdb->insert( $table, $row, $formats );
    if ( $ok === false ) {
        return false;
    }
    $insert_id = (int) $wpdb->insert_id;

    // Retention prune: keep at most N entries per post. Cheap because the
    // (post_id, created_at) index covers the ORDER BY.
    $cap = FIREFLY_PROJECTS_LOG_RETENTION_PER_POST;
    $excess_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT 1000 OFFSET %d",
        $row['post_id'],
        $cap
    ) );
    if ( ! empty( $excess_ids ) ) {
        // Build a sanitized IN clause from integer ids.
        $ids_sql = implode( ',', array_map( 'intval', $excess_ids ) );
        $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids_sql})" );
    }

    return $insert_id;
}

/**
 * Read the most recent log entries for a post, joined with display_name so the
 * UI can render attribution without a second hop.
 *
 * Returns array of associative rows shaped for the REST response.
 */
function firefly_projects_get_sync_log( $post_id, $limit = 20 ) {
    global $wpdb;
    $table = firefly_projects_sync_log_table();
    $limit = max( 1, min( 100, (int) $limit ) );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.id, l.post_id, l.post_type, l.direction, l.env, l.user_id, l.status,
                l.revision_id, l.files_count, l.summary, l.created_at,
                u.display_name AS user_display_name
           FROM {$table} l
      LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
          WHERE l.post_id = %d
       ORDER BY l.id DESC
          LIMIT %d",
        (int) $post_id,
        $limit
    ), ARRAY_A );

    if ( ! is_array( $rows ) ) {
        return array();
    }

    $entries = array();
    foreach ( $rows as $r ) {
        $summary = null;
        if ( ! empty( $r['summary'] ) ) {
            $decoded = json_decode( $r['summary'], true );
            $summary = is_array( $decoded ) ? $decoded : null;
        }

        // created_at is stored as GMT; convert for both ISO and human strings.
        $ts_gmt   = strtotime( $r['created_at'] . ' UTC' );
        $iso      = $ts_gmt ? gmdate( 'c', $ts_gmt ) : null;
        $human    = $ts_gmt ? human_time_diff( $ts_gmt, time() ) . ' ago' : '';

        $revision_url = null;
        if ( ! empty( $r['revision_id'] ) ) {
            $revision_url = admin_url( 'revision.php?revision=' . (int) $r['revision_id'] );
        }

        $entries[] = array(
            'id'               => (int) $r['id'],
            'post_id'          => (int) $r['post_id'],
            'post_type'        => $r['post_type'],
            'direction'        => $r['direction'],
            'env'              => $r['env'],
            'user_id'          => isset( $r['user_id'] ) ? (int) $r['user_id'] : null,
            'user'             => $r['user_display_name'] ? $r['user_display_name'] : ( $r['user_id'] ? 'User #' . (int) $r['user_id'] : 'System' ),
            'status'           => $r['status'],
            'revision_id'      => isset( $r['revision_id'] ) ? (int) $r['revision_id'] : null,
            'revision_url'     => $revision_url,
            'files_count'      => (int) $r['files_count'],
            'summary'          => $summary,
            'created_at_iso'   => $iso,
            'created_at_human' => $human,
        );
    }

    return $entries;
}
