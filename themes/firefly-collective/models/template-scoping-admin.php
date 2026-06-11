<?php
/**
 * Template Scoping Admin UI
 *
 * Adds admin interface for managing template assignments on posts/pages.
 */

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// META BOX FOR TEMPLATE ASSIGNMENT
// =============================================================================

/**
 * Add template selector meta box to posts and pages.
 */
add_action('add_meta_boxes', 'firefly_add_template_meta_box');

function firefly_add_template_meta_box() {
    $screens = array('post', 'page');

    foreach ($screens as $screen) {
        add_meta_box(
            'firefly_template_meta_box',
            'Template Assignment',
            'firefly_template_meta_box_callback',
            $screen,
            'side',
            'high',
            // Mark as back-compat so Gutenberg hides the legacy box from the
            // Document sidebar. A native PluginDocumentSettingPanel replaces
            // it (see assets/js/template-tools-panel.js). Classic editor
            // (if anyone still uses it) still gets the old metabox.
            array( '__back_compat_meta_box' => true )
        );
    }
}

/**
 * Render the template assignment meta box.
 */
function firefly_template_meta_box_callback($post) {
    wp_nonce_field('firefly_template_meta_box', 'firefly_template_meta_box_nonce');

    $current = get_post_meta($post->ID, FIREFLY_TEMPLATE_META_KEY, true);
    if (empty($current)) {
        $current = firefly_get_scoping_template();
    }

    echo '<select name="firefly_template_assignment" id="firefly_template_assignment" style="width:100%">';
    $valid_templates = firefly_get_valid_templates();
    foreach ($valid_templates as $template) {
        $selected = selected($current, $template, false);
        $label = ucfirst($template);
        echo "<option value='{$template}' {$selected}>{$label}</option>";
    }
    echo '</select>';
    echo '<p class="description" style="margin-top:8px;">Assign this content to a specific template. Content is only visible when that template is active.</p>';
}

/**
 * Save template assignment when post is saved.
 */
add_action('save_post', 'firefly_save_template_meta_box', 10, 2);

function firefly_save_template_meta_box($post_id, $post) {
    // Verify nonce
    if (!isset($_POST['firefly_template_meta_box_nonce'])) {
        return;
    }
    if (!wp_verify_nonce($_POST['firefly_template_meta_box_nonce'], 'firefly_template_meta_box')) {
        return;
    }

    // Skip autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save the template assignment
    if (isset($_POST['firefly_template_assignment'])) {
        $template = sanitize_text_field($_POST['firefly_template_assignment']);
        if (firefly_is_valid_template($template)) {
            update_post_meta($post_id, FIREFLY_TEMPLATE_META_KEY, $template);
        }
    }
}

// =============================================================================
// ADMIN LIST FILTERING (AUTO-FILTER BY ACTIVE TEMPLATE)
// =============================================================================

/**
 * Show current template indicator in admin.
 */
add_action('restrict_manage_posts', 'firefly_admin_template_indicator');

function firefly_admin_template_indicator() {
    global $typenow;

    if (!in_array($typenow, array('post', 'page'))) {
        return;
    }

    $template = firefly_get_scoping_template();
    echo '<span style="padding:5px 10px; background:#0073aa; color:#fff; border-radius:3px; font-size:12px;">Template: ' . esc_html(ucfirst($template)) . '</span>';
}

/**
 * Auto-filter posts/pages by active template in admin lists.
 */
add_action('pre_get_posts', 'firefly_admin_auto_filter_by_template');

function firefly_admin_auto_filter_by_template($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    global $pagenow, $typenow;

    if ($pagenow !== 'edit.php') {
        return;
    }

    if (!in_array($typenow, array('post', 'page'))) {
        return;
    }

    // Auto-filter by active template
    $template = firefly_get_scoping_template();

    $meta_query = $query->get('meta_query') ?: array();
    $meta_query[] = array(
        'key'     => FIREFLY_TEMPLATE_META_KEY,
        'value'   => $template,
        'compare' => '='
    );
    $query->set('meta_query', $meta_query);
}

// =============================================================================
// ADMIN POST COUNT FILTERING
// =============================================================================

/**
 * Filter post counts to only show template-scoped content.
 */
add_filter('wp_count_posts', 'firefly_filter_admin_post_counts', 10, 3);

function firefly_filter_admin_post_counts($counts, $type, $perm) {
    // Only filter in admin for posts and pages
    if (!is_admin()) {
        return $counts;
    }

    if (!in_array($type, array('post', 'page'))) {
        return $counts;
    }

    global $pagenow;
    if ($pagenow !== 'edit.php') {
        return $counts;
    }

    global $wpdb;
    $template = firefly_get_scoping_template();

    // Get counts filtered by template
    $query = $wpdb->prepare(
        "SELECT p.post_status, COUNT(*) as count
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = %s
         AND pm.meta_key = %s
         AND pm.meta_value = %s
         GROUP BY p.post_status",
        $type,
        FIREFLY_TEMPLATE_META_KEY,
        $template
    );

    $results = $wpdb->get_results($query);

    // Build new counts object
    $new_counts = new stdClass();
    foreach (get_post_stati() as $status) {
        $new_counts->$status = 0;
    }

    foreach ($results as $row) {
        $new_counts->{$row->post_status} = (int) $row->count;
    }

    return $new_counts;
}

/**
 * Filter the views (All, Mine, Published, etc.) to show template-scoped counts.
 */
add_filter('views_edit-post', 'firefly_filter_admin_views', 10, 1);
add_filter('views_edit-page', 'firefly_filter_admin_views', 10, 1);

function firefly_filter_admin_views($views) {
    global $wpdb, $typenow;
    $template = firefly_get_scoping_template();
    $current_user_id = get_current_user_id();

    // Get template-scoped counts by status
    $counts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.post_status, COUNT(*) as count
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = %s
         AND pm.meta_key = %s
         AND pm.meta_value = %s
         GROUP BY p.post_status",
        $typenow,
        FIREFLY_TEMPLATE_META_KEY,
        $template
    ), OBJECT_K);

    // Get "mine" count (current user's posts in this template)
    $mine_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = %s
         AND p.post_author = %d
         AND p.post_status NOT IN ('trash', 'auto-draft')
         AND pm.meta_key = %s
         AND pm.meta_value = %s",
        $typenow,
        $current_user_id,
        FIREFLY_TEMPLATE_META_KEY,
        $template
    ));

    // Calculate totals
    $total = 0;
    $publish_count = isset($counts['publish']) ? $counts['publish']->count : 0;
    $draft_count = isset($counts['draft']) ? $counts['draft']->count : 0;
    $trash_count = isset($counts['trash']) ? $counts['trash']->count : 0;

    foreach ($counts as $status => $data) {
        if ($status !== 'trash' && $status !== 'auto-draft') {
            $total += $data->count;
        }
    }

    // Update view counts using regex
    foreach ($views as $key => &$view) {
        if ($key === 'all') {
            $view = preg_replace('/\(\d+\)/', '(' . $total . ')', $view);
        } elseif ($key === 'mine') {
            $view = preg_replace('/\(\d+\)/', '(' . $mine_count . ')', $view);
        } elseif ($key === 'publish') {
            $view = preg_replace('/\(\d+\)/', '(' . $publish_count . ')', $view);
        } elseif ($key === 'draft') {
            $view = preg_replace('/\(\d+\)/', '(' . $draft_count . ')', $view);
        } elseif ($key === 'trash') {
            $view = preg_replace('/\(\d+\)/', '(' . $trash_count . ')', $view);
        }
    }

    return $views;
}

// =============================================================================
// NAV MENU PAGE FILTERING
// =============================================================================

/**
 * Ensure the recently-edited menu belongs to the active template.
 * WordPress uses nav_menu_recently_edited to auto-select a menu on nav-menus.php,
 * bypassing wp_get_nav_menus filter. Fix it before the page processes.
 */
add_action('load-nav-menus.php', 'firefly_fix_recently_edited_menu');

function firefly_fix_recently_edited_menu() {
    $template = firefly_get_scoping_template();

    // If URL has ?menu= for a wrong-template menu, redirect to first correct menu
    if (isset($_GET['menu']) && absint($_GET['menu']) > 0) {
        $requested = absint($_GET['menu']);
        $menu_template = get_term_meta($requested, FIREFLY_TEMPLATE_META_KEY, true);
        if ($menu_template !== $template) {
            wp_safe_redirect(admin_url('nav-menus.php'));
            exit;
        }
    }

    // Fix recently-edited menu if it belongs to a different template
    $recently_edited = absint(get_user_option('nav_menu_recently_edited'));
    if ($recently_edited) {
        $menu_template = get_term_meta($recently_edited, FIREFLY_TEMPLATE_META_KEY, true);
        if ($menu_template !== $template) {
            $menus = wp_get_nav_menus();
            $first_match = 0;
            foreach ($menus as $menu) {
                $mt = get_term_meta($menu->term_id, FIREFLY_TEMPLATE_META_KEY, true);
                if ($mt === $template) {
                    $first_match = $menu->term_id;
                    break;
                }
            }
            update_user_meta(get_current_user_id(), 'nav_menu_recently_edited', $first_match);
        }
    }
}

/**
 * Prepare the Menus screen for template scoping before WordPress processes it.
 *
 * 1. Heal orphaned menus: any nav_menu term with no _firefly_template meta is
 *    adopted into the active template. Such menus are otherwise invisible on
 *    every template (firefly_filter_nav_menus_by_template matches on template).
 * 2. Intercept menu creation: WordPress treats nav_menu *names* as globally
 *    unique (wp_update_nav_menu_object rejects a name already used by any menu,
 *    via get_term_by('name') which uses suppress_filter and cannot be hooked).
 *    That defeats template scoping, where each template should be free to have
 *    its own "Main Menu" just like pages/posts share slugs per template. So we
 *    create the menu ourselves with a per-template-unique slug -- which
 *    wp_insert_term allows for a duplicate name -- and tag it with the template.
 *    Name uniqueness is still enforced, but only *within* the active template.
 */
add_action('load-nav-menus.php', 'firefly_prepare_menus_screen', 5);

function firefly_prepare_menus_screen() {
    $template = firefly_get_scoping_template();

    // 1. Heal orphaned menus. Query terms directly so we bypass the
    //    wp_get_nav_menus template filter, which would hide the very orphans
    //    we need to fix (get_terms_args scoping does not touch nav_menu).
    $all_menus = get_terms(array(
        'taxonomy'   => 'nav_menu',
        'hide_empty' => false,
    ));
    if (is_array($all_menus)) {
        foreach ($all_menus as $menu) {
            $menu_template = get_term_meta($menu->term_id, FIREFLY_TEMPLATE_META_KEY, true);
            if (empty($menu_template)) {
                update_term_meta($menu->term_id, FIREFLY_TEMPLATE_META_KEY, $template);
            }
        }
    }

    // 2. Intercept the "Create Menu" submit (menu id 0) so the new menu is
    //    scoped to the active template and may reuse a name from another one.
    $is_create = (
        isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD']
        && !empty($_POST['save_menu'])
        && 0 === (isset($_REQUEST['menu']) ? (int) $_REQUEST['menu'] : 0)
        && isset($_POST['menu-name'])
        && isset($_POST['update-nav-menu-nonce'])
        && wp_verify_nonce($_POST['update-nav-menu-nonce'], 'update-nav_menu')
    );
    if (!$is_create) {
        return;
    }

    $name = trim(wp_unslash($_POST['menu-name']));

    // Enforce name uniqueness within the active template only. If this template
    // already has a menu with this name, fall through and let core show its
    // standard "conflicts with another menu name" notice.
    $named = get_terms(array('taxonomy' => 'nav_menu', 'hide_empty' => false, 'name' => $name));
    if (is_array($named)) {
        foreach ($named as $term) {
            if (get_term_meta($term->term_id, FIREFLY_TEMPLATE_META_KEY, true) === $template) {
                return;
            }
        }
    }

    $new_id = firefly_create_scoped_menu($name, $template);
    if (is_wp_error($new_id)) {
        return; // Let core surface the error (e.g. empty name).
    }

    // Honor the new-menu form settings, mirroring wp-admin/nav-menus.php.
    if (!empty($_POST['menu-locations'])) {
        $new_locations = array_map('absint', (array) $_POST['menu-locations']);
        $locations     = get_nav_menu_locations();
        foreach (array_keys($new_locations) as $location) {
            $locations[$location] = $new_id;
        }
        set_theme_mod('nav_menu_locations', $locations);
    }
    if (!empty($_POST['auto-add-pages'])) {
        $nav_menu_option              = (array) get_option('nav_menu_options');
        $nav_menu_option['auto_add']  = isset($nav_menu_option['auto_add']) ? $nav_menu_option['auto_add'] : array();
        if (!in_array($new_id, $nav_menu_option['auto_add'], true)) {
            $nav_menu_option['auto_add'][] = $new_id;
        }
        update_option('nav_menu_options', $nav_menu_option);
    }

    // Land on the freshly created menu.
    update_user_meta(get_current_user_id(), 'nav_menu_recently_edited', $new_id);
    wp_safe_redirect(admin_url('nav-menus.php?menu=' . $new_id));
    exit;
}

/**
 * Filter menu dropdown on nav-menus.php to only show active template's menus.
 */
add_filter('wp_get_nav_menus', 'firefly_filter_nav_menus_by_template', 10, 2);

function firefly_filter_nav_menus_by_template($menus, $args) {
    global $pagenow;
    if ($pagenow !== 'nav-menus.php') {
        return $menus;
    }

    $template = firefly_get_scoping_template();

    $filtered = array();
    foreach ($menus as $menu) {
        $menu_template = get_term_meta($menu->term_id, FIREFLY_TEMPLATE_META_KEY, true);
        if ($menu_template === $template) {
            $filtered[] = $menu;
        }
    }

    return $filtered;
}

/**
 * Filter page/post queries on nav-menus.php to only show active template's content.
 * Covers "Most Recent" tab (get_posts/WP_Query) and "Search" tab.
 */
add_action('pre_get_posts', 'firefly_filter_nav_menu_queries');

function firefly_filter_nav_menu_queries($query) {
    if (!is_admin()) return;

    global $pagenow;
    if ($pagenow !== 'nav-menus.php') return;

    $post_type = $query->get('post_type');
    if (!in_array($post_type, array('page', 'post'))) return;

    $template = firefly_get_scoping_template();

    $meta_query = $query->get('meta_query') ?: array();
    $meta_query[] = array(
        'key'     => FIREFLY_TEMPLATE_META_KEY,
        'value'   => $template,
        'compare' => '='
    );
    $query->set('meta_query', $meta_query);
}

/**
 * Auto-assign template meta when a new menu is created via the admin UI.
 */
add_action('wp_update_nav_menu', 'firefly_auto_scope_new_menu', 10, 2);
add_action('wp_create_nav_menu', 'firefly_auto_scope_new_menu', 10, 2);

function firefly_auto_scope_new_menu($menu_id, $menu_data = array()) {
    // Only set if no template meta exists yet
    $existing = get_term_meta($menu_id, FIREFLY_TEMPLATE_META_KEY, true);
    if (!empty($existing)) return;

    $template = firefly_get_scoping_template();
    update_term_meta($menu_id, FIREFLY_TEMPLATE_META_KEY, $template);
}

// =============================================================================
// CATEGORY/TAG TERM EDITING
// =============================================================================

/**
 * Add template field to category/tag add forms.
 */
add_action('category_add_form_fields', 'firefly_add_term_template_field', 10, 1);
add_action('post_tag_add_form_fields', 'firefly_add_term_template_field', 10, 1);

function firefly_add_term_template_field($taxonomy) {
    $current_template = firefly_get_scoping_template();
    ?>
    <div class="form-field">
        <label for="firefly_term_template">Template</label>
        <select name="firefly_term_template" id="firefly_term_template">
            <?php foreach (FIREFLY_VALID_TEMPLATES as $template): ?>
                <option value="<?php echo esc_attr($template); ?>" <?php selected($current_template, $template); ?>>
                    <?php echo esc_html(ucfirst($template)); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p>Assign this term to a specific template.</p>
    </div>
    <?php
}

/**
 * Add template field to category/tag edit forms.
 */
add_action('category_edit_form_fields', 'firefly_edit_term_template_field', 10, 2);
add_action('post_tag_edit_form_fields', 'firefly_edit_term_template_field', 10, 2);

function firefly_edit_term_template_field($term, $taxonomy) {
    $current = get_term_meta($term->term_id, FIREFLY_TEMPLATE_META_KEY, true);
    if (empty($current)) {
        $current = firefly_get_scoping_template();
    }
    ?>
    <tr class="form-field">
        <th scope="row"><label for="firefly_term_template">Template</label></th>
        <td>
            <select name="firefly_term_template" id="firefly_term_template">
                <?php foreach (FIREFLY_VALID_TEMPLATES as $template): ?>
                    <option value="<?php echo esc_attr($template); ?>" <?php selected($current, $template); ?>>
                        <?php echo esc_html(ucfirst($template)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description">Assign this term to a specific template.</p>
        </td>
    </tr>
    <?php
}

/**
 * Save term template on create.
 */
add_action('created_category', 'firefly_save_term_template', 10, 2);
add_action('created_post_tag', 'firefly_save_term_template', 10, 2);

/**
 * Save term template on edit.
 */
add_action('edited_category', 'firefly_save_term_template', 10, 2);
add_action('edited_post_tag', 'firefly_save_term_template', 10, 2);

function firefly_save_term_template($term_id, $tt_id) {
    if (isset($_POST['firefly_term_template'])) {
        $template = sanitize_text_field($_POST['firefly_term_template']);
        if (firefly_is_valid_template($template)) {
            update_term_meta($term_id, FIREFLY_TEMPLATE_META_KEY, $template);
        }
    }
}

// =============================================================================
// SNIPPET EXPORT META BOX
// =============================================================================

/**
 * Add snippet export meta box to pages.
 */
add_action('add_meta_boxes', 'firefly_add_snippet_export_meta_box');

function firefly_add_snippet_export_meta_box() {
    add_meta_box(
        'firefly_snippet_export_meta_box',
        'Snippet Export',
        'firefly_snippet_export_meta_box_callback',
        'page',
        'side',
        'default',
        // Hidden from Gutenberg; a native PluginDocumentSettingPanel replaces it.
        array( '__back_compat_meta_box' => true )
    );
}

/**
 * Render the snippet export meta box.
 */
function firefly_snippet_export_meta_box_callback($post) {
    $template = get_post_meta($post->ID, FIREFLY_TEMPLATE_META_KEY, true);
    $snippet_path = function_exists('firefly_get_page_snippet_path') ? firefly_get_page_snippet_path($post->ID) : null;

    if (!$template) {
        echo '<p class="description">No template assigned to this page.</p>';
        return;
    }

    if (!$snippet_path) {
        echo '<p class="description">This page is not linked to a snippet file in the schema.</p>';
        return;
    }

    $relative_path = str_replace(get_template_directory() . '/', '', $snippet_path);
    $file_exists = file_exists($snippet_path);

    echo '<p><strong>Snippet:</strong><br><code style="font-size:11px;">' . esc_html($relative_path) . '</code></p>';

    if ($file_exists) {
        $file_time = filemtime($snippet_path);
        echo '<p><strong>Last Modified:</strong><br>' . date('Y-m-d H:i:s', $file_time) . '</p>';
    } else {
        echo '<p><em>Snippet file does not exist yet.</em></p>';
    }

    wp_nonce_field('firefly_export_snippet', 'firefly_export_snippet_nonce');
    echo '<input type="hidden" name="firefly_export_page_id" value="' . esc_attr($post->ID) . '">';
    echo '<p><button type="button" class="button" id="firefly-export-snippet-btn">Export to Snippet</button></p>';
    echo '<div id="firefly-export-result" style="margin-top:10px;"></div>';

    // Add inline JavaScript for AJAX export
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('#firefly-export-snippet-btn').on('click', function() {
            var btn = $(this);
            var resultDiv = $('#firefly-export-result');

            btn.prop('disabled', true).text('Exporting...');
            resultDiv.html('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'firefly_export_snippet',
                    page_id: <?php echo $post->ID; ?>,
                    nonce: '<?php echo wp_create_nonce('firefly_export_snippet'); ?>'
                },
                success: function(response) {
                    btn.prop('disabled', false).text('Export to Snippet');
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline" style="padding:8px;"><p>' + response.data.message + '</p></div>');
                    } else {
                        resultDiv.html('<div class="notice notice-error inline" style="padding:8px;"><p>' + response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Export to Snippet');
                    resultDiv.html('<div class="notice notice-error inline" style="padding:8px;"><p>Export failed. Please try again.</p></div>');
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * Handle AJAX snippet export.
 */
add_action('wp_ajax_firefly_export_snippet', 'firefly_ajax_export_snippet');

function firefly_ajax_export_snippet() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'firefly_export_snippet')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }

    // Check permissions
    if (!current_user_can('edit_pages')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }

    // Get page ID
    $page_id = isset($_POST['page_id']) ? intval($_POST['page_id']) : 0;
    if (!$page_id) {
        wp_send_json_error(array('message' => 'Invalid page ID.'));
    }

    // Export
    if (function_exists('firefly_export_page_to_snippet')) {
        $result = firefly_export_page_to_snippet($page_id);
        if ($result) {
            wp_send_json_success(array('message' => 'Content exported to snippet file successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Export failed. Page may not have a snippet defined in schema.'));
        }
    } else {
        wp_send_json_error(array('message' => 'Export function not available.'));
    }
}

/**
 * Enqueue the Gutenberg-native Template Tools panel + the session-fresh
 * panel-defaults manager. Replaces the classic metaboxes (which are hidden
 * via __back_compat_meta_box). The defaults manager runs across ALL firefly
 * Gutenberg panels — not just template-tools — so it lives here, where it
 * loads on every block-editor screen alongside the tools panel.
 */
add_action( 'enqueue_block_editor_assets', 'firefly_enqueue_template_tools_panel' );

function firefly_enqueue_template_tools_panel() {
    $tools_js     = get_template_directory() . '/assets/js/template-tools-panel.js';
    $defaults_js  = get_template_directory() . '/assets/js/panel-defaults.js';
    $tools_ver    = file_exists( $tools_js )    ? filemtime( $tools_js )    : '1';
    $defaults_ver = file_exists( $defaults_js ) ? filemtime( $defaults_js ) : '1';

    // Panel-defaults runs first so its sessionStorage check completes before
    // any user-driven open/close interactions race it.
    wp_enqueue_script(
        'firefly-panel-defaults',
        get_template_directory_uri() . '/assets/js/panel-defaults.js',
        array( 'wp-data', 'wp-dom-ready' ),
        $defaults_ver,
        true
    );

    wp_enqueue_script(
        'firefly-template-tools-panel',
        get_template_directory_uri() . '/assets/js/template-tools-panel.js',
        array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
        $tools_ver,
        true
    );

    // Resolve the snippet info server-side so the panel doesn't need a second
    // REST hop just to display the path / mtime.
    global $post;
    $post_id   = $post ? $post->ID : ( isset( $_GET['post'] ) ? (int) $_GET['post'] : 0 );
    $post_type = $post_id ? get_post_type( $post_id ) : '';

    $snippet_info = null;
    if ( $post_type === 'page' && $post_id ) {
        if ( ! get_post_meta( $post_id, FIREFLY_TEMPLATE_META_KEY, true ) ) {
            $snippet_info = array( 'warning' => __( 'No template assigned to this page.', 'firefly-collective' ) );
        } elseif ( function_exists( 'firefly_get_page_snippet_path' ) ) {
            $snippet_path = firefly_get_page_snippet_path( $post_id );
            if ( $snippet_path ) {
                $rel        = str_replace( get_template_directory() . '/', '', $snippet_path );
                $modified   = file_exists( $snippet_path ) ? date( 'Y-m-d H:i:s', filemtime( $snippet_path ) ) : null;
                $snippet_info = array( 'path' => $rel, 'modified' => $modified );
            } else {
                $snippet_info = array( 'warning' => __( 'This page is not linked to a snippet file in the schema.', 'firefly-collective' ) );
            }
        }
    }

    $valid_templates = function_exists( 'firefly_get_valid_templates' ) ? firefly_get_valid_templates() : array();
    $default_template = function_exists( 'firefly_get_scoping_template' ) ? firefly_get_scoping_template() : '';

    wp_localize_script( 'firefly-template-tools-panel', 'fireflyTemplateTools', array(
        'templates'        => array_values( $valid_templates ),
        'defaultTemplate'  => $default_template,
        'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
        'exportNonce'      => wp_create_nonce( 'firefly_export_snippet' ),
        'snippetInfo'      => $snippet_info,
    ) );
}
