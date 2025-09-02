<?php

    // theme/template/default/template-info.php

    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }

    // Return template information array
    return array(
        'name'        => 'Glow',
        'description' => 'The barebones version of default',
        'version'     => '1.0.0',
        'author'      => 'Firefly Collective',
        'requires'    => array(
            'wordpress' => '5.0',
            'php'       => '7.4'
        )
    );