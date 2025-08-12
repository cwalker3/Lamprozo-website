<?php

    // theme/models/nav.php

	function custom_theme_setup_navigation() {
		// Get the pages that should be in navigation (excluding dashboard)
		$nav_page_slugs = array('home', 'app', 'blog', 'contact', 'signup', 'order-history');
		$page_ids = array();
		$pages = array();

		foreach ($nav_page_slugs as $slug) {
			$page = get_page_by_path($slug);
			if ($page) {
				$page_ids[$slug] = $page->ID;
				$pages[$slug] = array('title' => $page->post_title);
			}
		}

		// Create or fetch main menu
		$menu_name = 'Main Website Menu';
		$menu_obj  = wp_get_nav_menu_object($menu_name);
		if (! $menu_obj) {
			$menu_id  = wp_create_nav_menu($menu_name);
			$new_menu = true;
		} else {
			$menu_id  = $menu_obj->term_id;
			$new_menu = false;
		}

		// Assign menu to theme location
		$locations = get_theme_mod('nav_menu_locations', array());
		$locations['website-menu'] = $menu_id;
		set_theme_mod('nav_menu_locations', $locations);

		// If newly created, add all pages
		if ($new_menu) {
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

		// Get existing menu item titles to avoid duplicates
		$existing_items  = wp_get_nav_menu_items($menu_id) ?: array();
		$existing_titles = wp_list_pluck($existing_items, 'title');

		// Append custom links in the exact order
		$custom_links = array(
			array('title' => 'Log Out',         'url' => '/logout'),
			array('title' => 'Log In',          'url' => '/ffc-login'),
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
	add_action('after_switch_theme', 'custom_theme_setup_navigation', 20);