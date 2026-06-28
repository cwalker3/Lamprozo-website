// DynamicFields.js — renders custom (non-built-in) fields for an entity,
// reproducing FieldFactory.createDynamicFields + createDynamicField +
// determineFieldType. Skips known keys, skips non-admin wrapped fields
// (admin only renders admin + user-display levels), and routes each field
// to the right atom by ui_type (wrapped) or inferred type (unwrapped).

import { Field } from './Field.js';
import { SelectField } from './SelectField.js';

function formatName(name) {
    return String(name)
        .replace(/[-_]+/g, ' ')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .split(' ').filter(Boolean)
        .map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

function inferType(value) {
    if (value && typeof value === 'object') {
        if (Array.isArray(value.types) && 'selected' in value) return 'select';
        if ('text' in value) return 'textarea';
    }
    if (typeof value === 'boolean') return 'checkbox';
    if (typeof value === 'number') return 'number';
    if (Array.isArray(value)) return value.every(x => typeof x === 'string') ? 'select' : 'text';
    if (typeof value === 'string' && value.length > 80) return 'textarea';
    return 'text';
}

function uiToAtom(ui) {
    switch (ui) {
        case 'array':     return 'select';
        case 'boolean':   return 'checkbox';
        case 'number':
        case 'int-float': return 'number';
        case 'date':      return 'date';
        case 'long-text':
        case 'textarea':  return 'textarea';
        case 'array-obj': return null; // composite — not rendered as a dynamic scalar
        default:          return 'text';
    }
}

const DynamicField = {
    components: { Field, SelectField },
    props: { obj: Object, fieldKey: String },
    computed: {
        raw() { return this.obj[this.fieldKey]; },
        wrapped() { const r = this.raw; return !!(r && typeof r === 'object' && 'ui_type' in r && 'value' in r); },
        label() { return formatName(this.fieldKey); },
        display() {
            const r = this.raw;
            return !!((this.wrapped && (r.level === 'user-display' || r.is_display)) || this.fieldKey.endsWith('_display'));
        },
        atom() { return this.wrapped ? uiToAtom(this.raw.ui_type) : inferType(this.raw); },
        isSelect() {
            if (this.wrapped) return this.raw.ui_type === 'array' && this.raw.value && Array.isArray(this.raw.value.types);
            const v = this.raw;
            return !!(v && typeof v === 'object' && Array.isArray(v.types) && 'selected' in v);
        },
        selTypes() { return this.wrapped ? (this.raw.value.types || []) : (this.raw.types || []); },
        sel: {
            get() { return this.wrapped ? (this.raw.value.selected || 0) : (this.raw.selected || 0); },
            set(v) { if (this.wrapped) { this.raw.value.selected = v; } else { this.raw.selected = v; } }
        },
        scalar: {
            get() {
                const holder = this.wrapped ? this.raw.value : this.raw;
                if (holder && typeof holder === 'object' && 'text' in holder) return holder.text;
                return holder == null ? '' : holder;
            },
            set(v) {
                if (this.wrapped) {
                    const h = this.raw.value;
                    if (h && typeof h === 'object' && 'text' in h) { h.text = v; } else { this.raw.value = v; }
                } else {
                    const h = this.obj[this.fieldKey];
                    if (h && typeof h === 'object' && 'text' in h) { h.text = v; } else { this.obj[this.fieldKey] = v; }
                }
            }
        }
    },
    template: `
    <select-field v-if="isSelect" :label="label" :types="selTypes" v-model:selected="sel" :display="display"></select-field>
    <field v-else-if="atom" :label="label" :type="atom" v-model="scalar" :display="display"></field>`
};

export const DynamicFields = {
    components: { DynamicField },
    props: { obj: Object, known: { type: Array, default: () => [] } },
    computed: {
        keys() {
            const out = [];
            const obj = this.obj || {};
            Object.keys(obj).forEach(k => {
                if (this.known.indexOf(k) !== -1) return;
                const r = obj[k];
                // Skip non-admin/non-display wrapped fields.
                if (r && typeof r === 'object' && 'level' in r && 'ui_type' in r && 'value' in r
                    && r.level !== 'admin' && r.level !== 'user-display') return;
                // Skip structural arrays + composite array-obj (handled elsewhere).
                if (Array.isArray(r)) return;
                if (r && typeof r === 'object' && r.ui_type === 'array-obj') return;
                out.push(k);
            });
            return out;
        }
    },
    template: `
    <div v-if="keys.length" class="fpa-dynamic">
        <dynamic-field v-for="k in keys" :key="k" :obj="obj" :field-key="k"></dynamic-field>
    </div>`
};
