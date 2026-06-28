// FeatureCard.js — a single feature; clone/delete actions, add-option form,
// nested options, name-change tracking, dynamic custom fields.

import { Field } from './fields/Field.js';
import { DynamicFields } from './fields/DynamicFields.js';
import { OptionCard } from './OptionCard.js';
import { AddEntityForm } from './AddEntityForm.js';
import { schemaOptionsForOption, buildOption, clone } from './create.js';

const FEATURE_KNOWN = ['featureName', 'description', 'options', 'recurring', 'link_name'];

export const FeatureCard = {
    name: 'FeatureCard',
    components: { Field, DynamicFields, OptionCard, AddEntityForm },
    inject: ['store', 'ui'],
    props: { feature: Object, index: Number },
    data() { return { expanded: false, known: FEATURE_KNOWN, addingOption: false }; },
    computed: {
        optionSchema() { return schemaOptionsForOption(this.store.dataManager.data, this.feature); }
    },
    watch: {
        'feature.featureName'(n, o) {
            if (n !== o) this.store.dataManager.recordNameChange('features', [this.index], o, n);
        }
    },
    methods: {
        async cloneSelf() {
            const ok = await this.ui.confirm({ title: 'Clone “' + (this.feature.featureName || 'feature') + '”?', message: 'Creates a full copy you can rename.', confirmText: 'Clone' });
            if (ok) this.store.dataManager.addFeature(clone(this.feature));
        },
        async deleteSelf() {
            const ok = await this.ui.confirm({ title: 'Delete “' + (this.feature.featureName || 'feature') + '”?', message: 'This removes the feature and all its options and add-ons.', confirmText: 'Delete', danger: true });
            if (ok) this.store.dataManager.deleteFeature(this.index);
        },
        addOption(payload) {
            const o = buildOption(this.store.config, this.store.dataManager.data, this.feature, payload.schemaValue, payload.name, payload.description);
            this.store.dataManager.addOption(this.index, o);
            this.addingOption = false;
            this.expanded = true;
        }
    },
    template: `
    <div class="fpa-card fpa-feature">
        <div class="fpa-card-head" @click="expanded = !expanded">
            <button type="button" class="fpa-toggle" :class="{ open: expanded }" aria-label="Toggle">▸</button>
            <strong>{{ feature.featureName || 'Untitled feature' }}</strong>
            <span class="fpa-spacer"></span>
            <span class="fpa-chip">{{ (feature.options || []).length }} option{{ (feature.options || []).length === 1 ? '' : 's' }}</span>
            <span class="fpa-chip" v-if="feature.recurring">recurring</span>
            <span class="fpa-actions">
                <button type="button" class="fpa-icon-btn" title="Clone feature" @click.stop="cloneSelf">⧉</button>
                <button type="button" class="fpa-icon-btn fpa-icon-danger" title="Delete feature" @click.stop="deleteSelf">🗑</button>
            </span>
        </div>
        <div class="fpa-card-body" v-show="expanded">
            <Field label="Feature name" type="text" v-model="feature.featureName" />
            <Field label="Description" type="textarea" v-model="feature.description.text" />
            <Field type="checkbox" label="Recurring billing" v-model="feature.recurring" />
            <DynamicFields :obj="feature" :known="known" />

            <div class="fpa-section">
                <div class="fpa-section-head">{{ feature.featureName || 'Feature' }}'s options</div>
                <OptionCard v-for="(o, oi) in (feature.options || [])" :key="oi" :option="o" :feature="feature" :f-index="index" :index="oi" />
                <div v-if="!(feature.options || []).length && !addingOption" class="fpa-empty fpa-empty-sm">No options.</div>
                <add-entity-form v-if="addingOption" level="option" :schema-options="optionSchema"
                    @create="addOption" @cancel="addingOption = false"></add-entity-form>
                <button v-else type="button" class="fpa-btn fpa-btn-sm fpa-add-row" @click="addingOption = true">+ Add option</button>
            </div>
        </div>
    </div>`
};
