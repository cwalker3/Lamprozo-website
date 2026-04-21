<?php
    if ( ! defined( 'ABSPATH' ) ) exit;
    $items_raw = isset( $attributes['items'] ) ? $attributes['items'] : '';
    $items = array_filter( array_map( 'trim', explode( '·', $items_raw ) ) );
    $wrap = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => 'substrate-logos' ) )
        : 'class="substrate-logos"';
?>
<div <?php echo $wrap; ?>>
    <?php foreach ( $items as $it ) : ?>
        <span class="tech"><?php echo esc_html( $it ); ?></span>
    <?php endforeach; ?>
</div>
