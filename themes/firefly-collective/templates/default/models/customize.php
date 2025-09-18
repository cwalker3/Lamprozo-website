<?php

	// template/models/customize.php

	if (!defined('ABSPATH')) {
		exit; // Exit if accessed directly
	}

	/**
	 * Template Options Configuration
	 * Define all template-specific options here
	 */
	function template_get_options_config() {
		return array(
			'menu_overlay' => array(
				'default' => 0,
				'type' => 'checkbox',
				'label' => __('Menu Overlay'),
				'description' => __('Enable overlay menu display on front page.'),
				'section' => 'firefly_collective_landing',
				'priority' => 15,
				'sanitize_callback' => function($value) {
					return $value ? 1 : 0;
				}
			),
			'nav_overlay_menu' => array(
				'default' => 0,
				'type' => 'checkbox',
				'label' => __('Overlay Navigation'),
				'description' => __('Enable overlay navigation menu on non-front pages with left-positioned logo.'),
				'section' => 'firefly_collective_navigation',
				'priority' => 10,
				'sanitize_callback' => function($value) {
					return $value ? 1 : 0;
				}
			),
			// CSS Custom Properties - Systematic approach
			'background_color' => array(
				'default' => '#ffffff',
				'type' => 'color',
				'label' => __('Background Color'),
				'description' => __('Set the site background color.'),
				'section' => 'firefly_collective_layout',
				'priority' => 10,
				'css_property' => '--backgroundColor',
				'sanitize_callback' => function($value) {
					// Handle named colors
					if ($value === 'white') return '#ffffff';
					if ($value === 'black') return '#000000';
					
					// Use WordPress built-in sanitization for hex
					$sanitized = sanitize_hex_color($value);
					if ($sanitized) return $sanitized;
					
					// Handle hex without hash
					if (preg_match('/^[a-fA-F0-9]{6}$/', $value)) {
						return '#' . $value;
					}
					
					// Fallback to default
					return '#ffffff';
				}
			),
			// Add more CSS properties here - the system will handle them automatically
			// 'text_color' => array(
			//     'default' => '#333333',
			//     'type' => 'color',
			//     'label' => __('Text Color'),
			//     'description' => __('Set the main text color.'),
			//     'section' => 'firefly_collective_layout',
			//     'priority' => 20,
			//     'css_property' => '--textColor',
			//     'sanitize_callback' => 'sanitize_hex_color'
			// ),
		);
	}

	/**
	 * Normalize option values for control consumption (e.g. ensure checkboxes use real booleans)
	 */
	function template_normalize_option_value($option_key, $value) {
		$options_config = template_get_options_config();
		
		if (!isset($options_config[$option_key])) {
			return $value;
		}
		
		$type = isset($options_config[$option_key]['type']) ? $options_config[$option_key]['type'] : '';
		
		if ($type === 'checkbox') {
			return (bool) $value;
		}
		
		return $value;
	}

	/**
	 * Get template-scoped option name
	 */
	function template_get_option_name($option_key, $is_preview = false) {
		$current_template = firefly_collective_get_active_template();
		$prefix = 'firefly_collective_' . $option_key . '_';
		$suffix = $is_preview ? 'preview_' : '';
		return $prefix . $suffix . $current_template;
	}

	/**
	 * Set template defaults on theme activation
	 */
	function template_set_defaults() {
		$options_config = template_get_options_config();
		
		foreach ($options_config as $option_key => $config) {
		// Apply sanitize callbacks so stored defaults match expected format
		template_set_option($option_key, $config['default']);
		template_set_option_preview($option_key, $config['default']);
		
		// Clear any previously saved Customizer setting so UI reflects defaults
		$setting_id = 'template_' . $option_key;
		delete_option($setting_id);
		}
	}
	add_action('after_switch_theme', 'template_set_defaults');

	/**
	 * Reset preview values to match live values when customizer loads
	 */
	function template_reset_preview_values_on_customizer_load() {
		// Only run in customizer
		if (!(isset($_GET['customize_messenger_channel']) ||
			isset($_GET['customize_changeset_uuid']) ||
			( isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe' ))) {
			return;
		}
		
		// Reset preview values to match live values
		$options_config = template_get_options_config();
		
		foreach ($options_config as $option_key => $config) {
			$live_value = template_get_option($option_key, false);
			template_set_option_preview($option_key, $live_value);
		}
	}
	add_action('customize_controls_init', 'template_reset_preview_values_on_customizer_load');

	/**
	 * Add template-specific customizer controls
	 */
	function template_customize_register($wp_customize) {
		$options_config = template_get_options_config();
		
		foreach ($options_config as $option_key => $config) {
			$setting_id = 'template_' . $option_key;
			
			// Get the current LIVE value (not preview)
			$current_live_value = template_get_option($option_key, false);
			
		// Add setting
		$wp_customize->add_setting($setting_id, array(
			'default' => $current_live_value,
			'type' => 'option',
			'capability' => 'customize',
			'transport' => 'postMessage',
			'sanitize_callback' => isset($config['sanitize_callback']) ? $config['sanitize_callback'] : 'sanitize_text_field'
		));

			// DON'T force set_post_value - let user changes work normally
			// The JavaScript will handle syncing preview values

			// Prepare control args
			$control_args = array(
				'label' => $config['label'],
				'section' => $config['section'],
				'type' => $config['type'],
				'priority' => isset($config['priority']) ? $config['priority'] : 10
			);
			
			if (isset($config['description'])) {
				$control_args['description'] = $config['description'];
			}
			
			if (isset($config['choices'])) {
				$control_args['choices'] = $config['choices'];
			}
			
			if (isset($config['input_attrs'])) {
				$control_args['input_attrs'] = $config['input_attrs'];
			}

			// Use WP_Customize_Color_Control for color types
			if ($config['type'] === 'color') {
				$wp_customize->add_control(
					new WP_Customize_Color_Control(
						$wp_customize,
						$setting_id,
						$control_args
					)
				);
			} else {
				$wp_customize->add_control($setting_id, $control_args);
			}
		}
	}
	add_action('customize_register', 'template_customize_register', 20);

	/**
	 * Get current option value (live or preview)
	 */
	function template_get_option($option_key, $preview = false) {
		$options_config = template_get_options_config();
		$option_name = template_get_option_name($option_key, $preview);
		$default = isset($options_config[$option_key]['default']) ? $options_config[$option_key]['default'] : '';
		$value = get_option($option_name, $default);
		return template_normalize_option_value($option_key, $value);
	}

	/**
	 * Get current option preview value
	 */
	function template_get_option_preview($option_key) {
		$option_name = template_get_option_name($option_key, true);
		$fallback = template_get_option($option_key);
		$value = get_option($option_name, $fallback);
		return template_normalize_option_value($option_key, $value);
	}

	/**
	 * Set option preview value
	 */
	function template_set_option_preview($option_key, $value) {
		$options_config = template_get_options_config();
		$option_name = template_get_option_name($option_key, true);
		
		// Apply sanitization if defined
		if (isset($options_config[$option_key]['sanitize_callback'])) {
			$sanitize_callback = $options_config[$option_key]['sanitize_callback'];
			if (is_callable($sanitize_callback)) {
				$value = call_user_func($sanitize_callback, $value);
			}
		}
		
		return update_option($option_name, $value);
	}

	/**
	 * Set option live value
	 */
	function template_set_option($option_key, $value) {
		$options_config = template_get_options_config();
		$option_name = template_get_option_name($option_key, false);
		
		// Apply sanitization if defined
		if (isset($options_config[$option_key]['sanitize_callback'])) {
			$sanitize_callback = $options_config[$option_key]['sanitize_callback'];
			if (is_callable($sanitize_callback)) {
				$value = call_user_func($sanitize_callback, $value);
			}
		}
		
		return update_option($option_name, $value);
	}

	/**
	 * Generate dynamic CSS for custom properties - Always outputs ALL properties
	 */
	function template_get_dynamic_css($use_preview = false) {
		$options_config = template_get_options_config();
		$css_properties = array();
		
		// Check if we're in customizer preview mode
		$in_customizer = (
			isset($_GET['customize_messenger_channel']) ||
			isset($_GET['customize_changeset_uuid']) ||
			( isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe' )
		);
		
		// Use preview values if in customizer, otherwise use live values
		$should_use_preview = $use_preview || $in_customizer;
		
		foreach ($options_config as $option_key => $config) {
			// Only process options that have a css_property defined
			if (!isset($config['css_property'])) {
				continue;
			}
			
			// Get current value (either customized or default)
			$current_value = $should_use_preview ? 
				template_get_option_preview($option_key) : 
				template_get_option($option_key);
			
			// Always output the property with its current value (or default if not customized)
			$css_properties[] = $config['css_property'] . ': ' . esc_attr($current_value) . ';';
		}
		
		// Return CSS if there are any CSS properties defined
		if (!empty($css_properties)) {
			return ":root {\n\t" . implode("\n\t", $css_properties) . "\n}";
		}
		
		return '';
	}

	/**
	 * Output dynamic CSS in header - Always outputs if CSS properties are defined
	 */
	function template_output_dynamic_css() {
		$dynamic_css = template_get_dynamic_css();
		
		// Always output the style block if we have CSS properties configured
		if (!empty($dynamic_css)) {
			echo "<style id='template-custom-properties'>\n" . $dynamic_css . "\n</style>\n";
		}
	}
	add_action('wp_head', 'template_output_dynamic_css', 1); // Priority 1 - before all other CSS

	/**
	 * Generic handler for option preview changes
	 */
	function handle_change_option_preview(WP_REST_Request $request) {
		$option_key = sanitize_key($request->get_param('option_key'));
		$option_value = $request->get_param('option_value');
		
		if (empty($option_key)) {
			return new WP_Error('missing_option_key', 'Option key is required', array('status' => 400));
		}
		
		if ($option_value === null) {
			return new WP_Error('missing_option_value', 'Option value is required', array('status' => 400));
		}
		
		// Validate option exists in config
		$options_config = template_get_options_config();
		if (!isset($options_config[$option_key])) {
			return new WP_Error('invalid_option', 'Invalid option key', array('status' => 400));
		}
		
		// Update preview option
		template_set_option_preview($option_key, $option_value);
		
		return rest_ensure_response(array(
			'success' => true,
			'option_key' => $option_key,
			'option_value' => $option_value
		));
	}

	/**
	 * Handle customizer publish - save all template option changes
	 */
	function template_customize_save_after($wp_customize) {
		$options_config = template_get_options_config();
		
		foreach ($options_config as $option_key => $config) {
			$setting_id = 'template_' . $option_key;
			$setting = $wp_customize->get_setting($setting_id);
			
			if ($setting) {
				$option_value = $setting->post_value();
				
				if ($option_value !== null) {
					// Apply sanitization if defined
					if (isset($config['sanitize_callback']) && is_callable($config['sanitize_callback'])) {
						$option_value = call_user_func($config['sanitize_callback'], $option_value);
					}
					
					// Save the live value
					template_set_option($option_key, $option_value);
					
					// Sync preview value to match live value
					template_set_option_preview($option_key, $option_value);
					
					// Remove the transient option used by the Customizer since we persist elsewhere
					delete_option($setting_id);
				}
			}
		}
	}
	add_action('customize_save_after', 'template_customize_save_after');

	/**
	 * Get all template options (useful for debugging or frontend access)
	 */
	function template_get_all_options($use_preview = false) {
		$options_config = template_get_options_config();
		$options = array();
		
		foreach ($options_config as $option_key => $config) {
			$options[$option_key] = $use_preview ? 
				template_get_option_preview($option_key) : 
				template_get_option($option_key);
		}
		
		return $options;
	}

	/**
	 * Debug function - call this to see current option states
	 * Usage: Add ?debug_template_options=1 to any page URL when logged in as admin
	 */
	function template_debug_options() {
		if (!isset($_GET['debug_template_options']) || !current_user_can('manage_options')) {
			return;
		}
		
		echo "<div style='background: #fff; border: 2px solid #000; padding: 20px; margin: 20px; z-index: 99999; position: relative;'>";
		echo "<h3>Template Options Debug</h3>";
		
		$options_config = template_get_options_config();
		$current_template = firefly_collective_get_active_template();
		
		echo "<p><strong>Current Template:</strong> {$current_template}</p>";
		
		foreach ($options_config as $option_key => $config) {
			if (!isset($config['css_property'])) continue;
			
			$live_value = template_get_option($option_key, false);
			$preview_value = template_get_option_preview($option_key);
			$default_value = $config['default'];
			$css_property = $config['css_property'];
			
			echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
			echo "<h4>{$config['label']} ({$option_key})</h4>";
			echo "<p><strong>CSS Property:</strong> {$css_property}</p>";
			echo "<p><strong>Default:</strong> '{$default_value}'</p>";
			echo "<p><strong>Live Value:</strong> '{$live_value}'</p>";
			echo "<p><strong>Preview Value:</strong> '{$preview_value}'</p>";
			echo "<p><strong>Live Option Name:</strong> " . template_get_option_name($option_key, false) . "</p>";
			echo "<p><strong>Preview Option Name:</strong> " . template_get_option_name($option_key, true) . "</p>";
			echo "</div>";
		}
		
		// Show generated CSS
		echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
		echo "<h4>Generated CSS (Live)</h4>";
		echo "<pre style='background: #f0f0f0; padding: 10px;'>" . esc_html(template_get_dynamic_css(false)) . "</pre>";
		echo "</div>";
		
		echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
		echo "<h4>Generated CSS (Preview)</h4>";
		echo "<pre style='background: #f0f0f0; padding: 10px;'>" . esc_html(template_get_dynamic_css(true)) . "</pre>";
		echo "</div>";
		
		echo "</div>";
	}
	add_action('wp_footer', 'template_debug_options');

	// Customizer from template
	function enqueue_template_customize_js() {
		global $template_path_web;
		global $main_nonce;

		wp_enqueue_script(
			'my-customize-controls',
			$template_path_web . '/assets/js/customize.js',
			array( 'customize-controls' ),
			false,
			true
		);

		// Get current preview values for all CSS options
		$css_options = array();
		$options_config = template_get_options_config();
		foreach ($options_config as $option_key => $config) {
			if (isset($config['css_property'])) {
				$css_options[$option_key] = template_get_option_preview($option_key);
			}
		}

		wp_localize_script('my-customize-controls', 'customizeData', array(
			'nonce' => $main_nonce,
			'template_options' => array_keys(template_get_options_config()),
			'css_option_values' => $css_options
		));
	}
	add_action( 'customize_controls_enqueue_scripts', 'enqueue_template_customize_js' );
