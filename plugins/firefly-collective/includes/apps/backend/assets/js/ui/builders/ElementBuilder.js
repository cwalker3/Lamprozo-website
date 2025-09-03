// ui/builders/ElementBuilder.js

import { generateId } from '../../utils/helpers.js';

/**
 * Builder for creating pricing element DOM structures
 */
export class ElementBuilder {
    constructor(eventBus, config, dataManager, fieldFactory, specialFieldFactory, 
                expandCollapseController, pricingTypeController, dialogService, 
                groupSyncService, formManager) {
        this.eventBus = eventBus;
        this.config = config;
        this.dataManager = dataManager;
        this.fieldFactory = fieldFactory;
        this.specialFieldFactory = specialFieldFactory;
        this.expandCollapseController = expandCollapseController;
        this.pricingTypeController = pricingTypeController;
        this.dialogService = dialogService;
        this.groupSyncService = groupSyncService;
        this.formManager = formManager;
    }

    /**
     * Create a complete feature element
     * @param {number} featureIndex - Feature index
     * @param {Object} feature - Feature data
     * @param {Object} availableMetrics - Available metrics for dropdowns
     * @returns {HTMLElement} Feature element
     */
    createFeatureElement(featureIndex, feature, availableMetrics) {
        const wrapper = document.createElement('div');
        wrapper.className = this.config.getClass('feature');
        wrapper.dataset.featureIndex = featureIndex;

        // Create header
        const header = this.createFeatureHeader(feature, featureIndex);
        wrapper.appendChild(header);

        // Create content area
        const content = this.createFeatureContent(feature, featureIndex, availableMetrics);
        wrapper.appendChild(content);

        // Set up expand/collapse
        header.addEventListener('click', (e) => {
            if (e.target.closest('.header-right')) return;
            this.expandCollapseController.toggleExpandCollapse(wrapper);
        });

        return wrapper;
    }

    /**
     * Create feature header
     * @param {Object} feature - Feature data
     * @param {number} featureIndex - Feature index
     * @returns {HTMLElement} Header element
     */
    createFeatureHeader(feature, featureIndex) {
        const header = document.createElement('div');
        header.className = 'feature-header';

        // Left section
        const left = document.createElement('div');
        left.className = 'feature-header-left';
        
        const titleSpan = document.createElement('span');
        titleSpan.textContent = feature.featureName || 'New Feature';
        titleSpan.className = 'feature-title';
        left.appendChild(titleSpan);

        // Right section
        const right = document.createElement('div');
        right.className = 'feature-header-right';

        // Toggle indicator
        const toggle = document.createElement('span');
        toggle.className = this.config.getClass('toggle_indicator');
        toggle.textContent = '+';

        // Clone button
        const cloneBtn = this.createActionButton('clone', 'Clone', () => {
            this.dialogService.confirmClone(feature.featureName || 'this feature', () => {
                this.cloneFeature(featureIndex, feature);
            });
        });

        // Delete button
        const deleteBtn = this.createActionButton('delete', 'Delete Feature', () => {
            this.dialogService.confirmDeletion(feature.featureName || 'this feature', () => {
                this.deleteFeature(featureIndex, header.closest('.feature'));
            });
        });

        right.appendChild(toggle);
        right.appendChild(cloneBtn);
        right.appendChild(deleteBtn);

        header.appendChild(left);
        header.appendChild(right);

        return header;
    }

    /**
     * Create feature content area
     * @param {Object} feature - Feature data
     * @param {number} featureIndex - Feature index
     * @param {Object} availableMetrics - Available metrics
     * @returns {HTMLElement} Content element
     */
    createFeatureContent(feature, featureIndex, availableMetrics) {
        const content = document.createElement('div');
        content.className = 'feature-content';

        const inner = document.createElement('div');
        inner.className = 'feature-content-inner';

        // Feature name field with title update
        const nameField = this.fieldFactory.createFieldGroup(
            'featureName', 'text',
            () => feature.featureName,
            (value) => {
                const oldName = feature.featureName;
                this.dataManager.recordNameChange('features', [featureIndex], oldName, value);
                feature.featureName = value;
                
                // Update title display
                const titleSpan = content.closest('.feature').querySelector('.feature-title');
                if (titleSpan) titleSpan.textContent = value || 'New Feature';
                
                // Update references in other features
                this.updateFeatureNameReferences(oldName, value);
                
                this.dataManager.save();
            },
            'Enter feature name...'
        );
        inner.appendChild(nameField);

        // Description field
        const descField = this.fieldFactory.createFieldGroup(
            'description', 'long-text',
            () => feature.description.text,
            (value) => {
                feature.description.text = value;
                this.dataManager.save();
            },
            'Description...'
        );
        inner.appendChild(descField);

        // Recurring checkbox
        const recurringField = this.fieldFactory.createFieldGroup(
            'recurring', 'boolean',
            () => !!feature.recurring,
            (value) => {
                feature.recurring = value;
                this.dataManager.save();
                this.eventBus.emit('featureRecurringChanged', { featureIndex, recurring: value });
            }
        );
        inner.appendChild(recurringField);

        // Add dynamic fields
        this.fieldFactory.createDynamicFields(feature, inner, [
            'featureName', 'description', 'options', 'recurring', 'link_name'
        ]);

        // Options section
        const optionsHeader = document.createElement('div');
        optionsHeader.className = 'section-header feature-options-header';
        const optionsTitle = document.createElement('span');
        optionsTitle.className = 'feature-name-reference';
        optionsTitle.textContent = (feature.featureName || 'Feature') + "'s Options:";
        optionsHeader.appendChild(optionsTitle);
        inner.appendChild(optionsHeader);

        // Add existing options
        if (feature.options && Array.isArray(feature.options)) {
            feature.options.forEach((option, optionIndex) => {
                const optionElement = this.createOptionElement(
                    featureIndex, optionIndex, option, availableMetrics, 
                    recurringField.querySelector('input[type=checkbox]')
                );
                inner.appendChild(optionElement);
            });
        }

        // Add option button
        const addOptionRow = document.createElement('div');
        addOptionRow.className = `${this.config.getClass('button_row')} add-option-row`;
        const addOptionBtn = this.createActionButton('add', 'Add Option', () => {
            this.formManager.showNewOptionForm(featureIndex);
        });
        addOptionRow.appendChild(addOptionBtn);
        inner.appendChild(addOptionRow);

        content.appendChild(inner);
        return content;
    }

    /**
     * Create option element
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Option index
     * @param {Object} option - Option data
     * @param {Object} availableMetrics - Available metrics
     * @param {HTMLInputElement} featureRecurringCheckbox - Feature recurring checkbox
     * @returns {HTMLElement} Option element
     */
    createOptionElement(featureIndex, optionIndex, option, availableMetrics, featureRecurringCheckbox) {
        const wrapper = document.createElement('div');
        wrapper.className = this.config.getClass('option');
        wrapper.dataset.featureIndex = featureIndex;
        wrapper.dataset.optionIndex = optionIndex;

        // Create header
        const header = this.createOptionHeader(option, featureIndex, optionIndex);
        wrapper.appendChild(header);

        // Create content
        const content = this.createOptionContent(
            featureIndex, optionIndex, option, availableMetrics, featureRecurringCheckbox
        );
        wrapper.appendChild(content);

        // Set up expand/collapse
        header.addEventListener('click', (e) => {
            if (e.target.closest('.header-right')) return;
            this.expandCollapseController.toggleExpandCollapse(wrapper);
        });

        return wrapper;
    }

    /**
     * Create option header
     * @param {Object} option - Option data
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Option index
     * @returns {HTMLElement} Header element
     */
    createOptionHeader(option, featureIndex, optionIndex) {
        const header = document.createElement('div');
        header.className = 'header option-header';

        // Left section
        const left = document.createElement('div');
        left.className = 'header-left';
        const title = document.createElement('span');
        title.className = 'option-title';
        title.textContent = option.optionName || 'New Option';
        left.appendChild(title);

        // Right section
        const right = document.createElement('div');
        right.className = 'header-right';

        const toggle = document.createElement('span');
        toggle.className = this.config.getClass('toggle_indicator');
        toggle.textContent = '+';

        const cloneBtn = this.createActionButton('clone', 'Clone', () => {
            this.dialogService.confirmClone(option.optionName || 'this option', () => {
                this.cloneOption(featureIndex, optionIndex, option);
            });
        });

        const deleteBtn = this.createActionButton('delete', 'Delete', () => {
            this.dialogService.confirmDeletion(option.optionName || 'this option', () => {
                this.deleteOption(featureIndex, optionIndex, header.closest('.option'));
            });
        });

        right.appendChild(toggle);
        right.appendChild(cloneBtn);
        right.appendChild(deleteBtn);
        header.appendChild(left);
        header.appendChild(right);

        return header;
    }

    /**
     * Create option content area
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Option index
     * @param {Object} option - Option data
     * @param {Object} availableMetrics - Available metrics
     * @param {HTMLInputElement} featureRecurringCheckbox - Feature recurring checkbox
     * @returns {HTMLElement} Content element
     */
    createOptionContent(featureIndex, optionIndex, option, availableMetrics, featureRecurringCheckbox) {
        const content = document.createElement('div');
        content.className = 'content option-content';
        content.style.maxHeight = '0px';

        const inner = document.createElement('div');
        inner.className = 'content-inner';

        // Option name field
        const nameField = this.fieldFactory.createFieldGroup(
            'optionName', 'text',
            () => option.optionName,
            (value) => {
                const oldName = option.optionName;
                this.dataManager.recordNameChange('options', [featureIndex, optionIndex], oldName, value);
                option.optionName = value;
                
                // Update title
                const title = content.closest('.option').querySelector('.option-title');
                if (title) title.textContent = value || 'New Option';
                
                this.dataManager.save();
            },
            'Enter option name...'
        );
        inner.appendChild(nameField);

        // Description field
        const descField = this.fieldFactory.createFieldGroup(
            'description', 'long-text',
            () => option.description.text,
            (value) => {
                option.description.text = value;
                this.dataManager.save();
            },
            'Description...'
        );
        inner.appendChild(descField);

        // Interval field
        const intervalField = this.fieldFactory.createDynamicField(
            'interval', option.interval,
            (value) => {
                option.interval.value = value;
                this.dataManager.save();
            }
        );
        inner.appendChild(intervalField);

        // Pricing type field
        const pricingTypeField = this.fieldFactory.createDynamicField(
            'pricingType', option.pricingType,
            (value) => {
                option.pricingType.value = value;
                this.dataManager.save();
            }
        );
        inner.appendChild(pricingTypeField);

        // Price fields
        const priceFloorField = this.fieldFactory.createFieldGroup(
            'priceFloor', 'number',
            () => option.priceFloor,
            (value) => {
                option.priceFloor = value;
                this.dataManager.save();
            }
        );
        inner.appendChild(priceFloorField);

        const priceCeilingField = this.fieldFactory.createFieldGroup(
            'priceCeiling', 'number',
            () => option.priceCeiling,
            (value) => {
                option.priceCeiling = value;
                this.dataManager.save();
            }
        );
        inner.appendChild(priceCeilingField);

        const staticPriceField = this.fieldFactory.createFieldGroup(
            'staticPrice', 'number',
            () => option.staticPrice,
            (value) => {
                option.staticPrice = value;
                this.dataManager.save();
            }
        );
        inner.appendChild(staticPriceField);

        // Price options field
        const priceOptionsField = this.specialFieldFactory.createPriceOptionsField(
            option.priceOptions,
            (value) => {
                if (!option.priceOptions) {
                    option.priceOptions = {
                        level: 'admin',
                        ui_type: 'array-obj',
                        value: { types: value }
                    };
                } else {
                    option.priceOptions.value = { types: value };
                }
                this.dataManager.save();
            }
        );
        inner.appendChild(priceOptionsField);

        // Threshold discounts
        const thresholdCheckboxGroup = document.createElement('div');
        thresholdCheckboxGroup.className = this.config.getClass('field_group');
        const thresholdLabel = document.createElement('label');
        thresholdLabel.textContent = 'Use Threshold Discounts:';
        const thresholdCheckbox = document.createElement('input');
        thresholdCheckbox.type = 'checkbox';
        thresholdCheckbox.checked = option.enableThresholdDiscounts === true;
        thresholdCheckboxGroup.appendChild(thresholdLabel);
        thresholdCheckboxGroup.appendChild(thresholdCheckbox);
        inner.appendChild(thresholdCheckboxGroup);

        const thresholdDiscountsField = this.specialFieldFactory.createThresholdDiscountsField(
            option.thresholdDiscounts,
            (value) => {
                if (!option.thresholdDiscounts) {
                    option.thresholdDiscounts = {
                        level: 'admin',
                        ui_type: 'array-obj',
                        value: { types: value }
                    };
                } else {
                    option.thresholdDiscounts.value = { types: value };
                }
                this.dataManager.save();
            }
        );
        inner.appendChild(thresholdDiscountsField);

        // Set up threshold discounts logic
        this.pricingTypeController.setupThresholdDiscounts(
            content, option, thresholdCheckbox, thresholdDiscountsField
        );

        // Option metric field
        const optionMetricField = this.fieldFactory.createFieldGroup(
            'optionMetric', 'text',
            () => option.optionMetric,
            (value) => {
                option.optionMetric = value;
                this.dataManager.save();
            },
            'Enter metric...'
        );
        inner.appendChild(optionMetricField);

        // Max addons field
        const maxAddonsField = this.specialFieldFactory.createMaxAddonsField(option);
        inner.appendChild(maxAddonsField);

        // Set up pricing type logic
        this.setupOptionPricingTypeLogic(inner, option, {
            pricingTypeSelect: pricingTypeField.querySelector('select'),
            priceFloorInput: priceFloorField.querySelector('input'),
            priceCeilingInput: priceCeilingField.querySelector('input'),
            staticPriceInput: staticPriceField.querySelector('input'),
            priceOptionsDiv: priceOptionsField
        });

        // Set up interval logic
        const intervalSelect = intervalField.querySelector('select');
        if (intervalSelect && featureRecurringCheckbox) {
            featureRecurringCheckbox.addEventListener('change', () => {
                intervalSelect.disabled = !featureRecurringCheckbox.checked;
            });
            intervalSelect.disabled = !featureRecurringCheckbox.checked;
        }

        // Add dynamic fields
        this.fieldFactory.createDynamicFields(option, inner, [
            'optionName', 'description', 'interval', 'pricingType',
            'priceFloor', 'priceCeiling', 'staticPrice', 'priceOptions', 'optionMetric', 
            'addons', 'link_name', 'thresholdDiscounts', 'enableThresholdDiscounts', 'maxAddons'
        ]);

        // Addons section
        const addonsHeader = document.createElement('div');
        addonsHeader.className = 'section-header option-addons-header';
        const addonsTitle = document.createElement('span');
        addonsTitle.className = 'option-name-reference';
        addonsTitle.textContent = (option.optionName || 'Option') + "'s Add-ons:";
        addonsHeader.appendChild(addonsTitle);
        inner.appendChild(addonsHeader);

        // Addon container
        const addonContainer = document.createElement('div');
        addonContainer.className = 'addon-content-area';

        // Add existing addons
        if (option.addons && Array.isArray(option.addons)) {
            option.addons.forEach((addon, addonIndex) => {
                const addonElement = this.createAddonElement(
                    featureIndex, optionIndex, addonIndex, addon, 
                    availableMetrics, featureRecurringCheckbox
                );
                addonContainer.appendChild(addonElement);
            });
        }

        // Add addon button
        const addAddonRow = document.createElement('div');
        addAddonRow.className = `${this.config.getClass('button_row')} add-addon-row`;
        const addAddonBtn = this.createActionButton('add', 'Add Addon', () => {
            this.formManager.showNewAddonForm(featureIndex, optionIndex);
        });
        addAddonRow.appendChild(addAddonBtn);
        addonContainer.appendChild(addAddonRow);

        inner.appendChild(addonContainer);
        content.appendChild(inner);
        return content;
    }

    /**
     * Create addon element
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Parent option index
     * @param {number} addonIndex - Addon index
     * @param {Object} addon - Addon data
     * @param {Object} availableMetrics - Available metrics
     * @param {HTMLInputElement} featureRecurringCheckbox - Feature recurring checkbox
     * @returns {HTMLElement} Addon element
     */
    createAddonElement(featureIndex, optionIndex, addonIndex, addon, availableMetrics, featureRecurringCheckbox) {
        const wrapper = document.createElement('div');
        wrapper.className = this.config.getClass('addon');
        wrapper.dataset.featureIndex = featureIndex;
        wrapper.dataset.optionIndex = optionIndex;
        wrapper.dataset.addonIndex = addonIndex;

        // Create header
        const header = this.createAddonHeader(addon, featureIndex, optionIndex, addonIndex);
        wrapper.appendChild(header);

        // Create content
        const content = this.createAddonContent(
            featureIndex, optionIndex, addonIndex, addon, availableMetrics
        );
        wrapper.appendChild(content);

        // Set up expand/collapse
        header.addEventListener('click', (e) => {
            if (e.target.closest('.header-right')) return;
            this.expandCollapseController.toggleExpandCollapse(wrapper);
        });

        return wrapper;
    }

    /**
     * Create addon header
     * @param {Object} addon - Addon data
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Parent option index
     * @param {number} addonIndex - Addon index
     * @returns {HTMLElement} Header element
     */
    createAddonHeader(addon, featureIndex, optionIndex, addonIndex) {
        const header = document.createElement('div');
        header.className = 'header addon-header';

        // Left section
        const left = document.createElement('div');
        left.className = 'header-left';
        const title = document.createElement('span');
        title.className = 'addon-title';
        title.textContent = addon.addonName || 'New Addon';
        left.appendChild(title);

        // Right section
        const right = document.createElement('div');
        right.className = 'header-right';

        const toggle = document.createElement('span');
        toggle.className = this.config.getClass('toggle_indicator');
        toggle.textContent = '+';

        const cloneBtn = this.createActionButton('clone', 'Clone', () => {
            this.dialogService.confirmClone(addon.addonName || 'this addon', () => {
                this.cloneAddon(featureIndex, optionIndex, addonIndex, addon);
            });
        });

        const deleteBtn = this.createActionButton('delete', 'Delete', () => {
            this.dialogService.confirmDeletion(addon.addonName || 'this addon', () => {
                this.deleteAddon(featureIndex, optionIndex, addonIndex, header.closest('.addon'));
            });
        });

        right.appendChild(toggle);
        right.appendChild(cloneBtn);
        right.appendChild(deleteBtn);
        header.appendChild(left);
        header.appendChild(right);

        return header;
    }

    /**
     * Create addon content area
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Parent option index
     * @param {number} addonIndex - Addon index
     * @param {Object} addon - Addon data
     * @param {Object} availableMetrics - Available metrics
     * @returns {HTMLElement} Content element
     */
    createAddonContent(featureIndex, optionIndex, addonIndex, addon, availableMetrics) {
        const content = document.createElement('div');
        content.className = 'content addon-content';
        content.style.maxHeight = '0px';

        const inner = document.createElement('div');
        inner.className = 'content-inner';

        // Basic fields
        this.addAddonBasicFields(inner, featureIndex, optionIndex, addonIndex, addon);

        // Grouping fields
        this.addAddonGroupingFields(inner, featureIndex, optionIndex, addonIndex, addon);

        // Add dynamic fields
        this.fieldFactory.createDynamicFields(addon, inner, [
            'addonName', 'description', 'addOnMetric', 'pricingType', 
            'floorPriceMod', 'ceilingPriceMod', 'staticPriceMod',
            'priceModifierType', 'link_name', 'groupName', 'groupThresholdDiscounts',
            'enableGrouping', 'maxGroupItems'
        ]);

        content.appendChild(inner);
        return content;
    }

    // Helper methods for creating various elements and handling actions...

    createActionButton(type, text, onClick) {
        const button = document.createElement('button');
        button.className = this.config.getClass(`${type}_button`);
        button.textContent = text;
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            onClick();
        });
        return button;
    }

    addAddonBasicFields(container, featureIndex, optionIndex, addonIndex, addon) {
        // Name field
        const nameField = this.fieldFactory.createFieldGroup(
            'addonName', 'text',
            () => addon.addonName,
            (value) => {
                const oldName = addon.addonName;
                this.dataManager.recordNameChange('addons', [featureIndex, optionIndex, addonIndex], oldName, value);
                addon.addonName = value;
                
                // Update title
                const title = container.closest('.addon').querySelector('.addon-title');
                if (title) title.textContent = value || 'New Addon';
                
                this.dataManager.save();
            },
            'Enter addon name...'
        );
        container.appendChild(nameField);

        // Description field
        const descField = this.fieldFactory.createFieldGroup(
            'description', 'long-text',
            () => addon.description.text,
            (value) => {
                addon.description.text = value;
                this.dataManager.save();
            },
            'Description...'
        );
        container.appendChild(descField);

        // Metric field
        const metricField = this.fieldFactory.createFieldGroup(
            'addOnMetric', 'text',
            () => addon.addOnMetric,
            (value) => {
                addon.addOnMetric = value;
                this.dataManager.save();
            },
            'Enter addon metric...'
        );
        container.appendChild(metricField);

        // Pricing fields...
        // (Additional fields would be added here following the same pattern)
    }

    addAddonGroupingFields(container, featureIndex, optionIndex, addonIndex, addon) {
        // Group checkbox
        const groupCheckboxField = document.createElement('div');
        groupCheckboxField.className = this.config.getClass('field_group');
        
        const groupLabel = document.createElement('label');
        groupLabel.textContent = 'Group this Addon:';
        const groupCheckbox = document.createElement('input');
        groupCheckbox.type = 'checkbox';
        groupCheckbox.checked = !!(addon.groupName && addon.groupName.trim());
        
        groupCheckboxField.appendChild(groupLabel);
        groupCheckboxField.appendChild(groupCheckbox);
        container.appendChild(groupCheckboxField);

        // Group name field
        const groupNameField = this.fieldFactory.createFieldGroup(
            'groupName', 'text',
            () => addon.groupName,
            (value) => {
                addon.groupName = value;
                this.dataManager.save();
            },
            'Enter group name...'
        );
        container.appendChild(groupNameField);

        // Max group items field
        const maxGroupItemsField = this.specialFieldFactory.createMaxGroupItemsField(
            addon, featureIndex, optionIndex, addonIndex, addon.groupName
        );
        container.appendChild(maxGroupItemsField);

        // Group threshold discounts field
        const groupThresholdField = this.specialFieldFactory.createThresholdDiscountsField(
            addon.groupThresholdDiscounts,
            (value) => {
                addon.groupThresholdDiscounts = {
                    level: 'admin',
                    ui_type: 'array-obj',
                    value: { types: value }
                };
                this.dataManager.save();
            },
            {
                groupName: addon.groupName,
                featureIdx: featureIndex,
                optionIdx: optionIndex,
                addonIdx: addonIndex
            }
        );
        container.appendChild(groupThresholdField);

        // Set up grouping logic
        this.pricingTypeController.setupAddonGrouping(
            container.closest('.addon'), addon, groupCheckbox, {
                groupNameField,
                maxGroupItemsField,
                groupThresholdDiscountsUI: groupThresholdField
            }
        );
    }

    setupOptionPricingTypeLogic(container, option, fieldElements) {
        this.pricingTypeController.setupOptionPricingType(container, option, fieldElements);
    }

    // Action handlers
    cloneFeature(featureIndex, feature) {
        const clone = JSON.parse(JSON.stringify(feature));
        clone.featureName += ' (Copy)';
        clone.link_name = '';
        
        const newIndex = this.dataManager.addFeature(clone);
        this.eventBus.emit('elementCloned', { type: 'feature', originalIndex: featureIndex, newIndex });
    }

    cloneOption(featureIndex, optionIndex, option) {
        const clone = JSON.parse(JSON.stringify(option));
        clone.optionName += ' (Copy)';
        
        const newIndex = this.dataManager.addOption(featureIndex, clone);
        this.eventBus.emit('elementCloned', { type: 'option', featureIndex, originalIndex: optionIndex, newIndex });
    }

    cloneAddon(featureIndex, optionIndex, addonIndex, addon) {
        const clone = JSON.parse(JSON.stringify(addon));
        clone.addonName += ' (Copy)';
        
        const newIndex = this.dataManager.addAddon(featureIndex, optionIndex, clone);
        this.eventBus.emit('elementCloned', { type: 'addon', featureIndex, optionIndex, originalIndex: addonIndex, newIndex });
    }

    deleteFeature(featureIndex, element) {
        element.classList.add(this.config.getClass('fade_out'));
        element.addEventListener('animationend', () => {
            this.dataManager.deleteFeature(featureIndex);
            element.remove();
        });
    }

    deleteOption(featureIndex, optionIndex, element) {
        element.classList.add(this.config.getClass('fade_out'));
        element.addEventListener('animationend', () => {
            this.dataManager.deleteOption(featureIndex, optionIndex);
            element.remove();
        });
    }

    deleteAddon(featureIndex, optionIndex, addonIndex, element) {
        element.classList.add(this.config.getClass('fade_out'));
        element.addEventListener('animationend', () => {
            // Clean up group registrations
            this.groupSyncService.cleanupAddonRegistrations(featureIndex, optionIndex, addonIndex);
            this.dataManager.deleteAddon(featureIndex, optionIndex, addonIndex);
            element.remove();
        });
    }

    updateFeatureNameReferences(oldName, newName) {
        // Update link_name references in other features
        this.dataManager.getData().features.forEach(feature => {
            if (feature.link_name === oldName) {
                feature.link_name = newName;
            }
        });
        this.dataManager.save();
    }
}