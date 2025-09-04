<?php

    // theme/models/pages.php

	// Define the page structure that both pages.php and nav.php will use
	function get_theme_pages_structure() {
		global $active_template;

        $landing_page_style 	 =  firefly_collective_get_landing_style();
        $landing_page_contents   =  file_get_contents(get_template_directory() 	. '/templates/' . $active_template . '/snippets/landing.html');
		$landing_page_contents   .= file_get_contents(get_template_directory() 	. '/templates/' . $active_template . '/snippets/landing-secondary.html');
		$template_page_contents  =  file_get_contents(get_template_directory() 	. '/templates/' . $active_template . '/snippets/template.html');
		
		// Load banner template
		$banner_template = file_get_contents(get_template_directory() . '/templates/' . $active_template . '/snippets/page-banner.html');
		
		// Create banners for each page
		$blog_banner = str_replace('{{PAGE_TITLE}}', 'Blog', $banner_template);
		$contact_banner = str_replace('{{PAGE_TITLE}}', 'Contact', $banner_template);
		$signup_banner = str_replace('{{PAGE_TITLE}}', 'Sign Up', $banner_template);
		$order_history_banner = str_replace('{{PAGE_TITLE}}', 'Order History', $banner_template);
		$dashboard_banner = str_replace('{{PAGE_TITLE}}', 'Dashboard', $banner_template);

		return array(
			'home'              => array('title' => 'Home',             'content' => $landing_page_contents),
			'app'               => array('title' => 'App',              'content' => 'This is the PWA front end page.'),
			'blog'              => array('title' => 'Blog',             'content' => $blog_banner),
			'contact'           => array('title' => 'Contact',          'content' => $contact_banner),
			'signup'            => array('title' => 'Signup',           'content' => $signup_banner),
			'order-history'     => array('title' => 'Order History',    'content' => $order_history_banner),
			'dashboard'         => array('title' => 'Dashboard',        'content' => $dashboard_banner),
			'template'          => array('title' => 'Template',        	'content' => $template_page_contents),
		);
	}

	function custom_theme_setup_pages() {
		$pages = get_theme_pages_structure();

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