// create.js — entity creation helpers, faithful to FormManager.handle*Create
// + getSchemaOrigin*. New entities are either a fresh ConfigManager default
// or a deep clone of a chosen template (with link_name set to the template's
// name, exactly as the original did). Schema-option lists resolve the
// "origin" template via link_name so a cloned entity offers its template's
// children as clone sources.

function clone(x) { return JSON.parse(JSON.stringify(x)); }
function ensureDesc(obj) {
    if (!obj.description || typeof obj.description.text !== 'string') obj.description = { text: '' };
}

function resolveOriginFeature(data, feature) {
    if (!feature || !feature.link_name) return feature;
    const f = data.features.find(x => x.featureName === feature.link_name);
    return f || feature;
}
function resolveOriginOption(originFeature, option) {
    if (!option || !option.link_name || !originFeature || !Array.isArray(originFeature.options)) return option;
    const o = originFeature.options.find(x => x.optionName === option.link_name);
    return o || option;
}

// ── Schema option lists (Default + sibling templates) ──────────────────────
export function schemaOptionsForFeature(data) {
    const out = [{ value: '__default__', label: 'Default (blank)' }];
    (data.features || []).forEach((f, i) => out.push({ value: String(i), label: 'Like: ' + (f.featureName || 'Feature ' + (i + 1)) }));
    return out;
}
export function schemaOptionsForOption(data, feature) {
    const origin = resolveOriginFeature(data, feature);
    const out = [{ value: '__default__', label: 'Default (blank)' }];
    ((origin && origin.options) || []).forEach((o, i) => out.push({ value: String(i), label: 'Like: ' + (o.optionName || 'Option ' + (i + 1)) }));
    return out;
}
export function schemaOptionsForAddon(data, feature, option) {
    const originFeature = resolveOriginFeature(data, feature);
    const originOption = resolveOriginOption(originFeature, option);
    const out = [{ value: '__default__', label: 'Default (blank)' }];
    ((originOption && originOption.addons) || []).forEach((a, i) => out.push({ value: String(i), label: 'Like: ' + (a.addonName || 'Add-on ' + (i + 1)) }));
    return out;
}

// ── Entity builders ────────────────────────────────────────────────────────
export function buildFeature(config, data, schemaValue, name, description, recurring) {
    let f;
    if (schemaValue === '__default__') {
        f = clone(config.getDefault('feature'));
    } else {
        const t = data.features[parseInt(schemaValue, 10)];
        f = clone(t);
        f.link_name = t.featureName;
    }
    f.featureName = name;
    ensureDesc(f);
    f.description.text = description || '';
    f.recurring = !!recurring;
    if (!Array.isArray(f.options)) f.options = [];
    return f;
}

export function buildOption(config, data, feature, schemaValue, name, description) {
    let o;
    if (schemaValue === '__default__') {
        o = clone(config.getDefault('option'));
    } else {
        const origin = resolveOriginFeature(data, feature);
        const t = origin.options[parseInt(schemaValue, 10)];
        o = clone(t);
        o.link_name = t.optionName;
    }
    o.optionName = name;
    ensureDesc(o);
    o.description.text = description || '';
    if (!Array.isArray(o.addons)) o.addons = [];
    return o;
}

export function buildAddon(config, data, feature, option, schemaValue, name, description) {
    let a;
    if (schemaValue === '__default__') {
        a = clone(config.getDefault('addon'));
    } else {
        const originFeature = resolveOriginFeature(data, feature);
        const originOption = resolveOriginOption(originFeature, option);
        const t = originOption.addons[parseInt(schemaValue, 10)];
        a = clone(t);
        a.link_name = t.addonName;
    }
    a.addonName = name;
    ensureDesc(a);
    a.description.text = description || '';
    return a;
}

export { clone };
