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
    $allowed_underscore_keys = array('_thumbnail_id', '_geo_summary', '_geo_key_facts', '_geo_article_type', '_geo_faq');
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
    $content_to_sync = $post->post_content;
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
    $allowed_underscore_keys = array('_thumbnail_id', '_geo_summary', '_geo_key_facts', '_geo_article_type', '_geo_faq');
    foreach ($meta as $key => $values) {
        if (strpos($key, '_') === 0 && !in_array($key, $allowed_underscore_keys)) {
            continue;
        }
        $meta_data[$key] = $values[0];
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

    // Create temporary zip file with assets or featured image
    $zip_path = null;
    if (!empty($assets_to_sync) || $featured_image_path) {
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

        // Add manifest with relative paths for proper extraction
        $manifest = array(
            'post_data'      => $post_data,
            'meta_data'      => $meta_data,
            'target_env'     => $target_env,
            'asset_map'      => $asset_map,
            'featured_image' => $featured_image,
            'page_role'      => $page_role,
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

    // Prepare request body
    $body = array(
        'post_data'      => $post_data,
        'meta_data'      => $meta_data,
        'target_env'     => $target_env,
        'asset_map'      => $asset_map,
        'featured_image' => $featured_image,
        'has_assets'     => !empty($assets_to_sync) || $featured_image_path,
        'page_role'      => $page_role
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
        $response = wp_remote_retrieve_body($response);
    }

    // Parse response
    $result = json_decode($response, true);

    if ($http_code === 200 && isset($result['success']) && $result['success']) {
        // Save last sync timestamp per environment
        $sync_time = time();
        if ($target_env === 'prod') {
            update_post_meta($post->ID, '_firefly_last_sync_prod', $sync_time);
        } else {
            update_post_meta($post->ID, '_firefly_last_sync_dev', $sync_time);
        }

        return array(
            'success' => true,
            'message' => 'Page synced successfully to ' . $env_label . '.',
            'details' => array(
                'page_title'   => $post->post_title,
                'page_slug'    => $post->post_name,
                'files_synced' => count($assets_to_sync),
                'synced_at'    => time(),
                'target_env'   => $target_env
            )
        );
    } else {
        $error_message = isset($result['message']) ? $result['message'] : 'Unknown error occurred.';
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
        $page_role = isset($manifest['page_role']) ? $manifest['page_role'] : null;

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
        $page_role = isset($body['page_role']) ? $body['page_role'] : null;
        $zip_file = null;
    }

    // Find existing post by slug and type
    $existing_post = get_page_by_path($post_data['post_name'], OBJECT, $post_data['post_type']);

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

    if ($existing_post) {
        // Update existing post
        $wp_post_data['ID'] = $existing_post->ID;
        $post_id = wp_update_post($wp_post_data, true);
    } else {
        // Create new post
        $post_id = wp_insert_post($wp_post_data, true);
    }

    if (is_wp_error($post_id)) {
        return array(
            'success' => false,
            'message' => 'Failed to save post: ' . $post_id->get_error_message()
        );
    }

    // Update meta data
    foreach ($meta_data as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }

    // Save asset map if provided
    if (!empty($asset_map)) {
        update_post_meta($post_id, '_firefly_asset_map', $asset_map);
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

            // Extract and process featured image if included
            if ($featured_image && !empty($featured_image['filename'])) {
                $featured_filename = $featured_image['filename'];
                $featured_content = $zip->getFromName('featured/' . $featured_filename);

                if ($featured_content !== false) {
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
                    }
                }
            }

            $zip->close();
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
        'page_role' => $page_role
    );
}
