<?php
/**
 * ETTUR - API de Configuración v2.2
 * Yape + Temporadas editables
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
    case 'temporadas': handle_get_temporadas(); break;
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
    Auth::requireAuth();
    $pdo = db();
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('yape_numero', 'yape_nombre')");
    $rows = $stmt->fetchAll();
    $config = [];
    foreach ($rows as $r) { $config[$r['clave']] = $r['valor']; }
    success_response($config);
}

function handle_get_temporadas() {
    Auth::requireAuth();
    $config = get_config_temporadas();
    $temporada_actual = get_temporada();

    $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
              'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    success_response([
        'temporada_actual' => $temporada_actual,
        'verano_dia_inicio' => $config['verano_dia_inicio'],
        'verano_mes_inicio' => $config['verano_mes_inicio'],
        'verano_dia_fin' => $config['verano_dia_fin'],
        'verano_mes_fin' => $config['verano_mes_fin'],
        'verano_texto' => $config['verano_dia_inicio'] . ' de ' . $meses[$config['verano_mes_inicio']] . ' — ' . $config['verano_dia_fin'] . ' de ' . $meses[$config['verano_mes_fin']],
        'normal_texto' => ($config['verano_dia_fin'] + 1) . ' de ' . $meses[$config['verano_mes_fin']] . ' — ' . ($config['verano_dia_inicio'] > 1 ? ($config['verano_dia_inicio'] - 1) : 31) . ' de ' . $meses[$config['verano_mes_inicio'] == 1 ? 12 : $config['verano_mes_inicio'] - 1]
    ]);
}

function handle_update() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();

    if (empty($data) || !is_array($data)) {
        error_response('Datos requeridos');
    }

    $pdo = db();
    $allowed = ['yape_numero', 'yape_nombre', 'verano_dia_inicio', 'verano_mes_inicio', 'verano_dia_fin', 'verano_mes_fin'];

    // Validar fechas de temporada si se envían
    if (isset($data['verano_mes_inicio'])) {
        $mes_i = (int)$data['verano_mes_inicio'];
        $dia_i = (int)($data['verano_dia_inicio'] ?? 1);
        $mes_f = (int)($data['verano_mes_fin'] ?? 4);
        $dia_f = (int)($data['verano_dia_fin'] ?? 15);
        if ($mes_i < 1 || $mes_i > 12 || $mes_f < 1 || $mes_f > 12) {
            error_response('Mes inválido (1-12)');
        }
        if ($dia_i < 1 || $dia_i > 31 || $dia_f < 1 || $dia_f > 31) {
            error_response('Día inválido (1-31)');
        }
    }

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
