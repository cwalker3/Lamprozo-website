// PricingRoot.js — top-level Vue component for the pricing admin.
//
// Phase 2: renders editable Feature → Option → Addon cards off the reactive
// DataManager data. Add/clone/delete + save bar arrive in later phases.

import { FeatureCard } from './FeatureCard.js';

export const PricingRoot = {
    components: { FeatureCard },
    inject: ['store'],
    computed: {
        features() { return this.store.dataManager.data.features; }
    },
    template: `
    <div class="fpa-wrap fpa-fade">
        <div class="fpa-topbar">
            <div class="fpa-title">
                <h1>Pricing</h1>
                <span class="fpa-sub">{{ features.length }} feature{{ features.length === 1 ? '' : 's' }}</span>
            </div>
        </div>

        <div v-if="!features.length" class="fpa-empty">No features yet.</div>

        <feature-card v-for="(f, i) in features" :key="i" :feature="f" :index="i"></feature-card>
    </div>`
};
