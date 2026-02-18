<?php

    // template/options.php
    // Declarative template options config — no hooks, no side effects.
    // The theme-level template-options.php reads this and handles registration,
    // save, preview, and dynamic CSS automatically.

    return array(
        'menu_overlay' => array(
            'default' => 0,
            'type' => 'checkbox',
            'label' => 'Menu Overlay',
            'description' => 'Enable overlay menu display on front page.',
            'section' => 'firefly_collective_landing',
            'priority' => 15,
        ),
        'nav_overlay_menu' => array(
            'default' => 0,
            'type' => 'checkbox',
            'label' => 'Overlay Navigation',
            'description' => 'Enable overlay navigation menu on non-front pages with left-positioned logo.',
            'section' => 'firefly_collective_navigation',
            'priority' => 10,
        ),
        'background_color' => array(
            'default' => '#ffffff',
            'type' => 'color',
            'label' => 'Background Color',
            'description' => 'Set the site background color.',
            'section' => 'firefly_collective_layout',
            'priority' => 10,
            'css_property' => '--backgroundColor',
        ),
    );
