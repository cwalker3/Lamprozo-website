/**
 * Firefly Projects - Menu Sync Meta Box
 *
 * jQuery-based UI for syncing WordPress menus to Live Dev or Production
 * environments from the Appearance → Menus admin screen.
 */
(function($) {
    'use strict';

    // Get configuration from PHP
    var config = window.fireflyMenuSync || {};

    // State
    var state = {
        targetEnvProd: localStorage.getItem('firefly_menu_sync_env') === 'prod',
        isSyncing: false,
        lastSyncDev: config.lastSyncDev || 0,
        lastSyncProd: config.lastSyncProd || 0
    };

    /**
     * Format timestamp for display
     */
    function formatDate(timestamp) {
        if (!timestamp || timestamp === 0) {
            return null;
        }
        var date = new Date(timestamp * 1000);
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    /**
     * Get current menu ID from the form
     */
    function getMenuId() {
        return parseInt($('#menu').val(), 10) || 0;
    }

    /**
     * Get environment label
     */
    function getEnvLabel() {
        return state.targetEnvProd ? 'Production' : 'Live Dev';
    }

    /**
     * Get current last sync time based on environment
     */
    function getLastSyncTime() {
        return state.targetEnvProd ? state.lastSyncProd : state.lastSyncDev;
    }

    /**
     * Update the UI to reflect current state
     */
    function updateUI() {
        var $container = $('#firefly-menu-sync-content');
        if (!$container.length) return;

        var envLabel = getEnvLabel();
        var lastSync = getLastSyncTime();
        var formattedDate = formatDate(lastSync);

        // Update toggle state
        var $switch = $container.find('.firefly-env-toggle-switch');
        var $devLabel = $container.find('.firefly-env-toggle-label').first();
        var $prodLabel = $container.find('.firefly-env-toggle-label').last();

        if (state.targetEnvProd) {
            $switch.addClass('is-prod');
            $devLabel.removeClass('active');
            $prodLabel.addClass('active');
        } else {
            $switch.removeClass('is-prod');
            $devLabel.addClass('active');
            $prodLabel.removeClass('active');
        }

        // Update status text
        var $status = $container.find('.firefly-sync-status');
        if (formattedDate) {
            $status.html(
                '<strong>Last synced to ' + envLabel + ':</strong> ' +
                '<span class="firefly-status-published">' + formattedDate + '</span>'
            );
        } else {
            $status.html(
                '<strong>' + envLabel + ':</strong> ' +
                '<span class="firefly-status-never">Never synced</span>'
            );
        }

        // Update button
        var $button = $container.find('.firefly-sync-button');
        $button.text('Sync to ' + envLabel);

        if (state.targetEnvProd) {
            $button.addClass('firefly-sync-button-prod');
        } else {
            $button.removeClass('firefly-sync-button-prod');
        }
    }

    /**
     * Fetch latest sync timestamps from server
     */
    function fetchSyncStatus() {
        var menuId = getMenuId();
        if (!menuId) return;

        $.ajax({
            url: config.restUrl + 'menu-sync-status',
            method: 'GET',
            data: { menu_id: menuId },
            headers: {
                'X-WP-Nonce': config.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    state.lastSyncDev = response.data.last_sync_dev || 0;
                    state.lastSyncProd = response.data.last_sync_prod || 0;
                    updateUI();
                }
            }
        });
    }

    /**
     * Handle environment toggle
     */
    function toggleEnvironment() {
        if (state.isSyncing) return;

        state.targetEnvProd = !state.targetEnvProd;
        localStorage.setItem('firefly_menu_sync_env', state.targetEnvProd ? 'prod' : 'dev');
        updateUI();
    }

    /**
     * Show confirmation modal
     */
    function showConfirmModal() {
        var menuId = getMenuId();
        if (!menuId) {
            alert('Please select a menu first.');
            return;
        }

        var envLabel = getEnvLabel();
        var menuName = $('#menu-to-edit').closest('form').find('#menu-name').val() || 'this menu';
        var itemCount = $('#menu-to-edit > li').length;

        var warningClass = state.targetEnvProd ? 'firefly-sync-warning firefly-sync-warning-prod' : 'firefly-sync-warning';
        var warningText = state.targetEnvProd
            ? 'This will update the PRODUCTION site'
            : 'This will update the Live Dev site';

        var modalHtml = '<div id="firefly-menu-sync-modal" class="firefly-sync-modal-overlay">' +
            '<div class="firefly-sync-modal">' +
                '<div class="firefly-sync-modal-header">' +
                    '<h2>Confirm Menu Sync</h2>' +
                    '<button type="button" class="firefly-modal-close">&times;</button>' +
                '</div>' +
                '<div class="firefly-modal-content">' +
                    '<div class="' + warningClass + '">' +
                        '<span class="dashicons dashicons-warning"></span>' +
                        '<span>' + warningText + '</span>' +
                    '</div>' +
                    '<div class="firefly-sync-summary">' +
                        '<h4>Sync Details</h4>' +
                        '<table class="firefly-sync-table">' +
                            '<tr><th>Menu</th><td>' + menuName + '</td></tr>' +
                            '<tr><th>Items</th><td>' + itemCount + ' menu item(s)</td></tr>' +
                            '<tr><th>Target</th><td><span class="firefly-env-badge firefly-env-' + (state.targetEnvProd ? 'prod' : 'dev') + '">' + envLabel + '</span></td></tr>' +
                        '</table>' +
                    '</div>' +
                    '<p class="firefly-sync-description">' +
                        'The menu structure will be synced to ' + envLabel + '. ' +
                        'This will replace the existing menu on the remote site.' +
                    '</p>' +
                '</div>' +
                '<div class="firefly-modal-footer">' +
                    '<button type="button" class="button firefly-modal-cancel">Cancel</button>' +
                    '<button type="button" class="button button-primary firefly-modal-confirm' + (state.targetEnvProd ? ' firefly-confirm-sync-prod' : '') + '">Sync to ' + envLabel + '</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        $('body').append(modalHtml);

        // Bind modal events
        $('#firefly-menu-sync-modal').on('click', '.firefly-modal-close, .firefly-modal-cancel', closeModal);
        $('#firefly-menu-sync-modal').on('click', '.firefly-modal-confirm', performSync);
        $('#firefly-menu-sync-modal').on('click', function(e) {
            if ($(e.target).is('.firefly-sync-modal-overlay')) {
                closeModal();
            }
        });
    }

    /**
     * Close the modal
     */
    function closeModal() {
        $('#firefly-menu-sync-modal').remove();
    }

    /**
     * Show result in modal
     */
    function showResult(success, message, details) {
        var $modal = $('#firefly-menu-sync-modal');
        var $content = $modal.find('.firefly-modal-content');
        var $footer = $modal.find('.firefly-modal-footer');

        var statusClass = success ? 'notice-success' : 'notice-error';
        var resultHtml = '<div class="notice ' + statusClass + ' firefly-sync-result">' +
            '<p>' + message + '</p>';

        if (success && details && details.items_count !== undefined) {
            resultHtml += '<p class="firefly-sync-details">' +
                'Menu items synced: ' + details.items_count +
            '</p>';
        }

        resultHtml += '</div>';

        $content.html(resultHtml);
        $footer.html('<button type="button" class="button firefly-modal-cancel">Close</button>');
    }

    /**
     * Perform the sync
     */
    function performSync() {
        if (state.isSyncing) return;

        var menuId = getMenuId();
        if (!menuId) {
            showResult(false, 'No menu selected.');
            return;
        }

        state.isSyncing = true;

        var $modal = $('#firefly-menu-sync-modal');
        var $confirmBtn = $modal.find('.firefly-modal-confirm');
        var $cancelBtn = $modal.find('.firefly-modal-cancel');

        $confirmBtn.prop('disabled', true).text('Syncing...');
        $cancelBtn.prop('disabled', true);

        $.ajax({
            url: config.restUrl + 'sync-menu',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                menu_id: menuId,
                target_env: state.targetEnvProd ? 'prod' : 'dev'
            }),
            headers: {
                'X-WP-Nonce': config.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update local state with new timestamp
                    if (response.details && response.details.synced_at) {
                        if (state.targetEnvProd) {
                            state.lastSyncProd = response.details.synced_at;
                        } else {
                            state.lastSyncDev = response.details.synced_at;
                        }
                    }
                    showResult(true, response.message, response.details);
                    updateUI();
                } else {
                    showResult(false, response.message || 'Sync failed.');
                }
            },
            error: function(xhr) {
                var message = 'Sync failed.';
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.message) {
                        message = response.message;
                    }
                } catch (e) {}
                showResult(false, message);
            },
            complete: function() {
                state.isSyncing = false;
            }
        });
    }

    /**
     * Build the meta box content
     */
    function buildMetaBoxContent() {
        var html = '<div id="firefly-menu-sync-content" class="firefly-menu-sync-panel">';

        // Environment toggle (only if PROD_ENDPOINT is configured)
        if (config.hasProdEndpoint) {
            html += '<div class="firefly-env-toggle-container">' +
                '<div class="firefly-env-toggle-row">' +
                    '<span class="firefly-env-toggle-label active">Live Dev</span>' +
                    '<button type="button" role="switch" aria-checked="false" class="firefly-env-toggle-switch">' +
                        '<span class="firefly-env-toggle-knob"></span>' +
                    '</button>' +
                    '<span class="firefly-env-toggle-label">Production</span>' +
                '</div>' +
            '</div>';
        }

        // Status info
        html += '<div class="firefly-sync-info">' +
            '<p class="firefly-sync-status">' +
                '<strong>Live Dev:</strong> <span class="firefly-status-never">Never synced</span>' +
            '</p>' +
        '</div>';

        // Sync button
        html += '<button type="button" class="button button-primary firefly-sync-button">Sync to Live Dev</button>';

        html += '</div>';

        return html;
    }

    /**
     * Initialize the meta box
     */
    function init() {
        // Check if we're on the nav-menus page and have the container
        var $metaBox = $('#firefly-menu-sync');
        if (!$metaBox.length) return;

        // Build and insert content
        var $inside = $metaBox.find('.inside');
        $inside.html(buildMetaBoxContent());

        // Bind events
        $inside.on('click', '.firefly-env-toggle-switch, .firefly-env-toggle-label', function(e) {
            e.preventDefault();
            if ($(this).hasClass('firefly-env-toggle-label')) {
                // Clicking label - only toggle if clicking opposite label
                var isProdLabel = $(this).is(':last-child');
                if (isProdLabel !== state.targetEnvProd) {
                    toggleEnvironment();
                }
            } else {
                toggleEnvironment();
            }
        });

        $inside.on('click', '.firefly-sync-button', function(e) {
            e.preventDefault();
            showConfirmModal();
        });

        // Initial UI update
        updateUI();

        // Fetch latest timestamps
        fetchSyncStatus();

        // Re-fetch when menu changes
        $('#menu').on('change', function() {
            fetchSyncStatus();
        });
    }

    // Initialize when document is ready
    $(document).ready(init);

})(jQuery);
