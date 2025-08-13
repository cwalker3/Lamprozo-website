<?php

    // Open Graph/Twitter Meta Tags
    function set_open_graph_meta_data() {
        global $template_path;

        $theme_path = get_template_directory_uri();
        if (is_singular()) {
            global $post;
            setup_postdata($post);
            $title = get_the_title();
            $description = get_the_excerpt();
            $image = get_the_post_thumbnail_url($post->ID, 'full');
            $url = get_permalink();
            $type = 'article';
        } else {
            $title = get_bloginfo('name');
            $description = get_bloginfo('description');

            $url = home_url();
            $type = 'website';
        }

        $image = $image ? $image : $template_path . '/images/default-og.webp';
        $description = $description ? $description : 'Firecly Collective website development framework';

        // Output meta tags
        echo '<!-- Open Graph meta tags -->' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '" />' . "\n";
        if ($image) {
            echo '<meta property="og:image" content="' . esc_url($image) . '" />' . "\n";
        }
        echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
        echo '<meta property="og:type" content="' . esc_attr($type) . '" />' . "\n";

        echo '<!-- Twitter Card meta tags -->' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($description) . '" />' . "\n";
        if ($image) {
            echo '<meta name="twitter:image" content="' . esc_url($image) . '" />' . "\n";
        }
    }
    add_action('wp_head', 'set_open_graph_meta_data');