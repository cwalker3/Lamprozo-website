<?php
/**
 * Lamprozo Template - View Routing
 */

function parse_request_uri() {
    $uri = $_SERVER['REQUEST_URI'];
    $parsed = parse_url($uri);
    $path = $parsed['path'] ?? '';
    return explode('/', trim($path, '/'));
}

function determine_view() {
    if (is_single()) return 'blog-post';
    if (is_home()) return 'blog';

    $segments = parse_request_uri();
    if (!empty($segments[0])) {
        $view = sanitize_title($segments[0]);

        $static_views = array('home', 'challenges', 'contact', 'about', 'blog', 'videos', 'clips');
        if (in_array($view, $static_views)) {
            return $view;
        }

        // Dynamic challenge routing
        if (function_exists('lamprozo_get_challenges')) {
            $challenge_slugs = array_keys(lamprozo_get_challenges());
            if (in_array($view, $challenge_slugs)) {
                return 'challenge';
            }
        }
    }

    if (is_page()) {
        $page = get_queried_object();
        return $page->post_name ?? null;
    }

    return null;
}

function get_view_assets($view, $theme_path) {
    $active_template = firefly_collective_get_active_template();
    $template_path = $theme_path . '/templates/' . $active_template;

    $assets = array('js' => array(), 'css' => array());

    // Add view-specific assets here
    switch ($view) {
        case 'home':
            // Twitch embed rendered server-side in home.php (see kses sync constraint)
            break;
        case 'contact':
            // $assets['js'][] = $template_path . '/assets/js/contact.js';
            break;

        case 'blog':
            $assets['css'][]    = $template_path . '/assets/css/blog.css';
            $assets['js'][]     = $template_path . '/assets/js/blog.js';
            break;

        case 'videos':
            $assets['css'][]    = $template_path . '/assets/css/videos.css';
            $assets['js'][]     = $template_path . '/assets/js/videos.js';
            break;

        case 'clips':
            $assets['css'][]    = $template_path . '/assets/css/clips.css';
            $assets['js'][]     = $template_path . '/assets/js/clips.js';
            break;
    }

    return $assets;
}

function lamprozo_handle_custom_views() {
    if (determine_view()) {
        status_header(200);
    }
}
add_action('template_redirect', 'lamprozo_handle_custom_views');
