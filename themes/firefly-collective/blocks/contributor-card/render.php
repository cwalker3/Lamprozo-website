<?php
    if ( ! defined( 'ABSPATH' ) ) exit;

    $number      = isset( $attributes['number'] )      ? $attributes['number']      : '';
    $audience    = isset( $attributes['audience'] )    ? $attributes['audience']    : '';
    $title       = isset( $attributes['title'] )       ? $attributes['title']       : '';
    $description = isset( $attributes['description'] ) ? $attributes['description'] : '';
    $chips_raw   = isset( $attributes['chips'] )       ? $attributes['chips']       : '';

    $chips = array_filter( array_map( 'trim', explode( '·', $chips_raw ) ) );

    $wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => 'contrib-card' ) )
        : 'class="contrib-card"';
?>
<article <?php echo $wrapper_attributes; ?>>
    <span class="arrow-out" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M7 17L17 7M17 7H9M17 7v8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
    </span>
    <span class="n"><?php echo esc_html( $number ); ?> &middot; <?php echo esc_html( $audience ); ?></span>
    <?php if ( $title !== '' ) : ?>
        <h3 class="wp-block-heading"><?php echo wp_kses_post( $title ); ?></h3>
    <?php endif; ?>
    <?php if ( $description !== '' ) : ?>
        <p><?php echo wp_kses_post( $description ); ?></p>
    <?php endif; ?>
    <?php if ( ! empty( $chips ) ) : ?>
        <div class="tools">
            <?php foreach ( $chips as $chip ) : ?>
                <span class="chip"><?php echo esc_html( $chip ); ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>
