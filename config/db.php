<?php
/**
 * MilkBook - Database Connection
 * PDO Singleton with prepared statements only and exception handling.
 */

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    // Prevent direct instantiation
    private function __construct() {}
    private function __clone() {}

    /**
     * Get the singleton PDO database connection instance.
     *
     * @return PDO
     * @throws PDOException
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
        }

        return self::$instance;
    }
}

/**
 * Functional alias for Database::getConnection()
 *
 * @return PDO
 */
function get_db_connection(): PDO {
    return Database::getConnection();
}
