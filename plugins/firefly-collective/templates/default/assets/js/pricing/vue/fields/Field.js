// Field.js — one scalar field atom (label + the right input), v-model-bound.
// Reproduces FieldFactory.createInput's type routing in a Vue component.

export const Field = {
    props: {
        label: String,
        type: { type: String, default: 'text' }, // text | textarea | number | checkbox | date
        modelValue: {},
        placeholder: String,
        display: Boolean,                          // user-display styling
        rows: { type: Number, default: 3 }
    },
    emits: ['update:modelValue'],
    computed: {
        proxy: {
            get() { return this.modelValue; },
            set(v) { this.$emit('update:modelValue', v); }
        }
    },
    methods: {
        onNum(e) {
            const v = e.target.value;
            this.$emit('update:modelValue', v === '' ? '' : parseFloat(v));
        }
    },
    template: `
    <div v-if="type==='checkbox'" class="fpa-field fpa-field-check">
        <label class="fpa-check">
            <input type="checkbox" v-model="proxy">
            <span>{{ label }}</span>
        </label>
    </div>
    <div v-else class="fpa-field" :class="{'is-display': display}">
        <label v-if="label">{{ label }}<span v-if="display" class="fpa-display-tag">user-facing</span></label>
        <textarea v-if="type==='textarea'" :rows="rows" v-model="proxy" :placeholder="placeholder"></textarea>
        <input v-else-if="type==='number'" type="number" step="any" :value="modelValue" @input="onNum" :placeholder="placeholder">
        <input v-else-if="type==='date'" type="date" v-model="proxy" :placeholder="placeholder">
        <input v-else type="text" v-model="proxy" :placeholder="placeholder">
    </div>`
};
