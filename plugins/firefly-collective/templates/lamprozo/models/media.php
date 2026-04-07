<?php
/**
 * Media - Plugin Model
 * Manages Videos and Clips stored in wp_options as JSON.
 * Option keys: lamprozo_videos, lamprozo_clips
 */
if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', function() {
    add_menu_page('Media', 'Media', 'manage_options', 'lamprozo-media', 'ffc_media_dashboard', 'dashicons-video-alt3');
});

function ffc_media_dashboard() {
    $view_path = dirname(__FILE__, 2) . '/views/media.php';
    if (file_exists($view_path)) include $view_path;
}

// ── REST API ────────────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    foreach (['videos', 'clips'] as $type) {
        register_rest_route('lamprozo/v1', "/{$type}", [
            'methods'             => 'GET',
            'callback'            => fn($r) => rest_ensure_response(get_option("lamprozo_{$type}", [])),
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);
        register_rest_route('lamprozo/v1', "/{$type}", [
            'methods'             => 'POST',
            'callback'            => function($r) use ($type) {
                $items = $r->get_json_params();
                if (!is_array($items)) return new WP_Error('invalid_data', 'Expected array', ['status' => 400]);
                update_option("lamprozo_{$type}", $items);
                return rest_ensure_response(['success' => true]);
            },
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);
    }
});
