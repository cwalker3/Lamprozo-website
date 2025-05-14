document.addEventListener('DOMContentLoaded', function() {
    console.log(dashboardData);
    // Global state: keys are feature type indexes; each value is an array of instance objects.
    // Each instance object: { optionIndex: number, addons: [number, ...], quantity?: number }
    let selections = {};

    // Load any saved state from sessionStorage
    if (sessionStorage.getItem('priceCalcSelections')) {
        selections = JSON.parse(sessionStorage.getItem('priceCalcSelections'));
    }

    const featuresContainer = document.getElementById('features-container');
    const invoiceDetails = document.getElementById('invoice-details');
    const invoiceTotal = document.getElementById('invoice-total');
    const themePath = dashboardData.theme_path;

    // Clear container to prevent duplication.
    featuresContainer.innerHTML = '';

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
        
        // Add addon prices
        if (instance.addons && Array.isArray(instance.addons)) {
            instance.addons.forEach(addonIndex => {
                const addon = option.addons[addonIndex];
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
        
        // Calculate total price before discount
        let totalPrice = price * qty;
        const originalPrice = totalPrice;
        
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

    // Calculate lower bound for invoice if there's a range
    function calculateInstancePriceLower(feature, instance) {
        const option = feature.options[instance.optionIndex];
        if (!option) return 0;

        let price = parseSafe(option.priceFloor, parseSafe(option.staticPrice, 0));
        if (instance.addons && Array.isArray(instance.addons)) {
            instance.addons.forEach(aIndex => {
                const addon = option.addons[aIndex];
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
            instance.addons.forEach(aIndex => {
                const addon = option.addons[aIndex];
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
        let estimateMode = false;

        let tableHTML = `
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th>Item</th>
                        <th>Interval</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
        `;

        dashboardData.features.forEach((feature, fIndex) => {
            const instances = selections[fIndex] || [];
            instances.forEach(instance => {
                if (instance.optionIndex === undefined) return;
                const option = feature.options[instance.optionIndex];
                if (!option) return;

                // If not recurring, show (Qty: X)
                let qtyDisplay = '';
                if (!feature.recurring) {
                    const qty = parseInt(instance.quantity) || 1;
                    qtyDisplay = ` (Qty: ${qty})`;
                }

                let intervalLabel = '';
                if (feature.recurring && option.interval) {
                    intervalLabel = option.interval;
                }
                
                let selectedOptionText = '';
                let priceOptionsArray = [];
                
                if (option.priceOptions) {
                    try {
                        if (typeof option.priceOptions === 'string') {
                            priceOptionsArray = JSON.parse(option.priceOptions).types || [];
                        } else if (option.priceOptions.types) {
                            priceOptionsArray = option.priceOptions.types;
                        }
                        
                        // If we have a valid selection, add the name to the display
                        if (priceOptionsArray.length > 0 && 
                            instance.priceOptionIndex !== undefined &&
                            priceOptionsArray[instance.priceOptionIndex]) {
                            selectedOptionText = ` (${priceOptionsArray[instance.priceOptionIndex].label})`;
                        }
                    } catch(e) {
                        console.error("Error parsing price options:", e);
                    }
                }

                // Include existing option-level user fields
                let userFieldsText = '';
                if (instance.userFields) {
                    for (const [fieldName, selectedIndex] of Object.entries(instance.userFields)) {
                        // Try to get the user field data
                        const userField = option[`${fieldName}_user`];
                        if (userField) {
                            try {
                                let fieldData;
                                if (typeof userField === 'string') {
                                    fieldData = JSON.parse(userField);
                                } else {
                                    fieldData = userField;
                                }
                                
                                if (fieldData && fieldData.types && Array.isArray(fieldData.types) && 
                                    fieldData.types[selectedIndex]) {
                                    userFieldsText += ` ${fieldName}: ${fieldData.types[selectedIndex]},`;
                                }
                            } catch(e) {
                                console.error(`Error processing user field ${fieldName}:`, e);
                            }
                        }
                    }
                    
                    // Remove trailing comma if exists
                    if (userFieldsText.endsWith(',')) {
                        userFieldsText = userFieldsText.slice(0, -1);
                    }
                    
                    // If we have user fields to display, wrap in parentheses
                    if (userFieldsText) {
                        userFieldsText = ` (${userFieldsText})`;
                    }
                }

                // Check if we have a range
                if (instanceHasRange(feature, instance)) {
                    estimateMode = true;
                    const lower = calculateInstancePriceLower(feature, instance);
                    const upper = calculateInstancePriceUpper(feature, instance);
                    totalLower += lower;
                    totalUpper += upper;
                    tableHTML += `
                        <tr>
                            <td>${feature.featureName}</td>
                            <td>${option.optionName}${selectedOptionText}${userFieldsText}${qtyDisplay}</td>
                            <td>${intervalLabel}</td>
                            <td>$${lower.toFixed(2)} - $${upper.toFixed(2)}</td>
                        </tr>
                    `;
                    // If the instance has addons, show them as sub-rows
                    if (instance.addons && instance.addons.length > 0) {
                        instance.addons.forEach(aIndex => {
                            const addon = option.addons[aIndex];
                            if (addon) {
                                const symbol = isMultiply(addon) ? 'x' : '+';
                                const floorVal = parseSafe(addon.floorPriceMod, parseSafe(addon.staticPriceMod, 0));
                                const ceilVal  = parseSafe(addon.ceilingPriceMod, parseSafe(addon.staticPriceMod, 0));
                                if (floorVal !== 0 || ceilVal !== 0) {
                                    tableHTML += `
                                        <tr class="addon-row">
                                            <td></td>
                                            <td>${addon.addonName}</td>
                                            <td></td>
                                            <td>${symbol} $${floorVal.toFixed(2)} - $${ceilVal.toFixed(2)}</td>
                                        </tr>
                                    `;
                                } else {
                                    const addonStatic = parseSafe(addon.staticPriceMod, 0);
                                    tableHTML += `
                                        <tr class="addon-row">
                                            <td></td>
                                            <td>${addon.addonName}</td>
                                            <td></td>
                                            <td>${symbol} $${addonStatic.toFixed(2)}</td>
                                        </tr>
                                    `;
                                }
                            }
                        });
                    }
                } else {
                    // No range => single price
                    const price = calculateInstancePrice(feature, instance);
                    totalLower += price;
                    totalUpper += price;
                    // Check if a discount is applied
                    if (instance.appliedDiscount) {
                            // Get the original price before discount
                            const originalPrice = instance.appliedDiscount.originalPrice;
                            
                            tableHTML += `
                                <tr>
                                    <td>${feature.featureName}</td>
                                    <td>${option.optionName}${selectedOptionText}${userFieldsText}${qtyDisplay}</td>
                                    <td>${intervalLabel}</td>
                                    <td>
                                        <div>
                                            <span style="text-decoration: line-through; color: #999;">$${originalPrice.toFixed(2)}</span>
                                            <span style="color: #d83838; font-weight: bold;"> $${price.toFixed(2)}</span>
                                            <div style="font-size: 11px; color: #d83838;">
                                                ${instance.appliedDiscount.percentage}% discount applied
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        } else {
                        // No discount, show regular price
                        tableHTML += `
                            <tr>
                                <td>${feature.featureName}</td>
                                <td>${option.optionName}${selectedOptionText}${userFieldsText}${qtyDisplay}</td>
                                <td>${intervalLabel}</td>
                                <td>$${price.toFixed(2)}</td>
                            </tr>
                        `;
                    }
                    if (instance.addons && instance.addons.length > 0) {
                        instance.addons.forEach(aIndex => {
                            const addon = option.addons[aIndex];
                            if (addon) {
                                const symbol = isMultiply(addon) ? 'x' : '+';
                                const addonStatic = parseSafe(addon.staticPriceMod, 0);
                                tableHTML += `
                                    <tr class="addon-row">
                                        <td></td>
                                        <td>${addon.addonName}</td>
                                        <td></td>
                                        <td>${symbol} $${addonStatic.toFixed(2)}</td>
                                    </tr>
                                `;
                            }
                        });
                    }
                }
            });
        });

        tableHTML += `
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right; font-weight: bold;">Total:</td>
                        <td id="invoice-total">`;
        if (estimateMode) {
            tableHTML += `$${totalLower.toFixed(2)} - $${totalUpper.toFixed(2)}`;
        } else {
            // Simple total, no need for discount display here
            tableHTML += `$${totalLower.toFixed(2)}`;
        }
        tableHTML += `</td>
                    </tr>
                </tfoot>
            </table>
        `;
        invoiceDetails.innerHTML = tableHTML;

        // Handle toggling Pay Now vs. Request Estimate
        const payNowBtn = document.getElementById('pay-now');
        if (payNowBtn) {
            if (estimateMode) {
                payNowBtn.textContent = 'Request Estimate';
                payNowBtn.onclick = function() {
                    alert('Request Estimate functionality coming soon!');
                };
            } else {
                payNowBtn.textContent = 'Pay Now';
                payNowBtn.onclick = function() {
                    alert('Pay Now functionality coming soon!');
                };
            }
        }
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
        headerTitle.textContent = (instance.optionIndex === undefined)
            ? `New ${feature.featureName}`
            : feature.featureName;
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
                maxAddonsMessage.style.fontSize = '12px';
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

        if (!selectedOption.priceOptions) detailsHTML += `<p><strong>Price:</strong> ${optionPriceText}</p>`;
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
        
        // If we have price options, create a dropdown
        if (priceOptionsArray && priceOptionsArray.length > 0) {
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

            // Check for threshold discounts
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
                        discountDiv.style.marginTop = '12px';
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
                        discountList.style.fontSize = '12px';
                        
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

            selectedOption.addons.forEach((addon, aIndex) => {
                // Change from label to div as container
                const container = document.createElement('div');
                container.classList.add('addon-item');

                // Create checkbox with explicit dimensions
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.value = aIndex;
                // Fix: Use instance.optionIndex instead of oIdx
                checkbox.id = `addon-checkbox-${fIndex}-${instance.optionIndex}-${aIndex}`;
                checkbox.classList.add('addon-checkbox');
                if (instance.addons && instance.addons.indexOf(aIndex) !== -1) {
                    checkbox.checked = true;
                }
                
                // Restore checkbox functionality
                checkbox.addEventListener('change', function() {
                    if (!instance.addons) {
                        instance.addons = [];
                    }
                    if (this.checked) {
                        instance.addons.push(aIndex);
                    } else {
                        const idx = instance.addons.indexOf(aIndex);
                        if (idx !== -1) {
                            instance.addons.splice(idx, 1);
                        }
                    }

                    // After changing selection, enforce max addons
                    enforceMaxAddons(fIndex, instIndex, addonsDiv);

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
                addonsDiv.appendChild(container);
            });
            optionDetailsDiv.appendChild(addonsDiv);

            // Then add this line after all addons are added:
            enforceMaxAddons(fIndex, instIndex, addonsDiv);

        }

        saveSelections();
        updateInvoice();
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
    if (updateProfileBtn) {
        updateProfileBtn.addEventListener('click', () => {
            const firstName = document.getElementById('profile-first-name').value.trim();
            const lastName = document.getElementById('profile-last-name').value.trim();
            const email = document.getElementById('profile-email').value.trim();
            const profileMessage = document.getElementById('profile-message');
            profileMessage.innerHTML = '';
            updateProfileBtn.innerHTML = `<img class="loader" src="${themePath}/images/loading.gif" alt="Loading">`;
            let formData = new FormData();
            formData.append('first_name', firstName);
            formData.append('last_name', lastName);
            formData.append('email', email);
            fetch(`${myApi.api_url}update-profile`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': myApi.nonce },
                body: formData,
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(data => {
                        throw new Error(data.message || 'An error occurred.');
                    });
                }
                return response.json();
            })
            .then(data => {
                profileMessage.textContent = data.message || 'Profile updated successfully.';
                updateProfileBtn.textContent = 'Update Profile';
            })
            .catch(error => {
                profileMessage.textContent = error.message || 'An error occurred.';
                updateProfileBtn.textContent = 'Update Profile';
            });
        });
    }
    // Reset password handler
    const resetPasswordBtn = document.getElementById('reset-password-btn');
    if (resetPasswordBtn) {
        resetPasswordBtn.addEventListener('click', () => {
            const profileMessage = document.getElementById('profile-message');
            profileMessage.innerHTML = '';
            resetPasswordBtn.innerHTML = `<img class="loader" src="${themePath}/images/loading.gif" alt="Loading">`;
            fetch(`${myApi.api_url}reset-password`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': myApi.nonce },
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(data => {
                        throw new Error(data.message || 'An error occurred.');
                    });
                }
                return response.json();
            })
            .then(data => {
                profileMessage.textContent = data.message || 'Password reset email sent.';
                resetPasswordBtn.textContent = 'Send Password Reset';
            })
            .catch(error => {
                profileMessage.textContent = error.message || 'An error occurred.';
                resetPasswordBtn.textContent = 'Send Password Reset';
            });
        });
    }

    // Finally, build the invoice
    updateInvoice();

    // Stub pay-now button
    const payNowBtn = document.getElementById('pay-now');
    if (payNowBtn) {
        payNowBtn.addEventListener('click', function() {
            alert('Pay Now functionality coming soon!');
        });
    }
});
