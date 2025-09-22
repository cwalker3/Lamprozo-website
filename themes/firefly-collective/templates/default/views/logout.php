<?php
// theme/views/logout.php

// Clear authentication
if (isset($_COOKIE['auth_id'])) {
    setcookie('auth_id', '', time() - 3600, '/', '', is_ssl(), true);
    unset($_COOKIE['auth_id']);
}

// Clear WordPress authentication
wp_logout();

// Clear any campaign tokens
if (isset($_COOKIE['campaign_token'])) {
    setcookie('campaign_token', '', time() - 3600, '/', '', is_ssl(), true);
    unset($_COOKIE['campaign_token']);
}

// Redirect to home page or login page
wp_redirect(home_url());
exit;