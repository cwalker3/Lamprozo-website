<?php
/**
 * Template Snippets Sync
 *
 * Automatically syncs page/post content to snippet files when saved in WordPress.
 * This keeps snippets in sync whether editing via Gutenberg or CLI.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Relativize URLs in content for domain-agnostic snippets.
 *
 * Strips the site URL so paths like https://dev.fireflycollective.org/wp-content/uploads/img.jpg
 * become /wp-content/uploads/img.jpg — portable across dev/staging/prod.
 */
function firefly_relativize_urls($content) {
    $site_url = untrailingslashit(site_url());
    $home_url = untrailingslashit(home_url());

    // Build list of URL variants to strip (longest first)
    $urls = array_unique(array(
        str_replace('http://', 'https://', $site_url),
        str_replace('https://', 'http://', $site_url),
        $site_url,
        str_replace('http://', 'https://', $home_url),
        str_replace('https://', 'http://', $home_url),
        $home_url,
    ));
    usort($urls, function($a, $b) { return strlen($b) - strlen($a); });

    foreach ($urls as $url) {
        $content = str_replace($url, '', $content);
    }

    // Catch any remaining absolute URLs pointing to internal WordPress paths,
    // regardless of domain. Handles content from other environments.
    // Matches /wp-content/, /wp-includes/, and /wp-admin/ asset paths.
    $content = preg_replace('#https?://[^/\s"\']+(/wp-(?:content|includes|admin)/)#', '$1', $content);

    // Post-pass: restore absolute URLs in social-crawler meta tags.
    //
    // The relativization above is needed for content portability (snippets
    // sync clean across environments). But social platforms (Twitter/X,
    // LinkedIn, Slack, Discord, Pinterest) silently SKIP images and links
    // when given a relative URL — they don't auto-resolve. Even Facebook
    // only resolves og:image, not twitter:image. Google's canonical also
    // requires absolute for correct indexing.
    //
    // We selectively re-absolute these specific tags after relativization
    // so the rest of the document (links, images, scripts) stays relative.
    $content = firefly_reabsolute_crawler_tags( $content );

    return $content;
}

/**
 * Restore absolute URLs in the specific <meta> / <link> tags that social
 * crawlers and search engines require absolute. Runs as a post-pass after
 * firefly_relativize_urls. Tag matching is intentionally tight — driven by
 * the emit shape used in seo-meta.php / seo-schema.php — to avoid
 * accidentally re-absoluting content elsewhere in the page.
 */
function firefly_reabsolute_crawler_tags( $content ) {
    if ( ! is_string( $content ) || $content === '' ) {
        return $content;
    }
    $home = untrailingslashit( home_url() );
    if ( $home === '' ) {
        return $content;
    }

    // og:image / og:url / og:image:secure_url / og:video / og:audio
    // twitter:image / twitter:image:src
    // <link rel="canonical" href="...">
    // Matches root-relative paths only (content="/..." or href="/..."),
    // case-insensitive on attribute keys, single- or double-quoted.
    $patterns = array(
        '#(<meta\s+property=(?:"|\')(?:og:image|og:image:secure_url|og:url|og:video|og:audio)(?:"|\')\s+content=(?:"|\'))(/[^"\']*)#i',
        '#(<meta\s+name=(?:"|\')(?:twitter:image|twitter:image:src|twitter:url)(?:"|\')\s+content=(?:"|\'))(/[^"\']*)#i',
        '#(<link\s+rel=(?:"|\')canonical(?:"|\')\s+href=(?:"|\'))(/[^"\']*)#i',
    );

    foreach ( $patterns as $pattern ) {
        $content = preg_replace_callback( $pattern, function ( $m ) use ( $home ) {
            return $m[1] . $home . $m[2];
        }, $content );
    }

    return $content;
}

/**
 * Relativize URLs in rendered content so pages work across
 * all environments (localhost, dev, production) without mixed content warnings.
 */
add_filter( 'the_content', 'firefly_relativize_urls', 99 );
add_filter( 'the_content_feed', 'firefly_relativize_urls', 99 );
add_filter( 'wp_get_attachment_url', 'firefly_relativize_urls', 99 );
add_filter( 'wp_get_attachment_image_src', function( $image ) {
    if ( is_array( $image ) && ! empty( $image[0] ) ) {
        $image[0] = firefly_relativize_urls( $image[0] );
    }
    return $image;
}, 99 );
add_filter( 'wp_calculate_image_srcset', function( $sources ) {
    if ( is_array( $sources ) ) {
        foreach ( $sources as &$source ) {
            $source['url'] = firefly_relativize_urls( $source['url'] );
        }
    }
    return $sources;
}, 99 );

/**
 * Catch any remaining absolute URLs in final HTML output.
 * Runs as an output buffer on template_redirect.
 *
 * Skips WordPress core's XML sitemaps (/wp-sitemap*.xml, the .xsl
 * stylesheets) AND robots.txt. The sitemap protocol requires <loc> URLs to
 * be absolute — relativizing them made every entry an "Invalid URL" in
 * Google Search Console. robots.txt's `Sitemap:` directive likewise must be
 * an absolute URL, and the relativizer was rewriting core's absolute
 * `Sitemap: https://…/wp-sitemap.xml` down to a relative `/wp-sitemap.xml`
 * that Google ignores. Sitemap requests carry the `sitemap` /
 * `sitemap-stylesheet` query var and robots.txt sets is_robots() by the time
 * template_redirect fires, so this is a targeted skip; ordinary page/post
 * output is untouched.
 */
add_action( 'template_redirect', function() {
    if ( get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) || is_robots() ) {
        return;
    }
    ob_start( 'firefly_relativize_urls' );
} );

/**
 * Look up the snippet filename from the schema for a given post.
 * Returns the snippet basename (e.g. "home.html") if found, or null.
 */
function firefly_get_schema_snippet($template, $post_id, $post_type) {
    $schema_path = get_template_directory() . '/data/schemas/' . $template . '-schema.json';
    if ( ! file_exists( $schema_path ) ) {
        return null;
    }

    $schema = json_decode( file_get_contents( $schema_path ), true );
    if ( ! $schema ) {
        return null;
    }

    $key = ( $post_type === 'post' ) ? 'posts' : 'pages';
    if ( ! isset( $schema[ $key ] ) ) {
        return null;
    }

    // Match by post ID's current slug OR by WordPress-deduplicated slug
    $slug = get_post_field( 'post_name', $post_id );
    foreach ( $schema[ $key ] as $entry ) {
        if ( $entry['slug'] === $slug ) {
            return $entry['snippet'];
        }
    }

    // Also try matching by stripping the "-2", "-3" etc. suffix that WordPress adds
    $base_slug = preg_replace( '/-\d+$/', '', $slug );
    if ( $base_slug !== $slug ) {
        foreach ( $schema[ $key ] as $entry ) {
            if ( $entry['slug'] === $base_slug ) {
                return $entry['snippet'];
            }
        }
    }

    return null;
}

/**
 * Get the snippet file path for a post
 */
function firefly_get_snippet_path($post_id, $post_type = 'page') {
    $template = get_post_meta($post_id, '_firefly_template', true);
    if (empty($template)) {
        return false;
    }

    $slug = get_post_field('post_name', $post_id);
    if (empty($slug)) {
        return false;
    }

    $type_dir = ($post_type === 'post') ? 'posts' : 'pages';
    $template_dir = get_template_directory() . '/templates/' . $template . '/snippets/' . $type_dir;

    // Ensure directory exists
    if (!is_dir($template_dir)) {
        wp_mkdir_p($template_dir);
    }

    // Use the schema's snippet filename if it exists (handles WordPress slug deduplication)
    $schema_snippet = firefly_get_schema_snippet( $template, $post_id, $post_type );
    if ( $schema_snippet ) {
        return $template_dir . '/' . $schema_snippet;
    }

    return $template_dir . '/' . $slug . '.html';
}

/**
 * Save post content to snippet file
 */
/**
 * Normalize a snippet's HTML for EQUIVALENCE COMPARISON ONLY (never for what is
 * written to disk). Canonicalizes the cosmetic differences WordPress's block
 * editor introduces when it re-serializes every block on save — chiefly inline
 * `style` attributes, where it adds a trailing ";", tweaks spacing, etc. Two
 * snippets that differ only in those ways describe the identical page and must
 * not produce a diff; any real change survives normalization and still writes.
 */
function firefly_normalize_snippet_for_compare($html) {
    // Canonicalize every inline style attribute: split declarations on ";",
    // trim each, normalize spacing around the first ":", drop empties, rejoin.
    // Absorbs the trailing-";", double-";", and stray-space variations the
    // editor emits without touching declaration order or values.
    $html = preg_replace_callback(
        '/style=(["\'])(.*?)\1/s',
        function ($m) {
            $decls = array();
            foreach (explode(';', $m[2]) as $decl) {
                $decl = trim($decl);
                if ('' === $decl) {
                    continue;
                }
                $decls[] = preg_replace('/\s*:\s*/', ':', $decl, 1);
            }
            return 'style=' . $m[1] . implode(';', $decls) . $m[1];
        },
        $html
    );

    return $html;
}

/**
 * True when two snippet HTML strings describe the same page, ignoring only the
 * cosmetic serialization noise firefly_normalize_snippet_for_compare() strips.
 * Keeps snippet exports idempotent: a meta-only edit (which still re-serializes
 * every block) no longer dirties the body snippet.
 */
function firefly_snippets_equivalent($a, $b) {
    if ($a === $b) {
        return true;
    }
    return firefly_normalize_snippet_for_compare($a) === firefly_normalize_snippet_for_compare($b);
}

function firefly_save_snippet($post_id) {
    // Suppress when an inbound page-sync is currently applying the snippet
    // explicitly from the sender's manifest. Without this guard the receiver
    // would immediately re-derive the snippet from post_content via
    // firefly_relativize_urls() and clobber the file we just wrote, defeating
    // the point of shipping snippet HTML byte-for-byte.
    if (defined('FIREFLY_PROJECTS_SYNCING_INBOUND') && FIREFLY_PROJECTS_SYNCING_INBOUND) {
        return;
    }

    // Skip autosaves
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Skip revisions
    if (wp_is_post_revision($post_id)) {
        return;
    }

    // Skip if not a page or post
    $post_type = get_post_type($post_id);
    if (!in_array($post_type, array('page', 'post'))) {
        return;
    }

    // Skip if not published
    $post_status = get_post_status($post_id);
    if ($post_status !== 'publish') {
        return;
    }

    // Skip if no template assigned
    $template = get_post_meta($post_id, '_firefly_template', true);
    if (empty($template)) {
        return;
    }

    // Get snippet path
    $snippet_path = firefly_get_snippet_path($post_id, $post_type);
    if (!$snippet_path) {
        return;
    }

    // Get content and relativize URLs for domain-agnostic snippets
    $post = get_post($post_id);
    $content = firefly_relativize_urls($post->post_content);

    // Idempotent export: WordPress re-serializes every block on save (e.g. it
    // appends a trailing ";" to inline styles), so a meta-only edit would
    // otherwise rewrite the body snippet with purely cosmetic diffs. Skip the
    // file write when the new content is equivalent to what's already on disk —
    // but STILL refresh the schema entry, since title/menu/SEO/etc. may have
    // changed even when the body didn't.
    $existing = file_exists($snippet_path) ? file_get_contents($snippet_path) : null;
    if (null !== $existing && firefly_snippets_equivalent($content, $existing)) {
        firefly_update_schema_entry($post_id, $post_type, $template);
        return;
    }

    // Write to file
    $result = @file_put_contents($snippet_path, $content);

    if ($result !== false) {
        // Update schema
        firefly_update_schema_entry($post_id, $post_type, $template);
    } else {
        // Store error for admin notice
        set_transient('firefly_snippet_error_' . get_current_user_id(), array(
            'post_title' => $post->post_title,
            'snippet_path' => $snippet_path,
        ), 60);
        error_log("Firefly: Failed to save snippet for {$post_type} '{$post->post_name}' - permission denied");
    }
}
add_action('save_post', 'firefly_save_snippet', 20);

// Block-editor (REST) saves persist post meta AFTER save_post fires, so the
// hook above captures STALE SEO / featured / mobile / OG meta into the schema
// (the meta the block editor just set isn't written yet). rest_after_insert_{type}
// runs once the REST controller has stored the post AND all its registered meta,
// so re-syncing here captures the just-saved values. firefly_save_snippet is
// idempotent, so the extra run on a REST save is harmless — it simply wins with
// the correct meta.
function firefly_save_snippet_after_rest($post) {
    if ($post instanceof WP_Post) {
        firefly_save_snippet($post->ID);
    }
}
add_action('rest_after_insert_page', 'firefly_save_snippet_after_rest', 10, 1);
add_action('rest_after_insert_post', 'firefly_save_snippet_after_rest', 10, 1);

/**
 * Show admin notice when snippet save fails (classic editor)
 */
function firefly_snippet_error_notice() {
    $error = get_transient('firefly_snippet_error_' . get_current_user_id());
    if (!$error) {
        return;
    }

    delete_transient('firefly_snippet_error_' . get_current_user_id());

    ?>
    <div class="notice notice-error is-dismissible">
        <p><strong>Firefly Snippet Error:</strong> Could not save snippet for "<?php echo esc_html($error['post_title']); ?>".</p>
        <p>Permission denied writing to: <code><?php echo esc_html($error['snippet_path']); ?></code></p>
        <p>Run <code>firefly permissions</code> from the CLI to fix file permissions.</p>
    </div>
    <?php
}
add_action('admin_notices', 'firefly_snippet_error_notice');

/**
 * Add snippet error to REST API response for Gutenberg
 */
function firefly_add_snippet_error_to_rest_response($response, $post, $request) {
    $error = get_transient('firefly_snippet_error_' . get_current_user_id());
    if ($error) {
        delete_transient('firefly_snippet_error_' . get_current_user_id());
        $response->header('X-Firefly-Snippet-Error', 'Permission denied: ' . $error['snippet_path']);
    }
    return $response;
}
add_filter('rest_prepare_page', 'firefly_add_snippet_error_to_rest_response', 10, 3);
add_filter('rest_prepare_post', 'firefly_add_snippet_error_to_rest_response', 10, 3);

/**
 * Enqueue Gutenberg notice script
 */
function firefly_enqueue_gutenberg_notice_script() {
    $screen = get_current_screen();
    if (!$screen || !$screen->is_block_editor()) {
        return;
    }

    wp_enqueue_script(
        'firefly-snippet-notices',
        '',
        array('wp-data', 'wp-notices', 'wp-api-fetch'),
        null,
        true
    );

    $inline_script = "
    (function() {
        const originalFetch = wp.apiFetch;
        wp.apiFetch = function(options) {
            return originalFetch(options).then(function(response) {
                return response;
            }).catch(function(error) {
                throw error;
            });
        };

        // Listen for post save and check for snippet errors
        wp.data.subscribe(function() {
            const isSaving = wp.data.select('core/editor').isSavingPost();
            const isAutosave = wp.data.select('core/editor').isAutosavingPost();

            if (!isSaving || isAutosave) return;

            // Check transient via REST
            setTimeout(function() {
                fetch('/wp-json/firefly/v1/snippet-error')
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) {
                            wp.data.dispatch('core/notices').createErrorNotice(
                                'Firefly Snippet Error: ' + data.message + ' Run \"firefly permissions\" to fix.',
                                { id: 'firefly-snippet-error', isDismissible: true }
                            );
                        }
                    })
                    .catch(() => {});
            }, 500);
        });
    })();
    ";

    wp_add_inline_script('firefly-snippet-notices', $inline_script);
}
add_action('enqueue_block_editor_assets', 'firefly_enqueue_gutenberg_notice_script');

/**
 * REST endpoint to check for snippet errors
 */
function firefly_register_snippet_error_endpoint() {
    register_rest_route('firefly/v1', '/snippet-error', array(
        'methods' => 'GET',
        'callback' => 'firefly_get_snippet_error',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        }
    ));
}
add_action('rest_api_init', 'firefly_register_snippet_error_endpoint');

function firefly_get_snippet_error() {
    $error = get_transient('firefly_snippet_error_' . get_current_user_id());
    if ($error) {
        delete_transient('firefly_snippet_error_' . get_current_user_id());
        return array(
            'error' => true,
            'message' => 'Could not save snippet for "' . $error['post_title'] . '". Permission denied: ' . $error['snippet_path']
        );
    }
    return array('error' => false);
}

/**
 * Update schema JSON when a post is saved
 */
function firefly_update_schema_entry($post_id, $post_type, $template) {
    $schema_path = get_template_directory() . '/data/schemas/' . $template . '-schema.json';

    if (!file_exists($schema_path)) {
        return;
    }

    $schema_content = file_get_contents($schema_path);
    $schema = json_decode($schema_content, true);

    if (!$schema) {
        return;
    }

    $post = get_post($post_id);
    $slug = $post->post_name;
    $title = $post->post_title;
    $menu_order = $post->menu_order;

    // Determine which array to update
    $key = ($post_type === 'post') ? 'posts' : 'pages';

    if (!isset($schema[$key])) {
        $schema[$key] = array();
    }

    // Check if entry exists — also match base slug to handle WordPress deduplication
    // (e.g. slug "home-2" should match schema entry "home")
    $found = false;
    $base_slug = preg_replace( '/-\d+$/', '', $slug );
    foreach ($schema[$key] as $index => $entry) {
        if ( $entry['slug'] === $slug || ( $base_slug !== $slug && $entry['slug'] === $base_slug ) ) {
            // Update existing entry — preserve the schema's slug and snippet name
            $schema[$key][$index]['title'] = $title;

            if ($post_type === 'page') {
                $schema[$key][$index]['menu_order'] = $menu_order;
                // Check if in menu
                $in_menu = firefly_is_page_in_menu($post_id);
                $schema[$key][$index]['in_menu'] = $in_menu;
            }

            if ($post_type === 'post') {
                // Category is post-only.
                $categories = get_the_category($post_id);
                if (!empty($categories)) {
                    $schema[$key][$index]['category'] = $categories[0]->slug;
                }
            }

            // SEO / featured image / mobile featured / GEO — captured for pages
            // AND posts so the schema entry records everything substantial.
            firefly_apply_entry_extras($schema[$key][$index], $post_id);

            $found = true;
            break;
        }
    }

    // Add new entry if not found
    if (!$found) {
        $new_entry = array(
            'slug' => $slug,
            'title' => $title,
            'snippet' => $slug . '.html',
        );

        if ($post_type === 'page') {
            $new_entry['in_menu'] = firefly_is_page_in_menu($post_id);
            $new_entry['menu_order'] = $menu_order;
        }

        if ($post_type === 'post') {
            $categories = get_the_category($post_id);
            $new_entry['category'] = !empty($categories) ? $categories[0]->slug : 'uncategorized';
        }

        firefly_apply_entry_extras($new_entry, $post_id);

        $schema[$key][] = $new_entry;
    }

    // Sort pages by menu_order, posts by title
    if ($post_type === 'page') {
        usort($schema[$key], function($a, $b) {
            return ($a['menu_order'] ?? 0) - ($b['menu_order'] ?? 0);
        });
    } else {
        usort($schema[$key], function($a, $b) {
            return strcmp($a['title'], $b['title']);
        });
    }

    // Save schema. UNESCAPED_UNICODE keeps accented chars literal (’ – …) so
    // this writer matches the CLI export (json.dump ensure_ascii=False) — without
    // it the two writers flip every unicode char to/from \uXXXX and every save
    // churned the whole file.
    $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($schema_path, $json);
}

/**
 * Merge the "substantial meta" fields into a schema page/post entry (by ref):
 * SEO block, featured image (by filename), mobile featured (filename +
 * breakpoint), and GEO. Keys are set when present and unset when cleared, so
 * the schema entry always reflects the post's current state without stale
 * fields. Used for BOTH pages and posts — this is the parity fix.
 */
function firefly_apply_entry_extras(&$entry, $post_id) {
    // SEO
    $seo = function_exists('firefly_get_post_seo_for_schema') ? firefly_get_post_seo_for_schema($post_id) : null;
    if ($seo) { $entry['seo'] = $seo; } else { unset($entry['seo']); }

    // Featured image (filename) — pages + posts.
    $featured = firefly_get_featured_image_filename($post_id);
    if ($featured) { $entry['featured_image'] = $featured; } else { unset($entry['featured_image']); }

    // Mobile featured image (filename) + breakpoint.
    $mobile = function_exists('firefly_get_post_mobile_featured_for_schema')
        ? firefly_get_post_mobile_featured_for_schema($post_id) : array();
    if (!empty($mobile['mobile_featured_image'])) {
        $entry['mobile_featured_image'] = $mobile['mobile_featured_image'];
    } else {
        unset($entry['mobile_featured_image']);
    }
    if (isset($mobile['mobile_featured_breakpoint'])) {
        $entry['mobile_featured_breakpoint'] = $mobile['mobile_featured_breakpoint'];
    } else {
        unset($entry['mobile_featured_breakpoint']);
    }

    // GEO
    $geo = firefly_get_post_geo_data($post_id);
    if ($geo) { $entry['geo'] = $geo; } else { unset($entry['geo']); }
}

/**
 * Check if a page is in any navigation menu
 */
function firefly_is_page_in_menu($post_id) {
    $menu_items = wp_get_nav_menu_items('website-menu');
    if (!$menu_items) {
        return false;
    }

    foreach ($menu_items as $item) {
        if ($item->object_id == $post_id && $item->object === 'page') {
            return true;
        }
    }

    return false;
}

/**
 * Get the filename of the featured image
 */
function firefly_get_featured_image_filename($post_id) {
    $thumbnail_id = get_post_thumbnail_id($post_id);
    if (!$thumbnail_id) {
        return null;
    }

    $file_path = get_attached_file($thumbnail_id);
    if (!$file_path) {
        return null;
    }

    return basename($file_path);
}

/**
 * Get GEO data for a post
 */
function firefly_get_post_geo_data($post_id) {
    $summary = get_post_meta($post_id, '_geo_summary', true);
    $key_facts = get_post_meta($post_id, '_geo_key_facts', true);
    $article_type = get_post_meta($post_id, '_geo_article_type', true);
    $faq = get_post_meta($post_id, '_geo_faq', true);

    if (empty($summary) && empty($key_facts) && empty($faq)) {
        return null;
    }

    $geo = array();

    if (!empty($summary)) {
        $geo['summary'] = $summary;
    }
    if (!empty($key_facts)) {
        $geo['key_facts'] = is_string($key_facts) ? json_decode($key_facts, true) : $key_facts;
    }
    if (!empty($article_type)) {
        $geo['article_type'] = $article_type;
    }
    if (!empty($faq)) {
        $geo['faq'] = is_string($faq) ? json_decode($faq, true) : $faq;
    }

    return $geo;
}

/**
 * Delete snippet when post is trashed or deleted
 */
function firefly_delete_snippet($post_id) {
    $post_type = get_post_type($post_id);
    if (!in_array($post_type, array('page', 'post'))) {
        return;
    }

    $snippet_path = firefly_get_snippet_path($post_id, $post_type);
    if ($snippet_path && file_exists($snippet_path)) {
        unlink($snippet_path);
        error_log("Firefly: Snippet deleted for {$post_type} ID {$post_id}");
    }

    // Note: We don't remove from schema on delete - that's a manual cleanup
    // This prevents accidental data loss
}
add_action('before_delete_post', 'firefly_delete_snippet');

/**
 * Handle slug changes - rename snippet file
 */
function firefly_handle_slug_change($post_id, $post_after, $post_before) {
    // Only handle published posts
    if ($post_after->post_status !== 'publish') {
        return;
    }

    $post_type = $post_after->post_type;
    if (!in_array($post_type, array('page', 'post'))) {
        return;
    }

    // Check if slug changed
    if ($post_after->post_name === $post_before->post_name) {
        return;
    }

    $template = get_post_meta($post_id, '_firefly_template', true);
    if (empty($template)) {
        return;
    }

    // If the new slug looks like WordPress deduplication (e.g. "home-2") and
    // the schema already has an entry for the old slug, skip the rename.
    // WordPress enforces globally unique slugs, but snippets are template-scoped.
    $schema_snippet = firefly_get_schema_snippet( $template, $post_id, $post_type );
    if ( $schema_snippet ) {
        $schema_slug = str_replace( '.html', '', $schema_snippet );
        $new_slug = $post_after->post_name;
        // The schema already tracks this post under a different slug — don't rename
        if ( $schema_slug !== $new_slug ) {
            error_log( "Firefly: Ignoring WordPress slug deduplication {$schema_slug} -> {$new_slug} (template '{$template}' snippets are scoped)" );
            return;
        }
    }

    $type_dir = ($post_type === 'post') ? 'posts' : 'pages';
    $template_dir = get_template_directory() . '/templates/' . $template . '/snippets/' . $type_dir;

    $old_path = $template_dir . '/' . $post_before->post_name . '.html';
    $new_path = $template_dir . '/' . $post_after->post_name . '.html';

    // Rename file if old exists
    if (file_exists($old_path)) {
        rename($old_path, $new_path);
        error_log("Firefly: Snippet renamed from {$post_before->post_name}.html to {$post_after->post_name}.html");
    }

    // Update schema with new slug
    firefly_rename_schema_entry($template, $post_type, $post_before->post_name, $post_after->post_name);
}
add_action('post_updated', 'firefly_handle_slug_change', 10, 3);

/**
 * Rename entry in schema when slug changes
 */
function firefly_rename_schema_entry($template, $post_type, $old_slug, $new_slug) {
    $schema_path = get_template_directory() . '/data/schemas/' . $template . '-schema.json';

    if (!file_exists($schema_path)) {
        return;
    }

    $schema_content = file_get_contents($schema_path);
    $schema = json_decode($schema_content, true);

    if (!$schema) {
        return;
    }

    $key = ($post_type === 'post') ? 'posts' : 'pages';

    if (!isset($schema[$key])) {
        return;
    }

    foreach ($schema[$key] as $index => $entry) {
        if ($entry['slug'] === $old_slug) {
            $schema[$key][$index]['slug'] = $new_slug;
            $schema[$key][$index]['snippet'] = $new_slug . '.html';
            break;
        }
    }

    $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($schema_path, $json);
}
