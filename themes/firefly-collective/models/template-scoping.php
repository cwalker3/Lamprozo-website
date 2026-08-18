<?php
/**
 * Template Content Scoping
 *
 * Scopes posts, pages, categories, tags, and media to specific templates.
 * When switching templates, only content assigned to that template is visible.
 */

// Constants
define('FIREFLY_TEMPLATE_META_KEY', '_firefly_template');

/**
 * Expose _firefly_template via REST so the Gutenberg Template Assignment
 * panel can read/write it through wp.data.dispatch('core/editor').editPost.
 * The classic meta box still uses raw $_POST so registration is purely
 * additive — no save_post conflict.
 */
add_action( 'init', function () {
    foreach ( array( 'post', 'page' ) as $post_type ) {
        register_post_meta( $post_type, FIREFLY_TEMPLATE_META_KEY, array(
            'show_in_rest'      => true,
            'single'            => true,
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => function () { return current_user_can( 'edit_posts' ); },
        ) );
    }
} );

/**
 * Get valid templates dynamically by checking existing template directories.
 */
function firefly_get_valid_templates() {
    static $templates = null;

    if ($templates === null) {
        $templates = array();
        $templates_dir = get_template_directory() . '/templates';

        if (is_dir($templates_dir)) {
            $dirs = scandir($templates_dir);
            foreach ($dirs as $dir) {
                if ($dir !== '.' && $dir !== '..' && is_dir($templates_dir . '/' . $dir)) {
                    // Check if it has the required files (header.php, footer.php)
                    if (file_exists($templates_dir . '/' . $dir . '/header.php') &&
                        file_exists($templates_dir . '/' . $dir . '/footer.php')) {
                        $templates[] = $dir;
                    }
                }
            }
        }

        // Fallback if no templates found
        if (empty($templates)) {
            $templates = array('firefly', 'default', 'glow');
        }
    }

    return $templates;
}

/**
 * Get the template to use for content scoping.
 * Respects Customizer iframe context (uses temp template when previewing).
 */
function firefly_get_scoping_template() {
    if (function_exists('in_customizer_iframe') && in_customizer_iframe()) {
        return get_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
    }
    return get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
}

/**
 * Check if a template name is valid.
 */
function firefly_is_valid_template($template) {
    return in_array($template, firefly_get_valid_templates(), true);
}

// =============================================================================
// SLUG DEDUPLICATION OVERRIDE
// =============================================================================

/**
 * Allow duplicate post slugs across templates.
 *
 * WordPress enforces globally unique slugs via wp_unique_post_slug().
 * Since templates scope content independently, two templates can safely
 * share the same slug (e.g. both have "home"). This filter short-circuits
 * the deduplication when the "conflict" is with a page from a different template.
 */
add_filter( 'wp_unique_post_slug', 'firefly_allow_cross_template_slugs', 10, 6 );

function firefly_allow_cross_template_slugs( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
    // Only act when WordPress actually changed the slug
    if ( $slug === $original_slug ) {
        return $slug;
    }

    // Only handle pages and posts
    if ( ! in_array( $post_type, array( 'page', 'post' ), true ) ) {
        return $slug;
    }

    // Get this post's template
    $template = get_post_meta( $post_id, FIREFLY_TEMPLATE_META_KEY, true );
    if ( empty( $template ) ) {
        return $slug;
    }

    // Check if any post using the original slug belongs to the SAME template
    global $wpdb;
    $conflict_in_same_template = $wpdb->get_var( $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_name = %s
           AND p.post_type = %s
           AND p.ID != %d
           AND pm.meta_key = %s
           AND pm.meta_value = %s
         LIMIT 1",
        $original_slug,
        $post_type,
        $post_id,
        FIREFLY_TEMPLATE_META_KEY,
        $template
    ) );

    // If no conflict within the same template, keep the original slug
    if ( ! $conflict_in_same_template ) {
        return $original_slug;
    }

    // Conflict is within the same template — let WordPress deduplicate
    return $slug;
}

// =============================================================================
// QUERY FILTERING
// =============================================================================

/**
 * Resolve duplicate page slugs to the correct template-scoped page.
 * WordPress picks the first page by ID when slugs collide — this
 * intercepts and points the query at the right page for the active template.
 */
add_action('pre_get_posts', 'firefly_resolve_scoped_page_slug', 5, 1);

function firefly_resolve_scoped_page_slug($query) {
    if (!$query->is_main_query() || is_admin()) {
        return;
    }

    $pagename = $query->get('pagename');
    if (!$pagename) {
        return;
    }

    // Strip any parent prefix (e.g. "parent/child" → "child")
    $slug = basename($pagename);
    $template = firefly_get_scoping_template();
    $page = firefly_get_scoped_page($slug, $template);

    if ($page) {
        $page_for_posts = (int) get_option('page_for_posts');

        if ($page->ID === $page_for_posts) {
            // This is the blog/posts page — WordPress should treat it as is_home()
            $query->set('pagename', '');
            $query->set('page_id', 0);
            $query->is_page = false;
            $query->is_singular = false;
            $query->is_home = true;
            $query->is_posts_page = true;
        } else {
            // Regular page — direct ID lookup
            $query->set('pagename', '');
            $query->set('page_id', $page->ID);
            $query->is_page = true;
            $query->is_singular = true;
            // Force the queried object to the scoped page. WordPress resolves a
            // shared slug to the lowest-ID page (which may be another template's)
            // and caches it as the queried object; without this, is_front_page()
            // and is_page(page_on_front) compare against the wrong page, so the
            // front page isn't recognized when accessed via its slug URL.
            $query->queried_object    = $page;
            $query->queried_object_id = $page->ID;
        }
    }
}

/**
 * Filter main queries to only show template-scoped content.
 * Handles posts, pages, and attachments.
 */
add_action('pre_get_posts', 'firefly_template_scope_query', 10, 1);

function firefly_template_scope_query($query) {
    // Skip if explicitly told to skip scoping
    if ($query->get('firefly_skip_scoping')) {
        return;
    }

    // Skip in admin unless we're doing AJAX (media library) or explicitly filtering
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }

    // Get the scoping template
    $template = firefly_get_scoping_template();

    // Get current post types being queried
    $post_type = $query->get('post_type');

    // Apply to relevant post types
    $scopable_types = array('post', 'page', 'attachment', '');

    // Check if this query should be scoped
    $should_scope = false;

    if (empty($post_type) && $query->is_main_query()) {
        $should_scope = true;
    } elseif (is_string($post_type) && in_array($post_type, $scopable_types)) {
        $should_scope = true;
    } elseif (is_array($post_type)) {
        foreach ($post_type as $type) {
            if (in_array($type, $scopable_types)) {
                $should_scope = true;
                break;
            }
        }
    }

    if ($should_scope) {
        $meta_query = $query->get('meta_query') ?: array();
        $meta_query[] = array(
            'key'     => FIREFLY_TEMPLATE_META_KEY,
            'value'   => $template,
            'compare' => '='
        );
        $query->set('meta_query', $meta_query);
    }
}

/**
 * Filter get_pages() to respect template scoping.
 * This is critical for Customizer page dropdowns.
 */
add_filter('get_pages', 'firefly_filter_scoped_pages', 10, 2);

function firefly_filter_scoped_pages($pages, $args) {
    // Skip if explicitly told to skip
    if (isset($args['firefly_skip_scoping']) && $args['firefly_skip_scoping']) {
        return $pages;
    }

    $template = firefly_get_scoping_template();

    $filtered = array();
    foreach ($pages as $page) {
        $page_template = get_post_meta($page->ID, FIREFLY_TEMPLATE_META_KEY, true);
        if ($page_template === $template) {
            $filtered[] = $page;
        }
    }

    return $filtered;
}

/**
 * Filter media library queries (AJAX).
 */
add_filter('ajax_query_attachments_args', 'firefly_scope_media_library', 10, 1);

function firefly_scope_media_library($query) {
    $template = firefly_get_scoping_template();

    $query['meta_query'] = isset($query['meta_query']) ? $query['meta_query'] : array();
    $query['meta_query'][] = array(
        'key'     => FIREFLY_TEMPLATE_META_KEY,
        'value'   => $template,
        'compare' => '='
    );

    return $query;
}

// =============================================================================
// TAXONOMY FILTERING
// =============================================================================

/**
 * Filter category and tag queries to respect template scoping.
 */
add_filter('get_terms_args', 'firefly_template_scope_terms', 10, 2);

function firefly_template_scope_terms($args, $taxonomies) {
    // Skip if explicitly told to skip
    if (isset($args['firefly_skip_scoping']) && $args['firefly_skip_scoping']) {
        return $args;
    }

    // Only filter categories and tags
    $scoped_taxonomies = array('category', 'post_tag');
    $should_scope = false;

    foreach ($taxonomies as $taxonomy) {
        if (in_array($taxonomy, $scoped_taxonomies)) {
            $should_scope = true;
            break;
        }
    }

    if (!$should_scope) {
        return $args;
    }

    // Skip in admin unless doing AJAX
    if (is_admin() && !wp_doing_ajax()) {
        return $args;
    }

    $template = firefly_get_scoping_template();

    $args['meta_query'] = (isset($args['meta_query']) && is_array($args['meta_query'])) ? $args['meta_query'] : array();
    $args['meta_query'][] = array(
        'key'     => FIREFLY_TEMPLATE_META_KEY,
        'value'   => $template,
        'compare' => '='
    );

    return $args;
}

/**
 * Scope nav_menu NAME lookups to the active template.
 *
 * Menu names are meant to repeat across templates (each template has its own
 * "Main Menu", distinguished by slug + term meta), but WordPress core enforces
 * globally unique menu names: wp_update_nav_menu_object() runs a duplicate-name
 * check via get_term_by( 'name', ... ) — a raw get_terms that bypasses the
 * wp_get_nav_menus list scoping. Result: every "Save Menu" in wp-admin errored
 * with "The menu name Main Menu conflicts with another menu name" as soon as a
 * second template owned a same-named menu.
 *
 * This filter narrows ONLY name lookups ($args['name'] set) on the nav_menu
 * taxonomy to menus belonging to the active template — plus untagged menus, so
 * legacy menus without term meta still conflict globally rather than silently
 * duplicating. Same-template duplicates still conflict, as they should.
 * Deliberately active in wp-admin (that's where the check runs). Callers that
 * need a cross-template name lookup pass firefly_skip_scoping.
 */
add_filter('get_terms_args', 'firefly_template_scope_nav_menu_name_lookups', 10, 2);

function firefly_template_scope_nav_menu_name_lookups($args, $taxonomies) {
    if (isset($args['firefly_skip_scoping']) && $args['firefly_skip_scoping']) {
        return $args;
    }

    if (!in_array('nav_menu', (array) $taxonomies, true)) {
        return $args;
    }

    if (empty($args['name'])) {
        return $args;
    }

    $template = firefly_get_scoping_template();

    $args['meta_query'] = (isset($args['meta_query']) && is_array($args['meta_query'])) ? $args['meta_query'] : array();
    $args['meta_query'][] = array(
        'relation' => 'OR',
        array(
            'key'     => FIREFLY_TEMPLATE_META_KEY,
            'value'   => $template,
            'compare' => '=',
        ),
        array(
            'key'     => FIREFLY_TEMPLATE_META_KEY,
            'compare' => 'NOT EXISTS',
        ),
    );

    return $args;
}

// =============================================================================
// POST NAVIGATION FILTERING
// =============================================================================

/**
 * Filter adjacent posts (prev/next) for blog post navigation.
 */
add_filter('get_previous_post_where', 'firefly_scope_adjacent_post_where', 10, 5);
add_filter('get_next_post_where', 'firefly_scope_adjacent_post_where', 10, 5);

function firefly_scope_adjacent_post_where($where, $in_same_term, $excluded_terms, $taxonomy, $post) {
    global $wpdb;

    $template = firefly_get_scoping_template();

    $where .= $wpdb->prepare(
        " AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s)",
        FIREFLY_TEMPLATE_META_KEY,
        $template
    );

    return $where;
}

// =============================================================================
// AUTO-ASSIGNMENT ON CONTENT CREATION
// =============================================================================

/**
 * Auto-assign the active template to any post/page/attachment that ends up
 * with empty _firefly_template meta. Idempotent: bails immediately when the
 * meta is already set, so it's safe to fire on every save.
 *
 * Previously this only ran for new inserts ($update === false), but Gutenberg's
 * "Add New Post" flow first creates an auto-draft (skipped by the status check
 * below), then PUBLISHES via an update — and the update path used to bail
 * unconditionally, leaving the post with the default empty meta value the
 * REST register_post_meta() exposes. Empty meta makes the admin-list scoping
 * filter exclude the post AND blocks the snippet save_post hook from writing
 * the HTML file, so the post effectively vanishes from local without anything
 * showing up in version control. Treating every save as a fill-empty pass
 * closes that hole without affecting posts that already carry a valid value.
 */
add_action('wp_insert_post', 'firefly_assign_template_to_new_post', 10, 3);

function firefly_assign_template_to_new_post($post_id, $post, $update) {
    // Skip revisions and auto-drafts
    if (wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') {
        return;
    }

    // Only for scopable post types
    if (!in_array($post->post_type, array('post', 'page', 'attachment'))) {
        return;
    }

    // Check if already has template assignment
    $existing = get_post_meta($post_id, FIREFLY_TEMPLATE_META_KEY, true);
    if (!empty($existing)) {
        return;
    }

    $template = firefly_get_scoping_template();
    update_post_meta($post_id, FIREFLY_TEMPLATE_META_KEY, $template);
}

/**
 * REST post/page saves apply registered post-meta AFTER wp_insert_post fires.
 * Gutenberg's editor holds _firefly_template in its local state and sends it
 * back on save, sourced from the meta's registered default of '' — so even
 * if wp_insert_post above filled the meta correctly, the REST meta write that
 * follows clobbers it back to empty. rest_after_insert_{post,page} runs AFTER
 * the meta write, so this handler gets the last word and restores the active
 * template when the value lands empty.
 */
foreach (array('post', 'page') as $firefly_rest_type) {
    add_action('rest_after_insert_' . $firefly_rest_type, 'firefly_rest_fill_empty_template_meta', 10, 1);
}

function firefly_rest_fill_empty_template_meta($post) {
    if (!($post instanceof WP_Post)) {
        return;
    }
    if (wp_is_post_revision($post->ID) || $post->post_status === 'auto-draft') {
        return;
    }

    $existing = get_post_meta($post->ID, FIREFLY_TEMPLATE_META_KEY, true);
    if (!empty($existing)) {
        return;
    }

    $template = firefly_get_scoping_template();
    update_post_meta($post->ID, FIREFLY_TEMPLATE_META_KEY, $template);

    // The original save_post snippet sync already ran with empty meta and
    // bailed — call it explicitly now that the meta is filled, so the
    // snippet HTML + schema entry land without requiring the user to
    // re-save the post.
    if (function_exists('firefly_save_snippet')) {
        firefly_save_snippet($post->ID);
    }
}

/**
 * Auto-assign template to new terms (categories, tags).
 */
add_action('created_term', 'firefly_assign_template_to_new_term', 10, 3);

function firefly_assign_template_to_new_term($term_id, $tt_id, $taxonomy) {
    if (!in_array($taxonomy, array('category', 'post_tag'))) {
        return;
    }

    $template = firefly_get_scoping_template();
    update_term_meta($term_id, FIREFLY_TEMPLATE_META_KEY, $template);
}

/**
 * Auto-assign template to uploaded media.
 */
add_action('add_attachment', 'firefly_assign_template_to_new_media', 10, 1);

function firefly_assign_template_to_new_media($post_id) {
    // Check if already has template assignment
    $existing = get_post_meta($post_id, FIREFLY_TEMPLATE_META_KEY, true);
    if (!empty($existing)) {
        return;
    }

    $template = firefly_get_scoping_template();
    update_post_meta($post_id, FIREFLY_TEMPLATE_META_KEY, $template);
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Get all content (posts, pages, media) for a specific template.
 */
function firefly_get_template_content($template, $post_type = 'any') {
    return get_posts(array(
        'post_type'      => $post_type,
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'meta_query'     => array(
            array(
                'key'     => FIREFLY_TEMPLATE_META_KEY,
                'value'   => $template,
                'compare' => '='
            )
        ),
        'firefly_skip_scoping' => true
    ));
}

/**
 * Assign content to a specific template.
 */
function firefly_assign_content_to_template($post_id, $template) {
    if (!firefly_is_valid_template($template)) {
        return false;
    }
    return update_post_meta($post_id, FIREFLY_TEMPLATE_META_KEY, $template);
}

/**
 * Assign term to a specific template.
 */
function firefly_assign_term_to_template($term_id, $template) {
    if (!firefly_is_valid_template($template)) {
        return false;
    }
    return update_term_meta($term_id, FIREFLY_TEMPLATE_META_KEY, $template);
}

/**
 * Get the template assigned to a piece of content.
 */
function firefly_get_content_template($post_id) {
    return get_post_meta($post_id, FIREFLY_TEMPLATE_META_KEY, true);
}

/**
 * Get the template assigned to a term.
 */
function firefly_get_term_template($term_id) {
    return get_term_meta($term_id, FIREFLY_TEMPLATE_META_KEY, true);
}

// =============================================================================
// CUSTOMIZER FRONT PAGE HANDLING
// =============================================================================

/**
 * In customizer iframe, override front page to use the temp template's front page.
 * This ensures the correct home page shows when previewing different templates.
 */
add_filter('option_page_on_front', 'firefly_filter_front_page_for_customizer', 10, 1);

function firefly_filter_front_page_for_customizer($page_id) {
    // Only in customizer iframe
    if (!function_exists('in_customizer_iframe') || !in_customizer_iframe()) {
        return $page_id;
    }

    // Always use the temp template's front page in customizer.
    // We can't compare temp vs active because the Customizer changeset
    // overrides the active option in the preview, making them always equal.
    $temp_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);

    // Get the temp template's front page
    $temp_front_page = get_option("firefly_front_page_{$temp_template}");
    if ($temp_front_page) {
        error_log("[CUSTOMIZER] front_page filter: using {$temp_template} front page={$temp_front_page} (original={$page_id})");
        return $temp_front_page;
    }

    // If no stored front page, try to find the home page for this template
    $home_page = firefly_get_scoped_page('home', $temp_template);
    if ($home_page) {
        error_log("[CUSTOMIZER] front_page filter: found scoped home page {$home_page->ID} for {$temp_template}");
        return $home_page->ID;
    }

    error_log("[CUSTOMIZER] front_page filter: NO front page found for {$temp_template}, keeping {$page_id}");
    return $page_id;
}

/**
 * Filter posts page for customizer as well.
 */
add_filter('option_page_for_posts', 'firefly_filter_posts_page_for_customizer', 10, 1);

function firefly_filter_posts_page_for_customizer($page_id) {
    // Only in customizer iframe
    if (!function_exists('in_customizer_iframe') || !in_customizer_iframe()) {
        return $page_id;
    }

    // Always use the temp template's posts page in customizer.
    $temp_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);

    // Get the temp template's posts page
    $temp_posts_page = get_option("firefly_posts_page_{$temp_template}");
    if ($temp_posts_page) {
        return $temp_posts_page;
    }

    // If no stored posts page, try to find the blog page for this template
    $blog_page = firefly_get_scoped_page('blog', $temp_template);
    if ($blog_page) {
        return $blog_page->ID;
    }

    return $page_id;
}

// =============================================================================
// CUSTOMIZER HOMEPAGE SETTINGS VISIBILITY
// =============================================================================

/**
 * Force the core "Homepage Settings" customizer section to stay visible.
 *
 * Background: WordPress core's WP_Customize_Manager::has_published_pages()
 * checks for a published page via get_pages(['number' => 1]) and uses the
 * result as the active_callback for the static_front_page section. The
 * LIMIT is applied in SQL before our firefly_filter_scoped_pages() runs,
 * so if the single page WP fetched doesn't belong to the active firefly
 * template, our filter discards it — has_published_pages() returns false,
 * and the section is hidden, even when the active template HAS pages.
 *
 * Fix: override the section's active state. If the active firefly template
 * (or its temp/preview counterpart in the customizer iframe) has any
 * published page, force the section visible.
 */
add_filter( 'customize_section_active', 'firefly_force_static_front_page_active', 10, 2 );

function firefly_force_static_front_page_active( $active, $section ) {
    if ( ! is_object( $section ) || $section->id !== 'static_front_page' ) {
        return $active;
    }

    // Use temp template in customizer iframe context, active template otherwise.
    if ( function_exists( 'in_customizer_iframe' ) && in_customizer_iframe() ) {
        $template = get_option( FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE );
    } else {
        $template = firefly_get_scoping_template();
    }

    // Count scoped pages without going through our get_pages filter
    // (firefly_skip_scoping bypasses it for this query only).
    $pages = get_posts( array(
        'post_type'            => 'page',
        'post_status'          => 'publish',
        'numberposts'          => 1,
        'meta_key'             => FIREFLY_TEMPLATE_META_KEY,
        'meta_value'           => $template,
        'firefly_skip_scoping' => true,
        'fields'               => 'ids',
        'suppress_filters'     => false, // we WANT pre_get_posts to run with our skip flag
    ) );

    return ! empty( $pages ) ? true : $active;
}
