<?php
/**
 * ETTUR - API de Reportes y Liquidación
 * Reportes detallados para Admin y Coadmin
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'dashboard':
        handle_dashboard();
        break;
    case 'liquidacion':
        handle_liquidacion();
        break;
    case 'liquidacion-trabajador':
        handle_liquidacion_trabajador();
        break;
    case 'auditoria':
        handle_auditoria();
        break;
    case 'resumen-mensual':
        handle_resumen_mensual();
        break;
    default:
        error_response('Acción no válida', 404);
}

function handle_dashboard() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();

    // Totales generales
    $stats = [];

    // Total trabajadores activos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE rol_id = 3 AND activo = 1");
    $stats['trabajadores_activos'] = (int)$stmt->fetch()['total'];

    // Total recaudado este mes
    $mes_actual = date('Y-m-01');
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(monto_pagado), 0) as total 
        FROM pagos WHERE estado = 'aprobado' AND fecha_pago >= ?
    ");
    $stmt->execute([$mes_actual]);
    $stats['recaudado_mes'] = (float)$stmt->fetch()['total'];

    // Total recaudado histórico
    $stmt = $pdo->query("SELECT COALESCE(SUM(monto_pagado), 0) as total FROM pagos WHERE estado = 'aprobado'");
    $stats['recaudado_total'] = (float)$stmt->fetch()['total'];

    // Pagos pendientes de validar
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM pagos WHERE estado = 'pendiente'");
    $stats['pagos_pendientes'] = (int)$stmt->fetch()['total'];

    // Pagos aprobados hoy
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM pagos WHERE estado = 'aprobado' AND DATE(fecha_validacion) = CURDATE()
    ");
    $stmt->execute();
    $stats['aprobados_hoy'] = (int)$stmt->fetch()['total'];

    // Últimos 5 pagos registrados
    $stmt = $pdo->query("
        SELECT p.id, p.monto_pagado, p.metodo_pago, p.estado, p.fecha_pago,
               CONCAT(t.nombres, ' ', t.apellidos) as trabajador,
               pp.anio, pp.mes, pp.quincena
        FROM pagos p
        JOIN usuarios t ON p.trabajador_id = t.id
        JOIN periodos_pago pp ON p.periodo_id = pp.id
        ORDER BY p.fecha_pago DESC LIMIT 5
    ");
    $stats['ultimos_pagos'] = $stmt->fetchAll();

    // Trabajadores con más deuda (top 5)
    $stmt = $pdo->query("
        SELECT u.id, CONCAT(u.nombres, ' ', u.apellidos) as nombre, u.dni,
               tc.fecha_inicio_cobro
        FROM usuarios u
        JOIN trabajador_config tc ON u.id = tc.usuario_id
        WHERE u.rol_id = 3 AND u.activo = 1
        ORDER BY tc.fecha_inicio_cobro ASC
        LIMIT 10
    ");
    $stats['trabajadores_info'] = $stmt->fetchAll();

    success_response($stats);
}

function handle_liquidacion() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();

    $desde = $_GET['desde'] ?? date('Y-01-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-d');

    // Recaudación por trabajador
    $stmt = $pdo->prepare("
        SELECT t.id, CONCAT(t.nombres, ' ', t.apellidos) as nombre, t.dni,
               COUNT(CASE WHEN p.estado = 'aprobado' THEN 1 END) as pagos_aprobados,
               COUNT(CASE WHEN p.estado = 'pendiente' THEN 1 END) as pagos_pendientes,
               COUNT(CASE WHEN p.estado = 'rechazado' THEN 1 END) as pagos_rechazados,
               COALESCE(SUM(CASE WHEN p.estado = 'aprobado' THEN p.monto_pagado END), 0) as total_aprobado,
               COALESCE(SUM(CASE WHEN p.estado = 'pendiente' THEN p.monto_pagado END), 0) as total_pendiente
        FROM usuarios t
        LEFT JOIN pagos p ON t.id = p.trabajador_id AND p.fecha_pago BETWEEN ? AND ?
        WHERE t.rol_id = 3 AND t.activo = 1
        GROUP BY t.id
        ORDER BY t.apellidos, t.nombres
    ");
    $stmt->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
    $liquidacion = $stmt->fetchAll();

    // Totales
    $total_recaudado = 0;
    $total_pendiente = 0;
    foreach ($liquidacion as &$l) {
        $l['total_aprobado'] = (float)$l['total_aprobado'];
        $l['total_pendiente'] = (float)$l['total_pendiente'];
        $total_recaudado += $l['total_aprobado'];
        $total_pendiente += $l['total_pendiente'];
    }

    // Recaudación por método de pago
    $stmt2 = $pdo->prepare("
        SELECT metodo_pago, COUNT(*) as cantidad, SUM(monto_pagado) as total
        FROM pagos
        WHERE estado = 'aprobado' AND fecha_pago BETWEEN ? AND ?
        GROUP BY metodo_pago
    ");
    $stmt2->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
    $por_metodo = $stmt2->fetchAll();

    success_response([
        'periodo' => ['desde' => $desde, 'hasta' => $hasta],
        'trabajadores' => $liquidacion,
        'por_metodo' => $por_metodo,
        'totales' => [
            'recaudado' => round($total_recaudado, 2),
            'pendiente' => round($total_pendiente, 2),
            'trabajadores' => count($liquidacion)
        ]
    ]);
}

function handle_liquidacion_trabajador() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $trabajador_id = $_GET['trabajador_id'] ?? 0;
    if (!$trabajador_id) error_response('ID de trabajador requerido');

    $pdo = db();

    // Datos del trabajador
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombres, u.apellidos, u.dni, u.telefono,
               tc.fecha_inicio_cobro
        FROM usuarios u
        LEFT JOIN trabajador_config tc ON u.id = tc.usuario_id
        WHERE u.id = ?
    ");
    $stmt->execute([$trabajador_id]);
    $trabajador = $stmt->fetch();
    if (!$trabajador) error_response('Trabajador no encontrado', 404);

    // Historial completo de pagos
    $stmt2 = $pdo->prepare("
        SELECT p.id, p.monto_pagado, p.metodo_pago, p.estado, p.fecha_pago,
               p.fecha_validacion, p.observaciones, p.observacion_rechazo,
               pp.anio, pp.mes, pp.quincena, pp.tipo_tarifa,
               CONCAT(v.nombres, ' ', v.apellidos) as validado_por
        FROM pagos p
        JOIN periodos_pago pp ON p.periodo_id = pp.id
        LEFT JOIN usuarios v ON p.validado_por = v.id
        WHERE p.trabajador_id = ?
        ORDER BY pp.anio ASC, pp.mes ASC, pp.quincena ASC
    ");
    $stmt2->execute([$trabajador_id]);
    $pagos = $stmt2->fetchAll();

    $total_pagado = 0;
    foreach ($pagos as $p) {
        if ($p['estado'] === 'aprobado') {
            $total_pagado += $p['monto_pagado'];
        }
    }

    success_response([
        'trabajador' => $trabajador,
        'pagos' => $pagos,
        'resumen' => [
            'total_pagado' => round($total_pagado, 2),
            'total_registros' => count($pagos)
        ]
    ]);
}

function handle_auditoria() {
    $user = Auth::requireRole('admin');
    $pdo = db();

    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $accion = $_GET['accion'] ?? '';

    $where = "1=1";
    $params = [];

    if ($accion) {
        $where .= " AND a.accion LIKE ?";
        $params[] = "%$accion%";
    }

    $stmt = $pdo->prepare("
        SELECT a.*, CONCAT(u.nombres, ' ', u.apellidos) as usuario_nombre
        FROM auditoria a
        LEFT JOIN usuarios u ON a.usuario_id = u.id
        WHERE $where
        ORDER BY a.fecha DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);

    success_response($stmt->fetchAll());
}

function handle_resumen_mensual() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();

    $anio = $_GET['anio'] ?? date('Y');

    $stmt = $pdo->prepare("
        SELECT MONTH(p.fecha_pago) as mes,
               COUNT(CASE WHEN p.estado = 'aprobado' THEN 1 END) as aprobados,
               COUNT(CASE WHEN p.estado = 'pendiente' THEN 1 END) as pendientes,
               COUNT(CASE WHEN p.estado = 'rechazado' THEN 1 END) as rechazados,
               COALESCE(SUM(CASE WHEN p.estado = 'aprobado' THEN p.monto_pagado END), 0) as total_recaudado
        FROM pagos p
        WHERE YEAR(p.fecha_pago) = ?
        GROUP BY MONTH(p.fecha_pago)
        ORDER BY mes
    ");
    $stmt->execute([$anio]);

    success_response([
        'anio' => (int)$anio,
        'meses' => $stmt->fetchAll()
    ]);
}
