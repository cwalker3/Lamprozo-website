<?php
// Include the functions in your functions.php file or define them here
// ...

// Get the post ID
$postID = get_the_ID();

// Get the page title
$pageTitle = get_the_title();

// Get theme path
$themePath = get_template_directory_uri();

// Get the slug for the page
$pageSlug = '';
if (is_single()) {
    $pageSlug = 'blog-post';
} elseif (is_page()) {
    $pageSlug = get_post_field('post_name', $postID);
}

$view = determine_view();
if ( $view !== null ) $pageTitle = slugToTitle($view);

// Unique ID for cache busting or unique elements
$unique = wp_unique_id('unique-');

// Initialize content variables
$postContent = '';
$featuredImgHTML = '';
$content = '';

if (is_page()) {
    $postContent = apply_filters('the_content', get_the_content(null, false, $post));
}

if (has_post_thumbnail($postID)) {
    $featuredImgURL = get_the_post_thumbnail_url($postID, 'full');
    $featuredImgHTML = '<img src="' . esc_url($featuredImgURL) . '" alt="' . esc_attr($pageTitle) . '">';
}

$template_vars = array(
    'page-title' => $pageTitle,
    'theme-path' => $themePath,
    'page-slug'  => $pageSlug,
    'unique'     => $unique,
    'is-single'  => is_single(),
);

$content .= $postContent;

get_header(null, $template_vars);

// Determine if a custom view should be loaded
$view = determine_view();

if ($postID && is_null($view)) {
    $title = '';
    if (!is_front_page()) {
        $title = '<h1>' . esc_html($pageTitle) . '</h1>';
    }

    echo $title . $featuredImgHTML . apply_filters('the_content', $content);
} elseif (!is_null($view)) {
    $view = sanitize_file_name($view);
    $view_path = get_template_directory() . '/views/' . $view . '.php';

    if (file_exists($view_path)) {
        include $view_path;
    } else {
        // Fallback to 404 if view not found
        include get_template_directory() . '/views/404.php';
    }
} else {
    // If no post ID and no view, load 404
    include get_template_directory() . '/views/404.php';
}

get_footer(null, $template_vars);