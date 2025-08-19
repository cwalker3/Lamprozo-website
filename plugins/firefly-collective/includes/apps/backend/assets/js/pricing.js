// plugin/assets/js/pricing.js

// Pricing Editor JavaScript

/**************************************************************
 * 0) Name-Change Setup
 **************************************************************/
window.nameChanges = sessionStorage.getItem('nameChanges')
  ? JSON.parse(sessionStorage.getItem('nameChanges'))
  : { features: {}, options: {}, addons: {} };

function saveData() {
  sessionStorage.setItem('pricingData',
    JSON.stringify(window.pricingData));
  sessionStorage.setItem('nameChanges',
    JSON.stringify(window.nameChanges));
}

function loadData() {
  const s = sessionStorage.getItem('pricingData');
  return s ? JSON.parse(s) : null;
}

/**************************************************************
 * 1. Confirmation Helpers
 **************************************************************/
function confirmDeletion(name, onYes) {
  const d = document.getElementById('confirm-dialog'),
        m = document.getElementById('confirm-message'),
        y = document.getElementById('confirm-yes'),
        n = document.getElementById('confirm-no');
  m.textContent = `Are you sure you want to delete "${name}"?`;
  d.classList.add('show');
  function close() {
    d.classList.remove('show');
    y.removeEventListener('click', yesFn);
    n.removeEventListener('click', noFn);
  }
  function yesFn(){ close(); onYes(); }
  function noFn(){ close(); }
  y.addEventListener('click', yesFn);
  n.addEventListener('click', noFn);
}

function confirmClone(name, onYes) {
  const d = document.getElementById('confirm-dialog'),
        m = document.getElementById('confirm-message'),
        y = document.getElementById('confirm-yes'),
        n = document.getElementById('confirm-no');
  m.textContent = `Are you sure you want to clone "${name}"?`;
  d.classList.add('show');
  function close() {
    d.classList.remove('show');
    y.removeEventListener('click', yesFn);
    n.removeEventListener('click', noFn);
  }
  function yesFn(){ close(); onYes(); }
  function noFn(){ close(); }
  y.addEventListener('click', yesFn);
  n.addEventListener('click', noFn);
}

/**************************************************************
 * 2) Name-change Helpers
 **************************************************************/
function recordNameChange(container, idxs, oldName, newName) {
  let obj = container;
  for (let i = 0; i < idxs.length - 1; i++) {
    const k = idxs[i];
    if (!obj[k]) obj[k] = {};
    obj = obj[k];
  }
  const last = idxs[idxs.length - 1];
  const firstOld = obj[last]?.oldName || oldName;
  obj[last] = { oldName: firstOld, newName };
}

/**************************************************************
 * 3) Merge session onto base JSON
 **************************************************************/
function mergeData(base, overlay) {
  if (base && typeof base === 'object' && 'ui_type' in base && 'value' in base) {
    const ov = overlay && overlay.value !== undefined ? overlay.value : overlay;
    return {
      level: base.level,
      ui_type: base.ui_type,
      value: mergeData(base.value, ov)
    };
  }
  if (Array.isArray(base) && Array.isArray(overlay)) {
    const m = base.map((b, i) =>
      i in overlay ? mergeData(b, overlay[i]) : b
    );
    if (overlay.length > base.length)
      m.push(...overlay.slice(base.length));
    return m;
  }
  if (base && typeof base === 'object' && !Array.isArray(base)
    && overlay && typeof overlay === 'object' && !Array.isArray(overlay)) {
    const obj = {};
    for (const k in base) {
      if (!base.hasOwnProperty(k)) continue;
      obj[k] = k in overlay ? mergeData(base[k], overlay[k]) : base[k];
    }
    return obj;
  }
  return overlay !== undefined ? overlay : base;
}

/**************************************************************
 * 4) Helpers
 **************************************************************/
function formatFieldName(name) {
  return name
    .replace(/[-_]+/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .split(' ')
    .map(w => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}

function scrollIntoViewWithOffset(el) {
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/**************************************************************
 * 5) Pricing-Type Logic
 **************************************************************/
function handlePricingTypeChange(fI, cI, sI, poDiv, typeIdx) {
  // Clear all inputs first
  fI.disabled = cI.disabled = sI.disabled = true;

  // Always hide price options form first
  if (poDiv) {
    poDiv.style.display = 'none';
  }
  
  if (typeIdx === 0) { // static price
    // Enable only static price field for static price option
    sI.disabled = false;
    
    // Clear other fields
    [fI, cI].forEach(i => { i.value = ''; i.dispatchEvent(new Event('input')); });
  } else if (typeIdx === 1) { // price range
    // Enable min/max price fields for price range option
    fI.disabled = cI.disabled = false;
    
    // Clear static price field
    sI.value = ''; sI.dispatchEvent(new Event('input'));
  } else if (typeIdx === 2) { // price options
    // Show price options form for price options option
    if (poDiv) {
      poDiv.style.display = 'block';
    }
    
    // Clear other fields
    [fI, cI].forEach(i => { i.value = ''; i.dispatchEvent(new Event('input')); });
    sI.value = ''; sI.dispatchEvent(new Event('input'));
  }
}

/**************************************************************
 * 6) Field-Group Factory
 **************************************************************/
function createFieldGroup(labelText, inputType, getValue, setValue, placeholder='') {
  const grp = document.createElement('div');
  grp.className = 'field-group';
  const lbl = document.createElement('label');
  lbl.textContent = formatFieldName(labelText) + ':';
  grp.appendChild(lbl);

  function commit(val){ setValue(val); saveData(); }

  if (inputType === 'boolean') {
    const inp = document.createElement('input'); inp.type='checkbox';
    inp.checked = !!getValue();
    inp.addEventListener('change', e => commit(e.target.checked));
    grp.appendChild(inp);

  } else if (inputType==='number' || inputType==='int-float') {
    const inp = document.createElement('input'); inp.type='number';
    if (inputType==='int-float') inp.step='any';
    inp.value = getValue()!=null ? getValue() : '';
    inp.addEventListener('input', e =>
      commit(parseFloat(e.target.value)||0)
    );
    grp.appendChild(inp);

  } else if (inputType==='date') {
    const inp = document.createElement('input'); inp.type='date';
    inp.value = getValue()||'';
    inp.addEventListener('change', e => commit(e.target.value));
    grp.appendChild(inp);

  } else if (inputType==='long-text' || inputType==='textarea') {
    const ta = document.createElement('textarea');
    ta.rows = 3; ta.placeholder = placeholder;
    ta.value = getValue()||'';
    ta.addEventListener('input', e => commit(e.target.value));
    grp.appendChild(ta);

  } else if (inputType==='dropdown') {
    const { types, selected } = getValue();
    const sel = document.createElement('select');
    types.forEach((opt,i)=>{
      const o=document.createElement('option');
      o.value=i; o.textContent=opt;
      if (i===selected) o.selected=true;
      sel.appendChild(o);
    });
    sel.addEventListener('change', e =>
      commit({ types, selected: parseInt(e.target.value,10) })
    );
    grp.appendChild(sel);

  } else {
    const inp = document.createElement('input'); inp.type='text';
    inp.placeholder = placeholder;
    inp.value = getValue()!=null ? getValue() : '';
    inp.addEventListener('input', e => commit(e.target.value));
    grp.appendChild(inp);
  }

  return grp;
}

/**************************************************************
 * 7) Expand helper
 **************************************************************/
function expandFeature(featureDiv) {
  const content = featureDiv.querySelector('.feature-content'),
         toggle = featureDiv.querySelector('.toggle-indicator');
  
  if (!content.classList.contains('open')) {
    // IMPORTANT: Set max-height to 0 first
    content.style.maxHeight = '0px';
    // Force reflow
    void content.offsetHeight;
    
    // Add the open class
    content.classList.add('open');
    toggle.textContent = '-';
    
    // Then animate to full height
    setTimeout(() => {
      content.style.maxHeight = content.scrollHeight * 2 + 'px';
    }, 10);
  }
}

// Refined toggleExpandCollapse with smoother transitions
// Modify the toggleExpandCollapse function to update max group items UI when expanding
function toggleExpandCollapse(element, forceExpand = false) {
  const content = element.querySelector('.content');
  const toggle = element.querySelector('.toggle-indicator');
  
  if (!content) return;
  
  if (content.classList.contains('open') && !forceExpand) {
    // Collapse - This works well, keeping as is
    content.classList.remove('open');
    content.style.maxHeight = '0px';
    toggle.textContent = '+';
    
    // Update parent containers after collapse
    setTimeout(() => {
      updateParentContainers(element);
    }, 50);
  } else {
    // Expand - Simplified for smoother animation
    content.classList.add('open');
    toggle.textContent = '-';
    
    // Get actual content height
    content.style.maxHeight = '0px';
    void content.offsetHeight; // Force reflow
    
    // Calculate a safe size that won't need to be changed later
    // This is key to smooth animation - set it once to a value that won't need adjustment
    const safeHeight = Math.max(content.scrollHeight * 2, 1000) + 'px';
    content.style.maxHeight = safeHeight;
    
    // Update the Max Group Items UI if this is an addon with a group
    if (element.classList.contains('addon')) {
      // Find the fIdx, oIdx, aIdx for this addon
      const addon = findAddonFromElement(element);
      if (addon && addon.data && addon.data.groupName) {
        // Find the max group items field
        const maxGroupItemsField = Array.from(content.querySelectorAll('.field-group')).find(field => {
          const label = field.querySelector('label');
          return label && label.textContent === 'Max Group Items:';
        });
        
        if (maxGroupItemsField) {
          const input = maxGroupItemsField.querySelector('input[type="number"]');
          const checkbox = maxGroupItemsField.querySelector('input[type="checkbox"]');
          
          if (input && checkbox) {
            // Update the UI to match the data
            const maxGroupItems = addon.data.maxGroupItems;
            if (maxGroupItems === -1) {
              checkbox.checked = true;
              input.disabled = true;
              input.value = '';
            } else {
              checkbox.checked = false;
              input.disabled = false;
              input.value = maxGroupItems.toString();
            }
          }
        }
      }
    }
    
    // Update parent containers with a slight delay to ensure this element has started expanding
    setTimeout(() => {
      updateParentContainers(element);
    }, 50);
  }
}

// Helper function to find addon data from DOM element
function findAddonFromElement(addonElement) {
  // Find the feature and option containers
  const optionEl = addonElement.closest('.option');
  const featureEl = optionEl ? optionEl.closest('.feature') : null;
  
  if (!featureEl || !optionEl) return null;
  
  // Find indices
  const features = Array.from(document.querySelectorAll('.feature'));
  const fIdx = features.indexOf(featureEl);
  
  if (fIdx === -1) return null;
  
  const options = Array.from(featureEl.querySelectorAll('.option'));
  const oIdx = options.indexOf(optionEl);
  
  if (oIdx === -1) return null;
  
  const addons = Array.from(optionEl.querySelectorAll('.addon'));
  const aIdx = addons.indexOf(addonElement);
  
  if (aIdx === -1) return null;
  
  // Return the addon data and its indices
  return {
    data: window.pricingData.features[fIdx].options[oIdx].addons[aIdx],
    fIdx: fIdx,
    oIdx: oIdx,
    aIdx: aIdx
  };
}

function updateParentContainers(element) {
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

// New more aggressive cascade update function
function cascadeUpdateContainers(element) {
  // Start with the element itself
  if (element.classList.contains('addon') || element.classList.contains('option')) {
    const content = element.querySelector('.content');
    if (content && content.classList.contains('open')) {
      // Use larger multiplier for addons to ensure space for any content
      const multiplier = element.classList.contains('addon') ? 1.5 : 1.3;
      content.style.maxHeight = (content.scrollHeight * multiplier) + 'px';
    }
  }
  
  // Update option parent if it exists
  const optionEl = element.closest('.option');
  if (optionEl) {
    const optionContent = optionEl.querySelector('.content');
    if (optionContent && optionContent.classList.contains('open')) {
      optionContent.style.maxHeight = (optionContent.scrollHeight * 1.3) + 'px';
    }
  }
  
  // Always update feature container
  const featureEl = element.closest('.feature');
  if (featureEl) {
    const featureContent = featureEl.querySelector('.feature-content');
    if (featureContent && featureContent.classList.contains('open')) {
      featureContent.style.maxHeight = (featureContent.scrollHeight * 1.2) + 'px';
    }
  }
}

// Function to update all open containers with smoother transitions
function updateAllOpenContainers() {
  // Force a reflow first
  document.body.offsetHeight;
  
  // Update all open addon contents first (innermost containers)
  document.querySelectorAll('.addon > .content.open').forEach(content => {
    const newHeight = Math.max(content.scrollHeight * 1.5, 300) + 'px';
    content.style.maxHeight = newHeight;
  });
  
  // Then update all open option contents (middle containers)
  document.querySelectorAll('.option > .content.open').forEach(content => {
    const newHeight = Math.max(content.scrollHeight * 1.3, 500) + 'px';
    content.style.maxHeight = newHeight;
  });
  
  // Finally update all open feature contents (outermost containers)
  document.querySelectorAll('.feature-content.open').forEach(content => {
    const newHeight = Math.max(content.scrollHeight * 1.2, 800) + 'px';
    content.style.maxHeight = newHeight;
  });
}

/**************************************************************
 * 8) Inline Add-Addon Flow
 **************************************************************/

function showNewAddonForm(fIdx, oIdx) {

  const featEls = document.querySelectorAll('.feature'),
         optEls = featEls[fIdx].querySelectorAll('.option'),
         optEl  = optEls[oIdx];
  
  // Check if form already exists
  if (optEl.querySelector('#new-addon-form')) return;

  // determine feature schema origin
  const parentFeat = window.pricingData.features[fIdx];
  let originFeatIdx = fIdx;
  if (parentFeat.link_name) {
    const fnd = window.pricingData.features.findIndex(f=>f.featureName===parentFeat.link_name);
    if (fnd!==-1) originFeatIdx = fnd;
  }
  const originFeat = window.pricingData.features[originFeatIdx];

  // determine option schema origin
  const thisOpt = parentFeat.options[oIdx];
  let originOptIdx = oIdx;
  if (thisOpt.link_name) {
    const fndO = originFeat.options.findIndex(o=>o.optionName===thisOpt.link_name);
    if (fndO!==-1) originOptIdx = fndO;
  }
  const originOpt = originFeat.options[originOptIdx];

  // Find parent containers before inserting the form
  const featureEl = featEls[fIdx];
  const optionContent = optEl.querySelector('.content');
  const featureContent = featureEl.querySelector('.feature-content');
  
  // First, pre-expand all containers to their maximum to ensure everything is visible
  if (optionContent && optionContent.classList.contains('open')) {
    optionContent.style.maxHeight = '99999px';
  }
  
  if (featureContent && featureContent.classList.contains('open')) {
    featureContent.style.maxHeight = '99999px';
  }
  
  // Force reflow to apply these changes immediately
  void optionContent.offsetHeight;
  void featureContent.offsetHeight;

  // Create the form
  const form = document.createElement('div');
  form.id = 'new-addon-form';
  form.className = 'addon new-feature-form fade-in';
  
  // Schema dropdown
  const schemaG = document.createElement('div');
  schemaG.className='field-group';
  const schemaLbl = document.createElement('label');
  schemaLbl.textContent='Schema:';
  const schemaSel = document.createElement('select');
  const defOpt = document.createElement('option');
  defOpt.value='__default__';
  defOpt.textContent='Default';
  schemaSel.appendChild(defOpt);
  originOpt.addons.forEach((a,i)=>{
    const o = document.createElement('option');
    o.value = i;
    o.textContent = a.addonName || `Addon ${i+1}`;
    schemaSel.appendChild(o);
  });
  schemaG.append(schemaLbl, schemaSel);
  form.append(schemaG);

  let tempName='', tempDesc='';
  form.appendChild(createFieldGroup(
    'addonName','text',
    ()=>tempName, v=>tempName=v,
    'Enter addon name...'
  ));
  form.appendChild(createFieldGroup(
    'description','long-text',
    ()=>tempDesc, v=>tempDesc=v,
    'Description...'
  ));

  const br = document.createElement('div');
  br.className='button-row';
  const createBtn = document.createElement('button');
  createBtn.textContent='Create Addon';
  createBtn.className='add-button';
  const cancelBtn = document.createElement('button');
  cancelBtn.textContent='Cancel';
  cancelBtn.className='delete-button';
  br.append(createBtn, cancelBtn);
  form.append(br);
  
  // Find the add-addon row and insert the form before it
  const addAddonRow = optEl.querySelector('.add-addon-row');
  addAddonRow.parentNode.insertBefore(form, addAddonRow);
  
  // Force layout recalculation
  void form.offsetHeight;
  
  // Update container heights in multiple passes
  setTimeout(() => {
    updateAllOpenContainers();
    scrollIntoViewWithOffset(form);
  }, 50);
  
  // Additional update passes
  setTimeout(() => updateAllOpenContainers(), 200);
  setTimeout(() => updateAllOpenContainers(), 500);

  // Cancel button handler
  cancelBtn.addEventListener('click', ()=>{
    form.remove();
    // Update heights after removing form
    setTimeout(() => {
      cascadeUpdateContainers(optEl);
    }, 50);
  });

  // Create button handler
  createBtn.addEventListener('click', ()=>{
    if (!tempName.trim()) {
      alert('Addon name is required.');
      return;
    }
  
    let newA;
    if (schemaSel.value==='__default__') {
      newA = {
        addonName:'', description:{text:''}, addOnMetric:'',
        floorPriceMod:0, ceilingPriceMod:0,
        pricingType:{level:'admin',ui_type:'array',value:{types:['static price','price range'],selected:0}},
        priceModifierType:{level:'admin',ui_type:'array',value:{types:['add','multiply'],selected:0}},
        staticPriceMod:0,
        // Add these fields
        groupName: '',
        groupThresholdDiscounts: {
          level: 'admin',
          ui_type: 'array-obj',
          value: {
            types: [{ itemCount: "", discount: "" }]
          }
        },
        enableGrouping: false,
        maxGroupItems: -1,
        link_name:''
      };
    } else {
      // CLONE & WIPE wrapper values:
      const tpl = originOpt.addons[+schemaSel.value];
      newA = JSON.parse(JSON.stringify(tpl));
      clearSchemaValues(newA);
  
      // then zero out the fields we want blank:
      newA.link_name        = tpl.addonName;
      newA.addonName        = '';
      newA.description      = { text:'' };
      newA.floorPriceMod    = 0;
      newA.ceilingPriceMod  = 0;
      newA.staticPriceMod   = 0;
      newA.addOnMetric      = '';
    }
    newA.addonName = tempName;
    newA.description.text = tempDesc;
  
    // Add to data structure
    window.pricingData.features[fIdx].options[oIdx].addons.push(newA);
    saveData();
    form.remove();
  
    // Pre-expand all containers again
    if (optionContent) optionContent.style.maxHeight = '99999px';
    if (featureContent) featureContent.style.maxHeight = '99999px';
    
    // Force layout recalculation
    void optionContent.offsetHeight;
    void featureContent.offsetHeight;
  
    // Build available metrics list
    const availAdd = [];
    window.pricingData.features[fIdx].options[oIdx].addons.forEach(a=>{
      if (a.addOnMetric && !availAdd.includes(a.addOnMetric))
        availAdd.push(a.addOnMetric);
    });
    
    // Get needed reference and create new element
    const featureRecCheckbox = featEls[fIdx].querySelector('input[type=checkbox]');
    const newAi = window.pricingData.features[fIdx].options[oIdx].addons.length - 1;
    const newAddEl = createAddonElement(
      fIdx, oIdx, newA, availAdd, featureRecCheckbox, newAi
    );
    newAddEl.classList.add('fade-in');
    
    // Add the new element to the DOM
    const addonContent = optEl.querySelector('.addon-content-area');
    const addAddonRow = optEl.querySelector('.add-addon-row');
    
    // Remove the add-addon-row first
    if (addAddonRow && addAddonRow.parentNode) {
      addAddonRow.parentNode.removeChild(addAddonRow);
    }
    
    // Add the new addon
    if (addonContent) {
      addonContent.appendChild(newAddEl);
      
      // Add the add-addon-row back
      if (addAddonRow) {
        addonContent.appendChild(addAddonRow);
      }
    }
    
    // Use a sequence of timers to ensure proper expansion
    setTimeout(() => {
      // First expand the new addon
      toggleExpandCollapse(newAddEl, true);
      
      // Then update all containers after a delay
      setTimeout(() => {
        updateAllOpenContainers();
        
        // Scroll to the new addon
        scrollIntoViewWithOffset(newAddEl);
        
        // Multiple additional update passes
        setTimeout(() => updateAllOpenContainers(), 200);
        setTimeout(() => updateAllOpenContainers(), 400);
        setTimeout(() => updateAllOpenContainers(), 800);
      }, 100);
    }, 100);
  });
}

/**************************************************************
 * 9) Dynamic-Field Helpers
 **************************************************************/
function determineFieldType(v){
  if (v && typeof v==='object'){
    if (Array.isArray(v.types) && 'selected' in v) return 'dropdown';
    if ('text' in v)                     return 'textarea';
  }
  if (typeof v==='boolean') return 'boolean';
  if (typeof v==='number')  return 'number';
  if (Array.isArray(v)) {
    return v.every(x=>typeof x==='string') ? 'dropdown' : 'text';
  }
  return (typeof v==='string' && v.length>80) ? 'textarea' : 'text';
}

function createDynamicField(key, raw, onChange) {
  let wrapper = null, fieldType, valueHolder;
  if (raw && typeof raw==='object'
    && 'ui_type' in raw && 'value' in raw){
    wrapper = raw;
    fieldType = raw.ui_type;
    valueHolder = raw.value;
  } else {
    valueHolder = raw;
    fieldType = determineFieldType(raw);
  }

  const grp = document.createElement('div');
  grp.className='field-group';
  const lbl = document.createElement('label');

  // Add a special class for user-display fields in admin UI
  if (wrapper && wrapper.level === 'user-display' || 
      (key.endsWith('_display') && wrapper && wrapper.is_display)) {
      grp.classList.add('user-display-field');
  }

  lbl.textContent = formatFieldName(key) + ':';
  grp.appendChild(lbl);

  function commit(val){
    if (wrapper) wrapper.value = val;
    else onChange(val);
    saveData();
  }

  if (wrapper && fieldType==='array'
    && Array.isArray(valueHolder.types)){
    const sel = document.createElement('select');
    valueHolder.types.forEach((opt,i)=>{
      const o=document.createElement('option');
      o.value=i; o.textContent=opt;
      if (i===valueHolder.selected) o.selected=true;
      sel.appendChild(o);
    });
    sel.addEventListener('change', e=>
      commit({ types:valueHolder.types, selected:parseInt(e.target.value,10)})
    );
    grp.appendChild(sel);

  } else if (fieldType==='boolean') {
    const inp = document.createElement('input'); inp.type='checkbox';
    inp.checked = !!valueHolder;
    inp.addEventListener('change', e=>commit(e.target.checked));
    grp.appendChild(inp);

  } else if (fieldType==='number'||fieldType==='int-float') {
    const inp = document.createElement('input'); inp.type='number';
    if (fieldType==='int-float') inp.step='any';
    inp.value = valueHolder!=null?valueHolder:'';
    inp.addEventListener('input', e=>commit(parseFloat(e.target.value)||0));
    grp.appendChild(inp);

  } else if (fieldType==='date') {
    const inp = document.createElement('input'); inp.type='date';
    inp.value = valueHolder||'';
    inp.addEventListener('change', e=>commit(e.target.value));
    grp.appendChild(inp);

  } else if (fieldType==='textarea'||fieldType==='long-text') {
    const ta = document.createElement('textarea'); ta.rows=3;
    const txt = (valueHolder && valueHolder.text!=null)
      ? valueHolder.text : (valueHolder||'');
    ta.value = txt;
    ta.addEventListener('input', e=>commit(e.target.value));
    grp.appendChild(ta);

  } else {
    const inp = document.createElement('input'); inp.type='text';
    inp.value = valueHolder!=null?valueHolder:'';
    inp.addEventListener('input', e=>commit(e.target.value));
    grp.appendChild(inp);
  }

  return grp;
}

function createDynamicFields(obj, container, knownKeys) {
  for (const key in obj) {
    if (!obj.hasOwnProperty(key) || knownKeys.includes(key)) continue;
    const raw = obj[key];
    // Update this condition to include user-display fields
    if (raw && typeof raw === 'object'
      && 'level' in raw
      && 'ui_type' in raw
      && 'value' in raw
      && raw.level !== 'admin'
      && raw.level !== 'user-display') // Added check for user-display
    continue;
    container.appendChild(
      createDynamicField(key, raw, v => obj[key] = v)
    );
  }
}

function createPriceOptionsUI(optionsData, onChange) {
  // Create main container as a field-group to match other fields
  const container = document.createElement('div');
  container.className = 'field-group price-options-field';
  container.style.display = 'none'; // Start hidden, controlled by price type
  
  // Create label (first column)
  const label = document.createElement('label');
  label.textContent = 'Price Options:';
  container.appendChild(label);
  
  // Handle potential string input (from DB)
  let typesArray = [];
  if (typeof optionsData === 'string') {
    try {
      typesArray = JSON.parse(optionsData);
    } catch(e) {
      console.error('Failed to parse price options:', e);
      typesArray = [{ label: 'Default', price: 0 }];
    }
  } else if (Array.isArray(optionsData)) {
    typesArray = optionsData;
  } else if (optionsData && optionsData.types) {
    typesArray = optionsData.types;
  } else {
    typesArray = [{ label: 'Default', price: 0 }];
  }
  
  // Create the working data structure - just the array, no selected property
  const workingData = typesArray;

  // Create right-side container (second column)
  const rightColumn = document.createElement('div');
  rightColumn.className = 'price-options-container';
  rightColumn.style.flex = '1';
  rightColumn.style.display = 'flex';
  rightColumn.style.flexDirection = 'column';
  container.appendChild(rightColumn);
  
// Helper function to update parent container heights
function updateContainerHeights() {
    // Use a small delay to ensure DOM has updated
    setTimeout(() => {
      // Find the closest option and feature containers
      const optionEl = container.closest('.option');
      const featureEl = container.closest('.feature');
      
      if (optionEl) {
        const optionContent = optionEl.querySelector('.content');
        if (optionContent && optionContent.classList.contains('open')) {
          // Set a generous height that accounts for the new content
          optionContent.style.maxHeight = Math.max(optionContent.scrollHeight * 1.2, 1000) + 'px';
        }
      }
      
      if (featureEl) {
        const featureContent = featureEl.querySelector('.feature-content');
        if (featureContent && featureContent.classList.contains('open')) {
          // Set a generous height that accounts for the new content
          featureContent.style.maxHeight = Math.max(featureContent.scrollHeight * 1.2, 1500) + 'px';
        }
      }
    }, 10);
    
    // Also call the global update function as a backup
    setTimeout(() => {
      updateAllOpenContainers();
    }, 50);
  }
  
  function renderOptions() {
    rightColumn.innerHTML = '';
    
    // Create each option row
    workingData.forEach((option, idx) => {
      const row = document.createElement('div');
      row.className = 'price-option-row';
      
      // Label input
      const labelInput = document.createElement('input');
      labelInput.type = 'text';
      labelInput.value = option.label;
      labelInput.placeholder = 'Option label';
      labelInput.addEventListener('input', e => {
        workingData[idx].label = e.target.value;
        onChange(workingData);
      });
      
      // Price input
      const priceInput = document.createElement('input');
      priceInput.type = 'number';
      priceInput.value = option.price;
      priceInput.placeholder = 'Price';
      priceInput.addEventListener('input', e => {
        workingData[idx].price = parseFloat(e.target.value) || 0;
        onChange(workingData);
      });
      
      // Controls container for buttons
      const controlsContainer = document.createElement('div');
      controlsContainer.className = 'controls-container';
      
      // Delete button
      const deleteBtn = document.createElement('button');
      deleteBtn.textContent = '−';
      deleteBtn.className = 'price-option-delete';
      deleteBtn.addEventListener('click', () => {
        workingData.splice(idx, 1);
        onChange(workingData);
        renderOptions();
        // Update container heights after deletion
        updateContainerHeights();
      });
      
      // Up arrow button
      const upBtn = document.createElement('button');
      upBtn.innerHTML = '&#9650;'; // Up arrow symbol
      upBtn.className = 'price-option-arrow';
      upBtn.disabled = idx === 0;
      upBtn.addEventListener('click', () => {
        if (idx > 0) {
          // Swap current item with the one above it
          [workingData[idx-1], workingData[idx]] = 
          [workingData[idx], workingData[idx-1]];
          onChange(workingData);
          renderOptions();
          // Update container heights after reordering
          updateContainerHeights();
        }
      });
      
      // Down arrow button
      const downBtn = document.createElement('button');
      downBtn.innerHTML = '&#9660;'; // Down arrow symbol
      downBtn.className = 'price-option-arrow';
      downBtn.disabled = idx === workingData.length - 1;
      downBtn.addEventListener('click', () => {
        if (idx < workingData.length - 1) {
          // Swap current item with the one below it
          [workingData[idx], workingData[idx+1]] = 
          [workingData[idx+1], workingData[idx]];
          onChange(workingData);
          renderOptions();
          // Update container heights after reordering
          updateContainerHeights();
        }
      });
      
      // Add buttons to controls container
      controlsContainer.appendChild(deleteBtn);
      controlsContainer.appendChild(upBtn);
      controlsContainer.appendChild(downBtn);
      
      // Add all elements to row
      row.appendChild(labelInput);
      row.appendChild(priceInput);
      row.appendChild(controlsContainer);
      rightColumn.appendChild(row);
    });
    
    // Add button row
    const addBtnRow = document.createElement('div');
    addBtnRow.style.textAlign = 'right';
    addBtnRow.style.marginTop = '5px';
    
    const addBtn = document.createElement('button');
    addBtn.textContent = '+';
    addBtn.className = 'add-button';
    addBtn.style.marginTop = '0';
    addBtn.addEventListener('click', () => {
      workingData.push({ label: '', price: 0 });
      onChange(workingData);
      renderOptions();
      // Update container heights after addition
      updateContainerHeights();
    });
    
    addBtnRow.appendChild(addBtn);
    rightColumn.appendChild(addBtnRow);
  }
  
  // Initial render
  renderOptions();
  
  return container;
}

// Improved debounce function with leading and trailing options
function debounce(func, wait, options) {
  let timeout;
  let lastArgs;
  let lastThis;
  let result;
  let lastCallTime = 0;
  
  options = options || {};
  const leading = !!options.leading;
  const trailing = 'trailing' in options ? !!options.trailing : true;
  
  function invokeFunc() {
    result = func.apply(lastThis, lastArgs);
    lastThis = lastArgs = null;
    return result;
  }
  
  return function() {
    const time = Date.now();
    const isInvoking = leading && (lastCallTime === 0);
    
    lastArgs = arguments;
    lastThis = this;
    lastCallTime = time;
    
    if (isInvoking) {
      result = invokeFunc();
    }
    
    clearTimeout(timeout);
    
    if (trailing) {
      timeout = setTimeout(() => {
        lastCallTime = 0;
        if (!leading || (Date.now() - lastCallTime) >= wait) {
          result = invokeFunc();
        }
      }, wait);
    }
    
    return result;
  };
}

function addGroupNameAutocomplete(inputField, fIdx, oIdx, aIdx, addon, maxGroupItemsGroup, groupThresholdDiscountsUI) {
    
    // Get the current option's addons to find existing groups
    const option = window.pricingData.features[fIdx].options[oIdx];
    if (!option || !option.addons) return;
    
    // Collect all existing group names
    const existingGroups = [];
    const groupAddonTemplates = {}; // Store a template addon for each group
    
    // First pass: collect all group names and template addons
    option.addons.forEach(addonItem => {
        if (addonItem.groupName && addonItem.groupName.trim() && 
            !existingGroups.includes(addonItem.groupName)) {
            existingGroups.push(addonItem.groupName);
            // Store the first addon we find with this group name as template
            if (!groupAddonTemplates[addonItem.groupName] && addonItem.enableGrouping) {
                groupAddonTemplates[addonItem.groupName] = addonItem;
            }
        }
        
        // Also check for stored group names
        if (addonItem._storedGroupName && addonItem._storedGroupName.trim() && 
            !existingGroups.includes(addonItem._storedGroupName)) {
            existingGroups.push(addonItem._storedGroupName);
            // We might want to also collect stored data for templates
        }
    });
    
    // Add a datalist element for autocomplete
    const datalistId = `group-names-${fIdx}-${oIdx}-${Math.random().toString(36).substring(2, 9)}`;
    const datalist = document.createElement('datalist');
    datalist.id = datalistId;
    
    // Add options for each group name
    existingGroups.forEach(groupName => {
        const option = document.createElement('option');
        option.value = groupName;
        datalist.appendChild(option);
    });
    
    // Add the datalist to the document
    document.body.appendChild(datalist);
    
    // Connect the input to the datalist
    inputField.setAttribute('list', datalistId);
    
    // Add event listener to restore stored settings when a matching group is selected
    inputField.addEventListener('change', function() {
        const selectedGroupName = this.value.trim();
        if (!selectedGroupName) return;
        
        // Find an addon with the same group name to use as a template
        const templateAddon = groupAddonTemplates[selectedGroupName];
        
        if (templateAddon) {
            // Update the fields and data with values from the template
            updateAddonGroupFields(addon, templateAddon, maxGroupItemsGroup, groupThresholdDiscountsUI, fIdx, oIdx, aIdx);
        }
    });
    
    // Also check for stored group name and restore it if present
    if (addon._storedGroupName && !addon.groupName) {
        inputField.placeholder = `Previously: ${addon._storedGroupName}`;
    }
}

// Helper function to update group fields from a template addon
function updateAddonGroupFields(targetAddon, templateAddon, maxGroupItemsGroup, groupThresholdDiscountsUI, fIdx, oIdx, aIdx) {
    // Make sure fields are visible
    if (maxGroupItemsGroup) maxGroupItemsGroup.style.display = 'flex';
    if (groupThresholdDiscountsUI) groupThresholdDiscountsUI.style.display = 'flex';
    
    const groupName = templateAddon.groupName;
    
    // 0. Update the group name property
    targetAddon.groupName = groupName;
    targetAddon.enableGrouping = true;
    
    // 1. Update maxGroupItems
    targetAddon.maxGroupItems = templateAddon.maxGroupItems;
    
    // Update the max items UI
    if (maxGroupItemsGroup) {
        const input = maxGroupItemsGroup.querySelector('input[type="number"]');
        const checkbox = maxGroupItemsGroup.querySelector('input[type="checkbox"]');
        
        if (input && checkbox) {
            // Update the UI to match the templateAddon
            if (templateAddon.maxGroupItems === -1) {
                checkbox.checked = true;
                input.disabled = true;
                input.value = '';
            } else {
                checkbox.checked = false;
                input.disabled = false;
                input.value = templateAddon.maxGroupItems.toString();
            }
            
            // RE-REGISTER WITH GROUP MAX ITEMS SYNC
            registerMaxGroupItems(groupName, fIdx, oIdx, aIdx, input, checkbox);
        }
    }
    
    // 2. Update groupThresholdDiscounts with a deep copy
    if (templateAddon.groupThresholdDiscounts) {
        // Create a completely separate copy to avoid reference issues
        targetAddon.groupThresholdDiscounts = JSON.parse(JSON.stringify(templateAddon.groupThresholdDiscounts));
        
        // Rebuild the discount thresholds UI
        rebuildThresholdUI(groupThresholdDiscountsUI, targetAddon.groupThresholdDiscounts, groupName, fIdx, oIdx, aIdx);
    }
    
    // Save the data
    saveData();
    
    // Update container heights after a short delay
    setTimeout(() => {
        updateAllOpenContainers();
    }, 100);
}

// Helper function to register with max group items sync system
function registerMaxGroupItems(groupName, fIdx, oIdx, aIdx, input, checkbox) {
    // Create a render function that updates the UI
    function renderMaxItems(newValue) {
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
    }
    
    // Ensure group registry exists
    if (!window.groupMaxItemsUIRegistry) {
        window.groupMaxItemsUIRegistry = {};
    }
    
    // Initialize the group registry if needed
    if (!window.groupMaxItemsUIRegistry[groupName]) {
        window.groupMaxItemsUIRegistry[groupName] = [];
    }
    
    // Remove any existing entries for this addon
    window.groupMaxItemsUIRegistry[groupName] = 
        window.groupMaxItemsUIRegistry[groupName].filter(ui => 
            !(ui.featureIdx === fIdx && ui.optionIdx === oIdx && ui.addonIdx === aIdx));
    
    // Add this UI to the registry
    window.groupMaxItemsUIRegistry[groupName].push({
        featureIdx: fIdx,
        optionIdx: oIdx,
        addonIdx: aIdx,
        renderFunction: renderMaxItems
    });
    
    // Re-attach event handlers
    checkbox.addEventListener('change', function() {
        const addon = window.pricingData.features[fIdx].options[oIdx].addons[aIdx];
        const newValue = this.checked ? -1 : (input.value ? parseInt(input.value, 10) : 0);
        addon.maxGroupItems = newValue;
        
        // Sync with other addons
        synchronizeGroupMaxItems(fIdx, oIdx, aIdx, groupName, newValue);
    });
    
    input.addEventListener('input', function() {
        const addon = window.pricingData.features[fIdx].options[oIdx].addons[aIdx];
        const newValue = this.value ? parseInt(this.value, 10) : 0;
        addon.maxGroupItems = newValue;
        
        // Sync with other addons
        synchronizeGroupMaxItems(fIdx, oIdx, aIdx, groupName, newValue);
    });
}

// Helper function to rebuild the threshold UI and register for sync
function rebuildThresholdUI(groupThresholdDiscountsUI, thresholdData, groupName, fIdx, oIdx, aIdx) {
    if (!groupThresholdDiscountsUI) return;
    
    // Extract the threshold data
    let thresholdsArray = [];
    try {
        if (typeof thresholdData === 'string') {
            thresholdsArray = JSON.parse(thresholdData);
        } else if (thresholdData.types) {
            thresholdsArray = thresholdData.types;
        } else if (thresholdData.value && thresholdData.value.types) {
            thresholdsArray = thresholdData.value.types;
        }
    } catch (e) {
        console.error("Error parsing threshold data:", e);
        thresholdsArray = [{ itemCount: "", discount: "" }];
    }
    
    const workingData = JSON.parse(JSON.stringify(thresholdsArray));
    
    // Find the container for threshold rows
    const container = groupThresholdDiscountsUI.querySelector('.price-options-container');
    if (!container) return;
    
    // Clear current content
    container.innerHTML = '';
    
    // Keep track of all input elements for re-registration
    const inputElements = [];
    
    // Create function to update working data and trigger sync
    function updateAndSync(index, field, value) {
        if (!workingData[index]) return;
        
        workingData[index][field] = value;
        
        // Get the addon object
        const addon = window.pricingData.features[fIdx].options[oIdx].addons[aIdx];
        
        // Update addon data
        if (!addon.groupThresholdDiscounts) {
            addon.groupThresholdDiscounts = {
                level: 'admin',
                ui_type: 'array-obj',
                value: { types: workingData }
            };
        } else if (typeof addon.groupThresholdDiscounts === 'string') {
            addon.groupThresholdDiscounts = {
                level: 'admin',
                ui_type: 'array-obj',
                value: { types: workingData }
            };
        } else {
            addon.groupThresholdDiscounts.value = { types: workingData };
        }
        
        // Trigger synchronization
        synchronizeGroupThresholdDiscounts(fIdx, oIdx, aIdx, groupName, workingData);
    }
    
    // Rebuild all threshold rows
    workingData.forEach((threshold, idx) => {
        const row = document.createElement('div');
        row.className = 'price-option-row';
        
        // Quantity input
        const countInput = document.createElement('input');
        countInput.type = 'number';
        countInput.min = '1';
        countInput.value = threshold.itemCount !== '' ? threshold.itemCount : '';
        countInput.placeholder = 'Quantity';
        countInput.dataset.index = idx;
        countInput.dataset.field = 'itemCount';
        inputElements.push(countInput);
        
        // Add event listener for sync
        countInput.addEventListener('input', function() {
            const val = this.value.trim() !== '' ? parseInt(this.value, 10) : '';
            updateAndSync(idx, 'itemCount', val);
        });
        
        // Discount percentage input
        const discountInput = document.createElement('input');
        discountInput.type = 'number';
        discountInput.min = '0';
        discountInput.max = '100';
        discountInput.value = threshold.discount !== '' ? threshold.discount : '';
        discountInput.placeholder = '%';
        discountInput.dataset.index = idx;
        discountInput.dataset.field = 'discount';
        inputElements.push(discountInput);
        
        // Add event listener for sync
        discountInput.addEventListener('input', function() {
            const val = this.value.trim() !== '' ? parseFloat(this.value) : '';
            updateAndSync(idx, 'discount', val);
        });
        
        // Controls container
        const controlsContainer = document.createElement('div');
        controlsContainer.className = 'controls-container';
        
        // Delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.textContent = '−';
        deleteBtn.className = 'price-option-delete';
        deleteBtn.dataset.index = idx;
        
        deleteBtn.addEventListener('click', function() {
            workingData.splice(idx, 1);
            updateAndSync(0, 'placeholder', ''); // Just triggers the sync
            rebuildThresholdUI(groupThresholdDiscountsUI, { value: { types: workingData }}, groupName, fIdx, oIdx, aIdx);
        });
        
        // Up arrow
        const upBtn = document.createElement('button');
        upBtn.innerHTML = '&#9650;';
        upBtn.className = 'price-option-arrow';
        upBtn.disabled = idx === 0;
        upBtn.dataset.index = idx;
        
        upBtn.addEventListener('click', function() {
            if (idx > 0) {
                // Swap with previous row
                [workingData[idx-1], workingData[idx]] = [workingData[idx], workingData[idx-1]];
                updateAndSync(0, 'placeholder', ''); // Just triggers the sync
                rebuildThresholdUI(groupThresholdDiscountsUI, { value: { types: workingData }}, groupName, fIdx, oIdx, aIdx);
            }
        });
        
        // Down arrow
        const downBtn = document.createElement('button');
        downBtn.innerHTML = '&#9660;';
        downBtn.className = 'price-option-arrow';
        downBtn.disabled = idx === workingData.length - 1;
        downBtn.dataset.index = idx;
        
        downBtn.addEventListener('click', function() {
            if (idx < workingData.length - 1) {
                // Swap with next row
                [workingData[idx], workingData[idx+1]] = [workingData[idx+1], workingData[idx]];
                updateAndSync(0, 'placeholder', ''); // Just triggers the sync
                rebuildThresholdUI(groupThresholdDiscountsUI, { value: { types: workingData }}, groupName, fIdx, oIdx, aIdx);
            }
        });
        
        // Add buttons to controls
        controlsContainer.appendChild(deleteBtn);
        controlsContainer.appendChild(upBtn);
        controlsContainer.appendChild(downBtn);
        
        // Add elements to row
        row.appendChild(countInput);
        row.appendChild(discountInput);
        row.appendChild(controlsContainer);
        container.appendChild(row);
    });
    
    // Add button row
    const addBtnRow = document.createElement('div');
    addBtnRow.style.textAlign = 'right';
    addBtnRow.style.marginTop = '5px';
    
    const addBtn = document.createElement('button');
    addBtn.textContent = '+';
    addBtn.className = 'add-button';
    addBtn.style.marginTop = '0';
    
    addBtn.addEventListener('click', function() {
        workingData.push({ itemCount: '', discount: '' });
        updateAndSync(0, 'placeholder', ''); // Just triggers the sync
        rebuildThresholdUI(groupThresholdDiscountsUI, { value: { types: workingData }}, groupName, fIdx, oIdx, aIdx);
    });
    
    addBtnRow.appendChild(addBtn);
    container.appendChild(addBtnRow);
    
    // Register with threshold registry
    if (!window.groupThresholdUIRegistry) {
        window.groupThresholdUIRegistry = {};
    }
    
    if (!window.groupThresholdUIRegistry[groupName]) {
        window.groupThresholdUIRegistry[groupName] = [];
    }
    
    // Create a render function for this UI
    function renderThresholds(newData) {
        rebuildThresholdUI(groupThresholdDiscountsUI, { value: { types: newData }}, groupName, fIdx, oIdx, aIdx);
    }
    
    // Remove any existing entries
    window.groupThresholdUIRegistry[groupName] = 
        window.groupThresholdUIRegistry[groupName].filter(ui => 
            !(ui.featureIdx === fIdx && ui.optionIdx === oIdx && ui.addonIdx === aIdx));
    
    // Add to registry
    window.groupThresholdUIRegistry[groupName].push({
        featureIdx: fIdx,
        optionIdx: oIdx,
        addonIdx: aIdx,
        uiElement: groupThresholdDiscountsUI,
        renderFunction: renderThresholds
    });
}

// Improved createThresholdDiscountsUI function
function createThresholdDiscountsUI(discountsData, onChange, groupInfo = null) {
  // Create main container
  const container = document.createElement('div');
  container.className = 'field-group price-options-field';
  container.style.display = 'none'; // Start hidden
  
  // Only add dataset attributes if groupInfo is provided
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
  let thresholdsArray = [];
  if (typeof discountsData === 'string') {
    try {
      thresholdsArray = JSON.parse(discountsData);
    } catch(e) {
      thresholdsArray = [{ itemCount: "", discount: "" }];
    }
  } else if (Array.isArray(discountsData)) {
    thresholdsArray = discountsData;
  } else if (discountsData && discountsData.types) {
    thresholdsArray = discountsData.types;
  } else {
    thresholdsArray = [{ itemCount: "", discount: "" }];
  }
  
  // Create working data with deep copy
  const workingData = JSON.parse(JSON.stringify(thresholdsArray));
  
  // Create container for discount rows
  const rightColumn = document.createElement('div');
  rightColumn.className = 'price-options-container';
  rightColumn.style.flex = '1';
  rightColumn.style.display = 'flex';
  rightColumn.style.flexDirection = 'column';
  container.appendChild(rightColumn);
  
// Helper function to update parent container heights
function updateContainerHeights() {
    setTimeout(() => {
      // Find the closest option and feature containers
      const addonEl = container.closest('.addon');
      const optionEl = container.closest('.option');
      const featureEl = container.closest('.feature');
      
      if (addonEl) {
        const addonContent = addonEl.querySelector('.content');
        if (addonContent && addonContent.classList.contains('open')) {
          addonContent.style.maxHeight = Math.max(addonContent.scrollHeight * 1.3, 400) + 'px';
        }
      }
      
      if (optionEl) {
        const optionContent = optionEl.querySelector('.content');
        if (optionContent && optionContent.classList.contains('open')) {
          optionContent.style.maxHeight = Math.max(optionContent.scrollHeight * 1.2, 600) + 'px';
        }
      }
      
      if (featureEl) {
        const featureContent = featureEl.querySelector('.feature-content');
        if (featureContent && featureContent.classList.contains('open')) {
          featureContent.style.maxHeight = Math.max(featureContent.scrollHeight * 1.2, 1000) + 'px';
        }
      }
    }, 10);
    
    // Also call the global update function
    setTimeout(() => {
      updateAllOpenContainers();
    }, 50);
  }
  
  // This flag prevents endless sync loop
  let preventSync = false;
  
  // Function to render the threshold rows
  function renderThresholds(externalData = null, preserveId = null) {
    // Use external data if provided
    if (externalData && !preventSync) {
      workingData.length = 0;
      externalData.forEach(item => {
        workingData.push(JSON.parse(JSON.stringify(item)));
      });
    }
    
    // Store currently focused element details if any
    let activeElement = document.activeElement;
    let activeId = activeElement ? activeElement.id : null;
    let activeValue = activeElement ? activeElement.value : null;
    let selectionStart = activeElement ? activeElement.selectionStart : null;
    let selectionEnd = activeElement ? activeElement.selectionEnd : null;
    
    // If preserveId is provided, use it instead
    if (preserveId) {
      activeId = preserveId;
      activeElement = document.getElementById(preserveId);
      if (activeElement) {
        activeValue = activeElement.value;
        selectionStart = activeElement.selectionStart;
        selectionEnd = activeElement.selectionEnd;
      }
    }
    
    // Clear existing rows
    rightColumn.innerHTML = '';
    
    // Create each threshold row
    workingData.forEach((threshold, idx) => {
      const row = document.createElement('div');
      row.className = 'price-option-row';
      
      // Quantity input
      const countInput = document.createElement('input');
      countInput.type = 'number';
      countInput.min = '1';
      countInput.value = threshold.itemCount !== '' ? threshold.itemCount : '';
      countInput.placeholder = 'Quantity';
      
      // Create stable ID for this input
      const scopePrefix = groupInfo ? 'addon-' : 'option-';
      const uniqueGroupId = groupInfo ? 
        `${groupInfo.groupName || 'local'}-${groupInfo.featureIdx || '0'}-${groupInfo.optionIdx || '0'}-${groupInfo.addonIdx || '0'}` : 
        'local';
      const countId = `${scopePrefix}count-input-${uniqueGroupId}-${idx}`;
      countInput.id = countId;
      
      // Debounced update function
      const debouncedCountUpdate = debounce((e) => {
        if (preventSync) return;
        
        const val = e.target.value.trim() !== '' ? parseInt(e.target.value, 10) : '';
        workingData[idx].itemCount = val;
        
        // Update container heights after change
        updateContainerHeights();
        
        // Handle group synchronization if needed
        if (groupInfo && groupInfo.groupName && groupInfo.groupName.trim()) {
          onChange(workingData);
          preventSync = true;
          synchronizeGroupThresholdDiscounts(
            groupInfo.featureIdx,
            groupInfo.optionIdx,
            groupInfo.addonIdx,
            groupInfo.groupName,
            workingData,
            countId
          );
          setTimeout(() => { preventSync = false; }, 10);
        } else {
          onChange(workingData);
        }
      }, 300, { leading: false, trailing: true });
      
      countInput.addEventListener('input', debouncedCountUpdate);
      
      // Discount percentage input
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
        
        // Update container heights after change
        updateContainerHeights();
        
        // Handle group synchronization if needed
        if (groupInfo && groupInfo.groupName && groupInfo.groupName.trim()) {
          onChange(workingData);
          preventSync = true;
          synchronizeGroupThresholdDiscounts(
            groupInfo.featureIdx,
            groupInfo.optionIdx,
            groupInfo.addonIdx,
            groupInfo.groupName,
            workingData,
            discountInput.id
          );
          setTimeout(() => { preventSync = false; }, 10);
        } else {
          onChange(workingData);
        }
      }, 300, { leading: false, trailing: true });
      
      discountInput.addEventListener('input', debouncedDiscountUpdate);
      
      // Controls container
      const controlsContainer = document.createElement('div');
      controlsContainer.className = 'controls-container';
      
      // Delete button
      const deleteBtn = document.createElement('button');
      deleteBtn.textContent = '−';
      deleteBtn.className = 'price-option-delete';
      deleteBtn.addEventListener('click', () => {
        if (preventSync) return;
        
        workingData.splice(idx, 1);
        
        // Handle group synchronization if needed
        if (groupInfo && groupInfo.groupName && groupInfo.groupName.trim()) {
          onChange(workingData);
          preventSync = true;
          synchronizeGroupThresholdDiscounts(
            groupInfo.featureIdx,
            groupInfo.optionIdx,
            groupInfo.addonIdx,
            groupInfo.groupName,
            workingData,
            null,
            true
          );
          setTimeout(() => { preventSync = false; }, 10);
        } else {
          onChange(workingData);
          renderThresholds();
        }
        
        // Update container heights after deletion
        updateContainerHeights();
      });
      
      // Up/Down arrow buttons with similar logic
      const upBtn = document.createElement('button');
      upBtn.innerHTML = '&#9650;';
      upBtn.className = 'price-option-arrow';
      upBtn.disabled = idx === 0;
      upBtn.addEventListener('click', () => {
        if (preventSync || idx === 0) return;
        
        [workingData[idx-1], workingData[idx]] = [workingData[idx], workingData[idx-1]];
        
        if (groupInfo && groupInfo.groupName && groupInfo.groupName.trim()) {
          onChange(workingData);
          preventSync = true;
          synchronizeGroupThresholdDiscounts(
            groupInfo.featureIdx,
            groupInfo.optionIdx,
            groupInfo.addonIdx,
            groupInfo.groupName,
            workingData,
            null,
            true
          );
          setTimeout(() => { preventSync = false; }, 10);
        } else {
          onChange(workingData);
          renderThresholds();
        }
        
        updateContainerHeights();
      });
      
      const downBtn = document.createElement('button');
      downBtn.innerHTML = '&#9660;';
      downBtn.className = 'price-option-arrow';
      downBtn.disabled = idx === workingData.length - 1;
      downBtn.addEventListener('click', () => {
        if (preventSync || idx === workingData.length - 1) return;
        
        [workingData[idx], workingData[idx+1]] = [workingData[idx+1], workingData[idx]];
        
        if (groupInfo && groupInfo.groupName && groupInfo.groupName.trim()) {
          onChange(workingData);
          preventSync = true;
          synchronizeGroupThresholdDiscounts(
            groupInfo.featureIdx,
            groupInfo.optionIdx,
            groupInfo.addonIdx,
            groupInfo.groupName,
            workingData,
            null,
            true
          );
          setTimeout(() => { preventSync = false; }, 10);
        } else {
          onChange(workingData);
          renderThresholds();
        }
        
        updateContainerHeights();
      });
      
      // Add buttons to controls
      controlsContainer.appendChild(deleteBtn);
      controlsContainer.appendChild(upBtn);
      controlsContainer.appendChild(downBtn);
      
      // Add elements to row
      row.appendChild(countInput);
      row.appendChild(discountInput);
      row.appendChild(controlsContainer);
      rightColumn.appendChild(row);
    });
    
    // Add button row
    const addBtnRow = document.createElement('div');
    addBtnRow.style.textAlign = 'right';
    addBtnRow.style.marginTop = '5px';
    
    const addBtn = document.createElement('button');
    addBtn.textContent = '+';
    addBtn.className = 'add-button';
    addBtn.style.marginTop = '0';
    addBtn.addEventListener('click', () => {
      if (preventSync) return;
      
      workingData.push({ itemCount: '', discount: '' });
      
      if (groupInfo && groupInfo.groupName && groupInfo.groupName.trim()) {
        onChange(workingData);
        preventSync = true;
        synchronizeGroupThresholdDiscounts(
          groupInfo.featureIdx,
          groupInfo.optionIdx,
          groupInfo.addonIdx,
          groupInfo.groupName,
          workingData,
          null,
          true
        );
        setTimeout(() => { preventSync = false; }, 10);
      } else {
        onChange(workingData);
        renderThresholds();
      }
      
      // Update container heights after addition
      updateContainerHeights();
    });
    
    addBtnRow.appendChild(addBtn);
    rightColumn.appendChild(addBtnRow);
    
    // Restore focus and cursor position if we had an active element
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
              // Ignore errors from setSelectionRange
            }
          }
        }
      });
    }
  }
  
  // Initialize the UI registry if needed
  if (!window.groupThresholdUIRegistry) {
    window.groupThresholdUIRegistry = {};
  }
  
  // Register in global registry if this is part of a group
  if (groupInfo && groupInfo.groupName && groupInfo.groupName.trim()) {
    if (!window.groupThresholdUIRegistry[groupInfo.groupName]) {
      window.groupThresholdUIRegistry[groupInfo.groupName] = [];
    }
    
    // Remove any existing entries for this addon
    window.groupThresholdUIRegistry[groupInfo.groupName] = 
      window.groupThresholdUIRegistry[groupInfo.groupName].filter(ui => 
        !(ui.featureIdx === groupInfo.featureIdx && 
        ui.optionIdx === groupInfo.optionIdx && 
        ui.addonIdx === groupInfo.addonIdx));
    
    // Add this UI to the registry
    window.groupThresholdUIRegistry[groupInfo.groupName].push({
      featureIdx: groupInfo.featureIdx,
      optionIdx: groupInfo.optionIdx,
      addonIdx: groupInfo.addonIdx,
      uiElement: container,
      renderFunction: renderThresholds
    });
  }
  
  // Initial render
  renderThresholds();
  
  return container;
}

// Improved synchronization function with focus preservation
function synchronizeGroupThresholdDiscounts(currentFeatureIdx, currentOptionIdx, currentAddonIdx, groupName, newDiscounts, preserveFocusId = null, forceUpdateCurrent = false) {
  // Skip if no group name
  if (!groupName || !groupName.trim()) return;
  
  // Initialize the registry if it doesn't exist
  if (!window.groupThresholdUIRegistry) {
    window.groupThresholdUIRegistry = {};
  }
  
  // Create a clean deep copy of the discounts to avoid reference issues
  const discountsCopy = JSON.parse(JSON.stringify(newDiscounts));
  
  // 1. Update the data structures for all addons in the group
  let updateCount = 0;
  window.pricingData.features.forEach((feature, featureIdx) => {
    feature.options.forEach((option, optionIdx) => {
      option.addons.forEach((addon, addonIdx) => {
        // Update any addon with the matching group name
        if (addon.groupName === groupName) {
          updateCount++;
          
          // Skip the current addon that triggered the sync to avoid circular updates
          // UNLESS forceUpdateCurrent is true
          if (!forceUpdateCurrent && featureIdx === currentFeatureIdx && 
              optionIdx === currentOptionIdx && 
              addonIdx === currentAddonIdx) {
            return;
          }
          
          // Update the groupThresholdDiscounts structure
          if (!addon.groupThresholdDiscounts) {
            addon.groupThresholdDiscounts = {
              level: 'admin',
              ui_type: 'array-obj',
              value: {
                types: JSON.parse(JSON.stringify(discountsCopy))
              }
            };
          } else if (typeof addon.groupThresholdDiscounts === 'string') {
            // Convert from string to proper object if it was loaded from DB
            addon.groupThresholdDiscounts = {
              level: 'admin',
              ui_type: 'array-obj',
              value: {
                types: JSON.parse(JSON.stringify(discountsCopy))
              }
            };
          } else {
            // Update existing structure
            addon.groupThresholdDiscounts.value = {
              types: JSON.parse(JSON.stringify(discountsCopy))
            };
          }
        }
      });
    });
  });
  
  // Only save if we actually updated something
  if (updateCount > 0) {
    saveData();
  }
  
  // 2. Update the UI for all addons in the group
  if (window.groupThresholdUIRegistry[groupName]) {
    window.groupThresholdUIRegistry[groupName].forEach(uiInfo => {
      // Skip the current addon to avoid circular updates
      // UNLESS forceUpdateCurrent is true
      if (!forceUpdateCurrent && uiInfo.featureIdx === currentFeatureIdx && 
          uiInfo.optionIdx === currentOptionIdx && 
          uiInfo.addonIdx === currentAddonIdx) {
        return;
      }
      
      // Update this UI element if it's still in the DOM
      if (uiInfo.uiElement && 
          uiInfo.uiElement.parentNode && 
          uiInfo.renderFunction) {
        try {
          // Batch DOM updates with requestAnimationFrame for better performance
          requestAnimationFrame(() => {
            uiInfo.renderFunction(discountsCopy, preserveFocusId);
          });
        } catch (err) {
          // Silent error handling in case an element was removed
        }
      }
    });
  }
  
  // 3. Update container heights to ensure proper display
  setTimeout(() => {
    updateAllOpenContainers();
  }, 100);
}

// Update the modifyCreateOptionElement function with a more direct approach
function modifyCreateOptionElement() {
  // Store a reference to the original function
  const originalFunction = createOptionElement;
  
  // Replace it with our enhanced version
  createOptionElement = function(fIdx, oIdx, opt, availOpt, availAdd, featureRecCheckbox) {
    // Call the original implementation first to get the option element
    const wrap = originalFunction(fIdx, oIdx, opt, availOpt, availAdd, featureRecCheckbox);
    
    // Get the content inner element
    const contentInner = wrap.querySelector('.content-inner');
    
    // Create our new threshold discounts checkbox
    const thresholdCheckboxGroup = document.createElement('div');
    thresholdCheckboxGroup.className = 'field-group';
    
    const thresholdLabel = document.createElement('label');
    thresholdLabel.textContent = 'Use Threshold Discounts:';
    thresholdCheckboxGroup.appendChild(thresholdLabel);
    
    const thresholdCheckbox = document.createElement('input');
    thresholdCheckbox.type = 'checkbox';
    thresholdCheckbox.checked = opt.enableThresholdDiscounts || 
      (opt.thresholdDiscounts && 
      opt.thresholdDiscounts.value && 
      opt.thresholdDiscounts.value.types && 
      opt.thresholdDiscounts.value.types.length > 0 && 
      opt.thresholdDiscounts.value.types.some(t => t.itemCount && t.discount));
    thresholdCheckboxGroup.appendChild(thresholdCheckbox);
    
    // If checkbox is checked due to data existing, make sure the flag is updated
    if (thresholdCheckbox.checked && !opt.enableThresholdDiscounts) {
      opt.enableThresholdDiscounts = true;
    }
    
    // Initialize thresholdDiscounts data if not present
    if (!opt.thresholdDiscounts) {
      opt.thresholdDiscounts = {
        level: 'admin',
        ui_type: 'array-obj',
        value: {
          types: [{ itemCount: "", discount: "" }]
        }
      };
    }
    
    // Create the thresholdDiscounts UI
    const thresholdDiscountsUI = createThresholdDiscountsUI(
      opt.thresholdDiscounts ? (
        typeof opt.thresholdDiscounts === 'string' ? 
        JSON.parse(opt.thresholdDiscounts) : 
        (Array.isArray(opt.thresholdDiscounts) ? opt.thresholdDiscounts : 
        (opt.thresholdDiscounts.value?.types || [{ itemCount: 10, discount: 5 }]))
      ) : [{ itemCount: 10, discount: 5 }],
      v => {
        if (!opt.thresholdDiscounts) {
          opt.thresholdDiscounts = {
            level: 'admin',
            ui_type: 'array-obj',
            value: {
              types: v
            }
          };
        } else if (typeof opt.thresholdDiscounts === 'string') {
          // Convert from string to proper object if it was loaded from DB
          opt.thresholdDiscounts = {
            level: 'admin',
            ui_type: 'array-obj',
            value: {
              types: v
            }
          };
        } else {
          opt.thresholdDiscounts.value = {
            types: v
          };
        }
        saveData();
      }
    );
    
    // Find the option metric field by getting all fields and looking for the right one
    // We'll insert our elements at a specific position in the DOM
    const allFields = contentInner.querySelectorAll('.field-group');
    
    // Look for the option metric field near the end of the form
    let optionMetricIndex = -1;
    for (let i = 0; i < allFields.length; i++) {
      const label = allFields[i].querySelector('label');
      if (label && label.textContent === 'Option Metric:') {
        optionMetricIndex = i;
        break;
      }
    }
    
    if (optionMetricIndex !== -1) {
      // Insert the checkbox before the option metric field
      contentInner.insertBefore(thresholdCheckboxGroup, allFields[optionMetricIndex]);
      // Insert the UI right after the checkbox
      contentInner.insertBefore(thresholdDiscountsUI, allFields[optionMetricIndex]);
    } else {
      // Fallback: Add at the end of the form, before the addons section
      const addonsHeader = contentInner.querySelector('.section-header.option-addons-header');
      if (addonsHeader) {
        contentInner.insertBefore(thresholdCheckboxGroup, addonsHeader);
        contentInner.insertBefore(thresholdDiscountsUI, addonsHeader);
      } else {
        // Last resort: Just append to the content
        contentInner.appendChild(thresholdCheckboxGroup);
        contentInner.appendChild(thresholdDiscountsUI);
      }
    }
    
    // Handle checkbox state change
    thresholdCheckbox.addEventListener('change', e => {
      opt.enableThresholdDiscounts = e.target.checked;
      thresholdDiscountsUI.style.display = e.target.checked ? 'flex' : 'none';
      saveData();
      
      // Update container heights
      setTimeout(() => {
        updateAllOpenContainers();
      }, 100);
    });
    
    // Set initial visibility based on checkbox state
    thresholdDiscountsUI.style.display = thresholdCheckbox.checked ? 'flex' : 'none';
    
    return wrap;
  };
}

// A more reliable way to initialize our modification when the page loads
document.addEventListener('DOMContentLoaded', function() {
  // Add our createThresholdDiscountsUI function first if not already present
  if (typeof createThresholdDiscountsUI !== 'function') {
    // New function to create threshold discounts UI
    window.createThresholdDiscountsUI = function(discountsData, onChange) {
      // Create main container as a field-group to match other fields
      const container = document.createElement('div');
      container.className = 'field-group price-options-field';
      container.style.display = 'none'; // Start hidden, controlled by threshold checkbox
      
      // Create label (first column)
      const label = document.createElement('label');
      label.textContent = 'Quantity Discounts:';
      container.appendChild(label);
      
      // Handle potential string input (from DB)
      let thresholdsArray = [];
      if (typeof discountsData === 'string') {
        try {
          thresholdsArray = JSON.parse(discountsData);
        } catch(e) {
          console.error('Failed to parse threshold discounts:', e);
          thresholdsArray = [{ itemCount: 10, discount: 5 }];
        }
      } else if (Array.isArray(discountsData)) {
        thresholdsArray = discountsData;
      } else if (discountsData && discountsData.types) {
        thresholdsArray = discountsData.types;
      } else {
        thresholdsArray = [{ itemCount: 10, discount: 5 }];
      }
      
      // Create the working data structure - just the array, no selected property
      const workingData = thresholdsArray;

      // Create right-side container (second column)
      const rightColumn = document.createElement('div');
      rightColumn.className = 'price-options-container';
      rightColumn.style.flex = '1';
      rightColumn.style.display = 'flex';
      rightColumn.style.flexDirection = 'column';
      container.appendChild(rightColumn);
      
      function renderThresholds() {
        rightColumn.innerHTML = '';
        
        // Create each threshold row
        workingData.forEach((threshold, idx) => {
          const row = document.createElement('div');
          row.className = 'price-option-row';
          
          // Item count input
          const countInput = document.createElement('input');
          countInput.type = 'number';
          countInput.min = '1';
          countInput.value = threshold.itemCount;
          countInput.placeholder = 'Item count';
          countInput.addEventListener('input', e => {
            workingData[idx].itemCount = parseInt(e.target.value, 10) || 1;
            onChange(workingData);
          });
          
          // Discount percentage input
          const discountInput = document.createElement('input');
          discountInput.type = 'number';
          discountInput.min = '0';
          discountInput.max = '100';
          discountInput.value = threshold.discount;
          discountInput.placeholder = 'Discount %';
          discountInput.addEventListener('input', e => {
            workingData[idx].discount = parseFloat(e.target.value) || 0;
            onChange(workingData);
          });
          
          // Controls container for buttons
          const controlsContainer = document.createElement('div');
          controlsContainer.className = 'controls-container';
          
          // Delete button
          const deleteBtn = document.createElement('button');
          deleteBtn.textContent = '−';
          deleteBtn.className = 'price-option-delete';
          deleteBtn.addEventListener('click', () => {
            workingData.splice(idx, 1);
            onChange(workingData);
            renderThresholds();
          });
          
          // Up arrow button
          const upBtn = document.createElement('button');
          upBtn.innerHTML = '&#9650;'; // Up arrow symbol
          upBtn.className = 'price-option-arrow';
          upBtn.disabled = idx === 0;
          upBtn.addEventListener('click', () => {
            if (idx > 0) {
              // Swap current item with the one above it
              [workingData[idx-1], workingData[idx]] = 
              [workingData[idx], workingData[idx-1]];
              onChange(workingData);
              renderThresholds();
            }
          });
          
          // Down arrow button
          const downBtn = document.createElement('button');
          downBtn.innerHTML = '&#9660;'; // Down arrow symbol
          downBtn.className = 'price-option-arrow';
          downBtn.disabled = idx === workingData.length - 1;
          downBtn.addEventListener('click', () => {
            if (idx < workingData.length - 1) {
              // Swap current item with the one below it
              [workingData[idx], workingData[idx+1]] = 
              [workingData[idx+1], workingData[idx]];
              onChange(workingData);
              renderThresholds();
            }
          });
          
          // Add buttons to controls container
          controlsContainer.appendChild(deleteBtn);
          controlsContainer.appendChild(upBtn);
          controlsContainer.appendChild(downBtn);
          
          // Add all elements to row
          row.appendChild(countInput);
          row.appendChild(discountInput);
          row.appendChild(controlsContainer);
          rightColumn.appendChild(row);
        });
        
        // Add button row
        const addBtnRow = document.createElement('div');
        addBtnRow.style.textAlign = 'right';
        addBtnRow.style.marginTop = '5px';
        
        const addBtn = document.createElement('button');
        addBtn.textContent = '+';
        addBtn.className = 'add-button';
        addBtn.style.marginTop = '0';
        addBtn.addEventListener('click', () => {
          // Calculate a sensible next threshold based on the last one
          const lastThreshold = workingData.length > 0 ? workingData[workingData.length - 1] : null;
          const newItemCount = lastThreshold ? lastThreshold.itemCount + 5 : 10;
          const newDiscount = lastThreshold ? Math.min(lastThreshold.discount + 5, 100) : 5;
          
          workingData.push({ itemCount: newItemCount, discount: newDiscount });
          onChange(workingData);
          renderThresholds();
        });
        
        addBtnRow.appendChild(addBtn);
        rightColumn.appendChild(addBtnRow);
      }
      
      // Initial render
      renderThresholds();
      
      return container;
    };
  }
  
  // Try to modify the createOptionElement function
  let attempts = 0;
  const maxAttempts = 50;
  
  function attemptModification() {
    if (typeof createOptionElement === 'function') {
      modifyCreateOptionElement();
    } else if (attempts < maxAttempts) {
      attempts++;
      setTimeout(attemptModification, 100);
    } else {
      console.error('Failed to modify createOptionElement function: not found after ' + maxAttempts + ' attempts');
    }
  }
  
  // Start the attempt process
  attemptModification();
});

// Create a custom function for the max addons field with a checkbox for unlimited
function createMaxAddonsField(opt) {
  const container = document.createElement('div');
  container.className = 'field-group';
  
  // Create label
  const label = document.createElement('label');
  label.textContent = 'Max Addons:';
  container.appendChild(label);
  
  // Create a wrapper div for the input and checkbox
  const inputWrapper = document.createElement('div');
  inputWrapper.style.display = 'flex';
  inputWrapper.style.alignItems = 'center';
  inputWrapper.style.flex = '1';
  
  // Create number input
  const input = document.createElement('input');
  input.type = 'number';
  input.min = '1';
  input.style.flex = '1';
  input.placeholder = 'Maximum allowed addons';
  
  // Determine initial state
  const isUnlimited = opt.maxAddons === -1 || opt.maxAddons === undefined;
  
  // Set initial value
  if (!isUnlimited) {
    input.value = opt.maxAddons;
  }
  
  // Add input change handler
  input.addEventListener('input', e => {
    const val = parseInt(e.target.value, 10) || 1;
    opt.maxAddons = val;
    saveData();
  });
  
  // Create checkbox container
  const checkboxContainer = document.createElement('div');
  checkboxContainer.style.marginLeft = '10px';
  checkboxContainer.style.display = 'flex';
  checkboxContainer.style.alignItems = 'center';
  
  // Create checkbox
  const checkbox = document.createElement('input');
  checkbox.type = 'checkbox';
  checkbox.checked = isUnlimited;
  checkbox.id = 'unlimited-addons-' + Math.random().toString(36).substring(2, 9);
  checkbox.style.marginRight = '5px';
  
  // Initial state of input based on checkbox
  input.disabled = checkbox.checked;
  
  // Create checkbox label
  const checkboxLabel = document.createElement('label');
  checkboxLabel.htmlFor = checkbox.id;
  checkboxLabel.textContent = 'Unlimited';
  checkboxLabel.style.marginBottom = '0';
  
  // Add checkbox change handler
  checkbox.addEventListener('change', e => {
    const isUnlimited = e.target.checked;
    input.disabled = isUnlimited;
    
    if (isUnlimited) {
      input.value = '';
      opt.maxAddons = -1;
    } else {
      input.value = input.value || '1';
      opt.maxAddons = parseInt(input.value, 10) || 1;
    }
    saveData();
  });
  
  // Assemble the elements
  checkboxContainer.appendChild(checkbox);
  checkboxContainer.appendChild(checkboxLabel);
  
  inputWrapper.appendChild(input);
  inputWrapper.appendChild(checkboxContainer);
  container.appendChild(inputWrapper);
  
  return container;
}

// Create a global registry for max group items UI elements
if (!window.groupMaxItemsUIRegistry) window.groupMaxItemsUIRegistry = {};

// Modify the createMaxGroupItemsField function to register in the registry
function createMaxGroupItemsField(addon, fIdx, oIdx, aIdx, groupName) {
  const container = document.createElement('div');
  container.className = 'field-group';
  
  // Create label
  const label = document.createElement('label');
  label.textContent = 'Max Group Items:';
  container.appendChild(label);
  
  // Create a wrapper div for the input and checkbox
  const inputWrapper = document.createElement('div');
  inputWrapper.style.display = 'flex';
  inputWrapper.style.alignItems = 'center';
  inputWrapper.style.flex = '1';
  
  // Create number input
  const input = document.createElement('input');
  input.type = 'number';
  input.min = '0';
  input.style.flex = '1';
  input.placeholder = 'Maximum allowed items';
  
  // Determine initial state
  const isUnlimited = addon.maxGroupItems === -1 || addon.maxGroupItems === undefined;
  
  // Set initial value
  if (!isUnlimited) {
    input.value = addon.maxGroupItems;
  }
  
  // Create checkbox container
  const checkboxContainer = document.createElement('div');
  checkboxContainer.style.marginLeft = '10px';
  checkboxContainer.style.display = 'flex';
  checkboxContainer.style.alignItems = 'center';
  
  // Create checkbox
  const checkbox = document.createElement('input');
  checkbox.type = 'checkbox';
  checkbox.checked = isUnlimited;
  checkbox.id = 'unlimited-group-items-' + Math.random().toString(36).substring(2, 9);
  checkbox.style.marginRight = '5px';
  
  // Initial state of input based on checkbox
  input.disabled = checkbox.checked;
  
  // Create checkbox label
  const checkboxLabel = document.createElement('label');
  checkboxLabel.htmlFor = checkbox.id;
  checkboxLabel.textContent = 'Unlimited';
  checkboxLabel.style.marginBottom = '0';
  
  // Create a render function to update this field
  function renderMaxItems(newValue) {
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
    
    // Update the data model too
    addon.maxGroupItems = newValue;
  }
  
  // Register in global registry if this is part of a group
  if (groupName && groupName.trim()) {
    // Initialize the group if needed
    if (!window.groupMaxItemsUIRegistry[groupName]) {
      window.groupMaxItemsUIRegistry[groupName] = [];
    }
    
    // Remove any existing entries for this addon
    window.groupMaxItemsUIRegistry[groupName] = 
      window.groupMaxItemsUIRegistry[groupName].filter(ui => 
        !(ui.featureIdx === fIdx && 
          ui.optionIdx === oIdx && 
          ui.addonIdx === aIdx));
    
    // Add this UI to the registry
    window.groupMaxItemsUIRegistry[groupName].push({
      featureIdx: fIdx,
      optionIdx: oIdx,
      addonIdx: aIdx,
      renderFunction: renderMaxItems
    });
  }
  
  // Modified input change handler
  input.addEventListener('input', e => {
    let newValue;
    
    if (e.target.value === '') {
      newValue = 0; // Our special "not set" value
    } else {
      newValue = parseInt(e.target.value, 10);
      if (isNaN(newValue)) newValue = 0;
    }
    
    addon.maxGroupItems = newValue;
    
    // If part of a group, sync with other addons
    if (groupName && groupName.trim()) {
      synchronizeGroupMaxItems(fIdx, oIdx, aIdx, groupName, newValue);
    } else {
      saveData();
    }
  });
  
  // Modified checkbox change handler
  checkbox.addEventListener('change', e => {
    const isUnlimited = e.target.checked;
    input.disabled = isUnlimited;
    
    let newValue;
    if (isUnlimited) {
      input.value = '';
      newValue = -1;
    } else {
      if (input.value === '') {
        newValue = 0;
      } else {
        newValue = parseInt(input.value, 10);
        if (isNaN(newValue)) newValue = 0;
      }
    }
    
    addon.maxGroupItems = newValue;
    
    // If part of a group, sync with other addons
    if (groupName && groupName.trim()) {
      synchronizeGroupMaxItems(fIdx, oIdx, aIdx, groupName, newValue);
    } else {
      saveData();
    }
  });
  
  // Assemble the elements
  checkboxContainer.appendChild(checkbox);
  checkboxContainer.appendChild(checkboxLabel);
  
  inputWrapper.appendChild(input);
  inputWrapper.appendChild(checkboxContainer);
  container.appendChild(inputWrapper);
  
  return container;
}

/**************************************************************
 * 10) clearSchemaValues — preserve dropdown.types
 **************************************************************/
function clearSchemaValues(node){
  if (!node || typeof node!=='object') return;
  if ('ui_type' in node && 'value' in node){
    switch(node.ui_type){
      case 'boolean':   node.value=false; break;
      case 'int-float': node.value=0;     break;
      case 'date':      node.value='';    break;
      case 'array':
        if (node.value && Array.isArray(node.value.types))
          node.value.selected=0;
        break;
      default:          node.value='';    break;
    }
    return;
  }
  if (Array.isArray(node)){
    node.forEach(clearSchemaValues);
    return;
  }
  for (const k in node){
    if (!node.hasOwnProperty(k)) continue;
    clearSchemaValues(node[k]);
  }
}

/**************************************************************
 * 11) Inline Add-Option Flow
 **************************************************************/
function showNewOptionForm(fIdx) {
  const featEls = document.querySelectorAll('.feature'),
         featEl = featEls[fIdx];
  if (featEl.querySelector('#new-option-form')) return;

  // figure out which feature schema to use
  const thisFeat = window.pricingData.features[fIdx];
  let originFeatIdx = fIdx;
  if (thisFeat.link_name) {
    const found = window.pricingData.features
      .findIndex(f=>f.featureName===thisFeat.link_name);
    if (found!==-1) originFeatIdx = found;
  }
  const originFeat = window.pricingData.features[originFeatIdx];

  const isRecurring = !!thisFeat.recurring;
  const defaultIntervalSchema = {
    level:'admin', ui_type:'array',
    value:{
      types:['day','week','month','year','none'],
      selected:4
    }
  };

  const orow = featEl.querySelector('.add-option-row'),
        form = document.createElement('div');
  form.id = 'new-option-form';
  form.className = 'option new-feature-form fade-in';

  // Schema dropdown
  const schemaG   = document.createElement('div');
  schemaG.className = 'field-group';
  const schemaLbl = document.createElement('label');
  schemaLbl.textContent = 'Schema:';
  const schemaSel = document.createElement('select');
  const defOpt    = document.createElement('option');
  defOpt.value    = '__default__';
  defOpt.textContent = 'Default';
  schemaSel.appendChild(defOpt);
  originFeat.options.forEach((opt,i)=>{
    const o = document.createElement('option');
    o.value       = i;
    o.textContent = opt.optionName||`Option ${i+1}`;
    schemaSel.appendChild(o);
  });
  schemaG.append(schemaLbl, schemaSel);
  form.append(schemaG);

  let tempName='', tempDesc='';
  form.appendChild(createFieldGroup(
    'optionName','text',
    ()=>tempName, v=>tempName=v,
    'Enter option name...'
  ));
  form.appendChild(createFieldGroup(
    'description','long-text',
    ()=>tempDesc, v=>tempDesc=v,
    'Description...'
  ));

  const btnRow = document.createElement('div');
  btnRow.className = 'button-row';
  const createBtn = document.createElement('button');
  createBtn.textContent = 'Create Option';
  createBtn.className = 'add-button';
  const cancelBtn = document.createElement('button');
  cancelBtn.textContent = 'Cancel';
  cancelBtn.className = 'delete-button';
  btnRow.append(createBtn, cancelBtn);
  form.append(btnRow);

  orow.parentNode.insertBefore(form, orow);
  expandFeature(featEl);
  const content = featEl.querySelector('.feature-content');
  content.style.maxHeight = content.scrollHeight + 'px';

  cancelBtn.addEventListener('click', () => form.remove());

  createBtn.addEventListener('click', () => {
    if (!tempName.trim()) {
      alert('Option name is required.');
      return;
    }

    let newO;
    if (schemaSel.value==='__default__') {
      newO = {
        optionName:'', description:{text:''},
        interval: isRecurring
          ? JSON.parse(JSON.stringify(defaultIntervalSchema))
          : { level:'admin', ui_type:'array', value:{types:['none'],selected:0} },
        // Include price options in pricing type options
        pricingType:{ level:'admin', ui_type:'array', value:{types:['static price','price range','price options'],selected:0} },
        priceFloor:0, priceCeiling:0, staticPrice:0,
        // Add default priceOptions structure
        priceOptions: {
          level: 'admin',
          ui_type: 'array-obj',
          value: {
            types: [{ label: 'Default', price: 0 }]
          }
        },
        // Add thresholdDiscounts fields
        enableThresholdDiscounts: false,
        thresholdDiscounts: {
          level: 'admin',
          ui_type: 'array-obj',
          value: {
            types: [{ itemCount: 10, discount: 5 }]
          }
        },
        maxAddons: -1, // -1 means unlimited
        optionMetric:'', addons:[], link_name:''
      };
    } else {
      // CLONE structure, then WIPE ANY WRAPPERS back to defaults:
      const tpl = originFeat.options[+schemaSel.value];
      newO = JSON.parse(JSON.stringify(tpl));
      clearSchemaValues(newO);
    
      // zero out the few fields we want blank:
      newO.link_name       = tpl.optionName;
      newO.optionName      = '';
      newO.description.text= '';
      newO.priceFloor      = 0;
      newO.priceCeiling    = 0;
      newO.staticPrice     = 0;
      newO.optionMetric    = '';
      newO.addons          = [];
      
      // Add thresholdDiscounts if it doesn't exist
      if (!newO.hasOwnProperty('enableThresholdDiscounts')) {
        newO.enableThresholdDiscounts = false;
      }
      
      if (!newO.hasOwnProperty('thresholdDiscounts')) {
        newO.thresholdDiscounts = {
          level: 'admin',
          ui_type: 'array-obj',
          value: {
            types: [{ itemCount: 10, discount: 5 }]
          }
        };
      }
      
      if (isRecurring) {
        newO.interval = JSON.parse(JSON.stringify(defaultIntervalSchema));
      }
      
      // Make sure cloned options have the price options in the dropdown
      if (newO.pricingType && newO.pricingType.value && Array.isArray(newO.pricingType.value.types)) {
        if (!newO.pricingType.value.types.includes('price options')) {
          newO.pricingType.value.types.push('price options');
        }
      }
      
      // Add default priceOptions if not present in the clone
      if (!newO.priceOptions) {
        newO.priceOptions = {
          level: 'admin',
          ui_type: 'array-obj',
          value: {
            types: [{ label: 'Default', price: 0 }],
            selected: 0
          }
        };
      }
    }

    newO.optionName      = tempName;
    newO.description.text= tempDesc;

    window.pricingData.features[fIdx].options.push(newO);
    saveData();
    form.remove();

    // re-render inline
    const availOpt = [], availAdd = [];
    window.pricingData.features.forEach(f=>{
      f.options.forEach(o=>{
        if (o.optionMetric && !availOpt.includes(o.optionMetric))
          availOpt.push(o.optionMetric);
        o.addons.forEach(a=>{
          if (a.addOnMetric && !availAdd.includes(a.addOnMetric))
            availAdd.push(a.addOnMetric);
        });
      });
    });

    const newIdx = window.pricingData.features[fIdx].options.length - 1;
    const newEl = createOptionElement(
      fIdx, newIdx, newO,
      availOpt, availAdd,
      featEl.querySelector('input[type=checkbox]')
    );
    newEl.classList.add('fade-in');
    orow.parentNode.insertBefore(newEl, orow);
    expandFeature(featEl);
    content.style.maxHeight = content.scrollHeight + 'px';
    scrollIntoViewWithOffset(newEl);
    
    // Force expand the new option for visibility
    setTimeout(() => {
      toggleExpandCollapse(newEl, true);
    }, 100);
  });
}

/**************************************************************
 * 12) Inline Add-Addon Flow
 **************************************************************/
function showNewAddonForm(fIdx, oIdx) {
  const featEls = document.querySelectorAll('.feature'),
         optEls = featEls[fIdx].querySelectorAll('.option'),
         optEl  = optEls[oIdx];
  if (optEl.querySelector('#new-addon-form')) return;

  // determine feature schema origin
  const parentFeat = window.pricingData.features[fIdx];
  let originFeatIdx = fIdx;
  if (parentFeat.link_name) {
    const fnd = window.pricingData.features.findIndex(f=>f.featureName===parentFeat.link_name);
    if (fnd!==-1) originFeatIdx = fnd;
  }
  const originFeat = window.pricingData.features[originFeatIdx];

  // determine option schema origin
  const thisOpt = parentFeat.options[oIdx];
  let originOptIdx = oIdx;
  if (thisOpt.link_name) {
    const fndO = originFeat.options.findIndex(o=>o.optionName===thisOpt.link_name);
    if (fndO!==-1) originOptIdx = fndO;
  }
  const originOpt = originFeat.options[originOptIdx];

  // Find the add-addon row in the content area
  const addAddonRow = optEl.querySelector('.add-addon-row');

  const form = document.createElement('div');
  form.id = 'new-addon-form';
  form.className = 'addon new-feature-form fade-in';

  // Schema dropdown from originOpt.addons
  const schemaG = document.createElement('div');
  schemaG.className='field-group';
  const schemaLbl = document.createElement('label');
  schemaLbl.textContent='Schema:';
  const schemaSel = document.createElement('select');
  const defOpt = document.createElement('option');
  defOpt.value='__default__';
  defOpt.textContent='Default';
  schemaSel.appendChild(defOpt);
  originOpt.addons.forEach((a,i)=>{
    const o = document.createElement('option');
    o.value = i;
    o.textContent = a.addonName || `Addon ${i+1}`;
    schemaSel.appendChild(o);
  });
  schemaG.append(schemaLbl, schemaSel);
  form.append(schemaG);

  let tempName='', tempDesc='';
  form.appendChild(createFieldGroup(
    'addonName','text',
    ()=>tempName, v=>tempName=v,
    'Enter addon name...'
  ));
  form.appendChild(createFieldGroup(
    'description','long-text',
    ()=>tempDesc, v=>tempDesc=v,
    'Description...'
  ));

  const br = document.createElement('div');
  br.className='button-row';
  const createBtn = document.createElement('button');
  createBtn.textContent='Create Addon';
  createBtn.className='add-button';
  const cancelBtn = document.createElement('button');
  cancelBtn.textContent='Cancel';
  cancelBtn.className='delete-button';
  br.append(createBtn, cancelBtn);
  form.append(br);

  // Find parent containers before inserting the form
  const featureEl = featEls[fIdx];
  const optionContent = optEl.querySelector('.content');
  const featureContent = featureEl.querySelector('.feature-content');
  
  // Set large maxHeight on containers to allow for expansion
  if (optionContent && optionContent.classList.contains('open')) {
    optionContent.style.maxHeight = '9999px';
  }
  
  if (featureContent && featureContent.classList.contains('open')) {
    featureContent.style.maxHeight = '9999px';
  }
  
  // Insert before the add-addon row
  addAddonRow.parentNode.insertBefore(form, addAddonRow);
  
  // Force reflow
  void form.offsetHeight;
  
  // Make sure both the option and feature are expanded
  if (optionContent && !optionContent.classList.contains('open')) {
    toggleExpandCollapse(optEl, true);
  }
  
  // Properly adjust container heights with delay to ensure form is rendered
  setTimeout(() => {
    // Force measurement and update of option content height
    if (optionContent && optionContent.classList.contains('open')) {
      optionContent.style.maxHeight = (optionContent.scrollHeight + 500) + 'px';
    }
    
    // Force measurement and update of feature content height
    if (featureContent && featureContent.classList.contains('open')) {
      featureContent.style.maxHeight = (featureContent.scrollHeight + 500) + 'px';
    }
    
    // Make sure add-addon button is visible by scrolling to it
    setTimeout(() => {
      scrollIntoViewWithOffset(addAddonRow);
    }, 100);
  }, 50);

  cancelBtn.addEventListener('click', ()=>{
    form.remove();
    // Update heights after removing form with a delay
    setTimeout(() => {
      if (optionContent && optionContent.classList.contains('open')) {
        optionContent.style.maxHeight = optionContent.scrollHeight + 'px';
      }
      
      if (featureContent && featureContent.classList.contains('open')) {
        featureContent.style.maxHeight = featureContent.scrollHeight + 'px';
      }
    }, 50);
  });

  createBtn.addEventListener('click', ()=>{
    if (!tempName.trim()) {
      alert('Addon name is required.');
      return;
    }
  
    let newA;
    if (schemaSel.value==='__default__') {
      newA = {
        addonName:'', description:{text:''}, addOnMetric:'',
        floorPriceMod:0, ceilingPriceMod:0,
        pricingType:{level:'admin',ui_type:'array',value:{types:['static price','price range'],selected:0}},
        priceModifierType:{level:'admin',ui_type:'array',value:{types:['add','multiply'],selected:0}},
        staticPriceMod:0,
        // Add these new fields (but not the UI flag)
        groupName: '',
        groupThresholdDiscounts: {
          level: 'admin',
          ui_type: 'array-obj',
          value: {
            types: [{ itemCount: "", discount: "" }]
          }
        },
        enableGrouping: false,
        maxGroupItems: -1,
        link_name:''
      };
    } else {
      // CLONE & WIPE wrapper values:
      const tpl = originOpt.addons[+schemaSel.value];
      newA = JSON.parse(JSON.stringify(tpl));
      clearSchemaValues(newA);
  
      // then zero out the fields we want blank:
      newA.link_name        = tpl.addonName;
      newA.addonName        = '';
      newA.description      = { text:'' };
      newA.floorPriceMod    = 0;
      newA.ceilingPriceMod  = 0;
      newA.staticPriceMod   = 0;
      newA.addOnMetric      = '';
    }
    newA.addonName = tempName;
    newA.description.text = tempDesc;
  
    window.pricingData.features[fIdx].options[oIdx].addons.push(newA);
    saveData();
    form.remove();
  
    // re-render inline
    const availAdd = [];
    window.pricingData.features[fIdx].options[oIdx].addons.forEach(a=>{
      if (a.addOnMetric && !availAdd.includes(a.addOnMetric))
        availAdd.push(a.addOnMetric);
    });
    
    const featureRecCheckbox = featEls[fIdx].querySelector('input[type=checkbox]');
    const newAi = window.pricingData.features[fIdx].options[oIdx].addons.length - 1;
    const newAddEl = createAddonElement(
      fIdx, oIdx, newA, availAdd, featureRecCheckbox, newAi
    );
    newAddEl.classList.add('fade-in');
    
    // Get references to the necessary elements
    const addonContent = optEl.querySelector('.addon-content-area');
    const addAddonRow = optEl.querySelector('.add-addon-row');
    const optionContent = optEl.querySelector('.content');
    const featureEl = optEl.closest('.feature');
    const featureContent = featureEl ? featureEl.querySelector('.feature-content') : null;
    
    // Only proceed if we have all the necessary elements
    if (addonContent && addAddonRow) {
      // Important: Set extraordinarily large maxHeights on all containers first
      if (optionContent && optionContent.classList.contains('open')) {
        optionContent.style.maxHeight = '99999px';
      }
      
      if (featureContent && featureContent.classList.contains('open')) {
        featureContent.style.maxHeight = '99999px';
      }
      
      // Remove the add-addon-row
      addAddonRow.parentNode.removeChild(addAddonRow);
      
      // Add the new addon element
      addonContent.appendChild(newAddEl);
      
      // Now add the add-addon-row back at the end
      addonContent.appendChild(addAddonRow);
      
      // Use setTimeout to allow the DOM to update
      setTimeout(() => {
        // Expand the new addon
        toggleExpandCollapse(newAddEl, true);
        
        // Force reflow to ensure all elements have been properly measured
        void featureContent.offsetHeight;
        void optionContent.offsetHeight;
        void newAddEl.offsetHeight;
        
        // Update container heights with delay
        setTimeout(() => {
          // Use recursive function to properly adjust all heights
          function adjustAllContainers() {
            // Start with addon content
            const addonContents = optionContent.querySelectorAll('.addon > .content.open');
            addonContents.forEach(addonContent => {
              addonContent.style.maxHeight = (addonContent.scrollHeight + 100) + 'px';
            });
            
            // Then adjust option content to fit all addons
            if (optionContent.classList.contains('open')) {
              optionContent.style.maxHeight = (optionContent.scrollHeight + 500) + 'px';
            }
            
            // Finally adjust feature content to fit everything
            if (featureContent && featureContent.classList.contains('open')) {
              featureContent.style.maxHeight = (featureContent.scrollHeight + 800) + 'px';
            }
          }
          
          // Run the adjustment multiple times with increasing delays
          adjustAllContainers();
          setTimeout(adjustAllContainers, 100);
          setTimeout(adjustAllContainers, 300);
          setTimeout(adjustAllContainers, 600);
        }, 150);
      }, 100);
    }
  });
}

/**************************************************************
 * 13) Addon Element
 **************************************************************/
function createAddonElement(fIdx, oIdx, addon, availAdd, featureRecCheckbox, aIdx) {

  const wrap = document.createElement('div');
  wrap.className = 'addon';
  
  // Create header
  const header = document.createElement('div');
  header.className = 'header addon-header';
  
  // Left section of header
  const headerLeft = document.createElement('div');
  headerLeft.className = 'header-left';
  const addonTitle = document.createElement('span');
  addonTitle.className = 'addon-title';
  addonTitle.textContent = addon.addonName || 'New Addon';
  headerLeft.appendChild(addonTitle);
  
  // Right section of header
  const headerRight = document.createElement('div');
  headerRight.className = 'header-right';
  
  // Toggle indicator
  const toggle = document.createElement('span');
  toggle.className = 'toggle-indicator';
  toggle.textContent = '+';
  
  // Clone button
  const cloneBtn = document.createElement('button');
  cloneBtn.className = 'clone-button';
  cloneBtn.textContent = 'Clone';
  cloneBtn.addEventListener('click', e => {
    e.stopPropagation();
    confirmClone(addon.addonName || 'this addon', () => {
      // Clone the addon
      const addonClone = JSON.parse(JSON.stringify(addon));
      addonClone.addonName += ' (Copy)';
      
      // Add to data structure
      window.pricingData.features[fIdx].options[oIdx].addons.push(addonClone);
      saveData();
      
      // Find parent option and feature elements
      const optionEl = wrap.closest('.option');
      const featureEl = optionEl.closest('.feature');
      
      // Pre-expand all containers
      const optionContent = optionEl.querySelector('.content');
      const featureContent = featureEl.querySelector('.feature-content');
      
      if (optionContent && optionContent.classList.contains('open')) {
        optionContent.style.maxHeight = '99999px';
      }
      
      if (featureContent && featureContent.classList.contains('open')) {
        featureContent.style.maxHeight = '99999px';
      }
      
      // Create and add the new element
      const newAddonIdx = window.pricingData.features[fIdx].options[oIdx].addons.length - 1;
      const newAddonEl = createAddonElement(
        fIdx, oIdx, addonClone, availAdd, featureRecCheckbox, newAddonIdx
      );
      newAddonEl.classList.add('fade-in');
      
      // Add to DOM after the current addon
      wrap.parentNode.insertBefore(newAddonEl, wrap.nextSibling);
      
      // Use a sequence of timers to ensure proper expansion
      setTimeout(() => {
        // First expand the new addon
        toggleExpandCollapse(newAddonEl, true);
        
        // Scroll to the new addon
        scrollIntoViewWithOffset(newAddonEl);
        
        // Update all containers
        setTimeout(() => {
          updateAllOpenContainers();
          
          // Multiple additional update passes
          setTimeout(() => updateAllOpenContainers(), 200);
          setTimeout(() => updateAllOpenContainers(), 500);
        }, 100);
      }, 100);
    });
  });
  
  // Delete button
  const deleteBtn = document.createElement('button');
  deleteBtn.className = 'delete-button';
  deleteBtn.textContent = 'Delete';
  deleteBtn.addEventListener('click', e => {
    e.stopPropagation();
    confirmDeletion(addon.addonName || 'this addon', () => {
      wrap.classList.add('fade-out');
      wrap.addEventListener('animationend', () => {
        // Find parent option and feature, then get current indexes
        const optionEl = wrap.closest('.option');
        const featureEl = optionEl ? optionEl.closest('.feature') : null;
        
        if (featureEl && optionEl) {
          const currentFIdx = Array.from(document.querySelectorAll('.feature')).indexOf(featureEl);
          const currentOIdx = Array.from(featureEl.querySelectorAll('.option')).indexOf(optionEl);
          const currentAIdx = Array.from(optionEl.querySelectorAll('.addon')).indexOf(wrap);
          
          if (currentFIdx !== -1 && currentOIdx !== -1 && currentAIdx !== -1) {
            // Use current indexes to find and remove the addon
            window.pricingData.features[currentFIdx].options[currentOIdx].addons.splice(currentAIdx, 1);
          }
        }
        saveData();
        
        // Remove from DOM
        wrap.remove();
        
        // Update parent container heights
        updateParentContainerHeights(wrap.parentNode);
      });
    });
  });
  
  headerRight.append(toggle, cloneBtn, deleteBtn);
  header.append(headerLeft, headerRight);
  wrap.appendChild(header);
  
  // Content area (collapsible)
  const content = document.createElement('div');
  content.className = 'content addon-content';
  content.style.maxHeight = '0px'; // Start collapsed
  wrap.appendChild(content);
  
  // Handle header click to expand/collapse
  header.addEventListener('click', e => {
    if (headerRight.contains(e.target)) return;
    toggleExpandCollapse(wrap);
  });
  
  // Create fields inside the content
  const contentInner = document.createElement('div');
  contentInner.className = 'content-inner';
  content.appendChild(contentInner);

  // Name field with update to header text
  const nameGroup = createFieldGroup(
    'addonName', 'text',
    () => addon.addonName,
    v => {
      recordNameChange(window.nameChanges.addons, [fIdx, oIdx, aIdx], addon.addonName, v);
      addon.addonName = v;
      addonTitle.textContent = v || 'New Addon';
    },
    'Enter addon name...'
  );
  contentInner.appendChild(nameGroup);
  
  // Description field
  contentInner.appendChild(createFieldGroup(
    'description', 'long-text',
    () => addon.description.text,
    v => addon.description.text = v,
    'Description...'
  ));
  
  // Add-on metric field
  contentInner.appendChild(createFieldGroup(
    'addOnMetric', 'text',
    () => addon.addOnMetric,
    v => addon.addOnMetric = v,
    'Enter addon metric...'
  ));

  // Pricing type dropdown
  const ptG = createFieldGroup(
    'pricingType', 'dropdown',
    () => addon.pricingType.value,
    v => addon.pricingType.value = v
  );
  contentInner.append(ptG);

  // Price fields
  const fpG = createFieldGroup(
    'floorPriceMod', 'number',
    () => addon.floorPriceMod,
    v => addon.floorPriceMod = v
  ),
  cpG = createFieldGroup(
    'ceilingPriceMod', 'number',
    () => addon.ceilingPriceMod,
    v => addon.ceilingPriceMod = v
  ),
  spG = createFieldGroup(
    'staticPriceMod', 'number',
    () => addon.staticPriceMod,
    v => addon.staticPriceMod = v
  );
  contentInner.append(fpG, cpG, spG);

  // Setup pricing type logic
  const ptSel = ptG.querySelector('select'),
        fpI   = fpG.querySelector('input'),
        cpI   = cpG.querySelector('input'),
        spI   = spG.querySelector('input');
        
  function applyA(){
    handlePricingTypeChange(fpI, cpI, spI, null, parseInt(ptSel.value, 10));
  }
  ptSel.addEventListener('change', applyA);
  applyA();
  
  // Price Modifier Type dropdown (add this BEFORE the grouping elements)
  const pmtG = createFieldGroup(
    'priceModifierType', 'dropdown',
    () => addon.priceModifierType.value,
    v => addon.priceModifierType.value = v
  );
  contentInner.append(pmtG);
  
  // NOW add the grouping elements AFTER the price modifier type
  
  // Group this addon checkbox (UI only)
  const groupAddonGroup = document.createElement('div');
  groupAddonGroup.className = 'field-group';
  
  const groupLabel = document.createElement('label');
  groupLabel.textContent = 'Group this Addon:';
  groupAddonGroup.appendChild(groupLabel);
  
  const groupCheckbox = document.createElement('input');
  groupCheckbox.type = 'checkbox';
  // Initialize checkbox based on whether groupName has a value
  groupCheckbox.checked = !!(addon.groupName && addon.groupName.trim());
  groupAddonGroup.appendChild(groupCheckbox);
  
  // Initialize groupName if not present
  if (!addon.groupName) {
    addon.groupName = '';
  }
  
  // Create group name field
  const groupNameGroup = createFieldGroup(
      'groupName', 'text',
      () => addon.groupName,
      v => {
          const oldGroupName = addon.groupName;
          addon.groupName = v;
          
          // Update registry registrations if group name changed
          if (oldGroupName !== v && window.groupThresholdUIRegistry) {
              if (oldGroupName && window.groupThresholdUIRegistry[oldGroupName]) {
                  // Remove from old group registry
                  window.groupThresholdUIRegistry[oldGroupName] = 
                      window.groupThresholdUIRegistry[oldGroupName].filter(ui => 
                          !(ui.featureIdx === fIdx && ui.optionIdx === oIdx && ui.addonIdx === aIdx));
              }
              
              // Add to new group registry if value is not empty
              if (v && v.trim()) {
                  if (!window.groupThresholdUIRegistry[v]) {
                      window.groupThresholdUIRegistry[v] = [];
                  }
                  
                  // Only add if not already present
                  const exists = window.groupThresholdUIRegistry[v].some(ui => 
                      ui.featureIdx === fIdx && ui.optionIdx === oIdx && ui.addonIdx === aIdx);
                  
                  if (!exists && groupThresholdDiscountsUI) {
                      window.groupThresholdUIRegistry[v].push({
                          featureIdx: fIdx,
                          optionIdx: oIdx,
                          addonIdx: aIdx,
                          uiElement: groupThresholdDiscountsUI,
                          renderFunction: groupThresholdDiscountsUI._renderFunction
                      });
                  }
              }
          }
          
          // Only hide fields if the checkbox is already unchecked
          if (!v.trim() && !groupCheckbox.checked) {
              groupNameGroup.style.display = 'none';
              groupThresholdDiscountsUI.style.display = 'none';
              maxGroupItemsGroup.style.display = 'none';
          }
      },
      'Enter group name...'
  );
  
  // Initialize groupThresholdDiscounts data if not present
  if (!addon.groupThresholdDiscounts) {
    addon.groupThresholdDiscounts = {
      level: 'admin',
      ui_type: 'array-obj',
      value: {
        types: [{ itemCount: "", discount: "" }]
      }
    };
  }

  // Initialize groupThresholdDiscounts data if not present
  if (!addon.groupThresholdDiscounts) {
    addon.groupThresholdDiscounts = {
      level: 'admin',
      ui_type: 'array-obj',
      value: {
        types: [{ itemCount: "", discount: "" }]
      }
    };
  }
  
  // Create the groupThresholdDiscounts UI first
  const groupThresholdDiscountsUI = createThresholdDiscountsUI(
      getThresholdData(addon.groupThresholdDiscounts),
      v => {
          // First update this addon's discounts
          if (!addon.groupThresholdDiscounts) {
              addon.groupThresholdDiscounts = {
                  level: 'admin',
                  ui_type: 'array-obj',
                  value: { types: v }
              };
          } else if (typeof addon.groupThresholdDiscounts === 'string') {
              // Convert from string to proper object if it was loaded from DB
              addon.groupThresholdDiscounts = {
                  level: 'admin',
                  ui_type: 'array-obj',
                  value: { types: v }
              };
          } else {
              addon.groupThresholdDiscounts.value = { types: v };
          }
          
          // Then synchronize with other addons in the same group
          if (addon.groupName && addon.groupName.trim()) {
              synchronizeGroupThresholdDiscounts(fIdx, oIdx, aIdx, addon.groupName, v);
          } else {
              // If no grouping, just save the current addon's data
              saveData();
          }
      },
      // Pass group info for UI registry - only if the addon is actually in a group
      addon.groupName && addon.groupName.trim() ? {
          groupName: addon.groupName,
          featureIdx: fIdx,
          optionIdx: oIdx,
          addonIdx: aIdx
      } : null
  );

  // Store the render function for later use
  groupThresholdDiscountsUI._renderFunction = groupThresholdDiscountsUI.querySelector('._render_function');

  // Create maxGroupItems field second
  const maxGroupItemsGroup = createMaxGroupItemsField(
      addon, fIdx, oIdx, aIdx, addon.groupName
  );

  // AFTER UI elements are created, add autocomplete to the group name
  const groupNameInput = groupNameGroup.querySelector('input[type="text"]');
  if (groupNameInput) {
      // Set a unique class to help identify this is an addon group name input
      groupNameInput.classList.add('addon-group-name-input');
      groupNameInput.dataset.fIdx = fIdx;
      groupNameInput.dataset.oIdx = oIdx;
      groupNameInput.dataset.aIdx = aIdx;
      
      // Add autocomplete with all references properly defined
      addGroupNameAutocomplete(
          groupNameInput, 
          fIdx, 
          oIdx, 
          aIdx, 
          addon,
          maxGroupItemsGroup,
          groupThresholdDiscountsUI
      );
      
      // Check for stored group name and show it as placeholder
      if (addon._storedGroupName && !addon.groupName) {
          groupNameInput.placeholder = `Previously: ${addon._storedGroupName}`;
      }
  }
  
  // Add all new UI elements to content in the desired order
  contentInner.appendChild(groupAddonGroup);
  contentInner.appendChild(groupNameGroup);
  contentInner.appendChild(maxGroupItemsGroup);
  contentInner.appendChild(groupThresholdDiscountsUI);
  
  // Set initial visibility based on checkbox state
  groupNameGroup.style.display = groupCheckbox.checked ? 'flex' : 'none';
  maxGroupItemsGroup.style.display = groupCheckbox.checked ? 'flex' : 'none';
  groupThresholdDiscountsUI.style.display = groupCheckbox.checked ? 'flex' : 'none';

  let previousGroupName = addon.groupName || '';

  // Add this helper function
  function getThresholdData(discountsData) {
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

  // Handle checkbox state change
  groupCheckbox.addEventListener('change', function(e) {
      addon.enableGrouping = e.target.checked;

      if (e.target.checked) {
          // When re-checked, first restore fields visibility
          groupNameGroup.style.display = 'flex';
          maxGroupItemsGroup.style.display = 'flex';
          groupThresholdDiscountsUI.style.display = 'flex';
          
          // Restore the previous group name if available
          if (addon._storedGroupName) {
              // Set the value in the data model
              addon.groupName = addon._storedGroupName;
              
              // Update the input field
              const groupNameInput = groupNameGroup.querySelector('input');
              if (groupNameInput) {
                  // Set the value programmatically
                  groupNameInput.value = addon._storedGroupName;
                  
                  // Force input event to trigger data synchronization
                  const inputEvent = new Event('input', { bubbles: true });
                  groupNameInput.dispatchEvent(inputEvent);
                  
                  // Force change event for good measure
                  const changeEvent = new Event('change', { bubbles: true });
                  groupNameInput.dispatchEvent(changeEvent);
              }
              
              // Restore threshold discounts if available
              if (addon._storedGroupThresholdDiscounts) {
                  addon.groupThresholdDiscounts = JSON.parse(
                      JSON.stringify(addon._storedGroupThresholdDiscounts)
                  );
                  
                  // Update the threshold UI with the stored data
                  if (groupThresholdDiscountsUI._renderFunction) {
                      const discountsData = getThresholdData(addon._storedGroupThresholdDiscounts);
                      groupThresholdDiscountsUI._renderFunction(discountsData);
                  }
              }
              
              // Restore max group items if available
              if (addon._storedMaxGroupItems !== undefined) {
                  addon.maxGroupItems = addon._storedMaxGroupItems;
                  
                  // Update the max items UI
                  const input = maxGroupItemsGroup.querySelector('input[type="number"]');
                  const checkbox = maxGroupItemsGroup.querySelector('input[type="checkbox"]');
                  
                  if (input && checkbox) {
                      if (addon._storedMaxGroupItems === -1) {
                          checkbox.checked = true;
                          input.disabled = true;
                          input.value = '';
                      } else {
                          checkbox.checked = false;
                          input.disabled = false;
                          input.value = addon._storedMaxGroupItems.toString();
                      }
                  }
              }
          }
      } else {
          // When unchecked, store current values but hide UI
          const groupNameInput = groupNameGroup.querySelector('input');
          if (groupNameInput && groupNameInput.value) {
              addon._storedGroupName = groupNameInput.value;
          }
          
          // Store threshold discounts
          if (addon.groupThresholdDiscounts) {
              addon._storedGroupThresholdDiscounts = JSON.parse(
                  JSON.stringify(addon.groupThresholdDiscounts)
              );
          }
          
          // Store max items
          if (addon.maxGroupItems !== -1) {
              addon._storedMaxGroupItems = addon.maxGroupItems;
          }
          
          // Clear the actual data values (not just hiding UI)
          addon.groupName = '';
          addon.groupThresholdDiscounts = { 
              level: 'admin', 
              ui_type: 'array-obj', 
              value: { types: [{ itemCount: "", discount: "" }] } 
          };
          addon.maxGroupItems = -1;
          
          // Hide all group-related fields
          groupNameGroup.style.display = 'none';
          maxGroupItemsGroup.style.display = 'none';
          groupThresholdDiscountsUI.style.display = 'none';
      }

      saveData();

      // Update container heights
      setTimeout(() => {
          updateAllOpenContainers();
      }, 100);
  });

  // Add dynamic fields
  createDynamicFields(addon, contentInner, [
    'addonName', 'description', 'addOnMetric',
    'pricingType', 'floorPriceMod', 'ceilingPriceMod', 'staticPriceMod',
    'priceModifierType', 'link_name', 'groupName', 'groupThresholdDiscounts',
    'enableGrouping', 'maxGroupItems'
  ]);

  return wrap;
}

function synchronizeGroupMaxItems(currentFeatureIdx, currentOptionIdx, currentAddonIdx, groupName, maxGroupItems) {
  // Skip if no group name
  if (!groupName || !groupName.trim()) return;
  
  // 1. Update the data structures for all addons in the group
  window.pricingData.features.forEach((feature, featureIdx) => {
    feature.options.forEach((option, optionIdx) => {
      option.addons.forEach((addon, addonIdx) => {
        // Update any addon with the matching group name
        if (addon.groupName === groupName) {
          // Skip the current addon that triggered the sync
          if (featureIdx === currentFeatureIdx && 
              optionIdx === currentOptionIdx && 
              addonIdx === currentAddonIdx) {
            return;
          }
          
          // Update the maxGroupItems value
          addon.maxGroupItems = maxGroupItems;
        }
      });
    });
  });
  
  // 2. Save data after all updates
  saveData();
  
  // 3. Update the UI for all group members - PREFERRED APPROACH: using the registry
  if (window.groupMaxItemsUIRegistry && window.groupMaxItemsUIRegistry[groupName]) {
    window.groupMaxItemsUIRegistry[groupName].forEach(uiInfo => {
      // Skip the current addon to avoid circular updates
      if (uiInfo.featureIdx === currentFeatureIdx && 
          uiInfo.optionIdx === currentOptionIdx && 
          uiInfo.addonIdx === currentAddonIdx) {
        return;
      }
      
      // Call the render function to update the UI
      if (uiInfo.renderFunction) {
        try {
          requestAnimationFrame(() => {
            uiInfo.renderFunction(maxGroupItems);
          });
        } catch (err) {
          // Silent error handling
        }
      }
    });
  } 
  // FALLBACK: If registry is not available or empty for this group, use direct DOM approach
  else {
    document.querySelectorAll('.addon').forEach(addonEl => {
      // Only process addons that are expanded so we can access their fields
      const addonContent = addonEl.querySelector('.content');
      if (!addonContent || !addonContent.classList.contains('open')) return;
      
      // Find the group name input field specifically
      const groupNameField = Array.from(addonContent.querySelectorAll('.field-group')).find(field => {
        const label = field.querySelector('label');
        return label && label.textContent === 'Group Name:';
      });
      
      // If found, check if this addon is part of our target group
      if (groupNameField) {
        const groupNameInput = groupNameField.querySelector('input[type="text"]');
        if (groupNameInput && groupNameInput.value === groupName) {
          // Now find the max group items field
          const maxGroupItemsField = Array.from(addonContent.querySelectorAll('.field-group')).find(field => {
            const label = field.querySelector('label');
            return label && label.textContent === 'Max Group Items:';
          });
          
          if (maxGroupItemsField) {
            const input = maxGroupItemsField.querySelector('input[type="number"]');
            const checkbox = maxGroupItemsField.querySelector('input[type="checkbox"]');
            
            if (input && checkbox) {
              // Update the UI elements
              if (maxGroupItems === -1) {
                checkbox.checked = true;
                input.disabled = true;
                input.value = '';
              } else if (maxGroupItems === 0) {
                // For our special "empty/not set" case
                checkbox.checked = false;
                input.disabled = false;
                input.value = ''; // Display as empty
              } else {
                checkbox.checked = false;
                input.disabled = false;
                input.value = maxGroupItems.toString();
              }
            }
          }
        }
      }
    });
  }
  
  // 4. Update container heights to ensure proper display
  setTimeout(() => {
    updateAllOpenContainers();
  }, 100);
}

/**************************************************************
 * 14) Option Element
 **************************************************************/
function createOptionElement(fIdx, oIdx, opt, availOpt, availAdd, featureRecCheckbox){
  const wrap = document.createElement('div');
  wrap.className = 'option';
  
  // Create header
  const header = document.createElement('div');
  header.className = 'header option-header';
  
  // Left section of header
  const headerLeft = document.createElement('div');
  headerLeft.className = 'header-left';
  const optionTitle = document.createElement('span');
  optionTitle.className = 'option-title';
  optionTitle.textContent = opt.optionName || 'New Option';
  headerLeft.appendChild(optionTitle);
  
  // Right section of header
  const headerRight = document.createElement('div');
  headerRight.className = 'header-right';
  
  // Toggle indicator
  const toggle = document.createElement('span');
  toggle.className = 'toggle-indicator';
  toggle.textContent = '+';
  
  // Clone button
  const cloneBtn = document.createElement('button');
  cloneBtn.className = 'clone-button';
  cloneBtn.textContent = 'Clone';
  cloneBtn.addEventListener('click', e => {
    e.stopPropagation();
    confirmClone(opt.optionName || 'this option', () => {
      // Clone the option
      const optClone = JSON.parse(JSON.stringify(opt));
      optClone.optionName += ' (Copy)';
      
      // Add to data structure
      window.pricingData.features[fIdx].options.push(optClone);
      saveData();
      
      // Create and add the new element
      const newOptIdx = window.pricingData.features[fIdx].options.length - 1;
      const newOptEl = createOptionElement(
        fIdx, newOptIdx, optClone, availOpt, availAdd, featureRecCheckbox
      );
      newOptEl.classList.add('fade-in');
      
      // Find the add-option-row to insert before
      const featureEl = document.querySelectorAll('.feature')[fIdx];
      const addOptionRow = featureEl.querySelector('.add-option-row');
      addOptionRow.parentNode.insertBefore(newOptEl, addOptionRow);
      
      // Expand and scroll to the new option
      setTimeout(() => {
        toggleExpandCollapse(newOptEl, true);
        scrollIntoViewWithOffset(newOptEl);
        
        // Update parent containers
        updateParentContainers(newOptEl);
      }, 100);
    });
  });
  
  // Delete button
  const deleteBtn = document.createElement('button');
  deleteBtn.className = 'delete-button';
  deleteBtn.textContent = 'Delete';
  deleteBtn.addEventListener('click', e => {
    e.stopPropagation();
    confirmDeletion(opt.optionName || 'this option', () => {
      wrap.classList.add('fade-out');
      wrap.addEventListener('animationend', () => {
        // Find parent feature and get current indexes
        const featureEl = wrap.closest('.feature');
        if (featureEl) {
          const currentFIdx = Array.from(document.querySelectorAll('.feature')).indexOf(featureEl);
          const currentOIdx = Array.from(featureEl.querySelectorAll('.option')).indexOf(wrap);
          
          if (currentFIdx !== -1 && currentOIdx !== -1) {
            // Remove from data structure using current indexes
            window.pricingData.features[currentFIdx].options.splice(currentOIdx, 1);
          }
        }
        saveData();
        
        // Remove from DOM
        wrap.remove();
        
        // Update parent container heights
        if (featureEl) {
          const featureContent = featureEl.querySelector('.feature-content');
          if (featureContent && featureContent.classList.contains('open')) {
            featureContent.style.maxHeight = featureContent.scrollHeight + 'px';
          }
        }
      });
    });
  });
  
  headerRight.append(toggle, cloneBtn, deleteBtn);
  header.append(headerLeft, headerRight);
  wrap.appendChild(header);
  
  // Content area (collapsible)
  const content = document.createElement('div');
  content.className = 'content option-content';
  content.style.maxHeight = '0px'; // Start collapsed
  wrap.appendChild(content);
  
  // Handle header click to expand/collapse
  header.addEventListener('click', e => {
    if (headerRight.contains(e.target)) return;
    toggleExpandCollapse(wrap);
  });
  
  // Create fields inside the content
  const contentInner = document.createElement('div');
  contentInner.className = 'content-inner';
  content.appendChild(contentInner);

  // Name field with update to header text
  const nameGroup = createFieldGroup(
    'optionName', 'text',
    () => opt.optionName,
    v => {
      recordNameChange(window.nameChanges.options, [fIdx, oIdx], opt.optionName, v);
      opt.optionName = v;
      optionTitle.textContent = v || 'New Option';
      
      // Add this line to update the reference text
      const optionNameRef = wrap.querySelector('.option-name-reference');
      if (optionNameRef) optionNameRef.textContent = (v || 'Option') + "'s Add-ons";
    },
    'Enter option name...'
  );
  contentInner.appendChild(nameGroup);
  
  // Description field
  contentInner.appendChild(createFieldGroup(
    'description', 'long-text',
    () => opt.description.text,
    v => opt.description.text = v,
    'Description...'
  ));
  
  // Interval dropdown
  const intG = createFieldGroup(
    'interval', 'dropdown',
    () => opt.interval.value,
    v => opt.interval.value = v
  );
  contentInner.append(intG);
  
  // Pricing type dropdown
  const ptG = createFieldGroup(
    'pricingType', 'dropdown',
    () => opt.pricingType.value,
    v => opt.pricingType.value = v
  );
  contentInner.append(ptG);
  
  // Price fields
  const pfG = createFieldGroup(
    'priceFloor', 'number',
    () => opt.priceFloor,
    v => opt.priceFloor = v
  ),
  pcG = createFieldGroup(
    'priceCeiling', 'number',
    () => opt.priceCeiling,
    v => opt.priceCeiling = v
  ),
  spG = createFieldGroup(
    'staticPrice', 'number',
    () => opt.staticPrice,
    v => opt.staticPrice = v
  );
  contentInner.append(pfG, pcG, spG);
  
  // Price options field
  const poDiv = createPriceOptionsUI(
    opt.priceOptions ? (
        typeof opt.priceOptions === 'string' ? 
        JSON.parse(opt.priceOptions) : 
        (Array.isArray(opt.priceOptions) ? opt.priceOptions : 
        (opt.priceOptions.value?.types || [{ label: 'Default', price: 0 }]))
    ) : [{ label: 'Default', price: 0 }],
    v => {
        if (!opt.priceOptions) {
            opt.priceOptions = {
                level: 'admin',
                ui_type: 'array-obj',
                value: {
                    types: v
                }
            };
        } else if (typeof opt.priceOptions === 'string') {
            // Convert from string to proper object if it was loaded from DB
            opt.priceOptions = {
                level: 'admin',
                ui_type: 'array-obj',
                value: {
                    types: v
                }
            };
        } else {
            opt.priceOptions.value = {
                types: v
            };
        }
        saveData();
    }
  );
  contentInner.append(poDiv);

  // Setup pricing type logic with price options
  const ptSel = ptG.querySelector('select');
  const pfI = pfG.querySelector('input');
  const pcI = pcG.querySelector('input');
  const spI = spG.querySelector('input');

  function applyO() {
    // Get the selected pricing type index
    const selectedTypeIdx = parseInt(ptSel.value, 10);
    
    // Apply UI changes based on the selected type
    handlePricingTypeChange(pfI, pcI, spI, poDiv, selectedTypeIdx);
    saveData();
  }
  
  // Add change listener
  ptSel.addEventListener('change', applyO);
  
  applyO();
  
  // Setup interval logic
  const intSel = intG.querySelector('select');
  featureRecCheckbox.addEventListener('change', () => {
    intSel.disabled = !featureRecCheckbox.checked;
  });
  intSel.disabled = !featureRecCheckbox.checked;
  
  // Option metric field
  contentInner.appendChild(createFieldGroup(
    'optionMetric', 'text',
    () => opt.optionMetric,
    v => opt.optionMetric = v,
    'Enter metric...'
  ));

  // Add the custom max addons field with checkbox
  contentInner.appendChild(createMaxAddonsField(opt));
  
  // Add dynamic fields
  createDynamicFields(opt, contentInner, [
    'optionName', 'description', 'interval', 'pricingType',
    'priceFloor', 'priceCeiling', 'staticPrice', 'priceOptions', 'optionMetric', 'addons',
    'link_name', 'thresholdDiscounts', 'enableThresholdDiscounts', 'maxAddons'
  ]);
  
  // Create addons header with option name
  const addonsHeader = document.createElement('div');
  addonsHeader.className = 'section-header option-addons-header';

  // Set initial text
  const optionNameSpan = document.createElement('span');
  optionNameSpan.className = 'option-name-reference';
  optionNameSpan.textContent = (opt.optionName || 'Option') + "'s Add-ons:";
  addonsHeader.appendChild(optionNameSpan);

  // Insert the header before addons list
  contentInner.appendChild(addonsHeader);

  // Create addon container
  const addonContentArea = document.createElement('div');
  addonContentArea.className = 'addon-content-area';
  contentInner.appendChild(addonContentArea);
  
  // Add existing addons
  opt.addons.forEach((a, ai) => {
    addonContentArea.appendChild(
      createAddonElement(fIdx, oIdx, a, availAdd, featureRecCheckbox, ai)
    );
  });
  
  // Add the "Add Addon" button
  const addAddonRow = document.createElement('div');
  addAddonRow.className = 'button-row add-addon-row';
  const addAddonBtn = document.createElement('button');
  addAddonBtn.textContent = 'Add Addon';
  addAddonBtn.className = 'add-button';
  addAddonBtn.addEventListener('click', () => showNewAddonForm(fIdx, oIdx));
  addAddonRow.appendChild(addAddonBtn);
  addonContentArea.appendChild(addAddonRow);
  
  return wrap;
}

/**************************************************************
 * 15) Feature Element (with Clone button)
 **************************************************************/
function createFeatureElement(idx, feat, availOpt, availAdd){
  const outer = document.createElement('div');
  outer.className = 'feature';

  const hdr   = document.createElement('div');
  hdr.className = 'feature-header';
  const left  = document.createElement('div');
  left.className = 'feature-header-left';
  const right = document.createElement('div');
  right.className = 'feature-header-right';

  const toggle= document.createElement('span');
  toggle.className = 'toggle-indicator';
  toggle.textContent = '+';

  // new Clone button
  const cloneBtn = document.createElement('button');
  cloneBtn.className = 'clone-button';
  cloneBtn.textContent = 'Clone';
  cloneBtn.addEventListener('click', e=>{
    e.stopPropagation();
    confirmClone(feat.featureName || 'this feature', ()=>{
      // perform the clone
      const tpl = window.pricingData.features[idx];
      const copy = JSON.parse(JSON.stringify(tpl));
      copy.link_name = '';
      window.pricingData.features.push(copy);
      saveData();
      // rebuild metrics lists
      const availOpt2 = [], availAdd2 = [];
      window.pricingData.features.forEach(f=>{
        f.options.forEach(o=>{
          if (o.optionMetric && !availOpt2.includes(o.optionMetric))
            availOpt2.push(o.optionMetric);
          o.addons.forEach(a=>{
            if (a.addOnMetric && !availAdd2.includes(a.addOnMetric))
              availAdd2.push(a.addOnMetric);
          });
        });
      });
      // render at end
      const newIdx = window.pricingData.features.length - 1;
      const newEl = createFeatureElement(newIdx, copy, availOpt2, availAdd2);
      newEl.classList.add('fade-in');
      const container = document.getElementById('pricing-form');
      const addBtn   = document.getElementById('add-feature-button');
      container.insertBefore(newEl, addBtn);
      setTimeout(()=>{
        expandFeature(newEl);
        scrollIntoViewWithOffset(newEl);
      }, 10);
    });
  });

  const delF  = document.createElement('button');
  delF.className = 'delete-button';
  delF.textContent = 'Delete Feature';
  delF.addEventListener('click', e => {
    e.stopPropagation();
    confirmDeletion(feat.featureName||'this feature', () => {
      outer.classList.add('fade-out');
      outer.addEventListener('animationend', () => {
        // Get current index at deletion time
        const currentIdx = Array.from(document.querySelectorAll('.feature')).indexOf(outer);
        if (currentIdx !== -1) {
          window.pricingData.features.splice(currentIdx, 1);
        }
        saveData();
        outer.remove();
      });
    });
  });

  right.append(toggle, cloneBtn, delF);
  hdr.append(left, right);
  outer.appendChild(hdr);

  const content = document.createElement('div');
  content.className = 'feature-content';
  const inner   = document.createElement('div');
  inner.className = 'feature-content-inner';
  content.appendChild(inner);

  const titleSpan = document.createElement('span');
  titleSpan.textContent = feat.featureName || 'New Feature';
  left.append(titleSpan);

  inner.appendChild(createFieldGroup(
    'featureName','text',
    () => feat.featureName,
    v => {
      const old = feat.featureName;
      recordNameChange(window.nameChanges.features, [idx], old, v);
      feat.featureName = v;
      titleSpan.textContent = v || 'New Feature';
      
      // Add this line to update the reference text
      const featureNameRef = outer.querySelector('.feature-name-reference');
      if (featureNameRef) featureNameRef.textContent = (v || 'Feature') + "'s Options";
      
      // propagate into link_name
      window.pricingData.features.forEach(f => {
        if (f.link_name === old) {
          f.link_name = v;
        }
      });
    },
    'Enter feature name...'
  ));
  inner.appendChild(createFieldGroup(
    'description','long-text',
    () => feat.description.text,
    v => feat.description.text = v,
    'Description...'
  ));

  const recFG = createFieldGroup(
    'recurring','boolean',
    () => !!feat.recurring,
    v => { feat.recurring = v; saveData(); }
  );
  inner.appendChild(recFG);
  const featureRecCheckbox =
    recFG.querySelector('input[type=checkbox]');

  // hide link_name from UI
  createDynamicFields(feat, inner, [
    'featureName','description','options','recurring','link_name'
  ]);

  // Create options header with feature name
  const optionsHeader = document.createElement('div');
  optionsHeader.className = 'section-header feature-options-header';

  // Set initial text
  const featureNameSpan = document.createElement('span');
  featureNameSpan.className = 'feature-name-reference';
  featureNameSpan.textContent = (feat.featureName || 'Feature') + "'s Options:";
  optionsHeader.appendChild(featureNameSpan);

  // Insert the header before options list
  inner.appendChild(optionsHeader);

  // Add options to the feature
  feat.options.forEach((o, i) => {
    inner.appendChild(
      createOptionElement(idx, i, o, availOpt, availAdd, featureRecCheckbox)
    );
  });

  const orow = document.createElement('div');
  orow.className = 'button-row add-option-row';
  const ob = document.createElement('button');
  ob.textContent = 'Add Option';
  ob.className = 'add-button';
  ob.addEventListener('click', () => showNewOptionForm(idx));
  orow.append(ob);
  inner.append(orow);

  outer.append(content);

  hdr.addEventListener('click', e=>{
    if (right.contains(e.target)) return;
    if (content.classList.contains('open')) {
      content.classList.remove('open');
      content.style.maxHeight = 0;
      toggle.textContent = '+';
    } else {
      content.classList.add('open');
      content.style.maxHeight = content.scrollHeight + 'px';
      toggle.textContent = '-';
    }
  });

  return outer;
}

/**************************************************************
 * 16) Render & "Add Feature"
 **************************************************************/
function renderPricingForm(data, availOpt, availAdd){
  const container = document.getElementById('pricing-form');
  container.innerHTML = '';
  if (!data.features || !Array.isArray(data.features)) data.features = [];

  data.features.forEach((f,i)=>
    container.appendChild(createFeatureElement(i,f,availOpt,availAdd))
  );

  const af = document.createElement('button');
  af.id = 'add-feature-button';
  af.className = 'add-button';
  af.textContent = 'Add Feature';
  af.addEventListener('click', showNewFeatureForm);
  container.append(af);
}

/**************************************************************
 * 17) New Feature Flow
 **************************************************************/
function showNewFeatureForm(){
  const addBtn = document.getElementById('add-feature-button');
  if (!addBtn || document.getElementById('new-feature-form')) return;
  addBtn.style.display = 'none';

  const form = document.createElement('div');
  form.id = 'new-feature-form';
  form.className = 'feature new-feature-form';

  const schemaG   = document.createElement('div'); schemaG.className='field-group';
  const schemaLbl = document.createElement('label'); schemaLbl.textContent='Schema:';
  const schemaSel = document.createElement('select');
  const defOpt    = document.createElement('option');
  defOpt.value    = '__default__';
  defOpt.textContent = 'Default';
  schemaSel.appendChild(defOpt);
  window.pricingData.features.forEach((f,i)=>{
    const o=document.createElement('option');
    o.value=i; o.textContent=f.featureName||`Feature ${i+1}`;
    schemaSel.appendChild(o);
  });
  schemaG.append(schemaLbl,schemaSel); form.append(schemaG);

  let tempName='', tempDesc='', tempRec=false;
  form.appendChild(createFieldGroup('featureName','text',()=>tempName,v=>tempName=v,'Enter feature name...'));
  form.appendChild(createFieldGroup('description','long-text',()=>tempDesc,v=>tempDesc=v,'Description...'));
  form.appendChild(createFieldGroup('recurring','boolean',()=>tempRec,v=>tempRec=v));

  const br = document.createElement('div'); br.className='button-row';
  const createBtn=document.createElement('button'); createBtn.textContent='Create Feature'; createBtn.className='add-button';
  const cancelBtn=document.createElement('button'); cancelBtn.textContent='Cancel'; cancelBtn.className='delete-button';
  br.append(createBtn,cancelBtn); form.append(br);

  addBtn.parentNode.insertBefore(form, addBtn);
  cancelBtn.addEventListener('click', ()=>{ form.remove(); addBtn.style.display=''; });

  createBtn.addEventListener('click', ()=>{
    if (!tempName.trim()){ alert('Feature name is required.'); return; }

    let newFeat;
    if (schemaSel.value==='__default__') {
      newFeat = { featureName:'', description:{text:''}, options:[], recurring:tempRec, link_name:'' };
    } else {
      // clone & reset wrappers:
      const raw = window.pricingData.features[+schemaSel.value];
      newFeat = JSON.parse(JSON.stringify(raw));
      clearSchemaValues(newFeat);
      newFeat.options = [];
      newFeat.recurring = tempRec;
      newFeat.link_name = raw.featureName;
      newFeat.featureName = '';
      newFeat.description.text = '';
    }
    newFeat.featureName = tempName;
    newFeat.description.text = tempDesc;

    const newFi = window.pricingData.features.push(newFeat) - 1;
    saveData();
    form.remove();

    // rebuild avail lists
    const availOpt = [], availAdd = [];
    window.pricingData.features.forEach(f=>{
      f.options.forEach(o=>{
        if (o.optionMetric && !availOpt.includes(o.optionMetric))
          availOpt.push(o.optionMetric);
        o.addons.forEach(a=>{
          if (a.addOnMetric && !availAdd.includes(a.addOnMetric))
            availAdd.push(a.addOnMetric);
        });
      });
    });

    const container = document.getElementById('pricing-form');
    const featureEl = createFeatureElement(newFi,newFeat,availOpt,availAdd);
    featureEl.classList.add('fade-in');
    container.insertBefore(featureEl, addBtn);

    setTimeout(()=>{ expandFeature(featureEl); scrollIntoViewWithOffset(featureEl); },10);
    addBtn.style.display = '';
  });
}

/**************************************************************
 * 18) On DOM Ready
 **************************************************************/
document.addEventListener('DOMContentLoaded', ()=>{
  const base = pricingData.data && Array.isArray(pricingData.data.features)
    ? { features: pricingData.data.features }
    : { features: [] };
  const stored = loadData();
  window.pricingData = stored ? mergeData(base, stored) : base;

  // normalize descriptions
  window.pricingData.features.forEach(feat=>{
    if (!feat.description || typeof feat.description.text!=='string')
      feat.description = { text:'' };
    feat.options.forEach(opt=>{
      if (!opt.description||typeof opt.description.text!=='string')
        opt.description = { text:'' };
      opt.addons.forEach(a=>{
        if (!a.description||typeof a.description.text!=='string')
          a.description = { text:'' };
      });
    });
  });

  // collect metrics
  const availOpt = [], availAdd = [];
  window.pricingData.features.forEach(f=>{
    f.options.forEach(o=>{
      if (o.optionMetric && !availOpt.includes(o.optionMetric))
        availOpt.push(o.optionMetric);
      o.addons.forEach(a=>{
        if (a.addOnMetric && !availAdd.includes(a.addOnMetric))
          availAdd.push(a.addOnMetric);
      });
    });
  });

  renderPricingForm(window.pricingData,availOpt,availAdd);

  const applyBtn = document.getElementById('apply-button');
  if (applyBtn) {
    applyBtn.addEventListener('click',()=>{
      document.getElementById('pricing-loader').style.display='block';
      fetch(pricingDataSettings.apiUrl+'save-pricing',{
        method:'POST',
        headers:{
          'Content-Type':'application/json',
          'X-WP-Nonce':pricingDataSettings.nonce
        },
        body: JSON.stringify({
          pricingData: window.pricingData,
          nameChanges: window.nameChanges
        })
      })
      .then(r=>r.json())
      .then(res=>{
        if (!res.success) console.error(res);
        window.nameChanges = { features:{}, options:{}, addons:{} };
        sessionStorage.setItem('nameChanges',JSON.stringify(window.nameChanges));
        document.getElementById('pricing-loader').style.display='none';
      })
      .catch(err=>{
        console.error(err);
        document.getElementById('pricing-loader').style.display='none';
      });
    });
  }
});