<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KaryRide V1 - Database Configuration
|--------------------------------------------------------------------------
| Supports both MySQLi and PDO.
| Returns a database connection instance.
|
| Environment variables should be set in .env file.
|--------------------------------------------------------------------------
*/

// ============================================================
// LOAD ENVIRONMENT VARIABLES
// ============================================================

$envFile = __DIR__ . '/../.env';

if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// ============================================================
// DATABASE CONFIGURATION
// ============================================================

$config = [
    'host'     => getenv('DB_HOST') ?: 'localhost',
    'port'     => (int)(getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_NAME') ?: 'karyride',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASS') ?: '',
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'driver'   => getenv('DB_DRIVER') ?: 'mysqli', // 'mysqli' or 'pdo'
];

// ============================================================
// CONNECTION
// ============================================================

$connection = null;
$error = null;

try {

    if ($config['driver'] === 'pdo') {

        // ============================================================
        // PDO CONNECTION
        // ============================================================

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        $connection = new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => sprintf(
                    "SET NAMES %s COLLATE %s",
                    $config['charset'],
                    $config['collation']
                )
            ]
        );

    } else {

        // ============================================================
        // MYSQLI CONNECTION
        // ============================================================

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $connection = new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );

        $connection->set_charset($config['charset']);

        // Set timezone
        $connection->query("SET time_zone = '+02:00'");
    }

} catch (Throwable $e) {

    $error = $e->getMessage();

    // Log error
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    error_log(
        '[' . date('Y-m-d H:i:s') . '] Database connection failed: ' .
        $e->getMessage() .
        PHP_EOL,
        3,
        $logDir . '/errors.log'
    );

    // In development, you might want to see the error
    if (getenv('APP_ENV') === 'development') {
        die('Database connection failed: ' . $e->getMessage());
    }

    // In production, return null silently
    $connection = null;
}

// ============================================================
// RETURN CONNECTION
// ============================================================

return $connection; 
