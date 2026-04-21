<?php
    if ( ! defined( 'ABSPATH' ) ) exit;
    $status = isset( $attributes['status'] ) ? $attributes['status'] : '';
    $tech   = isset( $attributes['tech'] )   ? $attributes['tech']   : '';
    $wrap   = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => 'hero-meta' ) )
        : 'class="hero-meta"';
?>
<div <?php echo $wrap; ?>>
    <span><span class="dot" aria-hidden="true"></span> <?php echo wp_kses_post( $status ); ?></span>
    <span class="mono"><?php echo wp_kses_post( $tech ); ?></span>
</div>
