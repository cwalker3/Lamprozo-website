<?php
// CRITICAL CHECK: If $projects_data is not set or not an array, default to empty array
if (!isset($projects_data) || !is_array($projects_data)) {
    $projects_data = array();
}
?>

<!--
    Project data is now injected via wp_add_inline_script in projects.php
    This ensures proper load order and prevents conflicts with wp_localize_script
-->

<h1>Projects - File Selector</h1>
<p class="description">Select individual files and folders to sync to your live environment.</p>

<div id="project-file-selector" v-cloak>
    <!-- Bootstrap Dev Environment Section - only show if wp-dev doesn't exist -->
    <div v-if="bootstrapStatus === 'not-exists' || bootstrapStatus === 'error' || bootstrapStatus === 'success'" class="firefly-bootstrap-section">
        <h2>Bootstrap Dev Environment</h2>
        <p class="description">Set up a new wp-dev WordPress installation on your production server.</p>

        <div v-if="bootstrapStatus === 'not-exists'" class="bootstrap-form-container">
            <div class="bootstrap-form">
                <p class="form-intro"><strong>wp-dev does not exist.</strong> Fill in the database credentials to create it:</p>

                <div class="form-row">
                    <label for="bootstrap-subdomain">Dev Site URL:</label>
                    <input type="text" id="bootstrap-subdomain" v-model="bootstrapForm.subdomain" placeholder="dev.yourdomain.com" :disabled="isBootstrapping" />
                    <span class="form-hint">Full domain for the dev site (e.g., test1.fireflycollective.org)</span>
                </div>

                <div class="form-row">
                    <label for="bootstrap-db-name">Database Name:</label>
                    <input type="text" id="bootstrap-db-name" v-model="bootstrapForm.dbName" placeholder="wp_dev" :disabled="isBootstrapping" />
                </div>

                <div class="form-row">
                    <label for="bootstrap-db-user">Database User:</label>
                    <input type="text" id="bootstrap-db-user" v-model="bootstrapForm.dbUser" :disabled="isBootstrapping" />
                </div>

                <div class="form-row">
                    <label for="bootstrap-db-password">Database Password:</label>
                    <input type="password" id="bootstrap-db-password" v-model="bootstrapForm.dbPassword" :disabled="isBootstrapping" />
                </div>

                <div class="form-row">
                    <label for="bootstrap-db-host">Database Host:</label>
                    <input type="text" id="bootstrap-db-host" v-model="bootstrapForm.dbHost" placeholder="localhost" :disabled="isBootstrapping" />
                </div>

                <div class="form-row">
                    <label for="bootstrap-table-prefix">Table Prefix:</label>
                    <input type="text" id="bootstrap-table-prefix" v-model="bootstrapForm.tablePrefix" placeholder="wp_" :disabled="isBootstrapping" />
                </div>

                <button @click="showBootstrapModal = true" class="action-button primary" :disabled="isBootstrapping || !isBootstrapFormValid">
                    <span v-if="!isBootstrapping">Bootstrap wp-dev Environment</span>
                    <span v-else>Bootstrapping...</span>
                </button>
            </div>
        </div>

        <div v-if="bootstrapStatus === 'error'" class="bootstrap-status bootstrap-error">
            <span class="status-icon">&#10007;</span> {{ bootstrapError }}
            <button @click="checkDevExists" class="action-button small">Try Again</button>
        </div>

        <div v-if="bootstrapStatus === 'success'" class="bootstrap-status bootstrap-success">
            <span class="status-icon">&#10003;</span> wp-dev environment created successfully!
            <p class="success-details">
                <strong>Next steps:</strong><br>
                1. Set up your subdomain pointing to wp-dev/<br>
                2. Run the WordPress installer at https://{{ bootstrapForm.subdomain }}/<br>
                3. Activate the Firefly Projects plugin
            </p>
        </div>
    </div>

    <!-- Bootstrap Confirmation Modal -->
    <div v-if="showBootstrapModal" class="modal-overlay" @click.self="cancelBootstrap">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Confirm Bootstrap</h2>
                <button class="modal-close" @click="cancelBootstrap">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-warning">
                    <span class="warning-icon">&#9888;</span>
                    <strong>Create wp-dev Environment</strong>
                </div>
                <div class="modal-details">
                    <p><strong>This will:</strong></p>
                    <ul>
                        <li>Create a <code>wp-dev/</code> folder on production</li>
                        <li>Copy WordPress core files</li>
                        <li>Install Firefly Projects plugin</li>
                        <li>Generate wp-config.php with your database settings</li>
                    </ul>
                    <p><strong>Database:</strong> {{ bootstrapForm.dbName }} @ {{ bootstrapForm.dbHost }}</p>
                    <p><strong>Dev Site URL:</strong> https://{{ bootstrapForm.subdomain }}</p>
                    <p class="modal-description">
                        Make sure your database exists and the credentials are correct before proceeding.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button @click="cancelBootstrap" class="modal-button modal-button-cancel">
                    Cancel
                </button>
                <button @click="performBootstrap" class="modal-button modal-button-confirm">
                    Create wp-dev
                </button>
            </div>
        </div>
    </div>

    <!-- Self-Sync Section for Firefly Projects Plugin -->
    <div class="firefly-self-sync-section">
        <h2>Sync Firefly Projects Plugin</h2>
        <p class="description">Sync the Firefly Projects plugin itself to a remote environment.</p>

        <div class="self-sync-controls">
            <div class="env-toggle-container">
                <span class="env-label" :class="{ active: !selfSyncToProd }">Dev</span>
                <label class="env-toggle-switch">
                    <input type="checkbox" v-model="selfSyncToProd" :disabled="isSyncingSelf">
                    <span class="env-toggle-slider"></span>
                </label>
                <span class="env-label" :class="{ active: selfSyncToProd }">Prod</span>
            </div>

            <button @click="showSelfSyncModal = true" class="action-button primary" :disabled="isSyncingSelf">
                <span v-if="!isSyncingSelf">Sync Firefly Projects</span>
                <span v-else>Syncing...</span>
            </button>
        </div>
    </div>

    <!-- Self-Sync Confirmation Modal -->
    <div v-if="showSelfSyncModal" class="modal-overlay" @click.self="cancelSelfSync">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Confirm Sync</h2>
                <button class="modal-close" @click="cancelSelfSync">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-info">
                    <span class="info-icon">ℹ️</span>
                    <strong>Sync Firefly Projects Plugin</strong>
                </div>
                <div class="modal-details">
                    <p><strong>Target:</strong> /wp-content/plugins/firefly-projects</p>
                    <p><strong>Environment:</strong> <span :class="selfSyncToProd ? 'env-prod' : 'env-dev'" class="backup-env">{{ selfSyncToProd ? 'Production' : 'Dev' }}</span></p>
                    <p class="modal-description">
                        This will sync the Firefly Projects plugin to your <strong>{{ selfSyncToProd ? 'Production' : 'Live Dev' }}</strong> environment.
                        This is useful when you've made changes to the plugin itself.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button @click="cancelSelfSync" class="modal-button modal-button-cancel">
                    Cancel
                </button>
                <button @click="confirmSelfSync" class="modal-button modal-button-confirm">
                    Confirm Sync
                </button>
            </div>
        </div>
    </div>
    <div class="project-selector-container">
        <label for="project-select">Select Project:</label>
        <select id="project-select" v-model="selectedProject" @change="loadProjectFiles" :disabled="isLoadingFiles || isSyncing || isRestoring">
            <option value="">-- Choose a Project --</option>
            <option v-for="project in projects" :key="project.name" :value="project.name">
                {{ project.name }} - {{ project.description }}
            </option>
        </select>

        <div v-if="isLoadingFiles" class="loading-indicator">
            <span>Loading project files...</span>
        </div>
    </div>

    <!-- Toast notification - fixed in top right -->
    <div v-if="message" :class="['toast-notification', 'toast-' + messageType]">
        <div class="toast-content">
            <span class="toast-icon">{{ messageType === 'success' ? '✓' : '✕' }}</span>
            <span class="toast-message">{{ message }}</span>
        </div>
        <button @click="dismissMessage" class="toast-close">&times;</button>
    </div>

    <div v-if="selectedProject && fileTree.length > 0 && !isLoadingFiles" class="file-selector-wrapper">

        <!-- Git Mode toggle — only shown when wp-content/.git exists on the server -->
        <div v-if="gitModeAvailable" class="git-mode-bar" :class="{ 'is-on': gitModeEnabled }">
            <label class="git-mode-toggle">
                <input type="checkbox"
                       v-model="gitModeEnabled"
                       @change="onGitModeToggle"
                       :disabled="isSyncing || isRestoring" />
                <span class="git-mode-switch" aria-hidden="true"></span>
                <span class="git-mode-text">
                    <strong>Git Auto-Detect</strong>
                    <span class="git-mode-hint" v-if="gitModeEnabled && gitChangedFiles.length">
                        {{ gitChangedFiles.length }} file{{ gitChangedFiles.length === 1 ? '' : 's' }} auto-selected
                        <span v-if="gitStatusCounts.modified" class="git-count-chip git-badge-modified">{{ gitStatusCounts.modified }} modified</span>
                        <span v-if="gitStatusCounts.staged"   class="git-count-chip git-badge-staged">{{ gitStatusCounts.staged }} staged</span>
                        <span v-if="gitStatusCounts.untracked" class="git-count-chip git-badge-untracked">{{ gitStatusCounts.untracked }} new</span>
                    </span>
                    <span class="git-mode-hint" v-else-if="gitModeEnabled">
                        No local changes detected — nothing to sync
                    </span>
                    <span class="git-mode-hint" v-else>
                        Toggle on to auto-select files modified in git
                    </span>
                </span>
            </label>
            <button v-if="gitModeEnabled"
                    @click="refreshGitStatus"
                    class="git-mode-refresh"
                    :disabled="isSyncing || isRestoring || isLoadingGitStatus"
                    :title="'Re-read git status'">
                <span v-if="isLoadingGitStatus">Refreshing…</span>
                <span v-else>↻ Refresh</span>
            </button>
        </div>

        <div class="sync-mode-selector" v-if="!gitModeEnabled">
            <label><strong>Sync Mode:</strong></label>
            <label class="sync-mode-option">
                <input type="radio" v-model="syncMode" value="partial" :disabled="isSyncing || isRestoring" />
                <span class="sync-mode-label">
                    <strong>Partial Sync</strong> (update selected files only, keep other files on remote)
                </span>
            </label>
            <label class="sync-mode-option">
                <input type="radio" v-model="syncMode" value="full" :disabled="isSyncing || isRestoring" />
                <span class="sync-mode-label">
                    <strong>Full Sync</strong> (mirror exactly, delete files not in selection)
                </span>
            </label>
            <div v-if="syncMode === 'full'" class="sync-mode-warning">
                <strong>Warning:</strong> Full Sync will mirror your local project completely to the remote site. All files will be synced and any files on the remote not in your local project will be deleted. Checkboxes are disabled because Full Sync always includes all project files.
            </div>
            <div v-if="isAllFilesSelected && syncMode === 'partial'" class="sync-mode-suggestion">
                Tip: You have all files selected. Consider using "Full Sync" to remove any orphaned files.
            </div>
        </div>

        <div class="file-selector-actions">
            <button @click="selectAllFiles" class="action-button" :disabled="isSyncing || isRestoring || syncMode === 'full'">
                Select All
            </button>
            <button @click="deselectAllFiles" class="action-button" :disabled="isSyncing || isRestoring || syncMode === 'full'">
                Deselect All
            </button>
            <button @click="syncSelectedFiles" class="action-button primary" :disabled="!hasSelection || isSyncing || isRestoring">
                <span v-if="!isSyncing">Sync Selected Files ({{ selectedCount }})</span>
                <span v-else>Syncing...</span>
            </button>

            <!-- Environment Toggle Switch -->
            <div class="env-toggle-container" v-if="hasProdEndpoint">
                <span class="env-label" :class="{ active: targetEnv === 'dev' }">Live Dev</span>
                <label class="env-toggle-switch">
                    <input type="checkbox" v-model="targetEnvProd" :disabled="isSyncing || isRestoring" />
                    <span class="env-toggle-slider"></span>
                </label>
                <span class="env-label" :class="{ active: targetEnv === 'prod' }">Production</span>
            </div>
        </div>

        <div class="file-tree-container">
            <ul class="file-tree-root">
                <file-tree-item
                    v-for="node in fileTree"
                    :key="node.path"
                    :node="node"
                    :selected-paths="selectedPaths"
                    :expanded-paths="expandedPaths"
                    :checkboxes-disabled="checkboxesDisabled"
                    :git-status-map="gitStatusMap"
                    :git-mode-enabled="gitModeEnabled"
                    @update="handleUpdate"
                />
            </ul>
        </div>
    </div>

    <div v-if="!showFileSelector && !isLoadingFiles && selectedProject" class="no-files-message">
        <p>No files found for this project.</p>
    </div>

    <!-- Backup History Section -->
    <div v-if="selectedProject" class="backup-history-section">
        <h2>Backup History</h2>
        <p class="description">View and restore from previous syncs (last 5 kept)</p>

        <button @click="loadBackupHistory" class="action-button" :disabled="isLoadingHistory">
            {{ isLoadingHistory ? 'Loading...' : 'Refresh History' }}
        </button>

        <div v-if="backupHistory.length > 0" class="backup-list">
            <div v-for="(backup, backupIndex) in backupHistory" :key="backup.id" class="backup-item">
                <div class="backup-info">
                    <div class="backup-header">
                        <strong>{{ formatTimestamp(backup.timestamp) }}</strong>
                        <span class="backup-env" :class="'env-' + (backup.target_env || 'dev')">
                            {{ (backup.target_env || 'dev') === 'prod' ? 'Production' : 'Live Dev' }}
                        </span>
                    </div>
                    <div class="backup-details">
                        <span class="backup-mode" :class="'mode-' + backup.sync_mode">
                            {{ backup.sync_mode === 'full' ? 'Full Sync' : 'Partial Sync' }}
                        </span>
                        <span class="backup-files">{{ backup.file_count }} files</span>
                        <span v-if="backup.sync_mode === 'partial'" class="backup-selected">
                            ({{ backup.selected_count }} selected)
                        </span>
                        <span class="backup-size">{{ formatFileSize(backup.zip_size) }}</span>
                    </div>
                </div>
                <div class="backup-actions">
                    <template v-if="backupIndex === 0">
                        <button v-if="!activeBackupId || activeBackupId === backup.id"
                                @click="confirmRestore(backupHistory[1])"
                                class="restore-button"
                                :disabled="isRestoring || isDeletingBackup || backupHistory.length < 2"
                                :title="backupHistory.length < 2 ? 'No earlier sync to roll back to' : 'Undo this sync by restoring the previous one'">
                            Rollback
                        </button>
                        <button v-else
                                @click="confirmRestore(backup)"
                                class="restore-button"
                                :disabled="isRestoring || isDeletingBackup"
                                title="Reapply this sync (currently rolled back)">
                            Reapply
                        </button>
                    </template>
                    <button v-else
                            @click="confirmRestore(backup)"
                            class="restore-button" :disabled="isRestoring || isDeletingBackup">
                        Restore
                    </button>
                    <button @click="confirmDeleteBackup(backup)"
                            class="delete-button" :disabled="isRestoring || isDeletingBackup">
                        Delete
                    </button>
                </div>
            </div>
        </div>

        <div v-else-if="!isLoadingHistory" class="no-backups">
            <p>No backup history available. Perform a sync to create the first backup.</p>
        </div>
    </div>

    <!-- Restore Confirmation Modal -->
    <div v-if="showRestoreModal" class="modal-overlay" @click.self="cancelRestore">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Confirm Restore</h2>
                <button class="modal-close" @click="cancelRestore">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-warning">
                    <span class="warning-icon">⚠️</span>
                    <strong>Warning: This will overwrite the remote site</strong>
                </div>

                <div class="modal-details">
                    <p><strong>Restore Point:</strong> {{ restoreBackup ? formatTimestamp(restoreBackup.timestamp) : '' }}</p>
                    <p><strong>Target Environment:</strong>
                        <span :class="'backup-env env-' + (restoreBackup ? (restoreBackup.target_env || 'dev') : 'dev')">
                            {{ restoreBackup ? ((restoreBackup.target_env || 'dev') === 'prod' ? 'Production' : 'Live Dev') : '' }}
                        </span>
                    </p>
                    <p><strong>Sync Mode:</strong> {{ restoreBackup ? (restoreBackup.sync_mode === 'full' ? 'Full Sync' : 'Partial Sync') : '' }}</p>
                    <p><strong>Files:</strong> {{ restoreBackup ? restoreBackup.file_count : 0 }}</p>
                    <p class="modal-description">
                        This will restore the <strong>{{ restoreBackup ? ((restoreBackup.target_env || 'dev') === 'prod' ? 'Production' : 'Live Dev') : '' }}</strong>
                        site to the exact state it was in at this backup point.
                        All current files on the remote will be overwritten. This action cannot be undone.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button @click="cancelRestore" class="modal-button modal-button-cancel">
                    Cancel
                </button>
                <button @click="performRestore" class="modal-button modal-button-confirm modal-button-danger">
                    Confirm Restore
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Backup Confirmation Modal -->
    <div v-if="showDeleteModal" class="modal-overlay" @click.self="cancelDeleteBackup">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Confirm Delete</h2>
                <button class="modal-close" @click="cancelDeleteBackup">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-warning">
                    <span class="warning-icon">⚠️</span>
                    <strong>Delete this backup?</strong>
                </div>

                <div class="modal-details">
                    <p><strong>Backup Date:</strong> {{ deleteBackup ? formatTimestamp(deleteBackup.timestamp) : '' }}</p>
                    <p><strong>Sync Mode:</strong> {{ deleteBackup ? (deleteBackup.sync_mode === 'full' ? 'Full Sync' : 'Partial Sync') : '' }}</p>
                    <p><strong>Files:</strong> {{ deleteBackup ? deleteBackup.file_count : 0 }}</p>
                    <p class="modal-description">
                        This will permanently delete this backup. You will not be able to restore
                        to this point in time after deletion. This action cannot be undone.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button @click="cancelDeleteBackup" class="modal-button modal-button-cancel">
                    Cancel
                </button>
                <button @click="performDeleteBackup" class="modal-button modal-button-confirm modal-button-danger">
                    Delete Backup
                </button>
            </div>
        </div>
    </div>

    <!-- Sync Confirmation Modal -->
    <div v-if="showSyncModal" class="modal-overlay" @click.self="cancelSync">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Confirm Sync</h2>
                <button class="modal-close" @click="cancelSync">&times;</button>
            </div>
            <div class="modal-body">
                <div v-if="syncMode === 'full'" class="modal-warning">
                    <span class="warning-icon">⚠️</span>
                    <strong>Full Sync Mode</strong>
                </div>
                <div v-else class="modal-info">
                    <span class="info-icon">ℹ️</span>
                    <strong>Partial Sync Mode</strong>
                </div>

                <div class="modal-details">
                    <p><strong>Project:</strong> {{ selectedProject }}</p>
                    <p><strong>Target:</strong>
                        <span :class="'backup-env env-' + targetEnv">
                            {{ targetEnv === 'prod' ? 'Production' : 'Live Dev' }}
                        </span>
                    </p>
                    <p><strong>Files to sync:</strong> {{ selectedCount }}</p>
                    <p v-if="syncMode === 'full'" class="modal-description">
                        This will <strong>completely mirror</strong> your local project to
                        <strong>{{ targetEnv === 'prod' ? 'Production' : 'Live Dev' }}</strong>.
                        All {{ selectedCount }} files will be synced, and any files on the remote that
                        are not in your local project will be <strong>permanently deleted</strong>.
                    </p>
                    <p v-else class="modal-description">
                        This will <strong>update only the selected files</strong> ({{ selectedCount }} files)
                        on <strong>{{ targetEnv === 'prod' ? 'Production' : 'Live Dev' }}</strong>. Other files will remain unchanged.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button @click="cancelSync" class="modal-button modal-button-cancel">
                    Cancel
                </button>
                <button @click="confirmSync" class="modal-button modal-button-confirm"
                        :class="{ 'modal-button-danger': syncMode === 'full' }">
                    {{ syncMode === 'full' ? 'Confirm Full Sync' : 'Confirm Partial Sync' }}
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Legacy Projects Table (Hidden - for backward compatibility reference) -->
<div style="display: none;">
<?php if (!empty($projects_data)): ?>
    <h2>Legacy Quick Update</h2>
    <p class="description">Quick update all files (no selection).</p>
    <table class="projects-table">
        <thead>
            <tr>
                <th>Project Name</th>
                <th>Description</th>
                <th>Update</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($projects_data as $project):
                // Ensure keys exist to avoid notices
                $name        = isset($project['name']) ? $project['name'] : 'No Name';
                $description = isset($project['description']) ? $project['description'] : 'No Description';
            ?>
                <tr>
                    <td><?php echo esc_html($name); ?></td>
                    <td><?php echo esc_html($description); ?></td>
                    <td>
                        <!-- Update button -->
                        <button
                            class="update-project-button"
                            data-project-name="<?php echo esc_attr($name); ?>">
                            Update
                        </button>
                        <!-- Message container -->
                        <div class="update-message-container" aria-live="polite"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No projects found.</p>
<?php endif; ?>
</div>