<?php
/**
 * exPricer API Endpoint
 * 
 * This is the main API endpoint for exPricer, handling price calculations
 * and other API requests.
 */

// Main API endpoint for exPricer
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load configuration
require_once __DIR__ . '/src/autoload.php';
use exPricer\Core\Config;
use exPricer\Core\PriceCalculator;

try {
    Config::load();
} catch (Exception $e) {
    error_log("Configuration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Configuration error']);
    exit;
}

// Rate limiting
$rateLimitFile = __DIR__ . '/data/rate_limit.json';
$rateLimitWindow = 3600; // 1 hour
$maxRequests = 100; // Max requests per hour

function checkRateLimit($ip) {
    global $rateLimitFile, $rateLimitWindow, $maxRequests;
    
    // Open file for reading and writing
    $fp = fopen($rateLimitFile, 'c+');
    if (!$fp) {
        error_log("Could not open rate limit file for writing");
        http_response_code(500);
        die(json_encode(['error' => 'Internal server error']));
    }

    // Get exclusive lock
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        error_log("Could not acquire lock on rate limit file");
        http_response_code(500);
        die(json_encode(['error' => 'Internal server error']));
    }

    try {
        // Read current limits
        $content = fread($fp, filesize($rateLimitFile) ?: 1);
        $limits = json_decode($content, true) ?: [];
        $now = time();
        
        // Clean up old entries
        foreach ($limits as $storedIp => $data) {
            if ($now - $data['timestamp'] > $rateLimitWindow) {
                unset($limits[$storedIp]);
            }
        }
        
        // Check rate limit
        if (isset($limits[$ip])) {
            if ($limits[$ip]['count'] >= $maxRequests) {
                if ($now - $limits[$ip]['timestamp'] < $rateLimitWindow) {
                    http_response_code(429);
                    die(json_encode([
                        'error' => 'Rate limit exceeded',
                        'retry_after' => $rateLimitWindow - ($now - $limits[$ip]['timestamp'])
                    ]));
                }
                $limits[$ip] = ['count' => 1, 'timestamp' => $now];
            } else {
                $limits[$ip]['count']++;
            }
        } else {
            $limits[$ip] = ['count' => 1, 'timestamp' => $now];
        }
        
        // Write back to file
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($limits));
        
    } finally {
        // Always release the lock and close the file
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// CORS headers
$allowedOrigins = [
    Config::get('APP_URL'),
    'http://localhost:3000',
    'http://localhost:8000',
    '*'
];

$origin = '*';
/* Uncomment the following lines to restrict access to the API from only certain servers, as specified above */
// $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Only set CORS headers if the origin is allowed
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400'); // 24 hours
} else {
    // If origin is not allowed, return 403 Forbidden
    http_response_code(403);
    echo json_encode([
        'error' => 'Forbidden',
        'message' => 'Access from the server making the request is not allowed.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Check rate limit
checkRateLimit($_SERVER['REMOTE_ADDR']);

// Parse the request path
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Handle API requests
try {
    // Health check endpoint
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && 
        preg_match('#/api/v1/health$#', $requestPath)) {
        
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'healthy',
            'version' => '1.0.0',
            'timestamp' => date('c'),
            'service' => 'exPricer API'
        ]);
        exit;
    }
    
    // Check for calculate endpoint with wrong method
    if (preg_match('#/api/v1/calculate$#', $requestPath)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405); // Method Not Allowed
            echo json_encode([
                'error' => 'Method not allowed',
                'message' => 'The calculate endpoint only accepts POST requests. Please use POST method with a JSON body containing work_type, copies_sold, max_copies, and min_price.'
            ]);
            exit;
        }
        
        $calculator = new PriceCalculator();
        
        // Get request data
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON data');
        }
        
        // Validate required fields
        $requiredFields = ['work_type', 'copies_sold', 'max_copies', 'min_price'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new \RuntimeException("Missing required field: $field");
            }
        }
        
        // Calculate prices
        $result = $calculator->calculatePrices(
            $data['work_type'],
            $data['copies_sold'],
            $data['max_copies'],
            $data['min_price']
        );
        
        // Return success response
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
    
    // If we get here, the endpoint doesn't exist
    http_response_code(404);
    echo json_encode([
        'error' => 'Not found',
        'message' => 'The requested API endpoint does not exist'
    ]);
    
} catch (\RuntimeException $e) {
    // Client errors (400)
    http_response_code(400);
    echo json_encode([
        'error' => 'Bad Request',
        'message' => $e->getMessage()
    ]);
} catch (\Exception $e) {
    // Server errors (500)
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => 'An unexpected error occurred'
    ]);
} 