// theme/assets/js/customize.js

(function() {
    'use strict';

    var refreshTimeout = null;

    // Force-reload the Customizer preview iframe.
    // WordPress's refresh() relies on a 'ready' handshake from customize-preview.js
    // inside the iframe. If that handshake fails, the old iframe stays visible.
    // This bypasses that by directly reloading the iframe src.
    function refreshPreview() {
        if (refreshTimeout) {
            clearTimeout(refreshTimeout);
        }
        refreshTimeout = setTimeout(function() {
            var iframe = document.querySelector('#customize-preview iframe');
            if (iframe) {
                var previewUrl = wp.customize.settings.url.preview;
                var params = new URLSearchParams(iframe.src.split('?')[1] || '');
                var channel = params.get('customize_messenger_channel');
                var uuid = params.get('customize_changeset_uuid');

                var newUrl = previewUrl
                    + (previewUrl.indexOf('?') === -1 ? '?' : '&')
                    + 'customize_messenger_channel=' + encodeURIComponent(channel || '')
                    + '&customize_changeset_uuid=' + encodeURIComponent(uuid || '')
                    + '&_=' + Date.now();

                console.log('[FF] Reloading iframe:', newUrl);
                iframe.src = newUrl;
            } else {
                console.log('[FF] No iframe found, using wp.customize.previewer.refresh()');
                wp.customize.previewer.refresh();
            }
        }, 200);
    }

    // -----------------------------------------------------------------------
    //  Publish button management
    // -----------------------------------------------------------------------

    var templateOptionsChanged = false;
    var originalOptionValues = {};

    function checkTemplateOptionsChanged() {
        var data = window.fireflyTemplateOptions;
        if (!data) return false;

        var currentTemplate = wp.customize('firefly_collective_active_template').get();
        var tmplData = data.templates[currentTemplate];
        if (!tmplData) return false;

        var hasChanges = false;
        tmplData.options.forEach(function(key) {
            var settingId = 'template_' + key;
            var setting = wp.customize(settingId);
            if (setting && originalOptionValues[key] !== undefined) {
                if (setting.get() !== originalOptionValues[key]) {
                    hasChanges = true;
                }
            }
        });

        templateOptionsChanged = hasChanges;
        updatePublishButtonState();
        return hasChanges;
    }

    function updatePublishButtonState() {
        var publishButton = document.querySelector('#save');
        var gearButton = document.querySelector('#publish-settings');
        var landingSection = wp.customize.section('firefly_collective_landing');
        var isInLandingSection = landingSection && landingSection.expanded.get();

        var shouldEnable = templateOptionsChanged || !isInLandingSection;

        if (publishButton) {
            if (shouldEnable) {
                publishButton.disabled = false;
                publishButton.style.opacity = '1';
                publishButton.title = '';
            } else {
                publishButton.disabled = true;
                publishButton.style.opacity = '0.5';
                publishButton.title = 'Publishing is disabled while in Landing options';
            }
        }

        if (gearButton) {
            if (shouldEnable) {
                gearButton.disabled = false;
                gearButton.style.opacity = '1';
                gearButton.title = '';
                gearButton.style.pointerEvents = 'auto';
            } else {
                gearButton.disabled = true;
                gearButton.style.opacity = '0.5';
                gearButton.title = 'Settings are disabled while in Landing options';
                gearButton.style.pointerEvents = 'none';
            }
        }
    }

    // -----------------------------------------------------------------------
    //  Template option controls: show/hide + value sync on template switch
    // -----------------------------------------------------------------------

    function updateTemplateOptionControls(newTemplate) {
        var data = window.fireflyTemplateOptions;
        if (!data) return;

        var allKeys = data.allOptionKeys || [];
        var tmplData = data.templates[newTemplate] || { options: [], values: {}, sections: [] };
        var activeKeys = tmplData.options;
        var activeSections = tmplData.sections || [];
        var allSections = data.allSections || [];

        // Hide/show custom sections based on template
        allSections.forEach(function(sectionId) {
            var section = wp.customize.section(sectionId);
            if (section) {
                section.active.set(activeSections.indexOf(sectionId) !== -1);
            }
        });

        // Hide controls not in new template, show those that are
        allKeys.forEach(function(key) {
            var control = wp.customize.control('template_' + key);
            if (control) {
                var isActive = activeKeys.indexOf(key) !== -1;
                control.active.set(isActive);
            }
        });

        // Update control values to the new template's saved values
        activeKeys.forEach(function(key) {
            var setting = wp.customize('template_' + key);
            if (setting && tmplData.values[key] !== undefined) {
                setting.set(tmplData.values[key]);
            }
        });

        // Reset original values for change tracking
        originalOptionValues = {};
        activeKeys.forEach(function(key) {
            var setting = wp.customize('template_' + key);
            if (setting) {
                originalOptionValues[key] = setting.get();
            }
        });

        templateOptionsChanged = false;
        updatePublishButtonState();
    }

    // -----------------------------------------------------------------------
    //  Bind template option change listeners
    // -----------------------------------------------------------------------

    function bindTemplateOptionListeners() {
        var data = window.fireflyTemplateOptions;
        if (!data) return;

        var allKeys = data.allOptionKeys || [];

        allKeys.forEach(function(key) {
            var settingId = 'template_' + key;

            wp.customize(settingId, function(setting) {
                setting.bind(function(newValue) {
                    // Update preview via REST
                    fetch('/wp-json/custom-api/v1/change-template-option-preview', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            option_key: key,
                            option_value: newValue
                        })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(respData) {
                        if (respData.success) {
                            checkTemplateOptionsChanged();
                            refreshPreview();
                        } else {
                            console.error('[FF] Failed to update option preview:', respData);
                        }
                    })
                    .catch(function(err) {
                        console.error('[FF] Error updating option preview:', err);
                    });
                });
            });
        });
    }

    // -----------------------------------------------------------------------
    //  Customizer ready
    // -----------------------------------------------------------------------

    wp.customize.bind('ready', function() {

        // Store initial option values for change tracking
        var data = window.fireflyTemplateOptions;
        if (data) {
            var currentTemplate = wp.customize('firefly_collective_active_template').get();
            var tmplData = data.templates[currentTemplate] || { options: [], values: {} };

            // Set initial control values from server data
            tmplData.options.forEach(function(key) {
                var setting = wp.customize('template_' + key);
                if (setting && tmplData.values[key] !== undefined) {
                    setting.set(tmplData.values[key]);
                }
            });

            // Record originals after a tick (so set() calls settle)
            setTimeout(function() {
                tmplData.options.forEach(function(key) {
                    var setting = wp.customize('template_' + key);
                    if (setting) {
                        originalOptionValues[key] = setting.get();
                    }
                });
            }, 100);

            // Hide controls for options not in the current template
            updateTemplateOptionControls(currentTemplate);
        }

        // Bind listeners for template option changes
        bindTemplateOptionListeners();

        // Template selector: update temp, toggle controls, refresh preview.
        wp.customize('firefly_collective_active_template', function(setting) {
            setting.bind(function(newTemplate) {
                // 1. Toggle controls for the new template
                updateTemplateOptionControls(newTemplate);

                // 2. Suppress Customizer's own preview refresh during template switch
                var origRefresh = wp.customize.previewer.refresh;
                wp.customize.previewer.refresh = function() {};

                // 3. Update temp via REST (also resets preview options)
                fetch('/wp-json/custom-api/v1/change-template-temp', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ template: newTemplate })
                })
                .then(function(r) { return r.json(); })
                .then(function(respData) {
                    // Restore Customizer refresh before our own refresh
                    wp.customize.previewer.refresh = origRefresh;
                    if (respData.success) {
                        refreshPreview();
                    } else {
                        console.error('[FF] Failed to update temp:', respData);
                    }
                })
                .catch(function(err) {
                    wp.customize.previewer.refresh = origRefresh;
                    console.error('[FF] REST error:', err);
                    refreshPreview();
                });
            });
        });

        // Landing style changes
        wp.customize('firefly_collective_landing_style', function(setting) {
            setting.bind(function(newLandingStyle) {
                fetch('/wp-json/custom-api/v1/change-landing-style-preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ landing_style: newLandingStyle })
                })
                .then(function(r) { return r.json(); })
                .then(function(respData) {
                    if (respData.success) {
                        refreshPreview();
                    } else {
                        console.error('Failed to update landing style preview:', respData);
                    }
                })
                .catch(function(err) {
                    console.error('Error updating landing style preview:', err);
                });
            });
        });

        // Publish button state based on active section
        var landingSection = wp.customize.section('firefly_collective_landing');

        if (landingSection) {
            landingSection.expanded.bind(function() {
                updatePublishButtonState();
            });
        }

        wp.customize.section.each(function(section) {
            if (section.id !== 'firefly_collective_landing') {
                section.expanded.bind(function(isExpanded) {
                    if (isExpanded) {
                        updatePublishButtonState();
                    }
                });
            }
        });

        // Force landing style dropdown to correct value
        setTimeout(function() {
            var landingStyleSetting = wp.customize('firefly_collective_landing_style');
            if (landingStyleSetting) {
                fetch('/wp-json/custom-api/v1/get-landing-style-preview')
                .then(function(r) { return r.json(); })
                .then(function(respData) {
                    if (respData.success && respData.preview_style) {
                        landingStyleSetting.set(respData.preview_style);
                    }
                })
                .catch(function(err) {
                    console.error('Error getting landing style preview:', err);
                });
            }
        }, 500);

        // Handle Edit in Gutenberg button
        var editButton = document.querySelector('#edit-landing-gutenberg');
        if (editButton) {
            editButton.addEventListener('click', function(e) {
                e.preventDefault();
                openGutenbergEditor();
            });
        }

        // Create and inject Edit in Gutenberg button
        setTimeout(function() {
            var editControl = wp.customize.control('firefly_collective_edit_landing_button');
            if (editControl) {
                var button = document.createElement('button');
                button.textContent = 'Edit in Gutenberg';
                button.className = 'button button-secondary';
                button.style.width = '100%';
                button.style.marginTop = '10px';

                var controlElement = editControl.container[0];
                controlElement.appendChild(button);

                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    openGutenbergEditor();
                });
            }
        }, 500);

    });

    // Helper: open Gutenberg editor for landing page
    function openGutenbergEditor() {
        var currentPreviewStyle = wp.customize('firefly_collective_landing_style').get();

        fetch('/wp-json/custom-api/v1/edit-landing-in-gutenberg', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ preview_style: currentPreviewStyle })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.onbeforeunload = null;
                if (wp.customize && wp.customize.state) {
                    wp.customize.state('saved').set(true);
                }
                window.location.href = data.edit_url;
            } else {
                console.error('Failed to get edit URL:', data);
                alert('Unable to open editor. Please try again.');
            }
        })
        .catch(function(err) {
            console.error('Error:', err);
            alert('Unable to open editor. Please try again.');
        });
    }

})();
