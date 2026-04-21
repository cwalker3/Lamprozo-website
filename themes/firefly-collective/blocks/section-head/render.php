<?php
    /**
     * firefly/section-head — server render
     * Output exactly matches the editor edit() output so CSS treats them identically.
     */
    if ( ! defined( 'ABSPATH' ) ) exit;

    $overline = isset( $attributes['overline'] ) ? $attributes['overline'] : '';
    $heading  = isset( $attributes['heading'] )  ? $attributes['heading']  : '';
    $lead     = isset( $attributes['lead'] )     ? $attributes['lead']     : '';
    $centered = ! empty( $attributes['centered'] );

    $classes  = 'section-head reveal';
    if ( $centered ) $classes .= ' center';

    $wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => $classes ) )
        : 'class="' . esc_attr( $classes ) . '"';
?>
<div <?php echo $wrapper_attributes; ?>>
    <?php if ( $overline !== '' ) : ?>
        <span class="overline"><?php echo wp_kses_post( $overline ); ?></span>
    <?php endif; ?>
    <?php if ( $heading !== '' ) : ?>
        <h2 class="wp-block-heading"><?php echo wp_kses_post( $heading ); ?></h2>
    <?php endif; ?>
    <?php if ( $lead !== '' ) : ?>
        <p class="lead"><?php echo wp_kses_post( $lead ); ?></p>
    <?php endif; ?>
</div>
