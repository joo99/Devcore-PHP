<?php
/**
 * ===== Database connection (MySQL / MariaDB on Hostinger) =====
 * Reads environment variables from .env or from the system environment.
 *
 * Note: Hostinger shared hosting does not support PostgreSQL/pdo_pgsql, so the
 * connection was switched to MySQL. Numeric columns (id, capacity, order ...)
 * are returned as numbers and boolean columns as true/false, exactly as they
 * were with Postgres, to keep the same response shape the frontend relies on.
 */

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

loadEnv(__DIR__ . '/../.env');

// ===== Required environment variables =====
// DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS  -> MySQL connection info (Hostinger -> hPanel -> Databases -> MySQL)
// JWT_SECRET                                    -> JWT signing secret

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'u306056985_DrMohamedkh');
define('DB_USER', getenv('DB_USER') ?: 'u306056985_DrMohamedkh');
define('DB_PASS', getenv('DB_PASS') ?: 'Dr_mohamed_khaled_123##');
define('JWT_SECRET', getenv('JWT_SECRET') ?: '');

if (DB_USER === '' || DB_NAME === '') {
    error_log('❌ DB_USER أو DB_NAME غير موجودة!');
}
if (JWT_SECRET === '') {
    error_log('❌ JWT_SECRET غير موجودة!');
}

/**
 * Returns a single (singleton) PDO connection to the MySQL database
 */
function getDb(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real (non-emulated) prepared statements: with mysqlnd, numeric
                // columns come back as int and booleans as int(0/1) instead of
                // strings, keeping the numeric/boolean response shape that the
                // old Postgres version produced.
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);

            // Pin the session time zone to UTC so created_at/updated_at values
            // stay consistent with the old data (which was stored at +00).
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            error_log('❌ DB connection error: ' . $e->getMessage());
            throw $e;
        }
    }

    return $pdo;
}

function isDbConfigured(): bool
{
    return DB_USER !== '' && DB_NAME !== '';
}
