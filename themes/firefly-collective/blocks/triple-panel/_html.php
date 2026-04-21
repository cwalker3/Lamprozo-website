<?php
    /**
     * Shared HTML for the triple-panel block. Used by render.php (direct) and
     * rendered as a string into index.js's dangerouslySetInnerHTML via
     * render-passthrough in the editor. Keeping the HTML here means there's
     * one source of truth; if you edit the panel markup, it changes in both
     * the editor preview and the frontend.
     */
    if ( ! defined( 'ABSPATH' ) ) exit;

    if ( ! function_exists( 'firefly_triple_panel_html' ) ) :
    function firefly_triple_panel_html() {
        ob_start();
        ?>
<div class="triple reveal" id="demo" aria-label="Three contributors editing the same site">

    <div class="panel" data-panel="gutenberg">
        <div class="panel-head">
            <span>wp-admin &middot; Gutenberg</span>
            <span class="dots"><span></span><span></span><span></span></span>
        </div>
        <div class="panel-body mono">
            <div class="line"><span class="tag-c">// pricing.html</span></div>
            <div class="line"><span class="tag-k">&lt;h1&gt;</span>Pricing<span class="tag-k">&lt;/h1&gt;</span></div>
            <div class="line"><span class="tag-k">&lt;p&gt;</span>Three plans. No credit card.<span class="tag-k">&lt;/p&gt;</span></div>
            <div class="line"><span class="tag-k">&lt;button&gt;</span>Start free<span class="tag-k">&lt;/button&gt;</span></div>
            <div class="line">&nbsp;</div>
            <div class="line"><span class="tag-c" data-gu-save>// saved &middot; synced to snippet</span></div>
        </div>
        <div class="panel-cta"><span class="live" data-gu-live>editing</span></div>
    </div>

    <div class="panel" data-panel="cli">
        <div class="panel-head">
            <span>claude-code &middot; firefly CLI</span>
            <span class="dots"><span></span><span></span><span></span></span>
        </div>
        <div class="panel-body mono" data-cli-stream>
            <div class="line"><span class="tag-a">$</span> <span data-cli-cmd></span><span class="cursor" data-cli-cursor></span></div>
            <div class="line"><span data-cli-step="0"></span></div>
            <div class="line"><span data-cli-step="1"></span></div>
            <div class="line"><span data-cli-step="2"></span></div>
            <div class="line"><span data-cli-step="3"></span></div>
            <div class="line"><span data-cli-step="4"></span></div>
        </div>
        <div class="panel-cta"><span class="live" data-cli-live>scaffolding</span></div>
    </div>

    <div class="panel" data-panel="php">
        <div class="panel-head">
            <span>vs-code &middot; pricing.php</span>
            <span class="dots"><span></span><span></span><span></span></span>
        </div>
        <div class="panel-body mono">
            <div class="line"><span class="tag-d">-</span> <span class="tag-c">// stub</span></div>
            <div class="line"><span class="tag-a">+</span> <span class="tag-k">function</span> get_pricing_tiers() {</div>
            <div class="line">&nbsp;&nbsp;<span class="tag-k">return</span> apply_filters(</div>
            <div class="line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="tag-s">'ff_pricing'</span>,</div>
            <div class="line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="tag-s">$tiers</span></div>
            <div class="line">&nbsp;&nbsp;);</div>
            <div class="line">}</div>
        </div>
        <div class="panel-cta"><span class="live" data-php-live>writing</span></div>
    </div>

    <div class="triple-output" style="grid-column: 1 / -1;">
        <span class="status">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span data-deploy-status>firefly import default &middot; 1 page created</span>
        </span>
        <span class="url">/pricing &rarr; live</span>
    </div>
</div>
        <?php
        return ob_get_clean();
    }
    endif;
