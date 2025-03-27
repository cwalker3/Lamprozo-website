/*****************************************************
 * 1) Use JSON Control Data Directly
 *****************************************************/
window.nameChanges = sessionStorage.getItem('nameChanges')
  ? JSON.parse(sessionStorage.getItem('nameChanges'))
  : { features: {}, options: {}, addons: {} };

/*****************************************************
 * Helper: Format Field Name
 *****************************************************/
function formatFieldName(fieldName) {
  let result = fieldName.replace(/[_-]+/g, ' ');
  result = result.replace(/([a-z])([A-Z])/g, '$1 $2');
  result = result.split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
  return result;
}

/*****************************************************
 * 2) Scroll helper
 *****************************************************/
function scrollIntoViewWithOffset(element, offset) {
  element.scrollIntoView({ behavior: 'smooth', block: 'start' });
  setTimeout(() => {
    window.scrollBy({ top: -offset, behavior: 'smooth' });
  }, 500);
}

/*****************************************************
 * 3) Mutual Exclusivity – static vs. floor/ceiling
 *****************************************************/
function handleStaticPriceChange(floorInput, ceilingInput, staticInput) {
  const floorVal = floorInput.value.trim() === "" ? 0 : parseFloat(floorInput.value) || 0;
  const ceilVal  = ceilingInput.value.trim() === "" ? 0 : parseFloat(ceilingInput.value) || 0;
  const staticVal = staticInput.value.trim() === "" ? 0 : parseFloat(staticInput.value) || 0;
  if (staticInput.value.trim() !== "" && staticVal !== 0) {
    floorInput.disabled = true;
    ceilingInput.disabled = true;
    staticInput.disabled = false;
  } else if ((floorInput.value.trim() !== "" && floorVal !== 0) ||
             (ceilingInput.value.trim() !== "" && ceilVal !== 0)) {
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
 * 4) Confirmation Dialog
 *****************************************************/
function confirmDeletion(name, onConfirm) {
  const dialog = document.getElementById('confirm-dialog');
  const message = document.getElementById('confirm-message');
  const yesBtn = document.getElementById('confirm-yes');
  const noBtn = document.getElementById('confirm-no');
  message.textContent = 'Are you sure you want to delete "' + name + '"?';
  dialog.classList.add('show');
  function closeDialog() {
    dialog.classList.remove('show');
    yesBtn.removeEventListener('click', onYes);
    noBtn.removeEventListener('click', onNo);
  }
  function onYes() {
    closeDialog();
    onConfirm();
  }
  function onNo() {
    closeDialog();
  }
  yesBtn.addEventListener('click', onYes);
  noBtn.addEventListener('click', onNo);
}

/*****************************************************
 * 5) Save & Load Data in sessionStorage
 *****************************************************/
function saveData() {
  sessionStorage.setItem('pricingData', JSON.stringify(window.pricingData));
  sessionStorage.setItem('nameChanges', JSON.stringify(window.nameChanges));
}
function loadData() {
  let stored = sessionStorage.getItem('pricingData');
  if (stored) {
    return JSON.parse(stored);
  }
  return null;
}

/*****************************************************
 * 6) Decide display name for a key/dataName pair
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
 * 7) Create a field group (label + input)
 *****************************************************/
function createFieldGroup(labelText, inputType, value, placeholder = '') {
  const group = document.createElement('div');
  group.className = 'field-group';
  const label = document.createElement('label');
  label.textContent = formatFieldName(labelText) + ':';
  if (inputType === 'text') {
    const input = createInputOrTextarea(labelText, value, placeholder);
    group.appendChild(label);
    group.appendChild(input);
    return group;
  } else if (inputType === 'date') {
    const input = document.createElement('input');
    input.type = 'date';
    input.value = value || '';
    input.addEventListener('change', saveData);
    input.addEventListener('blur', saveData);
    group.appendChild(label);
    group.appendChild(input);
    return group;
  } else if (inputType === 'number') {
    const input = document.createElement('input');
    input.type = 'number';
    input.value = (value === 0 ? "" : value);
    if (placeholder) { input.placeholder = placeholder; }
    input.addEventListener('input', saveData);
    group.appendChild(label);
    group.appendChild(input);
    return group;
  } else {
    const input = document.createElement('input');
    input.type = inputType;
    input.value = value;
    if (placeholder) { input.placeholder = placeholder; }
    input.addEventListener('blur', saveData);
    group.appendChild(label);
    group.appendChild(input);
    return group;
  }
}

/*****************************************************
 * 8) Minimal helper for description -> textarea
 *****************************************************/
function createInputOrTextarea(labelText, value, placeholder) {
  if (labelText.toLowerCase().includes('description')) {
    const txt = document.createElement('textarea');
    if (typeof value === "object" && value !== null && "text" in value) {
      txt.value = value.text;
    } else {
      txt.value = value || '';
    }
    txt.placeholder = placeholder;
    txt.rows = 3;
    txt.addEventListener('input', function(e) {
      if (typeof value === "object" && value !== null && "text" in value) {
        value.text = e.target.value;
      }
      saveData();
    });
    return txt;
  } else {
    const inp = document.createElement('input');
    inp.type = 'text';
    inp.value = value;
    if (placeholder) { inp.placeholder = placeholder; }
    inp.addEventListener('input', function() { saveData(); });
    return inp;
  }
}

/*****************************************************
 * 9) Create a dropdown field group (label + select)
 *****************************************************/
function createDropdownFieldGroup(labelText, optionsArray, selectedValue) {
  const group = document.createElement('div');
  group.className = 'field-group';
  const label = document.createElement('label');
  label.textContent = formatFieldName(labelText) + ':';
  const select = document.createElement('select');
  optionsArray.forEach(function(option) {
    const opt = document.createElement('option');
    opt.value = option;
    opt.textContent = option;
    if (option === selectedValue) { opt.selected = true; }
    select.appendChild(opt);
  });
  select.addEventListener('change', saveData);
  group.appendChild(label);
  group.appendChild(select);
  return group;
}

/*****************************************************
 * 10) Force feature to fully expand (open)
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
 * 11) Dynamic Field Generation
 *****************************************************/
function determineFieldType(value) {
  if (typeof value === "object" && value !== null) {
    if (value.hasOwnProperty("types") && Array.isArray(value.types) && value.hasOwnProperty("selected")) {
      return "dropdown_custom";
    }
    if (value.hasOwnProperty("text")) {
      return "textarea";
    }
  }
  const t = typeof value;
  if (t === "boolean") return "checkbox";
  if (t === "number") return "number";
  if (Array.isArray(value)) {
    if (value.every(v => typeof v === "string")) return "dropdown";
    return "text";
  }
  if (t === "string") { return value.length > 80 ? "textarea" : "text"; }
  return "text";
}

function createDynamicField(key, value, onChange) {
  const fieldType = determineFieldType(value);
  const group = document.createElement('div');
  group.className = 'field-group';
  const label = document.createElement('label');
  label.textContent = formatFieldName(key) + ':';
  group.appendChild(label);
  if (fieldType === 'checkbox') {
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.checked = !!value;
    input.addEventListener('change', e => onChange(e.target.checked));
    group.appendChild(input);
  } else if (fieldType === 'number') {
    const input = document.createElement('input');
    input.type = 'number';
    input.value = value;
    input.addEventListener('input', e => onChange(parseFloat(e.target.value) || 0));
    group.appendChild(input);
  } else if (fieldType === 'textarea') {
    const txt = document.createElement('textarea');
    if (typeof value === "object" && value !== null && "text" in value) {
      txt.value = value.text;
    } else {
      txt.value = value;
    }
    txt.rows = 3;
    txt.addEventListener('input', e => {
      if (typeof value === "object" && value !== null && "text" in value) {
        onChange({ text: e.target.value });
      } else {
        onChange(e.target.value);
      }
    });
    group.appendChild(txt);
  } else if (fieldType === 'dropdown_custom') {
    const select = document.createElement('select');
    value.types.forEach(function(opt, index) {
      const optionElem = document.createElement('option');
      optionElem.value = index;
      optionElem.textContent = opt;
      if (index === value.selected) {
        optionElem.selected = true;
      }
      select.appendChild(optionElem);
    });
    select.addEventListener('change', function(e) {
      onChange({ types: value.types, selected: parseInt(e.target.value, 10) });
    });
    group.appendChild(select);
  } else if (fieldType === 'dropdown') {
    const select = document.createElement('select');
    value.forEach(strVal => {
      const opt = document.createElement('option');
      opt.value = strVal;
      opt.textContent = strVal;
      select.appendChild(opt);
    });
    select.addEventListener('change', e => onChange([e.target.value]));
    group.appendChild(select);
  } else {
    const input = document.createElement('input');
    input.type = 'text';
    input.value = value;
    input.addEventListener('input', e => onChange(e.target.value));
    group.appendChild(input);
  }
  return group;
}

function createDynamicFields(obj, container, knownKeys) {
  for (let key in obj) {
    if (!obj.hasOwnProperty(key)) continue;
    // If it's in knownKeys, we skip it here because it might be handled manually above
    if (knownKeys.indexOf(key) !== -1) continue;
    const field = createDynamicField(key, obj[key], newVal => {
      obj[key] = newVal;
      saveData();
    });
    container.appendChild(field);
  }
}

/*****************************************************
 * Helper: Get union of keys and merge defaults
 *****************************************************/
function getUnionKeys(arr) {
  let union = {};
  arr.forEach(obj => {
    Object.keys(obj).forEach(k => union[k] = true);
  });
  return Object.keys(union);
}
function mergeWithUnionKeys(newObj, arrOfObjs) {
  const unionKeys = getUnionKeys(arrOfObjs);
  unionKeys.forEach(key => {
    if (!newObj.hasOwnProperty(key)) {
      let sample;
      for (let i = 0; i < arrOfObjs.length; i++) {
        if (arrOfObjs[i].hasOwnProperty(key)) {
          sample = arrOfObjs[i][key];
          break;
        }
      }
      if (typeof sample === "object" && sample !== null && sample.hasOwnProperty("text")) {
        newObj[key] = { text: "" };
      } else if (typeof sample === "object" && sample !== null && sample.hasOwnProperty("types") && sample.hasOwnProperty("selected")) {
        newObj[key] = { types: sample.types, selected: 0 };
      } else if (typeof sample === "number") {
        newObj[key] = 0;
      } else if (typeof sample === "boolean") {
        newObj[key] = false;
      } else {
        newObj[key] = "";
      }
    }
  });
  return newObj;
}

/*****************************************************
 * 12) Addon Element
 *****************************************************/
function createAddonElement(featureIndex, optionIndex, addonData, availableAddonMetrics) {
  const addonDiv = document.createElement('div');
  addonDiv.className = 'addon';
  const addonNameGroup = createFieldGroup('Addon Name', 'text', addonData.addonName || '', 'Enter addon name...');
  addonNameGroup.querySelector('input, textarea').addEventListener('input', e => {
    const oldName = addonData.addonName;
    const newName = e.target.value;
    if (!window.nameChanges.addons.hasOwnProperty(featureIndex)) {
      window.nameChanges.addons[featureIndex] = {};
    }
    if (!window.nameChanges.addons[featureIndex].hasOwnProperty(optionIndex)) {
      window.nameChanges.addons[featureIndex][optionIndex] = {};
    }
    if (!window.nameChanges.addons[featureIndex][optionIndex].hasOwnProperty('0')) {
      window.nameChanges.addons[featureIndex][optionIndex]['0'] = { oldName: oldName, newName: newName };
    } else {
      if (!window.nameChanges.addons[featureIndex][optionIndex]['0'].oldName) {
        window.nameChanges.addons[featureIndex][optionIndex]['0'].oldName = oldName;
      }
      window.nameChanges.addons[featureIndex][optionIndex]['0'].newName = newName;
    }
    addonData.addonName = newName;
    saveData();
  });
  addonDiv.appendChild(addonNameGroup);
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
  const floorGroup = createFieldGroup('Addon Floor Modifier', 'number', addonData.floorPriceMod || 0, 'Enter floor mod...');
  const floorInput = floorGroup.querySelector('input');
  floorInput.addEventListener('input', e => {
    addonData.floorPriceMod = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, staticPriceModInput);
  });
  addonDiv.appendChild(floorGroup);
  const ceilingGroup = createFieldGroup('Addon Ceiling Modifier', 'number', addonData.ceilingPriceMod || 0, 'Enter ceiling mod...');
  const ceilingInput = ceilingGroup.querySelector('input');
  ceilingInput.addEventListener('input', e => {
    addonData.ceilingPriceMod = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, staticPriceModInput);
  });
  addonDiv.appendChild(ceilingGroup);
  const spVal = addonData.staticPriceMod || '';
  const spGroup = createFieldGroup('Static Price Mod', 'number', spVal, 'Static price');
  const staticPriceModInput = spGroup.querySelector('input');
  staticPriceModInput.addEventListener('input', e => {
    addonData.staticPriceMod = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, staticPriceModInput);
  });
  addonDiv.appendChild(spGroup);
  handleStaticPriceChange(floorInput, ceilingInput, staticPriceModInput);

  // Remove "priceModifierType" from knownAddonKeys so that createDynamicFields
  // will handle it as a dynamic field, i.e. as a dropdown_custom if present.
  const knownAddonKeys = [
    'addonName',
    'addOnMetric',
    'floorPriceMod',
    'ceilingPriceMod',
    'staticPriceMod'
  ];
  createDynamicFields(addonData, addonDiv, knownAddonKeys);

  const addonButtonRow = document.createElement('div');
  addonButtonRow.className = 'button-row';
  const deleteAddonButton = document.createElement('button');
  deleteAddonButton.textContent = 'Delete Addon';
  deleteAddonButton.className = 'delete-button';
  deleteAddonButton.addEventListener('click', () => {
    const nameForDialog = addonData.addonName && addonData.addonName.trim() ? addonData.addonName : 'this addon';
    confirmDeletion(nameForDialog, () => {
      addonDiv.classList.add('fade-out');
      addonDiv.addEventListener('animationend', () => {
        window.pricingData.features[featureIndex].options[optionIndex].addons =
          window.pricingData.features[featureIndex].options[optionIndex].addons.filter(a => a !== addonData);
        saveData();
        addonDiv.remove();
      });
    });
  });
  addonButtonRow.appendChild(deleteAddonButton);
  addonDiv.appendChild(addonButtonRow);
  return addonDiv;
}

/*****************************************************
 * 13) Option Element
 *****************************************************/
function createOptionElement(featureIndex, optionIndex, optionData, availableOptionMetrics, availableAddonMetrics) {
  const featureName = window.pricingData.features[featureIndex].featureName;
  const optionDiv = document.createElement('div');
  optionDiv.className = 'option';
  let optionNameValue = optionData.optionName || '';
  if (optionNameValue === "") optionNameValue = featureName;
  const titleGroup = createFieldGroup('Option Name', 'text', optionNameValue, 'Enter option name...');
  const titleInput = titleGroup.querySelector('input, textarea');
  titleInput.addEventListener('input', e => {
    const oldName = optionData.optionName;
    const newName = e.target.value;
    if (!window.nameChanges.options.hasOwnProperty(featureIndex)) {
      window.nameChanges.options[featureIndex] = {};
    }
    if (!window.nameChanges.options[featureIndex].hasOwnProperty(optionIndex)) {
      window.nameChanges.options[featureIndex][optionIndex] = { oldName: oldName, newName: newName };
    } else {
      if (!window.nameChanges.options[featureIndex][optionIndex].oldName) {
        window.nameChanges.options[featureIndex][optionIndex].oldName = oldName;
      }
      window.nameChanges.options[featureIndex][optionIndex].newName = newName;
    }
    optionData.optionName = newName;
    saveData();
  });
  optionDiv.appendChild(titleGroup);
  if (optionData.recurring !== null) {
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
    const recurringContent = document.createElement('div');
    recurringContent.style.display = optionData.recurring ? 'block' : 'none';
    optionDiv.appendChild(recurringContent);
    const startDateGroup = createFieldGroup('Start Date', 'date', optionData.startDate || '', '');
    const startDateInput = startDateGroup.querySelector('input');
    startDateInput.addEventListener('change', e => {
      optionData.startDate = e.target.value;
      saveData();
    });
    recurringContent.appendChild(startDateGroup);
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
  const floorGroup = createFieldGroup('Price Floor', 'number', optionData.priceFloor || 0, 'Enter floor price...');
  const floorInput = floorGroup.querySelector('input');
  floorInput.addEventListener('input', e => {
    optionData.priceFloor = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, spInput);
  });
  optionDiv.appendChild(floorGroup);
  const ceilingGroup = createFieldGroup('Price Ceiling', 'number', optionData.priceCeiling || 0, 'Enter ceiling price...');
  const ceilingInput = ceilingGroup.querySelector('input');
  ceilingInput.addEventListener('input', e => {
    optionData.priceCeiling = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, spInput);
  });
  optionDiv.appendChild(ceilingGroup);
  const spGroup = createFieldGroup('Static Price', 'number', optionData.staticPrice || '', 'Static price');
  const spInput = spGroup.querySelector('input');
  spInput.addEventListener('input', e => {
    optionData.staticPrice = parseFloat(e.target.value) || 0;
    saveData();
    handleStaticPriceChange(floorInput, ceilingInput, spInput);
  });
  optionDiv.appendChild(spGroup);
  handleStaticPriceChange(floorInput, ceilingInput, spInput);
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

  const knownOptionKeys = [
    'optionName',
    'recurring',
    'startDate',
    'interval',
    'staticPrice',
    'priceFloor',
    'priceCeiling',
    'optionMetric',
    'addons'
  ];
  createDynamicFields(optionData, optionDiv, knownOptionKeys);
  optionData.addons.forEach((addon, addonIndex) => {
    if (!window.nameChanges.addons.hasOwnProperty(featureIndex)) {
      window.nameChanges.addons[featureIndex] = {};
    }
    if (!window.nameChanges.addons[featureIndex].hasOwnProperty(optionIndex)) {
      window.nameChanges.addons[featureIndex][optionIndex] = {};
    }
    const addonElem = createAddonElement(featureIndex, optionIndex, addon, availableAddonMetrics);
    addonElem.setAttribute('data-addon-index', addonIndex);
    optionDiv.appendChild(addonElem);
  });
  const optionButtonRow = document.createElement('div');
  optionButtonRow.className = 'button-row';
  const deleteOptionButton = document.createElement('button');
  deleteOptionButton.textContent = 'Delete Option';
  deleteOptionButton.className = 'delete-button';
  deleteOptionButton.addEventListener('click', function() {
    const dialogName = optionData.optionName && optionData.optionName.trim() !== '' ? optionData.optionName : 'this option';
    confirmDeletion(dialogName, () => {
      optionDiv.classList.add('fade-out');
      optionDiv.addEventListener('animationend', function() {
        window.pricingData.features[featureIndex].options.splice(optionIndex, 1);
        saveData();
        optionDiv.remove();
      });
    });
  });
  optionButtonRow.appendChild(deleteOptionButton);
  optionDiv.appendChild(optionButtonRow);
  const addAddonButton = document.createElement('button');
  addAddonButton.textContent = 'Add Addon';
  addAddonButton.className = 'add-button';
  addAddonButton.addEventListener('click', function() {
    let newAddon = {
      addonName: '',
      addOnMetric: availableAddonMetrics.length > 0 ? availableAddonMetrics[0] : '',
      floorPriceMod: 0,
      ceilingPriceMod: 0,
      priceModifierType: { types: ["add", "multiply"], selected: 0 },
      staticPriceMod: null
    };
    if (optionData.addons && optionData.addons.length > 0) {
      newAddon = mergeWithUnionKeys(newAddon, optionData.addons);
    }
    optionData.addons.push(newAddon);
    const newAddonElem = createAddonElement(featureIndex, optionIndex, newAddon, availableAddonMetrics);
    newAddonElem.classList.add('fade-in');
    optionDiv.insertBefore(newAddonElem, optionButtonRow);
    const featureDiv = optionDiv.closest('.feature');
    if (featureDiv) expandFeature(featureDiv);
    setTimeout(() => {
      scrollIntoViewWithOffset(newAddonElem, window.innerHeight/2 - newAddonElem.offsetHeight/2);
    }, 350);
    saveData();
  });
  optionButtonRow.appendChild(addAddonButton);
  return optionDiv;
}

/*****************************************************
 * 14) Feature Element
 *****************************************************/
function createFeatureElement(featureIndex, featureData, availableOptionMetrics, availableAddonMetrics) {
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
  const contentInner = document.createElement('div');
  contentInner.className = 'feature-content-inner';
  contentDiv.appendChild(contentInner);
  const initialNameVal = featureData.featureName || '';
  const displayName = initialNameVal !== '' ? initialNameVal : 'New Feature';
  const featureTitleSpan = document.createElement('span');
  featureTitleSpan.textContent = displayName;
  leftDiv.appendChild(featureTitleSpan);
  const featureNameGroup = createFieldGroup('Feature Name', 'text', initialNameVal, 'Feature name...');
  const featureNameInput = featureNameGroup.querySelector('input, textarea');
  featureNameInput.addEventListener('input', function(e) {
    const oldName = featureData.featureName;
    const newName = e.target.value;
    if (!window.nameChanges.features.hasOwnProperty(featureIndex)) {
      window.nameChanges.features[featureIndex] = { oldName: oldName, newName: newName };
    } else {
      if (!window.nameChanges.features[featureIndex].oldName) {
        window.nameChanges.features[featureIndex].oldName = oldName;
      }
      window.nameChanges.features[featureIndex].newName = newName;
    }
    featureData.featureName = newName;
    featureTitleSpan.textContent = newName.trim() !== '' ? newName : 'New Feature';
    saveData();
  });
  contentInner.appendChild(featureNameGroup);
  const descVal = featureData.description || '';
  const descGroup = createFieldGroup('Feature Description', 'text', descVal, 'Description...');
  descGroup.querySelector('input,textarea').addEventListener('input', function(e) {
    featureData.description = e.target.value;
    saveData();
  });
  contentInner.appendChild(descGroup);
  const knownFeatureKeys = ['featureName','description','options','recurring'];
  createDynamicFields(featureData, contentInner, knownFeatureKeys);
  if ((!featureData.options || featureData.options.length === 0) && typeof featureData.recurring === 'undefined') {
    const recurringRow = document.createElement('div');
    recurringRow.className = 'field-group recurring-row';
    const recurringLabel = document.createElement('label');
    recurringLabel.textContent = 'Recurring Charge?';
    const recurringCheckbox = document.createElement('input');
    recurringCheckbox.type = 'checkbox';
    recurringCheckbox.checked = false;
    recurringCheckbox.addEventListener('change', e => {
      featureData.recurring = e.target.checked;
      saveData();
    });
    recurringRow.appendChild(recurringLabel);
    recurringRow.appendChild(recurringCheckbox);
    contentInner.appendChild(recurringRow);
  }
  if (!featureData.options || !Array.isArray(featureData.options)) {
    featureData.options = [];
  }
  featureData.options.forEach((optionData, optionIndex) => {
    if (!window.nameChanges.options.hasOwnProperty(featureIndex)) {
      window.nameChanges.options[featureIndex] = {};
    }
    const optionElem = createOptionElement(featureIndex, optionIndex, optionData, availableOptionMetrics, availableAddonMetrics);
    contentInner.appendChild(optionElem);
  });
  // --- Change "Add Feature" button to "Add Option" button ---
  const addOptionRow = document.createElement('div');
  addOptionRow.className = 'button-row';
  const addOptionButton = document.createElement('button');
  addOptionButton.textContent = 'Add Option';
  addOptionButton.className = 'add-button';
  addOptionButton.addEventListener('click', function(e) {
    let newOption = {
      optionName: '',
      recurring: featureData.recurring === true ? true : null,
      startDate: null,
      interval: null,
      staticPrice: null,
      priceFloor: 0,
      priceCeiling: 0,
      optionMetric: availableOptionMetrics.length > 0 ? availableOptionMetrics[0] : '',
      addons: []
    };
    if (featureData.options && featureData.options.length > 0) {
      newOption = mergeWithUnionKeys(newOption, featureData.options);
    }
    featureData.options.push(newOption);
    const newOptionIndex = featureData.options.length - 1;
    const newOptionElem = createOptionElement(featureIndex, newOptionIndex, newOption, availableOptionMetrics, availableAddonMetrics);
    newOptionElem.classList.add('fade-in');
    contentInner.insertBefore(newOptionElem, addOptionRow);
    expandFeature(featureDiv);
    setTimeout(() => {
      scrollIntoViewWithOffset(newOptionElem, window.innerHeight/2 - newOptionElem.offsetHeight/2);
    }, 350);
    saveData();
  });
  addOptionRow.appendChild(addOptionButton);
  contentInner.appendChild(addOptionRow);
  featureDiv.appendChild(contentDiv);
  if (window.expandedFeatures && window.expandedFeatures[featureIndex]) {
    contentDiv.classList.add('open');
    contentDiv.style.maxHeight = contentDiv.scrollHeight + 'px';
    toggleIndicator.textContent = '-';
  }
  deleteFeatureBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    const nameForDialog = featureData.featureName && featureData.featureName.trim() !== '' ? featureData.featureName : 'this feature';
    confirmDeletion(nameForDialog, () => {
      featureDiv.classList.add('fade-out');
      featureDiv.addEventListener('animationend', function() {
        window.pricingData.features.splice(featureIndex, 1);
        saveData();
        featureDiv.remove();
      });
    });
  });
  header.addEventListener('click', function(e) {
    if (rightDiv.contains(e.target)) return;
    if (contentDiv.classList.contains('open')) {
      contentDiv.style.maxHeight = 0;
      contentDiv.classList.remove('open');
      toggleIndicator.textContent = '+';
      window.expandedFeatures[featureIndex] = false;
    } else {
      contentDiv.classList.add('open');
      contentDiv.style.maxHeight = contentDiv.scrollHeight + 'px';
      toggleIndicator.textContent = '-';
      window.expandedFeatures[featureIndex] = true;
      setTimeout(() => {
        scrollIntoViewWithOffset(featureDiv, window.innerHeight * 0.15);
      }, 350);
    }
  });
  return featureDiv;
}

/*****************************************************
 * 16) Delete Option from a Feature
 *****************************************************/
function deleteFeatureOption(pricingData, featureIndex, optionIndex) {
  if (pricingData.features[featureIndex] && pricingData.features[featureIndex].options[optionIndex] !== undefined) {
    pricingData.features[featureIndex].options.splice(optionIndex, 1);
  }
}

/*****************************************************
 * 17) Render all features
 *****************************************************/
function renderPricingForm(data, availableOptionMetrics, availableAddonMetrics) {
  const container = document.getElementById('pricing-form');
  container.innerHTML = '';
  if (!data || typeof data !== 'object' || !Array.isArray(data.features)) {
    data.features = [];
  }
  data.features.forEach((featureData, featureIndex) => {
    if (!featureData.options || !Array.isArray(featureData.options)) {
      featureData.options = Array.isArray(featureData.options)
        ? featureData.options
        : Object.values(featureData.options || {});
    }
    const featureElem = createFeatureElement(featureIndex, featureData, availableOptionMetrics, availableAddonMetrics);
    container.appendChild(featureElem);
  });
  const addFeatureButton = document.createElement('button');
  addFeatureButton.textContent = 'Add Feature';
  addFeatureButton.id = 'add-feature-button';
  addFeatureButton.className = 'add-button';
  addFeatureButton.addEventListener('click', function() {
    let newFeature = {
      featureName: '',
      description: '',
      options: []
    };
    if (data.features && data.features.length > 0) {
      newFeature = mergeWithUnionKeys(newFeature, data.features);
    }
    data.features.push(newFeature);
    window.expandedFeatures = window.expandedFeatures || {};
    window.expandedFeatures[data.features.length - 1] = true;
    const newFeatureElem = createFeatureElement(data.features.length - 1, newFeature, availableOptionMetrics, availableAddonMetrics);
    newFeatureElem.classList.add('fade-in');
    container.insertBefore(newFeatureElem, addFeatureButton);
    expandFeature(newFeatureElem);
    setTimeout(() => {
      scrollIntoViewWithOffset(newFeatureElem, window.innerHeight/2 - newFeatureElem.offsetHeight/2);
    }, 350);
    saveData();
  });
  container.appendChild(addFeatureButton);
}

/*****************************************************
 * Merge Functions (sync only properties by index)
 *****************************************************/
function mergeJSONWithSession(sessionData, controlData) {
  if (!sessionData.features || !Array.isArray(sessionData.features)) {
    sessionData.features = [];
  }
  if (!controlData.features || !Array.isArray(controlData.features)) {
    return sessionData;
  }
  for (let i = 0; i < controlData.features.length; i++) {
    if (i < sessionData.features.length) {
      mergeObjectProperties(sessionData.features[i], controlData.features[i]);
    } else {
      sessionData.features.push(controlData.features[i]);
    }
  }
  return sessionData;
}

function mergeObjectProperties(sessionObj, controlObj) {
  Object.keys(sessionObj).forEach(key => {
    if (!controlObj.hasOwnProperty(key) && !/^nameChanges_/.test(key)) {
      if (!Array.isArray(sessionObj[key])) {
        delete sessionObj[key];
      }
    }
  });
  Object.keys(controlObj).forEach(key => {
    if (Array.isArray(controlObj[key])) {
      if (!sessionObj.hasOwnProperty(key) || !Array.isArray(sessionObj[key])) {
        sessionObj[key] = controlObj[key];
      } else {
        let minLen = Math.min(sessionObj[key].length, controlObj[key].length);
        for (let j = 0; j < minLen; j++) {
          if (typeof controlObj[key][j] === "object" && controlObj[key][j] !== null) {
            mergeObjectProperties(sessionObj[key][j], controlObj[key][j]);
          } else {
            sessionObj[key][j] = controlObj[key][j];
          }
        }
        for (let j = sessionObj[key].length; j < controlObj[key].length; j++) {
          sessionObj[key].push(controlObj[key][j]);
        }
      }
    } else if (typeof controlObj[key] === "object" && controlObj[key] !== null) {
      if (!sessionObj.hasOwnProperty(key) || typeof sessionObj[key] !== "object" || sessionObj[key] === null) {
        sessionObj[key] = controlObj[key];
      } else {
        mergeObjectProperties(sessionObj[key], controlObj[key]);
      }
    } else {
      if (!sessionObj.hasOwnProperty(key)) {
        sessionObj[key] = controlObj[key];
      }
    }
  });
}

/*****************************************************
 * 18) On DOM ready
 *****************************************************/
document.addEventListener('DOMContentLoaded', function() {
  let loaded = loadData();
  const controlData = window.pricingData.data;
  if (loaded) {
    window.pricingData = loaded;
    window.pricingData = mergeJSONWithSession(loaded, controlData);
  } else {
    window.pricingData = pricingData.data;
  }
  if (!window.pricingData || typeof window.pricingData !== 'object' || !Array.isArray(window.pricingData.features)) {
    window.pricingData = { features: [] };
  }
  window.expandedFeatures = window.expandedFeatures || {};

  const availableOptionMetrics = (function(data) {
    let metrics = new Set();
    data.features.forEach(feature => {
      const options = Array.isArray(feature.options)
        ? feature.options
        : Object.values(feature.options || {});
      options.forEach(option => {
        if (option.optionMetric) { metrics.add(option.optionMetric); }
      });
    });
    return Array.from(metrics);
  })(window.pricingData);

  const availableAddonMetrics = (function(data) {
    let metrics = new Set();
    data.features.forEach(feature => {
      const options = Array.isArray(feature.options)
        ? feature.options
        : Object.values(feature.options || {});
      options.forEach(option => {
        const addons = Array.isArray(option.addons)
          ? option.addons
          : Object.values(option.addons || {});
        addons.forEach(function(addon) {
          if (addon.addOnMetric && addon.addOnMetric.trim() !== '') { metrics.add(addon.addOnMetric); }
        });
      });
    });
    return Array.from(metrics);
  })(window.pricingData);

  renderPricingForm(window.pricingData, availableOptionMetrics, availableAddonMetrics);

  // When applying changes, send both pricingData and nameChanges.
  const applyButton = document.getElementById('apply-button');
  if (applyButton) {
    applyButton.addEventListener('click', function() {
      const loader = document.getElementById('pricing-loader');
      if (loader) { loader.style.display = 'block'; }
      const payload = {
        pricingData: window.pricingData,
        nameChanges: window.nameChanges
      };
      fetch(pricingDataSettings.apiUrl + 'save-pricing', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': pricingDataSettings.nonce
        },
        body: JSON.stringify(payload)
      })
      .then(response => response.json())
      .then(function (data) {
        if (!data.success) { console.error(data); }
        // On successful update, reset the nameChanges mapping.
        window.nameChanges = { features: {}, options: {}, addons: {} };
        sessionStorage.setItem('nameChanges', JSON.stringify(window.nameChanges));
        if (loader) { loader.style.display = 'none'; }
      })
      .catch(function (error) {
        console.error('Error:', error);
        if (loader) { loader.style.display = 'none'; }
      });
    });
  }
});
