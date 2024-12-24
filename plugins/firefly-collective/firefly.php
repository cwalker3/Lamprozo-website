<?php
/*
Plugin Name: Firefly Collective
Description: Core website features.
Author: Alex Strait
*/

// Define the shared secret for authenticating requests between local and live dev environments
define('FIREFLY_SHARED_SECRET', '2R%$<Gs>Ft-iY"[73[_uR|kkDdyIAx');

require_once plugin_dir_path(__FILE__) . 'includes/firefly-functions.php';

register_activation_hook(__FILE__, 'firefly_collective_create_tables');

define('LIVE_DEV_ENDPOINT', 'https://fireflycollective.org/wp-json/firefly-collective/v1/update_project');

// DANGEROUS LINE :: DO NOT UNCOMMENT UNLESS ABSOLUTELY NECESSARY
// register_deactivation_hook(__FILE__, 'firefly_collective_drop_all_tables');