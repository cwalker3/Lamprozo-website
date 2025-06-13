<?php
    
    // plugin/functions.php

    // Ensure no direct access to the file
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    require_once('const.php');

    // Load backend models
    $models = array(
                'init',     'rest',     
                'db',       'bookings',
                'util',     'projects',
                'pricing',  'orders',
                'payment');
    foreach($models as $model) {
        require_once(plugin_dir_path(__FILE__) . "apps/backend/models/$model.php");
    }