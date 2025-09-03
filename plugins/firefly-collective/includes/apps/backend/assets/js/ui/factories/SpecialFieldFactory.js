// ui/factories/SpecialFieldFactory.js

import { debounce } from '../../utils/helpers.js';

/**
 * Factory for creating complex specialized fields
 */
export class SpecialFieldFactory {
    constructor(eventBus, config, groupSyncService) {
        this.eventBus = eventBus;
        this.config = config;
        this.groupSyncService = groupSyncService;
    }

    /**
     * Create price options UI field
     * @param {*} optionsData - Current price options data
     * @param {Function} onChange - Change callback
     * @returns {HTMLElement} Price options field element
     */
    createPriceOptionsField(optionsData, onChange) {
        const container = document.createElement('div');
        container.className = `${this.config.getClass('field_group')} ${this.config.getClass('price_options_field')}`;
        container.style.display = 'none'; // Start hidden, controlled by pricing type
        
        // Create label
        const label = document.createElement('label');
        label.textContent = 'Price Options:';
        container.appendChild(label);
        
        // Process input data
        let typesArray = this.processOptionsData(optionsData);
        const workingData = [...typesArray]; // Create working copy
        
        // Create container for option rows
        const rightColumn = document.createElement('div');
        rightColumn.className = 'price-options-container';
        rightColumn.style.flex = '1';
        rightColumn.style.display = 'flex';
        rightColumn.style.flexDirection = 'column';
        container.appendChild(rightColumn);
        
        const updateContainerHeights = debounce(() => {
            this.eventBus.emit('containerHeightUpdate', { 
                element: container.closest('.option, .addon') 
            });
        }, 100);
        
        const renderOptions = () => {
            rightColumn.innerHTML = '';
            
            workingData.forEach((option, idx) => {
                const row = this.createPriceOptionRow(option, idx, workingData, onChange, renderOptions, updateContainerHeights);
                rightColumn.appendChild(row);
            });
            
            // Add button
            const addBtnRow = this.createAddButtonRow(() => {
                workingData.push({ label: '', price: 0 });
                onChange([...workingData]);
                renderOptions();
                updateContainerHeights();
            });
            
            rightColumn.appendChild(addBtnRow);
        };
        
        renderOptions();
        return container;
    }

    /**
     * Create threshold discounts UI field
     * @param {*} discountsData - Current threshold data
     * @param {Function} onChange - Change callback
     * @param {Object} groupInfo - Group information for synchronization
     * @returns {HTMLElement} Threshold discounts field element
     */
    createThresholdDiscountsField(discountsData, onChange, groupInfo = null) {
        const container = document.createElement('div');
        container.className = `${this.config.getClass('field_group')} ${this.config.getClass('price_options_field')}`;
        container.style.display = 'none'; // Start hidden
        
        // Set data attributes for group sync if provided
        if (groupInfo) {
            container.dataset.fIdx = groupInfo.featureIdx || '';
            container.dataset.oIdx = groupInfo.optionIdx || '';
            container.dataset.aIdx = groupInfo.addonIdx || '';
            container.dataset.groupName = groupInfo.groupName || '';
        }
        
        // Create label
        const label = document.createElement('label');
        label.textContent = 'Quantity Discounts:';
        container.appendChild(label);
        
        // Process input data
        let thresholdsArray = this.processThresholdData(discountsData);
        const workingData = [...thresholdsArray];
        
        // Create container for threshold rows
        const rightColumn = document.createElement('div');
        rightColumn.className = 'price-options-container';
        rightColumn.style.flex = '1';
        rightColumn.style.display = 'flex';
        rightColumn.style.flexDirection = 'column';
        container.appendChild(rightColumn);
        
        let preventSync = false; // Prevent circular updates
        
        const updateContainerHeights = debounce(() => {
            this.eventBus.emit('containerHeightUpdate', { 
                element: container.closest('.addon, .option') 
            });
        }, 100);
        
        const renderThresholds = (externalData = null, preserveId = null) => {
            // Handle external data updates from sync
            if (externalData && !preventSync) {
                workingData.length = 0;
                externalData.forEach(item => {
                    workingData.push({ ...item });
                });
            }
            
            // Store focus information
            const activeElement = document.activeElement;
            const activeId = preserveId || (activeElement ? activeElement.id : null);
            const activeValue = activeElement ? activeElement.value : null;
            const selectionStart = activeElement ? activeElement.selectionStart : null;
            const selectionEnd = activeElement ? activeElement.selectionEnd : null;
            
            // Clear and rebuild
            rightColumn.innerHTML = '';
            
            workingData.forEach((threshold, idx) => {
                const row = this.createThresholdRow(
                    threshold, idx, workingData, onChange, renderThresholds, 
                    updateContainerHeights, groupInfo, preventSync
                );
                rightColumn.appendChild(row);
            });
            
            // Add button
            const addBtnRow = this.createAddButtonRow(() => {
                if (preventSync) return;
                
                workingData.push({ itemCount: '', discount: '' });
                this.handleThresholdChange(workingData, onChange, groupInfo, preventSync);
                updateContainerHeights();
            });
            
            rightColumn.appendChild(addBtnRow);
            
            // Restore focus
            this.restoreFocus(activeId, activeValue, selectionStart, selectionEnd);
        };
        
        // Register with group sync if needed
        if (groupInfo && groupInfo.groupName && groupInfo.groupName.trim()) {
            this.groupSyncService.registerThresholdUI(
                groupInfo.groupName,
                groupInfo.featureIdx,
                groupInfo.optionIdx,
                groupInfo.addonIdx,
                container,
                renderThresholds
            );
        }
        
        renderThresholds();
        return container;
    }

    /**
     * Create max addons field with unlimited checkbox
     * @param {Object} option - Option object
     * @returns {HTMLElement} Max addons field element
     */
    createMaxAddonsField(option) {
        const container = document.createElement('div');
        container.className = this.config.getClass('field_group');
        
        const label = document.createElement('label');
        label.textContent = 'Max Addons:';
        container.appendChild(label);
        
        const inputWrapper = document.createElement('div');
        inputWrapper.style.display = 'flex';
        inputWrapper.style.alignItems = 'center';
        inputWrapper.style.flex = '1';
        
        // Number input
        const input = document.createElement('input');
        input.type = 'number';
        input.min = '1';
        input.style.flex = '1';
        input.placeholder = 'Maximum allowed addons';
        
        // Checkbox for unlimited
        const checkboxContainer = document.createElement('div');
        checkboxContainer.style.marginLeft = '10px';
        checkboxContainer.style.display = 'flex';
        checkboxContainer.style.alignItems = 'center';
        
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = 'unlimited-addons-' + Math.random().toString(36).substring(2, 9);
        checkbox.style.marginRight = '5px';
        
        const checkboxLabel = document.createElement('label');
        checkboxLabel.htmlFor = checkbox.id;
        checkboxLabel.textContent = 'Unlimited';
        checkboxLabel.style.marginBottom = '0';
        
        // Initialize state
        const isUnlimited = option.maxAddons === -1 || option.maxAddons === undefined;
        checkbox.checked = isUnlimited;
        input.disabled = isUnlimited;
        if (!isUnlimited) {
            input.value = option.maxAddons;
        }
        
        // Event handlers
        input.addEventListener('input', e => {
            const val = parseInt(e.target.value, 10) || 1;
            option.maxAddons = val;
            this.eventBus.emit('fieldChanged', { field: 'maxAddons', value: val });
        });
        
        checkbox.addEventListener('change', e => {
            const isUnlimited = e.target.checked;
            input.disabled = isUnlimited;
            
            if (isUnlimited) {
                input.value = '';
                option.maxAddons = -1;
            } else {
                input.value = input.value || '1';
                option.maxAddons = parseInt(input.value, 10) || 1;
            }
            this.eventBus.emit('fieldChanged', { field: 'maxAddons', value: option.maxAddons });
        });
        
        // Assemble
        checkboxContainer.appendChild(checkbox);
        checkboxContainer.appendChild(checkboxLabel);
        inputWrapper.appendChild(input);
        inputWrapper.appendChild(checkboxContainer);
        container.appendChild(inputWrapper);
        
        return container;
    }

    /**
     * Create max group items field with unlimited checkbox
     * @param {Object} addon - Addon object
     * @param {number} featureIdx - Feature index
     * @param {number} optionIdx - Option index
     * @param {number} addonIdx - Addon index
     * @param {string} groupName - Group name for sync
     * @returns {HTMLElement} Max group items field element
     */
    createMaxGroupItemsField(addon, featureIdx, optionIdx, addonIdx, groupName) {
        const container = document.createElement('div');
        container.className = this.config.getClass('field_group');
        
        const label = document.createElement('label');
        label.textContent = 'Max Group Items:';
        container.appendChild(label);
        
        const inputWrapper = document.createElement('div');
        inputWrapper.style.display = 'flex';
        inputWrapper.style.alignItems = 'center';
        inputWrapper.style.flex = '1';
        
        // Number input
        const input = document.createElement('input');
        input.type = 'number';
        input.min = '0';
        input.style.flex = '1';
        input.placeholder = 'Maximum allowed items';
        
        // Checkbox for unlimited
        const checkboxContainer = document.createElement('div');
        checkboxContainer.style.marginLeft = '10px';
        checkboxContainer.style.display = 'flex';
        checkboxContainer.style.alignItems = 'center';
        
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = 'unlimited-group-items-' + Math.random().toString(36).substring(2, 9);
        checkbox.style.marginRight = '5px';
        
        const checkboxLabel = document.createElement('label');
        checkboxLabel.htmlFor = checkbox.id;
        checkboxLabel.textContent = 'Unlimited';
        checkboxLabel.style.marginBottom = '0';
        
        // Initialize state
        const isUnlimited = addon.maxGroupItems === -1 || addon.maxGroupItems === undefined;
        checkbox.checked = isUnlimited;
        input.disabled = isUnlimited;
        if (!isUnlimited) {
            input.value = addon.maxGroupItems;
        }
        
        // Create render function for sync
        const renderMaxItems = (newValue) => {
            if (newValue === -1) {
                checkbox.checked = true;
                input.disabled = true;
                input.value = '';
            } else if (newValue === 0) {
                checkbox.checked = false;
                input.disabled = false;
                input.value = '';
            } else {
                checkbox.checked = false;
                input.disabled = false;
                input.value = newValue.toString();
            }
            addon.maxGroupItems = newValue;
        };
        
        // Register with group sync
        if (groupName && groupName.trim()) {
            this.groupSyncService.registerMaxItemsUI(
                groupName, featureIdx, optionIdx, addonIdx, renderMaxItems
            );
        }
        
        // Event handlers
        input.addEventListener('input', e => {
            const newValue = e.target.value ? parseInt(e.target.value, 10) : 0;
            addon.maxGroupItems = newValue;
            
            if (groupName && groupName.trim()) {
                this.groupSyncService.synchronizeMaxGroupItems(
                    featureIdx, optionIdx, addonIdx, groupName, newValue
                );
            }
        });
        
        checkbox.addEventListener('change', e => {
            const newValue = e.target.checked ? -1 : (input.value ? parseInt(input.value, 10) : 0);
            input.disabled = e.target.checked;
            
            if (e.target.checked) {
                input.value = '';
            }
            
            addon.maxGroupItems = newValue;
            
            if (groupName && groupName.trim()) {
                this.groupSyncService.synchronizeMaxGroupItems(
                    featureIdx, optionIdx, addonIdx, groupName, newValue
                );
            }
        });
        
        // Assemble
        checkboxContainer.appendChild(checkbox);
        checkboxContainer.appendChild(checkboxLabel);
        inputWrapper.appendChild(input);
        inputWrapper.appendChild(checkboxContainer);
        container.appendChild(inputWrapper);
        
        return container;
    }

    // Helper methods

    processOptionsData(optionsData) {
        if (typeof optionsData === 'string') {
            try {
                return JSON.parse(optionsData);
            } catch(e) {
                return [{ label: 'Default', price: 0 }];
            }
        } else if (Array.isArray(optionsData)) {
            return optionsData;
        } else if (optionsData && optionsData.types) {
            return optionsData.types;
        }
        return [{ label: 'Default', price: 0 }];
    }

    processThresholdData(discountsData) {
        try {
            if (!discountsData) return [{ itemCount: "", discount: "" }];
            
            if (typeof discountsData === 'string') {
                const parsed = JSON.parse(discountsData);
                return Array.isArray(parsed) ? parsed : 
                      (parsed.types ? parsed.types : [{ itemCount: "", discount: "" }]);
            } 
            
            if (Array.isArray(discountsData)) {
                return discountsData;
            }
            
            if (discountsData.types) {
                return discountsData.types;
            }
            
            if (discountsData.value && discountsData.value.types) {
                return discountsData.value.types;
            }
            
            return [{ itemCount: "", discount: "" }];
        } catch (e) {
            console.error("Error parsing threshold data:", e);
            return [{ itemCount: "", discount: "" }];
        }
    }

    createPriceOptionRow(option, idx, workingData, onChange, renderOptions, updateContainerHeights) {
        const row = document.createElement('div');
        row.className = 'price-option-row';
        
        // Label input
        const labelInput = document.createElement('input');
        labelInput.type = 'text';
        labelInput.value = option.label;
        labelInput.placeholder = 'Option label';
        labelInput.addEventListener('input', e => {
            workingData[idx].label = e.target.value;
            onChange([...workingData]);
        });
        
        // Price input
        const priceInput = document.createElement('input');
        priceInput.type = 'number';
        priceInput.value = option.price;
        priceInput.placeholder = 'Price';
        priceInput.addEventListener('input', e => {
            workingData[idx].price = parseFloat(e.target.value) || 0;
            onChange([...workingData]);
        });
        
        // Controls
        const controlsContainer = this.createRowControls(
            idx, workingData, onChange, renderOptions, updateContainerHeights
        );
        
        row.appendChild(labelInput);
        row.appendChild(priceInput);
        row.appendChild(controlsContainer);
        
        return row;
    }

    createThresholdRow(threshold, idx, workingData, onChange, renderThresholds, 
                      updateContainerHeights, groupInfo, preventSync) {
        const row = document.createElement('div');
        row.className = 'price-option-row';
        
        // Create unique IDs for focus preservation
        const scopePrefix = groupInfo ? 'addon-' : 'option-';
        const uniqueGroupId = groupInfo ? 
            `${groupInfo.groupName || 'local'}-${groupInfo.featureIdx || '0'}-${groupInfo.optionIdx || '0'}-${groupInfo.addonIdx || '0'}` : 
            'local';
        
        // Quantity input
        const countInput = document.createElement('input');
        countInput.type = 'number';
        countInput.min = '1';
        countInput.value = threshold.itemCount !== '' ? threshold.itemCount : '';
        countInput.placeholder = 'Quantity';
        countInput.id = `${scopePrefix}count-input-${uniqueGroupId}-${idx}`;
        
        const debouncedCountUpdate = debounce((e) => {
            if (preventSync) return;
            const val = e.target.value.trim() !== '' ? parseInt(e.target.value, 10) : '';
            workingData[idx].itemCount = val;
            updateContainerHeights();
            this.handleThresholdChange(workingData, onChange, groupInfo, preventSync, countInput.id);
        }, this.config.getUI('DEBOUNCE_DELAY'));
        
        countInput.addEventListener('input', debouncedCountUpdate);
        
        // Discount input
        const discountInput = document.createElement('input');
        discountInput.type = 'number';
        discountInput.min = '0';
        discountInput.max = '100';
        discountInput.value = threshold.discount !== '' ? threshold.discount : '';
        discountInput.placeholder = '%';
        discountInput.id = `${scopePrefix}discount-input-${uniqueGroupId}-${idx}`;
        
        const debouncedDiscountUpdate = debounce((e) => {
            if (preventSync) return;
            const val = e.target.value.trim() !== '' ? parseFloat(e.target.value) : '';
            workingData[idx].discount = val;
            updateContainerHeights();
            this.handleThresholdChange(workingData, onChange, groupInfo, preventSync, discountInput.id);
        }, this.config.getUI('DEBOUNCE_DELAY'));
        
        discountInput.addEventListener('input', debouncedDiscountUpdate);
        
        // Controls
        const controlsContainer = this.createThresholdRowControls(
            idx, workingData, onChange, renderThresholds, updateContainerHeights, 
            groupInfo, preventSync
        );
        
        row.appendChild(countInput);
        row.appendChild(discountInput);
        row.appendChild(controlsContainer);
        
        return row;
    }

    createRowControls(idx, workingData, onChange, renderCallback, updateContainerHeights) {
        const controlsContainer = document.createElement('div');
        controlsContainer.className = 'controls-container';
        
        // Delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.textContent = '−';
        deleteBtn.className = 'price-option-delete';
        deleteBtn.addEventListener('click', () => {
            workingData.splice(idx, 1);
            onChange([...workingData]);
            renderCallback();
            updateContainerHeights();
        });
        
        // Up/Down arrows
        const upBtn = this.createArrowButton('up', idx === 0, () => {
            if (idx > 0) {
                [workingData[idx-1], workingData[idx]] = [workingData[idx], workingData[idx-1]];
                onChange([...workingData]);
                renderCallback();
                updateContainerHeights();
            }
        });
        
        const downBtn = this.createArrowButton('down', idx === workingData.length - 1, () => {
            if (idx < workingData.length - 1) {
                [workingData[idx], workingData[idx+1]] = [workingData[idx+1], workingData[idx]];
                onChange([...workingData]);
                renderCallback();
                updateContainerHeights();
            }
        });
        
        controlsContainer.appendChild(deleteBtn);
        controlsContainer.appendChild(upBtn);
        controlsContainer.appendChild(downBtn);
        
        return controlsContainer;
    }

    createThresholdRowControls(idx, workingData, onChange, renderCallback, updateContainerHeights, 
                              groupInfo, preventSync) {
        const controlsContainer = document.createElement('div');
        controlsContainer.className = 'controls-container';
        
        // Delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.textContent = '−';
        deleteBtn.className = 'price-option-delete';
        deleteBtn.addEventListener('click', () => {
            if (preventSync) return;
            workingData.splice(idx, 1);
            this.handleThresholdChange(workingData, onChange, groupInfo, preventSync, null, true);
            updateContainerHeights();
        });
        
        // Up/Down arrows
        const upBtn = this.createArrowButton('up', idx === 0, () => {
            if (preventSync || idx === 0) return;
            [workingData[idx-1], workingData[idx]] = [workingData[idx], workingData[idx-1]];
            this.handleThresholdChange(workingData, onChange, groupInfo, preventSync, null, true);
            updateContainerHeights();
        });
        
        const downBtn = this.createArrowButton('down', idx === workingData.length - 1, () => {
            if (preventSync || idx === workingData.length - 1) return;
            [workingData[idx], workingData[idx+1]] = [workingData[idx+1], workingData[idx]];
            this.handleThresholdChange(workingData, onChange, groupInfo, preventSync, null, true);
            updateContainerHeights();
        });
        
        controlsContainer.appendChild(deleteBtn);
        controlsContainer.appendChild(upBtn);
        controlsContainer.appendChild(downBtn);
        
        return controlsContainer;
    }

    createArrowButton(direction, disabled, onClick) {
        const btn = document.createElement('button');
        btn.innerHTML = direction === 'up' ? '&#9650;' : '&#9660;';
        btn.className = 'price-option-arrow';
        btn.disabled = disabled;
        btn.addEventListener('click', onClick);
        return btn;
    }

    createAddButtonRow(onClick) {
        const addBtnRow = document.createElement('div');
        addBtnRow.style.textAlign = 'right';
        addBtnRow.style.marginTop = '5px';
        
        const addBtn = document.createElement('button');
        addBtn.textContent = '+';
        addBtn.className = this.config.getClass('add_button');
        addBtn.style.marginTop = '0';
        addBtn.addEventListener('click', onClick);
        
        addBtnRow.appendChild(addBtn);
        return addBtnRow;
    }

    handleThresholdChange(workingData, onChange, groupInfo, preventSync, preserveFocusId = null, forceUpdate = false) {
        if (groupInfo && groupInfo.groupName && groupInfo.groupName.trim()) {
            onChange([...workingData]);
            preventSync = true;
            this.groupSyncService.synchronizeThresholdDiscounts(
                groupInfo.featureIdx,
                groupInfo.optionIdx,
                groupInfo.addonIdx,
                groupInfo.groupName,
                workingData,
                preserveFocusId,
                forceUpdate
            );
            setTimeout(() => { preventSync = false; }, 10);
        } else {
            onChange([...workingData]);
        }
    }

    restoreFocus(activeId, activeValue, selectionStart, selectionEnd) {
        if (activeId) {
            requestAnimationFrame(() => {
                const elementToFocus = document.getElementById(activeId);
                if (elementToFocus) {
                    elementToFocus.focus();
                    
                    if (activeValue !== null && elementToFocus.value !== activeValue) {
                        elementToFocus.value = activeValue;
                    }
                    
                    if (typeof selectionStart === 'number' && typeof selectionEnd === 'number') {
                        try {
                            elementToFocus.setSelectionRange(selectionStart, selectionEnd);
                        } catch (err) {
                            // Ignore selection range errors
                        }
                    }
                }
            });
        }
    }
}