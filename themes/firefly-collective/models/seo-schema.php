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
 * Self-heal moved blog-post URLs (301).
 *
 * Post permalinks are date-based (/YYYY/MM/DD/slug/). When a post's publish
 * date is corrected, its old dated URL 404s while Google (and old
 * links/shares) still point at it — a "Not found (404)" in Search Console.
 * On any 404 whose path looks like a dated post URL, resolve the post by its
 * slug (active-template scope) and 301 to its current permalink. Heals the
 * one flagged URL and any future date change automatically. Only fires on a
 * genuine 404 for a dated path, and only when a matching published post
 * exists at a different URL — so it can never loop or shadow a live page.
 */
add_action( 'template_redirect', 'firefly_heal_moved_post_url', 5 );

function firefly_heal_moved_post_url() {
    if ( ! is_404() ) {
        return;
    }
    $path = isset( $_SERVER['REQUEST_URI'] ) ? parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
    if ( ! $path || ! preg_match( '#/\d{4}/\d{2}(?:/\d{2})?/([^/]+)/?$#', $path, $m ) ) {
        return;
    }
    $found = get_posts( array(
        'name'        => sanitize_title( $m[1] ),
        'post_type'   => 'post',
        'post_status' => 'publish',
        'numberposts' => 1,
    ) );
    if ( empty( $found ) ) {
        return;
    }
    $target = get_permalink( $found[0]->ID );
    if ( $target && untrailingslashit( wp_parse_url( $target, PHP_URL_PATH ) ) !== untrailingslashit( $path ) ) {
        wp_safe_redirect( $target, 301 );
        exit;
    }
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
 * over the GEO/excerpt fallback chain. Also fires on /blog (the posts page),
 * not just singular, by routing through firefly_get_seo_post_id().
 */
function firefly_output_meta_description() {
    $post_id = function_exists( 'firefly_get_seo_post_id' ) ? firefly_get_seo_post_id() : ( is_singular() ? get_queried_object_id() : 0 );
    if ( ! $post_id ) {
        return;
    }
    $description = firefly_get_meta_description( $post_id );
    if ( $description ) {
        echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
    }
}
add_action( 'wp_head', 'firefly_output_meta_description', 1 );

/**
 * Canonical URL — single source of truth via WP core's emission path.
 *
 * Two contributors:
 *   1. _seo_canonical postmeta override → injected into the `get_canonical_url`
 *      filter so WP core's rel_canonical() emits it as the singular tag.
 *   2. Posts-page case (/blog) where WP core's rel_canonical() bails because
 *      is_singular() is false → we emit the tag directly via wp_head, with
 *      the same override-aware resolution.
 *
 * No double tag for singular pages: WP core wins, with our override applied.
 */
add_filter( 'get_canonical_url', 'firefly_filter_canonical_url', 10, 2 );

function firefly_filter_canonical_url( $canonical, $post ) {
    if ( $post && $post->ID ) {
        $override = get_post_meta( $post->ID, '_seo_canonical', true );
        if ( ! empty( $override ) ) {
            return $override;
        }
    }
    return $canonical;
}

/**
 * Emit canonical on the WP posts page (is_home() with a static blog page).
 * WP core's rel_canonical() skips this case; we fill the gap.
 */
function firefly_output_canonical_url_for_home() {
    if ( ! is_home() || is_front_page() ) {
        return;
    }
    $post_id = function_exists( 'firefly_get_seo_post_id' ) ? firefly_get_seo_post_id() : 0;
    if ( ! $post_id ) {
        return;
    }
    $override  = get_post_meta( $post_id, '_seo_canonical', true );
    $canonical = ! empty( $override ) ? $override : get_permalink( $post_id );
    echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
}
add_action( 'wp_head', 'firefly_output_canonical_url_for_home', 10 );

/**
 * Robots directives — composed via WP core's wp_robots filter (5.7+) so
 * exactly ONE <meta name="robots"> tag is emitted regardless of how many
 * subsystems contribute. Three sources funnel through this filter:
 *
 *   1. Dev / localhost domains → force noindex,nofollow (this function)
 *   2. Per-page _seo_robots_noindex / _seo_robots_nofollow (this function)
 *   3. Production indexability hints (firefly-collective plugin geo-schema)
 *
 * WP serializes the final array into one tag at the standard priority — no
 * direct echo to wp_head, no duplicate tags.
 */
function firefly_apply_robots_filters( $robots ) {
    $is_dev        = defined( 'FIREFLY_DEV' ) || defined( 'FIREFLY_LIVE_DEV' );
    $host          = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
    $is_dev_domain = ( strpos( $host, 'dev.' ) === 0 || strpos( $host, 'localhost' ) !== false );

    if ( $is_dev || $is_dev_domain ) {
        // Force noindex,nofollow on dev — overrides any other contributor.
        $robots['noindex']  = true;
        $robots['nofollow'] = true;
        unset( $robots['index'], $robots['follow'] );
        // Strip indexing hints that don't make sense once noindex is set.
        unset( $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
        return $robots;
    }

    // Thin, auto-generated listing archives with low search value and high
    // duplicate-content risk: tag archives, and date archives (/2026/,
    // /2026/05/, /2026/05/30/) which just re-list the same posts and compete
    // with the real post URLs. Author archives are noise too (and expose
    // usernames). Noindex all of them so ranking signal concentrates on the
    // canonical posts/pages. Category archives stay indexable — they're the
    // real content taxonomy. (Tags are also dropped from the sitemap in
    // seo-meta.php; date/author archives aren't in the WP core sitemap.)
    if ( is_tag() || is_date() || is_author() ) {
        $robots['noindex'] = true;
        unset( $robots['index'] );
        return $robots;
    }

    $post_id = function_exists( 'firefly_get_seo_post_id' ) ? firefly_get_seo_post_id() : ( is_singular() ? get_queried_object_id() : 0 );
    if ( $post_id ) {
        // Framework chrome pages (header/footer) are content holders, not real
        // pages. They render at /header/ and /footer/ but should never be
        // indexed — noindex them (they're also dropped from the sitemap in
        // seo-meta.php's firefly_sitemap_exclude_chrome).
        $chrome_slug = get_post_field( 'post_name', $post_id );
        if ( 'header' === $chrome_slug || 'footer' === $chrome_slug ) {
            $robots['noindex'] = true;
            unset( $robots['index'] );
        }
        if ( get_post_meta( $post_id, '_seo_robots_noindex', true ) ) {
            $robots['noindex']  = true;
            unset( $robots['index'] );
        }
        if ( get_post_meta( $post_id, '_seo_robots_nofollow', true ) ) {
            $robots['nofollow'] = true;
            unset( $robots['follow'] );
        }
    }

    return $robots;
}
add_filter( 'wp_robots', 'firefly_apply_robots_filters', 20 );

/**
 * Self-referential canonical for taxonomy/date/author archives.
 *
 * WP core's rel_canonical() only fires on singular content, so category and
 * other archive pages shipped with NO canonical tag at all (GSC "Crawled -
 * currently not indexed" is worsened by missing canonicals). Emit a clean
 * self-canonical for the archive, stripping pagination/query noise. Singular
 * + posts-page canonicals are still handled by core + the two functions
 * above — this only fills the archive gap.
 */
function firefly_output_archive_canonical() {
    if ( is_singular() || is_home() || is_front_page() ) {
        return; // handled by core rel_canonical / the posts-page function
    }
    if ( is_category() || is_tax() || is_date() ) {
        $term = get_queried_object();
        if ( is_category() || is_tax() ) {
            $link = get_term_link( $term );
        } else {
            $link = '';
        }
        if ( $link && ! is_wp_error( $link ) ) {
            echo '<link rel="canonical" href="' . esc_url( $link ) . '" />' . "\n";
        }
    }
}
add_action( 'wp_head', 'firefly_output_archive_canonical', 10 );

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
