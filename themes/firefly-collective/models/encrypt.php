<?php

    function encrypt_with_auth_key($input) {
        $key       = SECURE_AUTH_KEY;
        $method    = 'AES-256-CBC';
        $iv_len    = openssl_cipher_iv_length($method);
        $iv        = openssl_random_pseudo_bytes($iv_len);
        $cipher    = openssl_encrypt($input, $method, $key, OPENSSL_RAW_DATA, $iv);

        // Derive a MAC key (could also use HKDF to split keys)  
        $mac_key   = hash_hmac('sha256', 'auth_mac', $key, true);
        $mac       = hash_hmac('sha256', $iv . $cipher, $mac_key, true);

        return base64_encode($iv . $cipher . $mac);
    }

    function decrypt_with_auth_key($cookie) {
        $key        = SECURE_AUTH_KEY;
        $method     = 'AES-256-CBC';
        $data       = base64_decode($cookie);
        $iv_len     = openssl_cipher_iv_length($method);
        $mac_len    = 32; // 256-bit HMAC

        $iv         = substr($data, 0, $iv_len);
        $ciphertext = substr($data, $iv_len, -$mac_len);
        $mac        = substr($data, -$mac_len);

        // Re-derive the same MAC key
        $mac_key    = hash_hmac('sha256', 'auth_mac', $key, true);
        $calc_mac   = hash_hmac('sha256', $iv . $ciphertext, $mac_key, true);

        // Constant-time comparison prevents timing attacks
        if ( ! hash_equals($mac, $calc_mac) ) {
            return false;
        }

        return openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);
    }

