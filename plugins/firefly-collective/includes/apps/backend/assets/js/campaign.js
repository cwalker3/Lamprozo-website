// plugin/assets/js/campaign.js

// Vue.js Campaign Management Component
document.addEventListener('DOMContentLoaded', function() {
    // Wait for features data to be available
    if (!window.campaignFeatures || !Array.isArray(window.campaignFeatures)) {
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
                features: window.campaignFeatures || [],
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
            
            // Handle option selection change in preselect section
            onPreselectOptionChange(featureId) {
                // Clear selected addons when option changes
                if (this.campaignForm.preselect_config[featureId]) {
                    this.campaignForm.preselect_config[featureId].selectedAddons = [];
                }
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
                        method: 'GET'
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
                    }
                } catch (error) {
                    console.error('Error loading campaigns:', error);
                } finally {
                    this.loading = false;
                }
            },
            
            async saveCampaign() {
                try {
                    const endpoint = this.editingCampaign ? 'update-campaign' : 'create-campaign';
                    const method = 'POST';
                    
                    const payload = {
                        ...this.campaignForm
                    };
                    
                    if (this.editingCampaign) {
                        payload.id = this.editingCampaign.id;
                    }
                    
                    const response = await fetch(`${campaignData.api_url}${endpoint}`, {
                        method: method,
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    });
                    
                    const data = await response.json();
                    if (data.success) {
                        this.cancelForm();
                        this.loadCampaigns();
                    } else {
                        alert('Error saving campaign: ' + (data.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error saving campaign:', error);
                    alert('Error saving campaign');
                }
            },
            
            editCampaign(campaign) {
                this.editingCampaign = campaign;
                
                const formUnlimited = campaign.unlimited === true || campaign.unlimited === 1 || campaign.unlimited === "1";
                
                // Create completely new form object
                const newForm = {
                    name: campaign.name || '',
                    start_date: campaign.start_date || '',
                    end_date: campaign.end_date || '',
                    unlimited: formUnlimited,
                    features_config: JSON.parse(JSON.stringify(campaign.features_config || {})),
                    preselect_config: JSON.parse(JSON.stringify(campaign.preselect_config || {}))
                };
                
                // Assign the new form and show it
                this.campaignForm = newForm;
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
            }
        }
    }).mount('#ffc-campaign-app');
});