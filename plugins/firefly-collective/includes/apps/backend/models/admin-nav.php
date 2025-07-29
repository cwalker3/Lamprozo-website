<?php

    // plugin/models/admin-nav.php

    function firefly_collective_add_bookings_link() {
        add_menu_page(
            'My Bookings',
            'Bookings',
            'manage_options',
            'my-bookings',
            'firefly_collective_bookings_dashboard',
            'dashicons-calendar'
        );
    }
    add_action('admin_menu', 'firefly_collective_add_bookings_link');

    function firefly_collective_add_projects_link() {
        add_menu_page(
            'Projects',
            'Projects',
            'manage_options',
            'projects',
            'firefly_collective_projects_dashboard'
        );
    }
    add_action('admin_enqueue_scripts', 'enqueue_projects_styles_and_scripts');

    if (defined('FIREFLY_DEV')) {
        add_action('admin_menu', 'firefly_collective_add_projects_link');
    }

    function firefly_collective_add_pricing_link() {
        add_menu_page(
            'Pricing',
            'Pricing',
            'manage_options',
            'pricing',
            'firefly_collective_pricing_dashboard',
            'dashicons-money-alt'
        );
    }
    add_action('admin_menu', 'firefly_collective_add_pricing_link');

    function firefly_collective_add_campaign_link() {
        add_menu_page(
            'Campaigns',
            'Campaigns',
            'manage_options',
            'campaign',
            'firefly_collective_campaign_dashboard',
            'dashicons-megaphone'
        );
    }
    add_action('admin_menu', 'firefly_collective_add_campaign_link');

    function firefly_collective_add_orders_link() {
        add_menu_page(
            'Orders',
            'Orders',
            'manage_options',
            'orders',
            'firefly_collective_orders_dashboard',
            'dashicons-cart'
        );
    }
    add_action('admin_menu', 'firefly_collective_add_orders_link');

    function firefly_collective_add_subscriptions_link() {
        add_menu_page(
            'Subscriptions',
            'Subscriptions',
            'manage_options',
            'subscriptions',
            'firefly_collective_subscriptions_dashboard',
            'dashicons-tickets-alt'
        );
    }
    add_action('admin_menu', 'firefly_collective_add_subscriptions_link');
