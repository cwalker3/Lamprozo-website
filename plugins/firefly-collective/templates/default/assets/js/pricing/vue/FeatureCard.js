// FeatureCard.js — a single feature; nests OptionCards.
// Phase 2: built-in scalar fields + dynamic custom fields + expand/collapse
// + nested options. Structural ops (add/clone/delete) arrive in Phase 4.

import { Field } from './fields/Field.js';
import { DynamicFields } from './fields/DynamicFields.js';
import { OptionCard } from './OptionCard.js';

const FEATURE_KNOWN = ['featureName', 'description', 'options', 'recurring', 'link_name'];

export const FeatureCard = {
    name: 'FeatureCard',
    components: { Field, DynamicFields, OptionCard },
    props: { feature: Object, index: Number },
    data() { return { expanded: false, known: FEATURE_KNOWN }; },
    template: `
    <div class="fpa-card fpa-feature">
        <div class="fpa-card-head" @click="expanded = !expanded">
            <button type="button" class="fpa-toggle" :class="{ open: expanded }" aria-label="Toggle">▸</button>
            <strong>{{ feature.featureName || 'Untitled feature' }}</strong>
            <span class="fpa-spacer"></span>
            <span class="fpa-chip">{{ (feature.options || []).length }} option{{ (feature.options || []).length === 1 ? '' : 's' }}</span>
            <span class="fpa-chip" v-if="feature.recurring">recurring</span>
        </div>
        <div class="fpa-card-body" v-show="expanded">
            <Field label="Feature name" type="text" v-model="feature.featureName" />
            <Field label="Description" type="textarea" v-model="feature.description.text" />
            <Field type="checkbox" label="Recurring billing" v-model="feature.recurring" />
            <DynamicFields :obj="feature" :known="known" />

            <div class="fpa-section">
                <div class="fpa-section-head">{{ feature.featureName || 'Feature' }}'s options</div>
                <OptionCard v-for="(o, oi) in (feature.options || [])" :key="oi" :option="o" :feature="feature" :f-index="index" :index="oi" />
                <div v-if="!(feature.options || []).length" class="fpa-empty fpa-empty-sm">No options.</div>
            </div>
        </div>
    </div>`
};
