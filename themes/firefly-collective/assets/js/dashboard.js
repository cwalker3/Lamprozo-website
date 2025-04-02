document.addEventListener('DOMContentLoaded', function() {
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

        let price = parseSafe(option.staticPrice, 0);
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
        if (!feature.recurring) {
            const qty = parseInt(instance.quantity) || 1;
            price *= qty;
        }
        return price;
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
                            <td>${option.optionName}${qtyDisplay}</td>
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
                    tableHTML += `
                        <tr>
                            <td>${feature.featureName}</td>
                            <td>${option.optionName}${qtyDisplay}</td>
                            <td>${intervalLabel}</td>
                            <td>$${price.toFixed(2)}</td>
                        </tr>
                    `;
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
            tableHTML += `$${totalLower.toFixed(2)}`;
        }
        tableHTML += `</td>
                    </tr>
                </tfoot>
            </table>
        `;
        invoiceDetails.innerHTML = tableHTML;

        // >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>
        // ADD THIS SNIPPET to handle toggling Pay Now vs. Request Estimate
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
        // <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<
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
            if (this.value === '') {
                instance.optionIndex = undefined;
            } else {
                instance.optionIndex = parseInt(this.value);
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

        const detailsHTML = `
            <p><strong>Description:</strong> ${descText}</p>
            <p><strong>Price:</strong> ${optionPriceText}</p>
        `;
        optionDetailsDiv.innerHTML = detailsHTML;

        // If non-recurring, let the user set quantity
        if (!feature.recurring) {
            let quantity = parseInt(instance.quantity) || 1;
            if (quantity < 1) quantity = 1;
            instance.quantity = quantity;

            const qtyDiv = document.createElement('div');
            qtyDiv.classList.add('quantity-input');
            qtyDiv.innerHTML = `
                <label><strong>Quantity:</strong>
                    <input type="number" min="1" value="${quantity}" style="width:60px; margin-left:5px;">
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
                const label = document.createElement('label');
                label.classList.add('addon-item');

                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.value = aIndex;
                if (instance.addons && instance.addons.indexOf(aIndex) !== -1) {
                    checkbox.checked = true;
                }
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
                    saveSelections();
                    updateInvoice();
                });
                label.appendChild(checkbox);

                // Show the raw addon data. If floor/ceiling is non-zero, show a range.
                const floorVal = parseSafe(addon.floorPriceMod);
                const ceilVal  = parseSafe(addon.ceilingPriceMod);
                const symbol   = isMultiply(addon) ? 'x' : '+';

                // If there's a range
                if (floorVal !== 0 || ceilVal !== 0) {
                    label.appendChild(
                        document.createTextNode(
                            ` ${addon.addonName} (${symbol}$${floorVal.toFixed(2)} - $${ceilVal.toFixed(2)})`
                        )
                    );
                } else {
                    // Otherwise show staticPriceMod
                    const addonStatic = parseSafe(addon.staticPriceMod, 0);
                    label.appendChild(
                        document.createTextNode(
                            ` ${addon.addonName} (${symbol}$${addonStatic.toFixed(2)})`
                        )
                    );
                }

                addonsDiv.appendChild(label);
            });
            optionDetailsDiv.appendChild(addonsDiv);
        }

        saveSelections();
        updateInvoice();
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
