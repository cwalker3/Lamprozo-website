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

    function firefly_collective_make_log($request) {
        $params = $request->get_json_params();
        if ($params['secret'] !== FIREFLY_SHARED_SECRET) {
            return rest_ensure_response( array('success' => false, 
                                            'message' => 'Not authorized') );
        }
        error_log(print_r($params, true));
        return rest_ensure_response( array('success' => true, 
                                        'message' => 'Log successful') );
    }

    /**
     * Makes a log request to an endpoint
     */
    function make_log_request($api_url, $shared_secret, $log_data) {
        // Prepare the data to be sent
        $data = array(
            'secret' => $shared_secret,
            'log_data' => $log_data
        );
        
        // Initialize cURL session
        $ch = curl_init();
        
        // Set cURL options
        curl_setopt_array($ch, array(
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Accept: application/json'
            ),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true  // Set to false only in development if needed
        ));
        
        // Execute the request
        $response = curl_exec($ch);
        
        // Check for errors
        if ($response === false) {
            $result = array(
                'success' => false,
                'message' => 'cURL Error: ' . curl_error($ch)
            );
        } else {
            // Decode the JSON response
            $decoded_response = json_decode($response, true);
            
            // Use the decoded response if valid, otherwise use the raw response
            $result = is_array($decoded_response) ? $decoded_response : array(
                'success' => true,
                'message' => $response
            );
        }
        
        // Close cURL session
        curl_close($ch);
        
        return $result;
    }