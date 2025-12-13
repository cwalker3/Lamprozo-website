/**
 * Project File Selector Vue.js Application
 * Provides hierarchical file/folder selection interface for project syncing
 */

document.addEventListener('DOMContentLoaded', () => {
    const { createApp } = Vue;

    // Recursive File Tree Item Component
    const FileTreeItem = {
        name: 'FileTreeItem',
        props: {
            node: {
                type: Object,
                required: true
            },
            selectedPaths: {
                type: Set,
                required: true
            },
            expandedPaths: {
                type: Set,
                required: true
            },
            checkboxesDisabled: {
                type: Boolean,
                required: true
            }
        },
        template: `
            <li class="file-tree-item">
                <div class="file-tree-node" :class="{ 'is-directory': node.type === 'directory' }">
                    <span v-if="node.type === 'directory'"
                          class="toggle-icon"
                          @click="toggleExpanded">
                        {{ isExpanded ? '▼' : '▶' }}
                    </span>
                    <span v-else class="file-spacer"></span>

                    <input
                        type="checkbox"
                        :id="'file-' + node.path"
                        :checked="isChecked"
                        :disabled="checkboxesDisabled"
                        :indeterminate.prop="isIndeterminate"
                        @change="handleCheckboxChange"
                        class="file-checkbox"
                    />

                    <label :for="'file-' + node.path" class="file-label">
                        <span class="file-icon">{{ node.type === 'directory' ? '📁' : '📄' }}</span>
                        <span class="file-name">{{ node.name }}</span>
                        <span v-if="node.type === 'file'" class="file-meta">
                            ({{ formatFileSize(node.size) }}, {{ node.modified }})
                        </span>
                    </label>
                </div>

                <ul v-if="node.type === 'directory' && isExpanded && node.children && node.children.length > 0"
                    class="file-tree-children">
                    <file-tree-item
                        v-for="child in node.children"
                        :key="child.path"
                        :node="child"
                        :selected-paths="selectedPaths"
                        :expanded-paths="expandedPaths"
                        :checkboxes-disabled="checkboxesDisabled"
                        @update="$emit('update')"
                    />
                </ul>
            </li>
        `,
        computed: {
            isExpanded() {
                return this.expandedPaths.has(this.node.path);
            },
            isChecked() {
                return this.selectedPaths.has(this.node.path);
            },
            isIndeterminate() {
                if (this.node.type !== 'directory' || !this.node.children || this.node.children.length === 0) {
                    return false;
                }

                const checkedChildren = this.node.children.filter(child =>
                    this.isChildFullyChecked(child)
                );

                return checkedChildren.length > 0 && checkedChildren.length < this.node.children.length;
            }
        },
        methods: {
            toggleExpanded() {
                if (this.node.type !== 'directory') return;

                const path = this.node.path;

                // Toggle expansion state
                if (this.expandedPaths.has(path)) {
                    this.expandedPaths.delete(path);
                } else {
                    this.expandedPaths.add(path);

                    // Smooth scroll to center this folder in viewport
                    this.$nextTick(() => {
                        const element = this.$el;
                        if (element) {
                            element.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });
                        }
                    });
                }

                this.$emit('update');
            },
            handleCheckboxChange(event) {
                const isChecked = event.target.checked;

                if (isChecked) {
                    this.addPathRecursive(this.node);
                } else {
                    this.removePathRecursive(this.node);
                }

                this.$emit('update');
            },
            addPathRecursive(node) {
                this.selectedPaths.add(node.path);

                if (node.type === 'directory' && node.children) {
                    node.children.forEach(child => {
                        this.addPathRecursive(child);
                    });
                }
            },
            removePathRecursive(node) {
                this.selectedPaths.delete(node.path);

                if (node.type === 'directory' && node.children) {
                    node.children.forEach(child => {
                        this.removePathRecursive(child);
                    });
                }
            },
            isChildFullyChecked(node) {
                if (!this.selectedPaths.has(node.path)) {
                    return false;
                }

                if (node.type === 'directory' && node.children) {
                    return node.children.every(child => this.isChildFullyChecked(child));
                }

                return true;
            },
            formatFileSize(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
        }
    };

    // Main Application
    const app = createApp({
        data() {
            return {
                projects: [],
                projectsNeedingDev: {}, // Object mapping project names to arrays of items needing -dev
                selectedProject: '',
                fileTree: [],
                selectedPaths: new Set(),
                expandedPaths: new Set(), // Track which folder paths are expanded
                isLoading: false,
                isLoadingFiles: false,
                isSyncing: false,
                isSyncingSelf: false, // For firefly-projects self-sync
                isAddingDevSuffix: false, // For adding -dev suffix
                message: '',
                messageType: '', // 'success' or 'error'
                showFileSelector: false,
                apiUrl: '',
                nonce: '',
                syncMode: 'partial', // Default to safe mode: 'partial' or 'full'
                totalFileCount: 0, // Track total files for all-files detection
                showSyncModal: false, // Control modal visibility
                showSelfSyncModal: false, // Control self-sync modal
                showDevSuffixModal: false, // Control dev suffix modal
                savedPartialSelections: new Set(), // Store selections when switching to Full Sync
                backupHistory: [],
                isLoadingHistory: false,
                isRestoring: false,
                showRestoreModal: false,
                restoreBackup: null,
                isDeletingBackup: false,
                showDeleteModal: false,
                deleteBackup: null,
                targetEnvProd: false, // false = dev, true = prod
                hasProdEndpoint: false // Whether PROD_ENDPOINT is configured
            };
        },
        computed: {
            selectedCount() {
                return this.selectedPaths.size;
            },
            hasSelection() {
                return this.selectedPaths.size > 0;
            },
            isAllFilesSelected() {
                return this.totalFileCount > 0 && this.selectedPaths.size === this.totalFileCount;
            },
            checkboxesDisabled() {
                return this.syncMode === 'full';
            },
            currentProjectNeedsDev() {
                if (!this.selectedProject || !this.projectsNeedingDev) {
                    return null;
                }
                return this.projectsNeedingDev[this.selectedProject] || null;
            },
            targetEnv() {
                return this.targetEnvProd ? 'prod' : 'dev';
            }
        },
        watch: {
            selectedPaths: {
                handler(newPaths) {
                    // Save selections to session storage
                    sessionStorage.setItem('firefly_selected_paths', JSON.stringify(Array.from(newPaths)));
                },
                deep: true
            },
            expandedPaths: {
                handler(newPaths) {
                    // Persist expansion state
                    sessionStorage.setItem('firefly_expanded_paths', JSON.stringify(Array.from(newPaths)));
                },
                deep: true
            },
            selectedProject(newProject) {
                // Save selected project to session storage
                if (newProject) {
                    sessionStorage.setItem('firefly_selected_project', newProject);
                }
            },
            syncMode(newMode, oldMode) {
                sessionStorage.setItem('firefly_sync_mode', newMode);

                if (newMode === 'full') {
                    // Switching TO Full Sync: Save current selections
                    this.savedPartialSelections = new Set(this.selectedPaths);

                    // Auto-select all files
                    this.selectAllFiles();
                } else if (oldMode === 'full' && newMode === 'partial') {
                    // Switching FROM Full Sync TO Partial: Restore saved selections
                    if (this.savedPartialSelections.size > 0) {
                        this.selectedPaths = new Set(this.savedPartialSelections);
                    }
                }
            },
            savedPartialSelections: {
                handler(newSelections) {
                    sessionStorage.setItem('firefly_saved_partial_selections', JSON.stringify(Array.from(newSelections)));
                },
                deep: true
            },
            targetEnvProd(newValue) {
                sessionStorage.setItem('firefly_target_env', newValue ? 'prod' : 'dev');
            }
        },
        methods: {
            async loadProjects() {
                // Parse projects from PHP data injected into the page
                if (typeof window.projectData === 'undefined') {
                    this.message = 'Error: Project data not loaded. Please refresh the page.';
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] loadProjects - window.projectData is undefined');
                    return;
                }

                // Extract configuration
                this.apiUrl = window.projectData.apiUrl || '';
                this.nonce = window.projectData.nonce || '';
                this.projectsNeedingDev = window.projectData.projectsNeedingDev || {};
                this.hasProdEndpoint = window.projectData.hasProdEndpoint || false;

                // Validate required data
                if (!this.apiUrl) {
                    this.message = 'Error: API URL not configured.';
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] loadProjects - API URL is missing from projectData');
                    return;
                }

                if (!this.nonce) {
                    this.message = 'Error: Security nonce not configured.';
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] loadProjects - Nonce is missing from projectData');
                    return;
                }

                // Load projects
                if (Array.isArray(window.projectData.projects) && window.projectData.projects.length > 0) {
                    this.projects = window.projectData.projects;
                } else {
                    this.message = 'No projects found. Please check your projects.json file.';
                    this.messageType = 'error';
                    console.warn('[Firefly Projects Error] loadProjects - No projects found in projectData.projects');
                }
            },
            async loadProjectFiles() {
                if (!this.selectedProject) {
                    this.message = 'Please select a project.';
                    this.messageType = 'error';
                    console.warn('[Firefly Projects Error] loadProjectFiles - No project selected');
                    return;
                }

                if (!this.apiUrl || !this.nonce) {
                    this.message = 'Error: API configuration missing. Please refresh the page.';
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] loadProjectFiles - API configuration missing');
                    return;
                }

                this.isLoadingFiles = true;
                this.message = '';
                this.fileTree = [];
                this.selectedPaths.clear();
                this.showFileSelector = false;

                const url = `${this.apiUrl}get-project-files?project_name=${encodeURIComponent(this.selectedProject)}`;

                try {
                    const response = await fetch(url, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'X-WP-Nonce': this.nonce
                        }
                    });

                    const data = await response.json();

                    if (data.success) {
                        if (Array.isArray(data.files) && data.files.length > 0) {
                            this.fileTree = data.files;
                            this.showFileSelector = true;

                            // Count total files for all-files detection
                            this.totalFileCount = this.countTotalFiles(this.fileTree);

                            // If Full Sync mode is active, always select all files (ignore saved selections)
                            if (this.syncMode === 'full') {
                                this.selectAllFiles();
                            } else {
                                // Partial Sync: Try to restore saved selections from session storage
                                const savedPaths = sessionStorage.getItem('firefly_selected_paths');
                                const savedProject = sessionStorage.getItem('firefly_selected_project');

                                if (savedPaths && savedProject === this.selectedProject) {
                                    try {
                                        const paths = JSON.parse(savedPaths);
                                        this.selectedPaths = new Set(paths);
                                    } catch (e) {
                                        console.error('[Firefly Projects Error] loadProjectFiles - Failed to restore selections:', e);
                                        // Fall back to selecting all files
                                        this.selectAllFiles();
                                    }
                                } else {
                                    // Auto-select all files initially
                                    this.selectAllFiles();
                                }
                            }

                            // Load backup history for this project
                            this.loadBackupHistory();
                        } else {
                            this.message = 'No files found for this project.';
                            this.messageType = 'error';
                            console.warn('[Firefly Projects Error] loadProjectFiles - No files in response');
                        }
                    } else {
                        this.message = data.message || 'Failed to load project files.';
                        this.messageType = 'error';
                        console.error('[Firefly Projects Error] loadProjectFiles - Error response:', data);
                    }
                } catch (error) {
                    this.message = 'Network error: ' + error.message;
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] loadProjectFiles - Exception caught:', error);
                    console.error('[Firefly Projects Error] loadProjectFiles - Error stack:', error.stack);
                } finally {
                    this.isLoadingFiles = false;
                }
            },
            selectAllFiles() {
                this.selectedPaths.clear();
                this.fileTree.forEach(node => {
                    this.addNodeRecursive(node);
                });
                this.$forceUpdate();
            },
            deselectAllFiles() {
                this.selectedPaths.clear();
                this.$forceUpdate();
            },
            addNodeRecursive(node) {
                this.selectedPaths.add(node.path);

                if (node.type === 'directory' && node.children) {
                    node.children.forEach(child => {
                        this.addNodeRecursive(child);
                    });
                }
            },
            syncSelectedFiles() {
                if (!this.selectedProject) {
                    this.message = 'Please select a project first.';
                    this.messageType = 'error';
                    return;
                }

                if (this.selectedPaths.size === 0) {
                    this.message = 'Please select at least one file to sync.';
                    this.messageType = 'error';
                    return;
                }

                // Show confirmation modal instead of syncing immediately
                this.showSyncModal = true;
            },
            cancelSync() {
                this.showSyncModal = false;
            },
            async confirmSync() {
                this.showSyncModal = false;

                // Now perform the actual sync
                await this.performSync();
            },
            async performSync() {
                if (!this.apiUrl || !this.nonce) {
                    this.message = 'Error: API configuration missing. Please refresh the page.';
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] performSync - API configuration missing');
                    return;
                }

                this.isSyncing = true;
                this.message = '';

                const selectedArray = Array.from(this.selectedPaths);

                const url = `${this.apiUrl}update-project`;
                const payload = {
                    project_name: this.selectedProject,
                    selected_files: selectedArray,
                    sync_mode: this.syncMode,
                    target_env: this.targetEnv
                };

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': this.nonce
                        },
                        body: JSON.stringify(payload)
                    });

                    const data = await response.json();

                    if (data.success) {
                        const envLabel = this.targetEnv === 'prod' ? 'Production' : 'Live Dev';
                        this.message = `Project "${this.selectedProject}" synced to ${envLabel}! (${this.selectedCount} items)`;
                        this.messageType = 'success';

                        // Reload backup history to show new backup
                        await this.loadBackupHistory();

                        // Auto-dismiss success messages after 5 seconds
                        setTimeout(() => {
                            this.dismissMessage();
                        }, 5000);
                    } else {
                        this.message = data.message || 'Failed to sync project.';
                        this.messageType = 'error';
                        console.error('[Firefly Projects Error] performSync - Error response:', data);
                        // Errors stay visible until manually dismissed
                    }
                } catch (error) {
                    this.message = 'Network error: ' + error.message;
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] performSync - Exception caught:', error);
                    console.error('[Firefly Projects Error] performSync - Error stack:', error.stack);
                } finally {
                    this.isSyncing = false;
                }
            },
            handleUpdate() {
                // Force reactivity update for checkbox states
                this.$forceUpdate();
            },
            countTotalFiles(nodes) {
                // Recursively count all files in the tree
                let count = 0;
                for (const node of nodes) {
                    if (node.type === 'file') {
                        count++;
                    } else if (node.type === 'directory' && node.children) {
                        count += this.countTotalFiles(node.children);
                    }
                }
                return count;
            },
            async loadBackupHistory() {
                if (!this.selectedProject) return;

                this.isLoadingHistory = true;

                try {
                    const url = `${this.apiUrl}get-backup-history?project_name=${encodeURIComponent(this.selectedProject)}`;
                    const response = await fetch(url, {
                        credentials: 'same-origin',
                        headers: {
                            'X-WP-Nonce': this.nonce
                        }
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.backupHistory = data.backups || [];
                    }
                } catch (error) {
                    console.error('[Firefly Projects Error] loadBackupHistory:', error);
                } finally {
                    this.isLoadingHistory = false;
                }
            },
            confirmRestore(backup) {
                this.restoreBackup = backup;
                this.showRestoreModal = true;
            },
            cancelRestore() {
                this.showRestoreModal = false;
                this.restoreBackup = null;
            },
            async performRestore() {
                if (!this.restoreBackup) return;

                this.showRestoreModal = false;
                this.isRestoring = true;
                this.message = '';

                try {
                    const url = `${this.apiUrl}restore-backup`;
                    const response = await fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': this.nonce
                        },
                        body: JSON.stringify({
                            project_name: this.selectedProject,
                            backup_id: this.restoreBackup.id
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.message = data.message;
                        this.messageType = 'success';

                        // Reload backup history to update current marker
                        await this.loadBackupHistory();

                        setTimeout(() => this.dismissMessage(), 5000);
                    } else {
                        this.message = data.message || 'Failed to restore backup.';
                        this.messageType = 'error';
                    }
                } catch (error) {
                    this.message = 'Network error: ' + error.message;
                    this.messageType = 'error';
                } finally {
                    this.isRestoring = false;
                    this.restoreBackup = null;
                }
            },
            confirmDeleteBackup(backup) {
                this.deleteBackup = backup;
                this.showDeleteModal = true;
            },
            cancelDeleteBackup() {
                this.showDeleteModal = false;
                this.deleteBackup = null;
            },
            async performDeleteBackup() {
                if (!this.deleteBackup) return;

                this.showDeleteModal = false;
                this.isDeletingBackup = true;
                this.message = '';

                try {
                    const url = `${this.apiUrl}delete-backup`;
                    const response = await fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': this.nonce
                        },
                        body: JSON.stringify({
                            project_name: this.selectedProject,
                            backup_id: this.deleteBackup.id
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.message = 'Backup deleted successfully';
                        this.messageType = 'success';

                        // Reload backup history
                        await this.loadBackupHistory();

                        setTimeout(() => this.dismissMessage(), 5000);
                    } else {
                        this.message = data.message || 'Failed to delete backup.';
                        this.messageType = 'error';
                        console.error('[Firefly Projects Error] performDeleteBackup - Error:', data);
                    }
                } catch (error) {
                    this.message = 'Network error: ' + error.message;
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] performDeleteBackup - Exception:', error);
                } finally {
                    this.isDeletingBackup = false;
                    this.deleteBackup = null;
                }
            },
            formatTimestamp(timestamp) {
                const date = new Date(timestamp);
                return date.toLocaleString();
            },
            formatFileSize(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            },
            dismissMessage() {
                // Add dismissing class for animation
                const toastEl = document.querySelector('.toast-notification');
                if (toastEl) {
                    toastEl.classList.add('toast-dismissing');
                    setTimeout(() => {
                        this.message = '';
                        this.messageType = '';
                    }, 300); // Wait for animation
                } else {
                    this.message = '';
                    this.messageType = '';
                }
            },
            // Self-sync methods
            cancelSelfSync() {
                this.showSelfSyncModal = false;
            },
            async confirmSelfSync() {
                this.showSelfSyncModal = false;
                this.isSyncingSelf = true;
                this.message = '';

                try {
                    const url = `${this.apiUrl}sync-self`;
                    const response = await fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': this.nonce
                        },
                        body: JSON.stringify({
                            sync_mode: 'partial'
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.message = 'Firefly Projects plugin synced successfully!';
                        this.messageType = 'success';
                        setTimeout(() => this.dismissMessage(), 5000);
                    } else {
                        this.message = data.message || 'Failed to sync plugin.';
                        this.messageType = 'error';
                    }
                } catch (error) {
                    this.message = 'Network error: ' + error.message;
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] confirmSelfSync:', error);
                } finally {
                    this.isSyncingSelf = false;
                }
            },
            // Dev suffix methods
            cancelDevSuffix() {
                this.showDevSuffixModal = false;
            },
            async confirmDevSuffix() {
                this.showDevSuffixModal = false;
                this.isAddingDevSuffix = true;
                this.message = '';

                try {
                    const url = `${this.apiUrl}add-dev-suffix`;
                    const response = await fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': this.nonce
                        },
                        body: JSON.stringify({
                            project_name: this.selectedProject
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        const renamedCount = data.renamed ? data.renamed.length : 0;
                        this.message = `Successfully renamed ${renamedCount} folder(s) to include -dev suffix. Please reactivate the renamed plugins/themes in WordPress admin.`;
                        this.messageType = 'success';

                        // Remove this project from projectsNeedingDev
                        if (this.projectsNeedingDev[this.selectedProject]) {
                            delete this.projectsNeedingDev[this.selectedProject];
                        }

                        // Reload projects to get updated paths
                        setTimeout(() => {
                            window.location.reload();
                        }, 3000);
                    } else {
                        this.message = data.message || 'Failed to add -dev suffix.';
                        this.messageType = 'error';
                    }
                } catch (error) {
                    this.message = 'Network error: ' + error.message;
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] confirmDevSuffix:', error);
                } finally {
                    this.isAddingDevSuffix = false;
                }
            }
        },
        mounted() {
            if (typeof window.projectData === 'undefined') {
                console.error('[Firefly Projects Error] Vue app - window.projectData is UNDEFINED at mount time');
            }

            this.loadProjects();

            // Restore expansion state from session storage
            const savedExpanded = sessionStorage.getItem('firefly_expanded_paths');
            if (savedExpanded) {
                try {
                    const paths = JSON.parse(savedExpanded);
                    this.expandedPaths = new Set(paths);
                } catch (e) {
                    console.error('[Firefly Projects Error] Vue app - Failed to restore expansion state:', e);
                }
            }

            // Restore sync mode preference from session storage
            const savedSyncMode = sessionStorage.getItem('firefly_sync_mode');
            if (savedSyncMode && (savedSyncMode === 'full' || savedSyncMode === 'partial')) {
                this.syncMode = savedSyncMode;
            }

            // Restore target environment preference from session storage
            const savedTargetEnv = sessionStorage.getItem('firefly_target_env');
            if (savedTargetEnv === 'prod') {
                this.targetEnvProd = true;
            }

            // Restore saved partial selections
            const savedPartialSelections = sessionStorage.getItem('firefly_saved_partial_selections');
            if (savedPartialSelections) {
                try {
                    const paths = JSON.parse(savedPartialSelections);
                    this.savedPartialSelections = new Set(paths);
                } catch (e) {
                    console.error('[Firefly Projects Error] Vue app - Failed to restore saved partial selections:', e);
                }
            }

            // Restore saved project selection
            const savedProject = sessionStorage.getItem('firefly_selected_project');
            if (savedProject) {
                // Wait for projects to load, then check if saved project exists
                this.$nextTick(() => {
                    if (this.projects.find(p => p.name === savedProject)) {
                        this.selectedProject = savedProject;
                        this.loadProjectFiles();
                    }
                });
            }
        }
    });

    // Register FileTreeItem component globally for recursive use
    app.component('FileTreeItem', FileTreeItem);

    app.mount('#project-file-selector');
});
