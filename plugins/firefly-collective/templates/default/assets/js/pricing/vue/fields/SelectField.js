// SelectField.js — dropdown for the wrapped `array` ui_type
// ({ value: { types: [...], selected: N } }). Emits the new selected index.

export const SelectField = {
    props: {
        label: String,
        types: { type: Array, default: () => [] },
        selected: { type: Number, default: 0 },
        display: Boolean
    },
    emits: ['update:selected'],
    template: `
    <div class="fpa-field" :class="{'is-display': display}">
        <label v-if="label">{{ label }}</label>
        <select :value="selected" @change="$emit('update:selected', Number($event.target.value))">
            <option v-for="(t, i) in types" :key="i" :value="i">{{ t }}</option>
        </select>
    </div>`
};
