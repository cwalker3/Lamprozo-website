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
        GEO Settings
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
                    Sync GEO Data
                </h3>
            </div>
            <div class="geo-sync-content">
                <div class="geo-sync-info">
                    <p>Push your GEO configuration to remote environments. Files sync via Firefly Projects, data syncs here.</p>
                </div>
                
                <!-- Environment toggle -->
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
                
                <!-- Sync button -->
                <div class="geo-sync-actions">
                    <button type="button" 
                            class="button button-primary"
                            @click="syncConfig"
                            :disabled="syncing">
                        <span class="spinner is-active" v-if="syncing" style="float: none; margin: 0 5px 0 0;"></span>
                        <span class="dashicons dashicons-upload" v-else></span>
                        {{ syncing ? 'Syncing...' : 'Sync to ' + (syncEnv === 'prod' ? 'Production' : 'Live Dev') }}
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
        
        <!-- Tab navigation -->
        <div class="geo-tabs">
            <button v-for="tab in tabs" 
                    :key="tab.id"
                    type="button"
                    :class="['geo-tab', { active: activeTab === tab.id }]"
                    @click="setActiveTab(tab.id)">
                <span class="dashicons" :class="tab.icon"></span>
                {{ tab.label }}
            </button>
        </div>
        
        <!-- Tab content -->
        <div class="geo-content">
            
            <!-- Organization Tab -->
            <div :class="['geo-panel', { active: activeTab === 'organization' }]">
                <div class="geo-section">
                    <h3>Basic Information</h3>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="org-name">Organization Name</label>
                            <input type="text" id="org-name" v-model="config.organization.name" placeholder="Lamprozo">
                            <p class="description">Display name for your organization or brand</p>
                        </div>
                        <div class="geo-field">
                            <label for="org-legal-name">Legal Name</label>
                            <input type="text" id="org-legal-name" v-model="config.organization.legalName" placeholder="Lamprozo">
                            <p class="description">Registered legal name (or same as display name)</p>
                        </div>
                    </div>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="org-url">Website URL</label>
                            <input type="url" id="org-url" v-model="config.organization.url" placeholder="https://lamprozo.com">
                        </div>
                        <div class="geo-field">
                            <label for="org-founded">Year Founded / Started</label>
                            <input type="text" id="org-founded" v-model="config.organization.foundingDate" placeholder="2024">
                        </div>
                    </div>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="org-entity-type">Schema Entity Type</label>
                            <select id="org-entity-type" v-model="config.organization.entityType">
                                <option value="ProfessionalService">ProfessionalService</option>
                                <option value="Organization">Organization</option>
                                <option value="LocalBusiness">LocalBusiness</option>
                                <option value="Corporation">Corporation</option>
                                <option value="NGO">NGO</option>
                                <option value="EducationalOrganization">EducationalOrganization</option>
                                <option value="MediaObject">MediaObject</option>
                                <option value="Brand">Brand</option>
                                <option value="Person">Person</option>
                            </select>
                            <p class="description">Schema.org @type for your organization. Use "Person" or "Brand" for content creators.</p>
                        </div>
                        <div class="geo-field">
                            <label for="org-tagline">Tagline</label>
                            <input type="text" id="org-tagline" v-model="config.organization.tagline" placeholder="Hardcore Pokémon Nuzlockes on Twitch &amp; YouTube">
                            <p class="description">Appended to homepage title and used in meta tags</p>
                        </div>
                    </div>
                </div>
                
                <div class="geo-section">
                    <h3>Descriptions</h3>
                    <div class="geo-field">
                        <label for="org-description">Short Description</label>
                        <textarea id="org-description" v-model="config.organization.description" rows="3" placeholder="Hardcore Pokémon Nuzlocke content creator streaming on Twitch and YouTube."></textarea>
                        <p class="description">Brief description (1-2 sentences). Used in meta description and schema.</p>
                    </div>
                    <div class="geo-field">
                        <label for="org-disambiguation">Disambiguation Description</label>
                        <textarea id="org-disambiguation" v-model="config.organization.disambiguatingDescription" rows="4" placeholder="Lamprozo is a hardcore Pokémon Nuzlocker creating long-form Nuzlocke and ROM hack content. The name is derived from Lampropeltis zonata (California Mountain Kingsnake)."></textarea>
                        <p class="description">Detailed description that distinguishes you from other entities with similar names. Important for AI search engines.</p>
                    </div>
                    <div class="geo-field">
                        <label>Alternate Names</label>
                        <div v-for="(alt, idx) in config.organization.alternateNames" :key="idx" style="display:flex; gap:8px; margin-bottom:6px;">
                            <input type="text" v-model="config.organization.alternateNames[idx]" placeholder="Alternate spelling or nickname" style="flex:1;">
                            <button type="button" class="button" @click="config.organization.alternateNames.splice(idx, 1)">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                        <button type="button" class="button" @click="config.organization.alternateNames.push('')">
                            <span class="dashicons dashicons-plus-alt"></span>
                            Add Alternate Name
                        </button>
                        <p class="description">Other names people use to find you (e.g. nicknames, common misspellings, channel names).</p>
                    </div>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="org-service-catalog-name">Service Catalog Name</label>
                            <input type="text" id="org-service-catalog-name" v-model="config.organization.serviceCatalogName" placeholder="Content Offerings">
                            <p class="description">Heading shown over the services list in schema. Defaults to "Services".</p>
                        </div>
                        <div class="geo-field">
                            <label for="org-contact-description">Contact Page Description</label>
                            <input type="text" id="org-contact-description" v-model="config.organization.contactDescription" placeholder="Reach out to Lamprozo for collabs and business inquiries.">
                            <p class="description">Description shown on the contact page schema.</p>
                        </div>
                    </div>
                </div>
                
                <div class="geo-section">
                    <h3>Images</h3>
                    <div class="geo-field-row">
                        <div class="geo-field">
                            <label for="org-logo">Logo URL</label>
                            <input type="url" id="org-logo" v-model="config.organization.logo" placeholder="https://fireflycreative.co/wp-content/uploads/logo.png">
                        </div>
                        <div class="geo-field">
                            <label for="org-image">OG Image URL</label>
                            <input type="url" id="org-image" v-model="config.organization.image" placeholder="https://fireflycreative.co/wp-content/uploads/og-image.png">
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
                            <input type="text" id="loc-city" v-model="config.location.city" placeholder="Redding">
                        </div>
                        <div class="geo-field">
                            <label for="loc-state">State / Province</label>
                            <input type="text" id="loc-state" v-model="config.location.state" placeholder="California">
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
                            <input type="email" id="contact-email" v-model="config.contact.email" placeholder="info@fireflycreative.co">
                        </div>
                        <div class="geo-field">
                            <label for="contact-phone">Phone Number</label>
                            <input type="tel" id="contact-phone" v-model="config.contact.phone" placeholder="+1 (530) 555-0123">
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
                                    <input type="text" v-model="founder.name" placeholder="Alex Strait">
                                </div>
                                <div class="geo-field">
                                    <label>Job Title</label>
                                    <input type="text" v-model="founder.jobTitle" placeholder="Co-Founder & Lead Developer">
                                </div>
                            </div>
                            <div class="geo-field">
                                <label>Description / Bio</label>
                                <textarea v-model="founder.description" rows="2" placeholder="Brief bio or expertise description"></textarea>
                            </div>
                            <div class="geo-field">
                                <label>Knows About (Topics of Expertise)</label>
                                <div v-for="(topic, tIdx) in (founder.knowsAbout || [])" :key="tIdx" style="display:flex; gap:8px; margin-bottom:6px;">
                                    <input type="text" v-model="founder.knowsAbout[tIdx]" placeholder="Topic, technique, or domain" style="flex:1;">
                                    <button type="button" class="button" @click="founder.knowsAbout.splice(tIdx, 1)">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>
                                <button type="button" class="button" @click="(founder.knowsAbout = founder.knowsAbout || []).push('')">
                                    <span class="dashicons dashicons-plus-alt"></span>
                                    Add Topic
                                </button>
                                <p class="description">Subjects this person is known for. Adds the schema.org "knowsAbout" property.</p>
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
                                <input type="text" v-model="service.name" placeholder="Custom WordPress Development">
                            </div>
                            <div class="geo-field">
                                <label>Description</label>
                                <textarea v-model="service.description" rows="2" placeholder="Bespoke WordPress themes and plugins built from scratch"></textarea>
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
</div>
