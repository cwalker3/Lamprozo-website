/**
 * GEO Admin Vue.js Application
 * Manages GEO configuration through a tabbed interface
 */

document.addEventListener('DOMContentLoaded', function() {
    const { createApp } = Vue;

    createApp({
        data() {
            return {
                // UI State
                loading: true,
                saving: false,
                syncing: false,
                activeTab: 'organization',
                showPreview: false,
                previewLoading: false,
                previewJson: '',
                showResetModal: false,
                
                // Sync configuration
                syncEnv: 'dev',
                hasDevEndpoint: geoData.hasDevEndpoint || false,
                hasProdEndpoint: geoData.hasProdEndpoint || false,
                devSite: geoData.devSite || '',
                prodSite: geoData.prodSite || '',
                
                // Notification
                notification: {
                    show: false,
                    type: 'success',
                    message: ''
                },
                
                // Tabs configuration
                tabs: [
                    { id: 'organization', label: 'Organization', icon: 'dashicons-building' },
                    { id: 'location', label: 'Location', icon: 'dashicons-location' },
                    { id: 'contact', label: 'Contact', icon: 'dashicons-email' },
                    { id: 'founders', label: 'Founders', icon: 'dashicons-groups' },
                    { id: 'services', label: 'Services', icon: 'dashicons-portfolio' },
                    { id: 'social', label: 'Social', icon: 'dashicons-share' },
                    { id: 'industry', label: 'Industry', icon: 'dashicons-chart-bar' }
                ],
                
                // Configuration data
                config: {
                    organization: {
                        name: '',
                        legalName: '',
                        url: '',
                        foundingDate: '',
                        description: '',
                        disambiguatingDescription: '',
                        logo: '',
                        image: ''
                    },
                    location: {
                        city: '',
                        state: '',
                        stateCode: '',
                        country: '',
                        countryCode: ''
                    },
                    contact: {
                        email: '',
                        phone: ''
                    },
                    founders: [],
                    services: [],
                    social: {
                        linkedin: '',
                        facebook: '',
                        twitter: '',
                        github: '',
                        instagram: ''
                    },
                    industry: {
                        naics: '',
                        isicV4: ''
                    }
                }
            };
        },

        computed: {
            // Check if config has been modified (for unsaved changes warning)
            hasChanges() {
                return JSON.stringify(this.config) !== JSON.stringify(this.originalConfig);
            }
        },

        mounted() {
            this.loadConfig();
            
            // Warn before leaving with unsaved changes
            window.addEventListener('beforeunload', (e) => {
                if (this.hasChanges) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        },

        methods: {
            /**
             * Load configuration from API
             */
            async loadConfig() {
                this.loading = true;
                
                try {
                    const response = await fetch(geoData.apiUrl + 'geo/config', {
                        method: 'GET',
                        headers: {
                            'X-WP-Nonce': geoData.nonce,
                            'Content-Type': 'application/json'
                        }
                    });
                    
                    const data = await response.json();
                    
                    if (data.success && data.config) {
                        this.config = this.mergeWithDefaults(data.config);
                        this.originalConfig = JSON.parse(JSON.stringify(this.config));
                    }
                } catch (error) {
                    console.error('Error loading config:', error);
                    this.showNotification('error', 'Failed to load configuration');
                } finally {
                    this.loading = false;
                }
            },

            /**
             * Merge loaded config with defaults to ensure all keys exist
             */
            mergeWithDefaults(loadedConfig) {
                const defaults = {
                    organization: {
                        name: '',
                        legalName: '',
                        url: '',
                        foundingDate: '',
                        description: '',
                        disambiguatingDescription: '',
                        logo: '',
                        image: ''
                    },
                    location: {
                        city: '',
                        state: '',
                        stateCode: '',
                        country: '',
                        countryCode: ''
                    },
                    contact: {
                        email: '',
                        phone: ''
                    },
                    founders: [],
                    services: [],
                    social: {
                        linkedin: '',
                        facebook: '',
                        twitter: '',
                        github: '',
                        instagram: ''
                    },
                    industry: {
                        naics: '',
                        isicV4: ''
                    }
                };
                
                // Deep merge
                const merged = { ...defaults };
                for (const key in loadedConfig) {
                    if (typeof loadedConfig[key] === 'object' && !Array.isArray(loadedConfig[key])) {
                        merged[key] = { ...defaults[key], ...loadedConfig[key] };
                    } else {
                        merged[key] = loadedConfig[key];
                    }
                }
                
                return merged;
            },

            /**
             * Save configuration to API
             */
            async saveConfig() {
                this.saving = true;
                
                try {
                    const response = await fetch(geoData.apiUrl + 'geo/config', {
                        method: 'POST',
                        headers: {
                            'X-WP-Nonce': geoData.nonce,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(this.config)
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        this.originalConfig = JSON.parse(JSON.stringify(this.config));
                        this.showNotification('success', 'Configuration saved successfully');
                        
                        // Refresh preview if open
                        if (this.showPreview) {
                            this.generatePreview();
                        }
                    } else {
                        this.showNotification('error', data.message || 'Failed to save configuration');
                    }
                } catch (error) {
                    console.error('Error saving config:', error);
                    this.showNotification('error', 'Failed to save configuration');
                } finally {
                    this.saving = false;
                }
            },

            /**
             * Reset configuration to defaults
             */
            async resetConfig() {
                this.showResetModal = false;
                this.saving = true;
                
                try {
                    const response = await fetch(geoData.apiUrl + 'geo/reset', {
                        method: 'POST',
                        headers: {
                            'X-WP-Nonce': geoData.nonce,
                            'Content-Type': 'application/json'
                        }
                    });
                    
                    const data = await response.json();
                    
                    if (data.success && data.config) {
                        this.config = this.mergeWithDefaults(data.config);
                        this.originalConfig = JSON.parse(JSON.stringify(this.config));
                        this.showNotification('success', 'Configuration reset to defaults');
                        
                        // Refresh preview if open
                        if (this.showPreview) {
                            this.generatePreview();
                        }
                    } else {
                        this.showNotification('error', data.message || 'Failed to reset configuration');
                    }
                } catch (error) {
                    console.error('Error resetting config:', error);
                    this.showNotification('error', 'Failed to reset configuration');
                } finally {
                    this.saving = false;
                }
            },

            /**
             * Sync configuration to remote environment
             */
            async syncConfig() {
                this.syncing = true;
                const envName = this.syncEnv === 'prod' ? 'Production' : 'Live Dev';
                
                try {
                    const response = await fetch(geoData.apiUrl + 'geo/sync', {
                        method: 'POST',
                        headers: {
                            'X-WP-Nonce': geoData.nonce,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            target_env: this.syncEnv
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        this.showNotification('success', data.message || `GEO config synced to ${envName}`);
                    } else {
                        this.showNotification('error', data.message || `Failed to sync to ${envName}`);
                    }
                } catch (error) {
                    console.error('Error syncing config:', error);
                    this.showNotification('error', `Failed to sync to ${envName}`);
                } finally {
                    this.syncing = false;
                }
            },

            /**
             * Generate JSON-LD preview
             */
            async generatePreview() {
                this.previewLoading = true;
                
                try {
                    const response = await fetch(geoData.apiUrl + 'geo/preview', {
                        method: 'POST',
                        headers: {
                            'X-WP-Nonce': geoData.nonce,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(this.config)
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        this.previewJson = this.syntaxHighlight(data.preview);
                    } else {
                        this.previewJson = '<span class="json-error">Error generating preview</span>';
                    }
                } catch (error) {
                    console.error('Error generating preview:', error);
                    this.previewJson = '<span class="json-error">Error generating preview</span>';
                } finally {
                    this.previewLoading = false;
                }
            },

            /**
             * Toggle preview panel
             */
            togglePreview() {
                this.showPreview = !this.showPreview;
                if (this.showPreview) {
                    this.generatePreview();
                }
            },

            /**
             * Syntax highlight JSON for preview
             */
            syntaxHighlight(json) {
                if (typeof json !== 'string') {
                    json = JSON.stringify(json, null, 2);
                }
                
                // Escape HTML
                json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                
                // Add syntax highlighting
                return json.replace(
                    /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
                    function(match) {
                        let cls = 'json-number';
                        if (/^"/.test(match)) {
                            if (/:$/.test(match)) {
                                cls = 'json-key';
                            } else {
                                cls = 'json-string';
                            }
                        } else if (/true|false/.test(match)) {
                            cls = 'json-boolean';
                        } else if (/null/.test(match)) {
                            cls = 'json-null';
                        }
                        return '<span class="' + cls + '">' + match + '</span>';
                    }
                );
            },

            /**
             * Switch active tab
             */
            setActiveTab(tabId) {
                this.activeTab = tabId;
            },

            /**
             * Add a new founder
             */
            addFounder() {
                this.config.founders.push({
                    name: '',
                    jobTitle: '',
                    description: ''
                });
            },

            /**
             * Remove a founder
             */
            removeFounder(index) {
                this.config.founders.splice(index, 1);
            },

            /**
             * Add a new service
             */
            addService() {
                this.config.services.push({
                    name: '',
                    description: ''
                });
            },

            /**
             * Remove a service
             */
            removeService(index) {
                this.config.services.splice(index, 1);
            },

            /**
             * Show notification
             */
            showNotification(type, message) {
                this.notification = {
                    show: true,
                    type: type,
                    message: message
                };
                
                // Auto-hide after 5 seconds
                setTimeout(() => {
                    this.notification.show = false;
                }, 5000);
            },

            /**
             * Get social platform icon class
             */
            getSocialIcon(platform) {
                const icons = {
                    linkedin: 'dashicons-linkedin',
                    facebook: 'dashicons-facebook',
                    twitter: 'dashicons-twitter',
                    github: 'dashicons-media-code',
                    instagram: 'dashicons-instagram'
                };
                return icons[platform] || 'dashicons-admin-links';
            },

            /**
             * Get social platform label
             */
            getSocialLabel(platform) {
                const labels = {
                    linkedin: 'LinkedIn',
                    facebook: 'Facebook',
                    twitter: 'Twitter / X',
                    github: 'GitHub',
                    instagram: 'Instagram'
                };
                return labels[platform] || platform;
            }
        }
    }).mount('#geo-admin-app');
});
