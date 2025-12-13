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
    <!-- Self-Sync Section for Firefly Projects Plugin -->
    <div class="firefly-self-sync-section">
        <h2>Sync Firefly Projects Plugin</h2>
        <p class="description">Sync the Firefly Projects plugin itself to your Live Dev environment.</p>
        <button @click="showSelfSyncModal = true" class="action-button primary" :disabled="isSyncingSelf">
            <span v-if="!isSyncingSelf">Sync Firefly Projects</span>
            <span v-else>Syncing...</span>
        </button>
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
                    <p><strong>Mode:</strong> Partial Sync</p>
                    <p class="modal-description">
                        This will sync the Firefly Projects plugin to your Live Dev environment.
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
        <select id="project-select" v-model="selectedProject" @change="loadProjectFiles" :disabled="isLoadingFiles || isSyncing">
            <option value="">-- Choose a Project --</option>
            <option v-for="project in projects" :key="project.name" :value="project.name">
                {{ project.name }} - {{ project.description }}
            </option>
        </select>

        <div v-if="isLoadingFiles" class="loading-indicator">
            <span>Loading project files...</span>
        </div>
    </div>

    <!-- Warning: Project needs -dev suffix -->
    <div v-if="currentProjectNeedsDev" class="dev-suffix-warning">
        <div class="warning-header">
            <span class="warning-icon">⚠️</span>
            <strong>Action Required: Add -dev Suffix to Plugins/Themes</strong>
        </div>
        <p>The following plugins/themes in this project do not have the <code>-dev</code> suffix:</p>
        <ul class="dev-suffix-list">
            <li v-for="item in currentProjectNeedsDev" :key="item.folder">
                <strong>{{ item.folder }}</strong> ({{ item.type }})
                →
                <strong>{{ item.folder }}-dev</strong>
            </li>
        </ul>
        <p>
            <strong>Why is this important?</strong> The <code>-dev</code> suffix ensures your syncs target development versions
            on Live Dev, never the production versions. This prevents accidentally overwriting live code.
        </p>
        <button @click="showDevSuffixModal = true" class="action-button primary" :disabled="isAddingDevSuffix">
            <span v-if="!isAddingDevSuffix">Add -dev Suffix Now</span>
            <span v-else>Processing...</span>
        </button>
    </div>

    <!-- Add -dev Suffix Confirmation Modal -->
    <div v-if="showDevSuffixModal" class="modal-overlay" @click.self="cancelDevSuffix">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Confirm -dev Suffix Addition</h2>
                <button class="modal-close" @click="cancelDevSuffix">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-warning">
                    <span class="warning-icon">⚠️</span>
                    <strong>This will rename folders on your server</strong>
                </div>
                <div class="modal-details">
                    <p><strong>The following folders will be renamed:</strong></p>
                    <table class="dev-suffix-table">
                        <thead>
                            <tr>
                                <th>Current Name</th>
                                <th></th>
                                <th>New Name</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in currentProjectNeedsDev" :key="item.folder">
                                <td><code>{{ item.folder }}</code></td>
                                <td>→</td>
                                <td><code>{{ item.folder }}-dev</code></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="modal-description">
                        <strong>What happens next:</strong><br>
                        1. Folders will be renamed to include <code>-dev</code> suffix<br>
                        2. Your <code>projects.json</code> will be automatically updated<br>
                        3. You'll need to reactivate the renamed plugins/themes in WordPress admin<br>
                        4. Future syncs will target these <code>-dev</code> versions
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button @click="cancelDevSuffix" class="modal-button modal-button-cancel">
                    Cancel
                </button>
                <button @click="confirmDevSuffix" class="modal-button modal-button-confirm modal-button-danger">
                    Rename Folders
                </button>
            </div>
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
        <div class="sync-mode-selector">
            <label><strong>Sync Mode:</strong></label>
            <label class="sync-mode-option">
                <input type="radio" v-model="syncMode" value="partial" :disabled="isSyncing" />
                <span class="sync-mode-label">
                    <strong>Partial Sync</strong> (update selected files only, keep other files on remote)
                </span>
            </label>
            <label class="sync-mode-option">
                <input type="radio" v-model="syncMode" value="full" :disabled="isSyncing" />
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
            <button @click="selectAllFiles" class="action-button" :disabled="isSyncing || syncMode === 'full'">
                Select All
            </button>
            <button @click="deselectAllFiles" class="action-button" :disabled="isSyncing || syncMode === 'full'">
                Deselect All
            </button>
            <button @click="syncSelectedFiles" class="action-button primary" :disabled="!hasSelection || isSyncing">
                <span v-if="!isSyncing">Sync Selected Files ({{ selectedCount }})</span>
                <span v-else>Syncing...</span>
            </button>

            <!-- Environment Toggle Switch -->
            <div class="env-toggle-container" v-if="hasProdEndpoint">
                <span class="env-label" :class="{ active: targetEnv === 'dev' }">Live Dev</span>
                <label class="env-toggle-switch">
                    <input type="checkbox" v-model="targetEnvProd" :disabled="isSyncing" />
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
            <div v-for="backup in backupHistory" :key="backup.id" class="backup-item">
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
                    <button @click="confirmRestore(backup)"
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