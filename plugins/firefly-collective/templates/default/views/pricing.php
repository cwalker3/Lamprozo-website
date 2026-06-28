<?php
/**
 * Pricing admin — Vue mount.
 *
 * The entire UI (header, feature/option/addon cards, add forms, confirm
 * dialogs, save bar, toast) is rendered by the Vue app in
 * assets/js/pricing/. Server contract is unchanged: data arrives via
 * wp_localize_script (window.pricingData / window.pricingDataSettings)
 * and saves via POST custom-api/v1/save-pricing. Styling is scoped under
 * .fpa (matches the Analytics admin design system).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div id="fpa-app" class="fpa" v-cloak>
    <div class="fpa-boot"><span class="fpa-spinner"></span> Loading pricing…</div>
</div>
