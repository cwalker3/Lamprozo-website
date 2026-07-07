<?php
/**
 * Sitemap - Theme Model
 *
 * Serves a bare XML sitemap at /sitemap.xml for the active template. Content
 * discovery is a plain WP_Query for published pages/posts — the framework's
 * pre_get_posts scoping (template-scoping.php) automatically restricts that
 * to posts tagged for THIS template, so pages belonging to other templates
 * sharing this install never leak in.
 */
if (!defined('ABSPATH')) { exit; }

// Priority 1: must run before core's redirect_canonical (default priority 10 on
// this same hook), which otherwise 301s /sitemap.xml -> /wp-sitemap.xml before
// we get a chance to render anything.
add_action('template_redirect', function () {
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if ($path !== 'sitemap.xml') {
        return;
    }
    lamprozo_render_sitemap();
    exit;
}, 1);

function lamprozo_render_sitemap() {
    $query = new WP_Query(array(
        'post_type'      => array('page', 'post'),
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ));

    // WP already resolved /sitemap.xml as a 404 (no matching page/rewrite)
    // before template_redirect fires — override that so crawlers see a 200.
    status_header(200);
    header('Content-Type: application/xml; charset=UTF-8');
    nocache_headers();

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($query->posts as $post) {
        $loc     = esc_url(get_permalink($post));
        $lastmod = mysql2date('c', $post->post_modified_gmt, false);
        echo "  <url>\n";
        echo "    <loc>{$loc}</loc>\n";
        echo "    <lastmod>{$lastmod}</lastmod>\n";
        echo "  </url>\n";
    }
    echo '</urlset>' . "\n";
}
