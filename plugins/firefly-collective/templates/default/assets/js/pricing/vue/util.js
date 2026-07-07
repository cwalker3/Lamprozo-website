// util.js — small helpers for the Vue layer (wrapper normalization).
// These only run when a card mounts, to guarantee the array-obj wrappers
// (priceOptions / thresholdDiscounts / groupThresholdDiscounts) and the
// numeric max fields exist before binding — matching the shapes the save
// endpoint + the original UI expect.

export function ensureArrayObj(obj, key) {
    let w = obj[key];
    if (w && typeof w === 'object' && w.value && Array.isArray(w.value.types)) {
        return; // already a valid array-obj wrapper
    }
    if (typeof w === 'string') {
        try { w = JSON.parse(w); } catch (e) { w = null; }
    }
    const types = (w && w.value && Array.isArray(w.value.types)) ? w.value.types : [];
    obj[key] = { level: 'admin', ui_type: 'array-obj', value: { types: types } };
}

export function ensureNumber(obj, key, def) {
    if (typeof obj[key] !== 'number') {
        obj[key] = def;
    }
}
