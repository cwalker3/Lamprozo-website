/**
 * Firefly Projects - Pages List Sync
 *
 * jQuery-based UI for syncing pages from the WordPress Pages admin list.
 * Supports individual page sync and bulk "Sync All Pages" with Dev/Prod toggle.
 */
(function($) {
    'use strict';

    // Get configuration from PHP
    var config = window.fireflyPagesSync || {};

    // State
    var state = {
        targetEnvProd: localStorage.getItem('firefly_pages_sync_env') === 'prod',
        isSyncing: false,
        syncMode: 'safe', // 'safe' or 'mirror'
        orphanCount: 0,
        // Pull state
        pullEnvProd: localStorage.getItem('firefly_pages_pull_env') === 'prod',
        isPulling: false,
        remotePages: [],
        selectedPages: {},
        pullSearchTerm: ''
    };

    /**
     * Format timestamp for display
     */
    function formatDate(timestamp) {
        if (!timestamp || timestamp === 0 || timestamp === '') {
            return null;
        }
        var date = new Date(parseInt(timestamp) * 1000);
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    /**
     * Get environment label
     */
    function getEnvLabel() {
        return state.targetEnvProd ? 'Production' : 'Live Dev';
    }

    /**
     * Get environment class suffix
     */
    function getEnvClass() {
        return state.targetEnvProd ? 'prod' : 'dev';
    }

    /**
     * Get pull environment label
     */
    function getPullEnvLabel() {
        return state.pullEnvProd ? 'Production' : 'Live Dev';
    }

    /**
     * Get pull environment class suffix
     */
    function getPullEnvClass() {
        return state.pullEnvProd ? 'prod' : 'dev';
    }

    /**
     * Update toolbar toggle UI
     */
    function updateToolbarToggle() {
        var $container = $('#firefly-pages-toolbar');
        if (!$container.length) return;

        var $switch = $container.find('.firefly-env-toggle-switch');
        var $labels = $container.find('.firefly-env-toggle-label');
        var $devLabel = $labels.first();
        var $prodLabel = $labels.last();
        var $syncButton = $container.find('#firefly-sync-all-pages');

        if (state.targetEnvProd) {
            $switch.addClass('is-prod');
            $devLabel.removeClass('active');
            $prodLabel.addClass('active');
            $syncButton.addClass('firefly-sync-button-prod');
        } else {
            $switch.removeClass('is-prod');
            $devLabel.addClass('active');
            $prodLabel.removeClass('active');
            $syncButton.removeClass('firefly-sync-button-prod');
        }
    }

    /**
     * Close modal
     */
    function closeModal() {
        $('#firefly-pages-sync-modal').remove();
    }

    /**
     * Show individual page sync modal
     */
    function showSinglePageModal(pageData) {
        var envLabel = getEnvLabel();
        var envClass = getEnvClass();
        var lastSync = state.targetEnvProd ? pageData.lastSyncProd : pageData.lastSyncDev;
        var lastSyncFormatted = formatDate(lastSync);

        var warningClass = state.targetEnvProd ? 'firefly-sync-warning firefly-sync-warning-prod' : 'firefly-sync-warning';
        var warningText = state.targetEnvProd
            ? 'This will update the PRODUCTION site'
            : 'This will update the Live Dev site';

        var modalHtml = '<div id="firefly-pages-sync-modal" class="firefly-sync-modal-overlay">' +
            '<div class="firefly-sync-modal">' +
                '<div class="firefly-sync-modal-header">' +
                    '<h2>Sync Page to Remote</h2>' +
                    '<button type="button" class="firefly-modal-close">&times;</button>' +
                '</div>' +
                '<div class="firefly-modal-content">' +
                    // Environment toggle in modal
                    (config.hasProdEndpoint ? buildEnvToggleHtml() : '') +
                    '<div class="' + warningClass + '" id="firefly-modal-warning">' +
                        '<span class="dashicons dashicons-warning"></span>' +
                        '<span>' + warningText + '</span>' +
                    '</div>' +
                    '<div class="firefly-sync-summary">' +
                        '<h4>Sync Details</h4>' +
                        '<table class="firefly-sync-table">' +
                            '<tr><th>Page</th><td>' + escapeHtml(pageData.postTitle) + '</td></tr>' +
                            '<tr><th>Target</th><td><span class="firefly-env-badge firefly-env-' + envClass + '" id="firefly-modal-target-badge">' + envLabel + '</span></td></tr>' +
                            '<tr><th>Last Sync</th><td id="firefly-modal-last-sync">' + (lastSyncFormatted || '<em>Never synced</em>') + '</td></tr>' +
                        '</table>' +
                    '</div>' +
                    '<p class="firefly-sync-description">' +
                        'The page content and assets will be synced to the remote site.' +
                    '</p>' +
                '</div>' +
                '<div class="firefly-modal-footer">' +
                    '<button type="button" class="button firefly-modal-cancel">Cancel</button>' +
                    '<button type="button" class="button button-primary firefly-modal-confirm' + (state.targetEnvProd ? ' firefly-confirm-sync-prod' : '') + '" id="firefly-confirm-single">' +
                        'Sync to ' + envLabel +
                    '</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        $('body').append(modalHtml);

        // Store page data for sync
        $('#firefly-pages-sync-modal').data('pageData', pageData);

        // Bind modal events
        bindModalEvents();
        bindModalEnvToggle(pageData);
    }

    /**
     * Show bulk sync modal
     */
    function showBulkSyncModal() {
        var envLabel = getEnvLabel();
        var envClass = getEnvClass();

        var modalHtml = '<div id="firefly-pages-sync-modal" class="firefly-sync-modal-overlay">' +
            '<div class="firefly-sync-modal">' +
                '<div class="firefly-sync-modal-header">' +
                    '<h2>Sync All Pages</h2>' +
                    '<button type="button" class="firefly-modal-close">&times;</button>' +
                '</div>' +
                '<div class="firefly-modal-content">' +
                    // Environment toggle
                    (config.hasProdEndpoint ? buildEnvToggleHtml() : '') +
                    // Sync mode selection
                    '<div class="firefly-sync-mode-section">' +
                        '<h4>Sync Mode</h4>' +
                        '<div class="firefly-sync-mode-options">' +
                            '<label class="firefly-sync-mode-option">' +
                                '<input type="radio" name="sync_mode" value="safe" checked>' +
                                '<div>' +
                                    '<strong>Safe Sync</strong>' +
                                    '<p class="firefly-sync-mode-description">Sync all local pages. Remote-only pages are kept.</p>' +
                                '</div>' +
                            '</label>' +
                            '<label class="firefly-sync-mode-option">' +
                                '<input type="radio" name="sync_mode" value="mirror">' +
                                '<div>' +
                                    '<strong>Mirror Sync</strong>' +
                                    '<p class="firefly-sync-mode-description">Make remote an exact copy. Remote-only pages are <strong>deleted</strong>.</p>' +
                                '</div>' +
                            '</label>' +
                        '</div>' +
                    '</div>' +
                    // Warning for mirror mode (hidden by default)
                    '<div class="firefly-sync-warning firefly-sync-warning-prod firefly-mirror-warning" id="firefly-mirror-warning" style="display: none;">' +
                        '<span class="dashicons dashicons-warning"></span>' +
                        '<span id="firefly-orphan-warning-text">Checking remote pages...</span>' +
                    '</div>' +
                    // Summary
                    '<div class="firefly-sync-summary">' +
                        '<table class="firefly-sync-table">' +
                            '<tr><th>Pages</th><td>' + config.pageCount + ' published page(s)</td></tr>' +
                            '<tr><th>Target</th><td><span class="firefly-env-badge firefly-env-' + envClass + '" id="firefly-modal-target-badge">' + envLabel + '</span></td></tr>' +
                        '</table>' +
                    '</div>' +
                '</div>' +
                '<div class="firefly-modal-footer">' +
                    '<button type="button" class="button firefly-modal-cancel">Cancel</button>' +
                    '<button type="button" class="button button-primary firefly-modal-confirm' + (state.targetEnvProd ? ' firefly-confirm-sync-prod' : '') + '" id="firefly-confirm-bulk">' +
                        'Sync All to ' + envLabel +
                    '</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        $('body').append(modalHtml);

        // Bind events
        bindModalEvents();
        bindModalEnvToggle();
        bindSyncModeChange();

        // Fetch orphan count for current environment
        fetchOrphanCount();
    }

    /**
     * Build environment toggle HTML for modal
     */
    function buildEnvToggleHtml() {
        var isProd = state.targetEnvProd;
        return '<div class="firefly-env-toggle-container firefly-modal-env-toggle">' +
            '<div class="firefly-env-toggle-row">' +
                '<span class="firefly-env-toggle-label' + (!isProd ? ' active' : '') + '">Live Dev</span>' +
                '<button type="button" role="switch" class="firefly-env-toggle-switch' + (isProd ? ' is-prod' : '') + '" id="firefly-modal-env-toggle">' +
                    '<span class="firefly-env-toggle-knob"></span>' +
                '</button>' +
                '<span class="firefly-env-toggle-label' + (isProd ? ' active' : '') + '">Production</span>' +
            '</div>' +
        '</div>';
    }

    /**
     * Bind common modal events
     */
    function bindModalEvents() {
        var $modal = $('#firefly-pages-sync-modal');

        $modal.on('click', '.firefly-modal-close, .firefly-modal-cancel', closeModal);
        $modal.on('click', function(e) {
            if ($(e.target).is('.firefly-sync-modal-overlay')) {
                closeModal();
            }
        });

        // Single page sync
        $modal.on('click', '#firefly-confirm-single', function() {
            var pageData = $modal.data('pageData');
            if (pageData) {
                performSingleSync(pageData.postId);
            }
        });

        // Bulk sync
        $modal.on('click', '#firefly-confirm-bulk', function() {
            var syncMode = $modal.find('input[name="sync_mode"]:checked').val() || 'safe';
            performBulkSync(syncMode);
        });
    }

    /**
     * Bind environment toggle in modal
     */
    function bindModalEnvToggle(pageData) {
        var $modal = $('#firefly-pages-sync-modal');

        $modal.on('click', '#firefly-modal-env-toggle, .firefly-modal-env-toggle .firefly-env-toggle-label', function(e) {
            e.preventDefault();
            if (state.isSyncing) return;

            var $this = $(this);
            if ($this.hasClass('firefly-env-toggle-label')) {
                var isProdLabel = $this.is(':last-child') || $this.text() === 'Production';
                if (isProdLabel === state.targetEnvProd) return;
            }

            // Toggle state
            state.targetEnvProd = !state.targetEnvProd;
            localStorage.setItem('firefly_pages_sync_env', state.targetEnvProd ? 'prod' : 'dev');

            // Update toolbar
            updateToolbarToggle();

            // Update modal UI
            updateModalEnvUI(pageData);

            // Fetch new orphan count if bulk modal
            if ($modal.find('#firefly-confirm-bulk').length) {
                fetchOrphanCount();
            }
        });
    }

    /**
     * Update modal UI after environment change
     */
    function updateModalEnvUI(pageData) {
        var $modal = $('#firefly-pages-sync-modal');
        var envLabel = getEnvLabel();
        var envClass = getEnvClass();

        // Update toggle
        var $switch = $modal.find('#firefly-modal-env-toggle');
        var $labels = $modal.find('.firefly-modal-env-toggle .firefly-env-toggle-label');

        if (state.targetEnvProd) {
            $switch.addClass('is-prod');
            $labels.first().removeClass('active');
            $labels.last().addClass('active');
        } else {
            $switch.removeClass('is-prod');
            $labels.first().addClass('active');
            $labels.last().removeClass('active');
        }

        // Update warning
        var $warning = $modal.find('#firefly-modal-warning');
        if ($warning.length) {
            if (state.targetEnvProd) {
                $warning.addClass('firefly-sync-warning-prod');
                $warning.find('span:last').text('This will update the PRODUCTION site');
            } else {
                $warning.removeClass('firefly-sync-warning-prod');
                $warning.find('span:last').text('This will update the Live Dev site');
            }
        }

        // Update badge
        var $badge = $modal.find('#firefly-modal-target-badge');
        $badge.removeClass('firefly-env-dev firefly-env-prod')
              .addClass('firefly-env-' + envClass)
              .text(envLabel);

        // Update button
        var $confirmBtn = $modal.find('.firefly-modal-confirm');
        if (state.targetEnvProd) {
            $confirmBtn.addClass('firefly-confirm-sync-prod');
        } else {
            $confirmBtn.removeClass('firefly-confirm-sync-prod');
        }
        $confirmBtn.text($confirmBtn.attr('id') === 'firefly-confirm-bulk' ? 'Sync All to ' + envLabel : 'Sync to ' + envLabel);

        // Update last sync for single page modal
        if (pageData) {
            var lastSync = state.targetEnvProd ? pageData.lastSyncProd : pageData.lastSyncDev;
            var lastSyncFormatted = formatDate(lastSync);
            $modal.find('#firefly-modal-last-sync').html(lastSyncFormatted || '<em>Never synced</em>');
        }
    }

    /**
     * Bind sync mode radio change
     */
    function bindSyncModeChange() {
        var $modal = $('#firefly-pages-sync-modal');

        $modal.on('change', 'input[name="sync_mode"]', function() {
            var mode = $(this).val();
            state.syncMode = mode;

            if (mode === 'mirror') {
                $('#firefly-mirror-warning').show();
            } else {
                $('#firefly-mirror-warning').hide();
            }
        });
    }

    /**
     * Fetch orphan count from server
     */
    function fetchOrphanCount() {
        var targetEnv = state.targetEnvProd ? 'prod' : 'dev';

        $('#firefly-orphan-warning-text').text('Checking remote pages...');

        $.ajax({
            url: config.restUrl + 'pages-orphan-count',
            method: 'GET',
            data: { target_env: targetEnv },
            headers: { 'X-WP-Nonce': config.nonce },
            success: function(response) {
                state.orphanCount = response.orphan_count || 0;
                if (state.orphanCount > 0) {
                    $('#firefly-orphan-warning-text').text(
                        'Mirror mode will DELETE ' + state.orphanCount + ' page(s) on remote that don\'t exist locally.'
                    );
                } else {
                    $('#firefly-orphan-warning-text').text(
                        'No pages will be deleted (remote has no extra pages).'
                    );
                    $('#firefly-mirror-warning').removeClass('firefly-sync-warning-prod');
                }
            },
            error: function() {
                $('#firefly-orphan-warning-text').text('Could not check remote pages.');
            }
        });
    }

    /**
     * Perform single page sync
     */
    function performSingleSync(postId) {
        if (state.isSyncing) return;

        state.isSyncing = true;

        var $modal = $('#firefly-pages-sync-modal');
        var $confirmBtn = $modal.find('#firefly-confirm-single');
        var $cancelBtn = $modal.find('.firefly-modal-cancel');

        $confirmBtn.prop('disabled', true).text('Syncing...');
        $cancelBtn.prop('disabled', true);

        $.ajax({
            url: config.restUrl + 'sync-page',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                post_id: postId,
                include_assets: true,
                target_env: state.targetEnvProd ? 'prod' : 'dev'
            }),
            headers: { 'X-WP-Nonce': config.nonce },
            success: function(response) {
                showSyncResult(true, response.message, response.details);
                // Notify the per-row activity log to refresh if its panel is open.
                $(document).trigger('firefly:sync-page:done', [{ postId: postId, success: true }]);
            },
            error: function(xhr) {
                var message = 'Sync failed.';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) message = resp.message;
                } catch (e) {}
                showSyncResult(false, message);
                $(document).trigger('firefly:sync-page:done', [{ postId: postId, success: false }]);
            },
            complete: function() {
                state.isSyncing = false;
            }
        });
    }

    /**
     * Perform bulk sync
     */
    function performBulkSync(syncMode) {
        if (state.isSyncing) return;

        state.isSyncing = true;

        var $modal = $('#firefly-pages-sync-modal');
        var $content = $modal.find('.firefly-modal-content');
        var $footer = $modal.find('.firefly-modal-footer');

        // Show progress UI
        $content.html(
            '<div class="firefly-sync-progress">' +
                '<h4>Syncing Pages to ' + getEnvLabel() + '...</h4>' +
                '<div class="firefly-progress-bar">' +
                    '<div class="firefly-progress-fill' + (state.targetEnvProd ? ' is-prod' : '') + '" style="width: 0%"></div>' +
                '</div>' +
                '<p class="firefly-progress-text">Starting sync...</p>' +
            '</div>'
        );
        $footer.html('<button type="button" class="button firefly-modal-cancel" disabled>Please wait...</button>');

        $.ajax({
            url: config.restUrl + 'sync-all-pages',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                sync_mode: syncMode,
                target_env: state.targetEnvProd ? 'prod' : 'dev'
            }),
            headers: { 'X-WP-Nonce': config.nonce },
            timeout: 300000, // 5 minutes
            success: function(response) {
                showBulkResult(response);
            },
            error: function(xhr) {
                var message = 'Sync failed.';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) message = resp.message;
                } catch (e) {}
                showSyncResult(false, message);
            },
            complete: function() {
                state.isSyncing = false;
            }
        });
    }

    /**
     * Show sync result in modal
     */
    function showSyncResult(success, message, details) {
        var $modal = $('#firefly-pages-sync-modal');
        var $content = $modal.find('.firefly-modal-content');
        var $footer = $modal.find('.firefly-modal-footer');

        var statusClass = success ? 'notice-success' : 'notice-error';
        var html = '<div class="notice ' + statusClass + ' firefly-sync-result">' +
            '<p>' + escapeHtml(message) + '</p>' +
        '</div>';

        $content.html(html);
        $footer.html('<button type="button" class="button firefly-modal-cancel">Close</button>');
    }

    /**
     * Show bulk sync result
     */
    function showBulkResult(response) {
        var $modal = $('#firefly-pages-sync-modal');
        var $content = $modal.find('.firefly-modal-content');
        var $footer = $modal.find('.firefly-modal-footer');

        var details = response.details || {};
        var success = details.synced === details.total && details.failed === 0;
        var statusClass = success ? 'notice-success' : (details.synced > 0 ? 'notice-warning' : 'notice-error');

        var html = '<div class="notice ' + statusClass + ' firefly-sync-result">' +
            '<p><strong>' + escapeHtml(response.message) + '</strong></p>' +
        '</div>';

        html += '<div class="firefly-bulk-results">' +
            '<table class="firefly-sync-table">' +
                '<tr><th>Synced</th><td>' + details.synced + ' of ' + details.total + '</td></tr>' +
                '<tr><th>Failed</th><td>' + details.failed + '</td></tr>';

        if (details.sync_mode === 'mirror') {
            html += '<tr><th>Deleted</th><td>' + details.deleted + '</td></tr>';
        }

        html += '</table>';

        // Show errors if any
        if (details.errors && details.errors.length > 0) {
            html += '<div class="firefly-sync-errors">' +
                '<h4>Errors:</h4>' +
                '<ul>';
            details.errors.forEach(function(err) {
                html += '<li>' + escapeHtml(err.title || err) + '</li>';
            });
            html += '</ul></div>';
        }

        // Show deleted pages if any
        if (details.deleted_pages && details.deleted_pages.length > 0) {
            html += '<div class="firefly-sync-deleted">' +
                '<h4>Deleted Remote Pages:</h4>' +
                '<ul>';
            details.deleted_pages.forEach(function(page) {
                html += '<li>' + escapeHtml(page.title) + ' <code>' + escapeHtml(page.slug) + '</code></li>';
            });
            html += '</ul></div>';
        }

        html += '</div>';

        $content.html(html);
        $footer.html('<button type="button" class="button button-primary firefly-modal-cancel">Close</button>');
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ============================================================================
    // PULL PAGES FUNCTIONALITY
    // ============================================================================

    /**
     * Close pull modal
     */
    function closePullModal() {
        $('#firefly-pages-pull-modal').remove();
        state.remotePages = [];
        state.selectedPages = {};
        state.pullSearchTerm = '';
    }

    /**
     * Build pull environment toggle HTML
     */
    function buildPullEnvToggleHtml() {
        var isProd = state.pullEnvProd;
        return '<div class="firefly-env-toggle-container firefly-modal-env-toggle">' +
            '<div class="firefly-env-toggle-row">' +
                '<span class="firefly-env-toggle-label' + (!isProd ? ' active' : '') + '">Live Dev</span>' +
                '<button type="button" role="switch" class="firefly-env-toggle-switch' + (isProd ? ' is-prod' : '') + '" id="firefly-pull-modal-env-toggle">' +
                    '<span class="firefly-env-toggle-knob"></span>' +
                '</button>' +
                '<span class="firefly-env-toggle-label' + (isProd ? ' active' : '') + '">Production</span>' +
            '</div>' +
        '</div>';
    }

    /**
     * Show pull pages modal
     */
    function showPullPagesModal() {
        var envLabel = getPullEnvLabel();
        var envClass = getPullEnvClass();

        var modalHtml = '<div id="firefly-pages-pull-modal" class="firefly-sync-modal-overlay">' +
            '<div class="firefly-sync-modal firefly-pull-modal-wide">' +
                '<div class="firefly-sync-modal-header">' +
                    '<h2>Pull Pages from Remote</h2>' +
                    '<button type="button" class="firefly-modal-close">&times;</button>' +
                '</div>' +
                '<div class="firefly-modal-content">' +
                    // Environment toggle
                    (config.hasProdEndpoint ? buildPullEnvToggleHtml() : '') +
                    // Source info
                    '<div class="firefly-pull-source-info">' +
                        '<span class="firefly-env-badge firefly-env-' + envClass + '" id="firefly-pull-source-badge">' + envLabel + '</span>' +
                    '</div>' +
                    // Search and selection controls
                    '<div class="firefly-pull-controls">' +
                        '<input type="text" id="firefly-pull-search" class="regular-text" placeholder="Search pages...">' +
                        '<div class="firefly-pull-select-controls">' +
                            '<button type="button" class="button button-small" id="firefly-pull-select-all">Select All</button>' +
                            '<button type="button" class="button button-small" id="firefly-pull-deselect-all">Deselect All</button>' +
                            '<span class="firefly-pull-selection-count" id="firefly-pull-selection-count">0 selected</span>' +
                        '</div>' +
                    '</div>' +
                    // Page list container
                    '<div class="firefly-pull-page-list" id="firefly-pull-page-list">' +
                        '<div class="firefly-pull-loading">' +
                            '<span class="spinner is-active"></span>' +
                            '<span>Loading pages from ' + envLabel + '...</span>' +
                        '</div>' +
                    '</div>' +
                    // Description
                    '<p class="firefly-sync-description">' +
                        'Select pages to pull. Content and assets will be copied to your local environment.' +
                    '</p>' +
                '</div>' +
                '<div class="firefly-modal-footer">' +
                    '<button type="button" class="button firefly-modal-cancel">Cancel</button>' +
                    '<button type="button" class="button button-primary" id="firefly-confirm-pull" disabled>' +
                        'Pull Selected Pages' +
                    '</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        $('body').append(modalHtml);

        // Bind modal events
        bindPullModalEvents();

        // Load pages
        loadRemotePages();
    }

    /**
     * Bind pull modal events
     */
    function bindPullModalEvents() {
        var $modal = $('#firefly-pages-pull-modal');

        // Close handlers
        $modal.on('click', '.firefly-modal-close, .firefly-modal-cancel', closePullModal);
        $modal.on('click', function(e) {
            if ($(e.target).is('.firefly-sync-modal-overlay')) {
                closePullModal();
            }
        });

        // Environment toggle
        $modal.on('click', '#firefly-pull-modal-env-toggle, .firefly-modal-env-toggle .firefly-env-toggle-label', function(e) {
            e.preventDefault();
            if (state.isPulling) return;

            var $this = $(this);
            if ($this.hasClass('firefly-env-toggle-label')) {
                var isProdLabel = $this.text() === 'Production';
                if (isProdLabel === state.pullEnvProd) return;
            }

            // Toggle state
            state.pullEnvProd = !state.pullEnvProd;
            localStorage.setItem('firefly_pages_pull_env', state.pullEnvProd ? 'prod' : 'dev');

            // Update UI
            updatePullModalEnvUI();

            // Reload pages
            loadRemotePages();
        });

        // Search
        var searchTimeout;
        $modal.on('input', '#firefly-pull-search', function() {
            clearTimeout(searchTimeout);
            var term = $(this).val();
            searchTimeout = setTimeout(function() {
                state.pullSearchTerm = term;
                renderPageList();
            }, 200);
        });

        // Select All
        $modal.on('click', '#firefly-pull-select-all', function() {
            selectAllVisiblePages(true);
        });

        // Deselect All
        $modal.on('click', '#firefly-pull-deselect-all', function() {
            selectAllVisiblePages(false);
        });

        // Page checkbox
        $modal.on('change', '.firefly-pull-page-checkbox', function() {
            var slug = $(this).data('slug');
            if ($(this).is(':checked')) {
                state.selectedPages[slug] = true;
            } else {
                delete state.selectedPages[slug];
            }
            updateSelectionCount();
        });

        // Confirm pull
        $modal.on('click', '#firefly-confirm-pull', function() {
            performBulkPull();
        });
    }

    /**
     * Update pull modal environment UI
     */
    function updatePullModalEnvUI() {
        var $modal = $('#firefly-pages-pull-modal');
        var envLabel = getPullEnvLabel();
        var envClass = getPullEnvClass();

        // Update toggle
        var $switch = $modal.find('#firefly-pull-modal-env-toggle');
        var $labels = $modal.find('.firefly-modal-env-toggle .firefly-env-toggle-label');

        if (state.pullEnvProd) {
            $switch.addClass('is-prod');
            $labels.first().removeClass('active');
            $labels.last().addClass('active');
        } else {
            $switch.removeClass('is-prod');
            $labels.first().addClass('active');
            $labels.last().removeClass('active');
        }

        // Update badge
        var $badge = $modal.find('#firefly-pull-source-badge');
        $badge.removeClass('firefly-env-dev firefly-env-prod')
              .addClass('firefly-env-' + envClass)
              .text(envLabel);
    }

    /**
     * Load pages from remote environment
     */
    function loadRemotePages() {
        var $list = $('#firefly-pull-page-list');
        var envLabel = getPullEnvLabel();

        // Show loading
        $list.html(
            '<div class="firefly-pull-loading">' +
                '<span class="spinner is-active"></span>' +
                '<span>Loading pages from ' + envLabel + '...</span>' +
            '</div>'
        );

        // Reset state
        state.remotePages = [];
        state.selectedPages = {};
        updateSelectionCount();

        var sourceEnv = state.pullEnvProd ? 'prod' : 'dev';

        $.ajax({
            url: config.restUrl + 'fetch-remote-pages',
            method: 'GET',
            data: {
                source_env: sourceEnv,
                post_type: 'page'
            },
            headers: { 'X-WP-Nonce': config.nonce },
            success: function(response) {
                if (response.success) {
                    state.remotePages = response.pages || [];
                    renderPageList();
                } else {
                    $list.html(
                        '<div class="notice notice-error"><p>' + escapeHtml(response.message || 'Failed to load pages.') + '</p></div>'
                    );
                }
            },
            error: function(xhr) {
                var message = 'Failed to connect to remote.';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) message = resp.message;
                } catch (e) {}
                $list.html(
                    '<div class="notice notice-error"><p>' + escapeHtml(message) + '</p></div>'
                );
            }
        });
    }

    /**
     * Render the page list based on search filter
     */
    function renderPageList() {
        var $list = $('#firefly-pull-page-list');
        var searchTerm = state.pullSearchTerm.toLowerCase();

        // Filter pages
        var filteredPages = state.remotePages.filter(function(page) {
            if (!searchTerm) return true;
            return (page.title && page.title.toLowerCase().indexOf(searchTerm) !== -1) ||
                   (page.slug && page.slug.toLowerCase().indexOf(searchTerm) !== -1);
        });

        if (filteredPages.length === 0) {
            $list.html(
                '<div class="firefly-pull-no-pages">' +
                    (searchTerm ? 'No pages match your search.' : 'No pages available on remote.') +
                '</div>'
            );
            return;
        }

        var html = '';
        filteredPages.forEach(function(page) {
            var isChecked = state.selectedPages[page.slug] ? ' checked' : '';
            var statusClass = page.status === 'publish' ? 'publish' : 'draft';
            var statusLabel = page.status === 'publish' ? 'Published' : 'Draft';
            var modifiedDate = page.modified ? formatDate(Math.floor(new Date(page.modified).getTime() / 1000)) : '';

            html += '<div class="firefly-pull-page-item">' +
                '<label class="firefly-pull-page-label">' +
                    '<input type="checkbox" class="firefly-pull-page-checkbox" data-slug="' + escapeHtml(page.slug) + '"' + isChecked + '>' +
                    '<div class="firefly-pull-page-info">' +
                        '<div class="firefly-pull-page-header">' +
                            '<span class="firefly-pull-page-title">' + escapeHtml(page.title || '(No title)') + '</span>' +
                            '<span class="firefly-pull-page-status firefly-pull-page-status-' + statusClass + '">' + statusLabel + '</span>' +
                        '</div>' +
                        '<div class="firefly-pull-page-meta">' +
                            '<code>/' + escapeHtml(page.slug) + '</code>' +
                            (modifiedDate ? '<span class="firefly-pull-page-date">Modified: ' + modifiedDate + '</span>' : '') +
                        '</div>' +
                    '</div>' +
                '</label>' +
            '</div>';
        });

        $list.html(html);
    }

    /**
     * Select or deselect all visible pages
     */
    function selectAllVisiblePages(select) {
        var searchTerm = state.pullSearchTerm.toLowerCase();

        state.remotePages.forEach(function(page) {
            var matches = !searchTerm ||
                (page.title && page.title.toLowerCase().indexOf(searchTerm) !== -1) ||
                (page.slug && page.slug.toLowerCase().indexOf(searchTerm) !== -1);

            if (matches) {
                if (select) {
                    state.selectedPages[page.slug] = true;
                } else {
                    delete state.selectedPages[page.slug];
                }
            }
        });

        // Update checkboxes
        $('#firefly-pull-page-list .firefly-pull-page-checkbox').each(function() {
            var slug = $(this).data('slug');
            $(this).prop('checked', !!state.selectedPages[slug]);
        });

        updateSelectionCount();
    }

    /**
     * Update the selection count display
     */
    function updateSelectionCount() {
        var count = Object.keys(state.selectedPages).length;
        $('#firefly-pull-selection-count').text(count + ' selected');
        $('#firefly-confirm-pull').prop('disabled', count === 0);
    }

    /**
     * Perform bulk pull of selected pages
     */
    function performBulkPull() {
        var selectedSlugs = Object.keys(state.selectedPages);
        if (selectedSlugs.length === 0) return;

        state.isPulling = true;

        var $modal = $('#firefly-pages-pull-modal');
        var $content = $modal.find('.firefly-modal-content');
        var $footer = $modal.find('.firefly-modal-footer');
        var envLabel = getPullEnvLabel();
        var sourceEnv = state.pullEnvProd ? 'prod' : 'dev';

        // Show progress UI
        $content.html(
            '<div class="firefly-sync-progress">' +
                '<h4>Pulling ' + selectedSlugs.length + ' page(s) from ' + envLabel + '...</h4>' +
                '<div class="firefly-progress-bar">' +
                    '<div class="firefly-progress-fill' + (state.pullEnvProd ? ' is-prod' : '') + '" id="firefly-pull-progress" style="width: 0%"></div>' +
                '</div>' +
                '<p class="firefly-progress-text" id="firefly-pull-progress-text">Starting pull...</p>' +
            '</div>'
        );
        $footer.html('<button type="button" class="button firefly-modal-cancel" disabled>Please wait...</button>');

        // Pull pages one by one
        var results = {
            total: selectedSlugs.length,
            success: 0,
            failed: 0,
            errors: []
        };
        var currentIndex = 0;

        function pullNextPage() {
            if (currentIndex >= selectedSlugs.length) {
                // All done
                showPullResults(results);
                return;
            }

            var slug = selectedSlugs[currentIndex];
            var page = state.remotePages.find(function(p) { return p.slug === slug; });
            var pageTitle = page ? page.title : slug;

            // Update progress
            var progress = Math.round(((currentIndex + 1) / selectedSlugs.length) * 100);
            $('#firefly-pull-progress').css('width', progress + '%');
            $('#firefly-pull-progress-text').text('Pulling: ' + pageTitle + ' (' + (currentIndex + 1) + '/' + selectedSlugs.length + ')');

            $.ajax({
                url: config.restUrl + 'pull-page',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    post_slug: slug,
                    source_env: sourceEnv
                }),
                headers: { 'X-WP-Nonce': config.nonce },
                success: function(response) {
                    if (response.success) {
                        results.success++;
                    } else {
                        results.failed++;
                        results.errors.push({
                            title: pageTitle,
                            slug: slug,
                            error: response.message || 'Unknown error'
                        });
                    }
                },
                error: function(xhr) {
                    results.failed++;
                    var message = 'Request failed';
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.message) message = resp.message;
                    } catch (e) {}
                    results.errors.push({
                        title: pageTitle,
                        slug: slug,
                        error: message
                    });
                },
                complete: function() {
                    currentIndex++;
                    pullNextPage();
                }
            });
        }

        pullNextPage();
    }

    /**
     * Show pull results
     */
    function showPullResults(results) {
        state.isPulling = false;

        var $modal = $('#firefly-pages-pull-modal');
        var $content = $modal.find('.firefly-modal-content');
        var $footer = $modal.find('.firefly-modal-footer');

        var success = results.failed === 0;
        var statusClass = success ? 'notice-success' : (results.success > 0 ? 'notice-warning' : 'notice-error');
        var message = 'Pulled ' + results.success + ' of ' + results.total + ' page(s).';

        var html = '<div class="notice ' + statusClass + ' firefly-sync-result">' +
            '<p><strong>' + message + '</strong></p>' +
        '</div>';

        html += '<div class="firefly-bulk-results">' +
            '<table class="firefly-sync-table">' +
                '<tr><th>Pulled</th><td>' + results.success + ' of ' + results.total + '</td></tr>' +
                '<tr><th>Failed</th><td>' + results.failed + '</td></tr>' +
            '</table>';

        // Show errors if any
        if (results.errors.length > 0) {
            html += '<div class="firefly-sync-errors">' +
                '<h4>Errors:</h4>' +
                '<ul>';
            results.errors.forEach(function(err) {
                html += '<li><strong>' + escapeHtml(err.title) + '</strong> <code>' + escapeHtml(err.slug) + '</code>: ' + escapeHtml(err.error) + '</li>';
            });
            html += '</ul></div>';
        }

        html += '</div>';

        // Auto-refresh if any pages were pulled successfully
        if (results.success > 0) {
            html += '<p class="firefly-refresh-notice">Refreshing page list...</p>';
            $content.html(html);
            $footer.html('<button type="button" class="button firefly-modal-cancel" disabled>Please wait...</button>');

            // Refresh after a short delay to show the results
            setTimeout(function() {
                window.location.reload();
            }, 1500);
        } else {
            $content.html(html);
            $footer.html('<button type="button" class="button button-primary firefly-modal-cancel">Close</button>');
        }
    }

    /**
     * Initialize
     */
    function init() {
        // Check if we're on the pages list
        if (!$('#firefly-pages-toolbar').length && !$('.firefly-sync-page-link').length) {
            return;
        }

        // Restore environment from localStorage
        if (config.hasProdEndpoint) {
            updateToolbarToggle();
        }

        // Toolbar environment toggle
        $(document).on('click', '#firefly-pages-env-toggle, #firefly-pages-toolbar .firefly-env-toggle-label', function(e) {
            e.preventDefault();
            if (state.isSyncing) return;

            var $this = $(this);
            if ($this.hasClass('firefly-env-toggle-label')) {
                var isProdLabel = $this.text() === 'Production';
                if (isProdLabel === state.targetEnvProd) return;
            }

            state.targetEnvProd = !state.targetEnvProd;
            localStorage.setItem('firefly_pages_sync_env', state.targetEnvProd ? 'prod' : 'dev');
            updateToolbarToggle();
            // Notify other modules (notably the activity log) so open panels
            // re-fetch their remote half for the new env.
            $(document).trigger('firefly:env-changed', [{
                env: state.targetEnvProd ? 'prod' : 'dev'
            }]);
        });

        // Individual page sync link
        $(document).on('click', '.firefly-sync-page-link', function(e) {
            e.preventDefault();
            var $link = $(this);
            showSinglePageModal({
                postId: $link.data('post-id'),
                postTitle: $link.data('post-title'),
                lastSyncDev: $link.data('last-sync-dev'),
                lastSyncProd: $link.data('last-sync-prod')
            });
        });

        // Bulk sync button
        $(document).on('click', '#firefly-sync-all-pages', function(e) {
            e.preventDefault();
            showBulkSyncModal();
        });

        // Pull pages button
        $(document).on('click', '#firefly-pull-pages', function(e) {
            e.preventDefault();
            showPullPagesModal();
        });
    }

    // Initialize when document is ready
    $(document).ready(init);

})(jQuery);

/* =============================================================================
 * Sync activity log — per-row expandable panel (DUAL-SOURCE).
 *
 * Local is the command center; the env toggle in the toolbar (Live Dev /
 * Production) decides which remote's activity is merged into the same panel.
 *
 * On chevron click, fetches BOTH:
 *   - GET /sync-log?post_id=X        (this local's sync_log entries)
 *   - GET /remote-activity?...&env=Y (the remote's sync_log + WP revisions)
 *
 * Entries are tagged with origin = 'local' | 'remote' and rendered as one
 * chronological timeline. Per-entry actions appear contextually:
 *   - "Restore" on any local-side entry with a revision_id
 *   - "Roll back" on any local push entry with summary.pre_push_revision_id
 *
 * When both sides have post-sync edits, a warning strip at the top of the
 * panel offers Push (existing flow) or Restore-to-last-sync.
 * ============================================================================= */
(function ($) {
    'use strict';

    var SyncLog = window.FireflyProjectsSyncLog = window.FireflyProjectsSyncLog || {};

    function getRest() {
        var cfg = window.fireflyPagesSync || window.fireflyPageSync || {};
        return {
            url:   cfg.restUrl   || (window.location.origin + '/wp-json/firefly-plugin/v1/'),
            nonce: cfg.nonce     || ''
        };
    }

    /** Read current env from the toolbar toggle's persisted state. */
    function getCurrentEnv() {
        return (localStorage.getItem('firefly_pages_sync_env') === 'prod') ? 'prod' : 'dev';
    }

    function envLabel(env) { return env === 'prod' ? 'Production' : 'Live Dev'; }

    // Inline SVGs reused in the entries.
    var ICONS = {
        push:     '<svg viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 9 8 4 13 9"/><line x1="8" y1="4" x2="8" y2="13"/></svg>',
        pull:     '<svg viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 7 8 12 13 7"/><line x1="8" y1="3" x2="8" y2="12"/></svg>',
        restore:  '<svg viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8a5 5 0 1 0 1.5-3.5"/><polyline points="2 3 2 6 5 6"/></svg>',
        edit:     '<svg viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 2l3 3-8 8H3v-3l8-8z"/></svg>',
        spinner:  '<span class="firefly-sync-log-spinner" aria-hidden="true"></span>'
    };

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** Fetch local sync log entries. */
    function fetchLocal(postId) {
        var r = getRest();
        return $.ajax({
            url: r.url + 'sync-log',
            method: 'GET',
            data: { post_id: postId, limit: 20 },
            headers: { 'X-WP-Nonce': r.nonce }
        }).then(function (data) {
            return (data && data.entries) ? data.entries : [];
        });
    }

    /** Fetch remote activity for the toggled env (graceful on failure). */
    function fetchRemote(postId, env) {
        var r = getRest();
        return $.ajax({
            url: r.url + 'remote-activity',
            method: 'GET',
            data: { post_id: postId, env: env, limit: 20 },
            headers: { 'X-WP-Nonce': r.nonce }
        }).then(function (data) {
            // Endpoint always returns success:true even when the remote is
            // unreachable; warning + empty entries surface as a banner.
            return data || {};
        }, function () {
            return { warning: 'Could not load remote activity.', sync_log: [], revisions: [] };
        });
    }

    /** Combined fetch + normalization. Returns { entries, warning, env } */
    function fetchCombined(postId, env) {
        return $.when(fetchLocal(postId), fetchRemote(postId, env)).then(function (local, remote) {
            var localEntries  = Array.isArray(local) ? local : [];
            var remoteSync    = (remote && Array.isArray(remote.sync_log))  ? remote.sync_log  : [];
            var remoteRev     = (remote && Array.isArray(remote.revisions)) ? remote.revisions : [];
            var warning       = (remote && remote.warning) ? remote.warning : null;
            var current       = (remote && remote.current) ? remote.current : null;

            // Tag origin + a sortable timestamp on each entry.
            var tagged = [];
            localEntries.forEach(function (e) {
                tagged.push(Object.assign({}, e, { origin: 'local', sort_ts: e.created_at_iso }));
            });
            remoteSync.forEach(function (e) {
                tagged.push(Object.assign({}, e, { origin: 'remote', kind: 'sync', sort_ts: e.created_at_iso }));
            });
            remoteRev.forEach(function (e) {
                // Remote revisions become "edit" entries — distinguish from syncs visually.
                tagged.push(Object.assign({}, e, { origin: 'remote', kind: 'revision', sort_ts: e.created_at_iso }));
            });

            tagged.sort(function (a, b) {
                var ta = a.sort_ts ? Date.parse(a.sort_ts) : 0;
                var tb = b.sort_ts ? Date.parse(b.sort_ts) : 0;
                return tb - ta;
            });

            return { entries: tagged, warning: warning, env: env, current: current };
        });
    }

    function summaryFiles(summary) {
        if (!summary) return [];
        var out = [];
        if (Array.isArray(summary.media_files))      out = out.concat(summary.media_files);
        if (Array.isArray(summary.associated_files)) out = out.concat(summary.associated_files);
        return out;
    }

    function dirIcon(entry) {
        if (entry.kind === 'revision') return ICONS.edit;
        if (entry.direction === 'restore' || entry.direction === 'rollback' || entry.direction === 'rollback_applied') return ICONS.restore;
        if (entry.direction === 'pull')    return ICONS.pull;
        return ICONS.push;
    }

    function dirLabel(entry, env) {
        if (entry.kind === 'revision') return 'Edit on ' + envLabel(env);
        switch (entry.direction) {
            case 'pull':              return 'Pull ← ' + envLabel(entry.env || env);
            case 'restore':           return 'Restore local';
            case 'rollback':          return 'Roll back ' + envLabel(entry.env || env);
            case 'rollback_applied':  return 'Rolled back (applied)';
            case 'push':
            default:                  return 'Push → ' + envLabel(entry.env || env);
        }
    }

    /** Decide which per-entry action buttons are available. */
    function entryActions(entry) {
        var actions = [];
        // Restore local: any local-origin entry with a revision_id that points
        // at a LOCAL revision (sync_log rows store local revision_ids).
        if (entry.origin === 'local' && entry.revision_id) {
            actions.push({
                cls:   'firefly-sync-log-action firefly-sync-log-restore',
                label: 'Restore',
                attrs: 'data-action="restore-local" data-revision-id="' + entry.revision_id + '"'
            });
        }
        // Roll back: a local push success with a recorded pre_push_revision_id.
        if (entry.origin === 'local' && entry.direction === 'push' && entry.status === 'success'
            && entry.summary && entry.summary.pre_push_revision_id) {
            actions.push({
                cls:   'firefly-sync-log-action firefly-sync-log-rollback',
                label: 'Roll back',
                attrs: 'data-action="rollback-push" data-log-id="' + entry.id + '"'
            });
        }
        return actions;
    }

    function renderEntry(entry, env) {
        var isFail   = entry.status === 'failure';
        var iconHtml = dirIcon(entry);
        var dirHtml  = iconHtml + escapeHtml(dirLabel(entry, env));
        var files    = summaryFiles(entry.summary);
        var filesPart = files.length
            ? '<span class="firefly-sync-log-files" title="' + escapeHtml(files.join('\n')) + '">' + entry.files_count + ' file' + (entry.files_count === 1 ? '' : 's') + '</span>'
            : (entry.direction === 'push' ? '<span class="firefly-sync-log-files is-muted">no files</span>' : '');
        var revPart = entry.revision_url
            ? '<a class="firefly-sync-log-revision" href="' + escapeHtml(entry.revision_url) + '">View version</a>'
            : '';
        var actions = entryActions(entry);
        var actionsHtml = actions.map(function (a) {
            return '<button type="button" class="' + a.cls + '" ' + a.attrs + '>' + escapeHtml(a.label) + '</button>';
        }).join('');
        var errPart = (isFail && entry.summary && entry.summary.error_message)
            ? '<div class="firefly-sync-log-error">' + escapeHtml(entry.summary.error_message) + '</div>'
            : '';

        var classes = 'firefly-sync-log-entry'
            + ' is-origin-' + (entry.origin || 'local')
            + ' ' + (isFail ? 'is-failure' : 'is-success')
            + (entry.kind === 'revision' ? ' is-kind-revision' : ' is-kind-sync');

        // Origin chip prefixes every entry so users see at a glance whose
        // event it was (Local vs Live Dev/Prod).
        var originChip = '<span class="firefly-sync-log-origin">'
            + (entry.origin === 'local' ? 'Local' : escapeHtml(envLabel(env)))
            + '</span>';

        return '<li class="' + classes + '" data-entry-id="' + escapeHtml(entry.id) + '">'
            +   '<span class="firefly-sync-log-dot" aria-hidden="true"></span>'
            +   originChip
            +   '<span class="firefly-sync-log-direction">' + dirHtml + '</span>'
            +   '<span class="firefly-sync-log-user">' + escapeHtml(entry.user || 'System') + '</span>'
            +   filesPart
            +   revPart
            +   '<span class="firefly-sync-log-actions">' + actionsHtml + '</span>'
            +   '<time class="firefly-sync-log-time" datetime="' + escapeHtml(entry.created_at_iso || '') + '" title="' + escapeHtml(entry.created_at_iso || '') + '">' + escapeHtml(entry.created_at_human || '') + '</time>'
            +   errPart
            + '</li>';
    }

    /**
     * Detect a both-sides-edited conflict for the conflict strip.
     * "Both sides have edits since the last successful push" means: there's at
     * least one remote entry (sync OR revision) newer than the most recent
     * local push success, AND there's at least one local revision newer than
     * that same push (we approximate with: any local sync_log row newer that
     * isn't itself a push, OR the user has unpushed local edits — but we
     * don't track local revisions in the panel directly, so we settle for
     * "remote moved since our last successful push" as the trigger).
     */
    function findConflict(entries, env) {
        // Most recent local push-success entry.
        var lastPush = null;
        for (var i = 0; i < entries.length; i++) {
            var e = entries[i];
            if (e.origin === 'local' && e.direction === 'push' && e.status === 'success') {
                lastPush = e;
                break;
            }
        }
        if (!lastPush || !lastPush.sort_ts) return null;
        var pushTs = Date.parse(lastPush.sort_ts);

        // Any remote entry newer than the last push?
        var remoteAhead = entries.some(function (e) {
            return e.origin === 'remote' && e.sort_ts && Date.parse(e.sort_ts) > pushTs;
        });
        if (!remoteAhead) return null;

        return {
            last_push:  lastPush,
            env_label:  envLabel(env),
        };
    }

    function renderConflictStrip(conflict) {
        if (!conflict) return '';
        var pushDateAttr = conflict.last_push.created_at_iso || '';
        var pushDateLabel = conflict.last_push.created_at_human || 'last push';
        var restoreAttrs = 'data-action="restore-local" data-revision-id="' + (conflict.last_push.revision_id || '') + '"';
        return '<div class="firefly-sync-log-conflict">'
            +   '<span class="firefly-sync-log-conflict-icon">⚠</span>'
            +   '<span class="firefly-sync-log-conflict-text">'
            +     escapeHtml(conflict.env_label) + ' has changed since your last sync ('
            +     '<time datetime="' + escapeHtml(pushDateAttr) + '">' + escapeHtml(pushDateLabel) + '</time>).'
            +   '</span>'
            +   '<button type="button" class="button button-primary firefly-sync-log-conflict-push" data-action="push-now">Push your local now</button>'
            +   (conflict.last_push.revision_id
                ? '<button type="button" class="button firefly-sync-log-action" ' + restoreAttrs + '>Restore local to last sync</button>'
                : '')
            + '</div>';
    }

    function renderBody(combined) {
        var html = '';
        var warning = combined.warning;
        if (warning) {
            html += '<div class="firefly-sync-log-warning">' + escapeHtml(warning) + '</div>';
        }
        var conflict = findConflict(combined.entries, combined.env);
        if (conflict) html += renderConflictStrip(conflict);

        if (!combined.entries || combined.entries.length === 0) {
            html += '<div class="firefly-sync-log-empty">No sync activity yet for this page.</div>';
            return html;
        }

        html += '<ol class="firefly-sync-log-timeline">';
        combined.entries.forEach(function (e) { html += renderEntry(e, combined.env); });
        html += '</ol>';
        return html;
    }

    function getColspan($row) {
        // colspan matches the number of column headers so the panel spans the
        // full table width even when WP columns are reconfigured.
        var n = $row.closest('table.wp-list-table').find('thead th, thead td').length;
        return n > 0 ? n : 1;
    }

    function ensurePanel($row, postId) {
        var $next = $row.next('.firefly-sync-log-row');
        if ($next.length) return $next;
        var colspan = getColspan($row);
        var $panel = $('<tr class="firefly-sync-log-row" data-post-id="' + postId + '" hidden>'
                     +   '<td colspan="' + colspan + '">'
                     +     '<div class="firefly-sync-log-body"></div>'
                     +   '</td>'
                     + '</tr>');
        $row.after($panel);
        return $panel;
    }

    function loadInto($panel, postId) {
        var $body = $panel.find('.firefly-sync-log-body');
        var env   = getCurrentEnv();
        $body.html('<div class="firefly-sync-log-loading">' + ICONS.spinner + ' Loading activity from Local + ' + escapeHtml(envLabel(env)) + '…</div>');
        fetchCombined(postId, env).done(function (combined) {
            $body.html(renderBody(combined));
            $panel.data('loaded', true);
            $panel.data('env', env);
        }).fail(function () {
            $body.html('<div class="firefly-sync-log-error">Failed to load activity. Try again.</div>');
        });
    }

    function togglePanel($link) {
        var postId = parseInt($link.attr('data-post-id'), 10);
        if (!postId) return;
        var $row   = $link.closest('tr');
        var $panel = ensurePanel($row, postId);
        var willOpen = $panel.prop('hidden');

        $panel.prop('hidden', !willOpen);
        $link.attr('aria-expanded', willOpen ? 'true' : 'false');
        $link.toggleClass('is-open', willOpen);

        if (willOpen) loadInto($panel, postId); // always re-fetch so env-toggle changes are reflected
    }

    /**
     * Public: refresh the activity panel for a given post (if its panel is open).
     * Called from the existing sync flow so a new entry appears without reload.
     */
    SyncLog.refresh = function (postId) {
        var $panel = $('.firefly-sync-log-row[data-post-id="' + postId + '"]');
        if (!$panel.length || $panel.prop('hidden')) return;
        loadInto($panel, postId);
    };

    /** Per-entry action: restore local to a revision. */
    function doRestoreLocal($btn) {
        var $panel    = $btn.closest('.firefly-sync-log-row');
        var postId    = parseInt($panel.attr('data-post-id'), 10);
        var revId     = parseInt($btn.attr('data-revision-id'), 10);
        if (!postId || !revId) return;
        if (!confirm('Restore this page to revision #' + revId + '? Local content will be overwritten.')) return;

        var r = getRest();
        $btn.prop('disabled', true).addClass('is-working');
        $.ajax({
            url:     r.url + 'restore-local-revision',
            method:  'POST',
            data:    JSON.stringify({ post_id: postId, revision_id: revId }),
            contentType: 'application/json',
            headers: { 'X-WP-Nonce': r.nonce }
        }).done(function () {
            SyncLog.refresh(postId);
        }).fail(function (xhr) {
            var msg = 'Restore failed.';
            try { var j = JSON.parse(xhr.responseText); if (j.message) msg = j.message; } catch (e) {}
            alert(msg);
            $btn.prop('disabled', false).removeClass('is-working');
        });
    }

    /** Per-entry action: roll back a push on the remote. */
    function doRollbackPush($btn) {
        var $panel = $btn.closest('.firefly-sync-log-row');
        var postId = parseInt($panel.attr('data-post-id'), 10);
        var logId  = parseInt($btn.attr('data-log-id'), 10);
        if (!postId || !logId) return;
        if (!confirm('Roll back this push on the remote? The remote will be restored to its pre-push state.')) return;

        var r = getRest();
        $btn.prop('disabled', true).addClass('is-working');
        $.ajax({
            url:     r.url + 'rollback-push',
            method:  'POST',
            data:    JSON.stringify({ log_id: logId }),
            contentType: 'application/json',
            headers: { 'X-WP-Nonce': r.nonce }
        }).done(function () {
            SyncLog.refresh(postId);
        }).fail(function (xhr) {
            var msg = 'Rollback failed.';
            try { var j = JSON.parse(xhr.responseText); if (j.message) msg = j.message; } catch (e) {}
            alert(msg);
            $btn.prop('disabled', false).removeClass('is-working');
        });
    }

    /** Conflict strip's "Push your local now" — delegates to the existing per-row link. */
    function doPushFromConflict($btn) {
        var $panel = $btn.closest('.firefly-sync-log-row');
        var postId = parseInt($panel.attr('data-post-id'), 10);
        if (!postId) return;
        // Trigger a click on the per-row Sync to Remote link so the existing
        // confirmation modal handles the flow (don't duplicate that logic).
        var $row = $panel.prev('tr');
        $row.find('.firefly-sync-page-link').first().trigger('click');
    }

    /**
     * Drift indicator — lights an amber dot on the chevron when local has
     * been modified since its last sync to the currently-selected env.
     * Remote-ahead detection is a planned follow-up (needs a batched
     * /post-activity sweep on page load).
     */
    function updateDriftIndicators() {
        var env = getCurrentEnv();
        $('.firefly-sync-log-link').each(function () {
            var $link = $(this);
            var modified  = parseInt($link.attr('data-post-modified'), 10) || 0;
            var lastSync  = parseInt($link.attr(env === 'prod' ? 'data-last-sync-prod' : 'data-last-sync-dev'), 10) || 0;
            var localAhead = modified > 0 && (lastSync === 0 || modified > lastSync + 5); // 5s slack for clock skew
            $link.toggleClass('is-drift-local', localAhead);
        });
    }

    $(document).ready(function () {
        $(document).on('click', '.firefly-sync-log-link', function (e) {
            e.preventDefault();
            togglePanel($(this));
        });

        // Compute drift dots on initial render + whenever the env toggle flips.
        updateDriftIndicators();
        $(document).on('firefly:env-changed', updateDriftIndicators);

        // Per-entry action buttons (delegated, since rows are rendered dynamically).
        $(document).on('click', '.firefly-sync-log-restore, .firefly-sync-log-conflict .firefly-sync-log-action', function (e) {
            e.preventDefault();
            doRestoreLocal($(this));
        });
        $(document).on('click', '.firefly-sync-log-rollback', function (e) {
            e.preventDefault();
            doRollbackPush($(this));
        });
        $(document).on('click', '.firefly-sync-log-conflict-push', function (e) {
            e.preventDefault();
            doPushFromConflict($(this));
        });

        // Whenever the existing per-row "Sync to Remote" flow finishes,
        // refresh the matching activity panel so the new entry shows up.
        $(document).on('firefly:sync-page:done', function (e, data) {
            if (data && data.postId) SyncLog.refresh(data.postId);
        });

        // Env toggle changed in the toolbar — re-fetch any open panels so
        // the remote half matches the new viewing perspective.
        $(document).on('firefly:env-changed', function () {
            $('.firefly-sync-log-row:not([hidden])').each(function () {
                var postId = parseInt($(this).attr('data-post-id'), 10);
                if (postId) loadInto($(this), postId);
            });
        });
    });
})(jQuery);
