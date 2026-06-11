<?php
/**
 * Firefly Projects - Page Sync Handler
 *
 * Handles packaging and syncing page/post content to remote sites,
 * including Gutenberg block content and associated media assets.
 */

// Ensure no direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Replace a previous featured / mobile-featured attachment record without
 * destroying the file we just wrote for the new one.
 *
 * Every sync writes the new image to the same path the previous attachment
 * was using ( /uploads/pages/{slug}/featured.webp etc. ). WP's
 * wp_delete_attachment_files() then deletes that path unconditionally, which
 * silently strips the file out from under the new attachment. This helper
 * compares paths first: when they match, the orphan record is removed via a
 * direct DB delete that bypasses file cleanup; when they differ (rare), the
 * normal wp_delete_attachment path runs so the old file is properly cleaned.
 */
function firefly_projects_safely_replace_previous_attachment( $previous_id, $new_id ) {
    $previous_id = (int) $previous_id;
    $new_id      = (int) $new_id;
    if ( ! $previous_id || $previous_id === $new_id ) {
        return;
    }

    $prev_path = get_attached_file( $previous_id );
    $new_path  = get_attached_file( $new_id );

    if ( $prev_path && $new_path && wp_normalize_path( $prev_path ) === wp_normalize_path( $new_path ) ) {
        global $wpdb;
        $wpdb->delete( $wpdb->postmeta, array( 'post_id' => $previous_id ) );
        $wpdb->delete( $wpdb->posts,    array( 'ID'      => $previous_id ) );
        clean_post_cache( $previous_id );
        return;
    }

    wp_delete_attachment( $previous_id, true );
}

/**
 * Extract asset URLs from Gutenberg content
 *
 * @param string $content The post content
 * @return array List of asset URLs found in content
 */
function firefly_projects_extract_assets($content) {
    $assets = array();

    // Patterns to match asset URLs
    $patterns = array(
        // Image sources
        '/src=["\']([^"\']*\/wp-content\/uploads\/[^"\']+)["\']/i',
        // Background images in inline styles
        '/url\(["\']?([^"\')]*\/wp-content\/uploads\/[^"\')]+)["\']?\)/i',
        // href to files (PDFs, docs, etc.)
        '/href=["\']([^"\']*\/wp-content\/uploads\/[^"\']+\.(?:pdf|doc|docx|xls|xlsx|zip|png|jpg|jpeg|gif|webp|svg))["\']/i'
    );

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $url) {
                // Normalize URL to path
                $path = $url;
                
                // Remove domain if present
                if (strpos($path, 'http') === 0) {
                    $parsed = parse_url($path);
                    $path = isset($parsed['path']) ? $parsed['path'] : $path;
                }

                // Only add unique assets
                if (!in_array($path, $assets)) {
                    $assets[] = $path;
                }
            }
        }
    }

    return $assets;
}

/**
 * Convert asset URL to local file path
 *
 * @param string $url The asset URL or path
 * @return string|false The local file path, or false if not found
 */
function firefly_projects_asset_url_to_path($url) {
    // Get WordPress upload directory info
    $upload_dir = wp_upload_dir();
    $upload_base_url = $upload_dir['baseurl'];
    $upload_base_path = $upload_dir['basedir'];

    // Handle relative URLs starting with /wp-content/uploads/
    if (strpos($url, '/wp-content/uploads/') === 0) {
        $relative_path = str_replace('/wp-content/uploads/', '', $url);
        $file_path = $upload_base_path . '/' . $relative_path;
        
        if (file_exists($file_path)) {
            return $file_path;
        }
    }

    // Handle full URLs
    if (strpos($url, $upload_base_url) !== false) {
        $relative_path = str_replace($upload_base_url . '/', '', $url);
        $file_path = $upload_base_path . '/' . $relative_path;
        
        if (file_exists($file_path)) {
            return $file_path;
        }
    }

    return false;
}

/**
 * Extract assets from content and return with local file paths
 * Used for syncing assets at their original upload locations
 *
 * @param string $content The post content
 * @return array List of assets with url, path, filename, and original_url
 */
function firefly_projects_extract_assets_with_paths($content) {
    $assets = array();
    $asset_urls = firefly_projects_extract_assets($content);

    foreach ($asset_urls as $url) {
        $local_path = firefly_projects_asset_url_to_path($url);

        if ($local_path && file_exists($local_path)) {
            // Determine the relative path within wp-content/uploads
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace($upload_dir['basedir'], '', $local_path);
            $relative_path = ltrim($relative_path, '/\\');

            $assets[] = array(
                'url'          => $url,
                'path'         => $local_path,
                'filename'     => basename($local_path),
                'relative_path' => $relative_path,  // e.g., "2024/11/portfolio-swrr.webp"
                'size'         => filesize($local_path)
            );
        }
    }

    return $assets;
}

/**
 * Package page content and assets for sync
 *
 * @param WP_Post $post The post object
 * @param bool $include_assets Whether to include media assets
 * @return array Package data including content and assets
 */
function firefly_projects_package_page($post, $include_assets = true) {
    $package = array(
        'post_data' => array(
            'post_title'   => $post->post_title,
            'post_name'    => $post->post_name,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_type'    => $post->post_type,
            'post_status'  => $post->post_status,
            'menu_order'   => $post->menu_order,
            'post_parent'  => $post->post_parent,
        ),
        'meta_data' => array(),
        'assets'    => array(),
        'asset_files' => array()
    );

    // Get post meta (excluding internal WordPress meta)
    $meta = get_post_meta($post->ID);
    // Whitelist underscore-prefixed keys that should sync
    $allowed_underscore_keys = array(
        '_thumbnail_id', '_firefly_template', '_firefly_page_id', '_firefly_mobile_thumbnail_breakpoint',
        // GEO meta
        '_geo_summary', '_geo_key_facts', '_geo_article_type', '_geo_faq',
        // SEO meta (per-page overrides — see seo-post.php for the registration)
        '_seo_title', '_seo_description', '_seo_canonical',
        '_seo_robots_noindex', '_seo_robots_nofollow',
        // NOTE: _seo_og_image_id is intentionally NOT in this whitelist.
        // The id is environment-specific; the OG image file ships in the zip
        // and the receiver re-resolves the id against the dev attachment.
        '_seo_og_title', '_seo_og_description',
    );
    foreach ($meta as $key => $values) {
        // Skip internal meta keys (except whitelisted ones)
        if (strpos($key, '_') === 0 && !in_array($key, $allowed_underscore_keys)) {
            continue;
        }
        $package['meta_data'][$key] = $values[0];
    }

    // Extract and package assets if requested
    if ($include_assets) {
        $asset_urls = firefly_projects_extract_assets($post->post_content);
        
        foreach ($asset_urls as $url) {
            $local_path = firefly_projects_asset_url_to_path($url);
            
            if ($local_path && file_exists($local_path)) {
                $package['assets'][] = array(
                    'url'  => $url,
                    'path' => $local_path,
                    'name' => basename($local_path),
                    'size' => filesize($local_path)
                );
            }
        }
    }

    return $package;
}

/**
 * Collect the theme-side files associated with a page/post for sync.
 *
 * The DB sync alone ships post_content + meta + uploads — but the snippet HTML
 * and schema entry live on disk in the theme tree. Save_post on the receiver
 * would normally regenerate them, except it fires before _firefly_template
 * meta is applied, so it bails. We ship the files explicitly here.
 *
 * Returns:
 *   array(
 *     'snippet'      => array(rel_path, content)   // omitted if file missing
 *     'schema_entry' => array(template, kind, slug, entry, schema_rel_path)
 *     'warnings'     => string[]
 *   )
 * or array('warnings' => [...]) when no template assignment exists.
 */
function firefly_projects_collect_associated_files( $post ) {
    $out = array( 'warnings' => array() );

    $template = get_post_meta( $post->ID, '_firefly_template', true );
    if ( empty( $template ) ) {
        $out['warnings'][] = 'No _firefly_template meta on post; skipped associated files.';
        return $out;
    }

    // Snippet — reuse the canonical path resolver from the theme.
    if ( function_exists( 'firefly_get_snippet_path' ) ) {
        $snippet_abs = firefly_get_snippet_path( $post->ID, $post->post_type );
        if ( $snippet_abs && file_exists( $snippet_abs ) ) {
            $content_root = trailingslashit( WP_CONTENT_DIR );
            // Convert absolute → wp-content-relative for the receiver to land at.
            if ( strpos( $snippet_abs, $content_root ) === 0 ) {
                $rel = substr( $snippet_abs, strlen( $content_root ) );
                $out['snippet'] = array(
                    'rel_path' => $rel,                         // e.g. themes/firefly-collective/templates/default/snippets/pages/about.html
                    'content'  => file_get_contents( $snippet_abs ),
                );
            } else {
                $out['warnings'][] = 'Snippet path is outside wp-content: ' . $snippet_abs;
            }
        } else {
            $out['warnings'][] = 'Snippet file missing on sender: ' . ( $snippet_abs ? $snippet_abs : '(unresolved)' );
        }
    }

    // Schema entry — pluck the slug's record from the active schema JSON.
    $schema_abs = get_template_directory() . '/data/schemas/' . $template . '-schema.json';
    if ( file_exists( $schema_abs ) ) {
        $raw = file_get_contents( $schema_abs );
        $schema = json_decode( $raw, true );
        if ( is_array( $schema ) ) {
            $kind = ( $post->post_type === 'post' ) ? 'posts' : 'pages';
            $slug = $post->post_name;
            $entry = null;
            $base_slug = preg_replace( '/-\d+$/', '', $slug );
            if ( isset( $schema[ $kind ] ) && is_array( $schema[ $kind ] ) ) {
                foreach ( $schema[ $kind ] as $row ) {
                    if ( ! isset( $row['slug'] ) ) continue;
                    if ( $row['slug'] === $slug || ( $base_slug !== $slug && $row['slug'] === $base_slug ) ) {
                        $entry = $row;
                        break;
                    }
                }
            }
            if ( $entry ) {
                $content_root = trailingslashit( WP_CONTENT_DIR );
                $schema_rel = ( strpos( $schema_abs, $content_root ) === 0 )
                    ? substr( $schema_abs, strlen( $content_root ) )
                    : null;
                $out['schema_entry'] = array(
                    'template'        => $template,
                    'kind'            => $kind,
                    'slug'            => $slug,
                    'entry'           => $entry,
                    'schema_rel_path' => $schema_rel,
                );
            } else {
                $out['warnings'][] = "Schema entry for slug '{$slug}' not found in {$template}-schema.json.";
            }
        } else {
            $out['warnings'][] = 'Schema JSON parse failed: ' . $schema_abs;
        }
    } else {
        $out['warnings'][] = 'Schema file missing on sender: ' . $schema_abs;
    }

    return $out;
}

/**
 * Apply the associated_files manifest to the local filesystem (receiver side).
 *
 * Writes snippet HTML to its declared rel_path and merges the schema_entry into
 * the local schema JSON for that slug. Always sender-wins for the targeted slug;
 * other slugs in the schema are untouched.
 *
 * Returns:
 *   array( 'files_written' => string[], 'warnings' => string[] )
 */
function firefly_projects_apply_associated_files( $associated, $post ) {
    $result = array( 'files_written' => array(), 'warnings' => array() );
    if ( ! is_array( $associated ) ) return $result;

    $content_root = realpath( WP_CONTENT_DIR );
    if ( ! $content_root ) {
        $result['warnings'][] = 'realpath(WP_CONTENT_DIR) failed; refusing to write.';
        return $result;
    }
    $content_root = rtrim( $content_root, DIRECTORY_SEPARATOR );

    // ---- Snippet ----
    if ( ! empty( $associated['snippet']['rel_path'] ) && isset( $associated['snippet']['content'] ) ) {
        $rel = ltrim( (string) $associated['snippet']['rel_path'], '/\\' );
        $candidate = $content_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
        // Resolve parent dir realpath for containment; create it if missing.
        $parent_dir = dirname( $candidate );
        if ( ! is_dir( $parent_dir ) ) {
            wp_mkdir_p( $parent_dir );
        }
        $parent_real = realpath( $parent_dir );
        if ( $parent_real && strpos( $parent_real, $content_root ) === 0 ) {
            $final_path = $parent_real . DIRECTORY_SEPARATOR . basename( $candidate );
            if ( @file_put_contents( $final_path, (string) $associated['snippet']['content'] ) !== false ) {
                $result['files_written'][] = $rel;
            } else {
                $result['warnings'][] = 'Snippet write failed: ' . $rel;
            }
        } else {
            $result['warnings'][] = 'Snippet path escapes wp-content; refused: ' . $rel;
        }
    }

    // ---- Schema entry ----
    if ( ! empty( $associated['schema_entry']['template'] ) && ! empty( $associated['schema_entry']['slug'] ) && isset( $associated['schema_entry']['entry'] ) ) {
        $template = (string) $associated['schema_entry']['template'];
        $kind     = ( ( $associated['schema_entry']['kind'] ?? '' ) === 'posts' ) ? 'posts' : 'pages';
        $slug     = (string) $associated['schema_entry']['slug'];
        $entry    = $associated['schema_entry']['entry'];

        // Resolve the receiver's schema path the same way the theme does, not
        // by trusting the sender's path — they may differ if the theme moves.
        $schema_abs = get_template_directory() . '/data/schemas/' . $template . '-schema.json';
        $parent_real = realpath( dirname( $schema_abs ) );
        if ( $parent_real && strpos( $parent_real, $content_root ) === 0 && file_exists( $schema_abs ) ) {
            $raw = file_get_contents( $schema_abs );
            $schema = json_decode( $raw, true );
            if ( is_array( $schema ) ) {
                if ( ! isset( $schema[ $kind ] ) || ! is_array( $schema[ $kind ] ) ) {
                    $schema[ $kind ] = array();
                }
                $found = false;
                $base_slug = preg_replace( '/-\d+$/', '', $slug );
                foreach ( $schema[ $kind ] as $i => $row ) {
                    if ( ! isset( $row['slug'] ) ) continue;
                    if ( $row['slug'] === $slug || ( $base_slug !== $slug && $row['slug'] === $base_slug ) ) {
                        $schema[ $kind ][ $i ] = $entry;
                        $found = true;
                        break;
                    }
                }
                if ( ! $found ) {
                    $schema[ $kind ][] = $entry;
                }
                // Atomic write: tmp + rename so a crash mid-write doesn't leave a half-file.
                $tmp = $schema_abs . '.tmp';
                $encoded = json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
                if ( $encoded !== false && @file_put_contents( $tmp, $encoded ) !== false && @rename( $tmp, $schema_abs ) ) {
                    $result['files_written'][] = ltrim( str_replace( $content_root . DIRECTORY_SEPARATOR, '', $schema_abs ), '/\\' );
                } else {
                    @unlink( $tmp );
                    $result['warnings'][] = 'Schema write failed: ' . $schema_abs;
                }
            } else {
                $result['warnings'][] = 'Local schema JSON parse failed; refused to overwrite.';
            }
        } else {
            $result['warnings'][] = 'Schema path inaccessible or escapes wp-content: ' . $schema_abs;
        }
    }

    // Forward warnings from the sender's collection step (e.g. "file missing on sender").
    if ( ! empty( $associated['warnings'] ) && is_array( $associated['warnings'] ) ) {
        foreach ( $associated['warnings'] as $w ) {
            $result['warnings'][] = '[sender] ' . $w;
        }
    }

    return $result;
}

/**
 * Perform page sync to remote site
 *
 * @param WP_Post $post The post object to sync
 * @param bool $include_assets Whether to include media assets
 * @param string $target_env Target environment: 'dev' (Live Dev) or 'prod' (Production)
 * @return array Result array with success status and message
 */
function firefly_projects_perform_page_sync($post, $include_assets = true, $target_env = 'dev') {
    // Load asset mapping functions
    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/asset-mapping.php';

    // Get asset map for this page
    $asset_map = firefly_projects_get_asset_map($post->ID);

    // Get remote endpoint based on target environment
    $project_endpoint = ($target_env === 'prod') ? PROD_ENDPOINT : LIVE_DEV_ENDPOINT;

    // Extract base URL and build page sync endpoint
    if (preg_match('/(https?:\/\/[^\/]+)/', $project_endpoint, $matches)) {
        $base_url = $matches[1];
        $page_endpoint = $base_url . '/wp-json/firefly-plugin/v1/receive-page';
    } else {
        $page_endpoint = str_replace('/update_project', '/receive-page', $project_endpoint);
        $page_endpoint = str_replace('firefly-collective', 'firefly-plugin', $page_endpoint);
    }

    $env_label = ($target_env === 'prod') ? 'Production' : 'Live Dev';

    // Prepare content and assets based on target environment
    // Relativize URLs so content is domain-agnostic during sync
    $content_to_sync = $post->post_content;
    if ( function_exists( 'firefly_relativize_urls' ) ) {
        $content_to_sync = firefly_relativize_urls( $content_to_sync );
    }
    $assets_to_sync = array();

    if ($target_env === 'prod') {
        // PRODUCTION SYNC
        // Restore original URLs if we have mappings from production
        if ($asset_map['asset_origin'] === 'production' && !empty($asset_map['mappings'])) {
            // Reverse the mappings: dev -> original
            $content_to_sync = firefly_projects_rewrite_content_urls($post->post_content, $asset_map['mappings'], 'to_prod');
            // Don't send assets - originals already exist on production
        }
        // For dev-created content, create production paths and send assets
        elseif (!empty($asset_map['dev_created']) || $asset_map['asset_origin'] === 'dev') {
            $prod_result = firefly_projects_prepare_content_for_production($post->ID);
            if ($prod_result['success']) {
                $content_to_sync = $prod_result['content'];
                $assets_to_sync = $prod_result['assets_to_sync'];
            }
        }
        // No asset map (e.g. content from CLI import) — extract and send assets directly
        else {
            if ($include_assets) {
                $content_assets = firefly_projects_extract_assets_with_paths($post->post_content);
                foreach ($content_assets as $asset) {
                    $assets_to_sync[] = $asset;
                }
            }
        }
    } else {
        // LIVE DEV SYNC
        // Get page assets to sync - both from pages folder AND original upload paths
        if ($include_assets) {
            // First get any assets already in the pages folder
            $assets_to_sync = firefly_projects_get_page_assets_for_sync($post->ID);

            // Also extract assets from content at their original paths
            $content_assets = firefly_projects_extract_assets_with_paths($post->post_content);
            foreach ($content_assets as $asset) {
                // Avoid duplicates
                $already_included = false;
                foreach ($assets_to_sync as $existing) {
                    if (basename($existing['path']) === basename($asset['path'])) {
                        $already_included = true;
                        break;
                    }
                }
                if (!$already_included) {
                    $assets_to_sync[] = $asset;
                }
            }
        }
    }

    // Package post data
    $meta = get_post_meta($post->ID);
    $meta_data = array();
    // Whitelist underscore-prefixed keys that should sync
    $allowed_underscore_keys = array(
        '_thumbnail_id', '_firefly_template', '_firefly_page_id', '_firefly_mobile_thumbnail_breakpoint',
        // GEO meta
        '_geo_summary', '_geo_key_facts', '_geo_article_type', '_geo_faq',
        // SEO meta (per-page overrides — see seo-post.php for the registration)
        '_seo_title', '_seo_description', '_seo_canonical',
        '_seo_robots_noindex', '_seo_robots_nofollow',
        // NOTE: _seo_og_image_id is intentionally NOT in this whitelist.
        // The id is environment-specific; the OG image file ships in the zip
        // and the receiver re-resolves the id against the dev attachment.
        '_seo_og_title', '_seo_og_description',
    );
    foreach ($meta as $key => $values) {
        if (strpos($key, '_') === 0 && !in_array($key, $allowed_underscore_keys)) {
            continue;
        }
        $meta_data[$key] = $values[0];
    }

    // Ensure _firefly_page_id is always present (compute from template + slug if missing)
    if (empty($meta_data['_firefly_page_id']) && !empty($meta_data['_firefly_template'])) {
        $meta_data['_firefly_page_id'] = $meta_data['_firefly_template'] . ':' . $post->post_name;
        // Backfill locally too
        update_post_meta($post->ID, '_firefly_page_id', $meta_data['_firefly_page_id']);
    }

    $post_data = array(
        'post_title'   => $post->post_title,
        'post_name'    => $post->post_name,
        'post_content' => $content_to_sync,
        'post_excerpt' => $post->post_excerpt,
        'post_type'    => $post->post_type,
        'post_status'  => $post->post_status,
        'menu_order'   => $post->menu_order,
        'post_parent'  => $post->post_parent,
    );

    // Check if this page has special WordPress roles (home page, posts page)
    $page_role = null;
    if ($post->post_type === 'page') {
        $show_on_front = get_option('show_on_front');
        if ($show_on_front === 'page') {
            $page_on_front = get_option('page_on_front');
            $page_for_posts = get_option('page_for_posts');

            if ($page_on_front && $page_on_front == $post->ID) {
                $page_role = 'front_page';
            } elseif ($page_for_posts && $page_for_posts == $post->ID) {
                $page_role = 'posts_page';
            }
        }
    }

    // Get featured image data if exists
    $featured_image = null;
    $featured_image_path = null;
    $thumbnail_id = get_post_thumbnail_id($post->ID);
    if ($thumbnail_id) {
        $thumbnail_path = get_attached_file($thumbnail_id);
        if ($thumbnail_path && file_exists($thumbnail_path)) {
            $featured_image = array(
                'filename'  => basename($thumbnail_path),
                'mime_type' => get_post_mime_type($thumbnail_id),
                'alt_text'  => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
                'title'     => get_the_title($thumbnail_id)
            );
            $featured_image_path = $thumbnail_path;
        }
    }

    // Get mobile featured image data if exists. Stored on the post as
    // _firefly_mobile_thumbnail_id, the same attachment-id shape as the
    // standard featured image. The receiver re-creates the attachment and
    // wires it back up on the synced post.
    //
    // When the local post has NO mobile thumbnail, we still send an explicit
    // "clear" signal so the receiver can wipe any stale mobile attachment on
    // the remote — otherwise removing the mobile featured image locally
    // never propagates and dev stays stuck on the old one.
    $mobile_featured_image = null;
    $mobile_featured_image_path = null;
    $mobile_featured_image_clear = false;
    $mobile_thumbnail_id = (int) get_post_meta( $post->ID, '_firefly_mobile_thumbnail_id', true );
    if ( $mobile_thumbnail_id ) {
        $mobile_path = get_attached_file( $mobile_thumbnail_id );
        if ( $mobile_path && file_exists( $mobile_path ) ) {
            $mobile_featured_image = array(
                'filename'  => basename( $mobile_path ),
                'mime_type' => get_post_mime_type( $mobile_thumbnail_id ),
                'alt_text'  => get_post_meta( $mobile_thumbnail_id, '_wp_attachment_image_alt', true ),
                'title'     => get_the_title( $mobile_thumbnail_id ),
            );
            $mobile_featured_image_path = $mobile_path;
        }
    } else {
        $mobile_featured_image_clear = true;
    }

    // Per-page _seo_og_image_id override. Same shape as featured/mobile:
    // package the file in the zip so the receiver can create an attachment
    // on the remote and re-resolve the id. When empty locally, send a
    // clear signal so the remote drops any stale override.
    $og_image = null;
    $og_image_path = null;
    $og_image_clear = false;
    $og_image_id = (int) get_post_meta( $post->ID, '_seo_og_image_id', true );
    if ( $og_image_id ) {
        $og_path_local = get_attached_file( $og_image_id );
        if ( $og_path_local && file_exists( $og_path_local ) ) {
            $og_image = array(
                'filename'  => basename( $og_path_local ),
                'mime_type' => get_post_mime_type( $og_image_id ),
                'alt_text'  => get_post_meta( $og_image_id, '_wp_attachment_image_alt', true ),
                'title'     => get_the_title( $og_image_id ),
            );
            $og_image_path = $og_path_local;
        }
    } else {
        $og_image_clear = true;
    }

    // Collect theme-side files associated with this post (snippet + schema entry).
    // Warnings are forwarded to the response so the caller can surface them.
    $associated_files = firefly_projects_collect_associated_files( $post );

    // Get tracked links for this post (moved up so the zip manifest can include them)
    $tracked_links = array();
    if (function_exists('firefly_link_tracking_get_post_links_for_sync')) {
        $tracked_links = firefly_link_tracking_get_post_links_for_sync($post->ID);
    }

    // Create temporary zip file with assets or featured image
    $zip_path = null;
    if (!empty($assets_to_sync) || $featured_image_path || $mobile_featured_image_path || $og_image_path) {
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'firefly_collective_temp';

        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $zip_path = $temp_dir . '/page_sync_' . $post->post_name . '_' . time() . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
            return array(
                'success' => false,
                'message' => 'Failed to create asset package.'
            );
        }

        // Add assets to zip
        foreach ($assets_to_sync as $asset) {
            $asset_path = isset($asset['path']) ? $asset['path'] : (isset($asset['dev_path']) ? $asset['dev_path'] : '');
            $asset_name = isset($asset['filename']) ? $asset['filename'] : basename($asset_path);
            if ($asset_path && file_exists($asset_path)) {
                $zip->addFile($asset_path, 'assets/' . $asset_name);
            }
        }

        // Add featured image to zip if exists
        if ($featured_image_path && file_exists($featured_image_path)) {
            $zip->addFile($featured_image_path, 'featured/' . basename($featured_image_path));
        }

        // Add mobile featured image to zip if exists
        if ($mobile_featured_image_path && file_exists($mobile_featured_image_path)) {
            $zip->addFile($mobile_featured_image_path, 'mobile_featured/' . basename($mobile_featured_image_path));
        }

        // Add OG image override to zip if exists. Deliberately a separate
        // path so the receiver can de-duplicate vs featured / mobile featured
        // when the user picked the same file for multiple slots.
        if ($og_image_path && file_exists($og_image_path)) {
            $zip->addFile($og_image_path, 'og_image/' . basename($og_image_path));
        }

        // Add manifest with relative paths for proper extraction
        $manifest = array(
            'post_data'                   => $post_data,
            'meta_data'                   => $meta_data,
            'target_env'                  => $target_env,
            'asset_map'                   => $asset_map,
            'featured_image'              => $featured_image,
            'mobile_featured_image'       => $mobile_featured_image,
            'mobile_featured_image_clear' => $mobile_featured_image_clear,
            'og_image'                    => $og_image,
            'og_image_clear'              => $og_image_clear,
            'page_role'                   => $page_role,
            'tracked_links'               => $tracked_links,
            'associated_files'            => $associated_files,
            'assets'         => array_map(function($a) {
                return array(
                    'url'           => isset($a['url']) ? $a['url'] : '',
                    'filename'      => isset($a['filename']) ? $a['filename'] : basename($a['path']),
                    'relative_path' => isset($a['relative_path']) ? $a['relative_path'] : ''
                );
            }, $assets_to_sync)
        );
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $zip->close();
    }

    // Prepare request body — $tracked_links and $associated_files were collected
    // before the zip block so the zip manifest could include them.
    //
    // The receiver decodes the multipart `manifest` form field from THIS body
    // (not the manifest.json inside the zip), so every field the receiver
    // reads has to be present here too. Forgetting to mirror a field here
    // was the cause of mobile_featured_image silently never landing on the
    // remote even though the zip's manifest.json had it correctly.
    $body = array(
        'post_data'                   => $post_data,
        'meta_data'                   => $meta_data,
        'target_env'                  => $target_env,
        'asset_map'                   => $asset_map,
        'featured_image'              => $featured_image,
        'mobile_featured_image'       => $mobile_featured_image,
        'mobile_featured_image_clear' => $mobile_featured_image_clear,
        'og_image'                    => $og_image,
        'og_image_clear'              => $og_image_clear,
        'has_assets'                  => !empty($assets_to_sync) || $featured_image_path || $mobile_featured_image_path || $og_image_path,
        'page_role'                   => $page_role,
        'tracked_links'               => $tracked_links,
        'associated_files'            => $associated_files,
    );

    // Send request
    if ($zip_path && file_exists($zip_path)) {
        // Use cURL for multipart form data
        $ch = curl_init();

        $post_fields = array(
            'manifest' => json_encode($body),
            'assets'   => new CURLFile($zip_path, 'application/zip', 'assets.zip')
        );

        curl_setopt_array($ch, array(
            CURLOPT_URL            => $page_endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array(
                'X-Firefly-Secret: ' . FIREFLY_SHARED_SECRET
            ),
            CURLOPT_TIMEOUT        => 120
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Clean up temp file
        @unlink($zip_path);

        if ($error) {
            return array(
                'success' => false,
                'message' => 'Connection error: ' . $error
            );
        }
    } else {
        // Simple JSON request without assets
        $response = wp_remote_post($page_endpoint, array(
            'headers' => array(
                'Content-Type'      => 'application/json',
                'X-Firefly-Secret'  => FIREFLY_SHARED_SECRET
            ),
            'body'    => json_encode($body),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Connection error: ' . $response->get_error_message()
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        $response = $response_body;
    }

    // Parse response
    $result = json_decode($response, true);

    if ($http_code === 200 && isset($result['success']) && $result['success']) {
        // Save last sync timestamp + attribution per environment.
        $sync_time = time();
        $user_id   = get_current_user_id();
        if ($target_env === 'prod') {
            update_post_meta($post->ID, '_firefly_last_sync_prod', $sync_time);
            if ( $user_id ) update_post_meta($post->ID, '_firefly_last_sync_prod_by', $user_id);
        } else {
            update_post_meta($post->ID, '_firefly_last_sync_dev', $sync_time);
            if ( $user_id ) update_post_meta($post->ID, '_firefly_last_sync_dev_by', $user_id);
        }

        // Files reported by the receiver (snippet + schema) plus media assets we shipped.
        $associated_written = isset($result['associated_files_written']) && is_array($result['associated_files_written'])
            ? $result['associated_files_written']
            : array();
        $associated_warnings = isset($result['associated_files_warnings']) && is_array($result['associated_files_warnings'])
            ? $result['associated_files_warnings']
            : array();
        $files_count = count($assets_to_sync) + count($associated_written);

        // Activity log row — push, success.
        if ( function_exists( 'firefly_projects_log_sync' ) ) {
            $revisions = wp_get_post_revisions( $post->ID, array( 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
            $revision_id = $revisions ? (int) array_key_first( $revisions ) : null;
            firefly_projects_log_sync(array(
                'post_id'     => (int) $post->ID,
                'post_type'   => $post->post_type,
                'direction'   => 'push',
                'env'         => $target_env,
                'user_id'     => $user_id ?: null,
                'status'      => 'success',
                'revision_id' => $revision_id,
                'files_count' => $files_count,
                'summary'     => array(
                    'target_host'        => parse_url($page_endpoint, PHP_URL_HOST),
                    'media_files'        => array_map(function($a) {
                        return isset($a['filename']) ? $a['filename'] : basename( isset($a['path']) ? $a['path'] : '' );
                    }, $assets_to_sync),
                    'associated_files'   => $associated_written,
                    'warnings'           => $associated_warnings,
                ),
            ));
        }

        return array(
            'success' => true,
            'message' => 'Page synced successfully to ' . $env_label . '.',
            'details' => array(
                'page_title'        => $post->post_title,
                'page_slug'         => $post->post_name,
                'files_synced'      => $files_count,
                'associated_files'  => $associated_written,
                'warnings'          => $associated_warnings,
                'synced_at'         => $sync_time,
                'target_env'        => $target_env
            )
        );
    } else {
        $error_message = isset($result['message']) ? $result['message'] : 'Unknown error occurred.';

        // Activity log row — push, failure.
        if ( function_exists( 'firefly_projects_log_sync' ) ) {
            firefly_projects_log_sync(array(
                'post_id'     => (int) $post->ID,
                'post_type'   => $post->post_type,
                'direction'   => 'push',
                'env'         => $target_env,
                'user_id'     => get_current_user_id() ?: null,
                'status'      => 'failure',
                'revision_id' => null,
                'files_count' => 0,
                'summary'     => array(
                    'target_host'    => parse_url($page_endpoint, PHP_URL_HOST),
                    'error_message'  => $error_message,
                    'http_code'      => isset($http_code) ? (int) $http_code : null,
                ),
            ));
        }

        return array(
            'success' => false,
            'message' => 'Sync to ' . $env_label . ' failed: ' . $error_message
        );
    }
}

/**
 * Handle incoming page sync from local site (remote endpoint)
 *
 * @param WP_REST_Request $request The REST request
 * @return array Result array with success status and message
 */
function firefly_projects_handle_incoming_page($request) {
    // Suppress the save_post → firefly_save_snippet hook for the duration of
    // this request — we're going to write the snippet explicitly from the
    // sender's manifest, and we don't want the hook to clobber it from
    // post_content immediately after wp_insert_post fires.
    if ( ! defined( 'FIREFLY_PROJECTS_SYNCING_INBOUND' ) ) {
        define( 'FIREFLY_PROJECTS_SYNCING_INBOUND', true );
    }

    // Get content type to determine how data was sent
    $content_type = $request->get_content_type();

    // Handle multipart form data (with assets)
    if (isset($content_type['value']) && strpos($content_type['value'], 'multipart/form-data') !== false) {
        $manifest = json_decode($request->get_param('manifest'), true);
        $post_data = $manifest['post_data'];
        $meta_data = isset($manifest['meta_data']) ? $manifest['meta_data'] : array();
        $has_assets = isset($manifest['has_assets']) ? $manifest['has_assets'] : false;
        $target_env = isset($manifest['target_env']) ? $manifest['target_env'] : 'dev';
        $asset_map = isset($manifest['asset_map']) ? $manifest['asset_map'] : array();
        $featured_image = isset($manifest['featured_image']) ? $manifest['featured_image'] : null;
        $mobile_featured_image = isset($manifest['mobile_featured_image']) ? $manifest['mobile_featured_image'] : null;
        $mobile_featured_image_clear = ! empty($manifest['mobile_featured_image_clear']);
        $og_image = isset($manifest['og_image']) ? $manifest['og_image'] : null;
        $og_image_clear = ! empty($manifest['og_image_clear']);
        $page_role = isset($manifest['page_role']) ? $manifest['page_role'] : null;
        $tracked_links = isset($manifest['tracked_links']) ? $manifest['tracked_links'] : array();
        $associated_files = isset($manifest['associated_files']) ? $manifest['associated_files'] : array();

        // Handle uploaded zip file
        $files = $request->get_file_params();
        $zip_file = isset($files['assets']) ? $files['assets']['tmp_name'] : null;
    } else {
        // Handle JSON request (no assets)
        $body = $request->get_json_params();
        $post_data = $body['post_data'];
        $meta_data = isset($body['meta_data']) ? $body['meta_data'] : array();
        $has_assets = isset($body['has_assets']) ? $body['has_assets'] : false;
        $target_env = isset($body['target_env']) ? $body['target_env'] : 'dev';
        $asset_map = isset($body['asset_map']) ? $body['asset_map'] : array();
        $featured_image = isset($body['featured_image']) ? $body['featured_image'] : null;
        $mobile_featured_image = isset($body['mobile_featured_image']) ? $body['mobile_featured_image'] : null;
        $mobile_featured_image_clear = ! empty($body['mobile_featured_image_clear']);
        $og_image = isset($body['og_image']) ? $body['og_image'] : null;
        $og_image_clear = ! empty($body['og_image_clear']);
        $page_role = isset($body['page_role']) ? $body['page_role'] : null;
        $tracked_links = isset($body['tracked_links']) ? $body['tracked_links'] : array();
        $associated_files = isset($body['associated_files']) ? $body['associated_files'] : array();
        $zip_file = null;
    }

    // Debug logger
    $sync_log = function($msg) {
        file_put_contents(WP_CONTENT_DIR . '/sync-debug.log', date('Y-m-d H:i:s') . " {$msg}\n", FILE_APPEND);
    };

    // Find existing post by _firefly_page_id meta (stable cross-environment identifier)
    $existing_post = null;
    $firefly_page_id = isset($meta_data['_firefly_page_id']) ? $meta_data['_firefly_page_id'] : '';

    $sync_log("INCOMING slug={$post_data['post_name']} fpid={$firefly_page_id} tmpl=" . (isset($meta_data['_firefly_template']) ? $meta_data['_firefly_template'] : 'NONE'));

    if (!empty($firefly_page_id)) {
        $found = get_posts(array(
            'post_type'            => $post_data['post_type'],
            'post_status'          => 'any',
            'numberposts'          => 1,
            'meta_key'             => '_firefly_page_id',
            'meta_value'           => $firefly_page_id,
            'firefly_skip_scoping' => true,
        ));
        if (!empty($found)) {
            $existing_post = $found[0];
            $sync_log("  FOUND by fpid: ID={$existing_post->ID} slug={$existing_post->post_name}");
        } else {
            $sync_log("  NO MATCH by fpid={$firefly_page_id}");
        }
    }

    // Fallback to slug lookup for backwards compatibility
    if (!$existing_post) {
        $fallback = get_page_by_path($post_data['post_name'], OBJECT, $post_data['post_type']);
        if ($fallback) {
            $fallback_template = get_post_meta($fallback->ID, '_firefly_template', true);
            $incoming_template = isset($meta_data['_firefly_template']) ? $meta_data['_firefly_template'] : '';
            // Only use fallback if templates match (or fallback has no template)
            if (empty($fallback_template) || $fallback_template === $incoming_template) {
                $existing_post = $fallback;
                $sync_log("  SLUG FALLBACK: ID={$fallback->ID} slug={$fallback->post_name} tmpl={$fallback_template}");
            } else {
                $sync_log("  SLUG FALLBACK REJECTED: ID={$fallback->ID} tmpl={$fallback_template} != incoming {$incoming_template} — will create new");
            }
        } else {
            $sync_log("  NO MATCH — will create new");
        }
    }

    // Prepare post data for insert/update
    $wp_post_data = array(
        'post_title'   => $post_data['post_title'],
        'post_name'    => $post_data['post_name'],
        'post_content' => $post_data['post_content'],
        'post_excerpt' => isset($post_data['post_excerpt']) ? $post_data['post_excerpt'] : '',
        'post_type'    => $post_data['post_type'],
        'post_status'  => $post_data['post_status'],
        'menu_order'   => isset($post_data['menu_order']) ? $post_data['menu_order'] : 0,
    );

    // wp_insert_post / wp_update_post internally call wp_unslash() on their
    // input — they expect PRE-SLASHED data. Our $post_data came straight
    // from JSON decode (unslashed), so we must wp_slash() here or WP will
    // strip legitimate backslashes inside block-attribute JSON such as
    // "heading":"...\u003cspan\u003e..." — turning \u003c into the literal
    // string "u003c" on the live site.
    $wp_post_data = wp_slash($wp_post_data);

    if ($existing_post) {
        $wp_post_data['ID'] = $existing_post->ID;
        $post_id = wp_update_post($wp_post_data, true);
        $sync_log("  UPDATED ID={$post_id}");
    } else {
        $post_id = wp_insert_post($wp_post_data, true);
        $sync_log("  CREATED ID={$post_id}");
    }

    if (is_wp_error($post_id)) {
        $sync_log("  ERROR: " . $post_id->get_error_message());
        return array(
            'success' => false,
            'message' => 'Failed to save post: ' . $post_id->get_error_message()
        );
    }

    $final_slug = get_post_field('post_name', $post_id);
    $sync_log("  DONE ID={$post_id} final_slug={$final_slug}");

    // Update meta data
    foreach ($meta_data as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }

    // Preserve the intended slug across templates. wp_insert_post may have
    // deduplicated it (e.g. "home" -> "home-2") because another template owns
    // the slug — the theme's wp_unique_post_slug filter can't see
    // _firefly_template on a brand-new insert. Now that the template meta is
    // written, re-apply the desired slug; the filter keeps it scoped.
    $desired_slug = isset($post_data['post_name']) ? $post_data['post_name'] : '';
    if ($desired_slug && get_post_field('post_name', $post_id) !== $desired_slug) {
        wp_update_post(array('ID' => $post_id, 'post_name' => $desired_slug));
        $sync_log("  SLUG RESTORED -> {$desired_slug}");
    }

    // Save asset map if provided
    if (!empty($asset_map)) {
        update_post_meta($post_id, '_firefly_asset_map', $asset_map);
    }

    // Apply theme-side files (snippet + schema entry) AFTER meta has been written,
    // so the writer has access to the right _firefly_template if it needs to fall
    // back. The snippet auto-export hook is suppressed via FIREFLY_PROJECTS_SYNCING_INBOUND
    // (defined at the top of this function).
    $applied_post = get_post( $post_id );
    $associated_report = array( 'files_written' => array(), 'warnings' => array() );
    if ( $applied_post && ! empty( $associated_files ) ) {
        $associated_report = firefly_projects_apply_associated_files( $associated_files, $applied_post );
    }

    // Sync tracked links if provided
    if (!empty($tracked_links) && function_exists('firefly_link_tracking_sync_incoming_links')) {
        firefly_link_tracking_sync_incoming_links($post_id, $tracked_links);
    }

    // Process assets if included
    if ($has_assets && $zip_file && file_exists($zip_file)) {
        $upload_dir = wp_upload_dir();
        $uploads_base = $upload_dir['basedir'];

        // Also create page-specific directory as fallback
        $page_assets_dir = trailingslashit($uploads_base) . 'pages/' . $post_data['post_name'];
        if (!file_exists($page_assets_dir)) {
            wp_mkdir_p($page_assets_dir);
        }

        // Extract assets from zip
        $zip = new ZipArchive();
        if ($zip->open($zip_file) === true) {
            // Read manifest from zip
            $manifest_json = $zip->getFromName('manifest.json');
            $zip_manifest = json_decode($manifest_json, true);

            // Build a lookup of filename -> relative_path from manifest
            $asset_paths = array();
            if (isset($zip_manifest['assets']) && is_array($zip_manifest['assets'])) {
                foreach ($zip_manifest['assets'] as $asset_info) {
                    if (!empty($asset_info['relative_path'])) {
                        $asset_paths[$asset_info['filename']] = $asset_info['relative_path'];
                    }
                }
            }

            // Extract assets to their original paths
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos($filename, 'assets/') === 0 && $filename !== 'assets/') {
                    $asset_name = basename($filename);
                    $content = $zip->getFromIndex($i);

                    // Check if we have a relative path for this asset
                    if (isset($asset_paths[$asset_name]) && !empty($asset_paths[$asset_name])) {
                        // Extract to original path (e.g., /uploads/2024/11/image.webp)
                        $destination = $uploads_base . '/' . $asset_paths[$asset_name];
                        $destination_dir = dirname($destination);

                        // Create directory structure if needed
                        if (!file_exists($destination_dir)) {
                            wp_mkdir_p($destination_dir);
                        }
                    } else {
                        // Fallback to page-specific directory
                        $destination = $page_assets_dir . '/' . $asset_name;
                    }

                    file_put_contents($destination, $content);
                }
            }

            // Extract and process featured image if included.
            // Local is the source of truth: any prior featured attachment on
            // the remote is force-replaced by what arrived in the zip. We
            // delete the previous attachment record + file before inserting
            // the new one, so re-syncs don't accumulate orphan attachments
            // and stale image URLs (which a CDN can keep caching for hours).
            if ($featured_image && !empty($featured_image['filename'])) {
                $featured_filename = $featured_image['filename'];
                $featured_content = $zip->getFromName('featured/' . $featured_filename);

                if ($featured_content !== false) {
                    $previous_thumb_id = (int) get_post_thumbnail_id( $post_id );

                    $featured_path = $page_assets_dir . '/' . $featured_filename;
                    file_put_contents($featured_path, $featured_content);

                    // Create attachment post
                    $attachment = array(
                        'post_mime_type' => $featured_image['mime_type'],
                        'post_title'     => !empty($featured_image['title']) ? $featured_image['title'] : pathinfo($featured_filename, PATHINFO_FILENAME),
                        'post_content'   => '',
                        'post_status'    => 'inherit'
                    );

                    // Insert attachment
                    $attach_id = wp_insert_attachment($attachment, $featured_path, $post_id);

                    if (!is_wp_error($attach_id)) {
                        // Generate attachment metadata
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        $attach_data = wp_generate_attachment_metadata($attach_id, $featured_path);
                        wp_update_attachment_metadata($attach_id, $attach_data);

                        // Set alt text if provided
                        if (!empty($featured_image['alt_text'])) {
                            update_post_meta($attach_id, '_wp_attachment_image_alt', $featured_image['alt_text']);
                        }

                        // Set as featured image
                        set_post_thumbnail($post_id, $attach_id);

                        // Purge the previous featured attachment so the host
                        // stops pointing at an out-of-date image URL. Uses the
                        // path-aware helper because every sync writes the new
                        // file to the same path the previous attachment
                        // pointed at, and wp_delete_attachment_files() would
                        // unconditionally delete it.
                        firefly_projects_safely_replace_previous_attachment( $previous_thumb_id, $attach_id );
                    }
                }
            }

            // Extract and process mobile featured image if included
            if ($mobile_featured_image && !empty($mobile_featured_image['filename'])) {
                $mobile_filename = $mobile_featured_image['filename'];
                $mobile_content = $zip->getFromName('mobile_featured/' . $mobile_filename);

                if ($mobile_content !== false) {
                    // Avoid clobbering a file in page_assets_dir if it shares the
                    // name with the desktop featured image — prefix with "mobile-".
                    $mobile_destination_name = $mobile_filename;
                    if ($featured_image && isset($featured_image['filename']) && $featured_image['filename'] === $mobile_filename) {
                        $mobile_destination_name = 'mobile-' . $mobile_filename;
                    }
                    $mobile_path = $page_assets_dir . '/' . $mobile_destination_name;
                    file_put_contents($mobile_path, $mobile_content);

                    // Capture any prior mobile thumbnail attachment so we can
                    // purge it after the new one is wired up — same force-
                    // replace semantics as the desktop featured image above.
                    $previous_mobile_id = (int) get_post_meta( $post_id, '_firefly_mobile_thumbnail_id', true );

                    $mobile_attachment = array(
                        'post_mime_type' => $mobile_featured_image['mime_type'],
                        'post_title'     => ! empty($mobile_featured_image['title'])
                            ? $mobile_featured_image['title']
                            : pathinfo($mobile_filename, PATHINFO_FILENAME),
                        'post_content'   => '',
                        'post_status'    => 'inherit'
                    );

                    $mobile_attach_id = wp_insert_attachment($mobile_attachment, $mobile_path, $post_id);

                    if (! is_wp_error($mobile_attach_id)) {
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        $mobile_attach_data = wp_generate_attachment_metadata($mobile_attach_id, $mobile_path);
                        wp_update_attachment_metadata($mobile_attach_id, $mobile_attach_data);

                        if (! empty($mobile_featured_image['alt_text'])) {
                            update_post_meta($mobile_attach_id, '_wp_attachment_image_alt', $mobile_featured_image['alt_text']);
                        }

                        // Wire it up to the post's mobile thumbnail meta.
                        update_post_meta($post_id, '_firefly_mobile_thumbnail_id', (int) $mobile_attach_id);

                        // Purge the previous mobile attachment so old URLs
                        // (and CDN caches keyed on them) stop resolving.
                        // Path-aware because new + previous typically share
                        // the same featured-mobile.webp path.
                        firefly_projects_safely_replace_previous_attachment( $previous_mobile_id, $mobile_attach_id );
                    }
                }
            }

            // Extract and process per-page OG image override (_seo_og_image_id).
            // De-duplicates against the desktop / mobile featured attachments
            // when the user picked the same file for multiple slots — common
            // enough that creating a third attachment record would be noise.
            if ( $og_image && ! empty( $og_image['filename'] ) ) {
                $og_filename       = $og_image['filename'];
                $og_attach_id_use  = null;

                if ( isset( $attach_id ) && ! is_wp_error( $attach_id ) && $featured_image && isset( $featured_image['filename'] ) && $featured_image['filename'] === $og_filename ) {
                    $og_attach_id_use = (int) $attach_id;
                } elseif ( isset( $mobile_attach_id ) && ! is_wp_error( $mobile_attach_id ) && $mobile_featured_image && isset( $mobile_featured_image['filename'] ) && $mobile_featured_image['filename'] === $og_filename ) {
                    $og_attach_id_use = (int) $mobile_attach_id;
                } else {
                    $og_content = $zip->getFromName( 'og_image/' . $og_filename );
                    if ( $og_content !== false ) {
                        // Namespace the destination so it doesn't clobber
                        // featured / mobile featured files in the same dir.
                        $og_destination_name = $og_filename;
                        $collides_with_featured = $featured_image && isset( $featured_image['filename'] ) && $featured_image['filename'] === $og_filename;
                        $collides_with_mobile   = $mobile_featured_image && isset( $mobile_featured_image['filename'] ) && $mobile_featured_image['filename'] === $og_filename;
                        if ( $collides_with_featured || $collides_with_mobile ) {
                            $og_destination_name = 'og-' . $og_filename;
                        }
                        $og_path_dest = $page_assets_dir . '/' . $og_destination_name;
                        file_put_contents( $og_path_dest, $og_content );

                        $previous_og_id = (int) get_post_meta( $post_id, '_seo_og_image_id', true );

                        $og_attachment = array(
                            'post_mime_type' => $og_image['mime_type'],
                            'post_title'     => ! empty( $og_image['title'] )
                                ? $og_image['title']
                                : pathinfo( $og_filename, PATHINFO_FILENAME ),
                            'post_content'   => '',
                            'post_status'    => 'inherit',
                        );
                        $og_new_id = wp_insert_attachment( $og_attachment, $og_path_dest, $post_id );
                        if ( ! is_wp_error( $og_new_id ) ) {
                            require_once ABSPATH . 'wp-admin/includes/image.php';
                            $og_meta = wp_generate_attachment_metadata( $og_new_id, $og_path_dest );
                            wp_update_attachment_metadata( $og_new_id, $og_meta );

                            if ( ! empty( $og_image['alt_text'] ) ) {
                                update_post_meta( $og_new_id, '_wp_attachment_image_alt', $og_image['alt_text'] );
                            }
                            $og_attach_id_use = (int) $og_new_id;
                            firefly_projects_safely_replace_previous_attachment( $previous_og_id, $og_new_id );
                        }
                    }
                }

                if ( $og_attach_id_use ) {
                    update_post_meta( $post_id, '_seo_og_image_id', $og_attach_id_use );
                }
            }

            $zip->close();
        }
    }

    // Apply "clear" signals for mobile featured + OG image overrides. These
    // run unconditionally (outside the zip / asset block) so removing an
    // image on the source propagates to the remote even when the sync
    // wouldn't otherwise carry any media. We refuse to delete an attachment
    // that's still serving another role for the same post (e.g. clearing OG
    // when OG was using the post's featured image attachment).
    if ( $mobile_featured_image_clear && empty( $mobile_featured_image ) ) {
        $previous_mobile_id = (int) get_post_meta( $post_id, '_firefly_mobile_thumbnail_id', true );
        delete_post_meta( $post_id, '_firefly_mobile_thumbnail_id' );
        if ( $previous_mobile_id ) {
            $cur_thumb_id = (int) get_post_thumbnail_id( $post_id );
            $cur_og_id    = (int) get_post_meta( $post_id, '_seo_og_image_id', true );
            if ( $previous_mobile_id !== $cur_thumb_id && $previous_mobile_id !== $cur_og_id ) {
                wp_delete_attachment( $previous_mobile_id, true );
            }
        }
    }
    if ( $og_image_clear && empty( $og_image ) ) {
        $previous_og_id = (int) get_post_meta( $post_id, '_seo_og_image_id', true );
        delete_post_meta( $post_id, '_seo_og_image_id' );
        if ( $previous_og_id ) {
            $cur_thumb_id  = (int) get_post_thumbnail_id( $post_id );
            $cur_mobile_id = (int) get_post_meta( $post_id, '_firefly_mobile_thumbnail_id', true );
            if ( $previous_og_id !== $cur_thumb_id && $previous_og_id !== $cur_mobile_id ) {
                wp_delete_attachment( $previous_og_id, true );
            }
        }
    }

    // Set page role if specified (front page or posts page)
    if ($page_role && $post_data['post_type'] === 'page') {
        // Ensure WordPress is set to use a static front page
        update_option('show_on_front', 'page');

        if ($page_role === 'front_page') {
            update_option('page_on_front', $post_id);
        } elseif ($page_role === 'posts_page') {
            update_option('page_for_posts', $post_id);
        }
    }

    $env_label = ($target_env === 'prod') ? 'Production' : 'Live Dev';
    $type_label = ($post_data['post_type'] === 'post') ? 'Post' : 'Page';

    return array(
        'success' => true,
        'message' => ($existing_post ? $type_label . ' updated' : $type_label . ' created') . ' successfully on ' . $env_label . '.',
        'page_role' => $page_role,
        'associated_files_written'  => $associated_report['files_written'],
        'associated_files_warnings' => $associated_report['warnings'],
    );
}
