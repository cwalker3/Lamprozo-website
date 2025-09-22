// theme/assets/js/customize.js

(function() {
    'use strict';
    
    // URL preservation variables
    var currentPreviewUrl = null;
    var refreshTimeout = null;
    
    // Function to store and refresh to current URL
    function refreshPreviewToCurrentUrl() {
        // Clear any existing timeout
        if (refreshTimeout) {
            clearTimeout(refreshTimeout);
        }
        
        // Get the ACTUAL current URL from the preview iframe
        try {
            var previewFrame = document.querySelector('#customize-preview iframe');
            if (previewFrame && previewFrame.contentWindow) {
                currentPreviewUrl = previewFrame.contentWindow.location.href;
            } else {
                // Fallback to WordPress customizer URL
                currentPreviewUrl = wp.customize.previewer.previewUrl.get();
            }
        } catch (e) {
            // Cross-origin access might be blocked, fallback
            console.warn('Could not access iframe URL, using fallback:', e);
            currentPreviewUrl = wp.customize.previewer.previewUrl.get();
        }
        
        // Set a timeout to ensure the API call has completed
        refreshTimeout = setTimeout(function() {
            // Refresh to the stored URL to maintain current page
            wp.customize.previewer.previewUrl.set(currentPreviewUrl);
            wp.customize.previewer.refresh();
        }, 150);
    }
    
    // Function to handle template option refreshes (for template-level use)
    function handleTemplateOptionRefresh() {
        refreshPreviewToCurrentUrl();
    }
    
    // Function to disable/enable publish button
        function togglePublishButton(disable) {
        var publishButton = document.querySelector('#save');
        // Try multiple selectors for the gear icon
        var gearButton = document.querySelector('#publish-settings');
        
        if (publishButton) {
            if (disable) {
                publishButton.disabled = true;
                publishButton.style.opacity = '0.5';
                publishButton.title = 'Publishing is disabled while in Landing options';
            } else {
                publishButton.disabled = false;
                publishButton.style.opacity = '1';
                publishButton.title = '';
            }
        }
        
        if (gearButton) {
            if (disable) {
                gearButton.disabled = true;
                gearButton.style.opacity = '0.5';
                gearButton.title = 'Settings are disabled while in Landing options';
                gearButton.style.pointerEvents = 'none';
            } else {
                gearButton.disabled = false;
                gearButton.style.opacity = '1';
                gearButton.title = '';
                gearButton.style.pointerEvents = 'auto';
            }
        }
    }
    
    // Wait for customizer to be ready
    wp.customize.bind('ready', function() {
        
        // Store initial preview URL and listen for changes
        currentPreviewUrl = wp.customize.previewer.previewUrl.get();
        
        // Listen for preview URL changes to keep our stored URL current
        wp.customize.previewer.previewUrl.bind(function(newUrl) {
            currentPreviewUrl = newUrl
        });
        
        // Expose the global function for template-level customize.js
        window.fireflyTemplateRefresh = handleTemplateOptionRefresh;
        
        // Listen for template selector changes
        wp.customize('firefly_collective_active_template', function(setting) {
            setting.bind(function(newTemplate) {
                
                // Make API call to update temp template
                fetch('/wp-json/custom-api/v1/change-template-temp', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        template: newTemplate
                    })
                })
                .then(response => {
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        
                        // Use URL-preserving refresh instead of direct refresh
                        refreshPreviewToCurrentUrl();
                    } else {
                        console.error('Failed to update template temp:', data);
                    }
                })
                .catch(error => {
                    console.error('Error updating template temp:', error);
                });
            });
        });
        
        // Listen for landing style changes
        wp.customize('firefly_collective_landing_style', function(setting) {
            setting.bind(function(newLandingStyle) {
                
                // Make API call to update landing style preview
                fetch('/wp-json/custom-api/v1/change-landing-style-preview', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        landing_style: newLandingStyle
                    })
                })
                .then(response => {
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        
                        // Use URL-preserving refresh instead of direct refresh
                        refreshPreviewToCurrentUrl();
                    } else {
                        console.error('Failed to update landing style preview:', data);
                    }
                })
                .catch(error => {
                    console.error('Error updating landing style preview:', error);
                });
            });
        });
        
        // Handle publish button state based on active section
        var landingSection = wp.customize.section('firefly_collective_landing');
        
        if (landingSection) {
            // Listen for when landing section is expanded
            landingSection.expanded.bind(function(isExpanded) {
                if (isExpanded) {
                    // Landing section is open - disable publish
                    togglePublishButton(true);
                } else {
                    // Landing section is closed - enable publish
                    togglePublishButton(false);
                }
            });
        }
        
        // Also listen for other sections being expanded to re-enable publish
        wp.customize.section.each(function(section) {
            if (section.id !== 'firefly_collective_landing') {
                section.expanded.bind(function(isExpanded) {
                    if (isExpanded) {
                        // Any other section is open - enable publish
                        togglePublishButton(false);
                    }
                });
            }
        });

        // Force the landing style dropdown to show the correct preview value
        setTimeout(function() {
            var landingStyleSetting = wp.customize('firefly_collective_landing_style');
            if (landingStyleSetting) {
                // Get the current preview value via API
                fetch('/wp-json/custom-api/v1/get-landing-style-preview')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.preview_style) {
                        landingStyleSetting.set(data.preview_style);
                    }
                })
                .catch(error => {
                    console.error('Error getting landing style preview:', error);
                });
            }
        }, 500);

        // Handle Edit in Gutenberg button
        var editButton = document.querySelector('#edit-landing-gutenberg');
        if (editButton) {
            editButton.addEventListener('click', function(e) {
                e.preventDefault();
                
                var currentPreviewStyle = wp.customize('firefly_collective_landing_style').get();
                
                fetch('/wp-json/custom-api/v1/edit-landing-in-gutenberg', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        preview_style: currentPreviewStyle
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Temporarily disable beforeunload warning
                        window.onbeforeunload = null;
                        // Also try to disable WordPress customizer's beforeunload
                        if (wp.customize && wp.customize.state) {
                            wp.customize.state('saved').set(true);
                        }
                        // Redirect to Gutenberg in current window
                        window.location.href = data.edit_url;
                    } else {
                        console.error('Failed to get edit URL:', data);
                        alert('Unable to open editor. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Unable to open editor. Please try again.');
                });
            });
        }

        // Create and inject Edit in Gutenberg button
        setTimeout(function() {
            var editControl = wp.customize.control('firefly_collective_edit_landing_button');
            if (editControl) {
                // Create button element
                var button = document.createElement('button');
                button.textContent = 'Edit in Gutenberg';
                button.className = 'button button-secondary';
                button.style.width = '100%';
                button.style.marginTop = '10px';
                
                // Insert button after the control
                var controlElement = editControl.container[0];
                controlElement.appendChild(button);
                
                // Add click handler
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    var currentPreviewStyle = wp.customize('firefly_collective_landing_style').get();
                    
                    fetch('/wp-json/custom-api/v1/edit-landing-in-gutenberg', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            preview_style: currentPreviewStyle
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Temporarily disable beforeunload warning
                            window.onbeforeunload = null;
                            // Also try to disable WordPress customizer's beforeunload
                            if (wp.customize && wp.customize.state) {
                                wp.customize.state('saved').set(true);
                            }
                            // Redirect to Gutenberg in current window
                            window.location.href = data.edit_url;
                        } else {
                            console.error('Failed to get edit URL:', data);
                            alert('Unable to open editor. Please try again.');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Unable to open editor. Please try again.');
                    });
                });
            }
        }, 500);
        
    });
    
})();