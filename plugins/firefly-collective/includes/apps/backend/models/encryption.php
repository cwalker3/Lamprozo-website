<?php

/**
 * Database field encryption — AES-256-GCM primitives.
 *
 * Framework-level utility for encrypting individual values (typically post
 * meta, user meta, or custom-table columns) at rest. Key is derived from
 * SECURE_AUTH_SALT via PBKDF2 with a salt prefix unique to db-field
 * encryption, so file-level encryption (used elsewhere) and field-level
 * encryption use independent keys even though they share a master.
 *
 * Callers wire their own meta-key whitelists; this file deliberately ships
 * only the primitives so it stays template-agnostic.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get encryption key for database fields.
 * PBKDF2 over SECURE_AUTH_SALT with a unique label so file-level and
 * field-level encryption derive distinct keys from the same master.
 */
function firefly_collective_get_db_encryption_key() {
    if (!defined('SECURE_AUTH_SALT')) {
        throw new Exception('SECURE_AUTH_SALT not defined in wp-config.php');
    }

    // Use different salt than file encryption for key separation
    $key = hash_pbkdf2('sha256', SECURE_AUTH_SALT, 'db-field-encryption', 10000, 32, true);
    return $key;
}

/**
 * Encrypt a database field value
 * Returns base64-encoded encrypted data with IV and tag
 *
 * @param string $plaintext The value to encrypt
 * @return string Base64-encoded encrypted data (IV + Tag + Ciphertext)
 * @throws Exception if encryption fails
 */
function firefly_collective_encrypt_field($plaintext) {
    // Handle null/empty values
    if ($plaintext === null || $plaintext === '') {
        return '';
    }

    $key = firefly_collective_get_db_encryption_key();
    $iv = random_bytes(16); // 128-bit IV for AES
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false) {
        throw new Exception('Field encryption failed');
    }

    // Combine IV + Tag + Ciphertext and base64 encode
    $encrypted_data = base64_encode($iv . $tag . $ciphertext);

    // Clear sensitive data from memory
    unset($key, $ciphertext);

    return $encrypted_data;
}

/**
 * Decrypt a database field value
 *
 * @param string $encrypted_data Base64-encoded encrypted data
 * @return string The decrypted plaintext value
 * @throws Exception if decryption fails
 */
function firefly_collective_decrypt_field($encrypted_data) {
    // Handle null/empty values
    if ($encrypted_data === null || $encrypted_data === '') {
        return '';
    }

    // Check if this is already plaintext (for migration compatibility)
    // Encrypted data will always be base64 and start with consistent pattern
    // This detection helps during migration period
    if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $encrypted_data)) {
        // Not base64, assume plaintext (during migration)
        return $encrypted_data;
    }

    $raw_data = base64_decode($encrypted_data, true);

    // If base64 decode fails, assume plaintext
    if ($raw_data === false) {
        return $encrypted_data;
    }

    // Check if data is long enough (minimum 32 bytes for IV + tag)
    if (strlen($raw_data) < 32) {
        return $encrypted_data;
    }

    $key = firefly_collective_get_db_encryption_key();

    $iv = substr($raw_data, 0, 16);
    $tag = substr($raw_data, 16, 16);
    $ciphertext = substr($raw_data, 32);

    $decrypted = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($decrypted === false) {
        // Decryption failed - might be corrupted or wrong key
        // Log error and return empty string for safety
        error_log('Field decryption failed for data: ' . substr($encrypted_data, 0, 20) . '...');
        return '';
    }

    // Clear sensitive data from memory
    unset($key, $raw_data, $ciphertext);

    return $decrypted;
}

/**
 * Encrypt a JSON field
 * JSON data is serialized, encrypted, then stored
 *
 * @param mixed $data Data to encrypt (will be JSON encoded)
 * @return string Encrypted JSON data
 */
function firefly_collective_encrypt_json_field($data) {
    if ($data === null) {
        return '';
    }

    $json_string = json_encode($data);
    return firefly_collective_encrypt_field($json_string);
}

/**
 * Decrypt a JSON field
 *
 * @param string $encrypted_data Encrypted JSON data
 * @param bool $associative Return associative array instead of object
 * @return mixed Decrypted and decoded data
 */
function firefly_collective_decrypt_json_field($encrypted_data, $associative = true) {
    if ($encrypted_data === null || $encrypted_data === '') {
        return $associative ? array() : null;
    }

    // If already an array (e.g., stored unencrypted via update_user_meta), return as-is
    if (is_array($encrypted_data)) {
        return $encrypted_data;
    }

    $decrypted = firefly_collective_decrypt_field($encrypted_data);

    if ($decrypted === '') {
        return $associative ? array() : null;
    }

    $decoded = json_decode($decrypted, $associative);

    // If JSON decode fails, might be unencrypted legacy data
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        // Try decoding the original encrypted data (might be unencrypted JSON)
        $decoded = json_decode($encrypted_data, $associative);
    }

    return $decoded;
}

/**
 * Check if a value looks like one of our encrypted blobs.
 * Cheap heuristic — base64 shape and at least IV+tag in length.
 * Useful for migrations / mixed-state reads where some rows are
 * still plaintext after a partial backfill.
 */
function firefly_collective_is_encrypted($value) {
    if (empty($value)) {
        return false;
    }

    // Encrypted values are base64 encoded and at least 32 chars (IV + tag minimum)
    if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $value)) {
        return false;
    }

    $decoded = base64_decode($value, true);
    if ($decoded === false) {
        return false;
    }

    // Minimum length check (IV + tag = 32 bytes)
    if (strlen($decoded) < 32) {
        return false;
    }

    return true;
}
