<?php

    function encrypt_with_auth_key($input) {
        $key = SECURE_AUTH_KEY;
        $method = 'AES-256-CBC';
        $iv_length = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($iv_length);
        $encrypted = openssl_encrypt($input, $method, $key, OPENSSL_RAW_DATA, $iv);
        $encrypted_user_id = base64_encode($iv . $encrypted);
        return $encrypted_user_id;
    }

    function decrypt_with_auth_key($encrypted) {
        $key = SECURE_AUTH_KEY;
        $method = 'AES-256-CBC';
        $iv_length = openssl_cipher_iv_length($method);
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, $iv_length);
        $ciphertext = substr($data, $iv_length);
        $decrypted = openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted;
    }
