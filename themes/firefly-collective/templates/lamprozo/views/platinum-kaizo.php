<?php
require_once get_template_directory() . '/templates/lamprozo/data/platinum-kaizo.php';
$db_attempts = get_option('lamprozo_attempts_platinum-kaizo', null);
if ($db_attempts !== null) $attempts = $db_attempts;

$status_labels = [ 'ongoing' => 'Ongoing', 'failed' => 'Wiped', 'completed' => 'Completed' ];

include __DIR__ . '/_attempt-list.php';
