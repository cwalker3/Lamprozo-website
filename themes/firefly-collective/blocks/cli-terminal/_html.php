<?php
    if ( ! defined( 'ABSPATH' ) ) exit;

    if ( ! function_exists( 'firefly_cli_terminal_html' ) ) :
    function firefly_cli_terminal_html() {
        ob_start();
        ?>
<div class="cli-box reveal" aria-hidden="true">
    <div class="cli-head">
        <div class="dots"><span></span><span></span><span></span></div>
        <span class="mono">~/projects/default</span>
    </div>
    <div class="cli-body" data-cli-demo>
        <div><span class="prompt">$</span> <span class="cmd" data-cli-type>firefly views create pricing</span><span class="cursor"></span></div>
        <div class="ok" data-cli-out="0">&nbsp;</div>
        <div class="ok" data-cli-out="1">&nbsp;&nbsp;&check; Created views/pricing.php</div>
        <div class="ok" data-cli-out="2">&nbsp;&nbsp;&check; Created assets/css/pricing.css</div>
        <div class="ok" data-cli-out="3">&nbsp;&nbsp;&check; Created assets/js/pricing.js</div>
        <div class="ok" data-cli-out="4">&nbsp;&nbsp;&check; Registered in $valid_views</div>
        <div class="ok" data-cli-out="5">&nbsp;&nbsp;&check; Added to get_view_assets()</div>
        <div class="ok" data-cli-out="6">&nbsp;&nbsp;&check; Added to schema</div>
        <div class="ok" data-cli-out="7">&nbsp;&nbsp;&check; Created WordPress page</div>
    </div>
</div>
        <?php
        return ob_get_clean();
    }
    endif;
