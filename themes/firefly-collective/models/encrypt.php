<?php

    function encrypt_with_auth_key($input) {
        // --- Encrypt the auth_id using SECURE_AUTH_KEY ---
        // Using AES-256-CBC. The IV is prepended to the ciphertext; result is base64-encoded.
        $key = SECURE_AUTH_KEY; // defined in wp-config.php
        $method = 'AES-256-CBC';
        $iv_length = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($iv_length);
        $encrypted = openssl_encrypt($input, $method, $key, OPENSSL_RAW_DATA, $iv);
        $encrypted_user_id = base64_encode($iv . $encrypted);
        return $encrypted_user_id;
    }