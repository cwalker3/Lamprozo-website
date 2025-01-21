<?php
/*
Plugin Name: Firefly Collective
Description: Core website features.
Author: Alex Strait
*/

require_once plugin_dir_path(__FILE__) . 'includes/firefly-functions.php';

register_activation_hook(__FILE__, 'firefly_collective_create_tables');

define('LIVE_DEV_ENDPOINT', 'https://fireflycollective.org/wp-json/firefly-collective/v1/update_project');

// DANGEROUS LINE :: DO NOT UNCOMMENT UNLESS ABSOLUTELY NECESSARY
// register_deactivation_hook(__FILE__, 'firefly_collective_drop_all_tables');