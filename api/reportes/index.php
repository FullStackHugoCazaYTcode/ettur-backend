<?php
/**
 * ETTUR - API de Reportes v3.0
 * Con metas, deudas por trabajador y desglose histórico
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'dashboard':              handle_dashboard(); break;
    case 'liquidacion':            handle_liquidacion(); break;
    case 'liquidacion-trabajador': handle_liquidacion_trabajador(); break;
    case 'meta-mensual':           handle_meta_mensual(); break;
    case 'deudas':                 handle_deudas(); break;
    case 'auditoria':              handle_auditoria(); break;
    default: error_response('Acción no válida', 404);
}

function handle_dashboard() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();
    $stats = [];

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE rol_id = 3 AND activo = 1");
    $stats['trabajadores_activos'] = (int)$stmt->fetch()['total'];

    $mes_actual = date('Y-m-01');
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto_pagado), 0) as total FROM pagos WHERE estado = 'aprobado' AND fecha_pago >= ?");
    $stmt->execute([$mes_actual]);
    $stats['recaudado_mes'] = (float)$stmt->fetch()['total'];

    $stmt = $pdo->query("SELECT COALESCE(SUM(monto_pagado), 0) as total FROM pagos WHERE estado = 'aprobado'");
    $stats['recaudado_total'] = (float)$stmt->fetch()['total'];

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM pagos WHERE estado = 'pendiente'");
    $stats['pagos_pendientes'] = (int)$stmt->fetch()['total'];

    $stmt = $pdo->query("
        SELECT p.id, p.monto_pagado, p.metodo_pago, p.estado, p.fecha_pago, p.tipo_periodo,
               CONCAT(t.nombres, ' ', t.apellidos) as trabajador,
               pp.fecha_inicio as periodo_inicio, pp.fecha_fin as periodo_fin,
               pp.frecuencia
        FROM pagos p
        JOIN usuarios t ON p.trabajador_id = t.id
        JOIN periodos_pago pp ON p.periodo_id = pp.id
        ORDER BY p.fecha_pago DESC LIMIT 5
    ");
    $stats['ultimos_pagos'] = $stmt->fetchAll();

    success_response($stats);
}

function handle_meta_mensual() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();

    $anio = $_GET['anio'] ?? date('Y');
    $mes = $_GET['mes'] ?? date('n');

    // Obtener todos los trabajadores activos con config
    $stmt = $pdo->prepare("
        SELECT u.id, CONCAT(u.nombres, ' ', u.apellidos) as nombre, u.dni, u.placa,
               u.tipo_trabajador, u.monto_personalizado, u.frecuencia_personalizado,
               tc.fecha_inicio_cobro, tc.fecha_lanzamiento
        FROM usuarios u
        JOIN trabajador_config tc ON u.id = tc.usuario_id
        WHERE u.rol_id = 3 AND u.activo = 1
    ");
    $stmt->execute();
    $trabajadores = $stmt->fetchAll();

    $meta_total = 0;
    $recaudado_total = 0;
    $detalles = [];

    foreach ($trabajadores as $t) {
        $tipo = $t['tipo_trabajador'] ?? 'normal';
        $frecuencia = get_frecuencia($tipo, $t['frecuencia_personalizado']);

        // Calcular cuánto debería pagar este mes
        $primer_dia = sprintf('%04d-%02d-01', $anio, $mes);
        $ultimo_dia = date('Y-m-t', strtotime($primer_dia));

        $fecha_lanzamiento = $t['fecha_lanzamiento'] ?? $t['fecha_inicio_cobro'];

        // Solo contar si el trabajador ya está activo en este mes
        if ($fecha_lanzamiento > $ultimo_dia) continue;

        $inicio_calculo = max($fecha_lanzamiento, $primer_dia);

        if ($frecuencia === 'mensual') {
            $monto_mes = get_monto_trabajador($tipo, $primer_dia, $t['monto_personalizado']);
            $meta_trabajador = $monto_mes;
        } else {
            // Contar semanas completas del mes
            $start = new DateTime($inicio_calculo);
            $end = new DateTime($ultimo_dia);
            $dow = (int)$start->format('N');
            if ($dow !== 1) $start->modify('next monday');
            $semanas = 0;
            $monto_sem_total = 0;
            while ($start <= $end) {
                $fin_sem = clone $start;
                $fin_sem->modify('+6 days');
                if ($fin_sem > $end) break;
                $monto_sem = get_monto_trabajador($tipo, $start->format('Y-m-d'), $t['monto_personalizado']);
                $monto_sem_total += $monto_sem;
                $semanas++;
                $start->modify('+7 days');
            }
            $meta_trabajador = $monto_sem_total;
        }

        // Cuánto ha pagado (aprobado) este mes
        $stmt2 = $pdo->prepare("
            SELECT COALESCE(SUM(p.monto_pagado), 0) as pagado
            FROM pagos p
            JOIN periodos_pago pp ON p.periodo_id = pp.id
            WHERE p.trabajador_id = ? AND p.estado = 'aprobado' AND p.tipo_periodo = 'corriente'
            AND pp.fecha_inicio >= ? AND pp.fecha_fin <= ?
        ");
        $stmt2->execute([$t['id'], $primer_dia, $ultimo_dia]);
        $pagado = (float)$stmt2->fetch()['pagado'];

        $meta_total += $meta_trabajador;
        $recaudado_total += $pagado;

        $detalles[] = [
            'id' => (int)$t['id'],
            'nombre' => $t['nombre'],
            'dni' => $t['dni'],
            'placa' => $t['placa'],
            'tipo_trabajador' => $tipo,
            'meta' => round($meta_trabajador, 2),
            'pagado' => round($pagado, 2),
            'pendiente' => round(max(0, $meta_trabajador - $pagado), 2),
            'porcentaje' => $meta_trabajador > 0 ? round(($pagado / $meta_trabajador) * 100, 1) : 0
        ];
    }

    // Ordenar por porcentaje (menor primero = más deuda)
    usort($detalles, function($a, $b) { return $a['porcentaje'] - $b['porcentaje']; });

    $meses_nombres = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                      'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    success_response([
        'anio' => (int)$anio,
        'mes' => (int)$mes,
        'mes_nombre' => $meses_nombres[(int)$mes] ?? '',
        'meta_total' => round($meta_total, 2),
        'recaudado_total' => round($recaudado_total, 2),
        'pendiente_total' => round(max(0, $meta_total - $recaudado_total), 2),
        'porcentaje' => $meta_total > 0 ? round(($recaudado_total / $meta_total) * 100, 1) : 0,
        'trabajadores' => $detalles
    ]);
}

function handle_deudas() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT u.id, CONCAT(u.nombres, ' ', u.apellidos) as nombre, u.dni, u.placa,
               u.tipo_trabajador, u.monto_personalizado, u.frecuencia_personalizado,
               tc.fecha_inicio_cobro, tc.fecha_lanzamiento
        FROM usuarios u
        JOIN trabajador_config tc ON u.id = tc.usuario_id
        WHERE u.rol_id = 3 AND u.activo = 1
        ORDER BY u.apellidos, u.nombres
    ");
    $stmt->execute();
    $trabajadores = $stmt->fetchAll();

    $resultado = [];
    $total_deuda_corriente = 0;
    $total_deuda_historica = 0;

    foreach ($trabajadores as $t) {
        $tipo = $t['tipo_trabajador'] ?? 'normal';
        $frecuencia = get_frecuencia($tipo, $t['frecuencia_personalizado']);
        $fecha_deuda = $t['fecha_inicio_cobro'];
        $fecha_lanzamiento = $t['fecha_lanzamiento'] ?? $fecha_deuda;
        $fecha_hoy = date('Y-m-d');

        // Obtener pagos aprobados
        $stmt2 = $pdo->prepare("
            SELECT pp.fecha_inicio, pp.fecha_fin, p.estado
            FROM pagos p JOIN periodos_pago pp ON p.periodo_id = pp.id
            WHERE p.trabajador_id = ? AND p.estado = 'aprobado'
        ");
        $stmt2->execute([$t['id']]);
        $pagos = $stmt2->fetchAll();
        $aprobados = [];
        foreach ($pagos as $pg) { $aprobados[$pg['fecha_inicio'] . '_' . $pg['fecha_fin']] = true; }

        // Pagos pendientes de validación
        $stmt3 = $pdo->prepare("
            SELECT COUNT(*) as total FROM pagos WHERE trabajador_id = ? AND estado = 'pendiente'
        ");
        $stmt3->execute([$t['id']]);
        $pendientes_validacion = (int)$stmt3->fetch()['total'];

        // Calcular deuda corriente
        $deuda_corr = 0;
        $periodos_corr = 0;
        if ($fecha_lanzamiento <= $fecha_hoy) {
            $pcs = generar_periodos_corrientes($fecha_lanzamiento, $fecha_hoy, $tipo, $t['monto_personalizado'], $frecuencia);
            foreach ($pcs as $pc) {
                $key = $pc['fecha_inicio'] . '_' . $pc['fecha_fin'];
                if (!isset($aprobados[$key])) { $deuda_corr += $pc['monto']; $periodos_corr++; }
            }
        }

        // Calcular deuda histórica
        $deuda_hist = 0;
        $periodos_hist = 0;
        if ($fecha_deuda < $fecha_lanzamiento) {
            $dia_antes = new DateTime($fecha_lanzamiento);
            $dia_antes->modify('-1 day');
            $phs = generar_periodos_historicos($fecha_deuda, $dia_antes->format('Y-m-d'), $tipo, $t['monto_personalizado'], $frecuencia);
            foreach ($phs as $ph) {
                $key = $ph['fecha_inicio'] . '_' . $ph['fecha_fin'];
                if (!isset($aprobados[$key])) { $deuda_hist += $ph['monto']; $periodos_hist++; }
            }
        }

        $deuda_total = $deuda_corr + $deuda_hist;
        $total_deuda_corriente += $deuda_corr;
        $total_deuda_historica += $deuda_hist;

        $resultado[] = [
            'id' => (int)$t['id'],
            'nombre' => $t['nombre'],
            'dni' => $t['dni'],
            'placa' => $t['placa'],
            'tipo_trabajador' => $tipo,
            'deuda_corriente' => round($deuda_corr, 2),
            'deuda_historica' => round($deuda_hist, 2),
            'deuda_total' => round($deuda_total, 2),
            'periodos_corriente' => $periodos_corr,
            'periodos_historico' => $periodos_hist,
            'pagos_por_validar' => $pendientes_validacion
        ];
    }

    // Ordenar por deuda total (mayor primero)
    usort($resultado, function($a, $b) { return $b['deuda_total'] - $a['deuda_total']; });

    success_response([
        'trabajadores' => $resultado,
        'totales' => [
            'deuda_corriente' => round($total_deuda_corriente, 2),
            'deuda_historica' => round($total_deuda_historica, 2),
            'deuda_total' => round($total_deuda_corriente + $total_deuda_historica, 2),
            'total_trabajadores' => count($resultado)
        ]
    ]);
}

// Helpers para generar periodos sin depender de semana actual
function generar_periodos_corrientes($fecha_inicio, $fecha_fin, $tipo, $monto_pers, $frecuencia) {
    $periodos = [];
    $start = new DateTime($fecha_inicio);
    $end = new DateTime($fecha_fin);
    if ($frecuencia === 'mensual') {
        $start->setDate((int)$start->format('Y'), (int)$start->format('n'), 1);
        while ($start <= $end) {
            $y = (int)$start->format('Y'); $m = (int)$start->format('n');
            $fm = new DateTime(sprintf('%04d-%02d-%02d', $y, $m, (int)$start->format('t')));
            $fs = $start->format('Y-m-d');
            $periodos[] = ['fecha_inicio' => $fs, 'fecha_fin' => $fm->format('Y-m-d'),
                           'monto' => get_monto_trabajador($tipo, $fs, $monto_pers)];
            $start->modify('first day of next month');
        }
    } else {
        $dow = (int)$start->format('N'); if ($dow !== 1) $start->modify('next monday');
        while ($start <= $end) {
            $fs_end = clone $start; $fs_end->modify('+6 days');
            if ($fs_end > $end) break;
            $fs = $start->format('Y-m-d');
            $periodos[] = ['fecha_inicio' => $fs, 'fecha_fin' => $fs_end->format('Y-m-d'),
                           'monto' => get_monto_trabajador($tipo, $fs, $monto_pers)];
            $start->modify('+7 days');
        }
    }
    return $periodos;
}

function generar_periodos_historicos($fecha_inicio, $fecha_fin, $tipo, $monto_pers, $frecuencia) {
    return generar_periodos_corrientes($fecha_inicio, $fecha_fin, $tipo, $monto_pers, $frecuencia);
}

function handle_liquidacion() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();
    $desde = $_GET['desde'] ?? date('Y-01-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT t.id, CONCAT(t.nombres, ' ', t.apellidos) as nombre, t.dni,
               COUNT(CASE WHEN p.estado = 'aprobado' THEN 1 END) as pagos_aprobados,
               COUNT(CASE WHEN p.estado = 'pendiente' THEN 1 END) as pagos_pendientes,
               COALESCE(SUM(CASE WHEN p.estado = 'aprobado' THEN p.monto_pagado END), 0) as total_aprobado,
               COALESCE(SUM(CASE WHEN p.estado = 'pendiente' THEN p.monto_pagado END), 0) as total_pendiente
        FROM usuarios t
        LEFT JOIN pagos p ON t.id = p.trabajador_id AND p.fecha_pago BETWEEN ? AND ?
        WHERE t.rol_id = 3 AND t.activo = 1
        GROUP BY t.id ORDER BY t.apellidos, t.nombres
    ");
    $stmt->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
    $liquidacion = $stmt->fetchAll();
    $total_recaudado = 0; $total_pendiente = 0;
    foreach ($liquidacion as &$l) {
        $l['total_aprobado'] = (float)$l['total_aprobado'];
        $l['total_pendiente'] = (float)$l['total_pendiente'];
        $total_recaudado += $l['total_aprobado'];
        $total_pendiente += $l['total_pendiente'];
    }
    $stmt2 = $pdo->prepare("
        SELECT metodo_pago, COUNT(*) as cantidad, SUM(monto_pagado) as total
        FROM pagos WHERE estado = 'aprobado' AND fecha_pago BETWEEN ? AND ?
        GROUP BY metodo_pago
    ");
    $stmt2->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
    success_response([
        'periodo' => ['desde' => $desde, 'hasta' => $hasta],
        'trabajadores' => $liquidacion,
        'por_metodo' => $stmt2->fetchAll(),
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
    if (!$trabajador_id) error_response('ID requerido');
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombres, u.apellidos, u.dni, u.telefono, u.placa, u.tipo_trabajador,
               tc.fecha_inicio_cobro, tc.fecha_lanzamiento
        FROM usuarios u LEFT JOIN trabajador_config tc ON u.id = tc.usuario_id WHERE u.id = ?
    ");
    $stmt->execute([$trabajador_id]);
    $trabajador = $stmt->fetch();
    if (!$trabajador) error_response('No encontrado', 404);
    $stmt2 = $pdo->prepare("
        SELECT p.id, p.monto_pagado, p.metodo_pago, p.estado, p.fecha_pago, p.tipo_periodo,
               pp.frecuencia, pp.tipo_tarifa,
               pp.fecha_inicio as periodo_inicio, pp.fecha_fin as periodo_fin,
               CONCAT(v.nombres, ' ', v.apellidos) as validado_por
        FROM pagos p JOIN periodos_pago pp ON p.periodo_id = pp.id
        LEFT JOIN usuarios v ON p.validado_por = v.id
        WHERE p.trabajador_id = ? ORDER BY pp.fecha_inicio ASC
    ");
    $stmt2->execute([$trabajador_id]);
    $pagos = $stmt2->fetchAll();
    $total_pagado = 0;
    foreach ($pagos as $p) { if ($p['estado'] === 'aprobado') $total_pagado += $p['monto_pagado']; }
    success_response([
        'trabajador' => $trabajador,
        'pagos' => $pagos,
        'resumen' => ['total_pagado' => round($total_pagado, 2), 'total_registros' => count($pagos)]
    ]);
}

function handle_auditoria() {
    $user = Auth::requireRole('admin');
    $pdo = db();
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT a.*, CONCAT(u.nombres, ' ', u.apellidos) as usuario_nombre
        FROM auditoria a LEFT JOIN usuarios u ON a.usuario_id = u.id
        ORDER BY a.fecha DESC LIMIT $limit OFFSET $offset
    ");
    $stmt->execute();
    success_response($stmt->fetchAll());
}
