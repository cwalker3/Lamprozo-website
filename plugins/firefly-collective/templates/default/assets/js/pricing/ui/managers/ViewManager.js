// ui/managers/ViewManager.js

/**
 * Manager for rendering and updating the main pricing form view
 */
export class ViewManager {
    constructor(eventBus, config, dataManager, elementBuilder, expandCollapseController) {
        this.eventBus = eventBus;
        this.config = config;
        this.dataManager = dataManager;
        this.elementBuilder = elementBuilder;
        this.expandCollapseController = expandCollapseController;
        
        this.container = null;
        this.addFeatureButton = null;
        
        this.setupEventListeners();
    }

    /**
     * Initialize the view manager with DOM container
     * @param {HTMLElement} container - Main container element
     */
    initialize(container) {
        this.container = container;
        this.render();
    }

    /**
     * Set up event listeners for data changes
     */
    setupEventListeners() {
        this.eventBus.on('dataInitialized', this.handleDataInitialized.bind(this));
        this.eventBus.on('featureAdded', this.handleFeatureAdded.bind(this));
        this.eventBus.on('optionAdded', this.handleOptionAdded.bind(this));
        this.eventBus.on('addonAdded', this.handleAddonAdded.bind(this));
        this.eventBus.on('featureDeleted', this.handleFeatureDeleted.bind(this));
        this.eventBus.on('optionDeleted', this.handleOptionDeleted.bind(this));
        this.eventBus.on('addonDeleted', this.handleAddonDeleted.bind(this));
        this.eventBus.on('elementCloned', this.handleElementCloned.bind(this));
        this.eventBus.on('featureCreateSuccess', this.handleFeatureCreateSuccess.bind(this));
        this.eventBus.on('optionCreateSuccess', this.handleOptionCreateSuccess.bind(this));
        this.eventBus.on('addonCreateSuccess', this.handleAddonCreateSuccess.bind(this));
    }

    /**
     * Render the complete pricing form
     */
    render() {
        if (!this.container) return;
        
        this.container.innerHTML = '';
        
        const data = this.dataManager.getData();
        if (!data.features || !Array.isArray(data.features)) {
            data.features = [];
        }

        const availableMetrics = this.dataManager.getAvailableMetrics();
        
        // Render each feature
        data.features.forEach((feature, index) => {
            const featureElement = this.elementBuilder.createFeatureElement(
                index, feature, availableMetrics
            );
            this.container.appendChild(featureElement);
        });

        // Add feature button
        this.createAddFeatureButton();
        
        this.eventBus.emit('viewRendered', { 
            featureCount: data.features.length 
        });
    }

    /**
     * Create the add feature button
     */
    createAddFeatureButton() {
        this.addFeatureButton = document.createElement('button');
        this.addFeatureButton.id = 'add-feature-button';
        this.addFeatureButton.className = this.config.getClass('add_button');
        this.addFeatureButton.textContent = 'Add Feature';
        
        this.addFeatureButton.addEventListener('click', () => {
            this.eventBus.emit('addFeatureRequested');
        });
        
        this.container.appendChild(this.addFeatureButton);
    }

    /**
     * Re-render specific feature
     * @param {number} featureIndex - Index of feature to re-render
     */
    rerenderFeature(featureIndex) {
        const data = this.dataManager.getData();
        const feature = data.features[featureIndex];
        
        if (!feature) return;

        const existingElement = this.container.querySelector(`[data-feature-index="${featureIndex}"]`);
        if (existingElement) {
            const availableMetrics = this.dataManager.getAvailableMetrics();
            const newElement = this.elementBuilder.createFeatureElement(
                featureIndex, feature, availableMetrics
            );
            
            // Preserve expanded state
            const wasExpanded = existingElement.querySelector('.feature-content.open');
            if (wasExpanded) {
                setTimeout(() => {
                    this.expandCollapseController.expandFeature(newElement);
                }, 0);
            }
            
            existingElement.replaceWith(newElement);
        }
    }

    /**
     * Re-render specific option
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Index of option to re-render
     */
    rerenderOption(featureIndex, optionIndex) {
        const data = this.dataManager.getData();
        const option = data.features[featureIndex]?.options[optionIndex];
        
        if (!option) return;

        const featureElement = this.container.querySelector(`[data-feature-index="${featureIndex}"]`);
        const existingElement = featureElement?.querySelector(`[data-option-index="${optionIndex}"]`);
        
        if (existingElement) {
            const availableMetrics = this.dataManager.getAvailableMetrics();
            const featureRecurringCheckbox = featureElement.querySelector('input[type="checkbox"]');
            
            const newElement = this.elementBuilder.createOptionElement(
                featureIndex, optionIndex, option, availableMetrics, featureRecurringCheckbox
            );
            
            // Preserve expanded state
            const wasExpanded = existingElement.querySelector('.content.open');
            if (wasExpanded) {
                setTimeout(() => {
                    this.expandCollapseController.toggleExpandCollapse(newElement, true);
                }, 0);
            }
            
            existingElement.replaceWith(newElement);
        }
    }

    /**
     * Re-render specific addon
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Parent option index
     * @param {number} addonIndex - Index of addon to re-render
     */
    rerenderAddon(featureIndex, optionIndex, addonIndex) {
        const data = this.dataManager.getData();
        const addon = data.features[featureIndex]?.options[optionIndex]?.addons[addonIndex];
        
        if (!addon) return;

        const optionElement = this.container.querySelector(
            `[data-feature-index="${featureIndex}"] [data-option-index="${optionIndex}"]`
        );
        const existingElement = optionElement?.querySelector(`[data-addon-index="${addonIndex}"]`);
        
        if (existingElement) {
            const availableMetrics = this.dataManager.getAvailableMetrics();
            const featureElement = optionElement.closest('.feature');
            const featureRecurringCheckbox = featureElement.querySelector('input[type="checkbox"]');
            
            const newElement = this.elementBuilder.createAddonElement(
                featureIndex, optionIndex, addonIndex, addon, 
                availableMetrics, featureRecurringCheckbox
            );
            
            // Preserve expanded state
            const wasExpanded = existingElement.querySelector('.content.open');
            if (wasExpanded) {
                setTimeout(() => {
                    this.expandCollapseController.toggleExpandCollapse(newElement, true);
                }, 0);
            }
            
            existingElement.replaceWith(newElement);
        }
    }

    /**
     * Update indices after element deletion
     * @param {string} type - Type: 'feature', 'option', 'addon'
     * @param {Object} indices - Indices object
     */
    updateIndicesAfterDeletion(type, indices) {
        if (type === 'feature') {
            this.updateFeatureIndices(indices.deletedIndex);
        } else if (type === 'option') {
            this.updateOptionIndices(indices.featureIndex, indices.deletedIndex);
        } else if (type === 'addon') {
            this.updateAddonIndices(indices.featureIndex, indices.optionIndex, indices.deletedIndex);
        }
    }

    /**
     * Update feature indices in DOM after deletion
     * @param {number} deletedIndex - Index of deleted feature
     */
    updateFeatureIndices(deletedIndex) {
        const features = this.container.querySelectorAll('.feature');
        features.forEach((feature, index) => {
            if (index >= deletedIndex) {
                feature.dataset.featureIndex = index;
                
                // Update nested elements
                feature.querySelectorAll('.option').forEach((option, optionIndex) => {
                    option.dataset.featureIndex = index;
                    
                    option.querySelectorAll('.addon').forEach((addon, addonIndex) => {
                        addon.dataset.featureIndex = index;
                    });
                });
            }
        });
    }

    /**
     * Update option indices in DOM after deletion
     * @param {number} featureIndex - Parent feature index
     * @param {number} deletedIndex - Index of deleted option
     */
    updateOptionIndices(featureIndex, deletedIndex) {
        const featureElement = this.container.querySelector(`[data-feature-index="${featureIndex}"]`);
        if (!featureElement) return;
        
        const options = featureElement.querySelectorAll('.option');
        options.forEach((option, index) => {
            if (index >= deletedIndex) {
                option.dataset.optionIndex = index;
                
                // Update nested addons
                option.querySelectorAll('.addon').forEach((addon, addonIndex) => {
                    addon.dataset.optionIndex = index;
                });
            }
        });
    }

    /**
     * Update addon indices in DOM after deletion
     * @param {number} featureIndex - Parent feature index
     * @param {number} optionIndex - Parent option index
     * @param {number} deletedIndex - Index of deleted addon
     */
    updateAddonIndices(featureIndex, optionIndex, deletedIndex) {
        const optionElement = this.container.querySelector(
            `[data-feature-index="${featureIndex}"] [data-option-index="${optionIndex}"]`
        );
        if (!optionElement) return;
        
        const addons = optionElement.querySelectorAll('.addon');
        addons.forEach((addon, index) => {
            if (index >= deletedIndex) {
                addon.dataset.addonIndex = index;
            }
        });
    }

    // Event handlers

    handleDataInitialized(data) {
        if (this.container) {
            this.render();
        }
    }

    handleFeatureAdded(data) {
        // Feature will be added via create success handler
    }

    handleOptionAdded(data) {
        // Option will be added via create success handler
    }

    handleAddonAdded(data) {
        // Addon will be added via create success handler
    }

    handleFeatureDeleted(data) {
        this.updateIndicesAfterDeletion('feature', { deletedIndex: data.index });
    }

    handleOptionDeleted(data) {
        this.updateIndicesAfterDeletion('option', {
            featureIndex: data.featureIndex,
            deletedIndex: data.optionIndex
        });
    }

    handleAddonDeleted(data) {
        this.updateIndicesAfterDeletion('addon', {
            featureIndex: data.featureIndex,
            optionIndex: data.optionIndex,
            deletedIndex: data.addonIndex
        });
    }

    handleElementCloned(data) {
        const { type, featureIndex, optionIndex, newIndex } = data;
        
        if (type === 'feature') {
            // Re-render all features to show the new clone
            this.render();
            
            // Simple scroll like the old system - just find and scroll
            if (newIndex !== undefined) {
                setTimeout(() => {
                    const newFeatureElement = this.container.querySelector(`[data-feature-index="${newIndex}"]`);
                    if (newFeatureElement) {
                        this.expandCollapseController.toggleExpandCollapse(newFeatureElement, true);
                        this.simpleScrollToElement(newFeatureElement);
                        // Use the full height calculation system for features too
                        this.expandCollapseController.recalculateAllHeights();
                    }
                }, 100);
            }
        } else if (type === 'option') {
            // Create and insert the new option element directly, like the old system
            const data = this.dataManager.getData();
            const option = data.features[featureIndex]?.options[newIndex];
            
            if (option) {
                const featureElement = this.container.querySelector(`[data-feature-index="${featureIndex}"]`);
                const availableMetrics = this.dataManager.getAvailableMetrics();
                const featureRecurringCheckbox = featureElement?.querySelector('input[type="checkbox"]');
                
                const newOptionElement = this.elementBuilder.createOptionElement(
                    featureIndex, newIndex, option, availableMetrics, featureRecurringCheckbox
                );
                newOptionElement.classList.add(this.config.getClass('fade_in'));
                
                // Insert before add option row - just like the old system
                const addOptionRow = featureElement?.querySelector('.add-option-row');
                if (addOptionRow) {
                    addOptionRow.parentNode.insertBefore(newOptionElement, addOptionRow);
                    
                    // Simple scroll like the old system - expand and scroll
                    setTimeout(() => {
                        this.expandCollapseController.toggleExpandCollapse(newOptionElement, true);
                        this.simpleScrollToElement(newOptionElement);
                        // Use the full height calculation system instead of simple multipliers
                        this.expandCollapseController.recalculateAllHeights();
                    }, 100);
                }
            }
        } else if (type === 'addon') {
            // Create and insert the new addon element directly, like the old system
            const data = this.dataManager.getData();
            const addon = data.features[featureIndex]?.options[optionIndex]?.addons[newIndex];
            
            if (addon) {
                const optionElement = this.container.querySelector(
                    `[data-feature-index="${featureIndex}"] [data-option-index="${optionIndex}"]`
                );
                const availableMetrics = this.dataManager.getAvailableMetrics();
                const featureElement = optionElement?.closest('.feature');
                const featureRecurringCheckbox = featureElement?.querySelector('input[type="checkbox"]');
                
                const newAddonElement = this.elementBuilder.createAddonElement(
                    featureIndex, optionIndex, newIndex, addon,
                    availableMetrics, featureRecurringCheckbox
                );
                newAddonElement.classList.add(this.config.getClass('fade_in'));
                
                // Insert before add addon row - just like the old system
                const addAddonRow = optionElement?.querySelector('.add-addon-row');
                if (addAddonRow) {
                    addAddonRow.parentNode.insertBefore(newAddonElement, addAddonRow);
                    
                    // Simple scroll like the old system - expand and scroll
                    setTimeout(() => {
                        this.expandCollapseController.toggleExpandCollapse(newAddonElement, true);
                        this.simpleScrollToElement(newAddonElement);
                        // Use the full height calculation system instead of simple multipliers
                        this.expandCollapseController.recalculateAllHeights();
                    }, 100);
                }
            }
        }
    }

    /**
     * Simple scroll function like the old monolith system
     * @param {HTMLElement} element - Element to scroll to
     */
    simpleScrollToElement(element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    /**
     * Update parent container heights like the old monolith system
     * @param {HTMLElement} element - Element that was added
     */
    updateParentContainers(element) {
        // Find parent option if it exists
        const optionEl = element.closest('.option');
        if (optionEl) {
            const optionContent = optionEl.querySelector('.content');
            if (optionContent && optionContent.classList.contains('open')) {
                // Set a generous max-height that won't need adjustment
                optionContent.style.maxHeight = Math.max(optionContent.scrollHeight * 2.5, 2000) + 'px';
            }
        }
        
        // Find parent feature
        const featureEl = element.closest('.feature');
        if (featureEl) {
            const featureContent = featureEl.querySelector('.feature-content');
            if (featureContent && featureContent.classList.contains('open')) {
                // Set a generous max-height that won't need adjustment
                featureContent.style.maxHeight = Math.max(featureContent.scrollHeight * 2.5, 3000) + 'px';
            }
        }
    }

    async handleFeatureCreateSuccess(data) {
        const { feature, index } = data;
        const availableMetrics = this.dataManager.getAvailableMetrics();
        
        const featureElement = this.elementBuilder.createFeatureElement(
            index, feature, availableMetrics
        );
        featureElement.classList.add(this.config.getClass('fade_in'));
        
        // Insert before add button
        if (this.addFeatureButton) {
            this.container.insertBefore(featureElement, this.addFeatureButton);
            
            // Wait for DOM insertion to complete
            await new Promise(resolve => setTimeout(resolve, 10));
            
            // Sequential async operations for proper timing
            await this.expandCollapseController.expandFeatureAsync(featureElement);
            await this.expandCollapseController.updateAllOpenContainersAsync();
            await this.expandCollapseController.scrollIntoViewWithOffsetAsync(featureElement);
        }
    }

    async handleOptionCreateSuccess(data) {
        console.log('🆕 handleOptionCreateSuccess: Starting option creation sequence', data);
        const { option, featureIndex, optionIndex } = data;
        
        const featureElement = this.container.querySelector(`[data-feature-index="${featureIndex}"]`);
        if (!featureElement) {
            console.log('❌ handleOptionCreateSuccess: Feature element not found');
            return;
        }
        
        const availableMetrics = this.dataManager.getAvailableMetrics();
        const featureRecurringCheckbox = featureElement.querySelector('input[type="checkbox"]');
        
        const optionElement = this.elementBuilder.createOptionElement(
            featureIndex, optionIndex, option, availableMetrics, featureRecurringCheckbox
        );
        optionElement.classList.add(this.config.getClass('fade_in'));
        console.log('🏗️ handleOptionCreateSuccess: Option element created', optionElement);
        
        // Insert before add option row
        const addOptionRow = featureElement.querySelector('.add-option-row');
        if (addOptionRow) {
            addOptionRow.parentNode.insertBefore(optionElement, addOptionRow);
            console.log('📍 handleOptionCreateSuccess: Option element inserted into DOM');
            
            // Wait for DOM insertion to complete
            await new Promise(resolve => setTimeout(resolve, 10));
            console.log('✅ handleOptionCreateSuccess: DOM insertion wait complete');
            
            // Sequential async operations for proper timing
            console.log('🔄 handleOptionCreateSuccess: Starting async sequence...');
            await this.expandCollapseController.expandFeatureAsync(featureElement);
            await this.expandCollapseController.toggleExpandCollapseAsync(optionElement, true);
            await this.expandCollapseController.updateAllOpenContainersAsync();
            await this.expandCollapseController.scrollIntoViewWithOffsetAsync(optionElement);
            console.log('🎉 handleOptionCreateSuccess: Complete sequence finished!');
        }
    }

    async handleAddonCreateSuccess(data) {
        const { addon, featureIndex, optionIndex, addonIndex } = data;
        
        const optionElement = this.container.querySelector(
            `[data-feature-index="${featureIndex}"] [data-option-index="${optionIndex}"]`
        );
        if (!optionElement) return;
        
        const availableMetrics = this.dataManager.getAvailableMetrics();
        const featureElement = optionElement.closest('.feature');
        const featureRecurringCheckbox = featureElement.querySelector('input[type="checkbox"]');
        
        const addonElement = this.elementBuilder.createAddonElement(
            featureIndex, optionIndex, addonIndex, addon,
            availableMetrics, featureRecurringCheckbox
        );
        addonElement.classList.add(this.config.getClass('fade_in'));
        
        // Insert before add addon row
        const addAddonRow = optionElement.querySelector('.add-addon-row');
        if (addAddonRow) {
            addAddonRow.parentNode.insertBefore(addonElement, addAddonRow);
            
            // Wait for DOM insertion to complete
            await new Promise(resolve => setTimeout(resolve, 10));
            
            // Sequential async operations for proper timing
            await this.expandCollapseController.expandFeatureAsync(featureElement);
            await this.expandCollapseController.toggleExpandCollapseAsync(optionElement, true);
            await this.expandCollapseController.toggleExpandCollapseAsync(addonElement, true);
            await this.expandCollapseController.updateAllOpenContainersAsync();
            await this.expandCollapseController.scrollIntoViewWithOffsetAsync(addonElement);
        }
    }

    /**
     * Show loading state
     */
    showLoading() {
        if (this.container) {
            this.container.innerHTML = '<div class="loading">Loading pricing data...</div>';
        }
    }

    /**
     * Show error state
     * @param {string} message - Error message to display
     */
    showError(message) {
        if (this.container) {
            this.container.innerHTML = `<div class="error">Error: ${message}</div>`;
        }
    }

    /**
     * Show empty state
     */
    showEmpty() {
        if (this.container) {
            this.container.innerHTML = '<div class="empty">No features configured. Click "Add Feature" to get started.</div>';
        }
    }

    /**
     * Get current view state for debugging
     * @returns {Object} View state information
     */
    getViewState() {
        if (!this.container) {
            return { initialized: false };
        }
        
        const features = this.container.querySelectorAll('.feature');
        const openFeatures = this.container.querySelectorAll('.feature .feature-content.open');
        const openOptions = this.container.querySelectorAll('.option .content.open');
        const openAddons = this.container.querySelectorAll('.addon .content.open');
        
        return {
            initialized: true,
            featureCount: features.length,
            openFeatures: openFeatures.length,
            openOptions: openOptions.length,
            openAddons: openAddons.length,
            hasAddButton: !!this.addFeatureButton
        };
    }

    /**
     * Clean up view manager
     */
    cleanup() {
        if (this.container) {
            this.container.innerHTML = '';
        }
        this.container = null;
        this.addFeatureButton = null;
    }
}