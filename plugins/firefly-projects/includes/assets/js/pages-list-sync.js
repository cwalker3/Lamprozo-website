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
        orphanCount: 0
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
    }

    // Initialize when document is ready
    $(document).ready(init);

})(jQuery);
