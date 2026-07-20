<?php
/**
 * Environment Configuration Loader
 * Loads environment variables from .env file if it exists
 * Should be included in config.php before database operations
 */

class EnvironmentLoader {
    private static $loaded = false;

    /**
     * Load environment variables from .env file
     * Looks for .env file in parent directory (outside web root)
     */
    public static function load() {
        if (self::$loaded) {
            return;
        }

        // Look for .env in parent directory (outside web root)
        $envPath = dirname(dirname(__FILE__)) . '/.env';
        
        if (!file_exists($envPath)) {
            // Try sibling of rsvp directory
            $envPath = dirname(dirname(dirname(__FILE__))) . '/.env';
        }

        if (file_exists($envPath)) {
            self::loadFromFile($envPath);
        }

        self::$loaded = true;
    }

    /**
     * Load environment variables from file
     * 
     * @param string $filePath Path to .env file
     */
    private static function loadFromFile($filePath) {
        if (!is_readable($filePath)) {
            throw new Exception("Cannot read .env file: $filePath");
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse key=value
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                    (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                    $value = substr($value, 1, -1);
                }

                // Set environment variable if not already set
                if (!getenv($key)) {
                    putenv("$key=$value");
                }

                // Also set in $_ENV
                $_ENV[$key] = $value;
            }
        }
    }

    /**
     * Get environment variable with default fallback
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $default;
        }
        return $value;
    }

    /**
     * Check if environment variable exists and is not empty
     * 
     * @param string $key
     * @return bool
     */
    public static function has($key) {
        $value = self::get($key);
        return !empty($value);
    }

    /**
     * Set environment variable
     * 
     * @param string $key
     * @param string $value
     */
    public static function set($key, $value) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// Auto-load when included
EnvironmentLoader::load();

?>
