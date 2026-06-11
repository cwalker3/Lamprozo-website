<?php
/**
 * Migrations Admin Interface
 *
 * Provides admin UI for managing database migrations using the existing FFC_MigrationRunner
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . '../migrations/registry.php';

// Add admin menu
function add_migrations_admin_menu() {
    add_management_page(
        'Database Migrations',
        'Migrations',
        'manage_options',
        'firefly-migrations',
        'render_migrations_admin_page'
    );
}
add_action('admin_menu', 'add_migrations_admin_menu');

// Handle migration execution (form POST fallback)
function process_migration_execution() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Execute a migration
    if (isset($_POST['execute_migration']) && check_admin_referer('execute_migration')) {
        $migration_name = sanitize_text_field($_POST['migration_name']);

        $runner = ffc_get_migration_runner();
        $result = $runner->runMigration($migration_name);

        if ($result['success']) {
            wp_redirect(add_query_arg('message', 'executed', $_SERVER['REQUEST_URI']));
        } else {
            wp_redirect(add_query_arg(array('message' => 'error', 'error_msg' => urlencode($result['message'])), $_SERVER['REQUEST_URI']));
        }
        exit;
    }
}
add_action('admin_init', 'process_migration_execution');

// AJAX handler for migration execution
function ajax_execute_migration() {
    check_ajax_referer('execute_migration', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'), 403);
    }

    $migration_name = sanitize_text_field($_POST['migration_name']);

    // No time limit for migrations — backfills may decrypt tens of thousands of rows
    set_time_limit(0);

    $runner = ffc_get_migration_runner();
    $result = $runner->runMigration($migration_name);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}
add_action('wp_ajax_execute_migration', 'ajax_execute_migration');

// Admin notice for pending migrations
function show_pending_migrations_notice() {
    // Only show to admins
    if (!current_user_can('manage_options')) {
        return;
    }

    // Don't show on migrations page itself
    $screen = get_current_screen();
    if ($screen && $screen->id === 'tools_page_firefly-migrations') {
        return;
    }

    $runner = ffc_get_migration_runner();

    // Use getAllMigrationsWithStatus() instead of getPendingMigrations()
    // This triggers auto-detection of completed migrations that aren't tracked in DB
    $migrations = $runner->getAllMigrationsWithStatus();
    $pending_count = 0;
    foreach ($migrations as $migration) {
        if ($migration['status'] !== 'completed' && $migration['status'] !== 'failed') {
            $pending_count++;
        }
    }

    if ($pending_count > 0) {
        $migrations_url = admin_url('tools.php?page=firefly-migrations');
        ?>
        <div class="notice notice-warning">
            <p>
                <strong>Database Migrations Required:</strong>
                There <?php echo $pending_count === 1 ? 'is' : 'are'; ?> <?php echo $pending_count; ?>
                pending database migration<?php echo $pending_count === 1 ? '' : 's'; ?> that need<?php echo $pending_count === 1 ? 's' : ''; ?> to be run.
                <a href="<?php echo esc_url($migrations_url); ?>">View and run migrations &rarr;</a>
            </p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'show_pending_migrations_notice');

// Render admin page
function render_migrations_admin_page() {
    $runner = ffc_get_migration_runner();
    $migrations = $runner->getAllMigrationsWithStatus();

    $pending_count = 0;
    $completed_count = 0;
    $failed_count = 0;

    foreach ($migrations as $migration) {
        if ($migration['status'] === 'completed') {
            $completed_count++;
        } elseif ($migration['status'] === 'failed') {
            $failed_count++;
        } else {
            $pending_count++;
        }
    }
    ?>
    <div class="wrap">
        <h1>Database Migrations</h1>

        <?php if (isset($_GET['message'])): ?>
            <div class="notice notice-<?php echo $_GET['message'] === 'error' ? 'error' : 'success'; ?> is-dismissible">
                <p>
                    <?php
                    switch ($_GET['message']) {
                        case 'executed':
                            echo 'Migration executed successfully!';
                            break;
                        case 'error':
                            echo 'Error: ' . esc_html(urldecode($_GET['error_msg'] ?? 'Unknown error'));
                            break;
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 100%; margin-bottom: 20px;">
            <h2>Migration Status</h2>
            <p>
                <strong>Total Migrations:</strong> <?php echo count($migrations); ?><br>
                <strong>Completed:</strong> <span style="color: #10b981;"><?php echo $completed_count; ?></span><br>
                <strong>Pending:</strong> <span style="color: <?php echo $pending_count > 0 ? '#d63638' : '#10b981'; ?>;"><?php echo $pending_count; ?></span>
                <?php if ($failed_count > 0): ?>
                    <br><strong>Failed:</strong> <span style="color: #d63638;"><?php echo $failed_count; ?></span>
                <?php endif; ?>
            </p>

            <?php if ($pending_count > 0): ?>
                <p style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin-top: 16px;">
                    <strong>⚠️ Action Required:</strong> You have <?php echo $pending_count; ?> pending migration<?php echo $pending_count === 1 ? '' : 's'; ?>.
                    Please review and execute <?php echo $pending_count === 1 ? 'it' : 'them'; ?> below.
                </p>
            <?php else: ?>
                <p style="background: #d1fae5; border-left: 4px solid #10b981; padding: 12px; margin-top: 16px;">
                    <strong>✓ All migrations are up to date!</strong>
                </p>
            <?php endif; ?>
        </div>

        <?php if (empty($migrations)): ?>
            <p>No migration files found.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 15%;">Migration</th>
                        <th style="width: 40%;">Description</th>
                        <th style="width: 15%;">Status</th>
                        <th style="width: 15%;">Executed</th>
                        <th style="width: 15%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($migrations as $migration): ?>
                        <?php
                        // Extract number from migration name
                        preg_match('/^(\d+)_/', $migration['name'], $matches);
                        $number = $matches[1] ?? '';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($number); ?></strong>
                                <div class="row-actions">
                                    <span style="color: #666; font-size: 12px;">
                                        <?php echo esc_html($migration['name']); ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <strong><?php echo esc_html($migration['description']); ?></strong>
                                <?php if ($migration['metadata']): ?>
                                    <div class="row-actions">
                                        <?php if (isset($migration['metadata']['auto_detected']) && $migration['metadata']['auto_detected']): ?>
                                            <span style="color: #666; font-size: 11px;">
                                                ℹ️ Auto-detected as already applied
                                            </span>
                                        <?php endif; ?>
                                        <?php if (isset($migration['metadata']['error'])): ?>
                                            <span style="color: #d63638; font-size: 11px;">
                                                ❌ Error: <?php echo esc_html($migration['metadata']['error']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($migration['status'] === 'completed'): ?>
                                    <span style="display: inline-block; padding: 4px 12px; background: #d1fae5; color: #065f46; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                        ✓ Completed
                                    </span>
                                <?php elseif ($migration['status'] === 'failed'): ?>
                                    <span style="display: inline-block; padding: 4px 12px; background: #fee2e2; color: #991b1b; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                        ❌ Failed
                                    </span>
                                <?php elseif ($migration['status'] === 'running'): ?>
                                    <span style="display: inline-block; padding: 4px 12px; background: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                        ⏳ Running
                                    </span>
                                <?php else: ?>
                                    <span style="display: inline-block; padding: 4px 12px; background: #fee2e2; color: #991b1b; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                        ⚠ Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($migration['executed_at']): ?>
                                    <?php echo esc_html(date('M j, Y g:i A', strtotime($migration['executed_at']))); ?>
                                    <?php if ($migration['batch']): ?>
                                        <br><span style="color: #666; font-size: 11px;">Batch #<?php echo $migration['batch']; ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #999;">Not executed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($migration['status'] !== 'completed'): ?>
                                    <form method="post" style="display: inline;" class="execute-migration-form">
                                        <?php wp_nonce_field('execute_migration'); ?>
                                        <input type="hidden" name="migration_name" value="<?php echo esc_attr($migration['name']); ?>" />
                                        <button type="button" class="button button-primary execute-migration-btn"
                                                data-migration-name="<?php echo esc_attr($migration['name']); ?>"
                                                data-migration-description="<?php echo esc_attr($migration['description']); ?>">
                                            Run Migration
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: #10b981;">✓ Complete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="card" style="margin-top: 30px; max-width: 100%; background: #f0f6fc; border-left: 4px solid #0066cc;">
            <h3>About Migrations</h3>
            <p>
                Migrations are database schema changes that need to be applied in order. This system tracks which migrations
                have been executed to ensure your database schema is up to date.
            </p>
            <ul style="margin-left: 20px;">
                <li><strong>Auto-Detection:</strong> The system automatically detects if migrations have already been applied by checking database structure.</li>
                <li><strong>Pending Migrations:</strong> These need to be run to update your database schema.</li>
                <li><strong>Order Matters:</strong> Migrations should be run in numerical order.</li>
                <li><strong>One-Time Execution:</strong> Each migration can only be executed once.</li>
                <li><strong>Batches:</strong> Migrations are grouped into batches for tracking purposes.</li>
            </ul>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="migration-confirm-modal" style="display: none;">
        <div class="migration-modal-overlay"></div>
        <div class="migration-modal-content">
            <div class="migration-modal-header">
                <h2 id="migration-modal-title">Confirm Migration</h2>
                <button type="button" class="migration-modal-close">&times;</button>
            </div>
            <div class="migration-modal-body">
                <p id="migration-modal-message"></p>
                <div id="migration-spinner" style="display: none; text-align: center; padding: 20px 0;">
                    <span class="spinner" style="visibility: visible; float: none; margin: 0 auto;"></span>
                    <p style="margin-top: 10px; color: #666;" id="migration-spinner-text">Running migration... This may take a moment.</p>
                </div>
            </div>
            <div class="migration-modal-footer">
                <button type="button" class="button migration-modal-cancel">Cancel</button>
                <button type="button" class="button button-primary migration-modal-confirm">Run Migration</button>
            </div>
        </div>
    </div>

    <style>
    .migration-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        z-index: 100000;
        animation: fadeIn 0.2s ease;
    }

    .migration-modal-content {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border-radius: 8px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        z-index: 100001;
        min-width: 500px;
        max-width: 90%;
        animation: slideIn 0.3s ease;
    }

    .migration-modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid #ddd;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .migration-modal-header h2 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: #1d2327;
    }

    .migration-modal-close {
        background: none;
        border: none;
        font-size: 28px;
        line-height: 1;
        color: #666;
        cursor: pointer;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        transition: all 0.2s;
    }

    .migration-modal-close:hover {
        background: #f0f0f0;
        color: #000;
    }

    .migration-modal-body {
        padding: 24px;
        font-size: 14px;
        line-height: 1.6;
        color: #50575e;
    }

    .migration-modal-body p {
        margin: 0;
    }

    .migration-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #ddd;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .migration-modal-cancel {
        background: white;
        border-color: #ddd;
        color: #2c3338;
    }

    .migration-modal-cancel:hover {
        background: #f6f7f7;
        border-color: #999;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translate(-50%, -48%);
        }
        to {
            opacity: 1;
            transform: translate(-50%, -50%);
        }
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        var modalCallback = null;
        var isRunning = false;

        function showModal(title, message) {
            $('#migration-modal-title').text(title);
            $('#migration-modal-message').text(message).show();
            $('#migration-spinner').hide();
            $('.migration-modal-confirm').show().prop('disabled', false);
            $('.migration-modal-cancel').show();
            $('.migration-modal-close').show();

            var scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
            $('body').css({ 'overflow': 'hidden', 'padding-right': scrollbarWidth + 'px' });
            $('#migration-confirm-modal').fadeIn(200);
        }

        function showRunning() {
            isRunning = true;
            $('#migration-modal-title').text('Running Migration');
            $('#migration-modal-message').hide();
            $('#migration-spinner').show();
            $('#migration-spinner-text').text('Running migration... This may take a moment.');
            $('.migration-modal-confirm').hide();
            $('.migration-modal-cancel').hide();
            $('.migration-modal-close').hide();
        }

        function hideModal() {
            if (isRunning) return;
            $('#migration-confirm-modal').fadeOut(200);
            $('body').css({ 'overflow': '', 'padding-right': '' });
            modalCallback = null;
        }

        // Modal close handlers
        $('.migration-modal-close, .migration-modal-cancel, .migration-modal-overlay').on('click', function(e) {
            e.preventDefault();
            hideModal();
        });

        // Modal confirm handler
        $('.migration-modal-confirm').on('click', function(e) {
            e.preventDefault();
            if (modalCallback) {
                modalCallback();
            }
        });

        // Escape key to close
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#migration-confirm-modal').is(':visible') && !isRunning) {
                hideModal();
            }
        });

        // Execute migration handler
        $('.execute-migration-btn').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var migrationName = btn.data('migration-name');
            var migrationDescription = btn.data('migration-description');
            var form = btn.closest('form');
            var nonce = form.find('input[name="_wpnonce"]').val();

            showModal(
                'Run Migration',
                'Are you sure you want to run the migration "' + migrationDescription + '"? This will modify your database schema.'
            );

            modalCallback = function() {
                showRunning();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    timeout: 0, // no timeout — migrations can take a long time
                    data: {
                        action: 'execute_migration',
                        migration_name: migrationName,
                        nonce: nonce
                    },
                    success: function(response) {
                        isRunning = false;
                        if (response.success) {
                            $('#migration-spinner .spinner').css('visibility', 'hidden');
                            $('#migration-spinner-text')
                                .css('color', '#065f46')
                                .html('<strong>Migration completed successfully!</strong>');
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            var msg = response.data && response.data.message ? response.data.message : 'Unknown error';
                            $('#migration-spinner .spinner').css('visibility', 'hidden');
                            $('#migration-spinner-text')
                                .css('color', '#991b1b')
                                .html('<strong>Migration failed:</strong> ' + $('<span>').text(msg).html());
                            $('.migration-modal-close').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        isRunning = false;
                        var msg = status === 'timeout' ? 'Request timed out. The migration may still be running on the server. Try refreshing the page.' : (error || 'Connection error');
                        $('#migration-spinner .spinner').css('visibility', 'hidden');
                        $('#migration-spinner-text')
                            .css('color', '#991b1b')
                            .html('<strong>Error:</strong> ' + $('<span>').text(msg).html());
                        $('.migration-modal-close').show();
                    }
                });
            };
        });
    });
    </script>
    <?php
}
