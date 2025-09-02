// template/assets/js/customize.js

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
	
	// Function to refresh preview while preserving current URL
	function refreshPreviewSafely() {
		// Use the theme-level function if available, otherwise fallback to standard refresh
		if (window.fireflyTemplateRefresh && typeof window.fireflyTemplateRefresh === 'function') {
			window.fireflyTemplateRefresh();
		} else {
			// Fallback - but this might cause the URL issue
			console.warn('Theme-level refresh function not available, using fallback');
			setTimeout(function() {
				wp.customize.previewer.refresh();
			}, 100);
		}
	}
	
	// Wait for customizer to be ready
	wp.customize.bind('ready', function() {
		
		// Get template options from localized data
		var templateOptions = customizeData.template_options || [];
		
		// Set initial values for CSS options from server
		if (customizeData.css_option_values) {
			Object.keys(customizeData.css_option_values).forEach(function(optionKey) {
				var settingId = 'template_' + optionKey;
				var setting = wp.customize(settingId);
				var serverValue = customizeData.css_option_values[optionKey];
				
				if (setting && serverValue) {
					// Set the customizer setting to match the server preview value
					setting.set(serverValue);
				}
			});
		}
		
		// Store original live values
		setTimeout(function() {
			templateOptions.forEach(function(optionKey) {
				var settingId = 'template_' + optionKey;
				var setting = wp.customize(settingId);
				
				if (setting) {
					originalValues[optionKey] = setting.get();
				}
			});
		}, 100);
		
		// Bind listeners for all template options
		templateOptions.forEach(function(optionKey) {
			var settingId = 'template_' + optionKey;
			
			// Listen for changes to this template option
			wp.customize(settingId, function(setting) {
				setting.bind(function(newValue) {
					
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
							// CRITICAL: Ensure the customizer setting is synced with the value
							// This prevents the publish issue where post_value was wrong
							if (setting.get() !== newValue) {
								console.log('Syncing customizer setting:', settingId, 'to value:', newValue);
								setting.set(newValue);
							}
							
							// Check if template options have changed and update publish button
							checkTemplateOptionsChanged();
							
							// Use safe refresh that preserves current URL
							refreshPreviewSafely();
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