<?php
    if ( ! defined( 'ABSPATH' ) ) exit;
    $label = isset( $attributes['label'] ) ? $attributes['label'] : '';
    $sites_raw = isset( $attributes['sites'] ) ? $attributes['sites'] : '';

    $sites = array_filter( array_map( function ( $chunk ) {
        $parts = explode( '|', trim( $chunk ) );
        return array(
            'name'  => isset( $parts[0] ) ? trim( $parts[0] ) : '',
            'count' => isset( $parts[1] ) ? trim( $parts[1] ) : '',
        );
    }, explode( ';', $sites_raw ) ), function ( $s ) { return ! empty( $s['name'] ); } );

    $wrap = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => 'container' ) )
        : 'class="container"';
?>
<div <?php echo $wrap; ?>>
    <div class="trust-grid">
        <div class="trust-label"><?php echo wp_kses_post( $label ); ?></div>
        <div class="trust-items">
            <?php foreach ( $sites as $s ) : ?>
                <span class="site"><?php echo esc_html( $s['name'] ); ?><?php if ( ! empty( $s['count'] ) ) : ?><span class="count"><?php echo esc_html( $s['count'] ); ?></span><?php endif; ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
