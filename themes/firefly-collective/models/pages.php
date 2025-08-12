<?php

    // theme/models/pages.php

	function custom_theme_setup_pages() {

        $active_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION);
        $landing_page_style = firefly_collective_get_landing_style();
        $landing_page_contents = file_get_contents(get_template_directory() . '/templates/' . $active_template . '/snippets/landing.html');

		$pages = array(
			'home'              => array('title' => 'Home',             'content' => $landing_page_contents . 'This is the homepage.'),
			'app'               => array('title' => 'App',              'content' => 'This is the PWA front end page.'),
			'blog'              => array('title' => 'Blog',             'content' => 'This is the blog.'),
			'contact'           => array('title' => 'Contact',          'content' => 'This is the contact page.'),
			'signup'            => array('title' => 'Signup',           'content' => 'This is the signup page.'),
			'order-history'     => array('title' => 'Order History',    'content' => 'This is the order history page.'),
			'dashboard'         => array('title' => 'Dashboard',        'content' => 'This is the dashboard page.'),
		);

		// Create pages if they don't exist
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

		// Set front page and posts page
		if (! empty($page_ids['home'])) {
			update_option('show_on_front', 'page');
			update_option('page_on_front', $page_ids['home']);
		}
		if (! empty($page_ids['blog'])) {
			update_option('page_for_posts', $page_ids['blog']);
		}
	}
	add_action('after_switch_theme', 'custom_theme_setup_pages');