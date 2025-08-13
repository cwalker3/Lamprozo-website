<?php

    // theme/models/template.php

    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }

    // Load template globals if not present
    if (!defined('FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE')) {
        $plugin_template_file_path = WP_PLUGIN_DIR . '/firefly-collective/includes/apps/backend/models/template.php';
        require $plugin_template_file_path;
    }

    // Define landing style system constants
	define('FIREFLY_COLLECTIVE_DEFAULT_LANDING_STYLE', 'default');
	define('FIREFLY_COLLECTIVE_LANDING_STYLE_OPTION', 'firefly_collective_landing_style');

    // Load template core model  
    $active_template = firefly_collective_get_active_template();
    require get_template_directory() . '/templates/' . $active_template . '/models/_core.php';

    /**
     * Theme Template System Management
     * 
     * Handles template selection, activation, and switching functionality
     * following WordPress best practices for secure file inclusion.
     */

    /**
     * Theme activation hook - sets default template on theme activation
     * This runs only once when the theme is activated
     */
    function firefly_collective_theme_activation() {
        // Always reset to default template on theme activation
        update_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        
        // Set temp template option for customizer preview
        update_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        
        // Set default landing style only if not already set
        if (get_option(FIREFLY_COLLECTIVE_LANDING_STYLE_OPTION) === false) {
            update_option(FIREFLY_COLLECTIVE_LANDING_STYLE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_LANDING_STYLE);
        }
        
        // Flush rewrite rules if needed
        flush_rewrite_rules();
    }
    add_action('after_switch_theme', 'firefly_collective_theme_activation');

    /**
	 * Get the current landing style
	 * 
	 * @return string The current landing style
	 */
	function firefly_collective_get_landing_style() {
		return get_option(FIREFLY_COLLECTIVE_LANDING_STYLE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_LANDING_STYLE);
	}

	/**
	 * Set the landing style
	 * 
	 * @param string $style The landing style to set
	 * @return bool True on success, false on failure
	 */
	function firefly_collective_set_landing_style($style) {
		$style = sanitize_text_field($style);
		return update_option(FIREFLY_COLLECTIVE_LANDING_STYLE_OPTION, $style);
	}

    /**
     * Get the currently active template
     * 
     * @return string The active template name
     */
    function firefly_collective_get_active_template() {
        $is_in_iframe = in_customizer_iframe();
        
        // Use temp template when in customizer iframe
        if ($is_in_iframe) {
            $template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        } else {
            $template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        }
        
        // Validate template exists, fallback to default if not
        if (!firefly_collective_template_exists($template)) {
            $template = FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE;
            if ($is_in_iframe) {
                update_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, $template);
            } else {
                update_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, $template);
            }
        }
        
        return sanitize_file_name($template);
    }

    /**
     * Set the active template
     * 
     * @param string $template_name The template name to activate
     * @return bool True on success, false on failure
     */
    function firefly_collective_set_active_template($template_name) {
        $template_name = sanitize_file_name($template_name);
        
        // Validate template exists
        if (!firefly_collective_template_exists($template_name)) {
            return false;
        }
        
        return update_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, $template_name);
    }

    /**
     * Check if a template exists
     * 
     * @param string $template_name The template name to check
     * @return bool True if template exists, false otherwise
     */
    function firefly_collective_template_exists($template_name) {
        $template_name = sanitize_file_name($template_name);
        $template_path = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . $template_name;
        
        // Check if template directory exists and has required files
        return is_dir($template_path) && 
               file_exists($template_path . '/header.php') && 
               file_exists($template_path . '/footer.php');
    }

    /**
     * Get the path to a template file
     * 
     * @param string $file The file name (e.g., 'header.php', 'footer.php')
     * @param string $template_name Optional. Template name, defaults to active template
     * @return string|false The full path to the template file, or false if not found
     */
    function firefly_collective_get_template_file_path($file, $template_name = null) {
        if ($template_name === null) {
            $template_name = firefly_collective_get_active_template();
        }
        
        $template_name = sanitize_file_name($template_name);
        $file = sanitize_file_name($file);
        
        // Construct path
        $template_path = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . $template_name . '/' . $file;
        
        // Verify file exists and is within allowed directory
        if (file_exists($template_path) && firefly_collective_is_valid_template_path($template_path)) {
            return $template_path;
        }
        
        return false;
    }

    /**
     * Security check: Ensure file path is within templates directory
     * 
     * @param string $path The file path to validate
     * @return bool True if path is valid, false otherwise
     */
    function firefly_collective_is_valid_template_path($path) {
        $real_path = realpath($path);
        $templates_dir = realpath(FIREFLY_COLLECTIVE_TEMPLATES_DIR);
        
        return $real_path && $templates_dir && strpos($real_path, $templates_dir) === 0;
    }

    /**
     * Load a template file with fallback support
     * 
     * @param string $file The file name to load
     * @param array $args Optional. Arguments to pass to the template
     * @return bool True if file was loaded, false otherwise
     */
    function firefly_collective_load_template_file($file, $args = array()) {
        $template_path = firefly_collective_get_template_file_path($file);
        
        if ($template_path) {
            // Extract args for use in template
            if (!empty($args)) {
                extract($args, EXTR_SKIP);
            }
            
            include $template_path;
            return true;
        }
        
        return false;
    }

    /**
     * Get list of available templates
     * 
     * @return array Array of template names
     */
    function firefly_collective_get_available_templates() {
        $templates = array();
        
        if (is_dir(FIREFLY_COLLECTIVE_TEMPLATES_DIR)) {
            $dirs = scandir(FIREFLY_COLLECTIVE_TEMPLATES_DIR);
            
            foreach ($dirs as $dir) {
                if ($dir !== '.' && $dir !== '..' && firefly_collective_template_exists($dir)) {
                    $templates[] = $dir;
                }
            }
        }
        
        return $templates;
    }

    /**
     * Get template information
     * 
     * @param string $template_name The template name
     * @return array|false Template info array or false if not found
     */
    function firefly_collective_get_template_info($template_name) {
        if (!firefly_collective_template_exists($template_name)) {
            return false;
        }
        
        $template_name = sanitize_file_name($template_name);
        $info_file = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . $template_name . '/template-info.php';
        
        // Default info
        $info = array(
            'name' => ucfirst($template_name),
            'description' => 'Template: ' . $template_name,
            'version' => '1.0.0',
            'author' => get_bloginfo('name')
        );
        
        // Load custom info if exists
        if (file_exists($info_file) && firefly_collective_is_valid_template_path($info_file)) {
            $custom_info = include $info_file;
            if (is_array($custom_info)) {
                $info = array_merge($info, $custom_info);
            }
        }
        
        return $info;
    }

    /**
     * Reset temp template to live template when NOT in customizer
     * This ensures fresh start when entering customizer
     */
    function firefly_collective_maybe_reset_temp_template() {
        // Only reset if we're NOT in the customizer iframe
        if (!in_customizer_iframe() && !is_customize_preview()) {
            // Check if we need to reset (not in an active customizer session)
            if (!isset($_GET['customize_changeset_uuid']) && !isset($_POST['customize_changeset_uuid'])) {
                $current_live_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
                $temp_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION);
                
                // Only update if different to avoid unnecessary database writes
                if ($temp_template !== $current_live_template) {
                    update_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, $current_live_template);
                }
            }
        }
    }
    add_action('init', 'firefly_collective_maybe_reset_temp_template', 1);

    /**
     * Add template selector to WordPress Customizer
     */
    function firefly_collective_customize_register($wp_customize) {
        
        $current_live_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        
        $wp_customize->add_setting('firefly_collective_template_selector', array(
            'default' => $current_live_template,
            'transport' => 'postMessage',
            'sanitize_callback' => 'sanitize_file_name'
        ));
        
        // Set the current value to always be the live template
        $wp_customize->set_post_value('firefly_collective_template_selector', $current_live_template);
        
        // Get available templates for dropdown
        $templates = firefly_collective_get_available_templates();
        $template_choices = array();
        
        foreach ($templates as $template) {
            $info = firefly_collective_get_template_info($template);
            $template_choices[$template] = $info ? $info['name'] : ucfirst($template);
        }
        
        // Add template selection control
        $wp_customize->add_control('firefly_collective_template_selector', array(
            'label' => __('Active Template'),
            'section' => 'title_tagline',
            'type' => 'select',
            'choices' => $template_choices,
            'priority' => 9
        ));
        
        // Add Landing section
        $wp_customize->add_section('firefly_collective_landing', array(
            'title' => __('Landing'),
            'priority' => 121, // Right after Homepage Settings
            'description' => __('Landing page configuration options.'),
        ));
        
        // Landing Style setting
        $wp_customize->add_setting('firefly_collective_landing_style', array(
            'default' => firefly_collective_get_landing_style(),
            'transport' => 'postMessage',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        
        // Get available landing styles
        $landing_style_choices = firefly_collective_get_landing_style_choices();
        
        // Landing Style control
        $wp_customize->add_control('firefly_collective_landing_style', array(
            'label' => __('Landing Style'),
            'description' => __('Choose the landing page layout style.'),
            'section' => 'firefly_collective_landing',
            'type' => 'select',
            'choices' => $landing_style_choices,
        ));
        
        // Add Navigation section
        $wp_customize->add_section('firefly_collective_navigation', array(
            'title' => __('Navigation'),
            'priority' => 122,
            'description' => __('Navigation configuration options.'),
        ));
        
        // Navigation section placeholder setting/control
        $wp_customize->add_setting('firefly_collective_navigation_placeholder', array(
            'default' => '',
            'transport' => 'refresh',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        
        $wp_customize->add_control('firefly_collective_navigation_placeholder', array(
            'label' => __('Navigation Options'),
            'description' => __('Navigation configuration options will be added here.'),
            'section' => 'firefly_collective_navigation',
            'type' => 'text',
            'input_attrs' => array(
                'placeholder' => __('Coming soon...'),
                'readonly' => 'readonly'
            )
        ));
        
        // Add Layout section
        $wp_customize->add_section('firefly_collective_layout', array(
            'title' => __('Layout'),
            'priority' => 123,
            'description' => __('Layout configuration options.'),
        ));
        
        // Layout section placeholder setting/control
        $wp_customize->add_setting('firefly_collective_layout_placeholder', array(
            'default' => '',
            'transport' => 'refresh',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        
        $wp_customize->add_control('firefly_collective_layout_placeholder', array(
            'label' => __('Layout Options'),
            'description' => __('Layout configuration options will be added here.'),
            'section' => 'firefly_collective_layout',
            'type' => 'text',
            'input_attrs' => array(
                'placeholder' => __('Coming soon...'),
                'readonly' => 'readonly'
            )
        ));
    }
    add_action('customize_register', 'firefly_collective_customize_register');

    /**
	 * Get available landing styles from snippets directory
	 * 
	 * @return array Array of landing style choices for customizer
	 */
	function firefly_collective_get_landing_style_choices() {
		$choices = array();
		$active_template = firefly_collective_get_active_template();
		$snippets_dir = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . $active_template . '/snippets';
		
		if (!is_dir($snippets_dir)) {
			return array('default' => 'Default');
		}
		
		$files = scandir($snippets_dir);
		$landing_files = array();
		
		// Find all files that start with "landing"
		foreach ($files as $file) {
			if ($file !== '.' && $file !== '..' && strpos($file, 'landing') === 0) {
				$landing_files[] = $file;
			}
		}
		
		// Sort files to ensure consistent order
		sort($landing_files);
		
		foreach ($landing_files as $file) {
			$filename_without_ext = pathinfo($file, PATHINFO_FILENAME);
			
			if ($filename_without_ext === 'landing') {
				// The base "landing" file becomes "Default"
				$choices['default'] = 'Default';
			} else {
				// Extract the part after "landing-" and format it
				$style_name = substr($filename_without_ext, 8); // Remove "landing-" (8 characters)
				$formatted_name = firefly_collective_format_landing_style_name($style_name);
				$choices[$style_name] = $formatted_name;
			}
		}
		
		// Ensure "Default" is first if it exists
		if (isset($choices['default'])) {
			$default = array('default' => $choices['default']);
			unset($choices['default']);
			$choices = $default + $choices;
		}
		
		return !empty($choices) ? $choices : array('default' => 'Default');
	}

	/**
	 * Format landing style name for display
	 * 
	 * @param string $style_name The raw style name from filename
	 * @return string Formatted display name
	 */
	function firefly_collective_format_landing_style_name($style_name) {
		// Replace dashes with spaces
		$formatted = str_replace('-', ' ', $style_name);
		
		// Capitalize first letter of each word
		$formatted = ucwords($formatted);
		
		return $formatted;
	}

    /**
     * Handle customizer publish - copy temp template to live template and save landing style
     */
    function firefly_collective_customize_save_after($wp_customize) {
        // Save template changes
        $temp_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION);
        if ($temp_template && firefly_collective_template_exists($temp_template)) {
            update_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, $temp_template);
        }
        
        // Save landing style changes
        $landing_style = $wp_customize->get_setting('firefly_collective_landing_style');
        if ($landing_style) {
            $landing_style_value = $landing_style->post_value();
            if ($landing_style_value !== null) {
                firefly_collective_set_landing_style($landing_style_value);
            }
        }
    }
    add_action('customize_save_after', 'firefly_collective_customize_save_after');

    /**
     * Theme deactivation cleanup
     */
    function firefly_collective_theme_deactivation() {
        // Optional: Clean up theme-specific options
        // delete_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION);
        // delete_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION);
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    add_action('switch_theme', 'firefly_collective_theme_deactivation');

    // Require enqueue.php from the active template directory
    $active_template = firefly_collective_get_active_template();
    $enqueue_file = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . $active_template . '/enqueue.php';

    if ( file_exists( $enqueue_file ) && firefly_collective_is_valid_template_path( $enqueue_file ) ) {
        require_once $enqueue_file;
    }

    function in_customizer_iframe() {
        $in_customizer  = (
            isset($_GET['customize_messenger_channel']) ||
            isset($_GET['customize_changeset_uuid']) ||
            ( isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe' )
        );

        return $in_customizer;
    }

    // Hide admin bar when (a) user is auth_id-only OR (b) we're in the Customizer preview iframe.
    add_filter('show_admin_bar', function ($show) {
        // (a) Your original rule
        if ( ! empty($_COOKIE['auth_id']) ) {
            return false;
        }

        // (b) Customizer preview iframe (persists across iframe navigations)
        if ( isset($_GET['customize_messenger_channel']) || isset($_GET['customize_changeset_uuid']) ) {
            return false;
        }

        // Optional extra signal from modern browsers (doesn't hurt if absent)
        if ( isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe' ) {
            return false;
        }

        return $show;
    }, PHP_INT_MAX);