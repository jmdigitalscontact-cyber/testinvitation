<?php
/**
 * InputValidator Class - Comprehensive input validation and sanitization
 * 
 * Features:
 * - Whitelist-based validation
 * - Type checking and casting
 * - Injection prevention
 * - Error reporting
 */

class InputValidator {
    private $errors = [];
    private $data = [];

    // Validation rules
    private const RULES = [
        'invitation_id' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 50, 'pattern' => '/^[a-zA-Z0-9_-]+$/'],
        'password' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 255],
        'email' => ['type' => 'email', 'maxLength' => 255],
        'phone' => ['type' => 'phone', 'maxLength' => 20],
        'guest_name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 255],
        'attending' => ['type' => 'enum', 'values' => ['yes', 'no', 'maybe']],
        'attendee_count' => ['type' => 'integer', 'min' => 0, 'max' => 50],
        'dietary_restrictions' => ['type' => 'string', 'maxLength' => 512],
        'special_notes' => ['type' => 'string', 'maxLength' => 1024],
        'attendees' => ['type' => 'json_array'],
        'username' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 50, 'pattern' => '/^[a-zA-Z0-9_]+$/'],
        'max_guests' => ['type' => 'integer', 'min' => 1, 'max' => 200],
        'notes' => ['type' => 'string', 'maxLength' => 1024]
    ];

    /**
     * Constructor
     */
    public function __construct($data = []) {
        $this->data = $data;
    }

    /**
     * Validate field according to rules
     * 
     * @param string $field Field name
     * @param string $rule Rule name or custom rule
     * @param mixed $value Field value
     * @return bool Valid
     */
    public function validate($field, $rule = null, $value = null) {
        if ($value === null) {
            $value = $this->data[$field] ?? null;
        }

        if ($rule === null && isset(self::RULES[$field])) {
            $rule = self::RULES[$field];
        }

        if (is_string($rule)) {
            $rule = [
                'validation_rule' => $rule
            ];
        }

        if (!is_array($rule)) {
            $this->errors[$field] = 'Invalid validation rule';
            return false;
        }

        // Validate based on type
        $type = $rule['type'] ?? 'string';

        switch ($type) {
            case 'string':
                return $this->validateString($field, $value, $rule);
            case 'email':
                return $this->validateEmail($field, $value, $rule);
            case 'phone':
                return $this->validatePhone($field, $value, $rule);
            case 'integer':
            case 'int':
                return $this->validateInteger($field, $value, $rule);
            case 'enum':
                return $this->validateEnum($field, $value, $rule);
            case 'json_array':
                return $this->validateJsonArray($field, $value, $rule);
            case 'boolean':
            case 'bool':
                return $this->validateBoolean($field, $value, $rule);
            default:
                $this->errors[$field] = 'Unknown validation type: ' . $type;
                return false;
        }
    }

    /**
     * Validate required fields
     * 
     * @param array $required List of required fields
     * @return bool All required fields present
     */
    public function required($required = []) {
        $valid = true;

        foreach ($required as $field) {
            if (!isset($this->data[$field]) || (is_string($this->data[$field]) && empty(trim($this->data[$field])))) {
                $this->errors[$field] = ucfirst($field) . ' is required';
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Validate string field
     */
    private function validateString($field, $value, $rule) {
        if ($value === null) {
            return true; // Optional unless required
        }

        if (!is_string($value)) {
            $this->errors[$field] = ucfirst($field) . ' must be a string';
            return false;
        }

        // Trim whitespace
        $value = trim($value);

        // Check length
        $minLength = $rule['minLength'] ?? 0;
        if (strlen($value) < $minLength) {
            $this->errors[$field] = ucfirst($field) . ' must be at least ' . $minLength . ' characters';
            return false;
        }

        $maxLength = $rule['maxLength'] ?? 65535;
        if (strlen($value) > $maxLength) {
            $this->errors[$field] = ucfirst($field) . ' must be at most ' . $maxLength . ' characters';
            return false;
        }

        // Check pattern
        if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
            $this->errors[$field] = ucfirst($field) . ' format is invalid';
            return false;
        }

        // Check for common injections
        if ($this->containsInjectionPatterns($value)) {
            $this->errors[$field] = ucfirst($field) . ' contains invalid characters';
            return false;
        }

        return true;
    }

    /**
     * Validate email field
     */
    private function validateEmail($field, $value, $rule) {
        if ($value === null) {
            return true;
        }

        $value = trim((string)$value);

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = ucfirst($field) . ' must be a valid email address';
            return false;
        }

        $maxLength = $rule['maxLength'] ?? 255;
        if (strlen($value) > $maxLength) {
            $this->errors[$field] = ucfirst($field) . ' is too long';
            return false;
        }

        return true;
    }

    /**
     * Validate phone field
     */
    private function validatePhone($field, $value, $rule) {
        if ($value === null) {
            return true;
        }

        $value = trim((string)$value);

        // Remove common phone formatting
        $cleaned = preg_replace('/[^0-9+\-\s()]/', '', $value);

        // Basic validation: at least 7 digits
        $digits = preg_replace('/[^0-9]/', '', $cleaned);
        if (strlen($digits) < 7) {
            $this->errors[$field] = ucfirst($field) . ' must contain at least 7 digits';
            return false;
        }

        $maxLength = $rule['maxLength'] ?? 20;
        if (strlen($value) > $maxLength) {
            $this->errors[$field] = ucfirst($field) . ' is too long';
            return false;
        }

        return true;
    }

    /**
     * Validate integer field
     */
    private function validateInteger($field, $value, $rule) {
        if ($value === null) {
            return true;
        }

        if (!is_numeric($value) || (int)$value != $value) {
            $this->errors[$field] = ucfirst($field) . ' must be an integer';
            return false;
        }

        $value = (int)$value;

        if (isset($rule['min']) && $value < $rule['min']) {
            $this->errors[$field] = ucfirst($field) . ' must be at least ' . $rule['min'];
            return false;
        }

        if (isset($rule['max']) && $value > $rule['max']) {
            $this->errors[$field] = ucfirst($field) . ' must be at most ' . $rule['max'];
            return false;
        }

        return true;
    }

    /**
     * Validate enum field
     */
    private function validateEnum($field, $value, $rule) {
        if ($value === null) {
            return true;
        }

        $values = $rule['values'] ?? [];
        $value = strtolower((string)$value);

        $validValues = array_map('strtolower', $values);

        if (!in_array($value, $validValues)) {
            $this->errors[$field] = ucfirst($field) . ' must be one of: ' . implode(', ', $values);
            return false;
        }

        return true;
    }

    /**
     * Validate JSON array field
     */
    private function validateJsonArray($field, $value, $rule) {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->errors[$field] = ucfirst($field) . ' must be valid JSON';
                return false;
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            $this->errors[$field] = ucfirst($field) . ' must be an array';
            return false;
        }

        return true;
    }

    /**
     * Validate boolean field
     */
    private function validateBoolean($field, $value, $rule) {
        if ($value === null) {
            return true;
        }

        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            $this->errors[$field] = ucfirst($field) . ' must be a boolean';
            return false;
        }

        return true;
    }

    /**
     * Check for injection patterns
     * 
     * @param string $value
     * @return bool Contains injection patterns
     */
    private function containsInjectionPatterns($value) {
        $maliciousPatterns = [
            '/(<|>|&lt;|&gt;).*?(script|iframe|onclick|onerror)/i',
            '/(\bOR\b|\bAND\b)\s*\d+\s*=\s*\d+/i', // SQL injection
            '/(union|select|insert|update|delete|drop|create|alter)/i', // SQL keywords (some)
            '/\$\{.*?\}/', // Template injection
            '/%\{.*?\}/', // Template injection alternative
            '/<\?php|<\?|<%|%>|<\/\?/', // PHP tags
        ];

        foreach ($maliciousPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize string - remove potentially harmful characters
     * 
     * @param string $value
     * @return string Sanitized value
     */
    public static function sanitizeString($value) {
        // Remove null bytes
        $value = str_replace("\0", '', (string)$value);

        // Remove control characters
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // Trim whitespace
        $value = trim($value);

        return $value;
    }

    /**
     * Get validation errors
     */
    public function errors() {
        return $this->errors;
    }

    /**
     * Check if there are errors
     */
    public function hasErrors() {
        return !empty($this->errors);
    }

    /**
     * Get first error as string
     */
    public function firstError() {
        return array_values($this->errors)[0] ?? '';
    }

    /**
     * Validate entire dataset against rules
     * 
     * @param array $data
     * @param array $ruleset List of field => rule mappings
     * @param array $required List of required fields
     * @return bool Valid
     */
    public static function validateBatch($data, $ruleset, $required = []) {
        $validator = new self($data);

        if (!empty($required)) {
            $validator->required($required);
        }

        foreach ($ruleset as $field => $rule) {
            $validator->validate($field, $rule);
        }

        return !$validator->hasErrors();
    }
}

?>
