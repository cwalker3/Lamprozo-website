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
                syncingSeo: false,
                activeTab: 'organization',

                // SEO sync confirmation modal. Opens when the user clicks
                // "Sync SEO to ..." so they can decide what to include
                // (site-wide settings, per-page meta, or both) before sending.
                showSeoSyncModal: false,
                seoSyncIncludeAdmin: true,
                seoSyncIncludePages: true,
                seoSyncStatus: null, // { type: 'admin'|'pages'|'done', text: string }
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
                
                // Tabs configuration. SEO leads because it controls site-wide
                // defaults that affect every page; the GEO tabs configure
                // structured data for one Organization.
                tabs: [
                    { id: 'seo', label: 'SEO', icon: 'dashicons-search' },
                    { id: 'organization', label: 'Organization', icon: 'dashicons-building' },
                    { id: 'location', label: 'Location', icon: 'dashicons-location' },
                    { id: 'contact', label: 'Contact', icon: 'dashicons-email' },
                    { id: 'founders', label: 'Founders', icon: 'dashicons-groups' },
                    { id: 'services', label: 'Services', icon: 'dashicons-portfolio' },
                    { id: 'social', label: 'Social', icon: 'dashicons-share' },
                    { id: 'industry', label: 'Industry', icon: 'dashicons-chart-bar' }
                ],
                
                // SEO config (site-wide defaults that compose with per-page _seo_* meta).
                // Mirrors the seo-config.json shape.
                seoConfig: {
                    defaults: {
                        og_image_url: '',
                        title_separator: ' - '
                    },
                    twitter: {
                        site_handle: '',
                        creator_handle: '',
                        card_type: 'summary_large_image'
                    },
                    facebook: {
                        app_id: ''
                    },
                    verification: {
                        google: '',
                        bing: '',
                        yandex: '',
                        pinterest: '',
                        baidu: ''
                    },
                    robots: {
                        default_index: true,
                        default_follow: true
                    }
                },
                originalSeoConfig: null,

                // Twitter card-type options (Twitter docs: summary, summary_large_image, app, player).
                twitterCardOptions: [
                    { value: 'summary',             label: 'Summary' },
                    { value: 'summary_large_image', label: 'Summary with large image' },
                    { value: 'app',                 label: 'App' },
                    { value: 'player',              label: 'Player' }
                ],

                // Configuration data
                config: {
                    organization: {
                        name: '',
                        legalName: '',
                        entityType: '',
                        tagline: '',
                        alternateNames: [],
                        url: '',
                        foundingDate: '',
                        description: '',
                        disambiguatingDescription: '',
                        serviceCatalogName: '',
                        contactDescription: '',
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
                        instagram: '',
                        youtube: '',
                        twitch: '',
                        tiktok: '',
                        discord: '',
                        bluesky: '',
                        mastodon: ''
                    },
                    industry: {
                        naics: '',
                        isicV4: ''
                    }
                }
            };
        },

        computed: {
            // True when EITHER the GEO config OR the SEO config has unsaved changes.
            hasChanges() {
                return JSON.stringify(this.config) !== JSON.stringify(this.originalConfig)
                    || JSON.stringify(this.seoConfig) !== JSON.stringify(this.originalSeoConfig);
            }
        },

        mounted() {
            // Load both configs in parallel — they're independent stores.
            Promise.all([ this.loadConfig(), this.loadSeoConfig() ]);

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
             * Load SEO config from the parallel /seo/config endpoint.
             * Independent of GEO config — separate DB rows, separate state.
             */
            async loadSeoConfig() {
                try {
                    const response = await fetch(geoData.apiUrl + 'seo/config', {
                        method: 'GET',
                        headers: {
                            'X-WP-Nonce': geoData.nonce,
                            'Content-Type': 'application/json'
                        }
                    });
                    const data = await response.json();
                    if (data.success && data.config) {
                        // Shallow-merge with the data() defaults so missing
                        // sub-keys (added in later releases) get their defaults.
                        this.seoConfig = Object.assign({}, this.seoConfig, data.config);
                        // Section-level merge so each section's missing keys
                        // still resolve to the JS default values.
                        ['defaults', 'twitter', 'facebook', 'verification', 'robots'].forEach((s) => {
                            this.seoConfig[s] = Object.assign({}, this.seoConfig[s] || {}, (data.config[s] || {}));
                        });
                        this.originalSeoConfig = JSON.parse(JSON.stringify(this.seoConfig));
                    }
                } catch (error) {
                    console.error('Error loading SEO config:', error);
                    this.showNotification('error', 'Failed to load SEO configuration');
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
                        entityType: '',
                        tagline: '',
                        alternateNames: [],
                        url: '',
                        foundingDate: '',
                        description: '',
                        disambiguatingDescription: '',
                        serviceCatalogName: '',
                        contactDescription: '',
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
                        instagram: '',
                        youtube: '',
                        twitch: '',
                        tiktok: '',
                        discord: '',
                        bluesky: '',
                        mastodon: ''
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
             * Save BOTH GEO and SEO configurations. Two parallel POSTs so a
             * single "Save Changes" button covers all of the user's edits on
             * either tab group. Each is retried independently; partial failure
             * surfaces in the notification.
             */
            async saveConfig() {
                this.saving = true;

                try {
                    const [geoResp, seoResp] = await Promise.all([
                        fetch(geoData.apiUrl + 'geo/config', {
                            method: 'POST',
                            headers: {
                                'X-WP-Nonce': geoData.nonce,
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(this.config)
                        }).then((r) => r.json()).catch((e) => ({ success: false, message: e.message })),
                        fetch(geoData.apiUrl + 'seo/config', {
                            method: 'POST',
                            headers: {
                                'X-WP-Nonce': geoData.nonce,
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(this.seoConfig)
                        }).then((r) => r.json()).catch((e) => ({ success: false, message: e.message }))
                    ]);

                    if (geoResp && geoResp.success) {
                        this.originalConfig = JSON.parse(JSON.stringify(this.config));
                    }
                    if (seoResp && seoResp.success) {
                        this.originalSeoConfig = JSON.parse(JSON.stringify(this.seoConfig));
                    }

                    if (geoResp.success && seoResp.success) {
                        this.showNotification('success', 'SEO/GEO configuration saved successfully');
                        if (this.showPreview) {
                            this.generatePreview();
                        }
                    } else {
                        const parts = [];
                        if (!geoResp.success) parts.push('GEO: ' + (geoResp.message || 'failed'));
                        if (!seoResp.success) parts.push('SEO: ' + (seoResp.message || 'failed'));
                        this.showNotification('error', 'Save failed — ' + parts.join('; '));
                    }
                } catch (error) {
                    console.error('Error saving config:', error);
                    this.showNotification('error', 'Failed to save configuration');
                } finally {
                    this.saving = false;
                }
            },

            /**
             * Reset BOTH GEO and SEO configurations to their default values
             * (re-seeds each table from the corresponding JSON file).
             */
            async resetConfig() {
                this.showResetModal = false;
                this.saving = true;

                try {
                    const [geoResp, seoResp] = await Promise.all([
                        fetch(geoData.apiUrl + 'geo/reset', {
                            method: 'POST',
                            headers: { 'X-WP-Nonce': geoData.nonce, 'Content-Type': 'application/json' }
                        }).then((r) => r.json()).catch((e) => ({ success: false, message: e.message })),
                        fetch(geoData.apiUrl + 'seo/reset', {
                            method: 'POST',
                            headers: { 'X-WP-Nonce': geoData.nonce, 'Content-Type': 'application/json' }
                        }).then((r) => r.json()).catch((e) => ({ success: false, message: e.message }))
                    ]);

                    if (geoResp && geoResp.success && geoResp.config) {
                        this.config = this.mergeWithDefaults(geoResp.config);
                        this.originalConfig = JSON.parse(JSON.stringify(this.config));
                    }
                    if (seoResp && seoResp.success && seoResp.config) {
                        ['defaults', 'twitter', 'facebook', 'verification', 'robots'].forEach((s) => {
                            this.seoConfig[s] = Object.assign({}, this.seoConfig[s] || {}, (seoResp.config[s] || {}));
                        });
                        this.originalSeoConfig = JSON.parse(JSON.stringify(this.seoConfig));
                    }

                    if (geoResp.success && seoResp.success) {
                        this.showNotification('success', 'SEO/GEO configuration reset to defaults');
                        if (this.showPreview) {
                            this.generatePreview();
                        }
                    } else {
                        const parts = [];
                        if (!geoResp.success) parts.push('GEO: ' + (geoResp.message || 'failed'));
                        if (!seoResp.success) parts.push('SEO: ' + (seoResp.message || 'failed'));
                        this.showNotification('error', 'Reset failed — ' + parts.join('; '));
                    }
                } catch (error) {
                    console.error('Error resetting config:', error);
                    this.showNotification('error', 'Failed to reset configuration');
                } finally {
                    this.saving = false;
                }
            },

            /**
             * Sync GEO configuration to remote environment
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
                    console.error('Error syncing GEO config:', error);
                    this.showNotification('error', `Failed to sync to ${envName}`);
                } finally {
                    this.syncing = false;
                }
            },

            /**
             * Open the SEO sync confirmation modal. The actual sync runs
             * after the user picks their scope (admin / pages / both) and
             * clicks Sync in the modal. See executeSeoSync().
             */
            syncSeoConfig() {
                // Reset modal state to "include both" by default — the most
                // common intent for a fresh sync.
                this.seoSyncIncludeAdmin = true;
                this.seoSyncIncludePages = true;
                this.seoSyncStatus = null;
                this.showSeoSyncModal = true;
            },

            /**
             * Run the SEO sync subsets the user selected in the modal.
             *
             * - Admin: POST /seo/sync — pushes wp_ffc_seo_config rows
             *   (default OG, Twitter handles, verification codes, separator)
             * - Pages: POST /seo/sync-pages — gathers every page/post's
             *   _seo_* meta in the active template and pushes the bundle.
             *
             * Subsets fire sequentially so the status line can show progress
             * step-by-step. Either side's failure surfaces in the notification
             * but doesn't abort the other.
             */
            async executeSeoSync() {
                if (!this.seoSyncIncludeAdmin && !this.seoSyncIncludePages) return;
                this.syncingSeo = true;
                this.seoSyncStatus = null;
                const envName = this.syncEnv === 'prod' ? 'Production' : 'Live Dev';
                const results = [];

                if (this.seoSyncIncludeAdmin) {
                    this.seoSyncStatus = { type: 'admin', text: `Pushing site-wide SEO settings to ${envName}…` };
                    try {
                        const r = await fetch(geoData.apiUrl + 'seo/sync', {
                            method: 'POST',
                            headers: { 'X-WP-Nonce': geoData.nonce, 'Content-Type': 'application/json' },
                            body: JSON.stringify({ target_env: this.syncEnv })
                        });
                        const d = await r.json();
                        results.push({ label: 'Site-wide settings', success: !!d.success, message: d.message || (d.success ? 'OK' : `HTTP ${r.status}`) });
                    } catch (e) {
                        results.push({ label: 'Site-wide settings', success: false, message: e.message || 'Network error' });
                    }
                }

                if (this.seoSyncIncludePages) {
                    this.seoSyncStatus = { type: 'pages', text: `Pushing per-page SEO meta to ${envName}…` };
                    try {
                        const r = await fetch(geoData.apiUrl + 'seo/sync-pages', {
                            method: 'POST',
                            headers: { 'X-WP-Nonce': geoData.nonce, 'Content-Type': 'application/json' },
                            body: JSON.stringify({ target_env: this.syncEnv })
                        });
                        const d = await r.json();
                        results.push({
                            label: 'Per-page meta',
                            success: !!d.success,
                            message: d.message || (d.success ? 'OK' : `HTTP ${r.status}`),
                            sent: d.pages_sent,
                            applied: d.pages_applied,
                        });
                    } catch (e) {
                        results.push({ label: 'Per-page meta', success: false, message: e.message || 'Network error' });
                    }
                }

                const allOk = results.every((r) => r.success);
                this.seoSyncStatus = {
                    type: 'done',
                    text: allOk
                        ? `Synced to ${envName}: ` + results.map((r) => r.label + ' ✓').join(' · ')
                        : 'Sync completed with errors — see details below.',
                    results,
                };

                if (allOk) {
                    this.showNotification('success', this.seoSyncStatus.text);
                    // Close the modal after a short delay so the user sees the
                    // success summary before it disappears.
                    setTimeout(() => {
                        if (this.seoSyncStatus && this.seoSyncStatus.type === 'done') {
                            this.showSeoSyncModal = false;
                        }
                    }, 1800);
                } else {
                    this.showNotification('error', 'One or more SEO sync steps failed — see the modal for details.');
                }
                this.syncingSeo = false;
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
                    description: '',
                    knowsAbout: []
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
                    instagram: 'dashicons-instagram',
                    youtube: 'dashicons-video-alt3',
                    twitch: 'dashicons-format-video',
                    tiktok: 'dashicons-format-video',
                    discord: 'dashicons-format-chat',
                    bluesky: 'dashicons-twitter',
                    mastodon: 'dashicons-admin-site'
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
                    instagram: 'Instagram',
                    youtube: 'YouTube',
                    twitch: 'Twitch',
                    tiktok: 'TikTok',
                    discord: 'Discord',
                    bluesky: 'Bluesky',
                    mastodon: 'Mastodon'
                };
                return labels[platform] || platform;
            }
        }
    }).mount('#geo-admin-app');
});
