<?php
/**
 * ETTUR - API de Tarifas
 * Gestión de tarifas dinámicas (Verano/Normal)
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        handle_list();
        break;
    case 'current':
        handle_current();
        break;
    case 'update':
        if ($method !== 'PUT' && $method !== 'POST') error_response('Método no permitido', 405);
        handle_update();
        break;
    default:
        error_response('Acción no válida', 404);
}

function handle_list() {
    Auth::requireAuth();
    $pdo = db();
    $stmt = $pdo->query("SELECT * FROM tarifas WHERE activo = 1 ORDER BY id");
    $tarifas = $stmt->fetchAll();
    foreach ($tarifas as &$t) {
        $t['id'] = (int)$t['id'];
        $t['monto'] = (float)$t['monto'];
    }
    success_response($tarifas);
}

function handle_current() {
    // No requiere auth - se usa para mostrar info pública
    $tarifa = get_tarifa_actual();
    success_response($tarifa);
}

function handle_update() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();

    if (empty($data['id'])) error_response('ID de tarifa requerido');

    $errors = validate_required($data, ['monto']);
    if (!empty($errors)) error_response('Datos incompletos', 400, $errors);

    $monto = (float)$data['monto'];
    if ($monto <= 0) error_response('El monto debe ser mayor a 0');

    $pdo = db();

    // Obtener tarifa actual
    $stmt = $pdo->prepare("SELECT * FROM tarifas WHERE id = ?");
    $stmt->execute([(int)$data['id']]);
    $tarifa = $stmt->fetch();
    if (!$tarifa) error_response('Tarifa no encontrada', 404);

    $pdo->beginTransaction();
    try {
        $update_fields = ['monto' => $monto, 'modificado_por' => $admin['id']];

        // Actualizar fechas si se proporcionan
        if (isset($data['dia_inicio'])) $update_fields['dia_inicio'] = (int)$data['dia_inicio'];
        if (isset($data['mes_inicio'])) $update_fields['mes_inicio'] = (int)$data['mes_inicio'];
        if (isset($data['dia_fin'])) $update_fields['dia_fin'] = (int)$data['dia_fin'];
        if (isset($data['mes_fin'])) $update_fields['mes_fin'] = (int)$data['mes_fin'];
        if (isset($data['descripcion'])) $update_fields['descripcion'] = sanitize_string($data['descripcion']);

        $set_parts = [];
        $params = [];
        foreach ($update_fields as $field => $value) {
            $set_parts[] = "$field = ?";
            $params[] = $value;
        }
        $params[] = (int)$data['id'];

        $sql = "UPDATE tarifas SET " . implode(', ', $set_parts) . ", fecha_modificacion = NOW() WHERE id = ?";
        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute($params);

        $pdo->commit();

        registrar_auditoria($admin['id'], 'EDITAR_TARIFA', 'tarifas', (int)$data['id'], $tarifa, $data);

        success_response(null, 'Tarifa actualizada correctamente');

    } catch (Exception $e) {
        $pdo->rollBack();
        error_response('Error al actualizar tarifa: ' . $e->getMessage(), 500);
    }
}

/**
 * Obtener la tarifa vigente según la fecha actual
 */
function get_tarifa_actual($fecha = null) {
    if (!$fecha) $fecha = date('Y-m-d');

    $mes = (int)date('n', strtotime($fecha));
    $dia = (int)date('j', strtotime($fecha));

    $pdo = db();
    $tarifas = $pdo->query("SELECT * FROM tarifas WHERE activo = 1")->fetchAll();

    foreach ($tarifas as $tarifa) {
        $inicio_mes = (int)$tarifa['mes_inicio'];
        $inicio_dia = (int)$tarifa['dia_inicio'];
        $fin_mes = (int)$tarifa['mes_fin'];
        $fin_dia = (int)$tarifa['dia_fin'];

        $fecha_num = $mes * 100 + $dia;
        $inicio_num = $inicio_mes * 100 + $inicio_dia;
        $fin_num = $fin_mes * 100 + $fin_dia;

        if ($inicio_num <= $fin_num) {
            // Rango normal (no cruza año)
            if ($fecha_num >= $inicio_num && $fecha_num <= $fin_num) {
                return [
                    'id' => (int)$tarifa['id'],
                    'tipo' => $tarifa['tipo'],
                    'monto' => (float)$tarifa['monto'],
                    'descripcion' => $tarifa['descripcion']
                ];
            }
        } else {
            // Rango que cruza año (ej: Oct - Mar)
            if ($fecha_num >= $inicio_num || $fecha_num <= $fin_num) {
                return [
                    'id' => (int)$tarifa['id'],
                    'tipo' => $tarifa['tipo'],
                    'monto' => (float)$tarifa['monto'],
                    'descripcion' => $tarifa['descripcion']
                ];
            }
        }
    }

    // Default
    return ['id' => 0, 'tipo' => 'normal', 'monto' => 12.00, 'descripcion' => 'Tarifa por defecto'];
}

/**
 * Obtener tarifa para un periodo específico
 */
function get_tarifa_para_periodo($fecha_inicio) {
    return get_tarifa_actual($fecha_inicio);
}
