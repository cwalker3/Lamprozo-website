<?php
/**
 * Firefly Projects - Template Schema Sync Handler
 *
 * Handles syncing template schema files (JSON + snippets) to remote sites.
 * Only activates when the Firefly Collective theme with schema support is present.
 */

// Ensure no direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if template schema system is available.
 * Returns true if the Firefly theme with schema support is active.
 */
function firefly_projects_is_template_schema_available() {
    return function_exists('firefly_get_template_schema');
}

/**
 * Get list of available templates from schema files.
 */
function firefly_projects_get_available_templates() {
    if (!firefly_projects_is_template_schema_available()) {
        return array();
    }

    $schemas_dir = get_template_directory() . '/data/schemas';
    if (!is_dir($schemas_dir)) {
        return array();
    }

    $templates = array();
    $files = glob($schemas_dir . '/*-schema.json');

    foreach ($files as $file) {
        $basename = basename($file);
        // Extract template name from {template}-schema.json
        if (preg_match('/^(.+)-schema\.json$/', $basename, $matches)) {
            $template = $matches[1];
            $schema = json_decode(file_get_contents($file), true);
            $templates[] = array(
                'name'        => $template,
                'display_name' => isset($schema['template']) ? $schema['template'] : $template,
                'version'     => isset($schema['version']) ? $schema['version'] : '1.0.0',
                'description' => isset($schema['description']) ? $schema['description'] : '',
                'pages_count' => isset($schema['pages']) ? count($schema['pages']) : 0,
                'posts_count' => isset($schema['posts']) ? count($schema['posts']) : 0
            );
        }
    }

    return $templates;
}

/**
 * Package template schema and snippets for sync.
 *
 * @param string $template The template name (e.g., 'firefly', 'default', 'glow')
 * @return array|WP_Error Package data or error
 */
function firefly_projects_package_template_schema($template) {
    if (!firefly_projects_is_template_schema_available()) {
        return new WP_Error('not_available', 'Template schema system not available on this site.');
    }

    $theme_dir = get_template_directory();
    $schema_path = $theme_dir . '/data/schemas/' . $template . '-schema.json';

    if (!file_exists($schema_path)) {
        return new WP_Error('not_found', 'Schema file not found for template: ' . $template);
    }

    // Read schema file
    $schema_content = file_get_contents($schema_path);
    $schema = json_decode($schema_content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('invalid_json', 'Invalid JSON in schema file.');
    }

    // Collect snippet files
    $snippets = array();
    $snippets_base = $theme_dir . '/templates/' . $template . '/snippets';

    // Page snippets
    $pages_dir = $snippets_base . '/pages';
    if (is_dir($pages_dir)) {
        $page_files = glob($pages_dir . '/*.html');
        foreach ($page_files as $file) {
            $snippets['pages/' . basename($file)] = file_get_contents($file);
        }
    }

    // Post snippets
    $posts_dir = $snippets_base . '/posts';
    if (is_dir($posts_dir)) {
        $post_files = glob($posts_dir . '/*.html');
        foreach ($post_files as $file) {
            $snippets['posts/' . basename($file)] = file_get_contents($file);
        }
    }

    return array(
        'template'       => $template,
        'schema'         => $schema,
        'schema_content' => $schema_content,
        'snippets'       => $snippets,
        'snippets_count' => count($snippets)
    );
}

/**
 * Perform template schema sync to remote site.
 *
 * @param string $template The template name
 * @param string $target_env Target environment: 'dev' or 'prod'
 * @return array Result array with success status and message
 */
function firefly_projects_perform_template_schema_sync($template, $target_env = 'dev') {
    // Package the schema
    $package = firefly_projects_package_template_schema($template);

    if (is_wp_error($package)) {
        return array(
            'success' => false,
            'message' => $package->get_error_message()
        );
    }

    // Get remote endpoint
    $project_endpoint = ($target_env === 'prod') ? PROD_ENDPOINT : LIVE_DEV_ENDPOINT;

    if (preg_match('/(https?:\/\/[^\/]+)/', $project_endpoint, $matches)) {
        $base_url = $matches[1];
        $schema_endpoint = $base_url . '/wp-json/firefly-plugin/v1/receive-template-schema';
    } else {
        return array(
            'success' => false,
            'message' => 'Could not determine remote URL.'
        );
    }

    $env_label = ($target_env === 'prod') ? 'Production' : 'Live Dev';

    // Create ZIP with schema and snippets
    $upload_dir = wp_upload_dir();
    $temp_dir = trailingslashit($upload_dir['basedir']) . 'firefly_collective_temp';

    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
    }

    $zip_path = $temp_dir . '/schema_sync_' . $template . '_' . time() . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
        return array(
            'success' => false,
            'message' => 'Failed to create schema package.'
        );
    }

    // Add schema file
    $zip->addFromString('schema.json', $package['schema_content']);

    // Add snippet files
    foreach ($package['snippets'] as $relative_path => $content) {
        $zip->addFromString('snippets/' . $relative_path, $content);
    }

    // Add manifest
    $manifest = array(
        'template'       => $template,
        'version'        => isset($package['schema']['version']) ? $package['schema']['version'] : '1.0.0',
        'snippets_count' => $package['snippets_count'],
        'synced_at'      => time()
    );
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

    $zip->close();

    // Send request with cURL
    $ch = curl_init();

    $post_fields = array(
        'template' => $template,
        'manifest' => json_encode($manifest),
        'schema'   => new CURLFile($zip_path, 'application/zip', 'schema.zip')
    );

    curl_setopt_array($ch, array(
        CURLOPT_URL            => $schema_endpoint,
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

    // Parse response
    $result = json_decode($response, true);

    if ($http_code === 200 && isset($result['success']) && $result['success']) {
        return array(
            'success' => true,
            'message' => 'Template schema synced successfully to ' . $env_label . '.',
            'details' => array(
                'template'       => $template,
                'snippets_count' => $package['snippets_count'],
                'synced_at'      => time(),
                'target_env'     => $target_env
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
 * Handle incoming template schema sync from local site (remote endpoint).
 *
 * @param WP_REST_Request $request The REST request
 * @return array Result array with success status and message
 */
function firefly_projects_handle_incoming_template_schema($request) {
    // Check if template schema system is available on this site
    if (!firefly_projects_is_template_schema_available()) {
        return array(
            'success' => false,
            'message' => 'Template schema system not available on this site. Firefly theme may not be active.'
        );
    }

    $template = $request->get_param('template');
    $manifest = json_decode($request->get_param('manifest'), true);

    // Handle uploaded zip file
    $files = $request->get_file_params();
    $zip_file = isset($files['schema']) ? $files['schema']['tmp_name'] : null;

    if (!$zip_file || !file_exists($zip_file)) {
        return array(
            'success' => false,
            'message' => 'Schema package not received.'
        );
    }

    $theme_dir = get_template_directory();
    $schemas_dir = $theme_dir . '/data/schemas';
    $snippets_base = $theme_dir . '/templates/' . $template . '/snippets';

    // Ensure directories exist
    if (!file_exists($schemas_dir)) {
        wp_mkdir_p($schemas_dir);
    }
    if (!file_exists($snippets_base . '/pages')) {
        wp_mkdir_p($snippets_base . '/pages');
    }
    if (!file_exists($snippets_base . '/posts')) {
        wp_mkdir_p($snippets_base . '/posts');
    }

    // Extract ZIP
    $zip = new ZipArchive();
    if ($zip->open($zip_file) !== true) {
        return array(
            'success' => false,
            'message' => 'Failed to open schema package.'
        );
    }

    $files_extracted = 0;

    // Extract schema.json
    $schema_content = $zip->getFromName('schema.json');
    if ($schema_content !== false) {
        $schema_path = $schemas_dir . '/' . $template . '-schema.json';
        file_put_contents($schema_path, $schema_content);
        $files_extracted++;
    }

    // Extract snippet files
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);

        if (strpos($filename, 'snippets/pages/') === 0 && $filename !== 'snippets/pages/') {
            $content = $zip->getFromIndex($i);
            $dest = $snippets_base . '/pages/' . basename($filename);
            file_put_contents($dest, $content);
            $files_extracted++;
        } elseif (strpos($filename, 'snippets/posts/') === 0 && $filename !== 'snippets/posts/') {
            $content = $zip->getFromIndex($i);
            $dest = $snippets_base . '/posts/' . basename($filename);
            file_put_contents($dest, $content);
            $files_extracted++;
        }
    }

    $zip->close();

    return array(
        'success' => true,
        'message' => 'Template schema received and saved.',
        'details' => array(
            'template'        => $template,
            'files_extracted' => $files_extracted
        )
    );
}

/**
 * List template schemas available on this site (remote endpoint).
 */
function firefly_projects_list_template_schemas() {
    if (!firefly_projects_is_template_schema_available()) {
        return array(
            'success' => false,
            'message' => 'Template schema system not available.',
            'templates' => array()
        );
    }

    $templates = firefly_projects_get_available_templates();

    return array(
        'success'   => true,
        'templates' => $templates,
        'count'     => count($templates)
    );
}
