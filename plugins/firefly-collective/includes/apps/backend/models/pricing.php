<?php
/**
 * Pricing Model File
 *
 * This file adds a Pricing menu to the admin, enqueues assets,
 * and provides functions to create/update the pricing database tables.
 * When "Apply Changes" is clicked, the JSON control data along with a mapping
 * of name changes is used to UPSERT the database so that existing IDs are preserved.
 */

/* ------------------------------ *
 * Admin Menu & Assets
 * ------------------------------ */
function firefly_collective_add_pricing_link() {
    add_menu_page(
        'Pricing',
        'Pricing',
        'manage_options',
        'pricing',
        'firefly_collective_pricing_dashboard',
        'dashicons-money-alt'
    );
}
add_action('admin_menu', 'firefly_collective_add_pricing_link');

function enqueue_pricing_styles_and_scripts($hook) {
    if ($hook !== 'toplevel_page_pricing') {
        return;
    }
    
    $plugin_root_url = dirname(plugin_dir_url(__FILE__)) . '/';
    $unique_id = uniqid();
    
    // Enqueue CSS and JS.
    wp_enqueue_style('pricing-css', $plugin_root_url . 'assets/css/pricing.css', array(), $unique_id);
    wp_enqueue_script('pricing-js', $plugin_root_url . 'assets/js/pricing.js', array(), $unique_id, true);

    // Read pricing.json from the plugin root.
    $plugin_root_path = dirname(plugin_dir_path(__FILE__));
    $pricing_json_path = $plugin_root_path . '/pricing.json';
    $pricing_data = array();
    if (file_exists($pricing_json_path)) {
        $content = file_get_contents($pricing_json_path);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $pricing_data = $decoded;
        }
    }
    // Localize pricing data and settings.
    $nonce = wp_create_nonce('wp_rest');
    $api_url = get_rest_url(null, 'custom-api/v1/');
    wp_localize_script('pricing-js', 'pricingData', array(
        'data'   => $pricing_data,
        'nonce'  => $nonce,
        'apiUrl' => $api_url
    ));
    wp_localize_script('pricing-js', 'pricingDataSettings', array(
        'nonce'  => $nonce,
        'apiUrl' => $api_url
    ));
}
add_action('admin_enqueue_scripts', 'enqueue_pricing_styles_and_scripts');

function firefly_collective_pricing_dashboard() {
    $plugin_root = dirname(plugin_dir_path(__FILE__));
    $view_path = $plugin_root . '/views/pricing.php';
    if (file_exists($view_path)) {
        require_once $view_path;
    } else {
        wp_die('The pricing view file could not be found.', 'File Not Found', array('response' => 404));
    }
}

/* ------------------------------ *
 * Schema & Upsert Functions
 * ------------------------------ */

/**
 * Determine SQL type from a sample value.
 */
function get_sql_type($key, $value, $default_map = array()) {
    if (isset($default_map[$key])) {
        return $default_map[$key];
    }
    if (is_bool($value)) {
        return "TINYINT(1)";
    } elseif (is_int($value)) {
        return "INT";
    } elseif (is_float($value)) {
        return "FLOAT";
    } elseif (is_string($value)) {
        $len = strlen($value);
        if ($len <= 255) {
            return "VARCHAR(255)";
        } else {
            return "TEXT";
        }
    }
    return "TEXT";
}

/**
 * Default columns for features.
 */
function get_default_feature_columns() {
    return array(
        'featureName' => "VARCHAR(255) NOT NULL",
        'description' => "TEXT"
    );
}

/**
 * Default columns for options.
 */
function get_default_option_columns() {
    return array(
        'optionName'   => "VARCHAR(255) NOT NULL",
        'recurring'    => "TINYINT(1)",
        'startDate'    => "DATE",
        'interval'     => "VARCHAR(50)",
        'staticPrice'  => "FLOAT",
        'priceFloor'   => "FLOAT",
        'priceCeiling' => "FLOAT",
        'optionMetric' => "VARCHAR(50)"
    );
}

/**
 * Default columns for addons.
 */
function get_default_addon_columns() {
    return array(
        'addonName'           => "VARCHAR(255) NOT NULL",
        'addOnMetric'         => "VARCHAR(50)",
        'priceMultiplierType' => "VARCHAR(50)",
        'staticPriceMod'      => "FLOAT",
        'floorPriceMod'       => "FLOAT",
        'ceilingPriceMod'     => "FLOAT"
    );
}

/**
 * Merge extra columns found in $records with $default_columns.
 */
function merge_extra_columns($records, $default_columns) {
    $columns = $default_columns;
    foreach ($records as $record) {
        foreach ($record as $key => $value) {
            if ($key == 'options' || $key == 'addons') {
                continue;
            }
            if (!isset($columns[$key])) {
                if ($value === null) {
                    $columns[$key] = "TEXT";
                } else {
                    $columns[$key] = get_sql_type($key, $value);
                }
            }
        }
    }
    return $columns;
}

/**
 * Update table schema for a given table.
 */
function update_table_schema($table, $desired_columns) {
    global $wpdb;
    $existing = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
    $existing_columns = array();
    foreach ($existing as $col) {
        $existing_columns[$col['Field']] = $col['Type'];
    }
    // Add missing columns.
    foreach ($desired_columns as $col => $def) {
        if (!isset($existing_columns[$col])) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN `{$col}` {$def}");
        }
    }
    // Drop columns not in desired columns.
    foreach ($existing_columns as $col => $type) {
        if ($col == 'id' || $col == 'featureId' || $col == 'optionId') {
            continue;
        }
        if (!isset($desired_columns[$col])) {
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN `{$col}`");
        }
    }
}

/**
 * Create pricing tables if they don't exist.
 */
function create_pricing_tables_if_not_exist() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // ffc_features table.
    $features_default = get_default_feature_columns();
    $columns_sql = "";
    foreach ($features_default as $col => $def) {
        $columns_sql .= "`{$col}` {$def}, ";
    }
    $sql_features = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_features (
        id INT NOT NULL AUTO_INCREMENT,
        {$columns_sql}
        PRIMARY KEY  (id),
        UNIQUE KEY featureName (featureName)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_features);

    // ffc_options table.
    $options_default = get_default_option_columns();
    $columns_sql = "";
    foreach ($options_default as $col => $def) {
        $columns_sql .= "`{$col}` {$def}, ";
    }
    $sql_options = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_options (
        id INT NOT NULL AUTO_INCREMENT,
        featureId INT NOT NULL,
        {$columns_sql}
        PRIMARY KEY  (id),
        KEY featureId (featureId)
    ) $charset_collate;";
    dbDelta($sql_options);

    // ffc_addons table.
    $addons_default = get_default_addon_columns();
    $columns_sql = "";
    foreach ($addons_default as $col => $def) {
        $columns_sql .= "`{$col}` {$def}, ";
    }
    $sql_addons = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_addons (
        id INT NOT NULL AUTO_INCREMENT,
        optionId INT NOT NULL,
        {$columns_sql}
        PRIMARY KEY  (id),
        KEY optionId (optionId)
    ) $charset_collate;";
    dbDelta($sql_addons);
}

/**
 * Helper: Normalize a record.
 */
function normalize_record($record) {
    foreach ($record as $k => $v) {
        if (is_array($v) && isset($v['text'])) {
            $record[$k] = $v['text'];
        }
    }
    return $record;
}

/**
 * Update pricing schema based on current JSON data.
 */
function update_pricing_schema($data) {
    global $wpdb;
    // Features.
    $features = array();
    foreach ($data['features'] as $feature) {
        $features[] = $feature;
    }
    $desired_features = merge_extra_columns($features, get_default_feature_columns());
    update_table_schema($wpdb->prefix . "ffc_features", $desired_features);

    // Options.
    $options = array();
    foreach ($data['features'] as $feature) {
        if (isset($feature['options']) && is_array($feature['options'])) {
            foreach ($feature['options'] as $option) {
                $options[] = $option;
            }
        }
    }
    $desired_options = merge_extra_columns($options, get_default_option_columns());
    update_table_schema($wpdb->prefix . "ffc_options", $desired_options);

    // Addons.
    $addons = array();
    foreach ($data['features'] as $feature) {
        if (isset($feature['options']) && is_array($feature['options'])) {
            foreach ($feature['options'] as $option) {
                if (isset($option['addons']) && is_array($option['addons'])) {
                    foreach ($option['addons'] as $addon) {
                        $addons[] = $addon;
                    }
                }
            }
        }
    }
    $desired_addons = merge_extra_columns($addons, get_default_addon_columns());
    update_table_schema($wpdb->prefix . "ffc_addons", $desired_addons);
}

/**
 * Upsert pricing data into the DB, preserving IDs when names are changed.
 *
 * The REST payload is expected to have the structure:
 * {
 *    pricingData: { features: [...] },
 *    nameChanges: {
 *       features: { "0": { oldName: "Old", newName: "New" }, ... },
 *       options: { "0": { "0": { oldName: "Old", newName: "New" }, ... }, ... },
 *       addons: { "0": { "0": { "0": { oldName: "Old", newName: "New" } } }, ... }
 *    }
 * }
 *
 * In addition to updating/inserting records, this function now deletes any features,
 * options or addons that exist in the DB but are not present in the incoming JSON.
 */
function upsert_pricing_data($payload) {
    global $wpdb;
    create_pricing_tables_if_not_exist();
    // Use pricingData from the payload.
    $pricingData = $payload['pricingData'];
    update_pricing_schema($pricingData);

    // Retrieve nameChanges mapping.
    $nameChanges = isset($payload['nameChanges']) ? $payload['nameChanges'] : array(
        'features' => array(),
        'options'  => array(),
        'addons'   => array()
    );

    $features_table = $wpdb->prefix . "ffc_features";
    $options_table  = $wpdb->prefix . "ffc_options";
    $addons_table   = $wpdb->prefix . "ffc_addons";

    // Arrays to track processed IDs.
    $processed_features = array();
    $processed_options = array(); // keyed by featureId => array of option IDs
    $processed_addons = array();  // keyed by optionId => array of addon IDs

    // Process each feature.
    foreach ($pricingData['features'] as $fIndex => $feature) {
        $finalFeatureName = $feature['featureName'];
        $oldFeatureName = $finalFeatureName;
        if (isset($nameChanges['features'][$fIndex]['oldName']) && isset($nameChanges['features'][$fIndex]['newName'])) {
            $oldFeatureName = $nameChanges['features'][$fIndex]['oldName'];
            $finalFeatureName = $nameChanges['features'][$fIndex]['newName'];
        }
        $row = null;
        if ($oldFeatureName !== $finalFeatureName) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$features_table} WHERE featureName = %s",
                $oldFeatureName
            ), ARRAY_A);
        }
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$features_table} WHERE featureName = %s",
                $finalFeatureName
            ), ARRAY_A);
        }
        $feature_to_save = $feature;
        unset($feature_to_save['options']);
        $feature_to_save['featureName'] = $finalFeatureName;
        $feature_to_save = normalize_record($feature_to_save);
        $feature_id = 0;
        if ($row) {
            $feature_id = $row['id'];
            $wpdb->update($features_table, $feature_to_save, array('id' => $feature_id));
        } else {
            $wpdb->insert($features_table, $feature_to_save);
            $feature_id = $wpdb->insert_id;
        }
        $processed_features[] = $feature_id;
        // Clear mapping for this feature.
        $nameChanges['features'][$fIndex] = array();

        // Process options.
        if (isset($feature['options']) && is_array($feature['options'])) {
            foreach ($feature['options'] as $oIndex => $option) {
                $finalOptionName = $option['optionName'];
                $oldOptionName = $finalOptionName;
                if (isset($nameChanges['options'][$fIndex][$oIndex]['oldName']) &&
                    isset($nameChanges['options'][$fIndex][$oIndex]['newName'])) {
                    $oldOptionName = $nameChanges['options'][$fIndex][$oIndex]['oldName'];
                    $finalOptionName = $nameChanges['options'][$fIndex][$oIndex]['newName'];
                }
                $row_option = null;
                if ($oldOptionName !== $finalOptionName) {
                    $row_option = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM {$options_table} WHERE featureId = %d AND optionName = %s",
                        $feature_id, $oldOptionName
                    ), ARRAY_A);
                }
                if (!$row_option) {
                    $row_option = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM {$options_table} WHERE featureId = %d AND optionName = %s",
                        $feature_id, $finalOptionName
                    ), ARRAY_A);
                }
                $option_to_save = $option;
                unset($option_to_save['addons']);
                $option_to_save['optionName'] = $finalOptionName;
                $option_to_save['featureId'] = $feature_id;
                $option_to_save = normalize_record($option_to_save);
                $option_id = 0;
                if ($row_option) {
                    $option_id = $row_option['id'];
                    $wpdb->update($options_table, $option_to_save, array('id' => $option_id));
                } else {
                    $wpdb->insert($options_table, $option_to_save);
                    $option_id = $wpdb->insert_id;
                }
                // Track processed option IDs per feature.
                $processed_options[$feature_id][] = $option_id;
                $nameChanges['options'][$fIndex][$oIndex] = array();

                // Process addons.
                // Delete all addons for this option then insert new ones.
                $wpdb->delete($addons_table, array('optionId' => $option_id));
                if (isset($option['addons']) && is_array($option['addons'])) {
                    foreach ($option['addons'] as $aIndex => $addon) {
                        $finalAddonName = isset($addon['addonName']) ? $addon['addonName'] : '';
                        $oldAddonName = $finalAddonName;
                        if (isset($nameChanges['addons'][$fIndex][$oIndex][$aIndex]['oldName']) &&
                            isset($nameChanges['addons'][$fIndex][$oIndex][$aIndex]['newName'])) {
                            $oldAddonName = $nameChanges['addons'][$fIndex][$oIndex][$aIndex]['oldName'];
                            $finalAddonName = $nameChanges['addons'][$fIndex][$oIndex][$aIndex]['newName'];
                        }
                        $addon_to_save = $addon;
                        $addon_to_save['addonName'] = $finalAddonName;
                        if (isset($addon_to_save['addon_name'])) {
                            unset($addon_to_save['addon_name']);
                        }
                        $addon_to_save['optionId'] = $option_id;
                        $addon_to_save = normalize_record($addon_to_save);
                        $wpdb->insert($addons_table, $addon_to_save);
                        $processed_addons[$option_id][] = $wpdb->insert_id;
                        $nameChanges['addons'][$fIndex][$oIndex][$aIndex] = array();
                    }
                }
            }
        }
    }
    
    // Delete any features that exist in the DB but were not processed.
    $all_feature_ids = $wpdb->get_col("SELECT id FROM {$features_table}");
    $to_delete_features = array_diff($all_feature_ids, $processed_features);
    foreach ($to_delete_features as $fid) {
        $option_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$options_table} WHERE featureId = %d", $fid));
        if (!empty($option_ids)) {
            $ids = implode(',', array_map('intval', $option_ids));
            $wpdb->query("DELETE FROM {$addons_table} WHERE optionId IN ($ids)");
            $wpdb->query("DELETE FROM {$options_table} WHERE id IN ($ids)");
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$features_table} WHERE id = %d", $fid));
    }
    
    // For each processed feature, delete options not in the processed list.
    foreach ($processed_options as $featureId => $optionIds) {
        $all_opts = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$options_table} WHERE featureId = %d", $featureId));
        $to_delete_opts = array_diff($all_opts, $optionIds);
        if (!empty($to_delete_opts)) {
            $ids = implode(',', array_map('intval', $to_delete_opts));
            $wpdb->query("DELETE FROM {$addons_table} WHERE optionId IN ($ids)");
            $wpdb->query("DELETE FROM {$options_table} WHERE id IN ($ids)");
        }
    }
    
    // For each processed option, delete addons not in the processed list.
    foreach ($processed_addons as $optionId => $addonIds) {
        $all_addons = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$addons_table} WHERE optionId = %d", $optionId));
        $to_delete_addons = array_diff($all_addons, $addonIds);
        if (!empty($to_delete_addons)) {
            $ids = implode(',', array_map('intval', $to_delete_addons));
            $wpdb->query("DELETE FROM {$addons_table} WHERE id IN ($ids)");
        }
    }
}

/**
 * Drop all pricing tables.
 */
function drop_oscpc_tables() {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffc_addons");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffc_options");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffc_features");
}

/**
 * Modified save pricing function.
 */
function firefly_collective_save_pricing($request) {
    $data = $request->get_json_params();
    $plugin_root_path = dirname(plugin_dir_path(__FILE__));
    $pricing_json_path = $plugin_root_path . '/pricing.json';
    // Save only the pricingData portion to disk.
    $result = file_put_contents($pricing_json_path, json_encode($data['pricingData'], JSON_PRETTY_PRINT));
    if ($result === false) {
        return new WP_Error('save_failed', 'Failed to save pricing data', array('status' => 500));
    }
    // Pass the entire payload (including nameChanges) to upsert.
    upsert_pricing_data($data);
    return array('success' => true, 'message' => 'Pricing data saved and database updated successfully.');
}
?>
