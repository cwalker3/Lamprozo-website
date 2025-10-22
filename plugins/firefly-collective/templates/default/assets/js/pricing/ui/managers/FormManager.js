// ui/managers/FormManager.js

/**
 * Manager for handling inline form creation and lifecycle
 */
export class FormManager {
    constructor(eventBus, config, dataManager, fieldFactory, expandCollapseController, validationService) {
        this.eventBus = eventBus;
        this.config = config;
        this.dataManager = dataManager;
        this.fieldFactory = fieldFactory;
        this.expandCollapseController = expandCollapseController;
        this.validationService = validationService;
        
        this.activeForm = null;
        this.formIdCounter = 0;
    }

    /**
     * Show new feature form
     */
    showNewFeatureForm() {
        const addBtn = document.getElementById('add-feature-button');
        if (!addBtn || this.activeForm) return;

        // Hide add button
        addBtn.style.display = 'none';

        const form = this.createFeatureForm(addBtn);
        this.activeForm = {
            type: 'feature',
            element: form,
            addButton: addBtn
        };

        this.eventBus.emit('formCreated', { type: 'feature', form });
    }

    /**
     * Show new option form for a feature
     * @param {number} featureIndex - Index of the parent feature
     */
    showNewOptionForm(featureIndex) {
        const featureElements = document.querySelectorAll('.feature');
        const featureElement = featureElements[featureIndex];
        
        if (!featureElement || featureElement.querySelector('.new-option-form')) return;

        this.expandCollapseController.preExpandForForm(featureElement);

        const form = this.createOptionForm(featureIndex, featureElement);
        this.activeForm = {
            type: 'option',
            element: form,
            featureIndex
        };

        this.eventBus.emit('formCreated', { type: 'option', featureIndex, form });
    }

    /**
     * Show new addon form for an option
     * @param {number} featureIndex - Index of the parent feature
     * @param {number} optionIndex - Index of the parent option
     */
    showNewAddonForm(featureIndex, optionIndex) {
        const featureElements = document.querySelectorAll('.feature');
        const optionElements = featureElements[featureIndex]?.querySelectorAll('.option');
        const optionElement = optionElements?.[optionIndex];

        if (!optionElement || optionElement.querySelector('.new-addon-form')) return;

        this.expandCollapseController.preExpandForForm(optionElement);

        const form = this.createAddonForm(featureIndex, optionIndex, optionElement);
        this.activeForm = {
            type: 'addon',
            element: form,
            featureIndex,
            optionIndex
        };

        this.eventBus.emit('formCreated', { type: 'addon', featureIndex, optionIndex, form });
    }

    /**
     * Create feature form
     * @param {HTMLElement} addButton - Add feature button
     * @returns {HTMLElement} Form element
     */
    createFeatureForm(addButton) {
        const form = document.createElement('div');
        form.className = `${this.config.getClass('feature')} ${this.config.getClass('new_form')} new-feature-form`;
        
        // Schema selection
        const schemaGroup = this.createSchemaSelector('feature');
        form.appendChild(schemaGroup);
        
        // Form state
        let tempName = '';
        let tempDesc = '';
        let tempRecurring = false;
        
        // Name field
        const nameField = this.fieldFactory.createFieldGroup(
            'featureName', 'text',
            () => tempName,
            v => tempName = v,
            'Enter feature name...'
        );
        form.appendChild(nameField);
        
        // Description field
        const descField = this.fieldFactory.createFieldGroup(
            'description', 'long-text',
            () => tempDesc,
            v => tempDesc = v,
            'Description...'
        );
        form.appendChild(descField);
        
        // Recurring field
        const recurringField = this.fieldFactory.createFieldGroup(
            'recurring', 'boolean',
            () => tempRecurring,
            v => tempRecurring = v
        );
        form.appendChild(recurringField);
        
        // Buttons
        const buttonRow = this.createFormButtons(
            () => this.handleFeatureCreate(schemaGroup.querySelector('select'), tempName, tempDesc, tempRecurring),
            () => this.cancelForm()
        );
        form.appendChild(buttonRow);
        
        // Insert form
        addButton.parentNode.insertBefore(form, addButton);
        
        return form;
    }

    /**
     * Create option form
     * @param {number} featureIndex - Parent feature index
     * @param {HTMLElement} featureElement - Parent feature element
     * @returns {HTMLElement} Form element
     */
    createOptionForm(featureIndex, featureElement) {
        const form = document.createElement('div');
        form.className = `${this.config.getClass('option')} ${this.config.getClass('new_form')} new-option-form`;
        
        // Get schema origin feature
        const parentFeature = this.dataManager.getData().features[featureIndex];
        const originFeatureIndex = this.getSchemaOriginFeature(parentFeature);
        const originFeature = this.dataManager.getData().features[originFeatureIndex];
        
        // Schema selection
        const schemaGroup = this.createSchemaSelector('option', originFeature.options);
        form.appendChild(schemaGroup);
        
        // Form state
        let tempName = '';
        let tempDesc = '';
        
        // Fields
        const nameField = this.fieldFactory.createFieldGroup(
            'optionName', 'text',
            () => tempName,
            v => tempName = v,
            'Enter option name...'
        );
        form.appendChild(nameField);
        
        const descField = this.fieldFactory.createFieldGroup(
            'description', 'long-text',
            () => tempDesc,
            v => tempDesc = v,
            'Description...'
        );
        form.appendChild(descField);
        
        // Buttons
        const buttonRow = this.createFormButtons(
            () => this.handleOptionCreate(featureIndex, schemaGroup.querySelector('select'), tempName, tempDesc),
            () => this.cancelForm()
        );
        form.appendChild(buttonRow);
        
        // Insert before add-option-row
        const addOptionRow = featureElement.querySelector('.add-option-row');
        addOptionRow.parentNode.insertBefore(form, addOptionRow);
        
        // Update container heights
        setTimeout(() => {
            this.expandCollapseController.updateAllOpenContainers();
            this.expandCollapseController.scrollIntoViewWithOffset(form);
        }, 50);
        
        return form;
    }

    /**
     * Create addon form
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Parent option index
     * @param {HTMLElement} optionElement - Parent option element
     * @returns {HTMLElement} Form element
     */
    createAddonForm(featureIndex, optionIndex, optionElement) {
        const form = document.createElement('div');
        form.className = `${this.config.getClass('addon')} ${this.config.getClass('new_form')} new-addon-form`;
        
        // Get schema origins
        const parentFeature = this.dataManager.getData().features[featureIndex];
        const originFeatureIndex = this.getSchemaOriginFeature(parentFeature);
        const originFeature = this.dataManager.getData().features[originFeatureIndex];
        
        const thisOption = parentFeature.options[optionIndex];
        const originOptionIndex = this.getSchemaOriginOption(thisOption, originFeature);
        const originOption = originFeature.options[originOptionIndex];
        
        // Schema selection
        const schemaGroup = this.createSchemaSelector('addon', originOption.addons);
        form.appendChild(schemaGroup);
        
        // Form state
        let tempName = '';
        let tempDesc = '';
        
        // Fields
        const nameField = this.fieldFactory.createFieldGroup(
            'addonName', 'text',
            () => tempName,
            v => tempName = v,
            'Enter addon name...'
        );
        form.appendChild(nameField);
        
        const descField = this.fieldFactory.createFieldGroup(
            'description', 'long-text',
            () => tempDesc,
            v => tempDesc = v,
            'Description...'
        );
        form.appendChild(descField);
        
        // Buttons
        const buttonRow = this.createFormButtons(
            () => this.handleAddonCreate(featureIndex, optionIndex, schemaGroup.querySelector('select'), tempName, tempDesc),
            () => this.cancelForm()
        );
        form.appendChild(buttonRow);
        
        // Insert before add-addon-row
        const addAddonRow = optionElement.querySelector('.add-addon-row');
        addAddonRow.parentNode.insertBefore(form, addAddonRow);
        
        // Update container heights
        setTimeout(() => {
            this.expandCollapseController.updateAllOpenContainers();
            this.expandCollapseController.scrollIntoViewWithOffset(form);
        }, 50);
        
        return form;
    }

    /**
     * Create schema selector dropdown
     * @param {string} type - Type: feature, option, addon
     * @param {Array} schemas - Available schemas
     * @returns {HTMLElement} Schema group element
     */
    createSchemaSelector(type, schemas = []) {
        const group = document.createElement('div');
        group.className = this.config.getClass('field_group');
        
        const label = document.createElement('label');
        label.textContent = 'Schema:';
        group.appendChild(label);
        
        const select = document.createElement('select');
        
        // Default option
        const defaultOption = document.createElement('option');
        defaultOption.value = '__default__';
        defaultOption.textContent = 'Default';
        select.appendChild(defaultOption);
        
        // Schema options
        if (schemas && Array.isArray(schemas)) {
            schemas.forEach((schema, index) => {
                const option = document.createElement('option');
                option.value = index;
                
                if (type === 'feature') {
                    option.textContent = schema.featureName || `Feature ${index + 1}`;
                } else if (type === 'option') {
                    option.textContent = schema.optionName || `Option ${index + 1}`;
                } else if (type === 'addon') {
                    option.textContent = schema.addonName || `Addon ${index + 1}`;
                }
                
                select.appendChild(option);
            });
        }
        
        group.appendChild(select);
        return group;
    }

    /**
     * Create form buttons (Create/Cancel)
     * @param {Function} onCreate - Create callback
     * @param {Function} onCancel - Cancel callback
     * @returns {HTMLElement} Button row element
     */
    createFormButtons(onCreate, onCancel) {
        const buttonRow = document.createElement('div');
        buttonRow.className = this.config.getClass('button_row');
        
        const createBtn = document.createElement('button');
        createBtn.textContent = 'Create';
        createBtn.className = this.config.getClass('add_button');
        createBtn.addEventListener('click', onCreate);
        
        const cancelBtn = document.createElement('button');
        cancelBtn.textContent = 'Cancel';
        cancelBtn.className = this.config.getClass('delete_button');
        cancelBtn.addEventListener('click', onCancel);
        
        buttonRow.appendChild(createBtn);
        buttonRow.appendChild(cancelBtn);
        
        return buttonRow;
    }

    /**
     * Handle feature creation
     * @param {HTMLSelectElement} schemaSelect - Schema selector
     * @param {string} name - Feature name
     * @param {string} description - Feature description
     * @param {boolean} recurring - Recurring flag
     */
    handleFeatureCreate(schemaSelect, name, description, recurring) {
        if (!name.trim()) {
            alert('Feature name is required.');
            return;
        }

        // Validate
        const validation = this.validationService.quickValidate('feature', 'featureName', name);
        if (!validation.isValid) {
            alert(validation.error);
            return;
        }

        let newFeature;
        if (schemaSelect.value === '__default__') {
            newFeature = this.config.getDefault('feature');
            newFeature.recurring = recurring;
        } else {
            // Clone from template
            const templateIndex = parseInt(schemaSelect.value, 10);
            const template = this.dataManager.getData().features[templateIndex];
            newFeature = JSON.parse(JSON.stringify(template));
            
            // Clear values but preserve structure
            this.clearSchemaValues(newFeature);
            newFeature.options = [];
            newFeature.recurring = recurring;
            newFeature.link_name = template.featureName;
            newFeature.featureName = '';
            newFeature.description.text = '';
        }

        // Set values
        newFeature.featureName = name;
        newFeature.description.text = description;

        // Add to data
        const featureIndex = this.dataManager.addFeature(newFeature);
        
        this.cancelForm();
        this.eventBus.emit('featureCreateSuccess', { feature: newFeature, index: featureIndex });
    }

    /**
     * Handle option creation
     * @param {number} featureIndex - Parent feature index
     * @param {HTMLSelectElement} schemaSelect - Schema selector
     * @param {string} name - Option name
     * @param {string} description - Option description
     */
    handleOptionCreate(featureIndex, schemaSelect, name, description) {
        if (!name.trim()) {
            alert('Option name is required.');
            return;
        }

        // Validate
        const validation = this.validationService.quickValidate('option', 'optionName', name);
        if (!validation.isValid) {
            alert(validation.error);
            return;
        }

        let newOption;
        const parentFeature = this.dataManager.getData().features[featureIndex];
        const isRecurring = !!parentFeature.recurring;

        if (schemaSelect.value === '__default__') {
            newOption = this.config.getDefault('option');
            
            // Adjust interval based on recurring
            if (isRecurring) {
                newOption.interval = {
                    level: 'admin',
                    ui_type: 'array',
                    value: { types: ['day', 'week', 'month', 'year', 'none'], selected: 4 }
                };
            }
        } else {
            // Clone from template
            const originFeatureIndex = this.getSchemaOriginFeature(parentFeature);
            const originFeature = this.dataManager.getData().features[originFeatureIndex];
            const templateIndex = parseInt(schemaSelect.value, 10);
            const template = originFeature.options[templateIndex];
            
            newOption = JSON.parse(JSON.stringify(template));
            
            // Clear values but preserve structure
            this.clearSchemaValues(newOption);
            newOption.link_name = template.optionName;
            newOption.optionName = '';
            newOption.description.text = '';
            newOption.priceFloor = 0;
            newOption.priceCeiling = 0;
            newOption.staticPrice = 0;
            newOption.optionMetric = '';
            newOption.addons = [];
            
            if (isRecurring) {
                newOption.interval = {
                    level: 'admin',
                    ui_type: 'array',
                    value: { types: ['day', 'week', 'month', 'year', 'none'], selected: 4 }
                };
            }
        }

        // Set values
        newOption.optionName = name;
        newOption.description.text = description;

        // Add to data
        const optionIndex = this.dataManager.addOption(featureIndex, newOption);
        
        this.cancelForm();
        this.eventBus.emit('optionCreateSuccess', { 
            option: newOption, 
            featureIndex, 
            optionIndex 
        });
    }

    /**
     * Handle addon creation
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Parent option index
     * @param {HTMLSelectElement} schemaSelect - Schema selector
     * @param {string} name - Addon name
     * @param {string} description - Addon description
     */
    handleAddonCreate(featureIndex, optionIndex, schemaSelect, name, description) {
        if (!name.trim()) {
            alert('Addon name is required.');
            return;
        }

        // Validate
        const validation = this.validationService.quickValidate('addon', 'addonName', name);
        if (!validation.isValid) {
            alert(validation.error);
            return;
        }

        let newAddon;
        if (schemaSelect.value === '__default__') {
            newAddon = this.config.getDefault('addon');
        } else {
            // Clone from template
            const parentFeature = this.dataManager.getData().features[featureIndex];
            const originFeatureIndex = this.getSchemaOriginFeature(parentFeature);
            const originFeature = this.dataManager.getData().features[originFeatureIndex];
            
            const thisOption = parentFeature.options[optionIndex];
            const originOptionIndex = this.getSchemaOriginOption(thisOption, originFeature);
            const originOption = originFeature.options[originOptionIndex];
            
            const templateIndex = parseInt(schemaSelect.value, 10);
            const template = originOption.addons[templateIndex];
            
            newAddon = JSON.parse(JSON.stringify(template));
            
            // Clear values but preserve structure
            this.clearSchemaValues(newAddon);
            newAddon.link_name = template.addonName;
            newAddon.addonName = '';
            newAddon.description = { text: '' };
            newAddon.floorPriceMod = 0;
            newAddon.ceilingPriceMod = 0;
            newAddon.staticPriceMod = 0;
            newAddon.addOnMetric = '';
        }

        // Set values
        newAddon.addonName = name;
        newAddon.description.text = description;

        // Add to data
        const addonIndex = this.dataManager.addAddon(featureIndex, optionIndex, newAddon);
        
        this.cancelForm();
        this.eventBus.emit('addonCreateSuccess', { 
            addon: newAddon, 
            featureIndex, 
            optionIndex, 
            addonIndex 
        });
    }

    /**
     * Cancel active form
     */
    cancelForm() {
        if (!this.activeForm) return;

        const { type, element, addButton } = this.activeForm;
        
        // Remove form element
        element.remove();
        
        // Restore add button if it's a feature form
        if (type === 'feature' && addButton) {
            addButton.style.display = '';
        }
        
        // Update container heights
        setTimeout(() => {
            this.expandCollapseController.updateAllOpenContainers();
        }, 50);
        
        this.eventBus.emit('formCancelled', { type });
        this.activeForm = null;
    }

    /**
     * Check if form is currently active
     * @returns {boolean} True if form is active
     */
    hasActiveForm() {
        return this.activeForm !== null;
    }

    // Helper methods

    getSchemaOriginFeature(feature) {
        if (!feature.link_name) return 0;
        
        const data = this.dataManager.getData();
        const foundIndex = data.features.findIndex(f => f.featureName === feature.link_name);
        return foundIndex !== -1 ? foundIndex : 0;
    }

    getSchemaOriginOption(option, originFeature) {
        if (!option.link_name || !originFeature.options) return 0;
        
        const foundIndex = originFeature.options.findIndex(o => o.optionName === option.link_name);
        return foundIndex !== -1 ? foundIndex : 0;
    }

    /**
     * Clear schema values while preserving structure
     * @param {Object} node - Node to clear
     */
    clearSchemaValues(node) {
        if (!node || typeof node !== 'object') return;
        
        if ('ui_type' in node && 'value' in node) {
            switch (node.ui_type) {
                case 'boolean':
                    node.value = false;
                    break;
                case 'int-float':
                    node.value = 0;
                    break;
                case 'date':
                    node.value = '';
                    break;
                case 'array':
                    if (node.value && Array.isArray(node.value.types)) {
                        node.value.selected = 0;
                    }
                    break;
                default:
                    node.value = '';
                    break;
            }
            return;
        }
        
        if (Array.isArray(node)) {
            node.forEach(this.clearSchemaValues.bind(this));
            return;
        }
        
        for (const key in node) {
            if (node.hasOwnProperty(key)) {
                this.clearSchemaValues(node[key]);
            }
        }
    }

    /**
     * Clean up any active forms (for app cleanup)
     */
    cleanup() {
        if (this.activeForm) {
            this.cancelForm();
        }
    }
}