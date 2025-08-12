// theme/assets/js/customize.js

console.log('Firefly Collective Customizer loaded');

(function() {
    'use strict';
    
    // Wait for customizer to be ready
    wp.customize.bind('ready', function() {
        
        // Listen for template selector changes
        wp.customize('firefly_collective_template_selector', function(setting) {
            setting.bind(function(newTemplate) {
                console.log('Template changed to:', newTemplate);
                
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
                    console.log('API response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('API response data:', data);
                    if (data.success) {
                        console.log('Template temp updated successfully:', data.template);
                        
                        // Add a small delay before refreshing to ensure the option is saved
                        setTimeout(function() {
                            console.log('Refreshing iframe...');
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
        
        console.log('Template selector listener registered');
    });
    
})();