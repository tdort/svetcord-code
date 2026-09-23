<?php
require_once __DIR__ . '/../config/config.php';

/**
 * Thin PDO singleton wrapper. Every file that needs the DB calls
 * Database::get() to obtain a shared PDO connection.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                // PHP is forced to UTC above (date_default_timezone_set), and every
                // timestamp read back from the DB is treated as UTC (e.g. appending
                // "Z" in JS, " UTC" in PHP's strtotime calls) — so MySQL's own NOW()/
                // CURRENT_TIMESTAMP must actually BE UTC too, or every stored
                // timestamp silently drifts by the DB server's local UTC offset.
                self::$instance->exec("SET time_zone = '+00:00'");
            } catch (PDOException $e) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error'   => 'Database connection failed. Check config/config.php.',
                ]);
                exit;
            }
        }

        return self::$instance;
    }
}
