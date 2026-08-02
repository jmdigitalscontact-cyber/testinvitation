<?php
/**
 * Database Connection Handler
 * Supports MySQL (primary) and PostgreSQL (legacy) via PDO
 * 
 * All PHP code in this project uses MySQL-style queries (mysqli convention).
 * For MySQL: Direct native MySQLi is used.
 * For PostgreSQL: A PDO adapter handles query translation.
 */

class Database {
    private static $instance = null;
    private $connection;
    private $engine;

    private function __construct() {
        $this->engine = defined('DB_ENGINE') ? DB_ENGINE : 'mysql';

        try {
            if ($this->engine === 'mysql') {
                $this->connectMySQL();
            } else {
                $this->connectPostgreSQL();
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Database Connection Failed: ' . $e->getMessage()]);
            exit();
        }
    }

    /**
     * Connect to MySQL/MariaDB using MySQLi
     */
    private function connectMySQL() {
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $port = defined('DB_PORT') ? DB_PORT : 3306;
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASS') ? DB_PASS : '';
        $name = defined('DB_NAME') ? DB_NAME : 'wedding_rsvp';

        $mysqli = new mysqli($host, $user, $pass, $name, $port);

        if ($mysqli->connect_error) {
            throw new Exception('MySQL Connection Failed: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset('utf8mb4');

        $this->connection = new MySqlConnectionAdapter($mysqli);
    }

    /**
     * Connect to PostgreSQL using PDO (legacy support)
     */
    private function connectPostgreSQL() {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', PG_HOST, PG_PORT, PG_DB);
        $pdo = new PDO($dsn, PG_USER, PG_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->connection = new PgSqlConnectionAdapter($pdo);
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function query($sql) {
        return $this->connection->query($sql);
    }

    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }

    public function lastInsertId() {
        return $this->connection->insert_id;
    }

    public function affectedRows() {
        return $this->connection->affected_rows;
    }

    public function closeConnection() {
        $this->connection->close();
    }

    public function getEngine() {
        return $this->engine;
    }
}

// ============================================================
// MySQL Connection Adapter
// ============================================================

class MySqlConnectionAdapter {
    private $mysqli;
    public $error = '';
    public $affected_rows = 0;
    public $insert_id = null;

    public function __construct(mysqli $mysqli) {
        $this->mysqli = $mysqli;
    }

    public function ping() {
        return $this->mysqli->ping();
    }

    public function query($sql) {
        $result = $this->mysqli->query($sql);
        if ($result === false) {
            $this->error = $this->mysqli->error;
            return false;
        }
        $this->affected_rows = $this->mysqli->affected_rows;

        if ($result === true) {
            return true;
        }

        return new MySqlResultAdapter($result);
    }

    public function prepare($sql) {
        $stmt = $this->mysqli->prepare($sql);
        if ($stmt === false) {
            $this->error = $this->mysqli->error;
            return false;
        }
        return new MySqlStatementAdapter($this, $stmt);
    }

    public function execPrepared($sql, array $params) {
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $this->mysqli->error);
        }

        if (!empty($params)) {
            $types = '';
            $bindParams = [];
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } elseif (is_null($param)) {
                    $types .= 's';
                } else {
                    $types .= 's';
                }
                $bindParams[] = $param;
            }
            $stmt->bind_param($types, ...$bindParams);
        }

        $stmt->execute();
        $this->affected_rows = $stmt->affected_rows;

        // Get insert_id for INSERT statements
        if (preg_match('/^\s*INSERT\s+/i', $sql)) {
            $this->insert_id = $stmt->insert_id;
        }

        $result = $stmt->get_result();
        $stmt->close();

        return $result ? new MySqlResultAdapter($result) : true;
    }

    public function real_escape_string($value) {
        return $this->mysqli->real_escape_string((string)$value);
    }

    public function close() {
        $this->mysqli->close();
    }
}

class MySqlStatementAdapter {
    private $adapter;
    private $stmt;
    private $types = '';
    private $bound = [];
    public $error = '';
    private $result;

    public function __construct(MySqlConnectionAdapter $adapter, mysqli_stmt $stmt) {
        $this->adapter = $adapter;
        $this->stmt = $stmt;
    }

    public function bind_param($types, &...$vars) {
        $this->types = $types;
        $this->bound = $vars;
        return true;
    }

    public function execute() {
        try {
            if (!empty($this->bound)) {
                $this->stmt->bind_param($this->types, ...$this->bound);
            }
            $this->stmt->execute();
            $this->adapter->error = $this->stmt->error;
            $this->adapter->affected_rows = $this->stmt->affected_rows;
            $this->adapter->insert_id = $this->stmt->insert_id;

            $meta = $this->stmt->result_metadata();
            if ($meta) {
                $result = $this->stmt->get_result();
                $this->result = new MySqlResultAdapter($result);
                $meta->free();
            } else {
                $this->result = new MySqlResultAdapter(null);
            }

            return true;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->adapter->error = $e->getMessage();
            return false;
        }
    }

    public function get_result() {
        return $this->result ?: new MySqlResultAdapter(null);
    }

    public function close() {
        $this->stmt->close();
        $this->result = null;
        return true;
    }
}

class MySqlResultAdapter {
    private $rows = [];
    private $index = 0;
    public $num_rows = 0;

    public function __construct($result) {
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $this->rows[] = $row;
            }
            $this->num_rows = count($this->rows);
            $result->free();
        }
    }

    public function fetch_assoc() {
        if ($this->index >= $this->num_rows) {
            return null;
        }
        return $this->rows[$this->index++];
    }
}

// ============================================================
// PostgreSQL Connection Adapter (Legacy — unchanged)
// ============================================================

class PgSqlConnectionAdapter {
    private $pdo;
    public $error = '';
    public $affected_rows = 0;
    public $insert_id = null;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function ping() {
        $this->pdo->query('SELECT 1');
        return true;
    }

    public function query($sql) {
        $sql = trim($sql);

        if (preg_match("/^SHOW TABLES LIKE '([^']+)'$/i", $sql, $matches)) {
            $stmt = $this->pdo->prepare("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='public' AND tablename = ?");
            $stmt->execute([$matches[1]]);
            return new PgSqlResultAdapter($stmt);
        }

        if (preg_match("/^SHOW COLUMNS FROM ([a-zA-Z0-9_]+) LIKE '([^']+)'$/i", $sql, $matches)) {
            $stmt = $this->pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name = ? AND column_name = ?");
            $stmt->execute([$matches[1], $matches[2]]);
            return new PgSqlResultAdapter($stmt);
        }

        try {
            $stmt = $this->pdo->query($sql);
            return new PgSqlResultAdapter($stmt);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function prepare($sql) {
        try {
            return new PgSqlStatementAdapter($this, $sql);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function execPrepared($sql, array $params) {
        $stmt = $this->pdo->prepare($this->rewriteSql($sql));
        $stmt->execute($params);
        $this->affected_rows = $stmt->rowCount();
        return $stmt;
    }

    private function rewriteSql($sql) {
        $rewritten = trim(str_replace('`', '', $sql));
        $rewritten = preg_replace('/FROM_UNIXTIME\(\?\)/i', 'TO_TIMESTAMP(?)', $rewritten);

        if (stripos($rewritten, 'INSERT INTO sessions') !== false && stripos($rewritten, 'ON DUPLICATE KEY UPDATE') !== false) {
            return "INSERT INTO sessions (invitation_id, token, expiry_time) VALUES (?, ?, TO_TIMESTAMP(?)) ON CONFLICT (invitation_id) DO UPDATE SET token = EXCLUDED.token, expiry_time = EXCLUDED.expiry_time";
        }
        if (stripos($rewritten, 'INSERT INTO admin_sessions') !== false && stripos($rewritten, 'ON DUPLICATE KEY UPDATE') !== false) {
            return "INSERT INTO admin_sessions (admin_id, token, expiry_time) VALUES (?, ?, TO_TIMESTAMP(?)) ON CONFLICT (admin_id) DO UPDATE SET token = EXCLUDED.token, expiry_time = EXCLUDED.expiry_time";
        }
        if (stripos($rewritten, 'INSERT INTO qr_codes') !== false && stripos($rewritten, 'ON DUPLICATE KEY UPDATE') !== false) {
            return "INSERT INTO qr_codes (invitation_id, qr_code_data, qr_image_path) VALUES (?, ?, ?) ON CONFLICT (invitation_id) DO UPDATE SET qr_image_path = EXCLUDED.qr_image_path";
        }
        if (preg_match('/DATE_SUB\(NOW\(\), INTERVAL\s+(\d+)\s+HOUR\)/i', $rewritten, $matches)) {
            return preg_replace('/DATE_SUB\(NOW\(\), INTERVAL\s+\d+\s+HOUR\)/i', "NOW() - INTERVAL '" . (int)$matches[1] . " hour'", $rewritten);
        }

        return $rewritten;
    }

    public function real_escape_string($value) {
        $quoted = $this->pdo->quote((string)$value);
        return substr($quoted, 1, -1);
    }

    public function close() {
        $this->pdo = null;
    }
}

class PgSqlStatementAdapter {
    private $connection;
    private $sql;
    private $bound = [];
    public $error = '';
    private $result;

    public function __construct(PgSqlConnectionAdapter $connection, $sql) {
        $this->connection = $connection;
        $this->sql = $sql;
    }

    public function bind_param($types, &...$vars) {
        $this->bound = $vars;
        return true;
    }

    public function execute() {
        try {
            $stmt = $this->connection->execPrepared($this->sql, $this->bound);
            $this->result = new PgSqlResultAdapter($stmt);
            if (preg_match('/^\s*INSERT\s+/i', $this->sql)) {
                try {
                    $idStmt = $this->connection->query('SELECT LASTVAL() AS id');
                    $idRow = $idStmt ? $idStmt->fetch_assoc() : null;
                    if ($idRow && isset($idRow['id']) && is_numeric($idRow['id'])) {
                        $this->connection->insert_id = (int)$idRow['id'];
                    }
                } catch (Exception $ignored) {
                }
            }
            return true;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->connection->error = $e->getMessage();
            return false;
        }
    }

    public function get_result() {
        return $this->result ?: new PgSqlResultAdapter(null);
    }

    public function close() {
        $this->result = null;
        return true;
    }
}

class PgSqlResultAdapter {
    private $rows = [];
    private $index = 0;
    public $num_rows = 0;

    public function __construct($stmt) {
        if ($stmt instanceof PDOStatement) {
            $this->rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->num_rows = count($this->rows);
        }
    }

    public function fetch_assoc() {
        if ($this->index >= $this->num_rows) {
            return null;
        }
        return $this->rows[$this->index++];
    }
}

?>

