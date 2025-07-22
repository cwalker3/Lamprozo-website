<?php

    // plugin/models/util.php

    function generateToken($length = 21) {
        $bytes = random_bytes($length);
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $charsLength = strlen($chars);
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            $token .= $chars[$byte % $charsLength];
        }
        return $token;
    }

    function sanitizeRequestURI() {
        // Step 1: Retrieve the REQUEST_URI
        $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        // Step 2: Trim leading and trailing slashes
        $trimmedUri = trim($requestUri, '/');

        // Step 3: Remove all illegal URL characters
        $sanitizedUri = filter_var($trimmedUri, FILTER_SANITIZE_URL);

        // Step 4: Encode special characters to prevent XSS
        $safeUri = htmlspecialchars($sanitizedUri, ENT_QUOTES, 'UTF-8');

        return $safeUri;
    }

    function reliable_log($message, $context = '') {
        $log_file = ABSPATH . 'wp-content/debug.log';  // Changed ABS to ABSPATH
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[{$timestamp}] {$context}: {$message}" . PHP_EOL;
        
        // Use file locking to prevent corruption
        file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
    }