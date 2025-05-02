// assets/js/pricing.js
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
function handlePricingTypeChange(fI, cI, sI, typeIdx) {
  if (typeIdx === 0) { // static price
    fI.disabled = cI.disabled = true;
    sI.disabled = false;
    [fI, cI].forEach(i => { i.value = ''; i.dispatchEvent(new Event('input')); });
  } else {             // price range
    fI.disabled = cI.disabled = false;
    sI.disabled = true;
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
    
    // Update parent containers with a slight delay to ensure this element has started expanding
    setTimeout(() => {
      updateParentContainers(element);
    }, 50);
  }
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
  // Update all open feature contents
  document.querySelectorAll('.feature-content.open').forEach(content => {
    content.style.maxHeight = (content.scrollHeight * 1.2) + 'px';
  });
  
  // Update all open option contents
  document.querySelectorAll('.option > .content.open').forEach(content => {
    content.style.maxHeight = (content.scrollHeight * 1.3) + 'px';
  });
  
  // Update all open addon contents
  document.querySelectorAll('.addon > .content.open').forEach(content => {
    content.style.maxHeight = (content.scrollHeight * 1.5) + 'px';
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

function createDynamicField(key, raw, onChange){
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

function createDynamicFields(obj, container, knownKeys){
  for (const key in obj){
    if (!obj.hasOwnProperty(key) || knownKeys.includes(key)) continue;
    const raw = obj[key];
    // skip non-admin
    if ( raw && typeof raw==='object'
      && 'level' in raw
      && 'ui_type' in raw
      && 'value' in raw
      && raw.level!=='admin'
    ) continue;
    container.appendChild(
      createDynamicField(key, raw, v=>obj[key]=v)
    );
  }
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
      types:['daily','bi-weekly','weekly','monthly','bi-annually','annually','none'],
      selected:0
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
        pricingType:{ level:'admin', ui_type:'array', value:{types:['static price','price range'],selected:0} },
        priceFloor:0, priceCeiling:0, staticPrice:0,
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
      if (isRecurring) {
        newO.interval = JSON.parse(JSON.stringify(defaultIntervalSchema));
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
  // Clone button in createAddonElement function
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
        // Remove from data structure
        window.pricingData.features[fIdx].options[oIdx].addons =
          window.pricingData.features[fIdx].options[oIdx].addons.filter(a => a !== addon);
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
    handlePricingTypeChange(fpI, cpI, spI, parseInt(ptSel.value, 10));
  }
  ptSel.addEventListener('change', applyA);
  applyA();

  // Add dynamic fields
  createDynamicFields(addon, contentInner, [
    'addonName', 'description', 'addOnMetric',
    'pricingType', 'floorPriceMod', 'ceilingPriceMod', 'staticPriceMod',
    'link_name'
  ]);

  return wrap;
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
      optClone.addons = []; // Don't clone addons
      
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
        // Remove from data structure
        window.pricingData.features[fIdx].options.splice(oIdx, 1);
        saveData();
        
        // Remove from DOM
        wrap.remove();
        
        // Update parent container heights
        const featureEl = document.querySelectorAll('.feature')[fIdx];
        const featureContent = featureEl.querySelector('.feature-content');
        if (featureContent.classList.contains('open')) {
          featureContent.style.maxHeight = featureContent.scrollHeight + 'px';
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
  
  // Setup pricing type logic
  const ptSel = ptG.querySelector('select'),
        pfI   = pfG.querySelector('input'),
        pcI   = pcG.querySelector('input'),
        spI   = spG.querySelector('input');
        
  function applyO(){
    handlePricingTypeChange(pfI, pcI, spI, parseInt(ptSel.value, 10));
  }
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
  
  // Add dynamic fields
  createDynamicFields(opt, contentInner, [
    'optionName', 'description', 'interval', 'pricingType',
    'priceFloor', 'priceCeiling', 'staticPrice', 'optionMetric', 'addons',
    'link_name'
  ]);
  
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
  delF.addEventListener('click', e=>{
    e.stopPropagation();
    confirmDeletion(feat.featureName||'this feature', ()=>{
      outer.classList.add('fade-out');
      outer.addEventListener('animationend', ()=>{
        window.pricingData.features.splice(idx,1);
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