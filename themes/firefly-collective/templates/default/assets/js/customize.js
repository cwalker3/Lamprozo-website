// template/assets/js/customize.js

console.log(customizeData);

(function() {
	'use strict';
	
	// Track original live values and changes
	var originalValues = {};
	var templateOptionsChanged = false;
	
	// Function to check if template options have changed
	function checkTemplateOptionsChanged() {
		var templateOptions = customizeData.template_options || [];
		var hasChanges = false;
		
		templateOptions.forEach(function(optionKey) {
			var settingId = 'template_' + optionKey;
			var setting = wp.customize(settingId);
			
			if (setting && originalValues[optionKey] !== undefined) {
				var currentValue = setting.get();
				if (currentValue !== originalValues[optionKey]) {
					hasChanges = true;
				}
			}
		});
		
		templateOptionsChanged = hasChanges;
		updatePublishButtonState();
		
		return hasChanges;
	}
	
	// Function to update publish button state
	function updatePublishButtonState() {
		var publishButton = document.querySelector('#save');
		var gearButton = document.querySelector('#publish-settings');
		var landingSection = wp.customize.section('firefly_collective_landing');
		var isInLandingSection = landingSection && landingSection.expanded.get();
		
		// Enable publish if template options changed, even if in landing section
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
	
	// Wait for customizer to be ready
	wp.customize.bind('ready', function() {
		
		// Get template options from localized data
		var templateOptions = customizeData.template_options || [];
		
		// Store original live values
		setTimeout(function() {
			templateOptions.forEach(function(optionKey) {
				var settingId = 'template_' + optionKey;
				var setting = wp.customize(settingId);
				
				if (setting) {
					originalValues[optionKey] = setting.get();
					console.log('Template option initialized:', optionKey, setting.get());
				}
			});
		}, 100);
		
		// Bind listeners for all template options
		templateOptions.forEach(function(optionKey) {
			var settingId = 'template_' + optionKey;
			
			// Listen for changes to this template option
			wp.customize(settingId, function(setting) {
				setting.bind(function(newValue) {
					
					console.log('Template option changed:', optionKey, newValue);
					
					// Make API call to update option preview
					fetch('/wp-json/custom-api/v1/change-template-option-preview', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({
							option_key: optionKey,
							option_value: newValue
						})
					})
					.then(response => {
						return response.json();
					})
					.then(data => {
						if (data.success) {
							console.log('Template option preview updated:', data.option_key, data.option_value);
							
							// Check if template options have changed and update publish button
							checkTemplateOptionsChanged();
							
							// Add a small delay before refreshing to ensure the option is saved
							setTimeout(function() {
								wp.customize.previewer.refresh();
							}, 100);
						} else {
							console.error('Failed to update template option preview:', data);
						}
					})
					.catch(error => {
						console.error('Error updating template option preview:', error);
					});
				});
			});
		});
		
		// Hook into existing landing section expand/collapse logic
		var landingSection = wp.customize.section('firefly_collective_landing');
		
		if (landingSection) {
			// Listen for when landing section is expanded/collapsed
			landingSection.expanded.bind(function(isExpanded) {
				// Update publish button state based on section and template changes
				updatePublishButtonState();
			});
		}
		
		// Also listen for other sections being expanded
		wp.customize.section.each(function(section) {
			if (section.id !== 'firefly_collective_landing') {
				section.expanded.bind(function(isExpanded) {
					if (isExpanded) {
						// Any other section is open - update button state
						updatePublishButtonState();
					}
				});
			}
		});
		
	});
	
})();