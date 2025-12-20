<?php
/**
 * Firefly Projects - Asset Mapping System
 *
 * Handles asset URL detection, copying to dev folders, URL rewriting,
 * and mapping storage for bidirectional sync between environments.
 *
 * Asset Map Structure (stored in post meta '_firefly_asset_map'):
 * {
 *   "asset_origin": "production" | "dev" | "mixed",
 *   "mappings": {
 *     "original_url": "dev_url",
 *     ...
 *   },
 *   "dev_created": ["url1", "url2"]  // Assets that originated in dev
 * }
 */

// Ensure no direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detect ALL asset URLs in content (images, files, backgrounds)
 * Handles: src, href, url(), data-src, srcset, poster attributes
 *
 * @param string $content The post content
 * @return array List of unique asset URLs found
 */
function firefly_projects_detect_all_assets($content) {
    $assets = array();

    // Common media file extensions
    $media_extensions = 'jpg|jpeg|png|gif|webp|svg|ico|bmp|tiff|pdf|doc|docx|xls|xlsx|ppt|pptx|zip|mp4|webm|ogg|mp3|wav';

    // Patterns to match asset URLs
    $patterns = array(
        // src attribute (images, videos, audio, iframes)
        '/\bsrc=["\']([^"\']+\.(?:' . $media_extensions . '))["\']/i',
        // href to media files
        '/\bhref=["\']([^"\']+\.(?:' . $media_extensions . '))["\']/i',
        // CSS background-image url()
        '/url\(["\']?([^"\')]+\.(?:' . $media_extensions . '))["\']?\)/i',
        // data-src (lazy loading)
        '/\bdata-src=["\']([^"\']+\.(?:' . $media_extensions . '))["\']/i',
        // srcset attribute (responsive images)
        '/\bsrcset=["\']([^"\']+)["\']/i',
        // poster attribute (video)
        '/\bposter=["\']([^"\']+\.(?:' . $media_extensions . '))["\']/i',
        // WordPress image class with URL in content
        '/\bdata-orig-file=["\']([^"\']+\.(?:' . $media_extensions . '))["\']/i',
    );

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $url) {
                // Handle srcset (comma-separated URLs with sizes)
                if (strpos($pattern, 'srcset') !== false) {
                    $srcset_urls = firefly_projects_parse_srcset($url);
                    foreach ($srcset_urls as $srcset_url) {
                        $normalized = firefly_projects_normalize_asset_url($srcset_url);
                        if ($normalized && !in_array($normalized, $assets)) {
                            $assets[] = $normalized;
                        }
                    }
                } else {
                    $normalized = firefly_projects_normalize_asset_url($url);
                    if ($normalized && !in_array($normalized, $assets)) {
                        $assets[] = $normalized;
                    }
                }
            }
        }
    }

    return $assets;
}

/**
 * Parse srcset attribute into individual URLs
 *
 * @param string $srcset The srcset attribute value
 * @return array List of URLs
 */
function firefly_projects_parse_srcset($srcset) {
    $urls = array();
    $parts = explode(',', $srcset);

    foreach ($parts as $part) {
        $part = trim($part);
        // srcset format: "url size" or just "url"
        $space_pos = strpos($part, ' ');
        if ($space_pos !== false) {
            $url = substr($part, 0, $space_pos);
        } else {
            $url = $part;
        }
        if (!empty($url)) {
            $urls[] = trim($url);
        }
    }

    return $urls;
}

/**
 * Normalize asset URL (handle relative, absolute, CDN URLs)
 *
 * @param string $url The asset URL
 * @return string|false Normalized URL or false if invalid
 */
function firefly_projects_normalize_asset_url($url) {
    $url = trim($url);

    // Skip empty, data URIs, or anchors
    if (empty($url) || strpos($url, 'data:') === 0 || strpos($url, '#') === 0) {
        return false;
    }

    // Skip page-specific asset URLs when detecting for initial mapping
    // (we want original URLs only)
    if (strpos($url, '/uploads/pages/') !== false) {
        return false;
    }

    // Handle protocol-relative URLs
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }

    // Handle relative URLs starting with /
    if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
        // Keep as relative path
        return $url;
    }

    // Handle full URLs
    if (strpos($url, 'http') === 0) {
        return $url;
    }

    // Relative path without leading slash - skip these as they're ambiguous
    return false;
}

/**
 * Get the local path for a page asset
 *
 * @param string $original_url The original asset URL
 * @param string $page_slug The page slug for organization
 * @return string The local asset path
 */
function firefly_projects_get_page_asset_path($original_url, $page_slug) {
    $filename = basename(parse_url($original_url, PHP_URL_PATH));
    // Sanitize filename
    $filename = sanitize_file_name($filename);
    return '/wp-content/uploads/pages/' . $page_slug . '/' . $filename;
}

/**
 * Get the filesystem path for a page asset
 *
 * @param string $page_slug The page slug
 * @param string $filename The asset filename
 * @return string The filesystem path
 */
function firefly_projects_get_page_asset_filesystem_path($page_slug, $filename = '') {
    $upload_dir = wp_upload_dir();
    $base_path = $upload_dir['basedir'] . '/pages/' . $page_slug;

    if ($filename) {
        return $base_path . '/' . sanitize_file_name($filename);
    }
    return $base_path;
}

/**
 * Copy/download an asset to the page assets folder
 *
 * @param string $url The original asset URL
 * @param string $page_slug The page slug
 * @return array|false Array with 'local_path' and 'local_url' or false on failure
 */
function firefly_projects_copy_asset_to_page($url, $page_slug) {
    $filename = basename(parse_url($url, PHP_URL_PATH));
    $filename = sanitize_file_name($filename);

    // Create page assets directory if needed
    $assets_dir = firefly_projects_get_page_asset_filesystem_path($page_slug);
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
    }

    $local_filesystem_path = $assets_dir . '/' . $filename;
    $local_url = '/wp-content/uploads/pages/' . $page_slug . '/' . $filename;

    // Skip if already exists
    if (file_exists($local_filesystem_path)) {
        return array(
            'local_path' => $local_filesystem_path,
            'local_url'  => $local_url
        );
    }

    // Determine source path
    $source_path = firefly_projects_resolve_asset_source($url);

    if ($source_path && file_exists($source_path)) {
        // Local file - copy it
        if (copy($source_path, $local_filesystem_path)) {
            return array(
                'local_path' => $local_filesystem_path,
                'local_url'  => $local_url
            );
        }
    } elseif (strpos($url, 'http') === 0) {
        // Remote URL - download it
        $response = wp_remote_get($url, array('timeout' => 30));
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $content = wp_remote_retrieve_body($response);
            if (file_put_contents($local_filesystem_path, $content)) {
                return array(
                    'local_path' => $local_filesystem_path,
                    'local_url'  => $local_url
                );
            }
        }
    }

    return false;
}

/**
 * Resolve asset URL to local filesystem path
 *
 * @param string $url The asset URL
 * @return string|false Local path or false if not local
 */
function firefly_projects_resolve_asset_source($url) {
    // Handle relative URLs starting with /wp-content/
    if (strpos($url, '/wp-content/') === 0) {
        $path = ABSPATH . ltrim($url, '/');
        if (file_exists($path)) {
            return $path;
        }
    }

    // Handle full URLs pointing to current site
    $site_url = site_url();
    if (strpos($url, $site_url) === 0) {
        $relative = str_replace($site_url, '', $url);
        $path = ABSPATH . ltrim($relative, '/');
        if (file_exists($path)) {
            return $path;
        }
    }

    // Handle URLs with uploads directory
    $upload_dir = wp_upload_dir();
    if (strpos($url, $upload_dir['baseurl']) === 0) {
        $relative = str_replace($upload_dir['baseurl'], '', $url);
        $path = $upload_dir['basedir'] . $relative;
        if (file_exists($path)) {
            return $path;
        }
    }

    return false;
}

/**
 * Rewrite content URLs using mapping
 *
 * @param string $content The post content
 * @param array $mappings The URL mappings (original => dev or vice versa)
 * @param string $direction 'to_dev' or 'to_prod'
 * @return string Content with rewritten URLs
 */
function firefly_projects_rewrite_content_urls($content, $mappings, $direction = 'to_dev') {
    if (empty($mappings)) {
        return $content;
    }

    foreach ($mappings as $original => $dev) {
        if ($direction === 'to_dev') {
            // Original -> Dev
            $content = str_replace($original, $dev, $content);
        } else {
            // Dev -> Original (to_prod)
            $content = str_replace($dev, $original, $content);
        }
    }

    return $content;
}

/**
 * Get asset map for a post
 *
 * @param int $post_id The post ID
 * @return array The asset map
 */
function firefly_projects_get_asset_map($post_id) {
    $raw = get_post_meta($post_id, '_firefly_asset_map', true);

    // Handle JSON string storage
    if (is_string($raw) && !empty($raw)) {
        $map = json_decode($raw, true);
        if (is_array($map)) {
            return array_merge(array(
                'asset_origin' => null,
                'mappings'     => array(),
                'dev_created'  => array()
            ), $map);
        }
    }

    // Handle legacy array storage (backwards compatibility)
    if (is_array($raw)) {
        return array_merge(array(
            'asset_origin' => null,
            'mappings'     => array(),
            'dev_created'  => array()
        ), $raw);
    }

    // Return empty map
    return array(
        'asset_origin' => null,
        'mappings'     => array(),
        'dev_created'  => array()
    );
}

/**
 * Save asset map for a post
 *
 * @param int $post_id The post ID
 * @param array $map The asset map
 * @return bool Success
 */
function firefly_projects_save_asset_map($post_id, $map) {
    // Store as JSON string to avoid REST API schema validation issues
    $json = json_encode($map);
    return update_post_meta($post_id, '_firefly_asset_map', $json);
}

/**
 * Process a page for local environment - detect assets, copy, rewrite
 *
 * @param int $post_id The post ID
 * @param string $origin Where the content came from: 'production', 'dev', or 'local'
 * @return array Result with success status and updated content
 */
function firefly_projects_process_page_assets($post_id, $origin = 'production') {
    $post = get_post($post_id);
    if (!$post) {
        return array('success' => false, 'message' => 'Post not found');
    }

    $content = $post->post_content;
    $page_slug = $post->post_name;

    // Detect all assets in content
    $assets = firefly_projects_detect_all_assets($content);

    if (empty($assets)) {
        return array(
            'success' => true,
            'message' => 'No assets found to process',
            'assets_processed' => 0
        );
    }

    // Get existing map
    $map = firefly_projects_get_asset_map($post_id);
    $new_mappings = array();
    $processed = 0;
    $failed = 0;

    foreach ($assets as $asset_url) {
        // Skip if already mapped
        if (isset($map['mappings'][$asset_url])) {
            continue;
        }

        // Copy to local page assets folder
        $result = firefly_projects_copy_asset_to_page($asset_url, $page_slug);

        if ($result) {
            $new_mappings[$asset_url] = $result['local_url'];
            $processed++;
        } else {
            $failed++;
        }
    }

    // Merge new mappings
    $map['mappings'] = array_merge($map['mappings'], $new_mappings);
    $map['asset_origin'] = $origin;

    // Rewrite content
    $new_content = firefly_projects_rewrite_content_urls($content, $map['mappings'], 'to_dev');

    // Update post if content changed
    if ($new_content !== $content) {
        wp_update_post(array(
            'ID'           => $post_id,
            'post_content' => $new_content
        ));
    }

    // Save map
    firefly_projects_save_asset_map($post_id, $map);

    return array(
        'success'          => true,
        'message'          => "Processed {$processed} assets" . ($failed > 0 ? ", {$failed} failed" : ''),
        'assets_processed' => $processed,
        'assets_failed'    => $failed,
        'mappings'         => $new_mappings
    );
}

/**
 * Prepare content for production sync - restore original URLs
 *
 * @param int $post_id The post ID
 * @return array Result with production-ready content
 */
function firefly_projects_prepare_content_for_production($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return array('success' => false, 'message' => 'Post not found');
    }

    $map = firefly_projects_get_asset_map($post_id);
    $content = $post->post_content;

    // If origin is production, we can restore original URLs
    if ($map['asset_origin'] === 'production' && !empty($map['mappings'])) {
        $content = firefly_projects_rewrite_content_urls($content, $map['mappings'], 'to_prod');

        return array(
            'success'       => true,
            'content'       => $content,
            'assets_to_sync' => array(), // Don't need to sync - originals exist
            'restored_urls' => count($map['mappings'])
        );
    }

    // If origin is local, we need to create remote paths and sync assets
    if ($map['asset_origin'] === 'local' || !empty($map['local_created'])) {
        $remote_mappings = array();
        $assets_to_sync = array();

        // For locally-created assets, create remote paths
        $local_created = isset($map['local_created']) ? $map['local_created'] : (isset($map['dev_created']) ? $map['dev_created'] : array());
        foreach ($local_created as $local_url) {
            $filename = basename($local_url);
            $remote_url = '/wp-content/uploads/pages/' . $post->post_name . '/' . $filename;
            $remote_mappings[$local_url] = $remote_url;

            // Get the local file path for syncing
            $local_path = firefly_projects_get_page_asset_filesystem_path($post->post_name, $filename);
            if (file_exists($local_path)) {
                $assets_to_sync[] = array(
                    'local_url'  => $local_url,
                    'remote_url' => $remote_url,
                    'local_path' => $local_path,
                    'filename'   => $filename
                );
            }
        }

        // Rewrite local URLs to remote URLs
        $content = firefly_projects_rewrite_content_urls($content, $remote_mappings, 'to_dev');

        return array(
            'success'        => true,
            'content'        => $content,
            'assets_to_sync' => $assets_to_sync,
            'new_remote_urls' => count($remote_mappings)
        );
    }

    // No mapping - return content as-is
    return array(
        'success'        => true,
        'content'        => $content,
        'assets_to_sync' => array()
    );
}

/**
 * Register a locally-created asset (for new uploads)
 *
 * @param int $post_id The post ID
 * @param string $local_url The local URL of the new asset
 * @return bool Success
 */
function firefly_projects_register_local_asset($post_id, $local_url) {
    $map = firefly_projects_get_asset_map($post_id);

    $local_created = isset($map['local_created']) ? $map['local_created'] : array();
    if (!in_array($local_url, $local_created)) {
        $local_created[] = $local_url;
        $map['local_created'] = $local_created;

        if (empty($map['asset_origin'])) {
            $map['asset_origin'] = 'local';
        } elseif ($map['asset_origin'] === 'production') {
            $map['asset_origin'] = 'mixed';
        }

        return firefly_projects_save_asset_map($post_id, $map);
    }

    return true;
}

/**
 * Get all page assets for syncing
 *
 * @param int $post_id The post ID
 * @return array List of page assets with paths
 */
function firefly_projects_get_page_assets_for_sync($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return array();
    }

    $page_slug = $post->post_name;
    $assets_dir = firefly_projects_get_page_asset_filesystem_path($page_slug);

    if (!is_dir($assets_dir)) {
        return array();
    }

    $assets = array();
    $files = scandir($assets_dir);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $file_path = $assets_dir . '/' . $file;
        if (is_file($file_path)) {
            $assets[] = array(
                'filename'  => $file,
                'path'      => $file_path,
                'url'       => '/wp-content/uploads/pages/' . $page_slug . '/' . $file,
                'size'      => filesize($file_path)
            );
        }
    }

    return $assets;
}
