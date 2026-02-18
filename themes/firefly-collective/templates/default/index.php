<?php

    // template/index.php

    // Get the post ID
    $postID = get_the_ID();

    // Get the page title
    $pageTitle = get_the_title();

    // Get theme path
    $themePath = get_template_directory_uri();

    $pageFound = false;
    
    // Get the slug for the page
    $pageSlug = '';
    if (is_single()) {
        $pageFound = true;
        $pageSlug = 'blog-post';
    } elseif (is_page()) {
        $pageFound = true;
        $pageSlug = get_post_field('post_name', $postID);
    }

    $view = determine_view();
    if ( $view !== null && !$pageFound) $pageTitle = slugToTitle($view);

    // Unique ID for cache busting or unique elements
    $unique = wp_unique_id('unique-');

    // Initialize content variables
    $postContent = '';
    $featuredImgHTML = '';
    $content = '';

    if ( is_page() ) $postContent = apply_filters('the_content', get_the_content(null, false, $post));
    if ( is_home() ) {
        $posts_page_id = get_option('page_for_posts'); // Get the ID of the Posts page
        if ( $posts_page_id ) { // Check if a Posts page is set
            $postContent = apply_filters('the_content', get_post_field('post_content', $posts_page_id));
        } else {
            $postContent = ''; // Fallback in case no Posts page is assigned
        }
    }

    if (has_post_thumbnail($postID)) {
        $featuredImgURL = get_the_post_thumbnail_url($postID, 'full');
        $featuredImgHTML = '<img src="' . esc_url($featuredImgURL) . '" alt="' . esc_attr($pageTitle) . '">';
    }

    $template_vars = array(
        'page-title'    => $pageTitle,
        'theme-path'    => $themePath,
        'page-slug'     => $pageSlug,
        'unique'        => $unique,
        'is-single'     => is_single(),
        'is-user-logged-in'  => is_user_logged_in(),
    );

    $content .= $postContent;

    get_header(null, $template_vars);

    if ($postID && is_null($view)) {
        echo apply_filters('the_content', $content);
    } else {
        $view = sanitize_file_name($view ?: '404');
        $view_path = get_template_directory() . '/templates/' . '/' . $active_template . '/views/' . $view . '.php';
        if (!file_exists($view_path)) {
            $view_path = $template_path . '/views/404.php';
        }
        ?>
        <div id="view-content">
            <?php
                include $view_path;
            ?>
        </div>
        <?php
    }

    get_footer(null, $template_vars);