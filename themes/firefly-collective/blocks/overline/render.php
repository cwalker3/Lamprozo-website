<?php
    if ( ! defined( 'ABSPATH' ) ) exit;
    $text = isset( $attributes['text'] ) ? $attributes['text'] : '';
    $wrap = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => 'overline' ) )
        : 'class="overline"';
?>
<span <?php echo $wrap; ?>><?php echo wp_kses_post( $text ); ?></span>
