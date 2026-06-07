<?php
/**
 * Base Migration Class
 *
 * All migrations should extend this class and implement the required methods.
 * The MigrationRunner uses these to track per-migration status in the
 * {prefix}ffc_migrations table.
 *
 * Promoted from swrr's per-template migrations stack; the archiveFiles()
 * helper (swrr files_stage glue) is intentionally not ported — consumers
 * that want file archiving as part of a migration can implement it inline.
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class FFC_BaseMigration {

    /**
     * Get unique migration identifier.
     * Format: NNN_descriptive_name (e.g., "001_add_transaction_date_column")
     *
     * @return string
     */
    abstract public function getName();

    /**
     * Get human-readable description of what this migration does.
     *
     * @return string
     */
    abstract public function getDescription();

    /**
     * Execute the migration.
     *
     * @return array Result with keys: success (bool), message (string), metadata (array)
     */
    abstract public function up();

    /**
     * Rollback the migration (optional). Only called for migrations that
     * return true from isTemporary().
     *
     * @return array Result with keys: success (bool), message (string)
     */
    public function down() {
        return array(
            'success' => false,
            'message' => 'Rollback not implemented for this migration'
        );
    }

    /**
     * Migrations marked temporary can be rolled back from the admin UI.
     * Default false — permanent migrations cannot be undone through the runner.
     *
     * @return bool
     */
    public function isTemporary() {
        return false;
    }

    /**
     * Check if this migration should run. Override to add custom logic
     * (e.g., column existence). Default checks the migrations tracking
     * table for a completed row.
     *
     * @return bool
     */
    public function shouldRun() {
        global $wpdb;

        $table_name   = $wpdb->prefix . 'ffc_migrations';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (!$table_exists) {
            return true;
        }

        $migration_name = $this->getName();
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE name = %s AND status = 'completed'",
            $migration_name
        ));

        return $existing === null;
    }

    /**
     * Get the current batch number (for tracking migration groups).
     *
     * @return int
     */
    protected function getCurrentBatch() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_migrations';

        $max_batch = $wpdb->get_var("SELECT MAX(batch) FROM {$table_name}");
        return $max_batch ? (int)$max_batch + 1 : 1;
    }

    /**
     * Log a message (wrapper for error_log with migration context).
     *
     * @param string $message
     */
    protected function log($message) {
        error_log('FFC Migration [' . $this->getName() . ']: ' . $message);
    }
}
