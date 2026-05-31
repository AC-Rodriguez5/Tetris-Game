<?php
class dbcon {
    private $host = '';
    private $port = '';
    private $dbname = '';
    private $user = '';
    private $password = '';
    public $con;
    public $lastError = '';
    public $lastDebugError = '';

    public function __construct() {
        $config = [];
        $localConfig = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tetris-db.local.php';
        if (is_file($localConfig)) {
            $loaded = require $localConfig;
            if (is_array($loaded)) {
                $config = $loaded;
            }
        }

        $this->host = $this->configValue($config, 'host', 'TETRIS_DB_HOST');
        $this->port = $this->configValue($config, 'port', 'TETRIS_DB_PORT');
        $this->dbname = $this->configValue($config, 'dbname', 'TETRIS_DB_NAME');
        $this->user = $this->configValue($config, 'user', 'TETRIS_DB_USER');
        $this->password = $this->configValue($config, 'password', 'TETRIS_DB_PASSWORD');
    }

    public function dbconnect() {
        if (!extension_loaded('pdo_pgsql')) {
            $this->lastError = 'Database service is temporarily unavailable.';
            $this->lastDebugError = 'PHP PostgreSQL PDO driver is not enabled.';
            error_log('[dbconnect] ' . $this->lastDebugError);
            return null;
        }

        if (!$this->hasRequiredConfig()) {
            return null;
        }

        try {
            $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname};connect_timeout=5";
            $this->con = new PDO($dsn, $this->user, $this->password, [
            
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => true,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->lastError = '';
            $this->lastDebugError = '';
            return $this->con;
        } catch (PDOException $e) {
            $this->lastError = 'Database service is temporarily unavailable.';
            $this->lastDebugError = $e->getMessage();
            error_log('[dbconnect] ' . $e->getMessage());
            return null;
        }
    }

    private function configValue($config, $key, $envName) {
        if (array_key_exists($key, $config)) {
            return trim((string)$config[$key]);
        }
        $value = getenv($envName);
        return $value === false ? '' : trim((string)$value);
    }

    private function hasRequiredConfig() {
        $missing = [];
        foreach (['host', 'port', 'dbname', 'user', 'password'] as $field) {
            if ($this->{$field} === '') {
                $missing[] = $field;
            }
        }

        if ($missing) {
            $this->lastError = 'Database service is not configured.';
            $this->lastDebugError = 'Missing database config: ' . implode(', ', $missing);
            error_log('[dbconnect] ' . $this->lastDebugError);
            return false;
        }

        return true;
    }
}
