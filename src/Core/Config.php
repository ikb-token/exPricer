<?php
namespace exPricer\Core;

/**
 * exPricer Configuration Manager
 * 
 * This class handles loading and accessing configuration values from the .env file.
 */
class Config {
    private static $config = [];
    
    private static $required = [
        'STRIPE_PUBLISHABLE_KEY',
        'STRIPE_SECRET_KEY',
        'PRODUCT_NAME',
        'PRODUCT_DESCRIPTION',
        'PRODUCT_FILE_NAME',
        'PRODUCT_FILE_SIZE',
        'DOWNLOAD_TOKEN_SECRET',
        'DOWNLOAD_EXPIRY_HOURS',
        'MAIL_FROM',
        'MAIL_REPLY_TO',
        'MAIL_SMTP_HOST',
        'MAIL_SMTP_PORT',
        'APP_URL',
        'WORK_TYPE',
        'MAX_COPIES',
        'MIN_PRICE'
    ];
    
    private static $validators = [
        'STRIPE_PUBLISHABLE_KEY' => '/^pk_(test|live)_[a-zA-Z0-9]*$/',
        'STRIPE_SECRET_KEY' => '/^sk_(test|live)_[a-zA-Z0-9]*$/',
        'DOWNLOAD_EXPIRY_HOURS' => '/^[1-9][0-9]*$/',
        'MAIL_FROM' => FILTER_VALIDATE_EMAIL,
        'MAIL_REPLY_TO' => FILTER_VALIDATE_EMAIL,
        'MAIL_SMTP_PORT' => '/^[1-9][0-9]*$/',
        'APP_URL' => FILTER_VALIDATE_URL,
        'WORK_TYPE' => '/^(physical|digital)$/',
        'MAX_COPIES' => '/^[1-9][0-9]*$/',
        'MIN_PRICE' => '/^[1-9][0-9]*$/'
    ];
    
    /**
     * Load configuration from .env file
     * 
     * @throws \RuntimeException if .env file is missing or invalid
     */
    public static function load() {
        $envFile = __DIR__ . '/../../.env';
        
        if (!file_exists($envFile)) {
            throw new \RuntimeException('.env file not found. Please create one based on .env.example');
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Failed to read .env file');
        }
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse key=value pairs
            if (strpos($line, '=') === false) {
                throw new \RuntimeException("Invalid .env line: $line");
            }
            
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove inline comments
            if (strpos($value, '#') !== false) {
                $value = trim(explode('#', $value)[0]);
            }
            
            // Remove quotes if present
            if (strpos($value, '"') === 0 || strpos($value, "'") === 0) {
                $value = trim($value, '"\'');
            }
            
            self::$config[$key] = $value;
        }
        
        self::validateRequired();
        self::validateValues();
    }
    
    /**
     * Validate that all required configuration values are present
     * 
     * @throws \RuntimeException if required values are missing
     */
    private static function validateRequired() {
        $missing = [];
        foreach (self::$required as $key) {
            if (!isset(self::$config[$key]) || empty(self::$config[$key])) {
                $missing[] = $key;
            }
        }
        
        if (!empty($missing)) {
            throw new \RuntimeException('Missing required configuration: ' . implode(', ', $missing));
        }
    }
    
    /**
     * Validate configuration values against their expected formats
     * 
     * @throws \RuntimeException if values are invalid
     */
    private static function validateValues() {
        foreach (self::$validators as $key => $validator) {
            if (!isset(self::$config[$key])) {
                continue;
            }
            
            $value = self::$config[$key];
            
            // Skip validation for empty PRODUCT_IMAGE
            if ($key === 'PRODUCT_IMAGE' && empty($value)) {
                continue;
            }
            
            if (is_string($validator)) {
                // Regex validation
                if (preg_match($validator, $value) !== 1) {
                    throw new \RuntimeException("Invalid value for $key: $value");
                }
            } else {
                // Filter validation
                if (filter_var($value, $validator) === false) {
                    throw new \RuntimeException("Invalid value for $key: $value");
                }
            }
        }
        
        // Additional validations
        if (isset(self::$config['DOWNLOAD_EXPIRY_HOURS'])) {
            $hours = (int)self::$config['DOWNLOAD_EXPIRY_HOURS'];
            if ($hours <= 0 || $hours > 168) { // Max 1 week
                throw new \RuntimeException('DOWNLOAD_EXPIRY_HOURS must be between 1 and 168');
            }
        }
        
        if (isset(self::$config['MAX_COPIES'])) {
            $copies = (int)self::$config['MAX_COPIES'];
            if ($copies <= 0 || $copies > 1000) {
                throw new \RuntimeException('MAX_COPIES must be between 1 and 1000');
            }
        }
    }
    
    /**
     * Get a configuration value
     * 
     * @param string $key The configuration key
     * @param mixed $default Default value if key is not found
     * @return mixed The configuration value
     */
    public static function get($key, $default = null) {
        return self::$config[$key] ?? $default;
    }
    
    /**
     * Check if a configuration value exists
     * 
     * @param string $key The configuration key
     * @return bool True if the key exists
     */
    public static function has($key) {
        return isset(self::$config[$key]);
    }
    
    /**
     * Get all configuration values
     * 
     * @return array All configuration values
     */
    public static function getAll() {
        return self::$config;
    }
} 