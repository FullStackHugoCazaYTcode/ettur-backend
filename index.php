<?php
/**
 * ETTUR LA UNIVERSIDAD - Router Principal v2.0
 */

// CORS - solo si no se han enviado ya
if (!headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/config/helpers.php';
} catch (Throwable $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}

$dbOk = true;
$dbError = null;
try {
    $pdo = db();
} catch (Throwable $e) {
    $dbOk = false;
    $dbError = $e->getMessage();
}

$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#/+#', '/', $path);
$path = rtrim($path, '/');

$routes = [
    '/api/auth'      => '/api/auth/index.php',
    '/api/usuarios'  => '/api/usuarios/index.php',
    '/api/tarifas'   => '/api/tarifas/index.php',
    '/api/pagos'     => '/api/pagos/index.php',
    '/api/reportes'  => '/api/reportes/index.php',
    '/api/config'    => '/api/config/index.php',
];

$matched = false;
foreach ($routes as $route => $file) {
    if (strpos($path, $route) === 0) {
        $filePath = __DIR__ . $file;
        if (file_exists($filePath)) {
            try {
                require $filePath;
            } catch (Throwable $e) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Error interno: ' . $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => "Archivo no encontrado: $file"]);
        }
        $matched = true;
        break;
    }
}

if (!$matched) {
    header('Content-Type: application/json; charset=utf-8');
    if ($path === '' || $path === '/' || $path === '/index.php') {
        echo json_encode([
            'success' => true,
            'app' => 'ETTUR La Universidad',
            'version' => '2.0.0',
            'status' => 'running',
            'database' => $dbOk ? 'connected' : 'error: ' . $dbError,
            'php_version' => PHP_VERSION,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ruta no encontrada: ' . $path]);
    }
}
