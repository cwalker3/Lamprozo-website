// plugin/assets/js/dashboard.js

(function() {
    // Store initialization state
    let isInitialized = false;
    
    // Main initialization function
    window.initializeDashboard = function() {
        
        // Prevent multiple initializations
        if (isInitialized) return;
        
        // Check if we have the required elements
        const featuresContainer = document.getElementById('features-container');
        if (!featuresContainer) {
            console.log('Dashboard elements not found, skipping initialization');
            return;
        }

        isInitialized = true;
        if (isPWA || websiteApp) {
            myApi = {
                nonce: window.nonce,
                api_url: window.api_url
            }

            dashboardData = { 
                nonce: window.nonce,
                theme_path: window.theme_path,
                template_path: window.template_path,
                features: window.features,
                stripeKey: window.stripeKey,
                subscription_status: window.subscription_status,
                third_party: window.third_party
            }
        }

        // Disable password reset if from third-party login
        if (typeof dashboardData !== 'undefined' && dashboardData.third_party) {
            const passwordReset = document.querySelector('#reset-password-btn');
            if (passwordReset) {
                passwordReset.style.opacity = '0.3';
                passwordReset.style.pointerEvents = 'none';
                passwordReset.disabled = true;
            }
        }

        // Apply campaign configuration if present
        if (dashboardData.campaign_config) {
            const campaignConfig = dashboardData.campaign_config;
            
            // Store original features before filtering for reference
            const originalFeatures = [...dashboardData.features];
            
            // Filter features based on campaign config
            dashboardData.features = dashboardData.features.filter(feature => {
                if (campaignConfig.features_config[feature.id] && campaignConfig.features_config[feature.id].show) {
                    // Filter options
                    feature.options = feature.options.filter(option => {
                        if (campaignConfig.features_config[feature.id].options[option.id] && 
                            campaignConfig.features_config[feature.id].options[option.id].show) {
                            // Filter addons
                            if (option.addons) {
                                option.addons = option.addons.filter(addon => 
                                    campaignConfig.features_config[feature.id].options[option.id].addons[addon.id]
                                );
                            }
                            return true;
                        }
                        return false;
                    });
                    return feature.options.length > 0;
                }
                return false;
            });
            
            // Clear and rebuild selections object completely
            selections = {};
            
            // Initialize selections for each filtered feature
            dashboardData.features.forEach((feature, fIndex) => {
                selections[fIndex] = [{}]; // Initialize with empty instance
                
                // Apply preselections for this feature
                if (campaignConfig.preselect_config[feature.id]) {
                    const preselect = campaignConfig.preselect_config[feature.id];
                    if (preselect.selectedOption) {
                        // Find the option index in the filtered options
                        const optionIndex = feature.options.findIndex(o => o.id == preselect.selectedOption);
                        if (optionIndex !== -1) {
                            selections[fIndex][0].optionIndex = optionIndex;
                            
                            // Preselect addons
                            if (preselect.selectedAddons && preselect.selectedAddons.length) {
                                selections[fIndex][0].addons = [...preselect.selectedAddons];
                            }
                            
                            // Set quantity if specified
                            if (preselect.quantity && !feature.recurring) {
                                selections[fIndex][0].quantity = parseInt(preselect.quantity) || 1;
                            }
                        }
                    }
                }
            });
            
            saveSelections();
            
            // Hide "Add new" buttons in campaign mode
            setTimeout(() => {
                document.querySelectorAll('.add-new-feature').forEach(btn => {
                    btn.style.display = 'none';
                });
                
                // Also hide delete instance buttons
                document.querySelectorAll('.delete-instance').forEach(btn => {
                    btn.style.display = 'none';
                });
            }, 100);
        }

        // Handle anonymous user form for campaign mode
        if (dashboardData.campaign_config && !window.auth_id) {
            // Find the campaign head notice and inject the form after it
            const campaignHead = document.getElementById('campaign-head');
            
            if (campaignHead) {
                // Create the conditional anonymous user form
                const anonFormHTML = `
                    <div id="anonymous-user-form">
                        <h3>Contact Information</h3>
                        <div class="anon-field">
                            <input type="text" id="anon-firstName" placeholder="First Name (optional)">
                        </div>
                        <div class="anon-field">
                            <input type="text" id="anon-lastName" placeholder="Last Name (optional)">
                        </div>
                        <div class="anon-field">
                            <input type="email" id="anon-email" placeholder="Email Address (required)" required>
                        </div>
                        <div class="anon-field">
                            <input type="tel" id="anon-phone" placeholder="Phone Number (optional)">
                        </div>
                        <div id="anon-email-error" style="color: red; display: none; margin-top: 5px;">
                            Please enter a valid email address
                        </div>
                        
                        <!-- Account Creation Section (shown/hidden based on recurring features) -->
                        <div id="account-required-section" style="display: none;">
                            <div class="account-required-notice">
                                <strong>Account Required:</strong> Subscription services require an account for management and billing.
                            </div>
                            
                            <!-- Google Sign-in Option -->
                            <div class="account-creation-options">
                                <div class="google-signin-option">
                                    <button type="button" id="campaign-google-signin" class="google-signin-button">
                                        Sign in with Google
                                    </button>
                                    <div class="auth-divider">
                                        <span>or</span>
                                    </div>
                                </div>
                                
                                <!-- Username/Password Fields -->
                                <div class="account-fields" id="manual-account-fields">
                                    <div class="anon-field">
                                        <input type="text" id="anon-username" placeholder="Username (required)" autocomplete="username">
                                        <div id="username-error" style="color: red; display: none; margin-top: 3px; font-size: 12px;"></div>
                                    </div>
                                    <div class="anon-field password-field">
                                        <input type="password" id="anon-password" placeholder="Password (required)" autocomplete="new-password">
                                        <button type="button" class="password-toggle" id="password-toggle">
                                            <span class="password-eye">👁️</span>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Google Account Linked Status (hidden initially) -->
                                <div id="google-account-linked" style="display: none;">
                                    <div class="google-account-status">
                                        <span class="google-icon">✓</span>
                                        <span>Google account linked: <strong id="linked-email"></strong></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Insert the form after the campaign notice
                campaignHead.insertAdjacentHTML('afterend', anonFormHTML);

                // Form interaction handlers
                const emailInput = document.getElementById('anon-email');
                const emailError = document.getElementById('anon-email-error');
                const usernameField = document.getElementById('anon-username');
                const passwordField = document.getElementById('anon-password');
                const passwordToggle = document.getElementById('password-toggle');
                const usernameError = document.getElementById('username-error');
                const accountSection = document.getElementById('account-required-section');
                const googleSigninBtn = document.getElementById('campaign-google-signin');
                const manualAccountFields = document.getElementById('manual-account-fields');
                const googleLinkedSection = document.getElementById('google-account-linked');
                const linkedEmailSpan = document.getElementById('linked-email');

                // Function to check if current selections have recurring features
                function hasRecurringFeatures() {
                    for (const [fIdx, instances] of Object.entries(selections)) {
                        for (const instance of instances) {
                            if (instance.optionIndex !== undefined) {
                                const feature = dashboardData.features[fIdx];
                                if (feature && feature.recurring) {
                                    return true;
                                }
                            }
                        }
                    }
                    return false;
                }
                
                // Function to update form visibility based on selections
                function updateFormVisibility() {
                    const hasRecurring = hasRecurringFeatures();
                    accountSection.style.display = hasRecurring ? 'block' : 'none';
                    
                    // Update email placeholder and validation
                    if (hasRecurring) {
                        emailInput.placeholder = "Email Address (required - will be used for account)";
                    } else {
                        emailInput.placeholder = "Email Address (required)";
                    }
                    
                    updateOrderButton();
                }

                // Google Sign-in handler
                googleSigninBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Set campaign mode flag for auth.js
                    window.campaignMode = true;
                    
                    // Trigger existing Google auth
                    const width = 500;
                    const height = 600;
                    const left = (screen.width - width) / 2;
                    const top = (screen.height - height) / 2;
                    
                    const gapiEndpoint = myApi.gapiDomain || 'https://' + window.location.hostname;
                    window.open(
                        `${gapiEndpoint}/wp-json/custom-api/v1/google-auth-init`,
                        'google-signin',
                        `width=${width},height=${height},left=${left},top=${top}`
                    );
                });

                // Function to handle successful Google authentication
                window.handleCampaignGoogleSuccess = function(authData) {
                    googleAuthData = authData;
                    
                    // Update UI to show linked account
                    manualAccountFields.style.display = 'none';
                    googleLinkedSection.style.display = 'block';
                    linkedEmailSpan.textContent = authData.email || 'Unknown email';
                    
                    // Disable the Google sign-in button
                    googleSigninBtn.disabled = true;
                    googleSigninBtn.style.opacity = '0.6';
                    googleSigninBtn.style.cursor = 'not-allowed';
                    googleSigninBtn.textContent = 'Google Account Linked';
                    
                    // Disable contact form fields since info comes from Google
                    const contactFields = ['anon-firstName', 'anon-lastName', 'anon-email', 'anon-phone'];
                    contactFields.forEach(fieldId => {
                        const field = document.getElementById(fieldId);
                        if (field) {
                            field.disabled = true;
                            field.style.opacity = '0.6';
                            field.style.backgroundColor = '#f5f5f5';
                        }
                    });
                    
                    // Add a note about disabled fields
                    const contactNote = document.createElement('div');
                    contactNote.id = 'contact-fields-note';
                    contactNote.style.cssText = `
                        background: #e8f5e8;
                        border: 1px solid #4caf50;
                        border-radius: 4px;
                        padding: 10px;
                        margin-bottom: 15px;
                        color: #2e7d32;
                        font-size: 14px;
                    `;
                    contactNote.innerHTML = '<strong>Note:</strong> Contact information will be automatically filled from your Google account.';
                    
                    // Insert after the contact information heading
                    const contactHeading = document.querySelector('#anonymous-user-form h3');
                    if (contactHeading && contactHeading.nextSibling) {
                        contactHeading.parentNode.insertBefore(contactNote, contactHeading.nextSibling);
                    }
                    
                    // Set the auth_id for order processing
                    window.auth_id = authData.auth_id;
                    
                    // Update order button
                    updateOrderButton();
                };

                // Function to handle manual account creation success (NEW)
                function handleManualAccountSuccess() {
                    // Show success message similar to Google flow
                    setTimeout(() => {
                        const accountMessage = document.createElement('div');
                        accountMessage.style.cssText = `
                            position: fixed;
                            top: 80px;
                            right: 20px;
                            background: #2196F3;
                            color: white;
                            padding: 15px 20px;
                            border-radius: 4px;
                            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                            z-index: 10000;
                            max-width: 300px;
                        `;
                        accountMessage.innerHTML = `
                            <strong>Account Created!</strong><br>
                            Your account has been successfully created and you are now logged in.
                        `;
                        document.body.appendChild(accountMessage);
                        
                        // Remove message after 8 seconds
                        setTimeout(() => {
                            accountMessage.remove();
                        }, 8000);
                    }, 2000);

                    // Update form UI to show account is linked
                    const manualAccountFields = document.getElementById('manual-account-fields');
                    const googleLinkedSection = document.getElementById('google-account-linked');
                    const linkedEmailSpan = document.getElementById('linked-email');
                    
                    if (manualAccountFields && googleLinkedSection) {
                        manualAccountFields.style.display = 'none';
                        googleLinkedSection.style.display = 'block';
                        
                        // Show the email from the form
                        const emailInput = document.getElementById('anon-email');
                        if (emailInput && linkedEmailSpan) {
                            linkedEmailSpan.textContent = emailInput.value;
                        }
                        
                        // Update the status text
                        const statusDiv = googleLinkedSection.querySelector('.google-account-status span:first-child');
                        if (statusDiv) {
                            statusDiv.textContent = '✓';
                        }
                        const statusText = googleLinkedSection.querySelector('.google-account-status span:last-child');
                        if (statusText) {
                            statusText.innerHTML = 'Account created: <strong>' + (linkedEmailSpan.textContent || 'Success') + '</strong>';
                        }
                    }

                    // Disable contact form fields
                    const contactFields = ['anon-firstName', 'anon-lastName', 'anon-email', 'anon-phone'];
                    contactFields.forEach(fieldId => {
                        const field = document.getElementById(fieldId);
                        if (field) {
                            field.disabled = true;
                            field.style.opacity = '0.6';
                            field.style.backgroundColor = '#f5f5f5';
                        }
                    });

                    // Add success note
                    const existingNote = document.getElementById('contact-fields-note');
                    if (!existingNote) {
                        const contactNote = document.createElement('div');
                        contactNote.id = 'contact-fields-note';
                        contactNote.style.cssText = `
                            background: #e8f5e8;
                            border: 1px solid #4caf50;
                            border-radius: 4px;
                            padding: 10px;
                            margin-bottom: 15px;
                            color: #2e7d32;
                            font-size: 14px;
                        `;
                        contactNote.innerHTML = '<strong>Success!</strong> Your account has been created and you are now logged in.';
                        
                        const contactHeading = document.querySelector('#anonymous-user-form h3');
                        if (contactHeading && contactHeading.nextSibling) {
                            contactHeading.parentNode.insertBefore(contactNote, contactHeading.nextSibling);
                        }
                    }
                }

                // Function to check for auth_id changes (detect manual account creation)
                function detectAccountCreation() {
                    // Only run in campaign mode for anonymous users
                    if (!dashboardData.campaign_config || window.auth_id || googleAuthData) {
                        return;
                    }

                    // Function to get auth_id from cookie
                    function getAuthIdFromCookie() {
                        const name = 'auth_id=';
                        const decodedCookie = decodeURIComponent(document.cookie);
                        const ca = decodedCookie.split(';');
                        for(let i = 0; i < ca.length; i++) {
                            let c = ca[i];
                            while (c.charAt(0) === ' ') {
                                c = c.substring(1);
                            }
                            if (c.indexOf(name) === 0) {
                                return c.substring(name.length, c.length);
                            }
                        }
                        return null;
                    }

                    // Check if auth_id cookie appears
                    const currentAuthId = getAuthIdFromCookie();
                    if (currentAuthId && !window.auth_id) {
                        // Account was created! Set the auth_id and trigger success flow
                        window.auth_id = currentAuthId;
                        handleManualAccountSuccess();
                        
                        // Stop checking
                        if (window.accountCreationChecker) {
                            clearInterval(window.accountCreationChecker);
                            window.accountCreationChecker = null;
                        }
                    }
                }

                // Start checking for account creation if in campaign mode
                if (dashboardData.campaign_config && !window.auth_id) {
                    // Check every 2 seconds for auth_id cookie
                    window.accountCreationChecker = setInterval(detectAccountCreation, 2000);
                    
                    // Stop checking after 5 minutes to prevent endless polling
                    setTimeout(() => {
                        if (window.accountCreationChecker) {
                            clearInterval(window.accountCreationChecker);
                            window.accountCreationChecker = null;
                        }
                    }, 300000); // 5 minutes
                }
                
                // Email validation
                async function validateEmail() {
                    const email = emailInput.value.trim();
                    const isValidFormat = email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
                    
                    if (email && !isValidFormat) {
                        emailError.textContent = 'Please enter a valid email address';
                        emailError.style.display = 'block';
                        updateOrderButton();
                        return false;
                    }
                    
                    // Only check email exists if we need manual account creation and Google isn't linked
                    if (isValidFormat && hasRecurringFeatures() && !googleAuthData) {
                        try {
                            const response = await fetch(`${myApi.api_url}check-email?email=${encodeURIComponent(email)}`);
                            const data = await response.json();
                            
                            if (data.exists) {
                                emailError.textContent = 'An account with this email already exists. Please log in instead.';
                                emailError.style.display = 'block';
                                updateOrderButton();
                                return false;
                            }
                        } catch (error) {
                            console.error('Email validation error:', error);
                        }
                    }
                    
                    emailError.style.display = 'none';
                    updateOrderButton();
                    return isValidFormat;
                }
                
                // Username validation
                async function validateUsername() {
                    // Skip validation if Google is linked
                    if (googleAuthData) {
                        return true;
                    }
                    
                    const username = usernameField.value.trim();
                    if (!username) {
                        usernameError.style.display = 'none';
                        updateOrderButton();
                        return !hasRecurringFeatures(); // Required only for recurring
                    }
                    
                    try {
                        const response = await fetch(`${myApi.api_url}check-username?username=${encodeURIComponent(username)}`);
                        const data = await response.json();
                        
                        if (data.exists) {
                            usernameError.textContent = 'Username already taken';
                            usernameError.style.display = 'block';
                            updateOrderButton();
                            return false;
                        } else {
                            usernameError.style.display = 'none';
                            updateOrderButton();
                            return true;
                        }
                    } catch (error) {
                        console.error('Username validation error:', error);
                        updateOrderButton();
                        return true; // Allow on error
                    }
                }
                
                // Event listeners
                emailInput.addEventListener('input', () => {
                    clearTimeout(window.emailValidationTimeout);
                    window.emailValidationTimeout = setTimeout(validateEmail, 500);
                });
                emailInput.addEventListener('blur', validateEmail);
                
                // Username validation with debounce
                usernameField.addEventListener('input', () => {
                    clearTimeout(window.usernameValidationTimeout);
                    window.usernameValidationTimeout = setTimeout(validateUsername, 500);
                });
                
                // Password toggle
                passwordToggle.addEventListener('click', function() {
                    const isPassword = passwordField.type === 'password';
                    passwordField.type = isPassword ? 'text' : 'password';
                    this.querySelector('.password-eye').textContent = isPassword ? '🙈' : '👁️';
                });
                
                // Listen for selection changes to update form visibility
                const originalUpdateInvoice = updateInvoice;
                updateInvoice = function() {
                    originalUpdateInvoice.call(this);
                    updateFormVisibility();
                };
                
                // Initial form visibility check
                updateFormVisibility();
                
                // Also trigger validation on other inputs to update button state
                ['anon-firstName', 'anon-lastName', 'anon-phone', 'anon-password'].forEach(id => {
                    const input = document.getElementById(id);
                    if (input) {
                        input.addEventListener('input', updateOrderButton);
                    }
                });
            }
        }

        // Global state: keys are feature type indexes; each value is an array of instance objects.
        // Each instance object: { optionIndex: number, addons: [number, ...], quantity?: number }
        var selections = {};

        // Keeps track of mode
        let estimateMode = false;

        // Store Google auth state
        let googleAuthData = null;

        // Check for corrupt or invalid session data and clean it
        try {
            const orderData = sessionStorage.getItem('placedOrder');
            if (orderData) {
                const orderInfo = JSON.parse(orderData);
                if (!orderInfo || !orderInfo.orderID || (orderInfo.status !== 'pending' && orderInfo.status !== 'paid')) {
                    // If the data doesn't look like a valid order, clear it
                    sessionStorage.removeItem('placedOrder');
                }
            }
        } catch (e) {
            console.error('Error parsing order data on load', e);
            sessionStorage.removeItem('placedOrder');
        }

        if (hasValidOrder()) disableFormInteraction();
        if (hasValidOrder()) {
            disableFormInteraction();
            
            // If the order is already paid, show success immediately
            if (isOrderPaid()) {
                setTimeout(() => {
                    showPaymentSuccess();
                }, 100);
            }
        }

        // Load any saved state from sessionStorage
        const stored = sessionStorage.getItem('priceCalcSelections');
        if ( stored && stored !== 'undefined' ) {
            try {
                selections = JSON.parse( stored );
            } catch(e) {
                console.warn('Corrupt priceCalcSelections, clearing it:', e);
                sessionStorage.removeItem('priceCalcSelections');
                selections = {};
            }
        }

        if (isOrderPaid()) {
            setTimeout(() => {
                disableFormInteraction();
            }, 0);
        }

        const updateModal = document.getElementById('update-payment-modal');
        const invoiceDetails = document.getElementById('invoice-details');
        const invoiceTotal = document.getElementById('invoice-total');
        const themePath = dashboardData.theme_path;

        // Clear container to prevent duplication.
        if (featuresContainer) featuresContainer.innerHTML = '';

        // Safely parse floats without returning NaN
        function parseSafe(val, fallback = 0) {
            let p = parseFloat(val);
            return isNaN(p) ? fallback : p;
        }

        // Save selections to sessionStorage
        function saveSelections() {
            sessionStorage.setItem('priceCalcSelections', JSON.stringify(selections));
        }

        // Safely extract a description from either an object or string
        function getDescriptionText(desc) {
            if (!desc) return '';
            if (typeof desc === 'object' && desc.text) return desc.text;
            if (typeof desc === 'string') return desc;
            return '';
        }

        function formatFieldLabel(key) {
            // Remove _user/_display suffix
            let text = key.replace(/_user$|_display$/, '');
            
            // Convert camelCase to Title Case With Spaces
            return text
                .replace(/([A-Z])/g, ' $1') // Add space before capital letters
                .replace(/^./, function(str) { return str.toUpperCase(); }) // Capitalize first letter
                .trim();
        }

        // Smooth scroll helper
        function smoothScrollToElement(el, offset = 120) {
            const rect = el.getBoundingClientRect();
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            const targetY = rect.top + scrollTop - offset;
            window.scrollTo({
                top: targetY,
                behavior: 'smooth'
            });
        }

        // Check if an addon uses multiplication.
        function isMultiply(addon) {
            if (addon.priceModifierType && typeof addon.priceModifierType === 'object') {
                return addon.priceModifierType.selected === 1;
            }
            if (typeof addon.priceModifierType === 'string') {
                return addon.priceModifierType.toLowerCase() === 'multiply';
            }
            return false;
        }

        // Determine if an instance (option + selected addons) has any range
        function instanceHasRange(feature, instance) {
            const option = feature.options[instance.optionIndex];
            if (!option) return false;

            // If option has a non-zero floor or ceiling, that's a range
            const floorVal = parseSafe(option.priceFloor);
            const ceilVal  = parseSafe(option.priceCeiling);
            if (floorVal !== 0 || ceilVal !== 0) {
                return true;
            }

            // Check any selected addons for a non-zero floor or ceiling
            if (instance.addons && Array.isArray(instance.addons)) {
                for (let aIndex of instance.addons) {
                    const addon = option.addons[aIndex];
                    if (addon) {
                        const addonFloor = parseSafe(addon.floorPriceMod);
                        const addonCeil  = parseSafe(addon.ceilingPriceMod);
                        if (addonFloor !== 0 || addonCeil !== 0) {
                            return true;
                        }
                    }
                }
            }
            return false;
        }

        function disableFormInteraction() {
            // Disable the features container (the form)
            const featuresContainer = document.getElementById('features-container');
            if (featuresContainer) {
                featuresContainer.style.pointerEvents = 'none';
                featuresContainer.style.opacity = '0.7';
            }
            
            // Optionally add a subtle visual indicator that the form is locked
            const lockIndicator = document.createElement('div');
            lockIndicator.className = 'order-placed-indicator';
            lockIndicator.innerHTML = `
                <div class="lock-message">
                    <i class="fa fa-check-circle"></i> Order placed successfully! Pay Below.
                </div>
            `;
            
            // Style the indicator
            lockIndicator.style.position = 'absolute';
            lockIndicator.style.top = '10px';
            lockIndicator.style.right = '10px';
            lockIndicator.style.backgroundColor = 'rgba(76, 175, 80, 0.9)';
            lockIndicator.style.color = 'white';
            lockIndicator.style.padding = '8px (--fontSizeSmallest)';
            lockIndicator.style.borderRadius = '4px';
            lockIndicator.style.boxShadow = '0 2px 4px rgba(0,0,0,0.2)';
            lockIndicator.style.zIndex = '1';
            lockIndicator.style.fontSize = '14px';
            
            // Find a good container to append to
            const container = document.querySelector('.price-calculator-container') || 
                            document.querySelector('.dashboard-content') ||
                            featuresContainer.parentNode;
                            
            if (container) {
                // Check if we already added the indicator
                const existingIndicator = document.querySelector('.order-placed-indicator');
                if (!existingIndicator) {
                    container.style.position = 'relative';
                    container.appendChild(lockIndicator);
                }
            }
        }

        function showOrderConfirmation() {
            // Create modal (only once per click)
            const modal = document.createElement('div');
            modal.className = 'order-confirm-modal';
            modal.innerHTML = `
                <div class="order-confirm-content">
                <h3>Confirm Your Order</h3>
                <p>Are you sure you want to place this order?</p>
                <div class="order-confirm-buttons">
                    <button class="confirm-button">Confirm</button>
                    <button class="cancel-button">Cancel</button>
                </div>
                </div>
            `;
            document.body.appendChild(modal);

            // Force reflow and fade-in
            requestAnimationFrame(() => modal.classList.add('active'));

            const cleanup = () => {
                modal.classList.remove('active');
                // remove after fade
                setTimeout(() => modal.remove(), 300);
            };

            // Confirm: once, then cleanup + proceed
            modal.querySelector('.confirm-button')
            .addEventListener('click', () => {
                cleanup();
                submitOrder();
            }, { once: true });

            // Cancel: once, cleanup only
            modal.querySelector('.cancel-button')
                .addEventListener('click', () => cleanup(), { once: true });

            // Click outside content also cancels
            modal.addEventListener('click', e => {
                if (e.target === modal) cleanup();
            }, { once: true });
            }


        function showLoadingOverlay() {
            const overlay = document.createElement('div');
            overlay.className = 'loading-overlay';
            overlay.innerHTML = `<div class="loading-spinner">
                <img src="${dashboardData.template_path}/images/loading.gif" alt="Loading">
            </div>`;
            document.body.appendChild(overlay);
            
            // Force reflow for animation
            void overlay.offsetWidth;
            overlay.classList.add('active');
            return overlay;
        }

        function hideLoadingOverlay(overlay) {
            overlay.classList.remove('active');
            setTimeout(() => overlay.remove(), 300);
        }

        async function submitOrder() {
            const overlay = showLoadingOverlay();
            
            // Find all selections that have an optionIndex
            let orderItems = [];
            let foundSelections = false;

            // Extract data from selections
            for (const [fIdx, instances] of Object.entries(selections)) {
                for (const instance of instances) {
                    if (instance.optionIndex !== undefined) {
                        // Only count as a valid selection if it points to a real option
                        const feature = dashboardData.features[fIdx];
                        if (feature && feature.options && feature.options[instance.optionIndex]) {
                            const option = feature.options[instance.optionIndex];
                            
                            // Use actual database IDs instead of array indices
                            const orderItem = {
                                featureId: feature.id,
                                optionId: option.id,
                                addonIds: instance.addons || [],
                                userData: {},
                                clientCalculatedPrice: calculateInstancePrice(feature, instance)
                            };

                            // Add price option index as a dedicated field
                            if (instance.priceOptionIndex !== undefined) {
                                orderItem.priceOptionIndex = instance.priceOptionIndex;
                            }

                            // Add quantity for non-recurring features
                            if (!feature.recurring && instance.quantity) {
                                orderItem.quantity = parseInt(instance.quantity);
                            }

                            // Collect user fields
                            if (instance.userFields) {
                                orderItem.userData = { ...orderItem.userData, ...instance.userFields };
                            }

                            // Add feature-level fields if they exist
                            if (instance.featureFields) {
                                orderItem.userData = { ...orderItem.userData, ...instance.featureFields };
                            }
                            
                            orderItems.push(orderItem);
                            foundSelections = true;
                        }
                    }
                }
            }

            if (!foundSelections || orderItems.length === 0) {
                hideLoadingOverlay(overlay);
                alert('Please select at least one item to order.');
                return;
            }

            // Check for existing order
            let orderID = null;
            const existingOrder = sessionStorage.getItem('placedOrder');
            if (existingOrder) {
                try {
                    const orderInfo = JSON.parse(existingOrder);
                    if (orderInfo.orderID) orderID = orderInfo.orderID;
                } catch (e) {
                    console.error('Error parsing existing order data', e);
                }
            }
            
            // Handle anonymous user in campaign mode
            let anonUser = null;
            if (dashboardData.campaign_config && !window.auth_id) {
                const firstName = document.getElementById('anon-firstName')?.value || '';
                const lastName  = document.getElementById('anon-lastName')?.value  || '';
                const email     = document.getElementById('anon-email')?.value     || '';
                const phone     = document.getElementById('anon-phone')?.value     || '';
                
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    hideLoadingOverlay(overlay);
                    alert('Please enter a valid email address.');
                    // Re-enable button
                    const btn = document.getElementById('pay-now');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Place Order';
                    }
                    return;
                }
                
                anonUser = { firstName, lastName, email, phone };
                
                // Check if order has recurring features - if so, require account creation
                let hasRecurringFeatures = false;
                for (const item of orderItems) {
                    const feature = dashboardData.features.find(f => f.id === item.featureId);
                    if (feature && feature.recurring) {
                        hasRecurringFeatures = true;
                        break;
                    }
                }
                
                if (hasRecurringFeatures) {
                    // Check if we have Google authentication
                    if (googleAuthData && googleAuthData.auth_id) {
                        // Use Google authentication
                        anonUser.createAccount = true;
                        anonUser.signupMethod = 'google';
                        anonUser.googleAuthId = googleAuthData.auth_id;
                        
                        // Override auth_id for this order
                        window.auth_id = googleAuthData.auth_id;
                    } else {
                        // Use username/password authentication (existing logic)
                        const username = document.getElementById('anon-username')?.value.trim() || '';
                        const password = document.getElementById('anon-password')?.value.trim() || '';
                        
                        if (!username || !password) {
                            hideLoadingOverlay(overlay);
                            alert('Username and password are required for subscription services.');
                            const btn = document.getElementById('pay-now');
                            if (btn) {
                                btn.disabled = false;
                                btn.textContent = 'Place Order';
                            }
                            return;
                        }
                        
                        anonUser.createAccount = true;
                        anonUser.signupMethod = 'username';
                        anonUser.username = username;
                        anonUser.password = password;
                    }
                }
            }

            // Build payload (rest remains the same)
            const orderData = { items: orderItems };
            if (window.auth_id) {
                orderData.auth_id = window.auth_id;
            } else if (anonUser) {
                orderData.anonUser = anonUser;
            }

            // Add orderID if we have one
            if (orderID) {
                orderData.orderID = orderID;
            }

            // Send request
            try {
                const response = await fetch(`${myApi.api_url}place-order`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(orderData)
                });

                if (response.status === 403) {
                    console.error('You\'re not authorized to place this order.');
                    hideLoadingOverlay(overlay);
                    return;
                }

                // For any non-2xx status, attempt to pull an error message from JSON
                if (!response.ok) {
                    let errMsg = response.statusText;
                    try {
                        const errJson = await response.json();
                        errMsg = errJson.error || errJson.message || errMsg;
                    } catch (e) { /* ignore */ }
                    console.error(`Error (${response.status}): ${errMsg}`);
                    hideLoadingOverlay(overlay);
                    return;
                }

                // Success path
                const data = await response.json();

                // Persist a “pending” order
                sessionStorage.setItem('placedOrder', JSON.stringify({
                    recordId: data.records ? data.records[0].recordId : null,
                    orderID: data.orderID,
                    status: 'pending',
                    itemCount: orderItems.length,
                    totalValue: data.totalOrderValue,
                    type: data.type === 'subscription' ? 'subscription' : 'one_time',
                    subscriptionId: data.subscriptionId || undefined
                }));

                updateOrderButton();
                // → no disableFormInteraction() here

                if (data.type === 'subscription') {
                    handleSubscriptionPayment(data.clientSecret, data.subscriptionId);
                } else {
                    initializeStripePayment();
                }

                hideLoadingOverlay(overlay);
            } catch (error) {
                hideLoadingOverlay(overlay);
                alert(error.message || 'An error occurred while placing your order.');
                console.error('Order submission error:', error);
            }
        }

        // Check if there's actually a valid order
        function hasValidOrder() {
            const orderData = sessionStorage.getItem('placedOrder');
            if (!orderData) return false;
            
            try {
                const orderInfo = JSON.parse(orderData);
                // Make sure the order data has required fields that would indicate
                // it's a properly formed order
                return orderInfo && orderInfo.orderID && orderInfo.status === 'pending';
            } catch (e) {
                console.error('Error parsing order data', e);
                // If there's an error parsing the data, it's not valid
                sessionStorage.removeItem('placedOrder'); // Clear invalid data
                return false;
            }
        }
        
        function updateOrderButton() {
            const btn = document.getElementById('pay-now');
            if (!btn) return;

            btn.onclick = null;

            // Check if order is paid AFTER getting the button element
            if (isOrderPaid()) {
                btn.textContent = 'Payment Successful';
                btn.disabled = true;
                btn.style.backgroundColor = '#4CAF50';
                btn.style.cursor = 'not-allowed';
                return;
            }

            // Check if there are any valid selections
            if (!hasValidSelections()) {
                btn.textContent = 'Select Items to Continue';
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
                return;
            }

            // Check validation for anonymous users
            if (dashboardData.campaign_config && !window.auth_id) {
                const emailInput = document.getElementById('anon-email');
                const email = emailInput?.value.trim() || '';
                const isValidEmail = email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
                
                const hasRecurring = hasRecurringFeatures();
                
                // PRIORITY 1: If has recurring features, check account setup
                if (hasRecurring) {
                    // First check if email is provided and valid
                    if (!isValidEmail) {
                        btn.textContent = 'Complete Account Setup';
                        btn.disabled = true;
                        btn.style.opacity = '0.5';
                        return;
                    }
                    
                    // Check if we have either Google auth OR username/password
                    if (googleAuthData) {
                        // Google authentication is complete
                        btn.disabled = false;
                        btn.style.opacity = '1';
                        btn.style.cursor = 'pointer';
                    } else {
                        // Check username and password for manual creation
                        const username = document.getElementById('anon-username')?.value.trim() || '';
                        const password = document.getElementById('anon-password')?.value.trim() || '';
                        const usernameError = document.getElementById('username-error');
                        const emailError = document.getElementById('anon-email-error');
                        
                        if (!username || !password) {
                            btn.textContent = 'Complete Account Setup';
                            btn.disabled = true;
                            btn.style.opacity = '0.5';
                            return;
                        }
                        
                        if (usernameError?.style.display === 'block' || emailError?.style.display === 'block') {
                            btn.textContent = 'Fix Validation Errors';
                            btn.disabled = true;
                            btn.style.opacity = '0.5';
                            return;
                        }
                        
                        btn.disabled = false;
                        btn.style.opacity = '1';
                        btn.style.cursor = 'pointer';
                    }
                }
                // PRIORITY 2: No recurring features, just need email
                else {
                    if (!isValidEmail) {
                        btn.textContent = 'Enter Email to Continue';
                        btn.disabled = true;
                        btn.style.opacity = '0.5';
                        return;
                    }
                    
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    btn.style.cursor = 'pointer';
                }
            }

            // Rest of existing logic for order states...
            if (hasValidOrder() && !isOrderPaid()) {
                btn.textContent = 'Pay Now';
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                btn.onclick = function(e) {
                    e.preventDefault();
                    initializeStripePayment();
                };
            } else if (estimateMode) {
                btn.textContent = 'Request Estimate';
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                btn.onclick = function(e) {
                    e.preventDefault();
                    alert('Estimate request functionality coming soon!');
                };
            } else {
                btn.textContent = 'Place Order';
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                btn.onclick = function(e) {
                    e.preventDefault();
                    if (btn.disabled) return;
                    btn.disabled = true;
                    btn.textContent = 'Processing...';
                    showOrderConfirmation();
                };
            }
        }

        // -------------------------
        // INVOICE CALCULATION LOGIC
        // (All addition of addons is done here, not in the option details)
        // -------------------------

        // Calculate a single instance's price (no range) for invoice
        function calculateInstancePrice(feature, instance) {
            if (!instance || instance.optionIndex === undefined) return 0;
            const option = feature.options[instance.optionIndex];
            if (!option) return 0;

            // Get base price
            let price = parseSafe(option.staticPrice, 0);
            
            // Handle price options
            let priceOptionsArray = [];
            if (option.priceOptions) {
                try {
                    if (typeof option.priceOptions === 'string') {
                        priceOptionsArray = JSON.parse(option.priceOptions).types || [];
                    } else if (option.priceOptions.types) {
                        priceOptionsArray = option.priceOptions.types;
                    }
                    
                    // If we have price options and a selection, use that price
                    if (priceOptionsArray.length > 0 && 
                        instance.priceOptionIndex !== undefined &&
                        priceOptionsArray[instance.priceOptionIndex]) {
                        price = parseSafe(priceOptionsArray[instance.priceOptionIndex].price, price);
                    }
                } catch(e) {
                    console.error("Error parsing price options:", e);
                }
            }
            
            // Add addon prices (without group discounts yet)
            if (instance.addons && Array.isArray(instance.addons)) {
                instance.addons.forEach(addonId => {
                    // Find addon by ID instead of index
                    const addon = option.addons.find(a => a.id === addonId);
                    if (addon) {
                        const modVal = parseSafe(addon.staticPriceMod, 0);
                        if (isMultiply(addon)) {
                            price *= (modVal || 1);
                        } else {
                            price += modVal;
                        }
                    }
                });
            }
            
            // Get quantity (default to 1)
            const qty = parseInt(instance.quantity) || 1;
            
            // Calculate total price before group discounts
            let totalPrice = price * qty;
            const originalPrice = totalPrice;
            
            // NOW apply group discounts to the total addon cost
            if (instance.addons && Array.isArray(instance.addons)) {
                // Initialize or clear the groupDiscounts object
                instance.groupDiscounts = {};
                
                // Group selected addons by their group name
                const addonsByGroup = {};
                
                instance.addons.forEach(addonId => {
                    // Find addon by ID
                    const addon = option.addons.find(a => a.id === addonId);
                    if (addon && addon.groupName && addon.enableGrouping) {
                        if (!addonsByGroup[addon.groupName]) {
                            addonsByGroup[addon.groupName] = {
                                addons: [],
                                thresholdDiscounts: parseThresholdDiscounts(addon.groupThresholdDiscounts)
                            };
                        }
                        addonsByGroup[addon.groupName].addons.push(addon);
                    }
                });
                
                // Process each group for discounts
                Object.values(addonsByGroup).forEach(group => {
                    if (group.thresholdDiscounts.length === 0 || group.addons.length === 0) return;
                    
                    // Sort discounts by item count in descending order
                    const sortedDiscounts = [...group.thresholdDiscounts]
                        .sort((a, b) => parseInt(b.itemCount) - parseInt(a.itemCount));
                    
                    // Find the highest applicable discount
                    const applicableDiscount = sortedDiscounts.find(d => 
                        group.addons.length >= parseInt(d.itemCount)
                    );
                    
                    if (applicableDiscount) {
                        // Calculate the discount amount on the TOTAL price of this group's addons (including quantity)
                        const groupItemsPerUnit = group.addons.reduce((sum, addon) => {
                            // For premium toppings which are all additive, we just sum the static price modifiers
                            return sum + parseSafe(addon.staticPriceMod, 0);
                        }, 0);
                        
                        const groupItemsTotal = groupItemsPerUnit * qty; // Apply quantity here
                        const discountPercent = parseFloat(applicableDiscount.discount);
                        const discountAmount = groupItemsTotal * (discountPercent / 100);
                        
                        // Apply discount to the total price
                        totalPrice -= discountAmount;

                        // Store discount info for display in the invoice
                        instance.groupDiscounts[group.addons[0].groupName] = {
                            count: group.addons.length,
                            threshold: parseInt(applicableDiscount.itemCount),
                            percentage: discountPercent,
                            amount: discountAmount,
                            originalAmount: groupItemsTotal
                        };
                    }
                    // If no discount applies, we don't add an entry (since we cleared the object above)
                });
            }
            
            // Apply highest applicable threshold discount
            let appliedThreshold = null;
            let discountPercentage = 0;
            
            if (option.thresholdDiscounts) {
                try {
                    let thresholds = [];
                    if (typeof option.thresholdDiscounts === 'string') {
                        thresholds = JSON.parse(option.thresholdDiscounts);
                    } else if (option.thresholdDiscounts.types) {
                        thresholds = option.thresholdDiscounts.types;
                    } else if (Array.isArray(option.thresholdDiscounts)) {
                        thresholds = option.thresholdDiscounts;
                    }
                    
                    // Find highest applicable discount
                    if (Array.isArray(thresholds)) {
                        // Sort thresholds by itemCount in descending order
                        const sortedThresholds = [...thresholds]
                            .sort((a, b) => parseInt(b.itemCount) - parseInt(a.itemCount))
                            .filter(t => parseInt(t.itemCount) > 0 && parseFloat(t.discount) > 0);
                        
                        // Find first threshold that applies (highest one)
                        appliedThreshold = sortedThresholds.find(t => qty >= parseInt(t.itemCount));
                        
                        if (appliedThreshold) {
                            discountPercentage = parseFloat(appliedThreshold.discount);
                            totalPrice = originalPrice * (1 - discountPercentage/100);
                            
                            instance.appliedDiscount = {
                                threshold: parseInt(appliedThreshold.itemCount),
                                percentage: discountPercentage,
                                originalPrice: originalPrice
                            };
                        }
                    }
                } catch (e) {
                    console.error("Error processing threshold discounts:", e);
                }
            }
            
            if (!appliedThreshold) {
                delete instance.appliedDiscount;
            }
            
            return totalPrice;
        }

        // Parse threshold discounts from JSON string
        function parseThresholdDiscounts(discountsData) {
            if (!discountsData) return [];
            
            try {
                let thresholds = [];
                
                if (typeof discountsData === 'string') {
                    const parsed = JSON.parse(discountsData);
                    // Handle the case where it's parsed as an object with types
                    if (parsed && parsed.types) {
                        thresholds = parsed.types;
                    } else {
                        thresholds = parsed; // Assume it's directly an array
                    }
                } else if (discountsData.types) {
                    thresholds = discountsData.types;
                } else if (Array.isArray(discountsData)) {
                    thresholds = discountsData;
                }
                
                // Make sure we convert itemCount and discount to numbers
                const result = Array.isArray(thresholds) ? 
                    thresholds.filter(t => t.itemCount && t.discount).map(t => ({
                        itemCount: parseInt(t.itemCount, 10),
                        discount: parseFloat(t.discount)
                    })) : [];
                    
                return result;
            } catch (e) {
                console.error("Error parsing threshold discounts:", e, discountsData);
                return [];
            }
        }

        // Calculate lower bound for invoice if there's a range
        function calculateInstancePriceLower(feature, instance) {
            const option = feature.options[instance.optionIndex];
            if (!option) return 0;

            let price = parseSafe(option.priceFloor, parseSafe(option.staticPrice, 0));
            if (instance.addons && Array.isArray(instance.addons)) {
                instance.addons.forEach(addonId => {
                    const addon = option.addons.find(a => a.id === addonId);
                    if (addon) {
                        const floorVal = parseSafe(addon.floorPriceMod, parseSafe(addon.staticPriceMod, 0));
                        if (isMultiply(addon)) {
                            price *= (floorVal || 1);
                        } else {
                            price += floorVal;
                        }
                    }
                });
            }
            if (!feature.recurring) {
                const qty = parseInt(instance.quantity) || 1;
                price *= qty;
            }
            return price;
        }

        // Calculate upper bound for invoice if there's a range
        function calculateInstancePriceUpper(feature, instance) {
            const option = feature.options[instance.optionIndex];
            if (!option) return 0;

            let price = parseSafe(option.priceCeiling, parseSafe(option.staticPrice, 0));
            if (instance.addons && Array.isArray(instance.addons)) {
                instance.addons.forEach(addonId => {
                    const addon = option.addons.find(a => a.id === addonId);
                    if (addon) {
                        const ceilVal = parseSafe(addon.ceilingPriceMod, parseSafe(addon.staticPriceMod, 0));
                        if (isMultiply(addon)) {
                            price *= (ceilVal || 1);
                        } else {
                            price += ceilVal;
                        }
                    }
                });
            }
            if (!feature.recurring) {
                const qty = parseInt(instance.quantity) || 1;
                price *= qty;
            }
            return price;
        }

        // Build and display the invoice
        function updateInvoice() {
            let totalLower = 0;
            let totalUpper = 0;
            let totalFinal = 0;
            let recurringTotal = 0;
            let oneTimeTotal = 0;
            let oneTimeOriginalTotal = 0;
            let recurringOriginalTotal = 0;
            estimateMode = false;

            let tableHTML = `
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            // Separate recurring and one-time items
            let recurringItems = [];
            let oneTimeItems = [];

            dashboardData.features.forEach((feature, fIndex) => {
                const instances = selections[fIndex] || [];
                instances.forEach(instance => {
                    if (instance.optionIndex === undefined) return;
                    const option = feature.options[instance.optionIndex];
                    if (!option) return;
                    
                    const itemData = {
                        feature: feature,
                        option: option,
                        instance: instance,
                        fIndex: fIndex
                    };
                    
                    if (feature.recurring) {
                        recurringItems.push(itemData);
                    } else {
                        oneTimeItems.push(itemData);
                    }
                });
            });

            // Determine the interval label for recurring totals
            const recurringInterval = recurringItems.length > 0
                ? recurringItems[0].option.interval
                : '';

            // Function to render item rows
            const renderItemRow = (itemData) => {
                const { feature, option, instance, fIndex } = itemData;
                calculateInstancePrice(feature, instance);

                let itemDescription = `<div class="item-main">
                    <div class="feature-name">${feature.featureName}</div>
                    <div class="option-details">`;

                // handle priceOptions label
                let selectedOptionText = '';
                let priceOptionsArray = [];
                if (option.priceOptions) {
                    try {
                        if (typeof option.priceOptions === 'string') {
                            priceOptionsArray = JSON.parse(option.priceOptions).types || [];
                        } else {
                            priceOptionsArray = option.priceOptions.types || [];
                        }
                        const idx = instance.priceOptionIndex;
                        if (priceOptionsArray.length > 0 && idx != null && priceOptionsArray[idx]) {
                            selectedOptionText = ` (${priceOptionsArray[idx].label})`;
                        }
                    } catch (e) {
                        console.error("Error parsing price options:", e);
                    }
                }

                // quantity label for non-recurring
                let qtyLabel = '';
                if (!feature.recurring) {
                    const qty = parseInt(instance.quantity) || 1;
                    qtyLabel = ` (Qty: ${qty})`;
                }

                itemDescription += `<div class="option-name">
                    ${option.optionName}${selectedOptionText}${qtyLabel}
                </div>`;

                // recurring label
                if (feature.recurring && option.interval) {
                    itemDescription += `<div class="recurring-interval">Billed ${option.interval}ly</div>`;
                }

                // extra lines: userFields + addons
                const additionalDetails = [];
                if (instance.userFields) {
                    for (const [fieldName, selIdx] of Object.entries(instance.userFields)) {
                        const userField = option[`${fieldName}_user`];
                        if (!userField) continue;
                        try {
                            const fd = typeof userField === 'string' ? JSON.parse(userField) : userField;
                            if (fd && Array.isArray(fd.types) && fd.types[selIdx]) {
                                const niceName = fieldName
                                    .replace(/([A-Z])/g, ' $1')
                                    .replace(/^./, s => s.toUpperCase())
                                    .trim();
                                additionalDetails.push(`${niceName}: ${fd.types[selIdx]}`);
                            }
                        } catch (e) {
                            console.error(`Error user field ${fieldName}:`, e);
                        }
                    }
                }

                let hasAddon = false;
                if (Array.isArray(instance.addons)) {
                    instance.addons.forEach(addonId => {
                        const a = option.addons.find(x => x.id === addonId);
                        if (a) {
                            additionalDetails.push(`<span class="addon-item-name">${a.addonName}</span>`);
                            hasAddon = true;
                        }
                    });
                }

                const bullet = hasAddon ? '' : '• ';
                additionalDetails.forEach(detail => {
                    itemDescription += `<div class="option-detail-line">${bullet}${detail}</div>`;
                });

                itemDescription += `</div></div>`;

                //
                // --- pricing logic (same as before) ---
                //

                // 1) per-unit base price
                let unitBasePrice = parseSafe(option.staticPrice, 0);
                priceOptionsArray = [];
                if (option.priceOptions) {
                    try {
                        if (typeof option.priceOptions === 'string') {
                            priceOptionsArray = JSON.parse(option.priceOptions).types || [];
                        } else {
                            priceOptionsArray = option.priceOptions.types || [];
                        }
                        const idx = instance.priceOptionIndex;
                        if (priceOptionsArray.length > 0 && idx != null && priceOptionsArray[idx]) {
                            unitBasePrice = parseSafe(priceOptionsArray[idx].price, unitBasePrice);
                        }
                    } catch (e) {
                        console.error("Error parsing price options:", e);
                    }
                }

                // 2) per-unit addons total
                let addonUnitTotal = 0;
                if (Array.isArray(instance.addons)) {
                    instance.addons.forEach(addonId => {
                        const a = option.addons.find(x => x.id === addonId);
                        if (a) {
                            addonUnitTotal += parseSafe(a.staticPriceMod, 0);
                        }
                    });
                }

                // 3) compute totals
                const qty      = feature.recurring ? 1 : (parseInt(instance.quantity) || 1);
                const baseTotal  = unitBasePrice * qty;
                const addonTotal = addonUnitTotal * qty;
                const originalPrice = baseTotal + addonTotal;

                // 4) read any group-discount amounts (populated by calculateInstancePrice)
                const groupDiscountTotal = (!feature.recurring && instance.groupDiscounts)
                    ? Object.values(instance.groupDiscounts).reduce((sum, g) => sum + g.amount, 0)
                    : 0;

                // 5) figure out percent discount (stored or threshold)
                let pct = 0;
                if (!feature.recurring && instance.appliedDiscount) {
                    pct = instance.appliedDiscount.percentage || 0;
                } else if (!feature.recurring && option.thresholdDiscounts) {
                    try {
                        let thresholds = typeof option.thresholdDiscounts === 'string'
                            ? JSON.parse(option.thresholdDiscounts)
                            : option.thresholdDiscounts;
                        if (Array.isArray(thresholds)) {
                            thresholds.forEach(t => {
                                const count = parseInt(t.itemCount, 10);
                                const d = parseFloat(t.discount);
                                if (qty >= count) pct = Math.max(pct, d);
                            });
                        }
                    } catch (e) {
                        console.error("Error parsing thresholds:", e);
                    }
                }

                // 6) apply percent only to base, then subtract group discounts, then add addons
                const afterPct   = baseTotal * (1 - pct/100);
                let finalPrice = afterPct - groupDiscountTotal + addonTotal;
                finalPrice = Math.round(finalPrice * 100) / 100;

                return {
                    html: `<tr>
                        <td>${itemDescription}</td>
                        <td>${
                            feature.recurring
                                ? `${originalPrice.toFixed(2)}/${option.interval}`
                                : originalPrice.toFixed(2)
                        }</td>
                    </tr>`,
                    price: finalPrice,
                    originalPrice,
                    isRecurring: feature.recurring
                };
            };

            // Render one-time items first
            if (oneTimeItems.length > 0) {
                tableHTML += `<tr class="section-header"><td colspan="2"><strong>One-Time Charges</strong></td></tr>`;
                oneTimeItems.forEach(itemData => {
                    const rendered = renderItemRow(itemData);
                    tableHTML += rendered.html;
                    oneTimeTotal += rendered.price; // discounted price for actual total
                    oneTimeOriginalTotal += rendered.originalPrice; // original price for discount calculation
                    totalFinal += rendered.price;
                });
            }

            // Render recurring items
            if (recurringItems.length > 0) {
                if (oneTimeItems.length > 0) {
                    tableHTML += `<tr class="section-spacer"><td colspan="2">&nbsp;</td></tr>`;
                }
                tableHTML += `<tr class="section-header"><td colspan="2"><strong>Recurring Charges</strong></td></tr>`;
                recurringItems.forEach(itemData => {
                    const rendered = renderItemRow(itemData);
                    tableHTML += rendered.html;
                    recurringTotal += rendered.price; // discounted price for actual total
                    recurringOriginalTotal += rendered.originalPrice; // original price for discount calculation
                });

                // When we have both one-time and recurring, add recurring to the total
                if (oneTimeItems.length > 0) {
                    totalFinal += recurringTotal;
                } else if (recurringItems.length > 0) {
                    totalFinal = recurringTotal;
                }
            }

            // Calculate total discounts for display using the correct logic
            let totalOriginalPrice = 0;
            let totalDiscountedPrice = 0;

            // Calculate for one-time items
            if (oneTimeItems.length > 0) {
                totalOriginalPrice += oneTimeOriginalTotal;
                totalDiscountedPrice += oneTimeTotal;
            }

            // Calculate for recurring items  
            if (recurringItems.length > 0) {
                totalOriginalPrice += recurringOriginalTotal;
                totalDiscountedPrice += recurringTotal;
            }

            const totalDiscount = totalOriginalPrice - totalDiscountedPrice;
            
            // Show totals
            tableHTML += `
                    </tbody>
                    <tfoot>`;

            if (oneTimeItems.length > 0 && recurringItems.length > 0) {
                // Show both totals
                tableHTML += `
                    <tr class="subtotal-row">
                        <td style="text-align: right;">One-Time Total:</td>
                        <td>$${oneTimeTotal.toFixed(2)}</td>
                    </tr>
                    <tr class="subtotal-row">
                        <td style="text-align: right;">Recurring Total:</td>
                        <td>$${recurringTotal.toFixed(2)}/${recurringInterval}</td>
                    </tr>`;
                
                // Add discount row if there are discounts
                if (totalDiscount > 0) {
                    tableHTML += `
                        <tr class="discount-row" style="color: #0066cc;">
                            <td style="text-align: right;">Discount:</td>
                            <td>-$${totalDiscount.toFixed(2)}</td>
                        </tr>`;
                }
                
                tableHTML += `
                    <tr class="total-row">
                        <td style="text-align: right; font-weight: bold;">Due Today:</td>
                        <td id="invoice-total">$${totalFinal.toFixed(2)}</td>
                    </tr>`;
            } else if (recurringItems.length > 0) {
                // Only recurring
                if (totalDiscount > 0) {
                    tableHTML += `
                        <tr class="discount-row" style="color: #0066cc;">
                            <td style="text-align: right;">Discount:</td>
                            <td>-$${totalDiscount.toFixed(2)}</td>
                        </tr>`;
                }
                
                tableHTML += `
                    <tr class="total-row">
                        <td style="text-align: right; font-weight: bold;">Recurring Total:</td>
                        <td id="invoice-total">$${recurringTotal.toFixed(2)}/${recurringInterval}</td>
                    </tr>`;
            } else {
                // Only one-time
                if (totalDiscount > 0) {
                    tableHTML += `
                        <tr class="discount-row" style="color: #0066cc;">
                            <td style="text-align: right;">Discount:</td>
                            <td>-$${totalDiscount.toFixed(2)}</td>
                        </tr>`;
                }
                
                tableHTML += `
                    <tr class="total-row">
                        <td style="text-align: right; font-weight: bold;">Total:</td>
                        <td id="invoice-total">$${totalFinal.toFixed(2)}</td>
                    </tr>`;
            }

            tableHTML += `
                    </tfoot>
                </table>
            `;
            
            if (invoiceDetails) invoiceDetails.innerHTML = tableHTML;

            updateOrderButton();
        }

        // Check if there are any valid selections in the invoice
        function hasValidSelections() {
            for (const [fIndex, instances] of Object.entries(selections)) {
                for (const instance of instances) {
                    if (instance.optionIndex !== undefined) {
                        const feature = dashboardData.features[fIndex];
                        if (feature && feature.options && feature.options[instance.optionIndex]) {
                            return true; // Found at least one valid selection
                        }
                    }
                }
            }
            return false; // No valid selections found
        }

        // Render feature-level user fields
        function renderFeatureLevelFields(feature, fIndex, instanceDiv, instance) {
            const featureFieldsDiv = document.createElement('div');
            featureFieldsDiv.classList.add('feature-fields');
            featureFieldsDiv.id = feature.featureName.toLowerCase();
            featureFieldsDiv.id = `feature-${featureFieldsDiv.id.replace(/\s/, '-')}`;
            
            let hasUserFields = false;
            
            // Loop through feature properties to find user fields
            for (const key in feature) {
                // Check if it's a user field
                if ((key.endsWith('_user') || key.endsWith('_display')) && feature[key] !== null) {
                    
                    hasUserFields = true;
                    
                    try {
                        let fieldData;
                        let fieldType = 'normal-text'; // Default fallback
                        let fieldValue = '';
                        let placeholder = '';
                        let dropdownOptions = [];
                        let originalKey = key;
                        let isDisplayField = key.endsWith('_display') || 
                                            (typeof feature[key] === 'object' && 
                                            feature[key].level === 'user-display');
                        
                        // Standardize field data format
                        if (typeof feature[key] === 'string') {
                            try {
                                // Parse the JSON string into an object
                                fieldData = JSON.parse(feature[key]);
                                
                                // Extract ui_type, value, and placeholder if they exist
                                if (fieldData) {
                                    fieldType = fieldData.ui_type || 'normal-text';
                                    
                                    // Special handling for array/dropdown type
                                    if (fieldType === 'array') {
                                        if (fieldData.types && Array.isArray(fieldData.types)) {
                                            dropdownOptions = fieldData.types;
                                        } else if (fieldData.value && fieldData.value.types && Array.isArray(fieldData.value.types)) {
                                            dropdownOptions = fieldData.value.types;
                                        }
                                    } else {
                                        fieldValue = fieldData.value !== undefined ? fieldData.value : '';
                                    }
                                    
                                    placeholder = fieldData.placeholder || '';
                                }
                            } catch (e) {
                                // If parsing fails, use the string directly
                                console.warn(`Failed to parse feature user field ${key}:`, e);
                                fieldValue = feature[key];
                            }
                        } else if (typeof feature[key] === 'object') {
                            fieldData = feature[key];
                            
                            // Handle object format for the field with a "level" property (indicating it's from the JSON)
                            if (fieldData.level === 'user') {
                                fieldType = fieldData.ui_type || 'normal-text';
                                
                                // Special handling for array/dropdown type
                                if (fieldType === 'array') {
                                    if (fieldData.value && fieldData.value.types && Array.isArray(fieldData.value.types)) {
                                        dropdownOptions = fieldData.value.types;
                                    }
                                } else {
                                    fieldValue = fieldData.value !== undefined ? fieldData.value : '';
                                }
                                
                                placeholder = fieldData.placeholder || '';
                                originalKey = key.replace(/^(.+)(_user)?$/, '$1');
                            }
                            // Handling for already processed JSON user fields
                            else if (fieldData.ui_type) {
                                fieldType = fieldData.ui_type;
                                
                                if (fieldType === 'array') {
                                    if (fieldData.types && Array.isArray(fieldData.types)) {
                                        dropdownOptions = fieldData.types;
                                    } else if (fieldData.value && fieldData.value.types && Array.isArray(fieldData.value.types)) {
                                        dropdownOptions = fieldData.value.types;
                                    }
                                } else {
                                    fieldValue = fieldData.value !== undefined ? fieldData.value : '';
                                }
                                
                                placeholder = fieldData.placeholder || '';
                            }
                        } else {
                            // Direct value (number, boolean, etc.)
                            fieldValue = feature[key];
                            fieldType = typeof fieldValue === 'number' ? 'int-float' : 'normal-text';
                        }
                        
                        // Format the field name for display
                        // Remove _user suffix and convert camelCase to Title Case
                        const fieldName = originalKey.replace(/(_user)?$/, '');
                        const displayName = formatFieldLabel(fieldName);
                        
                        // Create container for the field
                        const userFieldDiv = document.createElement('div');
                        userFieldDiv.classList.add('user-field', 'feature-level-field');
                        
                        // Create label
                        const label = document.createElement('label');
                        label.innerHTML = `<strong>${displayName}:</strong> `;
                        
                        let inputElement;
                        
                        // Initialize featureFields object if it doesn't exist
                        if (!instance.featureFields) {
                            instance.featureFields = {};
                        }
                        
                        // Create the appropriate input based on ui_type
                        if (isDisplayField) {
                            // For display-only fields, create a static text display instead of an input
                            const displaySpan = document.createElement('span');
                            displaySpan.className = 'user-display-value';
                            
                            // Format the display value based on field type
                            if (fieldType === 'array' && dropdownOptions.length > 0) {
                                // Try to get the selected index from different possible locations
                                let selectedIndex = 0;
                                
                                if (fieldData && typeof fieldData === 'object') {
                                    if (fieldData.selected !== undefined) {
                                        selectedIndex = fieldData.selected;
                                    } else if (fieldData.value && fieldData.value.selected !== undefined) {
                                        selectedIndex = fieldData.value.selected;
                                    }
                                }
                                
                                displaySpan.textContent = dropdownOptions[selectedIndex] || '';
                            } else {
                                displaySpan.textContent = fieldValue || '';
                            }
                            
                            // Use the span as our "input element" for consistent handling
                            inputElement = displaySpan;
                        } else {
                            // Original switch statement for regular user fields
                            switch (fieldType) {
                                case 'array':
                                // It's a dropdown/select field
                                inputElement = document.createElement('select');
                                inputElement.id = `feature-field-${fIndex}-${fieldName}`;
                                
                                // Make sure we have an array to work with
                                if (!dropdownOptions.length) {
                                    dropdownOptions = ['No options available'];
                                }
                                
                                // Initialize selection if undefined
                                if (instance.featureFields[fieldName] === undefined) {
                                    instance.featureFields[fieldName] = 0;
                                }
                                
                                // Create options
                                dropdownOptions.forEach((option, i) => {
                                    const opt = document.createElement('option');
                                    opt.value = i;
                                    opt.textContent = option;
                                    if (i === instance.featureFields[fieldName]) {
                                        opt.selected = true;
                                    }
                                    inputElement.appendChild(opt);
                                });
                                
                                // Set up change event
                                inputElement.addEventListener('change', function() {
                                    instance.featureFields[fieldName] = parseInt(this.value);
                                    saveSelections();
                                    updateInvoice();
                                });
                                break;
                            
                            case 'long-text':
                                // Create textarea
                                inputElement = document.createElement('textarea');
                                inputElement.id = `feature-field-${fIndex}-${fieldName}`;
                                inputElement.rows = 3;
                                inputElement.placeholder = placeholder;
                                
                                // Initialize value if undefined
                                if (instance.featureFields[fieldName] === undefined) {
                                    instance.featureFields[fieldName] = fieldValue;
                                }
                                
                                inputElement.value = instance.featureFields[fieldName];
                                
                                // Set up input event
                                inputElement.addEventListener('input', function() {
                                    instance.featureFields[fieldName] = this.value;
                                    saveSelections();
                                    updateInvoice();
                                });
                                break;
                            
                            case 'int-float':
                                // Create number input
                                inputElement = document.createElement('input');
                                inputElement.type = 'number';
                                inputElement.id = `feature-field-${fIndex}-${fieldName}`;
                                inputElement.step = 'any'; // Allow decimal points
                                inputElement.placeholder = placeholder;
                                
                                // Initialize value if undefined
                                if (instance.featureFields[fieldName] === undefined) {
                                    instance.featureFields[fieldName] = fieldValue;
                                }
                                
                                inputElement.value = instance.featureFields[fieldName];
                                
                                // Set up input event
                                inputElement.addEventListener('input', function() {
                                    const val = parseFloat(this.value);
                                    instance.featureFields[fieldName] = isNaN(val) ? 0 : val;
                                    saveSelections();
                                    updateInvoice();
                                });
                                break;
                            
                            case 'date':
                                // Create date input
                                inputElement = document.createElement('input');
                                inputElement.type = 'date';
                                inputElement.id = `feature-field-${fIndex}-${fieldName}`;
                                
                                // Initialize value if undefined
                                if (instance.featureFields[fieldName] === undefined) {
                                    instance.featureFields[fieldName] = fieldValue;
                                }
                                
                                inputElement.value = instance.featureFields[fieldName];
                                
                                // Set up change event
                                inputElement.addEventListener('change', function() {
                                    instance.featureFields[fieldName] = this.value;
                                    saveSelections();
                                    updateInvoice();
                                });
                                break;
                            
                            case 'normal-text':
                            default:
                                // Create text input (default)
                                inputElement = document.createElement('input');
                                inputElement.type = 'text';
                                inputElement.id = `feature-field-${fIndex}-${fieldName}`;
                                inputElement.placeholder = placeholder;
                                
                                // Initialize value if undefined
                                if (instance.featureFields[fieldName] === undefined) {
                                    instance.featureFields[fieldName] = fieldValue;
                                }
                                
                                inputElement.value = instance.featureFields[fieldName];
                                
                                // Set up input event
                                inputElement.addEventListener('input', function() {
                                    instance.featureFields[fieldName] = this.value;
                                    saveSelections();
                                    updateInvoice();
                                });
                                break;
                        }

                    }
                        
                        // Append input to label
                        label.appendChild(inputElement);
                        userFieldDiv.appendChild(label);
                        featureFieldsDiv.appendChild(userFieldDiv);
                        
                    } catch (e) {
                        console.error(`Error processing feature user field ${key}:`, e);
                    }
                }
            }
            
            if (hasUserFields) instanceDiv.appendChild(featureFieldsDiv);
            
            return hasUserFields;
        }

        // Render a single instance in the UI (option details)
        function renderInstance(feature, fIndex, instIndex, instance) {
            const instanceDiv = document.createElement('div');
            instanceDiv.classList.add('feature');

            // Header with "New X" or feature name
            const headerDiv = document.createElement('div');
            headerDiv.classList.add('instance-header');
            const headerTitle = document.createElement('span');
            headerTitle.textContent = 'Options:';
            headerDiv.appendChild(headerTitle);

            // Show delete button if there's more than one instance
            if (selections[fIndex] && selections[fIndex].length > 1) {
                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.textContent = 'Delete';
                deleteBtn.classList.add('delete-instance');
                deleteBtn.addEventListener('click', function() {
                    selections[fIndex].splice(instIndex, 1);
                    saveSelections();
                    renderFeatureInstances(feature, fIndex);
                    updateInvoice();
                });
                headerDiv.appendChild(deleteBtn);
            }
            instanceDiv.appendChild(headerDiv);

            // Render feature-level user fields before the options dropdown
            renderFeatureLevelFields(feature, fIndex, instanceDiv, instance);

            // Dropdown for options
            const select = document.createElement('select');
            const defaultOption = document.createElement('option');
            defaultOption.textContent = 'None';
            defaultOption.value = '';
            select.appendChild(defaultOption);

            feature.options.forEach((option, oIndex) => {
                const opt = document.createElement('option');
                opt.value = oIndex;
                opt.textContent = option.optionName;

                // Check if this is a recurring feature and if user has active subscription for this option
                if (feature.recurring && 
                    dashboardData.subscription_status && 
                    dashboardData.subscription_status.has_active_subscription &&
                    dashboardData.subscription_status.subscription_details &&
                    parseInt( dashboardData.subscription_status.subscription_details.option_id) === parseInt(option.id) ) {
                    opt.disabled = true;
                    opt.textContent += ' (Already Subscribed)';
                }
                
                select.appendChild(opt);
            });

            // Handle "None" gracefully
            if (instance.optionIndex === undefined) {
                select.value = '';
            } else {
                select.value = instance.optionIndex;
            }

            instanceDiv.appendChild(select);

            // Container for option details
            const optionDetailsDiv = document.createElement('div');
            optionDetailsDiv.classList.add('option-details');
            optionDetailsDiv.style.display = 'none';
            instanceDiv.appendChild(optionDetailsDiv);

            // On dropdown change
            select.addEventListener('change', function() {
                let featureFieldsDivId = feature.featureName.toLowerCase();
                featureFieldsDivId = featureFieldsDivId.replace(/\s/, '-');
                let featureFieldsDiv = document.querySelector(`#feature-${featureFieldsDivId}`);
                if (this.value === '') {
                    instance.optionIndex = undefined;
                    if (featureFieldsDiv) featureFieldsDiv.style.display = 'none';
                } else {
                    instance.optionIndex = parseInt(this.value);
                    if (featureFieldsDiv) featureFieldsDiv.style.display = 'block';
                }
                // Reset addons and quantity if the user changes the option
                instance.addons = [];
                if (!feature.recurring) {
                    instance.quantity = 1;
                }
                renderOptionDetails(fIndex, instIndex, feature, optionDetailsDiv);
                if (instance.optionIndex !== undefined && !isNaN(instance.optionIndex)) {
                    smoothScrollToElement(instanceDiv, 120);
                }
                saveSelections();
            });

            // Render details if there's already a saved option
            if (instance.optionIndex !== undefined) {
                renderOptionDetails(fIndex, instIndex, feature, optionDetailsDiv);
            }

            return instanceDiv;
        }

        // Control max addons selection
        function enforceMaxAddons(fIndex, instIndex, addonsDiv) {
            const instance = selections[fIndex][instIndex];
            const option = dashboardData.features[fIndex].options[instance.optionIndex];
            
            // If no max addons constraint or unlimited (-1), enable all checkboxes
            if (!option || !option.maxAddons || option.maxAddons < 0) return;
            
            const maxAllowed = parseInt(option.maxAddons);
            const checkboxes = addonsDiv.querySelectorAll('input[type="checkbox"]');
            
            // Count checked addons
            const checkedCount = (instance.addons || []).length;
            
            // Disable unchecked boxes if limit reached, otherwise enable all
            checkboxes.forEach(checkbox => {
                if (!checkbox.checked) {
                    checkbox.disabled = checkedCount >= maxAllowed;
                }
            });
            
            // Add a message if needed
            let maxAddonsMessage = addonsDiv.querySelector('.max-addons-message');
            if (checkedCount >= maxAllowed) {
                if (!maxAddonsMessage) {
                    maxAddonsMessage = document.createElement('div');
                    maxAddonsMessage.className = 'max-addons-message';
                    maxAddonsMessage.style.color = '#d83838';
                    maxAddonsMessage.style.fontSize = '(--fontSizeSmallest)';
                    maxAddonsMessage.style.marginTop = '8px';
                    maxAddonsMessage.style.fontWeight = 'bold';
                    addonsDiv.appendChild(maxAddonsMessage);
                }
                maxAddonsMessage.textContent = `Maximum of ${maxAllowed} toppings reached.`;
            } else if (maxAddonsMessage) {
                maxAddonsMessage.remove();
            }
        }

        // Render the raw option details (no addition with addons)
        function renderOptionDetails(fIndex, instIndex, feature, optionDetailsDiv) {
            optionDetailsDiv.innerHTML = '';
            const instance = selections[fIndex][instIndex];
            if (instance.optionIndex === undefined || isNaN(instance.optionIndex)) {
                optionDetailsDiv.style.display = 'none';
                saveSelections();
                updateInvoice();
                return;
            }
            const selectedOption = feature.options[instance.optionIndex];
            if (!selectedOption) {
                optionDetailsDiv.style.display = 'none';
                saveSelections();
                updateInvoice();
                return;
            }
            optionDetailsDiv.style.display = 'block';

            // Show the raw option data. If it has a range, show that. Otherwise, show static.
            const descText = getDescriptionText(selectedOption.description);
            const floorVal = parseSafe(selectedOption.priceFloor);
            const ceilVal  = parseSafe(selectedOption.priceCeiling);
            let optionPriceText = '';

            // If there's a range for the option
            if (floorVal !== 0 || ceilVal !== 0) {
                optionPriceText = `$${floorVal.toFixed(2)} - $${ceilVal.toFixed(2)}`;
            } else {
                // Otherwise, show static
                const staticP = parseSafe(selectedOption.staticPrice, 0);
                optionPriceText = `$${staticP.toFixed(2)}`;
            }

            let detailsHTML = `<p><strong>Description:</strong> ${descText}</p>`;

            // Process display fields first and add them immediately after description
            for (const key in selectedOption) {
                // Only process display fields here
                if (key.endsWith('_display') && selectedOption[key] !== null) {
                    try {
                        let fieldData;
                        let fieldType = 'normal-text';
                        let displayValue = '';
                        
                        // Try to parse the field data
                        if (typeof selectedOption[key] === 'string') {
                            try {
                                fieldData = JSON.parse(selectedOption[key]);
                                if (fieldData) {
                                    fieldType = fieldData.ui_type || 'normal-text';
                                    
                                    // Handle array type (dropdown)
                                    if (fieldType === 'array' && fieldData.types && Array.isArray(fieldData.types)) {
                                        // Get selected index (default to 0)
                                        const selectedIndex = fieldData.selected || 0;
                                        displayValue = fieldData.types[selectedIndex] || '';
                                    } else {
                                        // For other types, use the value directly
                                        displayValue = fieldData.value || '';
                                    }
                                }
                            } catch (e) {
                                console.warn(`Failed to parse display field ${key}:`, e);
                                displayValue = selectedOption[key];
                            }
                        } else if (typeof selectedOption[key] === 'object') {
                            fieldData = selectedOption[key];
                            
                            if (fieldData.ui_type === 'array' && fieldData.types && Array.isArray(fieldData.types)) {
                                const selectedIndex = fieldData.selected || 0;
                                displayValue = fieldData.types[selectedIndex] || '';
                            } else if (fieldData.value !== undefined) {
                                displayValue = fieldData.value;
                            }
                        } else {
                            displayValue = selectedOption[key];
                        }
                        
                        // Format the field name for display
                        const fieldName = formatFieldLabel(key);
                        
                        // Add to detailsHTML
                        detailsHTML += `<p><strong>${fieldName}:</strong> ${displayValue}</p>`;
                    } catch (e) {
                        console.error(`Error processing display field ${key}:`, e);
                    }
                }
            }

            // Check if pricingType is set to "price options" before showing dropdown
            let showPriceOptions = false;
            if (selectedOption.pricingType) {
                try {
                    // Handle both simple string values and complex objects
                    if (typeof selectedOption.pricingType === 'string') {
                        // If it's a plain string (from database), use it directly
                        showPriceOptions = (selectedOption.pricingType === 'price options');
                    } else if (typeof selectedOption.pricingType === 'object') {
                        // If it's a complex object (from JSON), parse the structure
                        const pricingTypeData = selectedOption.pricingType;
                        if (pricingTypeData.value && pricingTypeData.value.types && pricingTypeData.value.selected !== undefined) {
                            const selectedType = pricingTypeData.value.types[pricingTypeData.value.selected];
                            showPriceOptions = (selectedType === 'price options');
                        }
                    }
                } catch(e) {
                    console.error("Error parsing pricingType:", e);
                    // Fallback: if there's an error, don't show price options
                    showPriceOptions = false;
                }
            }

            if (!showPriceOptions) detailsHTML += `<p><strong>Price:</strong> ${optionPriceText}</p>`;
            optionDetailsDiv.innerHTML = detailsHTML;
            
            let priceOptionsArray = [];
            if (selectedOption.priceOptions) {
                try {
                    if (typeof selectedOption.priceOptions === 'string') {
                        priceOptionsArray = JSON.parse(selectedOption.priceOptions).types || [];
                    } else if (selectedOption.priceOptions.types) {
                        priceOptionsArray = selectedOption.priceOptions.types;
                    }
                } catch(e) {
                    console.error("Error parsing price options:", e);
                    priceOptionsArray = [];
                }
            }

            // Only show price options dropdown if pricing type is "price options" AND we have options data
            if (showPriceOptions && priceOptionsArray && priceOptionsArray.length > 0) {
                const priceOptionDiv = document.createElement('div');
                priceOptionDiv.classList.add('price-option-selector');
                priceOptionDiv.style.marginTop = '10px';
                
                const label = document.createElement('label');
                label.innerHTML = '<strong>Price Option:</strong> ';
                
                const select = document.createElement('select');
                select.id = `price-option-select-${fIndex}-${instIndex}`;
                
                // Initialize the selection
                if (instance.priceOptionIndex === undefined) {
                    // Default to first option (usually cheapest)
                    instance.priceOptionIndex = 0;
                }
                
                priceOptionsArray.forEach((optData, idx) => {
                    const opt = document.createElement('option');
                    opt.value = idx;
                    opt.textContent = `${optData.label} - $${parseSafe(optData.price).toFixed(2)}`;
                    if (idx === instance.priceOptionIndex) {
                        opt.selected = true;
                    }
                    select.appendChild(opt);
                });
                    const instanceDiv = optionDetailsDiv.parentNode;
                    const featureFieldsDiv = instanceDiv.querySelector(
                    `#feature-${feature.featureName.toLowerCase().replace(/\s+/g, '-')}`
                );

                if (featureFieldsDiv) {
                    featureFieldsDiv.style.display = 'block';
                }

                
                select.addEventListener('change', function() {
                    instance.priceOptionIndex = parseInt(this.value);
                    saveSelections();
                    updateInvoice();
                });
                
                label.appendChild(select);
                priceOptionDiv.appendChild(label);
                optionDetailsDiv.appendChild(priceOptionDiv);
            }

            // Check for threshold discounts (this should always show regardless of pricing type)
            if (selectedOption.thresholdDiscounts) {
                try {
                    let thresholds = [];
                    if (typeof selectedOption.thresholdDiscounts === 'string') {
                        thresholds = JSON.parse(selectedOption.thresholdDiscounts);
                    } else if (selectedOption.thresholdDiscounts.types) {
                        thresholds = selectedOption.thresholdDiscounts.types;
                    } else if (Array.isArray(selectedOption.thresholdDiscounts)) {
                        thresholds = selectedOption.thresholdDiscounts;
                    }
                    
                    if (Array.isArray(thresholds) && thresholds.length > 0 && thresholds.some(t => t.itemCount && t.discount)) {
                        const discountDiv = document.createElement('div');
                        discountDiv.className = 'quantity-discounts';
                        discountDiv.style.marginTop = '(--fontSizeSmallest)';
                        discountDiv.style.padding = '8px';
                        discountDiv.style.backgroundColor = '#f8f8f8';
                        discountDiv.style.borderRadius = '4px';
                        discountDiv.style.border = '1px solid #e0e0e0';
                        
                        const discountTitle = document.createElement('div');
                        discountTitle.style.fontWeight = 'bold';
                        discountTitle.style.marginBottom = '6px';
                        discountTitle.textContent = 'Quantity Discounts Available:';
                        discountDiv.appendChild(discountTitle);
                        
                        const discountList = document.createElement('ul');
                        discountList.style.margin = '0';
                        discountList.style.paddingLeft = '20px';
                        discountList.style.fontSize = '(--fontSizeSmallest)';
                        
                        // Sort by item count
                        thresholds.sort((a, b) => parseInt(a.itemCount) - parseInt(b.itemCount))
                                .filter(t => t.itemCount && t.discount)
                                .forEach(threshold => {
                            const item = document.createElement('li');
                            item.textContent = `${threshold.discount}% off when you order ${threshold.itemCount} or more`;
                            discountList.appendChild(item);
                        });
                        
                        discountDiv.appendChild(discountList);
                        optionDetailsDiv.appendChild(discountDiv);
                    }
                } catch (e) {
                    console.error("Error displaying threshold discounts:", e);
                }
            }

            for (const key in selectedOption) {
                // Check if it's a user field or display field
                if (key.endsWith('_user') && !key.endsWith('_display') && selectedOption[key] !== null) {
                    try {
                        let fieldData;
                        let fieldType = 'normal-text'; // Default fallback
                        let fieldValue = '';
                        let placeholder = '';
                        let dropdownOptions = [];
                        let isDisplayField = key.endsWith('_display');
                        
                        // Try to parse the field data
                        if (typeof selectedOption[key] === 'string') {
                            try {
                                // Parse the JSON string into an object
                                fieldData = JSON.parse(selectedOption[key]);
                                
                                // Extract ui_type, value, and placeholder if they exist
                                if (fieldData) {
                                    if (fieldData.ui_type) {
                                        fieldType = fieldData.ui_type;
                                    }
                                    
                                    // Special handling for array/dropdown type
                                    if (fieldType === 'array') {
                                        // Look for types array in different possible locations
                                        if (fieldData.types && Array.isArray(fieldData.types)) {
                                            dropdownOptions = fieldData.types;
                                        } else if (fieldData.value && fieldData.value.types && Array.isArray(fieldData.value.types)) {
                                            dropdownOptions = fieldData.value.types;
                                        }
                                    } else {
                                        // For non-array types, get the value
                                        if (fieldData.value !== undefined) {
                                            fieldValue = fieldData.value;
                                        }
                                    }
                                    
                                    if (fieldData.placeholder) {
                                        placeholder = fieldData.placeholder;
                                    }
                                }
                            } catch (e) {
                                // If parsing fails, use the string directly
                                console.warn(`Failed to parse user field ${key}:`, e);
                                fieldValue = selectedOption[key];
                            }
                        } else if (typeof selectedOption[key] === 'object') {
                            fieldData = selectedOption[key];
                            
                            // Handle object format
                            if (fieldData.ui_type) {
                                fieldType = fieldData.ui_type;
                            }
                            
                            // Special handling for array/dropdown type
                            if (fieldType === 'array') {
                                if (fieldData.types && Array.isArray(fieldData.types)) {
                                    dropdownOptions = fieldData.types;
                                } else if (fieldData.value && fieldData.value.types && Array.isArray(fieldData.value.types)) {
                                    dropdownOptions = fieldData.value.types;
                                }
                            } else {
                                // For non-array types, get the value
                                if (fieldData.value !== undefined) {
                                    fieldValue = fieldData.value;
                                }
                            }
                            
                            if (fieldData.placeholder) {
                                placeholder = fieldData.placeholder;
                            }
                        } else {
                            // Direct value (number, boolean, etc.)
                            fieldValue = selectedOption[key];
                            fieldType = typeof fieldValue === 'number' ? 'int-float' : 'normal-text';
                        }
                        
                        // Format the field name for display
                        const fieldName = key.replace('_user', '');
                        const displayName = formatFieldLabel(key);
                        
                        // Create container for the field
                        const userFieldDiv = document.createElement('div');
                        userFieldDiv.classList.add('user-field');
                        
                        // Create label
                        const label = document.createElement('label');
                        label.innerHTML = `<strong>${displayName}:</strong> `;
                        
                        let inputElement;
                        
                        // Initialize userFields object if it doesn't exist
                        if (!instance.userFields) {
                            instance.userFields = {};
                        }
                        
                        // Create the appropriate input based on ui_type
                        if (isDisplayField) {
                            // For display-only fields, create a static text display instead of an input
                            const displaySpan = document.createElement('span');
                            displaySpan.className = 'user-display-value';
                            
                            // Format the display value based on field type
                            if (fieldType === 'array' && dropdownOptions.length > 0) {
                                // For dropdown fields, show the selected option text
                                const selectedIndex = instance.userFields[fieldName] || 0;
                                displaySpan.textContent = dropdownOptions[selectedIndex] || '';
                            } else {
                                displaySpan.textContent = fieldValue || '';
                            }
                            
                            // Use the span as our "input element" for consistent handling
                            inputElement = displaySpan;
                        } else {
                            // Original switch statement for regular user fields
                            switch (fieldType) {
                                case 'array':
                                // It's a dropdown/select field
                                inputElement = document.createElement('select');
                                inputElement.id = `user-field-${fIndex}-${instIndex}-${fieldName}`;
                                
                                // Make sure we have an array to work with
                                if (!dropdownOptions.length) {
                                    dropdownOptions = ['No options available'];
                                }
                                
                                // Initialize selection if undefined
                                if (instance.userFields[fieldName] === undefined) {
                                    instance.userFields[fieldName] = 0;
                                }
                                
                                // Create options
                                dropdownOptions.forEach((option, i) => {
                                    const opt = document.createElement('option');
                                    opt.value = i;
                                    opt.textContent = option;
                                    if (i === instance.userFields[fieldName]) {
                                        opt.selected = true;
                                    }
                                    inputElement.appendChild(opt);
                                });
                                
                                // Set up change event
                                inputElement.addEventListener('change', function() {
                                    instance.userFields[fieldName] = parseInt(this.value);
                                    saveSelections();
                                    updateInvoice();
                                });
                                break;
                            
                            case 'long-text':
                                // Create textarea
                                inputElement = document.createElement('textarea');
                                inputElement.id = `user-field-${fIndex}-${instIndex}-${fieldName}`;
                                inputElement.rows = 3;
                                inputElement.placeholder = placeholder;
                                
                                // Initialize value if undefined
                                if (instance.userFields[fieldName] === undefined) {
                                    instance.userFields[fieldName] = fieldValue;
                                }
                                
                                inputElement.value = instance.userFields[fieldName];
                                
                                // Set up input event
                                inputElement.addEventListener('input', function() {
                                    instance.userFields[fieldName] = this.value;
                                    saveSelections();
                                    updateInvoice();
                                });
                                break;
                            
                            case 'int-float':
                                // Create number input
                                inputElement = document.createElement('input');
                                inputElement.type = 'number';
                                inputElement.id = `user-field-${fIndex}-${instIndex}-${fieldName}`;
                                inputElement.step = 'any'; // Allow decimal points
                                inputElement.placeholder = placeholder;
                                
                                // Initialize value if undefined
                                if (instance.userFields[fieldName] === undefined) {
                                    instance.userFields[fieldName] = fieldValue;
                                }
                                
                                inputElement.value = instance.userFields[fieldName];
                                
                                // Set up input event
                                inputElement.addEventListener('input', function() {
                                    const val = parseFloat(this.value);
                                    instance.userFields[fieldName] = isNaN(val) ? 0 : val;
                                    saveSelections();
                                    updateInvoice();
                                });
                                break;
                            
                            case 'date':
                                // Create date input
                                inputElement = document.createElement('input');
                                inputElement.type = 'date';
                                inputElement.id = `user-field-${fIndex}-${instIndex}-${fieldName}`;
                                
                                // Initialize value if undefined
                                if (instance.userFields[fieldName] === undefined) {
                                    instance.userFields[fieldName] = fieldValue;
                                }
                                
                                inputElement.value = instance.userFields[fieldName];
                                
                                // Set up change event
                                inputElement.addEventListener('change', function() {
                                    instance.userFields[fieldName] = this.value;
                                    saveSelections();
                                    updateInvoice();
                                });
                                break;
                            
                            case 'normal-text':
                            default:
                                // Create text input (default)
                                inputElement = document.createElement('input');
                                inputElement.type = 'text';
                                inputElement.id = `user-field-${fIndex}-${instIndex}-${fieldName}`;
                                inputElement.placeholder = placeholder;
                                
                                // Initialize value if undefined
                                if (instance.userFields[fieldName] === undefined) {
                                    instance.userFields[fieldName] = fieldValue;
                                }
                                
                                inputElement.value = instance.userFields[fieldName];
                                
                                // Set up input event
                                inputElement.addEventListener('input', function() {
                                    instance.userFields[fieldName] = this.value;
                                    saveSelections();
                                    updateInvoice();
                                });
                                break;
                        }
                    }
                        
                        // Append input to label
                        label.appendChild(inputElement);
                        userFieldDiv.appendChild(label);
                        optionDetailsDiv.appendChild(userFieldDiv);
                        
                    } catch (e) {
                        console.error(`Error processing user field ${key}:`, e);
                    }
                }
            }

            // If non-recurring, let the user set quantity
            if (!feature.recurring) {
                let quantity = parseInt(instance.quantity) || 1;
                if (quantity < 1) quantity = 1;
                instance.quantity = quantity;

                const qtyDiv = document.createElement('div');
                qtyDiv.classList.add('quantity-input');
                qtyDiv.innerHTML = `
                    <label><strong>Quantity:</strong>
                        <input type="number" min="1" value="${quantity}">
                    </label>
                `;
                const qtyInput = qtyDiv.querySelector('input');
                qtyInput.addEventListener('change', function() {
                    let val = parseInt(this.value);
                    if (isNaN(val) || val < 1) {
                        val = 1;
                        this.value = 1;
                    }
                    instance.quantity = val;
                    saveSelections();
                    updateInvoice();
                });
                optionDetailsDiv.appendChild(qtyDiv);
            }

            // If there are addons, display their raw data (range or static)
            if (selectedOption.addons && selectedOption.addons.length > 0) {
                const addonsDiv = document.createElement('div');
                addonsDiv.classList.add('addons');
                const addonsTitle = document.createElement('h4');
                addonsTitle.textContent = 'Addons';
                addonsDiv.appendChild(addonsTitle);

                // Organize addons by group
                const { groups, ungrouped } = organizeAddonsByGroup(selectedOption.addons);
                
                // First render the ungrouped addons
                ungrouped.forEach(({addon, index: aIndex}) => {
                const addonItem = createAddonCheckboxItem(addon, aIndex, fIndex, instIndex, instance);
                addonsDiv.appendChild(addonItem);
                });
                
                // Then render each group with its own container
                Object.values(groups).forEach(group => {
                // Create group container
                const groupContainer = document.createElement('div');
                groupContainer.classList.add('addon-group');
                groupContainer.style.border = '1px solid #ccc';
                groupContainer.style.borderRadius = '4px';
                groupContainer.style.padding = '10px';
                groupContainer.style.marginTop = '15px';
                groupContainer.style.marginBottom = '15px';
                
                // Group header
                const groupHeader = document.createElement('div');
                groupHeader.classList.add('addon-group-header');
                groupHeader.textContent = group.name;
                groupHeader.style.fontWeight = 'bold';
                groupHeader.style.marginBottom = '10px';
                groupContainer.appendChild(groupHeader);
                
                // Add description of max items if applicable
                if (group.maxItems > 0) {
                    const maxItemsDesc = document.createElement('div');
                    maxItemsDesc.classList.add('max-group-items-desc');
                    maxItemsDesc.textContent = `Select up to ${group.maxItems} items`;
                    maxItemsDesc.style.fontSize = '(--fontSizeSmallest)';
                    maxItemsDesc.style.fontStyle = 'italic';
                    maxItemsDesc.style.marginBottom = '8px';
                    groupContainer.appendChild(maxItemsDesc);
                }
                
                // Add the addons to this group
                group.addons.forEach(({addon, index: aIndex}) => {
                    const addonItem = createAddonCheckboxItem(addon, aIndex, fIndex, instIndex, instance);
                    groupContainer.appendChild(addonItem);
                });
                
                // Add threshold discounts info if applicable
                if (group.thresholdDiscounts && group.thresholdDiscounts.length > 0) {
                    const discountInfo = document.createElement('div');
                    discountInfo.classList.add('group-discount-info');
                    discountInfo.style.fontSize = '(--fontSizeSmallest)';
                    discountInfo.style.marginTop = '8px';
                    discountInfo.style.color = '#d83838';
                    
                    // Sort discounts by item count
                    const sortedDiscounts = [...group.thresholdDiscounts].sort((a, b) => 
                        a.itemCount - b.itemCount
                    );
                    
                    // Get selected count
                    const selectedCount = getSelectedGroupCount(instance, group.name, selectedOption.addons);
                    
                    // Find applicable discount if any
                    const applicableDiscount = sortedDiscounts.filter(d => selectedCount >= d.itemCount)
                        .pop();
                    
                    if (applicableDiscount) {
                        discountInfo.innerHTML = `<strong>${applicableDiscount.discount}% discount</strong> applied for selecting ${selectedCount} items`;
                    } else {
                        // Show next available discount
                        const nextDiscount = sortedDiscounts.find(d => selectedCount < d.itemCount);
                        if (nextDiscount) {
                            discountInfo.innerHTML = `Select ${nextDiscount.itemCount} items for a <strong>${nextDiscount.discount}% discount</strong>`;
                        }
                    }
                    
                    groupContainer.appendChild(discountInfo);
                }
                
                // Add group container to addons div
                addonsDiv.appendChild(groupContainer);
                
                // Store a reference to this group on the container for easier access
                groupContainer.dataset.groupName = group.name;
                groupContainer.dataset.maxItems = group.maxItems;
                });
                
                optionDetailsDiv.appendChild(addonsDiv);
                
                // Apply constraints
                enforceMaxAddons(fIndex, instIndex, addonsDiv);
                enforceMaxGroupItems(fIndex, instIndex, addonsDiv);
            }
            
            saveSelections();
            updateInvoice();
        }

        // Helper to create addon checkbox items
        function createAddonCheckboxItem(addon, aIndex, fIndex, instIndex, instance) {
            const container = document.createElement('div');
            container.classList.add('addon-item');
            
            // Create checkbox
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = addon.id; // Use the actual addon ID instead of index
            checkbox.id = `addon-checkbox-${fIndex}-${instance.optionIndex}-${addon.id}`;
            checkbox.classList.add('addon-checkbox');
            if (addon.groupName) {
                checkbox.dataset.group = addon.groupName;
            }
            
            // Check if this addon is selected by ID instead of index
            if (instance.addons && instance.addons.includes(addon.id)) {
                checkbox.checked = true;
            }
            
            // Checkbox event handler
            checkbox.addEventListener('change', function() {
                if (!instance.addons) {
                    instance.addons = [];
                }
                if (this.checked) {
                    instance.addons.push(addon.id); // Store actual addon ID
                } else {
                    const idx = instance.addons.indexOf(addon.id);
                    if (idx !== -1) {
                        instance.addons.splice(idx, 1);
                    }
                }
                
                // After changing selection, enforce max addons and max group items
                const addonsDiv = this.closest('.addons');
                enforceMaxAddons(fIndex, instIndex, addonsDiv);
                enforceMaxGroupItems(fIndex, instIndex, addonsDiv);
                
                // Update discount info displays for all groups
                updateGroupDiscountDisplay(fIndex, instIndex, addonsDiv);
                
                saveSelections();
                updateInvoice();
            });
            
            container.appendChild(checkbox);
            
            // Get description and add tooltip if needed
            const addonDescription = getDescriptionText(addon.description);
            
            if (addonDescription) {
                const tooltipIcon = document.createElement('span');
                tooltipIcon.classList.add('tooltip-icon');
                tooltipIcon.textContent = '?';
                tooltipIcon.setAttribute('data-addon-name', addon.addonName);
                tooltipIcon.setAttribute('data-addon-desc', addonDescription);
                
                tooltipIcon.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                showTooltip(addon.addonName, addonDescription);
                });
                
                container.appendChild(tooltipIcon);
            } else {
                // Add a spacer element to maintain consistent layout
                const spacer = document.createElement('span');
                spacer.style.width = '16px';
                spacer.style.margin = '0 8px 0 8px';
                spacer.style.display = 'inline-block';
                container.appendChild(spacer);
            }
            
            // Create addon text span with proper class
            const addonTextSpan = document.createElement('span');
            addonTextSpan.classList.add('addon-text');
            
            // Price calculation and display
            const floorVal = parseSafe(addon.floorPriceMod);
            const ceilVal = parseSafe(addon.ceilingPriceMod);
            const symbol = isMultiply(addon) ? 'x' : '+';
            
            if (floorVal !== 0 || ceilVal !== 0) {
                addonTextSpan.textContent = `${addon.addonName} (${symbol}$${floorVal.toFixed(2)} - $${ceilVal.toFixed(2)})`;
            } else {
                const addonStatic = parseSafe(addon.staticPriceMod, 0);
                addonTextSpan.textContent = `${addon.addonName} (${symbol}$${addonStatic.toFixed(2)})`;
            }
            
            container.appendChild(addonTextSpan);
            return container;
            }

            // Update the discount displays
            function updateGroupDiscountDisplay(fIndex, instIndex, addonsDiv) {
                const instance = selections[fIndex][instIndex];
                const option = dashboardData.features[fIndex].options[instance.optionIndex];
                if (!option || !option.addons) return;
                
                // Find all group containers
                const groupContainers = addonsDiv.querySelectorAll('.addon-group');
                
                groupContainers.forEach(container => {
                    const groupName = container.dataset.groupName;
                    if (!groupName) return;
                    
                    // Find the discount info div
                    let discountInfo = container.querySelector('.group-discount-info');
                    if (!discountInfo) {
                        // Create it if it doesn't exist
                        discountInfo = document.createElement('div');
                        discountInfo.classList.add('group-discount-info');
                        discountInfo.style.fontSize = '(--fontSizeSmallest)';
                        discountInfo.style.marginTop = '8px';
                        discountInfo.style.color = '#d83838';
                        container.appendChild(discountInfo);
                    }
                    
                    // Get the addons in this group
                    const groupAddons = option.addons.filter(a => a.groupName === groupName && a.enableGrouping);
                    if (!groupAddons.length) {
                        discountInfo.style.display = 'none';
                        return;
                    }
                    
                    // Get the thresholds from the first addon in this group (they should all be the same)
                    const thresholdDiscounts = parseThresholdDiscounts(groupAddons[0].groupThresholdDiscounts);
                    if (!thresholdDiscounts.length) {
                        discountInfo.style.display = 'none';
                        return;
                    }
                    
                    // Get selected count
                    const selectedCount = getSelectedGroupCount(instance, groupName, option.addons);
                    
                    // Sort discounts by item count
                    const sortedDiscounts = [...thresholdDiscounts].sort((a, b) => 
                        a.itemCount - b.itemCount
                    );
                    
                    // Find applicable discount if any
                    const applicableDiscount = sortedDiscounts.filter(d => selectedCount >= d.itemCount)
                        .pop();
                    
                    if (applicableDiscount) {
                        discountInfo.style.display = 'block';
                        discountInfo.innerHTML = `<strong>${applicableDiscount.discount}% discount</strong> applied for selecting ${selectedCount} items`;
                    } else {
                        // Show next available discount
                        const nextDiscount = sortedDiscounts.find(d => selectedCount < d.itemCount);
                        if (nextDiscount) {
                            discountInfo.style.display = 'block';
                            discountInfo.innerHTML = `Select ${nextDiscount.itemCount} items for a <strong>${nextDiscount.discount}% discount</strong>`;
                        } else {
                            discountInfo.style.display = 'none';
                        }
                    }
                });
            }

            // Count selected addons in a specific group
            function getSelectedGroupCount(instance, groupName, allAddons) {
            if (!instance.addons || !Array.isArray(instance.addons)) return 0;
            
            return instance.addons.filter(addonId => {
                const addon = allAddons.find(a => a.id === addonId);
                return addon && addon.groupName === groupName && addon.enableGrouping;
            }).length;
        }

        // Enforce max group items limitation
        function enforceMaxGroupItems(fIndex, instIndex, addonsDiv) {
            const instance = selections[fIndex][instIndex];
            const option = dashboardData.features[fIndex].options[instance.optionIndex];
            if (!option || !option.addons) return;
            
            // Find all group containers
            const groupContainers = addonsDiv.querySelectorAll('.addon-group');
            
            groupContainers.forEach(container => {
                const groupName = container.dataset.groupName;
                const maxItems = parseInt(container.dataset.maxItems);
                
                // Skip groups with no limit
                if (!groupName || maxItems <= 0 || isNaN(maxItems)) return;
                
                // Count selected items in this group
                const selectedCount = getSelectedGroupCount(instance, groupName, option.addons);
                
                // Find all checkboxes for this group
                const groupCheckboxes = container.querySelectorAll('input[type="checkbox"]');
                
                // Disable unchecked boxes if limit reached, otherwise enable all
                groupCheckboxes.forEach(checkbox => {
                if (!checkbox.checked) {
                    checkbox.disabled = selectedCount >= maxItems;
                }
                });
                
                // Update message if present
                const maxItemsMessage = container.querySelector('.max-group-items-desc');
                if (maxItemsMessage) {
                if (selectedCount >= maxItems) {
                    maxItemsMessage.style.color = '#d83838';
                    maxItemsMessage.style.fontWeight = 'bold';
                } else {
                    maxItemsMessage.style.color = '';
                    maxItemsMessage.style.fontWeight = '';
                }
                }
            });
        }

        // Helper function to organize addons by group
        function organizeAddonsByGroup(addons) {
            const groups = {};
            const ungrouped = [];
            
            addons.forEach((addon, index) => {
                if (addon.groupName && addon.enableGrouping) {
                if (!groups[addon.groupName]) {
                    groups[addon.groupName] = {
                    name: addon.groupName,
                    maxItems: addon.maxGroupItems,
                    addons: [],
                    thresholdDiscounts: parseThresholdDiscounts(addon.groupThresholdDiscounts)
                    };
                }
                groups[addon.groupName].addons.push({addon, index});
                } else {
                ungrouped.push({addon, index});
                }
            });
            
            return { groups, ungrouped };
        }

        // Global tooltip functions
        function showTooltip(title, description) {
            // Remove any existing tooltips
            let existingTooltip = document.querySelector('.tooltip-overlay');
            if (existingTooltip) {
                document.body.removeChild(existingTooltip);
            }
            
            // Create new tooltip
            const overlay = document.createElement('div');
            overlay.classList.add('tooltip-overlay');
            
            const content = document.createElement('div');
            content.classList.add('tooltip-content');
            
            const tooltipTitle = document.createElement('div');
            tooltipTitle.classList.add('tooltip-title');
            tooltipTitle.textContent = title;
            
            const tooltipBody = document.createElement('div');
            tooltipBody.classList.add('tooltip-body');
            tooltipBody.textContent = description;
            
            content.appendChild(tooltipTitle);
            content.appendChild(tooltipBody);
            overlay.appendChild(content);
            
            // Add to body
            document.body.appendChild(overlay);
            
            // Force a reflow before adding the active class
            void overlay.offsetWidth;
            
            // Add active class to trigger animations
            overlay.classList.add('active');
            
            // Click handler to close when clicking outside
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    closeTooltip(overlay);
                }
            });
            
            // Add swipe gesture support
            let startX, startY, distX, distY;
            let threshold = 100; // Minimum distance required for a swipe
            
            content.addEventListener('touchstart', function(e) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
                e.preventDefault();
            }, false);
            
            content.addEventListener('touchmove', function(e) {
                if (!startX || !startY) return;
                
                distX = e.touches[0].clientX - startX;
                distY = e.touches[0].clientY - startY;
                e.preventDefault();
            }, false);
            
            content.addEventListener('touchend', function(e) {
                if (!startX || !startY) return;
                
                // Check if horizontal swipe distance is greater than threshold
                if (Math.abs(distX) >= threshold || Math.abs(distY) >= threshold) {
                    closeTooltip(overlay);
                }
                
                // Reset values
                startX = startY = distX = distY = null;
                e.preventDefault();
            }, false);
            
            // Also close when pressing escape
            document.addEventListener('keydown', function escHandler(e) {
                if (e.key === 'Escape') {
                    closeTooltip(overlay);
                    document.removeEventListener('keydown', escHandler);
                }
            });
        }

        function closeTooltip(overlay) {
            overlay.classList.remove('active');
            
            // Remove from DOM after animation completes
            setTimeout(() => {
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
            }, 300); // Match transition duration
        }

        // Helper to re-render all instances for a given feature type
        function renderFeatureInstances(feature, fIndex) {
            const featureTypeDiv = document.querySelectorAll('.feature-type')[fIndex];
            const instancesContainer = featureTypeDiv.querySelector('.instances-container');
            instancesContainer.innerHTML = '';
            selections[fIndex].forEach((instance, instIndex) => {
                const instanceDiv = renderInstance(feature, fIndex, instIndex, instance);
                instancesContainer.appendChild(instanceDiv);
            });
        }

        // Render all feature types with their instances
        dashboardData.features.forEach((feature, fIndex) => {
            if (!selections[fIndex] || !Array.isArray(selections[fIndex])) {
                selections[fIndex] = [{}]; // Start with one empty instance
            }

            const featureTypeDiv = document.createElement('div');
            featureTypeDiv.classList.add('feature-type');

            // Header with feature name + description
            const header = document.createElement('h3');
            header.textContent = feature.featureName;
            featureTypeDiv.appendChild(header);

            const descriptionP = document.createElement('p');
            descriptionP.textContent = feature.description;
            featureTypeDiv.appendChild(descriptionP);

            // Container for the instances
            const instancesContainer = document.createElement('div');
            instancesContainer.classList.add('instances-container');

            // Render each instance
            selections[fIndex].forEach((instance, instIndex) => {
                const instanceDiv = renderInstance(feature, fIndex, instIndex, instance);
                instancesContainer.appendChild(instanceDiv);
            });
            featureTypeDiv.appendChild(instancesContainer);

            // "Add new" button
            const addNewBtn = document.createElement('button');
            addNewBtn.type = 'button';
            addNewBtn.textContent = `Add new ${feature.featureName}`;
            addNewBtn.classList.add('add-new-feature');
            addNewBtn.addEventListener('click', function() {
                selections[fIndex].push({});
                saveSelections();
                renderFeatureInstances(feature, fIndex);
                updateInvoice();
            });
            featureTypeDiv.appendChild(addNewBtn);

            featuresContainer.appendChild(featureTypeDiv);
        });

        // Update profile handler
        const updateProfileBtn = document.getElementById('update-profile-btn');
        if (updateProfileBtn) updateProfileBtn.addEventListener('click', async () =>{
            if (dashboardData.campaign_config) return;

            const profileMessage   = document.getElementById('profile-message');
            const updateProfileBtn = document.getElementById('update-profile-btn');

            // Gather form data
            const firstName = document.getElementById('profile-first-name').value.trim();
            const lastName  = document.getElementById('profile-last-name').value.trim();
            const email     = document.getElementById('profile-email').value.trim();

            // Prepare request
            const url = `${myApi.api_url}update-profile`;
            const options = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                first_name: firstName,
                last_name:  lastName,
                email:      email,
                auth_id:    window.auth_id
                })
            };

            // Clear prior messages and show loader
            profileMessage.textContent = '';
            updateProfileBtn.innerHTML = `<img class="loader" src="${window.template_path}/images/loading.gif" alt="Loading…" style="max-width: 15px;">`;

            try {
                const response = await fetch(url, options);

                // If we got a 403 specifically, handle it first
                if (response.status === 403) {
                    profileMessage.textContent = 'You\'re not authorized to update this profile.';
                    return;
                }

                // For any non-2xx status, attempt to pull an error message from JSON
                if (!response.ok) {
                    let errMsg = response.statusText; // fallback
                try {
                    const errJson = await response.json();
                    // adjust these keys to whatever your API returns
                    errMsg = errJson.error || errJson.message || errMsg;
                } catch (e) {
                    // non-JSON body or parse failed; keep statusText
                }
                    profileMessage.textContent = `Error (${response.status}): ${errMsg}`;
                    return;
                }

                // Success path
                const data = await response.json();
                profileMessage.textContent = data.message || 'Profile updated successfully.';
            }
            catch (networkError) {
                // e.g. DNS failure or network down
                profileMessage.textContent = `Network error: ${networkError.message}`;
            }
            finally {
                // Restore the button text in all cases
                updateProfileBtn.innerHTML = 'Update Profile';
            }
        });

        // Reset password handler
        const resetPasswordBtn = document.getElementById('reset-password-btn');
        if (resetPasswordBtn) {
            resetPasswordBtn.addEventListener('click', async () => {
                const profileMessage = document.getElementById('profile-message');
                // Prepare request
                const url = `${myApi.api_url}reset-password`;
                const options = {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        auth_id:    window.auth_id
                    })
                };

                // Clear prior messages and show loader
                profileMessage.textContent = '';
                resetPasswordBtn.innerHTML = `<img class="loader" src="${window.template_path}/images/loading.gif" alt="Loading…" style="max-width: 15px;">`

                try {
                    const response = await fetch(url, options);

                    // If we got a 403 specifically, handle it first
                    if (response.status === 403) {
                        profileMessage.textContent = 'You\'re not authorized to update this profile.';
                        return;
                    }

                    // For any non-2xx status, attempt to pull an error message from JSON
                    if (!response.ok) {
                        let errMsg = response.statusText; // fallback
                    try {
                        const errJson = await response.json();
                        // adjust these keys to whatever your API returns
                        errMsg = errJson.error || errJson.message || errMsg;
                    } catch (e) {
                        // non-JSON body or parse failed; keep statusText
                    }
                        profileMessage.textContent = `Error (${response.status}): ${errMsg}`;
                        return;
                    }

                    // Success path
                    const data = await response.json();
                    profileMessage.textContent = data.message || 'Password reset email sent.';
                }
                catch (networkError) {
                    // e.g. DNS failure or network down
                    profileMessage.textContent = `Network error: ${networkError.message}`;
                }
                finally {
                    // Restore the button text in all cases
                    resetPasswordBtn.innerHTML = 'Send Password Reset';
                }
            });
        }

        // Finally, build the invoice
        updateInvoice();

        // Stub pay-now button
        const payNowBtn = document.getElementById('pay-now');
        if (payNowBtn) {
            payNowBtn.removeEventListener('click', payNowBtn.onclick);
            updateOrderButton();
        }

        // ---------------------------------------------------------------
        // Stripe integration
        // ---------------------------------------------------------------
        let stripe, elements, paymentElement;
        
        // Decide which Stripe confirm* to call based on server response
        async function confirmByIntentType(resp, elements) {
        const s = stripe || window.stripe;
        if (!s) throw new Error('Stripe.js not initialized');

        const confirmParams = { return_url: window.location.href };

        if (resp.intentType === 'payment_intent') {
            const { error, paymentIntent } = await s.confirmPayment({
                elements,
                confirmParams,
                redirect: 'if_required',
            });
            if (error) throw error;
            return { kind: 'payment_intent', id: paymentIntent.id };
        } else {
            const { error, setupIntent } = await s.confirmSetup({
                elements,
                confirmParams,
                redirect: 'if_required',
            });
            if (error) throw error;
            return { kind: 'setup_intent', id: setupIntent.id };
        }
        }

        // Initialize Stripe if the library is loaded
        if (typeof Stripe !== 'undefined' && dashboardData.stripeKey) {
            
            if (window.Stripe && navigator.onLine) {
                // Initialize Stripe only when online
                stripe = Stripe(dashboardData.stripeKey);
                window.stripe = stripe;
            } else {
                console.log('Stripe not available offline');
                // Disable payment features or show offline message
            }
            
            // Check URL parameters for payment status
            checkPaymentStatus();
            
            // Initialize payment if there's a valid order that's not yet paid
            if (hasValidOrder() && !isOrderPaid()) {
                initializeStripePayment();
            } else if (isOrderPaid()) {
                // If order is already paid, show success
                showPaymentSuccess();
            }
        }
        
        // Function to initialize Stripe payment form
        async function initializeStripePayment() {
            
            if (isOrderPaid()) {
                showPaymentSuccess();
                return;
            }

            if (!stripe) {
                console.error('Stripe not properly configured');
                return;
            }
            
            // Get existing order data
            const orderData = sessionStorage.getItem('placedOrder');
            if (!orderData) {
                console.error('No order data found');
                return;
            }
            
            const orderInfo = JSON.parse(orderData);
            if (!orderInfo || !orderInfo.orderID) {
                console.error('Invalid order data');
                return;
            }
            
            // Create payment intent
            const overlay = showLoadingOverlay();

            // Prepare request
            const url = `${myApi.api_url}create-payment-intent`;
            const options = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    orderID: orderInfo.orderID,
                    auth_id: window.auth_id
                })
            };

            try {
                const response = await fetch(url, options);

                // If we got a 403 specifically, handle it first
                if (response.status === 403) {
                    console.error('You\'re not authorized to create this payment intent.');
                    return;
                }

                // For any non-2xx status, attempt to pull an error message from JSON
                if (!response.ok) {
                    let errMsg = response.statusText; // fallback
                try {
                    const errJson = await response.json();
                    // adjust these keys to whatever your API returns
                    errMsg = errJson.error || errJson.message || errMsg;
                } catch (e) {
                    // non-JSON body or parse failed; keep statusText
                }
                    console.error(`Error (${response.status}): ${errMsg}`);
                    return;
                }

                // Success path
                const data = await response.json();

                if (data.success && data.clientSecret) {
                    // Create the payment form
                    createPaymentForm(data.clientSecret);
                } else {
                    throw new Error('Invalid response from server');
                }
                hideLoadingOverlay(overlay);
            }
            catch (error) {
                console.error('Payment intent error:', error);
                alert('Error initializing payment: ' + error.message);
                hideLoadingOverlay(overlay);
            }
        }
        
        // Create and mount the Stripe payment form
        function createPaymentForm(clientSecret) {
            if (isOrderPaid()) {
                showPaymentSuccess();
                return;
            }

            // Create container + form + error div
            const paymentContainer = document.createElement('div');
            paymentContainer.id = 'payment-element-container';
            paymentContainer.style.marginBottom = '20px';

            const form = document.createElement('form');
            form.id = 'payment-form';
            
            // Create the payment element container
            const paymentElementDiv = document.createElement('div');
            paymentElementDiv.id = 'payment-element';
            form.appendChild(paymentElementDiv);

            const errorDiv = document.createElement('div');
            errorDiv.id = 'payment-error';
            errorDiv.style.color = 'red';
            errorDiv.style.marginTop = '10px';
            errorDiv.style.display = 'none';
            form.appendChild(errorDiv);

            paymentContainer.appendChild(form);

            const payNowBtn = document.getElementById('pay-now');
            payNowBtn.parentNode.insertBefore(paymentContainer, payNowBtn);

            // Initialize Stripe Elements
            elements = stripe.elements({
                clientSecret: clientSecret,
                appearance: {
                    theme: 'stripe',
                    variables: {
                        colorPrimary: '#0073aa',
                        colorBackground: '#ffffff',
                        colorText: '#333333',
                        colorDanger: '#d83838',
                        fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                        borderRadius: '4px'
                    }
                }
            });

            // Mount the Payment Element
            paymentElement = elements.create('payment');
            paymentElement.mount('#payment-element');

            // Re-enable “Pay Now” and only disable on click
            payNowBtn.textContent = 'Pay Now';
            payNowBtn.disabled = false;
            payNowBtn.onclick = function(e) {
                e.preventDefault();
                payNowBtn.disabled = true;
                payNowBtn.textContent = 'Processing...';
                handlePayment(e);
            };

            setTimeout(() => {
                smoothScrollToElement(paymentContainer, 120);
            }, 100);
        }
        
        // Handle the payment submission
        async function handlePayment(event) {
            event.preventDefault();
            
            if (!stripe || !elements) {
                console.error('Stripe not initialized');
                return;
            }
            
            const payNowBtn = document.getElementById('pay-now');
            const errorDisplay = document.getElementById('payment-error');
            
            // Disable the button and show loading state
            payNowBtn.disabled = true;
            payNowBtn.textContent = 'Processing...';
            errorDisplay.style.display = 'none';
            
            try {
                // Get return URL for after payment (current page)
                const returnUrl = window.location.href;
                
                // Confirm payment with Stripe
                const result = await stripe.confirmPayment({
                    elements,
                    confirmParams: {
                        return_url: returnUrl,
                    },
                    redirect: 'if_required'
                });
                
                if (result.error) {
                    // Show error to customer
                    errorDisplay.textContent = result.error.message;
                    errorDisplay.style.display = 'block';
                    
                    // Reset button state
                    payNowBtn.disabled = false;
                    payNowBtn.textContent = 'Pay Now';
                } else {
                    // Payment succeeded
                    payNowBtn.textContent = 'Payment Successful!';
                    
                    // If we're still on the page (no redirect happened), update the UI
                    if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                        // Update order status in the database
                        await updateOrderAfterPayment(result.paymentIntent);
                        // showPaymentSuccess() will be called by updateOrderAfterPayment()
                    }
                }
            } catch (error) {
                console.error('Payment error:', error);
                
                // Show error to customer
                errorDisplay.textContent = 'An unexpected error occurred. Please try again.';
                errorDisplay.style.display = 'block';
                
                // Reset button state
                payNowBtn.disabled = false;
                payNowBtn.textContent = 'Pay Now';
            }
        }

        // Update order status after successful payment
        async function updateOrderAfterPayment(paymentIntent) {
            const orderData = sessionStorage.getItem('placedOrder');
            if (!orderData) {
                console.error('No order data found');
                return;
            }
            
            const orderInfo = JSON.parse(orderData);
            if (!orderInfo || !orderInfo.orderID) {
                console.error('Invalid order data');
                return;
            }

            // Prepare request
            const url = `${myApi.api_url}update-payment-status`;
            const options = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    orderID: orderInfo.orderID,
                    status: 'paid',
                    paymentIntentId: paymentIntent.id,
                    auth_id: window.auth_id
                })
            };

            try {
                const response = await fetch(url, options);

                if (response.status === 403) {
                    console.error('You\'re not authorized.');
                    return;
                }

                if (!response.ok) {
                    let errMsg = response.statusText;
                    try {
                        const errJson = await response.json();
                        errMsg = errJson.error || errJson.message || errMsg;
                    } catch (e) {
                        // non-JSON body or parse failed; keep statusText
                    }
                    console.error(`Error (${response.status}): ${errMsg}`);
                    return;
                }

                const data = await response.json();

                if (data && data.loggedIn) window.auth_id = '1';

                if (data.success) {
                    // Update session storage
                    orderInfo.status = 'paid';
                    orderInfo.paymentIntentId = paymentIntent.id;
                    orderInfo.paidAt = new Date().toISOString();
                    sessionStorage.setItem('placedOrder', JSON.stringify(orderInfo));
                    
                    // If this was a campaign order with account creation, show account message
                    if (dashboardData.campaign_config && (googleAuthData || window.auth_id)) {
                        setTimeout(() => {
                            const accountMessage = document.createElement('div');
                            accountMessage.style.cssText = `
                                position: fixed;
                                top: 80px;
                                right: 20px;
                                background: #2196F3;
                                color: white;
                                padding: 15px 20px;
                                border-radius: 4px;
                                box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                                z-index: 10000;
                                max-width: 300px;
                            `;
                            accountMessage.innerHTML = `
                                <strong>Account Created!</strong><br>
                                Your account is now set up. You can access your full dashboard anytime by logging in with Google.
                            `;
                            document.body.appendChild(accountMessage);
                            
                            // Remove message after 8 seconds
                            setTimeout(() => {
                                accountMessage.remove();
                            }, 8000);
                        }, 2000);
                    }

                    // Show success UI
                    showPaymentSuccess();
                } else {
                    console.error('Failed to update order status:', data);
                }
            }
            catch (error) {
                console.error('Error updating order status:', error);
            }
        }
        
        function isOrderPaid() {
            const orderData = sessionStorage.getItem('placedOrder');
            if (!orderData) return false;
            
            try {
                const orderInfo = JSON.parse(orderData);
                return orderInfo && orderInfo.status === 'paid';
            } catch (e) {
                return false;
            }
        }

        function showPaymentSuccess() {
            const payNowBtn = document.getElementById('pay-now');
            
            // Disable form interaction immediately
            disableFormInteraction();
            
            // Remove any existing payment form
            const existingPaymentContainer = document.getElementById('payment-element-container');
            if (existingPaymentContainer) {
                existingPaymentContainer.remove();
            }
            
            // Clear any Stripe elements
            if (paymentElement) {
                try {
                    paymentElement.unmount();
                    paymentElement = null;
                    elements = null;
                } catch (error) {
                    console.log('Payment element already unmounted');
                }
            }
            
            // Remove any existing success container to avoid duplicates
            const existingSuccessContainer = document.getElementById('payment-success-container');
            if (existingSuccessContainer) {
                existingSuccessContainer.remove();
            }
            
            // Create success message with new order button
            const successContainer = document.createElement('div');
            successContainer.id = 'payment-success-container';
            successContainer.style.marginBottom = '20px';
            
            // Different messages for campaign vs regular orders
            let successMessage = `
                <div style="padding: 20px; background-color: #4CAF50; color: white; border-radius: 4px; text-align: center; margin-bottom: 15px;">
                    <h3 style="margin: 0 0 10px 0;">Payment Successful!</h3>
                    <p style="margin: 0;">Your order has been paid. Thank you for your purchase!</p>
                </div>
            `;
            
            let buttonText = 'Start a New Order';
            let buttonAction = () => {
                // Clear the session storage
                sessionStorage.removeItem('placedOrder');
                sessionStorage.removeItem('priceCalcSelections');
                
                // If this was a campaign order, redirect to login page so they can access full dashboard
                if (dashboardData.campaign_config && (googleAuthData || window.auth_id)) {
                    window.location.href = '/ffc-login'; // Use your custom login slug
                } else {
                    // Regular flow - reload page
                    window.location.reload();
                }
            };
            
            // If this was a campaign order with account creation
            if (dashboardData.campaign_config && (googleAuthData || window.auth_id)) {
                successMessage = `
                    <div style="padding: 20px; background-color: #4CAF50; color: white; border-radius: 4px; text-align: center; margin-bottom: 15px;">
                        <h3 style="margin: 0 0 10px 0;">Payment Successful!</h3>
                        <p style="margin: 0 0 10px 0;">Your order has been paid and your account has been created!</p>
                        <p style="margin: 0; font-size: 14px;">You can now access your full dashboard anytime by logging in with Google.</p>
                    </div>
                `;
                buttonText = 'Go to Login Page';
            }
            
            successContainer.innerHTML = `
                ${successMessage}
                <button id="start-new-order" style="
                    display: block;
                    width: 100%;
                    padding: 12px 20px;
                    background-color: #0073aa;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    font-size: 16px;
                    font-weight: bold;
                    cursor: pointer;
                ">
                    ${buttonText}
                </button>
            `;
            
            // Insert before the pay now button
            if (payNowBtn && payNowBtn.parentNode) {
                payNowBtn.parentNode.insertBefore(successContainer, payNowBtn);
                payNowBtn.style.display = 'none';
                
                // Add event listener to new order button
                document.getElementById('start-new-order').addEventListener('click', buttonAction);
            }
        }

        // Check for URL parameters indicating payment status
        function checkPaymentStatus() {
            const urlParams = new URLSearchParams(window.location.search);
            const paymentIntent = urlParams.get('payment_intent');
            const paymentIntentClientSecret = urlParams.get('payment_intent_client_secret');
            const redirectStatus = urlParams.get('redirect_status');
            
            if (paymentIntent && paymentIntentClientSecret && redirectStatus) {
                // Handle the redirect back from Stripe
                if (redirectStatus === 'succeeded') {
                    // Update order session
                    const orderData = sessionStorage.getItem('placedOrder');
                    if (orderData) {
                        const orderInfo = JSON.parse(orderData);
                        orderInfo.status = 'paid';
                        orderInfo.paymentIntentId = paymentIntent;
                        orderInfo.paidAt = new Date().toISOString();
                        sessionStorage.setItem('placedOrder', JSON.stringify(orderInfo));
                    }
                    
                    // Retrieve the payment intent to update the order
                    stripe.retrievePaymentIntent(paymentIntentClientSecret)
                        .then(({paymentIntent}) => {
                            if (paymentIntent && paymentIntent.status === 'succeeded') {
                                updateOrderAfterPayment(paymentIntent);
                                // showPaymentSuccess() will be called by updateOrderAfterPayment()
                            }
                        })
                        .catch(error => {
                            console.error('Error retrieving payment intent:', error);
                            // Still show success since we know it succeeded from redirect
                            showPaymentSuccess();
                        });
                } else {
                    // Payment failed or was cancelled
                    const errorDisplay = document.getElementById('payment-error');
                    if (errorDisplay) {
                        errorDisplay.textContent = 'Payment failed or was cancelled. Please try again.';
                        errorDisplay.style.display = 'block';
                    }
                }
                
                // Clean up the URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        }
        
        // Subscription management functions
        let updatePaymentElements, updatePaymentElement;

        async function loadSubscriptions() {
            const container = document.getElementById('subscriptions-container');
            const loading = document.getElementById('subscriptions-loading');
            const noSubs = document.getElementById('no-subscriptions');
            const subsManagementEl = document.querySelector('#subscriptions-management');
            const manageSubsBtn = document.querySelector('#manage-subs-btn');
            
            loading.style.display = 'block';
            container.style.display = 'none';
            noSubs.style.display = 'none';
            manageSubsBtn.style.opacity = '0.1';
            manageSubsBtn.style.pointerEvents = 'none';
            
            try {
                const response = await fetch(`${myApi.api_url}get-subscriptions`, {
                    method: 'GET'
                });
                
                if (!response.ok) throw new Error('Failed to load subscriptions');
                
                const data = await response.json();
                smoothScrollToElement(subsManagementEl);

                if (data.success && data.subscriptions.length > 0) {
                    renderSubscriptions(data.subscriptions);
                    container.style.display = 'block';
                } else {
                    noSubs.style.display = 'block';
                }
            } catch (error) {
                console.error('Error loading subscriptions:', error);
                container.innerHTML = '<p style="color: red;">Error loading subscriptions. Please try again.</p>';
                container.style.display = 'block';
            } finally {
                loading.style.display = 'none';
            }
        }

        function renderSubscriptions(subscriptions) {
            const container = document.getElementById('subscriptions-container');
            container.innerHTML = '';
            
            subscriptions.forEach(sub => {
                const card = document.createElement('div');
                card.className = 'subscription-card';
                
                const nextBilling = new Date(sub.subscription_current_period_end);
                const formattedDate = nextBilling.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                
                // Get feature and option IDs directly from the subscription data
                const featureId = parseInt(sub.featureId);
                const currentOptionId = parseInt(sub.optionId);
                
                const isPastDue = sub.subscription_status === 'past_due';

                // Check if this recurring feature has other options
                let availablePlans = [];
                
                if (featureId && dashboardData.features) {
                    const feature = dashboardData.features.find(f => parseInt(f.id) === featureId);
                    if (feature && feature.recurring && feature.options) {
                        // Filter out the current option
                        availablePlans = feature.options.filter(opt => isPastDue || parseInt(opt.id) !== currentOptionId);
                    }
                }
                
                card.innerHTML = `
                    <div class="subscription-header">
                        <div class="subscription-title">${sub.features}</div>
                        <div class="subscription-status ${sub.subscription_status}">${formatStatus(sub.subscription_status)}</div>
                    </div>
                    
                    <div class="subscription-details">
                        <div class="subscription-detail">
                            <div class="subscription-detail-label">Plan</div>
                            <div class="subscription-detail-value">${sub.options}</div>
                        </div>
                        <div class="subscription-detail">
                            <div class="subscription-detail-label">Billing</div>
                            <div class="subscription-detail-value">$${sub.total_amount} / ${sub.intervals}</div>
                        </div>
                        <div class="subscription-detail">
                            <div class="subscription-detail-label">Next Payment</div>
                            <div class="subscription-detail-value">${formattedDate}</div>
                        </div>
                        <div class="subscription-detail">
                            <div class="subscription-detail-label">Started</div>
                            <div class="subscription-detail-value">${new Date(sub.started_at).toLocaleDateString()}</div>
                        </div>
                    </div>
                    
                    ${(availablePlans.length > 0 || isPastDue) ? `
                        <div class="plan-change-section" style="margin: 15px 0; padding: 15px; background: #f5f5f5; border-radius: 4px;">
                            ${isPastDue ? 
                                `<div style="color: #d83838; margin-bottom: 10px; font-weight: bold;">
                                    Your subscription is past due. Please renew to continue service.
                                </div>` : ''
                            }
                            
                            ${availablePlans.length > 0 ? `
                                <label style="display: block; margin-bottom: 8px; font-weight: bold;">
                                    ${isPastDue ? 'Renew with a different plan:' : 'Change Plan:'}
                                </label>
                                <select class="plan-select" data-subscription-id="${sub.subscription_id}" 
                                        data-current-option="${currentOptionId}"
                                        style="width: 100%; padding: 8px; margin-bottom: 10px;">
                                    <option value="">Select a plan...</option>
                                    ${availablePlans.map(plan => `
                                        <option value="${plan.id}">
                                            ${plan.optionName} - $${plan.staticPrice}/${plan.interval || 'month'}
                                        </option>
                                    `).join('')}
                                </select>
                            ` : ''}
                            
                            <button class="btn-primary ${isPastDue ? 'renew-subscription' : 'change-plan'}" 
                                    data-subscription-id="${sub.subscription_id}"
                                    ${availablePlans.length > 0 ? 'disabled' : ''}>
                                ${isPastDue ? 'Renew Subscription' : 'Change Plan'}
                            </button>
                        </div>
                    ` : ''}
                    
                    <div class="subscription-actions">
                        <button class="btn-primary update-payment-btn" 
                                ${isPastDue > 0 ? 'disabled ' : ''}
                                data-subscription-id="${sub.subscription_id}">
                            Update Payment Method
                        </button>
                        ${sub.subscription_status === 'active' && !isPastDue ? 
                            `<button class="btn-danger cancel-sub-btn" data-subscription-id="${sub.subscription_id}">
                                Cancel Subscription
                            </button>` 
                            : ''}
                    </div>
                `;
                
                container.appendChild(card);
            });
            
            // Add event listeners
            container.querySelectorAll('.cancel-sub-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    cancelSubscription(e.target.dataset.subscriptionId);
                });
            });
            
            container.querySelectorAll('.update-payment-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    updatePaymentMethod(e.target.dataset.subscriptionId);
                });
            });
            
            // Plan change dropdown listeners
            container.querySelectorAll('.plan-select').forEach(select => {
                select.addEventListener('change', (e) => {
                    const btn = e.target.parentElement.querySelector('.change-plan, .renew-subscription');
                    btn.disabled = !e.target.value;
                });
            });
            
            // Change plan / Renew button listeners
            container.querySelectorAll('.change-plan, .renew-subscription').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const subscriptionId = e.target.dataset.subscriptionId;
                    const planSelect = e.target.parentElement.querySelector('.plan-select');
                    const newOptionId = planSelect ? planSelect.value : null;
                    const isRenewal = e.target.classList.contains('renew-subscription');
                    
                    if (newOptionId) {
                        initiatePlanChange(subscriptionId, newOptionId, isRenewal);
                    }
                });
            });
        }

        function formatStatus(status) {
            const statusMap = {
                'active': 'Active',
                'past_due': 'Past Due',
                'cancelled': 'Cancelled',
                'trialing': 'Trial'
            };
            return statusMap[status] || status;
        }

        async function cancelSubscription(subscriptionId) {
            const modal = document.getElementById('cancel-subscription-modal');
            const content = document.getElementById('cancel-subscription-content');
            const loader = document.getElementById('cancel-modal-loader');
            const closeBtn = document.getElementById('close-cancel-modal');
            const confirmBtn = document.getElementById('confirm-cancel-subscription');
            const actionButtons = document.querySelector('.update-payment-actions');
            
            // Show modal
            modal.style.display = 'flex';
            content.style.display = 'block';
            loader.style.display = 'none';
            actionButtons.style.display = 'flex';
            
            // Store subscription ID for the confirm handler
            modal.dataset.subscriptionId = subscriptionId;
            
            // Close button handler
            const closeHandler = () => {
                modal.style.display = 'none';
                modal.dataset.subscriptionId = '';
            };
            
            // Remove any existing listeners
            closeBtn.removeEventListener('click', closeHandler);
            modal.removeEventListener('click', modalClickHandler);
            
            // Add close handlers
            closeBtn.addEventListener('click', closeHandler);
            
            // Click outside to close
            function modalClickHandler(e) {
                if (e.target === modal) {
                    closeHandler();
                }
            }
            modal.addEventListener('click', modalClickHandler);
            
            // Confirm button handler (one-time use)
            confirmBtn.onclick = async () => {
                // Hide content and show loader
                content.style.display = 'none';
                loader.style.display = 'block';
                
                try {
                    const response = await fetch(`${myApi.api_url}cancel-subscription`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            subscriptionId: modal.dataset.subscriptionId
                        })
                    });
                    
                    const data = await response.json();

                    if (data.success) {
                        // Close modal
                        modal.style.display = 'none';
                        
                        // Show success message
                        const successMessage = document.createElement('div');
                        successMessage.style.cssText = `
                            position: fixed;
                            top: 20px;
                            right: 20px;
                            background: #4CAF50;
                            color: white;
                            padding: 15px 20px;
                            border-radius: 4px;
                            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                            z-index: 10000;
                        `;
                        successMessage.textContent = 'Successfully canceled';
                        document.body.appendChild(successMessage);
                        
                        // Remove success message after 3 seconds
                        setTimeout(() => {
                            successMessage.remove();
                        }, 3000);
                        
                        // Reload subscriptions
                        loadSubscriptions();
                    } else {
                        throw new Error(data.message || 'Failed to cancel subscription');
                    }
                } catch (error) {
                    // Show error in modal
                    loader.style.display = 'none';
                    content.innerHTML = `
                        <div style="color: #d83838; margin-bottom: 20px;">
                            <p><strong>Error:</strong> ${error.message}</p>
                        </div>
                        <div class="update-payment-actions">
                            <button class="button" id="error-close-cancel">Close</button>
                        </div>
                    `;
                    content.style.display = 'block';
                    
                    // Add close handler to error button
                    document.getElementById('error-close-cancel').addEventListener('click', closeHandler);
                }
            };
        }

        if (updateModal) {
            // Ensure payment element container exists
            if (!document.getElementById('update-payment-element')) {
                const modalContent = updateModal.querySelector('.update-payment-content');
                if (modalContent) {
                    const paymentElementDiv = document.createElement('div');
                    paymentElementDiv.id = 'update-payment-element';
                    
                    // Insert before error div if it exists
                    const errorDiv = document.getElementById('update-payment-error');
                    if (errorDiv) {
                        errorDiv.parentNode.insertBefore(paymentElementDiv, errorDiv);
                    } else {
                        modalContent.appendChild(paymentElementDiv);
                    }
                }
            }
            
            // Ensure submit button is disabled by default
            const submitBtn = document.getElementById('update-payment-submit');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Update';
            }
        }

        // Helper to show action buttons after payment element loads
        function showModalActionButtons() {
            const actionButtons = document.querySelector('.update-payment-actions');
            if (actionButtons) {
                // Use requestAnimationFrame to ensure the browser has rendered the payment element
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        actionButtons.style.display = 'flex';
                    });
                });
            }
        }

        async function updatePaymentMethod() {
            const modal = document.getElementById('update-payment-modal');
            const modalLoader = document.querySelector('#update-modal-loader');
            const cancelBtn = document.querySelector('#cancel-update-payment');
            const submitBtn = document.getElementById('update-payment-submit');
            const actionButtons = document.querySelector('.update-payment-actions');
            
            // Setup modal
            modal.style.display = 'flex';
            modalLoader.style.display = 'block';
            submitBtn.disabled = true; // Keep disabled until loaded
            submitBtn.textContent = 'Update';
            
            // Hide action buttons until payment element loads
            if (actionButtons) {
                actionButtons.style.display = 'none';
            }

            // Add cancel handler (remove any existing first)
            cancelBtn.removeEventListener('pointerup', closeUpdatePaymentModal);
            cancelBtn.addEventListener('pointerup', closeUpdatePaymentModal);
            
            try {
                const response = await fetch(`${myApi.api_url}update-payment-method`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                if (data.success && stripe) {
                    // Create elements for updating payment method
                    updatePaymentElements = stripe.elements({
                        clientSecret: data.clientSecret,
                        appearance: {
                            theme: 'stripe'
                        }
                    });
                    
                    updatePaymentElement = updatePaymentElements.create('payment');
                    updatePaymentElement.mount('#update-payment-element');
                    
                    // Show action buttons after payment element is rendered
                    showModalActionButtons();

                    // Enable submit button now that content is loaded
                    submitBtn.disabled = false;
                    
                    // Handle form submission
                    submitBtn.onclick = async () => {
                        const errorDiv = document.getElementById('update-payment-error');
                        
                        const usePI = data.intentType === 'payment_intent';
                        let result;

                        if (usePI) {
                        result = await stripe.confirmPayment({
                            elements: updatePaymentElements,
                            confirmParams: { return_url: window.location.href },
                            redirect: 'if_required'
                        });
                        } else {
                        result = await stripe.confirmSetup({
                            elements: updatePaymentElements,
                            confirmParams: { return_url: window.location.href },
                            redirect: 'if_required'
                        });
                        }

                        if (result.error) {
                            errorDiv.textContent = result.error.message;
                            errorDiv.style.display = 'block';
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Update';
                        } else {
                            alert('Payment method updated successfully!');
                            closeUpdatePaymentModal();
                            loadSubscriptions();
                        }
                    };
                }
            } catch (error) {
                console.error('Error setting up payment update:', error);
                alert('Error setting up payment update. Please try again.');
                closeUpdatePaymentModal();
            } finally {
                modalLoader.style.display = 'none';
            }
        }

        function closeUpdatePaymentModal() {
            const modal = document.getElementById('update-payment-modal');
            modal.style.display = 'none';
            
            // Hide action buttons for next time
            const actionButtons = document.querySelector('.update-payment-actions');
            if (actionButtons) {
                actionButtons.style.display = 'none';
            }
            
            // Reset modal title
            const modalTitle = modal.querySelector('h3');
            if (modalTitle) modalTitle.textContent = 'Update Payment Method';
            
            // Clear any plan change details
            const detailsDiv = modal.querySelector('.plan-change-details');
            if (detailsDiv) {
                detailsDiv.remove();
            }
            
            // Reset error display
            const errorDiv = document.getElementById('update-payment-error');
            if (errorDiv) {
                errorDiv.style.display = 'none';
                errorDiv.textContent = '';
            }
            
            // Unmount payment element if it exists
            if (updatePaymentElement) {
                updatePaymentElement.unmount();
                updatePaymentElement = null;
                updatePaymentElements = null;
            }
            
            // IMPORTANT: Recreate the payment element container
            let paymentElementContainer = document.getElementById('update-payment-element');
            if (!paymentElementContainer) {
                const modalContent = modal.querySelector('.update-payment-content');
                const newPaymentElement = document.createElement('div');
                newPaymentElement.id = 'update-payment-element';
                
                // Insert before the error div
                const errorElement = document.getElementById('update-payment-error');
                if (errorElement && errorElement.parentNode) {
                    errorElement.parentNode.insertBefore(newPaymentElement, errorElement);
                } else if (modalContent) {
                    modalContent.appendChild(newPaymentElement);
                }
            }
            
            // Reset submit button
            const submitBtn = document.getElementById('update-payment-submit');
            if (submitBtn) {
                submitBtn.textContent = 'Update';
                submitBtn.disabled = true; // Disabled by default
                submitBtn.onclick = null; // Clear any existing handlers
            }
        }

        // Plan change functions
        async function initiatePlanChange(subscriptionId, newOptionId, isRenewal = false) {
            const modal = document.getElementById('update-payment-modal');
            const modalLoader = document.querySelector('#update-modal-loader');
            const modalTitle = modal.querySelector('h3');
            const submitBtn = document.getElementById('update-payment-submit');
            const errorDiv = document.getElementById('update-payment-error');
            const cancelBtn = document.querySelector('#cancel-update-payment');
            const actionButtons = document.querySelector('.update-payment-actions');
            
            // Update modal for plan change context
            modalTitle.textContent = isRenewal ? 'Renew Subscription' : 'Change Subscription Plan';
            modal.style.display = 'flex';
            modalLoader.style.display = 'block';
            submitBtn.disabled = true; // Keep disabled until loaded
            submitBtn.textContent = isRenewal ? 'Renew Plan' : 'Change Plan';
            
            // Hide action buttons until payment element loads
            if (actionButtons) {
                actionButtons.style.display = 'none';
            }

            // Add cancel handler (remove any existing first)
            cancelBtn.removeEventListener('pointerup', closeUpdatePaymentModal);
            cancelBtn.addEventListener('pointerup', closeUpdatePaymentModal);

            try {
                const response = await fetch(`${myApi.api_url}change-subscription-plan`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        subscriptionId: subscriptionId,
                        newOptionId: parseInt(newOptionId),
                        isRenewal: isRenewal,
                        auth_id: window.auth_id
                    })
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.message || 'Failed to initialize plan change');
                }
                
                if (data.success && stripe) {
                    // Show plan change details
                    const detailsDiv = document.createElement('div');
                    detailsDiv.className = 'plan-change-details'; // Use class for easy identification
                    detailsDiv.style.marginBottom = '15px';
                    detailsDiv.innerHTML = `
                        <p><strong>Current Plan:</strong> ${data.currentPlan} ($${data.currentPrice.toFixed(2)})</p>
                        <p><strong>New Plan:</strong> ${data.newPlan} ($${data.newPrice.toFixed(2)})</p>
                        ${data.immediateCharge > 0 ? 
                            `<p style="font-size: 1.1em; color: #333;"><strong>Due Today: $${data.immediateCharge.toFixed(2)}</strong></p>` :
                            data.immediateCharge < 0 ?
                            `<p style="font-size: 1.1em; color: #28a745;"><strong>Credit Applied: $${Math.abs(data.immediateCharge).toFixed(2)}</strong></p>` :
                            '<p style="font-size: 0.9em; color: #666;">No additional charge today</p>'
                        }
                    `;
                    
                    // Insert details before the payment element
                    const paymentElement = document.getElementById('update-payment-element');
                    if (paymentElement && paymentElement.parentNode) {
                        paymentElement.parentNode.insertBefore(detailsDiv, paymentElement);
                    }
                    
                    // Create elements for plan change
                    updatePaymentElements = stripe.elements({
                        clientSecret: data.clientSecret,
                        appearance: {
                            theme: 'stripe'
                        }
                    });
                    
                    updatePaymentElement = updatePaymentElements.create('payment');
                    updatePaymentElement.mount('#update-payment-element');
                    
                    // Show action buttons after payment element is rendered
                    showModalActionButtons();

                    // Enable submit button now that content is loaded
                    submitBtn.disabled = false;
                    
                    // Handle form submission
                    submitBtn.onclick = async () => {
                        submitBtn.disabled = true;
                        submitBtn.textContent = isRenewal ? 'Renewing...' : 'Changing Plan...';
                        errorDiv.style.display = 'none';

                        try {
                            // Confirm with the correct API based on server response
                            const confirmed = await confirmByIntentType(data, updatePaymentElements);

                            // Tell the server we’re done (support PI or SI)
                            await completePlanChange({
                                ...(confirmed.kind === 'payment_intent'
                                    ? { paymentIntentId: confirmed.id }
                                    : { setupIntentId: confirmed.id }),
                                invoiceId: data.invoiceId || null,
                                newOptionId: parseInt(newOptionId),
                                isRenewal: !!isRenewal,
                            });

                            // Success UI
                            closeUpdatePaymentModal();

                            const successMessage = document.createElement('div');
                            successMessage.style.cssText = `
                                position: fixed; top: 20px; right: 20px; background: #4CAF50; color: white;
                                padding: 15px 20px; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 10000;
                            `;
                            successMessage.textContent = isRenewal ? 'Subscription renewed successfully!' : 'Plan changed successfully!';
                            document.body.appendChild(successMessage);
                            setTimeout(() => successMessage.remove(), 3000);
                            setTimeout(() => loadSubscriptions(), 500);

                        } catch (err) {
                            errorDiv.textContent = err.message || 'Something went wrong.';
                            errorDiv.style.display = 'block';
                            submitBtn.disabled = false;
                            submitBtn.textContent = isRenewal ? 'Renew Plan' : 'Change Plan';
                        }
                    };
                } else {
                    throw new Error(data.message || 'Failed to initialize plan change');
                }
            } catch (error) {
                console.error('Error setting up plan change:', error);
                alert('Error: ' + error.message);
                closeUpdatePaymentModal();
            } finally {
                modalLoader.style.display = 'none';
            }
        }

        async function completePlanChange(payload) {
            // payload: { paymentIntentId? , setupIntentId?, newOptionId?, isRenewal? }
            const body = { ...payload, auth_id: window.auth_id };

            const response = await fetch(`${myApi.api_url}complete-plan-change`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });

            const data = await response.json();

            if (!response.ok || data.success !== true) {
                throw new Error(data.message || 'Failed to complete plan change');
            }

            return data;
        }

        // Handle subscription response
        function handleSubscriptionPayment(clientSecret, subscriptionId) {
            // Create payment form as before
            createPaymentForm(clientSecret);
            
            // Store subscription ID for later use
            const orderData = sessionStorage.getItem('placedOrder');
            if (orderData) {
                const orderInfo = JSON.parse(orderData);
                orderInfo.subscriptionId = subscriptionId;
                sessionStorage.setItem('placedOrder', JSON.stringify(orderInfo));
            }
        }
        
        // Add a button to show subscriptions section
        function addSubscriptionsTab() {
            const profileContainer = document.getElementById('profile-container');
            if (profileContainer) {
                const subscriptionsBtn = document.createElement('button');
                subscriptionsBtn.type = 'button';
                subscriptionsBtn.id = "manage-subs-btn";
                subscriptionsBtn.textContent = 'Manage Subscriptions';
                subscriptionsBtn.style.marginTop = '20px';
                subscriptionsBtn.onclick = () => {
                    document.getElementById('subscriptions-management').style.display = 'block';
                    loadSubscriptions();
                };
                profileContainer.appendChild(subscriptionsBtn);
            }
        }

        // Initialize on page load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', addSubscriptionsTab);
        } else {
            addSubscriptionsTab();
            if (isPWA) {
                const manageSubsLoader = document.querySelector('#subs-loader');
                manageSubsLoader.src = `${window.template_path}/images/loading.gif`;
            }
        }

        // Override the existing updateOrderButton function
        window.updateOrderButton = updateOrderButton;

        if (isOrderPaid()) {
            disableFormInteraction();
            showPaymentSuccess();
        }

    };

    // Reset function to allow re-initialization if needed
    window.resetDashboard = function() {
        isInitialized = false;
    };
    
    // Still run on DOMContentLoaded in case the HTML is already there
    document.addEventListener('DOMContentLoaded', function() {
        // Only initialize if the dashboard container exists
        if (document.getElementById('features-container')) {
            window.initializeDashboard();
        }
    });
})();
