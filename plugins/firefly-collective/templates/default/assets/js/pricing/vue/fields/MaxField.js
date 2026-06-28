// MaxField.js — number + "Unlimited" toggle (maxAddons / maxGroupItems).
// Unlimited persists as -1, matching the original SpecialFieldFactory.

export const MaxField = {
    props: { label: String, modelValue: { type: Number, default: -1 } },
    emits: ['update:modelValue', 'change'],
    computed: {
        unlimited: {
            get() { return this.modelValue === -1 || this.modelValue == null; },
            set(on) { this.$emit('update:modelValue', on ? -1 : 0); this.$emit('change'); }
        }
    },
    methods: {
        onNum(e) {
            const v = e.target.value;
            this.$emit('update:modelValue', v === '' ? 0 : parseFloat(v));
            this.$emit('change');
        }
    },
    template: `
    <div class="fpa-field">
        <label v-if="label">{{ label }}</label>
        <div class="fpa-max-row">
            <label class="fpa-check"><input type="checkbox" v-model="unlimited"><span>Unlimited</span></label>
            <input v-if="!unlimited" type="number" step="any" :value="modelValue" @input="onNum" placeholder="0" style="max-width:140px">
        </div>
    </div>`
};
