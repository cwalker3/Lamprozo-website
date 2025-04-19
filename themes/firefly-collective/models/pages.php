<?php

    function custom_theme_setup_pages() {
        $pages = array(
            'home'      => array('title' => 'Home',      'content' => 'This is the homepage.'),
            'blog'      => array('title' => 'Blog',      'content' => 'This is the blog.'),
            'contact'   => array('title' => 'Contact',   'content' => 'This is the contact page.'),
            'signup'    => array('title' => 'Signup',    'content' => 'This is the signup page.'),
            'dashboard' => array('title' => 'Dashboard', 'content' => 'This is the dashboard page.'),
        );

        // 1. Create pages if they don't exist
        $page_ids = array();
        foreach ($pages as $slug => $data) {
            $page = get_page_by_path($slug);
            if (! $page) {
                $page_id = wp_insert_post(array(
                    'post_title'   => $data['title'],
                    'post_content' => $data['content'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_name'    => $slug,
                ));
                $page_ids[$slug] = $page_id;
            } else {
                $page_ids[$slug] = $page->ID;
            }
        }

        // 2. Set front‐page and posts page
        if (! empty($page_ids['home'])) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $page_ids['home']);
        }
        if (! empty($page_ids['blog'])) {
            update_option('page_for_posts', $page_ids['blog']);
        }

        // 3. Create or fetch main menu
        $menu_name = 'Main Website Menu';
        $menu_obj  = wp_get_nav_menu_object($menu_name);
        if (! $menu_obj) {
            $menu_id  = wp_create_nav_menu($menu_name);
            $new_menu = true;
        } else {
            $menu_id  = $menu_obj->term_id;
            $new_menu = false;
        }

        // 4. Assign menu to theme location
        $locations = get_theme_mod('nav_menu_locations', array());
        $locations['website-menu'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);

        // 5. If newly created, add all pages *except* the Dashboard page
        if ($new_menu) {
            foreach ($page_ids as $slug => $page_id) {
                if ($slug === 'dashboard') {
                    continue; // skip the Dashboard page
                }
                wp_update_nav_menu_item($menu_id, 0, array(
                    'menu-item-title'     => $pages[$slug]['title'],
                    'menu-item-object'    => 'page',
                    'menu-item-object-id' => $page_id,
                    'menu-item-type'      => 'post_type',
                    'menu-item-status'    => 'publish',
                ));
            }
        }

        // 6. Get existing menu‐item titles to avoid duplicates
        $existing_items  = wp_get_nav_menu_items($menu_id) ?: array();
        $existing_titles = wp_list_pluck($existing_items, 'title');

        // 7. Append custom links in the exact order: Log In, Dashboard, Log Out
        $custom_links = array(
            array('title' => 'Log In',    'url' => '/admin'),
            array('title' => 'Dashboard', 'url' => '/dashboard'),
            array('title' => 'Log Out',   'url' => '/logout'),
        );
        foreach ($custom_links as $link) {
            if (! in_array($link['title'], $existing_titles, true)) {
                wp_update_nav_menu_item($menu_id, 0, array(
                    'menu-item-title'  => $link['title'],
                    'menu-item-url'    => $link['url'],
                    'menu-item-status' => 'publish',
                    'menu-item-type'   => 'custom',
                ));
            }
        }
    }
    add_action('after_switch_theme', 'custom_theme_setup_pages');
