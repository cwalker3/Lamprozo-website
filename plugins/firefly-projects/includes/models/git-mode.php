<?php
/**
 * Firefly Projects — Git Mode
 *
 * Detects a git repo at wp-content/, inspects `git status --porcelain` to
 * find modified + untracked files, and exposes that to the Projects UI so
 * the user can run a partial sync pre-selected to match their local diff.
 *
 * The UI toggle state is persisted per-user via user_meta so it survives
 * browser close and cross-device use.
 *
 * REST endpoints (registered from rest.php):
 *   GET  /firefly-plugin/v1/git-status?project_name=X
 *   POST /firefly-plugin/v1/git-mode       body: { enabled: bool }
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Locate the wp-content directory on the server. We detect git at this root
 * because wp-content is where the Firefly theme + plugins are tracked.
 */
function firefly_projects_git_wp_content_dir() {
    // WP_CONTENT_DIR is the canonical path on a normal install.
    return defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
}

/**
 * True if shell_exec is available + a git dir exists at wp-content/.git.
 * A worktree .git FILE (not dir) also qualifies, so check file_exists.
 */
function firefly_projects_git_is_available() {
    if ( ! function_exists( 'shell_exec' ) ) return false;
    $git_path = firefly_projects_git_wp_content_dir() . '/.git';
    return file_exists( $git_path );
}

/**
 * User-meta key for the per-user git-mode toggle.
 */
function firefly_projects_git_mode_meta_key() {
    return 'firefly_git_mode_enabled';
}

/**
 * Read the current user's git-mode preference.
 */
function firefly_projects_git_mode_is_enabled_for_user( $user_id = null ) {
    if ( ! $user_id ) $user_id = get_current_user_id();
    if ( ! $user_id ) return false;
    $val = get_user_meta( $user_id, firefly_projects_git_mode_meta_key(), true );
    return $val === '1';
}

/**
 * Classify a porcelain two-char status code into a simpler category the UI
 * can color: "staged" (ready to commit), "modified" (unstaged working-tree
 * change), or "untracked" (new file).
 *
 * If a file is both staged AND modified (e.g. "MM"), we return "modified"
 * since that's the actionable state — there's work that hasn't been staged.
 */
function firefly_projects_git_classify_status( $xy ) {
    if ( $xy === '??' ) return 'untracked';
    $x = isset( $xy[0] ) ? $xy[0] : ' ';
    $y = isset( $xy[1] ) ? $xy[1] : ' ';
    if ( $y !== ' ' && $y !== '?' ) return 'modified';   // unstaged change present
    if ( $x !== ' ' && $x !== '?' ) return 'staged';     // staged only
    return 'modified';
}

/**
 * Run `git status --porcelain` from wp-content and parse the output.
 * Returns an array of { path, raw, status } records where:
 *   path   — wp-content-relative file path (e.g. "plugins/foo/bar.php")
 *   raw    — the raw two-char XY status code ("??", " M", "M ", "MM", …)
 *   status — classification: "staged" | "modified" | "untracked"
 *
 * Porcelain format is: XY + space + path. Rename/copy entries use
 * " -> " syntax; we take the NEW path. Quoted paths (git escapes unicode
 * and spaces when core.quotepath=true) are unquoted.
 */
function firefly_projects_git_changed_files() {
    if ( ! firefly_projects_git_is_available() ) return array();

    $wp_content = firefly_projects_git_wp_content_dir();
    $cmd = 'cd ' . escapeshellarg( $wp_content ) . ' && git status --porcelain 2>&1';
    $output = shell_exec( $cmd );
    if ( ! is_string( $output ) || $output === '' ) return array();

    // NOTE: do NOT trim the whole output — porcelain lines for unstaged
    // changes start with a leading space (" M path"). A global trim() would
    // strip that space from the first line, corrupting the status column
    // and shifting the path by one character.
    $records = array();
    $seen    = array();
    $lines   = preg_split( '/\r?\n/', rtrim( $output, "\r\n" ) );
    foreach ( $lines as $line ) {
        if ( $line === '' ) continue;
        if ( strlen( $line ) < 4 ) continue;

        $xy   = substr( $line, 0, 2 );
        $rest = substr( $line, 3 );

        if ( strpos( $rest, ' -> ' ) !== false ) {
            $parts = explode( ' -> ', $rest, 2 );
            $rest = $parts[1];
        }

        $rest = trim( $rest );
        if ( strlen( $rest ) >= 2 && $rest[0] === '"' && substr( $rest, -1 ) === '"' ) {
            $rest = stripcslashes( substr( $rest, 1, -1 ) );
        }

        if ( isset( $seen[ $rest ] ) ) continue;
        $seen[ $rest ] = true;

        $records[] = array(
            'path'   => $rest,
            'raw'    => $xy,
            'status' => firefly_projects_git_classify_status( $xy ),
        );
    }

    return $records;
}

/**
 * Filter a list of wp-content-relative changed paths down to the ones that
 * sit inside one of the project's declared directories.
 *
 * project.json stores directories as web-paths like
 *   "/wp-content/plugins/firefly-collective"
 * and git porcelain output is wp-content-relative like
 *   "plugins/firefly-collective/includes/foo.php"
 *
 * Returns paths in the same format the file-tree UI uses for each node's
 * `path` attribute — `/wp-content/<rest>` — so the frontend can pre-check
 * them by string equality against node.path.
 */
function firefly_projects_git_files_in_project_scope( $changed_records, $project_directories ) {
    if ( empty( $changed_records ) || empty( $project_directories ) ) {
        return array( 'paths' => array(), 'status_map' => array() );
    }

    $prefixes = array();
    foreach ( $project_directories as $dir ) {
        $dir = rtrim( $dir, '/' );
        if ( strpos( $dir, '/wp-content/' ) === 0 ) {
            $prefixes[] = substr( $dir, strlen( '/wp-content/' ) );
        } elseif ( $dir === '/wp-content' ) {
            $prefixes[] = '';
        }
    }

    $paths      = array();
    $status_map = array();
    foreach ( $changed_records as $rec ) {
        $rel = is_array( $rec ) ? $rec['path'] : $rec;
        foreach ( $prefixes as $pfx ) {
            if ( $pfx === '' || $rel === $pfx || strpos( $rel, $pfx . '/' ) === 0 ) {
                $node_path = '/wp-content/' . $rel;
                if ( ! in_array( $node_path, $paths, true ) ) {
                    $paths[] = $node_path;
                    $status_map[ $node_path ] = is_array( $rec ) ? $rec['status'] : 'modified';
                }
                break;
            }
        }
    }

    return array( 'paths' => $paths, 'status_map' => $status_map );
}

/**
 * REST handler — GET /firefly-plugin/v1/git-status?project_name=X
 *
 * Returns everything the UI needs to render the git-mode toggle:
 *   - git_available: is there a repo at wp-content AND can we shell out?
 *   - git_mode_enabled: the user's persisted toggle state
 *   - all_changed_files: every modified/untracked file under wp-content
 *   - in_scope_files: absolute paths filtered to the active project's dirs
 *   - changed_count: length of in_scope_files (what the UI displays)
 */
function firefly_projects_git_status_endpoint( WP_REST_Request $request ) {
    $project_name = sanitize_text_field( $request->get_param( 'project_name' ) );

    $result = array(
        'git_available'     => firefly_projects_git_is_available(),
        'git_mode_enabled'  => firefly_projects_git_mode_is_enabled_for_user(),
        'all_changed_files' => array(),
        'in_scope_files'    => array(),
        'status_map'        => new stdClass(),  // "/wp-content/x" => "staged"|"modified"|"untracked"
        'status_counts'     => array( 'staged' => 0, 'modified' => 0, 'untracked' => 0 ),
        'changed_count'     => 0,
    );

    if ( ! $result['git_available'] ) return $result;

    $changed = firefly_projects_git_changed_files();
    $result['all_changed_files'] = $changed;

    if ( $project_name === '' ) return $result;

    $project = firefly_projects_find_project( $project_name );
    if ( ! $project ) return $result;

    $dirs = isset( $project['files'] )
        ? $project['files']
        : ( isset( $project['directories'] ) ? $project['directories'] : array() );

    $scoped = firefly_projects_git_files_in_project_scope( $changed, $dirs );
    $result['in_scope_files'] = $scoped['paths'];
    $result['status_map']     = (object) $scoped['status_map'];
    $result['changed_count']  = count( $scoped['paths'] );

    // Counts per status for the UI label
    $counts = array( 'staged' => 0, 'modified' => 0, 'untracked' => 0 );
    foreach ( $scoped['status_map'] as $status ) {
        if ( isset( $counts[ $status ] ) ) $counts[ $status ]++;
    }
    $result['status_counts'] = $counts;

    return $result;
}

/**
 * REST handler — POST /firefly-plugin/v1/git-mode  body: { enabled: bool }
 * Persists the toggle to the current user's user_meta.
 */
function firefly_projects_git_mode_toggle_endpoint( WP_REST_Request $request ) {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return new WP_Error( 'forbidden', 'Not logged in', array( 'status' => 403 ) );
    }

    $enabled = (bool) $request->get_param( 'enabled' );
    update_user_meta( $user_id, firefly_projects_git_mode_meta_key(), $enabled ? '1' : '0' );

    return array(
        'success'          => true,
        'git_mode_enabled' => $enabled,
    );
}
