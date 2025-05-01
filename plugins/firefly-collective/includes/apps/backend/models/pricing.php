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
    $unique_id       = uniqid();

    // Enqueue CSS & JS
    wp_enqueue_style('pricing-css',  $plugin_root_url . 'assets/css/pricing.css', array(), $unique_id);
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
 * Recursively unwrap all { level, ui_type, value } wrappers,
 * and only give `_admin` suffix to truly custom fields.
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
        'staticPrice','priceFloor','priceCeiling','optionMetric','link_name',
        // addon-level
        'addonName','description','addOnMetric','pricingType','priceModifierType',
        'staticPriceMod','floorPriceMod','ceilingPriceMod','link_name'
    );

    $out = array();
    foreach ($data as $key => $value) {
        if (is_array($value) && isset($value['level'], $value['ui_type'], $value['value'])) {
            // only custom keys get `_admin`
            $suffix = in_array($key, $builtins, true)
                ? ''
                : ($value['level'] === 'admin' ? '_admin' : '');
            $newKey = $key . $suffix;
            $out[$newKey] = unwrap_pricing_json($value['value']);
        } else {
            $out[$key] = unwrap_pricing_json($value);
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
        'normalText'  => "VARCHAR(255)",
        'longText'    => "TEXT",
        'intFloat'    => "FLOAT",
        'dateField'   => "DATE",
        'multiple'    => "VARCHAR(255)",
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
        'link_name'    => "VARCHAR(255) NULL"
    );
}

/**
 * Default columns for addons (pricingType & priceModifierType built-in).
 */
function get_default_addon_columns() {
    return array(
        'addonName'         => "VARCHAR(255) NOT NULL",
        'description'       => "TEXT",
        'addOnMetric'       => "VARCHAR(50)",
        'pricingType'       => "VARCHAR(50)",
        'priceModifierType' => "VARCHAR(50)",
        'staticPriceMod'    => "FLOAT",
        'floorPriceMod'     => "FLOAT",
        'ceilingPriceMod'   => "FLOAT",
        'link_name'         => "VARCHAR(255) NULL"
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
    foreach ($record as $k => $v) {
        if (is_array($v)) {
            if (isset($v['types'], $v['selected'])) {
                $record[$k] = $v['types'][$v['selected']];
            } elseif (isset($v['text'])) {
                $record[$k] = $v['text'];
            }
        }
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
    $nameChanges      = $payload['nameChanges'] ?? ['features'=>[],'options'=>[],'addons'=>[]];
    $ft = $wpdb->prefix.'ffc_features';
    $ot = $wpdb->prefix.'ffc_options';
    $at = $wpdb->prefix.'ffc_addons';

    $processed_features = [];
    $processed_options  = [];
    $processed_addons   = [];

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
        // include link_name automatically
        $save['featureName'] = $final;
        $save = normalize_record($save);

        if ($row) {
            $fid = $row['id'];
            $wpdb->update($ft, $save, ['id'=>$fid]);
        } else {
            $wpdb->insert($ft, $save);
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
            $saveO = normalize_record($saveO);

            if ($rowO) {
                $oid = $rowO['id'];
                $wpdb->update($ot, $saveO, ['id'=>$oid]);
            } else {
                $wpdb->insert($ot, $saveO);
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
                $saveA = normalize_record($saveA);

                if ($rowA) {
                    $aid = $rowA['id'];
                    $wpdb->update($at, $saveA, ['id'=>$aid]);
                } else {
                    $wpdb->insert($at, $saveA);
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
            $feat['options'] = [];
            $out[$fid] = $feat;
        }
        if ($r['o_id']) {
            $oid = $r['o_id'];
            if (!isset($out[$fid]['options'][$oid])) {
                $opt = [];
                foreach ($o_cols as $c=>$t) $opt[$c] = $cast($r["o_$c"],$t);
                $opt['addons'] = [];
                $out[$fid]['options'][$oid] = $opt;
            }
            if ($r['a_id']) {
                $add = [];
                foreach ($a_cols as $c=>$t) $add[$c] = $cast($r["a_$c"],$t);
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
 * Drop all pricing tables.
 */
function drop_ffc_tables() {
    global $wpdb;
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

    // 1) Persist raw JSON (will include link_name fields)
    file_put_contents(
        $pricing_json_path,
        json_encode($data['pricingData'], JSON_PRETTY_PRINT)
    );

    // 2) Upsert into MySQL (also saves link_name)
    upsert_pricing_data($data);

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
