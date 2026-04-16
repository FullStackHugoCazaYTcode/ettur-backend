<?php
/**
 * ETTUR - API de Tarifas v2.0
 * Tarifas por tipo de trabajador y temporada
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':    handle_list(); break;
    case 'current': handle_current(); break;
    case 'update':  if ($method !== 'PUT' && $method !== 'POST') error_response('Método no permitido', 405); handle_update(); break;
    case 'temporadas': handle_temporadas(); break;
    default: error_response('Acción no válida', 404);
}

function handle_list() {
    Auth::requireAuth();
    $pdo = db();
    $stmt = $pdo->query("SELECT * FROM tarifas_tipo ORDER BY tipo_trabajador, temporada");
    $tarifas = $stmt->fetchAll();
    foreach ($tarifas as &$t) {
        $t['id'] = (int)$t['id'];
        $t['monto'] = (float)$t['monto'];
    }

    // También devolver config de temporadas
    $temporada_actual = get_temporada();

    success_response([
        'tarifas' => $tarifas,
        'temporada_actual' => $temporada_actual,
        'config_temporadas' => [
            'verano' => ['inicio' => '1/Ene', 'fin' => '15/Abr'],
            'normal' => ['inicio' => '16/Abr', 'fin' => '31/Dic']
        ]
    ]);
}

function handle_current() {
    $temporada = get_temporada();
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM tarifas_tipo WHERE temporada = ?");
    $stmt->execute([$temporada]);
    $tarifas = $stmt->fetchAll();
    success_response([
        'temporada' => $temporada,
        'tarifas' => $tarifas
    ]);
}

function handle_update() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();

    if (empty($data['id'])) error_response('ID de tarifa requerido');
    if (!isset($data['monto']) || $data['monto'] <= 0) error_response('Monto inválido');

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM tarifas_tipo WHERE id = ?");
    $stmt->execute([(int)$data['id']]);
    $tarifa = $stmt->fetch();
    if (!$tarifa) error_response('Tarifa no encontrada', 404);

    $stmt2 = $pdo->prepare("UPDATE tarifas_tipo SET monto = ?, modificado_por = ?, fecha_modificacion = NOW() WHERE id = ?");
    $stmt2->execute([(float)$data['monto'], $admin['id'], (int)$data['id']]);

    registrar_auditoria($admin['id'], 'EDITAR_TARIFA', 'tarifas_tipo', (int)$data['id'], $tarifa, $data);
    success_response(null, 'Tarifa actualizada correctamente');
}

function handle_temporadas() {
    Auth::requireAuth();
    success_response([
        'temporada_actual' => get_temporada(),
        'verano' => [
            'mes_inicio' => VERANO_MES_INICIO,
            'dia_inicio' => VERANO_DIA_INICIO,
            'mes_fin' => VERANO_MES_FIN,
            'dia_fin' => VERANO_DIA_FIN
        ]
    ]);
}
