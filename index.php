<?php
/**
 * ETTUR LA UNIVERSIDAD - Router Principal
 * Punto de entrada del Backend API
 */

// Mostrar errores temporalmente para debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Test básico antes de cargar todo
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Intentar cargar config
try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/config/helpers.php';
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error cargando configuración: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}

// Intentar conectar a BD
try {
    $pdo = db();
    $dbOk = true;
} catch (Throwable $e) {
    // Continuar sin BD para mostrar al menos la ruta raíz
    $dbOk = false;
    $dbError = $e->getMessage();
}

// Router
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
];

$matched = false;
foreach ($routes as $route => $file) {
    if (strpos($path, $route) === 0) {
        $filePath = __DIR__ . $file;
        if (file_exists($filePath)) {
            require $filePath;
        } else {
            echo json_encode([
                'success' => false,
                'message' => "Archivo no encontrado: $file"
            ]);
        }
        $matched = true;
        break;
    }
}

if (!$matched) {
    if ($path === '' || $path === '/' || $path === '/index.php') {
        echo json_encode([
            'success' => true,
            'app' => 'ETTUR La Universidad',
            'version' => '1.0.0',
            'status' => 'running',
            'database' => $dbOk ? 'connected' : 'error: ' . ($dbError ?? 'unknown'),
            'php_version' => PHP_VERSION,
            'timestamp' => date('Y-m-d H:i:s'),
            'env_check' => [
                'DB_HOST' => getenv('DB_HOST') ?: 'NOT SET',
                'DB_NAME' => getenv('DB_NAME') ?: 'NOT SET',
                'DB_PORT' => getenv('DB_PORT') ?: 'NOT SET',
                'DB_USER' => getenv('DB_USER') ?: 'NOT SET',
                'DB_PASS' => getenv('DB_PASS') ? '***SET***' : 'NOT SET',
            ],
            'endpoints' => [
                'auth' => '/api/auth?action={login|logout|me}',
                'usuarios' => '/api/usuarios?action={list|create|update|toggle}',
                'tarifas' => '/api/tarifas?action={list|current|update}',
                'pagos' => '/api/pagos?action={periodos-pendientes|registrar|mis-pagos|pendientes|validar}',
                'reportes' => '/api/reportes?action={dashboard|liquidacion|auditoria}'
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ruta no encontrada: ' . $path]);
    }
}
