<?php
    if ( ! defined( 'ABSPATH' ) ) exit;
    $name  = isset( $attributes['name'] )  ? $attributes['name']  : '';
    $price = isset( $attributes['price'] ) ? $attributes['price'] : '';
    $wrap  = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => 'tier' ) )
        : 'class="tier"';
?>
<div <?php echo $wrap; ?>>
    <strong><?php echo wp_kses_post( $name ); ?></strong>
    <span class="price"><?php echo wp_kses_post( $price ); ?></span>
</div>
