<?php
/**
 * Lamprozo Template - Utility Functions
 */

if (!defined('ABSPATH')) {
    exit;
}

function slugToTitle($slug) {
    $string = str_replace('-', ' ', $slug);
    return ucwords($string);
}
