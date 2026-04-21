<?php
    if ( ! defined( 'ABSPATH' ) ) exit;

    $name   = isset( $attributes['name'] )   ? $attributes['name']   : '';
    $tag    = isset( $attributes['tag'] )    ? $attributes['tag']    : '';
    $days   = isset( $attributes['days'] )   ? $attributes['days']   : '—';
    $accent = isset( $attributes['accent'] ) ? $attributes['accent'] : '#f5b544';
    $url    = isset( $attributes['url'] )    ? $attributes['url']    : '#';

    $mock_style = 'background: linear-gradient(180deg, ' . esc_attr( $accent ) . ' 0%, transparent 30%), linear-gradient(180deg, #202225 0%, #141416 100%);';
    $wrap = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => 'tmpl-card' ) )
        : 'class="tmpl-card"';
?>
<a <?php echo $wrap; ?> href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $name ); ?>">
    <div class="tmpl-thumb"><div class="mock" style="<?php echo $mock_style; ?>"></div></div>
    <div class="tmpl-body">
        <span class="title"><?php echo wp_kses_post( $name ); ?></span>
        <span class="meta">
            <span><?php echo wp_kses_post( $tag ); ?></span>
            <span class="ok">shipped in <?php echo wp_kses_post( $days ); ?> days</span>
        </span>
    </div>
</a>
