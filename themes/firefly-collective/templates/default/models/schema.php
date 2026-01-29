<?php
/**
 * Firefly Schema Output Model
 *
 * Generates JSON-LD structured data and meta tags for SEO/GEO optimization.
 * Reads GEO fields from post meta (synced via firefly-projects) and outputs
 * proper schema markup on all environments.
 *
 * @package FireflyCollective
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Output JSON-LD schema for blog posts
 * Hooked to wp_head
 */
function firefly_output_post_schema() {
    // Only on single posts
    if (!is_singular('post')) {
        return;
    }

    global $post;
    $post_id = $post->ID;

    // Get GEO meta fields
    $geo_summary = get_post_meta($post_id, '_geo_summary', true);
    $geo_article_type = get_post_meta($post_id, '_geo_article_type', true) ?: 'BlogPosting';
    $geo_key_facts = get_post_meta($post_id, '_geo_key_facts', true);
    $geo_faq = get_post_meta($post_id, '_geo_faq', true);

    // Parse JSON fields
    $key_facts = $geo_key_facts ? json_decode($geo_key_facts, true) : array();
    $faqs = $geo_faq ? json_decode($geo_faq, true) : array();

    // Get post data
    $title = get_the_title($post_id);
    $excerpt = get_the_excerpt($post_id);
    $description = $geo_summary ?: $excerpt;
    $url = get_permalink($post_id);
    $published = get_the_date('c', $post_id);
    $modified = get_the_modified_date('c', $post_id);
    $featured_image = get_the_post_thumbnail_url($post_id, 'full');

    // Author data
    $author_id = get_post_field('post_author', $post_id);
    $author_name = get_the_author_meta('display_name', $author_id);
    $author_url = get_author_posts_url($author_id);

    // Word count for reading time
    $content = get_the_content(null, false, $post_id);
    $word_count = str_word_count(strip_tags($content));

    // Build main article schema
    $schema = array(
        '@context' => 'https://schema.org',
        '@type' => $geo_article_type,
        'headline' => $title,
        'description' => $description,
        'url' => $url,
        'datePublished' => $published,
        'dateModified' => $modified,
        'wordCount' => $word_count,
        'author' => array(
            '@type' => 'Person',
            'name' => $author_name,
            'url' => $author_url
        ),
        'publisher' => array(
            '@type' => 'Organization',
            'name' => 'Firefly Creative, LLC',
            'url' => home_url('/'),
            'logo' => array(
                '@type' => 'ImageObject',
                'url' => get_template_directory_uri() . '/templates/firefly/images/logo.webp'
            )
        ),
        'mainEntityOfPage' => array(
            '@type' => 'WebPage',
            '@id' => $url
        )
    );

    // Add featured image if exists
    if ($featured_image) {
        $schema['image'] = array(
            '@type' => 'ImageObject',
            'url' => $featured_image
        );
    }

    // Add key facts as citations/mentions if present
    if (!empty($key_facts)) {
        $citations = array();
        foreach ($key_facts as $fact) {
            if (!empty($fact['fact'])) {
                $citation = array(
                    '@type' => 'Claim',
                    'text' => $fact['fact']
                );
                if (!empty($fact['source'])) {
                    $citation['citation'] = $fact['source'];
                }
                $citations[] = $citation;
            }
        }
        if (!empty($citations)) {
            $schema['hasPart'] = $citations;
        }
    }

    // Output main schema
    echo "\n<!-- Firefly SEO/GEO Schema -->\n";
    echo '<script type="application/ld+json">' . "\n";
    echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo "\n</script>\n";

    // Output FAQ schema separately if FAQs exist (from sidebar, not block)
    if (!empty($faqs)) {
        $faq_schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array()
        );

        foreach ($faqs as $faq) {
            if (!empty($faq['question']) && !empty($faq['answer'])) {
                $faq_schema['mainEntity'][] = array(
                    '@type' => 'Question',
                    'name' => $faq['question'],
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text' => $faq['answer']
                    )
                );
            }
        }

        if (!empty($faq_schema['mainEntity'])) {
            echo '<script type="application/ld+json">' . "\n";
            echo json_encode($faq_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "\n</script>\n";
        }
    }

    // Output BreadcrumbList schema
    $breadcrumb_schema = array(
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => array(
            array(
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => home_url('/')
            ),
            array(
                '@type' => 'ListItem',
                'position' => 2,
                'name' => 'Blog',
                'item' => home_url('/newsroom')
            ),
            array(
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $title,
                'item' => $url
            )
        )
    );

    echo '<script type="application/ld+json">' . "\n";
    echo json_encode($breadcrumb_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo "\n</script>\n";
}
add_action('wp_head', 'firefly_output_post_schema', 5);

/**
 * Output meta description from GEO summary
 * Falls back to excerpt if no GEO summary
 */
function firefly_output_meta_description() {
    // Only on singular content
    if (!is_singular()) {
        return;
    }

    global $post;
    $post_id = $post->ID;

    // Get GEO summary first, fall back to excerpt
    $geo_summary = get_post_meta($post_id, '_geo_summary', true);
    $description = $geo_summary ?: get_the_excerpt($post_id);

    // Truncate to 160 characters for meta description
    if (strlen($description) > 160) {
        $description = substr($description, 0, 157) . '...';
    }

    if ($description) {
        echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
    }
}
add_action('wp_head', 'firefly_output_meta_description', 1);

/**
 * Output canonical URL
 */
function firefly_output_canonical_url() {
    // Only on singular content
    if (!is_singular()) {
        return;
    }

    $canonical = get_permalink();
    echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
}
add_action('wp_head', 'firefly_output_canonical_url', 1);

/**
 * Output robots meta tag
 * Prevents indexing on dev environments
 */
function firefly_output_robots_meta() {
    // Check if this is a dev environment
    $is_dev = defined('FIREFLY_DEV') || defined('FIREFLY_LIVE_DEV');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $is_dev_domain = (strpos($host, 'dev.') === 0 || strpos($host, 'localhost') !== false);

    if ($is_dev || $is_dev_domain) {
        echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
    }
}
add_action('wp_head', 'firefly_output_robots_meta', 1);

/**
 * Get GEO summary for a post (helper function)
 *
 * @param int $post_id Post ID
 * @return string GEO summary or empty string
 */
function firefly_get_geo_summary($post_id = null) {
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    return get_post_meta($post_id, '_geo_summary', true) ?: '';
}

/**
 * Get GEO article type for a post (helper function)
 *
 * @param int $post_id Post ID
 * @return string Article type (BlogPosting, HowTo, NewsArticle)
 */
function firefly_get_geo_article_type($post_id = null) {
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    return get_post_meta($post_id, '_geo_article_type', true) ?: 'BlogPosting';
}

/**
 * Get GEO key facts for a post (helper function)
 *
 * @param int $post_id Post ID
 * @return array Array of key facts
 */
function firefly_get_geo_key_facts($post_id = null) {
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    $json = get_post_meta($post_id, '_geo_key_facts', true);
    return $json ? json_decode($json, true) : array();
}

/**
 * Get GEO FAQ for a post (helper function)
 *
 * @param int $post_id Post ID
 * @return array Array of FAQ items
 */
function firefly_get_geo_faq($post_id = null) {
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    $json = get_post_meta($post_id, '_geo_faq', true);
    return $json ? json_decode($json, true) : array();
}
