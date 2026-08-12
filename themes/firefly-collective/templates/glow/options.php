<?php

    // templates/glow/options.php
    //
    // Declarative customizer config for the "glow" template — no hooks, no side
    // effects, just a config array. The framework engine
    // (theme/models/template-options.php) turns this into real Customizer
    // sections + controls, live-previews changes in the actual site (iframe),
    // and applies the values. Each template gets its OWN controls: nothing here
    // shows up when another template is selected.
    //
    // See docs/CUSTOMIZER.md for the full field reference.

    return array(

        // Sections this template declares. Ids are namespaced per template, so
        // 'appearance' here never collides with another template's 'appearance'.
        'sections' => array(
            'appearance' => array(
                'title'       => 'Glow Appearance',
                'priority'    => 30,
                'description' => 'Colors and surface treatment for the Glow template.',
            ),
            'layout' => array(
                'title'       => 'Glow Layout',
                'priority'    => 31,
                'description' => 'Width and density.',
            ),
        ),

        'options' => array(

            // --- Appearance -------------------------------------------------
            'background_color' => array(
                'type'        => 'color',
                'label'       => 'Background Color',
                'description' => 'Site background.',
                'section'     => 'appearance',
                'priority'    => 10,
                'default'     => '#ffffff',
                'css_var'     => '--backgroundColor',
            ),
            'accent_color' => array(
                'type'        => 'color',
                'label'       => 'Accent Color',
                'description' => 'Links, buttons and highlights.',
                'section'     => 'appearance',
                'priority'    => 20,
                'default'     => '#7c3aed',
                'css_var'     => '--accentColor',
            ),

            // --- Layout -----------------------------------------------------
            'content_width' => array(
                'type'        => 'range',
                'label'       => 'Content Width',
                'description' => 'Maximum width of the content column.',
                'section'     => 'layout',
                'priority'    => 10,
                'default'     => 1200,
                'min'         => 800,
                'max'         => 1600,
                'step'        => 20,
                'css_var'     => '--contentWidth',
                'unit'        => 'px',
            ),
            'density' => array(
                'type'        => 'select',
                'label'       => 'Density',
                'description' => 'Vertical rhythm. Adds a body class the stylesheet can target.',
                'section'     => 'layout',
                'priority'    => 20,
                'default'     => 'comfortable',
                'choices'     => array(
                    'comfortable' => 'Comfortable',
                    'compact'     => 'Compact',
                ),
                'body_class'  => 'density-',
            ),

            // --- Framework sections are still available by id ---------------
            'nav_overlay_menu' => array(
                'type'        => 'checkbox',
                'label'       => 'Overlay Navigation',
                'description' => 'Overlay navigation menu with left-positioned logo.',
                'section'     => 'firefly_collective_navigation',
                'priority'    => 10,
                'default'     => 0,
            ),
        ),
    );
