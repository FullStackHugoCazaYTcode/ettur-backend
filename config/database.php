<?php
/**
 * ETTUR LA UNIVERSIDAD - Configuración de Base de Datos
 * Conexión PDO segura para Railway MySQL
 */

// Cargar variables de entorno desde archivo .env (si existe)
$env_file = __DIR__ . '/../.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}

function ettur_env($key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// Configuración de la base de datos
define('DB_HOST', ettur_env('DB_HOST', 'localhost'));
define('DB_PORT', ettur_env('DB_PORT', '3306'));
define('DB_NAME', ettur_env('DB_NAME', 'ettur_recaudacion'));
define('DB_USER', ettur_env('DB_USER', 'root'));
define('DB_PASS', ettur_env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// Configuración de la aplicación
define('APP_NAME', 'ETTUR La Universidad');
define('APP_ENV', ettur_env('APP_ENV', 'production'));
define('APP_DEBUG', ettur_env('APP_DEBUG', 'false') === 'true');
define('JWT_SECRET', ettur_env('JWT_SECRET', 'ettur_secret_key_change_in_production_2025'));
define('JWT_EXPIRY', 86400);
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp']);

// CORS
define('CORS_ORIGIN', ettur_env('CORS_ORIGIN', '*'));

/**
 * Clase Singleton para conexión PDO
 */
class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                throw new Exception("Error de conexión: " . $e->getMessage());
            }
            throw new Exception("Error de conexión a la base de datos");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    private function __clone() {}
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

function db(): PDO {
    return Database::getInstance()->getConnection();
}
