<?php
/**
 * Whole-Template Push/Pull ("Template Sync" Tools page)
 *
 * Treats a template as a single portable unit between the three tiers:
 * local dev ↔ live dev ↔ production. Composes the existing per-object
 * primitives (page/menu sync + pull) with new endpoints for template FILES,
 * template-scoped MEDIA, and template SETTINGS (Customizer options,
 * categories + assignments, page roles, pricing).
 *
 * Tier rules: local initiates to dev+prod; live dev initiates to prod;
 * production initiates nothing (page hidden, endpoint constants absent).
 *
 * The pipeline is CLIENT-ORCHESTRATED (template-sync.js): each step is its
 * own REST call, so no single PHP request outlives the ~100s proxy window
 * and the UI gets live per-step progress. Step order — files first — is what
 * installs the template on the receiver so the per-page/menu template guards
 * pass naturally for every later step.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Push parts / pull chunks stay well under Cloudflare's ~100 MB body limit.
if (!defined('FIREFLY_TS_PART_BYTES')) {
    define('FIREFLY_TS_PART_BYTES', 40 * 1024 * 1024);
}
if (!defined('FIREFLY_TS_CHUNK_BYTES')) {
    define('FIREFLY_TS_CHUNK_BYTES', 8 * 1024 * 1024);
}
// Media above this size travels in chunks: base64-in-one-JSON of an 80MB+
// recording blows the remote's PHP memory limit (the capture.mp3 HTTP 500s).
if (!defined('FIREFLY_TS_MEDIA_INLINE_MAX')) {
    define('FIREFLY_TS_MEDIA_INLINE_MAX', 20 * 1024 * 1024);
}

// =============================================================================
// TOOLS PAGE
// =============================================================================

add_action('admin_menu', 'firefly_projects_template_sync_menu');
function firefly_projects_template_sync_menu() {
    // Visible on local AND live dev — production initiates nothing.
    if (firefly_projects_is_production()) {
        return;
    }
    add_management_page(
        'Template Sync',
        'Template Sync',
        'manage_options',
        'firefly-template-sync',
        'firefly_projects_template_sync_page'
    );
}

function firefly_projects_template_sync_page() {
    require FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/views/template-sync.php';
}

/**
 * One-shot post-bootstrap redirect: land the operator on Template Sync the
 * first time they reach wp-admin after a fresh setup. bootstrap.sh sets the
 * 'firefly_ts_bootstrap_redirect' option during activation; this consumes it
 * once (admin-only, never on the page itself, never during AJAX/REST).
 */
add_action('admin_init', 'firefly_projects_template_sync_first_landing');
function firefly_projects_template_sync_first_landing() {
    if (firefly_projects_is_production() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    if (!get_option('firefly_ts_bootstrap_redirect')) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    // Already heading there? consume the flag and let it load normally.
    if (isset($_GET['page']) && $_GET['page'] === 'firefly-template-sync') {
        delete_option('firefly_ts_bootstrap_redirect');
        return;
    }
    delete_option('firefly_ts_bootstrap_redirect');
    wp_safe_redirect(admin_url('tools.php?page=firefly-template-sync'));
    exit;
}

add_action('admin_enqueue_scripts', 'firefly_projects_template_sync_enqueue');
function firefly_projects_template_sync_enqueue($hook) {
    if ($hook !== 'tools_page_firefly-template-sync') {
        return;
    }

    $js_file  = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/js/template-sync.js';
    $css_file = FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/assets/css/template-sync.css';
    $js_version  = file_exists($js_file) ? filemtime($js_file) : FIREFLY_PROJECTS_VERSION;
    $css_version = file_exists($css_file) ? filemtime($css_file) : FIREFLY_PROJECTS_VERSION;

    wp_enqueue_script(
        'firefly-template-sync',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/js/template-sync.js',
        array('jquery'),
        $js_version,
        true
    );
    wp_enqueue_style(
        'firefly-template-sync',
        FIREFLY_PROJECTS_PLUGIN_URL . 'includes/assets/css/template-sync.css',
        array(),
        $css_version
    );

    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/template-schema-sync.php';

    wp_localize_script('firefly-template-sync', 'fireflyTemplateSync', array(
        'restUrl'        => rest_url('firefly-plugin/v1/'),
        'nonce'          => wp_create_nonce('wp_rest'),
        'environment'    => firefly_projects_get_environment(),
        'hasDevEndpoint' => defined('LIVE_DEV_ENDPOINT') && !empty(LIVE_DEV_ENDPOINT),
        'hasProdEndpoint' => defined('PROD_ENDPOINT') && !empty(PROD_ENDPOINT),
        'devSite'        => defined('LIVE_DEV_ENDPOINT') && !empty(LIVE_DEV_ENDPOINT) ? parse_url(LIVE_DEV_ENDPOINT, PHP_URL_HOST) : '',
        'prodSite'       => defined('PROD_ENDPOINT') && !empty(PROD_ENDPOINT) ? parse_url(PROD_ENDPOINT, PHP_URL_HOST) : '',
        'localTemplates' => firefly_projects_get_available_templates(),
        'activeTemplate' => function_exists('firefly_get_scoping_template') ? firefly_get_scoping_template() : '',
    ));
}

// =============================================================================
// SHARED HELPERS
// =============================================================================

/**
 * Strict template-name gate. Runs before ANY path math on every endpoint.
 */
function firefly_projects_ts_valid_name($template) {
    return is_string($template) && preg_match('/^[a-z0-9_-]+$/', $template) === 1;
}

/**
 * Resolve the remote base URL for an environment from the endpoint constants.
 *
 * @return string|WP_Error e.g. "https://dev.fireflycreative.io"
 */
function firefly_projects_ts_remote_base($env) {
    if ($env === 'prod') {
        if (!defined('PROD_ENDPOINT') || empty(PROD_ENDPOINT)) {
            return new WP_Error('no_prod_endpoint', 'PROD_ENDPOINT is not configured in wp-config.php.', array('status' => 400));
        }
        $endpoint = PROD_ENDPOINT;
    } else {
        if (!defined('LIVE_DEV_ENDPOINT') || empty(LIVE_DEV_ENDPOINT)) {
            return new WP_Error('no_dev_endpoint', 'LIVE_DEV_ENDPOINT is not configured in wp-config.php.', array('status' => 400));
        }
        $endpoint = LIVE_DEV_ENDPOINT;
    }
    if (!preg_match('/(https?:\/\/[^\/]+)/', $endpoint, $matches)) {
        return new WP_Error('bad_endpoint', 'Could not determine remote URL from the configured endpoint.', array('status' => 400));
    }
    return $matches[1];
}

/**
 * Call a remote /template-sync/* endpoint with shared-secret auth.
 * Maps a 404/rest_no_route into the "plugin is older there" message so the
 * UI can tell the operator exactly what to do.
 *
 * @return array|WP_Error decoded JSON body
 */
function firefly_projects_ts_remote_request($env, $method, $path, $body = null, $timeout = 120) {
    $base = firefly_projects_ts_remote_base($env);
    if (is_wp_error($base)) {
        return $base;
    }

    $url  = $base . '/wp-json/firefly-plugin/v1/template-sync/' . ltrim($path, '/');
    $args = array(
        'method'  => $method,
        'timeout' => $timeout,
        'headers' => array('X-Firefly-Secret' => FIREFLY_SHARED_SECRET),
    );
    if ($body !== null) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        return new WP_Error('remote_unreachable', 'Failed to connect to remote: ' . $response->get_error_message(), array('status' => 500));
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 404 && (!is_array($data) || (isset($data['code']) && $data['code'] === 'rest_no_route'))) {
        $env_label = ($env === 'prod') ? 'Production' : 'Live Dev';
        return new WP_Error('remote_plugin_old', "The firefly-projects plugin on {$env_label} is older than this one and doesn't have Template Sync yet. Push the plugin there first, then retry.", array('status' => 404));
    }
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        $msg = is_array($data) && isset($data['message']) ? $data['message'] : 'Unknown error from remote (HTTP ' . $code . ').';
        return new WP_Error('remote_error', 'Remote error: ' . $msg, array('status' => $code ?: 500));
    }
    return $data;
}

/**
 * Dual auth for endpoints that serve both the local admin UI and remote
 * peers: a request carrying the shared-secret header is verified as a peer,
 * anything else must pass the admin nonce + capability check.
 *
 * @return null|WP_REST_Response|WP_Error null = authorized
 */
function firefly_projects_ts_dual_auth($request) {
    $secret = $request->get_header('X-Firefly-Secret');
    if ($secret !== null && $secret !== '') {
        return firefly_projects_verify_shared_secret($request);
    }
    if (firefly_plugin_verify_rest_admin($request) !== true) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Unauthorized.'), 403);
    }
    return null;
}

/**
 * The three wp-content-relative scopes that make up a template's files.
 * These strings double as the allowed archive-path prefixes — nothing
 * outside them is ever written or deleted by the files step.
 */
function firefly_projects_ts_scopes($template) {
    return array(
        'theme_dir'  => 'themes/firefly-collective/templates/' . $template,
        'schema'     => 'themes/firefly-collective/data/schemas/' . $template . '-schema.json',
        'plugin_dir' => 'plugins/firefly-collective/templates/' . $template,
    );
}

/**
 * True when $archive_path (wp-content-relative, forward slashes) is inside
 * the template's allowed scopes.
 */
function firefly_projects_ts_path_in_scope($archive_path, $scopes) {
    if (strpos($archive_path, '..') !== false || strpos($archive_path, "\0") !== false) {
        return false;
    }
    if ($archive_path === $scopes['schema']) {
        return true;
    }
    return strpos($archive_path, $scopes['theme_dir'] . '/') === 0
        || strpos($archive_path, $scopes['plugin_dir'] . '/') === 0;
}

function firefly_projects_ts_temp_dir() {
    $upload_dir = wp_upload_dir();
    $temp_dir = trailingslashit($upload_dir['basedir']) . 'firefly_collective_temp';
    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
    }
    return $temp_dir;
}

// =============================================================================
// TEMPLATE FILES — collect / package / apply
// =============================================================================

/**
 * Enumerate every file in the template's scopes.
 *
 * @return array of ['abs' => absolute path, 'archive' => wp-content-relative, 'size' => bytes]
 */
function firefly_projects_ts_collect_files($template) {
    $scopes = firefly_projects_ts_scopes($template);
    $files  = array();

    foreach (array($scopes['theme_dir'], $scopes['plugin_dir']) as $scope) {
        $abs_dir = WP_CONTENT_DIR . '/' . $scope;
        if (!is_dir($abs_dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $abs = $file->getRealPath();
            $rel = str_replace('\\', '/', substr($abs, strlen(realpath($abs_dir)) + 1));
            $files[] = array(
                'abs'     => $abs,
                'archive' => $scope . '/' . $rel,
                'size'    => $file->getSize(),
            );
        }
    }

    $schema_abs = WP_CONTENT_DIR . '/' . $scopes['schema'];
    if (file_exists($schema_abs)) {
        $files[] = array('abs' => $schema_abs, 'archive' => $scopes['schema'], 'size' => filesize($schema_abs));
    }

    return $files;
}

/**
 * Read the template's entry (model list) from the plugin's templates.json.
 * The whole file NEVER travels — only this one key does, so a receiver's
 * entries for its other templates are never clobbered.
 */
function firefly_projects_ts_templates_json_entry($template) {
    $path = WP_CONTENT_DIR . '/plugins/firefly-collective/templates.json';
    if (!file_exists($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path), true);
    return (is_array($data) && isset($data[$template])) ? $data[$template] : null;
}

function firefly_projects_ts_merge_templates_json_entry($template, $entry) {
    if (!is_array($entry)) {
        return false;
    }
    $path = WP_CONTENT_DIR . '/plugins/firefly-collective/templates.json';
    $data = file_exists($path) ? json_decode(file_get_contents($path), true) : array();
    if (!is_array($data)) {
        $data = array();
    }
    $data[$template] = array_values(array_map('sanitize_text_field', $entry));
    // Atomic write: tmp + rename, matching apply_associated_files' schema merge.
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        return false;
    }
    return rename($tmp, $path);
}

/**
 * Zip a list of collected files into the temp dir. Archive paths are the
 * wp-content-relative 'themes/…|plugins/…' convention shared with the
 * projects.php shipper (so the existing backup/rollback tooling understands
 * these archives too).
 *
 * @return string|WP_Error zip path
 */
function firefly_projects_ts_build_zip($template, $files, $suffix = '') {
    $zip_path = firefly_projects_ts_temp_dir() . '/template-' . sanitize_title($template) . $suffix . '-' . time() . '-' . wp_generate_password(6, false) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return new WP_Error('zip_error', 'Unable to create zip file.', array('status' => 500));
    }
    foreach ($files as $f) {
        $zip->addFile($f['abs'], $f['archive']);
    }
    $zip->close();
    return $zip_path;
}

/**
 * Back up the CURRENT local state of a template's files into the standard
 * firefly_backups system (project name "template-{t}") before any apply
 * overwrites them. No-op when the template isn't installed yet.
 */
function firefly_projects_ts_backup_current($template, $mode, $env_label) {
    $files = firefly_projects_ts_collect_files($template);
    if (empty($files)) {
        return null;
    }
    $zip_path = firefly_projects_ts_build_zip($template, $files, '-backup');
    if (is_wp_error($zip_path)) {
        return null;
    }
    $backup = firefly_collective_add_backup('template-' . $template, $zip_path, $mode, count($files), count($files), $env_label);
    @unlink($zip_path);
    return $backup;
}

/**
 * Apply a template-files zip to this installation. THE ONLY function that
 * writes or deletes template files — used by both receive-files (push) and
 * pull-files (pull).
 *
 * DELIBERATELY NOT firefly_collective_sync_unzipped(): that function's
 * mirror scope is the top-level dir (themes/firefly-collective!), which
 * would delete every other template and the framework models. This apply is
 * scoped to exactly the template's own three paths.
 *
 * @param string     $zip_path             Local zip file
 * @param string     $template             Validated template name
 * @param string     $mode                 'safe' | 'mirror'
 * @param array|null $paths_manifest       Full archive-path list (mirror deletes extras not in it)
 * @param array|null $templates_json_entry Model list for templates.json merge
 * @param bool       $do_backup            Back up current state first
 * @param string     $backup_label         Env label recorded on the backup
 * @return array|WP_Error
 */
function firefly_projects_ts_apply_zip($zip_path, $template, $mode, $paths_manifest, $templates_json_entry, $do_backup, $backup_label = 'incoming') {
    $scopes = firefly_projects_ts_scopes($template);

    if ($do_backup) {
        firefly_projects_ts_backup_current($template, $mode, $backup_label);
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return new WP_Error('zip_open_failed', 'Could not open the template zip.', array('status' => 500));
    }

    $content_real = realpath(WP_CONTENT_DIR);
    // Resolved roots of the template's own scopes. Writes must land inside one
    // of these even after realpath — so a symlink planted inside the template
    // tree can't redirect a write to another template or the framework. Create
    // the scope bases first so realpath() resolves them on a first sync (the
    // writes target these dirs anyway); an empty plugin/theme scope dir is
    // harmless.
    foreach (array($scopes['theme_dir'], $scopes['plugin_dir'], dirname($scopes['schema'])) as $scope_base) {
        $abs_base = WP_CONTENT_DIR . '/' . $scope_base;
        if (!is_dir($abs_base)) {
            wp_mkdir_p($abs_base);
        }
    }
    $scope_roots = array_values(array_filter(array(
        realpath(WP_CONTENT_DIR . '/' . $scopes['theme_dir']),
        realpath(WP_CONTENT_DIR . '/' . $scopes['plugin_dir']),
        realpath(dirname(WP_CONTENT_DIR . '/' . $scopes['schema'])),
    )));
    $written = 0;
    $skipped = array();

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || substr($name, -1) === '/') {
            continue;
        }
        $name = str_replace('\\', '/', $name);
        if (!firefly_projects_ts_path_in_scope($name, $scopes)) {
            $skipped[] = $name;
            continue;
        }

        $dest = WP_CONTENT_DIR . '/' . $name;
        $dest_dir = dirname($dest);
        if (!file_exists($dest_dir)) {
            wp_mkdir_p($dest_dir);
        }
        // Containment: the resolved parent must stay inside wp-content. The
        // prefix check above already pins it to the template's scopes; this
        // catches symlink tricks.
        $parent_real = realpath($dest_dir);
        // Trailing separator so "wp-content" can't prefix-match "wp-content-x".
        if (!$parent_real || strpos($parent_real . '/', $content_real . '/') !== 0) {
            $skipped[] = $name;
            continue;
        }
        // And the resolved parent must sit inside one of the template's own
        // scope roots (symlink containment). When no root has materialized yet
        // the earlier ts_path_in_scope() prefix check is the guard.
        if (!empty($scope_roots)) {
            $in_scope = false;
            foreach ($scope_roots as $root) {
                if (strpos($parent_real . '/', $root . '/') === 0) {
                    $in_scope = true;
                    break;
                }
            }
            if (!$in_scope) {
                $skipped[] = $name;
                continue;
            }
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $skipped[] = $name;
            continue;
        }
        if (file_put_contents($parent_real . '/' . basename($dest), $content) !== false) {
            $written++;
        } else {
            $skipped[] = $name;
        }
    }
    $zip->close();

    // Mirror: delete local files inside the template dirs that the source
    // doesn't have. Scope is ONLY the two template dirs — never the schema
    // dir, never other templates, never framework files.
    $deleted = 0;
    if ($mode === 'mirror' && is_array($paths_manifest)) {
        $keep = array_flip($paths_manifest);
        foreach (array($scopes['theme_dir'], $scopes['plugin_dir']) as $scope) {
            $abs_dir = WP_CONTENT_DIR . '/' . $scope;
            if (!is_dir($abs_dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($abs_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $abs = $file->getRealPath();
                $rel = $scope . '/' . str_replace('\\', '/', substr($abs, strlen(realpath($abs_dir)) + 1));
                if (!isset($keep[$rel]) && strpos($abs, $content_real . '/') === 0) {
                    if (@unlink($abs)) {
                        $deleted++;
                    }
                }
            }
        }
    }

    if (is_array($templates_json_entry)) {
        firefly_projects_ts_merge_templates_json_entry($template, $templates_json_entry);
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    return array(
        'success'       => true,
        'files_written' => $written,
        'files_deleted' => $deleted,
        'skipped'       => $skipped,
    );
}

// =============================================================================
// TEMPLATE FILES — endpoints
// =============================================================================

/**
 * POST /template-sync/push-files (admin) — package + backup + send.
 * Splits into sequential multipart parts when the payload would exceed
 * FIREFLY_TS_PART_BYTES; mirror deletion happens on the final part only.
 */
function firefly_projects_ts_push_files($request) {
    $template   = $request->get_param('template');
    $target_env = $request->get_param('target_env') === 'prod' ? 'prod' : 'dev';
    $mode       = $request->get_param('mode') === 'mirror' ? 'mirror' : 'safe';

    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }

    $base = firefly_projects_ts_remote_base($target_env);
    if (is_wp_error($base)) {
        return new WP_REST_Response(array('success' => false, 'message' => $base->get_error_message()), 400);
    }

    $files = firefly_projects_ts_collect_files($template);
    if (empty($files)) {
        return new WP_REST_Response(array('success' => false, 'message' => "Template '{$template}' has no files on this site."), 404);
    }

    set_time_limit(0);

    // Partition greedily into parts under the size threshold.
    $parts = array(array());
    $part_bytes = 0;
    foreach ($files as $f) {
        if ($part_bytes > 0 && ($part_bytes + $f['size']) > FIREFLY_TS_PART_BYTES) {
            $parts[] = array();
            $part_bytes = 0;
        }
        $parts[count($parts) - 1][] = $f;
        $part_bytes += $f['size'];
    }

    $paths_manifest = array_map(function ($f) { return $f['archive']; }, $files);
    $entry = firefly_projects_ts_templates_json_entry($template);
    $batch_token = wp_generate_password(20, false);
    $of = count($parts);
    $url = $base . '/wp-json/firefly-plugin/v1/template-sync/receive-files';

    $total_written = 0;
    $total_deleted = 0;

    foreach ($parts as $idx => $part_files) {
        $part_no = $idx + 1;
        $zip_path = firefly_projects_ts_build_zip($template, $part_files, '-part' . $part_no);
        if (is_wp_error($zip_path)) {
            return new WP_REST_Response(array('success' => false, 'message' => $zip_path->get_error_message()), 500);
        }

        // Local push history — same backup system as the Projects file sync,
        // so a bad push can be rolled back from the Projects UI.
        if ($part_no === 1) {
            firefly_collective_add_backup('template-' . $template, $zip_path, $mode, count($files), count($files), $target_env);
        }

        $fields = array(
            'template'    => $template,
            'mode'        => $mode,
            'part'        => (string) $part_no,
            'of'          => (string) $of,
            'batch_token' => $batch_token,
            'file'        => new CURLFile($zip_path, 'application/zip', basename($zip_path)),
        );
        if ($part_no === $of) {
            $fields['paths_manifest'] = wp_json_encode($paths_manifest);
            if (is_array($entry)) {
                $fields['templates_json_entry'] = wp_json_encode($entry);
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Firefly-Secret: ' . FIREFLY_SHARED_SECRET));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $body = curl_exec($ch);
        $curl_err = curl_errno($ch) ? curl_error($ch) : '';
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($zip_path);

        if ($curl_err !== '') {
            return new WP_REST_Response(array('success' => false, 'message' => "Part {$part_no}/{$of} failed: {$curl_err}"), 500);
        }
        $data = json_decode($body, true);
        if ($http_code === 404) {
            $env_label = ($target_env === 'prod') ? 'Production' : 'Live Dev';
            return new WP_REST_Response(array('success' => false, 'message' => "The firefly-projects plugin on {$env_label} is older than this one and doesn't have Template Sync yet. Push the plugin there first, then retry."), 404);
        }
        if ($http_code < 200 || $http_code >= 300 || !is_array($data) || empty($data['success'])) {
            $msg = is_array($data) && isset($data['message']) ? $data['message'] : 'HTTP ' . $http_code;
            return new WP_REST_Response(array('success' => false, 'message' => "Part {$part_no}/{$of} rejected by remote: {$msg}"), 500);
        }
        $total_written += isset($data['files_written']) ? (int) $data['files_written'] : 0;
        $total_deleted += isset($data['files_deleted']) ? (int) $data['files_deleted'] : 0;
    }

    firefly_collective_cleanup_temp_dir();

    return new WP_REST_Response(array(
        'success'       => true,
        'message'       => sprintf('Template files pushed (%d file%s in %d part%s).', count($files), count($files) === 1 ? '' : 's', $of, $of === 1 ? '' : 's'),
        'files_total'   => count($files),
        'files_written' => $total_written,
        'files_deleted' => $total_deleted,
        'parts'         => $of,
    ), 200);
}

/**
 * POST /template-sync/receive-files (shared secret) — apply one pushed part.
 */
function firefly_projects_ts_receive_files($request) {
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $template = $request->get_param('template');
    $mode     = $request->get_param('mode') === 'mirror' ? 'mirror' : 'safe';
    $part     = max(1, (int) $request->get_param('part'));
    $of       = max(1, (int) $request->get_param('of'));

    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }

    $files = $request->get_file_params();
    if (empty($files['file']) || $files['file']['error'] !== UPLOAD_ERR_OK) {
        return new WP_REST_Response(array('success' => false, 'message' => 'No zip uploaded.'), 400);
    }

    $zip_path = firefly_projects_ts_temp_dir() . '/receive-' . sanitize_title($template) . '-' . time() . '.zip';
    if (!move_uploaded_file($files['file']['tmp_name'], $zip_path)) {
        // Direct copy fallback ONLY for in-process/test invocations, where the
        // temp file legitimately isn't a PHP SAPI upload. A real HTTP request
        // must go through move_uploaded_file — never trust an is_uploaded_file
        // -failing path over the network.
        if (is_uploaded_file($files['file']['tmp_name']) || !@copy($files['file']['tmp_name'], $zip_path)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Could not store uploaded zip.'), 500);
        }
    }

    $is_final = ($part >= $of);
    $paths_manifest = null;
    $entry = null;
    if ($is_final) {
        $manifest_json = $request->get_param('paths_manifest');
        if (is_string($manifest_json) && $manifest_json !== '') {
            $decoded = json_decode(wp_unslash($manifest_json), true);
            if (is_array($decoded)) $paths_manifest = $decoded;
        }
        $entry_json = $request->get_param('templates_json_entry');
        if (is_string($entry_json) && $entry_json !== '') {
            $decoded = json_decode(wp_unslash($entry_json), true);
            if (is_array($decoded)) $entry = $decoded;
        }
    }

    $result = firefly_projects_ts_apply_zip(
        $zip_path,
        $template,
        $is_final ? $mode : 'safe',        // mirror deletion only once the full manifest is here
        $paths_manifest,
        $entry,
        $part === 1                         // back up receiver state before the first bytes land
    );
    @unlink($zip_path);

    if (is_wp_error($result)) {
        return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), 500);
    }

    return new WP_REST_Response(array(
        'success'       => true,
        'files_written' => $result['files_written'],
        'files_deleted' => $result['files_deleted'],
        'part'          => $part,
        'of'            => $of,
    ), 200);
}

/**
 * GET /template-sync/export-files (shared secret) — token + chunk flow.
 * First call (no token) builds the zip and returns its metadata; subsequent
 * calls stream base64 chunks. The temp zip is TTL-cleaned like every other
 * firefly_collective_temp artifact.
 */
function firefly_projects_ts_export_files($request) {
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }

    $token = $request->get_param('token');

    if (empty($token)) {
        $files = firefly_projects_ts_collect_files($template);
        if (empty($files)) {
            return new WP_REST_Response(array('success' => false, 'message' => "Template '{$template}' has no files on this site."), 404);
        }
        $token = strtolower(wp_generate_password(24, false));
        $zip_path = firefly_projects_ts_temp_dir() . '/ts-export-' . $token . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Unable to create export zip.'), 500);
        }
        foreach ($files as $f) {
            $zip->addFile($f['abs'], $f['archive']);
        }
        $zip->close();

        $size = filesize($zip_path);
        return new WP_REST_Response(array(
            'success'              => true,
            'token'                => $token,
            'total_size'           => $size,
            'chunk_size'           => FIREFLY_TS_CHUNK_BYTES,
            'chunk_count'          => max(1, (int) ceil($size / FIREFLY_TS_CHUNK_BYTES)),
            'files_total'          => count($files),
            'paths_manifest'       => array_map(function ($f) { return $f['archive']; }, $files),
            'templates_json_entry' => firefly_projects_ts_templates_json_entry($template),
        ), 200);
    }

    if (!preg_match('/^[a-z0-9]+$/', $token)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid token.'), 400);
    }
    $zip_path = firefly_projects_ts_temp_dir() . '/ts-export-' . $token . '.zip';
    if (!file_exists($zip_path)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Export token expired — restart the pull.'), 410);
    }

    $chunk = max(0, (int) $request->get_param('chunk'));
    $size  = filesize($zip_path);
    $offset = $chunk * FIREFLY_TS_CHUNK_BYTES;
    if ($offset >= $size) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Chunk out of range.'), 400);
    }

    $fh = fopen($zip_path, 'rb');
    fseek($fh, $offset);
    $bytes = fread($fh, FIREFLY_TS_CHUNK_BYTES);
    fclose($fh);

    $is_last = ($offset + strlen($bytes)) >= $size;
    if ($is_last) {
        @unlink($zip_path);
    }

    return new WP_REST_Response(array(
        'success' => true,
        'chunk'   => $chunk,
        'is_last' => $is_last,
        'data'    => base64_encode($bytes),
    ), 200);
}

/**
 * POST /template-sync/pull-files (admin) — fetch the remote's template files
 * and apply them locally. This is the step that INSTALLS a template on a
 * fresh machine.
 */
function firefly_projects_ts_pull_files($request) {
    $template   = $request->get_param('template');
    $source_env = $request->get_param('source_env') === 'prod' ? 'prod' : 'dev';
    $mode       = $request->get_param('mode') === 'mirror' ? 'mirror' : 'safe';

    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }

    set_time_limit(0);

    $meta = firefly_projects_ts_remote_request($source_env, 'GET', 'export-files?template=' . rawurlencode($template));
    if (is_wp_error($meta)) {
        return new WP_REST_Response(array('success' => false, 'message' => $meta->get_error_message()), $meta->get_error_data()['status'] ?? 500);
    }
    if (empty($meta['success'])) {
        return new WP_REST_Response(array('success' => false, 'message' => isset($meta['message']) ? $meta['message'] : 'Remote export failed.'), 500);
    }

    $zip_path = firefly_projects_ts_temp_dir() . '/ts-pull-' . sanitize_title($template) . '-' . time() . '.zip';
    $fh = fopen($zip_path, 'wb');
    for ($chunk = 0; $chunk < (int) $meta['chunk_count']; $chunk++) {
        $piece = firefly_projects_ts_remote_request(
            $source_env,
            'GET',
            'export-files?template=' . rawurlencode($template) . '&token=' . rawurlencode($meta['token']) . '&chunk=' . $chunk
        );
        if (is_wp_error($piece) || empty($piece['success'])) {
            fclose($fh);
            @unlink($zip_path);
            $msg = is_wp_error($piece) ? $piece->get_error_message() : (isset($piece['message']) ? $piece['message'] : 'Chunk fetch failed.');
            return new WP_REST_Response(array('success' => false, 'message' => "Pull failed at chunk {$chunk}: {$msg}"), 500);
        }
        fwrite($fh, base64_decode($piece['data']));
    }
    fclose($fh);

    if (filesize($zip_path) !== (int) $meta['total_size']) {
        @unlink($zip_path);
        return new WP_REST_Response(array('success' => false, 'message' => 'Downloaded zip size mismatch — retry the pull.'), 500);
    }

    $result = firefly_projects_ts_apply_zip(
        $zip_path,
        $template,
        $mode,
        isset($meta['paths_manifest']) && is_array($meta['paths_manifest']) ? $meta['paths_manifest'] : null,
        isset($meta['templates_json_entry']) && is_array($meta['templates_json_entry']) ? $meta['templates_json_entry'] : null,
        true,
        $source_env
    );
    @unlink($zip_path);
    firefly_collective_cleanup_temp_dir();

    if (is_wp_error($result)) {
        return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), 500);
    }

    return new WP_REST_Response(array(
        'success'       => true,
        'message'       => sprintf('Template files pulled (%d written%s).', $result['files_written'], $result['files_deleted'] ? ', ' . $result['files_deleted'] . ' orphans removed' : ''),
        'files_written' => $result['files_written'],
        'files_deleted' => $result['files_deleted'],
    ), 200);
}

// =============================================================================
// MANIFEST + TEMPLATE LISTING
// =============================================================================

/**
 * Everything the pipeline planner needs to know about one template on this
 * site: content ids, menu, media/category/settings counts, file totals.
 */
function firefly_projects_ts_build_manifest($template) {
    $manifest = array(
        'template'  => $template,
        'installed' => function_exists('firefly_is_valid_template') ? firefly_is_valid_template($template) : false,
        'pages'     => array(),
        'posts'     => array(),
    );

    foreach (array('page', 'post') as $type) {
        $items = get_posts(array(
            'post_type'            => $type,
            'post_status'          => 'publish',
            'numberposts'          => -1,
            'orderby'              => 'menu_order',
            'order'                => 'ASC',
            'meta_key'             => '_firefly_template',
            'meta_value'           => $template,
            'firefly_skip_scoping' => true,
        ));
        foreach ($items as $item) {
            $fpid = get_post_meta($item->ID, '_firefly_page_id', true);
            if (empty($fpid)) {
                $fpid = $template . ':' . $item->post_name;
            }
            $manifest[$type . 's'][] = array(
                'id'    => $item->ID,
                'slug'  => $item->post_name,
                'title' => $item->post_title,
                'firefly_page_id' => $fpid,
            );
        }
    }

    $menu_id = (int) get_option('firefly_menu_' . $template);
    $menu = $menu_id ? wp_get_nav_menu_object($menu_id) : null;
    if (!$menu && function_exists('firefly_find_template_menu_id')) {
        $menu_id = (int) firefly_find_template_menu_id($template);
        $menu = $menu_id ? wp_get_nav_menu_object($menu_id) : null;
    }
    $manifest['menu_id']   = $menu ? (int) $menu->term_id : 0;
    $manifest['menu_name'] = $menu ? $menu->name : '';

    $media = firefly_projects_ts_media_list($template, false);
    $shared = firefly_projects_ts_media_list('', true);
    $manifest['media_count'] = count($media);
    $manifest['shared_media_count'] = count($shared);

    $categories = get_terms(array(
        'taxonomy'   => 'category',
        'hide_empty' => false,
        'meta_query' => array(array('key' => '_firefly_template', 'value' => $template)),
    ));
    $manifest['categories_count'] = is_array($categories) ? count($categories) : 0;

    $manifest['options_count'] = function_exists('firefly_get_template_options')
        ? count(firefly_get_template_options($template))
        : 0;

    $files = firefly_projects_ts_collect_files($template);
    $manifest['files_count'] = count($files);
    $manifest['files_bytes'] = array_sum(array_map(function ($f) { return $f['size']; }, $files));
    $manifest['schema_exists'] = file_exists(WP_CONTENT_DIR . '/' . firefly_projects_ts_scopes($template)['schema']);

    return $manifest;
}

/**
 * GET /template-sync/manifest — dual auth (local admin planner AND remote twin).
 */
function firefly_projects_ts_manifest($request) {
    $auth_failure = firefly_projects_ts_dual_auth($request);
    if ($auth_failure !== null) return $auth_failure;

    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }

    return new WP_REST_Response(array('success' => true, 'manifest' => firefly_projects_ts_build_manifest($template)), 200);
}

/**
 * GET /template-sync/list-templates (shared secret) — what templates this
 * site has (dir-installed ∪ schema metadata).
 */
function firefly_projects_ts_list_templates($request) {
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/template-schema-sync.php';
    $by_name = array();
    foreach (firefly_projects_get_available_templates() as $t) {
        $by_name[$t['name']] = $t;
    }
    if (function_exists('firefly_get_valid_templates')) {
        foreach (firefly_get_valid_templates() as $name) {
            if (!isset($by_name[$name])) {
                $by_name[$name] = array('name' => $name, 'display_name' => $name, 'version' => '', 'description' => '', 'pages_count' => 0, 'posts_count' => 0);
            }
        }
    }

    // Full templates.json map (template → plugin model list). Lets a fresh
    // machine seed its own templates.json from the remote's live truth
    // instead of a static stub (firefly local does this via the CLI).
    $templates_json = array();
    $tj_path = WP_CONTENT_DIR . '/plugins/firefly-collective/templates.json';
    if (file_exists($tj_path)) {
        $decoded = json_decode(file_get_contents($tj_path), true);
        if (is_array($decoded)) {
            $templates_json = $decoded;
        }
    }

    return new WP_REST_Response(array(
        'success'         => true,
        'templates'       => array_values($by_name),
        'active_template' => function_exists('firefly_get_scoping_template') ? firefly_get_scoping_template() : '',
        'templates_json'  => $templates_json,
    ), 200);
}

/**
 * GET /template-sync/remote-templates (admin) — proxy for the pull selector.
 */
function firefly_projects_ts_remote_templates($request) {
    $env = $request->get_param('source_env') === 'prod' ? 'prod' : 'dev';
    $data = firefly_projects_ts_remote_request($env, 'GET', 'list-templates');
    if (is_wp_error($data)) {
        return new WP_REST_Response(array('success' => false, 'message' => $data->get_error_message()), $data->get_error_data()['status'] ?? 500);
    }
    return new WP_REST_Response($data, 200);
}

/**
 * GET /template-sync/remote-manifest (admin) — proxy for the pull planner.
 */
function firefly_projects_ts_remote_manifest($request) {
    $env = $request->get_param('source_env') === 'prod' ? 'prod' : 'dev';
    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    $data = firefly_projects_ts_remote_request($env, 'GET', 'manifest?template=' . rawurlencode($template));
    if (is_wp_error($data)) {
        return new WP_REST_Response(array('success' => false, 'message' => $data->get_error_message()), $data->get_error_data()['status'] ?? 500);
    }
    return new WP_REST_Response($data, 200);
}

// =============================================================================
// MEDIA
// =============================================================================

/**
 * List this site's attachments for a template.
 *
 * @param string $template       Template to list (tagged attachments); pass ''
 *                               with $untagged_only=true for the shared set.
 * @param bool   $untagged_only  List attachments with NO template tag instead.
 */
function firefly_projects_ts_media_list($template, $untagged_only = false) {
    $meta_query = $untagged_only
        ? array(array('key' => '_firefly_template', 'compare' => 'NOT EXISTS'))
        : array(array('key' => '_firefly_template', 'value' => $template));

    $attachments = get_posts(array(
        'post_type'            => 'attachment',
        'post_status'          => 'inherit',
        'numberposts'          => -1,
        'meta_query'           => $meta_query,
        'firefly_skip_scoping' => true,
    ));

    $upload_dir = wp_upload_dir();
    $basedir = $upload_dir['basedir'];
    $items = array();

    foreach ($attachments as $att) {
        $rel = get_post_meta($att->ID, '_wp_attached_file', true);
        if (empty($rel)) {
            continue;
        }
        // uploads/pages/{slug}/ files are page-sync territory (they travel
        // with their page); backups/temp should never be attachments but
        // guard anyway.
        if (strpos($rel, 'pages/') === 0 || strpos($rel, 'firefly_backups/') === 0 || strpos($rel, 'firefly_collective_temp/') === 0) {
            continue;
        }
        $abs = $basedir . '/' . $rel;
        if (!file_exists($abs)) {
            continue;
        }
        $items[] = array(
            'id'       => $att->ID,
            'rel_path' => $rel,
            'size'     => filesize($abs),
            'mime'     => $att->post_mime_type,
            'title'    => $att->post_title,
            'alt'      => get_post_meta($att->ID, '_wp_attachment_image_alt', true),
            'template' => $untagged_only ? '' : $template,
        );
    }

    return $items;
}

/**
 * GET /template-sync/list-media (shared secret).
 */
function firefly_projects_ts_list_media($request) {
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    $include_shared = filter_var($request->get_param('include_shared'), FILTER_VALIDATE_BOOLEAN);

    $items = firefly_projects_ts_media_list($template, false);
    if ($include_shared) {
        $items = array_merge($items, firefly_projects_ts_media_list('', true));
    }

    return new WP_REST_Response(array('success' => true, 'items' => $items, 'count' => count($items)), 200);
}

/**
 * POST /template-sync/media-diff (admin) — plan the media sweep.
 * Returns rel_paths to transfer (missing or size-different on the
 * destination) and destination orphans (TAGGED only — shared media is never
 * a mirror-deletion candidate).
 */
function firefly_projects_ts_media_diff($request) {
    $template  = $request->get_param('template');
    $env       = $request->get_param('env') === 'prod' ? 'prod' : 'dev';
    $direction = $request->get_param('direction') === 'pull' ? 'pull' : 'push';
    $include_shared = filter_var($request->get_param('include_shared'), FILTER_VALIDATE_BOOLEAN);

    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }

    $remote = firefly_projects_ts_remote_request($env, 'GET', 'list-media?template=' . rawurlencode($template) . ($include_shared ? '&include_shared=1' : ''));
    if (is_wp_error($remote)) {
        return new WP_REST_Response(array('success' => false, 'message' => $remote->get_error_message()), $remote->get_error_data()['status'] ?? 500);
    }
    $remote_items = isset($remote['items']) && is_array($remote['items']) ? $remote['items'] : array();

    $local_items = firefly_projects_ts_media_list($template, false);
    if ($include_shared) {
        $local_items = array_merge($local_items, firefly_projects_ts_media_list('', true));
    }

    $key = function ($items) {
        $out = array();
        foreach ($items as $i) { $out[$i['rel_path']] = $i; }
        return $out;
    };
    $local_by_path  = $key($local_items);
    $remote_by_path = $key($remote_items);

    $source = ($direction === 'push') ? $local_by_path : $remote_by_path;
    $dest   = ($direction === 'push') ? $remote_by_path : $local_by_path;

    $to_transfer = array();
    foreach ($source as $path => $item) {
        if (!isset($dest[$path]) || (int) $dest[$path]['size'] !== (int) $item['size']) {
            $to_transfer[] = $item;
        }
    }

    // Orphans: tagged items on the destination the source doesn't have.
    $orphans = array();
    foreach ($dest as $path => $item) {
        if (!empty($item['template']) && !isset($source[$path])) {
            $orphans[] = $path;
        }
    }

    return new WP_REST_Response(array(
        'success'      => true,
        'to_transfer'  => $to_transfer,
        'orphans'      => $orphans,
        'source_count' => count($source),
        'dest_count'   => count($dest),
    ), 200);
}

/**
 * Build the transfer payload for one local attachment.
 */
function firefly_projects_ts_media_item_payload($attachment_id) {
    $att = get_post($attachment_id);
    if (!$att || $att->post_type !== 'attachment') {
        return new WP_Error('not_found', 'Attachment not found.', array('status' => 404));
    }
    $rel = get_post_meta($att->ID, '_wp_attached_file', true);
    $upload_dir = wp_upload_dir();
    $abs = $upload_dir['basedir'] . '/' . $rel;
    if (empty($rel) || !file_exists($abs)) {
        return new WP_Error('file_missing', 'Attachment file missing on disk: ' . $rel, array('status' => 404));
    }
    return array(
        'rel_path' => $rel,
        'content'  => base64_encode(file_get_contents($abs)),
        'mime'     => $att->post_mime_type,
        'title'    => $att->post_title,
        'alt'      => get_post_meta($att->ID, '_wp_attachment_image_alt', true),
        'template' => get_post_meta($att->ID, '_firefly_template', true),
    );
}

/**
 * Apply one media item to THIS site (shared by receive + pull). Writes the
 * file at its exact uploads-relative location and creates/updates the
 * attachment record so the media library and template scoping see it.
 * Content arrives as base64 in $payload['content'] OR as an already-decoded
 * local temp file in $payload['content_path'] (the chunked large-file path —
 * avoids ever holding the whole file in memory).
 */
function firefly_projects_ts_apply_media_item($payload) {
    $rel = isset($payload['rel_path']) ? str_replace('\\', '/', (string) $payload['rel_path']) : '';
    if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, '/') === 0 || strpos($rel, "\0") !== false) {
        return new WP_Error('bad_path', 'Invalid media path.', array('status' => 400));
    }

    // Only allow real media/document types into uploads. wp_check_filetype
    // rejects anything outside WP's allowed upload mime map (.php, .phtml,
    // .htaccess, etc.), so a peer can't drop executable code into a directory
    // that may be served with PHP enabled.
    $ft = wp_check_filetype(basename($rel));
    if (empty($ft['ext']) || empty($ft['type'])) {
        return new WP_Error('bad_type', 'Media file type not permitted: ' . basename($rel), array('status' => 400));
    }

    $upload_dir = wp_upload_dir();
    $basedir_real = realpath($upload_dir['basedir']);
    $dest = $upload_dir['basedir'] . '/' . $rel;
    $dest_dir = dirname($dest);
    if (!file_exists($dest_dir)) {
        wp_mkdir_p($dest_dir);
    }
    $parent_real = realpath($dest_dir);
    if (!$parent_real || strpos($parent_real . '/', $basedir_real . '/') !== 0) {
        return new WP_Error('bad_path', 'Media path escapes the uploads directory.', array('status' => 400));
    }

    if (!empty($payload['content_path'])) {
        if (!@rename($payload['content_path'], $parent_real . '/' . basename($dest))) {
            if (!@copy($payload['content_path'], $parent_real . '/' . basename($dest))) {
                return new WP_Error('write_failed', 'Could not move media file into place.', array('status' => 500));
            }
            @unlink($payload['content_path']);
        }
    } else {
        $bytes = base64_decode(isset($payload['content']) ? $payload['content'] : '', true);
        if ($bytes === false) {
            return new WP_Error('bad_content', 'Invalid file content.', array('status' => 400));
        }
        if (file_put_contents($parent_real . '/' . basename($dest), $bytes) === false) {
            return new WP_Error('write_failed', 'Could not write media file.', array('status' => 500));
        }
    }

    // Existing attachment at this path? Update in place; else create.
    $existing = get_posts(array(
        'post_type'            => 'attachment',
        'post_status'          => 'inherit',
        'numberposts'          => 1,
        'meta_key'             => '_wp_attached_file',
        'meta_value'           => $rel,
        'firefly_skip_scoping' => true,
    ));

    require_once ABSPATH . 'wp-admin/includes/image.php';

    if (!empty($existing)) {
        $att_id = $existing[0]->ID;
        wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $dest));
        $created = false;
    } else {
        $att_id = wp_insert_attachment(array(
            'post_mime_type' => isset($payload['mime']) ? sanitize_text_field($payload['mime']) : 'application/octet-stream',
            'post_title'     => !empty($payload['title']) ? sanitize_text_field($payload['title']) : pathinfo($rel, PATHINFO_FILENAME),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ), $dest);
        if (is_wp_error($att_id)) {
            return $att_id;
        }
        wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $dest));
        $created = true;
    }

    if (!empty($payload['alt'])) {
        update_post_meta($att_id, '_wp_attachment_image_alt', sanitize_text_field($payload['alt']));
    }
    if (!empty($payload['template']) && firefly_projects_ts_valid_name($payload['template'])) {
        update_post_meta($att_id, '_firefly_template', $payload['template']);
    }

    return array('success' => true, 'attachment_id' => $att_id, 'created' => $created, 'rel_path' => $rel);
}

/**
 * POST /template-sync/receive-media-item (shared secret).
 * Small files arrive whole (content = base64). Large files arrive as
 * sequential parts sharing a transfer_id — each part is appended to a temp
 * file; the final part moves it into place and registers the attachment.
 */
function firefly_projects_ts_receive_media_item($request) {
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $part = (int) $request->get_param('part');
    $of   = (int) $request->get_param('of');

    if ($part > 0 && $of > 0) {
        $transfer_id = (string) $request->get_param('transfer_id');
        if (!preg_match('/^[a-z0-9]{8,64}$/', $transfer_id)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid transfer id.'), 400);
        }
        $tmp = firefly_projects_ts_temp_dir() . '/media-' . $transfer_id . '.part';
        $bytes = base64_decode((string) $request->get_param('content'), true);
        if ($bytes === false) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid part content.'), 400);
        }
        if (file_put_contents($tmp, $bytes, $part === 1 ? 0 : FILE_APPEND) === false) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Could not buffer media part.'), 500);
        }
        if ($part < $of) {
            return new WP_REST_Response(array('success' => true, 'part' => $part, 'of' => $of), 200);
        }
        $result = firefly_projects_ts_apply_media_item(array(
            'rel_path'     => $request->get_param('rel_path'),
            'content_path' => $tmp,
            'mime'         => $request->get_param('mime'),
            'title'        => $request->get_param('title'),
            'alt'          => $request->get_param('alt'),
            'template'     => $request->get_param('template'),
        ));
        @unlink($tmp);
    } else {
        $result = firefly_projects_ts_apply_media_item(array(
            'rel_path' => $request->get_param('rel_path'),
            'content'  => $request->get_param('content'),
            'mime'     => $request->get_param('mime'),
            'title'    => $request->get_param('title'),
            'alt'      => $request->get_param('alt'),
            'template' => $request->get_param('template'),
        ));
    }

    if (is_wp_error($result)) {
        return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), $result->get_error_data()['status'] ?? 500);
    }
    return new WP_REST_Response($result, 200);
}

/**
 * GET /template-sync/export-media-item (shared secret).
 * Small files: whole item payload (base64). Large files: without `chunk` a
 * chunked:true descriptor is returned; with `chunk=N` the base64 slice is
 * streamed via fseek/fread so the whole file never sits in memory (an 80MB
 * recording base64'd into one JSON blew the PHP memory limit).
 */
function firefly_projects_ts_export_media_item($request) {
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $rel = (string) $request->get_param('rel_path');
    $existing = get_posts(array(
        'post_type'            => 'attachment',
        'post_status'          => 'inherit',
        'numberposts'          => 1,
        'meta_key'             => '_wp_attached_file',
        'meta_value'           => $rel,
        'firefly_skip_scoping' => true,
    ));
    if (empty($existing)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Attachment not found: ' . $rel), 404);
    }
    $att = $existing[0];
    $upload_dir = wp_upload_dir();
    $abs = $upload_dir['basedir'] . '/' . get_post_meta($att->ID, '_wp_attached_file', true);
    if (!file_exists($abs)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Attachment file missing on disk: ' . $rel), 404);
    }
    $size = filesize($abs);

    $chunk = $request->get_param('chunk');
    if ($chunk !== null && $chunk !== '') {
        $chunk = max(0, (int) $chunk);
        $offset = $chunk * FIREFLY_TS_CHUNK_BYTES;
        if ($offset >= $size) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Chunk out of range.'), 400);
        }
        $fh = fopen($abs, 'rb');
        fseek($fh, $offset);
        $bytes = fread($fh, FIREFLY_TS_CHUNK_BYTES);
        fclose($fh);
        return new WP_REST_Response(array(
            'success' => true,
            'chunk'   => $chunk,
            'is_last' => ($offset + strlen($bytes)) >= $size,
            'data'    => base64_encode($bytes),
        ), 200);
    }

    if ($size > FIREFLY_TS_MEDIA_INLINE_MAX) {
        return new WP_REST_Response(array(
            'success'     => true,
            'chunked'     => true,
            'size'        => $size,
            'chunk_size'  => FIREFLY_TS_CHUNK_BYTES,
            'chunk_count' => (int) ceil($size / FIREFLY_TS_CHUNK_BYTES),
            'item_meta'   => array(
                'rel_path' => $rel,
                'mime'     => $att->post_mime_type,
                'title'    => $att->post_title,
                'alt'      => get_post_meta($att->ID, '_wp_attachment_image_alt', true),
                'template' => get_post_meta($att->ID, '_firefly_template', true),
            ),
        ), 200);
    }

    $payload = firefly_projects_ts_media_item_payload($att->ID);
    if (is_wp_error($payload)) {
        return new WP_REST_Response(array('success' => false, 'message' => $payload->get_error_message()), 404);
    }
    return new WP_REST_Response(array('success' => true, 'item' => $payload), 200);
}

/**
 * POST /template-sync/push-media-item (admin) — one attachment to remote.
 * Files above the inline cap are streamed as sequential parts so neither
 * side ever holds the whole file in memory.
 */
function firefly_projects_ts_push_media_item($request) {
    $env = $request->get_param('target_env') === 'prod' ? 'prod' : 'dev';
    $att_id = (int) $request->get_param('attachment_id');

    $att = get_post($att_id);
    $rel = $att ? get_post_meta($att_id, '_wp_attached_file', true) : '';
    $upload_dir = wp_upload_dir();
    $abs = $rel ? $upload_dir['basedir'] . '/' . $rel : '';
    if (!$att || !$rel || !file_exists($abs)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Attachment not found or file missing.'), 404);
    }
    $size = filesize($abs);

    if ($size > FIREFLY_TS_MEDIA_INLINE_MAX) {
        set_time_limit(0);
        $meta = array(
            'rel_path' => $rel,
            'mime'     => $att->post_mime_type,
            'title'    => $att->post_title,
            'alt'      => get_post_meta($att_id, '_wp_attachment_image_alt', true),
            'template' => get_post_meta($att_id, '_firefly_template', true),
        );
        $transfer_id = strtolower(wp_generate_password(24, false));
        $of = (int) ceil($size / FIREFLY_TS_CHUNK_BYTES);
        $fh = fopen($abs, 'rb');
        for ($part = 1; $part <= $of; $part++) {
            $bytes = fread($fh, FIREFLY_TS_CHUNK_BYTES);
            $body = $meta;
            $body['transfer_id'] = $transfer_id;
            $body['part'] = $part;
            $body['of'] = $of;
            $body['content'] = base64_encode($bytes);
            $data = firefly_projects_ts_remote_request($env, 'POST', 'receive-media-item', $body, 180);
            if (is_wp_error($data) || empty($data['success'])) {
                fclose($fh);
                $msg = is_wp_error($data) ? $data->get_error_message() : (isset($data['message']) ? $data['message'] : 'part rejected');
                return new WP_REST_Response(array('success' => false, 'message' => "Part {$part}/{$of} of {$rel} failed: {$msg}"), 500);
            }
        }
        fclose($fh);
        return new WP_REST_Response($data, 200);
    }

    $payload = firefly_projects_ts_media_item_payload($att_id);
    if (is_wp_error($payload)) {
        return new WP_REST_Response(array('success' => false, 'message' => $payload->get_error_message()), 404);
    }
    $data = firefly_projects_ts_remote_request($env, 'POST', 'receive-media-item', $payload, 180);
    if (is_wp_error($data)) {
        return new WP_REST_Response(array('success' => false, 'message' => $data->get_error_message()), $data->get_error_data()['status'] ?? 500);
    }
    return new WP_REST_Response($data, 200);
}

/**
 * POST /template-sync/pull-media-item (admin) — one attachment from remote.
 * Transparently follows the remote's chunked descriptor for large files.
 */
function firefly_projects_ts_pull_media_item($request) {
    $env = $request->get_param('source_env') === 'prod' ? 'prod' : 'dev';
    $rel = (string) $request->get_param('rel_path');
    $data = firefly_projects_ts_remote_request($env, 'GET', 'export-media-item?rel_path=' . rawurlencode($rel), null, 180);
    if (is_wp_error($data)) {
        return new WP_REST_Response(array('success' => false, 'message' => $data->get_error_message()), $data->get_error_data()['status'] ?? 500);
    }
    if (empty($data['success'])) {
        return new WP_REST_Response(array('success' => false, 'message' => isset($data['message']) ? $data['message'] : 'Remote export failed.'), 500);
    }

    if (!empty($data['chunked'])) {
        set_time_limit(0);
        $tmp = firefly_projects_ts_temp_dir() . '/media-pull-' . strtolower(wp_generate_password(16, false)) . '.part';
        $fh = fopen($tmp, 'wb');
        for ($chunk = 0; $chunk < (int) $data['chunk_count']; $chunk++) {
            $piece = firefly_projects_ts_remote_request($env, 'GET', 'export-media-item?rel_path=' . rawurlencode($rel) . '&chunk=' . $chunk, null, 180);
            if (is_wp_error($piece) || empty($piece['success'])) {
                fclose($fh);
                @unlink($tmp);
                $msg = is_wp_error($piece) ? $piece->get_error_message() : (isset($piece['message']) ? $piece['message'] : 'chunk failed');
                return new WP_REST_Response(array('success' => false, 'message' => "Chunk {$chunk} of {$rel} failed: {$msg}"), 500);
            }
            fwrite($fh, base64_decode($piece['data']));
        }
        fclose($fh);
        if (filesize($tmp) !== (int) $data['size']) {
            @unlink($tmp);
            return new WP_REST_Response(array('success' => false, 'message' => "Size mismatch pulling {$rel} — retry."), 500);
        }
        $payload = $data['item_meta'];
        $payload['content_path'] = $tmp;
        $result = firefly_projects_ts_apply_media_item($payload);
    } else {
        if (empty($data['item'])) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Remote export returned no item.'), 500);
        }
        $result = firefly_projects_ts_apply_media_item($data['item']);
    }

    if (is_wp_error($result)) {
        return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), 500);
    }
    return new WP_REST_Response($result, 200);
}

/**
 * POST /template-sync/delete-media (shared secret) — mirror cleanup on the
 * receiving side. Hard guard: only deletes attachments whose OWN
 * _firefly_template equals the requested template. Shared/untagged media is
 * never deleted by a sync.
 */
function firefly_projects_ts_delete_media($request) {
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    $rel_paths = $request->get_param('rel_paths');
    if (!is_array($rel_paths)) {
        $rel_paths = array();
    }

    $deleted = array();
    $skipped = array();
    foreach ($rel_paths as $rel) {
        $found = get_posts(array(
            'post_type'            => 'attachment',
            'post_status'          => 'inherit',
            'numberposts'          => 1,
            'meta_key'             => '_wp_attached_file',
            'meta_value'           => (string) $rel,
            'firefly_skip_scoping' => true,
        ));
        if (empty($found)) {
            $skipped[] = $rel;
            continue;
        }
        $att = $found[0];
        if (get_post_meta($att->ID, '_firefly_template', true) !== $template) {
            $skipped[] = $rel;
            continue;
        }
        if (wp_delete_attachment($att->ID, true)) {
            $deleted[] = $rel;
        } else {
            $skipped[] = $rel;
        }
    }

    return new WP_REST_Response(array('success' => true, 'deleted' => $deleted, 'skipped' => $skipped), 200);
}

/**
 * Delete this site's attachments for the given rel_paths, but ONLY those
 * whose own _firefly_template matches. Shared/untagged media never deleted.
 */
function firefly_projects_ts_delete_local_media($template, $rel_paths) {
    $deleted = array();
    $skipped = array();
    foreach ((array) $rel_paths as $rel) {
        $found = get_posts(array(
            'post_type'            => 'attachment',
            'post_status'          => 'inherit',
            'numberposts'          => 1,
            'meta_key'             => '_wp_attached_file',
            'meta_value'           => (string) $rel,
            'firefly_skip_scoping' => true,
        ));
        if (empty($found) || get_post_meta($found[0]->ID, '_firefly_template', true) !== $template) {
            $skipped[] = $rel;
            continue;
        }
        if (wp_delete_attachment($found[0]->ID, true)) {
            $deleted[] = $rel;
        } else {
            $skipped[] = $rel;
        }
    }
    return array('success' => true, 'deleted' => $deleted, 'skipped' => $skipped);
}

/**
 * POST /template-sync/mirror-media (admin) — direction 'push' asks the
 * remote to delete its orphaned template-tagged media; 'pull' deletes the
 * LOCAL orphans (media the remote no longer has).
 */
function firefly_projects_ts_mirror_media($request) {
    $env = $request->get_param('target_env') === 'prod' ? 'prod' : 'dev';
    $direction = $request->get_param('direction') === 'pull' ? 'pull' : 'push';
    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    $rel_paths = $request->get_param('rel_paths');
    $rel_paths = is_array($rel_paths) ? $rel_paths : array();

    if ($direction === 'pull') {
        return new WP_REST_Response(firefly_projects_ts_delete_local_media($template, $rel_paths), 200);
    }

    $data = firefly_projects_ts_remote_request($env, 'POST', 'delete-media', array(
        'template'  => $template,
        'rel_paths' => $rel_paths,
    ));
    if (is_wp_error($data)) {
        return new WP_REST_Response(array('success' => false, 'message' => $data->get_error_message()), $data->get_error_data()['status'] ?? 500);
    }
    return new WP_REST_Response($data, 200);
}

// =============================================================================
// SETTINGS (Customizer options, categories + assignments, page roles, pricing)
// =============================================================================

function firefly_projects_ts_category_base_slug($slug, $template) {
    if ($template === 'default') {
        return $slug;
    }
    $suffix = '-' . $template;
    if (substr($slug, -strlen($suffix)) === $suffix) {
        return substr($slug, 0, -strlen($suffix));
    }
    return $slug;
}

function firefly_projects_ts_category_scoped_slug($base_slug, $template) {
    return $template === 'default' ? $base_slug : $base_slug . '-' . $template;
}

/**
 * Build the settings payload for a template on this site. IDs never travel —
 * page roles ship as slugs and category assignments as base slugs keyed by
 * the cross-env firefly_page_id.
 */
function firefly_projects_ts_build_settings($template) {
    $payload = array(
        'template'    => $template,
        'options'     => array(),
        'roles'       => array('front_page' => '', 'posts_page' => ''),
        'categories'  => array(),
        'assignments' => array(),
    );

    if (function_exists('firefly_get_template_options') && function_exists('firefly_get_template_option')) {
        foreach (array_keys(firefly_get_template_options($template)) as $key) {
            $payload['options'][$key] = firefly_get_template_option($key, false, $template);
        }
    }

    foreach (array('front_page', 'posts_page') as $role) {
        $post_id = (int) get_option("firefly_{$role}_{$template}");
        if ($post_id) {
            $slug = get_post_field('post_name', $post_id);
            if ($slug) {
                $payload['roles'][$role] = $slug;
            }
        }
    }

    $terms = get_terms(array(
        'taxonomy'   => 'category',
        'hide_empty' => false,
        'meta_query' => array(array('key' => '_firefly_template', 'value' => $template)),
    ));
    $term_base_by_id = array();
    if (is_array($terms)) {
        foreach ($terms as $term) {
            $base = firefly_projects_ts_category_base_slug($term->slug, $template);
            $term_base_by_id[$term->term_id] = $base;
            $payload['categories'][] = array(
                'name'        => $term->name,
                'base_slug'   => $base,
                'description' => $term->description,
            );
        }
    }

    $posts = get_posts(array(
        'post_type'            => 'post',
        'post_status'          => 'publish',
        'numberposts'          => -1,
        'meta_key'             => '_firefly_template',
        'meta_value'           => $template,
        'firefly_skip_scoping' => true,
    ));
    foreach ($posts as $post) {
        $fpid = get_post_meta($post->ID, '_firefly_page_id', true);
        if (empty($fpid)) {
            $fpid = $template . ':' . $post->post_name;
        }
        $cat_ids = wp_get_post_categories($post->ID);
        $bases = array();
        foreach ($cat_ids as $cid) {
            if (isset($term_base_by_id[$cid])) {
                $bases[] = $term_base_by_id[$cid];
            }
        }
        if (!empty($bases)) {
            $payload['assignments'][$fpid] = $bases;
        }
    }

    return $payload;
}

/**
 * Apply a settings payload to this site (shared by receive + pull). Runs
 * AFTER the content steps so role slugs and assignments resolve. Never
 * touches firefly_collective_active_template.
 */
function firefly_projects_ts_apply_settings($template, $payload) {
    $report = array(
        'options_set'         => 0,
        'categories_created'  => 0,
        'assignments_applied' => 0,
        'roles_set'           => array(),
        'pricing'             => 'skipped',
        'warnings'            => array(),
    );

    // Categories first — assignments resolve against them.
    $term_id_by_base = array();
    $categories = isset($payload['categories']) && is_array($payload['categories']) ? $payload['categories'] : array();
    foreach ($categories as $cat) {
        if (empty($cat['base_slug']) || empty($cat['name'])) {
            continue;
        }
        $base = sanitize_title($cat['base_slug']);
        $slug = firefly_projects_ts_category_scoped_slug($base, $template);
        $term = get_term_by('slug', $slug, 'category');
        if (!$term) {
            $result = wp_insert_term(sanitize_text_field($cat['name']), 'category', array(
                'slug'        => $slug,
                'description' => isset($cat['description']) ? sanitize_text_field($cat['description']) : '',
            ));
            if (is_wp_error($result)) {
                $report['warnings'][] = "Category '{$base}': " . $result->get_error_message();
                continue;
            }
            $term_id = (int) $result['term_id'];
            $report['categories_created']++;
        } else {
            // Reuse only if the existing term isn't scoped to a DIFFERENT
            // template. Without this, syncing the 'default' template (whose
            // scoped slug == the raw base slug) could resolve another
            // template's already-scoped category and yank it into default's
            // scope by overwriting its _firefly_template below.
            $owner = get_term_meta($term->term_id, '_firefly_template', true);
            if ($owner !== '' && $owner !== $template) {
                $report['warnings'][] = "Category '{$slug}' belongs to template '{$owner}' — not reassigned to '{$template}'.";
                continue;
            }
            $term_id = (int) $term->term_id;
        }
        update_term_meta($term_id, '_firefly_template', $template);
        $term_id_by_base[$base] = $term_id;
    }

    // Category assignments by cross-env page id.
    $assignments = isset($payload['assignments']) && is_array($payload['assignments']) ? $payload['assignments'] : array();
    foreach ($assignments as $fpid => $bases) {
        $post = function_exists('firefly_projects_find_post_by_firefly_page_id')
            ? firefly_projects_find_post_by_firefly_page_id($fpid)
            : null;
        if (!$post) {
            $report['warnings'][] = "Assignment skipped — no local post for {$fpid}.";
            continue;
        }
        // firefly_page_ids are guessable ("{template}:{slug}") and the resolver
        // above is deliberately unscoped, so verify the post belongs to THIS
        // template before replacing its category set — otherwise a peer could
        // clobber categories on another template's post.
        if (get_post_meta($post->ID, '_firefly_template', true) !== $template) {
            $report['warnings'][] = "Assignment skipped — {$fpid} is not owned by '{$template}'.";
            continue;
        }
        $ids = array();
        foreach ((array) $bases as $base) {
            $base = sanitize_title($base);
            if (isset($term_id_by_base[$base])) {
                $ids[] = $term_id_by_base[$base];
            }
        }
        if (!empty($ids)) {
            wp_set_post_categories($post->ID, $ids);
            $report['assignments_applied']++;
        }
    }

    // Customizer options through the theme's sanitizing setter. Unknown keys
    // (receiver's options.php older than the sender's — shouldn't happen
    // after the files step, but tolerate) are still written, with a warning.
    $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : array();
    if (!empty($options) && function_exists('firefly_set_template_option')) {
        $known = function_exists('firefly_get_template_options') ? firefly_get_template_options($template) : array();
        foreach ($options as $key => $value) {
            $key = sanitize_key($key);
            if ($key === '') {
                continue;
            }
            if (!isset($known[$key])) {
                // Enforce the declared-options allowlist when we can see it: an
                // undeclared key is never written (defence-in-depth, even though
                // writes are already namespaced to firefly_collective_*_{template}).
                // If the known set is empty we can't tell what's declared, so
                // fall back to writing with a warning rather than dropping a
                // legitimate sync.
                if (!empty($known)) {
                    $report['warnings'][] = "Option '{$key}' is not declared for '{$template}' — skipped.";
                    continue;
                }
                $report['warnings'][] = "Option '{$key}' is not declared in this site's options.php for {$template}.";
            }
            firefly_set_template_option($key, $value, $template, false);
            $report['options_set']++;
        }
    } elseif (!empty($options)) {
        $report['warnings'][] = 'Theme option helpers unavailable — Customizer options not applied.';
    }

    // Page roles: per-template options always; core options only when this
    // template is the locally active one (same rule as page pull).
    $roles = isset($payload['roles']) && is_array($payload['roles']) ? $payload['roles'] : array();
    $active_template = function_exists('firefly_get_scoping_template') ? firefly_get_scoping_template() : '';
    $is_active = ($active_template === '' || $active_template === $template);
    foreach (array('front_page', 'posts_page') as $role) {
        if (empty($roles[$role])) {
            continue;
        }
        $post = function_exists('firefly_projects_find_scoped_page')
            ? firefly_projects_find_scoped_page(sanitize_title($roles[$role]), $template, array('page'))
            : null;
        if (!$post) {
            $report['warnings'][] = "Role {$role}: page '{$roles[$role]}' not found locally.";
            continue;
        }
        update_option("firefly_{$role}_{$template}", $post->ID);
        if ($is_active) {
            if ($role === 'front_page') {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $post->ID);
            } else {
                update_option('page_for_posts', $post->ID);
            }
        }
        $report['roles_set'][] = $role;
    }

    // Pricing: when THIS template owns pricing.json (constant-driven owner
    // path) re-run the canonical file→DB apply so the ffc_* tables match the
    // file that just landed in the files step.
    if (function_exists('firefly_pricing_template_path') && function_exists('firefly_collective_pricing_init')) {
        $pricing_path = firefly_pricing_template_path('data/pricing.json');
        if (strpos($pricing_path, '/templates/' . $template . '/') !== false && file_exists($pricing_path)) {
            firefly_collective_pricing_init();
            $report['pricing'] = 'applied';
        }
    }

    return $report;
}

/**
 * GET /template-sync/export-settings (shared secret).
 */
function firefly_projects_ts_export_settings($request) {
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    return new WP_REST_Response(array('success' => true, 'settings' => firefly_projects_ts_build_settings($template)), 200);
}

/**
 * POST /template-sync/receive-settings (shared secret).
 */
function firefly_projects_ts_receive_settings($request) {
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    if (function_exists('firefly_is_valid_template') && !firefly_is_valid_template($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => "Refused: template '{$template}' is not installed on this environment."), 400);
    }

    $settings = $request->get_param('settings');
    if (!is_array($settings)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'No settings payload.'), 400);
    }

    $report = firefly_projects_ts_apply_settings($template, $settings);
    return new WP_REST_Response(array('success' => true, 'report' => $report), 200);
}

/**
 * POST /template-sync/push-settings (admin).
 */
function firefly_projects_ts_push_settings($request) {
    $env = $request->get_param('target_env') === 'prod' ? 'prod' : 'dev';
    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    $data = firefly_projects_ts_remote_request($env, 'POST', 'receive-settings', array(
        'template' => $template,
        'settings' => firefly_projects_ts_build_settings($template),
    ));
    if (is_wp_error($data)) {
        return new WP_REST_Response(array('success' => false, 'message' => $data->get_error_message()), $data->get_error_data()['status'] ?? 500);
    }
    return new WP_REST_Response($data, 200);
}

/**
 * POST /template-sync/pull-settings (admin).
 */
function firefly_projects_ts_pull_settings($request) {
    $env = $request->get_param('source_env') === 'prod' ? 'prod' : 'dev';
    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    if (function_exists('firefly_is_valid_template') && !firefly_is_valid_template($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => "Template '{$template}' is not installed locally — run the files step first."), 400);
    }
    $data = firefly_projects_ts_remote_request($env, 'GET', 'export-settings?template=' . rawurlencode($template));
    if (is_wp_error($data)) {
        return new WP_REST_Response(array('success' => false, 'message' => $data->get_error_message()), $data->get_error_data()['status'] ?? 500);
    }
    if (empty($data['success']) || !isset($data['settings'])) {
        return new WP_REST_Response(array('success' => false, 'message' => isset($data['message']) ? $data['message'] : 'Remote settings export failed.'), 500);
    }
    $report = firefly_projects_ts_apply_settings($template, $data['settings']);
    return new WP_REST_Response(array('success' => true, 'report' => $report), 200);
}

// =============================================================================
// ACTIVATE + CONTENT MIRROR
// =============================================================================

/**
 * Run the theme's activation worker in-process (idempotent: wires page
 * roles, menu location, and the active-template option). NOT the HTTP
 * firefly/v1 endpoint — that authenticates with a per-machine CLI key the
 * plugin doesn't know for remotes.
 */
function firefly_projects_ts_do_activate($template) {
    if (!function_exists('firefly_handle_activate_template')) {
        return new WP_Error('no_theme_support', 'Theme activation worker unavailable.', array('status' => 500));
    }
    if (function_exists('firefly_is_valid_template') && !firefly_is_valid_template($template)) {
        return new WP_Error('not_installed', "Template '{$template}' is not installed here.", array('status' => 400));
    }
    $req = new WP_REST_Request('POST', '/firefly/v1/activate-template');
    $req->set_param('template', $template);
    $result = firefly_handle_activate_template($req);
    if (is_wp_error($result)) {
        return $result;
    }
    if ($result instanceof WP_REST_Response) {
        $result = $result->get_data();
    }
    return $result;
}

/**
 * POST /template-sync/receive-activate (shared secret).
 */
function firefly_projects_ts_receive_activate($request) {
    $auth_failure = firefly_projects_verify_shared_secret($request);
    if ($auth_failure !== null) return $auth_failure;

    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    $result = firefly_projects_ts_do_activate($template);
    if (is_wp_error($result)) {
        return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), $result->get_error_data()['status'] ?? 500);
    }
    return new WP_REST_Response(array('success' => true, 'result' => $result), 200);
}

/**
 * POST /template-sync/remote-activate (admin).
 */
function firefly_projects_ts_remote_activate($request) {
    $env = $request->get_param('target_env') === 'prod' ? 'prod' : 'dev';
    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    $data = firefly_projects_ts_remote_request($env, 'POST', 'receive-activate', array('template' => $template), 300);
    if (is_wp_error($data)) {
        return new WP_REST_Response(array('success' => false, 'message' => $data->get_error_message()), $data->get_error_data()['status'] ?? 500);
    }
    return new WP_REST_Response($data, 200);
}

/**
 * POST /template-sync/activate-local (admin) — pull's optional final step.
 */
function firefly_projects_ts_activate_local($request) {
    $template = $request->get_param('template');
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }
    $result = firefly_projects_ts_do_activate($template);
    if (is_wp_error($result)) {
        return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), $result->get_error_data()['status'] ?? 500);
    }
    return new WP_REST_Response(array('success' => true, 'result' => $result), 200);
}

/**
 * POST /template-sync/clear-cache (admin) — clear THIS site's static page cache
 * as the final step of a pull. Freshly-synced content (the front page most
 * visibly) is otherwise served stale from the cache until a manual purge; the
 * cache helpers also trigger the nginx proxy purge. opcache is reset too since
 * the files step may have replaced template PHP.
 */
function firefly_projects_ts_clear_cache($request) {
    $template = sanitize_file_name((string) $request->get_param('template'));
    $cleared = array();
    if ($template !== '' && function_exists('firefly_collective_cache_delete_template')) {
        firefly_collective_cache_delete_template($template);  // also purges nginx
        $cleared[] = 'page cache: ' . $template;
    } elseif (function_exists('firefly_collective_cache_clear_all')) {
        firefly_collective_cache_clear_all();                 // also purges nginx
        $cleared[] = 'page cache: all';
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
        $cleared[] = 'opcache';
    }
    return new WP_REST_Response(array('success' => true, 'cleared' => $cleared), 200);
}

/**
 * POST /template-sync/mirror-content (admin).
 * direction 'push': delete remote pages/posts of this template that don't
 * exist locally (reuses the bulk-sync orphan machinery, template threaded
 * through explicitly). direction 'pull': trash LOCAL pages/posts of the
 * template whose firefly_page_id isn't in the provided keep_fpids list
 * (the remote's manifest ids) — trash, not hard delete, so a mistaken
 * mirror pull is recoverable from the WP trash.
 */
function firefly_projects_ts_mirror_content($request) {
    $env = $request->get_param('target_env') === 'prod' ? 'prod' : 'dev';
    $direction = $request->get_param('direction') === 'pull' ? 'pull' : 'push';
    $template = $request->get_param('template');
    $post_type = $request->get_param('post_type') === 'post' ? 'post' : 'page';
    if (!firefly_projects_ts_valid_name($template)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid template name.'), 400);
    }

    $items = get_posts(array(
        'post_type'            => $post_type,
        'post_status'          => 'publish',
        'numberposts'          => -1,
        'meta_key'             => '_firefly_template',
        'meta_value'           => $template,
        'firefly_skip_scoping' => true,
    ));

    if ($direction === 'pull') {
        $keep = $request->get_param('keep_fpids');
        $keep = is_array($keep) ? array_flip(array_map('strval', $keep)) : array();
        $trashed = array();
        foreach ($items as $item) {
            $fpid = get_post_meta($item->ID, '_firefly_page_id', true);
            if (empty($fpid)) {
                $fpid = $template . ':' . $item->post_name;
            }
            if (!isset($keep[$fpid])) {
                if (wp_trash_post($item->ID)) {
                    $trashed[] = array('slug' => $item->post_name, 'title' => $item->post_title, 'firefly_page_id' => $fpid);
                }
            }
        }
        return new WP_REST_Response(array('success' => true, 'deleted' => count($trashed), 'deleted_pages' => $trashed), 200);
    }

    $local_ids = array();
    foreach ($items as $item) {
        $fpid = get_post_meta($item->ID, '_firefly_page_id', true);
        $local_ids[] = $fpid ? $fpid : ($template . ':' . $item->post_name);
    }

    require_once FIREFLY_PROJECTS_PLUGIN_DIR . 'includes/models/pages-list-sync.php';
    $result = firefly_projects_delete_remote_orphans($local_ids, $env, $post_type, $template);

    return new WP_REST_Response(array(
        'success'       => true,
        'deleted'       => $result['deleted'],
        'deleted_pages' => isset($result['deleted_pages']) ? $result['deleted_pages'] : array(),
    ), 200);
}
