// theme/assets/js/customize.js

(function() {
    'use strict';

    var refreshTimeout = null;

    // -----------------------------------------------------------------------
    //  REST helper — root + nonce come from the server (never hardcode
    //  /wp-json/, which breaks on plain permalinks and subdirectory installs).
    // -----------------------------------------------------------------------

    function ffRoot() {
        var data = window.fireflyTemplateOptions;
        if (data && data.restRoot) {
            return data.restRoot;
        }
        // Fall back to WP's own API settings when available.
        if (window.wpApiSettings && wpApiSettings.root) {
            return wpApiSettings.root + 'custom-api/v1/';
        }
        return '/wp-json/custom-api/v1/';
    }

    function ffPost(path, body) {
        var data = window.fireflyTemplateOptions;
        var headers = { 'Content-Type': 'application/json' };
        if (data && data.nonce) {
            headers['X-WP-Nonce'] = data.nonce;   // CSRF protection
        }
        return fetch(ffRoot() + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(body)
        }).then(function(r) { return r.json(); });
    }

    function ffGet(path) {
        var data = window.fireflyTemplateOptions;
        var headers = {};
        if (data && data.nonce) {
            headers['X-WP-Nonce'] = data.nonce;
        }
        return fetch(ffRoot() + path, {
            credentials: 'same-origin',
            headers: headers
        }).then(function(r) { return r.json(); });
    }

    // The control id for an option key on a template (mirrors
    // firefly_option_control_id() in template-options.php).
    function controlIdFor(template, key) {
        var data = window.fireflyTemplateOptions;
        var tmpl = data && data.templates && data.templates[template];
        if (tmpl && tmpl.controls && tmpl.controls[key]) {
            return tmpl.controls[key];
        }
        return 'tplopt_' + template + '_' + key;
    }

    function currentTemplate() {
        var setting = wp.customize('firefly_collective_active_template');
        return setting ? setting.get() : null;
    }

    function optionKeysFor(template) {
        var data = window.fireflyTemplateOptions;
        var tmpl = data && data.templates && data.templates[template];
        return tmpl && tmpl.controls ? Object.keys(tmpl.controls) : [];
    }

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

        var tmpl = currentTemplate();
        var tmplData = tmpl && data.templates ? data.templates[tmpl] : null;
        if (!tmplData) return false;

        var hasChanges = false;
        optionKeysFor(tmpl).forEach(function(key) {
            var setting = wp.customize(controlIdFor(tmpl, key));
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
        if (!data || !data.templates) return;

        var tmplData = data.templates[newTemplate] || { controls: {}, values: {}, sections: [] };
        var activeSections = tmplData.sections || [];
        var allSections = data.allSections || [];

        // Show only the sections this template actually uses.
        allSections.forEach(function(sectionId) {
            var section = wp.customize.section(sectionId);
            if (section) {
                section.active.set(activeSections.indexOf(sectionId) !== -1);
            }
        });

        // Every template's controls are registered (namespaced), so show only
        // the selected template's and hide all the others'.
        Object.keys(data.templates).forEach(function(tmpl) {
            var controls = data.templates[tmpl].controls || {};
            var isCurrent = (tmpl === newTemplate);
            Object.keys(controls).forEach(function(key) {
                var control = wp.customize.control(controls[key]);
                if (control) {
                    control.active.set(isCurrent);
                }
            });
        });

        // Sync this template's controls to its saved values + reset change tracking.
        originalOptionValues = {};
        optionKeysFor(newTemplate).forEach(function(key) {
            var setting = wp.customize(controlIdFor(newTemplate, key));
            if (!setting) return;
            if (tmplData.values[key] !== undefined) {
                setting.set(tmplData.values[key]);
            }
            originalOptionValues[key] = setting.get();
        });

        templateOptionsChanged = false;
        updatePublishButtonState();
    }

    // -----------------------------------------------------------------------
    //  Bind template option change listeners
    // -----------------------------------------------------------------------

    function bindTemplateOptionListeners() {
        var data = window.fireflyTemplateOptions;
        if (!data || !data.templates) return;

        // Bind every template's controls. Only the selected template's are
        // visible, and the server validates the key against the temp template,
        // so a stale binding can never write to the wrong template.
        Object.keys(data.templates).forEach(function(tmpl) {
            var controls = data.templates[tmpl].controls || {};
            Object.keys(controls).forEach(function(key) {
                wp.customize(controls[key], function(setting) {
                    setting.bind(function(newValue) {
                        if (currentTemplate() !== tmpl) {
                            return; // not the template being previewed
                        }
                        ffPost('change-template-option-preview', {
                            option_key: key,
                            option_value: newValue
                        })
                        .then(function(respData) {
                            if (respData && respData.success) {
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
        });
    }

    // -----------------------------------------------------------------------
    //  Customizer ready
    // -----------------------------------------------------------------------

    wp.customize.bind('ready', function() {

        // Store initial option values for change tracking
        var data = window.fireflyTemplateOptions;
        if (data && data.templates) {
            var tmpl = currentTemplate();
            var tmplData = (tmpl && data.templates[tmpl]) || { controls: {}, values: {} };

            // Set initial control values from server data
            optionKeysFor(tmpl).forEach(function(key) {
                var setting = wp.customize(controlIdFor(tmpl, key));
                if (setting && tmplData.values[key] !== undefined) {
                    setting.set(tmplData.values[key]);
                }
            });

            // Record originals after a tick (so set() calls settle)
            setTimeout(function() {
                optionKeysFor(tmpl).forEach(function(key) {
                    var setting = wp.customize(controlIdFor(tmpl, key));
                    if (setting) {
                        originalOptionValues[key] = setting.get();
                    }
                });
            }, 100);

            // Show only this template's sections + controls
            updateTemplateOptionControls(tmpl);
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
                ffPost('change-template-temp', { template: newTemplate })
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
                ffPost('change-landing-style-preview', { landing_style: newLandingStyle })
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
                ffGet('get-landing-style-preview')
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

        ffPost('edit-landing-in-gutenberg', { preview_style: currentPreviewStyle })
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
