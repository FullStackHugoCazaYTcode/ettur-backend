<?php
/**
 * ETTUR LA UNIVERSIDAD - Router Principal v2.0
 */

// CORS headers PRIMERO - antes de todo
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    header("Content-Length: 0");
    exit;
}

// Mostrar errores si está en debug
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/config/helpers.php';
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Error cargando configuración: ' . $e->getMessage()
    ]);
    exit;
}

// Intentar conectar a BD
try {
    $pdo = db();
    $dbOk = true;
    $dbError = null;
} catch (Throwable $e) {
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
            'database' => $dbOk ? 'connected' : 'error: ' . ($dbError ?? 'unknown'),
            'php_version' => PHP_VERSION,
            'timestamp' => date('Y-m-d H:i:s'),
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
