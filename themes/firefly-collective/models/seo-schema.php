<?php
/**
 * Firefly SEO — JSON-LD structured data + meta description + canonical + robots.
 *
 * Promoted from templates/default/models/schema.php to framework level so every
 * template inherits structured data on every singular post/page.
 *
 * Per-page _seo_* overrides:
 *   - _seo_description  → wins over GEO summary + excerpt for meta description + schema description
 *   - _seo_canonical    → overrides the self-canonical
 *   - _seo_robots_noindex / _seo_robots_nofollow → page-level robots; dev/localhost still forces noindex,nofollow
 *
 * Publisher (organization name + logo) is resolved via firefly_get_publisher_organization()
 * which is filterable via the `firefly_publisher_organization` filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Return the publisher organization for schema.org Organization markup.
 *
 * Defaults: site title (Settings > General > Site Title) + active template's
 * /images/logo.webp. Override per-site via:
 *
 *   add_filter( 'firefly_publisher_organization', function() {
 *       return array( 'name' => 'Acme', 'logo_url' => 'https://...' );
 *   } );
 *
 * Returns array( 'name' => string, 'logo_url' => string ).
 */
function firefly_get_publisher_organization() {
    $template = function_exists( 'firefly_collective_get_active_template' )
        ? firefly_collective_get_active_template()
        : 'default';
    $defaults = array(
        'name'     => get_bloginfo( 'name' ),
        'logo_url' => get_template_directory_uri() . '/templates/' . $template . '/images/logo.webp',
    );
    return apply_filters( 'firefly_publisher_organization', $defaults );
}

/**
 * Resolve the meta description for the current request.
 *
 * Override chain (singular):
 *   _seo_description  →  _geo_summary  →  post excerpt
 *
 * Always truncated to ~160 chars at a word boundary so the rendered meta tag
 * stays under Google's display limit.
 */
function firefly_get_meta_description( $post_id = null ) {
    if ( ! $post_id ) {
        $post_id = get_the_ID();
    }
    if ( ! $post_id ) {
        return '';
    }

    $seo = get_post_meta( $post_id, '_seo_description', true );
    if ( ! empty( $seo ) ) {
        $description = $seo;
    } else {
        $geo = get_post_meta( $post_id, '_geo_summary', true );
        $description = $geo ?: get_the_excerpt( $post_id );
    }

    if ( strlen( $description ) > 160 ) {
        $description = substr( $description, 0, 157 ) . '...';
    }
    return $description;
}

/**
 * Output JSON-LD schema for blog posts. Hooked to wp_head priority 5.
 * Only runs on single posts (not pages, not archives).
 */
function firefly_output_post_schema() {
    if ( ! is_singular( 'post' ) ) {
        return;
    }

    global $post;
    $post_id = $post->ID;

    // GEO + SEO meta resolution
    $geo_article_type = get_post_meta( $post_id, '_geo_article_type', true ) ?: 'BlogPosting';
    $geo_key_facts    = get_post_meta( $post_id, '_geo_key_facts', true );
    $geo_faq          = get_post_meta( $post_id, '_geo_faq', true );

    $key_facts = $geo_key_facts ? json_decode( $geo_key_facts, true ) : array();
    $faqs      = $geo_faq ? json_decode( $geo_faq, true ) : array();

    $title          = get_the_title( $post_id );
    $description    = firefly_get_meta_description( $post_id );
    $url            = get_permalink( $post_id );
    $published      = get_the_date( 'c', $post_id );
    $modified       = get_the_modified_date( 'c', $post_id );
    $featured_image = get_the_post_thumbnail_url( $post_id, 'full' );

    $author_id   = get_post_field( 'post_author', $post_id );
    $author_name = get_the_author_meta( 'display_name', $author_id );
    $author_url  = get_author_posts_url( $author_id );

    $content    = get_the_content( null, false, $post_id );
    $word_count = str_word_count( strip_tags( $content ) );

    $publisher = firefly_get_publisher_organization();

    $schema = array(
        '@context'      => 'https://schema.org',
        '@type'         => $geo_article_type,
        'headline'      => $title,
        'description'   => $description,
        'url'           => $url,
        'datePublished' => $published,
        'dateModified'  => $modified,
        'wordCount'     => $word_count,
        'author'        => array(
            '@type' => 'Person',
            'name'  => $author_name,
            'url'   => $author_url,
        ),
        'publisher' => array(
            '@type' => 'Organization',
            'name'  => $publisher['name'],
            'url'   => home_url( '/' ),
            'logo'  => array(
                '@type' => 'ImageObject',
                'url'   => $publisher['logo_url'],
            ),
        ),
        'mainEntityOfPage' => array(
            '@type' => 'WebPage',
            '@id'   => $url,
        ),
    );

    if ( $featured_image ) {
        $schema['image'] = array(
            '@type' => 'ImageObject',
            'url'   => $featured_image,
        );
    }

    if ( ! empty( $key_facts ) ) {
        $citations = array();
        foreach ( $key_facts as $fact ) {
            if ( ! empty( $fact['fact'] ) ) {
                $citation = array(
                    '@type' => 'Claim',
                    'text'  => $fact['fact'],
                );
                if ( ! empty( $fact['source'] ) ) {
                    $citation['citation'] = $fact['source'];
                }
                $citations[] = $citation;
            }
        }
        if ( ! empty( $citations ) ) {
            $schema['hasPart'] = $citations;
        }
    }

    echo "\n<!-- Firefly SEO/GEO Schema -->\n";
    echo '<script type="application/ld+json">' . "\n";
    echo json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    echo "\n</script>\n";

    // FAQ schema (separate document; only emitted when FAQs exist)
    if ( ! empty( $faqs ) ) {
        $faq_schema = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array(),
        );

        foreach ( $faqs as $faq ) {
            if ( ! empty( $faq['question'] ) && ! empty( $faq['answer'] ) ) {
                $faq_schema['mainEntity'][] = array(
                    '@type'          => 'Question',
                    'name'           => $faq['question'],
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => $faq['answer'],
                    ),
                );
            }
        }

        if ( ! empty( $faq_schema['mainEntity'] ) ) {
            echo '<script type="application/ld+json">' . "\n";
            echo json_encode( $faq_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            echo "\n</script>\n";
        }
    }

    // BreadcrumbList — Home → Blog → current post. The blog landing URL is
    // taken from the WP "posts page" if set, else falls back to /blog.
    $posts_page_id = (int) get_option( 'page_for_posts' );
    $blog_url      = $posts_page_id ? get_permalink( $posts_page_id ) : home_url( '/blog' );

    $breadcrumb_schema = array(
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => array(
            array(
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => 'Home',
                'item'     => home_url( '/' ),
            ),
            array(
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => 'Blog',
                'item'     => $blog_url,
            ),
            array(
                '@type'    => 'ListItem',
                'position' => 3,
                'name'     => $title,
                'item'     => $url,
            ),
        ),
    );

    echo '<script type="application/ld+json">' . "\n";
    echo json_encode( $breadcrumb_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    echo "\n</script>\n";
}
add_action( 'wp_head', 'firefly_output_post_schema', 5 );

/**
 * Output meta description tag. Hooked to wp_head priority 1.
 * Routes through firefly_get_meta_description() so _seo_description wins
 * over the GEO/excerpt fallback chain.
 */
function firefly_output_meta_description() {
    if ( ! is_singular() ) {
        return;
    }
    $description = firefly_get_meta_description( get_queried_object_id() );
    if ( $description ) {
        echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
    }
}
add_action( 'wp_head', 'firefly_output_meta_description', 1 );

/**
 * Output canonical URL. Hooked to wp_head priority 1.
 * _seo_canonical postmeta overrides the self-canonical when present.
 */
function firefly_output_canonical_url() {
    if ( ! is_singular() ) {
        return;
    }
    $post_id   = get_queried_object_id();
    $override  = get_post_meta( $post_id, '_seo_canonical', true );
    $canonical = ! empty( $override ) ? $override : get_permalink( $post_id );
    echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
}
add_action( 'wp_head', 'firefly_output_canonical_url', 1 );

/**
 * Output robots meta tag. Hooked to wp_head priority 1.
 *
 * Dev environments and dev.* / localhost domains always emit noindex,nofollow
 * to keep them out of search indexes. On production sites, per-page
 * _seo_robots_noindex / _seo_robots_nofollow toggles compose into the value.
 */
function firefly_output_robots_meta() {
    $is_dev        = defined( 'FIREFLY_DEV' ) || defined( 'FIREFLY_LIVE_DEV' );
    $host          = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
    $is_dev_domain = ( strpos( $host, 'dev.' ) === 0 || strpos( $host, 'localhost' ) !== false );

    if ( $is_dev || $is_dev_domain ) {
        echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
        return;
    }

    if ( is_singular() ) {
        $post_id  = get_queried_object_id();
        $noindex  = (bool) get_post_meta( $post_id, '_seo_robots_noindex', true );
        $nofollow = (bool) get_post_meta( $post_id, '_seo_robots_nofollow', true );

        if ( $noindex || $nofollow ) {
            $parts = array();
            $parts[] = $noindex ? 'noindex' : 'index';
            $parts[] = $nofollow ? 'nofollow' : 'follow';
            echo '<meta name="robots" content="' . esc_attr( implode( ', ', $parts ) ) . '" />' . "\n";
        }
    }
}
add_action( 'wp_head', 'firefly_output_robots_meta', 1 );

/* ----------------------------------------------------------------------------
 * GEO helpers — kept here for backward compatibility with any caller that
 * still relies on these names (template-snippets.php's
 * firefly_get_post_geo_data references _geo_* meta directly, not these).
 * ------------------------------------------------------------------------- */

function firefly_get_geo_summary( $post_id = null ) {
    if ( ! $post_id ) $post_id = get_the_ID();
    return get_post_meta( $post_id, '_geo_summary', true ) ?: '';
}

function firefly_get_geo_article_type( $post_id = null ) {
    if ( ! $post_id ) $post_id = get_the_ID();
    return get_post_meta( $post_id, '_geo_article_type', true ) ?: 'BlogPosting';
}

function firefly_get_geo_key_facts( $post_id = null ) {
    if ( ! $post_id ) $post_id = get_the_ID();
    $json = get_post_meta( $post_id, '_geo_key_facts', true );
    return $json ? json_decode( $json, true ) : array();
}

function firefly_get_geo_faq( $post_id = null ) {
    if ( ! $post_id ) $post_id = get_the_ID();
    $json = get_post_meta( $post_id, '_geo_faq', true );
    return $json ? json_decode( $json, true ) : array();
}
