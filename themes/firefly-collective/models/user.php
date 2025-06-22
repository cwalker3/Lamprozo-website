<?php

    // theme/models/user.php

    function set_custom_user($encrypted_user_id) {
        setcookie('auth_id', $encrypted_user_id, time() + 3600, '/', DOMAIN, is_ssl(), true);
    }