// plugin/assets/js/pricing.js
// Main entry point - replaces the original monolithic pricing.js

import { PricingApp } from './PricingApp.js';

/**
 * Initialize the pricing application when DOM is ready
 */
document.addEventListener('DOMContentLoaded', async () => {
    try {
        // Validate required globals
        if (typeof pricingData === 'undefined') {
            throw new Error('pricingData global not found');
        }
        if (typeof pricingDataSettings === 'undefined') {
            throw new Error('pricingDataSettings global not found');
        }

        // Get container element
        const container = document.getElementById('pricing-form');
        if (!container) {
            throw new Error('Pricing form container not found');
        }

        // Prepare base data (access from window in module context)
        const baseData = window.pricingData.data && Array.isArray(window.pricingData.data.features)
            ? { features: window.pricingData.data.features }
            : { features: [] };

        // Create and initialize the app
        const app = new PricingApp();
        
        await app.initialize({
            container,
            wpGlobals: {
                nonce: window.pricingDataSettings.nonce,
                apiUrl: window.pricingDataSettings.apiUrl
            },
            baseData
        });

        // Make app instance globally accessible for debugging
        if (app.isDebugMode()) {
            window.pricingApp = app;
            console.log('PricingApp instance available as window.pricingApp');
        }

    } catch (error) {
        console.error('Failed to initialize pricing application:', error);
        
        // Show error in container if available
        const container = document.getElementById('pricing-form');
        if (container) {
            container.innerHTML = `
                <div style="padding: 20px; border: 1px solid #dc3545; background: #f8d7da; color: #721c24; border-radius: 4px;">
                    <h3>Pricing System Error</h3>
                    <p><strong>Error:</strong> ${error.message}</p>
                    <p>Please refresh the page. If the problem persists, contact support.</p>
                    <details style="margin-top: 10px;">
                        <summary>Technical Details</summary>
                        <pre style="background: #fff; padding: 10px; margin-top: 5px; overflow: auto;">${error.stack || error.message}</pre>
                    </details>
                </div>
            `;
        }
    }
});

// Export for potential use in other scripts
export { PricingApp };