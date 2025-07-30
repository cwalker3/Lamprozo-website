<?php

    // plugin/views/campaign.php

    global $currentUserIdAdmin;
    $currentUserIdAdmin = current_user_can('manage_options');

    if (!$currentUserIdAdmin) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    // Get features for configuration
    $features_options_addons = get_features_options_addons();

    // Ensure features are loaded
    if (empty($features_options_addons)) {
        ?>
        <div class="notice notice-error">
            <p>No features found. Please configure features in the Pricing section first.</p>
        </div>
        <?php
        return;
    }

?>

<div class="wrap" id="ffc-campaign-app" v-cloak>
    <h1>Campaign Management</h1>
    
    <!-- Loading State -->
    <div v-if="loading" class="ffc-loading">
        <div class="spinner is-active"></div>
        <p>Loading campaigns...</p>
    </div>
    
    <!-- Create Campaign Form -->
    <div class="ffc-campaign-form-container">
        <button v-if="!showCreateForm" @click="showCreateForm = true" class="button button-primary">
            + New Campaign
        </button>
        
        <div v-if="showCreateForm" class="ffc-campaign-form">
            <h3>{{ editingCampaign ? 'Edit Campaign' : 'Create New Campaign' }}</h3>
            
            <div class="form-grid">
                <div class="form-field">
                    <label>Campaign Name</label>
                    <input type="text" v-model="campaignForm.name" placeholder="Summer Sale 2024">
                </div>
                
                <div class="form-field">
                    <label>Start Date</label>
                    <input type="datetime-local" v-model="campaignForm.start_date">
                </div>
                
                <div class="form-field">
                    <label>End Date</label>
                    <input type="datetime-local" v-model="campaignForm.end_date" :disabled="campaignForm.unlimited">
                </div>
                
                <div class="form-field checkbox-field">
                    <span>
                        <input type="checkbox" v-model="campaignForm.unlimited">
                        Unlimited Campaign
                    </span>
                </div>
            </div>
            
            <!-- Features Configuration -->
            <div class="config-section">
                <h4>Features to Show</h4>
                <div class="features-config">
                    <div v-for="(feature, fIndex) in features" :key="'feature-' + fIndex" class="feature-config">
                        <span class="feature-toggle">
                            <input type="checkbox" v-model="campaignForm.features_config[feature.id].show">
                            <strong>{{ feature.featureName }}</strong>
                        </span>
                        
                        <div v-if="campaignForm.features_config[feature.id].show" class="options-config">
                            <p class="config-label">Options to show:</p>
                            <div v-for="(option, oIndex) in feature.options" :key="'option-' + oIndex" class="option-config">
                                <span>
                                    <input type="checkbox" v-model="campaignForm.features_config[feature.id].options[option.id].show">
                                    {{ option.optionName }}
                                </span>
                                
                                <div v-if="campaignForm.features_config[feature.id].options[option.id].show && option.addons && option.addons.length" class="addons-config">
                                    <p class="config-label">Addons to show:</p>
                                    <span v-for="(addon, aIndex) in option.addons" :key="'addon-' + aIndex">
                                        <input type="checkbox" v-model="campaignForm.features_config[feature.id].options[option.id].addons[addon.id]">
                                        {{ addon.addonName }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Preselect Configuration -->
            <div class="config-section">
                <h4>Preselected Items</h4>
                <div class="preselect-config">
                    <template v-for="(feature, fIndex) in features" :key="'preselect-' + fIndex">
                        <div v-if="campaignForm.features_config[feature.id] && campaignForm.features_config[feature.id].show" 
                            class="feature-preselect">
                            <label class="feature-label">
                                <strong>{{ feature.featureName }}</strong>
                            </label>
                            
                            <select v-model="campaignForm.preselect_config[feature.id].selectedOption" 
                                    @change="onPreselectOptionChange(feature.id)"
                                    class="option-select">
                                <option value="">No preselection</option>
                                <template v-for="(option, oIndex) in feature.options" :key="'pre-option-' + oIndex">
                                    <option v-if="campaignForm.features_config[feature.id].options[option.id] && campaignForm.features_config[feature.id].options[option.id].show"
                                            :value="option.id">
                                        {{ option.optionName }}
                                    </option>
                                </template>
                            </select>
                            
                            <div v-if="campaignForm.preselect_config[feature.id].selectedOption" class="addon-preselect">
                                <p class="config-label">Preselect addons:</p>
                                <div v-for="addon in getPreselectableAddons(feature)" :key="'pre-addon-' + addon.id" class="addon-checkbox">
                                    <span>
                                        <input type="checkbox" 
                                            :value="addon.id"
                                            :checked="campaignForm.preselect_config[feature.id].selectedAddons.includes(addon.id)"
                                            @change="togglePreselectAddon(feature.id, addon.id)">
                                        {{ addon.addonName }}
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Show quantity for non-recurring features -->
                            <div v-if="!feature.recurring && campaignForm.preselect_config[feature.id].selectedOption" 
                                class="quantity-preselect">
                                <label>
                                    <strong>Quantity:</strong>
                                    <input type="number" 
                                        v-model.number="campaignForm.preselect_config[feature.id].quantity"
                                        min="1" 
                                        placeholder="1">
                                </label>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            
            <div class="form-actions">
                <button @click="saveCampaign" class="button button-primary">
                    {{ editingCampaign ? 'Update' : 'Create' }} Campaign
                </button>
                <button @click="cancelForm" class="button">Cancel</button>
            </div>
        </div>
    </div>
    
    <!-- Campaigns List -->
    <div v-if="!loading && campaigns.length > 0" class="ffc-campaigns-list">
        <h3>Active Campaigns</h3>
        
        <div v-for="campaign in campaigns" :key="campaign.id" class="campaign-item" :class="{ expanded: expandedCampaigns.includes(campaign.id) }">
            <div class="campaign-header" @click="toggleExpand(campaign.id)">
                <div class="campaign-info">
                    <strong>{{ campaign.name }}</strong>
                    <span class="campaign-dates">
                        {{ formatDate(campaign.start_date) }} - 
                        {{ campaign.unlimited ? 'Unlimited' : formatDate(campaign.end_date) }}
                    </span>
                </div>
                <div class="campaign-actions">
                    <span class="expand-icon">{{ expandedCampaigns.includes(campaign.id) ? '▼' : '►' }}</span>
                </div>
            </div>
            
            <div v-if="expandedCampaigns.includes(campaign.id)" class="campaign-details">
                <div class="campaign-content">
                    <div class="campaign-links">
                        <p><a :href="campaign.dashboard_url" target="_blank">Campaign URL</a></p>
                        <p><strong>Token:</strong> {{ campaign.token }}</p>
                    </div>
                    
                    <div v-if="campaign.qr_url" class="campaign-qr">
                        <img :src="campaign.qr_url" alt="QR Code" style="max-width: 200px;">
                        <br>
                        <a :href="campaign.qr_url" download class="button button-small">Download QR Code</a>
                    </div>
                    
                    <div class="campaign-config-summary">
                        <h4>Configuration Summary</h4>
                        <p><strong>Features Shown:</strong> {{ getFeaturesSummary(campaign) }}</p>
                        <div><strong>Preselected Items:</strong><br><span v-html="getPreselectSummary(campaign)"></span></div>
                    </div>
                </div>
                
                <div class="campaign-detail-actions">
                    <button @click="editCampaign(campaign)" class="button">Edit</button>
                    <button @click="deleteCampaign(campaign.id)" class="button button-link-delete">Delete</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- No Campaigns Message -->
    <div v-if="!loading && campaigns.length === 0" class="ffc-no-campaigns">
        <p>No campaigns found. Create your first campaign to get started!</p>
    </div>
</div>

<script>
    window.campaignFeatures = <?php echo json_encode($features_options_addons); ?>;
</script>