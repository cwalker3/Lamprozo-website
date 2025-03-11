/*****************************************************
 * Mutual Exclusivity – static vs. floor/ceiling
 *****************************************************/
function handleStaticPriceChange(floorInput, ceilingInput, staticInput) {
  const floorVal = floorInput.value.trim() === "" ? 0 : parseFloat(floorInput.value) || 0;
  const ceilVal  = ceilingInput.value.trim() === "" ? 0 : parseFloat(ceilingInput.value) || 0;
  const staticVal = staticInput.value.trim() === "" ? 0 : parseFloat(staticInput.value) || 0;

  if (staticInput.value.trim() !== "" && staticVal !== 0) {
    floorInput.disabled = true;
    ceilingInput.disabled = true;
    staticInput.disabled = false;
  } else if (
    (floorInput.value.trim() !== "" && floorVal !== 0) ||
    (ceilingInput.value.trim() !== "" && ceilVal !== 0)
  ) {
    staticInput.disabled = true;
    floorInput.disabled = false;
    ceilingInput.disabled = false;
  } else {
    floorInput.disabled = false;
    ceilingInput.disabled = false;
    staticInput.disabled = false;
  }
  saveData();
}

/*****************************************************
 * Save & Load Data in sessionStorage
 *****************************************************/
function saveData() {
  sessionStorage.setItem('pricingData', JSON.stringify(window.pricingData));
}

function loadData() {
  let stored = sessionStorage.getItem('pricingData');
  if (stored) {
    return JSON.parse(stored);
  }
  return null;
}

/*****************************************************
 * Decide display name for a key/dataName pair
 *****************************************************/
function getDisplayName(key, dataName, prefix) {
  if (dataName && dataName.trim() !== '') {
    return dataName;
  } else if (!key.startsWith(prefix)) {
    return key;
  } else {
    return '';
  }
}

/*****************************************************
 * Create a field group (label + input)
 *****************************************************/
function createFieldGroup(labelText, inputType, value, placeholder = '') {
  const group = document.createElement('div');
  group.className = 'field-group';

  const label = document.createElement('label');
  label.textContent = labelText + ':';

  // "text" => normal text or textarea if "description"
  if (inputType === 'text') {
    const input = createInputOrTextarea(labelText, value, placeholder);
    group.appendChild(label);
    group.appendChild(input);
    return group;
  }
  // "date"
  else if (inputType === 'date') {
    const input = document.createElement('input');
    input.type = 'date';
    input.value = value || '';
    // Save on change + blur
    input.addEventListener('change', saveData);
    input.addEventListener('blur', saveData);
    group.appendChild(label);
    group.appendChild(input);
    return group;
  }
  // "number"
  else if (inputType === 'number') {
    const input = document.createElement('input');
    input.type = 'number';
    input.value = (value === 0 ? "" : value);
    if (placeholder) {
      input.placeholder = placeholder;
    }
    // Save on input
    input.addEventListener('input', saveData);
    group.appendChild(label);
    group.appendChild(input);
    return group;
  }
  // fallback for other input types
  else {
    const input = document.createElement('input');
    input.type = inputType;
    input.value = value;
    if (placeholder) {
      input.placeholder = placeholder;
    }
    // Save on blur
    input.addEventListener('blur', saveData);
    group.appendChild(label);
    group.appendChild(input);
    return group;
  }
}

/*****************************************************
 * Minimal helper for description -> textarea
 *****************************************************/
function createInputOrTextarea(labelText, value, placeholder) {
  // If label includes "description", use textarea
  if (labelText.toLowerCase().includes('description')) {
    const txt = document.createElement('textarea');
    txt.value = value || '';
    txt.placeholder = placeholder;
    txt.rows = 3;
    // Save on input
    txt.addEventListener('input', function() {
      saveData();
    });
    return txt;
  } else {
    // Normal text input
    const inp = document.createElement('input');
    inp.type = 'text';
    inp.value = value;
    if (placeholder) inp.placeholder = placeholder;
    // Save on input
    inp.addEventListener('input', function() {
      saveData();
    });
    return inp;
  }
}

/*****************************************************
 * Create a dropdown field group (label + select)
 *****************************************************/
function createDropdownFieldGroup(labelText, optionsArray, selectedValue) {
  const group = document.createElement('div');
  group.className = 'field-group';

  const label = document.createElement('label');
  label.textContent = labelText + ':';

  const select = document.createElement('select');
  optionsArray.forEach(function(option) {
    const opt = document.createElement('option');
    opt.value = option;
    opt.textContent = option;
    if (option === selectedValue) {
      opt.selected = true;
    }
    select.appendChild(opt);
  });
  // Save on change
  select.addEventListener('change', saveData);

  group.appendChild(label);
  group.appendChild(select);
  return group;
}

/*****************************************************
 * Force feature to fully expand (open)
 *****************************************************/
function expandFeature(featureDiv) {
  const contentDiv = featureDiv.querySelector('.feature-content');
  const toggleIndicator = featureDiv.querySelector('.toggle-indicator');
  if (contentDiv && toggleIndicator) {
    contentDiv.classList.add('open');
    contentDiv.style.maxHeight = contentDiv.scrollHeight + 'px';
    toggleIndicator.textContent = '-';
  }
}

/*****************************************************
 * Addon Element
 *****************************************************/
function createAddonElement(featureKey, optionKey, addonData, availableAddonMetrics) {
  const addonDiv = document.createElement('div');
  addonDiv.className = 'addon';

  // Addon Name
  const addonNameGroup = createFieldGroup('Addon Name', 'text', addonData.addonName || '', 'Enter addon name...');
  addonNameGroup.querySelector('input, textarea').addEventListener('input', e => {
    addonData.addonName = e.target.value;
    saveData();
  });
  addonDiv.appendChild(addonNameGroup);

  // Addon Metric
  const addonMetricValue = addonData.addOnMetric || (availableAddonMetrics[0] || '');
  let addonMetricField;
  if (availableAddonMetrics.length > 1) {
    addonMetricField = createDropdownFieldGroup('Addon Metric', availableAddonMetrics, addonMetricValue);
    addonMetricField.querySelector('select').addEventListener('change', e => {
      addonData.addOnMetric = e.target.value;
      saveData();
    });
  } else {
    addonMetricField = createFieldGroup('Addon Metric', 'text', addonMetricValue, 'Enter addon metric...');
    addonMetricField.querySelector('input').addEventListener('input', e => {
      addonData.addOnMetric = e.target.value;
      saveData();
    });
  }
  addonDiv.appendChild(addonMetricField);

  // Floor
  const floorGroup = createFieldGroup('Addon Floor Modifier', 'number', addonData.floorPriceMod || 0, 'Enter floor mod...');
  const floorInput = floorGroup.querySelector('input');
  floorInput.addEventListener('input', e => {
    addonData.floorPriceMod = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, staticPriceModInput);
  });
  addonDiv.appendChild(floorGroup);

  // Ceiling
  const ceilingGroup = createFieldGroup('Addon Ceiling Modifier', 'number', addonData.ceilingPriceMod || 0, 'Enter ceiling mod...');
  const ceilingInput = ceilingGroup.querySelector('input');
  ceilingInput.addEventListener('input', e => {
    addonData.ceilingPriceMod = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, staticPriceModInput);
  });
  addonDiv.appendChild(ceilingGroup);

  // Static Price Mod
  const spVal = addonData.staticPriceMod || '';
  const spGroup = createFieldGroup('Static Price Mod', 'number', spVal, 'Static price');
  const staticPriceModInput = spGroup.querySelector('input');
  staticPriceModInput.addEventListener('input', e => {
    addonData.staticPriceMod = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, staticPriceModInput);
  });
  addonDiv.appendChild(spGroup);

  // Ensure mutual exclusivity
  handleStaticPriceChange(floorInput, ceilingInput, staticPriceModInput);

  // Delete Addon
  const addonButtonRow = document.createElement('div');
  addonButtonRow.className = 'button-row';
  const deleteAddonButton = document.createElement('button');
  deleteAddonButton.textContent = 'Delete Addon';
  deleteAddonButton.className = 'delete-button';
  deleteAddonButton.addEventListener('click', () => {
    addonDiv.classList.add('fade-out');
    addonDiv.addEventListener('animationend', () => {
      const optionData = window.pricingData.features[featureKey].options[optionKey];
      const idx = optionData.addons.indexOf(addonData);
      if (idx > -1) optionData.addons.splice(idx, 1);
      saveData();
      addonDiv.remove();
    });
  });
  addonButtonRow.appendChild(deleteAddonButton);
  addonDiv.appendChild(addonButtonRow);

  return addonDiv;
}

/*****************************************************
 * Option Element
 *****************************************************/
function createOptionElement(featureKey, optionKey, optionData, availableOptionMetrics, availableAddonMetrics) {
  const optionDiv = document.createElement('div');
  optionDiv.className = 'option';

  // Option Name
  const optionNameValue = getDisplayName(optionKey, optionData.name, 'option_');
  const titleGroup = createFieldGroup('Option Name', 'text', optionNameValue, 'Enter option name...');
  const titleInput = titleGroup.querySelector('input, textarea');
  titleInput.addEventListener('input', e => {
    optionData.name = e.target.value;
    saveData();
  });
  optionDiv.appendChild(titleGroup);

  // If recurring is null => skip "Recurring?" row
  if (optionData.recurring !== null) {
    // Recurring? checkbox
    const recurringGroup = document.createElement('div');
    recurringGroup.className = 'field-group';
    const recurringLabel = document.createElement('label');
    recurringLabel.textContent = 'Recurring?';
    const recurringCheckbox = document.createElement('input');
    recurringCheckbox.type = 'checkbox';
    recurringCheckbox.checked = !!optionData.recurring;
    recurringCheckbox.addEventListener('change', e => {
      optionData.recurring = e.target.checked;
      recurringContent.style.display = optionData.recurring ? 'block' : 'none';
      saveData();
      // Recalc
      const featureDiv = optionDiv.closest('.feature');
      if (featureDiv) {
        const contentDiv = featureDiv.querySelector('.feature-content');
        if (contentDiv.classList.contains('open')) {
          contentDiv.style.maxHeight = contentDiv.scrollHeight + 'px';
        }
      }
    });
    recurringGroup.appendChild(recurringLabel);
    recurringGroup.appendChild(recurringCheckbox);
    optionDiv.appendChild(recurringGroup);

    // Recurring content
    const recurringContent = document.createElement('div');
    recurringContent.style.display = optionData.recurring ? 'block' : 'none';
    optionDiv.appendChild(recurringContent);

    // Start Date
    const startDateGroup = createFieldGroup('Start Date', 'date', optionData.startDate || '', '');
    const startDateInput = startDateGroup.querySelector('input');
    startDateInput.addEventListener('change', e => {
      optionData.startDate = e.target.value;
      saveData();
    });
    recurringContent.appendChild(startDateGroup);

    // Interval
    const intervalVals = ['every day','every week','bi weekly','monthly','yearly','specific day'];
    const intervalGroup = createDropdownFieldGroup('Interval', intervalVals, optionData.interval || '');
    const intervalSelect = intervalGroup.querySelector('select');
    intervalSelect.addEventListener('change', e => {
      optionData.interval = e.target.value;
      specificDayGroup.style.display = (optionData.interval === 'specific day') ? 'flex' : 'none';
      saveData();
      const featureDiv = optionDiv.closest('.feature');
      if (featureDiv) {
        const contentDiv = featureDiv.querySelector('.feature-content');
        if (contentDiv.classList.contains('open')) {
          contentDiv.style.maxHeight = contentDiv.scrollHeight + 'px';
        }
      }
    });
    recurringContent.appendChild(intervalGroup);

    // "Specific Day"
    const specificDayGroup = document.createElement('div');
    specificDayGroup.className = 'field-group';
    {
      const label = document.createElement('label');
      label.textContent = 'Specific Day:';
      const input = document.createElement('input');
      input.type = 'date';
      input.value = (optionData.interval === 'specific day') ? (optionData.startDate || '') : '';
      input.addEventListener('change', e => {
        optionData.startDate = e.target.value;
        saveData();
      });
      specificDayGroup.appendChild(label);
      specificDayGroup.appendChild(input);
    }
    specificDayGroup.style.display = (optionData.interval === 'specific day') ? 'flex' : 'none';
    recurringContent.appendChild(specificDayGroup);
  }

  // Price Floor
  const floorGroup = createFieldGroup('Price Floor', 'number', optionData.priceFloor || 0, 'Enter floor price...');
  const floorInput = floorGroup.querySelector('input');
  floorInput.addEventListener('input', e => {
    optionData.priceFloor = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, spInput);
  });
  optionDiv.appendChild(floorGroup);

  // Price Ceiling
  const ceilingGroup = createFieldGroup('Price Ceiling', 'number', optionData.priceCeiling || 0, 'Enter ceiling price...');
  const ceilingInput = ceilingGroup.querySelector('input');
  ceilingInput.addEventListener('input', e => {
    optionData.priceCeiling = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, spInput);
  });
  optionDiv.appendChild(ceilingGroup);

  // Static Price
  const spGroup = createFieldGroup('Static Price', 'number', optionData.staticPrice || '', 'Static price');
  const spInput = spGroup.querySelector('input');
  spInput.addEventListener('input', e => {
    optionData.staticPrice = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, spInput);
  });
  optionDiv.appendChild(spGroup);

  // Final mutual exclusivity
  handleStaticPriceChange(floorInput, ceilingInput, spInput);

  // Option Metric
  const metricValue = optionData.optionMetric || (availableOptionMetrics[0] || '');
  let metricField;
  if (availableOptionMetrics.length > 1) {
    metricField = createDropdownFieldGroup('Option Metric', availableOptionMetrics, metricValue);
    metricField.querySelector('select').addEventListener('change', e => {
      optionData.optionMetric = e.target.value;
      saveData();
    });
  } else {
    metricField = createFieldGroup('Option Metric', 'text', metricValue, 'Enter metric...');
    metricField.querySelector('input').addEventListener('input', e => {
      optionData.optionMetric = e.target.value;
      saveData();
    });
  }
  optionDiv.appendChild(metricField);

  // Existing addons
  optionData.addons.forEach(function(addon) {
    const addonElem = createAddonElement(featureKey, optionKey, addon, availableAddonMetrics);
    optionDiv.appendChild(addonElem);
  });

  // Buttons row
  const optionButtonRow = document.createElement('div');
  optionButtonRow.className = 'button-row';

  // Add Addon
  const addAddonButton = document.createElement('button');
  addAddonButton.textContent = 'Add Addon';
  addAddonButton.className = 'add-button';
  addAddonButton.addEventListener('click', function() {
    const newAddon = {
      addonName: '',
      addOnMetric: availableAddonMetrics.length > 0 ? availableAddonMetrics[0] : '',
      floorPriceMod: 0,
      ceilingPriceMod: 0,
      priceMultiplierType: 'plus',
      staticPriceMod: null
    };
    optionData.addons.push(newAddon);
    const newAddonElem = createAddonElement(featureKey, optionKey, newAddon, availableAddonMetrics);
    newAddonElem.classList.add('fade-in');
    optionDiv.insertBefore(newAddonElem, optionButtonRow);

    // Expand
    const featureDiv = optionDiv.closest('.feature');
    if (featureDiv) expandFeature(featureDiv);

    newAddonElem.scrollIntoView({ behavior: 'smooth' });
    saveData();
  });
  optionButtonRow.appendChild(addAddonButton);

  // Delete Option
  const deleteOptionButton = document.createElement('button');
  deleteOptionButton.textContent = 'Delete Option';
  deleteOptionButton.className = 'delete-button';
  deleteOptionButton.addEventListener('click', function() {
    optionDiv.classList.add('fade-out');
    optionDiv.addEventListener('animationend', function() {
      delete window.pricingData.features[featureKey].options[optionKey];
      saveData();
      optionDiv.remove();
    });
  });
  optionButtonRow.appendChild(deleteOptionButton);

  optionDiv.appendChild(optionButtonRow);
  return optionDiv;
}

/*****************************************************
 * 7) Feature Element
 *****************************************************/
function createFeatureElement(featureKey, featureData, availableOptionMetrics, availableAddonMetrics) {
  const featureDiv = document.createElement('div');
  featureDiv.className = 'feature';

  const header = document.createElement('div');
  header.className = 'feature-header';

  const leftDiv = document.createElement('div');
  leftDiv.className = 'feature-header-left';

  const rightDiv = document.createElement('div');
  rightDiv.className = 'feature-header-right';

  const toggleIndicator = document.createElement('span');
  toggleIndicator.className = 'toggle-indicator';
  toggleIndicator.textContent = '+';

  const deleteFeatureBtn = document.createElement('button');
  deleteFeatureBtn.textContent = 'Delete Feature';
  deleteFeatureBtn.className = 'delete-button';

  rightDiv.appendChild(toggleIndicator);
  rightDiv.appendChild(deleteFeatureBtn);
  header.appendChild(leftDiv);
  header.appendChild(rightDiv);
  featureDiv.appendChild(header);

  const contentDiv = document.createElement('div');
  contentDiv.className = 'feature-content';

  const initialNameVal = getDisplayName(featureKey, featureData.name, 'Feature_');
  const displayName = initialNameVal !== '' ? initialNameVal : 'New Feature';

  const featureTitleSpan = document.createElement('span');
  featureTitleSpan.textContent = displayName;
  leftDiv.appendChild(featureTitleSpan);

  // Feature Name
  const featureNameGroup = createFieldGroup('Feature Name', 'text', initialNameVal, 'Feature name...');
  const featureNameInput = featureNameGroup.querySelector('input, textarea');
  featureNameInput.addEventListener('input', function(e) {
    featureData.name = e.target.value;
    featureTitleSpan.textContent = e.target.value.trim() !== '' ? e.target.value : 'New Feature';
    saveData();
  });
  contentDiv.appendChild(featureNameGroup);

  // Description
  const descVal = featureData.description || '';
  const descGroup = createFieldGroup('Feature Description', 'text', descVal, 'Description...');
  descGroup.querySelector('input,textarea').addEventListener('input', function(e) {
    featureData.description = e.target.value;
    saveData();
  });
  contentDiv.appendChild(descGroup);

  // If no options + recurring=undefined => show checkbox
  if (Object.keys(featureData.options || {}).length === 0 && typeof featureData.recurring === 'undefined') {
    const recurringRow = document.createElement('div');
    recurringRow.className = 'field-group recurring-row';
    const recurringLabel = document.createElement('label');
    recurringLabel.textContent = 'Recurring charge?';
    const recurringCheckbox = document.createElement('input');
    recurringCheckbox.type = 'checkbox';
    recurringCheckbox.checked = false;
    recurringCheckbox.addEventListener('change', e => {
      featureData.recurring = e.target.checked ? true : false;
      saveData();
    });
    recurringRow.appendChild(recurringLabel);
    recurringRow.appendChild(recurringCheckbox);
    contentDiv.appendChild(recurringRow);
  }

  // Existing options
  for (let optionKey in featureData.options) {
    const optionData = featureData.options[optionKey];
    const optionElem = createOptionElement(featureKey, optionKey, optionData, availableOptionMetrics, availableAddonMetrics);
    contentDiv.appendChild(optionElem);
  }

  // Add Option
  const addOptionRow = document.createElement('div');
  addOptionRow.className = 'button-row';

  // We ALWAYS show the "Add Option" button now:
  const addOptionButton = document.createElement('button');
  addOptionButton.textContent = 'Add Option';
  addOptionButton.className = 'add-button';
  // <--- no logic about hiding it if feature name is empty
  // It's always visible from the start

  addOptionButton.addEventListener('click', function(e) {
    e.stopPropagation();
    let finalRecurringVal = null;
    if (featureData.recurring === true) {
      finalRecurringVal = true;
    }
    // Remove the recurring row if present
    const recurringRow = contentDiv.querySelector('.recurring-row');
    if (recurringRow) recurringRow.remove();

    const newOptionKey = 'option_' + Date.now();
    const newOptionData = {
      name: '',
      recurring: finalRecurringVal,
      interval: null,
      startDate: null,
      priceFloor: 0,
      priceCeiling: 0,
      staticPrice: null,
      optionMetric: availableOptionMetrics.length > 0 ? availableOptionMetrics[0] : '',
      addons: []
    };

    featureData.options[newOptionKey] = newOptionData;

    const newOptionElem = createOptionElement(
      featureKey,
      newOptionKey,
      newOptionData,
      availableOptionMetrics,
      availableAddonMetrics
    );
    newOptionElem.classList.add('fade-in');
    contentDiv.insertBefore(newOptionElem, addOptionRow);

    expandFeature(featureDiv);
    newOptionElem.scrollIntoView({ behavior: 'smooth' });
    saveData();
  });
  addOptionRow.appendChild(addOptionButton);
  contentDiv.appendChild(addOptionRow);

  featureDiv.appendChild(contentDiv);

  // If flagged open
  if (window.expandedFeatures && window.expandedFeatures[featureKey]) {
    contentDiv.classList.add('open');
    contentDiv.style.maxHeight = contentDiv.scrollHeight + 'px';
    toggleIndicator.textContent = '-';
  }

  // Delete feature
  deleteFeatureBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    featureDiv.classList.add('fade-out');
    featureDiv.addEventListener('animationend', function() {
      delete window.pricingData.features[featureKey];
      saveData();
      featureDiv.remove();
    });
  });

  // Toggle expand/collapse
  header.addEventListener('click', function(e) {
    if (rightDiv.contains(e.target)) return;
    if (contentDiv.classList.contains('open')) {
      contentDiv.style.maxHeight = 0;
      contentDiv.classList.remove('open');
      toggleIndicator.textContent = '+';
      window.expandedFeatures[featureKey] = false;
    } else {
      contentDiv.classList.add('open');
      contentDiv.style.maxHeight = contentDiv.scrollHeight + 'px';
      toggleIndicator.textContent = '-';
      window.expandedFeatures[featureKey] = true;
    }
  });

  return featureDiv;
}

/*****************************************************
 * 8) Delete Option from a Feature
 *****************************************************/
function deleteFeatureOption(pricingData, featureKey, optionKey) {
  if (pricingData.features[featureKey] && pricingData.features[featureKey].options[optionKey]) {
    delete pricingData.features[featureKey].options[optionKey];
  }
}

/*****************************************************
 * 9) Render all features
 *****************************************************/
function renderPricingForm(data, availableOptionMetrics, availableAddonMetrics) {
  const container = document.getElementById('pricing-form');
  container.innerHTML = '';

  // Ensure plain object
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    data = {};
  }
  if (!data.features || typeof data.features !== 'object') {
    data.features = {};
  }

  for (let featureKey in data.features) {
    const featureData = data.features[featureKey];
    if (!featureData.options || typeof featureData.options !== 'object') {
      featureData.options = {};
    }
    const featureElem = createFeatureElement(
      featureKey,
      featureData,
      availableOptionMetrics,
      availableAddonMetrics
    );
    container.appendChild(featureElem);
  }

  // Add Feature button
  const addFeatureButton = document.createElement('button');
  addFeatureButton.textContent = 'Add Feature';
  addFeatureButton.className = 'add-button';
  addFeatureButton.addEventListener('click', function() {
    if (!data.features || typeof data.features !== 'object') {
      data.features = {};
    }
    const newFeatureKey = 'Feature_' + Date.now();
    data.features[newFeatureKey] = {
      name: '',
      description: '',
      options: {}
    };
    window.expandedFeatures = window.expandedFeatures || {};
    window.expandedFeatures[newFeatureKey] = true;

    const newFeatureElem = createFeatureElement(
      newFeatureKey,
      data.features[newFeatureKey],
      availableOptionMetrics,
      availableAddonMetrics
    );
    newFeatureElem.classList.add('fade-in');
    container.insertBefore(newFeatureElem, addFeatureButton);

    expandFeature(newFeatureElem);
    newFeatureElem.scrollIntoView({ behavior: 'smooth' });
    saveData();
  });
  container.appendChild(addFeatureButton);
}

/*****************************************************
 * 10) On DOM ready
 *****************************************************/
document.addEventListener('DOMContentLoaded', function() {
  // Attempt to load from sessionStorage
  let loaded = loadData();
  if (loaded) {
    window.pricingData = loaded;
  } else {
    // fallback to server-provided
    window.pricingData = pricingData.data;
  }

  // Convert array to object if needed
  if (!window.pricingData || typeof window.pricingData !== 'object' || Array.isArray(window.pricingData)) {
    window.pricingData = { features: {} };
  }
  if (!window.pricingData.features || typeof window.pricingData.features !== 'object') {
    window.pricingData.features = {};
  }

  window.expandedFeatures = window.expandedFeatures || {};

  // Build arrays
  const availableOptionMetrics = (function(data) {
    let metrics = new Set();
    for (let featureKey in data.features) {
      let options = data.features[featureKey].options;
      for (let optKey in options) {
        if (options[optKey].optionMetric) {
          metrics.add(options[optKey].optionMetric);
        }
      }
    }
    return Array.from(metrics);
  })(window.pricingData);

  const availableAddonMetrics = (function(data) {
    let metrics = new Set();
    for (let featureKey in data.features) {
      let options = data.features[featureKey].options;
      for (let optKey in options) {
        options[optKey].addons.forEach(function(addon) {
          if (addon.addOnMetric && addon.addOnMetric.trim() !== '') {
            metrics.add(addon.addOnMetric);
          }
        });
      }
    }
    return Array.from(metrics);
  })(window.pricingData);

  renderPricingForm(window.pricingData, availableOptionMetrics, availableAddonMetrics);

  const applyButton = document.getElementById('apply-button');
  if (applyButton) {
    applyButton.addEventListener('click', function() {
      renderPricingForm(window.pricingData, availableOptionMetrics, availableAddonMetrics);
    });
  }
});
