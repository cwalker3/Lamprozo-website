<?php
/**
 * Generic challenge view — handles any challenge by slug.
 */
$segments   = parse_request_uri();
$slug       = sanitize_title($segments[0] ?? '');
$challenges = lamprozo_get_challenges_data();
$challenge  = $challenges[$slug] ?? null;

if (!$challenge) {
    include __DIR__ . '/404.php';
    return;
}

$attempts      = lamprozo_get_attempts($slug);
$status_labels = ['ongoing' => 'Ongoing', 'failed' => 'Wiped', 'completed' => 'Completed'];

include __DIR__ . '/_attempt-list.php';
