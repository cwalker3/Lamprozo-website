<?php
/**
 * Migration Registry — framework entry point.
 *
 * Bootstraps a single FFC_MigrationRunner per request, creates the
 * tracking table on first use, and fires the
 * `firefly_register_migrations` action so consumers (template models,
 * sibling plugins) can register their own migrations against it.
 *
 * To register a migration from a consumer:
 *
 *   add_action( 'firefly_register_migrations', function( $runner ) {
 *       require_once __DIR__ . '/migrations/001_add_widgets_table.php';
 *       $runner->registerMigration( new My_Migration_001_AddWidgetsTable() );
 *   });
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/BaseMigration.php';
require_once __DIR__ . '/MigrationRunner.php';

/**
 * Get the singleton FFC_MigrationRunner with all registered migrations.
 * On first call: ensures the tracking table exists, fires the
 * registration action, then runs the legacy-options hook so consumers
 * can backfill state from any pre-runner option flags.
 *
 * @return FFC_MigrationRunner
 */
function ffc_get_migration_runner() {
    static $runner = null;

    if ($runner === null) {
        $runner = new FFC_MigrationRunner();

        // Ensure migrations table exists before anyone registers against it
        $runner->createMigrationsTable();

        // Consumers register their migrations here (see file docblock).
        do_action( 'firefly_register_migrations', $runner );

        // No-op by default — fires the firefly_migrations_migrate_legacy hook
        // for consumers that need to backfill state from pre-runner options.
        $runner->migrateLegacyOptions();
    }

    return $runner;
}

/**
 * Initialize the migration system on WordPress init.
 * Idempotent — subsequent calls hit the static cache.
 */
function ffc_init_migration_system() {
    ffc_get_migration_runner();
}
add_action('init', 'ffc_init_migration_system');
