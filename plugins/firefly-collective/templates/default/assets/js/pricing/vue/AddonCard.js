// AddonCard.js — a single addon (leaf card).
// Pricing-type conditional visibility (static / range), price-modifier type,
// grouping (groupName + synced group-threshold discounts + max group items),
// and dynamic custom fields. Group sync reuses GroupSyncService verbatim;
// because the data is reactive, sibling same-group addons update on their own.

import { Field } from './fields/Field.js';
import { SelectField } from './fields/SelectField.js';
import { DynamicFields } from './fields/DynamicFields.js';
import { RepeatableRows } from './fields/RepeatableRows.js';
import { MaxField } from './fields/MaxField.js';
import { ensureArrayObj, ensureNumber } from './util.js';
import { clone } from './create.js';

const ADDON_KNOWN = [
    'addonName', 'description', 'addOnMetric', 'pricingType',
    'floorPriceMod', 'ceilingPriceMod', 'staticPriceMod',
    'priceModifierType', 'link_name', 'groupName', 'groupThresholdDiscounts',
    'enableGrouping', 'maxGroupItems'
];

export const AddonCard = {
    name: 'AddonCard',
    components: { Field, SelectField, DynamicFields, RepeatableRows, MaxField },
    inject: ['store', 'ui'],
    props: { addon: Object, option: Object, fIndex: Number, oIndex: Number, index: Number },
    data() {
        return {
            expanded: false,
            known: ADDON_KNOWN,
            groupThresholdCols: [
                { key: 'itemCount', label: 'Item count', type: 'number' },
                { key: 'discount', label: 'Discount %', type: 'number' }
            ]
        };
    },
    created() {
        ensureArrayObj(this.addon, 'groupThresholdDiscounts');
        ensureNumber(this.addon, 'maxGroupItems', -1);
        if (this.addon.enableGrouping === undefined) {
            this.addon.enableGrouping = !!(this.addon.groupName);
        }
    },
    watch: {
        'addon.addonName'(n, o) {
            if (n !== o) this.store.dataManager.recordNameChange('addons', [this.fIndex, this.oIndex, this.index], o, n);
        }
    },
    computed: {
        ptype() { return (this.addon.pricingType && this.addon.pricingType.value && this.addon.pricingType.value.selected) || 0; },
        groupRows() { return this.addon.groupThresholdDiscounts.value.types; }
    },
    methods: {
        async cloneSelf() {
            const ok = await this.ui.confirm({ title: 'Clone “' + (this.addon.addonName || 'add-on') + '”?', message: 'Creates a copy you can rename.', confirmText: 'Clone' });
            if (ok) { this.option.addons.splice(this.index + 1, 0, clone(this.addon)); this.store.dataManager.save(); }
        },
        async deleteSelf() {
            const ok = await this.ui.confirm({ title: 'Delete “' + (this.addon.addonName || 'add-on') + '”?', message: 'This removes the add-on.', confirmText: 'Delete', danger: true });
            if (ok) this.store.dataManager.deleteAddon(this.fIndex, this.oIndex, this.index);
        },
        syncGroupThresholds() {
            if (!this.addon.enableGrouping || !this.addon.groupName) return;
            this.store.groupSync.synchronizeThresholdDiscounts(
                this.fIndex, this.oIndex, this.index, this.addon.groupName,
                this.addon.groupThresholdDiscounts.value.types, null, false
            );
        },
        syncMaxGroupItems() {
            if (!this.addon.enableGrouping || !this.addon.groupName) return;
            this.store.groupSync.synchronizeMaxGroupItems(
                this.fIndex, this.oIndex, this.index, this.addon.groupName, this.addon.maxGroupItems
            );
        }
    },
    template: `
    <div class="fpa-card fpa-addon">
        <div class="fpa-card-head" @click="expanded = !expanded">
            <button type="button" class="fpa-toggle" :class="{ open: expanded }" aria-label="Toggle">▸</button>
            <strong>{{ addon.addonName || 'Untitled add-on' }}</strong>
            <span class="fpa-spacer"></span>
            <span class="fpa-chip" v-if="addon.enableGrouping && addon.groupName">group · {{ addon.groupName }}</span>
            <span class="fpa-actions">
                <button type="button" class="fpa-icon-btn" title="Clone add-on" @click.stop="cloneSelf">⧉</button>
                <button type="button" class="fpa-icon-btn fpa-icon-danger" title="Delete add-on" @click.stop="deleteSelf">🗑</button>
            </span>
        </div>
        <div class="fpa-card-body" v-show="expanded">
            <Field label="Add-on name" type="text" v-model="addon.addonName" />
            <Field label="Description" type="textarea" v-model="addon.description.text" />
            <Field label="Add-on metric" type="text" v-model="addon.addOnMetric" placeholder="e.g. perk" />
            <SelectField v-if="addon.pricingType && addon.pricingType.value"
                label="Pricing type" :types="addon.pricingType.value.types"
                v-model:selected="addon.pricingType.value.selected" />
            <SelectField v-if="addon.priceModifierType && addon.priceModifierType.value"
                label="Price modifier" :types="addon.priceModifierType.value.types"
                v-model:selected="addon.priceModifierType.value.selected" />

            <Field v-if="ptype === 0" label="Static price modifier" type="number" v-model="addon.staticPriceMod" />
            <template v-if="ptype === 1">
                <Field label="Floor price modifier" type="number" v-model="addon.floorPriceMod" />
                <Field label="Ceiling price modifier" type="number" v-model="addon.ceilingPriceMod" />
            </template>

            <Field type="checkbox" label="Enable grouping" v-model="addon.enableGrouping" />
            <template v-if="addon.enableGrouping">
                <Field label="Group name" type="text" v-model="addon.groupName"
                    placeholder="addons sharing this name share discounts" />
                <div class="fpa-field">
                    <label>Group quantity discounts</label>
                    <repeatable-rows :rows="groupRows" :columns="groupThresholdCols"
                        add-label="Add discount tier" @change="syncGroupThresholds"></repeatable-rows>
                </div>
                <MaxField label="Max items in group" v-model="addon.maxGroupItems" @change="syncMaxGroupItems" />
            </template>

            <DynamicFields :obj="addon" :known="known" />
        </div>
    </div>`
};
