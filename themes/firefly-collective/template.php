<?php

    // theme/models/template.php

    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }
    
    // Define template system constants
    define('FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE', 'default');
    define('FIREFLY_COLLECTIVE_TEMPLATE_OPTION', 'firefly_collective_active_template');
    define('FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION', 'firefly_collective_active_template_temp');
    define('FIREFLY_COLLECTIVE_TEMPLATES_DIR', get_template_directory() . '/templates');
    
    global $nonce;
    $nonce = wp_create_nonce('wp_rest');

    // Customizer admin only
    function init_customizer($hook) {
        $target_dir_url = plugin_dir_url( dirname( __FILE__, 1 ) );
        $plugin_uri = '/' . ltrim( str_replace( home_url(), '', $target_dir_url ), '/' );
        $unique_id = uniqid();
        if (is_customize_preview()) {
            wp_enqueue_script('customize-js', get_template_directory_uri() . '/assets/js/customize.js', array(), $unique_id, true);
        }
    }
    add_action('admin_enqueue_scripts', 'init_customizer');

    /**
     * Check if a template exists
     */
    function firefly_collective_plugin_template_exists($template_name) {
        $template_name = sanitize_file_name($template_name);
        $template_path = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . $template_name;
        
        // Check if template directory exists and has required files
        return is_dir($template_path) && 
               file_exists($template_path . '/header.php') && 
               file_exists($template_path . '/footer.php');
    }

    function handle_change_template_temp( WP_REST_Request $request ) {
        $template_name = sanitize_text_field( $request->get_param( 'template' ) );
        
        if ( empty( $template_name ) ) {
            return new WP_Error( 'missing_template', 'Template name is required', array( 'status' => 400 ) );
        }
        
        // Validate template exists using plugin function
        if ( ! firefly_collective_plugin_template_exists( $template_name ) ) {
            return new WP_Error( 'invalid_template', 'Template does not exist', array( 'status' => 400 ) );
        }
        
        // Update temp option
        update_option( FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, $template_name );
        
        return rest_ensure_response( array(
            'success' => true,
            'template' => $template_name
        ) );
    }

    // Define landing style system constants
	define('FIREFLY_COLLECTIVE_DEFAULT_LANDING_STYLE', 'default');
	define('FIREFLY_COLLECTIVE_LANDING_STYLE_OPTION', 'firefly_collective_landing_style');
    define('FIREFLY_COLLECTIVE_LANDING_STYLE_PREVIEW_OPTION', 'firefly_collective_landing_style_preview');

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
		
		// Always reset landing style to default on theme activation
		update_option(FIREFLY_COLLECTIVE_LANDING_STYLE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_LANDING_STYLE);
		
		// Initialize landing style preview to match current landing style
		update_option(FIREFLY_COLLECTIVE_LANDING_STYLE_PREVIEW_OPTION, FIREFLY_COLLECTIVE_DEFAULT_LANDING_STYLE);
		
		// Flush rewrite rules if needed
		flush_rewrite_rules();
	}
    add_action('after_switch_theme', 'firefly_collective_theme_activation');

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
     * DISABLED - The customizer system will handle this properly
     */
    function firefly_collective_maybe_reset_temp_template() {
        // COMPLETELY DISABLE AUTO-RESET - let the customizer handle it
        return;
        
        // Don't reset during AJAX requests (customizer uses AJAX)
        if (wp_doing_ajax()) {
            return;
        }
        
        // Don't reset during admin requests (including customizer saves)
        if (is_admin()) {
            return;
        }
        
        // Don't reset during REST API requests (customizer uses REST API)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        
        // Only reset if we're NOT in the customizer iframe
        if (!in_customizer_iframe() && !is_customize_preview()) {
            // Check if we need to reset (not in an active customizer session)
            if (!isset($_GET['customize_changeset_uuid']) && !isset($_POST['customize_changeset_uuid'])) {
                $current_live_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
                $temp_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION);
                
                // Only update if different to avoid unnecessary database writes
                if ($temp_template !== $current_live_template) {
                    error_log("Resetting temp template from {$temp_template} to {$current_live_template}");
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
        
        // Register the setting to save directly to the template option
        $wp_customize->add_setting(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, array(
            'type' => 'option',
            'capability' => 'manage_options',
            'default' => FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE,
            'transport' => 'postMessage',
            'sanitize_callback' => 'sanitize_file_name'
        ));
        
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
            'settings' => FIREFLY_COLLECTIVE_TEMPLATE_OPTION,
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

        // Landing Style control with button
        $wp_customize->add_control('firefly_collective_landing_style', array(
            'label' => __('Landing Style'),
            'description' => __('Choose the landing page layout style.'),
            'section' => 'firefly_collective_landing',
            'type' => 'select',
            'choices' => $landing_style_choices,
        ));

        // Add Edit in Gutenberg button setting (hidden, just for the control)
        $wp_customize->add_setting('firefly_collective_edit_landing_button', array(
            'default' => '',
            'transport' => 'postMessage',
            'sanitize_callback' => 'sanitize_text_field'
        ));

        // Use a text control but style it as a button
        $wp_customize->add_control('firefly_collective_edit_landing_button', array(
            'label' => __('Edit Landing Content'),
            'description' => __('Click the button below to edit landing content in Gutenberg.'),
            'section' => 'firefly_collective_landing',
            'type' => 'text',
            'input_attrs' => array(
                'style' => 'display: none;', // Hide the input
                'readonly' => 'readonly'
            )
        ));
        
        // Add Navigation section
        $wp_customize->add_section('firefly_collective_navigation', array(
            'title' => __('Navigation'),
            'priority' => 122,
            'description' => __('Navigation configuration options.'),
        ));
        
        // Navigation section
        $wp_customize->add_section('firefly_collective_navigation', array(
            'title' => __('Navigation'),
            'priority' => 122,
            'description' => __('Navigation configuration options.'),
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
	 */
	function firefly_collective_format_landing_style_name($style_name) {
		// Replace dashes with spaces
		$formatted = str_replace('-', ' ', $style_name);
		
		// Capitalize first letter of each word
		$formatted = ucwords($formatted);
		
		return $formatted;
	}

    /**
	 * Get the current landing style
	 */
	function firefly_collective_get_landing_style() {
		return get_option(FIREFLY_COLLECTIVE_LANDING_STYLE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_LANDING_STYLE);
	}

	/**
	 * Set the landing style
	 */
	function firefly_collective_set_landing_style($style) {
		$style = sanitize_text_field($style);
		return update_option(FIREFLY_COLLECTIVE_LANDING_STYLE_OPTION, $style);
	}

    /**
	 * Get the current landing style preview value
	 */
	function firefly_collective_get_landing_style_preview() {
		return get_option(FIREFLY_COLLECTIVE_LANDING_STYLE_PREVIEW_OPTION, firefly_collective_get_landing_style());
	}

	/**
	 * Set the landing style preview value
	 */
	function firefly_collective_set_landing_style_preview($style) {
		$style = sanitize_text_field($style);
		return update_option(FIREFLY_COLLECTIVE_LANDING_STYLE_PREVIEW_OPTION, $style);
	}

	/**
	 * Get landing style HTML content from snippets
	 */
	function firefly_collective_get_landing_style_html($style) {
		$active_template = firefly_collective_get_active_template();
		$snippets_dir = FIREFLY_COLLECTIVE_TEMPLATES_DIR . '/' . $active_template . '/snippets';
		
		// Determine the filename based on style
		if ($style === 'default') {
			$filename = 'landing.html';
		} else {
			$filename = 'landing-' . $style . '.html';
		}
		
		$file_path = $snippets_dir . '/' . $filename;
		
		if (file_exists($file_path) && firefly_collective_is_valid_template_path($file_path)) {
			return file_get_contents($file_path);
		}
		
		return false;
	}

    /**
	 * Get rendered HTML from landing style block markup
	 */
	function firefly_collective_get_landing_style_rendered_html($style) {
		$block_markup = firefly_collective_get_landing_style_html($style);
		
		if ($block_markup === false) {
			return false;
		}
		
		// Parse blocks and render to HTML
		$blocks = parse_blocks($block_markup);
		$rendered_html = '';
		
		foreach ($blocks as $block) {
			$rendered_html .= render_block($block);
		}
		
		$rendered_html = trim($rendered_html);
		
		// Wrap with HTML comments for easy identification and replacement
		if (!empty($rendered_html)) {
			$wrapped_html = "<!-- FIREFLY_LANDING_START:" . esc_attr($style) . " -->\n";
			$wrapped_html .= $rendered_html;
			$wrapped_html .= "\n<!-- FIREFLY_LANDING_END:" . esc_attr($style) . " -->";
			return $wrapped_html;
		}
		
		return $rendered_html;
	}

    // Add this handler function to your theme/models/rest.php
	function handle_change_landing_style_preview( WP_REST_Request $request ) {
		$landing_style = sanitize_text_field( $request->get_param( 'landing_style' ) );
		
		if ( empty( $landing_style ) ) {
			return new WP_Error( 'missing_landing_style', 'Landing style is required', array( 'status' => 400 ) );
		}
		
		// Validate landing style exists by checking if we can get its rendered HTML
		$landing_html = firefly_collective_get_landing_style_rendered_html( $landing_style );
		if ( $landing_html === false ) {
			return new WP_Error( 'invalid_landing_style', 'Landing style does not exist', array( 'status' => 400 ) );
		}
		
		// Update preview option
		firefly_collective_set_landing_style_preview( $landing_style );
		
		return rest_ensure_response( array(
			'success' => true,
			'landing_style' => $landing_style
		) );
	}

    /**
	 * Filter content to replace landing HTML in customizer iframe preview
	 */
	function firefly_collective_filter_landing_content($content) {
		
		// Only apply filter in customizer iframe
		if (!in_customizer_iframe()) {
			return $content;
		}
		
		// Skip if content is empty or very short (likely not the main content)
		if (empty($content) || strlen($content) < 100) {
			return $content;
		}
		
		static $processed_content = array();
		
		$current_landing_style = firefly_collective_get_landing_style();
		$preview_landing_style = firefly_collective_get_landing_style_preview();

		// Only apply filter if preview differs from current
		if ($current_landing_style === $preview_landing_style) {
			return $content;
		}
		
		// Create a cache key based on content hash and styles
		$cache_key = md5($content . $current_landing_style . $preview_landing_style);
		
		// Return cached result if we've already processed this exact content
		if (isset($processed_content[$cache_key])) {
			return $processed_content[$cache_key];
		}
		
		// Look for current landing style wrapped in HTML comments
		$current_pattern = '/<!-- FIREFLY_LANDING_START:' . preg_quote($current_landing_style, '/') . ' -->.*?<!-- FIREFLY_LANDING_END:' . preg_quote($current_landing_style, '/') . ' -->/s';
		
		if (preg_match($current_pattern, $content)) {
			// Get the preview landing style content (already wrapped with comments)
			// Prefer saved content over template content
			$preview_landing_html = firefly_collective_get_saved_landing_style_rendered_html($preview_landing_style);
			if ($preview_landing_html === false) {
				$preview_landing_html = firefly_collective_get_landing_style_rendered_html($preview_landing_style);
			}
			
			if ($preview_landing_style === 'default') {
				$saved_default = firefly_collective_get_saved_landing_style_rendered_html('default');
				
				$template_default = firefly_collective_get_landing_style_rendered_html('default');
				
			}
			
			if ($preview_landing_html !== false) {
				// Replace the current wrapped content with preview wrapped content
				$filtered_content = preg_replace($current_pattern, $preview_landing_html, $content);
				$processed_content[$cache_key] = $filtered_content;
				return $filtered_content;
			}
		}
		
		// Fallback: If no comment markers found, try to add them and then replace
		// This handles content that might not have been wrapped yet
		// Prefer saved content over template content for current style
		$current_landing_html = firefly_collective_get_saved_landing_style_rendered_html($current_landing_style);
		if ($current_landing_html === false) {
			$current_landing_html = firefly_collective_get_landing_style_rendered_html($current_landing_style);
		}
		
		// For preview, prefer saved content if available, otherwise use template
		$preview_landing_html = firefly_collective_get_saved_landing_style_rendered_html($preview_landing_style);
		if ($preview_landing_html === false) {
			$preview_landing_html = firefly_collective_get_landing_style_rendered_html($preview_landing_style);
		}
		
		if ($current_landing_html !== false && $preview_landing_html !== false) {
			// Remove the comment wrappers to get raw HTML for fallback matching
			$current_raw_html = preg_replace('/<!-- FIREFLY_LANDING_START:.*? -->|<!-- FIREFLY_LANDING_END:.*? -->/', '', $current_landing_html);
			$current_raw_html = trim($current_raw_html);
			
			// Try exact match on the raw HTML
			if (strpos($content, $current_raw_html) !== false) {
				$filtered_content = str_replace($current_raw_html, $preview_landing_html, $content);
				$processed_content[$cache_key] = $filtered_content;
				return $filtered_content;
			}
		}
		
		$processed_content[$cache_key] = $content;
		return $content;
	}

    /**
	 * Get rendered HTML from saved landing style content with proper wrapping
	 */
	function firefly_collective_get_saved_landing_style_rendered_html($style) {
		$block_markup = firefly_collective_get_saved_landing_style_content($style);
		
		if ($block_markup === false) {
			return false;
		}
		
		// Parse blocks and render to HTML
		$blocks = parse_blocks($block_markup);
		$rendered_html = '';
		
		foreach ($blocks as $block) {
			$rendered_html .= render_block($block);
		}
		
		$rendered_html = trim($rendered_html);
		
		// Wrap with HTML comments for easy identification and replacement
		if (!empty($rendered_html)) {
			$wrapped_html = "<!-- FIREFLY_LANDING_START:" . esc_attr($style) . " -->\n";
			$wrapped_html .= $rendered_html;
			$wrapped_html .= "\n<!-- FIREFLY_LANDING_END:" . esc_attr($style) . " -->";
			return $wrapped_html;
		}
		
		return $rendered_html;
	}

    /**
	 * Always wrap landing content with HTML comments when in customizer iframe
	 */
	function firefly_collective_wrap_landing_content($content) {
		// Only wrap when in customizer iframe
		if (!in_customizer_iframe()) {
			return $content;
		}
		
		// Only process on the home page
		$home_page_id = firefly_collective_get_home_page_id();
		if (!$home_page_id || get_the_ID() != $home_page_id) {
			return $content;
		}
		
		// Skip if already wrapped or too short
		if (empty($content) || strlen($content) < 100 || strpos($content, '<!-- FIREFLY_LANDING_START:') !== false) {
			return $content;
		}
		
		$current_landing_style = firefly_collective_get_landing_style();
		
		// Extract landing block from content
		$extracted = firefly_collective_extract_landing_block($content);
		if (!empty($extracted['block'])) {
			// Render the block to HTML and wrap it
			$blocks = parse_blocks($extracted['block']);
			$rendered_html = '';
			
			foreach ($blocks as $block) {
				$rendered_html .= render_block($block);
			}
			
			$rendered_html = trim($rendered_html);
			
			if (!empty($rendered_html)) {
				// Wrap with HTML comments using the CURRENT active style
				$wrapped_html = "<!-- FIREFLY_LANDING_START:" . esc_attr($current_landing_style) . " -->\n";
				$wrapped_html .= $rendered_html;
				$wrapped_html .= "\n<!-- FIREFLY_LANDING_END:" . esc_attr($current_landing_style) . " -->";
				
				// Replace the original content with wrapped version plus remaining content
				return $wrapped_html . $extracted['remaining'];
			}
		}
		
		return $content;
	}
	add_filter('the_content', 'firefly_collective_wrap_landing_content', 1);

	/**
	 * Initialize landing content filtering
	 * Only hook the filter when in customizer iframe and when preview differs from current
	 */
	function firefly_collective_init_landing_content_filter() {
		
		// Only initialize in customizer iframe
		if (!in_customizer_iframe()) {
			return;
		}
		
		$current_landing_style = firefly_collective_get_landing_style();
		$preview_landing_style = firefly_collective_get_landing_style_preview();
		
		// Only hook filter if preview differs from current
		if ($current_landing_style !== $preview_landing_style) {
			// Hook into various content filters where landing content might appear
			add_filter('the_content', 'firefly_collective_filter_landing_content', 10);
			add_filter('get_the_excerpt', 'firefly_collective_filter_landing_content', 10);
			
			// Hook into output buffer for full page content replacement if needed
			add_action('wp_loaded', 'firefly_collective_start_landing_output_buffer');
		}
	}
	add_action('init', 'firefly_collective_init_landing_content_filter', 20);

	/**
	 * Start output buffering for full page landing content replacement
	 * This catches content that might not go through the_content filter
	 */
	function firefly_collective_start_landing_output_buffer() {
		if (in_customizer_iframe()) {
			ob_start('firefly_collective_filter_full_page_landing_content');
		}
	}

	/**
	 * Filter full page output for landing content replacement
	 */
	function firefly_collective_filter_full_page_landing_content($buffer) {
		return firefly_collective_filter_landing_content($buffer);
	}

    function handle_get_landing_style_preview( WP_REST_Request $request ) {
        $preview_style = firefly_collective_get_landing_style_preview();
        
        return rest_ensure_response( array(
            'success' => true,
            'preview_style' => $preview_style
        ) );
    }

    /**
     * Get the home page ID
     */
    function firefly_collective_get_home_page_id() {
        return get_option('page_on_front');
    }

    /**
     * Save landing style content to post meta
     */
    function firefly_collective_save_landing_style_content($style, $content) {
        $home_page_id = firefly_collective_get_home_page_id();
        if (!$home_page_id) {
            return false;
        }
        
        $meta_key = '_landing_style_' . sanitize_key($style);
        return update_post_meta($home_page_id, $meta_key, $content);
    }

    /**
     * Get saved landing style content from post meta
     */
    function firefly_collective_get_saved_landing_style_content($style) {
        $home_page_id = firefly_collective_get_home_page_id();
        if (!$home_page_id) {
            return false;
        }
        
        $meta_key = '_landing_style_' . sanitize_key($style);
        $content = get_post_meta($home_page_id, $meta_key, true);
        
        return !empty($content) ? $content : false;
    }

    /**
     * Extract the first wp:cover block from content
     */
    function firefly_collective_extract_landing_block($content) {
        $blocks = parse_blocks($content);
        
        if (empty($blocks)) {
            return array('block' => '', 'remaining' => $content);
        }
        
        // Find first wp:cover block
        $landing_block = null;
        $landing_index = null;
        
        foreach ($blocks as $index => $block) {
            if ($block['blockName'] === 'core/cover') {
                $landing_block = $block;
                $landing_index = $index;
                break;
            }
        }
        
        if ($landing_block === null) {
            return array('block' => '', 'remaining' => $content);
        }
        
        // Remove the landing block from blocks array
        array_splice($blocks, $landing_index, 1);
        
        // Convert back to content
        $remaining_content = '';
        foreach ($blocks as $block) {
            $remaining_content .= serialize_block($block);
        }
        
        return array(
            'block' => serialize_block($landing_block),
            'remaining' => $remaining_content
        );
    }

    /**
     * Filter REST API response for Gutenberg content loading
     */
    function firefly_collective_filter_rest_post_content($response, $post, $request) {
		// Only filter if landing_preview parameter is present
		if (!isset($_GET['landing_preview'])) {
			return $response;
		}
		
		$home_page_id = firefly_collective_get_home_page_id();
		if (!$home_page_id || $post->ID != $home_page_id) {
			return $response;
		}
		
		$preview_style = sanitize_text_field($_GET['landing_preview']);
		$current_style = firefly_collective_get_landing_style();
		
		// Get the current content
		$current_content = $response->data['content']['raw'];
		
		// Extract current landing block for structure, but DON'T save it yet
		$extracted = firefly_collective_extract_landing_block($current_content);
		
		// Get the preview style content (RAW BLOCK MARKUP, not rendered HTML)
		$preview_content = firefly_collective_get_saved_landing_style_content($preview_style);
		
		// If no saved content, fall back to RAW template file content (not rendered)
		if ($preview_content === false) {
			$preview_content = firefly_collective_get_landing_style_html($preview_style); // This gets raw block markup
		}
		
		if ($preview_content) {
			// Combine preview landing content with remaining content
			$new_content = $preview_content . $extracted['remaining'];
			$response->data['content']['raw'] = $new_content;
			$response->data['content']['rendered'] = apply_filters('the_content', $new_content);
		}
		
		return $response;
	}
    add_filter('rest_prepare_page', 'firefly_collective_filter_rest_post_content', 10, 3);

    // Check if our template files are properly formatted
    function firefly_collective_validate_landing_file($style) {
        $content = firefly_collective_get_landing_style_html($style);
        
        if ($content === false) {
            return false;
        }
        
        // Check if content has block comments
        $has_blocks = strpos($content, '<!-- wp:') !== false;
        
        if (!$has_blocks) {
            return false;
        }
        
        // Parse blocks to validate structure
        $blocks = parse_blocks($content);
        $valid_blocks = 0;
        
        foreach ($blocks as $block) {
            if (!empty($block['blockName'])) {
                $valid_blocks++;
            }
        }
        
        return $valid_blocks > 0;
    }

    /**
     * Add landing_preview parameter to allowed query vars
     */
    function firefly_collective_add_landing_preview_query_var($vars) {
        $vars[] = 'landing_preview';
        return $vars;
    }
    add_filter('query_vars', 'firefly_collective_add_landing_preview_query_var');

    /**
     * Ensure the landing_preview parameter persists in admin
     */
    function firefly_collective_preserve_landing_preview_param() {
        if (is_admin() && isset($_GET['landing_preview'])) {
            // Add JavaScript to maintain the parameter in AJAX requests
            add_action('admin_footer', function() {
                $preview_style = sanitize_text_field($_GET['landing_preview']);
                ?>
                <script>
                // Intercept WordPress REST API calls to add our parameter
                (function() {
                    var originalFetch = window.fetch;
                    window.fetch = function(url, options) {
                        if (typeof url === 'string' && url.includes('/wp/v2/pages/')) {
                            var urlObj = new URL(url, window.location.origin);
                            urlObj.searchParams.set('landing_preview', '<?php echo esc_js($preview_style); ?>');
                            url = urlObj.toString();
                        }
                        return originalFetch(url, options);
                    };
                })();
                </script>
                <?php
            });
        }
    }
    add_action('init', 'firefly_collective_preserve_landing_preview_param');

    // Update the handle_edit_landing_in_gutenberg function to add debugging:
    function handle_edit_landing_in_gutenberg( WP_REST_Request $request ) {
        $preview_style = sanitize_text_field( $request->get_param( 'preview_style' ) );
        $current_style = firefly_collective_get_landing_style();
        
        // Validate the preview style file
        if (!firefly_collective_validate_landing_file($preview_style)) {
            return new WP_Error( 'invalid_landing_file', 'Landing style file is not properly formatted', array( 'status' => 400 ) );
        }
        
        $home_page_id = firefly_collective_get_home_page_id();
        if (!$home_page_id) {
            return new WP_Error( 'no_home_page', 'No home page found', array( 'status' => 400 ) );
        }
        
        // Build the edit URL
        $edit_url = admin_url('post.php?post=' . $home_page_id . '&action=edit');
        
        // If preview style differs from current, add parameter
        if ($preview_style !== $current_style) {
            $edit_url .= '&landing_preview=' . urlencode($preview_style);
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'edit_url' => $edit_url,
            'needs_preview' => ($preview_style !== $current_style)
        ) );
    }

    // Add these functions to theme/models/template.php

    /**
     * Detect if we're editing with a landing preview parameter
     */
    function firefly_collective_is_editing_with_landing_preview() {
        return is_admin() && isset($_GET['landing_preview']);
    }

    /**
     * Get the current landing preview parameter from URL
     */
    function firefly_collective_get_current_landing_preview_param() {
        if (firefly_collective_is_editing_with_landing_preview()) {
            return sanitize_text_field($_GET['landing_preview']);
        }
        return false;
    }

    /**
	 * Save landing style when home page is saved - use URL parameter as primary source
	 */
	function firefly_collective_save_landing_style_on_page_save($post_id, $post, $update) {
		
		// Only process for the home page
		$home_page_id = firefly_collective_get_home_page_id();
		if (!$home_page_id || $post_id != $home_page_id) {
			return;
		}
		
		// Avoid infinite loops
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		
		// Check user permissions
		if (!current_user_can('edit_page', $post_id)) {
			return;
		}
		
		// Extract landing block from content
		$extracted = firefly_collective_extract_landing_block($post->post_content);
		if (empty($extracted['block'])) {
			return;
		}
		
		// Primary: Use URL parameter if available (user's explicit intent)
		$intended_style = null;
		
		// Check URL parameter directly (don't rely on is_admin() check)
		if (isset($_GET['landing_preview']) && !empty($_GET['landing_preview'])) {
			$intended_style = sanitize_text_field($_GET['landing_preview']);
		} else {
			// Fallback: Try to auto-detect the style
			$intended_style = firefly_collective_detect_landing_style($extracted['block']);
		}
		
		if ($intended_style) {
			
			// Save the content for this style
			firefly_collective_save_landing_style_content($intended_style, $extracted['block']);
			
			// Update the active landing style
			$result = firefly_collective_set_landing_style($intended_style);
			
			// Sync preview style to match
			firefly_collective_set_landing_style_preview($intended_style);
		}
	}
    add_action('save_post', 'firefly_collective_save_landing_style_on_page_save', 10, 3);

    /**
	 * Generic fallback detection - works with any style names
	 * Since style names are arbitrary, we use simpler heuristics
	 */
	function firefly_collective_detect_landing_style($block_content) {
		
		// Try to match against existing saved content for known styles
		$available_styles = array_keys(firefly_collective_get_landing_style_choices());
		$current_style = firefly_collective_get_landing_style();
		
		// First, try to see if content matches the current active style
		$saved_content_for_current = firefly_collective_get_saved_landing_style_content($current_style);
		if ($saved_content_for_current) {
			// If content is similar to existing saved content, keep current style
			if (firefly_collective_content_roughly_matches($block_content, $saved_content_for_current)) {
				return $current_style;
			}
		}
		
		// If no match with current style, default to 'default'
		return 'default';
	}
	
	/**
	 * Generic content comparison - checks rough similarity without assumptions
	 */
	function firefly_collective_content_roughly_matches($content1, $content2) {
		// Simple similarity check - if content is very different, they don't match
		$similarity = similar_text($content1, $content2, $percent);
		return $percent > 80; // 80% similarity threshold
	}

    // Add a notice in the editor when editing with preview parameter
    function firefly_collective_add_landing_preview_notice() {
        if (!firefly_collective_is_editing_with_landing_preview()) {
            return;
        }
        
        $preview_style = firefly_collective_get_current_landing_preview_param();
        $formatted_style = firefly_collective_format_landing_style_name($preview_style);
        
        ?>
        <div class="notice notice-info" style="margin: 10px 0;">
            <p><strong>Landing Style Preview:</strong> You are editing with the "<?php echo esc_html($formatted_style); ?>" landing style. When you save this page, it will become the active landing style.</p>
        </div>
        <script>
        // Add the notice to the Gutenberg editor
        document.addEventListener('DOMContentLoaded', function() {
            if (window.wp && window.wp.data) {
                // Wait for editor to be ready
                var checkEditor = setInterval(function() {
                    var editorHeader = document.querySelector('.editor-header') || document.querySelector('.edit-post-header');
                    var notice = document.querySelector('.notice.notice-info');
                    
                    if (editorHeader && notice) {
                        var clonedNotice = notice.cloneNode(true);
                        editorHeader.parentNode.insertBefore(clonedNotice, editorHeader.nextSibling);
                        clearInterval(checkEditor);
                    }
                }, 500);
            }
        });
        </script>
        <?php
    }
    add_action('admin_notices', 'firefly_collective_add_landing_preview_notice');

    // Debug: Check current landing style on admin load
	function firefly_collective_debug_current_style() {
		if (is_admin() && isset($_GET['customize_messenger_channel'])) {
			$current = firefly_collective_get_landing_style();
			$preview = firefly_collective_get_landing_style_preview();
		}
	}
	add_action('admin_init', 'firefly_collective_debug_current_style');

    // Add this function to provide a "clean" edit URL after saving
    function firefly_collective_get_clean_edit_url($post_id) {
        return admin_url('post.php?post=' . $post_id . '&action=edit');
    }

    // Add a redirect after save to clean URL (optional)
    function firefly_collective_redirect_after_landing_save($location, $post_id) {
        // Only redirect if we saved with a landing preview parameter
        if (firefly_collective_is_editing_with_landing_preview()) {
            $home_page_id = firefly_collective_get_home_page_id();
            if ($home_page_id && $post_id == $home_page_id) {
                // Remove the landing_preview parameter from redirect URL
                $clean_url = firefly_collective_get_clean_edit_url($post_id);
                return $clean_url;
            }
        }
        return $location;
    }
    add_filter('redirect_post_location', 'firefly_collective_redirect_after_landing_save', 10, 2);

    /**
     * Handle customizer publish - copy temp template to live template and save landing style
     */
    function firefly_collective_customize_save_after($wp_customize) {
        // The template is now saved automatically by the customizer since we're using type => 'option'
        // Just sync the temp option to match the saved value
        $saved_template = get_option(FIREFLY_COLLECTIVE_TEMPLATE_OPTION, FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
        update_option(FIREFLY_COLLECTIVE_TEMPLATE_TEMP_OPTION, $saved_template);
        
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