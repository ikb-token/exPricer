<?php
/**
 * exPricer Download Handler
 * 
 * This script handles secure file downloads using time-limited access tokens.
 */

// Download handler for exPricer
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load configuration
require_once __DIR__ . '/src/autoload.php';
use exPricer\Core\Config;

try {
    Config::load();
} catch (Exception $e) {
    error_log("Configuration error: " . $e->getMessage());
    die("Configuration error. Please contact support.");
}

// Simple JWT implementation
function validateDownloadToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }

    $header = json_decode(base64_decode($parts[0]), true);
    $payload = json_decode(base64_decode($parts[1]), true);
    $signature = base64_decode($parts[2]);

    if (!$header || !$payload || !$signature) {
        return false;
    }

    // Verify signature
    $expectedSignature = hash_hmac('sha256', "$parts[0].$parts[1]", Config::get('DOWNLOAD_TOKEN_SECRET'), true);
    if (!hash_equals($expectedSignature, $signature)) {
        return false;
    }

    // Check expiration
    if (!isset($payload['exp']) || $payload['exp'] < time()) {
        return false;
    }

    return $payload;
}

// Get token from URL
$token = $_GET['token'] ?? null;
if (!$token) {
    header('HTTP/1.1 400 Bad Request');
    die('No download token provided');
}

// Validate token
$payload = validateDownloadToken($token);
if (!$payload || !isset($payload['file_id'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Invalid or expired download token');
}

// Get file name from token
$fileName = $payload['file_id']; // This is now the actual filename

// Define downloads directory (outside public for security)
$downloadsDir = __DIR__ . '/downloads';

// Check if downloads directory exists
if (!is_dir($downloadsDir)) {
    error_log("Downloads directory not found: " . $downloadsDir);
    header('HTTP/1.1 500 Internal Server Error');
    die('Download system error. Please contact support.');
}

// Get the actual file path
$filePath = $downloadsDir . '/' . $fileName;
error_log("Attempting to download file: " . $filePath);

// Check if file exists and is readable
if (!file_exists($filePath)) {
    error_log("File not found: " . $filePath);
    header('HTTP/1.1 404 Not Found');
    die('File not found');
}

if (!is_readable($filePath)) {
    error_log("File not readable: " . $filePath);
    header('HTTP/1.1 403 Forbidden');
    die('File access denied');
}

// Get file information
$fileSize = filesize($filePath);
if ($fileSize === false) {
    error_log("Could not get file size: " . $filePath);
    header('HTTP/1.1 500 Internal Server Error');
    die('Download system error. Please contact support.');
}

// Detect MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath);
finfo_close($finfo);

// Set security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Content-Security-Policy: default-src \'none\'');

// Set download headers
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Clear output buffer
if (ob_get_level()) {
    ob_end_clean();
}

// Stream the file
readfile($filePath);
exit; 