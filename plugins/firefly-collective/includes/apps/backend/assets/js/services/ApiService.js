// services/ApiService.js

/**
 * Service for handling API communications
 */
export class ApiService {
    constructor(eventBus, config) {
        this.eventBus = eventBus;
        this.config = config;
        this.isLoading = false;
    }

    /**
     * Initialize API service with WordPress globals
     * @param {Object} wpGlobals - WordPress localized script data
     */
    initialize(wpGlobals) {
        this.nonce = wpGlobals.nonce;
        this.apiUrl = wpGlobals.apiUrl;
        this.initialized = true;
    }

    /**
     * Save pricing data to server
     * @param {Object} pricingData - Current pricing data
     * @param {Object} nameChanges - Name change tracking
     * @returns {Promise<Object>} API response
     */
    async savePricing(pricingData, nameChanges) {
        if (!this.initialized) {
            throw new Error('ApiService not initialized');
        }

        if (this.isLoading) {
            throw new Error('Save operation already in progress');
        }

        this.isLoading = true;
        this.eventBus.emit('saveStarted');

        try {
            const response = await fetch(`${this.apiUrl}save-pricing`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.nonce
                },
                body: JSON.stringify({
                    pricingData,
                    nameChanges
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Save operation failed');
            }

            this.eventBus.emit('saveSuccess', result);
            return result;

        } catch (error) {
            console.error('Save pricing error:', error);
            this.eventBus.emit('saveError', error);
            throw error;
        } finally {
            this.isLoading = false;
            this.eventBus.emit('saveFinished');
        }
    }

    /**
     * Check if a save operation is in progress
     * @returns {boolean} True if saving
     */
    isSaving() {
        return this.isLoading;
    }

    /**
     * Generic API request handler
     * @param {string} endpoint - API endpoint
     * @param {Object} options - Request options
     * @returns {Promise<Object>} API response
     */
    async request(endpoint, options = {}) {
        if (!this.initialized) {
            throw new Error('ApiService not initialized');
        }

        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': this.nonce
            }
        };

        const requestOptions = { ...defaultOptions, ...options };

        try {
            const response = await fetch(`${this.apiUrl}${endpoint}`, requestOptions);

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            return await response.json();

        } catch (error) {
            console.error(`API request error (${endpoint}):`, error);
            throw error;
        }
    }

    /**
     * Handle API errors consistently
     * @param {Error} error - Error object
     * @param {string} context - Context where error occurred
     */
    handleError(error, context = 'API request') {
        const errorMessage = error.message || 'Unknown error occurred';
        console.error(`${context} failed:`, error);
        
        this.eventBus.emit('apiError', {
            error,
            context,
            message: errorMessage
        });

        // Could extend this to show user-friendly error messages
        // or implement retry logic here
    }
}