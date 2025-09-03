// ui/controllers/PricingTypeController.js

/**
 * Controller for managing pricing type UI logic and field visibility
 */
export class PricingTypeController {
    constructor(eventBus, config) {
        this.eventBus = eventBus;
        this.config = config;
        
        // Map pricing types to their indices
        this.PRICING_TYPES = {
            STATIC_PRICE: 0,
            PRICE_RANGE: 1,
            PRICE_OPTIONS: 2
        };
        
        // Listen for pricing type changes
        this.eventBus.on('pricingTypeChanged', this.handlePricingTypeChange.bind(this));
    }

    /**
     * Set up pricing type logic for an option
     * @param {HTMLElement} container - Container element
     * @param {Object} option - Option data
     * @param {Object} fieldElements - Object containing field elements
     */
    setupOptionPricingType(container, option, fieldElements) {
        const {
            pricingTypeSelect,
            priceFloorInput,
            priceCeilingInput,
            staticPriceInput,
            priceOptionsDiv
        } = fieldElements;

        if (!pricingTypeSelect) return;

        // Set up event listener
        pricingTypeSelect.addEventListener('change', (e) => {
            const selectedIndex = parseInt(e.target.value, 10);
            this.updateOptionFieldVisibility({
                selectedIndex,
                priceFloorInput,
                priceCeilingInput,
                staticPriceInput,
                priceOptionsDiv
            });

            // Emit event for other components
            this.eventBus.emit('pricingTypeChanged', {
                type: 'option',
                container,
                selectedIndex,
                option
            });
        });

        // Apply initial state
        const initialIndex = this.extractSelectedIndex(option.pricingType);
        this.updateOptionFieldVisibility({
            selectedIndex: initialIndex,
            priceFloorInput,
            priceCeilingInput,
            staticPriceInput,
            priceOptionsDiv
        });
    }

    /**
     * Set up pricing type logic for an addon
     * @param {HTMLElement} container - Container element
     * @param {Object} addon - Addon data
     * @param {Object} fieldElements - Object containing field elements
     */
    setupAddonPricingType(container, addon, fieldElements) {
        const {
            pricingTypeSelect,
            floorPriceModInput,
            ceilingPriceModInput,
            staticPriceModInput
        } = fieldElements;

        if (!pricingTypeSelect) return;

        // Set up event listener
        pricingTypeSelect.addEventListener('change', (e) => {
            const selectedIndex = parseInt(e.target.value, 10);
            this.updateAddonFieldVisibility({
                selectedIndex,
                floorPriceModInput,
                ceilingPriceModInput,
                staticPriceModInput
            });

            // Emit event for other components
            this.eventBus.emit('pricingTypeChanged', {
                type: 'addon',
                container,
                selectedIndex,
                addon
            });
        });

        // Apply initial state
        const initialIndex = this.extractSelectedIndex(addon.pricingType);
        this.updateAddonFieldVisibility({
            selectedIndex: initialIndex,
            floorPriceModInput,
            ceilingPriceModInput,
            staticPriceModInput
        });
    }

    /**
     * Update field visibility for option pricing type
     * @param {Object} params - Parameters object
     */
    updateOptionFieldVisibility(params) {
        const {
            selectedIndex,
            priceFloorInput,
            priceCeilingInput,
            staticPriceInput,
            priceOptionsDiv
        } = params;

        // Clear all inputs first
        this.disableAndClearInputs([priceFloorInput, priceCeilingInput, staticPriceInput]);

        // Hide price options form first
        if (priceOptionsDiv) {
            priceOptionsDiv.style.display = 'none';
        }

        switch (selectedIndex) {
            case this.PRICING_TYPES.STATIC_PRICE:
                // Enable only static price field
                if (staticPriceInput) {
                    staticPriceInput.disabled = false;
                }
                break;

            case this.PRICING_TYPES.PRICE_RANGE:
                // Enable min/max price fields
                if (priceFloorInput) priceFloorInput.disabled = false;
                if (priceCeilingInput) priceCeilingInput.disabled = false;
                break;

            case this.PRICING_TYPES.PRICE_OPTIONS:
                // Show price options form
                if (priceOptionsDiv) {
                    priceOptionsDiv.style.display = 'flex';
                }
                break;
        }

        // Emit container update event for height recalculation
        this.eventBus.emit('containerHeightUpdate', {
            element: this.findContainerElement(priceFloorInput || staticPriceInput)
        });
    }

    /**
     * Update field visibility for addon pricing type
     * @param {Object} params - Parameters object
     */
    updateAddonFieldVisibility(params) {
        const {
            selectedIndex,
            floorPriceModInput,
            ceilingPriceModInput,
            staticPriceModInput
        } = params;

        // Clear all inputs first
        this.disableAndClearInputs([floorPriceModInput, ceilingPriceModInput, staticPriceModInput]);

        switch (selectedIndex) {
            case this.PRICING_TYPES.STATIC_PRICE:
                // Enable only static price modifier field
                if (staticPriceModInput) {
                    staticPriceModInput.disabled = false;
                }
                break;

            case this.PRICING_TYPES.PRICE_RANGE:
                // Enable min/max price modifier fields
                if (floorPriceModInput) floorPriceModInput.disabled = false;
                if (ceilingPriceModInput) ceilingPriceModInput.disabled = false;
                break;
        }

        // Emit container update event for height recalculation
        this.eventBus.emit('containerHeightUpdate', {
            element: this.findContainerElement(floorPriceModInput || staticPriceModInput)
        });
    }

    /**
     * Set up threshold discount checkbox logic
     * @param {HTMLElement} container - Container element
     * @param {Object} option - Option data
     * @param {HTMLInputElement} checkbox - Threshold checkbox
     * @param {HTMLElement} thresholdUI - Threshold UI element
     */
    setupThresholdDiscounts(container, option, checkbox, thresholdUI) {
        if (!checkbox || !thresholdUI) return;

        // Set initial state - only check if explicitly true
        checkbox.checked = option.enableThresholdDiscounts === true;
        thresholdUI.style.display = checkbox.checked ? 'flex' : 'none';

        // Set up event listener
        checkbox.addEventListener('change', (e) => {
            const isEnabled = e.target.checked;
            option.enableThresholdDiscounts = isEnabled;
            thresholdUI.style.display = isEnabled ? 'flex' : 'none';

            // Emit events
            this.eventBus.emit('thresholdDiscountsToggled', {
                container,
                option,
                enabled: isEnabled
            });

            this.eventBus.emit('containerHeightUpdate', { 
                element: container,
                delay: 100 
            });
        });
    }

    /**
     * Set up addon grouping logic
     * @param {HTMLElement} container - Container element
     * @param {Object} addon - Addon data
     * @param {HTMLInputElement} checkbox - Grouping checkbox
     * @param {Object} groupFields - Group-related field elements
     */
    setupAddonGrouping(container, addon, checkbox, groupFields) {
        const {
            groupNameField,
            maxGroupItemsField,
            groupThresholdDiscountsUI
        } = groupFields;

        if (!checkbox) return;

        // Set initial state
        checkbox.checked = !!(addon.groupName && addon.groupName.trim());
        this.updateGroupFieldVisibility(checkbox.checked, groupFields);

        // Store previous group name for restoration
        let previousGroupName = addon.groupName || '';

        // Set up event listener
        checkbox.addEventListener('change', (e) => {
            const isEnabled = e.target.checked;
            addon.enableGrouping = isEnabled;

            if (isEnabled) {
                // Restore fields visibility
                this.updateGroupFieldVisibility(true, groupFields);

                // Restore previous group name if available
                if (addon._storedGroupName) {
                    addon.groupName = addon._storedGroupName;
                    this.updateGroupNameInput(groupNameField, addon._storedGroupName);
                    
                    // Restore other stored data
                    this.restoreStoredGroupData(addon, groupFields);
                }
            } else {
                // Store current values before hiding
                this.storeGroupData(addon, groupFields);
                
                // Clear actual data values
                addon.groupName = '';
                addon.groupThresholdDiscounts = this.getDefaultThresholdDiscounts();
                addon.maxGroupItems = -1;
                
                // Hide fields
                this.updateGroupFieldVisibility(false, groupFields);
            }

            // Emit events
            this.eventBus.emit('addonGroupingToggled', {
                container,
                addon,
                enabled: isEnabled
            });

            this.eventBus.emit('containerHeightUpdate', { 
                element: container,
                delay: 100 
            });
        });
    }

    /**
     * Handle pricing type change events from other components
     * @param {Object} data - Event data
     */
    handlePricingTypeChange(data) {
        const { type, container, selectedIndex } = data;
        
        // Additional logic can be added here for cross-component reactions
        console.debug(`Pricing type changed to ${selectedIndex} for ${type}`);
    }

    // Helper methods

    /**
     * Disable and clear input fields
     * @param {Array<HTMLInputElement>} inputs - Input elements to clear
     */
    disableAndClearInputs(inputs) {
        inputs.forEach(input => {
            if (input) {
                input.disabled = true;
                input.value = '';
                // Trigger input event to update data
                input.dispatchEvent(new Event('input'));
            }
        });
    }

    /**
     * Extract selected index from pricing type data structure
     * @param {*} pricingTypeData - Pricing type data
     * @returns {number} Selected index
     */
    extractSelectedIndex(pricingTypeData) {
        if (!pricingTypeData) return 0;
        
        if (typeof pricingTypeData === 'number') return pricingTypeData;
        
        if (pricingTypeData.value && 'selected' in pricingTypeData.value) {
            return pricingTypeData.value.selected;
        }
        
        return 0;
    }

    /**
     * Find the closest container element (option or addon)
     * @param {HTMLElement} element - Starting element
     * @returns {HTMLElement|null} Container element
     */
    findContainerElement(element) {
        if (!element) return null;
        return element.closest('.option, .addon');
    }

    /**
     * Update group field visibility
     * @param {boolean} visible - Whether fields should be visible
     * @param {Object} groupFields - Group field elements
     */
    updateGroupFieldVisibility(visible, groupFields) {
        const {
            groupNameField,
            maxGroupItemsField,
            groupThresholdDiscountsUI
        } = groupFields;

        const display = visible ? 'flex' : 'none';
        
        if (groupNameField) groupNameField.style.display = display;
        if (maxGroupItemsField) maxGroupItemsField.style.display = display;
        if (groupThresholdDiscountsUI) groupThresholdDiscountsUI.style.display = display;
    }

    /**
     * Update group name input field value
     * @param {HTMLElement} groupNameField - Group name field element
     * @param {string} value - New value
     */
    updateGroupNameInput(groupNameField, value) {
        if (!groupNameField) return;
        
        const input = groupNameField.querySelector('input[type="text"]');
        if (input) {
            input.value = value;
            input.dispatchEvent(new Event('input'));
            input.dispatchEvent(new Event('change'));
        }
    }

    /**
     * Store group data before disabling
     * @param {Object} addon - Addon data
     * @param {Object} groupFields - Group field elements
     */
    storeGroupData(addon, groupFields) {
        const { groupNameField } = groupFields;
        
        // Store group name
        const groupNameInput = groupNameField?.querySelector('input[type="text"]');
        if (groupNameInput && groupNameInput.value) {
            addon._storedGroupName = groupNameInput.value;
        }
        
        // Store threshold discounts
        if (addon.groupThresholdDiscounts) {
            addon._storedGroupThresholdDiscounts = JSON.parse(
                JSON.stringify(addon.groupThresholdDiscounts)
            );
        }
        
        // Store max items
        if (addon.maxGroupItems !== -1) {
            addon._storedMaxGroupItems = addon.maxGroupItems;
        }
    }

    /**
     * Restore stored group data
     * @param {Object} addon - Addon data
     * @param {Object} groupFields - Group field elements
     */
    restoreStoredGroupData(addon, groupFields) {
        // Restore threshold discounts
        if (addon._storedGroupThresholdDiscounts) {
            addon.groupThresholdDiscounts = JSON.parse(
                JSON.stringify(addon._storedGroupThresholdDiscounts)
            );
        }
        
        // Restore max items
        if (addon._storedMaxGroupItems !== undefined) {
            addon.maxGroupItems = addon._storedMaxGroupItems;
            
            // Update UI
            const { maxGroupItemsField } = groupFields;
            if (maxGroupItemsField) {
                this.updateMaxGroupItemsUI(maxGroupItemsField, addon._storedMaxGroupItems);
            }
        }
    }

    /**
     * Update max group items UI
     * @param {HTMLElement} maxGroupItemsField - Max items field element
     * @param {number} value - Value to set
     */
    updateMaxGroupItemsUI(maxGroupItemsField, value) {
        const input = maxGroupItemsField.querySelector('input[type="number"]');
        const checkbox = maxGroupItemsField.querySelector('input[type="checkbox"]');
        
        if (input && checkbox) {
            if (value === -1) {
                checkbox.checked = true;
                input.disabled = true;
                input.value = '';
            } else {
                checkbox.checked = false;
                input.disabled = false;
                input.value = value.toString();
            }
        }
    }

    /**
     * Get default threshold discounts structure
     * @returns {Object} Default threshold discounts
     */
    getDefaultThresholdDiscounts() {
        return {
            level: 'admin',
            ui_type: 'array-obj',
            value: {
                types: [{ itemCount: '', discount: '' }]
            }
        };
    }

    /**
     * Check if a pricing type supports price options
     * @param {number} pricingTypeIndex - Pricing type index
     * @returns {boolean} True if supports price options
     */
    supportsPriceOptions(pricingTypeIndex) {
        return pricingTypeIndex === this.PRICING_TYPES.PRICE_OPTIONS;
    }

    /**
     * Check if a pricing type supports static pricing
     * @param {number} pricingTypeIndex - Pricing type index
     * @returns {boolean} True if supports static pricing
     */
    supportsStaticPricing(pricingTypeIndex) {
        return pricingTypeIndex === this.PRICING_TYPES.STATIC_PRICE;
    }

    /**
     * Check if a pricing type supports range pricing
     * @param {number} pricingTypeIndex - Pricing type index
     * @returns {boolean} True if supports range pricing
     */
    supportsRangePricing(pricingTypeIndex) {
        return pricingTypeIndex === this.PRICING_TYPES.PRICE_RANGE;
    }
}