<?php
/**
 * Core helper functions for Firefly Projects
 */

// Ensure no direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if plugin is properly configured
 *
 * @return bool True if both FIREFLY_SHARED_SECRET and LIVE_DEV_ENDPOINT are set
 */
function firefly_projects_is_configured() {
    return !empty(FIREFLY_SHARED_SECRET) && !empty(LIVE_DEV_ENDPOINT);
}

/**
 * Get projects.json path
 *
 * @return string Absolute path to projects.json file
 */
function firefly_projects_get_json_path() {
    return FIREFLY_PROJECTS_PLUGIN_DIR . 'projects.json';
}

/**
 * Load projects from projects.json file
 *
 * @return array Array of projects or empty array if file doesn't exist
 */
function firefly_projects_load_projects() {
    $projects_json_path = firefly_projects_get_json_path();

    if (!file_exists($projects_json_path)) {
        return array();
    }

    $content = file_get_contents($projects_json_path);
    $decoded = json_decode($content, true);

    return is_array($decoded) ? $decoded : array();
}

/**
 * Find a project by name
 *
 * @param string $project_name The name of the project to find
 * @return array|null The project array or null if not found
 */
function firefly_projects_find_project($project_name) {
    $projects = firefly_projects_load_projects();

    foreach ($projects as $project) {
        if (isset($project['name']) && $project['name'] === $project_name) {
            return $project;
        }
    }

    return null;
}

/**
 * Check if current environment is Local Dev
 *
 * @return bool True if this is a local development environment
 */
function firefly_projects_is_local_dev() {
    return defined('FIREFLY_DEV') && FIREFLY_DEV === true;
}

/**
 * Check if current environment is Live Dev
 *
 * @return bool True if this is a live development environment (headless)
 */
function firefly_projects_is_live_dev() {
    return defined('FIREFLY_LIVE_DEV') && FIREFLY_LIVE_DEV === true;
}

/**
 * Check if current environment is Production
 *
 * @return bool True if this is a production environment
 */
function firefly_projects_is_production() {
    return !firefly_projects_is_local_dev() && !firefly_projects_is_live_dev();
}

/**
 * Get current environment name
 *
 * @return string 'local_dev', 'live_dev', or 'production'
 */
function firefly_projects_get_environment() {
    if (firefly_projects_is_local_dev()) {
        return 'local_dev';
    }
    if (firefly_projects_is_live_dev()) {
        return 'live_dev';
    }
    return 'production';
}

/**
 * Check if a project has paths without -dev suffix
 *
 * @param string $project_name The name of the project to check
 * @return array|false Array of paths without -dev suffix, or false if none found
 */
function firefly_projects_needs_dev_suffix($project_name) {
    $project = firefly_projects_find_project($project_name);

    if (!$project) {
        return false;
    }

    $files = isset($project['files']) ? $project['files'] : array();

    if (empty($files)) {
        return false;
    }

    $missing_dev = array();

    foreach ($files as $path) {
        $path = ltrim($path, '/');

        // Check if it's a plugin or theme path
        if (!preg_match('#^wp-content/(plugins|themes)/([^/]+)#', $path, $matches)) {
            continue;
        }

        $folder_name = $matches[2];

        // Check if it already has -dev suffix
        if (!preg_match('/-dev$/', $folder_name)) {
            $missing_dev[] = array(
                'path' => '/' . $path,
                'folder' => $folder_name,
                'type' => $matches[1]
            );
        }
    }

    return empty($missing_dev) ? false : $missing_dev;
}
