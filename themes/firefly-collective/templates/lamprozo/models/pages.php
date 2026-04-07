<?php
/**
 * Lamprozo Template - Pages
 */

function lamprozo_get_pages_structure() {
    global $active_template;
    return firefly_get_schema_pages($active_template);
}

function lamprozo_setup_pages() {
    global $active_template;

    // Create pages from schema
    $page_ids = firefly_create_template_pages($active_template);

    // Create categories from schema
    firefly_create_template_categories($active_template);

    // Create posts from schema
    firefly_create_template_posts($active_template);

    // Set front page
    if (isset($page_ids['home'])) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $page_ids['home']);
    }

    // Set posts page
    $blog_key = isset($page_ids['blog']) ? 'blog' : (isset($page_ids['newsroom']) ? 'newsroom' : null);
    if ($blog_key) {
        update_option('page_for_posts', $page_ids[$blog_key]);
    }
}
add_action('after_switch_theme', 'lamprozo_setup_pages');
