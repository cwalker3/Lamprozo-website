// theme/assets/js/customize.js

(function() {
    'use strict';
    
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
        
        // Listen for template selector changes
        wp.customize('firefly_collective_template_selector', function(setting) {
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
                        
                        // Add a small delay before refreshing to ensure the option is saved
                        setTimeout(function() {
                            wp.customize.previewer.refresh();
                        }, 100);
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
                        
                        // Add a small delay before refreshing to ensure the option is saved
                        setTimeout(function() {
                            wp.customize.previewer.refresh();
                        }, 100);
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
        
    });
    
})();