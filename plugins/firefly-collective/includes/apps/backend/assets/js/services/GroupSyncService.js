// services/GroupSyncService.js

/**
 * Service for managing addon group synchronization
 */
export class GroupSyncService {
    constructor(eventBus, dataManager) {
        this.eventBus = eventBus;
        this.dataManager = dataManager;
        this.groupThresholdUIRegistry = new Map();
        this.groupMaxItemsUIRegistry = new Map();
        
        // Subscribe to relevant events
        this.eventBus.on('addonGroupChanged', this.handleGroupChange.bind(this));
        this.eventBus.on('thresholdDiscountsChanged', this.handleThresholdChange.bind(this));
        this.eventBus.on('maxGroupItemsChanged', this.handleMaxItemsChange.bind(this));
    }

    /**
     * Register threshold discounts UI for a group
     * @param {string} groupName - Group name
     * @param {number} featureIdx - Feature index
     * @param {number} optionIdx - Option index
     * @param {number} addonIdx - Addon index
     * @param {HTMLElement} uiElement - UI element
     * @param {Function} renderFunction - Function to re-render the UI
     */
    registerThresholdUI(groupName, featureIdx, optionIdx, addonIdx, uiElement, renderFunction) {
        if (!groupName || !groupName.trim()) return;

        if (!this.groupThresholdUIRegistry.has(groupName)) {
            this.groupThresholdUIRegistry.set(groupName, []);
        }

        // Remove existing registration for this addon
        const registrations = this.groupThresholdUIRegistry.get(groupName);
        const filtered = registrations.filter(ui => 
            !(ui.featureIdx === featureIdx && 
              ui.optionIdx === optionIdx && 
              ui.addonIdx === addonIdx));

        // Add new registration
        filtered.push({
            featureIdx,
            optionIdx,
            addonIdx,
            uiElement,
            renderFunction
        });

        this.groupThresholdUIRegistry.set(groupName, filtered);
    }

    /**
     * Register max group items UI for a group
     * @param {string} groupName - Group name
     * @param {number} featureIdx - Feature index
     * @param {number} optionIdx - Option index
     * @param {number} addonIdx - Addon index
     * @param {Function} renderFunction - Function to re-render the UI
     */
    registerMaxItemsUI(groupName, featureIdx, optionIdx, addonIdx, renderFunction) {
        if (!groupName || !groupName.trim()) return;

        if (!this.groupMaxItemsUIRegistry.has(groupName)) {
            this.groupMaxItemsUIRegistry.set(groupName, []);
        }

        // Remove existing registration for this addon
        const registrations = this.groupMaxItemsUIRegistry.get(groupName);
        const filtered = registrations.filter(ui => 
            !(ui.featureIdx === featureIdx && 
              ui.optionIdx === optionIdx && 
              ui.addonIdx === addonIdx));

        // Add new registration
        filtered.push({
            featureIdx,
            optionIdx,
            addonIdx,
            renderFunction
        });

        this.groupMaxItemsUIRegistry.set(groupName, filtered);
    }

    /**
     * Synchronize threshold discounts across all addons in a group
     * @param {number} currentFeatureIdx - Current addon feature index
     * @param {number} currentOptionIdx - Current addon option index
     * @param {number} currentAddonIdx - Current addon index
     * @param {string} groupName - Group name
     * @param {Array} newDiscounts - New threshold discounts data
     * @param {string} preserveFocusId - Element ID to preserve focus on
     * @param {boolean} forceUpdateCurrent - Whether to update the triggering element
     */
    synchronizeThresholdDiscounts(currentFeatureIdx, currentOptionIdx, currentAddonIdx, 
                                  groupName, newDiscounts, preserveFocusId = null, 
                                  forceUpdateCurrent = false) {
        if (!groupName || !groupName.trim()) return;

        const discountsCopy = JSON.parse(JSON.stringify(newDiscounts));
        const data = this.dataManager.getData();
        let updateCount = 0;

        // Update data structures
        data.features.forEach((feature, featureIdx) => {
            feature.options.forEach((option, optionIdx) => {
                option.addons.forEach((addon, addonIdx) => {
                    if (addon.groupName === groupName) {
                        updateCount++;

                        // Skip current addon unless forced
                        if (!forceUpdateCurrent && 
                            featureIdx === currentFeatureIdx && 
                            optionIdx === currentOptionIdx && 
                            addonIdx === currentAddonIdx) {
                            return;
                        }

                        // Update the data structure
                        if (!addon.groupThresholdDiscounts) {
                            addon.groupThresholdDiscounts = {
                                level: 'admin',
                                ui_type: 'array-obj',
                                value: { types: JSON.parse(JSON.stringify(discountsCopy)) }
                            };
                        } else if (typeof addon.groupThresholdDiscounts === 'string') {
                            addon.groupThresholdDiscounts = {
                                level: 'admin',
                                ui_type: 'array-obj',
                                value: { types: JSON.parse(JSON.stringify(discountsCopy)) }
                            };
                        } else {
                            addon.groupThresholdDiscounts.value = {
                                types: JSON.parse(JSON.stringify(discountsCopy))
                            };
                        }
                    }
                });
            });
        });

        // Save data if we made updates
        if (updateCount > 0) {
            this.dataManager.save();
        }

        // Update UI elements
        this.updateThresholdUIs(groupName, currentFeatureIdx, currentOptionIdx, 
                               currentAddonIdx, discountsCopy, preserveFocusId, forceUpdateCurrent);
    }

    /**
     * Synchronize max group items across all addons in a group
     * @param {number} currentFeatureIdx - Current addon feature index
     * @param {number} currentOptionIdx - Current addon option index
     * @param {number} currentAddonIdx - Current addon index
     * @param {string} groupName - Group name
     * @param {number} maxGroupItems - New max group items value
     */
    synchronizeMaxGroupItems(currentFeatureIdx, currentOptionIdx, currentAddonIdx, 
                            groupName, maxGroupItems) {
        if (!groupName || !groupName.trim()) return;

        const data = this.dataManager.getData();

        // Update data structures
        data.features.forEach((feature, featureIdx) => {
            feature.options.forEach((option, optionIdx) => {
                option.addons.forEach((addon, addonIdx) => {
                    if (addon.groupName === groupName) {
                        // Skip current addon
                        if (featureIdx === currentFeatureIdx && 
                            optionIdx === currentOptionIdx && 
                            addonIdx === currentAddonIdx) {
                            return;
                        }

                        addon.maxGroupItems = maxGroupItems;
                    }
                });
            });
        });

        this.dataManager.save();

        // Update UI elements
        this.updateMaxItemsUIs(groupName, currentFeatureIdx, currentOptionIdx, 
                              currentAddonIdx, maxGroupItems);
    }

    /**
     * Update threshold UI elements for a group
     * @private
     */
    updateThresholdUIs(groupName, currentFeatureIdx, currentOptionIdx, currentAddonIdx, 
                      discountsCopy, preserveFocusId, forceUpdateCurrent) {
        const registrations = this.groupThresholdUIRegistry.get(groupName);
        if (!registrations) return;

        registrations.forEach(uiInfo => {
            // Skip current addon unless forced
            if (!forceUpdateCurrent && 
                uiInfo.featureIdx === currentFeatureIdx && 
                uiInfo.optionIdx === currentOptionIdx && 
                uiInfo.addonIdx === currentAddonIdx) {
                return;
            }

            // Update UI if element still exists and has render function
            if (uiInfo.uiElement && 
                uiInfo.uiElement.parentNode && 
                uiInfo.renderFunction) {
                try {
                    requestAnimationFrame(() => {
                        uiInfo.renderFunction(discountsCopy, preserveFocusId);
                    });
                } catch (error) {
                    console.error('Error updating threshold UI:', error);
                }
            }
        });
    }

    /**
     * Update max items UI elements for a group
     * @private
     */
    updateMaxItemsUIs(groupName, currentFeatureIdx, currentOptionIdx, 
                     currentAddonIdx, maxGroupItems) {
        const registrations = this.groupMaxItemsUIRegistry.get(groupName);
        if (!registrations) return;

        registrations.forEach(uiInfo => {
            // Skip current addon
            if (uiInfo.featureIdx === currentFeatureIdx && 
                uiInfo.optionIdx === currentOptionIdx && 
                uiInfo.addonIdx === currentAddonIdx) {
                return;
            }

            if (uiInfo.renderFunction) {
                try {
                    requestAnimationFrame(() => {
                        uiInfo.renderFunction(maxGroupItems);
                    });
                } catch (error) {
                    console.error('Error updating max items UI:', error);
                }
            }
        });
    }

    /**
     * Get all existing group names for autocomplete
     * @param {number} excludeFeatureIdx - Feature to exclude
     * @param {number} excludeOptionIdx - Option to exclude
     * @returns {Array} Array of group names with templates
     */
    getExistingGroups(excludeFeatureIdx = -1, excludeOptionIdx = -1) {
        const data = this.dataManager.getData();
        const groups = new Map();

        data.features.forEach((feature, featureIdx) => {
            if (featureIdx === excludeFeatureIdx) return;

            feature.options.forEach((option, optionIdx) => {
                if (featureIdx === excludeFeatureIdx && optionIdx === excludeOptionIdx) return;

                option.addons.forEach(addon => {
                    if (addon.groupName && addon.groupName.trim() && addon.enableGrouping) {
                        if (!groups.has(addon.groupName)) {
                            groups.set(addon.groupName, {
                                name: addon.groupName,
                                template: addon
                            });
                        }
                    }
                });
            });
        });

        return Array.from(groups.values());
    }

    /**
     * Clean up registrations when an addon is deleted
     * @param {number} featureIdx - Feature index
     * @param {number} optionIdx - Option index  
     * @param {number} addonIdx - Addon index
     */
    cleanupAddonRegistrations(featureIdx, optionIdx, addonIdx) {
        // Clean threshold UI registrations
        for (const [groupName, registrations] of this.groupThresholdUIRegistry.entries()) {
            const filtered = registrations.filter(ui => 
                !(ui.featureIdx === featureIdx && 
                  ui.optionIdx === optionIdx && 
                  ui.addonIdx === addonIdx));
            
            if (filtered.length === 0) {
                this.groupThresholdUIRegistry.delete(groupName);
            } else {
                this.groupThresholdUIRegistry.set(groupName, filtered);
            }
        }

        // Clean max items UI registrations
        for (const [groupName, registrations] of this.groupMaxItemsUIRegistry.entries()) {
            const filtered = registrations.filter(ui => 
                !(ui.featureIdx === featureIdx && 
                  ui.optionIdx === optionIdx && 
                  ui.addonIdx === addonIdx));
            
            if (filtered.length === 0) {
                this.groupMaxItemsUIRegistry.delete(groupName);
            } else {
                this.groupMaxItemsUIRegistry.set(groupName, filtered);
            }
        }
    }

    /**
     * Handle group change events
     * @private
     */
    handleGroupChange(data) {
        // Implementation for when an addon's group changes
        const { featureIdx, optionIdx, addonIdx, oldGroup, newGroup } = data;
        
        if (oldGroup) {
            this.cleanupAddonRegistrations(featureIdx, optionIdx, addonIdx);
        }
        
        // New group registration will happen when the addon is re-rendered
    }

    /**
     * Handle threshold change events
     * @private
     */
    handleThresholdChange(data) {
        const { featureIdx, optionIdx, addonIdx, groupName, thresholds } = data;
        
        if (groupName) {
            this.synchronizeThresholdDiscounts(featureIdx, optionIdx, addonIdx, 
                                             groupName, thresholds);
        }
    }

    /**
     * Handle max items change events
     * @private
     */
    handleMaxItemsChange(data) {
        const { featureIdx, optionIdx, addonIdx, groupName, maxItems } = data;
        
        if (groupName) {
            this.synchronizeMaxGroupItems(featureIdx, optionIdx, addonIdx, 
                                        groupName, maxItems);
        }
    }
}