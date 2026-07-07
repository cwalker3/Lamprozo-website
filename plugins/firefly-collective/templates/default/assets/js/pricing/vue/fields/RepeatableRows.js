// RepeatableRows.js — generic repeatable two-column editor.
// Used for option price-options ({label, price}) and threshold discounts
// ({itemCount, discount}) and addon group-threshold discounts. Mutates the
// bound `rows` array in place (reactive) and emits `change` after every
// add / edit / reorder / delete so a parent can run group sync.

export const RepeatableRows = {
    props: {
        rows: { type: Array, required: true },
        columns: { type: Array, required: true }, // [{ key, label, type:'text'|'number', placeholder }]
        addLabel: { type: String, default: 'Add row' }
    },
    emits: ['change'],
    methods: {
        add() {
            const blank = {};
            this.columns.forEach(c => { blank[c.key] = c.type === 'number' ? 0 : ''; });
            this.rows.push(blank);
            this.$emit('change');
        },
        del(i) { this.rows.splice(i, 1); this.$emit('change'); },
        up(i) { if (i > 0) { const r = this.rows.splice(i, 1)[0]; this.rows.splice(i - 1, 0, r); this.$emit('change'); } },
        down(i) { if (i < this.rows.length - 1) { const r = this.rows.splice(i, 1)[0]; this.rows.splice(i + 1, 0, r); this.$emit('change'); } },
        onInput(i, c, e) {
            const raw = e.target.value;
            this.rows[i][c.key] = c.type === 'number' ? (raw === '' ? '' : parseFloat(raw)) : raw;
            this.$emit('change');
        }
    },
    template: `
    <div class="fpa-rep">
        <div v-if="!rows.length" class="fpa-rep-empty">None yet.</div>
        <div v-for="(row, i) in rows" :key="i" class="fpa-rep-row">
            <div v-for="c in columns" :key="c.key" class="fpa-rep-cell">
                <input :type="c.type === 'number' ? 'number' : 'text'" :step="c.type === 'number' ? 'any' : null"
                    :value="row[c.key]" :placeholder="c.label" @input="onInput(i, c, $event)">
            </div>
            <div class="fpa-rep-ctrls">
                <button type="button" class="fpa-icon-btn" :disabled="i === 0" @click="up(i)" title="Move up">↑</button>
                <button type="button" class="fpa-icon-btn" :disabled="i === rows.length - 1" @click="down(i)" title="Move down">↓</button>
                <button type="button" class="fpa-icon-btn fpa-icon-danger" @click="del(i)" title="Remove">×</button>
            </div>
        </div>
        <button type="button" class="fpa-btn fpa-btn-sm" @click="add">+ {{ addLabel }}</button>
    </div>`
};
