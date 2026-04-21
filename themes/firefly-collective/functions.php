<?php

    // theme/functions.php

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    // Load template model
    require_once get_template_directory() . '/template.php';

    // Get active template
    global $active_template, $template_path, $template_path_web;
    $active_template    = firefly_collective_get_active_template();
    $template_path      = get_template_directory() . '/templates/' . $active_template;
    $template_path_web  = get_template_directory_uri() . '/templates/' . $active_template;

    // Load template functions
    require_once $template_path . '/functions.php';

    // Load custom Gutenberg blocks (framework-level, shared across templates)
    require_once get_template_directory() . '/blocks/register.php';

    // Inject analytics tracking script (all templates)
    add_action('wp_footer', function() {
        if (is_admin()) return;

        // Check if we're on local and tracking is disabled
        $is_local = defined('FIREFLY_DEV') && FIREFLY_DEV;

        if ($is_local && !get_option('firefly_analytics_track_local', false)) {
            return; // Skip tracking on local when disabled
        }

        $template = firefly_collective_get_active_template();
        $post_id = get_the_ID() ?: 'null';
        $post_type = get_post_type() ?: '';
        ?>
        <script>
        (function(){if(navigator.sendBeacon){navigator.sendBeacon('/wp-json/firefly-collective/v1/hit',JSON.stringify({p:location.pathname,t:document.title,r:document.referrer,i:<?php echo $post_id; ?>,y:'<?php echo esc_js($post_type); ?>',tp:'<?php echo esc_js($template); ?>'}));}})();
        </script>
        <?php
    }, 999);

    // Inject link click tracking script (all templates)
    add_action('wp_footer', function() {
        if (is_admin()) return;

        // Check if we're on local and tracking is disabled
        $is_local = defined('FIREFLY_DEV') && FIREFLY_DEV;

        if ($is_local && !get_option('firefly_analytics_track_local', false)) {
            return; // Skip tracking on local when disabled
        }

        $post_id = get_the_ID();
        if (!$post_id) return; // Only track on single posts/pages

        ?>
        <script>
        (function(){
            document.addEventListener('DOMContentLoaded', function() {
                var trackedLinks = document.querySelectorAll('a[data-track-clicks="true"]');

                trackedLinks.forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        var linkHash = this.getAttribute('data-link-hash');

                        if (linkHash && navigator.sendBeacon) {
                            navigator.sendBeacon(
                                '/wp-json/firefly-collective/v1/link-click',
                                JSON.stringify({
                                    h: linkHash,
                                    p: <?php echo $post_id; ?>,
                                    r: document.referrer
                                })
                            );
                        }
                    });
                });
            });
        })();
        </script>
        <?php
    }, 999);

    // Track login page views via login_footer (wp-login.php doesn't use theme footer)
    add_action('login_footer', function() {
        $is_local = defined('FIREFLY_DEV') && FIREFLY_DEV;

        if ($is_local && !get_option('firefly_analytics_track_local', false)) {
            return;
        }

        $template = function_exists('firefly_collective_get_active_template')
            ? firefly_collective_get_active_template()
            : 'firefly';
        ?>
        <script>
        (function(){if(navigator.sendBeacon){navigator.sendBeacon('/wp-json/firefly-collective/v1/admin-activity',JSON.stringify({type:'login_page_view',tp:'<?php echo esc_js($template); ?>'}));}})();
        </script>
        <?php
    }, 999);