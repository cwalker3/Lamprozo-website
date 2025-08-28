<?php

// theme/models/user.php

function set_custom_user($encrypted_user_id) {

    $secure = is_ssl();

    // Prefer configured DOMAIN if present; otherwise fall back to current host.
    $domain = (defined('DOMAIN') && DOMAIN) ? DOMAIN : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
    // Strip port (if any)
    if (strpos($domain, ':') !== false) {
        $domain = explode(':', $domain)[0];
    }
    // For localhost/IPs, omit the domain attribute so the cookie sticks.
    $is_ip      = preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $domain);
    $is_local   = ($domain === 'localhost');
    $cookieHost = ($is_ip || $is_local) ? '' : $domain;

    setcookie('auth_id', $encrypted_user_id, [
        'expires'  => time() + 3600, // 1 hour; adjust if you prefer
        'path'     => '/',
        'domain'   => $cookieHost,   // empty string => no Domain attribute
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}