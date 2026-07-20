<?php
/**
 * Encryption Key Management Script
 * 
 * Usage:
 * php setup-encryption.php --generate-key          : Generate a new encryption key
 * php setup-encryption.php --verify-key             : Verify encryption key is setup
 * php setup-encryption.php --test-encryption        : Test encryption/decryption
 * php setup-encryption.php --encrypt-existing-data  : Encrypt all existing unencrypted data
 * 
 * Run: php setup-encryption.php --help for more options
 */

require_once 'EnvironmentLoader.php';
require_once 'DataEncryption.php';

// Only load database components if needed
$needsDatabase = false;
if (php_sapi_name() === 'cli') {
    $command = $argv[1] ?? '--help';
    $needsDatabase = in_array($command, ['--check-data', '--encrypt-existing-data']);
}

if ($needsDatabase) {
    require_once 'config.php';
    require_once 'Database.php';
}

class EncryptionSetup {
    private $encryption;
    private $db;
    private $mysqli;

    public function __construct($requireDb = false) {
        try {
            // Try to load encryption - may fail if key not set yet
            if (EnvironmentLoader::has('ENCRYPTION_KEY')) {
                $this->encryption = DataEncryption::getInstance();
            }
            
            if ($requireDb) {
                $this->db = Database::getInstance();
                $this->mysqli = $this->db->getConnection();
            }
        } catch (Exception $e) {
            // Encryption key not set up yet - OK for initial setup
            $this->encryption = null;
            if ($requireDb) {
                throw $e;
            }
        }
    }

    /**
     * Generate a new encryption key
     */
    public function generateKey() {
        echo "\n🔐 Generating new encryption key...\n";
        
        $key = DataEncryption::generateRandomKey();
        
        echo "\n✅ Encryption key generated successfully!\n";
        echo "\n📋 Add this to your .env file:\n";
        echo "ENCRYPTION_KEY=$key\n";
        echo "\n⚠️  IMPORTANT:\n";
        echo "   1. Keep this key SECURE\n";
        echo "   2. Store .env file OUTSIDE web root\n";
        echo "   3. Never commit .env to version control\n";
        echo "   4. Back up this key in a secure location\n\n";
    }

    /**
     * Verify encryption key is properly set up
     */
    public function verifyKey() {
        echo "\n🔍 Verifying encryption key setup...\n";

        if (!EnvironmentLoader::has('ENCRYPTION_KEY')) {
            echo "❌ ENCRYPTION_KEY not set in environment\n";
            echo "   Run: php setup-encryption.php --generate-key\n";
            return false;
        }

        try {
            $enc = DataEncryption::getInstance();
            echo "✅ Encryption key is properly configured\n";
            echo "   Key version: " . $enc->getKeyVersion() . "\n";
            return true;
        } catch (Exception $e) {
            echo "❌ Encryption key error: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Test encryption and decryption
     */
    public function testEncryption() {
        echo "\n🧪 Testing encryption/decryption...\n";

        if (!$this->encryption) {
            echo "❌ Encryption not initialized. Set ENCRYPTION_KEY first.\n";
            return false;
        }

        $testData = [
            "John Smith",
            "jane.doe@example.com",
            "+1-555-0123",
            "Vegetarian, nut allergy",
            '{"name":"Jane Smith","age":28}'
        ];

        try {
            foreach ($testData as $index => $data) {
                $encrypted = $this->encryption->encrypt($data);
                $decrypted = $this->encryption->decrypt($encrypted);

                if ($decrypted === $data) {
                    echo "✅ Test " . ($index + 1) . " passed: " . substr($data, 0, 30) . "...\n";
                } else {
                    echo "❌ Test " . ($index + 1) . " failed: Decryption mismatch\n";
                    return false;
                }
            }

            echo "\n✅ All encryption tests passed!\n";
            return true;

        } catch (Exception $e) {
            echo "❌ Encryption test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Detect unencrypted guest names in database
     */
    public function detectUnencryptedData() {
        echo "\n🔎 Scanning for unencrypted data...\n";

        try {
            // Check if guest_name looks like it might be encrypted (base64-like pattern)
            $result = $this->mysqli->query("
                SELECT COUNT(*) as count FROM invitations 
                WHERE guest_name NOT LIKE '%:%' 
                AND guest_name NOT LIKE ''
            ");

            $row = $result->fetch_assoc();
            $unencryptedCount = $row['count'] ?? 0;

            if ($unencryptedCount > 0) {
                echo "Found $unencryptedCount potentially unencrypted records\n";
                return true; // Has unencrypted data
            } else {
                echo "✅ No unencrypted data detected\n";
                return false; // Already encrypted
            }

        } catch (Exception $e) {
            echo "⚠️  Could not check database: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Encrypt existing unencrypted data
     * WARNING: This is a one-way operation! Back up database first!
     */
    public function encryptExistingData() {
        echo "\n⚠️  ENCRYPTION OF EXISTING DATA\n";
        echo "This will encrypt all guest personal data in the database.\n";
        echo "⚠️  IMPORTANT: Back up your database before proceeding!\n\n";
        
        $answer = $this->prompt("Type 'YES' to confirm encryption: ");
        
        if ($answer !== 'YES') {
            echo "Cancelled.\n";
            return false;
        }

        if (!$this->encryption) {
            echo "❌ Encryption not initialized. Set ENCRYPTION_KEY first.\n";
            return false;
        }

        echo "\n🔄 Encrypting data...\n";

        try {
            // Define fields to encrypt
            $sensitiveFields = [
                'invitations' => ['guest_name', 'email', 'phone', 'notes'],
                'rsvp_responses' => ['dietary_restrictions', 'special_notes', 'attendees']
            ];

            foreach ($sensitiveFields as $table => $fields) {
                echo "\nProcessing $table...\n";
                
                // Get all records
                $result = $this->mysqli->query("SELECT id, " . implode(", ", $fields) . " FROM $table");

                if (!$result) {
                    echo "❌ Error querying $table: " . $this->mysqli->error . "\n";
                    continue;
                }

                $encrypted = 0;
                while ($row = $result->fetch_assoc()) {
                    $id = $row['id'];
                    $updates = [];

                    foreach ($fields as $field) {
                        if (!empty($row[$field])) {
                            // Check if already encrypted (contains ':' separator)
                            if (strpos($row[$field], ':') === false) {
                                $encryptedValue = $this->encryption->encrypt($row[$field]);
                                $updates[] = "$field = '" . $this->mysqli->real_escape_string($encryptedValue) . "'";
                            }
                        }
                    }

                    if (!empty($updates)) {
                        $sql = "UPDATE $table SET " . implode(", ", $updates) . " WHERE id = $id";
                        
                        if ($this->mysqli->query($sql)) {
                            $encrypted++;
                        } else {
                            echo "⚠️  Error updating record $id: " . $this->mysqli->error . "\n";
                        }
                    }
                }

                echo "✅ Encrypted $encrypted records in $table\n";
            }

            echo "\n✅ Data encryption complete!\n";
            return true;

        } catch (Exception $e) {
            echo "❌ Encryption failed: " . $e->getMessage() . "\n";
            echo "⚠️  Your database may be in an inconsistent state. Restore from backup.\n";
            return false;
        }
    }

    /**
     * Display help
     */
    public function showHelp() {
        echo "\n📚 Encryption Setup - Command Reference\n";
        echo "=======================================\n\n";
        echo "php setup-encryption.php [COMMAND]\n\n";
        echo "Commands:\n";
        echo "  --generate-key              Generate a new encryption key\n";
        echo "  --verify-key                Verify encryption key is set up\n";
        echo "  --test-encryption           Test encryption/decryption functionality\n";
        echo "  --check-data                Detect if database has unencrypted data\n";
        echo "  --encrypt-existing-data     Encrypt all existing guest data\n";
        echo "  --help                      Show this help message\n";
        echo "\nSetup Steps:\n";
        echo "  1. php setup-encryption.php --generate-key\n";
        echo "  2. Add ENCRYPTION_KEY to .env file\n";
        echo "  3. php setup-encryption.php --verify-key\n";
        echo "  4. php setup-encryption.php --test-encryption\n";
        echo "  5. php setup-encryption.php --check-data\n";
        echo "  6. php setup-encryption.php --encrypt-existing-data\n\n";
    }

    /**
     * Prompt user for input
     */
    private function prompt($message) {
        echo $message;
        return trim(fgets(STDIN));
    }
}

// Run setup if executed directly
if (php_sapi_name() === 'cli') {
    $command = $argv[1] ?? '--help';

    $requireDb = in_array($command, ['--check-data', '--encrypt-existing-data']);
    $setup = new EncryptionSetup($requireDb);

    switch ($command) {
        case '--generate-key':
            $setup->generateKey();
            break;

        case '--verify-key':
            $setup->verifyKey();
            break;

        case '--test-encryption':
            $setup->testEncryption();
            break;

        case '--check-data':
            $setup->detectUnencryptedData();
            break;

        case '--encrypt-existing-data':
            $setup->encryptExistingData();
            break;

        case '--help':
        default:
            $setup->showHelp();
            break;
    }
} else {
    die("This script must be run from command line (CLI)");
}

?>
