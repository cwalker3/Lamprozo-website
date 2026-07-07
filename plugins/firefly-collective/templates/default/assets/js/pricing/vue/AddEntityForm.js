// AddEntityForm.js — inline "add feature / option / add-on" form.
// Reproduces FormManager's create form: schema selector (Default or clone a
// sibling template) + required name + description (+ recurring for features).

export const AddEntityForm = {
    props: {
        level: { type: String, required: true },     // feature | option | addon
        schemaOptions: { type: Array, default: () => [] }
    },
    emits: ['create', 'cancel'],
    data() {
        return { schemaValue: '__default__', name: '', description: '', recurring: false, touched: false };
    },
    computed: {
        labelWord() { return this.level === 'feature' ? 'feature' : this.level === 'option' ? 'option' : 'add-on'; },
        valid() { return this.name.trim().length > 0; }
    },
    mounted() {
        const el = this.$refs.name;
        if (el) el.focus();
    },
    methods: {
        submit() {
            this.touched = true;
            if (!this.valid) return;
            this.$emit('create', {
                schemaValue: this.schemaValue,
                name: this.name.trim(),
                description: this.description,
                recurring: this.recurring
            });
        }
    },
    template: `
    <div class="fpa-addform">
        <div class="fpa-addform-title">New {{ labelWord }}</div>
        <div class="fpa-field" v-if="schemaOptions.length > 1">
            <label>Start from</label>
            <select v-model="schemaValue">
                <option v-for="o in schemaOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
        </div>
        <div class="fpa-field">
            <label>Name</label>
            <input ref="name" type="text" v-model="name" :placeholder="labelWord + ' name'"
                :class="{ 'fpa-invalid': touched && !valid }" @keydown.enter="submit">
        </div>
        <div class="fpa-field">
            <label>Description</label>
            <textarea rows="2" v-model="description" placeholder="optional"></textarea>
        </div>
        <div class="fpa-field fpa-field-check" v-if="level === 'feature'">
            <label class="fpa-check"><input type="checkbox" v-model="recurring"><span>Recurring billing</span></label>
        </div>
        <div class="fpa-addform-actions">
            <button type="button" class="fpa-btn fpa-btn-ghost" @click="$emit('cancel')">Cancel</button>
            <button type="button" class="fpa-btn fpa-btn-green" @click="submit">Create {{ labelWord }}</button>
        </div>
    </div>`
};
