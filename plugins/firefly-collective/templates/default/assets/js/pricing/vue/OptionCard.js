// OptionCard.js — a single option; nests AddonCards.
// Pricing-type conditional fields, price-options + quantity-discounts,
// max add-ons, clone/delete, add-addon form, name tracking, dynamic fields.

import { Field } from './fields/Field.js';
import { SelectField } from './fields/SelectField.js';
import { DynamicFields } from './fields/DynamicFields.js';
import { RepeatableRows } from './fields/RepeatableRows.js';
import { MaxField } from './fields/MaxField.js';
import { AddonCard } from './AddonCard.js';
import { AddEntityForm } from './AddEntityForm.js';
import { ensureArrayObj, ensureNumber } from './util.js';
import { schemaOptionsForAddon, buildAddon, clone } from './create.js';

const OPTION_KNOWN = [
    'optionName', 'description', 'interval', 'pricingType',
    'priceFloor', 'priceCeiling', 'staticPrice', 'priceOptions', 'optionMetric',
    'addons', 'link_name', 'thresholdDiscounts', 'enableThresholdDiscounts', 'maxAddons'
];

export const OptionCard = {
    name: 'OptionCard',
    components: { Field, SelectField, DynamicFields, RepeatableRows, MaxField, AddonCard, AddEntityForm },
    inject: ['store', 'ui'],
    props: { option: Object, feature: Object, fIndex: Number, index: Number },
    data() {
        return {
            expanded: false,
            addingAddon: false,
            known: OPTION_KNOWN,
            priceOptionCols: [
                { key: 'label', label: 'Label', type: 'text' },
                { key: 'price', label: 'Price', type: 'number' }
            ],
            thresholdCols: [
                { key: 'itemCount', label: 'Item count', type: 'number' },
                { key: 'discount', label: 'Discount %', type: 'number' }
            ]
        };
    },
    created() {
        ensureArrayObj(this.option, 'priceOptions');
        ensureArrayObj(this.option, 'thresholdDiscounts');
        ensureNumber(this.option, 'maxAddons', -1);
        if (this.option.enableThresholdDiscounts === undefined) {
            this.option.enableThresholdDiscounts = this.option.thresholdDiscounts.value.types.length > 0;
        }
    },
    watch: {
        'option.optionName'(n, o) {
            if (n !== o) this.store.dataManager.recordNameChange('options', [this.fIndex, this.index], o, n);
        }
    },
    computed: {
        ptype() { return (this.option.pricingType && this.option.pricingType.value && this.option.pricingType.value.selected) || 0; },
        priceOptionRows() { return this.option.priceOptions.value.types; },
        thresholdRows() { return this.option.thresholdDiscounts.value.types; },
        addonSchema() { return schemaOptionsForAddon(this.store.dataManager.data, this.feature, this.option); },
        thresholdsEnabled: {
            get() { return !!this.option.enableThresholdDiscounts; },
            set(v) { this.option.enableThresholdDiscounts = v; }
        }
    },
    methods: {
        async cloneSelf() {
            const ok = await this.ui.confirm({ title: 'Clone “' + (this.option.optionName || 'option') + '”?', message: 'Creates a copy (with its add-ons) you can rename.', confirmText: 'Clone' });
            if (ok) { this.feature.options.splice(this.index + 1, 0, clone(this.option)); this.store.dataManager.save(); }
        },
        async deleteSelf() {
            const ok = await this.ui.confirm({ title: 'Delete “' + (this.option.optionName || 'option') + '”?', message: 'This removes the option and all its add-ons.', confirmText: 'Delete', danger: true });
            if (ok) this.store.dataManager.deleteOption(this.fIndex, this.index);
        },
        addAddon(payload) {
            const a = buildAddon(this.store.config, this.store.dataManager.data, this.feature, this.option, payload.schemaValue, payload.name, payload.description);
            this.store.dataManager.addAddon(this.fIndex, this.index, a);
            this.addingAddon = false;
            this.expanded = true;
        }
    },
    template: `
    <div class="fpa-card fpa-option">
        <div class="fpa-card-head" @click="expanded = !expanded">
            <button type="button" class="fpa-toggle" :class="{ open: expanded }" aria-label="Toggle">▸</button>
            <strong>{{ option.optionName || 'Untitled option' }}</strong>
            <span class="fpa-spacer"></span>
            <span class="fpa-chip">{{ (option.addons || []).length }} add-on{{ (option.addons || []).length === 1 ? '' : 's' }}</span>
            <span class="fpa-actions">
                <button type="button" class="fpa-icon-btn" title="Clone option" @click.stop="cloneSelf">⧉</button>
                <button type="button" class="fpa-icon-btn fpa-icon-danger" title="Delete option" @click.stop="deleteSelf">🗑</button>
            </span>
        </div>
        <div class="fpa-card-body" v-show="expanded">
            <Field label="Option name" type="text" v-model="option.optionName" />
            <Field label="Description" type="textarea" v-model="option.description.text" />
            <SelectField v-if="feature.recurring && option.interval && option.interval.value"
                label="Billing interval" :types="option.interval.value.types"
                v-model:selected="option.interval.value.selected" />
            <SelectField v-if="option.pricingType && option.pricingType.value"
                label="Pricing type" :types="option.pricingType.value.types"
                v-model:selected="option.pricingType.value.selected" />

            <Field v-if="ptype === 0" label="Static price" type="number" v-model="option.staticPrice" />
            <template v-if="ptype === 1">
                <Field label="Price floor" type="number" v-model="option.priceFloor" />
                <Field label="Price ceiling" type="number" v-model="option.priceCeiling" />
            </template>
            <div v-if="ptype === 2" class="fpa-field">
                <label>Price options</label>
                <repeatable-rows :rows="priceOptionRows" :columns="priceOptionCols" add-label="Add price option"></repeatable-rows>
            </div>

            <Field label="Option metric" type="text" v-model="option.optionMetric" placeholder="e.g. subscriber" />
            <MaxField label="Max add-ons" v-model="option.maxAddons" />

            <Field type="checkbox" label="Enable quantity discounts" v-model="thresholdsEnabled" />
            <div v-if="thresholdsEnabled" class="fpa-field">
                <label>Quantity discounts</label>
                <repeatable-rows :rows="thresholdRows" :columns="thresholdCols" add-label="Add discount tier"></repeatable-rows>
            </div>

            <DynamicFields :obj="option" :known="known" />

            <div class="fpa-section">
                <div class="fpa-section-head">{{ option.optionName || 'Option' }}'s add-ons</div>
                <AddonCard v-for="(a, ai) in (option.addons || [])" :key="ai"
                    :addon="a" :option="option" :f-index="fIndex" :o-index="index" :index="ai"></AddonCard>
                <div v-if="!(option.addons || []).length && !addingAddon" class="fpa-empty fpa-empty-sm">No add-ons.</div>
                <add-entity-form v-if="addingAddon" level="addon" :schema-options="addonSchema"
                    @create="addAddon" @cancel="addingAddon = false"></add-entity-form>
                <button v-else type="button" class="fpa-btn fpa-btn-sm fpa-add-row" @click="addingAddon = true">+ Add add-on</button>
            </div>
        </div>
    </div>`
};
