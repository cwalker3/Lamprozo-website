<?php
/**
 * Glow Template - Pages Model
 *
 * Uses the schema-driven system to define and create pages.
 */

/**
 * Get the page structure for this template.
 * Used by nav.php and other components that need page info.
 */
function get_theme_pages_structure() {
    global $active_template;
    return firefly_get_schema_pages($active_template);
}

/**
 * Create pages from schema on theme activation.
 */
function custom_theme_setup_pages() {
    global $active_template;

    // Create pages from schema
    $page_ids = firefly_create_template_pages($active_template);

    // Create categories from schema
    firefly_create_template_categories($active_template);

    // Create posts from schema
    firefly_create_template_posts($active_template);

    // Set WordPress front page and posts page options
    $front_page_id = isset($page_ids['home']) ? $page_ids['home'] : 0;
    $posts_page_id = isset($page_ids['blog']) ? $page_ids['blog'] : 0;

    if ($front_page_id) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $front_page_id);
    }

    if ($posts_page_id) {
        update_option('page_for_posts', $posts_page_id);
    }
}
add_action('after_switch_theme', 'custom_theme_setup_pages');
