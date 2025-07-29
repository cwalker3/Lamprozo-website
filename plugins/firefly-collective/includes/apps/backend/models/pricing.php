<?php

    // plugin/models/pricing.php

    function enqueue_pricing_styles_and_scripts($hook) {
        if ($hook !== 'toplevel_page_pricing') {
            return;
        }

        $plugin_root_url = dirname(plugin_dir_url(__FILE__)) . '/';
        $unique_id       = uniqid();
        $hookName = '';
        $nonce   = wp_create_nonce('wp_rest');
        $api_url = get_rest_url(null, 'custom-api/v1/');
        $theme_path = get_template_directory_uri();
        
        switch ($hook) {

            // Pricing admin
            case "toplevel_page_pricing":

                // Enqueue CSS & JS
                wp_enqueue_style('pricing-css', $plugin_root_url . 'assets/css/pricing.css', array(), $unique_id);
                wp_enqueue_script('pricing-js', $plugin_root_url . 'assets/js/pricing.js', array(), $unique_id, true);

                // Load pricing.json
                $plugin_root_path  = dirname(plugin_dir_path(__FILE__));
                $pricing_json_path = $plugin_root_path . '/pricing.json';
                $pricing_data      = array();
                if (file_exists($pricing_json_path)) {
                    $content = file_get_contents($pricing_json_path);
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $pricing_data = $decoded;
                    }
                }

                // Localize into JS
                $nonce   = wp_create_nonce('wp_rest');
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
                break;

        }
    }
    add_action('admin_enqueue_scripts', 'enqueue_pricing_styles_and_scripts');

    function firefly_collective_pricing_dashboard() {
        $plugin_root = dirname(plugin_dir_path(__FILE__));
        $view_path   = $plugin_root . '/views/pricing.php';
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
     * Filter out _stored* temporary fields from data structure
     */
    function filter_stored_fields($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $result = array();
        foreach ($data as $key => $value) {
            // Skip any fields starting with _stored
            if (strpos($key, '_stored') === 0) {
                continue;
            }
            
            // Recursively filter arrays
            if (is_array($value)) {
                $result[$key] = filter_stored_fields($value);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    } 

    /**
     * Recursively unwrap all { level, ui_type, value } wrappers
     */
    function unwrap_pricing_json($data) {
        if (! is_array($data)) {
            return $data;
        }

        // Our built-in keys (never suffix these)
        $builtins = array(
            // feature-level
            'featureName','description','recurring',
            'normalText','longText','intFloat','dateField','multiple','link_name',
            // option-level
            'optionName','description','interval','pricingType',
            'staticPrice','priceFloor','priceCeiling','optionMetric','priceOptions','thresholdDiscounts','link_name',
            // addon-level
            'addonName','description','addOnMetric','pricingType','priceModifierType',
            'staticPriceMod','floorPriceMod','ceilingPriceMod','groupName','groupThresholdDiscounts','link_name'
        );

        $out = array();
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['level'], $value['ui_type'], $value['value'])) {
                // Add suffix based on level - handle admin, user, and now user-display levels
                $suffix = in_array($key, $builtins, true)
                    ? ''
                    : ($value['level'] === 'admin' ? '_admin' : 
                    ($value['level'] === 'user' ? '_user' : 
                        ($value['level'] === 'user-display' ? '_display' : ''))); // Added user-display handling
                
                $newKey = $key . $suffix;
                
                if ($value['level'] === 'user' || $value['level'] === 'user-display') {
                    // Special handling for array type (dropdown) fields
                    if ($value['ui_type'] === 'array' && isset($value['value']['types'])) {
                        // For array/dropdown fields, put types at the top level for easier access
                        $jsonValue = array(
                            'ui_type' => 'array',
                            'types' => $value['value']['types'],
                            'selected' => $value['value']['selected'] ?? 0, // Add this line to preserve selection
                            'is_display' => ($value['level'] === 'user-display')
                        );
                        $out[$newKey] = json_encode($jsonValue);
                    } else {
                        // For all other field types, use the standard format
                        $jsonValue = array(
                            'ui_type' => $value['ui_type'],
                            'value' => unwrap_pricing_json($value['value']),
                            'is_display' => ($value['level'] === 'user-display') // Add flag to identify display fields
                        );
                        
                        // Add placeholder if provided
                        if (isset($value['placeholder'])) {
                            $jsonValue['placeholder'] = $value['placeholder'];
                        }
                        
                        $out[$newKey] = json_encode($jsonValue);
                    }
                } else {
                    // For admin fields, continue with normal unwrapping
                    $out[$newKey] = unwrap_pricing_json($value['value']);
                }
            } else {
                $out[$key] = unwrap_pricing_json($value);
            }
        }

        // Special handling for addons with enableGrouping=false
        if (isset($out['enableGrouping']) && $out['enableGrouping'] === false) {
            // Just clear these fields without storing them in _stored fields
            if (isset($out['groupName']) && !empty($out['groupName'])) {
                // Don't create _storedGroupName
                $out['groupName'] = '';
            }
            if (isset($out['groupThresholdDiscounts']) && !empty($out['groupThresholdDiscounts'])) {
                // Don't create _storedGroupThresholdDiscounts
                $out['groupThresholdDiscounts'] = '{"types":[{"itemCount":"","discount":""}]}';
            }
            if (isset($out['maxGroupItems']) && $out['maxGroupItems'] !== -1) {
                // Don't create _storedMaxGroupItems
                $out['maxGroupItems'] = -1;
            }
        }
        
        // Remove any _stored fields that might still exist
        foreach (array_keys($out) as $key) {
            if (strpos($key, '_stored') === 0) {
                unset($out[$key]);
            }
        }
        
        return $out;
    }

    /**
     * Determine SQL type from a sample value.
     */
    function get_sql_type($key, $value, $default_map = array()) {
        if (isset($default_map[$key])) {
            return $default_map[$key];
        }
        if (is_bool($value))    return "TINYINT(1)";
        if (is_int($value))     return "INT";
        if (is_float($value))   return "FLOAT";
        if (is_string($value))  return strlen($value) <= 255 ? "VARCHAR(255)" : "TEXT";
        return "TEXT";
    }

    /**
     * Default columns for features.
     */
    function get_default_feature_columns() {
        return array(
            'featureName' => "VARCHAR(255) NOT NULL",
            'description' => "TEXT",
            'recurring'   => "TINYINT(1)",
            'link_name'   => "VARCHAR(255) NULL"
        );
    }

    /**
     * Default columns for options (no recurring here; pricingType built-in).
     */
    function get_default_option_columns() {
        return array(
            'optionName'   => "VARCHAR(255) NOT NULL",
            'description'  => "TEXT",
            'interval'     => "VARCHAR(50)",
            'pricingType'  => "VARCHAR(50)",
            'staticPrice'  => "FLOAT",
            'priceFloor'   => "FLOAT",
            'priceCeiling' => "FLOAT",
            'optionMetric' => "VARCHAR(50)",
            'priceOptions' => "TEXT",
            'thresholdDiscounts' => "TEXT",
            'maxAddons'    => "INT DEFAULT -1",
            'link_name'    => "VARCHAR(255) NULL"
        );
    }

    /**
     * Default columns for addons (pricingType & priceModifierType built-in).
     */
    function get_default_addon_columns() {
        return array(
            'addonName'               => "VARCHAR(255) NOT NULL",
            'description'             => "TEXT",
            'addOnMetric'             => "VARCHAR(50)",
            'pricingType'             => "VARCHAR(50)",
            'priceModifierType'       => "VARCHAR(50)",
            'staticPriceMod'          => "FLOAT",
            'floorPriceMod'           => "FLOAT",
            'ceilingPriceMod'         => "FLOAT",
            'groupName'               => "VARCHAR(255)",
            'groupThresholdDiscounts' => "TEXT",
            'maxGroupItems'           => "INT DEFAULT -1",
            'link_name'               => "VARCHAR(255) NULL"
        );
    }

    /**
     * Merge extra (custom) columns found in $records with $default_columns.
     */
    function merge_extra_columns($records, $default_columns) {
        $columns = $default_columns;
        foreach ($records as $record) {
            foreach ($record as $key => $val) {
                if ($key === 'options' || $key === 'addons') {
                    continue;
                }
                // Skip _stored fields when determining columns
                if (strpos($key, '_stored') === 0) {
                    continue;
                }
                if (! isset($columns[$key])) {
                    $columns[$key] = is_null($val)
                        ? "TEXT"
                        : get_sql_type($key, $val, $default_columns);
                }
            }
        }
        return $columns;
    }

    /**
     * Update a table's schema: add missing columns, drop removed ones.
     */
    function update_table_schema($table, $desired_columns) {
        global $wpdb;
        $existing = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have     = array_column($existing, 'Type', 'Field');

        // Add any missing
        foreach ($desired_columns as $col => $def) {
            if (! isset($have[$col])) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN `{$col}` {$def}");
            }
        }
        // Drop any extras (except id, featureId, optionId)
        foreach ($have as $col => $type) {
            if (in_array($col, array('id','featureId','optionId'), true)) {
                continue;
            }
            if (! isset($desired_columns[$col])) {
                $wpdb->query("ALTER TABLE {$table} DROP COLUMN `{$col}`");
            }
        }
    }

    /**
     * Create the three pricing tables if they don't exist.
     */
    function create_pricing_tables_if_not_exist() {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();

        // features
        $fdefs = get_default_feature_columns();
        $fcols = "";
        foreach ($fdefs as $c => $d) {
            $fcols .= "`{$c}` {$d}, ";
        }
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_features (
            id INT NOT NULL AUTO_INCREMENT,
            {$fcols}
            PRIMARY KEY(id),
            UNIQUE KEY(featureName)
        ) {$collate};";
        require_once(ABSPATH.'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // options
        $odefs = get_default_option_columns();
        $ocols = "";
        foreach ($odefs as $c => $d) {
            $ocols .= "`{$c}` {$d}, ";
        }
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_options (
            id INT NOT NULL AUTO_INCREMENT,
            featureId INT NOT NULL,
            {$ocols}
            PRIMARY KEY(id),
            KEY(featureId)
        ) {$collate};";
        dbDelta($sql);

        // addons
        $adefs = get_default_addon_columns();
        $acols = "";
        foreach ($adefs as $c => $d) {
            $acols .= "`{$c}` {$d}, ";
        }
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ffc_addons (
            id INT NOT NULL AUTO_INCREMENT,
            optionId INT NOT NULL,
            {$acols}
            PRIMARY KEY(id),
            KEY(optionId)
        ) {$collate};";
        dbDelta($sql);
    }

    /**
     * Normalize {types,selected} or {text} objects to scalars.
     */
    function normalize_record($record) {
        // First determine if this is an option record with pricing type
        $has_pricing_type = isset($record['pricingType']);
        $pricing_type_value = null;
        
        // Process all fields normally first
        foreach ($record as $k => $v) {
            if (is_array($v)) {
                // Special handling for priceOptions, thresholdDiscounts, groupThresholdDiscounts
                // and any user-level fields with array structure
                if ($k === 'priceOptions' || $k === 'thresholdDiscounts' || 
                    $k === 'groupThresholdDiscounts' || 
                    (isset($v['level']) && $v['level'] === 'user' && isset($v['value']))) {
                    continue; // Skip processing for now, handle it separately
                }
                
                if (isset($v['types'], $v['selected'])) {
                    // Extract the pricing type value for later use
                    if ($k === 'pricingType' && $has_pricing_type) {
                        $pricing_type_value = $v['types'][$v['selected']];
                    }
                    $record[$k] = $v['types'][$v['selected']];
                } elseif (isset($v['text'])) {
                    $record[$k] = $v['text'];
                }
            }
        }
        
        // Now handle the priceOptions field based on pricingType
        if ($has_pricing_type) {
            // Use the extracted pricing type value or the resolved one
            $pricing_type = $pricing_type_value ?? $record['pricingType'] ?? null;
            
            // ONLY keep priceOptions if the pricing type is explicitly "price options"
            if ($pricing_type !== 'price options' && isset($record['priceOptions'])) {
                unset($record['priceOptions']);
            }
        }
        
        // Process user fields to ensure proper JSON encoding
        foreach ($record as $k => $v) {
            // Check for _display suffix directly
            if (substr($k, -8) === '_display') {
                // Similar handling as _user fields
                if (is_array($v) && isset($v['level']) && $v['level'] === 'user-display' && $v['ui_type'] === 'array') {
                    // Array type handling for user-display
                    $jsonData = array(
                        'ui_type' => 'array',
                        'types' => $v['value']['types'],
                        'selected' => $v['value']['selected'] ?? 0, // Include selected index
                        'is_display' => true
                    );
                    $record[$k] = json_encode($jsonData);
                }
                // For other user-display field types
                else if (is_array($v) && isset($v['level']) && $v['level'] === 'user-display') {
                    $jsonData = array(
                        'ui_type' => $v['ui_type'],
                        'value' => $v['value'] ?? '',
                        'is_display' => true
                    );
                    $record[$k] = json_encode($jsonData);
                }
                // If it's already a string that looks like JSON, process it to preserve selected
                else if (is_string($v) && json_decode($v) !== null) {
                    $jsonData = json_decode($v, true);
                    
                    // If this is an array type without selected index, try to find it
                    if (isset($jsonData['ui_type']) && $jsonData['ui_type'] === 'array' && 
                        isset($jsonData['types']) && !isset($jsonData['selected'])) {
                        
                        // Look for admin field with the same name for selection
                        $adminKey = str_replace('_display', '_admin', $k);
                        if (isset($record[$adminKey]) && is_array($record[$adminKey]) && 
                            isset($record[$adminKey]['value']['selected'])) {
                            
                            $jsonData['selected'] = $record[$adminKey]['value']['selected'];
                            $record[$k] = json_encode($jsonData);
                        }
                    }
                } 
                // Otherwise, encode other value types
                else {
                    $ui_type = 'normal-text';
                    if (is_numeric($v)) {
                        $ui_type = 'int-float';
                    }
                    
                    $jsonData = array(
                        'ui_type' => $ui_type,
                        'value' => $v,
                        'is_display' => true
                    );
                    
                    $record[$k] = json_encode($jsonData);
                }
            }
        }
        
        // Check multiple possible representations of false values coming from JavaScript
        $threshold_disabled = false;
        if (isset($record['enableThresholdDiscounts'])) {
            // Handle various false-like values that might come from JS
            if ($record['enableThresholdDiscounts'] === false ||
                $record['enableThresholdDiscounts'] === 'false' ||
                $record['enableThresholdDiscounts'] === 0 ||
                $record['enableThresholdDiscounts'] === '0' ||
                $record['enableThresholdDiscounts'] === '' ||
                $record['enableThresholdDiscounts'] === null) {
                $threshold_disabled = true;
            }
        }
        
        // If explicitly disabled or not present at all in the data, set thresholdDiscounts to null
        if ($threshold_disabled) {
            $record['thresholdDiscounts'] = null;
        } else if (isset($record['thresholdDiscounts'])) {
            if (is_array($record['thresholdDiscounts'])) {
                $types_array = null;
                
                // If it has 'types' property, extract just the types array
                if (isset($record['thresholdDiscounts']['types']) && is_array($record['thresholdDiscounts']['types'])) {
                    $types_array = $record['thresholdDiscounts']['types'];
                } elseif (isset($record['thresholdDiscounts']['value']['types']) && is_array($record['thresholdDiscounts']['value']['types'])) {
                    // Handle nested value structure
                    $types_array = $record['thresholdDiscounts']['value']['types'];
                } else {
                    // Otherwise use the array directly
                    $types_array = $record['thresholdDiscounts'];
                }
                
                // If the array is empty or has no valid entries, set to null
                if (empty($types_array) || !array_filter($types_array, function($item) {
                    return !empty($item['itemCount']) || !empty($item['discount']);
                })) {
                    $record['thresholdDiscounts'] = null;
                } else {
                    $record['thresholdDiscounts'] = json_encode($types_array);
                }
            } elseif (is_string($record['thresholdDiscounts'])) {
                // Already a JSON string, check if it's valid and not empty
                $decoded = json_decode($record['thresholdDiscounts'], true);
                if (!is_array($decoded) || empty($decoded)) {
                    $record['thresholdDiscounts'] = null;
                }
            } else {
                // Unexpected type
                $record['thresholdDiscounts'] = null;
            }
        }
        
        // *** NEW CODE: Handle disabled group data properly ***
        $group_disabled = false;
        if (isset($record['enableGrouping'])) {
            // Handle various false-like values that might come from JS
            if ($record['enableGrouping'] === false ||
                $record['enableGrouping'] === 'false' ||
                $record['enableGrouping'] === 0 ||
                $record['enableGrouping'] === '0' ||
                $record['enableGrouping'] === '' ||
                $record['enableGrouping'] === null) {
                $group_disabled = true;
            }
        }
        
        // If grouping is explicitly disabled, clear group-related fields WITHOUT storing them
        if ($group_disabled) {
            // Simply clear the values without storing them
            if (isset($record['groupName']) && !empty($record['groupName'])) {
                $record['groupName'] = '';
            }
            
            if (isset($record['groupThresholdDiscounts']) && !empty($record['groupThresholdDiscounts'])) {
                $record['groupThresholdDiscounts'] = null;
            }
            
            if (isset($record['maxGroupItems']) && $record['maxGroupItems'] !== -1) {
                $record['maxGroupItems'] = -1;
            }
        }
        
        // Remove any _stored fields that might exist
        foreach (array_keys($record) as $key) {
            if (strpos($key, '_stored') === 0) {
                unset($record[$key]);
            }
        }
        
        // Remove enableThresholdDiscounts field entirely
        if (isset($record['enableThresholdDiscounts'])) {
            unset($record['enableThresholdDiscounts']);
        }
        
        return $record;
    }

    /**
     * Update DB schema based on the current JSON data.
     */
    function update_pricing_schema($data) {
        global $wpdb;
        create_pricing_tables_if_not_exist();

        // features
        $fdes = merge_extra_columns($data['features'], get_default_feature_columns());
        update_table_schema($wpdb->prefix.'ffc_features', $fdes);

        // options
        $opts = [];
        foreach ($data['features'] as $f) {
            foreach ($f['options'] as $o) {
                $opts[] = $o;
            }
        }
        $odes = merge_extra_columns($opts, get_default_option_columns());
        update_table_schema($wpdb->prefix.'ffc_options', $odes);

        // addons
        $adds = [];
        foreach ($data['features'] as $f) {
            foreach ($f['options'] as $o) {
                foreach ($o['addons'] as $a) {
                    $adds[] = $a;
                }
            }
        }
        $ades = merge_extra_columns($adds, get_default_addon_columns());
        update_table_schema($wpdb->prefix.'ffc_addons', $ades);
    }

    /**
     * Upsert pricing data, preserving IDs when names change.
     */
    function upsert_pricing_data($payload) {
        global $wpdb;
        create_pricing_tables_if_not_exist();

        // 1) unwrap & rebuild schema
        $pricingData = unwrap_pricing_json($payload['pricingData']);
        update_pricing_schema($pricingData);

        // 2) nameChanges maps
        $nameChanges = $payload['nameChanges'] ?? ['features'=>[],'options'=>[],'addons'=>[]];
        $ft = $wpdb->prefix.'ffc_features';
        $ot = $wpdb->prefix.'ffc_options';
        $at = $wpdb->prefix.'ffc_addons';

        $processed_features = [];
        $processed_options  = [];
        $processed_addons   = [];
        
        // Define allowed columns for each table to prevent field mismatches
        $feature_fields = array_keys(get_default_feature_columns());
        $option_fields = array_keys(get_default_option_columns());
        $addon_fields = array_keys(get_default_addon_columns());
        
        // Add the ID field to allowed fields
        $option_fields[] = 'featureId';
        $addon_fields[] = 'optionId';
        $addon_fields[] = 'enableGrouping'; // This is a special field for addons

        // --- FEATURES ---
        foreach ($pricingData['features'] as $fi => $feat) {
            $final = $feat['featureName'];
            $old   = $final;
            if (isset($nameChanges['features'][$fi]['oldName'], $nameChanges['features'][$fi]['newName'])) {
                $old   = $nameChanges['features'][$fi]['oldName'];
                $final = $nameChanges['features'][$fi]['newName'];
            }

            // try find by old, then by new
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$ft} WHERE featureName = %s", $old
            ), ARRAY_A);
            if (! $row) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$ft} WHERE featureName = %s", $final
                ), ARRAY_A);
            }

            $save = $feat;
            unset($save['options']);
            $save['featureName'] = $final;
            
            // Convert boolean recurring value to integer
            if (isset($save['recurring'])) {
                $save['recurring'] = (int)$save['recurring'];
            }
            
            $save = normalize_record($save);
            
            // Filter fields to only include those defined for features
            $filtered_save = array();
            foreach ($save as $key => $value) {
                // Skip any _stored fields
                if (strpos($key, '_stored') === 0) {
                    continue;
                }
                
                // Include standard fields, user fields, and display fields
                if (in_array($key, $feature_fields) || substr($key, -5) === '_user' || substr($key, -8) === '_display') {
                    $filtered_save[$key] = $value;
                }
            }

            if ($row) {
                $fid = $row['id'];
                $wpdb->update($ft, $filtered_save, ['id'=>$fid]);
            } else {
                $wpdb->insert($ft, $filtered_save);
                $fid = $wpdb->insert_id;
            }
            
            $processed_features[] = $fid;
            $nameChanges['features'][$fi] = [];

            // --- OPTIONS ---
            foreach ($feat['options'] as $oi => $opt) {
                $fO = $opt['optionName'];
                $oO = $fO;
                if (isset($nameChanges['options'][$fi][$oi]['oldName'], $nameChanges['options'][$fi][$oi]['newName'])) {
                    $oO = $nameChanges['options'][$fi][$oi]['oldName'];
                    $fO = $nameChanges['options'][$fi][$oi]['newName'];
                }

                $rowO = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$ot} WHERE featureId = %d AND optionName = %s",
                    $fid, $oO
                ), ARRAY_A);
                if (! $rowO) {
                    $rowO = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM {$ot} WHERE featureId = %d AND optionName = %s",
                        $fid, $fO
                    ), ARRAY_A);
                }

                $saveO = $opt;
                unset($saveO['addons']);
                $saveO['optionName']  = $fO;
                $saveO['featureId']   = $fid;

                // Normalize the record
                $saveO = normalize_record($saveO);
                
                // Filter fields to only include those defined for options
                $filtered_saveO = array();
                foreach ($saveO as $key => $value) {
                    // Skip any _stored fields
                    if (strpos($key, '_stored') === 0) {
                        continue;
                    }
                    
                    // Include standard fields, user fields, and display fields
                    if (in_array($key, $option_fields) || substr($key, -5) === '_user' || substr($key, -8) === '_display') {
                        $filtered_saveO[$key] = $value;
                    }
                }

                // Handle JSON fields specifically to ensure they're strings
                if (isset($filtered_saveO['priceOptions']) && is_array($filtered_saveO['priceOptions'])) {
                    $filtered_saveO['priceOptions'] = json_encode($filtered_saveO['priceOptions']);
                }
                
                if (isset($filtered_saveO['thresholdDiscounts']) && is_array($filtered_saveO['thresholdDiscounts'])) {
                    $filtered_saveO['thresholdDiscounts'] = json_encode($filtered_saveO['thresholdDiscounts']);
                }

                if ($rowO) {
                    $oid = $rowO['id'];
                    $wpdb->update($ot, $filtered_saveO, ['id'=>$oid]);
                } else {
                    $wpdb->insert($ot, $filtered_saveO);
                    $oid = $wpdb->insert_id;
                }
                $processed_options[$fid][] = $oid;
                $nameChanges['options'][$fi][$oi] = [];

                // --- ADDONS ---
                foreach ($opt['addons'] as $ai => $add) {
                    $fA = $add['addonName'] ?? '';
                    $oA = $fA;
                    if (isset($nameChanges['addons'][$fi][$oi][$ai]['oldName'], $nameChanges['addons'][$fi][$oi][$ai]['newName'])) {
                        $oA = $nameChanges['addons'][$fi][$oi][$ai]['oldName'];
                        $fA = $nameChanges['addons'][$fi][$oi][$ai]['newName'];
                    }

                    $rowA = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM {$at} WHERE optionId = %d AND addonName = %s",
                        $oid, $oA
                    ), ARRAY_A);
                    if (! $rowA) {
                        $rowA = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM {$at} WHERE optionId = %d AND addonName = %s",
                            $oid, $fA
                        ), ARRAY_A);
                    }

                    $saveA = $add;
                    $saveA['addonName'] = $fA;
                    $saveA['optionId']  = $oid;
                    
                    // Convert boolean values to integers
                    if (isset($saveA['enableGrouping'])) {
                        $saveA['enableGrouping'] = (int)$saveA['enableGrouping'];
                    }
                    
                    // Normalize record (will convert data types and handle JSON)
                    $saveA = normalize_record($saveA);
                    
                    // Filter fields to only include those defined for addons
                    $filtered_saveA = array();
                    foreach ($saveA as $key => $value) {
                        // Skip any _stored fields
                        if (strpos($key, '_stored') === 0) {
                            continue;
                        }
                        
                        // Include standard fields, user fields, and display fields
                        if (in_array($key, $addon_fields) || substr($key, -5) === '_user' || substr($key, -8) === '_display') {
                            $filtered_saveA[$key] = $value;
                        }
                    }
                    
                    // Handle JSON fields specifically
                    if (isset($filtered_saveA['groupThresholdDiscounts']) && is_array($filtered_saveA['groupThresholdDiscounts'])) {
                        $filtered_saveA['groupThresholdDiscounts'] = json_encode($filtered_saveA['groupThresholdDiscounts']);
                    }

                    if ($rowA) {
                        $aid = $rowA['id'];
                        $wpdb->update($at, $filtered_saveA, ['id'=>$aid]);
                    } else {
                        $wpdb->insert($at, $filtered_saveA);
                        $aid = $wpdb->insert_id;
                    }
                    $processed_addons[$oid][] = $aid;
                    $nameChanges['addons'][$fi][$oi][$ai] = [];
                }
            }
        }

        // --- CLEANUP DELETIONS ---
        // features
        $allF = $wpdb->get_col("SELECT id FROM {$ft}");
        $delF = array_diff($allF, $processed_features);
        foreach ($delF as $fid) {
            $optIDs = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$ot} WHERE featureId = %d", $fid));
            if ($optIDs) {
                $in = implode(',', array_map('intval',$optIDs));
                $wpdb->query("DELETE FROM {$at} WHERE optionId IN ($in)");
                $wpdb->query("DELETE FROM {$ot} WHERE id IN ($in)");
            }
            $wpdb->query($wpdb->prepare("DELETE FROM {$ft} WHERE id = %d", $fid));
        }
        // options
        foreach ($processed_options as $fid => $oIDs) {
            $allO = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$ot} WHERE featureId = %d", $fid));
            $delO = array_diff($allO, $oIDs);
            if ($delO) {
                $in = implode(',', array_map('intval',$delO));
                $wpdb->query("DELETE FROM {$at} WHERE optionId IN ($in)");
                $wpdb->query("DELETE FROM {$ot} WHERE id IN ($in)");
            }
        }
        // addons
        foreach ($processed_addons as $oid => $aIDs) {
            $allA = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$at} WHERE optionId = %d", $oid));
            $delA = array_diff($allA, $aIDs);
            if ($delA) {
                $in = implode(',', array_map('intval',$delA));
                $wpdb->query("DELETE FROM {$at} WHERE id IN ($in)");
            }
        }
    }

    /**
     * Fetch nested features → options → addons,
     * stripping out any _admin columns for non-admin users.
     */
    function get_features_options_addons() {
        global $wpdb;

        $f_table = $wpdb->prefix . 'ffc_features';
        $o_table = $wpdb->prefix . 'ffc_options';
        $a_table = $wpdb->prefix . 'ffc_addons';

        $get_cols = function($t,$exc=[]) use($wpdb) {
            $r = [];
            foreach ($wpdb->get_results("SHOW COLUMNS FROM `$t`", ARRAY_A) as $c) {
                if (!in_array($c['Field'],$exc)) {
                    $r[$c['Field']] = $c['Type'];
                }
            }
            return $r;
        };

        $f_cols = $get_cols($f_table,['id']);
        $o_cols = $get_cols($o_table,['id','featureId']);
        $a_cols = $get_cols($a_table,['id','optionId']);

        $select = [];
        foreach ($f_cols as $c=>$t) $select[]="f.`$c` AS f_$c";
        $select[]="f.id AS f_id";
        foreach ($o_cols as $c=>$t) $select[]="o.`$c` AS o_$c";
        $select[]="o.id AS o_id";
        foreach ($a_cols as $c=>$t) $select[]="a.`$c` AS a_$c";
        $select[]="a.id AS a_id";

        $sql = "SELECT ".implode(',',$select)."
                FROM `$f_table` f
                LEFT JOIN `$o_table` o ON o.featureId=f.id
                LEFT JOIN `$a_table` a ON a.optionId=o.id
                ORDER BY f.id,o.id,a.id";

        $rows = $wpdb->get_results($sql, ARRAY_A);

        $cast = function($v,$t){
            if ($v===null) return null;
            if (stripos($t,'tinyint(1)')!==false) return (bool)$v;
            if (stripos($t,'int')!==false)         return (int)$v;
            if (stripos($t,'float')!==false||
                stripos($t,'double')!==false)       return (float)$v;
            return $v;
        };

        $out = [];
        foreach ($rows as $r) {
            $fid = $r['f_id'];
            if (!isset($out[$fid])) {
                $feat = [];
                foreach ($f_cols as $c=>$t) $feat[$c] = $cast($r["f_$c"],$t);
                $feat['id'] = $fid;
                $feat['options'] = [];
                $out[$fid] = $feat;
            }
            if ($r['o_id']) {
                $oid = $r['o_id'];
                if (!isset($out[$fid]['options'][$oid])) {
                    $opt = [];
                    foreach ($o_cols as $c=>$t) $opt[$c] = $cast($r["o_$c"],$t);
                    $opt['id'] = $oid;
                    $opt['addons'] = [];
                    $out[$fid]['options'][$oid] = $opt;
                }
                if ($r['a_id']) {
                    $add = [];
                    foreach ($a_cols as $c=>$t) $add[$c] = $cast($r["a_$c"],$t);
                    $add['id'] = $r['a_id'];
                    $out[$fid]['options'][$oid]['addons'][] = $add;
                }
            }
        }

        // Reindex arrays
        $features = array_values($out);
        foreach ($features as &$f) {
            if (!empty($f['options'])) {
                $f['options'] = array_values($f['options']);
            }
            foreach ($f['options'] as &$opt) {
                if (!empty($opt['addons'])) {
                    $opt['addons'] = array_values($opt['addons']);
                }
            }
        }

        // strip _admin for non-admins
        if (! current_user_can('manage_options')) {
            foreach ($features as &$f) {
                foreach (array_keys($f) as $col) {
                    if (substr($col,-6)==='_admin') unset($f[$col]);
                }
                foreach ($f['options'] as &$opt) {
                    foreach (array_keys($opt) as $col) {
                        if (substr($col,-6)==='_admin') unset($opt[$col]);
                    }
                    foreach ($opt['addons'] as &$a) {
                        foreach (array_keys($a) as $col) {
                            if (substr($col,-6)==='_admin') unset($a[$col]);
                        }
                    }
                }
            }
        }

        return $features;
    }

    /**
     * Initialize pricing on plugin activation
     * Creates tables and syncs JSON with database
     */
    function firefly_collective_pricing_init() {
        // Always create the orders table
        create_ffc_orders_table_if_not_exist();
        
        // Check if pricing.json exists and has data
        $plugin_root_path = dirname(plugin_dir_path(__FILE__));
        $pricing_json_path = $plugin_root_path . '/pricing.json';
        
        if (file_exists($pricing_json_path)) {
            $content = file_get_contents($pricing_json_path);
            $pricing_data = json_decode($content, true);
            
            // Only proceed if we have valid JSON with features
            if (is_array($pricing_data) && !empty($pricing_data['features'])) {
                // Create the other tables
                create_pricing_tables_if_not_exist();
                
                // Format the data to match what the upsert function expects
                $payload = array(
                    'pricingData' => $pricing_data,
                    'nameChanges' => array('features' => array(), 'options' => array(), 'addons' => array())
                );
                
                // Sync the JSON with the database
                upsert_pricing_data($payload);
            }
        }
    }

    /**
     * Calculate order price using the same logic as the frontend
     */
    function calculate_server_price($feature_id, $option_id, $addon_ids, $price_option_index, $quantity) {
        global $wpdb;

        // 1) Fetch feature & option
        $feature = $wpdb->get_row(
            $wpdb->prepare("SELECT featureName FROM {$wpdb->prefix}ffc_features WHERE id = %d", $feature_id),
            ARRAY_A
        );
        $option = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}ffc_options WHERE id = %d", $option_id),
            ARRAY_A
        );
        if (!$feature || !$option) {
            return [
                'totalPrice' => 0,
                'totalPriceDiscount' => 0,
                'priceDiscountsInfo' => ['option'=>'','addons'=>[]],
                'feature' => $feature['featureName'] ?? ''
            ];
        }

        // 2) Base price (staticPrice + priceOptions) × qty
        $basePrice = parse_safe($option['staticPrice'], 0);
        $priceOptions = [];
        if (!empty($option['priceOptions'])) {
            $decoded = is_string($option['priceOptions'])
                ? json_decode($option['priceOptions'], true)
                : $option['priceOptions'];
            if (!empty($decoded['types'])) {
                $priceOptions = $decoded['types'];
            }
        }
        if (isset($priceOptions[$price_option_index]['price'])) {
            $basePrice = parse_safe($priceOptions[$price_option_index]['price'], $basePrice);
        }
        $qty       = max(1, (int)$quantity);
        $baseTotal = $basePrice * $qty;

        // 3) Apply the best option-level threshold discount
        $optionThresholds = parse_threshold_discounts($option['thresholdDiscounts']);
        usort($optionThresholds, fn($a,$b)=> $b['itemCount'] - $a['itemCount']);
        $thresholdDiscountAmt = 0;
        $optionNote = '';
        foreach ($optionThresholds as $th) {
            if ($qty >= $th['itemCount']) {
                $thresholdDiscountAmt = round($baseTotal * ($th['discount']/100), 2);
                $optionNote = "{$th['discount']}% discount applied for {$qty}+ items";
                break;
            }
        }
        $baseAfterThreshold = $baseTotal - $thresholdDiscountAmt;

        // 4) Fetch selected addons and build grouping
        $selectedAddons = [];
        if (!empty($addon_ids)) {
            $placeholders = implode(',', array_fill(0, count($addon_ids), '%d'));
            $params       = array_merge([$option_id], $addon_ids);
            $sql = "
            SELECT * FROM {$wpdb->prefix}ffc_addons 
            WHERE optionId = %d AND id IN ($placeholders)
            ";
            $selectedAddons = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        }

        // 5) Compute raw addon total and collect groups
        $rawAddonTotal    = 0;
        $groups           = [];
        foreach ($selectedAddons as $addon) {
            $unit = parse_safe($addon['staticPriceMod'], 0);
            $rawAddonTotal += $unit * $qty;

            if (!empty($addon['groupName']) && !empty($addon['enableGrouping'])) {
                $groups[$addon['groupName']]['addons'][] = $addon;
            }
        }

        // 6) Apply each group’s best discount
        $groupDiscountTotal = 0;
        $addonNotes         = [];
        foreach ($groups as $groupName => $grp) {
            // every addon in the group carries the same thresholds, so take the first
            $thresholds = parse_threshold_discounts($grp['addons'][0]['groupThresholdDiscounts'] ?? null);
            usort($thresholds, fn($a,$b)=> $b['itemCount'] - $a['itemCount']);
            $applicable = null;
            foreach ($thresholds as $th) {
                if (count($grp['addons']) >= $th['itemCount']) {
                    $applicable = $th;
                    break;
                }
            }
            if ($applicable) {
                $sumUnit = 0;
                foreach ($grp['addons'] as $addon) {
                    $sumUnit += parse_safe($addon['staticPriceMod'], 0);
                }
                $groupTotal    = $sumUnit * $qty;
                $discountAmt   = round($groupTotal * ($applicable['discount']/100), 2);
                $groupDiscountTotal += $discountAmt;
                $addonNotes[] = "Group Discount: {$groupName} ({$applicable['discount']}% off for " 
                                . count($grp['addons']) . " items)";
            }
        }

        // 7) Final assembly
        $totalPrice        = round($baseAfterThreshold + ($rawAddonTotal - $groupDiscountTotal), 2);
        $totalDiscount     = round($thresholdDiscountAmt + $groupDiscountTotal, 2);
        $priceDiscountsInfo = [
            'option' => $optionNote,
            'addons' => $addonNotes
        ];

        return [
            'totalPrice'         => $totalPrice,
            'totalPriceDiscount' => $totalDiscount,
            'priceDiscountsInfo' => $priceDiscountsInfo,
            'feature'            => $feature['featureName']
        ];
    }


    /**
     * Parse numeric values safely
     */
    function parse_safe($val, $fallback = 0) {
        $parsed = is_numeric($val) ? (float)$val : $fallback;
        return !is_finite($parsed) ? $fallback : $parsed;
    }

    /**
     * Check if an addon uses multiplication
     */
    function is_multiply($addon) {
        if (isset($addon['priceModifierType']) && is_array($addon['priceModifierType'])) {
            return isset($addon['priceModifierType']['selected']) && $addon['priceModifierType']['selected'] == 1;
        }
        if (isset($addon['priceModifierType']) && is_string($addon['priceModifierType'])) {
            return strtolower($addon['priceModifierType']) === 'multiply';
        }
        return false;
    }

    /**
     * Parse threshold discounts from JSON string
     */
    function parse_threshold_discounts($discounts_data) {
        if (empty($discounts_data)) {
            return [];
        }
        
        try {
            $thresholds = [];
            
            if (is_string($discounts_data)) {
                $parsed = json_decode($discounts_data, true);
                if (isset($parsed['types'])) {
                    $thresholds = $parsed['types'];
                } else {
                    $thresholds = $parsed;
                }
            } elseif (isset($discounts_data['types'])) {
                $thresholds = $discounts_data['types'];
            } elseif (is_array($discounts_data)) {
                $thresholds = $discounts_data;
            }
            
            // Convert to numeric values and filter
            $result = [];
            if (is_array($thresholds)) {
                foreach ($thresholds as $threshold) {
                    if (!empty($threshold['itemCount']) && !empty($threshold['discount'])) {
                        $result[] = [
                            'itemCount' => intval($threshold['itemCount']),
                            'discount' => floatval($threshold['discount'])
                        ];
                    }
                }
            }
            
            return $result;
        } catch (Exception $e) {
            error_log("Error parsing threshold discounts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Drop all pricing tables.
     */
    function drop_ffc_pricing_tables() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffc_orders");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffc_addons");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffc_options");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffc_features");
    }

    /**
     * Save Pricing: write raw JSON + DB sync.
     */
    function firefly_collective_save_pricing($request) {
        $data              = $request->get_json_params();
        $plugin_root_path  = dirname(plugin_dir_path(__FILE__));
        $pricing_json_path = $plugin_root_path . '/pricing.json';

        // Process the data before saving to both JSON and database
        if (isset($data['pricingData']) && isset($data['pricingData']['features']) && is_array($data['pricingData']['features'])) {
            foreach ($data['pricingData']['features'] as &$feature) {
                if (isset($feature['options']) && is_array($feature['options'])) {
                    foreach ($feature['options'] as &$option) {
                        // Clean up user fields before saving to JSON
                        foreach ($option as $key => &$value) {
                            if (is_array($value) && isset($value['level']) && $value['level'] === 'user') {
                                // Remove any original_key that was added
                                if (isset($value['original_key'])) {
                                    unset($value['original_key']);
                                }
                                
                                // For array type, remove the selected flag
                                if ($value['ui_type'] === 'array' && isset($value['value']['selected'])) {
                                    unset($value['value']['selected']);
                                }
                            }
                        }
                        
                        // Process addons if they exist
                        if (isset($option['addons'])) {
                            foreach ($option['addons'] as &$addon) {
                                foreach ($addon as $key => &$value) {
                                    if (is_array($value) && isset($value['level']) && $value['level'] === 'user') {
                                        // Remove any original_key that was added
                                        if (isset($value['original_key'])) {
                                            unset($value['original_key']);
                                        }
                                        
                                        // For array type, remove the selected flag
                                        if ($value['ui_type'] === 'array' && isset($value['value']['selected'])) {
                                            unset($value['value']['selected']);
                                        }
                                    }
                                }
                                
                                // We don't need to add a _groupDisabled flag here anymore
                                // We'll handle the enableGrouping logic directly in the upsert function
                            }
                        }
                        
                        // CRITICAL FIX: Check and properly handle enableThresholdDiscounts
                        // If enableThresholdDiscounts is false, null, 'false', 0, or '0', remove thresholdDiscounts
                        if (isset($option['enableThresholdDiscounts'])) {
                            $is_disabled = false;
                            
                            // Check for various representations of 'false'
                            if ($option['enableThresholdDiscounts'] === false || 
                                $option['enableThresholdDiscounts'] === 'false' || 
                                $option['enableThresholdDiscounts'] === 0 || 
                                $option['enableThresholdDiscounts'] === '0' ||
                                $option['enableThresholdDiscounts'] === null ||
                                $option['enableThresholdDiscounts'] === '') {
                                $is_disabled = true;
                            }
                            
                            // If disabled, completely remove thresholdDiscounts
                            if ($is_disabled && isset($option['thresholdDiscounts'])) {
                                unset($option['thresholdDiscounts']);
                            }
                            
                            // Remove enableThresholdDiscounts field after processing
                            unset($option['enableThresholdDiscounts']);
                        }
                    }
                }
            }
        }

        // Filter out _stored temporary fields before saving
        $filtered_data = filter_stored_fields($data);

        // 1) Persist FILTERED JSON (not the original data)
        file_put_contents(
            $pricing_json_path,
            json_encode($filtered_data['pricingData'], JSON_PRETTY_PRINT)
        );

        // 2) Upsert FILTERED data into MySQL (not the original data)
        upsert_pricing_data($filtered_data);

        return array('success' => true, 'message' => 'Pricing data saved.');
    }

    // Register REST endpoint
    add_action('rest_api_init', function() {
        register_rest_route('custom-api/v1','/save-pricing', array(
            'methods'  => 'POST',
            'callback' => 'firefly_collective_save_pricing',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
    });
