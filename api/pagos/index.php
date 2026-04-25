<?php
/**
 * ETTUR - API de Pagos v3.0
 * Dos carriles: Corriente + Deuda Histórica
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'periodos-pendientes': handle_periodos_pendientes(); break;
    case 'registrar':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_registrar_pago();
        break;
    case 'mis-pagos': handle_mis_pagos(); break;
    case 'pendientes': handle_pagos_pendientes(); break;
    case 'validar':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_validar_pago();
        break;
    case 'detalle': handle_detalle_pago(); break;
    case 'comprobante': handle_ver_comprobante(); break;
    case 'historial': handle_historial(); break;
    default: error_response('Acción no válida', 404);
}

function generar_periodos_semanales($fecha_inicio, $fecha_fin, $tipo_trabajador, $monto_personalizado = null) {
    $periodos = [];
    $start = new DateTime($fecha_inicio);
    $end = new DateTime($fecha_fin);
    $hoy = new DateTime(date('Y-m-d'));
    $dow = (int)$start->format('N');
    if ($dow !== 1) { $start->modify('next monday'); }
    $semana = 0;
    while ($start <= $end) {
        $semana++;
        $fin_semana = clone $start;
        $fin_semana->modify('+6 days');
        $es_semana_actual = ($hoy >= $start && $hoy <= $fin_semana);
        $es_futura = ($start > $hoy && !$es_semana_actual);
        if ($es_futura) break;
        $fecha_str = $start->format('Y-m-d');
        $monto = get_monto_trabajador($tipo_trabajador, $fecha_str, $monto_personalizado);
        $temporada = get_temporada($fecha_str);
        $periodos[] = [
            'anio' => (int)$start->format('Y'),
            'mes' => (int)$start->format('n'),
            'semana' => $semana,
            'frecuencia' => 'semanal',
            'fecha_inicio' => $start->format('Y-m-d'),
            'fecha_fin' => $fin_semana->format('Y-m-d'),
            'tipo_tarifa' => $temporada,
            'monto' => $monto,
            'es_semana_actual' => $es_semana_actual
        ];
        $start->modify('+7 days');
    }
    return $periodos;
}

function generar_periodos_mensuales($fecha_inicio, $fecha_fin, $tipo_trabajador, $monto_personalizado = null) {
    $periodos = [];
    $start = new DateTime($fecha_inicio);
    $end = new DateTime($fecha_fin);
    $hoy = new DateTime(date('Y-m-d'));
    $start->setDate((int)$start->format('Y'), (int)$start->format('n'), 1);
    while ($start <= $end) {
        $year = (int)$start->format('Y');
        $month = (int)$start->format('n');
        $last_day = (int)$start->format('t');
        $fin_mes = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $last_day));
        $es_mes_actual = ($hoy->format('Y-m') === $start->format('Y-m'));
        $es_futuro = ($start > $hoy && !$es_mes_actual);
        if ($es_futuro) break;
        $fecha_str = $start->format('Y-m-d');
        $monto = get_monto_trabajador($tipo_trabajador, $fecha_str, $monto_personalizado);
        $temporada = get_temporada($fecha_str);
        $periodos[] = [
            'anio' => $year, 'mes' => $month, 'semana' => null,
            'frecuencia' => 'mensual',
            'fecha_inicio' => $start->format('Y-m-d'),
            'fecha_fin' => $fin_mes->format('Y-m-d'),
            'tipo_tarifa' => $temporada, 'monto' => $monto,
            'es_semana_actual' => $es_mes_actual
        ];
        $start->modify('first day of next month');
    }
    return $periodos;
}

function generar_periodos_trabajador_tipo($trabajador_id, $fecha_inicio, $fecha_fin) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT tipo_trabajador, monto_personalizado, frecuencia_personalizado FROM usuarios WHERE id = ?");
    $stmt->execute([$trabajador_id]);
    $trab = $stmt->fetch();
    if (!$trab) return [];
    $tipo = $trab['tipo_trabajador'] ?? 'normal';
    $frecuencia = get_frecuencia($tipo, $trab['frecuencia_personalizado']);
    if ($frecuencia === 'mensual') {
        return generar_periodos_mensuales($fecha_inicio, $fecha_fin, $tipo, $trab['monto_personalizado']);
    } else {
        return generar_periodos_semanales($fecha_inicio, $fecha_fin, $tipo, $trab['monto_personalizado']);
    }
}

function ensure_periodo($periodo_data) {
    $pdo = db();
    $semana = $periodo_data['semana'] ?? null;
    $frecuencia = $periodo_data['frecuencia'] ?? 'semanal';
    $stmt = $pdo->prepare("SELECT id FROM periodos_pago WHERE fecha_inicio = ? AND fecha_fin = ?");
    $stmt->execute([$periodo_data['fecha_inicio'], $periodo_data['fecha_fin']]);
    $existing = $stmt->fetch();
    if ($existing) return (int)$existing['id'];
    $stmt2 = $pdo->prepare("
        INSERT INTO periodos_pago (anio, mes, quincena, frecuencia, fecha_inicio, fecha_fin, tipo_tarifa, monto)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt2->execute([
        $periodo_data['anio'], $periodo_data['mes'], $semana, $frecuencia,
        $periodo_data['fecha_inicio'], $periodo_data['fecha_fin'],
        $periodo_data['tipo_tarifa'], $periodo_data['monto']
    ]);
    return (int)$pdo->lastInsertId();
}

function get_pagados($trabajador_id) {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT pp.fecha_inicio, pp.fecha_fin, p.estado, p.tipo_periodo
        FROM pagos p JOIN periodos_pago pp ON p.periodo_id = pp.id
        WHERE p.trabajador_id = ?
    ");
    $stmt->execute([$trabajador_id]);
    $pagos = $stmt->fetchAll();
    $map = [];
    foreach ($pagos as $pe) {
        $key = $pe['fecha_inicio'] . '_' . $pe['fecha_fin'];
        $map[$key] = $pe;
    }
    return $map;
}

function filtrar_pendientes($periodos, $pagados) {
    $pendientes = [];
    foreach ($periodos as $p) {
        $key = $p['fecha_inicio'] . '_' . $p['fecha_fin'];
        // Solo excluir de la deuda si está APROBADO
        // Pendiente y rechazado siguen contando como deuda
        if (!isset($pagados[$key]) || $pagados[$key]['estado'] === 'rechazado' || $pagados[$key]['estado'] === 'pendiente') {
            $p['pago_pendiente_validacion'] = isset($pagados[$key]) && $pagados[$key]['estado'] === 'pendiente';
            $pendientes[] = $p;
        }
    }
    return $pendientes;
}

function handle_periodos_pendientes() {
    $user = Auth::requireAuth();
    $trabajador_id = $_GET['trabajador_id'] ?? $user['id'];
    if ($trabajador_id != $user['id'] && !in_array($user['rol_nombre'], ['admin', 'coadmin'])) {
        error_response('No tiene permisos', 403);
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT fecha_inicio_cobro, fecha_lanzamiento FROM trabajador_config WHERE usuario_id = ?");
    $stmt->execute([$trabajador_id]);
    $config = $stmt->fetch();
    if (!$config) { error_response('No se ha configurado la fecha de inicio de cobro'); }

    $fecha_deuda = $config['fecha_inicio_cobro'];
    $fecha_lanzamiento = $config['fecha_lanzamiento'] ?? $config['fecha_inicio_cobro'];
    $fecha_hoy = date('Y-m-d');

    $pagados = get_pagados($trabajador_id);

    // Carril 1: Corrientes (desde lanzamiento hasta hoy)
    $periodos_corrientes = generar_periodos_trabajador_tipo($trabajador_id, $fecha_lanzamiento, $fecha_hoy);
    $pendientes_corrientes = filtrar_pendientes($periodos_corrientes, $pagados);
    $deuda_corriente = 0;
    foreach ($pendientes_corrientes as $p) { $deuda_corriente += $p['monto']; }

    // Carril 2: Históricos (desde inicio deuda hasta un día antes del lanzamiento)
    $pendientes_historicos = [];
    $deuda_historica = 0;
    if ($fecha_deuda < $fecha_lanzamiento) {
        $dia_antes = new DateTime($fecha_lanzamiento);
        $dia_antes->modify('-1 day');
        $periodos_historicos = generar_periodos_trabajador_tipo($trabajador_id, $fecha_deuda, $dia_antes->format('Y-m-d'));
        // Históricos: incluir todos (no cortar por semana actual)
        // Regenerar sin corte de semana actual
        $pdo2 = db();
        $stmt2 = $pdo2->prepare("SELECT tipo_trabajador, monto_personalizado, frecuencia_personalizado FROM usuarios WHERE id = ?");
        $stmt2->execute([$trabajador_id]);
        $trab = $stmt2->fetch();
        $tipo = $trab['tipo_trabajador'] ?? 'normal';
        $frecuencia = get_frecuencia($tipo, $trab['frecuencia_personalizado']);

        // Generar periodos históricos sin corte de "semana actual"
        $hist_start = new DateTime($fecha_deuda);
        $hist_end = clone $dia_antes;
        $periodos_historicos = [];

        if ($frecuencia === 'mensual') {
            $hist_start->setDate((int)$hist_start->format('Y'), (int)$hist_start->format('n'), 1);
            while ($hist_start <= $hist_end) {
                $year = (int)$hist_start->format('Y');
                $month = (int)$hist_start->format('n');
                $last_day = (int)$hist_start->format('t');
                $fin_mes = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $last_day));
                if ($fin_mes > $hist_end) $fin_mes = clone $hist_end;
                $fecha_str = $hist_start->format('Y-m-d');
                $monto = get_monto_trabajador($tipo, $fecha_str, $trab['monto_personalizado']);
                $periodos_historicos[] = [
                    'anio' => $year, 'mes' => $month, 'semana' => null,
                    'frecuencia' => 'mensual',
                    'fecha_inicio' => $hist_start->format('Y-m-d'),
                    'fecha_fin' => $fin_mes->format('Y-m-d'),
                    'tipo_tarifa' => get_temporada($fecha_str),
                    'monto' => $monto, 'es_semana_actual' => false
                ];
                $hist_start->modify('first day of next month');
            }
        } else {
            $dow = (int)$hist_start->format('N');
            if ($dow !== 1) $hist_start->modify('next monday');
            $sem = 0;
            while ($hist_start <= $hist_end) {
                $sem++;
                $fin_sem = clone $hist_start;
                $fin_sem->modify('+6 days');
                if ($fin_sem > $hist_end) break;
                $fecha_str = $hist_start->format('Y-m-d');
                $monto = get_monto_trabajador($tipo, $fecha_str, $trab['monto_personalizado']);
                $periodos_historicos[] = [
                    'anio' => (int)$hist_start->format('Y'),
                    'mes' => (int)$hist_start->format('n'),
                    'semana' => $sem, 'frecuencia' => 'semanal',
                    'fecha_inicio' => $hist_start->format('Y-m-d'),
                    'fecha_fin' => $fin_sem->format('Y-m-d'),
                    'tipo_tarifa' => get_temporada($fecha_str),
                    'monto' => $monto, 'es_semana_actual' => false
                ];
                $hist_start->modify('+7 days');
            }
        }

        $pendientes_historicos = filtrar_pendientes($periodos_historicos, $pagados);
        foreach ($pendientes_historicos as $p) { $deuda_historica += $p['monto']; }
    }

    $deuda_total = $deuda_corriente + $deuda_historica;
    $primer_corriente = !empty($pendientes_corrientes) ? $pendientes_corrientes[0] : null;
    $primer_historico = !empty($pendientes_historicos) ? $pendientes_historicos[0] : null;

    $stmtT = $pdo->prepare("SELECT tipo_trabajador, monto_personalizado, frecuencia_personalizado FROM usuarios WHERE id = ?");
    $stmtT->execute([$trabajador_id]);
    $trabInfo = $stmtT->fetch();

    success_response([
        'fecha_inicio_cobro' => $fecha_deuda,
        'fecha_lanzamiento' => $fecha_lanzamiento,
        'tipo_trabajador' => $trabInfo['tipo_trabajador'] ?? 'normal',
        // Deuda total
        'total_deuda' => round($deuda_total, 2),
        // Carril corriente
        'corriente' => [
            'periodos_pendientes' => $pendientes_corrientes,
            'total_deuda' => round($deuda_corriente, 2),
            'total_pendientes' => count($pendientes_corrientes),
            'periodo_siguiente' => $primer_corriente
        ],
        // Carril histórico
        'historico' => [
            'periodos_pendientes' => $pendientes_historicos,
            'total_deuda' => round($deuda_historica, 2),
            'total_pendientes' => count($pendientes_historicos),
            'periodo_siguiente' => $primer_historico
        ],
        // Compatibilidad
        'periodos_pendientes' => $pendientes_corrientes,
        'periodo_siguiente_pago' => $primer_corriente,
        'total_periodos_pendientes' => count($pendientes_corrientes) + count($pendientes_historicos)
    ]);
}

function handle_registrar_pago() {
    $user = Auth::requireAuth();
    $trabajador_id = $user['id'];
    if (in_array($user['rol_nombre'], ['admin', 'coadmin']) && !empty($_POST['trabajador_id'])) {
        $trabajador_id = (int)$_POST['trabajador_id'];
    }
    $fecha_inicio_periodo = $_POST['fecha_inicio'] ?? '';
    $fecha_fin_periodo = $_POST['fecha_fin'] ?? '';
    $metodo_pago = sanitize_string($_POST['metodo_pago'] ?? 'yape');
    $observaciones = sanitize_string($_POST['observaciones'] ?? '');
    $tipo_periodo = sanitize_string($_POST['tipo_periodo'] ?? 'corriente');

    if (!$fecha_inicio_periodo || !$fecha_fin_periodo) { error_response('Datos del periodo incompletos'); }
    if (!in_array($metodo_pago, ['yape', 'transferencia', 'efectivo'])) { error_response('Método de pago no válido'); }
    if (!in_array($tipo_periodo, ['corriente', 'historico'])) { $tipo_periodo = 'corriente'; }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT fecha_inicio_cobro, fecha_lanzamiento FROM trabajador_config WHERE usuario_id = ?");
    $stmt->execute([$trabajador_id]);
    $config = $stmt->fetch();
    if (!$config) error_response('Trabajador sin configuración');

    $fecha_deuda = $config['fecha_inicio_cobro'];
    $fecha_lanzamiento = $config['fecha_lanzamiento'] ?? $config['fecha_inicio_cobro'];
    $fecha_hoy = date('Y-m-d');

    // Determinar rango según tipo
    if ($tipo_periodo === 'historico') {
        $dia_antes = new DateTime($fecha_lanzamiento);
        $dia_antes->modify('-1 day');
        $rango_inicio = $fecha_deuda;
        $rango_fin = $dia_antes->format('Y-m-d');
    } else {
        $rango_inicio = $fecha_lanzamiento;
        $rango_fin = $fecha_hoy;
    }

    $todos_periodos = generar_periodos_trabajador_tipo($trabajador_id, $rango_inicio, $rango_fin);

    // Para históricos, regenerar sin corte de semana actual
    if ($tipo_periodo === 'historico') {
        $pdo2 = db();
        $stmt2 = $pdo2->prepare("SELECT tipo_trabajador, monto_personalizado, frecuencia_personalizado FROM usuarios WHERE id = ?");
        $stmt2->execute([$trabajador_id]);
        $trab = $stmt2->fetch();
        $tipo = $trab['tipo_trabajador'] ?? 'normal';
        $frecuencia = get_frecuencia($tipo, $trab['frecuencia_personalizado']);
        $todos_periodos = [];
        $hs = new DateTime($rango_inicio);
        $he = new DateTime($rango_fin);
        if ($frecuencia === 'mensual') {
            $hs->setDate((int)$hs->format('Y'), (int)$hs->format('n'), 1);
            while ($hs <= $he) {
                $y = (int)$hs->format('Y'); $m = (int)$hs->format('n'); $ld = (int)$hs->format('t');
                $fm = new DateTime(sprintf('%04d-%02d-%02d', $y, $m, $ld));
                if ($fm > $he) $fm = clone $he;
                $fs = $hs->format('Y-m-d');
                $todos_periodos[] = ['anio'=>$y,'mes'=>$m,'semana'=>null,'frecuencia'=>'mensual',
                    'fecha_inicio'=>$fs,'fecha_fin'=>$fm->format('Y-m-d'),
                    'tipo_tarifa'=>get_temporada($fs),'monto'=>get_monto_trabajador($tipo,$fs,$trab['monto_personalizado']),
                    'es_semana_actual'=>false];
                $hs->modify('first day of next month');
            }
        } else {
            $dw = (int)$hs->format('N'); if ($dw !== 1) $hs->modify('next monday');
            $s = 0;
            while ($hs <= $he) {
                $s++; $fss = clone $hs; $fss->modify('+6 days');
                if ($fss > $he) break;
                $fs = $hs->format('Y-m-d');
                $todos_periodos[] = ['anio'=>(int)$hs->format('Y'),'mes'=>(int)$hs->format('n'),'semana'=>$s,
                    'frecuencia'=>'semanal','fecha_inicio'=>$fs,'fecha_fin'=>$fss->format('Y-m-d'),
                    'tipo_tarifa'=>get_temporada($fs),'monto'=>get_monto_trabajador($tipo,$fs,$trab['monto_personalizado']),
                    'es_semana_actual'=>false];
                $hs->modify('+7 days');
            }
        }
    }

    // Validar correlativo dentro del carril
    $target_periodo = null;
    foreach ($todos_periodos as $per) {
        if ($per['fecha_inicio'] === $fecha_inicio_periodo && $per['fecha_fin'] === $fecha_fin_periodo) {
            $target_periodo = $per;
            break;
        }
        $stmt_check = $pdo->prepare("
            SELECT p.estado FROM pagos p
            JOIN periodos_pago pp ON p.periodo_id = pp.id
            WHERE p.trabajador_id = ? AND pp.fecha_inicio = ? AND pp.fecha_fin = ?
            AND p.estado IN ('pendiente', 'aprobado')
        ");
        $stmt_check->execute([$trabajador_id, $per['fecha_inicio'], $per['fecha_fin']]);
        $pago_previo = $stmt_check->fetch();
        if (!$pago_previo) {
            error_response("Debe pagar primero el periodo del {$per['fecha_inicio']} al {$per['fecha_fin']}");
        }
    }
    if (!$target_periodo) { error_response('El periodo especificado no es válido'); }

    $periodo_id = ensure_periodo($target_periodo);

    // Verificar duplicado
    $stmt_dup = $pdo->prepare("SELECT id, estado FROM pagos WHERE trabajador_id = ? AND periodo_id = ?");
    $stmt_dup->execute([$trabajador_id, $periodo_id]);
    $dup = $stmt_dup->fetch();
    if ($dup) {
        if ($dup['estado'] === 'pendiente' || $dup['estado'] === 'aprobado') {
            error_response('Ya existe un pago ' . $dup['estado'] . ' para este periodo');
        }
        if ($dup['estado'] === 'rechazado') {
            $pdo->prepare("DELETE FROM pagos WHERE id = ?")->execute([$dup['id']]);
        }
    }

    // Procesar comprobante
    $comprobante_url = null;
    $comprobante_nombre = null;
    if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['comprobante'];
        if ($file['size'] > MAX_FILE_SIZE) error_response('El archivo excede 5MB');
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTENSIONS)) error_response('Formato no permitido');
        $upload_dir = UPLOAD_DIR . date('Y/m/');
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $filename = uniqid('comp_') . '_' . $trabajador_id . '.' . $ext;
        $filepath = $upload_dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $filepath)) error_response('Error al subir comprobante');
        $comprobante_url = 'uploads/' . date('Y/m/') . $filename;
        $comprobante_nombre = $file['name'];
    }

    $stmt_insert = $pdo->prepare("
        INSERT INTO pagos (trabajador_id, periodo_id, tipo_periodo, monto_pagado, metodo_pago, comprobante_url, comprobante_nombre, observaciones)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_insert->execute([
        $trabajador_id, $periodo_id, $tipo_periodo, $target_periodo['monto'],
        $metodo_pago, $comprobante_url, $comprobante_nombre, $observaciones
    ]);
    $pago_id = $pdo->lastInsertId();
    registrar_auditoria($user['id'], 'REGISTRAR_PAGO', 'pagos', $pago_id);
    success_response(['pago_id' => (int)$pago_id, 'monto' => $target_periodo['monto']],
        'Pago registrado. Pendiente de aprobación.', 201);
}

function handle_mis_pagos() {
    $user = Auth::requireAuth();
    $trabajador_id = $_GET['trabajador_id'] ?? $user['id'];
    if ($trabajador_id != $user['id'] && !in_array($user['rol_nombre'], ['admin', 'coadmin'])) {
        error_response('No tiene permisos', 403);
    }
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT p.id, p.monto_pagado, p.metodo_pago, p.estado, p.fecha_pago, p.tipo_periodo,
               p.fecha_validacion, p.observaciones, p.observacion_rechazo,
               p.comprobante_url, p.comprobante_nombre,
               pp.anio, pp.mes, pp.quincena as semana, pp.frecuencia, pp.tipo_tarifa,
               pp.fecha_inicio as periodo_inicio, pp.fecha_fin as periodo_fin,
               CONCAT(v.nombres, ' ', v.apellidos) as validado_por_nombre
        FROM pagos p JOIN periodos_pago pp ON p.periodo_id = pp.id
        LEFT JOIN usuarios v ON p.validado_por = v.id
        WHERE p.trabajador_id = ?
        ORDER BY pp.fecha_inicio DESC
    ");
    $stmt->execute([$trabajador_id]);
    success_response($stmt->fetchAll());
}

function handle_pagos_pendientes() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT p.id, p.monto_pagado, p.metodo_pago, p.estado, p.fecha_pago, p.tipo_periodo,
               p.comprobante_url, p.comprobante_nombre, p.observaciones,
               pp.anio, pp.mes, pp.quincena as semana, pp.frecuencia, pp.tipo_tarifa,
               pp.fecha_inicio as periodo_inicio, pp.fecha_fin as periodo_fin,
               CONCAT(t.nombres, ' ', t.apellidos) as trabajador_nombre,
               t.dni as trabajador_dni, t.placa as trabajador_placa,
               t.tipo_trabajador, t.id as trabajador_id
        FROM pagos p JOIN periodos_pago pp ON p.periodo_id = pp.id
        JOIN usuarios t ON p.trabajador_id = t.id
        WHERE p.estado = 'pendiente'
        ORDER BY p.fecha_pago ASC
    ");
    $stmt->execute();
    success_response($stmt->fetchAll());
}

function handle_validar_pago() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $data = get_json_input();
    $errors = validate_required($data, ['pago_id', 'accion']);
    if (!empty($errors)) error_response('Datos incompletos', 400, $errors);
    $pago_id = (int)$data['pago_id'];
    $accion = $data['accion'];
    if (!in_array($accion, ['aprobar', 'rechazar'])) { error_response('Acción no válida'); }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM pagos WHERE id = ? AND estado = 'pendiente'");
    $stmt->execute([$pago_id]);
    $pago = $stmt->fetch();
    if (!$pago) error_response('Pago no encontrado o ya procesado', 404);
    $nuevo_estado = $accion === 'aprobar' ? 'aprobado' : 'rechazado';
    $observacion_rechazo = ($accion === 'rechazar') ? sanitize_string($data['motivo_rechazo'] ?? '') : null;
    $stmt2 = $pdo->prepare("UPDATE pagos SET estado = ?, fecha_validacion = NOW(), validado_por = ?, observacion_rechazo = ? WHERE id = ?");
    $stmt2->execute([$nuevo_estado, $user['id'], $observacion_rechazo, $pago_id]);
    registrar_auditoria($user['id'], 'VALIDAR_PAGO_' . strtoupper($accion), 'pagos', $pago_id);
    success_response(['estado' => $nuevo_estado], $accion === 'aprobar' ? 'Pago aprobado' : 'Pago rechazado');
}

function handle_detalle_pago() {
    $user = Auth::requireAuth();
    $id = $_GET['id'] ?? 0;
    if (!$id) error_response('ID requerido');
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT p.*, pp.anio, pp.mes, pp.quincena as semana, pp.frecuencia, pp.tipo_tarifa,
               pp.fecha_inicio as periodo_inicio, pp.fecha_fin as periodo_fin,
               CONCAT(t.nombres, ' ', t.apellidos) as trabajador_nombre,
               t.dni as trabajador_dni, t.placa as trabajador_placa, t.tipo_trabajador,
               CONCAT(v.nombres, ' ', v.apellidos) as validado_por_nombre
        FROM pagos p JOIN periodos_pago pp ON p.periodo_id = pp.id
        JOIN usuarios t ON p.trabajador_id = t.id
        LEFT JOIN usuarios v ON p.validado_por = v.id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $pago = $stmt->fetch();
    if (!$pago) error_response('Pago no encontrado', 404);
    if ($user['rol_nombre'] === 'trabajador' && $pago['trabajador_id'] != $user['id']) {
        error_response('No tiene permisos', 403);
    }
    success_response($pago);
}

function handle_ver_comprobante() {
    Auth::requireAuth();
    $path = $_GET['path'] ?? '';
    if (!$path) error_response('Ruta requerida');
    $filepath = __DIR__ . '/../../' . $path;
    if (!file_exists($filepath)) error_response('Comprobante no encontrado', 404);
    $mime = mime_content_type($filepath);
    header("Content-Type: $mime");
    readfile($filepath);
    exit;
}

function handle_historial() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();
    $desde = $_GET['desde'] ?? date('Y-m-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $trabajador_id = $_GET['trabajador_id'] ?? '';
    $estado = $_GET['estado'] ?? '';
    $where = "p.fecha_pago BETWEEN ? AND ?";
    $params = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
    if ($trabajador_id) { $where .= " AND p.trabajador_id = ?"; $params[] = (int)$trabajador_id; }
    if ($estado && in_array($estado, ['pendiente', 'aprobado', 'rechazado'])) { $where .= " AND p.estado = ?"; $params[] = $estado; }
    $stmt = $pdo->prepare("
        SELECT p.id, p.monto_pagado, p.metodo_pago, p.estado, p.fecha_pago, p.tipo_periodo,
               pp.anio, pp.mes, pp.frecuencia, pp.tipo_tarifa,
               pp.fecha_inicio as periodo_inicio, pp.fecha_fin as periodo_fin,
               CONCAT(t.nombres, ' ', t.apellidos) as trabajador_nombre,
               t.dni as trabajador_dni, t.tipo_trabajador
        FROM pagos p JOIN periodos_pago pp ON p.periodo_id = pp.id
        JOIN usuarios t ON p.trabajador_id = t.id
        WHERE $where ORDER BY p.fecha_pago DESC
    ");
    $stmt->execute($params);
    $pagos = $stmt->fetchAll();
    $total_aprobado = $total_pendiente = $total_rechazado = 0;
    foreach ($pagos as $p) {
        switch ($p['estado']) {
            case 'aprobado': $total_aprobado += $p['monto_pagado']; break;
            case 'pendiente': $total_pendiente += $p['monto_pagado']; break;
            case 'rechazado': $total_rechazado += $p['monto_pagado']; break;
        }
    }
    success_response([
        'pagos' => $pagos,
        'resumen' => [
            'total_registros' => count($pagos),
            'total_aprobado' => round($total_aprobado, 2),
            'total_pendiente' => round($total_pendiente, 2),
            'total_rechazado' => round($total_rechazado, 2),
            'total_recaudado' => round($total_aprobado, 2)
        ]
    ]);
}
