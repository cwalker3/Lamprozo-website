// PricingRoot.js — top-level Vue component.
// Owns the feature list + Add-feature form, the shared confirm dialog
// (provided to all cards as `ui.confirm`), and the save bar.
//
// Save flow mirrors PricingApp.handleSaveRequest/performSave exactly:
// validate → block on errors → confirm on warnings → ApiService.savePricing
// (kept module) with { pricingData, nameChanges } → clearNameChanges on
// success. Button disables to "Saving…" while in flight.

import { FeatureCard } from './FeatureCard.js';
import { AddEntityForm } from './AddEntityForm.js';
import { ConfirmModal } from './ConfirmModal.js';
import { schemaOptionsForFeature, buildFeature } from './create.js';

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

export const PricingRoot = {
    components: { FeatureCard, AddEntityForm, ConfirmModal },
    inject: ['store'],
    data() { return { confirmState: null, adding: false, saving: false, toast: null }; },
    provide() {
        return { ui: { confirm: (o) => this.openConfirm(o) } };
    },
    computed: {
        features() { return this.store.dataManager.data.features; },
        featureSchema() { return schemaOptionsForFeature(this.store.dataManager.data); }
    },
    methods: {
        openConfirm(o) { return new Promise((res) => { this.confirmState = Object.assign({}, o, { resolve: res }); }); },
        onConfirmResolve(v) {
            if (!this.confirmState) return;
            const r = this.confirmState.resolve;
            this.confirmState = null;
            r(v);
        },
        notify(msg, err) {
            this.toast = { msg: msg, err: !!err };
            setTimeout(() => { this.toast = null; }, 3200);
        },
        addFeature(payload) {
            const f = buildFeature(this.store.config, this.store.dataManager.data, payload.schemaValue, payload.name, payload.description, payload.recurring);
            this.store.dataManager.addFeature(f);
            this.adding = false;
        },
        async handleSave() {
            if (this.saving) return;
            const dm = this.store.dataManager;
            const data = dm.getData();
            const v = this.store.validation.validatePricingData(data);

            if (!v.isValid) {
                await this.openConfirm({ title: 'Cannot save', message: esc(v.errors[0]), alert: true, confirmText: 'OK', danger: true });
                return;
            }
            if (v.warnings && v.warnings.length) {
                const ok = await this.openConfirm({
                    title: 'Warnings found',
                    message: v.warnings.map(esc).join('<br>') + '<br><br>Continue saving?',
                    confirmText: 'Save anyway'
                });
                if (!ok) return;
            }
            await this.performSave();
        },
        async performSave() {
            const dm = this.store.dataManager;
            this.saving = true;
            try {
                await this.store.api.savePricing(dm.getData(), dm.getNameChanges());
                dm.clearNameChanges();
                this.notify('Pricing saved');
            } catch (e) {
                await this.openConfirm({ title: 'Save failed', message: esc(e && e.message ? e.message : e), alert: true, confirmText: 'OK', danger: true });
            } finally {
                this.saving = false;
            }
        }
    },
    template: `
    <div class="fpa-wrap fpa-fade">
        <div class="fpa-topbar">
            <div class="fpa-title">
                <h1>Pricing</h1>
                <span class="fpa-sub">{{ features.length }} feature{{ features.length === 1 ? '' : 's' }}</span>
            </div>
            <button v-if="!adding" type="button" class="fpa-btn fpa-btn-green" @click="adding = true">+ Add feature</button>
        </div>

        <div v-if="!features.length && !adding" class="fpa-empty">No features yet. Click “Add feature” to start.</div>

        <feature-card v-for="(f, i) in features" :key="i" :feature="f" :index="i"></feature-card>

        <add-entity-form v-if="adding" level="feature" :schema-options="featureSchema"
            @create="addFeature" @cancel="adding = false"></add-entity-form>

        <div class="fpa-savebar">
            <span class="fpa-savebar-hint">Changes are kept locally until you apply them.</span>
            <button type="button" class="fpa-btn fpa-btn-primary" :disabled="saving" @click="handleSave">
                {{ saving ? 'Saving…' : 'Apply changes' }}
            </button>
        </div>

        <confirm-modal v-if="confirmState" :state="confirmState" @resolve="onConfirmResolve"></confirm-modal>
        <div v-if="toast" class="fpa-toast" :class="{ 'is-error': toast.err }">{{ toast.msg }}</div>
    </div>`
};
