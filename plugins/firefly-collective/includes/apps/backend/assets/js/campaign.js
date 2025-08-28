// plugin/assets/js/campaign.js

// Vue.js Campaign Management Component
document.addEventListener('DOMContentLoaded', function() {
    
    // Handle both array and object with features property
    let featuresArray = null;
    if (window.campaignFeatures) {
        if (Array.isArray(window.campaignFeatures)) {
            featuresArray = window.campaignFeatures;
        } else if (window.campaignFeatures.features && Array.isArray(window.campaignFeatures.features)) {
            featuresArray = window.campaignFeatures.features;
        }
    }
    
    if (!featuresArray || featuresArray.length === 0) {
        console.error('Campaign features data not found');
        return;
    }
    
    const { createApp } = Vue;
    
    createApp({
        data() {
            return {
                loading: true,
                showCreateForm: false,
                editingCampaign: null,
                campaigns: [],
                expandedCampaigns: [],
                features: featuresArray,
                validationErrors: [],
                campaignForm: {
                    name: '',
                    start_date: '',
                    end_date: '',
                    unlimited: false,
                    features_config: {},
                    preselect_config: {}
                }
            };
        },
        
        computed: {
            // Safe getter for features config
            safeFeatureConfig() {
                return (featureId, optionId = null, addonId = null) => {
                    const featureConfig = this.campaignForm.features_config[featureId];
                    if (!featureConfig) return false;
                    
                    if (optionId === null) return featureConfig;
                    
                    const optionConfig = featureConfig.options[optionId];
                    if (!optionConfig) return false;
                    
                    if (addonId === null) return optionConfig;
                    
                    return optionConfig.addons[addonId] || false;
                };
            },

            allFeaturesSelected() {
                if (!this.features || !this.campaignForm.features_config) return false;
                
                return this.features.every(feature => {
                    const featureConfig = this.campaignForm.features_config[feature.id];
                    if (!featureConfig || !featureConfig.show) return false;
                    
                    // Check if all options are selected
                    return feature.options.every(option => {
                        const optionConfig = featureConfig.options[option.id];
                        if (!optionConfig || !optionConfig.show) return false;
                        
                        // Check if all addons are selected
                        if (option.addons && option.addons.length > 0) {
                            return option.addons.every(addon => optionConfig.addons[addon.id] === true);
                        }
                        return true;
                    });
                });
            },
            
            someButNotAllFeaturesSelected() {
                if (!this.features || !this.campaignForm.features_config) return false;
                if (this.allFeaturesSelected) return false;
                
                // Check if any feature/option/addon is selected
                return this.features.some(feature => {
                    const featureConfig = this.campaignForm.features_config[feature.id];
                    if (featureConfig && featureConfig.show) return true;
                    
                    return feature.options.some(option => {
                        const optionConfig = featureConfig?.options[option.id];
                        if (optionConfig && optionConfig.show) return true;
                        
                        if (option.addons && option.addons.length > 0) {
                            return option.addons.some(addon => optionConfig?.addons[addon.id] === true);
                        }
                        return false;
                    });
                });
            }
        },
        
        watch: {
            // Watch the unlimited checkbox and clear dates when checked
            'campaignForm.unlimited'(newValue) {
                if (newValue) {
                    // Clear dates when unlimited is checked
                    this.campaignForm.start_date = '';
                    this.campaignForm.end_date = '';
                }
                // Clear any validation errors when changing unlimited status
                this.clearValidationErrors();
            },
            
            // Clear validation errors when form values change
            'campaignForm.name'() {
                this.clearValidationErrors();
            },
            'campaignForm.start_date'() {
                this.clearValidationErrors();
            },
            'campaignForm.end_date'() {
                this.clearValidationErrors();
            }
        },
        
        mounted() {
            if (this.features && this.features.length > 0) {
                this.initializeFormData();
                this.loadCampaigns();
            } else {
                console.error('No campaign features found. Check window.campaignFeatures');
                this.loading = false;
            }
        },
        
        methods: {
            // Clear validation errors
            clearValidationErrors() {
                this.validationErrors = [];
            },
            
            // Validate form before submission
            validateForm() {
                this.validationErrors = [];
                
                // Validate campaign name
                if (!this.campaignForm.name || this.campaignForm.name.trim() === '') {
                    this.validationErrors.push('Campaign name is required');
                }
                
                // Validate dates only if not unlimited
                if (!this.campaignForm.unlimited) {
                    if (!this.campaignForm.start_date || this.campaignForm.start_date.trim() === '') {
                        this.validationErrors.push('Start date is required when campaign is not unlimited');
                    }
                    
                    if (!this.campaignForm.end_date || this.campaignForm.end_date.trim() === '') {
                        this.validationErrors.push('End date is required when campaign is not unlimited');
                    }
                    
                    // If both dates are present, validate that end date is after start date
                    if (this.campaignForm.start_date && this.campaignForm.end_date) {
                        const startDate = new Date(this.campaignForm.start_date);
                        const endDate = new Date(this.campaignForm.end_date);
                        
                        if (endDate <= startDate) {
                            this.validationErrors.push('End date must be after start date');
                        }
                    }
                }
                
                // Validate that at least one feature is selected
                const hasSelectedFeatures = Object.values(this.campaignForm.features_config || {})
                    .some(config => config.show === true);
                
                if (!hasSelectedFeatures) {
                    this.validationErrors.push('At least one feature must be selected');
                }
                
                return this.validationErrors.length === 0;
            },
            
            // Helper method to get fetch headers with nonce
            getFetchHeaders(includeContentType = false) {
                const headers = {};
                
                if (includeContentType) {
                    headers['Content-Type'] = 'application/json';
                }
                
                return headers;
            },
            
            initializeFormData() {
                // Create completely new objects to avoid reactivity issues
                const featuresConfig = {};
                const preselectConfig = {};
                
                this.features.forEach(feature => {
                    // Ensure feature exists
                    if (!feature || !feature.id) {
                        console.warn('Invalid feature:', feature);
                        return;
                    }
                    
                    // Features config
                    featuresConfig[feature.id] = {
                        show: false,
                        options: {}
                    };
                    
                    // Preselect config
                    preselectConfig[feature.id] = {
                        selectedOption: '',
                        selectedAddons: [],
                        quantity: 1
                    };
                    
                    // Initialize options
                    if (feature.options && Array.isArray(feature.options)) {
                        feature.options.forEach(option => {
                            if (!option || !option.id) {
                                console.warn('Invalid option:', option);
                                return;
                            }
                            
                            featuresConfig[feature.id].options[option.id] = {
                                show: false,
                                addons: {}
                            };
                            
                            // Initialize addons
                            if (option.addons && Array.isArray(option.addons)) {
                                option.addons.forEach(addon => {
                                    if (addon && addon.id) {
                                        featuresConfig[feature.id].options[option.id].addons[addon.id] = false;
                                    } else {
                                        console.warn('Invalid addon:', addon);
                                    }
                                });
                            }
                        });
                    } else {
                        console.warn('Feature has no options:', feature);
                    }
                });
                
                // Assign the complete structures
                this.campaignForm.features_config = featuresConfig;
                this.campaignForm.preselect_config = preselectConfig;
            },
            
            // Copy to clipboard functionality
            async copyToClipboard(text, event) {
                try {
                    // Modern clipboard API
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(text);
                    } else {
                        // Fallback for older browsers or non-HTTPS
                        const textArea = document.createElement('textarea');
                        textArea.value = text;
                        textArea.style.position = 'fixed';
                        textArea.style.opacity = '0';
                        document.body.appendChild(textArea);
                        textArea.focus();
                        textArea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textArea);
                    }
                    
                    // Visual feedback - ensure we get the button element
                    let button = event.target;
                    
                    // If clicked on a child element or text node, find the button parent
                    while (button && button.tagName !== 'BUTTON') {
                        button = button.parentElement;
                    }
                    
                    if (!button) return; // Safety check
                    
                    // Store original state using innerHTML (not textContent) 
                    const originalContent = button.innerHTML;
                    const originalTitle = button.title;
                    
                    // Change appearance to show success
                    button.classList.add('copied');
                    button.innerHTML = '✓';
                    button.title = 'Copied!';
                    
                    // Reset after 2 seconds
                    setTimeout(() => {
                        if (button) { // Extra safety check
                            button.classList.remove('copied');
                            button.innerHTML = originalContent; // Restore original HTML content
                            button.title = originalTitle;
                        }
                    }, 2000);
                    
                } catch (err) {
                    console.error('Failed to copy text: ', err);
                    
                    // Show error feedback with same button-finding logic
                    let button = event.target;
                    while (button && button.tagName !== 'BUTTON') {
                        button = button.parentElement;
                    }
                    
                    if (!button) return;
                    
                    const originalContent = button.innerHTML;
                    const originalTitle = button.title;
                    
                    button.innerHTML = '✗';
                    button.title = 'Copy failed';
                    
                    setTimeout(() => {
                        if (button) {
                            button.innerHTML = originalContent;
                            button.title = originalTitle;
                        }
                    }, 2000);
                }
            },

            
            // Get available addons for a specific option (filtered by what's marked as show)
            getAvailableAddons(feature, option) {
                if (!option.addons || !Array.isArray(option.addons)) return [];
                
                return option.addons.filter(addon => {
                    if (!addon || !addon.id) return false;
                    
                    // Check if this addon is marked as "show" in features config
                    const featureConfig = this.campaignForm.features_config[feature.id];
                    if (!featureConfig || !featureConfig.options[option.id]) return false;
                    
                    return featureConfig.options[option.id].addons[addon.id] === true;
                });
            },
            
            // Get preselectable addons for a feature based on selected option
            getPreselectableAddons(feature) {
                const selectedOptionId = this.campaignForm.preselect_config[feature.id]?.selectedOption;
                if (!selectedOptionId) return [];
                
                const selectedOption = feature.options.find(opt => opt.id == selectedOptionId);
                if (!selectedOption || !selectedOption.addons) return [];
                
                // Return only addons that are marked as "show" in features config
                return selectedOption.addons.filter(addon => {
                    if (!addon || !addon.id) return false;
                    
                    const featureConfig = this.campaignForm.features_config[feature.id];
                    if (!featureConfig || !featureConfig.options[selectedOption.id]) return false;
                    
                    return featureConfig.options[selectedOption.id].addons[addon.id] === true;
                });
            },
            
            // Only clear other options within the same feature
            onPreselectOptionChange(featureId) {
                // Clear selected addons when option changes (do this first)
                if (this.campaignForm.preselect_config[featureId]) {
                    this.campaignForm.preselect_config[featureId].selectedAddons = [];
                }
                
                // Note: We do NOT clear other features anymore - each feature can have its own preselection
                console.log(`Feature ${featureId} option changed`); // Debug log
                
                // Force Vue to detect the change
                this.$forceUpdate();
            },
            
            // Toggle addon selection in preselect section
            togglePreselectAddon(featureId, addonId) {
                if (!this.campaignForm.preselect_config[featureId]) return;
                
                const selectedAddons = this.campaignForm.preselect_config[featureId].selectedAddons;
                const index = selectedAddons.indexOf(addonId);
                
                if (index > -1) {
                    selectedAddons.splice(index, 1);
                } else {
                    selectedAddons.push(addonId);
                }
            },
            
            async loadCampaigns() {
                this.loading = true;
                try {
                    const response = await fetch(`${campaignData.api_url}get-campaigns`, {
                        method: 'GET',
                        headers: this.getFetchHeaders()
                    });
                    
                    const data = await response.json();
                    if (data.success) {
                        // Convert unlimited from string/integer to boolean for proper display
                        this.campaigns = data.campaigns.map(campaign => {
                            const converted = {
                                ...campaign,
                                unlimited: parseInt(campaign.unlimited) === 1  // Convert "0"/"1" or 0/1 to boolean
                            };
                            return converted;
                        });
                    } else {
                        console.error('Failed to load campaigns:', data);
                        alert('Failed to load campaigns: ' + (data.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error loading campaigns:', error);
                    alert('Error loading campaigns: ' + error.message);
                } finally {
                    this.loading = false;
                }
            },
            
            async saveCampaign() {
                // Clear previous validation errors
                this.clearValidationErrors();
                
                // Validate form before sending
                if (!this.validateForm()) {
                    // Smooth scroll to validation errors with better positioning
                    this.$nextTick(() => {
                        const errorContainer = document.querySelector('.notice-error');
                        if (errorContainer) {
                            // Add a slight delay to ensure DOM is updated
                            setTimeout(() => {
                                errorContainer.scrollIntoView({ 
                                    behavior: 'smooth', 
                                    block: 'start',
                                    inline: 'nearest'
                                });
                                // Add a subtle highlight effect
                                errorContainer.style.boxShadow = '0 0 10px rgba(220, 53, 69, 0.3)';
                                setTimeout(() => {
                                    errorContainer.style.boxShadow = '';
                                }, 2000);
                            }, 100);
                        }
                    });
                    return;
                }
                
                try {
                    const endpoint = this.editingCampaign ? 'update-campaign' : 'create-campaign';
                    
                    const payload = {
                        ...this.campaignForm
                    };
                    
                    if (this.editingCampaign) {
                        payload.id = this.editingCampaign.id;
                    }
                    
                    const response = await fetch(`${campaignData.api_url}${endpoint}`, {
                        method: 'POST',
                        headers: this.getFetchHeaders(true),
                        body: JSON.stringify(payload)
                    });
                    
                    const data = await response.json();
                    if (data.success) {
                        this.cancelForm();
                        this.loadCampaigns();
                        alert('Campaign saved successfully!');
                    } else {
                        // Show specific error message from server or validation error
                        const errorMessage = data.message || 'Unknown error occurred';
                        
                        // Check if it's a database/server error and show specific message
                        if (errorMessage.includes('start_date') || errorMessage.includes('end_date')) {
                            this.validationErrors = ['Please ensure all date fields are properly filled out'];
                        } else if (errorMessage.includes('name')) {
                            this.validationErrors = ['Campaign name is invalid or missing'];
                        } else {
                            alert('Error saving campaign: ' + errorMessage);
                        }
                    }
                } catch (error) {
                    console.error('Error saving campaign:', error);
                    alert('Error saving campaign: ' + error.message);
                }
            },
            
            editCampaign(campaign) {
                this.editingCampaign = campaign;
                
                const formUnlimited = campaign.unlimited === true || campaign.unlimited === 1 || campaign.unlimited === "1";
                
                // First initialize a clean form structure
                this.initializeFormData();
                
                // Clear validation errors when editing
                this.clearValidationErrors();
                
                // Then overlay the campaign data on top of the initialized structure
                this.campaignForm.name = campaign.name || '';
                this.campaignForm.start_date = campaign.start_date || '';
                this.campaignForm.end_date = campaign.end_date || '';
                this.campaignForm.unlimited = formUnlimited;
                
                // Merge features_config - overlay campaign data onto initialized structure
                if (campaign.features_config) {
                    Object.keys(campaign.features_config).forEach(featureId => {
                        const campaignFeatureConfig = campaign.features_config[featureId];
                        if (this.campaignForm.features_config[featureId] && campaignFeatureConfig) {
                            // Set feature show state
                            if (typeof campaignFeatureConfig.show !== 'undefined') {
                                this.campaignForm.features_config[featureId].show = campaignFeatureConfig.show;
                            }
                            
                            // Merge options
                            if (campaignFeatureConfig.options) {
                                Object.keys(campaignFeatureConfig.options).forEach(optionId => {
                                    const campaignOptionConfig = campaignFeatureConfig.options[optionId];
                                    if (this.campaignForm.features_config[featureId].options[optionId] && campaignOptionConfig) {
                                        // Set option show state
                                        if (typeof campaignOptionConfig.show !== 'undefined') {
                                            this.campaignForm.features_config[featureId].options[optionId].show = campaignOptionConfig.show;
                                        }
                                        
                                        // Merge addons
                                        if (campaignOptionConfig.addons) {
                                            Object.keys(campaignOptionConfig.addons).forEach(addonId => {
                                                if (typeof this.campaignForm.features_config[featureId].options[optionId].addons[addonId] !== 'undefined') {
                                                    this.campaignForm.features_config[featureId].options[optionId].addons[addonId] = campaignOptionConfig.addons[addonId];
                                                }
                                            });
                                        }
                                    }
                                });
                            }
                        }
                    });
                }
                
                // Merge preselect_config - overlay campaign data onto initialized structure  
                if (campaign.preselect_config) {
                    Object.keys(campaign.preselect_config).forEach(featureId => {
                        const campaignPreselectConfig = campaign.preselect_config[featureId];
                        if (this.campaignForm.preselect_config[featureId] && campaignPreselectConfig) {
                            if (campaignPreselectConfig.selectedOption) {
                                this.campaignForm.preselect_config[featureId].selectedOption = campaignPreselectConfig.selectedOption;
                            }
                            if (Array.isArray(campaignPreselectConfig.selectedAddons)) {
                                this.campaignForm.preselect_config[featureId].selectedAddons = [...campaignPreselectConfig.selectedAddons];
                            }
                            if (campaignPreselectConfig.quantity) {
                                this.campaignForm.preselect_config[featureId].quantity = campaignPreselectConfig.quantity;
                            }
                        }
                    });
                }
                
                this.showCreateForm = true;
                
                // Scroll after Vue renders the form
                this.$nextTick(() => {
                    setTimeout(() => {
                        const formTitle = document.querySelector('.ffc-campaign-form h3');
                        if (formTitle) {
                            formTitle.scrollIntoView({ 
                                behavior: 'smooth', 
                                block: 'start' 
                            });
                        }
                    }, 100);
                });
            },
            
            cancelForm() {
                this.showCreateForm = false;
                this.editingCampaign = null;
                this.clearValidationErrors();
                // Only reinitialize form data when actually canceling (not when switching between campaigns)
                this.initializeFormData();
            },
            
            toggleExpand(campaignId) {
                const index = this.expandedCampaigns.indexOf(campaignId);
                if (index > -1) {
                    this.expandedCampaigns.splice(index, 1);
                } else {
                    this.expandedCampaigns.push(campaignId);
                }
            },

            toggleAllFeatures(event) {
                const shouldSelectAll = event.target.checked;
                
                // Loop through all features
                this.features.forEach(feature => {
                    const featureConfig = this.campaignForm.features_config[feature.id];
                    if (!featureConfig) return;
                    
                    // Set feature show state
                    featureConfig.show = shouldSelectAll;
                    
                    // Loop through all options for this feature
                    feature.options.forEach(option => {
                        const optionConfig = featureConfig.options[option.id];
                        if (!optionConfig) return;
                        
                        // Set option show state
                        optionConfig.show = shouldSelectAll;
                        
                        // Loop through all addons for this option
                        if (option.addons && option.addons.length > 0) {
                            option.addons.forEach(addon => {
                                // Set addon state
                                optionConfig.addons[addon.id] = shouldSelectAll;
                            });
                        }
                    });
                });
            },
            
            formatDate(dateString) {
                return new Date(dateString).toLocaleDateString();
            },
            
            getFeaturesSummary(campaign) {
                const features = Object.keys(campaign.features_config || {})
                    .filter(id => campaign.features_config[id]?.show)
                    .map(id => {
                        const feature = this.features.find(f => f.id == id);
                        return feature ? feature.featureName : `Feature ${id}`;
                    });
                return features.length ? features.join(', ') : 'None';
            },
            
            getPreselectSummary(campaign) {
                const preselected = [];
                Object.keys(campaign.preselect_config || {}).forEach(featureId => {
                    const config = campaign.preselect_config[featureId];
                    if (config.selectedOption) {
                        const feature = this.features.find(f => f.id == featureId);
                        const option = feature?.options.find(o => o.id == config.selectedOption);
                        if (feature && option) {
                            let summary = `<strong>${feature.featureName}:</strong> ${option.optionName}`;
                            
                            // Add selected addons if any
                            if (config.selectedAddons && config.selectedAddons.length > 0) {
                                const addonNames = [];
                                config.selectedAddons.forEach(addonId => {
                                    const addon = option.addons?.find(a => a.id == addonId);
                                    if (addon) {
                                        addonNames.push(addon.addonName);
                                    }
                                });
                                
                                if (addonNames.length > 0) {
                                    summary += `<br>&nbsp;&nbsp;&nbsp;&nbsp;<em>+ ${addonNames.join(', ')}</em>`;
                                }
                            }
                            
                            // Add quantity for non-recurring features
                            if (!feature.recurring && config.quantity && config.quantity > 1) {
                                summary += ` <span style="color: #666;">(Qty: ${config.quantity})</span>`;
                            }
                            
                            preselected.push(summary);
                        }
                    }
                });
                return preselected.length ? preselected.join('<br>') : 'None';
            },
            
            async deleteCampaign(campaignId) {
                if (!confirm('Are you sure you want to delete this campaign?')) {
                    return;
                }
                
                try {
                    const response = await fetch(`${campaignData.api_url}delete-campaign`, {
                        method: 'POST',
                        headers: this.getFetchHeaders(true),
                        body: JSON.stringify({ id: campaignId })
                    });
                    
                    const data = await response.json();
                    if (data.success) {
                        this.campaigns = this.campaigns.filter(c => c.id !== campaignId);
                        alert('Campaign deleted successfully!');
                    } else {
                        alert('Failed to delete campaign: ' + (data.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error deleting campaign:', error);
                    alert('Error deleting campaign: ' + error.message);
                }
            }
        }
    }).mount('#ffc-campaign-app');
});