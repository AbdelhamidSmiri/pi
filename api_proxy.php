<?php
/**
 * API Proxy for Laundry Locker System with Enhanced RFID Handling
 * 
 * This script acts as a proxy between the frontend and the Python API backend.
 * Improved with better error handling, request timeout management,
 * and specific optimizations for RFID card reading.
 */

// Set headers to prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Allow cross-origin requests if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Set default content type to JSON
header('Content-Type: application/json');

// Configuration
$api_url = "http://localhost:5000/api";
$timeout = 5; // Default timeout in seconds
$max_retries = 2; // Number of retries for failed requests

// Error logging
function log_error($message) {
    error_log("[Laundry Locker API] " . $message);
}

// Handle preflight requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get endpoint from query string
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';

if (empty($endpoint)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing endpoint parameter']);
    exit;
}

// Map endpoints to API URLs with improved configuration
$endpoints = [
    'status' => [
        'url' => '/status',
        'method' => 'GET',
        'timeout' => 3 // Shorter timeout for status checks
    ],
    'wash-types' => [
        'url' => '/wash-types',
        'method' => 'GET',
        'timeout' => 3
    ],
    'read-card' => [
        'url' => '/read-card',
        'method' => 'GET',
        'timeout' => 2, // Shorter timeout for card reading to prevent UI hanging
        'retries' => 3, // More retries for RFID reading
        'retry_delay' => 200 // Milliseconds between retries
    ],
    'clear-card-queue' => [
        'url' => '/clear-card-queue',
        'method' => 'POST',
        'timeout' => 2
    ],
    'drop-off' => [
        'url' => '/drop-off',
        'method' => 'POST',
        'timeout' => 10 // Longer timeout for operations that control hardware
    ],
    'pick-up' => [
        'url' => '/pick-up',
        'method' => 'POST',
        'timeout' => 10
    ],
    'health' => [
        'url' => '/health',
        'method' => 'GET',
        'timeout' => 2
    ],
    'reset-rfid-reader' => [
        'url' => '/reset-rfid-reader',
        'method' => 'POST',
        'timeout' => 15 // Longer timeout as RFID reset can take time
    ]
];

// Check if endpoint exists
if (!isset($endpoints[$endpoint])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    exit;
}

$api_endpoint = $endpoints[$endpoint];
$url = $api_url . $api_endpoint['url'];
$method = $api_endpoint['method'];
$endpoint_timeout = isset($api_endpoint['timeout']) ? $api_endpoint['timeout'] : $timeout;
$endpoint_retries = isset($api_endpoint['retries']) ? $api_endpoint['retries'] : $max_retries;
$retry_delay = isset($api_endpoint['retry_delay']) ? $api_endpoint['retry_delay'] : 500; // Default 500ms

// Get request body for POST requests
$request_body = null;
if ($method === 'POST') {
    $request_body = file_get_contents('php://input');
    
    // Validate JSON for POST requests
    if ($request_body) {
        $decoded = json_decode($request_body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON in request body: ' . json_last_error_msg()]);
            exit;
        }
    }
}

// Log request for debugging if needed
$request_id = uniqid();
log_error("API Request $request_id: $method $endpoint");

// Retry loop - very important for RFID reliability
$attempt = 0;
$success = false;
$last_error = null;
$response = null;
$status_code = 0;

while ($attempt < $endpoint_retries && !$success) {
    $attempt++;
    
    if ($attempt > 1) {
        // Add delay between retries (in microseconds)
        usleep($retry_delay * 1000);
        log_error("Retry attempt $attempt for request $request_id: $method $endpoint");
    }
    
    // Initialize cURL
    $curl = curl_init();
    
    // Set cURL options
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $endpoint_timeout,
        CURLOPT_CONNECTTIMEOUT => 3, // 3 second connection timeout
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FAILONERROR => false, // Don't fail on HTTP error codes, we'll handle them
        CURLOPT_SSL_VERIFYPEER => false, // Disable SSL verification for local development
        CURLOPT_NOSIGNAL => 1 // Prevent SIGALRM issues during multi-threading on some systems
    ];
    
    // Add request body for POST requests
    if ($method === 'POST' && $request_body) {
        $options[CURLOPT_POSTFIELDS] = $request_body;
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    
    curl_setopt_array($curl, $options);
    
    // Execute the request with error handling
    $start_time = microtime(true);
    $response = curl_exec($curl);
    $end_time = microtime(true);
    $duration = round(($end_time - $start_time) * 1000); // Duration in milliseconds
    
    $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_errno = curl_errno($curl);
    $error = curl_error($curl);
    
    curl_close($curl);
    
    // Log response for debugging
    log_error("API Response $request_id: HTTP $status_code, took {$duration}ms" . ($error ? ", Error: $error" : ""));
    
    // Check if the request was successful
    if (!$curl_errno && $status_code >= 200 && $status_code < 300) {
        $success = true;
    } else {
        // Store the last error
        $last_error = $error ?: "HTTP error $status_code";
        
        // For RFID reader, some specific errors might indicate we should retry
        if ($endpoint === 'read-card' && ($curl_errno == CURLE_OPERATION_TIMEDOUT || $curl_errno == CURLE_COULDNT_CONNECT)) {
            log_error("Retryable error for RFID reader: $last_error");
            // Continue to next attempt
        } else if ($status_code >= 500) {
            // Server errors might be temporary, retry
            log_error("Server error, retrying: $last_error");
            // Continue to next attempt
        } else if ($status_code >= 400 && $status_code < 500) {
            // Client errors are unlikely to be resolved by retry
            log_error("Client error, not retrying: HTTP $status_code");
            break;
        }
    }
}

// If all retries failed, return error
if (!$success) {
    // Provide more specific error messages for common connection issues
    $error_message = "API connection error after $attempt attempts";
    
    if ($curl_errno == CURLE_OPERATION_TIMEDOUT) {
        $error_message = "API request timed out after {$endpoint_timeout} seconds";
    } elseif ($curl_errno == CURLE_COULDNT_CONNECT) {
        $error_message = "Could not connect to API server";
    } elseif ($curl_errno == CURLE_COULDNT_RESOLVE_HOST) {
        $error_message = "Could not resolve API server hostname";
    }
    
    http_response_code(503); // Service Unavailable
    echo json_encode([
        'success' => false, 
        'message' => $error_message,
        'error_code' => $curl_errno,
        'error_details' => $last_error
    ]);
    exit;
}

// Set response status code
http_response_code($status_code);

// Enhanced handling for read-card endpoint
if ($endpoint === 'read-card') {
    $data = json_decode($response, true);
    
    // This is just to improve the user experience for card reading
    if (isset($data['success'])) {
        if ($data['success'] === true && isset($data['card'])) {
            // Successfully read a card - pass through the response exactly
            echo $response;
            exit;
        } else {
            // No card read yet - this is normal
            echo json_encode([
                'success' => false,
                'message' => 'No card detected'
            ]);
            exit;
        }
    }
}

// Special handling for status checks
if ($endpoint === 'status' || $endpoint === 'health') {
    $data = json_decode($response, true);
    
    // Add API proxy metadata to help with debugging
    if (is_array($data)) {
        $data['proxy_info'] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'request_id' => $request_id,
            'response_time_ms' => $duration
        ];
        
        echo json_encode($data);
        exit;
    }
}

// Return API response
echo $response;