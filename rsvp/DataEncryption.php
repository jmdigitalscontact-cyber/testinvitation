<?php
/**
 * DataEncryption Class - Provides AES-256-GCM encryption/decryption for sensitive data
 * 
 * Features:
 * - AES-256-GCM encryption with PBKDF2 key derivation
 * - Automatic IV generation for each encryption
 * - HMAC verification for tamper detection
 * - Base64 encoding for database storage
 * - Type hints and comprehensive error handling
 */

class DataEncryption {
    private static $instance = null;
    private $masterKey;
    private $keyVersion = 1;
    private $encryptionMethod = 'aes-256-gcm';
    private $hashAlgo = 'sha256';
    private $keyDerivationAlgo = 'sha256';

    /**
     * Singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new DataEncryption();
        }
        return self::$instance;
    }

    /**
     * Constructor - Initialize encryption with master key from environment
     */
    private function __construct() {
        $encryptionKey = getenv('ENCRYPTION_KEY');
        
        if (empty($encryptionKey)) {
            throw new Exception(
                'ENCRYPTION_KEY not set in environment variables. ' .
                'Please set the encryption key in your .env file or system environment.'
            );
        }

        // Validate key format (should be base64-encoded)
        if (!$this->isValidBase64($encryptionKey)) {
            throw new Exception('ENCRYPTION_KEY must be valid base64 encoded string.');
        }

        $this->masterKey = base64_decode($encryptionKey, true);
        
        if (strlen($this->masterKey) < 32) {
            throw new Exception('ENCRYPTION_KEY must decode to at least 32 bytes.');
        }
    }

    /**
     * Encrypt sensitive data
     * 
     * @param string $data Data to encrypt
     * @param string $associatedData Optional additional authenticated data
     * @return string Encrypted data (base64 encoded with format: version:iv:ciphertext:tag:hmac)
     * @throws Exception
     */
    public function encrypt($data, $associatedData = '') {
        if (empty($data)) {
            return '';
        }

        try {
            // Generate random IV (16 bytes for GCM)
            $iv = openssl_random_pseudo_bytes(16);
            
            // Derive encryption key using PBKDF2
            $derivedKey = hash_pbkdf2($this->keyDerivationAlgo, $this->masterKey, $iv, 10000, 32, true);
            
            // Encrypt using AES-256-GCM
            $tag = '';
            $ciphertext = openssl_encrypt(
                $data,
                $this->encryptionMethod,
                $derivedKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $associatedData
            );

            if ($ciphertext === false) {
                throw new Exception('Encryption failed: ' . openssl_error_string());
            }

            // Generate HMAC for additional integrity verification
            $hmacPayload = $this->keyVersion . ':' . base64_encode($iv) . ':' . 
                          base64_encode($ciphertext) . ':' . base64_encode($tag);
            $hmac = hash_hmac($this->hashAlgo, $hmacPayload, $this->masterKey);

            // Return format: version:iv:ciphertext:tag:hmac (all base64 except version)
            $encrypted = $this->keyVersion . ':' . 
                        base64_encode($iv) . ':' . 
                        base64_encode($ciphertext) . ':' . 
                        base64_encode($tag) . ':' . 
                        $hmac;

            return $encrypted;

        } catch (Exception $e) {
            throw new Exception('Encryption error: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt encrypted data
     * 
     * @param string $encryptedData Encrypted data (base64 encoded)
     * @param string $associatedData Optional additional authenticated data (must match encryption)
     * @return string|null Decrypted data, or null if decryption fails
     */
    public function decrypt($encryptedData, $associatedData = '') {
        if (empty($encryptedData)) {
            return '';
        }

        try {
            // Parse encrypted data format: version:iv:ciphertext:tag:hmac
            $parts = explode(':', $encryptedData, 5);
            
            if (count($parts) !== 5) {
                throw new Exception('Invalid encrypted data format.');
            }

            list($version, $ivBase64, $ciphertextBase64, $tagBase64, $hmac) = $parts;

            // Verify HMAC before attempting decryption
            $hmacPayload = $version . ':' . $ivBase64 . ':' . $ciphertextBase64 . ':' . $tagBase64;
            $expectedHmac = hash_hmac($this->hashAlgo, $hmacPayload, $this->masterKey);

            if (!hash_equals($hmac, $expectedHmac)) {
                throw new Exception('HMAC verification failed - data may be tampered.');
            }

            // Decode components
            $iv = base64_decode($ivBase64, true);
            $ciphertext = base64_decode($ciphertextBase64, true);
            $tag = base64_decode($tagBase64, true);

            if ($iv === false || $ciphertext === false || $tag === false) {
                throw new Exception('Invalid base64 encoding in encrypted data.');
            }

            // Derive encryption key using PBKDF2 (same as encryption)
            $derivedKey = hash_pbkdf2($this->keyDerivationAlgo, $this->masterKey, $iv, 10000, 32, true);

            // Decrypt using AES-256-GCM
            $plaintext = openssl_decrypt(
                $ciphertext,
                $this->encryptionMethod,
                $derivedKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $associatedData
            );

            if ($plaintext === false) {
                throw new Exception('Decryption failed or tag verification failed.');
            }

            return $plaintext;

        } catch (Exception $e) {
            // Log the error but don't expose sensitive details
            error_log('Decryption error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a string is valid base64
     * 
     * @param string $string
     * @return bool
     */
    private function isValidBase64($string) {
        $decoded = base64_decode($string, true);
        if ($decoded === false) {
            return false;
        }
        // Check if re-encoding produces the original string
        return base64_encode($decoded) === $string;
    }

    /**
     * Generate a random encryption key (for initial setup)
     * 
     * @return string Base64-encoded encryption key (should be stored in .env)
     */
    public static function generateRandomKey() {
        $key = openssl_random_pseudo_bytes(32);
        return base64_encode($key);
    }

    /**
     * Encrypt multiple sensitive fields in an associative array
     * 
     * @param array $data Array containing data
     * @param array $fieldsToEncrypt List of field names to encrypt
     * @return array Array with specified fields encrypted
     */
    public function encryptFields(&$data, $fieldsToEncrypt = []) {
        foreach ($fieldsToEncrypt as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $data[$field] = $this->encrypt($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Decrypt multiple sensitive fields in an associative array
     * 
     * @param array $data Array containing encrypted data
     * @param array $fieldsToDecrypt List of field names to decrypt
     * @return array Array with specified fields decrypted
     */
    public function decryptFields(&$data, $fieldsToDecrypt = []) {
        foreach ($fieldsToDecrypt as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $decrypted = $this->decrypt($data[$field]);
                $data[$field] = $decrypted !== null ? $decrypted : $data[$field];
            }
        }
        return $data;
    }

    /**
     * Get key version for managing key rotation
     * 
     * @return int
     */
    public function getKeyVersion() {
        return $this->keyVersion;
    }

    /**
     * Verify data integrity using HMAC
     * 
     * @param string $data Data to verify
     * @param string $hmac HMAC to compare against
     * @return bool
     */
    public function verifyIntegrity($data, $hmac) {
        $expectedHmac = hash_hmac($this->hashAlgo, $data, $this->masterKey);
        return hash_equals($hmac, $expectedHmac);
    }
}

?>
