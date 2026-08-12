<?php

    // theme/models/template-options.php
    //
    // Centralised template option management. Each template declares its
    // customizer — sections + options — declaratively in its own
    // templates/{template}/options.php. This engine registers those into the
    // native WordPress Customizer (scoped so each template shows ONLY its own
    // controls), handles the live-preview REST writes, publish, dynamic CSS,
    // body classes, render hooks, and the JS data.
    //
    // options.php shape (both forms supported):
    //   Legacy flat:   return array( 'key' => array(...config...), ... );
    //   New wrapped:   return array(
    //       'sections' => array( 'colors' => array('title'=>'Colors','priority'=>10), ... ),
    //       'options'  => array( 'background_color' => array(...config...), ... ),
    //   );
    //
    // Option config keys:
    //   type        checkbox|color|text|textarea|select|range|number|image
    //   label       control label
    //   description control description (optional)
    //   section     section id — a template-declared section key, or a framework
    //               section id (firefly_collective_landing|navigation|layout)
    //   priority    control order (optional)
    //   default     fallback value
    //   choices     value=>label map (select)
    //   min/max/step number/range bounds (optional)
    //   input_attrs extra control input attrs (optional)
    //   -- application (any combination) --
    //   css_var (or legacy css_property)  emit :root{ --var: value[unit] }
    //   unit        appended to the css var value (e.g. 'px','vh','%')
    //   body_class  add a <body> class: a string prefix -> "prefix{value}" (or
    //               the bare prefix for a truthy checkbox)
    //   render      callback( $value, $in_preview, $template ) run on wp_head so
    //               a template can apply anything CSS-vars/classes can't express

    if (!defined('ABSPATH')) {
        exit;
    }

    // Framework-provided sections every template may reference by id without
    // declaring them (kept registered in template.php for back-compat).
    if (!defined('FIREFLY_FRAMEWORK_SECTIONS')) {
        define('FIREFLY_FRAMEWORK_SECTIONS', 'firefly_collective_landing,firefly_collective_navigation,firefly_collective_layout,title_tagline,static_front_page');
    }

    // -------------------------------------------------------------------------
    //  Config helpers
    // -------------------------------------------------------------------------

    /**
     * Load a template's raw options.php return value (either shape).
     */
    function firefly_load_template_config($template) {
        $file = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . sanitize_file_name($template) . '/options.php';
        if (file_exists($file) && firefly_collective_is_valid_template_path($file)) {
            $config = include $file;
            return is_array($config) ? $config : array();
        }
        return array();
    }

    /**
     * A template's option configs, keyed by option key. Unwraps the new
     * 'options' form; treats a legacy flat array as the options map directly.
     */
    function firefly_get_template_options($template) {
        $config = firefly_load_template_config($template);
        if (isset($config['options']) && is_array($config['options'])) {
            return $config['options'];
        }
        // Legacy flat map — guard against a stray 'sections' key.
        unset($config['sections']);
        return $config;
    }

    /**
     * A template's declared sections, keyed by (local) section id. Empty for
     * legacy templates that only reference framework sections.
     */
    function firefly_get_template_sections($template) {
        $config = firefly_load_template_config($template);
        return (isset($config['sections']) && is_array($config['sections'])) ? $config['sections'] : array();
    }

    /**
     * Read every template's option configs. Returns
     * array( 'default' => array(...options...), 'glow' => array(...), ... ).
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
     * Union of option keys across every template. Retained for backward compat
     * with any external caller; the customizer no longer registers off this.
     */
    function firefly_get_union_options() {
        $union = array();
        $all   = firefly_get_all_templates_options();
        $published = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        if (isset($all[$published])) {
            $union = $all[$published];
        }
        foreach ($all as $opts) {
            foreach ($opts as $key => $config) {
                if (!isset($union[$key])) {
                    $union[$key] = $config;
                }
            }
        }
        return $union;
    }

    /**
     * Resolve an option's declared `section` to a real customizer section id.
     * A key that matches one of the template's declared sections is namespaced
     * (firefly_sec_{template}_{key}) so two templates' same-named sections stay
     * independent; a framework section id passes through unchanged.
     */
    function firefly_resolve_section_id($section, $template) {
        if ($section === '' || $section === null) {
            return 'firefly_collective_layout';
        }
        $declared = firefly_get_template_sections($template);
        if (isset($declared[$section])) {
            return 'firefly_sec_' . $template . '_' . $section;
        }
        return $section; // framework section id, used as-is
    }

    /**
     * The customizer setting/control id for a template's option — namespaced so
     * every template gets its own independent control.
     */
    function firefly_option_control_id($template, $key) {
        return 'tplopt_' . $template . '_' . $key;
    }

    /**
     * The customizer section ids a template actually uses (declared sections it
     * references + any framework sections its options point at).
     */
    function firefly_template_used_section_ids($template) {
        $ids = array();
        foreach (firefly_get_template_options($template) as $config) {
            $section = isset($config['section']) ? $config['section'] : '';
            $ids[firefly_resolve_section_id($section, $template)] = true;
        }
        return array_keys($ids);
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

    /**
     * A per-option sanitizer closure — closes over the option config so select
     * choices, number bounds, etc. can be enforced.
     */
    function firefly_get_option_sanitizer_for($config) {
        $type = isset($config['type']) ? $config['type'] : 'text';
        switch ($type) {
            case 'checkbox':
                return 'firefly_option_sanitize_checkbox';
            case 'color':
                return 'firefly_option_sanitize_color';
            case 'select':
                $choices = isset($config['choices']) && is_array($config['choices']) ? array_keys($config['choices']) : array();
                $default = isset($config['default']) ? $config['default'] : '';
                return function ($value) use ($choices, $default) {
                    return in_array($value, $choices, true) ? $value : $default;
                };
            case 'range':
            case 'number':
                $min  = isset($config['min']) ? $config['min'] : null;
                $max  = isset($config['max']) ? $config['max'] : null;
                $step = isset($config['step']) ? $config['step'] : null;
                return function ($value) use ($min, $max, $step) {
                    $n = ($step !== null && floor($step) != $step) ? (float) $value : (int) $value;
                    if ($min !== null && $n < $min) $n = $min;
                    if ($max !== null && $n > $max) $n = $max;
                    return $n;
                };
            case 'image':
                return 'esc_url_raw';
            case 'textarea':
                return 'sanitize_textarea_field';
            default:
                return 'sanitize_text_field';
        }
    }

    /**
     * Back-compat: type-only sanitizer lookup (older callers).
     */
    function firefly_get_option_sanitizer($type) {
        return firefly_get_option_sanitizer_for(array('type' => $type));
    }

    // -------------------------------------------------------------------------
    //  Option read / write (scoped by template) — storage layer, unchanged shape
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

        if (isset($options[$key]['type']) && $options[$key]['type'] === 'checkbox') {
            return $value ? 1 : 0;
        }
        return $value;
    }

    function firefly_set_template_option($key, $value, $template = null, $is_preview = false) {
        if (!$template) {
            $template = firefly_collective_get_active_template();
        }
        $options   = firefly_get_template_options($template);
        $config    = isset($options[$key]) ? $options[$key] : array('type' => 'text');
        $sanitizer = firefly_get_option_sanitizer_for($config);
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
    //  Customizer registration — per template, namespaced + scoped
    // -------------------------------------------------------------------------

    function firefly_template_options_customize_register($wp_customize) {
        $all = firefly_get_all_templates_options();

        foreach ($all as $template => $options) {
            // 1) Register this template's declared sections (namespaced).
            foreach (firefly_get_template_sections($template) as $sid => $sconf) {
                $section_id = 'firefly_sec_' . $template . '_' . $sid;
                if ($wp_customize->get_section($section_id)) {
                    continue;
                }
                $wp_customize->add_section($section_id, array(
                    'title'       => isset($sconf['title']) ? $sconf['title'] : ucfirst($sid),
                    'priority'    => isset($sconf['priority']) ? $sconf['priority'] : 30,
                    'description' => isset($sconf['description']) ? $sconf['description'] : '',
                ));
            }

            // 2) Register this template's controls (namespaced setting + control).
            foreach ($options as $key => $config) {
                $id = firefly_option_control_id($template, $key);
                $current = firefly_get_template_option($key, false, $template);

                $wp_customize->add_setting($id, array(
                    'type'              => 'option',
                    'default'           => $current,
                    'transport'         => 'postMessage',
                    'sanitize_callback' => firefly_get_option_sanitizer_for($config),
                ));

                firefly_add_option_control($wp_customize, $id, $config, $template);
            }
        }
    }
    add_action('customize_register', 'firefly_template_options_customize_register', 20);

    /**
     * Add the right control class for an option's type.
     */
    function firefly_add_option_control($wp_customize, $id, $config, $template) {
        $type    = isset($config['type']) ? $config['type'] : 'text';
        $section = firefly_resolve_section_id(isset($config['section']) ? $config['section'] : '', $template);

        $args = array(
            'label'    => isset($config['label']) ? $config['label'] : $id,
            'section'  => $section,
            'priority' => isset($config['priority']) ? $config['priority'] : 10,
        );
        if (!empty($config['description'])) {
            $args['description'] = $config['description'];
        }
        if (!empty($config['input_attrs']) && is_array($config['input_attrs'])) {
            $args['input_attrs'] = $config['input_attrs'];
        }

        switch ($type) {
            case 'color':
                $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, $id, $args));
                break;

            case 'image':
                $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, $id, $args));
                break;

            case 'select':
                $args['type']    = 'select';
                $args['choices'] = isset($config['choices']) && is_array($config['choices']) ? $config['choices'] : array();
                $wp_customize->add_control($id, $args);
                break;

            case 'range':
            case 'number':
                $args['type'] = $type;
                $input_attrs  = isset($args['input_attrs']) ? $args['input_attrs'] : array();
                foreach (array('min', 'max', 'step') as $attr) {
                    if (isset($config[$attr])) {
                        $input_attrs[$attr] = $config[$attr];
                    }
                }
                $args['input_attrs'] = $input_attrs;
                $wp_customize->add_control($id, $args);
                break;

            case 'textarea':
                $args['type'] = 'textarea';
                $wp_customize->add_control($id, $args);
                break;

            case 'checkbox':
                $args['type'] = 'checkbox';
                $wp_customize->add_control($id, $args);
                break;

            default:
                $args['type'] = 'text';
                $wp_customize->add_control($id, $args);
                break;
        }
    }

    // -------------------------------------------------------------------------
    //  Reset preview values when the Customizer loads (the temp template's set)
    // -------------------------------------------------------------------------

    function firefly_template_options_reset_on_customizer_load() {
        // Reset the currently-previewed (temp) template so a just-switched
        // template also starts from clean, published values.
        $temp = get_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION,
                           get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE));

        foreach (firefly_get_template_options($temp) as $key => $config) {
            $live = firefly_get_template_option($key, false, $temp);
            firefly_set_template_option($key, $live, $temp, true);
        }
    }
    add_action('customize_controls_init', 'firefly_template_options_reset_on_customizer_load');

    // -------------------------------------------------------------------------
    //  Publish handler — promote the active template's controls -> live
    // -------------------------------------------------------------------------

    function firefly_template_options_save_after($wp_customize) {
        $active  = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);

        foreach (firefly_get_template_options($active) as $key => $config) {
            $id      = firefly_option_control_id($active, $key);
            $setting = $wp_customize->get_setting($id);
            if (!$setting) {
                continue;
            }
            $value = $setting->post_value();
            if ($value === null) {
                continue;
            }
            $sanitizer = firefly_get_option_sanitizer_for($config);
            if (is_callable($sanitizer)) {
                $value = call_user_func($sanitizer, $value);
            }
            firefly_set_template_option($key, $value, $active, false); // live
            firefly_set_template_option($key, $value, $active, true);  // sync preview
            // The setting is type=option (id = tplopt_...), a scratch row — clear it.
            delete_option($id);
        }
    }
    add_action('customize_save_after', 'firefly_template_options_save_after');

    // -------------------------------------------------------------------------
    //  REST endpoint — option preview changes (scoped to the temp template)
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
    //  Application: dynamic CSS vars, body classes, render callbacks
    // -------------------------------------------------------------------------

    function firefly_template_options_output_css() {
        $template   = firefly_collective_get_active_template();
        $options    = firefly_get_template_options($template);
        $in_preview = in_customizer_iframe();
        $props      = array();

        foreach ($options as $key => $config) {
            $var = '';
            if (!empty($config['css_var'])) {
                $var = $config['css_var'];
            } elseif (!empty($config['css_property'])) {
                $var = $config['css_property']; // legacy
            }
            if ($var === '') {
                continue;
            }
            $value = firefly_get_template_option($key, $in_preview, $template);
            if (!empty($config['unit']) && $value !== '' && is_numeric($value)) {
                $value .= $config['unit'];
            }
            $props[] = $var . ': ' . esc_attr($value) . ';';
        }

        if (!empty($props)) {
            echo "<style id='template-custom-properties'>\n:root {\n\t"
                 . implode("\n\t", $props)
                 . "\n}\n</style>\n";
        }
    }
    add_action('wp_head', 'firefly_template_options_output_css', 1);

    /**
     * Add option-driven <body> classes.
     */
    function firefly_template_options_body_class($classes) {
        $template   = firefly_collective_get_active_template();
        $in_preview = in_customizer_iframe();

        foreach (firefly_get_template_options($template) as $key => $config) {
            if (empty($config['body_class'])) {
                continue;
            }
            $prefix = is_string($config['body_class']) ? $config['body_class'] : ($key . '-');
            $value  = firefly_get_template_option($key, $in_preview, $template);

            if (isset($config['type']) && $config['type'] === 'checkbox') {
                if ($value) {
                    $classes[] = sanitize_html_class(rtrim($prefix, '-'));
                }
            } elseif ($value !== '' && $value !== null) {
                $classes[] = sanitize_html_class($prefix . $value);
            }
        }
        return $classes;
    }
    add_filter('body_class', 'firefly_template_options_body_class');

    /**
     * Run template-defined render callbacks for options that declare one, so a
     * template can apply anything CSS vars / body classes can't express.
     */
    function firefly_template_options_render() {
        $template   = firefly_collective_get_active_template();
        $in_preview = in_customizer_iframe();

        foreach (firefly_get_template_options($template) as $key => $config) {
            if (empty($config['render']) || !is_callable($config['render'])) {
                continue;
            }
            $value = firefly_get_template_option($key, $in_preview, $template);
            call_user_func($config['render'], $value, $in_preview, $template);
        }
    }
    add_action('wp_head', 'firefly_template_options_render', 20);

    // -------------------------------------------------------------------------
    //  Enqueue JS + localized data for the Customizer admin panel
    // -------------------------------------------------------------------------

    function firefly_template_options_enqueue_js() {
        $templates_data = array();

        foreach (firefly_collective_get_available_templates() as $tmpl) {
            $opts     = firefly_get_template_options($tmpl);
            $controls = array();
            $values   = array();

            foreach ($opts as $key => $config) {
                $control_id           = firefly_option_control_id($tmpl, $key);
                $controls[$key]       = $control_id;
                $values[$key]         = firefly_get_template_option($key, false, $tmpl);
            }

            $sections = firefly_template_used_section_ids($tmpl);

            // Show the framework Landing section if the template ships landing snippets.
            $snippets_dir = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . sanitize_file_name($tmpl) . '/snippets';
            if (is_dir($snippets_dir)) {
                foreach (scandir($snippets_dir) as $file) {
                    if (strpos($file, 'landing') === 0) {
                        if (!in_array('firefly_collective_landing', $sections, true)) {
                            $sections[] = 'firefly_collective_landing';
                        }
                        break;
                    }
                }
            }

            $templates_data[$tmpl] = array(
                'controls' => $controls,          // option key => namespaced control id
                'values'   => $values,            // option key => current value
                'sections' => array_values($sections),
            );
        }

        // Every section id we register, so the JS can hide the inactive ones.
        $all_sections = array();
        foreach ($templates_data as $data) {
            foreach ($data['sections'] as $sid) {
                $all_sections[$sid] = true;
            }
        }

        wp_localize_script('customize-js', 'fireflyTemplateOptions', array(
            'templates'   => $templates_data,
            'allSections' => array_keys($all_sections),
            'restRoot'    => esc_url_raw(rest_url('custom-api/v1/')),
            'nonce'       => wp_create_nonce('wp_rest'),
        ));
    }
    add_action('customize_controls_enqueue_scripts', 'firefly_template_options_enqueue_js');
