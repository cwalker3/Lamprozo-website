<?php

    // theme/models/template-options.php
    //
    // Centralised template option management. Each template declares its options
    // in a static options.php file. This module handles Customizer registration,
    // publish, REST preview, dynamic CSS output, and JS data.

    if (!defined('ABSPATH')) {
        exit;
    }

    // -------------------------------------------------------------------------
    //  Config helpers
    // -------------------------------------------------------------------------

    /**
     * Read a single template's options config.
     */
    function firefly_get_template_options($template) {
        $file = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . sanitize_file_name($template) . '/options.php';
        if (file_exists($file) && firefly_collective_is_valid_template_path($file)) {
            $config = include $file;
            return is_array($config) ? $config : array();
        }
        return array();
    }

    /**
     * Read every template's options config.
     * Returns array( 'firefly' => array(...), 'glow' => array(...), ... )
     */
    function firefly_get_all_templates_options() {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = array();
        foreach (firefly_collective_get_available_templates() as $tmpl) {
            $cache[$tmpl] = firefly_get_template_options($tmpl);
        }
        return $cache;
    }

    /**
     * Return the UNION of all option keys across every template.
     * For each key, pick the config from the first template that defines it
     * (order: published template first, then alphabetical).
     */
    function firefly_get_union_options() {
        $all   = firefly_get_all_templates_options();
        $published = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        $union = array();

        // Published template first so its config takes priority for labels etc.
        if (isset($all[$published])) {
            foreach ($all[$published] as $key => $config) {
                $union[$key] = $config;
            }
        }

        // Then merge anything from other templates
        foreach ($all as $tmpl => $opts) {
            foreach ($opts as $key => $config) {
                if (!isset($union[$key])) {
                    $union[$key] = $config;
                }
            }
        }

        return $union;
    }

    // -------------------------------------------------------------------------
    //  Sanitizers
    // -------------------------------------------------------------------------

    function firefly_option_sanitize_checkbox($value) {
        return $value ? 1 : 0;
    }

    function firefly_option_sanitize_color($value) {
        if ($value === 'white') return '#ffffff';
        if ($value === 'black') return '#000000';
        $sanitized = sanitize_hex_color($value);
        if ($sanitized) return $sanitized;
        if (preg_match('/^[a-fA-F0-9]{6}$/', $value)) {
            return '#' . $value;
        }
        return '#ffffff';
    }

    function firefly_get_option_sanitizer($type) {
        switch ($type) {
            case 'checkbox': return 'firefly_option_sanitize_checkbox';
            case 'color':    return 'firefly_option_sanitize_color';
            default:         return 'sanitize_text_field';
        }
    }

    // -------------------------------------------------------------------------
    //  Option read / write (scoped by template)
    // -------------------------------------------------------------------------

    function firefly_get_template_option_name($key, $template, $is_preview = false) {
        $suffix = $is_preview ? 'preview_' : '';
        return 'firefly_collective_' . $key . '_' . $suffix . $template;
    }

    function firefly_get_template_option($key, $use_preview = false, $template = null) {
        if (!$template) {
            $template = firefly_collective_get_active_template();
        }
        $options = firefly_get_template_options($template);
        $default = isset($options[$key]['default']) ? $options[$key]['default'] : '';
        $name    = firefly_get_template_option_name($key, $template, $use_preview);
        $value   = get_option($name, $default);

        // Normalize checkbox to int
        if (isset($options[$key]['type']) && $options[$key]['type'] === 'checkbox') {
            return $value ? 1 : 0;
        }
        return $value;
    }

    function firefly_set_template_option($key, $value, $template = null, $is_preview = false) {
        if (!$template) {
            $template = firefly_collective_get_active_template();
        }
        $options = firefly_get_template_options($template);
        $sanitizer = firefly_get_option_sanitizer($options[$key]['type'] ?? 'text');
        if (is_callable($sanitizer)) {
            $value = call_user_func($sanitizer, $value);
        }
        $name = firefly_get_template_option_name($key, $template, $is_preview);
        return update_option($name, $value);
    }

    /**
     * Backwards-compat wrapper — called from template header.php files.
     */
    function template_get_option($key, $use_preview = false) {
        return firefly_get_template_option($key, $use_preview);
    }

    // -------------------------------------------------------------------------
    //  Customizer registration
    // -------------------------------------------------------------------------

    function firefly_template_options_customize_register($wp_customize) {
        $published = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        $union     = firefly_get_union_options();

        foreach ($union as $key => $config) {
            $setting_id = 'template_' . $key;

            // Default to the published template's live value
            $current_value = firefly_get_template_option($key, false, $published);

            $wp_customize->add_setting($setting_id, array(
                'default'           => $current_value,
                'transport'         => 'postMessage',
                'sanitize_callback' => firefly_get_option_sanitizer($config['type']),
            ));

            $control_args = array(
                'label'    => $config['label'],
                'section'  => $config['section'],
                'type'     => $config['type'],
                'priority' => isset($config['priority']) ? $config['priority'] : 10,
            );

            if (!empty($config['description'])) {
                $control_args['description'] = $config['description'];
            }

            if ($config['type'] === 'color') {
                $wp_customize->add_control(
                    new WP_Customize_Color_Control($wp_customize, $setting_id, $control_args)
                );
            } else {
                $wp_customize->add_control($setting_id, $control_args);
            }
        }
    }
    add_action('customize_register', 'firefly_template_options_customize_register', 20);

    // -------------------------------------------------------------------------
    //  Reset preview values when Customizer loads
    // -------------------------------------------------------------------------

    function firefly_template_options_reset_on_customizer_load() {
        $published = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        $options   = firefly_get_template_options($published);

        foreach ($options as $key => $config) {
            $live = firefly_get_template_option($key, false, $published);
            firefly_set_template_option($key, $live, $published, true);
        }
    }
    add_action('customize_controls_init', 'firefly_template_options_reset_on_customizer_load');

    // -------------------------------------------------------------------------
    //  Publish handler
    // -------------------------------------------------------------------------

    function firefly_template_options_save_after($wp_customize) {
        $active  = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        $options = firefly_get_template_options($active);

        foreach ($options as $key => $config) {
            $setting_id = 'template_' . $key;
            $setting    = $wp_customize->get_setting($setting_id);

            if ($setting) {
                $value = $setting->post_value();
                if ($value !== null) {
                    $sanitizer = firefly_get_option_sanitizer($config['type']);
                    if (is_callable($sanitizer)) {
                        $value = call_user_func($sanitizer, $value);
                    }
                    // Save live value
                    firefly_set_template_option($key, $value, $active, false);
                    // Sync preview
                    firefly_set_template_option($key, $value, $active, true);
                    // Remove WordPress-managed transient
                    delete_option($setting_id);
                }
            }
        }
    }
    add_action('customize_save_after', 'firefly_template_options_save_after');

    // -------------------------------------------------------------------------
    //  REST endpoint — option preview changes
    // -------------------------------------------------------------------------

    function firefly_handle_template_option_preview(WP_REST_Request $request) {
        $option_key   = sanitize_key($request->get_param('option_key'));
        $option_value = $request->get_param('option_value');

        if (empty($option_key)) {
            return new WP_Error('missing_option_key', 'Option key is required', array('status' => 400));
        }
        if ($option_value === null) {
            return new WP_Error('missing_option_value', 'Option value is required', array('status' => 400));
        }

        // Validate against the TEMP template's config (what the preview iframe uses)
        $template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        $options  = firefly_get_template_options($template);

        if (!isset($options[$option_key])) {
            return new WP_Error('invalid_option', 'Invalid option key for template: ' . $template, array('status' => 400));
        }

        firefly_set_template_option($option_key, $option_value, $template, true);

        return rest_ensure_response(array(
            'success'      => true,
            'option_key'   => $option_key,
            'option_value' => $option_value,
        ));
    }

    // -------------------------------------------------------------------------
    //  Dynamic CSS output
    // -------------------------------------------------------------------------

    function firefly_template_options_output_css() {
        $template = firefly_collective_get_active_template();
        $options  = firefly_get_template_options($template);
        $in_preview = in_customizer_iframe();
        $props = array();

        foreach ($options as $key => $config) {
            if (empty($config['css_property'])) {
                continue;
            }
            $value = firefly_get_template_option($key, $in_preview, $template);
            $props[] = $config['css_property'] . ': ' . esc_attr($value) . ';';
        }

        if (!empty($props)) {
            echo "<style id='template-custom-properties'>\n:root {\n\t"
                 . implode("\n\t", $props)
                 . "\n}\n</style>\n";
        }
    }
    add_action('wp_head', 'firefly_template_options_output_css', 1);

    // -------------------------------------------------------------------------
    //  Enqueue JS + localized data for the Customizer admin panel
    // -------------------------------------------------------------------------

    function firefly_template_options_enqueue_js() {
        // Build per-template data for JS
        $all = firefly_get_all_templates_options();
        $templates_data = array();

        foreach ($all as $tmpl => $opts) {
            $option_keys = array_keys($opts);
            $values = array();
            $sections = array();
            foreach ($opts as $key => $config) {
                $values[$key] = firefly_get_template_option($key, false, $tmpl);
                if (!empty($config['section'])) {
                    $sections[$config['section']] = true;
                }
            }

            // Also show Landing section if template has landing snippets
            $snippets_dir = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . sanitize_file_name($tmpl) . '/snippets';
            if (is_dir($snippets_dir)) {
                foreach (scandir($snippets_dir) as $file) {
                    if (strpos($file, 'landing') === 0) {
                        $sections['firefly_collective_landing'] = true;
                        break;
                    }
                }
            }

            $templates_data[$tmpl] = array(
                'options'  => $option_keys,
                'values'   => $values,
                'sections' => array_keys($sections),
            );
        }

        // Include templates with no options (like testtemplate)
        foreach (firefly_collective_get_available_templates() as $tmpl) {
            if (!isset($templates_data[$tmpl])) {
                $templates_data[$tmpl] = array('options' => array(), 'values' => array(), 'sections' => array());
            }
        }

        // Compute list of all option keys and all sections across templates
        $all_option_keys = array();
        $all_sections = array();
        foreach ($all as $opts) {
            $all_option_keys = array_merge($all_option_keys, array_keys($opts));
            foreach ($opts as $config) {
                if (!empty($config['section'])) {
                    $all_sections[$config['section']] = true;
                }
            }
        }
        $all_option_keys = array_unique($all_option_keys);

        wp_localize_script('customize-js', 'fireflyTemplateOptions', array(
            'templates'     => $templates_data,
            'allOptionKeys' => array_values($all_option_keys),
            'allSections'   => array_keys($all_sections),
        ));
    }
    add_action('customize_controls_enqueue_scripts', 'firefly_template_options_enqueue_js');
