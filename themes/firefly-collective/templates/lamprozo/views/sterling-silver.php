<?php
require_once get_template_directory() . '/templates/lamprozo/data/sterling-silver.php';
$db_attempts = get_option('lamprozo_attempts_sterling-silver', null);
if ($db_attempts !== null) $attempts = $db_attempts;

$status_labels = [ 'ongoing' => 'Ongoing', 'failed' => 'Wiped', 'completed' => 'Completed' ];

include __DIR__ . '/_attempt-list.php';
