<?php
/**
 * ResponseSanitizer Class - Sanitizes API responses to prevent sensitive data leaks
 * 
 * Features:
 * - Masks sensitive fields
 * - Removes system information from errors
 * - Provides safe response formatting
 * - Automatically decrypts sensitive fields for authorized users
 */

class ResponseSanitizer {
    
    // Fields that should never appear in API responses
    private static $forbiddenFields = [
        'password',
        'password_hash',
        'encryption_key',
        'key',
        'secret',
        'token', // Don't echo back tokens
        'admin_password',
        'db_password'
    ];

    // Fields that should be masked for non-authorized users
    private static $sensitiveFields = [
        'email',
        'phone',
        'notes',
        'guest_name',
        'dietary_restrictions',
        'special_notes',
        'attendees'
    ];

    /**
     * Sanitize API response - remove sensitive data
     * 
     * @param mixed $data Data to sanitize
     * @param bool $isAuthorized Is user authorized to see sensitive data
     * @return mixed Sanitized data
     */
    public static function sanitize($data, $isAuthorized = false) {
        if (is_array($data)) {
            return self::sanitizeArray($data, $isAuthorized);
        } elseif (is_object($data)) {
            return self::sanitizeObject($data, $isAuthorized);
        }
        return $data;
    }

    /**
     * Sanitize array
     */
    private static function sanitizeArray($array, $isAuthorized = false) {
        $sanitized = [];

        foreach ($array as $key => $value) {
            // Skip forbidden fields
            if (in_array(strtolower($key), array_map('strtolower', self::$forbiddenFields))) {
                continue;
            }

            // Mask sensitive fields if not authorized
            if (!$isAuthorized && in_array(strtolower($key), array_map('strtolower', self::$sensitiveFields))) {
                $sanitized[$key] = self::maskValue($key, $value);
                continue;
            }

            // Recursively sanitize nested arrays/objects
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value, $isAuthorized);
            } elseif (is_object($value)) {
                $sanitized[$key] = self::sanitizeObject($value, $isAuthorized);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize object
     */
    private static function sanitizeObject($object, $isAuthorized = false) {
        $sanitized = [];

        foreach ((array) $object as $key => $value) {
            $cleanKey = trim($key, "\0");

            // Skip forbidden fields
            if (in_array(strtolower($cleanKey), array_map('strtolower', self::$forbiddenFields))) {
                continue;
            }

            // Mask sensitive fields if not authorized
            if (!$isAuthorized && in_array(strtolower($cleanKey), array_map('strtolower', self::$sensitiveFields))) {
                $sanitized[$cleanKey] = self::maskValue($cleanKey, $value);
                continue;
            }

            // Recursively sanitize
            if (is_array($value)) {
                $sanitized[$cleanKey] = self::sanitizeArray($value, $isAuthorized);
            } elseif (is_object($value)) {
                $sanitized[$cleanKey] = self::sanitizeObject($value, $isAuthorized);
            } else {
                $sanitized[$cleanKey] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Mask sensitive value
     */
    private static function maskValue($fieldName, $value) {
        if (is_null($value) || empty($value)) {
            return null;
        }

        $fieldLower = strtolower($fieldName);

        if ($fieldLower === 'email') {
            // Mask email: john@example.com -> j***@example.com
            $parts = explode('@', (string)$value);
            if (count($parts) === 2) {
                return substr($parts[0], 0, 1) . '***@' . $parts[1];
            }
            return '***@***';
        }

        if ($fieldLower === 'phone') {
            // Mask phone: +1-555-0123 -> ***-***-0123
            $phone = preg_replace('/\D/', '', (string)$value);
            $lastFour = substr($phone, -4);
            return '***-***-' . $lastFour;
        }

        if ($fieldLower === 'guest_name' || $fieldLower === 'attendee_name') {
            // Mask name: John Smith -> J*** S***
            $parts = explode(' ', trim((string)$value));
            $masked = [];
            foreach ($parts as $part) {
                $masked[] = substr($part, 0, 1) . str_repeat('*', max(0, strlen($part) - 1));
            }
            return implode(' ', $masked);
        }

        if ($fieldLower === 'dietary_restrictions' || $fieldLower === 'special_notes') {
            // Just indicate that sensitive data exists
            return '[Restricted Information]';
        }

        if ($fieldLower === 'attendees') {
            // For array/JSON of attendees
            if (is_string($value)) {
                $count = substr_count($value, ',') + 1;
                return "[" . $count . " attendees (details restricted)]";
            }
            return '[Attendee data restricted]';
        }

        if ($fieldLower === 'notes') {
            // Indicate notes exist but don't show content
            return '[Notes restricted]';
        }

        return '[Restricted]';
    }

    /**
     * Sanitize error message - remove database/system information
     * 
     * @param String $error Raw error message
     * @param bool $debug Show debug details (only in development)
     * @return string Safe error message
     */
    public static function sanitizeError($error, $debug = false) {
        $error = (string)$error;

        // In production, hide database and system details
        if (!$debug) {
            // Hide SQL errors
            if (stripos($error, 'SQL') !== false || stripos($error, 'database') !== false) {
                return 'A database error occurred. Please try again.';
            }

            // Hide file paths
            $error = preg_replace('#/[^/]+/[^/]+\.php#', '[system]', $error);

            // Hide stack traces
            if (stripos($error, 'trace') !== false) {
                return 'An error occurred. Please try again.';
            }

            // Generic error for sensitive operations
            if (stripos($error, 'encryption') !== false || stripos($error, 'crypto') !== false) {
                return 'A security error occurred. Please try again.';
            }
        }

        return $error;
    }

    /**
     * Create a safe response wrapper
     * 
     * @param bool $success Operation success
     * @param string $message Response message
     * @param mixed $data Response data
     * @param int $statusCode HTTP status code
     * @param bool $isAuthorized Is user authorized
     * @return array Safe response array
     */
    public static function createResponse($success, $message = '', $data = null, $statusCode = 200, $isAuthorized = false) {
        $response = [
            'success' => (bool)$success,
            'message' => $message
        ];

        if ($data !== null) {
            // Sanitize data before including
            $response['data'] = self::sanitize($data, $isAuthorized);
        }

        // Add status code for client reference
        if ($statusCode !== 200) {
            $response['status_code'] = $statusCode;
        }

        return $response;
    }

    /**
     * Create an error response
     * 
     * @param string $error Error message
     * @param int $statusCode HTTP status code
     * @param bool $debug Show debug information
     * @return array Error response
     */
    public static function createErrorResponse($error, $statusCode = 400, $debug = false) {
        return [
            'success' => false,
            'error' => self::sanitizeError($error, $debug),
            'status_code' => $statusCode
        ];
    }

    /**
     * Check if array contains sensitive fields
     * 
     * @param array $array
     * @return bool
     */
    public static function hasSensitiveData($array) {
        foreach (self::$sensitiveFields as $field) {
            if (isset($array[$field]) || isset($array[strtolower($field)])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get list of sensitive fields
     * 
     * @return array
     */
    public static function getSensitiveFields() {
        return self::$sensitiveFields;
    }

    /**
     * Get list of forbidden fields
     * 
     * @return array
     */
    public static function getForbiddenFields() {
        return self::$forbiddenFields;
    }
}

?>
