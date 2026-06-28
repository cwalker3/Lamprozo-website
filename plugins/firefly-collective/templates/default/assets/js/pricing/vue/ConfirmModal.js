// ConfirmModal.js — promise-style confirm/alert dialog (delete, clone, save
// warnings). Replaces DialogService. Enter confirms, Esc cancels.

export const ConfirmModal = {
    props: { state: Object }, // { title, message, confirmText, cancelText, danger, alert }
    emits: ['resolve'],
    mounted() {
        this._onKey = (e) => {
            if (e.key === 'Escape') { this.$emit('resolve', false); }
            else if (e.key === 'Enter') { this.$emit('resolve', true); }
        };
        document.addEventListener('keydown', this._onKey);
        this.$nextTick(() => { if (this.$refs.ok) this.$refs.ok.focus(); });
    },
    beforeUnmount() { document.removeEventListener('keydown', this._onKey); },
    template: `
    <div class="fpa-modal-overlay" @click.self="$emit('resolve', false)">
        <div class="fpa-modal" role="dialog" aria-modal="true">
            <h3>{{ state.title }}</h3>
            <p v-if="state.message" v-html="state.message"></p>
            <div class="fpa-modal-actions">
                <button v-if="!state.alert" type="button" class="fpa-btn fpa-btn-ghost" @click="$emit('resolve', false)">{{ state.cancelText || 'Cancel' }}</button>
                <button ref="ok" type="button" class="fpa-btn" :class="state.danger ? 'fpa-btn-danger' : 'fpa-btn-primary'" @click="$emit('resolve', true)">{{ state.confirmText || 'Confirm' }}</button>
            </div>
        </div>
    </div>`
};
