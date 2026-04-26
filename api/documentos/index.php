<?php
/**
 * ETTUR - API de Documentos del Trabajador
 * Licencia, SOAT, Revisión Técnica, Tarjeta Circulación, Tarjeta Operatividad
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get': handle_get(); break;
    case 'mis-documentos': handle_mis_documentos(); break;
    case 'save':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_save();
        break;
    default: error_response('Acción no válida', 404);
}

function handle_get() {
    $user = Auth::requireAuth();
    $usuario_id = $_GET['usuario_id'] ?? $user['id'];

    // Solo admin/coadmin pueden ver docs de otros
    if ($usuario_id != $user['id'] && !in_array($user['rol_nombre'], ['admin', 'coadmin'])) {
        error_response('No tiene permisos', 403);
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM documentos_trabajador WHERE usuario_id = ? ORDER BY tipo_documento");
    $stmt->execute([$usuario_id]);
    $docs = $stmt->fetchAll();

    // Indexar por tipo
    $resultado = [];
    $tipos = ['licencia', 'soat', 'revision_tecnica', 'tarjeta_circulacion', 'tarjeta_operatividad'];
    foreach ($tipos as $tipo) {
        $resultado[$tipo] = null;
    }
    foreach ($docs as $d) {
        // Calcular días restantes
        if ($d['fecha_vencimiento']) {
            $hoy = new DateTime(date('Y-m-d'));
            $venc = new DateTime($d['fecha_vencimiento']);
            $diff = $hoy->diff($venc);
            $d['dias_restantes'] = $venc >= $hoy ? (int)$diff->days : -(int)$diff->days;
            $d['vencido'] = $venc < $hoy;
            $d['por_vencer'] = !$d['vencido'] && $d['dias_restantes'] <= 30;
        } else {
            $d['dias_restantes'] = null;
            $d['vencido'] = false;
            $d['por_vencer'] = false;
        }
        $resultado[$d['tipo_documento']] = $d;
    }

    success_response($resultado);
}

function handle_mis_documentos() {
    $user = Auth::requireAuth();
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM documentos_trabajador WHERE usuario_id = ? ORDER BY tipo_documento");
    $stmt->execute([$user['id']]);
    $docs = $stmt->fetchAll();

    $resultado = [];
    foreach ($docs as $d) {
        if ($d['fecha_vencimiento']) {
            $hoy = new DateTime(date('Y-m-d'));
            $venc = new DateTime($d['fecha_vencimiento']);
            $diff = $hoy->diff($venc);
            $d['dias_restantes'] = $venc >= $hoy ? (int)$diff->days : -(int)$diff->days;
            $d['vencido'] = $venc < $hoy;
            $d['por_vencer'] = !$d['vencido'] && $d['dias_restantes'] <= 30;
        } else {
            $d['dias_restantes'] = null;
            $d['vencido'] = false;
            $d['por_vencer'] = false;
        }
        $resultado[$d['tipo_documento']] = $d;
    }

    success_response($resultado);
}

function handle_save() {
    $admin = Auth::requireRole(['admin']);
    $data = get_json_input();

    if (empty($data['usuario_id'])) error_response('ID de usuario requerido');
    if (empty($data['tipo_documento'])) error_response('Tipo de documento requerido');

    $usuario_id = (int)$data['usuario_id'];
    $tipo = $data['tipo_documento'];
    $tipos_validos = ['licencia', 'soat', 'revision_tecnica', 'tarjeta_circulacion', 'tarjeta_operatividad'];

    if (!in_array($tipo, $tipos_validos)) error_response('Tipo de documento no válido');

    // Validar categoría si es licencia
    $categoria = null;
    if ($tipo === 'licencia') {
        $categoria = $data['categoria'] ?? null;
        $categorias_validas = ['A1', 'A2A', 'A2B', 'A3A', 'A3B', 'A3C'];
        if ($categoria && !in_array(strtoupper($categoria), $categorias_validas)) {
            error_response('Categoría no válida');
        }
        if ($categoria) $categoria = strtoupper($categoria);
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO documentos_trabajador (usuario_id, tipo_documento, numero, categoria, fecha_inicio, fecha_vencimiento, notas, modificado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        numero = VALUES(numero),
        categoria = VALUES(categoria),
        fecha_inicio = VALUES(fecha_inicio),
        fecha_vencimiento = VALUES(fecha_vencimiento),
        notas = VALUES(notas),
        modificado_por = VALUES(modificado_por)
    ");
    $stmt->execute([
        $usuario_id,
        $tipo,
        sanitize_string($data['numero'] ?? ''),
        $categoria,
        !empty($data['fecha_inicio']) ? $data['fecha_inicio'] : null,
        !empty($data['fecha_vencimiento']) ? $data['fecha_vencimiento'] : null,
        sanitize_string($data['notas'] ?? ''),
        $admin['id']
    ]);

    registrar_auditoria($admin['id'], 'GUARDAR_DOCUMENTO', 'documentos_trabajador', $usuario_id, null, $data);
    success_response(null, 'Documento guardado correctamente');
}
