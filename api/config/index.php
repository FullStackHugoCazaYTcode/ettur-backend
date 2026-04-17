<?php
/**
 * ETTUR - API de Configuración del Sistema
 * Gestión de Yape y configuraciones generales
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':    handle_get(); break;
    case 'yape':   handle_get_yape(); break;
    case 'update': if ($method !== 'POST') error_response('Método no permitido', 405); handle_update(); break;
    default: error_response('Acción no válida', 404);
}

function handle_get() {
    Auth::requireRole(['admin']);
    $pdo = db();
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion");
    $rows = $stmt->fetchAll();
    $config = [];
    foreach ($rows as $r) { $config[$r['clave']] = $r['valor']; }
    success_response($config);
}

function handle_get_yape() {
    // Cualquier usuario autenticado puede ver los datos de Yape
    Auth::requireAuth();
    $pdo = db();
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('yape_numero', 'yape_nombre')");
    $rows = $stmt->fetchAll();
    $config = [];
    foreach ($rows as $r) { $config[$r['clave']] = $r['valor']; }
    success_response($config);
}

function handle_update() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();

    if (empty($data) || !is_array($data)) {
        error_response('Datos requeridos');
    }

    $pdo = db();
    $allowed = ['yape_numero', 'yape_nombre'];

    $pdo->beginTransaction();
    try {
        foreach ($data as $clave => $valor) {
            if (!in_array($clave, $allowed)) continue;
            $stmt = $pdo->prepare("
                INSERT INTO configuracion (clave, valor, modificado_por) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE valor = VALUES(valor), modificado_por = VALUES(modificado_por)
            ");
            $stmt->execute([sanitize_string($clave), sanitize_string($valor), $admin['id']]);
        }
        $pdo->commit();
        registrar_auditoria($admin['id'], 'ACTUALIZAR_CONFIG', 'configuracion', null, null, $data);
        success_response(null, 'Configuración actualizada correctamente');
    } catch (Exception $e) {
        $pdo->rollBack();
        error_response('Error al actualizar: ' . $e->getMessage(), 500);
    }
}
