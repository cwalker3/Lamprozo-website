<?php

function send_html_mail($to, $subject, $html, $admin = false) {

    if ($admin) $to = 'info@fireflycollective.org';

    $headers = array(
        'From: Firefly Collective <donotreply@fireflycollective.org>',
        'Reply-To: donotreply@fireflycollective.org',
        'Content-Type: text/html; charset=UTF-8',
    );

    // Send the email
    wp_mail($to, $subject, $html, $headers);
}