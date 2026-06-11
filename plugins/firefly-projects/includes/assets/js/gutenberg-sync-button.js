/**
 * Firefly Projects - Gutenberg Page Sync Button
 *
 * Adds a "Sync to Remote" panel in the Gutenberg editor sidebar
 * with environment toggle and confirmation modal following WordPress UI standards.
 * Also supports pulling pages from remote environments.
 */
(function(wp) {
    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editor;
    const { Button, Modal, Flex, FlexItem, Notice, Spinner, Icon, ToggleControl, TextControl } = wp.components;
    const { useState, useEffect } = wp.element;
    const { useSelect, useDispatch } = wp.data;
    const { __ } = wp.i18n;
    const el = wp.element.createElement;

    /**
     * Main Sync Panel Component
     */
    const PageSyncPanel = () => {
        const [isModalOpen, setIsModalOpen] = useState(false);
        const [isSyncing, setIsSyncing] = useState(false);
        const [syncResult, setSyncResult] = useState(null);
        const [detectedAssets, setDetectedAssets] = useState([]);
        const [isAnalyzing, setIsAnalyzing] = useState(false);

        // Environment toggle state - load from localStorage
        const [targetEnvProd, setTargetEnvProd] = useState(() => {
            return localStorage.getItem('firefly_page_sync_env') === 'prod';
        });

        // Get configuration from PHP
        const hasProdEndpoint = window.fireflyPageSync?.hasProdEndpoint || false;
        const remoteSite = window.fireflyPageSync?.remoteSite || '';
        const prodSite = window.fireflyPageSync?.prodSite || '';

        // Computed target environment
        const targetEnv = targetEnvProd ? 'prod' : 'dev';
        const targetSite = targetEnvProd ? prodSite : remoteSite;
        const envLabel = targetEnvProd ? __('Production', 'firefly-projects') : __('Live Dev', 'firefly-projects');

        // Get current post data from the editor
        const { postId, postTitle, postSlug, postType, postStatus, isModified, postContent, lastSyncDev, lastSyncProd } = useSelect((select) => {
            const editor = select('core/editor');
            const meta = editor.getEditedPostAttribute('meta') || {};
            return {
                postId: editor.getCurrentPostId(),
                postTitle: editor.getEditedPostAttribute('title'),
                postSlug: editor.getEditedPostAttribute('slug'),
                postType: editor.getCurrentPostType(),
                postStatus: editor.getEditedPostAttribute('status'),
                isModified: editor.isEditedPostDirty(),
                postContent: editor.getEditedPostAttribute('content'),
                lastSyncDev: meta._firefly_last_sync_dev || null,
                lastSyncProd: meta._firefly_last_sync_prod || null
            };
        });

        // Get the appropriate last sync time based on current target environment
        const lastSyncTime = targetEnvProd ? lastSyncProd : lastSyncDev;

        // Get dispatch for updating post meta
        const { editPost } = useDispatch('core/editor');

        // Save environment preference to localStorage when it changes.
        // Also broadcast a window event so the sibling "Recent syncs" panel
        // can re-fetch with the new env without waiting for the next save.
        useEffect(() => {
            const env = targetEnvProd ? 'prod' : 'dev';
            localStorage.setItem('firefly_page_sync_env', env);
            try {
                window.dispatchEvent(new CustomEvent('firefly:env-changed', { detail: { env: env } }));
            } catch (e) { /* CustomEvent unavailable in ancient browsers */ }
        }, [targetEnvProd]);

        // Format date for display (accepts Date object or timestamp)
        const formatDate = (dateInput) => {
            if (!dateInput) return '';
            const date = dateInput instanceof Date ? dateInput : new Date(dateInput);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });
        };

        // Only show for pages and posts
        if (!['page', 'post'].includes(postType)) {
            return null;
        }

        /**
         * Analyze content for assets when modal opens
         */
        const analyzeContent = () => {
            setIsAnalyzing(true);

            // Extract asset URLs from content
            const assets = [];
            const urlPatterns = [
                // Image sources
                /src=["']([^"']*\/wp-content\/uploads\/[^"']+)["']/gi,
                // Background images
                /url\(["']?([^"')]*\/wp-content\/uploads\/[^"')]+)["']?\)/gi,
                // href to files
                /href=["']([^"']*\/wp-content\/uploads\/[^"']+\.(pdf|doc|docx|xls|xlsx|zip|png|jpg|jpeg|gif|webp|svg))["']/gi
            ];

            urlPatterns.forEach(pattern => {
                let match;
                while ((match = pattern.exec(postContent)) !== null) {
                    const url = match[1];
                    if (!assets.includes(url)) {
                        assets.push(url);
                    }
                }
            });

            setDetectedAssets(assets);
            setIsAnalyzing(false);
        };

        /**
         * Open confirmation modal
         */
        const handleSyncClick = () => {
            setSyncResult(null);
            setIsModalOpen(true);
            analyzeContent();
        };

        /**
         * Close modal
         */
        const handleCloseModal = () => {
            if (!isSyncing) {
                setIsModalOpen(false);
                setSyncResult(null);
            }
        };

        /**
         * Perform the sync operation
         */
        const handleConfirmSync = async () => {
            setIsSyncing(true);
            setSyncResult(null);

            try {
                const response = await wp.apiFetch({
                    path: '/firefly-plugin/v1/sync-page',
                    method: 'POST',
                    data: {
                        post_id: postId,
                        include_assets: true,
                        target_env: targetEnv
                    }
                });

                if (response.success) {
                    // Update the post meta locally to refresh UI without page reload
                    const syncedAt = response.details?.synced_at || Math.floor(Date.now() / 1000);
                    const syncedEnv = response.details?.target_env || targetEnv;

                    // Update the correct meta field based on environment
                    const metaUpdate = syncedEnv === 'prod'
                        ? { _firefly_last_sync_prod: syncedAt }
                        : { _firefly_last_sync_dev: syncedAt };
                    editPost({ meta: metaUpdate });

                    setSyncResult({
                        type: 'success',
                        message: response.message || __('Page synced successfully!', 'firefly-projects'),
                        details: response.details || null
                    });
                } else {
                    setSyncResult({
                        type: 'error',
                        message: response.message || __('Sync failed. Please try again.', 'firefly-projects')
                    });
                }
            } catch (error) {
                setSyncResult({
                    type: 'error',
                    message: error.message || __('An error occurred during sync.', 'firefly-projects')
                });
            } finally {
                setIsSyncing(false);
            }
        };

        // Build the modal content
        let modalContent = null;
        if (isModalOpen) {
            const modalChildren = [];

            // Result message
            if (syncResult) {
                modalChildren.push(
                    el(Notice, {
                        key: 'result',
                        status: syncResult.type,
                        isDismissible: false,
                        className: 'firefly-sync-result'
                    },
                        syncResult.message,
                        syncResult.details && syncResult.details.files_synced ?
                            el('div', { className: 'firefly-sync-details' },
                                el('p', null, __('Files synced:', 'firefly-projects') + ' ' + syncResult.details.files_synced)
                            ) : null
                    )
                );
            } else {
                // Pre-sync info with environment indicator
                const warningClass = targetEnvProd ? 'firefly-sync-warning firefly-sync-warning-prod' : 'firefly-sync-warning';
                modalChildren.push(
                    el('div', { key: 'warning', className: warningClass },
                        el(Icon, { icon: 'warning' }),
                        el('span', null,
                            targetEnvProd
                                ? __('This will update the PRODUCTION site', 'firefly-projects')
                                : __('This will update the Live Dev site', 'firefly-projects')
                        )
                    )
                );

                // Sync summary table
                modalChildren.push(
                    el('div', { key: 'summary', className: 'firefly-sync-summary' },
                        el('h4', null, __('Sync Details', 'firefly-projects')),
                        el('table', { className: 'firefly-sync-table' },
                            el('tbody', null,
                                el('tr', null,
                                    el('th', null, __('Page', 'firefly-projects')),
                                    el('td', null, postTitle)
                                ),
                                el('tr', null,
                                    el('th', null, __('Slug', 'firefly-projects')),
                                    el('td', null, el('code', null, postSlug))
                                ),
                                el('tr', null,
                                    el('th', null, __('Target', 'firefly-projects')),
                                    el('td', null,
                                        el('span', { className: 'firefly-env-badge firefly-env-' + targetEnv }, envLabel),
                                        targetSite ? el('span', { className: 'firefly-target-site' }, ' (' + targetSite + ')') : null
                                    )
                                )
                            )
                        )
                    )
                );

                // Detected assets section
                let assetsContent;
                if (isAnalyzing) {
                    assetsContent = el('div', { className: 'firefly-analyzing' },
                        el(Spinner, null),
                        el('span', null, __('Analyzing content...', 'firefly-projects'))
                    );
                } else if (detectedAssets.length > 0) {
                    const assetItems = detectedAssets.slice(0, 5).map((asset, index) =>
                        el('li', { key: index },
                            el('code', null, asset.split('/').pop())
                        )
                    );
                    if (detectedAssets.length > 5) {
                        assetItems.push(
                            el('li', { key: 'more', className: 'firefly-more-assets' },
                                __('...and', 'firefly-projects') + ' ' + (detectedAssets.length - 5) + ' ' + __('more', 'firefly-projects')
                            )
                        );
                    }
                    assetsContent = [
                        el('p', { key: 'count', className: 'firefly-asset-count' },
                            detectedAssets.length + ' ' + __('asset(s) will be included', 'firefly-projects')
                        ),
                        el('ul', { key: 'list', className: 'firefly-asset-list' }, assetItems)
                    ];
                } else {
                    assetsContent = el('p', { className: 'firefly-no-assets' },
                        __('No media assets detected in content.', 'firefly-projects')
                    );
                }

                modalChildren.push(
                    el('div', { key: 'assets', className: 'firefly-sync-assets' },
                        el('h4', null, __('Detected Assets', 'firefly-projects')),
                        assetsContent
                    )
                );

                modalChildren.push(
                    el('p', { key: 'desc', className: 'firefly-sync-description' },
                        __('The page content and any detected assets will be synced to', 'firefly-projects') + ' ' + envLabel + '. ' +
                        __('This will overwrite the existing page content on the remote.', 'firefly-projects')
                    )
                );
            }

            // Modal footer buttons
            const footerButtons = [
                el(FlexItem, { key: 'cancel' },
                    el(Button, {
                        variant: 'secondary',
                        onClick: handleCloseModal,
                        disabled: isSyncing
                    }, syncResult ? __('Close', 'firefly-projects') : __('Cancel', 'firefly-projects'))
                )
            ];

            if (!syncResult) {
                const confirmButtonClass = targetEnvProd
                    ? 'firefly-confirm-sync-button firefly-confirm-sync-prod'
                    : 'firefly-confirm-sync-button';
                footerButtons.push(
                    el(FlexItem, { key: 'confirm' },
                        el(Button, {
                            variant: 'primary',
                            onClick: handleConfirmSync,
                            disabled: isSyncing,
                            isBusy: isSyncing,
                            className: confirmButtonClass
                        }, isSyncing
                            ? __('Syncing...', 'firefly-projects')
                            : __('Sync to', 'firefly-projects') + ' ' + envLabel
                        )
                    )
                );
            }

            modalContent = el(Modal, {
                title: __('Confirm Page Sync', 'firefly-projects'),
                onRequestClose: handleCloseModal,
                className: 'firefly-sync-modal',
                isDismissible: !isSyncing
            },
                el('div', { className: 'firefly-modal-content' }, modalChildren),
                el('div', { className: 'firefly-modal-footer' },
                    el(Flex, { justify: 'flex-end' }, footerButtons)
                )
            );
        }

        // Build panel content
        const panelContent = [];

        // Environment toggle (only show if PROD_ENDPOINT is configured)
        if (hasProdEndpoint) {
            panelContent.push(
                el('div', { key: 'env-toggle', className: 'firefly-env-toggle-container' },
                    el('div', { className: 'firefly-env-toggle-row' },
                        el('span', {
                            className: 'firefly-env-toggle-label' + (!targetEnvProd ? ' active' : ''),
                            onClick: () => !isSyncing && setTargetEnvProd(false)
                        }, __('Live Dev', 'firefly-projects')),
                        el('button', {
                            type: 'button',
                            role: 'switch',
                            'aria-checked': targetEnvProd,
                            className: 'firefly-env-toggle-switch' + (targetEnvProd ? ' is-prod' : ''),
                            onClick: () => !isSyncing && setTargetEnvProd(!targetEnvProd),
                            disabled: isSyncing
                        },
                            el('span', { className: 'firefly-env-toggle-knob' })
                        ),
                        el('span', {
                            className: 'firefly-env-toggle-label' + (targetEnvProd ? ' active' : ''),
                            onClick: () => !isSyncing && setTargetEnvProd(true)
                        }, __('Production', 'firefly-projects'))
                    )
                )
            );
        }

        // Status info - shows last sync time for the currently selected environment
        panelContent.push(
            el('div', { key: 'info', className: 'firefly-sync-info' },
                el('p', { className: 'firefly-sync-status' },
                    lastSyncTime ?
                        [
                            el('strong', { key: 'label' }, __('Last synced to', 'firefly-projects') + ' ' + envLabel + ': '),
                            el('span', { key: 'date', className: 'firefly-status-published' }, formatDate(new Date(lastSyncTime * 1000)))
                        ] :
                        [
                            el('strong', { key: 'label' }, envLabel + ': '),
                            el('span', { key: 'status', className: 'firefly-status-never' }, __('Never synced', 'firefly-projects'))
                        ]
                ),
                postStatus !== 'publish' ? el(Notice, {
                    status: 'warning',
                    isDismissible: false,
                    className: 'firefly-unsaved-notice'
                }, __('Page must be published before syncing.', 'firefly-projects')) :
                isModified ? el(Notice, {
                    status: 'warning',
                    isDismissible: false,
                    className: 'firefly-unsaved-notice'
                }, __('You have unsaved changes. Save before syncing.', 'firefly-projects')) : null
            )
        );

        // Sync button with environment-aware styling
        const syncButtonClass = targetEnvProd
            ? 'firefly-sync-button firefly-sync-button-prod'
            : 'firefly-sync-button';
        panelContent.push(
            el(Button, {
                key: 'button',
                variant: postStatus === 'publish' ? 'primary' : 'secondary',
                onClick: handleSyncClick,
                disabled: isModified || postStatus !== 'publish',
                className: syncButtonClass,
                icon: 'upload'
            }, __('Sync to', 'firefly-projects') + ' ' + envLabel)
        );

        // Note for non-published pages
        if (postStatus !== 'publish') {
            panelContent.push(
                el('p', { key: 'note', className: 'firefly-sync-note' },
                    __('Publish the page first to enable syncing.', 'firefly-projects')
                )
            );
        }

        // Return the panel
        return el(PluginDocumentSettingPanel, {
            name: 'firefly-page-sync',
            title: __('Remote Sync', 'firefly-projects'),
            className: 'firefly-page-sync-panel'
        },
            el('div', { className: 'firefly-sync-panel-content' }, panelContent),
            modalContent
        );
    };

    /**
     * Pull Panel Component - For pulling pages from remote environments
     * Only shows when the page is in a "fresh" state (empty content)
     * Opens a modal to browse and select pages from remote
     */
    const PagePullPanel = () => {
        // =====================================================================
        // ALL HOOKS MUST BE DECLARED AT THE TOP - BEFORE ANY EARLY RETURNS
        // React requires hooks to be called in the same order on every render
        // =====================================================================

        const [isPullModalOpen, setIsPullModalOpen] = useState(false);
        const [isPulling, setIsPulling] = useState(false);
        const [isLoadingPages, setIsLoadingPages] = useState(false);
        const [pullResult, setPullResult] = useState(null);
        const [remotePages, setRemotePages] = useState([]);
        const [selectedPage, setSelectedPage] = useState(null);
        const [searchTerm, setSearchTerm] = useState('');
        const [loadError, setLoadError] = useState(null);
        const [localPageExists, setLocalPageExists] = useState(null);
        const [showOverwriteConfirm, setShowOverwriteConfirm] = useState(false);

        // Environment toggle state for pull - load from localStorage
        const [pullEnvProd, setPullEnvProd] = useState(() => {
            return localStorage.getItem('firefly_page_pull_env') === 'prod';
        });

        // Track which environment pages were loaded for
        const [loadedForEnv, setLoadedForEnv] = useState(null);

        // Get configuration from PHP
        const hasProdEndpoint = window.fireflyPageSync?.hasProdEndpoint || false;
        const remoteSite = window.fireflyPageSync?.remoteSite || '';
        const prodSite = window.fireflyPageSync?.prodSite || '';

        // Computed source environment
        const sourceEnv = pullEnvProd ? 'prod' : 'dev';
        const sourceSite = pullEnvProd ? prodSite : remoteSite;
        const envLabel = pullEnvProd ? __('Production', 'firefly-projects') : __('Live Dev', 'firefly-projects');

        // Get current post data including content
        const { postId, postTitle, postSlug, postType, postContent, lastPullDev, lastPullProd } = useSelect((select) => {
            const editor = select('core/editor');
            const meta = editor.getEditedPostAttribute('meta') || {};
            return {
                postId: editor.getCurrentPostId(),
                postTitle: editor.getEditedPostAttribute('title'),
                postSlug: editor.getEditedPostAttribute('slug'),
                postType: editor.getCurrentPostType(),
                postContent: editor.getEditedPostAttribute('content'),
                lastPullDev: meta._firefly_last_pull_dev || null,
                lastPullProd: meta._firefly_last_pull_prod || null
            };
        });

        const lastPullTime = pullEnvProd ? lastPullProd : lastPullDev;

        // Post type label for UI
        const postTypeLabel = postType === 'post' ? __('Posts', 'firefly-projects') : __('Pages', 'firefly-projects');
        const postTypeSingular = postType === 'post' ? __('post', 'firefly-projects') : __('page', 'firefly-projects');

        // Check if page is in "fresh" state (no title AND no content)
        const isPageFresh = () => {
            const hasTitle = postTitle && postTitle.trim() !== '';
            if (hasTitle) return false;

            if (!postContent || postContent.trim() === '') return true;

            const strippedContent = postContent
                .replace(/<!-- wp:paragraph -->/g, '')
                .replace(/<!-- \/wp:paragraph -->/g, '')
                .replace(/<p><\/p>/g, '')
                .replace(/<p>\s*<\/p>/g, '')
                .replace(/\s+/g, '')
                .trim();
            return strippedContent === '';
        };

        const isFresh = isPageFresh();

        // Save environment preference
        useEffect(() => {
            localStorage.setItem('firefly_page_pull_env', pullEnvProd ? 'prod' : 'dev');
        }, [pullEnvProd]);

        // Reload pages when environment toggle changes in modal
        useEffect(() => {
            if (isPullModalOpen && loadedForEnv !== sourceEnv) {
                fetchRemotePages();
            }
        }, [sourceEnv, isPullModalOpen]);

        // Check for existing local page when selection changes
        useEffect(() => {
            if (selectedPage) {
                // Quick check via REST API
                wp.apiFetch({
                    path: '/wp/v2/pages?slug=' + selectedPage.slug + '&status=any',
                    method: 'GET'
                }).then(pages => {
                    setLocalPageExists(pages && pages.length > 0 ? pages[0] : null);
                }).catch(() => {
                    setLocalPageExists(null);
                });
            } else {
                setLocalPageExists(null);
            }
            setShowOverwriteConfirm(false);
        }, [selectedPage]);

        // =====================================================================
        // END OF HOOKS - Early returns and other logic can now safely follow
        // =====================================================================

        // Fetch remote pages when modal opens or environment changes
        const fetchRemotePages = async () => {
            setIsLoadingPages(true);
            setLoadError(null);
            setRemotePages([]);
            setSelectedPage(null);

            try {
                const response = await wp.apiFetch({
                    path: '/firefly-plugin/v1/fetch-remote-pages?source_env=' + sourceEnv + '&post_type=' + postType,
                    method: 'GET'
                });

                if (response.success) {
                    setRemotePages(response.pages || []);
                    setLoadedForEnv(sourceEnv);
                } else {
                    setLoadError(response.message || __('Failed to load pages.', 'firefly-projects'));
                }
            } catch (error) {
                setLoadError(error.message || __('Failed to connect to remote.', 'firefly-projects'));
            } finally {
                setIsLoadingPages(false);
            }
        };

        // Format date for display
        const formatDate = (dateInput) => {
            if (!dateInput) return '';
            const date = dateInput instanceof Date ? dateInput : new Date(dateInput);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        };

        // Filter pages by search term
        const filteredPages = remotePages.filter(page => {
            if (!searchTerm) return true;
            const term = searchTerm.toLowerCase();
            return page.title.toLowerCase().includes(term) ||
                   page.slug.toLowerCase().includes(term) ||
                   (page.excerpt && page.excerpt.toLowerCase().includes(term));
        });

        // Only show for pages and posts
        if (!['page', 'post'].includes(postType)) {
            return null;
        }

        // Hide panel if page has content (not fresh)
        if (!isFresh) {
            return null;
        }

        // Open pull modal and fetch pages
        const handlePullClick = () => {
            setPullResult(null);
            setSelectedPage(null);
            setSearchTerm('');
            setIsPullModalOpen(true);
            fetchRemotePages();
        };

        // Close modal
        const handleClosePullModal = () => {
            if (!isPulling) {
                setIsPullModalOpen(false);
                setPullResult(null);
                setSelectedPage(null);
                setSearchTerm('');
            }
        };

        // Perform pull with selected page
        const handleConfirmPull = async () => {
            if (!selectedPage) {
                setPullResult({
                    type: 'error',
                    message: __('Please select a page to pull.', 'firefly-projects')
                });
                return;
            }

            // If local page exists and user hasn't confirmed overwrite, show warning
            if (localPageExists && !showOverwriteConfirm) {
                setShowOverwriteConfirm(true);
                return;
            }

            setIsPulling(true);
            setPullResult(null);

            try {
                const response = await wp.apiFetch({
                    path: '/firefly-plugin/v1/pull-page',
                    method: 'POST',
                    data: {
                        firefly_page_id: selectedPage.firefly_page_id || '',
                        post_slug: selectedPage.slug,
                        template: selectedPage.firefly_page_id && selectedPage.firefly_page_id.indexOf(':') !== -1
                            ? selectedPage.firefly_page_id.split(':')[0] : '',
                        source_env: sourceEnv
                    }
                });

                if (response.success) {
                    setPullResult({
                        type: 'success',
                        message: response.message || __('Page pulled successfully!', 'firefly-projects'),
                        details: response.details || null
                    });

                    // Redirect to the pulled page
                    if (response.post_id) {
                        setTimeout(() => {
                            // Disable the "unsaved changes" warning before redirect
                            window.onbeforeunload = null;
                            // Also clear Gutenberg's lock
                            if (wp.data && wp.data.dispatch('core/editor')) {
                                wp.data.dispatch('core/editor').lockPostSaving('redirect');
                            }
                            window.location.href = '/wp-admin/post.php?post=' + response.post_id + '&action=edit';
                        }, 1500);
                    }
                } else {
                    setPullResult({
                        type: 'error',
                        message: response.message || __('Pull failed. Please try again.', 'firefly-projects')
                    });
                }
            } catch (error) {
                setPullResult({
                    type: 'error',
                    message: error.message || __('An error occurred during pull.', 'firefly-projects')
                });
            } finally {
                setIsPulling(false);
            }
        };

        // Build modal content
        let pullModalContent = null;
        if (isPullModalOpen) {
            const modalChildren = [];

            if (pullResult) {
                // Show result
                modalChildren.push(
                    el(Notice, {
                        key: 'result',
                        status: pullResult.type,
                        isDismissible: false,
                        className: 'firefly-sync-result'
                    },
                        pullResult.message,
                        pullResult.details && pullResult.details.assets_pulled ?
                            el('div', { className: 'firefly-sync-details' },
                                el('p', null, __('Assets pulled:', 'firefly-projects') + ' ' + pullResult.details.assets_pulled)
                            ) : null,
                        pullResult.type === 'success' ?
                            el('p', { className: 'firefly-reload-notice' },
                                __('Redirecting to pulled page...', 'firefly-projects')
                            ) : null
                    )
                );
            } else {
                // Environment toggle in modal
                if (hasProdEndpoint) {
                    modalChildren.push(
                        el('div', { key: 'env-toggle', className: 'firefly-env-toggle-container' },
                            el('div', { className: 'firefly-env-toggle-row' },
                                el('span', {
                                    className: 'firefly-env-toggle-label' + (!pullEnvProd ? ' active' : ''),
                                    onClick: () => !isPulling && !isLoadingPages && setPullEnvProd(false)
                                }, __('Live Dev', 'firefly-projects')),
                                el('button', {
                                    type: 'button',
                                    role: 'switch',
                                    'aria-checked': pullEnvProd,
                                    className: 'firefly-env-toggle-switch' + (pullEnvProd ? ' is-prod' : ''),
                                    onClick: () => !isPulling && !isLoadingPages && setPullEnvProd(!pullEnvProd),
                                    disabled: isPulling || isLoadingPages
                                },
                                    el('span', { className: 'firefly-env-toggle-knob' })
                                ),
                                el('span', {
                                    className: 'firefly-env-toggle-label' + (pullEnvProd ? ' active' : ''),
                                    onClick: () => !isPulling && !isLoadingPages && setPullEnvProd(true)
                                }, __('Production', 'firefly-projects'))
                            )
                        )
                    );
                }

                // Source info
                modalChildren.push(
                    el('div', { key: 'source-info', className: 'firefly-pull-source-info' },
                        el('span', { className: 'firefly-env-badge firefly-env-' + sourceEnv }, envLabel),
                        sourceSite ? el('span', { className: 'firefly-target-site' }, ' ' + sourceSite) : null
                    )
                );

                // Loading state
                if (isLoadingPages) {
                    modalChildren.push(
                        el('div', { key: 'loading', className: 'firefly-pull-loading' },
                            el(Spinner, null),
                            el('span', null, __('Loading', 'firefly-projects') + ' ' + postTypeLabel.toLowerCase() + ' ' + __('from', 'firefly-projects') + ' ' + envLabel + '...')
                        )
                    );
                } else if (loadError) {
                    // Error state
                    modalChildren.push(
                        el(Notice, {
                            key: 'error',
                            status: 'error',
                            isDismissible: false
                        }, loadError)
                    );
                } else {
                    // Search input
                    modalChildren.push(
                        el('div', { key: 'search', className: 'firefly-pull-search' },
                            el(TextControl, {
                                value: searchTerm,
                                onChange: setSearchTerm,
                                placeholder: __('Search', 'firefly-projects') + ' ' + postTypeLabel.toLowerCase() + '...',
                                className: 'firefly-pull-search-input'
                            })
                        )
                    );

                    // Page list
                    if (filteredPages.length === 0) {
                        modalChildren.push(
                            el('div', { key: 'no-pages', className: 'firefly-pull-no-pages' },
                                searchTerm
                                    ? __('No', 'firefly-projects') + ' ' + postTypeLabel.toLowerCase() + ' ' + __('match your search.', 'firefly-projects')
                                    : __('No', 'firefly-projects') + ' ' + postTypeLabel.toLowerCase() + ' ' + __('available on remote.', 'firefly-projects')
                            )
                        );
                    } else {
                        const pageItems = filteredPages.map(page =>
                            el('div', {
                                key: page.id,
                                className: 'firefly-pull-page-item' + (selectedPage && selectedPage.id === page.id ? ' selected' : ''),
                                onClick: () => setSelectedPage(page)
                            },
                                el('div', { className: 'firefly-pull-page-header' },
                                    el('span', { className: 'firefly-pull-page-title' }, page.title || __('(No title)', 'firefly-projects')),
                                    el('span', { className: 'firefly-pull-page-status firefly-pull-page-status-' + page.status },
                                        page.status === 'publish' ? __('Published', 'firefly-projects') : __('Draft', 'firefly-projects')
                                    )
                                ),
                                el('div', { className: 'firefly-pull-page-meta' },
                                    el('code', null, '/' + page.slug),
                                    page.modified ? el('span', { className: 'firefly-pull-page-date' },
                                        __('Modified:', 'firefly-projects') + ' ' + formatDate(page.modified)
                                    ) : null
                                ),
                                page.excerpt ? el('div', { className: 'firefly-pull-page-excerpt' }, page.excerpt) : null
                            )
                        );

                        modalChildren.push(
                            el('div', { key: 'page-list', className: 'firefly-pull-page-list' }, pageItems)
                        );
                    }

                    // Overwrite warning if local page exists
                    if (showOverwriteConfirm && localPageExists) {
                        modalChildren.push(
                            el(Notice, {
                                key: 'overwrite-warning',
                                status: 'warning',
                                isDismissible: false,
                                className: 'firefly-overwrite-warning'
                            },
                                el('strong', null, __('Local page exists!', 'firefly-projects')),
                                el('p', null,
                                    __('A page with slug', 'firefly-projects') + ' "' + selectedPage.slug + '" ' +
                                    __('already exists locally. Pulling will overwrite its content.', 'firefly-projects')
                                )
                            )
                        );
                    } else {
                        // Description
                        modalChildren.push(
                            el('p', { key: 'desc', className: 'firefly-sync-description' },
                                __('Select a', 'firefly-projects') + ' ' + postTypeSingular + ' ' + __('to pull. Content and assets will be copied to your local environment.', 'firefly-projects')
                            )
                        );
                    }
                }
            }

            // Modal footer
            const footerButtons = [
                el(FlexItem, { key: 'cancel' },
                    el(Button, {
                        variant: 'secondary',
                        onClick: handleClosePullModal,
                        disabled: isPulling
                    }, pullResult ? __('Close', 'firefly-projects') : __('Cancel', 'firefly-projects'))
                )
            ];

            if (!pullResult && !isLoadingPages && !loadError) {
                const overwriteLabel = postType === 'post' ? __('Overwrite Local Post', 'firefly-projects') : __('Overwrite Local Page', 'firefly-projects');
                const selectLabel = postType === 'post' ? __('Select a post', 'firefly-projects') : __('Select a page', 'firefly-projects');
                const buttonLabel = isPulling
                    ? __('Pulling...', 'firefly-projects')
                    : showOverwriteConfirm
                        ? overwriteLabel
                        : selectedPage
                            ? __('Pull', 'firefly-projects') + ' "' + selectedPage.title + '"'
                            : selectLabel;

                footerButtons.push(
                    el(FlexItem, { key: 'confirm' },
                        el(Button, {
                            variant: showOverwriteConfirm ? 'primary' : 'primary',
                            onClick: handleConfirmPull,
                            disabled: isPulling || !selectedPage,
                            isBusy: isPulling,
                            className: showOverwriteConfirm ? 'firefly-pull-button firefly-overwrite-button' : 'firefly-pull-button'
                        }, buttonLabel)
                    )
                );
            }

            const modalTitle = postType === 'post' ? __('Pull Post from Remote', 'firefly-projects') : __('Pull Page from Remote', 'firefly-projects');
            pullModalContent = el(Modal, {
                title: modalTitle,
                onRequestClose: handleClosePullModal,
                className: 'firefly-sync-modal firefly-pull-modal',
                isDismissible: !isPulling
            },
                el('div', { className: 'firefly-modal-content' }, modalChildren),
                el('div', { className: 'firefly-modal-footer' },
                    el(Flex, { justify: 'flex-end' }, footerButtons)
                )
            );
        }

        // Build panel content
        const panelContent = [];

        // Info text
        const freshLabel = postType === 'post' ? __('This is a fresh post. You can pull content from a remote environment.', 'firefly-projects') : __('This is a fresh page. You can pull content from a remote environment.', 'firefly-projects');
        panelContent.push(
            el('div', { key: 'info', className: 'firefly-sync-info' },
                el('p', { className: 'firefly-sync-status' }, freshLabel)
            )
        );

        // Pull button
        const browseLabel = postType === 'post' ? __('Browse Remote Posts', 'firefly-projects') : __('Browse Remote Pages', 'firefly-projects');
        panelContent.push(
            el(Button, {
                key: 'button',
                variant: 'primary',
                onClick: handlePullClick,
                className: 'firefly-pull-button',
                icon: 'download'
            }, browseLabel)
        );

        // Note about pull
        const noteLabel = postType === 'post' ? __('Opens a list of available posts to pull from the remote site.', 'firefly-projects') : __('Opens a list of available pages to pull from the remote site.', 'firefly-projects');
        panelContent.push(
            el('p', { key: 'note', className: 'firefly-sync-note' }, noteLabel)
        );

        return el(PluginDocumentSettingPanel, {
            name: 'firefly-page-pull',
            title: __('Pull from Remote', 'firefly-projects'),
            className: 'firefly-page-pull-panel'
        },
            el('div', { className: 'firefly-sync-panel-content' }, panelContent),
            pullModalContent
        );
    };

    // Register the sync plugin
    registerPlugin('firefly-page-sync', {
        render: PageSyncPanel,
        icon: 'cloud-upload'
    });

    // Register the pull plugin (only on Local Dev)
    if (window.fireflyPageSync?.isLocalDev) {
        registerPlugin('firefly-page-pull', {
            render: PagePullPanel,
            icon: 'download'
        });
    }

    /* =========================================================================
     * Recent syncs — DUAL-SOURCE activity feed for the open post.
     *
     * Mirrors the pages-list chevron panel:
     *   - Fetches local /sync-log AND /remote-activity?env=<current> in parallel
     *   - Merges + sorts entries; tags each with origin (Local / remote-env)
     *   - Per-entry Restore (local revision) and Roll back (remote push) buttons
     *   - Listens for the window 'firefly:env-changed' event so flipping the
     *     env toggle in the sister panel above re-fetches without a save
     * ======================================================================== */
    const SyncLogPanel = () => {
        const [entries, setEntries] = useState([]);
        const [warning, setWarning] = useState(null);
        const [loading, setLoading] = useState(false);
        const [error, setError]     = useState(null);
        const [acting, setActing]   = useState(false);
        const [env, setEnv] = useState(() => localStorage.getItem('firefly_page_sync_env') === 'prod' ? 'prod' : 'dev');

        const { postId, isSaving } = useSelect((select) => {
            const editor = select('core/editor');
            return {
                postId:   editor.getCurrentPostId(),
                isSaving: editor.isSavingPost() && !editor.isAutosavingPost(),
            };
        });

        const restUrl = (window.fireflyPageSync && window.fireflyPageSync.restUrl) || '';
        const nonce   = (window.fireflyPageSync && window.fireflyPageSync.nonce)   || '';

        const fetchCombined = () => {
            if (!postId || !restUrl) return;
            setLoading(true);
            setError(null);
            setWarning(null);
            const headers = { 'X-WP-Nonce': nonce };
            Promise.all([
                fetch(restUrl + 'sync-log?post_id=' + postId + '&limit=10',  { headers, credentials: 'same-origin' }).then(r => r.json()).catch(() => ({ entries: [] })),
                fetch(restUrl + 'remote-activity?post_id=' + postId + '&env=' + env + '&limit=10', { headers, credentials: 'same-origin' }).then(r => r.json()).catch(() => ({ sync_log: [], revisions: [] })),
            ]).then(([local, remote]) => {
                const localEntries = (local && local.entries) ? local.entries.map(e => Object.assign({}, e, { origin: 'local', sort_ts: e.created_at_iso })) : [];
                const remoteSync   = (remote && remote.sync_log)  ? remote.sync_log.map(e => Object.assign({}, e, { origin: 'remote', kind: 'sync',     sort_ts: e.created_at_iso })) : [];
                const remoteRev    = (remote && remote.revisions) ? remote.revisions.map(e => Object.assign({}, e, { origin: 'remote', kind: 'revision', sort_ts: e.created_at_iso })) : [];
                const merged = localEntries.concat(remoteSync, remoteRev);
                merged.sort((a, b) => (Date.parse(b.sort_ts || 0) - Date.parse(a.sort_ts || 0)));
                setEntries(merged.slice(0, 8)); // sidebar is space-constrained
                if (remote && remote.warning) setWarning(remote.warning);
            }).catch((e) => {
                setError(e.message || 'Failed to load activity.');
            }).finally(() => setLoading(false));
        };

        // Initial load + on env change + after each post save.
        useEffect(fetchCombined, [postId, env]);
        useEffect(() => {
            if (!isSaving) {
                const t = setTimeout(fetchCombined, 600);
                return () => clearTimeout(t);
            }
        }, [isSaving]);

        // Listen for the env toggle in the sister panel.
        useEffect(() => {
            const onEnvChange = (e) => {
                const next = (e && e.detail && e.detail.env) || (localStorage.getItem('firefly_page_sync_env') === 'prod' ? 'prod' : 'dev');
                setEnv(next);
            };
            window.addEventListener('firefly:env-changed', onEnvChange);
            return () => window.removeEventListener('firefly:env-changed', onEnvChange);
        }, []);

        const envLabel = (e) => e === 'prod' ? 'Production' : 'Live Dev';
        const dirArrow = (entry) => {
            if (entry.kind === 'revision') return '✎';
            if (entry.direction === 'restore' || entry.direction === 'rollback' || entry.direction === 'rollback_applied') return '⟲';
            return entry.direction === 'pull' ? '↓' : '↑';
        };

        const doRestore = (entry) => {
            if (acting || !entry.revision_id) return;
            if (!window.confirm('Restore this page to revision #' + entry.revision_id + '? Local content will be overwritten.')) return;
            setActing(true);
            fetch(restUrl + 'restore-local-revision', {
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ post_id: postId, revision_id: entry.revision_id })
            }).then(r => r.ok ? r.json() : r.json().then(j => { throw new Error(j.message || 'HTTP ' + r.status); }))
                .then(() => { fetchCombined(); })
                .catch((e) => { window.alert(e.message || 'Restore failed.'); })
                .finally(() => setActing(false));
        };

        const doRollback = (entry) => {
            if (acting) return;
            if (!window.confirm('Roll back this push on ' + envLabel(entry.env) + '? The remote will be restored to its pre-push state.')) return;
            setActing(true);
            fetch(restUrl + 'rollback-push', {
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ log_id: entry.id })
            }).then(r => r.ok ? r.json() : r.json().then(j => { throw new Error(j.message || 'HTTP ' + r.status); }))
                .then(() => { fetchCombined(); })
                .catch((e) => { window.alert(e.message || 'Rollback failed.'); })
                .finally(() => setActing(false));
        };

        let body;
        if (loading && entries.length === 0) {
            body = el('div', { className: 'firefly-sync-log-loading' }, el(Spinner), ' Loading…');
        } else if (error) {
            body = el(Notice, { status: 'error', isDismissible: false }, error);
        } else if (entries.length === 0) {
            body = el('p', { className: 'firefly-sync-log-empty' }, __('No sync activity yet.', 'firefly-projects'));
        } else {
            const items = entries.map((entry) => {
                const origin = entry.origin === 'local' ? 'Local' : envLabel(env);
                const canRestore  = entry.origin === 'local' && entry.revision_id;
                const canRollback = entry.origin === 'local' && entry.direction === 'push' && entry.status === 'success'
                                    && entry.summary && entry.summary.pre_push_revision_id;
                return el('li', {
                    key: String(entry.id),
                    className: 'firefly-sync-log-mini-entry is-origin-' + (entry.origin || 'local')
                                + (entry.status === 'failure' ? ' is-failure' : ' is-success')
                                + (entry.kind === 'revision' ? ' is-kind-revision' : ' is-kind-sync')
                },
                    el('span', { className: 'firefly-sync-log-dot' }),
                    el('span', { className: 'firefly-sync-log-mini-origin' }, origin),
                    el('span', { className: 'firefly-sync-log-mini-dir' }, dirArrow(entry) + ' ' + (entry.env ? envLabel(entry.env) : '')),
                    el('span', { className: 'firefly-sync-log-mini-user' }, entry.user || 'System'),
                    entry.revision_url ? el('a', { className: 'firefly-sync-log-mini-rev', href: entry.revision_url, target: '_blank' }, __('View', 'firefly-projects')) : null,
                    canRestore  ? el('button', { className: 'firefly-sync-log-mini-action', disabled: acting, onClick: () => doRestore(entry)  }, __('Restore', 'firefly-projects')) : null,
                    canRollback ? el('button', { className: 'firefly-sync-log-mini-action is-danger', disabled: acting, onClick: () => doRollback(entry) }, __('Roll back', 'firefly-projects')) : null,
                    el('span', { className: 'firefly-sync-log-mini-time', title: entry.created_at_iso || '' }, entry.created_at_human || '')
                );
            });
            body = el('ul', { className: 'firefly-sync-log-mini' }, items);
        }

        const warningEl = warning
            ? el(Notice, { status: 'warning', isDismissible: false, className: 'firefly-sync-log-mini-warning' }, warning)
            : null;

        return el(PluginDocumentSettingPanel, {
            name: 'firefly-sync-log',
            title: __('Recent syncs (', 'firefly-projects') + envLabel(env) + ')',
            className: 'firefly-sync-log-panel'
        }, warningEl, body);
    };

    registerPlugin('firefly-sync-log', {
        render: SyncLogPanel,
        icon: 'clock'
    });

})(window.wp);
