<?php
/**
 * Migration Runner
 *
 * Handles the execution of migrations, tracks their status,
 * and provides methods to check which migrations need to run.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFC_MigrationRunner {

    /**
     * @var string Table name for tracking migrations
     */
    private $table_name;

    /**
     * @var array Registered migrations
     */
    private $migrations = array();

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ffc_migrations';
    }

    /**
     * Create the migrations table if it doesn't exist
     */
    public function createMigrationsTable() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text NOT NULL,
            executed_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            metadata longtext,
            batch int(11) NOT NULL DEFAULT 0,
            is_temporary tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name),
            KEY status (status),
            KEY batch (batch),
            KEY is_temporary (is_temporary)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verify table was created
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;

        if (!$table_exists) {
            error_log('FFC Migration Runner: Failed to create migrations table');
            return false;
        }

        // dbDelta doesn't always add new columns to existing tables, so check and add manually
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'is_temporary'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN is_temporary tinyint(1) NOT NULL DEFAULT 0");
            $wpdb->query("ALTER TABLE {$this->table_name} ADD INDEX is_temporary (is_temporary)");
            error_log('FFC Migration Runner: Added is_temporary column to migrations table');
        }

        return true;
    }

    /**
     * Register a migration
     *
     * @param FFC_BaseMigration $migration
     */
    public function registerMigration($migration) {
        $this->migrations[$migration->getName()] = $migration;
    }

    /**
     * Register multiple migrations
     *
     * @param array $migrations Array of FFC_BaseMigration instances
     */
    public function registerMigrations($migrations) {
        foreach ($migrations as $migration) {
            $this->registerMigration($migration);
        }
    }

    /**
     * Get all registered migrations
     *
     * @return array
     */
    public function getMigrations() {
        return $this->migrations;
    }

    /**
     * Get pending migrations (not yet run)
     *
     * @return array
     */
    public function getPendingMigrations() {
        $pending = array();

        foreach ($this->migrations as $name => $migration) {
            if ($migration->shouldRun()) {
                $pending[$name] = $migration;
            }
        }

        return $pending;
    }

    /**
     * Get completed migrations
     *
     * @return array Array of migration records from database
     */
    public function getCompletedMigrations() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE status = 'completed' ORDER BY executed_at ASC",
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Get all migrations with their status
     *
     * @return array Array of migrations with status information
     */
    public function getAllMigrationsWithStatus() {
        global $wpdb;

        $migrations_list = array();

        foreach ($this->migrations as $name => $migration) {
            // Check if migration exists in database
            $db_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE name = %s",
                $name
            ), ARRAY_A);

            // If no database record exists, but migration shouldn't run (already complete),
            // auto-mark it as completed to handle backward compatibility
            if (!$db_record && !$migration->shouldRun()) {
                // Migration is already complete but not tracked - add it now
                $wpdb->insert(
                    $this->table_name,
                    array(
                        'name' => $name,
                        'description' => $migration->getDescription(),
                        'executed_at' => current_time('mysql'),
                        'status' => 'completed',
                        'metadata' => json_encode(array('auto_detected' => true, 'note' => 'Marked as complete because migration validation indicates it has already been run')),
                        'batch' => 1
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%d')
                );

                // Re-fetch the record
                $db_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE name = %s",
                    $name
                ), ARRAY_A);
            }

            // If database record says "completed" but shouldRun() returns true,
            // the schema is out of sync (e.g., migrations table was synced but schema wasn't)
            // Reset the record to pending so the migration can run
            if ($db_record && $db_record['status'] === 'completed' && $migration->shouldRun()) {
                $wpdb->update(
                    $this->table_name,
                    array(
                        'status' => 'pending',
                        'metadata' => json_encode(array(
                            'reset_reason' => 'Schema check indicates migration needs to run despite completed status',
                            'reset_at' => current_time('mysql'),
                            'previous_executed_at' => $db_record['executed_at']
                        ))
                    ),
                    array('name' => $name),
                    array('%s', '%s'),
                    array('%s')
                );

                // Re-fetch the updated record
                $db_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE name = %s",
                    $name
                ), ARRAY_A);
            }

            $migrations_list[] = array(
                'name' => $name,
                'description' => $migration->getDescription(),
                'status' => $db_record ? $db_record['status'] : 'pending',
                'executed_at' => $db_record ? $db_record['executed_at'] : null,
                'metadata' => $db_record && $db_record['metadata'] ? json_decode($db_record['metadata'], true) : null,
                'batch' => $db_record ? $db_record['batch'] : null
            );
        }

        return $migrations_list;
    }

    /**
     * Run a specific migration by name
     *
     * @param string $migration_name
     * @return array Result with keys: success (bool), message (string), metadata (array)
     */
    public function runMigration($migration_name) {
        global $wpdb;

        if (!isset($this->migrations[$migration_name])) {
            return array(
                'success' => false,
                'message' => "Migration '{$migration_name}' not found"
            );
        }

        $migration = $this->migrations[$migration_name];

        // Check if should run
        if (!$migration->shouldRun()) {
            return array(
                'success' => false,
                'message' => "Migration '{$migration_name}' has already been completed"
            );
        }

        // Record migration start
        $batch = $this->getCurrentBatch();
        $wpdb->insert(
            $this->table_name,
            array(
                'name' => $migration_name,
                'description' => $migration->getDescription(),
                'executed_at' => current_time('mysql'),
                'status' => 'running',
                'batch' => $batch,
                'is_temporary' => $migration->isTemporary() ? 1 : 0
            ),
            array('%s', '%s', '%s', '%s', '%d', '%d')
        );

        try {
            // Execute migration
            $result = $migration->up();

            if ($result['success']) {
                // Update to completed
                $wpdb->update(
                    $this->table_name,
                    array(
                        'status' => 'completed',
                        'metadata' => isset($result['metadata']) ? json_encode($result['metadata']) : null
                    ),
                    array('name' => $migration_name),
                    array('%s', '%s'),
                    array('%s')
                );

                return $result;
            } else {
                // Update to failed
                $wpdb->update(
                    $this->table_name,
                    array(
                        'status' => 'failed',
                        'metadata' => json_encode(array('error' => $result['message']))
                    ),
                    array('name' => $migration_name),
                    array('%s', '%s'),
                    array('%s')
                );

                return $result;
            }

        } catch (Exception $e) {
            // Update to failed
            $wpdb->update(
                $this->table_name,
                array(
                    'status' => 'failed',
                    'metadata' => json_encode(array('error' => $e->getMessage()))
                ),
                array('name' => $migration_name),
                array('%s', '%s'),
                array('%s')
            );

            return array(
                'success' => false,
                'message' => 'Migration failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Run all pending migrations
     *
     * @return array Results for each migration
     */
    public function runPendingMigrations() {
        $pending = $this->getPendingMigrations();
        $results = array();

        foreach ($pending as $name => $migration) {
            $results[$name] = $this->runMigration($name);
        }

        return $results;
    }

    /**
     * Rollback a temporary migration
     * Only works for migrations marked as temporary with a valid down() method
     *
     * @param string $migration_name
     * @return array Result with keys: success (bool), message (string)
     */
    public function rollbackMigration($migration_name) {
        global $wpdb;

        if (!isset($this->migrations[$migration_name])) {
            return array(
                'success' => false,
                'message' => "Migration '{$migration_name}' not found"
            );
        }

        $migration = $this->migrations[$migration_name];

        // Check if migration is temporary
        if (!$migration->isTemporary()) {
            return array(
                'success' => false,
                'message' => "Migration '{$migration_name}' is not a temporary migration and cannot be rolled back"
            );
        }

        // Check if migration has been run
        $db_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE name = %s AND status = 'completed'",
            $migration_name
        ));

        if (!$db_record) {
            return array(
                'success' => false,
                'message' => "Migration '{$migration_name}' has not been run or is not completed"
            );
        }

        try {
            // Execute rollback
            $result = $migration->down();

            if ($result['success']) {
                // Remove migration record so it can be run again if needed
                $wpdb->delete(
                    $this->table_name,
                    array('name' => $migration_name),
                    array('%s')
                );

                return array(
                    'success' => true,
                    'message' => "Successfully rolled back migration '{$migration_name}'"
                );
            } else {
                return $result;
            }

        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Rollback failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get all temporary migrations that can be rolled back
     *
     * @return array
     */
    public function getTemporaryMigrations() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE is_temporary = 1 AND status = 'completed' ORDER BY executed_at DESC",
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Get the current batch number
     *
     * @return int
     */
    private function getCurrentBatch() {
        global $wpdb;

        $max_batch = $wpdb->get_var("SELECT MAX(batch) FROM {$this->table_name}");
        return $max_batch ? (int)$max_batch + 1 : 1;
    }

    /**
     * Reset a migration (mark as pending so it can be run again)
     * USE WITH CAUTION - Only for development/testing
     *
     * @param string $migration_name
     * @return bool
     */
    public function resetMigration($migration_name) {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            array('name' => $migration_name),
            array('%s')
        );

        return $result !== false;
    }

    /**
     * No-op hook reserved for consumers that need to seed migration-state
     * from a legacy WordPress option. Subclass or filter as needed; the
     * framework runner itself ships no opinions about legacy state.
     */
    public function migrateLegacyOptions() {
        do_action( 'firefly_migrations_migrate_legacy', $this );
    }
}
