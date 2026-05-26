<?php
/**
 * Firefly SEO — Open Graph + Twitter Card + <title> helper.
 *
 * Promoted from templates/default/models/meta.php to framework level so every
 * template inherits OG / Twitter / title behavior. Per-page _seo_* meta keys
 * (registered by firefly-projects/includes/models/seo-post.php) override the
 * auto-derived defaults; the override chain is documented inline.
 *
 * Title is not via WP `title-tag` — each template's header.php hand-rolls a
 * <title> tag, and firefly_get_document_title() is the routing point so
 * _seo_title overrides take effect with a one-line change per header.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolve the <title> tag content for the current request.
 *
 * Override chain (singular only):
 *   _seo_title postmeta  →  "{site name} - {page title}"
 *
 * Non-singular pages (archives, search, 404) skip the override and use the
 * site-name + page-title pattern the templates already pass in.
 */
function firefly_get_document_title( $page_title = '' ) {
    if ( is_singular() ) {
        $override = get_post_meta( get_queried_object_id(), '_seo_title', true );
        if ( ! empty( $override ) ) {
            return $override;
        }
    }
    $site = get_bloginfo( 'name' );
    if ( $page_title === '' ) {
        return $site;
    }
    return $site . ' - ' . $page_title;
}

/**
 * Resolve the OG / Twitter title for the current request.
 *
 * Override chain (singular):
 *   _seo_og_title  →  _seo_title  →  post_title  →  site name
 */
function firefly_get_og_title() {
    if ( is_singular() ) {
        $post_id = get_queried_object_id();
        $og      = get_post_meta( $post_id, '_seo_og_title', true );
        if ( ! empty( $og ) ) return $og;

        $seo = get_post_meta( $post_id, '_seo_title', true );
        if ( ! empty( $seo ) ) return $seo;

        return get_the_title( $post_id );
    }
    return get_bloginfo( 'name' );
}

/**
 * Resolve the OG / Twitter description for the current request.
 *
 * Override chain (singular):
 *   _seo_og_description  →  _seo_description  →  _geo_summary  →  excerpt  →  site tagline
 */
function firefly_get_og_description() {
    if ( is_singular() ) {
        $post_id = get_queried_object_id();

        $og = get_post_meta( $post_id, '_seo_og_description', true );
        if ( ! empty( $og ) ) return $og;

        $seo = get_post_meta( $post_id, '_seo_description', true );
        if ( ! empty( $seo ) ) return $seo;

        $geo = get_post_meta( $post_id, '_geo_summary', true );
        if ( ! empty( $geo ) ) return $geo;

        $excerpt = get_the_excerpt( $post_id );
        if ( ! empty( $excerpt ) ) return $excerpt;
    }
    return get_bloginfo( 'description' );
}

/**
 * Resolve the OG / Twitter image URL for the current request.
 *
 * Override chain (singular):
 *   _seo_og_image_id postmeta  →  featured image  →  default-og.webp from active template
 */
function firefly_get_og_image_url() {
    global $template_path_web;

    if ( is_singular() ) {
        $post_id = get_queried_object_id();

        $override_id = (int) get_post_meta( $post_id, '_seo_og_image_id', true );
        if ( $override_id > 0 ) {
            $url = wp_get_attachment_image_url( $override_id, 'full' );
            if ( $url ) return $url;
        }

        $featured = get_the_post_thumbnail_url( $post_id, 'full' );
        if ( $featured ) return $featured;
    }

    $base = $template_path_web ? $template_path_web : get_template_directory_uri();
    return $base . '/images/default-og.webp';
}

/**
 * Output Open Graph + Twitter Card meta tags. Hooked to wp_head.
 *
 * Singular pages get article semantics; non-singular fall back to website.
 * All three (title, description, image) flow through the override resolvers
 * above so _seo_og_* meta overrides take effect transparently.
 */
function set_open_graph_meta_data() {
    $title       = firefly_get_og_title();
    $description = firefly_get_og_description();
    $image       = firefly_get_og_image_url();
    $url         = is_singular() ? get_permalink() : home_url();
    $type        = is_singular() ? 'article' : 'website';

    echo '<!-- Open Graph meta tags -->' . "\n";
    echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
    echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
    if ( $image ) {
        echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
    }
    echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
    echo '<meta property="og:type" content="' . esc_attr( $type ) . '" />' . "\n";

    echo '<!-- Twitter Card meta tags -->' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
    if ( $image ) {
        echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
    }
}
add_action( 'wp_head', 'set_open_graph_meta_data' );
