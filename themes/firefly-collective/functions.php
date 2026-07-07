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
        (function(){
            if(!navigator.sendBeacon) return;
            var REST='/wp-json/firefly-collective/v1/';
            function uuid(){
                if(window.crypto&&crypto.randomUUID){try{return crypto.randomUUID();}catch(e){}}
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,function(c){
                    var r=Math.random()*16|0,v=c=='x'?r:(r&0x3|0x8);return v.toString(16);
                });
            }
            // Visit id: persists across in-tab navigations (sessionStorage,
            // cleared on tab close). is-entry = first pageview of the visit.
            var VK='ffa_vid',vid='',isEntry=0;
            try{vid=sessionStorage.getItem(VK);if(!vid){vid=uuid();sessionStorage.setItem(VK,vid);isEntry=1;}}
            catch(e){vid=uuid();isEntry=1;}
            var eid=uuid();
            var q;try{q=new URLSearchParams(location.search);}catch(e){q=null;}
            function utm(k){try{return (q&&q.get(k))||'';}catch(e){return '';}}
            navigator.sendBeacon(REST+'hit',JSON.stringify({
                p:location.pathname,t:document.title,r:document.referrer,
                i:<?php echo $post_id; ?>,y:'<?php echo esc_js($post_type); ?>',tp:'<?php echo esc_js($template); ?>',
                vs:vid,ev:eid,e:isEntry,sw:(window.screen&&screen.width)||0,
                us:utm('utm_source'),um:utm('utm_medium'),uc:utm('utm_campaign')
            }));
            // Engagement: dwell time + max scroll depth, flushed when the
            // page is hidden / unloaded. Server keeps the high-water mark.
            var start=Date.now(),maxScroll=0;
            function pct(){
                var de=document.documentElement,bd=document.body;
                var full=Math.max(de.scrollHeight,bd?bd.scrollHeight:0)-window.innerHeight;
                if(full<=0) return 100;
                return Math.min(100,Math.round((window.scrollY/full)*100));
            }
            window.addEventListener('scroll',function(){var p=pct();if(p>maxScroll)maxScroll=p;},{passive:true});
            var lastSent=-1;
            function flush(){
                var d=Math.round((Date.now()-start)/1000);
                // Skip a redundant flush if nothing changed since the last one.
                if(d===lastSent) return; lastSent=d;
                try{navigator.sendBeacon(REST+'engagement',JSON.stringify({ev:eid,d:d,sd:maxScroll}));}catch(e){}
            }
            document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')flush();});
            window.addEventListener('pagehide',flush);
        })();
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