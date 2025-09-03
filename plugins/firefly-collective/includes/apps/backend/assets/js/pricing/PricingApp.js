// PricingApp.js

import { EventBus } from './core/EventBus.js';
import { DataManager } from './core/DataManager.js';
import { ConfigManager } from './core/ConfigManager.js';
import { ApiService } from './services/ApiService.js';
import { GroupSyncService } from './services/GroupSyncService.js';
import { ValidationService } from './services/ValidationService.js';
import { FieldFactory } from './ui/factories/FieldFactory.js';
import { SpecialFieldFactory } from './ui/factories/SpecialFieldFactory.js';
import { DialogService } from './ui/services/DialogService.js';
import { FormManager } from './ui/managers/FormManager.js';
import { ViewManager } from './ui/managers/ViewManager.js';
import { ExpandCollapseController } from './ui/controllers/ExpandCollapseController.js';
import { PricingTypeController } from './ui/controllers/PricingTypeController.js';
import { ElementBuilder } from './ui/builders/ElementBuilder.js';

/**
 * Main application orchestrator for the pricing system
 */
export class PricingApp {
    constructor() {
        this.initialized = false;
        this.container = null;
        this.components = {};
        
        this.initializeComponents();
        this.setupEventListeners();
    }

    /**
     * Initialize all application components
     */
    initializeComponents() {
        // Core components
        this.components.eventBus = new EventBus();
        this.components.config = new ConfigManager();
        this.components.dataManager = new DataManager(this.components.eventBus);
        
        // Services
        this.components.apiService = new ApiService(
            this.components.eventBus, 
            this.components.config
        );
        this.components.groupSyncService = new GroupSyncService(
            this.components.eventBus, 
            this.components.dataManager
        );
        this.components.validationService = new ValidationService(
            this.components.eventBus, 
            this.components.config
        );
        
        // UI services and factories
        this.components.dialogService = new DialogService(
            this.components.eventBus, 
            this.components.config
        );
        this.components.fieldFactory = new FieldFactory(
            this.components.eventBus, 
            this.components.config
        );
        this.components.specialFieldFactory = new SpecialFieldFactory(
            this.components.eventBus, 
            this.components.config, 
            this.components.groupSyncService
        );
        
        // UI controllers
        this.components.expandCollapseController = new ExpandCollapseController(
            this.components.eventBus, 
            this.components.config
        );
        this.components.pricingTypeController = new PricingTypeController(
            this.components.eventBus, 
            this.components.config
        );
        
        // UI managers and builders
        this.components.formManager = new FormManager(
            this.components.eventBus,
            this.components.config,
            this.components.dataManager,
            this.components.fieldFactory,
            this.components.expandCollapseController,
            this.components.validationService
        );
        
        this.components.elementBuilder = new ElementBuilder(
            this.components.eventBus,
            this.components.config,
            this.components.dataManager,
            this.components.fieldFactory,
            this.components.specialFieldFactory,
            this.components.expandCollapseController,
            this.components.pricingTypeController,
            this.components.dialogService,
            this.components.groupSyncService,
            this.components.formManager
        );
        
        this.components.viewManager = new ViewManager(
            this.components.eventBus,
            this.components.config,
            this.components.dataManager,
            this.components.elementBuilder,
            this.components.expandCollapseController
        );
    }

    /**
     * Set up application-level event listeners
     */
    setupEventListeners() {
        // API events
        this.components.eventBus.on('saveStarted', this.handleSaveStarted.bind(this));
        this.components.eventBus.on('saveSuccess', this.handleSaveSuccess.bind(this));
        this.components.eventBus.on('saveError', this.handleSaveError.bind(this));
        this.components.eventBus.on('saveFinished', this.handleSaveFinished.bind(this));
        
        // Form events
        this.components.eventBus.on('addFeatureRequested', this.handleAddFeatureRequested.bind(this));
        
        // Validation events
        this.components.eventBus.on('validationError', this.handleValidationError.bind(this));
        
        // Error handling
        this.components.eventBus.on('apiError', this.handleApiError.bind(this));
        this.components.eventBus.on('dialogError', this.handleDialogError.bind(this));
        
        // Debug events (can be disabled in production)
        if (this.isDebugMode()) {
            this.setupDebugEventListeners();
        }
    }

    /**
     * Initialize the application
     * @param {Object} options - Initialization options
     */
    async initialize(options = {}) {
        if (this.initialized) {
            return;
        }

        try {
            const {
                container,
                wpGlobals,
                baseData
            } = options;

            // Validate required options
            if (!container) {
                throw new Error('Container element is required');
            }
            if (!wpGlobals) {
                throw new Error('WordPress globals are required');
            }
            if (!baseData) {
                throw new Error('Base pricing data is required');
            }

            this.container = container;

            // Initialize components that need external data
            this.components.apiService.initialize(wpGlobals);
            this.components.dataManager.initialize(baseData);
            this.components.viewManager.initialize(container);

            // Set up save button if present
            this.setupSaveButton();

            this.initialized = true;
            
            this.components.eventBus.emit('appInitialized', {
                container,
                dataLength: baseData.features?.length || 0
            });


        } catch (error) {
            this.handleInitializationError(error);
            throw error;
        }
    }

    /**
     * Set up the save button functionality
     */
    setupSaveButton() {
        const saveButton = document.getElementById('apply-button');
        if (saveButton) {
            saveButton.addEventListener('click', this.handleSaveRequest.bind(this));
        }
    }

    /**
     * Handle save request
     */
    async handleSaveRequest() {
        try {
            // Validate data before saving
            const data = this.components.dataManager.getData();
            const validation = this.components.validationService.validatePricingData(data);
            
            if (!validation.isValid) {
                this.components.dialogService.alert(
                    `Cannot save: ${validation.errors[0]}`,
                    { confirmText: 'OK' }
                );
                return;
            }

            // Show warnings if present
            if (validation.warnings.length > 0) {
                const warningMessage = `Warnings found:\n${validation.warnings.join('\n')}\n\nContinue saving?`;
                
                this.components.dialogService.showDialog(
                    warningMessage,
                    () => this.performSave(),
                    {
                        confirmText: 'Save Anyway',
                        cancelText: 'Cancel'
                    }
                );
            } else {
                await this.performSave();
            }

        } catch (error) {
            this.components.eventBus.emit('saveError', error);
        }
    }

    /**
     * Perform the actual save operation
     */
    async performSave() {
        const data = this.components.dataManager.getData();
        const nameChanges = this.components.dataManager.getNameChanges();
        
        await this.components.apiService.savePricing(data, nameChanges);
    }

    /**
     * Get a component instance
     * @param {string} componentName - Name of the component
     * @returns {Object|null} Component instance
     */
    getComponent(componentName) {
        return this.components[componentName] || null;
    }

    /**
     * Check if debug mode is enabled
     * @returns {boolean} True if debug mode is enabled
     */
    isDebugMode() {
        return window.pricingAppDebug === true || 
               new URLSearchParams(window.location.search).has('debug');
    }

    /**
     * Set up debug event listeners
     */
    setupDebugEventListeners() {
        // Log all events for debugging
        const originalEmit = this.components.eventBus.emit;
        this.components.eventBus.emit = function(event, data) {
            return originalEmit.call(this, event, data);
        };
    }

    // Event handlers

    handleSaveStarted() {
        const loader = document.getElementById('pricing-loader');
        if (loader) {
            loader.style.display = 'block';
        }
        
        // Disable save button
        const saveButton = document.getElementById('apply-button');
        if (saveButton) {
            saveButton.disabled = true;
            saveButton.textContent = 'Saving...';
        }
    }

    handleSaveSuccess(result) {
        
        // Clear name changes since save was successful
        this.components.dataManager.clearNameChanges();
        
        // Show success message (could be made more sophisticated)
        if (this.isDebugMode()) {
            this.components.dialogService.alert('Pricing data saved successfully!');
        }
    }

    handleSaveError(error) {
        
        const errorMessage = error.message || 'An error occurred while saving. Please try again.';
        this.components.dialogService.alert(errorMessage);
    }

    handleSaveFinished() {
        const loader = document.getElementById('pricing-loader');
        if (loader) {
            loader.style.display = 'none';
        }
        
        // Re-enable save button
        const saveButton = document.getElementById('apply-button');
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = 'Apply Changes';
        }
    }

    handleAddFeatureRequested() {
        this.components.formManager.showNewFeatureForm();
    }

    handleValidationError(data) {
        
        if (data.field && data.error) {
            // Could implement field-level error display here
        }
    }

    handleApiError(data) {
        
        const message = `API Error: ${data.message}`;
        this.components.dialogService.alert(message);
    }

    handleDialogError(data) {
    }

    handleInitializationError(error) {
        if (this.container) {
            this.container.innerHTML = `
                <div class="error">
                    <h3>Failed to Initialize Pricing System</h3>
                    <p>Error: ${error.message}</p>
                    <p>Please refresh the page and try again.</p>
                </div>
            `;
        }
    }

    /**
     * Get application state for debugging
     * @returns {Object} Application state
     */
    getAppState() {
        return {
            initialized: this.initialized,
            containerExists: !!this.container,
            dataManagerInitialized: this.components.dataManager.initialized,
            apiServiceInitialized: this.components.apiService.initialized,
            activeForm: this.components.formManager.hasActiveForm(),
            viewState: this.components.viewManager.getViewState(),
            validation: this.components.validationService.validatePricingData(
                this.components.dataManager.getData()
            )
        };
    }

    /**
     * Manually trigger a data validation
     * @returns {Object} Validation result
     */
    validateData() {
        const data = this.components.dataManager.getData();
        const result = this.components.validationService.validatePricingData(data);
        
        return result;
    }

    /**
     * Export current data (for debugging or backup)
     * @returns {Object} Current pricing data
     */
    exportData() {
        return {
            pricingData: this.components.dataManager.getData(),
            nameChanges: this.components.dataManager.getNameChanges(),
            timestamp: new Date().toISOString()
        };
    }

    /**
     * Clean up the application
     */
    destroy() {
        if (!this.initialized) return;

        // Clean up all components
        Object.values(this.components).forEach(component => {
            if (component.cleanup) {
                component.cleanup();
            }
        });

        // Clear event bus
        this.components.eventBus.clear();

        // Reset state
        this.initialized = false;
        this.container = null;
        this.components = {};

    }
}