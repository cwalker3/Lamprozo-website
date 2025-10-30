<!-- PHP Debug Output -->
<?php
error_log('[Firefly Debug View] START OF VIEW FILE');
error_log('[Firefly Debug View] $projects_data type: ' . gettype($projects_data));
error_log('[Firefly Debug View] $projects_data isset: ' . (isset($projects_data) ? 'YES' : 'NO'));

// CRITICAL CHECK: If $projects_data is not set or not an array, default to empty array
if (!isset($projects_data) || !is_array($projects_data)) {
    error_log('[Firefly Debug View] WARNING: $projects_data is not set or not an array, defaulting to empty array');
    $projects_data = array();
}

if (isset($projects_data)) {
    error_log('[Firefly Debug View] $projects_data count: ' . count($projects_data));
    error_log('[Firefly Debug View] $projects_data content: ' . print_r($projects_data, true));
}
$json_encoded = json_encode($projects_data);
error_log('[Firefly Debug View] JSON encoded: ' . $json_encoded);
error_log('[Firefly Debug View] JSON encode error: ' . json_last_error_msg());
?>
<!-- Projects Data: <?php echo esc_html(print_r($projects_data, true)); ?> -->

<!--
    Project data is now injected via wp_add_inline_script in projects.php
    This ensures proper load order and prevents conflicts with wp_localize_script
-->

<h1>Projects - File Selector</h1>
<p class="description">Select individual files and folders to sync to your live environment.</p>

<div id="project-file-selector" v-cloak>
    <div class="project-selector-container">
        <label for="project-select">Select Project:</label>
        <select id="project-select" v-model="selectedProject" @change="loadProjectFiles" :disabled="isLoadingFiles || isSyncing">
            <option value="">-- Choose a Project --</option>
            <option v-for="project in projects" :key="project.name" :value="project.name">
                {{ project.name }} - {{ project.description }}
            </option>
        </select>

        <div v-if="isLoadingFiles" class="loading-indicator">
            <img src="<?php echo plugin_dir_url(__FILE__) . '../images/loading.gif'; ?>" alt="Loading..." />
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
                    <p><strong>Files to sync:</strong> {{ selectedCount }}</p>
                    <p v-if="syncMode === 'full'" class="modal-description">
                        This will <strong>completely mirror</strong> your local project to the remote site.
                        All {{ selectedCount }} files will be synced, and any files on the remote that
                        are not in your local project will be <strong>permanently deleted</strong>.
                    </p>
                    <p v-else class="modal-description">
                        This will <strong>update only the selected files</strong> ({{ selectedCount }} files)
                        on the remote site. Other files will remain unchanged.
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