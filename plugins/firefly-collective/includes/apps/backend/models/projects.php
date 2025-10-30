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

    // REST endpoint registration moved to includes/apps/backend/models/rest.php
    // This keeps plugin REST infrastructure separate from business logic

    /**
     * Enqueue styles and scripts for the Projects admin page.
     */
    function enqueue_projects_styles_and_scripts($hook) {
        error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - Hook: ' . $hook);

        if ($hook !== 'toplevel_page_projects') {
            error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - Not projects page, returning');
            return;
        }

        error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - On projects page, proceeding with enqueue');

        $plugin_root = plugin_dir_url(dirname(__FILE__)) . '/';
        $unique_id   = uniqid();

        // Enqueue CSS
        wp_enqueue_style('projects-css', $plugin_root . 'assets/css/projects.css', array(), $unique_id);

        // Enqueue Vue.js 3 from CDN (use VUE_REMOTE_CORE constant if defined)
        $vue_url = defined('VUE_REMOTE_CORE') ? VUE_REMOTE_CORE : 'https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.js';
        wp_enqueue_script('vue-js', $vue_url, array(), '3', true);

        // Enqueue legacy projects.js for backward compatibility
        wp_enqueue_script('projects-js', $plugin_root . 'assets/js/projects.js', array(), $unique_id, true);

        // Enqueue new file selector Vue.js application
        // Version 2.1 - Fixed prop names to kebab-case
        wp_enqueue_script('project-file-selector-js', $plugin_root . 'assets/js/project-file-selector.js', array('vue-js'), '2.1', true);

        $nonce = wp_create_nonce('wp_rest');
        $is_admin = current_user_can('manage_options') ? 'true' : 'false';

        error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - Nonce created: ' . substr($nonce, 0, 10) . '...');
        error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - Is admin: ' . $is_admin);

        // Define the API URL for the plugin's REST API (firefly-plugin namespace)
        $api_url = rest_url('firefly-plugin/v1/');
        error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - API URL: ' . $api_url);

        // Load projects data directly from projects.json
        $plugin_root_path = dirname(plugin_dir_path(__FILE__));
        $projects_json_path = $plugin_root_path . '/projects.json';
        $projects = array();

        error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - Projects JSON path: ' . $projects_json_path);
        error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - File exists: ' . (file_exists($projects_json_path) ? 'YES' : 'NO'));

        if (file_exists($projects_json_path)) {
            $content = file_get_contents($projects_json_path);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $projects = $decoded;
                error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - Loaded ' . count($projects) . ' projects for inline script');
                error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - Project names: ' . implode(', ', array_column($projects, 'name')));
            } else {
                error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - JSON decode failed or not an array');
            }
        } else {
            error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - projects.json not found at: ' . $projects_json_path);
        }

        // Localize script data for legacy projects.js ONLY (no projects array)
        wp_localize_script('projects-js', 'legacyProjectData', array(
            'isAdmin'   => $is_admin,
            'nonce'     => $nonce,
            'adminUrl'  => admin_url('admin.php?page=projects'),
            'apiUrl'    => $api_url,
            'pluginUrl' => plugin_dir_url(__FILE__)
        ));

        // Add project data for Vue app BEFORE the script loads
        $projects_json = json_encode($projects);
        $inline_script = "
        // Initialize project data for Vue app
        window.projectData = {
            projects: {$projects_json},
            apiUrl: '{$api_url}',
            nonce: '{$nonce}',
            isAdmin: {$is_admin},
            adminUrl: '" . admin_url('admin.php?page=projects') . "',
            pluginUrl: '{$plugin_root}'
        };
        console.log('[Firefly Debug] projectData set via wp_add_inline_script:', window.projectData);
        ";
        wp_add_inline_script('project-file-selector-js', $inline_script, 'before');

        error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - Inline script data prepared');
        error_log('[Firefly Projects Debug] enqueue_projects_styles_and_scripts - Data being passed to frontend: ' . print_r(array(
            'projects_count' => count($projects),
            'apiUrl' => $api_url,
            'nonce' => substr($nonce, 0, 10) . '...',
            'isAdmin' => $is_admin
        ), true));
    }

    /**
     * Display the projects dashboard page (local site).
     */
    function firefly_collective_projects_dashboard() {
        error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - Function called');

        $plugin_root = dirname(plugin_dir_path(__FILE__));
        $view_path   = $plugin_root . '/views/projects.php';

        // Load projects data from projects.json on local site
        $projects_json_path = $plugin_root . '/projects.json';
        $projects_data      = array();

        // Debug output
        error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - Plugin root: ' . $plugin_root);
        error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - Projects JSON path: ' . $projects_json_path);
        error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - File exists: ' . (file_exists($projects_json_path) ? 'YES' : 'NO'));

        if (file_exists($projects_json_path)) {
            $content = file_get_contents($projects_json_path);
            error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - File content length: ' . strlen($content));
            $decoded = json_decode($content, true);
            error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - Decoded type: ' . gettype($decoded));
            error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - Decoded content: ' . print_r($decoded, true));
            if (is_array($decoded)) {
                $projects_data = $decoded;
                error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - Projects data count: ' . count($projects_data));
                error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - Project names: ' . implode(', ', array_column($projects_data, 'name')));
            } else {
                error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - Projects data is not an array');
            }
        } else {
            error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - Projects JSON file not found at: ' . $projects_json_path);
        }

        error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - View path: ' . $view_path);
        error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - View file exists: ' . (file_exists($view_path) ? 'YES' : 'NO'));

        if (file_exists($view_path)) {
            error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - Loading view file');
            require_once $view_path;
        } else {
            error_log('[Firefly Projects Debug] firefly_collective_projects_dashboard - View file not found, dying');
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

        // Get sync_mode parameter (default to 'partial' for safety)
        $sync_mode = sanitize_text_field($request->get_param('sync_mode'));
        if (empty($sync_mode) || !in_array($sync_mode, array('full', 'partial'), true)) {
            $sync_mode = 'partial';
        }
        error_log('[Firefly Projects Debug] firefly_collective_handle_project_update - Sync mode received: ' . $sync_mode);

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
        firefly_collective_sync_unzipped($update_dir, $sync_mode);

        // Delete the update directory and all its contents after successful sync
        // WP_Filesystem delete method accepts a second parameter for recursive deletion.
        $wp_filesystem->delete($update_dir, true);

        return new WP_REST_Response(array(
            'message' => 'Project updated successfully.',
            'project' => $project_name,
            'sync_mode' => $sync_mode
        ), 200);
    }

    /**
     * Synchronize the unzipped file structure in $update_dir with the live site by:
     * 1) Overwriting/copying all unzipped files to their final destinations.
     * 2) Conditionally deleting files on the live site that are not present in the unzipped content.
     *
     * @param string $update_dir The directory containing unzipped files
     * @param string $sync_mode Either 'full' (mirror exactly, delete extras) or 'partial' (update only, keep extras)
     */
    function firefly_collective_sync_unzipped($update_dir, $sync_mode = 'partial') {
        error_log('[Firefly Projects Debug] firefly_collective_sync_unzipped - Starting sync with mode: ' . $sync_mode);

        // 1. Overwrite all unzipped files, gathering their relative paths in an array.
        $unzipped_paths = array();
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($update_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files_copied = 0;
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
            $files_copied++;

            // Track for cleanup
            $unzipped_paths[] = $relative_path;
        }

        error_log('[Firefly Projects Debug] firefly_collective_sync_unzipped - Files copied/updated: ' . $files_copied);

        // 2. Conditionally remove extraneous plugin/theme files based on sync mode
        if ($sync_mode === 'full') {
            error_log('[Firefly Projects Debug] firefly_collective_sync_unzipped - FULL SYNC: Removing files not in sync package');
            $top_level_dirs = firefly_collective_extract_top_level_dirs($unzipped_paths);
            $files_deleted = 0;
            foreach ($top_level_dirs as $dir) {
                $live_dir = ABSPATH . 'wp-content/' . $dir;
                if (! is_dir($live_dir)) continue;
                $files_deleted += firefly_collective_remove_extras($live_dir, $dir, $unzipped_paths);
            }
            error_log('[Firefly Projects Debug] firefly_collective_sync_unzipped - Files deleted: ' . $files_deleted);
        } else {
            error_log('[Firefly Projects Debug] firefly_collective_sync_unzipped - PARTIAL SYNC: Skipping file deletion phase (keeping existing files)');
        }

        error_log('[Firefly Projects Debug] firefly_collective_sync_unzipped - Sync completed');
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
     * @return int The number of files deleted
     */
    function firefly_collective_remove_extras($live_dir, $dir_prefix, $unzipped_paths) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($live_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $deleted_count = 0;
        foreach ($files as $file) {
            if (!$file->isFile()) continue;
            $file_path = $file->getRealPath();

            // Reconstruct a relative path, e.g. "plugins/firefly-collective/foo.php"
            $relative = str_replace(ABSPATH . 'wp-content/', '', $file_path);
            // => "plugins/firefly-collective/foo.php" if it was in wp-content/plugins/firefly-collective

            // If this relative path is not in $unzipped_paths, remove it
            if (!in_array($relative, $unzipped_paths, true)) {
                if (@unlink($file_path)) {
                    $deleted_count++;
                    error_log('[Firefly Projects Debug] firefly_collective_remove_extras - Deleted: ' . $relative);
                }
            }
        }
        return $deleted_count;
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
     * Get backup directory for a project
     */
    function firefly_collective_get_backup_dir($project_name) {
        $upload_dir = wp_upload_dir();
        $project_slug = sanitize_title($project_name);
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'firefly_backups/' . $project_slug;

        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        return $backup_dir;
    }

    /**
     * Get backups metadata file path
     */
    function firefly_collective_get_backups_metadata_path($project_name) {
        $backup_dir = firefly_collective_get_backup_dir($project_name);
        return trailingslashit($backup_dir) . 'backups.json';
    }

    /**
     * Load backups metadata
     */
    function firefly_collective_load_backups_metadata($project_name) {
        $metadata_path = firefly_collective_get_backups_metadata_path($project_name);

        if (!file_exists($metadata_path)) {
            return array();
        }

        $content = file_get_contents($metadata_path);
        $backups = json_decode($content, true);

        return is_array($backups) ? $backups : array();
    }

    /**
     * Save backups metadata
     */
    function firefly_collective_save_backups_metadata($project_name, $backups) {
        $metadata_path = firefly_collective_get_backups_metadata_path($project_name);
        file_put_contents($metadata_path, json_encode($backups, JSON_PRETTY_PRINT));
    }

    /**
     * Add a new backup and rotate (keep last 5)
     */
    function firefly_collective_add_backup($project_name, $zip_path, $sync_mode, $file_count, $selected_count) {
        $backups = firefly_collective_load_backups_metadata($project_name);
        $backup_dir = firefly_collective_get_backup_dir($project_name);

        // Create backup ID based on timestamp
        $timestamp = current_time('mysql');
        $id = 'sync_' . date('Y-m-d_H-i-s');
        $zip_filename = $id . '.zip';
        $permanent_zip_path = trailingslashit($backup_dir) . $zip_filename;

        // Copy ZIP to backup location
        copy($zip_path, $permanent_zip_path);

        // Add new backup metadata
        $new_backup = array(
            'id' => $id,
            'timestamp' => $timestamp,
            'sync_mode' => $sync_mode,
            'file_count' => $file_count,
            'selected_count' => $selected_count,
            'zip_filename' => $zip_filename,
            'zip_size' => filesize($permanent_zip_path)
        );

        // Add to beginning of array (newest first)
        array_unshift($backups, $new_backup);

        // Keep only last 5 backups
        if (count($backups) > 5) {
            $removed_backups = array_splice($backups, 5);

            // Delete old ZIP files
            foreach ($removed_backups as $old_backup) {
                $old_zip_path = trailingslashit($backup_dir) . $old_backup['zip_filename'];
                if (file_exists($old_zip_path)) {
                    @unlink($old_zip_path);
                    error_log('[Firefly Backups] Deleted old backup: ' . $old_backup['zip_filename']);
                }
            }
        }

        // Save updated metadata
        firefly_collective_save_backups_metadata($project_name, $backups);

        error_log('[Firefly Backups] Added backup: ' . $zip_filename . ' (keeping ' . count($backups) . ' backups)');

        return $new_backup;
    }

    /**
     * Local environment: Trigger an update by zipping and sending files to live dev.
     * Supports optional 'selected_files' parameter for selective file syncing.
     */
    function firefly_collective_local_update_project(WP_REST_Request $request) {
        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Function called');

        if (!current_user_can('manage_options')) {
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Permission denied: User lacks manage_options capability');
            return new WP_Error('forbidden', 'You do not have permission to perform this action.', array('status' => 403));
        }

        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Permission check passed');

        $project_name = sanitize_text_field($request->get_param('project_name'));
        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Project name: ' . $project_name);

        if (empty($project_name)) {
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - No project name provided');
            return new WP_Error('no_project_name', 'No project_name provided.', array('status' => 400));
        }

        $plugin_root        = dirname(plugin_dir_path(__FILE__));
        $projects_json_path = $plugin_root . '/projects.json';

        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Projects JSON path: ' . $projects_json_path);
        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - File exists: ' . (file_exists($projects_json_path) ? 'YES' : 'NO'));

        if (!file_exists($projects_json_path)) {
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - projects.json not found');
            return new WP_Error('no_projects_file', 'projects.json not found.', array('status' => 404));
        }

        $content = file_get_contents($projects_json_path);
        $decoded = json_decode($content, true);

        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Decoded projects count: ' . (is_array($decoded) ? count($decoded) : 'NOT AN ARRAY'));

        if (!is_array($decoded)) {
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Invalid projects.json');
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
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Project not found: ' . $project_name);
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Available projects: ' . implode(', ', array_column($decoded, 'name')));
            return new WP_Error('not_found', 'Project not found.', array('status' => 404));
        }

        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Project found');

        // Get directories from project
        $directories = isset($project['files']) ? $project['files'] : (isset($project['directories']) ? $project['directories'] : array());

        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Directories count: ' . (is_array($directories) ? count($directories) : 'NOT AN ARRAY'));

        if (empty($directories) || !is_array($directories)) {
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - No directories defined');
            return new WP_Error('no_files', 'No directories defined for the project.', array('status' => 400));
        }

        // Check for sync_mode parameter (default to 'partial' for safety)
        $sync_mode = sanitize_text_field($request->get_param('sync_mode'));
        if (empty($sync_mode) || !in_array($sync_mode, array('full', 'partial'), true)) {
            $sync_mode = 'partial';
        }
        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Sync mode: ' . $sync_mode);

        // For FULL sync mode, ALWAYS use all project files (ignore selected_files parameter)
        if ($sync_mode === 'full') {
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - FULL SYNC: Using all project files (ignoring selections)');
            // $directories already contains all files from projects.json
            // Don't filter by selected_files
        } else {
            // PARTIAL sync mode: use selected_files if provided
            $selected_files = $request->get_param('selected_files');
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Selected files parameter: ' . (empty($selected_files) ? 'EMPTY' : 'PROVIDED'));

            if (!empty($selected_files) && is_array($selected_files)) {
                error_log('[Firefly Projects Debug] firefly_collective_local_update_project - PARTIAL SYNC: Using selected files: ' . count($selected_files) . ' items');
                error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Selected files list: ' . print_r($selected_files, true));
                $directories = $selected_files;
            } else {
                error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Using all project directories: ' . count($directories) . ' items');
            }
        }

        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Creating zip file');
        $zip_path = firefly_collective_zip_contents($project_name, $directories);
        if (is_wp_error($zip_path)) {
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Zip creation failed: ' . $zip_path->get_error_message());
            return $zip_path;
        }

        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Zip created: ' . $zip_path);

        // Save backup before sending
        $backup = firefly_collective_add_backup(
            $project_name,
            $zip_path,
            $sync_mode,
            count($directories), // total files in project
            count($directories)  // files actually selected (same for full sync, subset for partial)
        );

        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Backup saved: ' . $backup['id']);
        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Sending to live dev endpoint: ' . LIVE_DEV_ENDPOINT);

        $response = firefly_collective_send_project_update($zip_path, $project_name, LIVE_DEV_ENDPOINT, $sync_mode);
        if (is_wp_error($response)) {
            error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Send failed: ' . $response->get_error_message());
            return $response;
        }

        error_log('[Firefly Projects Debug] firefly_collective_local_update_project - Update successful');

        // Clean up temp directory
        firefly_collective_cleanup_temp_dir();

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
     * Get project files as hierarchical tree structure for file selection UI.
     *
     * @param WP_REST_Request $request REST request with project_name parameter
     * @return WP_REST_Response|WP_Error Response with file tree or error
     */
    function firefly_collective_get_project_files(WP_REST_Request $request) {
        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Function called');

        if (!current_user_can('manage_options')) {
            error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Permission denied: User lacks manage_options capability');
            return new WP_Error('forbidden', 'You do not have permission to perform this action.', array('status' => 403));
        }

        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Permission check passed');

        $project_name = sanitize_text_field($request->get_param('project_name'));
        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Project name: ' . $project_name);

        if (empty($project_name)) {
            error_log('[Firefly Projects Debug] firefly_collective_get_project_files - No project name provided');
            return new WP_Error('no_project_name', 'No project_name provided.', array('status' => 400));
        }

        $plugin_root        = dirname(plugin_dir_path(__FILE__));
        $projects_json_path = $plugin_root . '/projects.json';

        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Projects JSON path: ' . $projects_json_path);
        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - File exists: ' . (file_exists($projects_json_path) ? 'YES' : 'NO'));

        if (!file_exists($projects_json_path)) {
            error_log('[Firefly Projects Debug] firefly_collective_get_project_files - projects.json not found');
            return new WP_Error('no_projects_file', 'projects.json not found.', array('status' => 404));
        }

        $content = file_get_contents($projects_json_path);
        $decoded = json_decode($content, true);

        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Decoded projects count: ' . (is_array($decoded) ? count($decoded) : 'NOT AN ARRAY'));

        if (!is_array($decoded)) {
            error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Invalid projects.json');
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
            error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Project not found: ' . $project_name);
            error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Available projects: ' . implode(', ', array_column($decoded, 'name')));
            return new WP_Error('not_found', 'Project not found.', array('status' => 404));
        }

        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Project found: ' . print_r($project, true));

        $directories = isset($project['files']) ? $project['files'] : (isset($project['directories']) ? $project['directories'] : array());

        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Directories count: ' . (is_array($directories) ? count($directories) : 'NOT AN ARRAY'));
        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Directories: ' . print_r($directories, true));

        if (empty($directories) || !is_array($directories)) {
            error_log('[Firefly Projects Debug] firefly_collective_get_project_files - No directories defined');
            return new WP_Error('no_files', 'No directories defined for the project.', array('status' => 400));
        }

        // Build file tree for each directory
        $file_tree = array();
        foreach ($directories as $dir) {
            $relative_path = ltrim($dir, '/');
            $absolute_path = ABSPATH . $relative_path;

            error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Processing directory: ' . $dir);
            error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Absolute path: ' . $absolute_path);
            error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Path exists: ' . (file_exists($absolute_path) ? 'YES' : 'NO'));

            if (!file_exists($absolute_path)) {
                error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Skipping non-existent path: ' . $absolute_path);
                continue;
            }

            if (is_file($absolute_path)) {
                // Single file - just show basename
                $file_tree[] = array(
                    'path' => '/' . $relative_path,
                    'name' => basename($relative_path),
                    'type' => 'file',
                    'size' => filesize($absolute_path),
                    'modified' => date('Y-m-d H:i:s', filemtime($absolute_path))
                );
            } else {
                // Directory - pass TRUE for is_top_level flag
                $tree_node = firefly_collective_build_directory_tree($relative_path, $absolute_path, true);
                if ($tree_node) {
                    $file_tree[] = $tree_node;
                    error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Added tree node: ' . $tree_node['path']);
                }
            }
        }

        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - File tree count: ' . count($file_tree));
        error_log('[Firefly Projects Debug] firefly_collective_get_project_files - Returning success response');

        return new WP_REST_Response(array(
            'success' => true,
            'project' => $project_name,
            'files'   => $file_tree
        ), 200);
    }

    /**
     * Recursively build a directory tree structure with file metadata.
     *
     * @param string $relative_path Path relative to ABSPATH (e.g., 'wp-content/plugins/firefly-collective')
     * @param string $absolute_path Absolute filesystem path
     * @param bool $is_top_level Whether this is a top-level project folder (shows full path)
     * @return array|null Tree node with path, type, size, modified, and children
     */
    function firefly_collective_build_directory_tree($relative_path, $absolute_path, $is_top_level = false) {
        // Security: validate path is within ABSPATH
        $real_path = realpath($absolute_path);
        $real_abspath = realpath(ABSPATH);

        if ($real_path === false || strpos($real_path, $real_abspath) !== 0) {
            return null;
        }

        // Normalize relative path
        $relative_path = '/' . ltrim($relative_path, '/');

        // For top-level folders, show full path like "plugins/firefly-collective"
        // For sub-folders, show just basename like "includes"
        if ($is_top_level) {
            $display_path = ltrim(str_replace('/wp-content/', '', $relative_path), '/');
            $display_name = $display_path;
        } else {
            $display_name = basename($relative_path);
        }

        $node = array(
            'path' => $relative_path,
            'name' => $display_name
        );

        if (is_file($absolute_path)) {
            $node['type'] = 'file';
            $node['size'] = filesize($absolute_path);
            $node['modified'] = date('Y-m-d H:i:s', filemtime($absolute_path));
        } elseif (is_dir($absolute_path)) {
            $node['type'] = 'directory';
            $node['children'] = array();

            $items = scandir($absolute_path);
            if ($items === false) {
                return $node;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $item_absolute = $absolute_path . '/' . $item;
                $item_relative = $relative_path . '/' . $item;

                if (is_file($item_absolute)) {
                    // Files always show just basename
                    $node['children'][] = array(
                        'path' => $item_relative,
                        'name' => $item,
                        'type' => 'file',
                        'size' => filesize($item_absolute),
                        'modified' => date('Y-m-d H:i:s', filemtime($item_absolute))
                    );
                } elseif (is_dir($item_absolute)) {
                    // Sub-directories: pass FALSE for is_top_level (or omit it)
                    $child_node = firefly_collective_build_directory_tree($item_relative, $item_absolute, false);
                    if ($child_node) {
                        $node['children'][] = $child_node;
                    }
                }
            }

            // Sort children: directories first, then files, both alphabetically
            usort($node['children'], function($a, $b) {
                if ($a['type'] === $b['type']) {
                    return strcasecmp($a['name'], $b['name']);
                }
                return $a['type'] === 'directory' ? -1 : 1;
            });
        }

        return $node;
    }

    /**
     * Clean up temp directory after sync operations
     */
    function firefly_collective_cleanup_temp_dir() {
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'firefly_collective_temp';

        if (!file_exists($temp_dir)) {
            return;
        }

        // Delete all files in temp directory
        $files = glob($temp_dir . '/*');
        $deleted_count = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $deleted_count++;
                }
            }
        }

        error_log('[Firefly Projects Debug] Cleaned temp directory: deleted ' . $deleted_count . ' files');
    }

    /**
     * Send the zipped project file to the live dev environment (Local Site).
     */
    function firefly_collective_send_project_update($zip_path, $project_name, $destination_url, $sync_mode = 'partial') {
        if (!file_exists($zip_path)) {
            return new WP_Error('no_zip', 'Zip file does not exist.');
        }

        error_log('[Firefly Projects Debug] firefly_collective_send_project_update - Sync mode being sent: ' . $sync_mode);

        $ch = curl_init();

        $cfile = new CURLFile($zip_path, 'application/zip', basename($zip_path));

        $post_fields = array(
            'project_name' => $project_name,
            'file'         => $cfile,
            'sync_mode'    => $sync_mode
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

    /**
     * REST handler: Get backup history for a project
     */
    function firefly_collective_get_backup_history(WP_REST_Request $request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Insufficient permissions', array('status' => 403));
        }

        $project_name = sanitize_text_field($request->get_param('project_name'));

        if (empty($project_name)) {
            return new WP_Error('missing_param', 'Project name is required', array('status' => 400));
        }

        $backups = firefly_collective_load_backups_metadata($project_name);

        return array(
            'success' => true,
            'project' => $project_name,
            'backups' => $backups
        );
    }

    /**
     * REST handler: Restore from a specific backup
     */
    function firefly_collective_restore_backup(WP_REST_Request $request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Insufficient permissions', array('status' => 403));
        }

        $project_name = sanitize_text_field($request->get_param('project_name'));
        $backup_id = sanitize_text_field($request->get_param('backup_id'));

        if (empty($project_name) || empty($backup_id)) {
            return new WP_Error('missing_params', 'Project name and backup ID are required', array('status' => 400));
        }

        // Load metadata
        $backups = firefly_collective_load_backups_metadata($project_name);

        // Find the backup
        $backup = null;
        foreach ($backups as $b) {
            if ($b['id'] === $backup_id) {
                $backup = $b;
                break;
            }
        }

        if (!$backup) {
            return new WP_Error('backup_not_found', 'Backup not found', array('status' => 404));
        }

        // Get ZIP path
        $backup_dir = firefly_collective_get_backup_dir($project_name);
        $zip_path = trailingslashit($backup_dir) . $backup['zip_filename'];

        if (!file_exists($zip_path)) {
            return new WP_Error('zip_not_found', 'Backup ZIP file not found', array('status' => 404));
        }

        error_log('[Firefly Projects Debug] firefly_collective_restore_backup - Restoring from: ' . $backup['id']);

        // Send the backup ZIP to remote (always as 'full' sync since it's a complete restore)
        $response = firefly_collective_send_project_update($zip_path, $project_name, LIVE_DEV_ENDPOINT, 'full');

        if (is_wp_error($response)) {
            error_log('[Firefly Projects Debug] firefly_collective_restore_backup - Restore failed: ' . $response->get_error_message());
            return $response;
        }

        error_log('[Firefly Projects Debug] firefly_collective_restore_backup - Restore successful');

        // Clean up temp directory
        firefly_collective_cleanup_temp_dir();

        return array(
            'success' => true,
            'message' => 'Successfully restored from backup: ' . $backup['timestamp'],
            'backup' => $backup
        );
    }

    /**
     * REST handler: Delete a specific backup
     */
    function firefly_collective_delete_backup(WP_REST_Request $request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Insufficient permissions', array('status' => 403));
        }

        $project_name = sanitize_text_field($request->get_param('project_name'));
        $backup_id = sanitize_text_field($request->get_param('backup_id'));

        if (empty($project_name) || empty($backup_id)) {
            return new WP_Error('missing_params', 'Project name and backup ID are required', array('status' => 400));
        }

        // Load metadata
        $backups = firefly_collective_load_backups_metadata($project_name);

        // Find the backup
        $backup_index = null;
        $backup = null;
        foreach ($backups as $index => $b) {
            if ($b['id'] === $backup_id) {
                $backup = $b;
                $backup_index = $index;
                break;
            }
        }

        if (!$backup) {
            return new WP_Error('backup_not_found', 'Backup not found', array('status' => 404));
        }

        // Get ZIP path and delete file
        $backup_dir = firefly_collective_get_backup_dir($project_name);
        $zip_path = trailingslashit($backup_dir) . $backup['zip_filename'];

        if (file_exists($zip_path)) {
            if (!@unlink($zip_path)) {
                return new WP_Error('delete_failed', 'Failed to delete backup file', array('status' => 500));
            }
            error_log('[Firefly Backups] Manually deleted backup: ' . $backup['zip_filename']);
        }

        // Remove from metadata
        array_splice($backups, $backup_index, 1);
        firefly_collective_save_backups_metadata($project_name, $backups);

        error_log('[Firefly Projects Debug] firefly_collective_delete_backup - Deleted backup: ' . $backup_id);

        return array(
            'success' => true,
            'message' => 'Backup deleted successfully',
            'deleted_backup' => $backup
        );
    }