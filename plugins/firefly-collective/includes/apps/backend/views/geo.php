<?php
/**
 * GEO Admin View Template
 * Vue.js-based interface for managing GEO configuration
 */

// Security check
if (!defined('ABSPATH')) {
    exit;
}

// Permission check
if (!current_user_can('manage_options')) {
    wp_die('You do not have sufficient permissions to access this page.');
}
?>

<div class="geo-admin-wrap" id="geo-admin-app" v-cloak>
    <h1>
        <span class="dashicons dashicons-visibility"></span>
        SEO/GEO Settings
    </h1>
    
    <!-- Notification -->
    <div v-if="notification.show" 
         :class="['geo-notification', notification.type]"
         @click="notification.show = false">
        {{ notification.message }}
    </div>
    
    <!-- Loading state -->
    <div v-if="loading" class="geo-loading">
        <span class="spinner is-active"></span>
        Loading configuration...
    </div>
    
    <!-- Main content -->
    <div v-else :class="{ 'geo-saving': saving }">
        <!-- Header actions -->
        <div class="geo-header-actions">
            <div class="geo-preview-toggle">
                <button type="button" 
                        class="button"
                        @click="togglePreview">
                    <span class="dashicons" :class="showPreview ? 'dashicons-hidden' : 'dashicons-visibility'"></span>
                    {{ showPreview ? 'Hide Preview' : 'Show JSON-LD Preview' }}
                </button>
            </div>
            <div class="button-group">
                <button type="button" 
                        class="button button-secondary"
                        @click="showResetModal = true">
                    <span class="dashicons dashicons-image-rotate"></span>
                    Reset to Defaults
                </button>
                <button type="button" 
                        class="button button-primary"
                        @click="saveConfig"
                        :disabled="saving">
                    <span class="spinner is-active" v-if="saving" style="float: none; margin: 0 5px 0 0;"></span>
                    <span class="dashicons dashicons-saved" v-else></span>
                    {{ saving ? 'Saving...' : 'Save Changes' }}
                </button>
            </div>
        </div>
        
        <!-- Sync section -->
        <div v-if="hasDevEndpoint" class="geo-sync-section">
            <div class="geo-sync-header">
                <h3>
                    <span class="dashicons dashicons-update"></span>
                    Sync to Remote
                </h3>
            </div>
            <div class="geo-sync-content">
                <div class="geo-sync-info">
                    <p>Push your SEO/GEO configuration to remote environments. Two parallel push flows: GEO settings (Organization, location, services) and SEO settings (default OG image, Twitter handles, verification codes).</p>
                </div>

                <!-- Environment toggle (shared between SEO + GEO sync) -->
                <div v-if="hasProdEndpoint" class="geo-env-toggle">
                    <label class="geo-toggle-label">Target Environment:</label>
                    <div class="geo-toggle-switch">
                        <button type="button"
                                :class="['geo-toggle-btn', { active: syncEnv === 'dev' }]"
                                @click="syncEnv = 'dev'">
                            <span class="dashicons dashicons-desktop"></span>
                            Live Dev
                            <small v-if="devSite">({{ devSite }})</small>
                        </button>
                        <button type="button"
                                :class="['geo-toggle-btn', { active: syncEnv === 'prod' }]"
                                @click="syncEnv = 'prod'">
                            <span class="dashicons dashicons-admin-site-alt3"></span>
                            Production
                            <small v-if="prodSite">({{ prodSite }})</small>
                        </button>
                    </div>
                </div>

                <!-- Sync buttons (parallel: SEO + GEO) -->
                <div class="geo-sync-actions">
                    <button type="button"
                            class="button button-primary"
                            @click="syncSeoConfig"
                            :disabled="syncingSeo || syncing">
                        <span class="spinner is-active" v-if="syncingSeo" style="float: none; margin: 0 5px 0 0;"></span>
                        <span class="dashicons dashicons-upload" v-else></span>
                        {{ syncingSeo ? 'Syncing SEO...' : 'Sync SEO to ' + (syncEnv === 'prod' ? 'Production' : 'Live Dev') }}
                    </button>
                    <button type="button"
                            class="button button-primary"
                            @click="syncConfig"
                            :disabled="syncing || syncingSeo">
                        <span class="spinner is-active" v-if="syncing" style="float: none; margin: 0 5px 0 0;"></span>
                        <span class="dashicons dashicons-upload" v-else></span>
                        {{ syncing ? 'Syncing GEO...' : 'Sync GEO to ' + (syncEnv === 'prod' ? 'Production' : 'Live Dev') }}
                    </button>
                    <span v-if="!hasProdEndpoint" class="geo-sync-note">
                        <span class="dashicons dashicons-info"></span>
                        Syncing to Live Dev ({{ devSite }}). Add PROD_ENDPOINT to enable Production sync.
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Preview panel -->
        <div v-if="showPreview" class="geo-preview-panel">
            <div class="geo-preview-header">
                <h3>
                    <span class="dashicons dashicons-editor-code"></span>
                    JSON-LD Schema Preview
                </h3>
                <button type="button" class="button button-small" @click="generatePreview">
                    <span class="dashicons dashicons-update"></span>
                    Refresh
                </button>
            </div>
            <div class="geo-preview-content">
                <div v-if="previewLoading" class="geo-preview-loading">
                    <span class="spinner is-active"></span>
                    Generating preview...
                </div>
                <pre v-else v-html="previewJson"></pre>
            </div>
        </div>
        
        <!-- Tab navigation, split into two visually distinct groups:
             SEO (site-wide defaults) on the left, GEO (structured-data
             organization profile) on the right. The divider + section
             labels make it obvious which settings affect which domain. -->
        <div class="geo-tabs geo-tabs-split">
            <div class="geo-tabs-group geo-tabs-group-seo">
                <span class="geo-tabs-group-label">SEO</span>
                <button v-for="tab in tabs.filter(t => t.id === 'seo')"
                        :key="tab.id"
                        type="button"
                        :class="['geo-tab', 'geo-tab-seo', { active: activeTab === tab.id }]"
                        @click="setActiveTab(tab.id)">
                    <span class="dashicons" :class="tab.icon"></span>
                    {{ tab.label }}
                </button>
            </div>
            <div class="geo-tabs-divider" aria-hidden="true"></div>
            <div class="geo-tabs-group geo-tabs-group-geo">
                <span class="geo-tabs-group-label">GEO</span>
                <button v-for="tab in tabs.filter(t => t.id !== 'seo')"
                        :key="tab.id"
                        type="button"
                        :class="['geo-tab', 'geo-tab-geo', { active: activeTab === tab.id }]"
                        @click="setActiveTab(tab.id)">
                    <span class="dashicons" :class="tab.icon"></span>
                    {{ tab.label }}
                </button>
            </div>
        </div>
        
        <!-- Tab content -->
        <div class="geo-content">

            <!-- SEO Tab — site-wide defaults that compose with per-page _seo_* meta.
                 Editable values land in wp_ffc_seo_config; the theme's seo-meta.php
                 reads them via firefly_get_seo_setting() at wp_head time. -->
            <div :class="['geo-panel', 'geo-panel-seo', { active: activeTab === 'seo' }]">
                <div class="geo-panel-banner">
                    <span class="dashicons dashicons-search"></span>
                    <span><strong>SEO settings</strong> — site-wide defaults applied to every page. Per-page overrides live in each post's Gutenberg SEO panel.</span>
                </div>

                <h3>Defaults</h3>
                <div class="geo-field">
                    <label>Default OG image URL</label>
                    <input type="url" v-model="seoConfig.defaults.og_image_url" placeholder="https://example.com/og.png">
                    <p class="description">Used when a page has no <code>_seo_og_image_id</code> override and no featured image. Leave blank to fall back to the active template's <code>default-og.webp</code>.</p>
                </div>
                <div class="geo-field">
                    <label>Title separator</label>
                    <input type="text" v-model="seoConfig.defaults.title_separator" maxlength="12" placeholder=" - " style="width:120px;">
                    <p class="description">Joins site name + page title in the &lt;title&gt; tag (e.g. <code> - </code>, <code> | </code>, <code> · </code>). Whitespace is preserved.</p>
                </div>

                <h3>Twitter / X Card</h3>
                <div class="geo-field">
                    <label>Site handle</label>
                    <input type="text" v-model="seoConfig.twitter.site_handle" placeholder="@yoursite">
                    <p class="description">Emitted as <code>twitter:site</code>. The <code>@</code> is added automatically if missing.</p>
                </div>
                <div class="geo-field">
                    <label>Creator handle</label>
                    <input type="text" v-model="seoConfig.twitter.creator_handle" placeholder="@author">
                    <p class="description">Optional. Emitted as <code>twitter:creator</code> when set.</p>
                </div>
                <div class="geo-field">
                    <label>Card type</label>
                    <select v-model="seoConfig.twitter.card_type">
                        <option v-for="opt in twitterCardOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                    <p class="description">Drives how social-card previews render. <em>Summary with large image</em> is the right pick for most marketing sites.</p>
                </div>

                <h3>Facebook</h3>
                <div class="geo-field">
                    <label>Facebook App ID</label>
                    <input type="text" v-model="seoConfig.facebook.app_id" placeholder="1234567890123456">
                    <p class="description">Optional. Emitted as <code>fb:app_id</code>. Required only if you want Facebook Insights tracking — eliminates the FB Sharing Debugger warning. Leave blank otherwise.</p>
                </div>

                <h3>Search-engine verification</h3>
                <p class="description geo-panel-description">Paste the verification code each provider gives you. Only providers with a non-empty code emit a meta tag.</p>
                <div class="geo-field">
                    <label>Google Search Console</label>
                    <input type="text" v-model="seoConfig.verification.google" placeholder="abc123-google-verification-token">
                </div>
                <div class="geo-field">
                    <label>Bing Webmaster Tools</label>
                    <input type="text" v-model="seoConfig.verification.bing" placeholder="msvalidate.01 token">
                </div>
                <div class="geo-field">
                    <label>Yandex Webmaster</label>
                    <input type="text" v-model="seoConfig.verification.yandex" placeholder="yandex-verification token">
                </div>
                <div class="geo-field">
                    <label>Pinterest</label>
                    <input type="text" v-model="seoConfig.verification.pinterest" placeholder="p:domain_verify token">
                </div>
                <div class="geo-field">
                    <label>Baidu</label>
                    <input type="text" v-model="seoConfig.verification.baidu" placeholder="baidu-site-verification token">
                </div>

                <h3>Default robots policy</h3>
                <p class="description geo-panel-description">These are the site-wide defaults; per-page toggles can override them. Dev / localhost always forces <code>noindex,nofollow</code> regardless.</p>
                <div class="geo-field geo-field-inline">
                    <label>
                        <input type="checkbox" v-model="seoConfig.robots.default_index">
                        Allow search engines to index pages by default
                    </label>
                </div>
                <div class="geo-field geo-field-inline">
                    <label>
                        <input type="checkbox" v-model="seoConfig.robots.default_follow">
                        Allow search engines to follow links by default
                    </label>
                </div>
            </div>

            <!-- Organization Tab -->
            <div :class="['geo-panel', { active: activeTab === 'organization' }]">
                <div class="geo-section">
                    <h3>Basic Information</h3>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="org-name">Organization Name</label>
                            <input type="text" id="org-name" v-model="config.organization.name" placeholder="Your Business Name">
                            <p class="description">Display name for your organization</p>
                        </div>
                        <div class="geo-field">
                            <label for="org-legal-name">Legal Name</label>
                            <input type="text" id="org-legal-name" v-model="config.organization.legalName" placeholder="Your Business, LLC">
                            <p class="description">Registered business name</p>
                        </div>
                    </div>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="org-url">Website URL</label>
                            <input type="url" id="org-url" v-model="config.organization.url" placeholder="https://yourbusiness.com">
                        </div>
                        <div class="geo-field">
                            <label for="org-founded">Year Founded</label>
                            <input type="text" id="org-founded" v-model="config.organization.foundingDate" placeholder="2022">
                        </div>
                    </div>
                </div>
                
                <div class="geo-section">
                    <h3>Descriptions</h3>
                    <div class="geo-field">
                        <label for="org-description">Short Description</label>
                        <textarea id="org-description" v-model="config.organization.description" rows="3" placeholder="A short sentence about what your business does and who it serves."></textarea>
                        <p class="description">Brief description of your organization (1-2 sentences)</p>
                    </div>
                    <div class="geo-field">
                        <label for="org-disambiguation">Disambiguation Description</label>
                        <textarea id="org-disambiguation" v-model="config.organization.disambiguatingDescription" rows="4" placeholder="A detailed description that sets your business apart from others with a similar name."></textarea>
                        <p class="description">Detailed description that distinguishes you from other entities with similar names. Important for AI search engines.</p>
                    </div>
                </div>
                
                <div class="geo-section">
                    <h3>Images</h3>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="org-logo">Logo URL</label>
                            <input type="url" id="org-logo" v-model="config.organization.logo" placeholder="https://yourbusiness.com/logo.png">
                        </div>
                        <div class="geo-field">
                            <label for="org-image">OG Image URL</label>
                            <input type="url" id="org-image" v-model="config.organization.image" placeholder="https://yourbusiness.com/og-image.png">
                            <p class="description">Social sharing image (1200x630px recommended)</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Location Tab -->
            <div :class="['geo-panel', { active: activeTab === 'location' }]">
                <div class="geo-section">
                    <h3>Business Location</h3>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="loc-city">City</label>
                            <input type="text" id="loc-city" v-model="config.location.city" placeholder="City">
                        </div>
                        <div class="geo-field">
                            <label for="loc-state">State / Province</label>
                            <input type="text" id="loc-state" v-model="config.location.state" placeholder="State / Province">
                        </div>
                    </div>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="loc-state-code">State Code</label>
                            <input type="text" id="loc-state-code" v-model="config.location.stateCode" placeholder="CA" maxlength="2" style="max-width: 100px;">
                            <p class="description">2-letter state/province code</p>
                        </div>
                        <div class="geo-field">
                            <label for="loc-country">Country</label>
                            <input type="text" id="loc-country" v-model="config.location.country" placeholder="United States">
                        </div>
                        <div class="geo-field">
                            <label for="loc-country-code">Country Code</label>
                            <input type="text" id="loc-country-code" v-model="config.location.countryCode" placeholder="US" maxlength="2" style="max-width: 100px;">
                            <p class="description">2-letter country code (ISO 3166-1)</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Contact Tab -->
            <div :class="['geo-panel', { active: activeTab === 'contact' }]">
                <div class="geo-section">
                    <h3>Contact Information</h3>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="contact-email">Email Address</label>
                            <input type="email" id="contact-email" v-model="config.contact.email" placeholder="info@yourbusiness.com">
                        </div>
                        <div class="geo-field">
                            <label for="contact-phone">Phone Number</label>
                            <input type="tel" id="contact-phone" v-model="config.contact.phone" placeholder="+1 (555) 123-4567">
                            <p class="description">Include country code for international format</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Founders Tab -->
            <div :class="['geo-panel', { active: activeTab === 'founders' }]">
                <div class="geo-section">
                    <h3>Team Members / Founders</h3>
                    <p class="description" style="margin-bottom: 20px;">Add founders or key team members. These appear in the organization schema.</p>
                    
                    <div class="geo-list">
                        <div v-for="(founder, index) in config.founders" :key="index" class="geo-list-item">
                            <div class="geo-list-item-header">
                                <h4>Founder {{ index + 1 }}</h4>
                                <button type="button" class="remove-btn" @click="removeFounder(index)" title="Remove">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </div>
                            <div class="geo-field-row">
                                <div class="geo-field">
                                    <label>Full Name</label>
                                    <input type="text" v-model="founder.name" placeholder="Full name">
                                </div>
                                <div class="geo-field">
                                    <label>Job Title</label>
                                    <input type="text" v-model="founder.jobTitle" placeholder="Job title">
                                </div>
                            </div>
                            <div class="geo-field">
                                <label>Description / Bio</label>
                                <textarea v-model="founder.description" rows="2" placeholder="Brief bio or expertise description"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" class="geo-add-btn" @click="addFounder">
                        <span class="dashicons dashicons-plus-alt"></span>
                        Add Founder
                    </button>
                </div>
            </div>
            
            <!-- Services Tab -->
            <div :class="['geo-panel', { active: activeTab === 'services' }]">
                <div class="geo-section">
                    <h3>Services Offered</h3>
                    <p class="description" style="margin-bottom: 20px;">List your main services. These appear in the organization's service catalog schema.</p>
                    
                    <div class="geo-list">
                        <div v-for="(service, index) in config.services" :key="index" class="geo-list-item">
                            <div class="geo-list-item-header">
                                <h4>Service {{ index + 1 }}</h4>
                                <button type="button" class="remove-btn" @click="removeService(index)" title="Remove">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </div>
                            <div class="geo-field">
                                <label>Service Name</label>
                                <input type="text" v-model="service.name" placeholder="Service name">
                            </div>
                            <div class="geo-field">
                                <label>Description</label>
                                <textarea v-model="service.description" rows="2" placeholder="What this service includes"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" class="geo-add-btn" @click="addService">
                        <span class="dashicons dashicons-plus-alt"></span>
                        Add Service
                    </button>
                </div>
            </div>
            
            <!-- Social Tab -->
            <div :class="['geo-panel', { active: activeTab === 'social' }]">
                <div class="geo-section">
                    <h3>Social Media Profiles</h3>
                    <p class="description" style="margin-bottom: 20px;">Add your social media profile URLs. These create "sameAs" links in the schema, helping AI engines verify your identity.</p>
                    
                    <div v-for="(url, platform) in config.social" :key="platform" class="geo-field">
                        <label :for="'social-' + platform">{{ getSocialLabel(platform) }}</label>
                        <div class="geo-social-field">
                            <span class="social-icon">
                                <span class="dashicons" :class="getSocialIcon(platform)"></span>
                            </span>
                            <input type="url" 
                                   :id="'social-' + platform" 
                                   v-model="config.social[platform]" 
                                   :placeholder="'https://' + platform + '.com/your-profile'">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Industry Tab -->
            <div :class="['geo-panel', { active: activeTab === 'industry' }]">
                <div class="geo-section">
                    <h3>Industry Classification</h3>
                    <p class="description" style="margin-bottom: 20px;">Industry codes help AI engines categorize your business correctly.</p>
                    
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="industry-naics">NAICS Code</label>
                            <input type="text" id="industry-naics" v-model="config.industry.naics" placeholder="541511">
                            <p class="description">North American Industry Classification System. Example: 541511 = Custom Computer Programming Services</p>
                        </div>
                        <div class="geo-field">
                            <label for="industry-isic">ISIC v4 Code</label>
                            <input type="text" id="industry-isic" v-model="config.industry.isicV4" placeholder="6201">
                            <p class="description">International Standard Industrial Classification. Example: 6201 = Computer programming activities</p>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
        
        <!-- Bottom save button -->
        <div class="geo-actions">
            <span v-if="hasChanges" style="color: #d63638;">
                <span class="dashicons dashicons-warning"></span>
                You have unsaved changes
            </span>
            <span v-else></span>
            <button type="button" 
                    class="button button-primary button-large"
                    @click="saveConfig"
                    :disabled="saving">
                <span class="spinner is-active" v-if="saving" style="float: none; margin: 0 5px 0 0;"></span>
                <span class="dashicons dashicons-saved" v-else></span>
                {{ saving ? 'Saving...' : 'Save Changes' }}
            </button>
        </div>
    </div>
    
    <!-- Reset confirmation modal -->
    <div v-if="showResetModal" class="geo-modal-overlay" @click.self="showResetModal = false">
        <div class="geo-modal">
            <h3>
                <span class="dashicons dashicons-warning"></span>
                Reset to Defaults?
            </h3>
            <p>This will reset all GEO settings to their default values. This action cannot be undone.</p>
            <div class="geo-modal-actions">
                <button type="button" class="button" @click="showResetModal = false">Cancel</button>
                <button type="button" class="button button-secondary" @click="resetConfig">Reset</button>
            </div>
        </div>
    </div>

    <!-- SEO sync confirmation modal — explains what "SEO sync" covers and lets
         the user choose which subset(s) to push. Two checkboxes default to
         checked; either can be unchecked to skip that subset. Sync button is
         disabled when both are unchecked. -->
    <div v-if="showSeoSyncModal" class="geo-modal-overlay" @click.self="!syncingSeo && (showSeoSyncModal = false)">
        <div class="geo-modal geo-modal-wide geo-modal-sync">
            <h3>
                <span class="dashicons dashicons-upload"></span>
                Sync SEO to {{ syncEnv === 'prod' ? 'Production' : 'Live Dev' }}
            </h3>
            <p class="geo-modal-lead">
                An SEO sync pushes <strong>two kinds of data</strong> to the remote environment.
                Choose what to include:
            </p>

            <div class="geo-sync-choices">
                <label class="geo-sync-choice" :class="{ 'is-checked': seoSyncIncludeAdmin, 'is-disabled': syncingSeo }">
                    <input type="checkbox" v-model="seoSyncIncludeAdmin" :disabled="syncingSeo">
                    <span class="geo-sync-choice-body">
                        <span class="geo-sync-choice-title">
                            <span class="dashicons dashicons-admin-settings"></span>
                            Site-wide SEO settings
                        </span>
                        <span class="geo-sync-choice-desc">
                            Default OG image, Twitter handles, search-engine verification codes,
                            title separator, and default robots policy — everything from the SEO
                            tab of this admin page.
                        </span>
                        <span class="geo-sync-choice-endpoint">→ <code>/seo/receive</code></span>
                    </span>
                </label>

                <label class="geo-sync-choice" :class="{ 'is-checked': seoSyncIncludePages, 'is-disabled': syncingSeo }">
                    <input type="checkbox" v-model="seoSyncIncludePages" :disabled="syncingSeo">
                    <span class="geo-sync-choice-body">
                        <span class="geo-sync-choice-title">
                            <span class="dashicons dashicons-admin-page"></span>
                            Per-page SEO overrides
                        </span>
                        <span class="geo-sync-choice-desc">
                            Every page and post in the active template — their SEO title,
                            meta description, canonical, robots toggles, and Open Graph
                            overrides set in each post's Gutenberg sidebar.
                        </span>
                        <span class="geo-sync-choice-endpoint">→ <code>/seo/receive-pages</code></span>
                    </span>
                </label>
            </div>

            <!-- Live status / results -->
            <div v-if="seoSyncStatus" class="geo-sync-status" :class="'is-' + seoSyncStatus.type">
                <span v-if="seoSyncStatus.type !== 'done'" class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
                <span>{{ seoSyncStatus.text }}</span>
                <ul v-if="seoSyncStatus.results" class="geo-sync-results">
                    <li v-for="(r, idx) in seoSyncStatus.results" :key="idx" :class="r.success ? 'is-success' : 'is-failure'">
                        <span class="dashicons" :class="r.success ? 'dashicons-yes-alt' : 'dashicons-warning'"></span>
                        <strong>{{ r.label }}:</strong>
                        <span>{{ r.message }}</span>
                        <small v-if="r.sent != null"> ({{ r.applied != null ? (r.applied + '/' + r.sent + ' pages applied') : (r.sent + ' pages sent') }})</small>
                    </li>
                </ul>
            </div>

            <div class="geo-modal-actions">
                <button type="button" class="button" @click="showSeoSyncModal = false" :disabled="syncingSeo">
                    {{ seoSyncStatus && seoSyncStatus.type === 'done' ? 'Close' : 'Cancel' }}
                </button>
                <button type="button"
                        class="button button-primary"
                        @click="executeSeoSync"
                        :disabled="syncingSeo || (!seoSyncIncludeAdmin && !seoSyncIncludePages)">
                    <span v-if="syncingSeo" class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span>
                    <span v-else class="dashicons dashicons-upload"></span>
                    {{ syncingSeo ? 'Syncing…' : ('Sync to ' + (syncEnv === 'prod' ? 'Production' : 'Live Dev')) }}
                </button>
            </div>
        </div>
    </div>
</div>
