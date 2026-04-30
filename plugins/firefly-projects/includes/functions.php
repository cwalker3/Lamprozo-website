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
 * Allowlist of origins permitted to make CORS-credentialed requests
 * to firefly-projects REST endpoints. Tracks Item 4 of
 * docs/firefly-projects-security-evolution.md.
 *
 * Pre-this-helper, the CORS filter reflected whatever Origin header
 * arrived with Access-Control-Allow-Credentials: true. Endpoints are
 * still gated by the shared-secret check (or capability+nonce for
 * admin endpoints), but reflecting arbitrary origins is the
 * textbook permissive-CORS posture and worth tightening.
 *
 * Default allowlist is built from the configured peer endpoints
 * (LIVE_DEV_ENDPOINT + PROD_ENDPOINT) so a typical install has no
 * setup. Local-dev installs also accept localhost on common dev
 * ports. Tenants that need additional origins (custom client app
 * domains, staging URLs, etc.) extend via the
 * `firefly_projects_allowed_origins` filter from template code.
 *
 * @return string[] Array of normalized scheme://host[:port] strings
 */
function firefly_projects_allowed_origins() {
    $origins = array();

    foreach (array('LIVE_DEV_ENDPOINT', 'PROD_ENDPOINT') as $constant) {
        if (defined($constant) && !empty(constant($constant))) {
            $parsed = wp_parse_url((string) constant($constant));
            if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                $origin = $parsed['scheme'] . '://' . $parsed['host'];
                if (!empty($parsed['port'])) {
                    $origin .= ':' . $parsed['port'];
                }
                $origins[] = $origin;
            }
        }
    }

    // The receiver is itself a valid origin (for same-origin admin calls).
    $home = wp_parse_url(home_url());
    if (!empty($home['scheme']) && !empty($home['host'])) {
        $self = $home['scheme'] . '://' . $home['host'];
        if (!empty($home['port'])) $self .= ':' . $home['port'];
        $origins[] = $self;
    }

    // Local-dev workstations bouncing through the docker port map.
    if (firefly_projects_is_local_dev()) {
        $origins[] = 'http://localhost:8080';
        $origins[] = 'http://localhost:8081';
        $origins[] = 'http://localhost:8082';
        $origins[] = 'http://localhost:8083';
    }

    $origins = array_values(array_unique(array_filter($origins)));

    /**
     * Filter the CORS origin allowlist.
     *
     * Used by tenant template code to add tenant-specific origins
     * without modifying framework files.
     *
     * @param string[] $origins
     */
    return apply_filters('firefly_projects_allowed_origins', $origins);
}

/**
 * Verify the X-Firefly-Secret header against the configured shared secret.
 *
 * Single chokepoint for receive-side authentication on the framework's
 * REST endpoints. Tracks Items 1, 2, 5, and 8 of
 * docs/firefly-projects-security-evolution.md:
 *
 *   * Item 2  — this function exists. ~10 inline blocks in rest.php
 *               were collapsed to a single call site here.
 *   * Item 1  — uses hash_equals() for constant-time comparison so
 *               response timing doesn't leak partial-match progress.
 *   * Item 5  — also accepts FIREFLY_SHARED_SECRET_PREVIOUS so secret
 *               rotation can be done with no downtime: set the new
 *               secret + the previous one, update the sender, then
 *               drop the previous.
 *   * Item 8  — checks FIREFLY_DEPLOY_OPEN_UNTIL before doing any auth
 *               work. Receive-side endpoints are closed by default;
 *               operators set the constant (e.g. via
 *               `firefly deploy open --duration 30m`) to open a
 *               deployment window, then the constant lapses.
 *               Unset / empty constant = no window enforcement
 *               (back-compat for sites that haven't opted in yet).
 *
 * Usage:
 *     $auth_failure = firefly_projects_verify_shared_secret($request);
 *     if ($auth_failure !== null) return $auth_failure;
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|null Null on success; a 4xx/5xx response on
 *                               failure that the caller MUST return.
 */
function firefly_projects_verify_shared_secret($request) {
    // Item 8: deployment window gate. Runs before secret comparison so
    // a closed window doesn't leak any signal about secret validity.
    if (defined('FIREFLY_DEPLOY_OPEN_UNTIL') && !empty(FIREFLY_DEPLOY_OPEN_UNTIL)) {
        $open_until = strtotime((string) FIREFLY_DEPLOY_OPEN_UNTIL);
        if ($open_until === false || $open_until < time()) {
            return new WP_REST_Response(array(
                'success'  => false,
                'message'  => 'Deployment window closed.',
                'code'     => 'firefly_deploy_window_closed',
            ), 403);
        }
    }

    if (!defined('FIREFLY_SHARED_SECRET') || empty(FIREFLY_SHARED_SECRET)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Shared secret not configured on remote.'
        ), 500);
    }

    $provided_secret = (string) $request->get_header('X-Firefly-Secret');

    // Item 1: constant-time compare against the current secret.
    if (hash_equals((string) FIREFLY_SHARED_SECRET, $provided_secret)) {
        return null;
    }

    // Item 5: during a rotation, the previous secret is also accepted.
    // Senders that haven't been updated yet keep working until the
    // constant is removed from wp-config.php.
    if (defined('FIREFLY_SHARED_SECRET_PREVIOUS')
        && !empty(FIREFLY_SHARED_SECRET_PREVIOUS)
        && hash_equals((string) FIREFLY_SHARED_SECRET_PREVIOUS, $provided_secret)) {
        return null;
    }

    return new WP_REST_Response(array(
        'success' => false,
        'message' => 'Invalid shared secret.'
    ), 403);
}

