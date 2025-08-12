// theme/assets/js/customize.js

(function() {
    'use strict';
    
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
        
    });
    
})();