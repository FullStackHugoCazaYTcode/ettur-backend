<?php
/**
 * ETTUR LA UNIVERSIDAD - Router Principal
 * Punto de entrada del Backend API
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';

cors_headers();

// Router simple
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Limpiar path
$path = preg_replace('#/+#', '/', $path);
$path = rtrim($path, '/');

// Rutas
$routes = [
    '/api/auth'      => '/api/auth/index.php',
    '/api/usuarios'  => '/api/usuarios/index.php',
    '/api/tarifas'   => '/api/tarifas/index.php',
    '/api/pagos'     => '/api/pagos/index.php',
    '/api/reportes'  => '/api/reportes/index.php',
];

// Buscar ruta
$matched = false;
foreach ($routes as $route => $file) {
    if (strpos($path, $route) === 0) {
        require __DIR__ . $file;
        $matched = true;
        break;
    }
}

if (!$matched) {
    // Ruta raíz - info del API
    if ($path === '' || $path === '/' || $path === '/index.php') {
        success_response([
            'app' => APP_NAME,
            'version' => '1.0.0',
            'status' => 'running',
            'timestamp' => date('Y-m-d H:i:s'),
            'endpoints' => [
                'auth' => '/api/auth?action={login|logout|me|change-password}',
                'usuarios' => '/api/usuarios?action={list|get|create|update|toggle|reset-password}',
                'tarifas' => '/api/tarifas?action={list|current|update}',
                'pagos' => '/api/pagos?action={periodos-pendientes|registrar|mis-pagos|pendientes|validar|detalle|historial}',
                'reportes' => '/api/reportes?action={dashboard|liquidacion|liquidacion-trabajador|auditoria|resumen-mensual}'
            ]
        ]);
    } else {
        error_response('Ruta no encontrada', 404);
    }
}
