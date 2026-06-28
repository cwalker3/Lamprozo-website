// OptionCard.js — a single option; nests AddonCards.
// Pricing-type conditional visibility (0 static / 1 range / 2 price-options),
// quantity-threshold discounts (toggle + repeatable rows), max add-ons,
// dynamic custom fields, and nested addons.

import { Field } from './fields/Field.js';
import { SelectField } from './fields/SelectField.js';
import { DynamicFields } from './fields/DynamicFields.js';
import { RepeatableRows } from './fields/RepeatableRows.js';
import { MaxField } from './fields/MaxField.js';
import { AddonCard } from './AddonCard.js';
import { ensureArrayObj, ensureNumber } from './util.js';

const OPTION_KNOWN = [
    'optionName', 'description', 'interval', 'pricingType',
    'priceFloor', 'priceCeiling', 'staticPrice', 'priceOptions', 'optionMetric',
    'addons', 'link_name', 'thresholdDiscounts', 'enableThresholdDiscounts', 'maxAddons'
];

export const OptionCard = {
    name: 'OptionCard',
    components: { Field, SelectField, DynamicFields, RepeatableRows, MaxField, AddonCard },
    props: { option: Object, feature: Object, fIndex: Number, index: Number },
    data() {
        return {
            expanded: false,
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
        // Guarantee the array-obj wrappers + numeric max exist before binding.
        ensureArrayObj(this.option, 'priceOptions');
        ensureArrayObj(this.option, 'thresholdDiscounts');
        ensureNumber(this.option, 'maxAddons', -1);
        if (this.option.enableThresholdDiscounts === undefined) {
            this.option.enableThresholdDiscounts = this.option.thresholdDiscounts.value.types.length > 0;
        }
    },
    computed: {
        ptype() { return (this.option.pricingType && this.option.pricingType.value && this.option.pricingType.value.selected) || 0; },
        priceOptionRows() { return this.option.priceOptions.value.types; },
        thresholdRows() { return this.option.thresholdDiscounts.value.types; },
        thresholdsEnabled: {
            get() { return !!this.option.enableThresholdDiscounts; },
            set(v) { this.option.enableThresholdDiscounts = v; }
        }
    },
    template: `
    <div class="fpa-card fpa-option">
        <div class="fpa-card-head" @click="expanded = !expanded">
            <button type="button" class="fpa-toggle" :class="{ open: expanded }" aria-label="Toggle">▸</button>
            <strong>{{ option.optionName || 'Untitled option' }}</strong>
            <span class="fpa-spacer"></span>
            <span class="fpa-chip">{{ (option.addons || []).length }} add-on{{ (option.addons || []).length === 1 ? '' : 's' }}</span>
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
                <div v-if="!(option.addons || []).length" class="fpa-empty fpa-empty-sm">No add-ons.</div>
            </div>
        </div>
    </div>`
};
