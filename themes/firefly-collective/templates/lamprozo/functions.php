<?php
/**
 * Lamprozo Template - Functions
 *
 * Loads all template models and provides template-specific functions.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $template_path;

// Load models - add new model files to this array
$models = array(
    'util',
    'init',
    'view',
    'pages',
    'nav',
    'sitemap',
);

foreach ($models as $model) {
    $model_path = $template_path . "/models/$model.php";
    if (file_exists($model_path)) {
        require_once $model_path;
    }
}
