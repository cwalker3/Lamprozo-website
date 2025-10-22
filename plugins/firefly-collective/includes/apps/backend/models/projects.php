<?php
    // plugin/models/projects.php

    function firefly_collective_add_projects_link() {
        add_menu_page(
            'Projects',
            'Projects',
            'manage_options',
            'projects',
            'firefly_collective_projects_dashboard'
        );
    }
    add_action('admin_enqueue_scripts', 'enqueue_projects_styles_and_scripts');

    if (defined('FIREFLY_DEV')) {
        add_action('admin_menu', 'firefly_collective_add_projects_link');
    }

    /**
     * Enqueue styles and scripts for the Projects admin page.
     */
    function enqueue_projects_styles_and_scripts($hook) {
        if ($hook !== 'toplevel_page_projects') {
            return;
        }

        $plugin_root = plugin_dir_url(dirname(__FILE__)) . '/';
        $unique_id   = uniqid();

        wp_enqueue_style('projects-css', $plugin_root . 'assets/css/projects.css', array(), $unique_id);
        wp_enqueue_script('projects-js', $plugin_root . 'assets/js/projects.js', array(), $unique_id, true);

        $nonce = wp_create_nonce('wp_rest');
        $is_admin = current_user_can('manage_options') ? 'true' : 'false';

        // Define the API URL for the local environment's REST API
        $api_url = get_rest_url(null, 'custom-api/v1/');

        wp_localize_script('projects-js', 'projectData', array(
            'isAdmin'   => $is_admin,
            'nonce'     => $nonce,
            'adminUrl'  => admin_url('admin.php?page=projects'),
            'apiUrl'    => $api_url,
            'pluginUrl' => plugin_dir_url(__FILE__)
        ));
    }

    /**
     * Display the projects dashboard page (local site).
     */
    function firefly_collective_projects_dashboard() {
        $plugin_root = dirname(plugin_dir_path(__FILE__));
        $view_path   = $plugin_root . '/views/projects.php';

        // Load projects data from projects.json on local site
        $projects_json_path = $plugin_root . '/projects.json';
        $projects_data      = array();

        if (file_exists($projects_json_path)) {
            $content = file_get_contents($projects_json_path);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $projects_data = $decoded;
            }
        }

        if (file_exists($view_path)) {
            require_once $view_path;
        } else {
            wp_die('The projects view file could not be found.', 'File Not Found', array('response' => 404));
        }
    }

    /**
     * Handle the incoming REST request to update project files on the live dev environment.
     * This no longer relies on projects.json at the live dev site. Instead, it unzips the
     * payload, overwrites matching files, and removes files on the live site that don't
     * appear in the unzipped structure.
     */
    function firefly_collective_handle_project_update(WP_REST_Request $request) {
        // Check the shared secret
        $received_secret = $request->get_header('X-Firefly-Secret');
        if ($received_secret !== FIREFLY_SHARED_SECRET) {
            return new WP_Error('invalid_secret', 'Invalid shared secret.', array('status' => 403));
        }

        $project_name = sanitize_text_field($request->get_param('project_name'));

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_failed', 'Failed to upload file.', array('status' => 400));
        }

        $uploaded_file = $_FILES['file'];
        $upload_dir    = wp_upload_dir();
        $temp_zip_path = trailingslashit($upload_dir['basedir']) . basename($uploaded_file['name']);

        if (!move_uploaded_file($uploaded_file['tmp_name'], $temp_zip_path)) {
            return new WP_Error('file_move_error', 'Could not move uploaded file.', array('status' => 500));
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        WP_Filesystem();
        global $wp_filesystem;

        // Define a safe update directory in wp-content/uploads instead of the plugin folder
        $update_dir = trailingslashit($upload_dir['basedir']) . 'firefly_collective_updates/';
        if (!file_exists($update_dir)) {
            wp_mkdir_p($update_dir);
        }

        // Unzip the uploaded file into the safe update directory
        $result = unzip_file($temp_zip_path, $update_dir);

        // Delete the temporary ZIP file
        $wp_filesystem->delete($temp_zip_path);

        if (is_wp_error($result)) {
            return new WP_Error('unzip_failed', 'Failed to unzip the file: ' . $result->get_error_message(), array('status' => 500));
        }

        // Synchronize the unzipped file structure with the live site
        firefly_collective_sync_unzipped($update_dir);

        // Delete the update directory and all its contents after successful sync
        // WP_Filesystem delete method accepts a second parameter for recursive deletion.
        $wp_filesystem->delete($update_dir, true);

        return new WP_REST_Response(array(
            'message' => 'Project updated successfully.',
            'project' => $project_name
        ), 200);
    }

    /**
     * Synchronize the unzipped file structure in $update_dir with the live site by:
     * 1) Overwriting/copying all unzipped files to their final destinations.
     * 2) Deleting files on the live site that are not present in the unzipped content.
     */
    function firefly_collective_sync_unzipped($update_dir) {
        // 1. Overwrite all unzipped files, gathering their relative paths in an array.
        $unzipped_paths = array();
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($update_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isFile()) continue;

            $file_path     = $file->getRealPath();
            $relative_path = str_replace($update_dir, '', $file_path);
            $relative_path = ltrim($relative_path, '/');

            // Determine destination
            if (strpos($relative_path, 'plugins/') === 0 || strpos($relative_path, 'themes/') === 0) {
                // under wp-content/plugins or wp-content/themes
                $destination = ABSPATH . 'wp-content/' . $relative_path;
            } else {
                // ANY other file (e.g. .htaccess) → root of wp-content
                $destination = WP_CONTENT_DIR . '/' . $relative_path;
            }

            // Ensure the destination directory exists
            $destination_dir = dirname($destination);
            if (! file_exists($destination_dir)) {
                wp_mkdir_p($destination_dir);
            }

            // Copy/overwrite
            copy($file_path, $destination);

            // Track for cleanup
            $unzipped_paths[] = $relative_path;
        }

        // 2. Remove extraneous plugin/theme files
        $top_level_dirs = firefly_collective_extract_top_level_dirs($unzipped_paths);
        foreach ($top_level_dirs as $dir) {
            $live_dir = ABSPATH . 'wp-content/' . $dir;
            if (! is_dir($live_dir)) continue;
            firefly_collective_remove_extras($live_dir, $dir, $unzipped_paths);
        }
    }

    /**
     * Convert a relative path inside the unzipped folder (e.g. "plugins/firefly-collective/foo.php")
     * to a final destination in ABSPATH (e.g. ABSPATH . "wp-content/plugins/firefly-collective/foo.php").
     */
    function firefly_collective_map_unzipped_path($relative_path) {
        if (strpos($relative_path, 'plugins/') === 0) {
            return ABSPATH . 'wp-content/' . $relative_path; // => /wp-content/plugins/...
        } elseif (strpos($relative_path, 'themes/') === 0) {
            return ABSPATH . 'wp-content/' . $relative_path; // => /wp-content/themes/...
        }
        // Not recognized
        return null;
    }

    /**
     * Extract top-level directories (like "plugins/firefly-collective") from the unzipped paths.
     * If we have "plugins/firefly-collective/foo.php", the top-level is "plugins/firefly-collective".
     */
    function firefly_collective_extract_top_level_dirs($unzipped_paths) {
        $dirs = array();
        foreach ($unzipped_paths as $path) {
            $parts = explode('/', $path);
            if (count($parts) < 2) continue;
            // first two parts => "plugins/firefly-collective" or "themes/some-theme"
            $top = implode('/', array_slice($parts, 0, 2));
            $dirs[$top] = true;
        }
        return array_keys($dirs);
    }

    /**
     * Recursively remove files under $live_dir that do not appear in $unzipped_paths.
     *
     * @param string $live_dir   The absolute path on the live site (e.g. ABSPATH . 'wp-content/plugins/firefly-collective')
     * @param string $dir_prefix E.g. "plugins/firefly-collective" used to reconstruct relative path
     * @param array  $unzipped_paths The array of relative paths that do exist
     */
    function firefly_collective_remove_extras($live_dir, $dir_prefix, $unzipped_paths) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($live_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isFile()) continue;
            $file_path = $file->getRealPath();

            // Reconstruct a relative path, e.g. "plugins/firefly-collective/foo.php"
            $relative = str_replace(ABSPATH . 'wp-content/', '', $file_path); 
            // => "plugins/firefly-collective/foo.php" if it was in wp-content/plugins/firefly-collective

            // If this relative path is not in $unzipped_paths, remove it
            if (!in_array($relative, $unzipped_paths, true)) {
                @unlink($file_path);
            }
        }
    }

    /**
     * Backup a file before overwriting it (Optional).
     * If you don't want backups, simply remove this function calls from the code.
     */
    function firefly_collective_backup_file($file_path) {
        // If you do NOT want backups at all, remove references to this function in the code above
        return true;
    }

    /**
     * Log update events for debugging (Optional).
     * If you don't want logs, just remove references to this function.
     */
    function firefly_collective_log_update($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Firefly Collective] ' . $message);
        }
    }

    /**
     * Local environment: Trigger an update by zipping and sending files to live dev.
     * This logic remains unchanged, just sends the final ZIP with 'plugins/' or 'themes/' subfolders.
     */
    function firefly_collective_local_update_project(WP_REST_Request $request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'You do not have permission to perform this action.', array('status' => 403));
        }

        $project_name = sanitize_text_field($request->get_param('project_name'));
        if (empty($project_name)) {
            return new WP_Error('no_project_name', 'No project_name provided.', array('status' => 400));
        }

        $plugin_root        = dirname(plugin_dir_path(__FILE__));
        $projects_json_path = $plugin_root . '/projects.json';
        if (!file_exists($projects_json_path)) {
            return new WP_Error('no_projects_file', 'projects.json not found.', array('status' => 404));
        }

        $content = file_get_contents($projects_json_path);
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return new WP_Error('invalid_projects_file', 'projects.json is invalid.', array('status' => 500));
        }

        // Find the project by name
        $project = null;
        foreach ($decoded as $p) {
            if (isset($p['name']) && $p['name'] === $project_name) {
                $project = $p;
                break;
            }
        }

        if (!$project) {
            return new WP_Error('not_found', 'Project not found.', array('status' => 404));
        }

        // Expecting 'files' replaced by 'directories' if needed
        $directories = isset($project['files']) ? $project['files'] : (isset($project['directories']) ? $project['directories'] : array());
        if (empty($directories) || !is_array($directories)) {
            return new WP_Error('no_files', 'No directories defined for the project.', array('status' => 400));
        }

        $zip_path = firefly_collective_zip_contents($project_name, $directories);
        if (is_wp_error($zip_path)) {
            return $zip_path;
        }

        $response = firefly_collective_send_project_update($zip_path, $project_name, LIVE_DEV_ENDPOINT);
        if (is_wp_error($response)) {
            return $response;
        }

        return array('success' => true, 'message' => $response);
    }

    /**
     * Zip directories from local site, placing files under plugins/ or themes/ as needed.
     */
    function firefly_collective_zip_contents($project_name, $directories) {
        $upload_dir = wp_upload_dir();
        $temp_dir   = trailingslashit($upload_dir['basedir']) . 'firefly_collective_temp';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $zip_name = sanitize_title($project_name) . '-' . time() . '.zip';
        $zip_path = $temp_dir . '/' . $zip_name;

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_error', 'Unable to create zip file.');
        }

        foreach ($directories as $dir) {
            $absolute_dir = ABSPATH . ltrim($dir, '/');
            if (!file_exists($absolute_dir)) continue;

            // Handle single files from wp-content
            if (is_file($absolute_dir) && strpos($dir, '/wp-content/') !== false) {
                $relative_path = trim(str_replace('/wp-content/', '', $dir), '/');
                $zip->addFile($absolute_dir, $relative_path);
                continue;
            }

            // Determine if it's a plugin or theme directory
            if (strpos($dir, '/wp-content/plugins/') !== false) {
                // e.g. /wp-content/plugins/firefly-collective
                $relative_path = str_replace('/wp-content/plugins/', '', $dir);
                $relative_path = trim($relative_path, '/');
                $root_folder   = 'plugins/' . $relative_path;
            } elseif (strpos($dir, '/wp-content/themes/') !== false) {
                // e.g. /wp-content/themes/firefly-collective
                $relative_path = str_replace('/wp-content/themes/', '', $dir);
                $relative_path = trim($relative_path, '/');
                $root_folder   = 'themes/' . $relative_path;
            } else {
                continue; // skip directories outside plugins/ or themes/
            }

            firefly_collective_add_directory_to_zip($zip, $absolute_dir, $absolute_dir, $root_folder);
        }

        $zip->close();

        return $zip_path;
    }

    /**
     * Recursively add a directory to the zip archive, placing files under $root_folder.
     */
    function firefly_collective_add_directory_to_zip($zip, $folder, $base_path, $root_folder) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isFile()) continue;
            $filePath   = $file->getRealPath();
            $local_path = substr($filePath, strlen($base_path) + 1);
            $zip->addFile($filePath, $root_folder . '/' . $local_path);
        }
    }

    /**
     * Send the zipped project file to the live dev environment (Local Site).
     */
    function firefly_collective_send_project_update($zip_path, $project_name, $destination_url) {
        if (!file_exists($zip_path)) {
            return new WP_Error('no_zip', 'Zip file does not exist.');
        }

        $ch = curl_init();

        $cfile = new CURLFile($zip_path, 'application/zip', basename($zip_path));

        $post_fields = array(
            'project_name' => $project_name,
            'file'         => $cfile
        );

        curl_setopt($ch, CURLOPT_URL, $destination_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-Firefly-Secret: ' . FIREFLY_SHARED_SECRET
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            return new WP_Error('curl_error', 'cURL error: ' . $error_msg, array('status' => 500));
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded_response = json_decode($response, true);

        if ($http_code !== 200) {
            $remote_message = isset($decoded_response['message']) ? $decoded_response['message'] : 'Unknown error.';
            return new WP_Error('remote_error', 'Failed to update the live dev environment: ' . $remote_message, array('status' => $http_code));
        }

        return $decoded_response;
    }