<?php
    /**
     * firefly/pillar — server render
     * Keep SVG paths in sync with pillar/index.js ICONS table.
     */
    if ( ! defined( 'ABSPATH' ) ) exit;

    $icon_name   = isset( $attributes['iconName'] )    ? $attributes['iconName']    : 'schema';
    $title       = isset( $attributes['title'] )       ? $attributes['title']       : '';
    $description = isset( $attributes['description'] ) ? $attributes['description'] : '';
    $meta        = isset( $attributes['meta'] )        ? $attributes['meta']        : '';

    $icons = array(
        'schema'  => '<path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>',
        'scoping' => '<rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.75"/><path d="M9 10h6M9 14h4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>',
        'cli'     => '<path d="M8 9l-4 3 4 3M16 9l4 3-4 3" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
        'pwa'     => '<path d="M3 12a9 9 0 1 0 9-9" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><path d="M12 3v9h9" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>',
        'shield'  => '<path d="M12 2L4 6v6c0 5 4 9 8 10 4-1 8-5 8-10V6l-8-4z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>',
        'geo'     => '<circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.75"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>',
    );
    $icon_inner = isset( $icons[ $icon_name ] ) ? $icons[ $icon_name ] : $icons['schema'];

    $wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
        ? get_block_wrapper_attributes( array( 'class' => 'pillar' ) )
        : 'class="pillar"';
?>
<article <?php echo $wrapper_attributes; ?>>
    <span class="icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><?php echo $icon_inner; ?></svg></span>
    <?php if ( $title !== '' ) : ?>
        <h3 class="wp-block-heading"><?php echo wp_kses_post( $title ); ?></h3>
    <?php endif; ?>
    <?php if ( $description !== '' ) : ?>
        <p><?php echo wp_kses_post( $description ); ?></p>
    <?php endif; ?>
    <?php if ( $meta !== '' ) : ?>
        <div class="meta"><?php echo wp_kses_post( $meta ); ?></div>
    <?php endif; ?>
</article>
