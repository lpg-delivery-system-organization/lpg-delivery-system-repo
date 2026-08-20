<?php
/**
 * Database Singleton Class
 * LPG Delivery System v2
 */

class Database {
    /**
     * @var PDO|null
     */
    private static ?PDO $instance = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {}

    /**
     * Prevent cloning of the singleton instance
     */
    private function __clone() {}

    /**
     * Prevent unserialization of the singleton instance
     *
     * @throws Exception
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Connect to the database and return the PDO singleton instance
     *
     * @param array|null $config Optional custom configuration array
     * @return PDO
     * @throws PDOException
     */
    public static function connect(?array $config = null): PDO {
        if (self::$instance === null) {
            if ($config === null) {
                $configFile = dirname(__DIR__) . '/config/database.php';
                if (file_exists($configFile)) {
                    $config = require $configFile;
                } else {
                    $config = [
                        'host' => '127.0.0.1',
                        'port' => 3306,
                        'dbname' => 'lpg_delivery_v2',
                        'username' => 'root',
                        'password' => '',
                        'charset' => 'utf8mb4',
                        'options' => [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false,
                        ]
                    ];
                }
            }

            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 3306;
            $dbname = $config['dbname'] ?? 'lpg_delivery_v2';
            $charset = $config['charset'] ?? 'utf8mb4';
            $username = $config['username'] ?? 'root';
            $password = $config['password'] ?? '';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $options = $config['options'] ?? [];
            // Enforce required PDO attributes
            $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
            $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
            $options[PDO::ATTR_EMULATE_PREPARES] = false;

            self::$instance = new PDO($dsn, $username, $password, $options);
        }

        return self::$instance;
    }

    /**
     * Get the active PDO instance or null if not yet connected
     *
     * @return PDO|null
     */
    public static function getInstance(): ?PDO {
        return self::$instance;
    }

    /**
     * Reset the database singleton instance (primarily for testing and reconnects)
     *
     * @return void
     */
    public static function reset(): void {
        self::$instance = null;
    }
}
