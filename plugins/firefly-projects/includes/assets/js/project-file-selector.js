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
            },
            gitStatusMap: {
                type: Object,
                default: () => ({})
            },
            gitModeEnabled: {
                type: Boolean,
                default: false
            }
        },
        template: `
            <li class="file-tree-item" :class="{ 'is-dimmed': isDimmedByGitMode }">
                <div class="file-tree-node" :class="{ 'is-directory': node.type === 'directory', ['git-' + gitStatus]: gitStatus }">
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
                        <span v-if="gitStatus" :class="'git-badge git-badge-' + gitStatus" :title="gitBadgeTitle">
                            {{ gitBadgeLabel }}
                        </span>
                        <span v-if="node.type === 'file' && !isDeletedGhost" class="file-meta">
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
                        :git-status-map="gitStatusMap"
                        :git-mode-enabled="gitModeEnabled"
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
            },
            // Per-file git status classification: 'staged' | 'modified' | 'untracked' | ''
            gitStatus() {
                if (this.node.type !== 'file') return '';
                return this.gitStatusMap[this.node.path] || '';
            },
            gitBadgeLabel() {
                return {
                    staged:    'staged',
                    modified:  'modified',
                    untracked: 'new',
                    deleted:   'deleted'
                }[this.gitStatus] || '';
            },
            gitBadgeTitle() {
                return {
                    staged:    'Staged for commit',
                    modified:  'Modified, not staged',
                    untracked: 'New file, not tracked',
                    deleted:   'Deleted locally — will be removed on the remote when synced'
                }[this.gitStatus] || '';
            },
            // True for ghost nodes injected for git-deleted files. Used
            // to apply the strikethrough/dimmed treatment and to skip
            // the size/modified meta (the file doesn't exist on disk).
            isDeletedGhost() {
                return this.node && this.node.deleted === true;
            },
            // When git mode is on, dim files that have no git status AND
            // aren't manually checked — keeps focus on the changes. Users
            // can still click-check a dimmed file (it un-dims while checked,
            // re-dims when unchecked). Deleted ghosts are never dimmed —
            // they already carry their own strikethrough treatment.
            isDimmedByGitMode() {
                if (!this.gitModeEnabled) return false;
                if (this.node.type !== 'file') return false;
                if (this.gitStatus) return false;
                if (this.isChecked) return false;
                return true;
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
                selfSyncToProd: false, // false = dev, true = prod for self-sync
                showDevSuffixModal: false, // Control dev suffix modal
                savedPartialSelections: new Set(), // Store selections when switching to Full Sync
                backupHistory: [],
                activeBackupId: '',
                isLoadingHistory: false,
                isRestoring: false,
                showRestoreModal: false,
                restoreBackup: null,
                isDeletingBackup: false,
                showDeleteModal: false,
                deleteBackup: null,
                targetEnvProd: false, // false = dev, true = prod
                hasProdEndpoint: false, // Whether PROD_ENDPOINT is configured
                // Bootstrap wp-dev environment
                bootstrapStatus: 'checking', // 'checking', 'exists', 'not-exists', 'error', 'success'
                bootstrapError: '',
                isBootstrapping: false,
                showBootstrapModal: false,
                bootstrapForm: {
                    subdomain: '',
                    dbName: '',
                    dbUser: '',
                    dbPassword: '',
                    dbHost: 'localhost',
                    tablePrefix: 'wp_'
                },
                // Git Mode state
                gitModeAvailable: false,    // wp-content/.git exists on server
                gitModeEnabled: false,      // user toggle (persisted in user_meta)
                gitChangedFiles: [],        // absolute file paths in project scope
                gitStatusMap: {},           // { "/wp-content/x": "staged" | "modified" | "untracked" }
                gitStatusCounts: { staged: 0, modified: 0, untracked: 0, deleted: 0 },
                isLoadingGitStatus: false
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
                return this.syncMode === 'full' || this.isSyncing || this.isRestoring;
            },
            currentProjectNeedsDev() {
                if (!this.selectedProject || !this.projectsNeedingDev) {
                    return null;
                }
                return this.projectsNeedingDev[this.selectedProject] || null;
            },
            targetEnv() {
                return this.targetEnvProd ? 'prod' : 'dev';
            },
            isBootstrapFormValid() {
                return this.bootstrapForm.dbName.trim() !== '' &&
                       this.bootstrapForm.dbUser.trim() !== '' &&
                       this.bootstrapForm.dbPassword.trim() !== '' &&
                       this.bootstrapForm.dbHost.trim() !== '' &&
                       this.bootstrapForm.subdomain.trim() !== '';
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

                            // Refresh git status for this project (no-op if unavailable)
                            this.fetchGitStatus();
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
                        this.activeBackupId = data.active_backup_id || '';
                    }
                } catch (error) {
                    console.error('[Firefly Projects Error] loadBackupHistory:', error);
                } finally {
                    this.isLoadingHistory = false;
                }
            },

            // -----------------------------------------------------------
            // Git Mode
            // -----------------------------------------------------------

            /** Pull git status + user toggle state from the server. */
            async fetchGitStatus() {
                if (!this.apiUrl) return;
                this.isLoadingGitStatus = true;
                try {
                    const qs = this.selectedProject
                        ? '?project_name=' + encodeURIComponent(this.selectedProject)
                        : '';
                    const resp = await fetch(`${this.apiUrl}git-status${qs}`, {
                        credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': this.nonce }
                    });
                    const data = await resp.json();

                    this.gitModeAvailable = !!data.git_available;
                    this.gitModeEnabled   = !!data.git_mode_enabled && this.gitModeAvailable;
                    this.gitChangedFiles  = Array.isArray(data.in_scope_files) ? data.in_scope_files : [];
                    this.gitStatusMap     = (data.status_map && typeof data.status_map === 'object') ? data.status_map : {};
                    this.gitStatusCounts  = Object.assign({ staged: 0, modified: 0, untracked: 0, deleted: 0 }, data.status_counts || {});

                    if (this.gitModeEnabled) {
                        this.applyGitSelection();
                    }
                } catch (err) {
                    console.error('[Firefly Projects] fetchGitStatus failed:', err);
                } finally {
                    this.isLoadingGitStatus = false;
                }
            },

            /** Given a list of file paths, compute every ancestor directory
             *  path ("/wp-content/a/b/c/file.php" → "/wp-content/a", "/a/b",
             *  "/a/b/c") so those folders can be expanded in the tree. */
            _collectAncestorPaths(filePaths) {
                const ancestors = new Set();
                for (const p of filePaths) {
                    const parts = p.split('/').filter(Boolean);
                    let cur = '';
                    for (let i = 0; i < parts.length - 1; i++) {
                        cur += '/' + parts[i];
                        ancestors.add(cur);
                    }
                }
                return ancestors;
            },

            /** User clicked the git-mode toggle — persist + apply. */
            async onGitModeToggle() {
                // v-model already updated this.gitModeEnabled before this fires.
                const desired = this.gitModeEnabled;

                try {
                    const resp = await fetch(`${this.apiUrl}git-mode`, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': this.nonce
                        },
                        body: JSON.stringify({ enabled: desired })
                    });
                    const data = await resp.json();
                    if (!data.success) throw new Error('toggle rejected');
                } catch (err) {
                    console.error('[Firefly Projects] onGitModeToggle failed:', err);
                    // Revert optimistic state on failure
                    this.gitModeEnabled = !desired;
                    return;
                }

                if (desired) {
                    // Stash current manual selection so we can restore it when
                    // the user turns git mode off.
                    this.savedPartialSelections = new Set(this.selectedPaths);
                    await this.fetchGitStatus();  // refreshes selection + forces partial
                } else {
                    // Restore the user's pre-git-mode manual selection.
                    this.selectedPaths = new Set(this.savedPartialSelections);
                }
            },

            /** Manual flush + re-read (user clicks refresh).
             *  Re-loads the file tree (refreshes mtimes/sizes) AND re-reads
             *  git status, while preserving the user's current selection. */
            async refreshGitStatus() {
                if (!this.selectedProject) {
                    await this.fetchGitStatus();
                    return;
                }
                // Stash selection + git mode state so reload doesn't drop them.
                const savedSelection = new Set(this.selectedPaths);
                const savedGitMode   = this.gitModeEnabled;
                const savedSyncMode  = this.syncMode;

                await this.loadProjectFiles();

                // loadProjectFiles resets selection state — restore it.
                this.selectedPaths  = savedSelection;
                this.gitModeEnabled = savedGitMode;
                this.syncMode       = savedSyncMode;

                // loadProjectFiles already kicks off fetchGitStatus, but if
                // git mode is on we want its selection refresh to win.
                if (savedGitMode) {
                    await this.fetchGitStatus();
                }
            },

            /** Apply the current gitChangedFiles list as the selection,
             *  auto-expand parent folders of each selected file so they're
             *  visible in the tree, and lock sync mode to partial. */
            applyGitSelection() {
                this.syncMode = 'partial';
                this.selectedPaths = new Set(this.gitChangedFiles);
                const ancestors = this._collectAncestorPaths(this.gitChangedFiles);
                const merged = new Set(this.expandedPaths);
                ancestors.forEach(p => merged.add(p));
                this.expandedPaths = merged;
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

                const targetEnv = this.selfSyncToProd ? 'prod' : 'dev';

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
                            sync_mode: 'partial',
                            target_env: targetEnv
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        const envName = this.selfSyncToProd ? 'Production' : 'Live Dev';
                        this.message = `Firefly Projects plugin synced to ${envName} successfully!`;
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
            },
            // Bootstrap methods
            async checkDevExists() {
                this.bootstrapStatus = 'checking';
                this.bootstrapError = '';

                try {
                    // Check if wp-dev exists on production
                    // Handle both /update-project and /update_project endpoint formats
                    const prodUrl = window.projectData.prodEndpoint.replace(/\/update[-_]project$/, '/check-dev-exists');
                    const checkResponse = await fetch(prodUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Firefly-Secret': window.projectData.sharedSecret
                        },
                        body: JSON.stringify({})
                    });

                    const checkData = await checkResponse.json();

                    if (checkData.exists) {
                        this.bootstrapStatus = 'exists';
                    } else {
                        this.bootstrapStatus = 'not-exists';
                    }
                } catch (error) {
                    this.bootstrapStatus = 'error';
                    this.bootstrapError = error.message || 'Failed to check wp-dev status';
                    console.error('[Firefly Projects Error] checkDevExists:', error);
                }
            },
            cancelBootstrap() {
                this.showBootstrapModal = false;
            },
            async performBootstrap() {
                this.showBootstrapModal = false;
                this.isBootstrapping = true;
                this.bootstrapError = '';

                try {
                    // Step 1: Generate the WP bundle locally
                    this.message = 'Generating WordPress bundle...';
                    this.messageType = 'success';

                    const generateUrl = `${this.apiUrl}generate-wp-bundle`;
                    const generateResponse = await fetch(generateUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': this.nonce
                        },
                        body: JSON.stringify({
                            db_name: this.bootstrapForm.dbName,
                            db_user: this.bootstrapForm.dbUser,
                            db_password: this.bootstrapForm.dbPassword,
                            db_host: this.bootstrapForm.dbHost,
                            table_prefix: this.bootstrapForm.tablePrefix,
                            dev_subdomain: this.bootstrapForm.subdomain
                        })
                    });

                    const generateData = await generateResponse.json();

                    if (!generateData.success) {
                        throw new Error(generateData.message || 'Failed to generate bundle');
                    }

                    // Step 2: Send the bundle to production
                    this.message = 'Sending bundle to production...';

                    // Handle both /update-project and /update_project endpoint formats
                    const prodUrl = window.projectData.prodEndpoint.replace(/\/update[-_]project$/, '/bootstrap-dev');
                    console.log(prodUrl);
                    const bootstrapResponse = await fetch(prodUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Firefly-Secret': window.projectData.sharedSecret
                        },
                        body: JSON.stringify({
                            wp_bundle: generateData.wp_bundle,
                            plugin_bundle: generateData.plugin_bundle,
                            wp_config: generateData.wp_config
                        })
                    });

                    const bootstrapData = await bootstrapResponse.json();

                    if (!bootstrapData.success) {
                        throw new Error(bootstrapData.message || 'Failed to bootstrap wp-dev');
                    }

                    this.bootstrapStatus = 'success';
                    this.message = 'wp-dev environment created successfully!';
                    this.messageType = 'success';

                } catch (error) {
                    this.bootstrapStatus = 'error';
                    this.bootstrapError = error.message || 'Failed to bootstrap wp-dev';
                    this.message = 'Bootstrap failed: ' + this.bootstrapError;
                    this.messageType = 'error';
                    console.error('[Firefly Projects Error] performBootstrap:', error);
                } finally {
                    this.isBootstrapping = false;
                }
            }
        },
        mounted() {
            if (typeof window.projectData === 'undefined') {
                console.error('[Firefly Projects Error] Vue app - window.projectData is UNDEFINED at mount time');
            }

            this.loadProjects();

            // Probe git-mode availability + user preference on page load
            // so the toggle renders correctly even before a project is picked.
            this.fetchGitStatus();

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

            // Auto-check if wp-dev exists on production
            if (window.projectData.prodEndpoint && window.projectData.sharedSecret) {
                this.checkDevExists();
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
