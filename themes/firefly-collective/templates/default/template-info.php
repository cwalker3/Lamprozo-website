<?php

    // theme/template/default/template-info.php

    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }

    // Return template information array
    return array(
        'name'        => 'Default Template',
        'description' => 'The default Firefly Collective template with standard header, navigation, and footer layout.',
        'version'     => '1.0.0',
        'author'      => 'Firefly Collective',
        'features'    => array(
            'responsive',
            'navigation-menu',
            'user-authentication',
            'contact-sticky',
            'back-to-blogs'
        ),
        'requires'    => array(
            'wordpress' => '5.0',
            'php'       => '7.4'
        )
    );