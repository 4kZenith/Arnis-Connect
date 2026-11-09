<?php
declare(strict_types=1);

class Database
{
    private ?PDO $conn = null;

    public function connect(): PDO
    {
        if ($this->conn instanceof PDO) {
            return $this->conn;
        }

        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('Required PHP extension pdo_mysql is not enabled.');
        }

        $config = $this->loadLocalConfig();

        $host = trim((string)(getenv('ARNIS_DB_HOST') ?: ($config['host'] ?? '127.0.0.1')));
        $port = (int)(getenv('ARNIS_DB_PORT') ?: ($config['port'] ?? 3306));
        $database = trim((string)(getenv('ARNIS_DB_NAME') ?: ($config['database'] ?? 'if0_42800263_arnis_db')));
        $username = trim((string)(getenv('ARNIS_DB_USER') ?: ($config['username'] ?? 'root')));
        $passwordEnv = getenv('ARNIS_DB_PASSWORD');
        $password = $passwordEnv !== false ? (string)$passwordEnv : (string)($config['password'] ?? '');
        $charset = trim((string)($config['charset'] ?? 'utf8mb4'));

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException(
                'Database configuration is incomplete. Set ARNIS_DB_HOST/NAME/USER or update config/database.local.php.'
            );
        }

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Database port is invalid.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset !== '' ? $charset : 'utf8mb4'
        );

        try {
            $this->conn = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $this->conn;
        } catch (PDOException $e) {
            error_log('Arnis Connect database connection failed: ' . $e->getMessage());
            throw new RuntimeException(
                'Database connection is currently unavailable. Check host, database name, username and password.'
            );
        }
    }

    private function loadLocalConfig(): array
    {
        $localConfigPath = __DIR__ . '/database.local.php';
        if (!is_file($localConfigPath)) {
            return [];
        }
        $config = require $localConfigPath;
        if (!is_array($config)) {
            error_log('Arnis Connect: database.local.php must return an array; ignoring file.');
            return [];
        }
        return $config;
    }

    public function disconnect(): void
    {
        $this->conn = null;
    }
}