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
				'description' => __('Enable overlay menu display.'),
				'section' => 'firefly_collective_landing',
				'priority' => 15,
				'sanitize_callback' => function($value) {
					return $value ? 1 : 0;
				}
			),
			// Future options can be added here:
			// 'another_option' => array(
			//     'default' => 'default_value',
			//     'type' => 'select',
			//     'choices' => array('option1' => 'Option 1', 'option2' => 'Option 2'),
			//     'label' => __('Another Option'),
			//     'description' => __('Description of another option.'),
			//     'section' => 'firefly_collective_navigation',
			//     'priority' => 10,
			//     'sanitize_callback' => 'sanitize_text_field'
			// ),
		);
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
			$live_option = template_get_option_name($option_key, false);
			$preview_option = template_get_option_name($option_key, true);
			
			add_option($live_option, $config['default']);
			add_option($preview_option, $config['default']);
		}
	}
	add_action('after_switch_theme', 'template_set_defaults');

	/**
	 * Get current option value (live)
	 */
	function template_get_option($option_key) {
		$options_config = template_get_options_config();
		$option_name = template_get_option_name($option_key, false);
		$default = isset($options_config[$option_key]['default']) ? $options_config[$option_key]['default'] : '';
		return get_option($option_name, $default);
	}

	/**
	 * Get current option preview value
	 */
	function template_get_option_preview($option_key) {
		$option_name = template_get_option_name($option_key, true);
		$fallback = template_get_option($option_key);
		return get_option($option_name, $fallback);
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
	 * Add template-specific customizer controls
	 */
	function template_customize_register($wp_customize) {
		$options_config = template_get_options_config();
		
		foreach ($options_config as $option_key => $config) {
			$setting_id = 'template_' . $option_key;
			
			// Add setting - use LIVE value as default
			$wp_customize->add_setting($setting_id, array(
				'default' => template_get_option($option_key),
				'transport' => 'postMessage',
				'sanitize_callback' => isset($config['sanitize_callback']) ? $config['sanitize_callback'] : 'sanitize_text_field'
			));

			// Prepare control args
			$control_args = array(
				'label' => $config['label'],
				'section' => $config['section'],
				'type' => $config['type'],
				'priority' => isset($config['priority']) ? $config['priority'] : 10
			);
			
			// Add description if provided
			if (isset($config['description'])) {
				$control_args['description'] = $config['description'];
			}
			
			// Add choices for select/radio types
			if (isset($config['choices'])) {
				$control_args['choices'] = $config['choices'];
			}
			
			// Add input_attrs if provided
			if (isset($config['input_attrs'])) {
				$control_args['input_attrs'] = $config['input_attrs'];
			}

			// Add control
			$wp_customize->add_control($setting_id, $control_args);
		}
	}
	add_action('customize_register', 'template_customize_register', 20); // Later priority to ensure theme sections exist

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

		wp_localize_script('my-customize-controls', 'customizeData', array(
			'nonce' => $main_nonce,
			'template_options' => array_keys(template_get_options_config())
		));
	}
	add_action( 'customize_controls_enqueue_scripts', 'enqueue_template_customize_js' );