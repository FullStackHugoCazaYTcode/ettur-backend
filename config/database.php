<?php
/**
 * ETTUR LA UNIVERSIDAD - Configuración de Base de Datos v2.0
 */

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

define('DB_HOST', ettur_env('DB_HOST', 'localhost'));
define('DB_PORT', ettur_env('DB_PORT', '3306'));
define('DB_NAME', ettur_env('DB_NAME', 'railway'));
define('DB_USER', ettur_env('DB_USER', 'root'));
define('DB_PASS', ettur_env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'ETTUR La Universidad');
define('APP_ENV', ettur_env('APP_ENV', 'production'));
define('APP_DEBUG', ettur_env('APP_DEBUG', 'false') === 'true');
define('JWT_SECRET', ettur_env('JWT_SECRET', 'ettur_secret_2025'));
define('JWT_EXPIRY', 86400);
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp']);
define('CORS_ORIGIN', ettur_env('CORS_ORIGIN', '*'));

// Temporadas
define('VERANO_MES_INICIO', 1);
define('VERANO_DIA_INICIO', 1);
define('VERANO_MES_FIN', 4);
define('VERANO_DIA_FIN', 15);

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
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
    public function __wakeup() { throw new Exception("Cannot unserialize"); }
}

function db(): PDO {
    return Database::getInstance()->getConnection();
}

/**
 * Determinar la temporada para una fecha
 */
function get_temporada($fecha = null) {
    if (!$fecha) $fecha = date('Y-m-d');
    $mes = (int)date('n', strtotime($fecha));
    $dia = (int)date('j', strtotime($fecha));
    $fecha_num = $mes * 100 + $dia;
    $inicio = VERANO_MES_INICIO * 100 + VERANO_DIA_INICIO;
    $fin = VERANO_MES_FIN * 100 + VERANO_DIA_FIN;
    return ($fecha_num >= $inicio && $fecha_num <= $fin) ? 'verano' : 'normal';
}

/**
 * Obtener monto para un tipo de trabajador en una fecha
 */
function get_monto_trabajador($tipo_trabajador, $fecha = null, $monto_personalizado = null) {
    if ($tipo_trabajador === 'personalizado' && $monto_personalizado !== null) {
        return (float)$monto_personalizado;
    }
    $temporada = get_temporada($fecha);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT monto FROM tarifas_tipo WHERE tipo_trabajador = ? AND temporada = ?");
    $stmt->execute([$tipo_trabajador, $temporada]);
    $row = $stmt->fetch();
    return $row ? (float)$row['monto'] : 0;
}

/**
 * Obtener frecuencia de pago de un tipo de trabajador
 */
function get_frecuencia($tipo_trabajador, $frecuencia_personalizado = null) {
    if ($tipo_trabajador === 'personalizado' && $frecuencia_personalizado) {
        return $frecuencia_personalizado;
    }
    if ($tipo_trabajador === 'mensual') return 'mensual';
    return 'semanal';
}
