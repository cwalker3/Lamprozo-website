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
 * Return the post ID whose _seo_* postmeta drives the current request, or 0
 * if SEO meta doesn't apply (search results, generic archives, 404).
 *
 * Singular requests use the queried post. The blog landing (`is_home()` with
 * a static "posts page") falls back to that page's post id so per-page SEO
 * still applies to /blog even though `is_singular()` returns false there.
 * Front page is handled by is_singular() since `show_on_front=page`.
 */
function firefly_get_seo_post_id() {
    if ( is_singular() ) {
        return (int) get_queried_object_id();
    }
    if ( is_home() && ! is_front_page() ) {
        $posts_page = (int) get_option( 'page_for_posts' );
        if ( $posts_page ) {
            return $posts_page;
        }
    }
    return 0;
}

/**
 * Resolve the <title> tag content for the current request.
 *
 * Override chain (when a SEO post id resolves):
 *   _seo_title postmeta  →  "{site name} - {page title}"
 *
 * Pages that don't resolve a SEO post id (search, archives, 404) use the
 * site-name + page-title pattern the templates already pass in.
 */
function firefly_get_document_title( $page_title = '' ) {
    $post_id = firefly_get_seo_post_id();
    if ( $post_id ) {
        $override = get_post_meta( $post_id, '_seo_title', true );
        if ( ! empty( $override ) ) {
            return $override;
        }
    }
    $site      = get_bloginfo( 'name' );
    $separator = firefly_get_seo_setting( 'defaults', 'title_separator', ' - ' );
    if ( $page_title === '' ) {
        return $site;
    }
    return $site . $separator . $page_title;
}

/**
 * Small accessor that reads a single value from the site-wide SEO config
 * (managed by firefly-collective/includes/apps/backend/models/seo-admin.php).
 * Falls back to $default when the config function isn't loaded yet — keeps
 * frontend rendering safe on environments where the plugin is disabled.
 */
function firefly_get_seo_setting( $section, $key, $default = '' ) {
    if ( ! function_exists( 'firefly_collective_get_seo_config' ) ) {
        return $default;
    }
    static $cache = null;
    if ( $cache === null ) {
        $cache = firefly_collective_get_seo_config();
    }
    if ( isset( $cache[ $section ] ) && isset( $cache[ $section ][ $key ] ) && $cache[ $section ][ $key ] !== '' ) {
        return $cache[ $section ][ $key ];
    }
    return $default;
}

/**
 * Resolve the OG / Twitter title for the current request.
 *
 * Override chain:
 *   _seo_og_title  →  _seo_title  →  post_title  →  site name
 */
function firefly_get_og_title() {
    $post_id = firefly_get_seo_post_id();
    if ( $post_id ) {
        $og = get_post_meta( $post_id, '_seo_og_title', true );
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
 * Override chain:
 *   _seo_og_description  →  _seo_description  →  _geo_summary  →  excerpt  →  site tagline
 */
function firefly_get_og_description() {
    $post_id = firefly_get_seo_post_id();
    if ( $post_id ) {
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
 * Override chain:
 *   _seo_og_image_id postmeta  →  mobile featured image  →  featured image
 *   →  site-wide SEO default  →  default-og.webp from active template
 *
 * Optional $post_id / $size let template-side OG emitters request the image
 * for a specific post; with no args we resolve against the current request.
 */
function firefly_get_og_image_url( $post_id = null, $size = 'full' ) {
    if ( $post_id === null ) {
        $post_id = firefly_get_seo_post_id();
    }
    if ( $post_id ) {
        $override_id = (int) get_post_meta( $post_id, '_seo_og_image_id', true );
        if ( $override_id > 0 ) {
            $url = wp_get_attachment_image_url( $override_id, $size );
            if ( $url ) return firefly_absolute_url( $url );
        }

        // Prefer the mobile featured image when set — OG previews render at
        // mobile-like sizes in social feeds, so the mobile crop is the more
        // appropriate source when the editor has provided one.
        if ( function_exists( 'firefly_get_mobile_thumbnail_url' ) ) {
            $mobile = firefly_get_mobile_thumbnail_url( $post_id, $size );
            if ( $mobile ) return firefly_absolute_url( $mobile );
        }

        $featured = get_the_post_thumbnail_url( $post_id, $size );
        if ( $featured ) return firefly_absolute_url( $featured );
    }

    // Site-wide default from the SEO settings (Sync SEO admin page).
    $configured = firefly_get_seo_setting( 'defaults', 'og_image_url', '' );
    if ( $configured ) {
        return firefly_absolute_url( $configured );
    }

    // Default to the active template's default-og.webp. We don't trust the
    // global $template_path_web here — in some request contexts (SPA boot,
    // REST callbacks) it ends up relative or unset. Build the path from
    // scratch and pin to home_url() so the result is always absolute.
    $template = function_exists( 'firefly_collective_get_active_template' )
        ? firefly_collective_get_active_template()
        : 'default';
    $path = '/wp-content/themes/firefly-collective/templates/' . $template . '/images/default-og.webp';
    return firefly_absolute_url( $path );
}

/**
 * Normalize a URL to absolute form (scheme + host + path).
 *
 * Social crawlers (Twitter, LinkedIn, Slack, Discord) silently skip
 * images with relative URLs. Facebook is forgiving on `og:image` but not
 * on `twitter:image`. Firefly's snippet relativizer + various
 * `template_directory_uri` filters mean even WP-derived URLs can come
 * back path-only on this stack, so we centralize the absolute-ize step
 * for every OG/Twitter URL we emit.
 *
 * Already-absolute and protocol-relative URLs pass through unchanged.
 */
function firefly_absolute_url( $url ) {
    if ( empty( $url ) ) return $url;
    // Already has scheme — pass through.
    if ( preg_match( '#^https?://#i', $url ) ) return $url;
    // Protocol-relative — pass through (browser resolves at request time).
    if ( strpos( $url, '//' ) === 0 ) return $url;
    // Root-relative path — prepend home_url() with no double-slash.
    if ( strpos( $url, '/' ) === 0 ) {
        return rtrim( home_url(), '/' ) . $url;
    }
    // Bare path or unknown shape — try home_url() anyway.
    return rtrim( home_url(), '/' ) . '/' . ltrim( $url, '/' );
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
    $seo_post_id = firefly_get_seo_post_id();
    // Both og:url and the image URL go through firefly_absolute_url() so
    // crawlers that don't auto-resolve (Twitter, LinkedIn) always get a
    // full scheme+host URL instead of a path-only one.
    $url         = firefly_absolute_url( $seo_post_id ? get_permalink( $seo_post_id ) : home_url() );
    $type        = is_singular() ? 'article' : 'website';
    $twitter_card    = firefly_get_seo_setting( 'twitter', 'card_type',      'summary_large_image' );
    $twitter_site    = firefly_get_seo_setting( 'twitter', 'site_handle',    '' );
    $twitter_creator = firefly_get_seo_setting( 'twitter', 'creator_handle', '' );
    $fb_app_id       = firefly_get_seo_setting( 'facebook', 'app_id',        '' );

    echo '<!-- Open Graph meta tags -->' . "\n";
    echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
    echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
    if ( $image ) {
        echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
    }
    echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
    echo '<meta property="og:type" content="' . esc_attr( $type ) . '" />' . "\n";
    if ( $fb_app_id ) {
        echo '<meta property="fb:app_id" content="' . esc_attr( $fb_app_id ) . '" />' . "\n";
    }

    echo '<!-- Twitter Card meta tags -->' . "\n";
    echo '<meta name="twitter:card" content="' . esc_attr( $twitter_card ) . '" />' . "\n";
    if ( $twitter_site ) {
        $handle = ( strpos( $twitter_site, '@' ) === 0 ) ? $twitter_site : '@' . $twitter_site;
        echo '<meta name="twitter:site" content="' . esc_attr( $handle ) . '" />' . "\n";
    }
    if ( $twitter_creator ) {
        $handle = ( strpos( $twitter_creator, '@' ) === 0 ) ? $twitter_creator : '@' . $twitter_creator;
        echo '<meta name="twitter:creator" content="' . esc_attr( $handle ) . '" />' . "\n";
    }
    echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
    if ( $image ) {
        echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
    }
}
add_action( 'wp_head', 'set_open_graph_meta_data' );

/**
 * Emit search-engine site-verification meta tags from the SEO config.
 * Each provider has its own meta name convention; we emit only the ones
 * that have a non-empty configured value.
 *
 * Hooked to wp_head priority 3 so it sits between the
 * canonical/description block (priority 1) and the schema script (5).
 */
function firefly_output_verification_meta() {
    $providers = array(
        'google'    => 'google-site-verification',
        'bing'      => 'msvalidate.01',
        'yandex'    => 'yandex-verification',
        'pinterest' => 'p:domain_verify',
        'baidu'     => 'baidu-site-verification',
    );
    foreach ( $providers as $config_key => $meta_name ) {
        $value = firefly_get_seo_setting( 'verification', $config_key, '' );
        if ( $value ) {
            echo '<meta name="' . esc_attr( $meta_name ) . '" content="' . esc_attr( $value ) . '" />' . "\n";
        }
    }
}
add_action( 'wp_head', 'firefly_output_verification_meta', 3 );

/**
 * Drop the core "users" sitemap provider (wp-sitemap-users-1.xml).
 *
 * WordPress core registers a sitemap of author archive URLs by default,
 * which exposes admin/author usernames via their archive slugs. No template
 * uses author archives as a real navigation surface, so there's no upside —
 * only a minor username-enumeration exposure. Pages/posts/taxonomies
 * sitemaps (and robots.txt's Sitemap: line) are unaffected.
 */
add_filter( 'wp_sitemaps_add_provider', 'firefly_remove_users_sitemap_provider', 10, 2 );

function firefly_remove_users_sitemap_provider( $provider, $name ) {
    if ( 'users' === $name ) {
        return false;
    }
    return $provider;
}

/**
 * Keep the sitemap and the per-page robots meta in agreement.
 *
 * WP core's sitemap lists every published page/post regardless of its
 * _seo_robots_noindex flag, so a page that emits <meta robots noindex> was
 * still advertised in wp-sitemap.xml — Google crawls the sitemapped URL,
 * finds noindex, and files "Excluded by 'noindex' tag" / "Crawled - not
 * indexed". Exclude noindexed posts from the sitemap so the two signals
 * never contradict. (Template scoping is already applied via the
 * pre_get_posts meta_query; this clause ANDs with it.)
 */
add_filter( 'wp_sitemaps_posts_query_args', 'firefly_sitemap_exclude_noindex', 10, 2 );

function firefly_sitemap_exclude_noindex( $args, $post_type ) {
    // Exclude by ID, NOT via a meta_query. A `NOT EXISTS OR != '1'` meta_query
    // combines with the template-scoping meta_query (added in pre_get_posts)
    // into conflicting JOINs that silently drop pages which have NO
    // _seo_robots_noindex row at all — including the front page. Resolve the
    // noindexed IDs up front and post__not_in them instead.
    $noindex_ids = get_posts( array(
        'post_type'            => $post_type,
        'post_status'          => 'publish',
        'numberposts'          => -1,
        'fields'               => 'ids',
        'meta_key'             => '_seo_robots_noindex',
        'meta_value'           => '1',
        'firefly_skip_scoping' => true,
        'no_found_rows'        => true,
        'suppress_filters'     => false,
    ) );

    if ( ! empty( $noindex_ids ) ) {
        $existing            = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
        $args['post__not_in'] = array_merge( $existing, array_map( 'intval', $noindex_ids ) );
    }

    return $args;
}

/**
 * Drop the framework chrome pages (header/footer) from the sitemap.
 *
 * Every template stores its header/footer markup in `header`/`footer` pages
 * (menu_order -100/-99, in_menu:false). They render at /header/ and /footer/
 * but are content holders, not real pages — advertising them in the sitemap
 * sends Google to crawl bare chrome fragments. Resolve their IDs and
 * post__not_in them, mirroring firefly_sitemap_exclude_noindex. (They're also
 * noindexed in seo-schema.php so the two signals agree.)
 */
add_filter( 'wp_sitemaps_posts_query_args', 'firefly_sitemap_exclude_chrome', 10, 2 );

function firefly_sitemap_exclude_chrome( $args, $post_type ) {
    if ( 'page' !== $post_type ) {
        return $args;
    }

    $chrome_ids = get_posts( array(
        'post_type'            => 'page',
        'post_status'          => 'publish',
        'numberposts'          => -1,
        'fields'               => 'ids',
        'post_name__in'        => array( 'header', 'footer' ),
        'firefly_skip_scoping' => true,
        'no_found_rows'        => true,
        'suppress_filters'     => false,
    ) );

    if ( ! empty( $chrome_ids ) ) {
        $existing             = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
        $args['post__not_in'] = array_merge( $existing, array_map( 'intval', $chrome_ids ) );
    }

    return $args;
}

/**
 * Drop the tag (post_tag) sitemap. Tag archives are thin, near-duplicate
 * listing pages that Google reports as "Crawled - currently not indexed";
 * they're also noindexed by the robots filter (see seo-schema.php), so
 * advertising them in the sitemap only sends mixed signals. Categories stay
 * — they're the real content taxonomy. Pages/posts/category sitemaps are
 * untouched.
 */
add_filter( 'wp_sitemaps_taxonomies', 'firefly_remove_tag_sitemap' );

function firefly_remove_tag_sitemap( $taxonomies ) {
    unset( $taxonomies['post_tag'] );
    return $taxonomies;
}

/**
 * Keep feed URLs out of the search index.
 *
 * RSS/Atom feeds (/feed/, /tag/<x>/feed/, /comments/feed/, ...) are XML, not
 * HTML, so the wp_robots <meta> in seo-schema.php can't reach them — Google
 * was crawling and listing them as "Crawled - currently not indexed" pages.
 * Send an X-Robots-Tag: noindex header on every feed request instead. Feeds
 * keep working for subscribers; they just stop competing as search results.
 */
add_action( 'template_redirect', 'firefly_noindex_feeds' );

function firefly_noindex_feeds() {
    if ( is_feed() && ! headers_sent() ) {
        header( 'X-Robots-Tag: noindex, follow', true );
    }
}

/**
 * Disable WordPress's emoji subsystem.
 *
 * WP injects wp-emoji-release.min.js plus an s.w.org dns-prefetch into every
 * <head>. Modern browsers render emoji natively, so it is dead weight — and
 * Google crawls the standalone .js as its own URL, which shows up as
 * "Crawled - currently not indexed" noise in Search Console. Strip the whole
 * emoji subsystem: scripts, styles, feed/mail filters, the TinyMCE plugin,
 * and the dns-prefetch hint.
 */
add_action( 'init', 'firefly_disable_emojis' );

function firefly_disable_emojis() {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    add_filter( 'tiny_mce_plugins', 'firefly_disable_emojis_tinymce' );
    add_filter( 'wp_resource_hints', 'firefly_disable_emojis_dns_prefetch', 10, 2 );
}

function firefly_disable_emojis_tinymce( $plugins ) {
    return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
}

function firefly_disable_emojis_dns_prefetch( $urls, $relation_type ) {
    if ( 'dns-prefetch' === $relation_type ) {
        $urls = array_filter( $urls, function ( $url ) {
            $href = is_array( $url ) ? ( isset( $url['href'] ) ? $url['href'] : '' ) : $url;
            return strpos( (string) $href, 's.w.org' ) === false;
        } );
    }
    return $urls;
}
