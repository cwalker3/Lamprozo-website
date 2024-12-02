<?php

    function custom_theme_setup_pages() {
        $pages = array(
            'home' => array(
                'title'   => 'Home',
                'content' => 'This is the homepage.',
            ),
            'blog' => array(
                'title'   => 'Blog',
                'content' => 'This is the blog.',
            ),
            'contact' => array(
                'title'   => 'Contact',
                'content' => 'This is the contact page.',
            ),
            'signup' => array(
                'title'   => 'Signup',
                'content' => 'This is the signup page.',
            ),
        );

        $page_ids = array();

        foreach ($pages as $slug => $page_data) {
            // Check if the page already exists
            $page = get_page_by_path($slug);

            if (!$page) {
                // Page doesn't exist, so create it
                $page_id = wp_insert_post(array(
                    'post_title'   => $page_data['title'],
                    'post_content' => $page_data['content'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_name'    => $slug,
                ));
                $page_ids[$slug] = $page_id;
            } else {
                // Page exists, store its ID
                $page_ids[$slug] = $page->ID;
            }
        }

        // Set 'Home' as the static front page
        if (isset($page_ids['home'])) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $page_ids['home']);
        }

        // Set 'Blog' as the posts page
        if (isset($page_ids['blog'])) {
            update_option('page_for_posts', $page_ids['blog']);
        }

        // Create 'Main Website Menu' and assign it to the 'website-menu' location
        $menu_name = 'Main Website Menu';
        $menu_exists = wp_get_nav_menu_object($menu_name);

        if (!$menu_exists) {
            // Create the menu
            $menu_id = wp_create_nav_menu($menu_name);

            // Assign the menu to the 'website-menu' theme location
            $locations = get_theme_mod('nav_menu_locations');
            $locations['website-menu'] = $menu_id;
            set_theme_mod('nav_menu_locations', $locations);

            // Add pages to the menu
            foreach ($page_ids as $slug => $page_id) {
                wp_update_nav_menu_item($menu_id, 0, array(
                    'menu-item-title'     => $pages[$slug]['title'],
                    'menu-item-object'    => 'page',
                    'menu-item-object-id' => $page_id,
                    'menu-item-type'      => 'post_type',
                    'menu-item-status'    => 'publish',
                ));
            }
        }
    }
    add_action('after_switch_theme', 'custom_theme_setup_pages');