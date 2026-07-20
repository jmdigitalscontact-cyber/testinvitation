<?php
// PostgreSQL connection handler (PDO with lightweight mysqli-compatible adapters)

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', PG_HOST, PG_PORT, PG_DB);
            $pdo = new PDO($dsn, PG_USER, PG_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->connection = new DbConnectionAdapter($pdo);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Database Connection Failed: ' . $e->getMessage()]);
            exit();
        }
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
}

class DbConnectionAdapter {
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
            return new DbResultAdapter($stmt);
        }

        if (preg_match("/^SHOW COLUMNS FROM ([a-zA-Z0-9_]+) LIKE '([^']+)'$/i", $sql, $matches)) {
            $stmt = $this->pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name = ? AND column_name = ?");
            $stmt->execute([$matches[1], $matches[2]]);
            return new DbResultAdapter($stmt);
        }

        try {
            $stmt = $this->pdo->query($sql);
            return new DbResultAdapter($stmt);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function prepare($sql) {
        try {
            return new DbStatementAdapter($this, $sql);
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

class DbStatementAdapter {
    private $connection;
    private $sql;
    private $bound = [];
    public $error = '';
    private $result;

    public function __construct(DbConnectionAdapter $connection, $sql) {
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
            $this->result = new DbResultAdapter($stmt);
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
        return $this->result ?: new DbResultAdapter(null);
    }

    public function close() {
        $this->result = null;
        return true;
    }
}

class DbResultAdapter {
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
