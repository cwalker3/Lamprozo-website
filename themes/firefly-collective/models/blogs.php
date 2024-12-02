<?php

    // Handle Get More Blogs
    function handle_get_more_blogs(WP_REST_Request $request) {
        $params = $request->get_params();

        $page = isset($params['page']) ? intval($params['page']) : 1;

        $posts = get_posts(array(
            'post_type'      => 'post',
            'posts_per_page' => 15,
            'paged'          => $page,
        ));

        $response = array();

        foreach ($posts as $post) {
            setup_postdata($post);
            $response[] = array(
                'title'          => get_the_title($post->ID),
                'excerpt'        => get_the_excerpt($post->ID),
                'permalink'      => get_permalink($post->ID),
                'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
            );
        }
        wp_reset_postdata();

        return rest_ensure_response($response);
    }

    // Handle Filter Blogs
    function handle_filter_blogs(WP_REST_Request $request) {
        $params = $request->get_params();

        $page = isset($params['page']) ? intval($params['page']) : 1;

        $args = array(
            'post_type'      => 'post',
            'posts_per_page' => 15,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        // Apply filters if present
        if (!empty($params['category_id'])) {
            $args['cat'] = intval($params['category_id']);
        }

        if (!empty($params['tag_id'])) {
            $args['tag_id'] = intval($params['tag_id']);
        }

        if (!empty($params['month'])) {
            $args['monthnum'] = intval($params['month']);
        }

        if (!empty($params['year'])) {
            $args['year'] = intval($params['year']);
        }

        if (!empty($params['keywords'])) {
            $args['s'] = sanitize_text_field($params['keywords']);
        }

        $posts = get_posts($args);

        $response = array();

        foreach ($posts as $post) {
            setup_postdata($post);
            $response[] = array(
                'title'          => get_the_title($post->ID),
                'excerpt'        => get_the_excerpt($post->ID),
                'permalink'      => get_permalink($post->ID),
                'author'         => get_the_author('display_name', $post->post_author),
                'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
            );
        }
        wp_reset_postdata();

        return rest_ensure_response($response);
    }

    function get_blog_poster_name( $author_id = null ) {
        if ( is_null( $author_id ) ) {
            $author_id = get_the_author_meta( 'ID' );
        }
    
        $display_name = get_the_author_meta( 'display_name', $author_id );
    
        if ( empty( $display_name ) ) {
            $display_name = __( 'Anonymous', 'your-text-domain' );
        }
    
        return esc_html( apply_filters( 'get_blog_poster_display_name', $display_name, $author_id ) );
    }