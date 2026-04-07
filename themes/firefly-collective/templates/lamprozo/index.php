<?php
/**
 * Lamprozo Template - Main Index
 */

// Get active template
$active_template = firefly_collective_get_active_template();

// Get post info
$postID = get_the_ID();
$pageTitle = get_the_title();
$themePath = get_template_directory_uri();

// Determine view
$view = determine_view();
if ($view !== null && !is_page()) {
    $pageTitle = slugToTitle($view);
}

// Template variables
$template_vars = array(
    'page-title'        => $pageTitle,
    'theme-path'        => $themePath,
    'page-slug'         => get_post_field('post_name', $postID),
    'is-single'         => is_single(),
    'is-user-logged-in' => is_user_logged_in(),
);

// Get page content
$postContent = '';

if (is_page() || ($view && $postID)) {
    $postContent = apply_filters('the_content', get_post_field('post_content', $postID));
}
if (is_home()) {
    $posts_page_id = get_option('page_for_posts');
    if ($posts_page_id) {
        $postContent = apply_filters('the_content', get_post_field('post_content', $posts_page_id));
    }
}
// For custom views that have a matching WP page by slug
if ($view && empty($postContent)) {
    $view_page = get_posts(array(
        'post_type'   => 'page',
        'name'        => $view,
        'post_status' => 'publish',
        'meta_key'    => '_firefly_template',
        'meta_value'  => $active_template,
        'numberposts' => 1,
    ));
    if (!empty($view_page)) {
        $postContent = apply_filters('the_content', $view_page[0]->post_content);
        $pageTitle = $view_page[0]->post_title;
    }
}

$content = $postContent;

get_header(null, $template_vars);

// Check for custom view file
$view_path = get_template_directory() . '/templates/' . $active_template . '/views/' . $view . '.php';
if ($view && file_exists($view_path)) {
    echo '<div id="view-content">';
    include $view_path;
    echo '</div>';
} elseif ($postID) {
    echo apply_filters('the_content', $content);
} else {
    // 404 fallback
    include get_template_directory() . '/templates/' . $active_template . '/views/404.php';
}

get_footer(null, $template_vars);
